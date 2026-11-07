<?php
declare(strict_types=1);
// Run only against an isolated test database, after migrations. Never production.
require __DIR__.'/../src/Kernel.php';
require __DIR__.'/../src/Auth.php';
require __DIR__.'/../src/Designs.php';
use Zenith\ApiError;
use Zenith\Kernel;
use Zenith\Auth;
use Zenith\Designs;

$path=getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php';
$config=require $path;
if (($config['environment']??'production')==='production') throw new RuntimeException('Refusing production test configuration');
$config['session_enabled']=false;
$config['public_origin']='https://fashion.example.test';
$k=new Kernel($config); $auth=new Auth($k); $designs=new Designs($k); $checks=0; $_SESSION=[];
$_SERVER['REMOTE_ADDR']='test-'.bin2hex(random_bytes(12));
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function rejects(callable $fn,int $status,string $message): void { try { $fn(); } catch (ApiError $e) { check($e->status===$status,$message.' (wrong status)'); return; } throw new RuntimeException($message.' (accepted)'); }
function verificationToken(Kernel $k,string $user): string { $row=$k->one("SELECT payload FROM notification_outbox WHERE user_id=? AND event='account_verification' ORDER BY created_at DESC LIMIT 1",[$user]); $p=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR); parse_str((string)parse_url($p['url'],PHP_URL_FRAGMENT),$fragment); return $fragment['token']; }

$firstCsrf=$k->csrf(); rejects(fn()=> $k->verifyCsrf('wrong'),419,'CSRF mismatch rejected'); $k->verifyCsrf($firstCsrf); check(true,'CSRF accepted');
$email='core-'.bin2hex(random_bytes(5)).'@example.test';
$u=$auth->handle('POST','/register',['name'=>'Core test customer','email'=>$email,'password'=>'test-strong-password-794','role'=>'admin']);
check($u['role']==='customer','Registration never self-elevates'); check(!isset($u['password_hash']),'Password hash not returned'); check($k->csrf()!==$firstCsrf,'Registration rotates CSRF');
$uid=$u['id']; $stored=$k->one('SELECT * FROM users WHERE id=?',[$uid]); check(password_verify('test-strong-password-794',$stored['password_hash']),'Password really hashed');
$token=verificationToken($k,$uid); $authToken=$k->one('SELECT token_hash FROM auth_tokens WHERE user_id=?',[$uid]); check($authToken['token_hash']===hash('sha256',$token),'Token persisted as hash');
$auth->handle('POST','/verify-email',['token'=>$token]); check($k->user['email_verified_at']!==null,'Email verified'); rejects(fn()=> $auth->handle('POST','/verify-email',['token'=>$token]),422,'Verification replay blocked');
rejects(fn()=> $auth->handle('POST','/login',['email'=>$email,'password'=>'incorrect']),401,'Bad password rejected');
$auth->handle('POST','/login',['email'=>$email,'password'=>'test-strong-password-794']); check($k->user['id']===$uid,'Login uses real identity');
$values=['chest'=>96,'waist'=>80,'hip'=>96,'shoulder'=>44,'sleeve'=>62,'inseam'=>80];
rejects(fn()=> $designs->handle('POST','/measurements',['name'=>'No consent','garment'=>'suit','unit'=>'cm','values'=>$values]),422,'Measurement consent mandatory');
rejects(fn()=> $designs->handle('POST','/measurements',['name'=>'Missing fields','garment'=>'suit','unit'=>'cm','values'=>['chest'=>96],'consent'=>true]),422,'Measurement completeness enforced');
rejects(fn()=> $designs->handle('POST','/measurements',['name'=>'Wrong numeric type','garment'=>'suit','unit'=>'cm','values'=>array_replace($values,['waist'=>'80']),'consent'=>true]),422,'Numeric strings rejected');
rejects(fn()=> $designs->handle('POST','/measurements',['name'=>'Out of range','garment'=>'suit','unit'=>'in','values'=>$values,'consent'=>true]),422,'Unit-aware limits enforced');
$measurement=$designs->handle('POST','/measurements',['name'=>'Self measurements','garment'=>'suit','unit'=>'cm','values'=>$values,'consent'=>true]);
check($measurement['provenance']==='self'&&$measurement['verified_at']===null,'Self measurements not falsely verified');
$input=['name'=>'My suit','garment'=>'suit','shade'=>'forest','fit'=>'Tailored','finish'=>'Minimal','notes'=>'Review my preferred silhouette'];
rejects(fn()=> $designs->handle('POST','/designs',array_replace($input,['garment'=>'impossible'])),422,'Unsupported garment rejected');
$d=$designs->handle('POST','/designs',$input); $did=$d['id']; check($d['status']==='draft','Draft persisted');
$unverified=$auth->handle('POST','/register',['name'=>'Another customer','email'=>'other-'.bin2hex(random_bytes(5)).'@example.test','password'=>'test-second-password-684']);
rejects(fn()=> $designs->handle('GET','/designs/'.$did,[]),404,'Other customer cannot read design');
rejects(fn()=> $designs->handle('PATCH','/designs/'.$did,$input),404,'Other customer cannot edit design');
rejects(fn()=> $designs->handle('GET','/measurements/'.$measurement['id'],[]),404,'Other customer cannot read body measurements');
rejects(fn()=> $designs->handle('POST','/measurements/'.$measurement['id'].'/verify',['evidence'=>'Unauthorized attempt']),403,'Customer cannot verify measurements as staff');
$auth->handle('POST','/login',['email'=>$email,'password'=>'test-strong-password-794']);
$submitted=$designs->handle('POST','/designs/'.$did.'/submit',['measurement_id'=>$measurement['id']]); check($submitted['status']==='submitted','Submission persisted');
$version=$k->one('SELECT * FROM design_versions WHERE design_id=?',[$did]); check($version!==null&&json_decode($version['specification'],true)['shade']==='forest','Immutable submitted version stored');
try { $k->execute('UPDATE design_versions SET revision=revision WHERE id=?',[$version['id']]); throw new RuntimeException('Database allowed submitted snapshot mutation'); } catch (PDOException $e) { check($e->getCode()==='45000','Database rejects snapshot updates independently of API'); }
$audit=$k->one('SELECT id FROM audit_events WHERE actor_id=? LIMIT 1',[$uid]);
try { $k->execute('DELETE FROM audit_events WHERE id=?',[$audit['id']]); throw new RuntimeException('Database allowed audit deletion'); } catch (PDOException $e) { check($e->getCode()==='45000','Database rejects audit deletion independently of API'); }
rejects(fn()=> $designs->handle('PATCH','/designs/'.$did,array_replace($input,['shade'=>'black'])),409,'Submitted specification cannot be edited');
$copy=$designs->handle('POST','/designs/'.$did.'/duplicate',[]); check($copy['status']==='draft'&&$copy['id']!==$did,'Duplicate is separate draft');
$case=$designs->handle('POST','/support',['subject'=>'Fit question','message'=>'Please review sleeve length.']);
// Test fixture promotes separate user only inside this isolated test database.
$k->execute("UPDATE users SET role='operations',email_verified_at=? WHERE id=?",[$k->now(),$unverified['id']]);
$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$unverified['id']]));
$designs->handle('POST','/support/'.$case['id'].'/messages',['message'=>'PRIVATE OPERATOR NOTE','internal'=>true]);
$designs->handle('POST','/support/'.$case['id'].'/messages',['message'=>'We will review your fit.','internal'=>false]);
$verified=$designs->handle('POST','/measurements/'.$measurement['id'].'/verify',['evidence'=>'Reviewed in independent professional fitting']); check($verified['verified_at']!==null,'Operator verification recorded');
$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$uid]));
$cases=$designs->handle('GET','/support',[]); check(!str_contains(json_encode($cases),'PRIVATE OPERATOR NOTE'),'Customer never receives internal notes');
$export=$designs->handle('GET','/account/export',[]); $json=json_encode($export); check(!str_contains($json,'PRIVATE OPERATOR NOTE')&&!str_contains($json,'password_hash')&&!str_contains($json,$token),'Export excludes staff notes and auth secrets');
$auth->handle('POST','/forgot-password',['email'=>$email]); $recovery=$k->one("SELECT payload FROM notification_outbox WHERE user_id=? AND event='password_recovery' ORDER BY created_at DESC LIMIT 1",[$uid]); $url=json_decode($recovery['payload'],true)['url']; parse_str((string)parse_url($url,PHP_URL_FRAGMENT),$fragment);
$before=(int)$k->one('SELECT session_version FROM users WHERE id=?',[$uid])['session_version'];
$auth->handle('POST','/reset-password',['token'=>$fragment['token'],'password'=>'new-test-password-953']);
check((int)$k->one('SELECT session_version FROM users WHERE id=?',[$uid])['session_version']===$before+1,'Password reset revokes sessions');
rejects(fn()=> $auth->handle('POST','/reset-password',['token'=>$fragment['token'],'password'=>'another-test-password-053']),422,'Reset replay blocked');
$auth->handle('POST','/login',['email'=>$email,'password'=>'new-test-password-953']);
$sessionConfig=$config; $sessionConfig['session_enabled']=true; $sessionConfig['session_secure']=false; $sessionConfig['session_name']='zenith_test';
$sessionKernel=new Kernel($sessionConfig); $sessionKernel->sessionUser($sessionKernel->one('SELECT * FROM users WHERE id=?',[$uid]));
$_SESSION['last_seen']=time()-7200; $expired=new Kernel($sessionConfig); check($expired->user===null,'Idle session expiry enforced');
$expired->sessionUser($expired->one('SELECT * FROM users WHERE id=?',[$uid])); $k->execute('UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);
$revoked=new Kernel($sessionConfig); check($revoked->user===null,'Revoked session rejected on next request');
$deletion=$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$uid])); $request=$designs->handle('POST','/account/deletion-request',[]); check($request['status']==='pending','Deletion workflow records request, retains finance evidence');

// Partner measurement fixtures model approved agreements and an existing immutable order.
function fixtureUser(Kernel $k,string $role): array {
    $id=$k->id(); $now=$k->now(); $k->execute('INSERT INTO users (id,name,email,password_hash,role,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$id,'Isolated fixture '.$role,$id.'@example.test',password_hash('fixture-password-924',PASSWORD_DEFAULT),$role,$now,$now,$now]); return $k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$id]));
}
function fixtureBranch(Kernel $k,array $partner,string $operator): array {
    $app=$k->id(); $pid=$k->id(); $branch=$k->id(); $card=$k->id(); $now=$k->now();
    $k->execute("INSERT INTO partner_applications (id,user_id,business_name,contact,services,status,created_at,approved_at) VALUES (?,?,?,?,?,'approved',?,?)",[$app,$partner['id'],'Test-only independent tailor','Test-only contact','["measurement"]',$now,$now]);
    $k->execute('INSERT INTO partners (id,application_id,business_name,agreement_reference,approved_by,active_at) VALUES (?,?,?,?,?,?)',[$pid,$app,'Test tailor','TEST-AGREEMENT',$operator,$now]);
    $k->execute('INSERT INTO partner_branches (id,partner_id,name,created_at) VALUES (?,?,?,?)',[$branch,$pid,'Test branch',$now]);
    $k->execute('INSERT INTO partner_members (user_id,branch_id) VALUES (?,?)',[$partner['id'],$branch]);
    $k->execute('INSERT INTO rate_cards (id,branch_id,version,approval_reference,approved_by,created_at) VALUES (?,?,1,?,?,?)',[$card,$branch,'TEST-RATE',$operator,$now]);
    $k->execute('INSERT INTO rate_card_items (rate_card_id,service_type,amount_minor) VALUES (?,?,?)',[$card,'measurement',2500]);
    return ['branch'=>$branch,'card'=>$card];
}
$operator=$unverified['id']; $partnerA=fixtureUser($k,'partner'); $partnerB=fixtureUser($k,'partner');
$branchA=fixtureBranch($k,$partnerA,$operator); $branchB=fixtureBranch($k,$partnerB,$operator);
$qid=$k->id(); $oid=$k->id(); $now=$k->now(); $spec=json_encode($input,JSON_THROW_ON_ERROR);
$k->execute('INSERT INTO quotes (id,design_id,user_id,version,subtotal_minor,tax_minor,total_minor,deposit_minor,estimated_cost_minor,tax_bps,`lines`,specification,delivery_terms,terms,approval_reference,expires_at,created_by,created_at) VALUES (?,?,?,1,10000,0,10000,0,5000,0,?,?,?,?,?,?,?,?)',[$qid,$did,$uid,'[]',$spec,'TEST delivery','TEST terms','TEST-QUOTE',gmdate('Y-m-d H:i:s',time()+86400),$operator,$now]);
$k->execute("INSERT INTO orders (id,user_id,quote_id,design_id,status,subtotal_minor,tax_minor,total_minor,deposit_minor,snapshot,accepted_terms_at,created_at,updated_at) VALUES (?,?,?,?,'paid',10000,0,10000,0,?,?,?,?)",[$oid,$uid,$qid,$did,$spec,$now,$now,$now]);
$task=$k->id(); $k->execute("INSERT INTO partner_tasks (id,order_id,branch_id,service_type,rate_card_id,amount_minor,status,assigned_by,created_at,accepted_at) VALUES (?,?,?,'measurement',?,2500,'accepted',?,?,?)",[$task,$oid,$branchA['branch'],$branchA['card'],$operator,$now,$now]);
$beforeSnapshot=$k->one('SELECT snapshot FROM orders WHERE id=?',[$oid])['snapshot'];
$k->user=$partnerA;
$partnerInput=['name'=>'Professional fitting','garment'=>'suit','unit'=>'cm','values'=>$values];
rejects(fn()=> $designs->handle('POST','/tasks/'.$task.'/measurements',$partnerInput),409,'Partner capture requires explicit task consent');
$k->user=$partnerB; rejects(fn()=> $designs->handle('POST','/tasks/'.$task.'/consent',['consent'=>true]),404,'Partner cannot grant customer consent');
$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$uid]));
$serviceTasks=$designs->handle('GET','/service-tasks',[]); check(count(array_filter($serviceTasks,fn($t)=>$t['id']===$task))===1,'Customer sees their assigned service task');
$designs->handle('POST','/tasks/'.$task.'/consent',['consent'=>true]);
$k->user=$partnerB; rejects(fn()=> $designs->handle('POST','/tasks/'.$task.'/measurements',$partnerInput),404,'Unrelated branch cannot capture measurements');
$k->user=$partnerA; $captured=$designs->handle('POST','/tasks/'.$task.'/measurements',$partnerInput);
check($captured['provenance']==='partner'&&$captured['captured_by']===$partnerA['id']&&$captured['verified_at']===null,'Partner provenance and operator retained without false verification');
$again=$designs->handle('POST','/tasks/'.$task.'/measurements',$partnerInput); check($again['id']===$captured['id'],'Repeated capture does not create duplicate profile');
$ownRecord=$designs->handle('GET','/measurements/'.$captured['id'],[]); check($ownRecord['id']===$captured['id'],'Assigned partner can read only task-scoped measurement');
$k->user=$partnerB; rejects(fn()=> $designs->handle('GET','/measurements/'.$captured['id'],[]),404,'Other partner cannot read captured profile');
$k->execute("UPDATE users SET role='operations' WHERE id=?",[$partnerA['id']]); $k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$partnerA['id']]));
rejects(fn()=> $designs->handle('POST','/measurements/'.$captured['id'].'/verify',['evidence'=>'Captured it myself']),403,'Capture operator cannot independently verify their own work');
$k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$operator]));
$designs->handle('POST','/measurements/'.$captured['id'].'/verify',['evidence'=>'Independent review of partner capture']);
check($beforeSnapshot===$k->one('SELECT snapshot FROM orders WHERE id=?',[$oid])['snapshot'],'Partner capture never mutates customer-approved order snapshot');

echo 'Core integration: ',$checks,' checks passed',PHP_EOL;
