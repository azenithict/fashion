<?php
declare(strict_types=1);
namespace Zenith;

// Guest and customer enquiries: durable lead capture and the operations follow-up queue.
final class Enquiries
{
    private const FIELDS = 'e.id,e.reference,e.status,e.service,e.name,e.email,e.phone,e.contact_method,e.garment,e.quantity,e.deadline,e.location,e.requirements,e.source,e.user_id,e.design_id,e.assigned_to,a.name AS assigned_name,e.closed_reason,e.created_at,e.updated_at';
    public function __construct(private Kernel $k) {}

    public function handle(string $method, string $path, array $input): ?array
    {
        $k = $this->k;
        if ($path === '/enquiries' && $method === 'POST') return $this->create($input);
        if ($path === '/enquiries' && $method === 'GET') {
            $k->actor(['operations','admin']);
            return array_map(fn($r) => $this->output($r), $k->all('SELECT '.self::FIELDS.' FROM enquiries e LEFT JOIN users a ON a.id=e.assigned_to ORDER BY e.created_at DESC LIMIT 500'));
        }
        if ($path === '/my/enquiries' && $method === 'GET') return $this->mine();
        if ($method === 'POST' && preg_match('#^/my/enquiries/([a-f0-9]{32})/reply$#D', $path, $m)) return $this->reply($m[1], $input);
        if (!preg_match('#^/enquiries/([a-f0-9]{32})(?:/(status|notes|assign|message|link-design))?$#D', $path, $m)) return null;
        $u = $k->actor(['operations','admin']); $id = $m[1]; $action = $m[2] ?? '';
        if ($method === 'GET' && $action === '') {
            $e = $this->find($id);
            $events = $k->all('SELECT v.id,v.kind,v.from_status,v.to_status,v.note,u.name AS actor_name,v.created_at FROM enquiry_events v LEFT JOIN users u ON u.id=v.actor_id WHERE v.enquiry_id=? ORDER BY v.seq', [$id]);
            $candidates = $k->all("SELECT d.id,d.name,d.status,d.created_at FROM designs d JOIN users u ON u.id=d.user_id WHERE u.email=? AND u.email_verified_at IS NOT NULL ORDER BY d.created_at DESC LIMIT 20", [$e['email']]);
            return ['enquiry' => $this->output($e), 'events' => $events, 'transitions' => $k->contract['enquiryTransitions'][$e['status']] ?? [], 'candidate_designs' => $candidates];
        }
        if ($method !== 'POST' || $action === '') $k->fail(405, 'method_not_allowed', 'Unsupported request method.');
        return $k->transaction(function () use ($k, $u, $id, $action, $input): array {
            $e = $this->find($id, true); $now = $k->now();
            if ($action === 'notes') {
                $note = $k->text($input, 'note', 3000);
                $this->event($id, $u['id'], 'note', null, null, $note);
                $k->audit('enquiry.note_added', 'enquiries', $id); return ['ok' => true];
            }
            if ($action === 'message') {
                // Customer-facing message (e.g. request for missing details), delivered through the durable outbox.
                $message = $k->text($input, 'message', 3000);
                if (mb_strlen($message) < 5) $k->fail(422, 'invalid_input', 'Invalid message.');
                $this->event($id, $u['id'], 'customer_message', null, null, $message);
                $k->notifyEmail($e['email'], 'enquiry_message', ['reference' => $e['reference'], 'message' => $message, 'url' => Kernel::baseUrl($k->config).'/workspace/?tab=requests']);
                if (($input['request_details'] ?? false) === true && in_array('awaiting_details', $k->contract['enquiryTransitions'][$e['status']] ?? [], true)) {
                    $k->execute("UPDATE enquiries SET status='awaiting_details',updated_at=? WHERE id=?", [$now, $id]);
                    $this->event($id, $u['id'], 'status', $e['status'], 'awaiting_details', null);
                }
                $k->audit('enquiry.customer_messaged', 'enquiries', $id);
                return $this->output($this->find($id));
            }
            if ($action === 'link-design') {
                // Conversion: the customer's own design (same verified email) becomes the reviewed specification path.
                $designId = $k->text($input, 'design_id', 32);
                $d = $k->one('SELECT d.id,d.status,u.email,u.email_verified_at,u.id AS user_id FROM designs d JOIN users u ON u.id=d.user_id WHERE d.id=?', [$designId]);
                if (!$d || strtolower($d['email']) !== strtolower($e['email']) || !$d['email_verified_at']) $k->fail(422, 'invalid_input', 'The design must belong to a verified account using the enquiry email.');
                if (!in_array('converted', $k->contract['enquiryTransitions'][$e['status']] ?? [], true)) $k->fail(409, 'invalid_transition', 'Review the enquiry before converting it.');
                $k->execute("UPDATE enquiries SET status='converted',design_id=?,user_id=?,updated_at=? WHERE id=?", [$designId, $d['user_id'], $now, $id]);
                $this->event($id, $u['id'], 'status', $e['status'], 'converted', 'Linked to customer design');
                $k->audit('enquiry.converted', 'enquiries', $id, ['design_id' => $designId]);
                return $this->output($this->find($id));
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

    // A verified customer sees enquiries sent from their email, with customer-facing messages only.
    private function mine(): array
    {
        $k = $this->k; $u = $k->actor(['customer']);
        if (!$u['email_verified_at']) return [];
        $rows = $k->all('SELECT id,reference,status,service,garment,requirements,design_id,created_at,updated_at FROM enquiries WHERE email=? ORDER BY created_at DESC LIMIT 100', [strtolower($u['email'])]);
        foreach ($rows as &$r) $r['messages'] = $k->all("SELECT v.id,v.kind,v.note AS message,v.created_at FROM enquiry_events v WHERE v.enquiry_id=? AND v.kind IN ('customer_message','customer_reply') ORDER BY v.seq", [$r['id']]);
        return $rows;
    }
    private function reply(string $id, array $input): array
    {
        $k = $this->k; $u = $k->actor(['customer']); $k->rateLimit('enquiry-reply:'.$u['id'], 20, 3600);
        if (!$u['email_verified_at']) $k->fail(403, 'verification_required', 'Verify your email first.');
        $message = $k->text($input, 'message', 3000);
        return $k->transaction(function () use ($k, $u, $id, $message): array {
            $e = $k->one('SELECT id,reference,status,email FROM enquiries WHERE id=? FOR UPDATE', [$id]);
            if (!$e || strtolower($e['email']) !== strtolower($u['email'])) $k->fail(404, 'not_found', 'Enquiry not found.');
            if (in_array($e['status'], ['converted','closed'], true)) $k->fail(409, 'closed', 'This request is closed. Open a support request instead.');
            $this->event($id, $u['id'], 'customer_reply', null, null, $message);
            if ($e['status'] === 'awaiting_details') { $k->execute("UPDATE enquiries SET status='reviewing',updated_at=? WHERE id=?", [$k->now(), $id]); $this->event($id, $u['id'], 'status', 'awaiting_details', 'reviewing', 'Customer replied'); }
            else $k->execute('UPDATE enquiries SET updated_at=? WHERE id=?', [$k->now(), $id]);
            foreach ($k->all("SELECT id FROM users WHERE role IN ('operations','admin') AND disabled_at IS NULL") as $staff)
                $k->notify($staff['id'], 'enquiry_reply', ['reference' => $e['reference'], 'url' => Kernel::baseUrl($k->config).'/workspace/?tab=enquiries']);
            $k->audit('enquiry.customer_replied', 'enquiries', $id);
            return ['ok' => true];
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
