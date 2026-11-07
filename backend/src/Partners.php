<?php
declare(strict_types=1);
namespace Zenith;

final class Partners
{
    public function __construct(private Kernel $k) {}
    private function text(array $in, string $key, int $max=500): string
    {
        $v=$in[$key]??null;
        if (!is_string($v) || trim($v)==='' || mb_strlen($v)>$max) $this->k->fail(422,'validation',"A valid $key is required.");
        return trim($v);
    }
    private function staff(): array { return $this->k->actor(['operations','admin']); }
    private function branch(string $id): array
    {
        $u=$this->k->actor();
        $b=$this->k->one('SELECT b.*, p.agreement_reference, p.business_name FROM partner_branches b JOIN partners p ON p.id=b.partner_id WHERE b.id=?',[$id]);
        if (!$b) $this->k->fail(404,'not_found','Branch not found.');
        if (!in_array($u['role'],['operations','admin'],true) && !$this->k->one('SELECT user_id FROM partner_members WHERE user_id=? AND branch_id=?',[$u['id'],$id])) $this->k->fail(404,'not_found','Branch not found.');
        return $b;
    }
    private function task(string $id, bool $lock=false): array
    {
        $t=$this->k->one('SELECT * FROM partner_tasks WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if (!$t) $this->k->fail(404,'not_found','Service task not found.');
        $this->branch($t['branch_id']);
        return $t;
    }
    public function handle(string $method,string $path,array $in): ?array
    {
        $k=$this->k;
        if ($path==='/partner-applications' && $method==='POST') {
            $u=$k->actor(['customer']);
            if (!$u['email_verified_at']) $k->fail(409,'email_unverified','Verify your email before applying.');
            $name=$this->text($in,'business_name',180); $contact=$this->text($in,'contact');
            $services=$in['services']??null;
            if (!is_array($services)||!array_is_list($services)||count($services)<1||count($services)>9||count(array_filter($services,'is_string'))!==count($services)||array_diff($services,$k->contract['serviceTypes'])) $k->fail(422,'validation','Select supported service types.');
            return $k->transaction(function()use($k,$u,$name,$contact,$services){
                $k->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$u['id']]);
                if ($k->one("SELECT id FROM partner_applications WHERE user_id=? AND status='pending'",[$u['id']])) $k->fail(409,'application_pending','An application is already pending.');
                $id=$k->id(); $k->execute('INSERT INTO partner_applications(id,user_id,business_name,contact,services,created_at) VALUES(?,?,?,?,?,?)',[$id,$u['id'],$name,$contact,json_encode($services,JSON_THROW_ON_ERROR),$k->now()]);
                $k->audit('partner.applied','partner_application',$id); return ['id'=>$id,'status'=>'pending'];
            });
        }
        if ($path==='/partner-applications' && $method==='GET') {
            $k->actor(['admin']); return $k->all('SELECT * FROM partner_applications ORDER BY created_at DESC LIMIT 500');
        }
        if ($method==='POST' && preg_match('#^/partner-applications/([a-f0-9]{32})/approve$#',$path,$m)) {
            $u=$k->actor(['admin']); $agreement=$this->text($in,'agreement_reference');$branch=$this->text($in,'branch_name',180);$reference=$this->text($in,'rate_card_reference');
            $rates=$in['rates']??null;
            if (!is_array($rates)||array_is_list($rates)||!$rates||count($rates)>9) $k->fail(422,'validation','An approved service rate card is required.');
            foreach ($rates as $service=>$amount) if (!in_array($service,$k->contract['serviceTypes'],true)||!is_int($amount)||$amount<0||$amount>100000000) $k->fail(422,'validation','Rates must be approved integer CAD cents.');
            return $k->transaction(function()use($k,$u,$m,$agreement,$branch,$reference,$rates){
                $a=$k->one('SELECT * FROM partner_applications WHERE id=? FOR UPDATE',[$m[1]]);
                if (!$a) $k->fail(404,'not_found','Application not found.');
                if ($a['status']!=='pending') $k->fail(409,'already_processed','Application has already been processed.');
                $applicant=$k->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$a['user_id']]);
                if (!$applicant||$applicant['role']!=='customer') $k->fail(409,'invalid_applicant','Applicant must be a customer account.');
                $partner=$k->id();$bid=$k->id();$rid=$k->id();$now=$k->now();
                $k->execute('INSERT INTO partners(id,application_id,business_name,agreement_reference,approved_by,active_at) VALUES(?,?,?,?,?,?)',[$partner,$a['id'],$a['business_name'],$agreement,$u['id'],$now]);
                $k->execute('INSERT INTO partner_branches(id,partner_id,name,created_at) VALUES(?,?,?,?)',[$bid,$partner,$branch,$now]);
                $k->execute('INSERT INTO partner_members(user_id,branch_id) VALUES(?,?)',[$a['user_id'],$bid]);
                $k->execute('INSERT INTO rate_cards(id,branch_id,version,approval_reference,approved_by,created_at) VALUES(?,?,1,?,?,?)',[$rid,$bid,$reference,$u['id'],$now]);
                foreach($rates as $type=>$amount) $k->execute('INSERT INTO rate_card_items(rate_card_id,service_type,amount_minor) VALUES(?,?,?)',[$rid,$type,$amount]);
                $k->execute("UPDATE partner_applications SET status='approved',approved_at=? WHERE id=?",[$now,$a['id']]);
                $k->execute("UPDATE users SET role='partner' WHERE id=?",[$a['user_id']]);
                $k->audit('partner.approved','partner',$partner,['agreement_reference'=>$agreement,'rate_card_id'=>$rid]);
                $k->notify($a['user_id'],'partner.approved',['partner_id'=>$partner]);
                return ['id'=>$partner,'branch_id'=>$bid,'rate_card_id'=>$rid];
            });
        }
        if ($method==='POST' && preg_match('#^/partners/([a-f0-9]{32})/branches$#',$path,$m)) {
            $u=$k->actor(['admin']);$name=$this->text($in,'name',180);$approval=$this->text($in,'approval_reference');
            if (!$k->one('SELECT id FROM partners WHERE id=?',[$m[1]])) $k->fail(404,'not_found','Approved partner not found.');
            $id=$k->id();$k->execute('INSERT INTO partner_branches(id,partner_id,name,created_at) VALUES(?,?,?,?)',[$id,$m[1],$name,$k->now()]);
            $k->audit('branch.created','partner_branch',$id,['approval_reference'=>$approval]);return ['id'=>$id,'partner_id'=>$m[1],'name'=>$name];
        }
        if (preg_match('#^/branches/([a-f0-9]{32})/staff$#',$path,$m)) {
            $u=$k->actor(['admin']);$this->branch($m[1]);
            if ($method==='GET') return $k->all('SELECT u.id,u.name,u.email FROM users u JOIN partner_members pm ON pm.user_id=u.id WHERE pm.branch_id=?',[$m[1]]);
            if ($method==='POST') {
                $email=strtolower($this->text($in,'email',254));$reference=$this->text($in,'approval_reference');
                return $k->transaction(function()use($k,$m,$email,$reference){
                    $member=$k->one('SELECT * FROM users WHERE email=? AND disabled_at IS NULL FOR UPDATE',[$email]);
                    if (!$member || !$member['email_verified_at'] || !in_array($member['role'],['customer','partner'],true)) $k->fail(422,'invalid_staff','Use an existing verified customer or partner account.');
                    $existing=$k->all('SELECT b.partner_id FROM partner_members pm JOIN partner_branches b ON b.id=pm.branch_id WHERE pm.user_id=?',[$member['id']]);
                    $target=$this->branch($m[1]);foreach($existing as $e)if($e['partner_id']!==$target['partner_id'])$k->fail(409,'partner_isolation','This account already belongs to another independent partner.');
                    $k->execute('INSERT IGNORE INTO partner_members(user_id,branch_id) VALUES(?,?)',[$member['id'],$m[1]]);$k->execute("UPDATE users SET role='partner' WHERE id=?",[$member['id']]);
                    $k->audit('branch.staff_assigned','partner_branch',$m[1],['user_id'=>$member['id'],'approval_reference'=>$reference]);return ['ok'=>true];
                });
            }
        }
        if ($method==='DELETE' && preg_match('#^/branches/([a-f0-9]{32})/staff/([a-f0-9]{32})$#',$path,$m)) {
            $k->actor(['admin']);$this->branch($m[1]);
            return $k->transaction(function()use($k,$m){
                $k->execute('DELETE FROM partner_members WHERE branch_id=? AND user_id=?',[$m[1],$m[2]]);
                $k->execute('UPDATE users SET session_version=session_version+1 WHERE id=?',[$m[2]]);
                $k->audit('branch.staff_revoked','partner_branch',$m[1],['user_id'=>$m[2]]);return ['ok'=>true];
            });
        }
        if ($path==='/partners' && $method==='GET') {
            $this->staff();return $k->all('SELECT p.id,p.business_name,p.agreement_reference,p.active_at,b.id AS branch_id,b.name AS branch_name,r.id AS rate_card_id,r.version FROM partners p JOIN partner_branches b ON b.partner_id=p.id LEFT JOIN rate_cards r ON r.id=(SELECT rc.id FROM rate_cards rc WHERE rc.branch_id=b.id ORDER BY rc.version DESC LIMIT 1) ORDER BY p.business_name,b.name');
        }
        if ($method==='POST' && preg_match('#^/branches/([a-f0-9]{32})/rate-cards$#',$path,$m)) {
            $u=$k->actor(['admin']);$this->branch($m[1]);$reference=$this->text($in,'approval_reference');$rates=$in['rates']??[];
            if (!is_array($rates)||array_is_list($rates)||!$rates) $k->fail(422,'validation','Approved rates required.');
            foreach($rates as $type=>$amount) if (!in_array($type,$k->contract['serviceTypes'],true)||!is_int($amount)||$amount<0||$amount>100000000) $k->fail(422,'validation','Invalid service rate.');
            return $k->transaction(function()use($k,$u,$m,$rates,$reference){
                $k->one('SELECT id FROM partner_branches WHERE id=? FOR UPDATE',[$m[1]]);
                $v=(int)$k->one('SELECT COALESCE(MAX(version),0) AS v FROM rate_cards WHERE branch_id=?',[$m[1]])['v']+1;$id=$k->id();
                $k->execute('INSERT INTO rate_cards(id,branch_id,version,approval_reference,approved_by,created_at) VALUES(?,?,?,?,?,?)',[$id,$m[1],$v,$reference,$u['id'],$k->now()]);
                foreach($rates as $type=>$amount) $k->execute('INSERT INTO rate_card_items VALUES(?,?,?)',[$id,$type,$amount]);
                $k->audit('rate_card.created','rate_card',$id);return ['id'=>$id,'version'=>$v];
            });
        }
        if ($path==='/tasks' && $method==='GET') {
            $u=$k->actor(['partner','operations','admin']);
            if ($u['role']==='partner') return $k->all('SELECT t.*,b.name AS branch_name FROM partner_tasks t JOIN partner_branches b ON b.id=t.branch_id JOIN partner_members m ON m.branch_id=t.branch_id WHERE m.user_id=? ORDER BY t.created_at DESC LIMIT 500',[$u['id']]);
            return $k->all('SELECT t.*,b.name AS branch_name FROM partner_tasks t JOIN partner_branches b ON b.id=t.branch_id ORDER BY t.created_at DESC LIMIT 500');
        }
        if ($path==='/tasks' && $method==='POST') {
            $u=$this->staff();$oid=$this->text($in,'order_id',32);$bid=$this->text($in,'branch_id',32);$type=$this->text($in,'service_type',40);$this->branch($bid);
            $date=$in['scheduled_at']??null;
            if ($date!==null && (!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/D',$date))) $k->fail(422,'validation','Use a valid UTC appointment date.');
            if ($date!==null) {
                try {$parsed=new \DateTimeImmutable($date,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();if($errors!==false&&($errors['warning_count']||$errors['error_count']))$k->fail(422,'validation','Invalid appointment date.');$date=$parsed->format('Y-m-d H:i:s');} catch (\Exception) {$k->fail(422,'validation','Invalid appointment date.');}
            }
            return $k->transaction(function()use($k,$u,$oid,$bid,$type,$date){
                $o=$k->one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$oid]);
                if (!$o||in_array($o['status'],['cancelled','refunded','disputed'],true)) $k->fail(409,'invalid_order','An eligible order is required.');
                $rate=$k->one('SELECT r.id, i.amount_minor FROM rate_cards r JOIN rate_card_items i ON i.rate_card_id=r.id WHERE r.id=(SELECT rc.id FROM rate_cards rc WHERE rc.branch_id=? ORDER BY rc.version DESC LIMIT 1) AND i.service_type=?',[$bid,$type]);
                if (!$rate) $k->fail(409,'service_unavailable','This branch has no approved rate for that service.');
                if ($type==='referral' && $k->one("SELECT id FROM partner_tasks WHERE order_id=? AND service_type='referral'",[$oid])) $k->fail(409,'duplicate_referral','An order can have only one referral attribution.');
                $id=$k->id();$k->execute('INSERT INTO partner_tasks(id,order_id,branch_id,service_type,rate_card_id,amount_minor,scheduled_at,assigned_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$oid,$bid,$type,$rate['id'],$rate['amount_minor'],$date,$u['id'],$k->now()]);
                $k->audit('task.assigned','partner_task',$id);
                foreach($k->all('SELECT user_id FROM partner_members WHERE branch_id=?',[$bid]) as $p) $k->notify($p['user_id'],'service.assigned',['task_id'=>$id]);
                return ['id'=>$id,'status'=>'assigned'];
            });
        }
        if ($method==='POST' && preg_match('#^/tasks/([a-f0-9]{32})/(accept|complete|approve)$#',$path,$m)) {
            $u=$k->actor($m[2]==='approve'?['operations','admin']:['partner']);
            return $k->transaction(function()use($k,$u,$m,$in){
                $t=$this->task($m[1],true);$action=$m[2];$now=$k->now();
                $required=['accept'=>'assigned','complete'=>'accepted','approve'=>'completed'];
                if ($t['status']!==$required[$action]) $k->fail(409,'invalid_transition','This task cannot perform that action.');
                if ($action==='accept') $k->execute("UPDATE partner_tasks SET status='accepted',accepted_at=? WHERE id=?",[$now,$t['id']]);
                if ($action==='complete') {
                    if ($t['service_type']==='measurement' && empty($t['measurement_id'])) $k->fail(409,'measurement_required','Record the consented measurement profile before completing this task.');
                    $evidence=$this->text($in,'evidence',4000);
                    $k->execute("UPDATE partner_tasks SET status='completed',evidence=?,completed_by=?,completed_at=? WHERE id=?",[$evidence,$u['id'],$now,$t['id']]);
                }
                $approvalEvidence=null;
                if ($action==='approve') {
                    $approvalEvidence=$this->text($in,'evidence',4000);
                    if ($t['service_type']==='measurement') {
                        $profile=$k->one('SELECT verified_at FROM measurements WHERE id=?',[$t['measurement_id']??null]);
                        if (!$profile || !$profile['verified_at']) $k->fail(409,'verification_required','Independent measurement verification is required before approving compensation.');
                    }
                    if ($t['completed_by']===$u['id']) $k->fail(403,'separation_of_duties','A different operator must approve the work.');
                    $o=$k->one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$t['order_id']]);
                    if (in_array($o['status'],['cancelled','refunded','disputed'],true)) $k->fail(409,'invalid_order','Review cancelled or disputed work before compensation.');
                    if ($t['service_type']==='referral' && !in_array($o['status'],['paid','production_preparation','in_production','quality_inspection','shipping_preparation','in_transit','arrived_canada','local_fitting','ready_for_delivery','delivered','completed'],true)) $k->fail(409,'referral_unearned','Referral requires a fully paid order and verified work evidence.');
                    $branch=$this->branch($t['branch_id']);$id=$k->id();
                    $k->execute('INSERT INTO partner_ledger(id,partner_id,branch_id,order_id,task_id,rate_card_id,service_type,amount_minor,event_key,earning_condition,responsible_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$branch['partner_id'],$t['branch_id'],$t['order_id'],$t['id'],$t['rate_card_id'],$t['service_type'],$t['amount_minor'],'task:'.$t['id'],'Completed evidence reviewed under approved agreement',$u['id'],$now]);
                    $k->execute("UPDATE partner_tasks SET status='approved',approved_by=?,approved_at=? WHERE id=?",[$u['id'],$now,$t['id']]);
                }
                $k->audit('task.'.$action,'partner_task',$t['id'],['operator_evidence'=>$approvalEvidence]);return $this->task($t['id']);
            });
        }
        if ($path==='/ledger' && $method==='GET') {
            $u=$k->actor(['partner','operations','admin']);
            $sql='SELECT l.*,s.settlement_id FROM partner_ledger l LEFT JOIN settlement_items s ON s.entry_id=l.id';
            return $u['role']==='partner'?$k->all($sql.' JOIN partner_members m ON m.branch_id=l.branch_id WHERE m.user_id=? ORDER BY l.created_at DESC LIMIT 1000',[$u['id']]):$k->all($sql.' ORDER BY l.created_at DESC LIMIT 1000');
        }
        if ($method==='POST' && preg_match('#^/ledger/([a-f0-9]{32})/reverse$#',$path,$m)) {
            $u=$k->actor(['admin']);$reason=$this->text($in,'reason');
            return $k->transaction(function()use($k,$u,$reason,$m){
                $candidate=$k->one('SELECT branch_id FROM partner_ledger WHERE id=?',[$m[1]]);
                if (!$candidate) $k->fail(404,'not_found','Entry not found.');
                $k->one('SELECT id FROM partner_branches WHERE id=? FOR UPDATE',[$candidate['branch_id']]);
                $l=$k->one('SELECT * FROM partner_ledger WHERE id=? FOR UPDATE',[$m[1]]);
                if (!$l) $k->fail(404,'not_found','Entry not found.');
                if ($l['reversal_of']||$k->one('SELECT id FROM partner_ledger WHERE reversal_of=?',[$l['id']])) $k->fail(409,'already_reversed','This entry cannot be reversed again.');
                $id=$k->id();$k->execute('INSERT INTO partner_ledger(id,partner_id,branch_id,order_id,task_id,rate_card_id,service_type,amount_minor,event_key,earning_condition,reversal_of,responsible_user_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$l['partner_id'],$l['branch_id'],$l['order_id'],$l['task_id'],$l['rate_card_id'],$l['service_type'],-(int)$l['amount_minor'],'reverse:'.$l['id'],$reason,$l['id'],$u['id'],$k->now()]);
                $k->audit('ledger.reversed','partner_ledger',$id,['reversal_of'=>$l['id'],'reason'=>$reason]);return ['id'=>$id];
            });
        }
        if ($path==='/settlements' && $method==='POST') {
            $u=$k->actor(['admin']);$bid=$this->text($in,'branch_id',32);$this->branch($bid);$evidence=$this->text($in,'evidence',4000);$reference=$this->text($in,'reference',180);$ids=$in['entry_ids']??null;
            if (!is_array($ids)||!array_is_list($ids)||!$ids||count($ids)>500||count(array_unique($ids))!==count($ids)) $k->fail(422,'validation','Select unique unsettled ledger entries.');
            foreach($ids as $id) if (!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) $k->fail(422,'validation','Invalid ledger ID.');
            sort($ids);
            return $k->transaction(function()use($k,$u,$bid,$evidence,$reference,$ids){
                $k->one('SELECT id FROM partner_branches WHERE id=? FOR UPDATE',[$bid]);
                if ($k->one('SELECT id FROM partner_settlements WHERE reference=?',[$reference])) $k->fail(409,'duplicate_reference','Settlement reference already recorded.');
                $sum=0;
                // Include every pending negative adjustment so settled reversals cannot be omitted to overpay a branch.
                $negative=$k->all('SELECT l.id FROM partner_ledger l LEFT JOIN settlement_items s ON s.entry_id=l.id WHERE l.branch_id=? AND l.amount_minor<0 AND s.entry_id IS NULL',[$bid]);
                foreach($negative as $n) if (!in_array($n['id'],$ids,true)) $k->fail(409,'adjustment_required','Include all pending reversal adjustments in this settlement.');
                foreach($ids as $id){
                    $l=$k->one('SELECT * FROM partner_ledger WHERE id=? FOR UPDATE',[$id]);
                    if (!$l||$l['branch_id']!==$bid||$k->one('SELECT entry_id FROM settlement_items WHERE entry_id=?',[$id])) $k->fail(409,'entry_unavailable','An entry is missing, unrelated or already settled.');
                    $sum+=(int)$l['amount_minor'];
                }
                if ($sum<=0) $k->fail(409,'nonpositive_settlement','Settlement must have a positive net payable amount.');
                $id=$k->id();$k->execute('INSERT INTO partner_settlements(id,branch_id,amount_minor,evidence,reference,responsible_user_id,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$bid,$sum,$evidence,$reference,$u['id'],$k->now()]);
                foreach($ids as $entry) $k->execute('INSERT INTO settlement_items(settlement_id,entry_id) VALUES(?,?)',[$id,$entry]);
                $k->audit('settlement.recorded','partner_settlement',$id,['amount_minor'=>$sum]);return ['id'=>$id,'amount_minor'=>$sum,'currency'=>'CAD'];
            });
        }
        if ($path==='/settlements' && $method==='GET') {
            $u=$k->actor(['partner','admin']);
            return $u['role']==='partner'?$k->all('SELECT s.* FROM partner_settlements s JOIN partner_members m ON m.branch_id=s.branch_id WHERE m.user_id=? ORDER BY s.created_at DESC LIMIT 500',[$u['id']]):$k->all('SELECT * FROM partner_settlements ORDER BY created_at DESC LIMIT 500');
        }
        if ($method==='GET' && preg_match('#^/settlements/([a-f0-9]{32})/statement$#',$path,$m)) {
            $u=$k->actor(['partner','admin']);$s=$k->one('SELECT * FROM partner_settlements WHERE id=?',[$m[1]]);
            if (!$s) $k->fail(404,'not_found','Settlement not found.');$b=$this->branch($s['branch_id']);
            return ['settlement'=>$s,'branch'=>$b,'entries'=>$k->all('SELECT l.* FROM partner_ledger l JOIN settlement_items i ON i.entry_id=l.id WHERE i.settlement_id=? ORDER BY l.created_at',[$s['id']])];
        }
        return null;
    }
}
