<?php
declare(strict_types=1);
namespace Zenith;
use RuntimeException;
final class PreviewRecipientBlocked extends RuntimeException {}
final class Mailer
{
    public static function recipientAllowed(array $config,string $to): bool
    {
        if (($config['preview_enabled']??false)!==true) return true;
        $owner=strtolower($config['preview_operator_email']??'');$to=strtolower($to);
        if (!filter_var($owner,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
        if ($to===$owner) return true;
        [$ownerLocal,$ownerDomain]=explode('@',$owner,2);[$local,$domain]=explode('@',$to,2);
        return $ownerDomain==='gmail.com' && $domain===$ownerDomain && explode('+',$local,2)[0]===$ownerLocal;
    }
private static function read($socket,array $codes): void {
    $reply=''; $lineCount=0;
    do {
        $line=fgets($socket,2048);
        if ($line===false||++$lineCount>50) throw new RuntimeException('SMTP response unavailable');
        $reply=$line;
    } while (strlen($line)>=4&&$line[3]==='-');
    if (!in_array((int)substr($reply,0,3),$codes,true)) throw new RuntimeException('SMTP command rejected');
}
private static function command($socket,string $line,array $codes): void {
    $wire=$line."\r\n"; $offset=0;
    while ($offset<strlen($wire)) { $written=fwrite($socket,substr($wire,$offset)); if ($written===false||$written===0) throw new RuntimeException('SMTP connection failed'); $offset+=$written; }
    self::read($socket,$codes);
}
public static function send(array $config,string $to,string $event,array $payload): void {
    $mail=$config['mail']??[]; $from=$mail['from']??'';
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($from,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to.$from)) throw new RuntimeException('Mail address configuration unavailable');
    if (!self::recipientAllowed($config,$to)) throw new PreviewRecipientBlocked('Private preview recipient is not allowed');
    $subject=(($config['preview_enabled']??false)?'[Private preview] ':'').'A Zenith Fashion — '.ucwords(str_replace('_',' ',$event));
    $origin=Kernel::baseUrl($config);
    $url=$payload['url']??($origin.'/account/');
    if (!is_string($url)||!str_starts_with($url,$origin.'/')||str_contains($url,"\r")||str_contains($url,"\n")) throw new RuntimeException('Mail link configuration unavailable');
    $reference=is_string($payload['reference']??null)&&preg_match('/^AZF-[A-Z0-9]{6}$/D',$payload['reference'])?$payload['reference']:'';
    if ($event==='enquiry_received'&&$reference!=='') { $subject=(($config['preview_enabled']??false)?'[Private preview] ':'').'A Zenith Fashion — Request received ('.$reference.')';
        $body="Thank you for contacting A Zenith Fashion.\r\n\r\nWe have recorded your request. Reference: ".$reference."\r\n\r\nA member of our team will review it and reply by your preferred contact method. This acknowledgement is not a quotation, order or production commitment, and nothing has been charged.\r\n\r\n".$url."\r\n\r\nIf you did not submit this request, you can ignore this message.\r\n"; }
    elseif ($event==='enquiry_new'&&$reference!=='') $body="A new enquiry (".$reference.") is waiting in the operations queue.\r\n\r\n".$url."\r\n";
    else $body="An update is available in your A Zenith Fashion account.\r\n\r\n".$url."\r\n\r\nIf you did not request this message, contact the business through its website.\r\n";
    if ($config['preview_enabled']??false) $body="This is a private-preview email, not a live customer notification.\r\n\r\n".$body;
    $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
    $headers=['From: A Zenith Fashion <'.$from.'>','To: '.$to,'Subject: '.$encodedSubject,'Date: '.gmdate('D, d M Y H:i:s').' +0000','Message-ID: <'.bin2hex(random_bytes(16)).'@'.parse_url($origin,PHP_URL_HOST).'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
    $encodedBody=chunk_split(base64_encode($body),76,"\r\n");
    if (($mail['transport']??'smtp')==='sendmail') {
        if (!mail($to,$encodedSubject,$encodedBody,implode("\r\n",array_filter($headers,fn($h)=>!str_starts_with($h,'To: ')&&!str_starts_with($h,'Subject: '))))) throw new RuntimeException('Local mail handoff failed');
        return;
    }
    if (($mail['transport']??'smtp')!=='smtp'||empty($mail['host'])||empty($mail['username'])||empty($mail['password'])||!in_array($mail['encryption']??'', ['tls','ssl'],true)) throw new RuntimeException('Authenticated mail configuration unavailable');
    $host=$mail['host']; $port=(int)($mail['port']??587);
    if (!is_string($host)||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host)||$port<1||$port>65535) throw new RuntimeException('Mail host configuration invalid');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
    $socket=stream_socket_client(($mail['encryption']==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$error,20,STREAM_CLIENT_CONNECT,$context);
    if (!$socket) throw new RuntimeException('SMTP connection failed'); stream_set_timeout($socket,20);
    try {
        self::read($socket,[220]); self::command($socket,'EHLO fashion.azenith.ca',[250]);
        if ($mail['encryption']==='tls') { self::command($socket,'STARTTLS',[220]); if (!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS unavailable'); self::command($socket,'EHLO fashion.azenith.ca',[250]); }
        self::command($socket,'AUTH PLAIN '.base64_encode("\0".$mail['username']."\0".$mail['password']),[235]);
        self::command($socket,'MAIL FROM:<'.$from.'>',[250]); self::command($socket,'RCPT TO:<'.$to.'>',[250,251]); self::command($socket,'DATA',[354]);
        $message=implode("\r\n",$headers)."\r\n\r\n".$encodedBody;
        $message=preg_replace('/(^|\r\n)\./','$1..',$message);
        self::command($socket,rtrim($message,"\r\n")."\r\n.",[250]);
        // Acceptance after DATA is the durable handoff; a failed polite QUIT must not trigger duplicate email.
        @fwrite($socket,"QUIT\r\n");
    } finally { fclose($socket); }
}
}
