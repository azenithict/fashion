<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/Kernel.php';
require __DIR__.'/../src/Mailer.php';

$options=getopt('', ['config:','limit:','retry-failed']); $path=$options['config']??getenv('ZENITH_CONFIG');
if (!is_string($path)||!is_file($path)) { fwrite(STDERR,"Provide --config /absolute/private/config.php\n"); exit(2); }
try {
    $config=require $path; $config['session_enabled']=false; $k=new Zenith\Kernel($config);
    if (isset($options['retry-failed'])) { $count=$k->execute('UPDATE notification_outbox SET attempts=0,next_attempt_at=?,locked_at=NULL WHERE sent_at IS NULL AND attempts>=12',[$k->now()]); $k->audit('mail.retry_requested','notification_outbox','failed',['count'=>$count]); }
    $limit=min(100,max(1,(int)($options['limit']??20))); $sent=0; $failed=0; $blocked=0;
    for ($i=0;$i<$limit;$i++) {
        $row=$k->transaction(function () use ($k): ?array {
            $row=$k->one('SELECT o.*,COALESCE(u.email,o.recipient_email) AS email FROM notification_outbox o LEFT JOIN users u ON u.id=o.user_id WHERE o.sent_at IS NULL AND o.attempts<12 AND o.next_attempt_at<=? AND (o.locked_at IS NULL OR o.locked_at<?) ORDER BY o.created_at LIMIT 1 FOR UPDATE',[$k->now(),gmdate('Y-m-d H:i:s',time()-300)]);
            if ($row) $k->execute('UPDATE notification_outbox SET locked_at=?,attempts=attempts+1 WHERE id=?',[$k->now(),$row['id']]); return $row;
        });
        if (!$row) break;
        try {
            Zenith\Mailer::send($config,$row['email'],$row['event'],json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR));
            $k->execute("UPDATE notification_outbox SET sent_at=?,locked_at=NULL,last_error=NULL,payload=IF(event IN ('account_verification','password_recovery'),'{}',payload) WHERE id=?",[$k->now(),$row['id']]); $sent++;
        } catch (Zenith\PreviewRecipientBlocked $e) {
            $k->execute('UPDATE notification_outbox SET locked_at=NULL,attempts=12,last_error=? WHERE id=?',['Blocked by private preview recipient policy.',$row['id']]);$blocked++;
        } catch (Throwable $e) {
            $delay=min(21600,60*(2**min(8,(int)$row['attempts'])));
            $k->execute('UPDATE notification_outbox SET locked_at=NULL,last_error=?,next_attempt_at=? WHERE id=?',['Delivery failed; verify private mail configuration.',gmdate('Y-m-d H:i:s',time()+$delay),$row['id']]); $failed++;
        }
    }
    $k->execute('DELETE FROM rate_limits WHERE expires_at<?',[gmdate('Y-m-d H:i:s',time()-86400)]);
    echo 'Sent: ',$sent,'; retry pending: ',$failed; if($config['preview_enabled']??false)echo '; preview blocked: ',$blocked; echo PHP_EOL;
    if ($failed>0||$blocked>0) exit(1);
} catch (Throwable $e) { fwrite(STDERR,"Notification worker failed. Inspect private configuration and database readiness.\n"); exit(1); }
