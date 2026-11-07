<?php
declare(strict_types=1);
namespace Zenith;

/** Prices, immutable approvals, reconciled payments and fulfillment; no seeded tariffs. */
final class Commerce
{
    private const MAX = 1_000_000_000;
    private const OPS = ['admin','operations'];
    private const TERMINAL = ['cancelled','refunded','disputed'];
    public function __construct(private Kernel $k) {}
    public function handle(string $method, string $path, array $in): ?array
    {
        if ($method==='POST' && $path==='/stripe/webhook') return $this->webhook();
        if ($method==='GET' && $path==='/reviews') {
            $this->k->actor(self::OPS);
            return array_map(fn($r)=>$this->decode($r,['specification']), $this->k->all("SELECT d.*,u.name customer_name,m.verified_at measurement_verified_at FROM designs d JOIN users u ON u.id=d.user_id LEFT JOIN measurements m ON m.id=d.measurement_id WHERE d.status IN ('submitted','revision_requested','approved','quoted') ORDER BY d.submitted_at DESC LIMIT 500"));
        }
        if ($method==='POST' && preg_match('~^/designs/([a-f0-9]{32})/review$~D',$path,$m)) return $this->review($m[1],$in);
        if ($path==='/quotes' && $method==='POST') return $this->quote($in);
        if ($path==='/quotes' && $method==='GET') return $this->quotes();
        if ($method==='POST' && preg_match('~^/quotes/([a-f0-9]{32})/approve$~D',$path,$m)) return $this->approve($m[1],$in);
        if ($path==='/orders' && $method==='GET') return $this->orders();
        if ($path==='/reports' && $method==='GET') return $this->reports();
        if (preg_match('~^/orders/([a-f0-9]{32})(?:/([a-z-]+))?$~D',$path,$m)) {
            $id=$m[1]; $action=$m[2]??'';
            if ($method==='GET' && $action==='') return $this->detail($id);
            if ($method==='GET' && $action==='work-order') return $this->workOrder($id);
            if ($method==='POST') return match($action) {
                'manual-payment'=>$this->manualPayment($id,$in), 'checkout'=>$this->checkout($id,$in),
                'transition'=>$this->transition($id,$in), 'production'=>$this->production($id,$in),
                'qc'=>$this->qc($id,$in), 'shipments'=>$this->shipment($id,$in),
                'expenses'=>$this->expense($id,$in), 'refunds'=>$this->refund($id,$in), default=>null,
            };
        }
        return null;
    }
    private function review(string $id,array $in): array
    {
        $u=$this->k->actor(self::OPS); $decision=$this->enum($in,'decision',['approved','revision_requested']); $notes=$this->text($in,'notes',5,4000);
        return $this->k->transaction(function()use($id,$decision,$notes,$u){
            $d=$this->k->one('SELECT * FROM designs WHERE id=? FOR UPDATE',[$id]); if(!$d)$this->missing();
            if(!in_array($d['status'],['submitted','revision_requested'],true))$this->conflict('Only submitted designs may be reviewed.');
            if($decision==='approved') {
                $m=$this->k->one('SELECT * FROM measurements WHERE id=? AND user_id=? FOR UPDATE',[$d['measurement_id'],$d['user_id']]);
                if(!$m||!$m['verified_at'])$this->conflict('Staff-verified measurements are required before approval.');
            }
            $this->k->execute('UPDATE designs SET status=?,review_notes=?,reviewed_by=?,updated_at=? WHERE id=?',[$decision,$notes,$u['id'],$this->k->now(),$id]);
            $this->k->audit('design.review','design',$id,['decision'=>$decision]);
            $this->k->notify($d['user_id'],'design_reviewed',['design_id'=>$id,'decision'=>$decision,'notes'=>$notes]);
            return ['id'=>$id,'status'=>$decision];
        });
    }
    /** Financial inputs are integers, bounded before multiplication; half-up tax rounding. */
    public static function calculate(array $lines,int $taxBps): array
    {
        if(!$lines||count($lines)>100||$taxBps<0||$taxBps>10000)throw new \InvalidArgumentException('Invalid quote lines or tax basis points.');
        $subtotal=0;$cost=0;
        foreach($lines as $line) {
            if(!is_array($line)||!is_string($line['label']??null)||trim($line['label'])===''||strlen($line['label'])>200)throw new \InvalidArgumentException('Each line requires a short label.');
            foreach(['quantity','unit_minor','cost_minor']as$key)if(!isset($line[$key])||!is_int($line[$key]))throw new \InvalidArgumentException('Quote amounts and quantities must be integers.');
            if($line['quantity']<1||$line['quantity']>1000||$line['unit_minor']<0||$line['unit_minor']>self::MAX||$line['cost_minor']<0||$line['cost_minor']>self::MAX)throw new \InvalidArgumentException('Quote line outside permitted bounds.');
            $subtotal+=$line['quantity']*$line['unit_minor']; $cost+=$line['cost_minor'];
            if($subtotal>self::MAX||$cost>self::MAX)throw new \InvalidArgumentException('Quote exceeds supported bounds.');
        }
        if($subtotal<1)throw new \InvalidArgumentException('A binding quote requires a positive subtotal.');
        $tax=intdiv($subtotal*$taxBps+5000,10000);
        if($subtotal+$tax>self::MAX)throw new \InvalidArgumentException('Quote total exceeds supported bounds.');
        return ['subtotal_minor'=>$subtotal,'tax_minor'=>$tax,'total_minor'=>$subtotal+$tax,'estimated_cost_minor'=>$cost];
    }
    private function quote(array $in): array
    {
        $u=$this->k->actor(self::OPS);$margin=$this->k->config['pricing_min_margin_bps']??null;
        if(!is_int($margin)||$margin<0||$margin>10000||empty($this->k->config['pricing_approval_reference']))$this->k->fail(503,'pricing_not_approved','Business approval of pricing and margin rules is required before quoting.');
        $issuer=$this->k->config['merchant']??[];
        if(empty($issuer['legal_name'])||empty($issuer['address'])||empty($issuer['invoice_approval_reference']))$this->k->fail(503,'merchant_not_approved','Approved merchant identity and invoice configuration are required.');
        $designId=$this->identifier($in,'design_id');$lines=$in['lines']??null;if(!is_array($lines))$this->invalid('lines must be an array.');
        $tax=$this->integer($in,'tax_bps',0,10000);
        if($tax>0&&empty($issuer['tax_registration']))$this->k->fail(503,'tax_not_approved','Tax registration and invoice approval are required before collecting tax.');
        try{$totals=self::calculate($lines,$tax);}catch(\InvalidArgumentException $e){$this->invalid($e->getMessage());}
        if(($totals['subtotal_minor']-$totals['estimated_cost_minor'])*10000<$totals['subtotal_minor']*$margin)$this->invalid('Quote does not meet the approved contribution-margin rule.');
        $deposit=$this->integer($in,'deposit_minor',0,$totals['total_minor']);$expiry=$this->timestamp($in,'expires_at');
        if(strtotime($expiry.' UTC')<=time()||strtotime($expiry.' UTC')>time()+366*86400)$this->invalid('Expiry must be in the future, within one year.');
        $delivery=$this->text($in,'delivery_terms',5,4000);$terms=$this->text($in,'terms',10,12000);$approval=$this->text($in,'approval_reference',3,500);
        return $this->k->transaction(function()use($u,$designId,$lines,$tax,$totals,$deposit,$expiry,$delivery,$terms,$approval,$issuer){
            $d=$this->k->one('SELECT * FROM designs WHERE id=? FOR UPDATE',[$designId]);if(!$d)$this->missing();
            if(!in_array($d['status'],['approved','quoted'],true))$this->conflict('Design feasibility must be approved before quoting.');
            if($this->k->one('SELECT id FROM orders WHERE design_id=?',[$designId]))$this->conflict('An approved order already exists for this design.');
            $m=$this->k->one('SELECT * FROM measurements WHERE id=? AND user_id=? FOR UPDATE',[$d['measurement_id'],$d['user_id']]);
            if(!$m||!$m['verified_at'])$this->conflict('Verified measurements are required.');
            $latest=$this->k->one('SELECT MAX(version) version FROM quotes WHERE design_id=?',[$designId]);$version=(int)($latest['version']??0)+1;
            $spec=['issuer'=>array_intersect_key($issuer,array_flip(['legal_name','address','tax_registration'])),'design'=>json_decode($d['specification'],true,512,JSON_THROW_ON_ERROR),'design_revision'=>(int)$d['revision'],'measurement'=>$this->decode($m,['values_json']),'review_notes'=>$d['review_notes']];
            unset($spec['measurement']['verification_evidence']);$id=$this->k->id();
            $this->k->execute('INSERT INTO quotes (id,design_id,user_id,version,subtotal_minor,tax_minor,total_minor,deposit_minor,estimated_cost_minor,tax_bps,`lines`,specification,delivery_terms,terms,approval_reference,expires_at,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$designId,$d['user_id'],$version,$totals['subtotal_minor'],$totals['tax_minor'],$totals['total_minor'],$deposit,$totals['estimated_cost_minor'],$tax,json_encode($lines,JSON_THROW_ON_ERROR),json_encode($spec,JSON_THROW_ON_ERROR),$delivery,$terms,$approval,$expiry,$u['id'],$this->k->now()]);
            $this->k->execute("UPDATE designs SET status='quoted',updated_at=? WHERE id=?",[$this->k->now(),$designId]);
            $this->k->audit('quote.issue','quote',$id,['version'=>$version,'pricing_approval_reference'=>$this->k->config['pricing_approval_reference']]);
            $this->k->notify($d['user_id'],'quote_ready',['quote_id'=>$id,'total_minor'=>$totals['total_minor']]);
            return $this->quoteView($this->k->one('SELECT * FROM quotes WHERE id=?',[$id]),true);
        });
    }
    private function quotes():array
    {
        $u=$this->k->actor(['customer',...self::OPS]);$ops=in_array($u['role'],self::OPS,true);
        return array_map(fn($q)=>$this->quoteView($q,$ops),$this->k->all('SELECT q.*,(SELECT MAX(q2.version) FROM quotes q2 WHERE q2.design_id=q.design_id) latest_version FROM quotes q'.($ops?'':' WHERE q.user_id=?').' ORDER BY q.created_at DESC LIMIT 500',$ops?[]:[$u['id']]));
    }
    private function quoteView(array $q,bool $ops):array
    {
        $q=$this->decode($q,['lines','specification']);
        $q['status']=$q['accepted_at']?'accepted':(((int)($q['latest_version']??$q['version'])>(int)$q['version'])?'superseded':(strtotime($q['expires_at'].' UTC')<=time()?'expired':'pending'));
        foreach(['version','subtotal_minor','tax_minor','total_minor','deposit_minor','tax_bps','estimated_cost_minor']as$f)$q[$f]=(int)$q[$f];
        if(!$ops){unset($q['estimated_cost_minor'],$q['approval_reference'],$q['created_by']);$q['lines']=array_map(function($l){unset($l['cost_minor']);return $l;},$q['lines']);}
        unset($q['latest_version']);return $q;
    }
    private function approve(string $id,array $in):array
    {
        $u=$this->k->actor(['customer']);if(($in['accept_terms']??null)!==true)$this->invalid('Explicit acceptance of quote terms is required.');
        return $this->k->transaction(function()use($id,$u){
            $candidate=$this->k->one('SELECT design_id FROM quotes WHERE id=? AND user_id=?',[$id,$u['id']]);if(!$candidate)$this->missing();
            $d=$this->k->one('SELECT * FROM designs WHERE id=? FOR UPDATE',[$candidate['design_id']]);
            $q=$this->k->one('SELECT * FROM quotes WHERE id=? AND user_id=? FOR UPDATE',[$id,$u['id']]);
            $existing=$this->k->one('SELECT * FROM orders WHERE quote_id=? FOR UPDATE',[$id]);if($existing)return $this->orderView($existing);
            $latest=$this->k->one('SELECT version FROM quotes WHERE design_id=? ORDER BY version DESC LIMIT 1 FOR UPDATE',[$q['design_id']]);
            if((int)$latest['version']!==(int)$q['version'])$this->conflict('This quote was superseded; review the latest version.');
            if(strtotime($q['expires_at'].' UTC')<=time())$this->conflict('This quote expired; request a revision.');
            if($d['status']!=='quoted')$this->conflict('This design cannot currently be ordered.');
            if($this->k->one('SELECT id FROM orders WHERE design_id=?',[$q['design_id']]))$this->conflict('An order already exists for this design.');
            $orderId=$this->k->id();$now=$this->k->now();$snapshot=$this->quoteView($q,false);$snapshot['accepted_terms_at']=$now;$snapshot['accepted_at']=$now;$snapshot['status']='accepted';$json=json_encode($snapshot,JSON_THROW_ON_ERROR);
            $this->k->execute('INSERT INTO orders (id,user_id,quote_id,design_id,subtotal_minor,tax_minor,total_minor,deposit_minor,snapshot,accepted_terms_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$orderId,$u['id'],$id,$q['design_id'],$q['subtotal_minor'],$q['tax_minor'],$q['total_minor'],$q['deposit_minor'],$json,$now,$now,$now]);
            $invoiceId=$this->k->id();$this->k->execute('INSERT INTO invoices (id,order_id,invoice_number,subtotal_minor,tax_minor,total_minor,snapshot,issued_at) VALUES (?,?,?,?,?,?,?,?)',[$invoiceId,$orderId,'AZ-'.strtoupper($invoiceId),$q['subtotal_minor'],$q['tax_minor'],$q['total_minor'],$json,$now]);
            $this->k->execute('UPDATE quotes SET accepted_at=? WHERE id=?',[$now,$id]);$this->k->execute("UPDATE designs SET status='ordered',updated_at=? WHERE id=?",[$now,$q['design_id']]);
            $this->event($orderId,'awaiting_payment','Customer approved quote and terms.','Quote '.$id,$u['id']);$this->k->audit('quote.accept','order',$orderId,['quote_id'=>$id]);$this->k->notify($u['id'],'quote_accepted',['order_id'=>$orderId]);
            return $this->orderView($this->k->one('SELECT * FROM orders WHERE id=?',[$orderId]));
        });
    }
    private function orders():array
    {
        $u=$this->k->actor(['customer',...self::OPS]);$ops=in_array($u['role'],self::OPS,true);
        return array_map(fn($o)=>$this->orderView($o),$this->k->all('SELECT * FROM orders'.($ops?'':' WHERE user_id=?').' ORDER BY created_at DESC LIMIT 500',$ops?[]:[$u['id']]));
    }
    private function order(string $id,bool $lock=false):array
    {
        $u=$this->k->actor(['customer',...self::OPS]);$o=$this->k->one('SELECT * FROM orders WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if(!$o||($u['role']==='customer'&&$o['user_id']!==$u['id']))$this->missing();return $o;
    }
    private function financials(string $id):array
    {
        $p=$this->k->one('SELECT COALESCE(SUM(amount_minor),0) amount FROM payments WHERE order_id=?',[$id]);$r=$this->k->one('SELECT COALESCE(SUM(amount_minor),0) amount FROM refunds WHERE order_id=?',[$id]);
        return ['paid_minor'=>(int)$p['amount'],'refunded_minor'=>(int)$r['amount'],'net_paid_minor'=>(int)$p['amount']-(int)$r['amount']];
    }
    private function orderView(array $o):array
    {
        $o=$this->decode($o,['snapshot']);foreach(['subtotal_minor','tax_minor','total_minor','deposit_minor']as$f)$o[$f]=(int)$o[$f];$o+=$this->financials($o['id']);$o['balance_minor']=max(0,$o['total_minor']-$o['net_paid_minor']);return $o;
    }
    private function detail(string $id):array
    {
        $o=$this->order($id);$ops=in_array($this->k->user['role'],self::OPS,true);
        $history=$this->k->all('SELECT * FROM order_events WHERE order_id=? ORDER BY created_at,id',[$id]);$payments=$this->k->all('SELECT * FROM payments WHERE order_id=? ORDER BY created_at',[$id]);$refunds=$this->k->all('SELECT * FROM refunds WHERE order_id=? ORDER BY created_at',[$id]);
        if(!$ops){$history=array_map(fn($r)=>array_intersect_key($r,array_flip(['id','status','created_at'])),$history);$payments=array_map(fn($r)=>array_intersect_key($r,array_flip(['id','provider','kind','amount_minor','currency','created_at'])),$payments);$refunds=array_map(fn($r)=>array_intersect_key($r,array_flip(['id','amount_minor','created_at'])),$refunds);}
        $result=['order'=>$this->orderView($o),'history'=>$history,'invoice'=>$this->decode($this->k->one('SELECT * FROM invoices WHERE order_id=?',[$id]),['snapshot']),'payments'=>$payments,'refunds'=>$refunds];
        $result['production']=$ops?$this->k->one('SELECT * FROM production_jobs WHERE order_id=?',[$id]):null;$result['qc']=$ops?$this->k->all('SELECT * FROM quality_inspections WHERE order_id=? ORDER BY created_at DESC,id DESC',[$id]):[];
        $shipments=$this->k->all('SELECT * FROM shipments WHERE order_id=? ORDER BY created_at DESC',[$id]);$result['shipments']=$ops?$shipments:array_map(fn($r)=>array_intersect_key($r,array_flip(['id','carrier','tracking','stage','created_at'])),$shipments);
        if($ops)$result['expenses']=$this->k->all('SELECT * FROM order_expenses WHERE order_id=? ORDER BY created_at',[$id]);return $result;
    }
    public static function due(array $o,int $netPaid,string $kind):int
    {
        if(!in_array($kind,['deposit','balance'],true)||$netPaid<0||$netPaid>(int)$o['total_minor'])throw new \InvalidArgumentException('Invalid payment balance.');
        $due=($kind==='deposit'?(int)$o['deposit_minor']:(int)$o['total_minor'])-$netPaid;if($due<1)throw new \InvalidArgumentException('No payment of this kind is outstanding.');return $due;
    }
    private function payable(array $o):void{if(in_array($o['status'],self::TERMINAL,true)||$o['status']==='completed')$this->conflict('Payments are closed for this order.');}
    private function manualEnabled():void{if(($this->k->config['manual_payments_enabled']??false)!==true||empty($this->k->config['manual_payment_approval_reference']))$this->k->fail(503,'manual_payment_not_approved','Manual payment verification is not approved for this business.');}
    private function manualPayment(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$this->manualEnabled();$amount=$this->integer($in,'amount_minor',1,self::MAX);$kind=$this->enum($in,'kind',['deposit','balance']);$evidence=$this->text($in,'evidence',5,4000);$reference='manual:'.$this->text($in,'reference',3,200);
        return $this->k->transaction(function()use($id,$amount,$kind,$evidence,$reference,$u){
            $o=$this->order($id,true);$existing=$this->k->one('SELECT * FROM payments WHERE provider_reference=?',[$reference]);
            if($existing){if($existing['order_id']!==$id||(int)$existing['amount_minor']!==$amount||$existing['kind']!==$kind)$this->conflict('Payment reference already used for another transaction.');return ['payment'=>$existing,'order'=>$this->orderView($o),'duplicate'=>true];}
            $this->payable($o);$this->expireCheckouts($id);try{$due=self::due($o,$this->financials($id)['net_paid_minor'],$kind);}catch(\InvalidArgumentException $e){$this->conflict($e->getMessage());}
            if($amount!==$due)$this->invalid('Verified amount must equal the outstanding deposit or balance.');
            $paymentId=$this->recordPayment($o,'manual',$reference,null,$kind,$amount,$evidence,$u['id']);return ['payment_id'=>$paymentId,'order'=>$this->orderView($this->k->one('SELECT * FROM orders WHERE id=?',[$id]))];
        });
    }
    private function recordPayment(array $o,string $provider,string $reference,?string $intent,string $kind,int $amount,string $evidence,?string $operator):string
    {
        $id=$this->k->id();$this->k->execute('INSERT INTO payments (id,order_id,provider,provider_reference,provider_intent,kind,amount_minor,evidence,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$o['id'],$provider,$reference,$intent,$kind,$amount,$evidence,$operator,$this->k->now()]);
        $f=$this->financials($o['id']);$status=$f['net_paid_minor']>=(int)$o['total_minor']?'paid':'deposit_received';
        if(in_array($o['status'],['awaiting_payment','deposit_received','paid'],true))$this->setStatus($o,$status,'Payment verified.',$reference,$operator);
        $this->k->audit('payment.verify','payment',$id,['order_id'=>$o['id'],'provider'=>$provider,'amount_minor'=>$amount]);$this->k->notify($o['user_id'],'payment_confirmed',['order_id'=>$o['id'],'amount_minor'=>$amount]);return $id;
    }
    private function checkout(string $id,array $in):array
    {
        $this->k->actor(['customer']);$this->stripeEnabled();$kind=$this->enum($in,'kind',['deposit','balance']);
        return $this->k->transaction(function()use($id,$kind){
            $o=$this->order($id,true);$this->payable($o);$paid=$this->financials($id)['net_paid_minor'];
            try{$amount=self::due($o,$paid,$kind);}catch(\InvalidArgumentException $e){$this->conflict($e->getMessage());}
            $attempt=$this->k->one('SELECT * FROM checkout_attempts WHERE order_id=? AND kind=? AND paid_before_minor=? AND amount_minor=? ORDER BY created_at DESC LIMIT 1',[$id,$kind,$paid,$amount]);
            if($attempt&&$attempt['checkout_url']&&strtotime($attempt['expires_at'].' UTC')>time()+60)return ['url'=>$attempt['checkout_url']];
            $this->expireCheckouts($id);
            if(!$attempt||($attempt['expires_at']&&strtotime($attempt['expires_at'].' UTC')<=time()+60)){
                $aid=$this->k->id();$this->k->execute('INSERT INTO checkout_attempts (id,order_id,kind,amount_minor,paid_before_minor,created_at) VALUES (?,?,?,?,?,?)',[$aid,$id,$kind,$amount,$paid,$this->k->now()]);$attempt=['id'=>$aid];
            }
            $origin=rtrim($this->k->config['public_origin']??$this->k->config['base_url']??'','/');if(!str_starts_with($origin,'https://'))$this->k->fail(503,'payment_configuration','Secure checkout return URLs are not configured.');
            $s=$this->stripe('POST','/v1/checkout/sessions',[
                'mode'=>'payment','payment_method_types'=>['card'],'client_reference_id'=>$id,'metadata'=>['order_id'=>$id,'attempt_id'=>$attempt['id'],'kind'=>$kind],
                'line_items'=>[['price_data'=>['currency'=>'cad','unit_amount'=>$amount,'product_data'=>['name'=>'A Zenith Fashion approved '.$kind]],'quantity'=>1]],
                'success_url'=>$origin.'/account/?checkout=returned','cancel_url'=>$origin.'/account/?checkout=cancelled','expires_at'=>time()+1800,
            ],'checkout-'.$attempt['id']);
            if(!is_string($s['id']??null)||!str_starts_with($s['id'],'cs_')||parse_url($s['url']??'',PHP_URL_HOST)!=='checkout.stripe.com')$this->k->fail(502,'payment_provider','Invalid provider checkout session.');
            $this->k->execute('UPDATE checkout_attempts SET stripe_session_id=?,checkout_url=?,expires_at=? WHERE id=?',[$s['id'],$s['url'],gmdate('Y-m-d H:i:s',(int)$s['expires_at']),$attempt['id']]);$this->k->audit('payment.checkout','order',$id,['attempt_id'=>$attempt['id']]);return ['url'=>$s['url']];
        });
    }
    /** Expire superseded checkout links before another collection method can be used. */
    private function expireCheckouts(string $id):void
    {
        $attempts=$this->k->all("SELECT a.* FROM checkout_attempts a WHERE a.order_id=? AND a.stripe_session_id IS NOT NULL AND a.checkout_url IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.provider_reference=CONCAT('stripe:',a.stripe_session_id))",[$id]);
        foreach($attempts as$a){
            $this->stripeEnabled();$s=$this->stripe('GET','/v1/checkout/sessions/'.rawurlencode($a['stripe_session_id']));
            if(($s['payment_status']??'')==='paid'){
                if(!$this->k->one('SELECT id FROM payments WHERE provider_reference=?',['stripe:'.$s['id']]))$this->conflict('A completed provider payment is awaiting webhook reconciliation. Retry after confirmation.');
            }elseif(($s['status']??'')==='complete'){
                $this->conflict('An existing provider payment is still processing. Reconcile it before collecting by another method.');
            }elseif(($s['status']??'')==='open'){
                $expired=$this->stripe('POST','/v1/checkout/sessions/'.rawurlencode($a['stripe_session_id']).'/expire',[],'expire-'.$a['id']);
                if(($expired['status']??'')!=='expired')$this->conflict('An existing checkout could not be expired safely.');
            }
            if(!in_array($s['status']??'', ['open','expired','complete'],true))$this->conflict('The existing payment request has an unknown provider state.');
            $this->k->execute('UPDATE checkout_attempts SET expires_at=?,checkout_url=NULL WHERE id=?',[$this->k->now(),$a['id']]);
        }
    }
    public static function validStripeSignature(string $raw,string $header,string $secret,?int $now=null):bool
    {
        $ts=null;$signatures=[];
        foreach(explode(',',$header)as$part){[$name,$value]=array_pad(explode('=',trim($part),2),2,'');if($name==='t'&&ctype_digit($value))$ts=(int)$value;if($name==='v1')$signatures[]=$value;}
        if(!$secret||$ts===null||abs(($now??time())-$ts)>300)return false;$expected=hash_hmac('sha256',$ts.'.'.$raw,$secret);
        foreach($signatures as$signature)if(hash_equals($expected,$signature))return true;return false;
    }
    private function webhook():array
    {
        $this->stripeEnabled();$raw=file_get_contents('php://input');
        if(!is_string($raw)||!self::validStripeSignature($raw,$_SERVER['HTTP_STRIPE_SIGNATURE']??'',$this->k->config['stripe_webhook_secret']??''))$this->k->fail(400,'webhook_signature','Invalid webhook signature.');
        try{$event=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->invalid('Invalid webhook JSON.');}
        if(!is_string($event['id']??null)||!str_starts_with($event['id'],'evt_')||strlen($event['id'])>255||!is_string($event['type']??null))$this->invalid('Invalid webhook event.');
        if(!in_array($event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded'],true))return ['received'=>true,'ignored'=>true];
        $sid=$event['data']['object']['id']??'';if(!is_string($sid)||!preg_match('/^cs_[A-Za-z0-9_]+$/D',$sid)||strlen($sid)>255)$this->invalid('Invalid checkout session.');
        // Signed event + canonical provider retrieval, never a browser redirect.
        $s=$this->stripe('GET','/v1/checkout/sessions/'.rawurlencode($sid));
        if(($s['payment_status']??'')!=='paid')return ['received'=>true,'pending'=>true];
        if(($s['mode']??'')!=='payment'||($s['currency']??'')!=='cad'||!is_int($s['amount_total']??null)||!is_string($s['payment_intent']??null))$this->invalid('Unsupported provider payment state.');
        return $this->k->transaction(function()use($event,$s,$raw){
            $a=$this->k->one('SELECT * FROM checkout_attempts WHERE stripe_session_id=?',[$s['id']]);if(!$a)$this->conflict('Unknown checkout session.');
            $o=$this->k->one('SELECT * FROM orders WHERE id=? FOR UPDATE',[$a['order_id']]);$previous=$this->k->one('SELECT * FROM payment_events WHERE provider_event_id=?',[$event['id']]);
            if($previous){if(!hash_equals($previous['payload_sha256'],hash('sha256',$raw)))$this->conflict('Webhook event payload changed.');return ['received'=>true,'duplicate'=>true];}
            if(($s['client_reference_id']??'')!==$o['id']||($s['metadata']['order_id']??'')!==$o['id']||($s['metadata']['attempt_id']??'')!==$a['id']||($s['metadata']['kind']??'')!==$a['kind']||(int)$a['amount_minor']!==$s['amount_total'])$this->conflict('Checkout does not match the approved payment request.');
            $existing=$this->k->one('SELECT * FROM payments WHERE provider_reference=? OR provider_intent=?',['stripe:'.$s['id'],$s['payment_intent']]);
            if($existing&&($existing['order_id']!==$o['id']||(int)$existing['amount_minor']!==$s['amount_total']))$this->conflict('Provider transaction is already associated with another payment.');
            if(!$existing){
                // Record money that actually arrived, even if cancellation/another payment creates an exception.
                $this->recordPayment($o,'stripe','stripe:'.$s['id'],$s['payment_intent'],$a['kind'],$s['amount_total'],'Verified Stripe event '.$event['id'],null);
                if(in_array($o['status'],self::TERMINAL,true)||$this->financials($o['id'])['net_paid_minor']>(int)$o['total_minor']){
                    $this->setStatus($o,'disputed','Payment reconciliation exception requires review.',$event['id'],null);$this->k->audit('payment.reconciliation_required','order',$o['id'],['event_id'=>$event['id']]);
                }
            }
            $this->k->execute('INSERT INTO payment_events (provider_event_id,event_type,payload_sha256,processed_at) VALUES (?,?,?,?)',[$event['id'],$event['type'],hash('sha256',$raw),$this->k->now()]);return ['received'=>true];
        });
    }
    private function stripeEnabled():void{if(empty($this->k->config['stripe_secret_key'])||empty($this->k->config['stripe_webhook_secret']))$this->k->fail(503,'stripe_not_configured','Secure payment processing is not configured.');}
    private function stripe(string $method,string $path,array $fields=[],?string $key=null):array
    {
        $ch=curl_init('https://api.stripe.com'.$path);$headers=['Authorization: Bearer '.$this->k->config['stripe_secret_key'],'Stripe-Version: 2024-06-20'];if($key)$headers[]='Idempotency-Key: '.$key;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if(!is_string($body)||$status<200||$status>=300)$this->k->fail(502,'payment_provider_unavailable','Payment provider could not complete the request; retry safely or contact support.');
        try{$result=json_decode($body,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){$this->k->fail(502,'payment_provider','Invalid provider response.');}
        if(!is_array($result))$this->k->fail(502,'payment_provider','Invalid provider response.');return $result;
    }
    public static function allowedTransition(string $from,string $to):bool{static $contract=null;$contract??=json_decode((string)file_get_contents(__DIR__.'/../../shared/contract.json'),true,512,JSON_THROW_ON_ERROR);return in_array($to,$contract['orderTransitions'][$from]??[],true);}
    private function transition(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$status=$this->enum($in,'status',array_keys($this->k->contract['orderTransitions']));$note=$this->text($in,'note',3,4000);$evidence=$this->text($in,'evidence',3,4000);
        return $this->k->transaction(function()use($id,$status,$note,$evidence,$u){
            $o=$this->order($id,true);if(!self::allowedTransition($o['status'],$status))$this->conflict('Invalid order transition; payment states require verified payment events.');$f=$this->financials($id);
            if($status==='production_preparation'&&$f['net_paid_minor']<max(1,(int)$o['deposit_minor']))$this->conflict('Verify the approved deposit before production preparation.');
            if($status==='in_production'){$job=$this->k->one('SELECT * FROM production_jobs WHERE order_id=?',[$id]);if(!$job||strlen(trim($job['acknowledgement']))<3)$this->conflict('Supplier acknowledgement and a production work order are required.');}
            if($status==='shipping_preparation'){$qc=$this->k->one('SELECT result FROM quality_inspections WHERE order_id=? ORDER BY sequence_no DESC LIMIT 1',[$id]);if(!$qc||$qc['result']!=='pass')$this->conflict('The latest quality inspection must pass before shipping.');}
            if($status==='in_transit'&&!$this->k->one('SELECT id FROM shipments WHERE order_id=?',[$id]))$this->conflict('Carrier and tracking evidence are required.');
            if(in_array($status,['ready_for_delivery','delivered','completed'],true)&&$f['net_paid_minor']<(int)$o['total_minor'])$this->conflict('Verify the full balance before final delivery.');
            if($status==='cancelled'){$this->expireCheckouts($id);if($f['net_paid_minor']>0)$this->conflict('Refund/reconcile verified payments before cancellation.');}
            $this->setStatus($o,$status,$note,$evidence,$u['id']);$this->k->audit('order.transition','order',$id,['from'=>$o['status'],'to'=>$status]);$this->k->notify($o['user_id'],'order_status',['order_id'=>$id,'status'=>$status]);return $this->orderView($this->k->one('SELECT * FROM orders WHERE id=?',[$id]));
        });
    }
    private function production(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$supplier=$this->text($in,'supplier_reference',3,500);$spec=$this->text($in,'specification',10,20000);$ack=$this->text($in,'acknowledgement',3,4000);$due=$this->timestamp($in,'due_at');
        return $this->k->transaction(function()use($id,$supplier,$spec,$ack,$due,$u){
            $o=$this->order($id,true);if($o['status']!=='production_preparation')$this->conflict('Create the work order during production preparation.');
            if($this->k->one('SELECT id FROM production_jobs WHERE order_id=?',[$id]))$this->conflict('A production work order already exists; preserve its approved handoff.');$jid=$this->k->id();
            $this->k->execute('INSERT INTO production_jobs (id,order_id,supplier_reference,specification,acknowledgement,due_at,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)',[$jid,$id,$supplier,$spec,$ack,$due,$u['id'],$this->k->now()]);$this->k->audit('production.create','order',$id,['job_id'=>$jid]);return ['id'=>$jid,'order_id'=>$id];
        });
    }
    private function workOrder(string $id):array
    {
        $this->k->actor(self::OPS);$o=$this->order($id);$job=$this->k->one('SELECT * FROM production_jobs WHERE order_id=?',[$id]);if(!$job)$this->missing();$s=json_decode($o['snapshot'],true,512,JSON_THROW_ON_ERROR);
        return ['order_id'=>$id,'production'=>$job,'approved_specification'=>$s['specification'],'delivery_terms'=>$s['delivery_terms'],'generated_at'=>$this->k->now()];
    }
    private function qc(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$result=$this->enum($in,'result',['pass','fail']);$report=$this->text($in,'report',5,12000);$evidence=$this->text($in,'evidence',3,4000);
        return $this->k->transaction(function()use($id,$result,$report,$evidence,$u){
            $o=$this->order($id,true);if(!in_array($o['status'],['in_production','quality_inspection'],true))$this->conflict('Quality inspections require active production or inspection.');$qid=$this->k->id();
            $this->k->execute('INSERT INTO quality_inspections (id,order_id,result,report,evidence,created_by,created_at) VALUES (?,?,?,?,?,?,?)',[$qid,$id,$result,$report,$evidence,$u['id'],$this->k->now()]);$this->k->audit('production.qc','order',$id,['inspection_id'=>$qid,'result'=>$result]);return ['id'=>$qid,'result'=>$result];
        });
    }
    private function shipment(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$carrier=$this->text($in,'carrier',2,200);$tracking=$this->text($in,'tracking',2,200);$stage=$this->enum($in,'stage',['booked','dispatched','in_transit','arrived_canada','exception','delivered']);$note=$this->text($in,'note',3,4000);
        return $this->k->transaction(function()use($id,$carrier,$tracking,$stage,$note,$u){
            $o=$this->order($id,true);if(!in_array($o['status'],['shipping_preparation','in_transit','arrived_canada','local_fitting','ready_for_delivery','delivered'],true))$this->conflict('Shipment records require shipping/delivery stage.');$sid=$this->k->id();
            $this->k->execute('INSERT INTO shipments (id,order_id,carrier,tracking,stage,note,created_by,created_at) VALUES (?,?,?,?,?,?,?,?)',[$sid,$id,$carrier,$tracking,$stage,$note,$u['id'],$this->k->now()]);$this->k->audit('shipment.record','order',$id,['shipment_id'=>$sid,'stage'=>$stage]);return ['id'=>$sid];
        });
    }
    private function expense(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$category=$this->enum($in,'category',['manufacturing','fabric','customization','import','freight','payment_processing','acquisition','alteration','rework','other']);$amount=$this->integer($in,'amount_minor',1,self::MAX);$evidence=$this->text($in,'evidence',3,4000);
        return $this->k->transaction(function()use($id,$category,$amount,$evidence,$u){$this->order($id,true);$existing=$this->k->one('SELECT id FROM order_expenses WHERE order_id=? AND category=? AND amount_minor=? AND evidence=?',[$id,$category,$amount,$evidence]);if($existing)return ['id'=>$existing['id'],'duplicate'=>true];$eid=$this->k->id();$this->k->execute('INSERT INTO order_expenses (id,order_id,category,amount_minor,evidence,created_by,created_at) VALUES (?,?,?,?,?,?,?)',[$eid,$id,$category,$amount,$evidence,$u['id'],$this->k->now()]);$this->k->audit('expense.record','order',$id,['expense_id'=>$eid,'amount_minor'=>$amount]);return ['id'=>$eid];});
    }
    private function refund(string $id,array $in):array
    {
        $u=$this->k->actor(self::OPS);$amount=$this->integer($in,'amount_minor',1,self::MAX);$reason=$this->text($in,'reason',5,4000);$evidence=$this->text($in,'evidence',5,4000);$reference=$this->text($in,'reference',3,150);$pid=isset($in['payment_id'])?$this->identifier($in,'payment_id'):null;
        return $this->k->transaction(function()use($id,$amount,$reason,$evidence,$reference,$pid,$u){
            $o=$this->order($id,true);$existing=$this->k->one('SELECT * FROM refunds WHERE provider_reference=?',['refund:'.$reference]);
            if($existing){if($existing['order_id']!==$id||(int)$existing['amount_minor']!==$amount)$this->conflict('Refund reference already used.');return ['id'=>$existing['id'],'duplicate'=>true];}
            if($amount>$this->financials($id)['net_paid_minor'])$this->invalid('Refund exceeds verified funds received.');
            $this->expireCheckouts($id);
            $payments=$this->k->all('SELECT p.*,COALESCE((SELECT SUM(r.amount_minor) FROM refunds r WHERE r.payment_id=p.id),0) refunded FROM payments p WHERE p.order_id=?'.($pid?' AND p.id=?':'').' ORDER BY p.created_at DESC',$pid?[$id,$pid]:[$id]);$payment=null;
            foreach($payments as$p)if((int)$p['amount_minor']-(int)$p['refunded']>=$amount){$payment=$p;break;}
            if(!$payment)$this->invalid('Select a payment with enough refundable funds; split refunds across payments when required.');
            if($payment['provider']==='stripe'){
                $this->stripeEnabled();$result=$this->stripe('POST','/v1/refunds',['payment_intent'=>$payment['provider_intent'],'amount'=>$amount,'metadata'=>['order_id'=>$id,'reference'=>$reference]],'refund-'.hash('sha256',$reference));
                if(($result['status']??'')!=='succeeded'||(int)($result['amount']??0)!==$amount||($result['payment_intent']??null)!==$payment['provider_intent'])$this->k->fail(409,'refund_pending','Provider has not confirmed refund completion. Reconcile using the same reference.');
                $evidence.='; verified Stripe refund '.$result['id'];
            }else{$this->manualEnabled();}
            $rid=$this->k->id();$this->k->execute('INSERT INTO refunds (id,order_id,payment_id,provider_reference,amount_minor,reason,evidence,recorded_by,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$rid,$id,$payment['id'],'refund:'.$reference,$amount,$reason,$evidence,$u['id'],$this->k->now()]);
            if($this->financials($id)['net_paid_minor']===0)$this->setStatus($o,'refunded','Verified funds refunded in full.',$reference,$u['id']);
            $this->k->audit('payment.refund','order',$id,['refund_id'=>$rid,'amount_minor'=>$amount]);$this->k->notify($o['user_id'],'refund_confirmed',['order_id'=>$id,'amount_minor'=>$amount]);return ['id'=>$rid,'order'=>$this->orderView($this->k->one('SELECT * FROM orders WHERE id=?',[$id]))];
        });
    }
    private function reports():array
    {
        $this->k->actor(self::OPS);$rows=$this->k->all('SELECT o.*,COALESCE((SELECT SUM(e.amount_minor) FROM order_expenses e WHERE e.order_id=o.id),0) expenses_minor,COALESCE((SELECT SUM(l.amount_minor) FROM partner_ledger l WHERE l.order_id=o.id),0) partner_earnings_minor FROM orders o ORDER BY o.created_at DESC LIMIT 1000');$report=[];
        foreach($rows as$o){$f=$this->financials($o['id']);$preTax=intdiv($f['net_paid_minor']*(int)$o['subtotal_minor'],(int)$o['total_minor']);$report[]=['order_id'=>$o['id'],'status'=>$o['status'],'currency'=>'CAD','contracted_revenue_before_tax_minor'=>(int)$o['subtotal_minor'],'paid_minor'=>$f['paid_minor'],'refunded_minor'=>$f['refunded_minor'],'net_collected_minor'=>$f['net_paid_minor'],'collected_before_tax_minor'=>$preTax,'collected_tax_allocation_minor'=>$f['net_paid_minor']-$preTax,'recorded_variable_expenses_minor'=>(int)$o['expenses_minor'],'earned_partner_fees_minor'=>(int)$o['partner_earnings_minor'],'contribution_on_collected_funds_before_unrecorded_costs_minor'=>$preTax-(int)$o['expenses_minor']-(int)$o['partner_earnings_minor']];}
        return ['orders'=>$report,'note'=>'CAD minor units. Payments less verified refunds; tax proportionally allocated. Contribution excludes unrecorded variable costs, fixed overhead and income tax; it is not net profit. Latest 1000 orders.','generated_at'=>$this->k->now()];
    }
    private function setStatus(array $o,string $status,string $note,string $evidence,?string $actor):void{$this->k->execute('UPDATE orders SET status=?,updated_at=? WHERE id=?',[$status,$this->k->now(),$o['id']]);$this->event($o['id'],$status,$note,$evidence,$actor);}
    private function event(string $id,string $status,string $note,string $evidence,?string $actor):void{$this->k->execute('INSERT INTO order_events (id,order_id,status,note,evidence,actor_id,created_at) VALUES (?,?,?,?,?,?,?)',[$this->k->id(),$id,$status,$note,$evidence,$actor,$this->k->now()]);}
    private function decode(?array $r,array $fields):?array{if($r===null)return null;foreach($fields as$f)if(isset($r[$f])&&is_string($r[$f]))$r[$f]=json_decode($r[$f],true,512,JSON_THROW_ON_ERROR);return $r;}
    private function text(array $in,string $key,int $min,int $max):string{$v=$in[$key]??null;if(!is_string($v)||strlen(trim($v))<$min||strlen($v)>$max)$this->invalid("$key must contain $min–$max characters.");return trim($v);}
    private function identifier(array $in,string $key):string{$v=$in[$key]??null;if(!is_string($v)||!preg_match('/^[a-f0-9]{32}$/D',$v))$this->invalid("Invalid $key.");return $v;}
    private function integer(array $in,string $key,int $min,int $max):int{$v=$in[$key]??null;if(!is_int($v)||$v<$min||$v>$max)$this->invalid("$key must be an integer from $min to $max.");return $v;}
    private function enum(array $in,string $key,array $values):string{$v=$in[$key]??null;if(!is_string($v)||!in_array($v,$values,true))$this->invalid("Unsupported $key.");return $v;}
    private function timestamp(array $in,string $key):string
    {
        $v=$in[$key]??null;if(!is_string($v)||strlen($v)>40||!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D',$v))$this->invalid("$key must be an ISO date and time.");
        try{$date=new \DateTimeImmutable($v,new \DateTimeZone('UTC'));}catch(\Exception){$this->invalid("Invalid $key.");}$errors=\DateTimeImmutable::getLastErrors();if($errors!==false&&($errors['warning_count']||$errors['error_count']))$this->invalid("Invalid $key.");return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function missing():never{$this->k->fail(404,'not_found','Record not found.');}
    private function invalid(string $message):never{$this->k->fail(422,'validation',$message);}
    private function conflict(string $message):never{$this->k->fail(409,'conflict',$message);}
}
