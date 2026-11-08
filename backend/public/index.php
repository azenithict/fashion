<?php
declare(strict_types=1);
// Canonical API entry; bootstrap overrides may only be set by a trusted PHP include.
// Never read bootstrap overrides from client headers, query data or request bodies.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
$requestId=bin2hex(random_bytes(8));header('X-Request-Id: '.$requestId);
try {
    $base=$zenithBackendOverride??getenv('ZENITH_BACKEND') ?: '/home/isp85/fashion-private/current/backend';
    $configPath=$zenithConfigOverride??getenv('ZENITH_CONFIG') ?: dirname($base,2).'/config.php';
    $realConfig=realpath($configPath);$doc=realpath($_SERVER['DOCUMENT_ROOT']??'');
    $realBase=realpath($base);
    if (!$realBase || ($doc && ($realBase===$doc || str_starts_with($realBase,$doc.DIRECTORY_SEPARATOR)))) throw new RuntimeException('Backend must be private');
    if (!$realConfig || ($doc && str_starts_with($realConfig,$doc.DIRECTORY_SEPARATOR))) throw new RuntimeException('Private configuration unavailable');
    $config=require $realConfig;
    if (!is_array($config)||!isset($config['db'])) throw new RuntimeException('Invalid configuration');
    $https=($_SERVER['HTTPS']??'')==='on'||($_SERVER['HTTPS']??'')==='1';
    if (!$https && ($config['environment']??'production')==='production') {
        http_response_code(400);echo json_encode(['error'=>['code'=>'https_required','message'=>'Use the secure HTTPS address.']]);exit;
    }
    foreach (['storage_path','session_path'] as $privateKey) {
        $privatePath=realpath($config[$privateKey]??'');
        if (!$privatePath || ($doc && ($privatePath===$doc || str_starts_with($privatePath,$doc.DIRECTORY_SEPARATOR)))) throw new RuntimeException('Private directories unavailable');
    }
    $lockPath=$config['lock_path']??dirname($configPath).'/application.lock';
    $lock=fopen($lockPath,'c');
    if (!$lock||!flock($lock,LOCK_SH)) throw new RuntimeException('Application lock unavailable');
    foreach(['Kernel','Auth','Designs','Commerce','Partners','Enquiries','Catalog','Operations','Locations'] as $module) require_once $base.'/src/'.$module.'.php';
    $k=new \Zenith\Kernel($config);
    $k->rateLimit('api',180,60);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $uri=parse_url($zenithRequestUriOverride??$_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
    if (!is_string($uri)||!preg_match('#^/api/v1(?:/|$)#',$uri)) $k->fail(404,'not_found','API route not found.');
    $path=rtrim(substr($uri,7),'/')?:'/';
    if (!in_array($method,['GET','POST','PATCH','DELETE'],true)) {header('Allow: GET, POST, PATCH, DELETE');$k->fail(405,'method_not_allowed','Unsupported request method.');}
    if ($method==='GET' && $path==='/health') {echo json_encode(['data'=>['status'=>'ok','api_version'=>1]]);exit;}
    $webhook=$method==='POST'&&$path==='/stripe/webhook';
    if (!$webhook && $method!=='GET') {
        $origin=$_SERVER['HTTP_ORIGIN']??null;
        if ($origin!==null && $origin!==rtrim($config['public_origin'],'/')) $k->fail(403,'origin_rejected','Cross-site requests are not allowed.');
        $k->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    }
    $input=[];$contentType=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
    $multipart=$method==='POST'&&($path==='/uploads'||$path==='/catalog/media');
    if ($method!=='GET' && !$webhook && !$multipart) {
        if ($contentType!=='application/json') $k->fail(415,'json_required','Send JSON request data.');
        $raw=file_get_contents('php://input',false,null,0,131073);
        if ($raw===false || strlen($raw)>131072) $k->fail(413,'request_too_large','Request exceeds the size limit.');
        try {$input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);} catch (JsonException) {$k->fail(400,'invalid_json','Request must contain valid JSON.');}
        if (!is_array($input)||($input!==[] && array_is_list($input))) $k->fail(400,'invalid_json','Request must be a JSON object.');
    }
    if ($multipart) $input=$_POST;
    foreach([new \Zenith\Auth($k),new \Zenith\Designs($k),new \Zenith\Commerce($k),new \Zenith\Partners($k),new \Zenith\Enquiries($k),new \Zenith\Catalog($k),new \Zenith\Operations($k),new \Zenith\Locations($k)] as $module) {
        $data=$module->handle($method,$path,$input);
        if ($data!==null) {echo json_encode(['data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;}
    }
    $k->fail(404,'not_found','API route not found.');
} catch (\Zenith\ApiError $e) {
    http_response_code($e->status);echo json_encode(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
} catch (Throwable $e) {
    // Never log request bodies, tokens, exception messages, measurements or credentials.
    error_log('Zenith request '.$requestId.' failed: '.get_class($e));
    http_response_code(503);echo json_encode(['error'=>['code'=>'service_unavailable','message'=>'The service is temporarily unavailable. Please try again later.','request_id'=>$requestId]]);
}
