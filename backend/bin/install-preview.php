<?php
declare(strict_types=1);

/** Initial private preview installation. Never drops tables or replaces existing public files. */
final class ZenithPreviewInstaller
{
    private static function write(string $path,string $bytes,int $mode=0600): void
    {
        $f=fopen($path,'x');if(!$f)throw new RuntimeException('Destination already exists or is unavailable.');
        try{if(!chmod($path,$mode))throw new RuntimeException('Cannot protect setup file.');for($offset=0;$offset<strlen($bytes);){$n=fwrite($f,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Setup write failed.');$offset+=$n;}if(!fflush($f))throw new RuntimeException('Setup flush failed.');}finally{fclose($f);}
    }
    private static function command(array $arguments): void
    {
        $command=implode(' ',array_map('escapeshellarg',$arguments));$status=0;system($command,$status);if($status!==0)throw new RuntimeException('Private backup/restore command failed; public site unchanged.');
    }
    private static function inventory(string $root): array
    {
        $result=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        foreach($iterator as $item){$name=substr($item->getPathname(),strlen($root)+1);if($item->isLink())$result[$name]=['link'=>readlink($item->getPathname())];elseif($item->isDir())$result[$name]=['directory'=>true];elseif($item->isFile())$result[$name]=['sha256'=>hash_file('sha256',$item->getPathname()),'bytes'=>$item->getSize()];else throw new RuntimeException('Unsupported file in backup scope.');}ksort($result);return $result;
    }
    private static function removeScratch(string $path): void
    {
        if(!is_dir($path))return;$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as $item){if($item->isLink()||$item->isFile())unlink($item->getPathname());else rmdir($item->getPathname());}rmdir($path);
    }
    private static function copyPrivateTree(string $source,string $destination): void
    {
        if(!mkdir($destination,0700,true))throw new RuntimeException('Private storage destination unavailable.');
        foreach(self::inventory($source) as $name=>$record){if(isset($record['link']))throw new RuntimeException('Private storage symlinks are not supported.');$target=$destination.'/'.$name;if(isset($record['directory'])){if(!mkdir($target,0700))throw new RuntimeException('Cannot prepare private storage.');}else{if(!copy($source.'/'.$name,$target)||!chmod($target,0600)||!hash_equals($record['sha256'],hash_file('sha256',$target)))throw new RuntimeException('Private storage copy failed.');}}
    }
    public static function install(array $options,string $password): array
    {
        umask(0077);$configPath=realpath($options['config']??'/home/isp85/fashion-private/config.php')?:'';$release=realpath($options['release']??'')?:'';$doc=realpath($options['document-root']??'/home/isp85/fashion.azenith.ca')?:'';$operator=strtolower($options['operator-email']??'');
        if(!$configPath||!$release||!$doc||!filter_var($operator,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Valid private config, release, document root and operator email are required.');
        $private=dirname($configPath);if(!str_starts_with($release,$private.'/preview-releases/')||$private===$doc||str_starts_with($private,$doc.'/'))throw new RuntimeException('Preview code and configuration must remain private.');
        $manifest=json_decode((string)file_get_contents($release.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);$prefix=$manifest['base_path']??'';
        if(($manifest['variant']??'')!=='preview'||($manifest['dirty']??true)!==false||($manifest['commit']??'')!==basename($release)||!preg_match('#^/[A-Za-z0-9_-]+$#D',$prefix))throw new RuntimeException('A verified preview-variant package is required.');
        foreach(['src/Kernel.php','src/Preview.php','bin/export-db.php','public/index.php'] as $file){$name='private/backend/'.$file;$path=$release.'/'.$name;if(!isset($manifest['files'][$name])||!is_file($path)||is_link($path)||!hash_equals($manifest['files'][$name],hash_file('sha256',$path)))throw new RuntimeException('Preview runtime checksum mismatch.');}
        $backend=$release.'/private/backend';require_once $backend.'/src/Kernel.php';$original=require $configPath;
        $targetConfig=$private.'/config-preview.php';$publicFolder=$doc.$prefix;
        foreach([$targetConfig,$publicFolder,$private.'/preview-storage',$private.'/preview-sessions',$private.'/preview-gate-sessions'] as $path)if(file_exists($path)||is_link($path))throw new RuntimeException('Preview setup already exists; stop for review instead of overwriting.');
        $dbName=$options['db-name']??'isp85_fashion_preview';$dbUser=$options['db-user']??'isp85_fashion_preview';$host=$options['db-host']??'localhost';$port=$options['db-port']??null;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$dbName)||!preg_match('/^[A-Za-z0-9_]+$/D',$dbUser)||!preg_match('/^[A-Za-z0-9.-]+$/D',$host)||($port!==null&&(!ctype_digit((string)$port)||(int)$port<1||(int)$port>65535))||$password===''||strlen($password)>4096)throw new RuntimeException('Invalid dedicated preview database settings.');
        preg_match('/(?:^|;)dbname=([^;]+)/',$original['db']['dsn'],$sourceName);
        if($dbName===($sourceName[1]??'')||$dbUser===$original['db']['user']||hash_equals((string)$original['db']['password'],$password))throw new RuntimeException('Preview database, user and password must differ from the main database. Nothing installed.');
        $runtime=$original;$runtime['session_enabled']=false;$k=new Zenith\Kernel($runtime);$actor=$k->one("SELECT * FROM users WHERE email=? AND role='admin' AND disabled_at IS NULL",[$operator]);if(!$actor)throw new RuntimeException('Active owner administrator required.');$k->user=$k->publicUser($actor);
        if($k->one('SELECT id FROM notification_outbox WHERE sent_at IS NULL LIMIT 1'))throw new RuntimeException('Wait until the primary notification queue is empty before taking the initial preview snapshot.');
        $dsn='mysql:host='.$host.($port!==null?';port='.$port:'').';dbname='.$dbName.';charset=utf8mb4';
        try{$target=new PDO($dsn,$dbUser,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$tables=(int)$target->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();}catch(Throwable){throw new RuntimeException('Preview database connection failed; main configuration unchanged.');}
        if($target->query('SELECT DATABASE()')->fetchColumn()!==$dbName||$tables!==0)throw new RuntimeException('The dedicated preview database must be empty. No tables were dropped.');
        $installLock=fopen($private.'/preview-install.lock','c');if(!$installLock||!flock($installLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another preview installation is running.');
        $sourceLock=null;$migrationLock=false;$scratch=null;$publicScratch=null;$backup=null;
        try{
            $backups=$private.'/backups';if(!is_dir($backups)&&!mkdir($backups,0700))throw new RuntimeException('Private backups unavailable.');if(is_link($backups)||!chmod($backups,0700))throw new RuntimeException('Private backup directory is unsafe.');
            $backup=$backups.'/before-preview-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));if(!mkdir($backup,0700))throw new RuntimeException('Cannot prepare private backup.');
            $sourceLock=fopen($private.'/application.lock','c');if(!$sourceLock||!flock($sourceLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Main application is busy; retry when idle.');
            $migrationLock=(int)$k->one("SELECT GET_LOCK('zenith_schema_migrations',0) acquired")['acquired']===1;if(!$migrationLock)throw new RuntimeException('A migration is active; retry after it completes.');
            $inventory=self::inventory($doc);self::write($backup.'/public-inventory.json',json_encode($inventory,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            self::command(['tar','-czf',$backup.'/public-site.tar.gz','-C',$doc,'.']);chmod($backup.'/public-site.tar.gz',0600);
            $scratch=$private.'/.preview-restore-'.bin2hex(random_bytes(8));mkdir($scratch,0700);self::command(['tar','-xzf',$backup.'/public-site.tar.gz','-C',$scratch,'--no-same-owner','--same-permissions']);
            if(self::inventory($scratch)!==$inventory)throw new RuntimeException('Public file backup restore did not match. Public files unchanged.');self::removeScratch($scratch);$scratch=null;
            if(!copy($configPath,$backup.'/config.php')||!chmod($backup.'/config.php',0600))throw new RuntimeException('Private configuration backup failed.');
            $storage=realpath($original['storage_path']??'');if(!$storage||$storage===$private||str_starts_with($backup,$storage.'/')||$storage===$doc||str_starts_with($storage,$doc.'/'))throw new RuntimeException('Private storage backup scope is unsafe.');
            self::copyPrivateTree($storage,$backup.'/storage');
            self::command([PHP_BINARY,$backend.'/bin/export-db.php','--config',$configPath,'--output',$backup.'/database.sql','--manifest',$backup.'/database-snapshot.json']);
            $k->one("SELECT RELEASE_LOCK('zenith_schema_migrations')");$migrationLock=false;flock($sourceLock,LOCK_UN);fclose($sourceLock);$sourceLock=null;
            $snapshot=json_decode((string)file_get_contents($backup.'/database-snapshot.json'),true,512,JSON_THROW_ON_ERROR);
            if(filesize($backup.'/database.sql')>64*1024*1024)throw new RuntimeException('Snapshot needs a streaming restore procedure; no preview tables changed.');
            $target->exec((string)file_get_contents($backup.'/database.sql'));
            foreach($snapshot['tables'] as $table=>$count){if(!preg_match('/^[A-Za-z0-9_]+$/D',$table)||(int)$target->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==$count)throw new RuntimeException('Restored preview row counts differ; public site unchanged.');}
            $triggers=(int)$target->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn();if($triggers!==$snapshot['triggers'])throw new RuntimeException('Restored financial triggers differ; public site unchanged.');
            foreach($snapshot['financial_totals'] as $table=>$total)if(!in_array($table,['payments','refunds','partner_ledger'],true)||(string)$target->query('SELECT COALESCE(SUM(amount_minor),0) FROM '.$table)->fetchColumn()!==$total)throw new RuntimeException('Restored financial totals differ; public site unchanged.');
            self::copyPrivateTree($backup.'/storage',$private.'/preview-storage');foreach(['preview-sessions','preview-gate-sessions'] as $dir)if(!mkdir($private.'/'.$dir,0700))throw new RuntimeException('Cannot create isolated preview sessions.');
            $preview=$original;$preview['db']=['dsn'=>$dsn,'user'=>$dbUser,'password'=>$password];$preview['public_base_path']=$prefix;$preview['preview_enabled']=true;$preview['preview_gate_config']=$configPath;$preview['preview_operator_email']=$operator;
            $mainSession=$original['session_name']??'__Host-zenith';$preview['session_name']=$mainSession.'-preview';$preview['preview_gate_session_name']=$mainSession.'-preview-gate';$preview['session_path']=$private.'/preview-sessions';$preview['preview_gate_session_path']=$private.'/preview-gate-sessions';$preview['storage_path']=$private.'/preview-storage';$preview['lock_path']=$private.'/preview-application.lock';
            $preview['manual_payments_enabled']=false;$preview['manual_payment_approval_reference']='';$preview['stripe_secret_key']='';$preview['stripe_webhook_secret']='';$preview['pricing_min_margin_bps']=null;$preview['pricing_approval_reference']='';
            self::write($targetConfig,"<?php\ndeclare(strict_types=1);\nreturn ".var_export($preview,true).";\n");
            self::write($private.'/preview-installation.json',json_encode(['commit'=>$manifest['commit'],'base_path'=>$prefix,'backup'=>$backup,'table_count'=>count($snapshot['tables']),'triggers'=>$triggers,'installed_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            $publicScratch=$private.'/.preview-public-'.bin2hex(random_bytes(8));if(!mkdir($publicScratch,0755)||!chmod($publicScratch,0755))throw new RuntimeException('Cannot prepare web-readable preview entry directory.');
            self::write($publicScratch.'/index.php','<?php declare(strict_types=1); require '.var_export($backend.'/src/Preview.php',true).'; Zenith\\Preview::run('.var_export($targetConfig,true).','.var_export($release,true).');',0644);
            self::write($publicScratch.'/.htaccess',"Options -Indexes -MultiViews\nDirectoryIndex index.php\nLimitRequestBody 9437184\nRewriteEngine On\nRewriteRule ^index\\.php$ - [L]\nRewriteRule ^ index.php [QSA,L]\n",0644);
            if(file_exists($publicFolder)||is_link($publicFolder))throw new RuntimeException('Preview URL already exists; no public file replaced.');
            $k->audit('preview.prepared','users',$actor['id'],['commit'=>$manifest['commit'],'base_path'=>$prefix,'restored_tables'=>count($snapshot['tables']),'triggers'=>$triggers]);
            if(!rename($publicScratch,$publicFolder))throw new RuntimeException('Private restore succeeded, but preview URL publication needs operator review.');$publicScratch=null;
            return ['url'=>rtrim($original['public_origin'],'/').$prefix.'/','tables'=>count($snapshot['tables']),'triggers'=>$triggers,'backup'=>$backup];
        }finally{
            if($migrationLock)$k->one("SELECT RELEASE_LOCK('zenith_schema_migrations')");if(is_resource($sourceLock)){flock($sourceLock,LOCK_UN);fclose($sourceLock);}if($scratch)self::removeScratch($scratch);if($publicScratch)self::removeScratch($publicScratch);flock($installLock,LOCK_UN);fclose($installLock);
        }
    }
}

if(PHP_SAPI==='cli'&&realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    ini_set('display_errors','0');$restore=null;
    try{
        $o=getopt('',['config:','release:','document-root:','operator-email:','db-name:','db-user:','db-host:','db-port:','password-stdin']);$tty=function_exists('stream_isatty')&&stream_isatty(STDIN);
        if($tty){$status=0;ob_start();system('stty -g',$status);$state=trim((string)ob_get_clean());if($status!==0||!preg_match('/^[a-fA-F0-9:]+$/D',$state))throw new RuntimeException('Cannot safely hide terminal input.');$restored=false;$restore=static function()use($state,&$restored):void{if(!$restored){$restored=true;system('stty '.escapeshellarg($state));}};register_shutdown_function($restore);if(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')){pcntl_async_signals(true);pcntl_signal(SIGINT,static function()use($restore):void{$restore();exit(130);});pcntl_signal(SIGTERM,static function()use($restore):void{$restore();exit(143);});}echo 'Separate preview database password (hidden): ';system('stty -echo',$status);if($status!==0)throw new RuntimeException('Cannot hide terminal input.');}elseif(!array_key_exists('password-stdin',$o))throw new RuntimeException('Interactive terminal required.');
        try{$password=fgets(STDIN,4098);if($password===false)throw new RuntimeException('No preview database password supplied.');$password=rtrim($password,"\r\n");}finally{if($restore){$restore();echo PHP_EOL;}}
        $result=ZenithPreviewInstaller::install($o,$password);unset($password);
        echo 'Private preview installed: '.$result['url'].PHP_EOL.'Restored and verified: '.$result['tables'].' tables; '.$result['triggers'].' triggers; financial totals match.'.PHP_EOL.'Public file backup restored and matched; private backup: '.$result['backup'].PHP_EOL."Existing public homepage unchanged. Payment processing remains disabled in preview.\n";
    }catch(Throwable $e){if($restore)$restore();$message=$e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Preview installation stopped; private database/setup review required. Do not retry a partially restored database blindly.';fwrite(STDERR,$message.PHP_EOL);exit(1);}
}
