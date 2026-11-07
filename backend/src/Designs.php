<?php
declare(strict_types=1);
namespace Zenith;

final class Designs
{
    public function __construct(private Kernel $k) {}
    public function handle(string $method,string $path,array $input): ?array
    {
        $k=$this->k;
        if ($path==='/designs' && $method==='GET') {
            $u=$k->actor(); $ops=in_array($u['role'],['admin','operations'],true);
            $rows=$ops?$k->all('SELECT * FROM designs ORDER BY created_at DESC LIMIT 500'):$k->all('SELECT * FROM designs WHERE user_id=? ORDER BY created_at DESC',[$u['id']]);
            return array_map(fn($d)=>$this->designOutput($d),$rows);
        }
        if ($path==='/designs' && $method==='POST') {
            $u=$k->actor(); $k->rateLimit('design-create:'.$u['id'],30,3600); $spec=$this->specification($input); $name=$k->text($input,'name',120); $id=$k->id(); $now=$k->now();
            $k->execute("INSERT INTO designs (id,user_id,name,status,specification,created_at,updated_at) VALUES (?,?,?,'draft',?,?,?)",[$id,$u['id'],$name,json_encode($spec,JSON_THROW_ON_ERROR),$now,$now]);
            $k->audit('design.created','designs',$id); return $this->designOutput($k->one('SELECT * FROM designs WHERE id=?',[$id]));
        }
        if (preg_match('#^/designs/([a-f0-9]{32})(?:/(duplicate|submit))?$#D',$path,$m)) {
            $id=$m[1]; $action=$m[2]??''; $u=$k->actor();
            if ($method==='GET' && $action==='') return $this->designOutput($this->ownDesign($id));
            if ($method==='POST' && $action==='duplicate') {
                $source=$this->ownDesign($id); $new=$k->id(); $now=$k->now();
                $k->execute("INSERT INTO designs (id,user_id,name,status,specification,created_at,updated_at) VALUES (?,?,?,'draft',?,?,?)",[$new,$u['id'],mb_substr($source['name'],0,112).' (copy)',$source['specification'],$now,$now]);
                $k->audit('design.duplicated','designs',$new,['source_id'=>$id]); return $this->designOutput($k->one('SELECT * FROM designs WHERE id=?',[$new]));
            }
            if ($method==='PATCH' && $action==='') return $k->transaction(function () use ($k,$id,$u,$input): array {
                $d=$this->ownDesign($id,true,false); if (!in_array($d['status'],['draft','revision_requested'],true)) $k->fail(409,'immutable_design','Submitted designs are locked. Duplicate it to create a new draft.');
                $spec=$this->specification($input); $name=$k->text($input,'name',120); $revision=(int)$d['revision']+($d['status']==='revision_requested'?1:0);
                $k->execute("UPDATE designs SET name=?,specification=?,status='draft',revision=?,measurement_id=NULL,updated_at=? WHERE id=?",[$name,json_encode($spec,JSON_THROW_ON_ERROR),$revision,$k->now(),$id]);
                $k->audit('design.updated','designs',$id); return $this->designOutput($k->one('SELECT * FROM designs WHERE id=?',[$id]));
            });
            if ($method==='POST' && $action==='submit') return $k->transaction(function () use ($k,$id,$input,$u): array {
                if (!$u['email_verified_at']) $k->fail(403,'verification_required','Verify your email before submitting a design.');
                $d=$this->ownDesign($id,true,false); if ($d['status']!=='draft') $k->fail(409,'invalid_state','Only a draft can be submitted.');
                $measurementId=$k->text($input,'measurement_id',32);
                $measure=$k->one('SELECT * FROM measurements WHERE id=? AND user_id=?',[$measurementId,$u['id']]);
                $spec=json_decode($d['specification'],true,512,JSON_THROW_ON_ERROR);
                if (!$measure || !$measure['consent_at'] || $measure['garment']!==$spec['garment']) $k->fail(422,'measurement_required','Select your consented measurement profile for this garment.');
                $this->validateMeasurements($measure['garment'],$measure['unit'],json_decode($measure['values_json'],true,512,JSON_THROW_ON_ERROR));
                $versionSpec=$d['specification'];
                if (($spec['mode']??'')==='catalog') { $issues=$this->catalogIssues($spec); if ($issues) $k->fail(409,'selection_unavailable',implode(' ',$issues)); $versionSpec=json_encode($this->catalogSnapshot($spec),JSON_THROW_ON_ERROR); }
                $snapshot=$this->measurementOutput($measure); $now=$k->now();
                $k->execute('INSERT INTO design_versions (id,design_id,revision,specification,measurements_snapshot,created_at) VALUES (?,?,?,?,?,?)',[$k->id(),$id,(int)$d['revision'],$versionSpec,json_encode($snapshot,JSON_THROW_ON_ERROR),$now]);
                $k->execute("UPDATE designs SET status='submitted',measurement_id=?,submitted_at=?,updated_at=? WHERE id=?",[$measurementId,$now,$now,$id]);
                $k->notify($u['id'],'design_submitted',['design_id'=>$id]); $k->audit('design.submitted','designs',$id,['revision'=>(int)$d['revision']]);
                return $this->designOutput($k->one('SELECT * FROM designs WHERE id=?',[$id]));
            });
        }
        if ($path==='/measurements' && $method==='GET') {
            $u=$k->actor(); $rows=in_array($u['role'],['operations','admin'],true)?$k->all('SELECT * FROM measurements ORDER BY created_at DESC LIMIT 500'):$k->all('SELECT * FROM measurements WHERE user_id=? ORDER BY created_at DESC',[$u['id']]);
            return array_map(fn($r)=>$this->measurementOutput($r),$rows);
        }
        if ($method==='GET' && preg_match('#^/measurements/([a-f0-9]{32})$#D',$path,$m)) {
            $u=$k->actor(); $r=$k->one('SELECT * FROM measurements WHERE id=?',[$m[1]]);
            $assigned=$r && $u['role']==='partner' && $k->one('SELECT t.id FROM partner_tasks t JOIN partner_members pm ON pm.branch_id=t.branch_id WHERE t.measurement_id=? AND pm.user_id=?',[$r['id'],$u['id']]);
            if (!$r || ($r['user_id']!==$u['id'] && !in_array($u['role'],['operations','admin'],true) && !$assigned)) $k->fail(404,'not_found','Measurement profile not found.');
            return $this->measurementOutput($r);
        }
        if ($path==='/measurements' && $method==='POST') {
            $u=$k->actor(); if (($input['consent']??null)!==true) $k->fail(422,'consent_required','Explicit consent is required for body measurements.');
            $name=$k->text($input,'name',120); $garment=$k->text($input,'garment',30); $unit=$k->text($input,'unit',2); $values=$input['values']??null;
            if (!is_array($values)||array_is_list($values)) $k->fail(422,'invalid_measurements','Measurements must be named numeric values.');
            $this->validateMeasurements($garment,$unit,$values); $id=$k->id(); $now=$k->now();
            $k->execute("INSERT INTO measurements (id,user_id,name,garment,unit,values_json,provenance,consent_at,created_at) VALUES (?,?,?,?,?,?,'self',?,?)",[$id,$u['id'],$name,$garment,$unit,json_encode($values,JSON_THROW_ON_ERROR),$now,$now]);
            $k->audit('measurement.created','measurements',$id,['provenance'=>'self','consent'=>true]); return $this->measurementOutput($k->one('SELECT * FROM measurements WHERE id=?',[$id]));
        }
        if ($method==='POST' && preg_match('#^/measurements/([a-f0-9]{32})/verify$#D',$path,$m)) {
            $u=$k->actor(['admin','operations']); $evidence=$k->text($input,'evidence',2000);
            $r=$k->one('SELECT * FROM measurements WHERE id=?',[$m[1]]); if (!$r) $k->fail(404,'not_found','Measurement profile not found.');
            if ($r['user_id']===$u['id'] || ($r['captured_by']??null)===$u['id']) $k->fail(403,'independent_review_required','An operator cannot verify their own measurements.');
            $k->execute('UPDATE measurements SET verified_at=?,verified_by=?,verification_evidence=? WHERE id=?',[$k->now(),$u['id'],$evidence,$m[1]]); $k->audit('measurement.verified','measurements',$m[1]);
            return $this->measurementOutput($k->one('SELECT * FROM measurements WHERE id=?',[$m[1]]));
        }
        if ($path==='/service-tasks' && $method==='GET') {
            $u=$k->actor();
            return $k->all('SELECT t.id,t.order_id,t.service_type,t.status,t.scheduled_at,t.customer_consent_at,t.measurement_id,b.name AS branch_name FROM partner_tasks t JOIN orders o ON o.id=t.order_id JOIN partner_branches b ON b.id=t.branch_id WHERE o.user_id=? ORDER BY t.created_at DESC',[$u['id']]);
        }
        if ($method==='POST' && preg_match('#^/tasks/([a-f0-9]{32})/consent$#D',$path,$m)) {
            $u=$k->actor(); if (($input['consent']??null)!==true) $k->fail(422,'consent_required','Explicit consent is required to share measurements with this assigned professional.');
            return $k->transaction(function () use ($k,$u,$m): array {
                $task=$k->one('SELECT t.*,o.user_id AS customer_id FROM partner_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=? FOR UPDATE',[$m[1]]);
                if (!$task || $task['customer_id']!==$u['id']) $k->fail(404,'not_found','Service task not found.');
                if ($task['service_type']!=='measurement' || !in_array($task['status'],['assigned','accepted'],true)) $k->fail(409,'invalid_state','This task is not awaiting measurement consent.');
                if (!$task['customer_consent_at']) {
                    $k->execute('UPDATE partner_tasks SET customer_consent_at=?,customer_consent_by=? WHERE id=?',[$k->now(),$u['id'],$task['id']]);
                    $k->audit('measurement.partner_consent','partner_tasks',$task['id'],['branch_id'=>$task['branch_id']]);
                }
                return ['ok'=>true,'task_id'=>$task['id'],'consent_at'=>$task['customer_consent_at']?:$k->now()];
            });
        }
        if ($method==='POST' && preg_match('#^/tasks/([a-f0-9]{32})/measurements$#D',$path,$m)) {
            $u=$k->actor(['partner']);
            return $k->transaction(function () use ($k,$u,$m,$input): array {
                $task=$k->one('SELECT t.*,o.user_id AS customer_id,d.specification FROM partner_tasks t JOIN orders o ON o.id=t.order_id JOIN designs d ON d.id=o.design_id JOIN partner_members pm ON pm.branch_id=t.branch_id AND pm.user_id=? WHERE t.id=? FOR UPDATE',[$u['id'],$m[1]]);
                if (!$task) $k->fail(404,'not_found','Assigned service task not found.');
                if ($task['service_type']!=='measurement' || $task['status']!=='accepted' || !$task['customer_consent_at'] || $task['customer_consent_by']!==$task['customer_id']) $k->fail(409,'consent_required','Accept the assigned measurement service and obtain explicit customer consent first.');
                if ($task['measurement_id']) return $this->measurementOutput($k->one('SELECT * FROM measurements WHERE id=?',[$task['measurement_id']]));
                $name=$k->text($input,'name',120); $garment=$k->text($input,'garment',30); $unit=$k->text($input,'unit',2); $values=$input['values']??null;
                $spec=json_decode($task['specification'],true,512,JSON_THROW_ON_ERROR);
                if ($garment!==($spec['garment']??null)||!is_array($values)||array_is_list($values)) $k->fail(422,'invalid_measurements','Use measurements for this order garment.');
                $this->validateMeasurements($garment,$unit,$values); $id=$k->id();
                $k->execute("INSERT INTO measurements (id,user_id,name,garment,unit,values_json,provenance,consent_at,created_at,captured_by) VALUES (?,?,?,?,?,?,'partner',?,?,?)",[$id,$task['customer_id'],$name,$garment,$unit,json_encode($values,JSON_THROW_ON_ERROR),$task['customer_consent_at'],$k->now(),$u['id']]);
                $k->execute('UPDATE partner_tasks SET measurement_id=? WHERE id=?',[$id,$task['id']]);
                $k->audit('measurement.partner_captured','measurements',$id,['task_id'=>$task['id'],'branch_id'=>$task['branch_id']]);
                $k->notify($task['customer_id'],'measurement_recorded',['task_id'=>$task['id'],'measurement_id'=>$id]);
                // This record never replaces the already-approved order specification.
                return $this->measurementOutput($k->one('SELECT * FROM measurements WHERE id=?',[$id]));
            });
        }
        if ($path==='/uploads' && $method==='POST') return $this->upload($input);
        if ($method==='GET' && preg_match('#^/uploads/([a-f0-9]{32})$#D',$path,$m)) {
            $u=$k->actor(); $file=$k->one('SELECT * FROM uploads WHERE id=?',[$m[1]]);
            if (!$file || ($file['user_id']!==$u['id'] && !in_array($u['role'],['operations','admin'],true))) $k->fail(404,'not_found','Attachment not found.');
            $storage=$k->config['storage_path'].'/uploads/'.$file['storage_name'];
            if (!is_file($storage)) $k->fail(404,'not_found','Attachment unavailable.');
            header('Content-Type: '.$file['mime']); header('Content-Length: '.(string)filesize($storage)); header('Content-Disposition: attachment; filename="reference-'. $file['id'] . ($file['mime']==='application/pdf'?'.pdf':($file['mime']==='image/png'?'.png':'.jpg')).'"'); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox");
            readfile($storage); exit;
        }
        if ($path==='/support' && $method==='GET') {
            $u=$k->actor(); $ops=in_array($u['role'],['operations','admin'],true); $cases=$ops?$k->all('SELECT * FROM support_cases ORDER BY updated_at DESC LIMIT 500'):$k->all('SELECT * FROM support_cases WHERE user_id=? ORDER BY updated_at DESC',[$u['id']]);
            foreach ($cases as &$case) $case['messages']=$k->all('SELECT id,author_id,message,internal,created_at FROM support_messages WHERE case_id=?'.($ops?'':' AND internal=0').' ORDER BY created_at',[$case['id']]); unset($case); return $cases;
        }
        if ($path==='/support' && $method==='POST') {
            $u=$k->actor(); $k->rateLimit('support:'.$u['id'],20,3600); $subject=$k->text($input,'subject',180); $message=$k->text($input,'message',10000); $orderId=$input['order_id']??null;
            if ($orderId!==null && (!is_string($orderId)||!$k->one('SELECT id FROM orders WHERE id=? AND user_id=?',[$orderId,$u['id']]))) $k->fail(422,'invalid_order','Select one of your orders.');
            return $k->transaction(function () use ($k,$u,$subject,$message,$orderId): array {
                $id=$k->id(); $now=$k->now(); $k->execute('INSERT INTO support_cases (id,user_id,order_id,subject,created_at,updated_at) VALUES (?,?,?,?,?,?)',[$id,$u['id'],$orderId,$subject,$now,$now]);
                $k->execute('INSERT INTO support_messages (id,case_id,author_id,message,created_at) VALUES (?,?,?,?,?)',[$k->id(),$id,$u['id'],$message,$now]); $k->audit('support.opened','support_cases',$id); $k->notify($u['id'],'support_received',['case_id'=>$id]); return ['id'=>$id,'status'=>'open'];
            });
        }
        if ($method==='POST' && preg_match('#^/support/([a-f0-9]{32})/messages$#D',$path,$m)) {
            $u=$k->actor(); $case=$k->one('SELECT * FROM support_cases WHERE id=?',[$m[1]]); $ops=in_array($u['role'],['operations','admin'],true);
            if (!$case||(!$ops&&$case['user_id']!==$u['id'])) $k->fail(404,'not_found','Support case not found.');
            $message=$k->text($input,'message',10000); $internal=($input['internal']??false)===true;
            if ($internal&&!$ops) $k->fail(403,'forbidden','Only operations may add internal notes.');
            return $k->transaction(function () use ($k,$u,$case,$message,$internal,$ops): array {
                $id=$k->id(); $k->execute('INSERT INTO support_messages (id,case_id,author_id,message,internal,created_at) VALUES (?,?,?,?,?,?)',[$id,$case['id'],$u['id'],$message,(int)$internal,$k->now()]); $k->execute('UPDATE support_cases SET updated_at=? WHERE id=?',[$k->now(),$case['id']]);
                if ($ops&&!$internal) $k->notify($case['user_id'],'support_message',['case_id'=>$case['id']]); $k->audit('support.message','support_cases',$case['id'],['internal'=>$internal]); return ['id'=>$id,'ok'=>true];
            });
        }
        if ($path==='/notifications' && $method==='GET') { $u=$k->actor(); $rows=$k->all('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 200',[$u['id']]); foreach ($rows as &$r) $r['payload']=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR); unset($r); return $rows; }
        if ($method==='POST' && preg_match('#^/notifications/([a-f0-9]{32})/read$#D',$path,$m)) { $u=$k->actor(); $r=$k->one('SELECT id FROM notifications WHERE id=? AND user_id=?',[$m[1],$u['id']]); if (!$r) $k->fail(404,'not_found','Notification not found.'); $k->execute('UPDATE notifications SET read_at=COALESCE(read_at,?) WHERE id=?',[$k->now(),$m[1]]); return ['ok'=>true]; }
        if ($path==='/account/deletion-request' && $method==='POST') {
            $u=$k->actor(); $id=$k->id(); $k->execute('INSERT IGNORE INTO deletion_requests (id,user_id,created_at) VALUES (?,?,?)',[$id,$u['id'],$k->now()]); $k->audit('account.deletion_requested','users',$u['id']); return ['status'=>'pending','message'=>'Your request is recorded for privacy review. Financial records may require lawful retention.'];
        }
        if ($path==='/account/export' && $method==='GET') {
            $u=$k->actor(); $k->audit('account.exported','users',$u['id']);
            $cases=$k->all('SELECT * FROM support_cases WHERE user_id=?',[$u['id']]); foreach ($cases as &$c) $c['messages']=$k->all('SELECT id,author_id,message,created_at FROM support_messages WHERE case_id=? AND internal=0',[$c['id']]); unset($c);
            return ['exported_at'=>gmdate(DATE_ATOM),'profile'=>$u,'addresses'=>$k->all('SELECT * FROM addresses WHERE user_id=?',[$u['id']]),'designs'=>array_map(fn($d)=>$this->designOutput($d),$k->all('SELECT * FROM designs WHERE user_id=?',[$u['id']])),'measurements'=>array_map(fn($m)=>$this->measurementOutput($m),$k->all('SELECT * FROM measurements WHERE user_id=?',[$u['id']])),'uploads'=>$k->all('SELECT id,design_id,original_name,mime,byte_size,created_at FROM uploads WHERE user_id=?',[$u['id']]),'support'=>$cases,'orders'=>$k->all('SELECT id,quote_id,design_id,status,currency,subtotal_minor,tax_minor,total_minor,deposit_minor,accepted_terms_at,created_at,updated_at FROM orders WHERE user_id=?',[$u['id']]),'payments'=>$k->all('SELECT p.id,p.order_id,p.kind,p.amount_minor,p.currency,p.created_at FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.user_id=?',[$u['id']]),'notifications'=>$k->all('SELECT id,event,payload,read_at,created_at FROM notifications WHERE user_id=?',[$u['id']]),'deletion_requests'=>$k->all('SELECT id,status,created_at FROM deletion_requests WHERE user_id=?',[$u['id']])];
        }
        return null;
    }
    private function specification(array $in): array {
        if (array_key_exists('product_id',$in)) return $this->catalogSpecification($in);
        $k=$this->k; $spec=[];
        foreach (['garment'=>'garments','shade'=>'shades','fit'=>'fits','finish'=>'finishes'] as $key=>$enum) { $v=$k->text($in,$key,40); if (!in_array($v,$k->contract[$enum],true)) $k->fail(422,'invalid_option','Unsupported design option.'); $spec[$key]=$v; }
        $spec['notes']=$k->text($in,'notes',800,false); return $spec;
    }
    // Catalog-based design: references a published design and its verified, linked materials/options.
    private function catalogSpecification(array $in): array {
        $k=$this->k; $productId=$k->text($in,'product_id',32);
        $product=$k->one('SELECT id,slug,name,garment,status FROM catalog_products WHERE id=? AND status IN (?,?)',[$productId,...$k->contract['catalogPublicStatuses']]);
        if (!$product) $k->fail(422,'invalid_option','This design is not available for customization.');
        $sel=$in['selections']??[]; if (!is_array($sel)) $k->fail(422,'invalid_option','Invalid selections.');
        $materials=$sel['materials']??[]; $options=$sel['options']??[];
        if (!is_array($materials)||!is_array($options)||($materials!==[]&&array_is_list($materials))||($options!==[]&&array_is_list($options))) $k->fail(422,'invalid_option','Invalid selections.');
        $spec=['mode'=>'catalog','product_id'=>$product['id'],'product_slug'=>$product['slug'],'product_name'=>$product['name'],'garment'=>$product['garment'],'selections'=>['materials'=>[],'options'=>[]]];
        $spec['labels']=['materials'=>[],'options'=>[]];
        foreach ($materials as $kind=>$id) {
            if (!in_array($kind,$k->contract['materialKinds'],true)||!is_string($id)||!($row=$k->one("SELECT m.id,m.name FROM catalog_links l JOIN catalog_materials m ON m.id=l.target_id WHERE l.product_id=? AND l.target_type='material' AND m.id=? AND m.kind=? AND m.status='available'",[$product['id'],$id,$kind]))) $k->fail(422,'invalid_option','A selected material is not available for this design.');
            $spec['selections']['materials'][$kind]=$id; $spec['labels']['materials'][$kind]=$row['name'];
        }
        foreach ($options as $group=>$id) {
            if (!is_string($group)||!preg_match('/^[a-z][a-z_]{1,39}$/D',$group)||!is_string($id)||!($row=$k->one("SELECT o.id,o.label FROM catalog_links l JOIN catalog_options o ON o.id=l.target_id WHERE l.product_id=? AND l.target_type='option' AND o.id=? AND o.group_key=? AND o.status='available'",[$product['id'],$id,$group]))) $k->fail(422,'invalid_option','A selected construction option is not available for this design.');
            $spec['selections']['options'][$group]=$id; $spec['labels']['options'][$group]=$row['label'];
        }
        if (isset($in['fit'])&&$in['fit']!==''&&!in_array($in['fit'],$k->contract['fits'],true)) $k->fail(422,'invalid_option','Unsupported design option.');
        $spec['fit']=is_string($in['fit']??null)?$in['fit']:'';
        $spec['occasion']=$k->text($in,'occasion',160,false); $spec['notes']=$k->text($in,'notes',800,false);
        $quantity=$in['quantity']??null; if ($quantity!==null&&(!is_int($quantity)||$quantity<1||$quantity>10000)) $k->fail(422,'invalid_option','Invalid quantity.'); $spec['quantity']=$quantity;
        $deadline=$in['deadline']??null;
        if ($deadline!==null&&$deadline!=='') { $date=is_string($deadline)?\DateTimeImmutable::createFromFormat('!Y-m-d',$deadline):false; if (!$date||$date->format('Y-m-d')!==$deadline) $k->fail(422,'invalid_option','Invalid deadline.'); } else $deadline=null;
        $spec['deadline']=$deadline; return $spec;
    }
    // Current problems with a saved catalog selection (availability can change after saving).
    private function catalogIssues(array $spec): array {
        $k=$this->k; $issues=[];
        if (!$k->one('SELECT id FROM catalog_products WHERE id=? AND status IN (?,?)',[$spec['product_id'],...$k->contract['catalogPublicStatuses']])) return ['The selected design is no longer available.'];
        foreach ($spec['selections']['materials'] as $kind=>$id) if (!$k->one("SELECT m.id FROM catalog_links l JOIN catalog_materials m ON m.id=l.target_id WHERE l.product_id=? AND l.target_type='material' AND m.id=? AND m.status='available'",[$spec['product_id'],$id])) $issues[]='The selected '.$kind.' is no longer available. Choose another.';
        foreach ($spec['selections']['options'] as $group=>$id) if (!$k->one("SELECT o.id FROM catalog_links l JOIN catalog_options o ON o.id=l.target_id WHERE l.product_id=? AND l.target_type='option' AND o.id=? AND o.status='available'",[$spec['product_id'],$id])) $issues[]='The selected '.str_replace('_',' ',$group).' option is no longer available. Choose another.';
        if (!isset($spec['selections']['materials']['fabric'])&&$k->one("SELECT m.id FROM catalog_links l JOIN catalog_materials m ON m.id=l.target_id WHERE l.product_id=? AND l.target_type='material' AND m.kind='fabric' AND m.status='available' LIMIT 1",[$spec['product_id']])) $issues[]='Choose a fabric before submitting.';
        return $issues;
    }
    // Immutable submission snapshot: names and supplier codes as verified at submission time.
    private function catalogSnapshot(array $spec): array {
        $k=$this->k; $snap=$spec; unset($snap['labels']); $snap['selections']=['materials'=>[],'options'=>[]];
        foreach ($spec['selections']['materials'] as $kind=>$id) { $m=$k->one('SELECT id,kind,supplier_code,name,composition,colour_name,pattern,weight_gsm,weave,finish,verification_reference FROM catalog_materials WHERE id=?',[$id]); $snap['selections']['materials'][$kind]=$m; }
        foreach ($spec['selections']['options'] as $group=>$id) { $o=$k->one('SELECT id,group_key,label,description,verification_reference FROM catalog_options WHERE id=?',[$id]); $snap['selections']['options'][$group]=$o; }
        $snap['snapshot_at']=$k->now(); return $snap;
    }
    private function ownDesign(string $id,bool $lock=false,bool $allowOps=true): array {
        $k=$this->k; $u=$k->actor(); $d=$k->one('SELECT * FROM designs WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if (!$d || ($d['user_id']!==$u['id'] && !($allowOps&&in_array($u['role'],['admin','operations'],true)))) $k->fail(404,'not_found','Design not found.'); return $d;
    }
    private function designOutput(array $d): array {
        $d['specification']=json_decode($d['specification'],true,512,JSON_THROW_ON_ERROR); $d['revision']=(int)$d['revision'];
        if (($d['specification']['mode']??'')==='catalog'&&in_array($d['status'],['draft','revision_requested'],true)) $d['availability_issues']=$this->catalogIssues($d['specification']);
        $d['uploads']=$this->k->all('SELECT id,original_name,mime,byte_size,created_at FROM uploads WHERE design_id=? ORDER BY created_at',[$d['id']]);
        if (!in_array($this->k->user['role']??'', ['admin','operations'],true)) unset($d['reviewed_by']); return $d;
    }
    private function measurementOutput(array $m): array { $m['values']=json_decode($m['values_json'],true,512,JSON_THROW_ON_ERROR); unset($m['values_json']); if (!in_array($this->k->user['role']??'', ['admin','operations'],true)) unset($m['verification_evidence'],$m['verified_by']); return $m; }
    public function validateMeasurements(string $garment,string $unit,array $values): void {
        $k=$this->k; if (!isset($k->contract['requiredMeasurements'][$garment])||!in_array($unit,['cm','in'],true)) $k->fail(422,'invalid_measurements','Choose a valid garment and measurement unit.');
        foreach ($k->contract['requiredMeasurements'][$garment] as $field) if (!array_key_exists($field,$values)) $k->fail(422,'incomplete_measurements','Missing measurement: '.$field.'.');
        foreach ($values as $field=>$value) {
            if (!isset($k->contract['measurementFields'][$field])||(!is_int($value)&&!is_float($value))||!is_finite((float)$value)) $k->fail(422,'invalid_measurements','Use supported numeric measurements.');
            [$min,$max]=$k->contract['measurementFields'][$field]; $cm=$unit==='in'?$value*2.54:$value; if ($cm<$min||$cm>$max) $k->fail(422,'invalid_measurements','Measurement outside the reviewable range: '.$field.'.');
        }
    }
    private function upload(array $input): array {
        $k=$this->k; return $k->transaction(function () use ($k,$input): array {
        $u=$k->actor(); $k->rateLimit('upload:'.$u['id'],30,3600); $id=$k->text($input,'design_id',32); $d=$this->ownDesign($id,true,false);
        if (!in_array($d['status'],['draft','revision_requested'],true)) $k->fail(409,'immutable_design','Attachments can only be added to draft or revision-requested designs.');
        $file=$_FILES['file']??null;
        if (!is_array($file)||!isset($file['error'],$file['tmp_name'])||$file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name'])||!is_uploaded_file($file['tmp_name'])) $k->fail(422,'invalid_upload','Choose a JPEG, PNG or PDF attachment.');
        $size=filesize($file['tmp_name']); if ($size===false||$size<1||$size>8*1024*1024) $k->fail(422,'invalid_upload','Attachments must be no larger than 8 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if (!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) $k->fail(422,'invalid_upload','Only JPEG, PNG and PDF attachments are accepted.');
        if ($mime!=='application/pdf') { $image=getimagesize($file['tmp_name']); if (!$image||$image[0]*$image[1]>20000000) $k->fail(422,'invalid_upload','Invalid image or image dimensions are too large.'); }
        else { $fh=fopen($file['tmp_name'],'rb'); $magic=fread($fh,5); fclose($fh); if ($magic!=='%PDF-') $k->fail(422,'invalid_upload','Invalid PDF.'); }
        $folder=$k->config['storage_path'].'/uploads'; if (!is_dir($folder)||!is_writable($folder)) $k->fail(503,'storage_unavailable','Private attachment storage is unavailable.');
        $count=$k->one('SELECT COUNT(*) AS n FROM uploads WHERE design_id=?',[$id]); if ((int)$count['n']>=10) $k->fail(422,'upload_limit','Each design may have up to ten reference attachments.');
        $uploadId=$k->id(); $name=bin2hex(random_bytes(32)); $target=$folder.'/'.$name; $original=mb_substr(basename(is_string($file['name']??null)?$file['name']:'reference'),0,200);
        $sha=hash_file('sha256',$file['tmp_name']); if (!move_uploaded_file($file['tmp_name'],$target)) $k->fail(503,'storage_unavailable','The attachment could not be stored.'); chmod($target,0600);
        try { $k->execute('INSERT INTO uploads (id,design_id,user_id,original_name,storage_name,mime,byte_size,sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$uploadId,$id,$u['id'],$original,$name,$mime,$size,$sha,$k->now()]); }
        catch (\Throwable $e) { unlink($target); throw $e; }
        $k->audit('attachment.created','uploads',$uploadId); return ['id'=>$uploadId,'design_id'=>$id,'original_name'=>$original,'mime'=>$mime,'byte_size'=>$size];
        });
    }
}
