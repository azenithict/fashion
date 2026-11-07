<?php
declare(strict_types=1);
namespace Zenith;
use PDOException;

final class Auth
{
    public function __construct(private Kernel $k) {}
    public function handle(string $method, string $path, array $input): ?array
    {
        $k=$this->k;
        if ($method==='GET' && $path==='/session') return ['user'=>$k->user,'csrf'=>$k->csrf(),'capabilities'=>['manualPayments'=>!empty($k->config['manual_payments_enabled']) && !empty($k->config['manual_payment_approval_reference']),'stripe'=>!empty($k->config['stripe_secret_key']) && !empty($k->config['stripe_webhook_secret'])]];
        if ($method==='POST' && $path==='/register') {
            $k->rateLimit('register',5,3600); $name=$k->text($input,'name',120); $email=$this->email($input); $password=$this->password($input);
            return $k->transaction(function () use ($k,$name,$email,$password): array {
                if ($k->one('SELECT id FROM users WHERE email=?',[$email])) $k->fail(409,'account_exists','This email cannot be registered. Try signing in or recovering your password.');
                $id=$k->id(); $now=$k->now();
                try { $k->execute("INSERT INTO users (id,name,email,password_hash,role,created_at,updated_at) VALUES (?,?,?,?,'customer',?,?)",[$id,$name,$email,password_hash($password,PASSWORD_DEFAULT),$now,$now]); }
                catch (PDOException $e) { if ($e->getCode()==='23000') $k->fail(409,'account_exists','This email cannot be registered.'); throw $e; }
                $this->issueToken($id,'verify'); $u=$k->one('SELECT * FROM users WHERE id=?',[$id]); $k->sessionUser($u); $k->audit('account.registered','users',$id);
                return $k->user;
            });
        }
        if ($method==='POST' && $path==='/login') {
            $k->rateLimit('login',15,900); $email=$this->email($input); $password=$input['password']??'';
            if (!is_string($password) || strlen($password)>1024) $k->fail(422,'invalid_input','Invalid password.');
            $k->rateLimit('login:'.hash('sha256',$email),10,900);
            $u=$k->one('SELECT * FROM users WHERE email=? AND disabled_at IS NULL',[$email]);
            // Constant-cost verification also when the account does not exist.
            $valid=password_verify($password,$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$u || !$valid) $k->fail(401,'invalid_credentials','Email or password was not recognized.');
            if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) $k->execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
            $k->sessionUser($u); $k->audit('account.login','users',$u['id']); return $k->user;
        }
        if ($method==='POST' && $path==='/logout') { if ($k->user) $k->audit('account.logout','users',$k->user['id']); $k->logout(); return ['ok'=>true]; }
        if ($method==='POST' && $path==='/forgot-password') {
            $k->rateLimit('recovery',5,900); $email=$this->email($input); $u=$k->one('SELECT id FROM users WHERE email=? AND disabled_at IS NULL',[$email]);
            if ($u) $k->transaction(fn()=> $this->issueToken($u['id'],'reset'));
            return ['ok'=>true,'message'=>'If this account exists, recovery instructions will be sent.'];
        }
        if ($method==='POST' && $path==='/resend-verification') {
            $u=$k->actor(); $k->rateLimit('resend:'.$u['id'],3,3600);
            if (!$u['email_verified_at']) $k->transaction(fn()=> $this->issueToken($u['id'],'verify'));
            return ['ok'=>true];
        }
        if ($method==='POST' && in_array($path,['/verify-email','/reset-password'],true)) {
            $k->rateLimit('consume-token',15,900); $token=$k->text($input,'token',128); $purpose=$path==='/verify-email'?'verify':'reset';
            if (!preg_match('/^[a-f0-9]{64}$/D',$token)) $k->fail(422,'invalid_token','This link is invalid or expired.');
            $password=$purpose==='reset'?$this->password($input):null;
            return $k->transaction(function () use ($k,$token,$purpose,$password): array {
                $t=$k->one('SELECT * FROM auth_tokens WHERE token_hash=? AND purpose=? FOR UPDATE',[hash('sha256',$token),$purpose]);
                if (!$t || $t['consumed_at'] || $t['expires_at']<=$k->now()) $k->fail(422,'invalid_token','This link is invalid or expired.');
                $k->execute('UPDATE auth_tokens SET consumed_at=? WHERE id=?',[$k->now(),$t['id']]);
                if ($purpose==='verify') {
                    $k->execute('UPDATE users SET email_verified_at=COALESCE(email_verified_at,?),updated_at=? WHERE id=?',[$k->now(),$k->now(),$t['user_id']]);
                    if (($k->user['id']??null)===$t['user_id']) $k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$t['user_id']]));
                } else {
                    $k->execute('UPDATE users SET password_hash=?,session_version=session_version+1,updated_at=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$k->now(),$t['user_id']]);
                    $k->execute("UPDATE auth_tokens SET consumed_at=? WHERE user_id=? AND purpose='reset' AND consumed_at IS NULL",[$k->now(),$t['user_id']]);
                    if (($k->user['id']??null)===$t['user_id']) $k->logout();
                }
                $k->audit('account.'.$purpose,'users',$t['user_id']); return ['ok'=>true];
            });
        }
        if ($path==='/profile' && $method==='GET') return $k->actor();
        if ($path==='/profile' && $method==='PATCH') {
            $u=$k->actor(); $name=$k->text($input,'name',120); $phone=$k->text($input,'phone',40,false);
            $k->execute('UPDATE users SET name=?,phone=?,updated_at=? WHERE id=?',[$name,$phone,$k->now(),$u['id']]);
            $k->user=$k->publicUser($k->one('SELECT * FROM users WHERE id=?',[$u['id']])); $k->audit('account.profile_updated','users',$u['id']); return $k->user;
        }
        if ($path==='/addresses' && $method==='GET') { $u=$k->actor(); return $k->all('SELECT * FROM addresses WHERE user_id=? ORDER BY created_at DESC',[$u['id']]); }
        if ($path==='/addresses' && $method==='POST') {
            $u=$k->actor(); $fields=[];
            foreach (['label'=>100,'recipient'=>120,'line1'=>200,'line2'=>200,'city'=>100,'province'=>100,'postal_code'=>20,'country'=>2] as $key=>$max) $fields[$key]=$k->text($input,$key,$max,$key!=='line2');
            $fields['country']=strtoupper($fields['country']); if (!preg_match('/^[A-Z]{2}$/D',$fields['country'])) $k->fail(422,'invalid_input','Country must be an ISO two-letter code.');
            $id=$k->id(); $k->execute('INSERT INTO addresses (id,user_id,label,recipient,line1,line2,city,province,postal_code,country,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$id,$u['id'],...array_values($fields),$k->now()]); return $k->one('SELECT * FROM addresses WHERE id=?',[$id]);
        }
        if ($method==='DELETE' && preg_match('#^/addresses/([a-f0-9]{32})$#D',$path,$m)) {
            $u=$k->actor(); $n=$k->execute('DELETE FROM addresses WHERE id=? AND user_id=?',[$m[1],$u['id']]); if (!$n) $k->fail(404,'not_found','Address not found.'); return ['ok'=>true];
        }
        return null;
    }
    private function email(array $input): string { $email=strtolower($this->k->text($input,'email',254)); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $this->k->fail(422,'invalid_input','Enter a valid email address.'); return $email; }
    private function password(array $input): string { $p=$input['password']??null; if (!is_string($p)||strlen($p)<12||strlen($p)>72) $this->k->fail(422,'invalid_input','Use a password of 12–72 bytes.'); return $p; }
    private function issueToken(string $userId,string $purpose): array {
        $k=$this->k; $token=bin2hex(random_bytes(32)); $now=$k->now();
        $k->execute('UPDATE auth_tokens SET consumed_at=? WHERE user_id=? AND purpose=? AND consumed_at IS NULL',[$now,$userId,$purpose]);
        $k->execute('INSERT INTO auth_tokens (id,user_id,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?,?)',[$k->id(),$userId,$purpose,hash('sha256',$token),gmdate('Y-m-d H:i:s',time()+($purpose==='verify'?86400:1800)),$now]);
        $origin=rtrim($k->config['public_origin']??$k->config['origin']??'','/');
        if (!filter_var($origin,FILTER_VALIDATE_URL)) $k->fail(503,'configuration_required','Account email delivery is not configured.');
        $k->notify($userId,$purpose==='verify'?'account_verification':'password_recovery',['url'=>$origin.'/account/?action='.($purpose==='verify'?'verify':'reset').'#token='.$token]);
        return ['ok'=>true];
    }
}
