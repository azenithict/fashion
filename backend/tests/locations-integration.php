<?php
declare(strict_types=1);
// Public location finder lists only verified branches whose services are covered by the latest approved rate card.
require __DIR__.'/../src/Kernel.php';
require __DIR__.'/../src/Locations.php';
use Zenith\ApiError;
use Zenith\Kernel;
use Zenith\Locations;

$config=require (getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php');
if (($config['environment']??'production')==='production') throw new RuntimeException('Refusing production test configuration');
$config['session_enabled']=false; $k=new Kernel($config); $l=new Locations($k); $checks=0; $_SESSION=[];
function check(bool $x,string $m): void { global $checks; if(!$x) throw new RuntimeException($m); $checks++; }
function rejects(callable $fn,int $status,string $m): void { try { $fn(); } catch (ApiError $e) { check($e->status===$status,$m.' (got '.$e->status.')'); return; } throw new RuntimeException($m.' (accepted)'); }
function user(Kernel $k,string $role): array { $id=$k->id(); $n=$k->now(); $k->execute('INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$id,'Loc '.$role,$role.'-'.bin2hex(random_bytes(5)).'@example.test','x',$role,$n,$n]); return $k->one('SELECT * FROM users WHERE id=?',[$id]); }
$admin=user($k,'admin'); $owner=user($k,'partner'); $now=$k->now(); $s=bin2hex(random_bytes(3));
$app=$k->id(); $k->execute("INSERT INTO partner_applications (id,user_id,business_name,contact,services,status,created_at,approved_at) VALUES (?,?,?,?,'[]','approved',?,?)",[$app,$owner['id'],"TEST Tailor $s",'test contact',$now,$now]);
$pid=$k->id(); $k->execute('INSERT INTO partners (id,application_id,business_name,agreement_reference,approved_by,active_at) VALUES (?,?,?,?,?,?)',[$pid,$app,"TEST Tailor $s",'TEST-AGREEMENT',$admin['id'],$now]);
$bid=$k->id(); $k->execute('INSERT INTO partner_branches (id,partner_id,name,created_at) VALUES (?,?,?,?)',[$bid,$pid,'Main',$now]);
$rate=function(int $v,array $items) use($k,$bid,$admin,$now){ $r=$k->id(); $k->execute('INSERT INTO rate_cards (id,branch_id,version,approval_reference,approved_by,created_at) VALUES (?,?,?,?,?,?)',[$r,$bid,$v,'TEST-RATES',$admin['id'],$now]); foreach($items as $t) $k->execute('INSERT INTO rate_card_items VALUES (?,?,1000)',[$r,$t]); };
$rate(1,['measurement','fitting']);
$valid=['address_line'=>'1 Test Street','city'=>'Toronto','province'=>'ON','postal_code'=>'m5v 2t6','services'=>['measurement','fitting'],'booking_note'=>'Call the branch to book.','verification_reference'=>'Signed pilot agreement','listed'=>true];
$mine=fn()=>array_values(array_filter($l->handle('GET','/locations',[]),fn($r)=>$r['business_name']==="TEST Tailor $s"));
check($mine()===[],'unlisted branch is not public');
$k->user=user($k,'operations'); rejects(fn()=>$l->handle('PUT',"/branches/$bid/listing",$valid),403,'only admin can list');
$k->user=$admin;
rejects(fn()=>$l->handle('PUT',"/branches/$bid/listing",['services'=>['alteration']]+$valid),409,'service without approved rate rejected');
rejects(fn()=>$l->handle('PUT',"/branches/$bid/listing",['services'=>['referral']]+$valid),422,'non-public service rejected');
rejects(fn()=>$l->handle('PUT',"/branches/$bid/listing",['postal_code'=>'12345']+$valid),422,'invalid postal code');
rejects(fn()=>$l->handle('PUT',"/branches/$bid/listing",['verification_reference'=>'']+$valid),422,'evidence required');
check($l->handle('PUT',"/branches/$bid/listing",['listed'=>false]+$valid)['listing']['postal_code']==='M5V 2T6','saved unlisted');
check($mine()===[],'saved but unlisted stays private');
$l->handle('PUT',"/branches/$bid/listing",$valid); $pub=$mine();
check(count($pub)===1 && $pub[0]['services']===['measurement','fitting'] && !isset($pub[0]['verification_reference']),'listed branch public without evidence fields');
$rate(2,['measurement']);
check($mine()[0]['services']===['measurement'],'newer rate card narrows advertised services');
$rate(3,['alteration']);
check($mine()===[],'branch hidden when no listed service is still rated');
check((bool)$k->one("SELECT id FROM audit_events WHERE action='branch.listing_saved' AND entity_id=?",[$bid]),'listing audited');
echo "Locations integration: $checks checks passed\n";
