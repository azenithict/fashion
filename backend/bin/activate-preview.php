<?php
declare(strict_types=1);
// Code-only preview update. Never applies migrations or overwrites configuration/data.
if(PHP_SAPI!=='cli')exit(1);
ini_set('display_errors','0');umask(0077);$lock=null;$backup=null;$entry=null;$switched=false;
try{
 $o=getopt('',['release:']);$private='/home/isp85/fashion-private';$release=realpath($o['release']??'')?:'';$configPath=$private.'/config-preview.php';
 if(!preg_match('#^'.preg_quote($private,'#').'/preview-releases/[a-f0-9]{40}$#D',$release))throw new RuntimeException('A privately staged preview release is required.');
 $manifest=json_decode((string)file_get_contents($release.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($manifest['variant']??'')!=='preview'||($manifest['dirty']??true)||basename($release)!==($manifest['commit']??'')||($manifest['base_path']??'')!=='/_fashion-preview')throw new RuntimeException('Wrong preview package.');
 $pipes=[];$process=proc_open([PHP_BINARY,$release.'/private/backend/bin/verify-release.php',$release],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Release verification failed.');
 $config=require $configPath;if(($config['preview_enabled']??false)!==true||($config['public_base_path']??'')!=='/_fashion-preview')throw new RuntimeException('Existing isolated preview configuration required.');
 $db=new PDO($config['db']['dsn'],$config['db']['user'],$config['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$applied=$db->query('SELECT name,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);$files=glob($release.'/private/backend/migrations/*.sql');if(count($files)!==count($applied))throw new RuntimeException('Schema change needs separate review; no migration applied.');foreach($files as $file)if(!isset($applied[basename($file)])||!hash_equals($applied[basename($file)],hash_file('sha256',$file)))throw new RuntimeException('Migration checksum differs; no migration applied.');
 $lock=fopen($config['lock_path']??$private.'/preview-application.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Preview busy; no update performed.');
 $entry='/home/isp85/fashion.azenith.ca/_fashion-preview/index.php';if(!is_file($entry)||is_link($entry)||!is_file(dirname($entry).'/.htaccess'))throw new RuntimeException('Existing preview entry missing.');
 $home=dirname(dirname($entry)).'/index.html';$homeHash=is_file($home)?hash_file('sha256',$home):null;
 $backup=$private.'/preview-code-backups/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));if(!mkdir($backup,0700,true)||!copy($entry,$backup.'/index.php')||!chmod($backup.'/index.php',0600))throw new RuntimeException('Private entry backup failed.');
 $bytes='<?php declare(strict_types=1); require '.var_export($release.'/private/backend/src/Preview.php',true).'; Zenith\\Preview::run('.var_export($configPath,true).','.var_export($release,true).');';$temp=dirname($entry).'/.entry-'.bin2hex(random_bytes(6));if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)||!chmod($temp,0644)||!rename($temp,$entry))throw new RuntimeException('Entry update failed.');$switched=true;
 // Release the writer lock before HTTP acceptance, whose API may acquire a read lock.
 flock($lock,LOCK_UN);fclose($lock);$lock=null;
 $headers=[];$url=rtrim($config['public_origin'],'/').'/_fashion-preview/';$h=curl_init($url);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$headers){$headers[]=strtolower($line);return strlen($line);}]);$body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 if($status!==200||!is_string($body)||!str_contains($body,'Administrator password')||!str_contains(implode('',$headers),'noindex'))throw new RuntimeException('Preview gate health check failed; previous entry restored.');
 if($homeHash!==null&&!hash_equals($homeHash,hash_file('sha256',$home)))throw new RuntimeException('Existing homepage changed concurrently; stop for review.');
 file_put_contents($private.'/preview-active.json',json_encode(['commit'=>$manifest['commit'],'release'=>$release,'previous_entry'=>$backup.'/index.php','activated_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($private.'/preview-active.json',0600);
 echo 'Activated private preview '.$manifest['commit'].PHP_EOL.'Owner gate verified. Database, private configuration and existing homepage retained.'.PHP_EOL.'Rollback entry: '.$backup.'/index.php'.PHP_EOL;
}catch(Throwable $e){if($switched&&$backup&&$entry){$rollback=$entry.'.rollback-'.bin2hex(random_bytes(4));if(copy($backup.'/index.php',$rollback)&&chmod($rollback,0644))rename($rollback,$entry);}fwrite(STDERR,($e instanceof RuntimeException&&!$e instanceof PDOException?$e->getMessage():'Preview update stopped; private setup review required.').PHP_EOL);exit(1);}finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
