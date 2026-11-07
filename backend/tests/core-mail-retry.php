<?php
declare(strict_types=1);
// Failure/retry integration test. Intentionally supplies no SMTP credentials; never sends mail.
require __DIR__.'/../src/Kernel.php';
$configPath=getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php';
$config=require $configPath;
if (($config['environment']??'production')==='production') throw new RuntimeException('Refusing production test configuration');
$config['session_enabled']=false; $k=new Zenith\Kernel($config);
$id=$k->id(); $user=$k->id(); $now=$k->now();
$k->execute("INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,'customer',?,?)",[$user,'Mail retry test',$user.'@example.test',password_hash('fixture-password-604',PASSWORD_DEFAULT),$now,$now]);
$k->execute('INSERT INTO notification_outbox (id,user_id,event,payload,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)',[$id,$user,'worker_test','{}','2000-01-01 00:00:00','2000-01-01 00:00:00']);
$private=tempnam(sys_get_temp_dir(),'zenith-mail-test-');
file_put_contents($private,'<?php $c=require '.var_export(realpath($configPath),true).'; $c["mail"]=["transport"=>"smtp"]; return $c;'); chmod($private,0600);
$checks=0;
function mailCheck(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
try {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../bin/notify.php').' --config '.escapeshellarg($private).' --limit 1';
    exec($command,$output,$status); mailCheck($status===1,'Unavailable SMTP must fail, never simulate success');
    $r=$k->one('SELECT * FROM notification_outbox WHERE id=?',[$id]); mailCheck((int)$r['attempts']===1&&$r['sent_at']===null,'Failed delivery retained and attempt counted');
    mailCheck($r['locked_at']===null&&$r['next_attempt_at']>$now,'Failed delivery unlocked and scheduled for retry');
    mailCheck($r['last_error']==='Delivery failed; verify private mail configuration.','Safe failure logged without credentials');
    $k->execute('UPDATE notification_outbox SET attempts=12,next_attempt_at=? WHERE id=?',['2000-01-01 00:00:00',$id]);
    exec($command.' --retry-failed',$retryOutput,$retryStatus); $r=$k->one('SELECT * FROM notification_outbox WHERE id=?',[$id]);
    mailCheck($retryStatus===1&&(int)$r['attempts']===1&&$r['sent_at']===null,'Exhausted delivery is explicitly retryable');
    mailCheck($k->one("SELECT id FROM audit_events WHERE action='mail.retry_requested' ORDER BY created_at DESC LIMIT 1")!==null,'Manual retry audit event persisted');
} finally { unlink($private); }
echo 'Mail failure/retry integration: ',$checks,' checks passed',PHP_EOL;
