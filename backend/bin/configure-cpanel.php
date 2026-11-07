<?php
declare(strict_types=1);
// Private CLI installer. Never serves HTTP, overwrites config, changes DB or activates code.
if (PHP_SAPI!=='cli') exit(1);
$options=getopt('', ['release:','private-root:','db-host:','db-port:','password-stdin']);
$privateInput=$options['private-root']??'/home/isp85/fashion-private';
$releaseInput=$options['release']??'';
$host=$options['db-host']??'localhost';
$port=$options['db-port']??'3306';
$restore=null;
try {
    if (!is_string($privateInput)||!str_starts_with($privateInput,'/')||basename($privateInput)!=='fashion-private'||!is_string($releaseInput)||!is_string($host)||!preg_match('/^[a-zA-Z0-9.-]+$/D',$host)||!is_string($port)||!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('Invalid setup paths or database host.');
    $private=realpath($privateInput);$release=realpath($releaseInput);
    if (!$private||!$release||!str_starts_with($release,$private.'/releases/')||!preg_match('/^[a-f0-9]{40}$/D',basename($release))) throw new RuntimeException('Provide the verified private release folder with --release.');
    if (str_contains($private,'/fashion.azenith.ca/')) throw new RuntimeException('Setup must remain outside the public website.');
    $target=$private.'/config.php';
    if (file_exists($target)||is_link($target)) throw new RuntimeException('Private config already exists; it was not read or changed.');
    $manifest=json_decode((string)file_get_contents($release.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $template=$release.'/private/backend/config.example.php';
    if (($manifest['commit']??'')!==basename($release)||($manifest['dirty']??true)!==false||!isset($manifest['files']['private/backend/config.example.php'])||!is_file($template)||is_link($template)||!hash_equals($manifest['files']['private/backend/config.example.php'],hash_file('sha256',$template))) throw new RuntimeException('Private release template verification failed.');
    $config=require $template;
    if (!is_array($config)||($config['environment']??'')!=='production'||($config['manual_payments_enabled']??true)!==false||($config['pricing_min_margin_bps']??null)!==null) throw new RuntimeException('Unexpected production defaults; no configuration written.');
    umask(0077);
    if (!chmod($private,0700)) throw new RuntimeException('Cannot protect private installation directory.');
    foreach (['storage','storage/uploads','sessions','backups'] as $part) {
        $path=$private.'/'.$part;
        if (is_link($path)||(!is_dir($path)&&!mkdir($path,0700,true))||realpath($path)!==$path||!chmod($path,0700)) throw new RuntimeException('Cannot prepare protected private directories.');
    }
    $tty=function_exists('stream_isatty')&&stream_isatty(STDIN);
    if ($tty) {
        $status=0;ob_start();system('stty -g',$status);$state=trim((string)ob_get_clean());
        if ($status!==0||!preg_match('/^[a-fA-F0-9:]+$/D',$state)) throw new RuntimeException('Cannot safely hide terminal input.');
        $saved=$state;$restored=false;
        $restore=static function()use($saved,&$restored):void{if(!$restored){$restored=true;system('stty '.escapeshellarg($saved));}};
        register_shutdown_function($restore);
        if (function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')) {pcntl_async_signals(true);pcntl_signal(SIGINT,static function()use($restore):void{$restore();exit(130);});pcntl_signal(SIGTERM,static function()use($restore):void{$restore();exit(143);});}
        echo 'Database password (hidden): ';system('stty -echo',$status);
        if ($status!==0) throw new RuntimeException('Cannot safely hide terminal input.');
    } elseif (!array_key_exists('password-stdin',$options)) throw new RuntimeException('An interactive terminal is required.');
    try {$password=fgets(STDIN,4098);if($password===false)throw new RuntimeException('No password received.');$password=rtrim($password,"\r\n");if($password===''||strlen($password)>4096)throw new RuntimeException('Invalid password length.');}
    finally {if($restore){$restore();echo PHP_EOL;}}
    $config['db']=['dsn'=>'mysql:host='.$host.';port='.$port.';dbname=isp85_fashion;charset=utf8mb4','user'=>'isp85_fashion','password'=>$password];
    try {
        $db=new PDO($config['db']['dsn'],$config['db']['user'],$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        if ($db->query('SELECT DATABASE()')->fetchColumn()!=='isp85_fashion') throw new RuntimeException('Database identity mismatch.');
        $tables=(int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    } catch (Throwable) {throw new RuntimeException('Database connection could not be verified; no config written.');}
    if ($tables!==0) throw new RuntimeException('Database is not empty; stop for migration-state review. No config written.');
    $config['storage_path']=$private.'/storage';$config['session_path']=$private.'/sessions';$config['lock_path']=$private.'/application.lock';
    $bytes="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
    $file=fopen($target,'x');if(!$file)throw new RuntimeException('Cannot exclusively create private configuration.');
    try {
        if (!chmod($target,0600)||fwrite($file,$bytes)!==strlen($bytes)||!fflush($file)) throw new RuntimeException('Could not safely save private configuration.');
    } catch (Throwable $e) {fclose($file);unlink($target);throw $e;}
    fclose($file);unset($password,$bytes,$config);
    echo "Private configuration saved with permissions 0600.\nDatabase connection verified; existing tables: 0.\nNo database migrations, email/payment activation or public website changes were made.\n";
} catch (Throwable $e) {
    if($restore)$restore();
    // Only our fixed setup diagnostics are emitted, never PDO errors or secret values.
    $message=$e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Private setup failed; no public changes were made.';
    fwrite(STDERR,$message.PHP_EOL);exit(1);
}
