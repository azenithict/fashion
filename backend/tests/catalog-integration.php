<?php
declare(strict_types=1);
// Catalog verification/publication rules, licensed media and public read models. Isolated test database only.
require __DIR__.'/../src/Kernel.php';
require __DIR__.'/../src/Catalog.php';
require __DIR__.'/../src/Designs.php';
use Zenith\Designs;
use Zenith\ApiError;
use Zenith\Kernel;
use Zenith\Catalog;

$config=require (getenv('ZENITH_TEST_CONFIG')?:__DIR__.'/../../work/private/config.php');
if (($config['environment']??'production')==='production') throw new RuntimeException('Refusing production test configuration');
$config['session_enabled']=false; $config['public_origin']='https://fashion.example.test';
$k=new Kernel($config); $c=new Catalog($k); $checks=0; $_SESSION=[]; $_SERVER['REMOTE_ADDR']='cat-'.bin2hex(random_bytes(6));
function check(bool $x,string $m): void { global $checks; if(!$x) throw new RuntimeException($m); $checks++; }
function rejects(callable $fn,int $status,string $m): void { try { $fn(); } catch (ApiError $e) { check($e->status===$status,$m.' (got '.$e->status.': '.$e->getMessage().')'); return; } throw new RuntimeException($m.' (accepted)'); }
function user(Kernel $k,string $role): array { $id=$k->id(); $n=$k->now(); $k->execute('INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',[$id,'Cat '.$role,$role.'-'.bin2hex(random_bytes(5)).'@example.test','x',$role,$n,$n]); return $k->one('SELECT * FROM users WHERE id=?',[$id]); }
function image(int $w,int $h): string { $i=imagecreatetruecolor($w,$h); imagefill($i,0,0,imagecolorallocate($i,40,60,50)); $f=tempnam(sys_get_temp_dir(),'img'); imagejpeg($i,$f,80); return $f; }
$api=fn(string $m,string $p,array $in=[])=>$c->handle($m,$p,$in);
$photo=fn(string $owner,string $id,string $source='original_photography')=>$c->ingest(['owner_type'=>$owner,'owner_id'=>$id,'source'=>$source,'licence_reference'=>'TEST-LIC-1','rights_holder'=>'A Zenith Fashion','alt_text'=>''],image(1400,1000),false);
$s=bin2hex(random_bytes(3));

// Access control
$k->user=null; rejects(fn()=>$api('GET','/catalog/admin'),401,'guest blocked from admin');
$k->user=user($k,'customer'); rejects(fn()=>$api('POST','/catalog/products',[]),403,'customer blocked from admin');
check($api('GET','/catalog/products')===[] || is_array($api('GET','/catalog/products')),'public list readable');
$k->user=user($k,'operations');

// Creation and validation
$p=$api('POST','/catalog/products',['sku'=>"AZ-SUIT-$s",'slug'=>"navy-suit-$s",'name'=>'Navy two-piece suit','category'=>'mens_tailoring','garment'=>'suit','description'=>'A two-piece suit specified to your measurements.','supplier_reference'=>'SUP-REF-9','faq'=>[['q'=>'Is the fabric fixed?','a'=>'Choose from linked fabrics.']]])['record'];
check($p['status']==='draft' && $p['faq'][0]['q']==='Is the fabric fixed?','product created as draft');
rejects(fn()=>$api('POST','/catalog/products',['sku'=>"AZ-SUIT-$s",'slug'=>"other-$s",'name'=>'x','category'=>'mens_tailoring','garment'=>'suit','description'=>'d']),409,'duplicate sku');
rejects(fn()=>$api('POST','/catalog/products',['sku'=>"X-$s",'slug'=>str_repeat('a',32),'name'=>'x','category'=>'mens_tailoring','garment'=>'suit','description'=>'d']),422,'id-like slug rejected');
rejects(fn()=>$api('POST','/catalog/products',['sku'=>"Y-$s",'slug'=>"y-$s",'name'=>'x','category'=>'mens_tailoring','garment'=>'cape','description'=>'d']),422,'unknown garment rejected');
$fabric=$api('POST','/catalog/materials',['kind'=>'fabric','supplier_code'=>"F-$s",'name'=>'Navy wool','composition'=>'100% wool','colour_name'=>'Navy','colour_hex'=>'#1F2A44','weight_gsm'=>260,'suitable_garments'=>['suit'],'private_cost_minor'=>4200])['record'];
check($fabric['suitable_garments']===['suit'] && $fabric['colour_hex']==='#1f2a44','material created');
rejects(fn()=>$api('POST','/catalog/materials',['kind'=>'fabric','supplier_code'=>"G-$s",'name'=>'x','suitable_garments'=>['cape']]),422,'invalid garment list');
$lapel=$api('POST','/catalog/options',['garment'=>'suit','group_key'=>'lapel','label'=>"Peak lapel $s"])['record'];
$dressOpt=$api('POST','/catalog/options',['garment'=>'dress','group_key'=>'neckline','label'=>"V neckline $s"])['record'];
$hidden=$api('POST','/catalog/options',['garment'=>'suit','group_key'=>'pocket','label'=>"Ticket pocket $s"])['record'];

// Verification path
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'inquiry_only']),409,'draft cannot publish');
$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'pending_verification']);
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'approved']),422,'approval needs reference');
check($api('POST',"/catalog/products/{$p['id']}/status",['status'=>'approved','reference'=>'Supplier email 2026-11-07'])['record']['verified_at']!==null,'approved with reference');
$d=$api('GET',"/catalog/admin/products/{$p['id']}");
$inq=array_values(array_filter($d['transitions'],fn($t)=>$t['status']==='inquiry_only'))[0];
check($inq['blockers']!==[],'admin sees publication blockers');
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'inquiry_only']),409,'no photo blocks publication');

// Licensed media
rejects(fn()=>$c->ingest(['owner_type'=>'product','owner_id'=>$p['id'],'source'=>'original_photography','licence_reference'=>'L','rights_holder'=>'R'],image(500,400),false),422,'low-resolution rejected');
rejects(fn()=>$c->ingest(['owner_type'=>'product','owner_id'=>$p['id'],'source'=>'scraped','licence_reference'=>'L','rights_holder'=>'R'],image(1200,900),false),422,'unknown source rejected');
rejects(fn()=>$c->ingest(['owner_type'=>'product','owner_id'=>$p['id'],'source'=>'original_photography','licence_reference'=>'','rights_holder'=>'R'],image(1200,900),false),422,'licence required');
$m=$photo('product',$p['id']);
check($m['status']==='pending' && (!function_exists('imagecreatefromstring') || ($m['display_name'] && $m['thumb_name'])),'pending media with derivatives');
check(is_file($config['storage_path'].'/catalog/'.$m['storage_name']) && (fileperms($config['storage_path'].'/catalog/'.$m['storage_name'])&0777)===0600,'original stored privately');
rejects(fn()=>$api('POST',"/catalog/media/{$m['id']}/status",['status'=>'approved']),409,'alt text required to approve');
$api('PATCH',"/catalog/media/{$m['id']}",['alt_text'=>'Navy two-piece suit, front view','featured'=>true]);
check($api('POST',"/catalog/media/{$m['id']}/status",['status'=>'approved'])['status']==='approved','media approved');
check(!in_array("navy-suit-$s",array_column($api('GET','/catalog/products'),'slug'),true),'approved but unpublished stays private');
$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'inquiry_only']);
$k->user=null;
$list=array_values(array_filter($api('GET','/catalog/products'),fn($r)=>$r['slug']==="navy-suit-$s"));
check(count($list)===1 && $list[0]['featured_media']['id']===$m['id'],'published in public list');
$pub=$api('GET',"/catalog/products/navy-suit-$s");
check(!isset($pub['sku'],$pub['supplier_reference']) && $pub['orderable']===false && $pub['pricing']==='quote_required','public detail hides private fields');
check($pub['measurement_requirements']===$k->contract['requiredMeasurements']['suit'] && $pub['materials']===[],'unverified materials hidden');
$k->user=user($k,'operations');

// Orderable requires verified fabric with real photograph
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/links",['material_ids'=>[],'option_ids'=>[$dressOpt['id']]]),422,'incompatible option rejected');
$api('POST',"/catalog/products/{$p['id']}/links",['material_ids'=>[$fabric['id']],'option_ids'=>[$lapel['id'],$hidden['id']]]);
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'available_to_order','reference'=>'Capability sheet v1']),409,'draft fabric blocks orderable');
$api('POST',"/catalog/materials/{$fabric['id']}/status",['status'=>'pending_verification']);
rejects(fn()=>$api('POST',"/catalog/materials/{$fabric['id']}/status",['status'=>'available','reference'=>'Swatch card']),409,'fabric needs photograph');
$fm=$photo('material',$fabric['id']); $api('PATCH',"/catalog/media/{$fm['id']}",['alt_text'=>'Navy wool close-up']); $api('POST',"/catalog/media/{$fm['id']}/status",['status'=>'approved']);
$api('POST',"/catalog/materials/{$fabric['id']}/status",['status'=>'available','reference'=>'Swatch card']);
foreach (['pending_verification','available'] as $st) $api('POST',"/catalog/options/{$lapel['id']}/status",['status'=>$st,'reference'=>'Capability sheet v1']);
check($api('POST',"/catalog/products/{$p['id']}/status",['status'=>'available_to_order','reference'=>'Capability sheet v1'])['record']['status']==='available_to_order','orderable after verification');
$k->user=null; $pub=$api('GET',"/catalog/products/navy-suit-$s");
check($pub['orderable'] && count($pub['materials'])===1 && !isset($pub['materials'][0]['private_cost_minor']) && !isset($pub['materials'][0]['supplier_code']),'public fabric without cost or supplier code');
check(array_column($pub['options'],'label')===["Peak lapel $s"],'only available options public');
check(count($api('GET','/catalog/materials',[]))>=1,'public fabric library');
$k->user=user($k,'operations');

// Catalog-based customer design: validated selections, availability review and immutable snapshot
$ops=$k->user; $designs=new Designs($k);
$cust=user($k,'customer'); $k->execute('UPDATE users SET email_verified_at=? WHERE id=?',[$k->now(),$cust['id']]); $k->user=$k->one('SELECT * FROM users WHERE id=?',[$cust['id']]);
rejects(fn()=>$designs->handle('POST','/designs',['name'=>'Mine','product_id'=>$p['id'],'selections'=>['materials'=>['fabric'=>$dressOpt['id']],'options'=>[]]]),422,'unlinked material rejected');
rejects(fn()=>$designs->handle('POST','/designs',['name'=>'Mine','product_id'=>$p['id'],'selections'=>['materials'=>[],'options'=>['pocket'=>$hidden['id']]]]),422,'unavailable option rejected');
$dz=$designs->handle('POST','/designs',['name'=>'Wedding suit','product_id'=>$p['id'],'selections'=>['materials'=>['fabric'=>$fabric['id']],'options'=>['lapel'=>$lapel['id']]],'fit'=>'Tailored','occasion'=>'Wedding','notes'=>'Slim trousers','deadline'=>gmdate('Y-m-d',time()+86400*90)]);
check($dz['specification']['mode']==='catalog' && $dz['specification']['garment']==='suit' && $dz['availability_issues']===[],'catalog design saved');
$ms=$designs->handle('POST','/measurements',['name'=>'Me','garment'=>'suit','unit'=>'cm','values'=>['chest'=>100,'waist'=>86,'hip'=>100,'shoulder'=>46,'sleeve'=>64,'inseam'=>80],'consent'=>true]);
$k->user=$ops; $api('POST',"/catalog/materials/{$fabric['id']}/status",['status'=>'unavailable']);
$k->user=$k->one('SELECT * FROM users WHERE id=?',[$cust['id']]);
check(count($designs->handle('GET','/designs/'.$dz['id'],[])['availability_issues'])===1,'unavailable fabric flagged for review');
rejects(fn()=>$designs->handle('POST','/designs/'.$dz['id'].'/submit',['measurement_id'=>$ms['id']]),409,'submit blocked while selection unavailable');
$k->user=$ops; $api('POST',"/catalog/materials/{$fabric['id']}/status",['status'=>'available','reference'=>'Restocked']);
$k->user=$k->one('SELECT * FROM users WHERE id=?',[$cust['id']]);
check($designs->handle('POST','/designs/'.$dz['id'].'/submit',['measurement_id'=>$ms['id']])['status']==='submitted','catalog design submitted');
$k->user=$ops; $api('PATCH',"/catalog/materials/{$fabric['id']}",['name'=>'Renamed wool']);
$v=json_decode($k->one('SELECT specification FROM design_versions WHERE design_id=?',[$dz['id']])['specification'],true);
check($v['selections']['materials']['fabric']['name']==='Navy wool' && $v['selections']['materials']['fabric']['supplier_code']==="F-$s" && $v['selections']['options']['lapel']['label']==="Peak lapel $s",'submitted snapshot immutable after catalog edit');

// Integrity guards
rejects(fn()=>$api('POST',"/catalog/products/{$p['id']}/links",['material_ids'=>[],'option_ids'=>[]]),409,'cannot strip fabric from orderable design');
check(count($api('GET',"/catalog/admin/products/{$p['id']}")['links']['material_ids'])===1,'links unchanged after rejected replace');
rejects(fn()=>$api('POST',"/catalog/media/{$m['id']}/status",['status'=>'withdrawn']),409,'cannot withdraw only photo of public design');
rejects(fn()=>$api('PATCH',"/catalog/materials/{$fabric['id']}",['suitable_garments'=>['dress']]),409,'cannot drop garment used by linked design');
$api('POST',"/catalog/products/{$p['id']}/status",['status'=>'archived']);
$k->user=null; rejects(fn()=>$api('GET',"/catalog/products/navy-suit-$s"),404,'archived design not public');
$k->user=user($k,'operations');
rejects(fn()=>$api('PATCH',"/catalog/products/{$p['id']}",['name'=>'Changed']),409,'archived design immutable');
check((int)$k->one("SELECT COUNT(*) n FROM audit_events WHERE entity='catalog_products' AND entity_id=?",[$p['id']])['n']>=6,'catalog changes audited');
echo "Catalog integration: $checks checks passed\n";
