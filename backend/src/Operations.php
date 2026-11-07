<?php
declare(strict_types=1);
namespace Zenith;

// Operations control centre: actionable counts and the sales funnel, all derived from persisted records.
// Revenue is never inferred here; unaccepted quotes are counted only as issued, never as sales.
final class Operations
{
    public function __construct(private Kernel $k) {}

    public function handle(string $method, string $path, array $input): ?array
    {
        if ($method !== 'GET' || !in_array($path, ['/operations/overview','/operations/funnel'], true)) return null;
        $this->k->actor(['operations','admin']);
        return $path === '/operations/overview' ? $this->overview() : $this->funnel();
    }

    private function n(string $sql, array $p = []): int { return (int)($this->k->one($sql, $p)['n'] ?? 0); }

    private function overview(): array
    {
        $k = $this->k; $now = $k->now();
        return [
            'generated_at' => $now,
            'enquiries_new' => $this->n("SELECT COUNT(*) n FROM enquiries WHERE status='new'"),
            'enquiries_open' => $this->n("SELECT COUNT(*) n FROM enquiries WHERE status IN ('awaiting_details','reviewing','quotation_ready')"),
            'enquiries_unassigned' => $this->n("SELECT COUNT(*) n FROM enquiries WHERE assigned_to IS NULL AND status NOT IN ('converted','closed')"),
            'designs_awaiting_review' => $this->n("SELECT COUNT(*) n FROM designs WHERE status='submitted'"),
            'quotes_awaiting_customer' => $this->n("SELECT COUNT(*) n FROM designs d WHERE d.status='quoted' AND EXISTS (SELECT 1 FROM quotes q WHERE q.design_id=d.id AND q.accepted_at IS NULL AND q.expires_at>?)", [$now]),
            'quotes_expired_unanswered' => $this->n("SELECT COUNT(*) n FROM designs d WHERE d.status='quoted' AND NOT EXISTS (SELECT 1 FROM quotes q WHERE q.design_id=d.id AND q.expires_at>?)", [$now]),
            'orders_awaiting_payment' => $this->n("SELECT COUNT(*) n FROM orders WHERE status='awaiting_payment'"),
            'orders_in_production' => $this->n("SELECT COUNT(*) n FROM orders WHERE status IN ('deposit_received','paid','production_preparation','in_production','quality_inspection')"),
            'orders_in_delivery' => $this->n("SELECT COUNT(*) n FROM orders WHERE status IN ('shipping_preparation','in_transit','arrived_canada','local_fitting','ready_for_delivery')"),
            'orders_disputed' => $this->n("SELECT COUNT(*) n FROM orders WHERE status='disputed'"),
            'support_open' => $this->n("SELECT COUNT(*) n FROM support_cases WHERE status='open'"),
            'partner_tasks_awaiting_approval' => $this->n("SELECT COUNT(*) n FROM partner_tasks WHERE status='completed'"),
            'partner_applications_pending' => $this->n("SELECT COUNT(*) n FROM partner_applications WHERE status='pending'"),
            'measurements_unverified_on_submitted_designs' => $this->n("SELECT COUNT(*) n FROM designs d JOIN measurements m ON m.id=d.measurement_id WHERE d.status='submitted' AND m.verified_at IS NULL"),
            'email_pending' => $this->n('SELECT COUNT(*) n FROM notification_outbox WHERE sent_at IS NULL AND attempts<12'),
            'email_failed' => $this->n('SELECT COUNT(*) n FROM notification_outbox WHERE sent_at IS NULL AND attempts>=12'),
            'catalog_pending_verification' => $this->n("SELECT (SELECT COUNT(*) FROM catalog_products WHERE status='pending_verification')+(SELECT COUNT(*) FROM catalog_materials WHERE status='pending_verification')+(SELECT COUNT(*) FROM catalog_options WHERE status='pending_verification') n"),
        ];
    }

    private function funnel(): array
    {
        $k = $this->k;
        $sources = $k->all("SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(source,'$.utm_source')),'direct') AS source, COUNT(*) AS enquiries, SUM(status='converted') AS converted FROM enquiries GROUP BY 1 ORDER BY 2 DESC LIMIT 50");
        $services = $k->all('SELECT service, COUNT(*) AS enquiries, SUM(status=\'converted\') AS converted FROM enquiries GROUP BY service ORDER BY 2 DESC');
        $cast = fn(array $rows) => array_map(fn($r) => array_map(fn($v) => is_numeric($v) ? (int)$v : $v, $r), $rows);
        return [
            'stages' => [
                'enquiries' => $this->n('SELECT COUNT(*) n FROM enquiries'),
                'enquiries_converted' => $this->n("SELECT COUNT(*) n FROM enquiries WHERE status='converted'"),
                'registered_customers' => $this->n("SELECT COUNT(*) n FROM users WHERE role='customer'"),
                'designs_submitted' => $this->n("SELECT COUNT(*) n FROM designs WHERE status<>'draft'"),
                'quotes_issued' => $this->n('SELECT COUNT(DISTINCT design_id) n FROM quotes'),
                'quotes_accepted' => $this->n('SELECT COUNT(*) n FROM quotes WHERE accepted_at IS NOT NULL'),
                'orders_with_verified_payment' => $this->n('SELECT COUNT(DISTINCT order_id) n FROM payments'),
                'orders_completed' => $this->n("SELECT COUNT(*) n FROM orders WHERE status IN ('delivered','completed')"),
                'repeat_customers' => $this->n('SELECT COUNT(*) n FROM (SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*)>1) r'),
            ],
            'by_source' => $cast($sources),
            'by_service' => $cast($services),
            'note' => 'Counts only. Collected revenue, tax and contribution are in /reports and come from verified payment records.',
        ];
    }
}
