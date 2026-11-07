<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Kernel.php';require_once __DIR__.'/../src/Commerce.php';
use Zenith\{Kernel,Commerce,ApiError};
$worker=($argv[1]??'')==='--worker';$configPath=$argv[$worker?2:1]??getenv('ZENITH_TEST_CONFIG');
if(!$configPath||!is_file($configPath))throw new RuntimeException('Provide an isolated test config path.');$config=require $configPath;
if(($config['environment']??'')!=='test')throw new RuntimeException('Concurrency fixtures require environment=test.');
$config['session_enabled']=false;$config['pricing_min_margin_bps']=0;$config['pricing_approval_reference']='TEST ONLY';$config['manual_payments_enabled']=true;$config['manual_payment_approval_reference']='TEST ONLY';$k=new Kernel($config);$commerce=new Commerce($k);
if($worker){
    $u=$k->one('SELECT * FROM users WHERE id=?',[$argv[3]]);$k->user=$k->publicUser($u);$request=json_decode($argv[4],true,32,JSON_THROW_ON_ERROR);$barrier=$argv[5];$deadline=microtime(true)+5;
    while(!is_file($barrier)&&microtime(true)<$deadline)usleep(1000);
    try{$result=$commerce->handle('POST',$request['path'],$request['input']);echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR);}catch(ApiError $e){echo json_encode(['ok'=>false,'status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}catch(Throwable){echo '{"ok":false,"status":500}';}
    exit;
}
$count=0;function check(bool $v,string $message):void{global$count;if(!$v)throw new RuntimeException($message);$count++;}
function parallel(string $configPath,string $actor,array $requests):array{
    $barrier=tempnam(sys_get_temp_dir(),'zenith-race-');unlink($barrier);$children=[];
    foreach($requests as$request){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker',$configPath,$actor,json_encode($request,JSON_THROW_ON_ERROR),$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker launch failed.');fclose($pipes[0]);$children[]=[$p,$pipes];}
    touch($barrier);$results=[];
    foreach($children as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0||$error!=='')throw new RuntimeException('Concurrency worker failed.');$results[]=json_decode($out,true,64,JSON_THROW_ON_ERROR);}
    unlink($barrier);return $results;
}
$suffix=$k->id();$now=$k->now();$customer=$k->id();$ops=$k->id();
foreach([[$customer,'customer'],[$ops,'operations']]as[$uid,$role])$k->execute('INSERT INTO users (id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$uid,'Concurrency '.$role,$role.'-'.$suffix.'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$role,$now,$now,$now]);
$m=$k->id();$d=$k->id();$k->execute('INSERT INTO measurements (id,user_id,name,garment,unit,values_json,provenance,consent_at,verified_at,verified_by,verification_evidence,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$m,$customer,'Concurrency fixture','suit','cm','{"chest":100,"waist":80,"hip":95,"shoulder":40,"sleeve":60,"inseam":75}','self',$now,$now,$ops,'TEST ONLY verification',$now]);
$k->execute('INSERT INTO designs (id,user_id,name,status,specification,measurement_id,revision,submitted_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$d,$customer,'Concurrency fixture','approved','{"garment":"suit","shade":"midnight","fit":"Tailored","finish":"Minimal","notes":""}',$m,1,$now,$now,$now]);
$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$ops]));
$q=$commerce->handle('POST','/quotes',['design_id'=>$d,'lines'=>[['label'=>'Concurrency garment','quantity'=>1,'unit_minor'=>10000,'cost_minor'=>6000]],'tax_bps'=>1300,'deposit_minor'=>3000,'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+86400),'delivery_terms'=>'TEST ONLY supplier terms','terms'=>'TEST ONLY commercial terms','approval_reference'=>'TEST ONLY approval']);
$request=['path'=>'/quotes/'.$q['id'].'/approve','input'=>['accept_terms'=>true]];$results=parallel($configPath,$customer,[$request,$request]);
check($results[0]['ok']&&$results[1]['ok'],'Both retrying approvals complete safely');$order=$results[0]['data']['id'];check($order===$results[1]['data']['id'],'Concurrent approvals return one order');check((int)$k->one('SELECT COUNT(*) count FROM orders WHERE quote_id=?',[$q['id']])['count']===1,'Unique quote order constraint and aggregate lock');
$payment=['path'=>"/orders/$order/manual-payment",'input'=>['kind'=>'deposit','amount_minor'=>3000,'reference'=>'concurrency-deposit-'.$suffix,'evidence'=>'TEST ONLY verified bank deposit']];$results=parallel($configPath,$ops,[$payment,$payment]);
check($results[0]['ok']&&$results[1]['ok'],'Duplicate payment requests complete idempotently');check((int)$k->one('SELECT COUNT(*) count FROM payments WHERE order_id=?',[$order])['count']===1,'Concurrent duplicate reference creates one payment');check((int)$k->one('SELECT SUM(amount_minor) amount FROM payments WHERE order_id=?',[$order])['amount']===3000,'Concurrent duplicate reference cannot double funds');
$balanceA=['path'=>"/orders/$order/manual-payment",'input'=>['kind'=>'balance','amount_minor'=>8300,'reference'=>'concurrency-balance-a-'.$suffix,'evidence'=>'TEST ONLY bank balance receipt']];$balanceB=$balanceA;$balanceB['input']['reference']='concurrency-balance-b-'.$suffix;$results=parallel($configPath,$ops,[$balanceA,$balanceB]);
check(count(array_filter($results,fn($r)=>$r['ok']))===1,'Only one competing balance receipt accepted');$failed=array_values(array_filter($results,fn($r)=>!$r['ok']))[0];check($failed['status']===409,'Competing collection receives explicit balance conflict');check((int)$k->one('SELECT SUM(amount_minor) amount FROM payments WHERE order_id=?',[$order])['amount']===11300,'Concurrent competing references cannot overcollect');
echo "PASS $count commerce concurrency assertions\n";
