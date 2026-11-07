<?php
declare(strict_types=1);
// Requires only the disposable zenith-config MariaDB instance provisioned by the test runner.
define('FIXTURE_HOST',getenv('ZENITH_CONFIG_TEST_HOST')?:'zenith-config');
define('FIXTURE_PORT',getenv('ZENITH_CONFIG_TEST_PORT')?:'3306');
const FIXTURE_PASSWORD="test-only-'quote-2026!";
$base=sys_get_temp_dir().'/zenith-config-'.bin2hex(random_bytes(8));mkdir($base,0700);
$count=0;
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;}
function fixture(string $base,string $name):array{
    $private=$base.'/'.$name.'/fashion-private';$release=$private.'/releases/'.str_repeat('a',40);mkdir($release.'/private/backend',0700,true);
    $template=__DIR__.'/../config.example.php';copy($template,$release.'/private/backend/config.example.php');
    file_put_contents($release.'/release-manifest.json',json_encode(['commit'=>str_repeat('a',40),'dirty'=>false,'files'=>['private/backend/config.example.php'=>hash_file('sha256',$template)]]));
    return [$private,$release];
}
function runSetup(array $fixture,string $password,bool $stdin=true):array{
    [$private,$release]=$fixture;$command=[PHP_BINARY,__DIR__.'/../bin/configure-cpanel.php','--private-root',$private,'--release',$release,'--db-host',FIXTURE_HOST,'--db-port',FIXTURE_PORT];if($stdin)$command[]='--password-stdin';
    $pipes=[];$p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],$password."\n");fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$output];
}
try {
    $f=fixture($base,'success');[$status,$output]=runSetup($f,FIXTURE_PASSWORD);check($status===0,'Private setup succeeds against empty dedicated database');check(!str_contains($output,FIXTURE_PASSWORD),'Password is absent from output');
    $config=require $f[0].'/config.php';check(hash_equals(FIXTURE_PASSWORD,$config['db']['password']),'Quoted password round-trips without PHP escaping errors');check((fileperms($f[0].'/config.php')&0777)===0600,'Configuration permission0600');
    foreach(['storage','storage/uploads','sessions','backups'] as $dir)check((fileperms($f[0].'/'.$dir)&0777)===0700,'Private directory permission0700');
    check($config['manual_payments_enabled']===false&&$config['pricing_min_margin_bps']===null&&$config['stripe_secret_key']==='','Business/payment approvals remain disabled');
    $hash=hash_file('sha256',$f[0].'/config.php');[$status,$output]=runSetup($f,'different-password');check($status!==0&&hash_equals($hash,hash_file('sha256',$f[0].'/config.php')),'Existing configuration is never overwritten');
    $f=fixture($base,'bad-password');[$status,$output]=runSetup($f,'incorrect-password');check($status!==0&&!file_exists($f[0].'/config.php'),'Failed database authentication writes no config');check(!str_contains($output,'incorrect-password'),'Failed authentication does not echo password');
    $f=fixture($base,'not-interactive');[$status]=runSetup($f,FIXTURE_PASSWORD,false);check($status!==0&&!file_exists($f[0].'/config.php'),'Noninteractive password input requires explicit flag');
    $f=fixture($base,'tampered');file_put_contents($f[1].'/private/backend/config.example.php',"<?php return [];\n");[$status]=runSetup($f,FIXTURE_PASSWORD);check($status!==0&&!file_exists($f[0].'/config.php'),'Altered release template rejected');
    $f=fixture($base,'symlink');symlink(sys_get_temp_dir(),$f[0].'/storage');[$status]=runSetup($f,FIXTURE_PASSWORD);check($status!==0&&!file_exists($f[0].'/config.php'),'Symlinked private storage rejected');
    $db=new PDO('mysql:host='.FIXTURE_HOST.';port='.FIXTURE_PORT.';dbname=isp85_fashion;charset=utf8mb4','isp85_fashion',FIXTURE_PASSWORD);check((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'Setup did not change database tables');
    echo 'PASS '.$count." private configuration setup assertions.\n";
}finally{
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item){if($item->isLink()||$item->isFile())unlink($item->getPathname());else rmdir($item->getPathname());}rmdir($base);
}
