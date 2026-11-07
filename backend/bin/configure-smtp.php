<?php
declare(strict_types=1);

final class ZenithSmtpSetup
{
    public static function reply($socket, array $expected): void
    {
        for ($i=0; $i<50; $i++) {
            $line=fgets($socket,2048);
            if ($line===false || !preg_match('/^([0-9]{3})([ -])/D',$line,$m)) throw new RuntimeException('SMTP response unavailable.');
            if ($m[2]===' ') {
                $code=(int)$m[1];
                if (!in_array($code,$expected,true)) throw new RuntimeException('SMTP verification rejected (code '.$code.').');
                return;
            }
        }
        throw new RuntimeException('SMTP response exceeded its limit.');
    }
    public static function command($socket, string $line, array $codes): void
    {
        $wire=$line."\r\n";$offset=0;
        while ($offset<strlen($wire)) {
            $written=fwrite($socket,substr($wire,$offset));
            if ($written===false || $written===0) throw new RuntimeException('SMTP connection closed.');
            $offset+=$written;
        }
        self::reply($socket,$codes);
    }
    public static function authenticate(array $mail): void
    {
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$mail['host']]]);
        $socket=@stream_socket_client('ssl://'.$mail['host'].':'.$mail['port'],$errno,$error,20,STREAM_CLIENT_CONNECT,$context);
        if (!$socket) throw new RuntimeException('Verified SMTP TLS connection unavailable; configuration unchanged.');
        stream_set_timeout($socket,20);
        try {
            self::reply($socket,[220]);
            self::command($socket,'EHLO fashion.azenith.ca',[250]);
            self::command($socket,'AUTH PLAIN '.base64_encode("\0".$mail['username']."\0".$mail['password']),[235]);
            @fwrite($socket,"QUIT\r\n");
        } finally { fclose($socket); }
    }
    // The optional authenticator is a unit-test seam, not a command-line option.
    public static function save(string $configPath,string $release,string $operator,array $mail,?callable $authenticator=null): void
    {
        $configPath=realpath($configPath)?:'';$release=realpath($release)?:'';
        if (!$configPath || !$release || !str_starts_with($release,dirname($configPath).'/releases/') || !filter_var($operator,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Valid private configuration, release and operator email are required.');
        if ((fileperms($configPath)&0077)!==0) throw new RuntimeException('Private configuration must have owner-only permissions.');
        if (($mail['transport']??'')!=='smtp' || ($mail['encryption']??'')!=='ssl' || !is_string($mail['host']??null) || !preg_match('/^[a-zA-Z0-9.-]+$/D',$mail['host']) || !is_int($mail['port']??null) || $mail['port']<1 || $mail['port']>65535 || !filter_var($mail['username']??'',FILTER_VALIDATE_EMAIL) || !filter_var($mail['from']??'',FILTER_VALIDATE_EMAIL) || $mail['username']!==$mail['from'] || !is_string($mail['password']??null) || $mail['password']==='' || strlen($mail['password'])>4096) throw new RuntimeException('Invalid authenticated SMTP settings.');
        $manifest=json_decode((string)file_get_contents($release.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);$kernel=$release.'/private/backend/src/Kernel.php';
        if (($manifest['commit']??'')!==basename($release) || ($manifest['dirty']??true)!==false || !isset($manifest['files']['private/backend/src/Kernel.php']) || !is_file($kernel) || is_link($kernel) || !hash_equals($manifest['files']['private/backend/src/Kernel.php'],hash_file('sha256',$kernel))) throw new RuntimeException('Staged release verification failed.');
        require_once $kernel;
        $original=require $configPath;$initial=hash_file('sha256',$configPath);$runtime=$original;$runtime['session_enabled']=false;$k=new Zenith\Kernel($runtime);
        $actor=$k->one("SELECT * FROM users WHERE email=? AND role='admin' AND disabled_at IS NULL",[strtolower($operator)]);
        if (!$actor) throw new RuntimeException('Active administrator not found; configuration unchanged.');
        $k->user=$k->publicUser($actor);
        ($authenticator??[self::class,'authenticate'])($mail);
        umask(0077);$private=dirname($configPath);$lock=fopen($private.'/application.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {if($lock)fclose($lock);throw new RuntimeException('Application is busy; retry without changing configuration.');}
        $temp=null;$backup=null;$swapped=false;
        try {
            if (!hash_equals($initial,hash_file('sha256',$configPath))) throw new RuntimeException('Configuration changed during verification; retry.');
            $backups=$private.'/backups';
            if (is_link($backups) || (!is_dir($backups)&&!mkdir($backups,0700)) || realpath($backups)!==$backups || !chmod($backups,0700)) throw new RuntimeException('Private backup directory unavailable.');
            $backup=$backups.'/config-pre-smtp-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.php';
            if (!copy($configPath,$backup) || !chmod($backup,0600)) throw new RuntimeException('Protected configuration backup failed.');
            $updated=$original;$updated['mail']=$mail;$bytes="<?php\ndeclare(strict_types=1);\nreturn ".var_export($updated,true).";\n";
            $temp=tempnam($private,'.smtp-config-');
            if (!$temp || !chmod($temp,0600) || file_put_contents($temp,$bytes)!==strlen($bytes)) throw new RuntimeException('Cannot prepare protected SMTP configuration.');
            $k->transaction(function()use($k,$actor,$mail,$configPath,$temp,&$swapped):void{
                $k->audit('mail.configuration_updated','users',$actor['id'],['host'=>$mail['host'],'port'=>$mail['port'],'sender'=>$mail['from'],'tls_verified'=>true]);
                if (!rename($temp,$configPath)) throw new RuntimeException('Atomic configuration update failed.');
                $swapped=true;
            });
        } catch (Throwable $e) {
            if ($swapped && $backup) {
                $rollback=tempnam($private,'.smtp-rollback-');
                if (!$rollback || !copy($backup,$rollback) || !chmod($rollback,0600) || !rename($rollback,$configPath)) throw new RuntimeException('Configuration rollback requires operator review; private backup retained.');
            }
            throw $e;
        } finally {
            if ($temp && is_file($temp)) unlink($temp);
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    ini_set('display_errors','0');$restore=null;
    try {
        $o=getopt('',['release:','config:','operator-email:','host:','port:','username:','from:','password-stdin']);
        $tty=function_exists('stream_isatty')&&stream_isatty(STDIN);
        if ($tty) {
            $status=0;ob_start();system('stty -g',$status);$state=trim((string)ob_get_clean());
            if ($status!==0 || !preg_match('/^[a-fA-F0-9:]+$/D',$state)) throw new RuntimeException('Cannot safely hide terminal input.');
            $restored=false;$restore=static function()use($state,&$restored):void{if(!$restored){$restored=true;system('stty '.escapeshellarg($state));}};register_shutdown_function($restore);
            if (function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')) {pcntl_async_signals(true);pcntl_signal(SIGINT,static function()use($restore):void{$restore();exit(130);});pcntl_signal(SIGTERM,static function()use($restore):void{$restore();exit(143);});}
            echo 'Mailbox password (hidden): ';system('stty -echo',$status);if($status!==0)throw new RuntimeException('Cannot hide terminal input.');
        } elseif (!array_key_exists('password-stdin',$o)) throw new RuntimeException('Interactive terminal required.');
        try {$password=fgets(STDIN,4098);if($password===false)throw new RuntimeException('No mailbox password received.');$password=rtrim($password,"\r\n");} finally {if($restore){$restore();echo PHP_EOL;}}
        $port=$o['port']??'465';if(!ctype_digit((string)$port))throw new RuntimeException('Invalid SMTP port.');
        $mail=['transport'=>'smtp','host'=>$o['host']??'','port'=>(int)$port,'encryption'=>'ssl','username'=>$o['username']??'','password'=>$password,'from'=>$o['from']??'','from_name'=>'A Zenith Fashion'];
        ZenithSmtpSetup::save($o['config']??'/home/isp85/fashion-private/config.php',$o['release']??'',$o['operator-email']??'',$mail);unset($password,$mail);
        echo "SMTP TLS and login verified. Private email configuration saved with permissions0600.\nNo emails sent. Database credentials and public website unchanged.\n";
    } catch (Throwable $e) {
        if($restore)$restore();$message=$e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Email setup failed; inspect private setup without sharing credentials.';fwrite(STDERR,$message.PHP_EOL);exit(1);
    }
}
