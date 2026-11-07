<?php
declare(strict_types=1);
// Guest enquiry → database → operations queue → acknowledgement outbox. Isolated test database only.
require __DIR__.'/../src/Kernel.php';
require __DIR__.'/../src/Enquiries.php';
require __DIR__.'/../src/Designs.php';
require __DIR__.'/../src/Operations.php';
use Zenith\ApiError;
use Zenith\Kernel;
use Zenith\Enquiries;
use Zenith\Designs;
use Zenith\Operations;

$path=getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php';
$config=require $path;
if (($config['environment']??'production')==='production') throw new RuntimeException('Refusing production test configuration');
$config['session_enabled']=false; $config['public_origin']='https://fashion.example.test';
$k=new Kernel($config); $e=new Enquiries($k); $checks=0; $_SESSION=[];
function check(bool $c,string $m): void { global $checks; if(!$c) throw new RuntimeException($m); $checks++; }
function rejects(callable $fn,int $status,string $m): void { try { $fn(); } catch (ApiError $x) { check($x->status===$status,$m.' (got '.$x->status.')'); return; } throw new RuntimeException($m.' (accepted)'); }
function ip(): void { $_SERVER['REMOTE_ADDR']='enq-'.bin2hex(random_bytes(8)); }
function user(Kernel $k,string $role): array { $id=$k->id(); $now=$k->now(); $k->execute('INSERT INTO users (id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$id,ucfirst($role).' tester',$role.'-'.bin2hex(random_bytes(5)).'@example.test',password_hash('unused-'.bin2hex(random_bytes(8)),PASSWORD_DEFAULT),$role,$now,$now,$now]); return $k->one('SELECT * FROM users WHERE id=?',[$id]); }

$ops=user($k,'operations'); $customer=user($k,'customer');
$email='guest-'.bin2hex(random_bytes(5)).'@example.test';
$valid=['service'=>'custom_tailoring','name'=>'Guest Tester','email'=>strtoupper($email),'contact_method'=>'email','requirements'=>"Navy two-piece suit for a wedding.\nPeak lapel preferred.",'garment'=>"Men's Tailoring",'deadline'=>gmdate('Y-m-d',time()+86400*60),'location'=>'Toronto, ON','consent'=>true,'website'=>'','source'=>['utm_source'=>'test','landing'=>'/enquiry/']];

// Validation never stores or acknowledges a rejected request.
$k->user=null; ip();
$before=(int)$k->one('SELECT COUNT(*) n FROM enquiries')['n'];
rejects(fn()=>$e->handle('POST','/enquiries',['consent'=>false]+$valid),422,'consent required'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['website'=>'http://spam.test']+$valid),422,'honeypot rejected'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['service'=>'buy_now']+$valid),422,'unknown service rejected'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['contact_method'=>'phone']+$valid),422,'phone required for phone contact'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['deadline'=>'2020-01-01']+$valid),422,'past deadline rejected'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['quantity'=>'12']+$valid),422,'non-integer quantity rejected'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['requirements'=>'short']+$valid),422,'short requirements rejected'); ip();
rejects(fn()=>$e->handle('POST','/enquiries',['email'=>'not-an-email']+$valid),422,'invalid email rejected');
check((int)$k->one('SELECT COUNT(*) n FROM enquiries')['n']===$before,'rejected requests are not stored');

// A valid guest request is durable, referenced, queued for staff and acknowledged through the outbox.
ip(); $r=$e->handle('POST','/enquiries',$valid);
check((bool)preg_match('/^AZF-[2-9A-HJKMNP-Z]{6}$/D',$r['reference']),'reference format');
check($r['status']==='new' && $r['email']===$email,'response reflects stored request');
$row=$k->one('SELECT * FROM enquiries WHERE reference=?',[$r['reference']]);
check($row && $row['email']===$email && $row['user_id']===null && $row['consent_at']!==null,'enquiry persisted');
check(json_decode($row['source'],true)===['utm_source'=>'test','landing'=>'/enquiry/'],'source attribution stored');
check((int)$k->one("SELECT COUNT(*) n FROM enquiry_events WHERE enquiry_id=? AND kind='created'",[$row['id']])['n']===1,'created event recorded');
$ack=$k->one("SELECT o.*,COALESCE(u.email,o.recipient_email) AS email FROM notification_outbox o LEFT JOIN users u ON u.id=o.user_id WHERE o.event='enquiry_received' AND o.recipient_email=?",[$email]);
check($ack && $ack['user_id']===null && $ack['email']===$email && json_decode($ack['payload'],true)['reference']===$r['reference'],'guest acknowledgement queued for worker');
check((bool)$k->one("SELECT id FROM notification_outbox WHERE event='enquiry_new' AND user_id=? AND payload LIKE ?",[$ops['id'],'%'.$r['reference'].'%']),'operations notified');
check((bool)$k->one("SELECT id FROM audit_events WHERE action='enquiry.created' AND entity_id=?",[$row['id']]),'creation audited');

// Signed-in customers are linked; per-email and per-address abuse limits apply.
$k->user=$customer; ip(); $linked=$e->handle('POST','/enquiries',['email'=>$email,'service'=>'consultation']+$valid);
check($k->one('SELECT user_id FROM enquiries WHERE reference=?',[$linked['reference']])['user_id']===$customer['id'],'customer linked');
$k->user=null; ip(); $e->handle('POST','/enquiries',$valid);
ip(); rejects(fn()=>$e->handle('POST','/enquiries',$valid),429,'per-email daily limit');
ip(); for($i=0;$i<5;$i++){ try { $e->handle('POST','/enquiries',['consent'=>false]+$valid); } catch (ApiError) {} }
rejects(fn()=>$e->handle('POST','/enquiries',['email'=>'other-'.bin2hex(random_bytes(4)).'@example.test']+$valid),429,'per-address hourly limit');

// Only staff can see the queue.
rejects(fn()=>$e->handle('GET','/enquiries',[]),401,'guest cannot list');
$k->user=$customer; rejects(fn()=>$e->handle('GET','/enquiries',[]),403,'customer cannot list');
rejects(fn()=>$e->handle('GET','/enquiries/'.$row['id'],[]),403,'customer cannot read');

// Staff follow-up with an enforced state graph and audit trail.
$k->user=$ops; $id=$row['id'];
check(in_array($r['reference'],array_column($e->handle('GET','/enquiries',[]),'reference'),true),'staff queue lists enquiry');
$detail=$e->handle('GET','/enquiries/'.$id,[]);
check($detail['enquiry']['status']==='new' && $detail['transitions']===['reviewing','awaiting_details','closed'],'detail exposes allowed transitions');
rejects(fn()=>$e->handle('POST','/enquiries/'.$id.'/status',['status'=>'converted']),409,'invalid transition rejected');
check($e->handle('POST','/enquiries/'.$id.'/assign',['assign'=>'me'])['assigned_to']===$ops['id'],'assigned to staff');
check($e->handle('POST','/enquiries/'.$id.'/status',['status'=>'reviewing','note'=>'Called customer'])['status']==='reviewing','moved to reviewing');
check($e->handle('POST','/enquiries/'.$id.'/notes',['note'=>'Prefers navy wool.'])===['ok'=>true],'note recorded');
rejects(fn()=>$e->handle('POST','/enquiries/'.$id.'/status',['status'=>'closed']),422,'close requires reason');
check($e->handle('POST','/enquiries/'.$id.'/status',['status'=>'closed','note'=>'Customer postponed'])['closed_reason']==='Customer postponed','closed with reason');
$events=$e->handle('GET','/enquiries/'.$id,[])['events'];
check(array_column($events,'kind')===['created','assigned','status','note','status'],'complete activity trail');
rejects(fn()=>$e->handle('POST','/enquiries/'.str_repeat('0',32).'/notes',['note'=>'x']),404,'unknown enquiry');

// Customer messaging, reply loop and conversion to a reviewed design path
$k->user=$ops; ip(); $open=$e->handle('POST','/enquiries',['email'=>'loop-'.bin2hex(random_bytes(4)).'@example.test']+$valid);
$oid=$k->one('SELECT id,email FROM enquiries WHERE reference=?',[$open['reference']]);
rejects(fn()=>$e->handle('POST','/enquiries/'.$oid['id'].'/message',['message'=>'x']),422,'empty message rejected');
check($e->handle('POST','/enquiries/'.$oid['id'].'/message',['message'=>"Could you share the event date?\nThanks.",'request_details'=>true])['status']==='awaiting_details','details requested');
check((bool)$k->one("SELECT id FROM notification_outbox WHERE event='enquiry_message' AND recipient_email=?",[$oid['email']]),'customer message queued');
$owner=user($k,'customer'); $k->execute('UPDATE users SET email=?,email_verified_at=? WHERE id=?',[$oid['email'],$k->now(),$owner['id']]); $owner=$k->one('SELECT * FROM users WHERE id=?',[$owner['id']]);
$stranger=user($k,'customer');
$k->user=$stranger; check($e->handle('GET','/my/enquiries',[])===[],'other customers see nothing');
rejects(fn()=>$e->handle('POST','/my/enquiries/'.$oid['id'].'/reply',['message'=>'hi']),404,'cannot reply to another enquiry');
$k->user=$owner; $mine=$e->handle('GET','/my/enquiries',[]);
check(count($mine)===1 && $mine[0]['messages'][0]['kind']==='customer_message' && !isset($mine[0]['email']),'owner sees own request and messages');
$e->handle('POST','/my/enquiries/'.$oid['id'].'/reply',['message'=>'The wedding is on 12 June.']);
check($k->one('SELECT status FROM enquiries WHERE id=?',[$oid['id']])['status']==='reviewing','reply returns enquiry to review');
check(array_column($e->handle('GET','/my/enquiries',[])[0]['messages'],'kind')===['customer_message','customer_reply'],'internal notes hidden from customer');
$designs=new Designs($k); $dz=$designs->handle('POST','/designs',['name'=>'From enquiry','garment'=>'suit','shade'=>'midnight','fit'=>'Tailored','finish'=>'Minimal','notes'=>'']);
$foreign=(function() use($k,$stranger){ $k->user=$stranger; return (new Designs($k))->handle('POST','/designs',['name'=>'Other','garment'=>'suit','shade'=>'midnight','fit'=>'Tailored','finish'=>'Minimal','notes'=>'']); })();
$k->user=$ops;
rejects(fn()=>$e->handle('POST','/enquiries/'.$oid['id'].'/link-design',['design_id'=>$foreign['id']]),422,'cannot link another person design');
check($e->handle('POST','/enquiries/'.$oid['id'].'/link-design',['design_id'=>$dz['id']])['design_id']===$dz['id'],'enquiry converted to customer design');
$ov=(new Operations($k))->handle('GET','/operations/overview',[]); $fn=(new Operations($k))->handle('GET','/operations/funnel',[]);
check(is_int($ov['enquiries_new']) && $ov['email_pending']>=1 && $fn['stages']['enquiries_converted']>=1 && $fn['stages']['enquiries']>=$fn['stages']['enquiries_converted'],'overview and funnel from real records');
$k->user=$owner; rejects(fn()=>(new Operations($k))->handle('GET','/operations/overview',[]),403,'customers cannot see operations overview');
echo "Enquiry integration: $checks checks passed\n";
