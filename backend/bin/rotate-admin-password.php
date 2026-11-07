<?php
declare(strict_types=1);
// Standalone private operator tool; works with a verified, already-staged release.
if(PHP_SAPI!=='cli')exit(1);
ini_set('display_errors','0');
$options=getopt('',['release:','config:','email:','password-stdin']);$restore=null;
try{
    $configPath=realpath($options['config']??'/home/isp85/fashion-private/config.php');$release=realpath($options['release']??'');$email=strtolower(trim($options['email']??''));
    if(!$configPath||!$release||!filter_var($email,FILTER_VALIDATE_EMAIL)||!str_starts_with($release,dirname($configPath).'/releases/'))throw new RuntimeException('Provide the private configuration, verified release and administrator email.');
    $manifest=json_decode((string)file_get_contents($release.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);$kernel=$release.'/private/backend/src/Kernel.php';
    if(($manifest['commit']??'')!==basename($release)||($manifest['dirty']??true)!==false||!isset($manifest['files']['private/backend/src/Kernel.php'])||!is_file($kernel)||is_link($kernel)||!hash_equals($manifest['files']['private/backend/src/Kernel.php'],hash_file('sha256',$kernel)))throw new RuntimeException('Release verification failed; account unchanged.');
    require_once $kernel;$config=require $configPath;$config['session_enabled']=false;$k=new Zenith\Kernel($config);
    $user=$k->one("SELECT * FROM users WHERE email=? AND role='admin' AND disabled_at IS NULL",[$email]);if(!$user)throw new RuntimeException('Active administrator not found; account unchanged.');
    $tty=function_exists('stream_isatty')&&stream_isatty(STDIN);
    if($tty){
        $status=0;ob_start();system('stty -g',$status);$state=trim((string)ob_get_clean());if($status!==0||!preg_match('/^[a-fA-F0-9:]+$/D',$state))throw new RuntimeException('Cannot safely hide terminal input.');
        $restored=false;$restore=static function()use($state,&$restored):void{if(!$restored){$restored=true;system('stty '.escapeshellarg($state));}};register_shutdown_function($restore);
        if(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')){pcntl_async_signals(true);pcntl_signal(SIGINT,static function()use($restore):void{$restore();exit(130);});pcntl_signal(SIGTERM,static function()use($restore):void{$restore();exit(143);});}
        system('stty -echo',$status);if($status!==0)throw new RuntimeException('Cannot safely hide terminal input.');
    }elseif(!array_key_exists('password-stdin',$options))throw new RuntimeException('An interactive terminal is required.');
    try{
        if($tty)echo 'New administrator password (hidden): ';$password=fgets(STDIN,1025);if($tty)echo PHP_EOL.'Confirm new password (hidden): ';$confirmation=fgets(STDIN,1025);
        if($password===false||$confirmation===false)throw new RuntimeException('Two matching passwords are required; account unchanged.');
        $password=rtrim($password,"\r\n");$confirmation=rtrim($confirmation,"\r\n");
    }finally{if($restore){$restore();echo PHP_EOL;}}
    if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))throw new RuntimeException('Use a password of12–72 bytes; account unchanged.');
    if(!hash_equals($password,$confirmation))throw new RuntimeException('Passwords did not match; account unchanged.');
    if(hash_equals((string)$config['db']['password'],$password))throw new RuntimeException('Choose a password different from the database password; account unchanged.');
    if(password_verify($password,$user['password_hash']))throw new RuntimeException('Choose a new password different from the existing administrator password; account unchanged.');
    $hash=password_hash($password,PASSWORD_DEFAULT);$k->user=$k->publicUser($user);
    $k->transaction(function()use($k,$user,$hash):void{
        $current=$k->one("SELECT id FROM users WHERE id=? AND role='admin' AND disabled_at IS NULL FOR UPDATE",[$user['id']]);if(!$current)throw new RuntimeException('Administrator state changed; account unchanged.');
        $k->execute('UPDATE users SET password_hash=?,session_version=session_version+1,updated_at=? WHERE id=?',[$hash,$k->now(),$user['id']]);
        $k->audit('admin.password_rotated','users',$user['id'],['method'=>'private_cli']);
    });unset($password,$confirmation,$hash,$config);
    echo "Administrator password changed. Existing sign-in sessions invalidated.\nDatabase password and public website unchanged.\n";
}catch(Throwable $e){if($restore)$restore();$message=$e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Password update failed; no account change was committed.';fwrite(STDERR,$message.PHP_EOL);exit(1);}
