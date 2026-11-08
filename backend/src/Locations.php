<?php
declare(strict_types=1);
namespace Zenith;

// Public local-service finder. A branch is listed only when an administrator records verified address, services
// covered by the branch's latest approved rate card, a booking procedure and evidence. No placeholders, no pins.
final class Locations
{
    public function __construct(private Kernel $k) {}

    public function handle(string $method, string $path, array $input): ?array
    {
        $k = $this->k;
        if ($path === '/locations' && $method === 'GET') {
            $rows = $k->all('SELECT l.*,b.name AS branch_name,p.business_name FROM branch_listings l JOIN partner_branches b ON b.id=l.branch_id JOIN partners p ON p.id=b.partner_id WHERE l.listed=1 ORDER BY l.city,p.business_name');
            return array_values(array_filter(array_map(fn($r) => $this->publicRow($r), $rows)));
        }
        if (!preg_match('#^/branches/([a-f0-9]{32})/listing$#D', $path, $m)) return null;
        $u = $k->actor(['admin']); $id = $m[1];
        if ($method === 'GET') return ['listing' => $this->listing($id), 'rate_card_services' => $this->rateServices($id)];
        if ($method !== 'PUT') $k->fail(405, 'method_not_allowed', 'Unsupported request method.');
        return $k->transaction(function () use ($k, $u, $id, $input): array {
            if (!$k->one('SELECT id FROM partner_branches WHERE id=? FOR UPDATE', [$id])) $k->fail(404, 'not_found', 'Branch not found.');
            $address = $k->text($input, 'address_line', 200); $city = $k->text($input, 'city', 80);
            $province = $input['province'] ?? null;
            if (!is_string($province) || !in_array($province, $k->contract['provinces'], true)) $k->fail(422, 'invalid_input', 'Invalid province.');
            $postal = strtoupper(preg_replace('/\s+/', ' ', $k->text($input, 'postal_code', 10)));
            if (!preg_match('/^[A-CEGHJ-NPR-TVXY]\d[A-CEGHJ-NPR-TV-Z] ?\d[A-CEGHJ-NPR-TV-Z]\d$/D', $postal)) $k->fail(422, 'invalid_input', 'Invalid postal code.');
            $services = $input['services'] ?? null;
            if (!is_array($services) || $services === [] || !array_is_list($services) || array_diff($services, $k->contract['publicBranchServices'])) $k->fail(422, 'invalid_input', 'Invalid services.');
            $services = array_values(array_unique($services));
            $missing = array_diff($services, $this->rateServices($id));
            if ($missing) $k->fail(409, 'rates_missing', 'The latest approved rate card does not cover: '.implode(', ', $missing).'.');
            $booking = $k->text($input, 'booking_note', 400); $reference = $k->text($input, 'verification_reference', 300);
            $listed = ($input['listed'] ?? false) === true ? 1 : 0; $now = $k->now();
            $k->execute('INSERT INTO branch_listings (branch_id,address_line,city,province,postal_code,services,booking_note,listed,verification_reference,verified_by,verified_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE address_line=VALUES(address_line),city=VALUES(city),province=VALUES(province),postal_code=VALUES(postal_code),services=VALUES(services),booking_note=VALUES(booking_note),listed=VALUES(listed),verification_reference=VALUES(verification_reference),verified_by=VALUES(verified_by),verified_at=VALUES(verified_at),updated_at=VALUES(updated_at)',
                [$id, $address, $city, $province, $postal, json_encode($services, JSON_THROW_ON_ERROR), $booking, $listed, $reference, $u['id'], $now, $now]);
            $k->audit('branch.listing_saved', 'partner_branches', $id, ['listed' => (bool)$listed, 'reference' => $reference]);
            return ['listing' => $this->listing($id), 'rate_card_services' => $this->rateServices($id)];
        });
    }

    private function rateServices(string $branchId): array
    {
        return array_column($this->k->all('SELECT i.service_type FROM rate_card_items i JOIN rate_cards r ON r.id=i.rate_card_id WHERE r.branch_id=? AND r.version=(SELECT MAX(version) FROM rate_cards WHERE branch_id=?)', [$branchId, $branchId]), 'service_type');
    }
    private function listing(string $id): ?array
    {
        $r = $this->k->one('SELECT * FROM branch_listings WHERE branch_id=?', [$id]);
        if (!$r) return null;
        $r['services'] = json_decode($r['services'], true); $r['listed'] = (bool)$r['listed'];
        return $r;
    }
    // Re-checks rate coverage at read time so a later rate-card change cannot leave an unsupported service advertised.
    private function publicRow(array $r): ?array
    {
        $services = array_values(array_intersect(json_decode($r['services'], true), $this->rateServices($r['branch_id'])));
        if (!$services) return null;
        return ['business_name' => $r['business_name'], 'branch_name' => $r['branch_name'], 'address_line' => $r['address_line'], 'city' => $r['city'], 'province' => $r['province'], 'postal_code' => $r['postal_code'], 'services' => $services, 'booking_note' => $r['booking_note']];
    }
}
