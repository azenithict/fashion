<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/Kernel.php';
$options=getopt('', ['config:','email:','name:']);
$configPath=$options['config']??getenv('ZENITH_CONFIG'); $email=strtolower(trim($options['email']??'')); $name=trim($options['name']??'');
if (!is_string($configPath)||!is_file($configPath)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||$name===''||mb_strlen($name)>120) { fwrite(STDERR,"Usage: php bootstrap-admin.php --config /private/config.php --email owner@example.com --name 'Owner name'\nPassword is read from standard input, never command arguments.\n"); exit(2); }
try {
    $config=require $configPath; $config['session_enabled']=false; $k=new Zenith\Kernel($config);
    if ($k->one('SELECT id FROM users WHERE email=?',[$email])) throw new RuntimeException('Existing account');
    $tty=function_exists('stream_isatty')&&stream_isatty(STDIN);
    if ($tty) { fwrite(STDERR,'Administrator password (12–72 bytes): '); shell_exec('stty -echo'); }
    try { $password=rtrim((string)fgets(STDIN),"\r\n"); } finally { if ($tty) { shell_exec('stty echo'); fwrite(STDERR,PHP_EOL); } }
    if (strlen($password)<12||strlen($password)>72) throw new RuntimeException('Invalid password length');
    $id=$k->id(); $now=$k->now(); $k->transaction(function () use ($k,$id,$name,$email,$password,$now): void {
        $k->execute("INSERT INTO users (id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,'admin',?,?,?)",[$id,$name,$email,password_hash($password,PASSWORD_DEFAULT),$now,$now,$now]);
        $k->audit('admin.bootstrapped','users',$id);
    });
    echo 'Administrator created. Sign in through the account page.',PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,"Administrator creation failed. Confirm setup, a new email address, and password requirements.\n"); exit(1); }
