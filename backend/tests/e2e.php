<?php
declare(strict_types=1);
// Run only against an isolated test database and a separately running PHP API server.
$configPath=getenv('ZENITH_CONFIG')?:'/app/work/private/config.php';
$c=require $configPath;
if (($c['environment']??'')!=='test'||!str_contains($c['db']['dsn'],'dbname=zenith_test')) throw new RuntimeException('Isolated zenith_test configuration required.');
$db=new PDO($c['db']['dsn'],$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('DELETE FROM rate_limits');
$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
final class Client {
    private string $jar; public string $csrf=''; public int $status=0;
    public function __construct(){ $this->jar=tempnam(sys_get_temp_dir(),'zenith-cookie-'); }
    public function __destruct(){@unlink($this->jar);}
    public function call(string $method,string $path,array $body=[],bool $token=true):array {
        $h=curl_init('http://127.0.0.1:8080/api/v1'.$path);
        $headers=['Content-Type: application/json','Origin: http://127.0.0.1:8080'];if($token)$headers[]='X-CSRF-Token: '.$this->csrf;
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15]);
        if($method!=='GET')curl_setopt($h,CURLOPT_POSTFIELDS,json_encode((object)$body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($h);if($raw===false)throw new RuntimeException(curl_error($h));$this->status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        $json=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if($path==='/session'&&isset($json['data']['csrf']))$this->csrf=$json['data']['csrf'];
        return $json;
    }
    public function ok(string $method,string $path,array $body=[]):array {
        $r=$this->call($method,$path,$body);
        if($this->status!==200)throw new RuntimeException($method.' '.$path.' '.$this->status.' '.json_encode($r));return $r['data'];
    }
    public function session():array{return $this->ok('GET','/session');}
}
function register(Client $a,string $name,string $email):array {$a->session();$u=$a->ok('POST','/register',['name'=>$name,'email'=>$email,'password'=>'Test-password-456!']);$a->session();return $u;}
function verify(Client $a,array $u,PDO $db):void {
    $s=$db->prepare("SELECT payload FROM notification_outbox WHERE user_id=? AND event='account_verification' ORDER BY created_at DESC LIMIT 1");$s->execute([$u['id']]);$payload=json_decode($s->fetchColumn(),true);parse_str(parse_url($payload['url'],PHP_URL_FRAGMENT),$fragment);$a->ok('POST','/verify-email',['token'=>$fragment['token']]);$a->session();
}
$suffix=bin2hex(random_bytes(4));$a=new Client;$b=new Client;$p=new Client;$q=new Client;$admin=new Client;
$a->session();$a->call('POST','/register',['name'=>'Rejected','email'=>'bad@example.test','password'=>'Test-password-456!'],false);check($a->status===419,'CSRF rejects anonymous mutation');
$a->call('GET','/orders');check($a->status===401,'Unauthenticated orders rejected');
$u=register($a,'Test customer',"customer-$suffix@example.test");$v=register($b,'Other customer',"other-$suffix@example.test");$partner=register($p,'Independent tailor',"partner-$suffix@example.test");$otherPartner=register($q,'Other tailor',"partner2-$suffix@example.test");
check(!isset($u['password_hash']),'Account API excludes password hash');
verify($a,$u,$db);verify($b,$v,$db);verify($p,$partner,$db);verify($q,$otherPartner,$db);
$adminId=bin2hex(random_bytes(16));$now=gmdate('Y-m-d H:i:s');
$db->prepare("INSERT INTO users(id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES(?,?,?,?,'admin',?,?,?)")->execute([$adminId,'Test administrator',"admin-$suffix@example.test",password_hash('Test-password-456!',PASSWORD_DEFAULT),$now,$now,$now]);
$admin->session();$admin->ok('POST','/login',['email'=>"admin-$suffix@example.test",'password'=>'Test-password-456!']);$admin->session();
$spec=['name'=>'Evening suit','garment'=>'suit','shade'=>'forest','fit'=>'Tailored','finish'=>'Minimal','notes'=>'Concept requires professional review'];
$design=$a->ok('POST','/designs',$spec);
$b->call('PATCH','/designs/'.$design['id'],$spec);check($b->status===404,'Customer cannot edit unrelated design');
$a->call('POST','/measurements',['name'=>'No consent','garment'=>'suit','unit'=>'cm','values'=>['chest'=>100]]);check($a->status===422,'Measurement consent required');
$a->call('POST','/measurements',['name'=>'Bad values','garment'=>'suit','unit'=>'cm','consent'=>true,'values'=>['chest'=>-100]]);check($a->status===422,'Incomplete invalid measurements rejected');
$measurement=$a->ok('POST','/measurements',['name'=>'Self measured','garment'=>'suit','unit'=>'cm','consent'=>true,'values'=>['chest'=>100,'waist'=>85,'hip'=>100,'shoulder'=>45,'sleeve'=>60,'inseam'=>80]]);
$a->ok('POST','/designs/'.$design['id'].'/submit',['measurement_id'=>$measurement['id']]);
$a->call('PATCH','/designs/'.$design['id'],$spec);check($a->status===409,'Submitted specification is locked');
$admin->call('POST','/designs/'.$design['id'].'/review',['decision'=>'approved','notes'=>'Reviewed']);check($admin->status===409||$admin->status===422,'Unverified measurements prevent feasibility approval');
$admin->ok('POST','/measurements/'.$measurement['id'].'/verify',['evidence'=>'Test-only professional measurement review record']);
$admin->ok('POST','/designs/'.$design['id'].'/review',['decision'=>'approved','notes'=>'Verified against supplier specification']);
$a->call('POST','/quotes',['design_id'=>$design['id']]);check($a->status===403,'Customer cannot issue binding quote');
$quote=$admin->ok('POST','/quotes',['design_id'=>$design['id'],'lines'=>[['label'=>'Approved garment','quantity'=>1,'unit_minor'=>20000,'cost_minor'=>8000]],'tax_bps'=>1300,'deposit_minor'=>5000,'expires_at'=>gmdate('Y-m-d H:i:s',time()+86400),'delivery_terms'=>'TEST ONLY commitment','terms'=>'TEST ONLY approved terms','approval_reference'=>'TEST ONLY operator approval']);
$customerQuotes=$a->ok('GET','/quotes');check(!isset($customerQuotes[0]['estimated_cost_minor']),'Private quote costs excluded from customer API');
$b->call('POST','/quotes/'.$quote['id'].'/approve',['accept_terms'=>true]);check($b->status===404,'Customer cannot approve another quote');
$order=$a->ok('POST','/quotes/'.$quote['id'].'/approve',['accept_terms'=>true]);$oid=$order['id'];
$again=$a->ok('POST','/quotes/'.$quote['id'].'/approve',['accept_terms'=>true]);check($again['id']===$oid,'Quote approval is idempotent');
foreach([['UPDATE quotes SET terms=? WHERE id=?',['tampered terms',$quote['id']],'Approved quote terms are immutable in the database'],['UPDATE orders SET snapshot=? WHERE id=?',['{}',$oid],'Approved order snapshot is immutable in the database']] as [$sql,$params,$label]){try{$db->prepare($sql)->execute($params);check(false,$label);}catch(PDOException $e){check($e->getCode()==='45000',$label);}}
$admin->call('POST','/orders/'.$oid.'/transition',['status'=>'production_preparation','note'=>'Invalid unpaid production','evidence'=>'TEST']);check($admin->status===409,'Production blocked without verified payment');
$a->call('POST','/orders/'.$oid.'/manual-payment',['amount_minor'=>5000,'kind'=>'deposit','evidence'=>'TEST','reference'=>'rejected']);check($a->status===403,'Customer cannot verify own payment');
$admin->ok('POST','/orders/'.$oid.'/manual-payment',['amount_minor'=>5000,'kind'=>'deposit','evidence'=>'TEST ONLY verified bank statement','reference'=>'deposit-'.$suffix]);
$admin->call('POST','/orders/'.$oid.'/manual-payment',['amount_minor'=>5000,'kind'=>'deposit','evidence'=>'duplicate','reference'=>'deposit-'.$suffix]);check($admin->status===409||$admin->status===200,'Duplicate payment cannot create duplicate funds');
$paid=$db->prepare('SELECT SUM(amount_minor) FROM payments WHERE order_id=?');$paid->execute([$oid]);check((int)$paid->fetchColumn()===5000,'Deposit counted once');
$admin->ok('POST','/orders/'.$oid.'/manual-payment',['amount_minor'=>17600,'kind'=>'balance','evidence'=>'TEST ONLY verified balance','reference'=>'balance-'.$suffix]);
try{$db->prepare('DELETE FROM payments WHERE order_id=?')->execute([$oid]);check(false,'Verified payment records cannot be deleted');}catch(PDOException $e){check($e->getCode()==='45000','Verified payment records cannot be deleted');}
$admin->ok('POST','/orders/'.$oid.'/transition',['status'=>'production_preparation','note'=>'Approved production release','evidence'=>'TEST']);
$admin->ok('POST','/orders/'.$oid.'/production',['supplier_reference'=>'TEST supplier confirmation','specification'=>'Approved specification handoff','acknowledgement'=>'TEST acknowledged','due_at'=>gmdate('Y-m-d H:i:s',time()+86400*20)]);
$admin->ok('POST','/orders/'.$oid.'/transition',['status'=>'in_production','note'=>'Supplier started','evidence'=>'TEST acknowledgement']);
$admin->ok('POST','/orders/'.$oid.'/transition',['status'=>'quality_inspection','note'=>'Production finished','evidence'=>'TEST report']);
$admin->call('POST','/orders/'.$oid.'/transition',['status'=>'shipping_preparation','note'=>'No QC','evidence'=>'TEST']);check($admin->status===409,'Shipping requires a passing quality inspection');
$admin->ok('POST','/orders/'.$oid.'/qc',['result'=>'fail','report'=>'Rework required','evidence'=>'TEST defect evidence']);
$admin->call('POST','/orders/'.$oid.'/transition',['status'=>'shipping_preparation','note'=>'Failed QC','evidence'=>'TEST']);check($admin->status===409,'Failed QC prevents shipment');
$admin->ok('POST','/orders/'.$oid.'/qc',['result'=>'pass','report'=>'Rework accepted','evidence'=>'TEST acceptance report']);
$admin->ok('POST','/orders/'.$oid.'/transition',['status'=>'shipping_preparation','note'=>'QC complete','evidence'=>'TEST']);
$admin->ok('POST','/orders/'.$oid.'/shipments',['carrier'=>'TEST carrier','tracking'=>'TEST123','stage'=>'dispatched','note'=>'TEST dispatch']);
foreach(['in_transit','arrived_canada','local_fitting','ready_for_delivery','delivered','completed'] as $state) $admin->ok('POST','/orders/'.$oid.'/transition',['status'=>$state,'note'=>'TEST '.$state,'evidence'=>'TEST verified milestone']);
check($a->ok('GET','/orders/'.$oid)['order']['status']==='completed','Customer sees completed persistent order');
$application=$p->ok('POST','/partner-applications',['business_name'=>'TEST independent tailor','contact'=>'TEST contact','services'=>['fitting','referral']]);
$p->call('GET','/tasks');check($p->status===403,'Unapproved applicant cannot access partner workspace');
$approved=$admin->ok('POST','/partner-applications/'.$application['id'].'/approve',['agreement_reference'=>'TEST ONLY signed agreement','branch_name'=>'TEST Toronto','rate_card_reference'=>'TEST ONLY approved rates','rates'=>['fitting'=>2500,'referral'=>3000]]);$p->session();
$app2=$q->ok('POST','/partner-applications',['business_name'=>'TEST unrelated tailor','contact'=>'TEST contact','services'=>['fitting']]);
$admin->ok('POST','/partner-applications/'.$app2['id'].'/approve',['agreement_reference'=>'TEST ONLY second agreement','branch_name'=>'TEST unrelated branch','rate_card_reference'=>'TEST ONLY second rates','rates'=>['fitting'=>4000]]);$q->session();
$task=$admin->ok('POST','/tasks',['order_id'=>$oid,'branch_id'=>$approved['branch_id'],'service_type'=>'fitting','scheduled_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
$q->call('POST','/tasks/'.$task['id'].'/accept');check($q->status===404,'Partner cannot access unrelated branch task');
$p->ok('POST','/tasks/'.$task['id'].'/accept');$p->ok('POST','/tasks/'.$task['id'].'/complete',['evidence'=>'TEST fitting signed completion']);
$admin->ok('POST','/tasks/'.$task['id'].'/approve',['evidence'=>'TEST independent completion verification']);
$admin->call('POST','/tasks/'.$task['id'].'/approve');check($admin->status===409,'Completed work cannot earn twice');
$ledger=$p->ok('GET','/ledger');$entry=$ledger[0];check((int)$entry['amount_minor']===2500,'Immutable approved rate determines partner earning');
check(count($q->ok('GET','/ledger'))===0,'Partner financial isolation');
$settled=$admin->ok('POST','/settlements',['branch_id'=>$approved['branch_id'],'entry_ids'=>[$entry['id']],'evidence'=>'TEST ONLY bank settlement evidence','reference'=>'settlement-'.$suffix]);
$admin->call('POST','/settlements',['branch_id'=>$approved['branch_id'],'entry_ids'=>[$entry['id']],'evidence'=>'duplicate','reference'=>'settlement-duplicate-'.$suffix]);check($admin->status===409,'Ledger entry cannot be settled twice');
$q->call('GET','/settlements/'.$settled['id'].'/statement');check($q->status===404,'Settlement statement isolated by branch');
$admin->ok('POST','/ledger/'.$entry['id'].'/reverse',['reason'=>'TEST adjustment reversal']);
$reversed=$p->ok('GET','/ledger');check(array_sum(array_map(fn($x)=>(int)$x['amount_minor'],$reversed))===0,'Partner corrections append a balancing reversal');
try{$db->exec("UPDATE partner_ledger SET amount_minor=1 WHERE id='".$entry['id']."'");check(false,'Ledger mutation rejected');}catch(PDOException){check(true,'Database rejects ledger mutation');}
$admin->ok('POST','/branches/'.$approved['branch_id'].'/rate-cards',['approval_reference'=>'TEST ONLY revised rates','rates'=>['repair'=>3000]]);
$admin->call('POST','/tasks',['order_id'=>$oid,'branch_id'=>$approved['branch_id'],'service_type'=>'fitting']);check($admin->status===409,'Removed service cannot fall back to an older rate card');
$repair=$admin->ok('POST','/tasks',['order_id'=>$oid,'branch_id'=>$approved['branch_id'],'service_type'=>'repair']);$p->ok('POST','/tasks/'.$repair['id'].'/accept');$p->ok('POST','/tasks/'.$repair['id'].'/complete',['evidence'=>'TEST completed repair evidence']);$admin->ok('POST','/tasks/'.$repair['id'].'/approve',['evidence'=>'TEST independent repair verification']);
$entries=$p->ok('GET','/ledger');$positive=array_values(array_filter($entries,fn($x)=>$x['task_id']===$repair['id']))[0];$negative=array_values(array_filter($entries,fn($x)=>$x['reversal_of']===$entry['id']))[0];
$admin->call('POST','/settlements',['branch_id'=>$approved['branch_id'],'entry_ids'=>[$positive['id']],'evidence'=>'TEST must include reversal','reference'=>'bad-adjustment-'.$suffix]);check($admin->status===409,'Pending negative adjustment cannot be omitted from settlement');
$adjusted=$admin->ok('POST','/settlements',['branch_id'=>$approved['branch_id'],'entry_ids'=>[$positive['id'],$negative['id']],'evidence'=>'TEST net payable bank evidence','reference'=>'adjusted-'.$suffix]);check((int)$adjusted['amount_minor']===500,'Settlement reconciles new earnings with prior reversal');
$case=$a->ok('POST','/support',['subject'=>'TEST fit support','message'=>'Please review fit','order_id'=>$oid]);$admin->ok('POST','/support/'.$case['id'].'/messages',['message'=>'PRIVATE INTERNAL TEST NOTE','internal'=>true]);
$customerCases=$a->ok('GET','/support');check(!str_contains(json_encode($customerCases),'PRIVATE INTERNAL TEST NOTE'),'Customer cannot read internal support notes');
$b->call('POST','/support/'.$case['id'].'/messages',['message'=>'unrelated']);check($b->status===404,'Support customer isolation');
$newBranch=$admin->ok('POST','/partners/'.$approved['id'].'/branches',['name'=>'TEST additional agreed branch','approval_reference'=>'TEST branch agreement']);
$admin->ok('POST','/branches/'.$newBranch['id'].'/staff',['email'=>$v['email'],'approval_reference'=>'TEST branch staff approval']);$b->session();check(count($b->ok('GET','/tasks'))===0,'Branch staff cannot see assignments from a sibling branch');
$admin->call('POST','/branches/'.$newBranch['id'].'/staff',['email'=>$otherPartner['email'],'approval_reference'=>'TEST prohibited cross-partner access']);check($admin->status===409,'Staff membership cannot cross independent partner boundaries');
$p->call('GET','/branches/'.$newBranch['id'].'/staff');check($p->status===403,'Branch staffing is administrator-only');
$admin->ok('DELETE','/branches/'.$newBranch['id'].'/staff/'.$v['id']);$b->call('GET','/tasks');check($b->status===401,'Revoking branch access invalidates existing staff session');
$export=$a->ok('GET','/account/export');check(!str_contains(json_encode($export),'password_hash')&&!str_contains(json_encode($export),'PRIVATE INTERNAL TEST NOTE'),'Account export excludes secrets and internal notes');
$a->ok('POST','/account/deletion-request');
$a->ok('POST','/logout');$a->session();$a->call('GET','/orders');check($a->status===401,'Logout revokes session access');
echo 'HTTP E2E checks: '.$checks.PHP_EOL;
