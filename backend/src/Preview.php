<?php
declare(strict_types=1);
namespace Zenith;

/** Owner-only gateway. Compiled files and application code remain outside the web root. */
final class Preview
{
    private static function closeSession(): void
    {
        if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
        $_SESSION=[];session_id('');
    }
    private static function selectSession(array $config): void
    {
        self::closeSession();$name=$config['session_name'];
        $id=$_COOKIE[$name]??'';
        session_name($name);
        session_id(is_string($id)&&preg_match('/^[A-Za-z0-9,-]{16,128}$/D',$id)?$id:'');
    }
    private static function escaped(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function returnPath(string $candidate,string $prefix): string
    {
        $path=parse_url($candidate,PHP_URL_PATH);
        if (!str_starts_with($candidate,$prefix.'/')||str_contains($candidate,'\\')||!is_string($path)||!str_starts_with($path,$prefix.'/')||preg_match('/[\r\n\x00]/',$candidate)||preg_match('#(?:^|/)\.\.(?:/|$)#',rawurldecode($path))) return $prefix.'/workspace/';
        return $candidate;
    }
    private static function page(Kernel $gate,string $prefix,string $return,string $error='',bool $locking=false): never
    {
        header('Content-Type: text/html; charset=utf-8');
        $csrf=self::escaped($gate->csrf());$action=self::escaped($prefix.'/_gate/'.($locking?'lock':'login'));$target=self::escaped($return);
        $title=$locking?'Lock the private preview':'A Zenith Fashion — Private preview';
        $error=self::escaped($error);$label=$locking?'Lock preview':'Enter private preview';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{background:#f5f3ed;color:#26382e;font:17px/1.7 system-ui;margin:0;padding:8vh 6vw}main{max-width:480px;margin:auto}h1{font:normal 40px/1.2 Georgia,serif}label{display:block;margin:18px 0}input{box-sizing:border-box;width:100%;padding:12px;font:inherit;border:1px solid #a7afa8}button{padding:14px 20px;background:#26382e;color:white;border:0;font:inherit;cursor:pointer}.error{color:#a01d25}small{color:#555}</style></head><body><main><small>A ZENITH FASHION / OWNER ACCESS</small><h1>'.$title.'</h1><p>This validation site is private. The current public website remains unchanged.</p>';
        if($error!=='')echo '<p role="alert" class="error">'.$error.'</p>';
        echo '<form method="post" action="'.$action.'"><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="return" value="'.$target.'">';
        if(!$locking)echo '<label>Administrator email<input type="email" name="email" maxlength="254" autocomplete="username" required></label><label>Administrator password<input type="password" name="password" maxlength="72" autocomplete="current-password" required></label>';
        echo '<button type="submit">'.$label.'</button></form><script>const f=document.querySelector("form");f.addEventListener("submit",()=>{const i=f.elements.namedItem("return");if(location.hash&&!i.value.includes("#"))i.value+=location.hash;});</script></main></body></html>';
        exit;
    }
    public static function run(string $configPath,string $release): never
    {
        ini_set('display_errors','0');
        header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://checkout.stripe.com");
        try {
            $configPath=realpath($configPath)?:'';$release=realpath($release)?:'';$doc=realpath($_SERVER['DOCUMENT_ROOT']??'')?:'';
            foreach([$configPath,$release] as $privatePath)if(!$privatePath||($doc&&($privatePath===$doc||str_starts_with($privatePath,$doc.'/'))))throw new \RuntimeException('Preview files must be private');
            $config=require $configPath;$prefix=$config['public_base_path']??'';
            if(($config['preview_enabled']??false)!==true||!is_string($prefix)||!preg_match('#^/[A-Za-z0-9_-]+$#D',$prefix))throw new \RuntimeException('Preview not enabled');
            $backend=$release.'/private/backend';$assets=realpath($release.'/public');
            $ownerPath=realpath($config['preview_gate_config']??'');
            if(!$assets||!$ownerPath||($doc&&str_starts_with($ownerPath,$doc.'/')))throw new \RuntimeException('Private gateway unavailable');
            require_once $backend.'/src/Kernel.php';require_once $backend.'/src/Auth.php';
            if(!in_array($config['environment']??'production',['production','test'],true))throw new \RuntimeException('Unsupported preview environment');
            if(($config['environment']??'production')!=='test'&&!in_array($_SERVER['HTTPS']??'',['on','1'],true))throw new ApiError(400,'https_required','Use the secure HTTPS address.');
            $rawUri=$_SERVER['REQUEST_URI']??'/';$uri=parse_url($rawUri);$rawPath=$uri['path']??'';
            if($rawPath!==$prefix&&!str_starts_with($rawPath,$prefix.'/'))throw new ApiError(404,'not_found','Preview route not found.');
            $path=substr($rawPath,strlen($prefix))?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';$api=$path==='/api/v1'||str_starts_with($path,'/api/v1/');
            $owner=require $ownerPath;
            $gatePath=realpath($config['preview_gate_session_path']??'');$appPath=realpath($config['session_path']??'');$primaryPath=realpath($owner['session_path']??'');
            if(!$gatePath||!$appPath||$gatePath===$appPath||$gatePath===$primaryPath||$appPath===$primaryPath||($doc&&str_starts_with($gatePath,$doc.'/')))throw new \RuntimeException('Preview session stores must be separate and private');
            $owner['session_path']=$gatePath;
            $names=[$owner['session_name']??'__Host-zenith',$config['preview_gate_session_name']??'__Host-zenith-preview-gate',$config['session_name']??''];
            if(count(array_unique($names))!==3)throw new \RuntimeException('Preview cookie namespaces must be separate');
            $owner['session_name']=$config['preview_gate_session_name']??'__Host-zenith-preview-gate';$owner['session_idle_seconds']=1800;$owner['session_absolute_seconds']=7200;
            self::selectSession($owner);$gate=new Kernel($owner);$auth=new Auth($gate);
            if($path==='/_gate/login'&&$method==='POST'){
                $return=self::returnPath(is_string($_POST['return']??null)?$_POST['return']:$prefix.'/workspace/',$prefix);
                try {
                    if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==rtrim($config['public_origin'],'/'))throw new ApiError(403,'origin_rejected','Cross-site requests are not allowed.');
                    $gate->verifyCsrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');
                    $auth->handle('POST','/login',['email'=>$_POST['email']??'','password'=>$_POST['password']??'']);
                    if(($gate->user['role']??'')!=='admin'){$gate->logout();throw new ApiError(403,'owner_required','Private preview requires an administrator account.');}
                }catch(ApiError $e){http_response_code($e->status);self::page($gate,$prefix,$return,$e->getMessage());}
                $email=$gate->user['email'];self::selectSession($config);$app=new Kernel($config);
                $user=$app->one("SELECT * FROM users WHERE email=? AND role='admin' AND disabled_at IS NULL",[$email]);
                if($user)$app->sessionUser($user);
                self::closeSession();header('Location: '.$return,true,303);exit;
            }
            if(($gate->user['role']??'')!=='admin'){
                if($api){http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>['code'=>'preview_auth_required','message'=>'Open the private preview and sign in as its administrator first.']]);exit;}
                if(!in_array($method,['GET','HEAD'],true))throw new ApiError(401,'preview_auth_required','Administrator access is required.');
                self::page($gate,$prefix,self::returnPath($rawUri,$prefix));
            }
            if($path==='/_gate/lock'){
                if($method==='POST'){
                    if(isset($_SERVER['HTTP_ORIGIN'])&&$_SERVER['HTTP_ORIGIN']!==rtrim($config['public_origin'],'/'))throw new ApiError(403,'origin_rejected','Cross-site request rejected.');
                    $gate->verifyCsrf(is_string($_POST['csrf']??null)?$_POST['csrf']:'');$gate->logout();self::closeSession();header('Location: '.$prefix.'/',true,303);exit;
                }
                self::page($gate,$prefix,$prefix.'/','',true);
            }
            self::closeSession();
            if($api){
                self::selectSession($config);
                // Trusted, include-local values. Never accepted from request headers or query parameters.
                $zenithBackendOverride=$backend;$zenithConfigOverride=$configPath;
                $zenithRequestUriOverride=$path.(isset($uri['query'])?'?'.$uri['query']:'');
                require $backend.'/public/index.php';exit;
            }
            if(!in_array($method,['GET','HEAD'],true))throw new ApiError(405,'method_not_allowed','Unsupported preview request.');
            $decoded=rawurldecode($path);
            foreach(explode('/',$decoded) as $segment)if($segment==='..'||str_starts_with($segment,'.')||str_contains($segment,"\0"))throw new ApiError(404,'not_found','Preview page not found.');
            $candidate=$assets.'/'.ltrim($decoded,'/');if(is_dir($candidate))$candidate.='/index.html';
            $file=realpath($candidate);$types=['html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','txt'=>'text/plain; charset=utf-8','json'=>'application/json','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2'];
            $extension=strtolower(pathinfo($candidate,PATHINFO_EXTENSION));
            if(!$file||!str_starts_with($file,$assets.'/')||!is_file($file)||is_link($candidate)||!isset($types[$extension]))throw new ApiError(404,'not_found','Preview page not found.');
            header('Content-Type: '.$types[$extension]);header('Content-Length: '.filesize($file));if($method!=='HEAD')readfile($file);exit;
        }catch(\Throwable $e){
            $status=$e instanceof ApiError?$e->status:503;http_response_code($status);header('Content-Type: application/json; charset=utf-8');
            if(!($e instanceof ApiError))error_log('Zenith private preview failed: '.get_class($e));
            echo json_encode(['error'=>['code'=>$e instanceof ApiError?$e->errorCode:'preview_unavailable','message'=>$e instanceof ApiError?$e->getMessage():'The private preview is unavailable. The public website is unchanged.']]);exit;
        }
    }
}
