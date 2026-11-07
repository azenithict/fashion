<?php
declare(strict_types=1);
namespace Zenith;

// Guest and customer enquiries: durable lead capture and the operations follow-up queue.
final class Enquiries
{
    private const FIELDS = 'e.id,e.reference,e.status,e.service,e.name,e.email,e.phone,e.contact_method,e.garment,e.quantity,e.deadline,e.location,e.requirements,e.source,e.user_id,e.assigned_to,a.name AS assigned_name,e.closed_reason,e.created_at,e.updated_at';
    public function __construct(private Kernel $k) {}

    public function handle(string $method, string $path, array $input): ?array
    {
        $k = $this->k;
        if ($path === '/enquiries' && $method === 'POST') return $this->create($input);
        if ($path === '/enquiries' && $method === 'GET') {
            $k->actor(['operations','admin']);
            return array_map(fn($r) => $this->output($r), $k->all('SELECT '.self::FIELDS.' FROM enquiries e LEFT JOIN users a ON a.id=e.assigned_to ORDER BY e.created_at DESC LIMIT 500'));
        }
        if (!preg_match('#^/enquiries/([a-f0-9]{32})(?:/(status|notes|assign))?$#D', $path, $m)) return null;
        $u = $k->actor(['operations','admin']); $id = $m[1]; $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') {
            $e = $this->find($id);
            $events = $k->all('SELECT v.id,v.kind,v.from_status,v.to_status,v.note,u.name AS actor_name,v.created_at FROM enquiry_events v LEFT JOIN users u ON u.id=v.actor_id WHERE v.enquiry_id=? ORDER BY v.seq', [$id]);
            return ['enquiry' => $this->output($e), 'events' => $events, 'transitions' => $k->contract['enquiryTransitions'][$e['status']] ?? []];
        }
        if ($method !== 'POST' || $action === '') $k->fail(405, 'method_not_allowed', 'Unsupported request method.');
        return $k->transaction(function () use ($k, $u, $id, $action, $input): array {
            $e = $this->find($id, true); $now = $k->now();
            if ($action === 'notes') {
                $note = $k->text($input, 'note', 3000);
                $this->event($id, $u['id'], 'note', null, null, $note);
                $k->audit('enquiry.note_added', 'enquiries', $id); return ['ok' => true];
            }
            if ($action === 'assign') {
                $assign = $input['assign'] ?? null;
                if (!in_array($assign, ['me','none'], true)) $k->fail(422, 'invalid_input', 'Invalid assign.');
                $to = $assign === 'me' ? $u['id'] : null;
                $k->execute('UPDATE enquiries SET assigned_to=?,updated_at=? WHERE id=?', [$to, $now, $id]);
                $this->event($id, $u['id'], 'assigned', null, null, $to ? 'Assigned to '.$u['name'] : 'Unassigned');
                $k->audit('enquiry.assigned', 'enquiries', $id, ['assigned_to' => $to]); return $this->output($this->find($id));
            }
            $status = $input['status'] ?? null;
            $allowed = $k->contract['enquiryTransitions'][$e['status']] ?? [];
            if (!is_string($status) || !in_array($status, $allowed, true)) $k->fail(409, 'invalid_transition', 'This status change is not allowed from the current state.');
            $note = $k->text($input, 'note', 500, $status === 'closed');
            $k->execute('UPDATE enquiries SET status=?,closed_reason=?,updated_at=? WHERE id=?', [$status, $status === 'closed' ? $note : null, $now, $id]);
            $this->event($id, $u['id'], 'status', $e['status'], $status, $note === '' ? null : $note);
            $k->audit('enquiry.status_changed', 'enquiries', $id, ['from' => $e['status'], 'to' => $status]);
            return $this->output($this->find($id));
        });
    }

    private function create(array $input): array
    {
        $k = $this->k; $c = $k->contract;
        $k->rateLimit('enquiry', 5, 3600);
        // Honeypot: automated submissions are rejected and never shown a false success.
        if (($input['website'] ?? '') !== '') $k->fail(422, 'invalid_input', 'The request could not be accepted. Please try again.');
        if (($input['consent'] ?? null) !== true) $k->fail(422, 'consent_required', 'Please confirm the privacy acknowledgement.');
        $service = $input['service'] ?? null;
        if (!is_string($service) || !in_array($service, $c['enquiryServices'], true)) $k->fail(422, 'invalid_input', 'Invalid service.');
        $method = $input['contact_method'] ?? null;
        if (!is_string($method) || !in_array($method, $c['enquiryContactMethods'], true)) $k->fail(422, 'invalid_input', 'Invalid contact_method.');
        $name = $k->text($input, 'name', 100);
        if (mb_strlen($name) < 2) $k->fail(422, 'invalid_input', 'Invalid name.');
        $email = strtolower($k->text($input, 'email', 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $k->fail(422, 'invalid_input', 'Invalid email.');
        $phone = $k->text($input, 'phone', 40, $method === 'phone');
        if ($phone !== '' && !preg_match('/^[0-9+().\-\s]{7,40}$/D', $phone)) $k->fail(422, 'invalid_input', 'Invalid phone.');
        $requirements = $k->text($input, 'requirements', 3000);
        if (mb_strlen($requirements) < 10) $k->fail(422, 'invalid_input', 'Please describe your requirements in at least 10 characters.');
        $garment = $k->text($input, 'garment', 160, false); $location = $k->text($input, 'location', 120, false);
        $quantity = $input['quantity'] ?? null;
        if ($quantity !== null && (!is_int($quantity) || $quantity < 1 || $quantity > 10000)) $k->fail(422, 'invalid_input', 'Invalid quantity.');
        $deadline = $input['deadline'] ?? null;
        if ($deadline !== null) {
            $d = is_string($deadline) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $deadline, new \DateTimeZone('UTC')) : false;
            if (!$d || $d->format('Y-m-d') !== $deadline || $d < new \DateTimeImmutable('today', new \DateTimeZone('UTC')) || $d > new \DateTimeImmutable('+3 years')) $k->fail(422, 'invalid_input', 'Invalid deadline.');
        }
        $source = [];
        if (isset($input['source'])) {
            if (!is_array($input['source']) || count($input['source']) > 6) $k->fail(422, 'invalid_input', 'Invalid source.');
            foreach (['utm_source','utm_medium','utm_campaign','landing','referrer'] as $key) if (isset($input['source'][$key])) {
                $v = $input['source'][$key];
                if (!is_string($v) || mb_strlen($v) > 120 || preg_match('/[\x00-\x1F]/u', $v)) $k->fail(422, 'invalid_input', 'Invalid source.');
                if ($v !== '') $source[$key] = $v;
            }
        }
        $recent = $k->one('SELECT COUNT(*) AS n FROM enquiries WHERE email=? AND created_at>?', [$email, gmdate('Y-m-d H:i:s', time() - 86400)]);
        if ((int)$recent['n'] >= 3) $k->fail(429, 'rate_limited', 'Too many attempts. Please try again later.');
        $base = Kernel::baseUrl($k->config);
        return $k->transaction(function () use ($k, $service, $method, $name, $email, $phone, $requirements, $garment, $location, $quantity, $deadline, $source, $base): array {
            $id = $k->id(); $now = $k->now(); $reference = '';
            for ($i = 0; $i < 6 && $reference === ''; $i++) {
                $candidate = 'AZF-'.$this->code(6);
                if (!$k->one('SELECT id FROM enquiries WHERE reference=?', [$candidate])) $reference = $candidate;
            }
            if ($reference === '') throw new \RuntimeException('Reference allocation failed');
            $k->execute('INSERT INTO enquiries (id,reference,status,service,name,email,phone,contact_method,garment,quantity,deadline,location,requirements,source,consent_at,user_id,created_at,updated_at) VALUES (?,?,\'new\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id,$reference,$service,$name,$email,$phone ?: null,$method,$garment ?: null,$quantity,$deadline,$location ?: null,$requirements,json_encode($source, JSON_THROW_ON_ERROR),$now,$k->user['id'] ?? null,$now,$now]);
            $this->event($id, $k->user['id'] ?? null, 'created', null, 'new', null);
            $k->audit('enquiry.created', 'enquiries', $id, ['reference' => $reference, 'service' => $service]);
            $k->notifyEmail($email, 'enquiry_received', ['reference' => $reference, 'url' => $base.'/']);
            foreach ($k->all("SELECT id FROM users WHERE role IN ('operations','admin') AND disabled_at IS NULL") as $staff)
                $k->notify($staff['id'], 'enquiry_new', ['reference' => $reference, 'service' => $service, 'url' => $base.'/workspace/?tab=enquiries']);
            return ['reference' => $reference, 'status' => 'new', 'created_at' => $now, 'email' => $email];
        });
    }

    private function code(int $length): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; $out = '';
        for ($i = 0; $i < $length; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }
    private function find(string $id, bool $lock = false): array
    {
        if ($lock) $this->k->one('SELECT id FROM enquiries WHERE id=? FOR UPDATE', [$id]);
        $e = $this->k->one('SELECT '.self::FIELDS.' FROM enquiries e LEFT JOIN users a ON a.id=e.assigned_to WHERE e.id=?', [$id]);
        if (!$e) $this->k->fail(404, 'not_found', 'Enquiry not found.');
        return $e;
    }
    private function event(string $id, ?string $actor, string $kind, ?string $from, ?string $to, ?string $note): void
    {
        $this->k->execute('INSERT INTO enquiry_events (id,enquiry_id,actor_id,kind,from_status,to_status,note,created_at) VALUES (?,?,?,?,?,?,?,?)', [$this->k->id(),$id,$actor,$kind,$from,$to,$note,$this->k->now()]);
    }
    private function output(array $e): array
    {
        $e['quantity'] = $e['quantity'] === null ? null : (int)$e['quantity'];
        $e['source'] = json_decode((string)$e['source'], true) ?: new \stdClass();
        return $e;
    }
}
