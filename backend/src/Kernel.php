<?php
declare(strict_types=1);
namespace Zenith;

use PDO;
use RuntimeException;
use Throwable;

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message) { parent::__construct($message); }
}

final class Kernel
{
    public PDO $db;
    public array $config;
    public ?array $user = null;
    public array $contract;
    public function __construct(array $config, ?PDO $db = null)
    {
        $this->config = $config;
        $this->contract = json_decode((string)file_get_contents(__DIR__ . '/../../shared/contract.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->db = $db ?? new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->db->exec("SET time_zone = '+00:00'");
        if (($config['session_enabled'] ?? true) === true) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                $path = $config['session_path'] ?? '';
                if (!is_dir($path) || !is_writable($path)) throw new RuntimeException('Private session directory unavailable');
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                session_save_path($path);
                session_name($config['session_name'] ?? '__Host-zenith');
                session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (bool)($config['session_secure'] ?? true), 'httponly' => true, 'samesite' => 'Lax']);
                session_start();
            }
            $this->loadSession();
        }
    }
    public function id(): string { return bin2hex(random_bytes(16)); }
    public function now(): string { return gmdate('Y-m-d H:i:s'); }
    public function one(string $sql, array $params = []): ?array { $s = $this->db->prepare($sql); $s->execute($params); return $s->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { $s = $this->db->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    public function execute(string $sql, array $params = []): int { $s = $this->db->prepare($sql); $s->execute($params); return $s->rowCount(); }
    public function fail(int $status, string $code, string $message): never { throw new ApiError($status, $code, $message); }
    public function actor(array $roles = []): array {
        if (!$this->user) $this->fail(401, 'authentication_required', 'Please sign in.');
        if ($roles && !in_array($this->user['role'], $roles, true)) $this->fail(403, 'forbidden', 'You do not have permission for this action.');
        return $this->user;
    }
    public function transaction(callable $fn): mixed {
        if ($this->db->inTransaction()) return $fn();
        $this->db->beginTransaction();
        try { $result = $fn(); $this->db->commit(); return $result; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function audit(string $action, string $entity, string $id, array $details = []): void {
        $this->execute('INSERT INTO audit_events (id,actor_id,action,entity,entity_id,details,created_at) VALUES (?,?,?,?,?,?,?)', [$this->id(), $this->user['id'] ?? null, $action, $entity, $id, json_encode($details, JSON_THROW_ON_ERROR), $this->now()]);
    }
    public static function baseUrl(array $config): string {
        $origin=rtrim($config['public_origin']??$config['origin']??'','/');$url=parse_url($origin);$prefix=$config['public_base_path']??'';
        if (!$url || !in_array($url['scheme']??'',['https','http'],true) || empty($url['host']) || (isset($url['user'])||isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || !empty($url['path']) || !is_string($prefix) || ($prefix!==''&&!preg_match('#^/[A-Za-z0-9_-]+$#D',$prefix))) throw new RuntimeException('Invalid public URL configuration');
        return $origin.$prefix;
    }
    public function notify(string $userId, string $event, array $payload = []): void {
        $payload['url']??=self::baseUrl($this->config).'/account/';
        $now = $this->now(); $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        // Authentication tokens only enter the private delivery queue, never in-app notifications.
        if (!in_array($event, ['account_verification', 'password_recovery'], true)) $this->execute('INSERT INTO notifications (id,user_id,event,payload,created_at) VALUES (?,?,?,?,?)', [$this->id(),$userId,$event,$encoded,$now]);
        $this->execute('INSERT INTO notification_outbox (id,user_id,event,payload,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)', [$this->id(),$userId,$event,$encoded,$now,$now]);
    }
    // Delivery to a recipient without an account (e.g. a guest enquiry acknowledgement); no in-app record.
    public function notifyEmail(string $email, string $event, array $payload = []): void {
        $payload['url']??=self::baseUrl($this->config).'/';
        $now = $this->now();
        $this->execute('INSERT INTO notification_outbox (id,user_id,recipient_email,event,payload,next_attempt_at,created_at) VALUES (?,NULL,?,?,?,?,?)', [$this->id(),$email,$event,json_encode($payload, JSON_THROW_ON_ERROR),$now,$now]);
    }
    public function csrf(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
    public function verifyCsrf(string $token): void { if ($token === '' || !hash_equals($this->csrf(), $token)) $this->fail(419,'csrf_rejected','Your session token expired. Refresh and try again.'); }
    public function sessionUser(array $user): void {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION = ['user_id'=>$user['id'], 'session_version'=>(int)$user['session_version'], 'created'=>time(), 'last_seen'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
        $this->user = $this->publicUser($user);
    }
    public function logout(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $this->user = null;
        $this->csrf();
    }
    public function publicUser(array $u): array { return array_intersect_key($u,array_flip(['id','name','email','role','phone','email_verified_at','created_at'])); }
    private function loadSession(): void {
        if (!empty($_SESSION['user_id'])) {
            $expired = time() - (int)($_SESSION['last_seen'] ?? 0) > (int)($this->config['session_idle_seconds'] ?? 1800) || time() - (int)($_SESSION['created'] ?? 0) > (int)($this->config['session_absolute_seconds'] ?? 43200);
            $u = $expired ? null : $this->one('SELECT * FROM users WHERE id=? AND disabled_at IS NULL', [$_SESSION['user_id']]);
            if (!$u || (int)$u['session_version'] !== (int)($_SESSION['session_version'] ?? 0)) { $this->logout(); return; }
            $_SESSION['last_seen'] = time(); $this->user = $this->publicUser($u);
        }
        $this->csrf();
    }
    public function rateLimit(string $scope, int $limit = 10, int $seconds = 900): void {
        $identity = ($_SERVER['REMOTE_ADDR'] ?? 'cli') . ':' . $scope;
        $bucket = hash('sha256', $identity); $now = $this->now(); $expires = gmdate('Y-m-d H:i:s', time()+$seconds);
        $hits = $this->transaction(function () use ($bucket,$now,$expires): int {
            $this->execute('INSERT IGNORE INTO rate_limits (bucket,hits,expires_at) VALUES (?,0,?)',[$bucket,$expires]);
            $row = $this->one('SELECT * FROM rate_limits WHERE bucket=? FOR UPDATE',[$bucket]);
            $hits = $row['expires_at'] <= $now ? 1 : (int)$row['hits']+1;
            $this->execute('UPDATE rate_limits SET hits=?,expires_at=? WHERE bucket=?',[$hits,$row['expires_at'] <= $now ? $expires : $row['expires_at'],$bucket]);
            return $hits;
        });
        if ($hits > $limit) $this->fail(429,'rate_limited','Too many attempts. Please try again later.');
    }
    public function text(array $input, string $key, int $max, bool $required = true): string {
        $value = $input[$key] ?? '';
        if (!is_string($value)) $this->fail(422,'invalid_input',"Invalid $key.");
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$value)) $this->fail(422,'invalid_input',"Invalid $key.");
        return $value;
    }
}
