<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=realpath($argv[1]??'');if(!$root||!is_file($root.'/release-manifest.json')){fwrite(STDERR,"Provide the extracted private release folder.\n");exit(2);}
try{
    $manifest=json_decode(file_get_contents($root.'/release-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(!preg_match('/^[a-f0-9]{40}$/D',$manifest['commit']??'')||($manifest['dirty']??true)!==false)throw new RuntimeException('Uncommitted build rejected');
    $expected=$manifest['files']??null;if(!is_array($expected)||!$expected)throw new RuntimeException('Empty manifest');
    $seen=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
        if($file->isLink()||!$file->isFile())throw new RuntimeException('Special file rejected');
        $path=substr($file->getPathname(),strlen($root)+1);if($path==='release-manifest.json')continue;
        if(!isset($expected[$path])||!hash_equals($expected[$path],hash_file('sha256',$file->getPathname())))throw new RuntimeException('Release checksum mismatch');
        if(str_starts_with($path,'public/') && preg_match('/\.(php[0-9]?|phtml|phar)$/i',$path) && $path!=='public/api/v1/index.php')throw new RuntimeException('Unexpected public executable');
        $seen[$path]=true;
    }
    if(count($seen)!==count($expected))throw new RuntimeException('Release files missing');
    foreach(array_keys($expected) as $path)if(str_starts_with($path,'/')||str_contains($path,'..')||str_contains($path,'\\'))throw new RuntimeException('Unsafe manifest path');
    echo 'Verified release '.$manifest['commit'].' ('.count($seen)." files).\n";
}catch(Throwable){fwrite(STDERR,"Release verification failed; nothing activated.\n");exit(1);}
