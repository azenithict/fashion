<?php
declare(strict_types=1);
// Two disposable test databases only; no real SMTP calls are made by this suite.
$sourcePath=$argv[1]??getenv('ZENITH_TEST_CONFIG');$targetPath=$argv[2]??null;
if(!$sourcePath||!$targetPath)throw new RuntimeException('Provide isolated source and preview test configurations.');
$source=require $sourcePath;$target=require $targetPath;
foreach([$source,$target] as $c)if(($c['environment']??'')!=='test'||!str_contains($c['db']['dsn'],'dbname=zenith_test'))throw new RuntimeException('Test-only databases required.');
if($source['db']['dsn']===$target['db']['dsn'])throw new RuntimeException('Separate databases required.');
$connect=static fn($c)=>new PDO($c['db']['dsn'],$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$primary=$connect($source);$preview=$connect($target);
if((int)$preview->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==37)throw new RuntimeException('Restore/migrate the isolated preview database first.');
$primary->exec('DELETE FROM rate_limits');$preview->exec('DELETE FROM rate_limits');$root=sys_get_temp_dir().'/zenith-preview-'.bin2hex(random_bytes(6));$release=$root.'/release';$web=$root.'/web';mkdir($release.'/private/backend/src',0700,true);mkdir($release.'/private/backend/public',0700,true);mkdir($release.'/private/shared',0700,true);mkdir($release.'/public/_next',0700,true);mkdir($web.'/_fashion-preview',0755,true);
foreach(glob(__DIR__.'/../src/*.php') as $file)copy($file,$release.'/private/backend/src/'.basename($file));copy(__DIR__.'/../public/index.php',$release.'/private/backend/public/index.php');copy(__DIR__.'/../../shared/contract.json',$release.'/private/shared/contract.json');file_put_contents($release.'/public/index.html','PRIVATE_COMPILED_PAGE');file_put_contents($release.'/public/_next/test.js','window.privateCompiledAsset=true;');file_put_contents($release.'/public/secret.php','NEVER_SERVE_PHP_SOURCE');file_put_contents($release.'/public/.env','NEVER_SERVE_DOTFILE');file_put_contents($root.'/outside.txt','NEVER_SERVE_OUTSIDE');file_put_contents($web.'/index.html','ORIGINAL_PUBLIC_SITE');
foreach([$source['session_path'],$target['session_path'],$target['storage_path'].'/uploads'] as $dir)if(!is_dir($dir))mkdir($dir,0700,true);
$target['public_origin']='http://127.0.0.1:8091';$target['public_base_path']='/_fashion-preview';$target['preview_enabled']=true;$target['preview_gate_config']=realpath($sourcePath);$target['preview_gate_session_name']='preview_test_gate';$target['session_name']='preview_test_app';$target['session_path']=$root.'/app-sessions';$target['preview_gate_session_path']=$root.'/gate-sessions';mkdir($target['session_path'],0700);mkdir($target['preview_gate_session_path'],0700);$target['lock_path']=$root.'/application.lock';$target['session_secure']=false;
$cfg=$root.'/config.php';file_put_contents($cfg,'<?php return '.var_export($target,true).';');chmod($cfg,0600);file_put_contents($web.'/_fashion-preview/index.php','<?php require '.var_export($release.'/private/backend/src/Preview.php',true).'; Zenith\\Preview::run('.var_export($cfg,true).','.var_export($release,true).');');
$router=$root.'/router.php';file_put_contents($router,'<?php if(str_starts_with(parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH),"/_fashion-preview/")){require '.var_export($web.'/_fashion-preview/index.php',true).';return true;}return false;');
$id=bin2hex(random_bytes(16));$password='Test-only-preview-owner-456!';$email='preview-owner-'.$id.'@example.test';$customerEmail='preview-nonadmin-'.$id.'@example.test';$now=gmdate('Y-m-d H:i:s');
foreach([$primary,$preview] as $db)$db->prepare("INSERT INTO users(id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES(?,?,?,?,'admin',?,?,?)")->execute([$id,'Test preview owner',$email,password_hash($password,PASSWORD_DEFAULT),$now,$now,$now]);
$primary->prepare("INSERT INTO users(id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES(?,?,?,?,'customer',?,?,?)")->execute([bin2hex(random_bytes(16)),'Non-admin gate test',$customerEmail,password_hash($password,PASSWORD_DEFAULT),$now,$now,$now]);
$sourceCount=(int)$primary->query('SELECT COUNT(*) FROM users')->fetchColumn();$checks=0;
function check(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;}
final class PreviewClient{
 public int $status=0;public string $type='';private string $jar;
 public function __construct(){$this->jar=tempnam(sys_get_temp_dir(),'preview-cookie-');}
 public function __destruct(){@unlink($this->jar);}
 public function request(string $path,string $method='GET',?array $data=null,?string $csrf=null,bool $form=false):string{
  $h=curl_init('http://127.0.0.1:8091'.$path);$headers=['Origin: http://127.0.0.1:8091'];if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;if(!$form&&$data!==null)$headers[]='Content-Type: application/json';
  curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>10,CURLOPT_PATH_AS_IS=>true,CURLOPT_NOPROXY=>'127.0.0.1']);if($data!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$form?http_build_query($data):json_encode((object)$data));
  $body=curl_exec($h);if($body===false)throw new RuntimeException('HTTP test unavailable');$this->status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$this->type=(string)curl_getinfo($h,CURLINFO_CONTENT_TYPE);curl_close($h);return $body;
 }
 public function json(string $path,string $method='GET',?array $data=null,?string $csrf=null):array{return json_decode($this->request('/_fashion-preview/api/v1'.$path,$method,$data,$csrf),true,512,JSON_THROW_ON_ERROR);}
}
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:8091','-t',$web,$router],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);fclose($pipes[0]);
try{
 $guest=new PreviewClient;for($i=0;$i<50;$i++){try{$html=$guest->request('/_fashion-preview/');if($guest->status)break;}catch(Throwable){}usleep(100000);}
 check(str_contains($html,'Private preview')&&!str_contains($html,'PRIVATE_COMPILED_PAGE'),'Guest receives gate, not compiled page');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);$gateCsrf=$m[1]??'';check(strlen($gateCsrf)===64,'Gate CSRF token generated');
 $guest->json('/session');check($guest->status===401,'Guest API access denied');$body=$guest->request('/_fashion-preview/_next/test.js');check(!str_contains($body,'privateCompiledAsset=true'),'Guest compiled assets protected');
 $guest->request('/_fashion-preview/_gate/login','POST',['email'=>$email,'password'=>$password,'csrf'=>'incorrect'],null,true);check($guest->status===419,'Gate login requires CSRF');
 $html=$guest->request('/_fashion-preview/');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);$gateCsrf=$m[1];
 $guest->request('/_fashion-preview/_gate/login','POST',['email'=>$customerEmail,'password'=>$password,'csrf'=>$gateCsrf,'return'=>'/_fashion-preview/'],null,true);check($guest->status===403,'Non-admin cannot unlock preview');
 $html=$guest->request('/_fashion-preview/');preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m);$gateCsrf=$m[1];
 $guest->request('/_fashion-preview/_gate/login','POST',['email'=>$email,'password'=>$password,'csrf'=>$gateCsrf,'return'=>'/_fashion-preview/'],null,true);check($guest->status===303,'Owner login unlocks preview');check($guest->request('/_fashion-preview/')==='PRIVATE_COMPILED_PAGE','Owner receives compiled page');
 $session=$guest->json('/session')['data'];check(($session['user']['role']??'')==='admin','Owner SSO establishes separate app session');check($session['csrf']!==$gateCsrf,'Gate and app CSRF/session state are separate');
 $guest->json('/logout','POST',[],$session['csrf']);check($guest->status===200,'App logout works under owner gate');check($guest->request('/_fashion-preview/')==='PRIVATE_COMPILED_PAGE','App logout does not destroy owner gate');
 $session=$guest->json('/session')['data'];$newEmail='preview-new-'.$id.'@example.test';$created=$guest->json('/register','POST',['name'=>'Test preview customer','email'=>$newEmail,'password'=>'Test-only-customer-456!'],$session['csrf']);check($guest->status===200&&$created['data']['role']==='customer','Customer registration works behind owner gate');
 check((int)$primary->query('SELECT COUNT(*) FROM users')->fetchColumn()===$sourceCount,'Preview registration does not change primary database');
 $statement=$preview->prepare("SELECT payload FROM notification_outbox WHERE user_id=? AND event='account_verification' ORDER BY created_at DESC LIMIT 1");$statement->execute([$created['data']['id']]);$payload=json_decode($statement->fetchColumn(),true);check(str_starts_with($payload['url'],'http://127.0.0.1:8091/_fashion-preview/account/?action=verify#token='),'Verification links target private prefix');
 $session=$guest->json('/session')['data'];$notifications=$guest->json('/notifications')['data'];check(!str_contains(json_encode($notifications),'#token='),'Auth tokens not exposed in in-app notifications');
 foreach(['/../outside.txt','/%2e%2e/outside.txt','/.env','/secret.php'] as $path){$body=$guest->request('/_fashion-preview'.$path);check($guest->status===404&&!str_contains($body,'NEVER_SERVE'),'Traversal/dotfile/PHP source rejected');}
 $asset=$guest->request('/_fashion-preview/_next/test.js');check($asset==='window.privateCompiledAsset=true;'&&str_contains($guest->type,'javascript'),'Authenticated compiled asset served with correct type');
 $guest->json('/session?zenithBackendOverride=/invalid');check($guest->status===200,'Request parameters cannot override API bootstrap');
 check($guest->request('/')==='ORIGINAL_PUBLIC_SITE','Existing public homepage unchanged');
 $primary->prepare('UPDATE users SET session_version=session_version+1 WHERE id=?')->execute([$id]);$guest->json('/session');check($guest->status===401,'Primary owner session revocation locks preview');
 echo 'PASS '.$checks." private preview HTTP assertions.\n";
}finally{
 proc_terminate($process);proc_close($process);
 $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as $item){if($item->isFile()||$item->isLink())unlink($item->getPathname());else rmdir($item->getPathname());}rmdir($root);
}
