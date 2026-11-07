<?php
declare(strict_types=1);
// Narrow repair for the first installed owner preview. No configuration or DB access.
if(PHP_SAPI!=='cli')exit(1);
umask(0077);
try{
    $private='/home/isp85/fashion-private';$release=$private.'/preview-releases/f7ba5a46f17eec40061896b3ace66e760947d0dc';$path=$release.'/private/backend/src/Preview.php';$manifestPath=$release.'/release-manifest.json';
    $original='57ad3e02cc67875f75fff9951c8a8e1f2603a5718e4c68011550ad0bd13aa2a4';
    $bytes=file_get_contents($path);if($bytes===false||is_link($path)||!hash_equals($original,hash('sha256',$bytes)))throw new RuntimeException('Installed gateway differs from the expected version. Nothing changed.');
    $count=0;$fixed=str_replace("header('Referrer-Policy: no-referrer');","header('Referrer-Policy: same-origin');",$bytes,$count);if($count!==1)throw new RuntimeException('Expected policy not found. Nothing changed.');
    $manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);if(($manifest['files']['private/backend/src/Preview.php']??'')!==$original)throw new RuntimeException('Manifest differs. Nothing changed.');
    $backup=$private.'/preview-origin-repair-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));if(!mkdir($backup,0700))throw new RuntimeException('Cannot prepare private backup.');
    foreach([$path,$manifestPath] as $source)if(!copy($source,$backup.'/'.basename($source))||!chmod($backup.'/'.basename($source),0600))throw new RuntimeException('Private backup failed.');
    $manifest['files']['private/backend/src/Preview.php']=hash('sha256',$fixed);$manifest['operator_repairs'][]=['repair'=>'same-origin preview form policy','at'=>gmdate(DATE_ATOM),'backup'=>$backup];
    $write=static function(string $target,string $data):void{$temp=$target.'.repair-'.bin2hex(random_bytes(5));if(file_put_contents($temp,$data,LOCK_EX)!==strlen($data)||!chmod($temp,0600)||!rename($temp,$target))throw new RuntimeException('Private repair write failed; stop for review.');};
    $write($path,$fixed);$write($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    echo "Preview form policy repaired. Cross-site and CSRF checks remain enabled.\nPrivate backup: ".$backup."\nNo passwords, databases or existing homepage changed.\n";
}catch(Throwable $e){fwrite(STDERR,($e instanceof RuntimeException?$e->getMessage():'Repair stopped; private review required.').PHP_EOL);exit(1);}
