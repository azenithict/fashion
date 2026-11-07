<?php
declare(strict_types=1);
$configPath=getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php';$config=require $configPath;if(($config['environment']??'')!=='test')throw new RuntimeException('Isolated test configuration required.');$config['session_enabled']=false;
require __DIR__.'/../src/Kernel.php';$k=new Zenith\Kernel($config);$id=$k->id();$email='rotation-'.$id.'@example.test';$old='Old-test-admin-password-1!';$new="Unique-new-'quoted-password-2!";$now=$k->now();
$k->execute("INSERT INTO users(id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES(?,?,?,?,'admin',?,?,?)",[$id,'Test rotation administrator',$email,password_hash($old,PASSWORD_DEFAULT),$now,$now,$now]);
$root=sys_get_temp_dir().'/zenith-rotation-'.bin2hex(random_bytes(8));$release=$root.'/releases/'.str_repeat('b',40);mkdir($release.'/private/backend/src',0700,true);mkdir($release.'/private/shared',0700,true);
copy(__DIR__.'/../src/Kernel.php',$release.'/private/backend/src/Kernel.php');copy(__DIR__.'/../../shared/contract.json',$release.'/private/shared/contract.json');
file_put_contents($release.'/release-manifest.json',json_encode(['commit'=>str_repeat('b',40),'dirty'=>false,'files'=>['private/backend/src/Kernel.php'=>hash_file('sha256',__DIR__.'/../src/Kernel.php')]]));$cfg=$root.'/config.php';file_put_contents($cfg,'<?php return '.var_export($config,true).';');chmod($cfg,0600);$configHash=hash_file('sha256',$cfg);$checks=0;
function check(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;}
function rotate(string $release,string $cfg,string $email,string $one,string $two):array{
    $pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/../bin/rotate-admin-password.php','--release',$release,'--config',$cfg,'--email',$email,'--password-stdin'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$one."\n".$two."\n");fclose($pipes[0]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out];
}
try{
    [$code,$out]=rotate($release,$cfg,$email,$config['db']['password'],$config['db']['password']);check($code!==0&&str_contains($out,'different from the database'),'Database password reuse rejected');
    [$code]=rotate($release,$cfg,$email,$old,$old);check($code!==0,'Existing administrator password rejected');
    [$code]=rotate($release,$cfg,$email,$new,'Mismatch-test-password');check($code!==0,'Mismatched confirmation rejected');
    [$code]=rotate($release,$cfg,$email,'short','short');check($code!==0,'Short password rejected');
    $before=$k->one('SELECT * FROM users WHERE id=?',[$id]);check(password_verify($old,$before['password_hash']),'Rejected changes preserve old credentials');
    [$code,$out]=rotate($release,$cfg,$email,$new,$new);check($code===0,'Confirmed unique password accepted');check(!str_contains($out,$new),'Password absent from output');
    $after=$k->one('SELECT * FROM users WHERE id=?',[$id]);check(password_verify($new,$after['password_hash'])&&!password_verify($old,$after['password_hash']),'Only new password verifies');
    check((int)$after['session_version']===(int)$before['session_version']+1,'Existing sessions invalidated');check($after['role']==='admin'&&$after['email']===$email,'Role and email preserved');
    check(hash_equals($configHash,hash_file('sha256',$cfg)),'Database configuration unchanged');check((int)$k->one("SELECT COUNT(*) n FROM audit_events WHERE entity_id=? AND action='admin.password_rotated'",[$id])['n']===1,'One password-free audit event recorded');
    echo 'PASS '.$checks." administrator password rotation assertions.\n";
}finally{
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){if($file->isFile()||$file->isLink())unlink($file->getPathname());else rmdir($file->getPathname());}rmdir($root);
}
