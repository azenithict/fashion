<?php
declare(strict_types=1);
// Only two separately provisioned, disposable zenith_test databases are permitted.
$sourcePath=$argv[1]??getenv('ZENITH_TEST_CONFIG');$targetPath=$argv[2]??null;
if(!$sourcePath||!$targetPath)throw new RuntimeException('Provide source and isolated restore test config paths.');
$source=require $sourcePath;$target=require $targetPath;
foreach([$source,$target] as $c)if(($c['environment']??'')!=='test'||!str_contains($c['db']['dsn'],'dbname=zenith_test'))throw new RuntimeException('Refusing non-test database.');
if($source['db']['dsn']===$target['db']['dsn'])throw new RuntimeException('A separate database instance is required.');
$connect=static fn(array $c)=>new PDO($c['db']['dsn'],$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$s=$connect($source);$t=$connect($target);
if((int)$t->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Restore target must be empty; no data will be dropped.');
$file=tempnam(sys_get_temp_dir(),'zenith-restore-');unlink($file);
try{
    $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/../bin/export-db.php','--config',$sourcePath,'--output',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$stderr!=='')throw new RuntimeException('Logical export failed.');
    $t->exec(file_get_contents($file));$tables=$s->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected identifier');if($s->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==$t->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn())throw new RuntimeException('Row count mismatch.');}
    foreach(['payments','refunds','partner_ledger'] as $table)if($s->query('SELECT COALESCE(SUM(amount_minor),0) FROM '.$table)->fetchColumn()!==$t->query('SELECT COALESCE(SUM(amount_minor),0) FROM '.$table)->fetchColumn())throw new RuntimeException('Financial total mismatch.');
    $triggers=(int)$t->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetchColumn();
    if($triggers!==28)throw new RuntimeException('Immutability triggers not restored.');
    $entry=$t->query('SELECT id FROM partner_ledger LIMIT 1')->fetchColumn();
    if($entry){try{$t->prepare('UPDATE partner_ledger SET amount_minor=amount_minor WHERE id=?')->execute([$entry]);throw new RuntimeException('Restored ledger was mutable.');}catch(PDOException $e){if($e->getCode()!=='45000')throw $e;}}
    echo 'PASS isolated logical backup/restore: '.count($tables).' tables, '.$triggers." triggers, matching row counts and financial totals; ledger immutability enforced.\n";
}finally{if(is_file($file))unlink($file);}
