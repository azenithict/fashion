<?php
declare(strict_types=1);
// Consistent private logical backup; optional metadata records the same snapshot's invariants.
if(PHP_SAPI!=='cli')exit(1);
ini_set('display_errors','0');$options=getopt('',['config:','output:','manifest:']);$configPath=$options['config']??getenv('ZENITH_CONFIG');$output=$options['output']??null;$metadata=$options['manifest']??null;$p=null;$file=null;$metadataFile=null;$created=[];
$identifier=static function(string $name):string{if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Unexpected identifier');return '`'.$name.'`';};
$write=static function($stream,string $bytes):void{for($offset=0;$offset<strlen($bytes);){$n=fwrite($stream,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Snapshot write failed');$offset+=$n;}};
try{
    if(!$configPath||!$output||file_exists($output)||is_link($output)||($metadata&&(file_exists($metadata)||is_link($metadata))))throw new RuntimeException('Fresh private backup destinations required');
    $c=require $configPath;
    if(($c['environment']??'production')==='production')foreach(array_filter([$output,$metadata]) as $destination){$dir=realpath(dirname($destination));$private=realpath(dirname($configPath));if(!$dir||!$private||($dir!==$private&&!str_starts_with($dir,$private.'/')))throw new RuntimeException('Backup destination must remain private');}
    umask(0077);$file=fopen($output,'x');if(!$file)throw new RuntimeException('Private destination unavailable');$created[]=$output;
    $p=new PDO($c['db']['dsn'],$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);
    $p->exec("SET time_zone='+00:00'");$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $tables=$p->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')->fetchAll(PDO::FETCH_NUM);$counts=[];$financial=[];
    $write($file,"-- A Zenith Fashion private logical backup, UTC ".gmdate(DATE_ATOM)."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach($tables as $row){
        $table=$identifier($row[0]);$create=$p->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);$write($file,$create[1].";\n");$rows=$p->query('SELECT * FROM '.$table);$count=0;
        while($record=$rows->fetch()){$count++;$columns=implode(',',array_map($identifier,array_keys($record)));$values=implode(',',array_map(static fn($v)=>$v===null?'NULL':$p->quote((string)$v),array_values($record)));$write($file,'INSERT INTO '.$table.' ('.$columns.') VALUES ('.$values.");\n");}
        $rows->closeCursor();$counts[$row[0]]=$count;
    }
    $triggers=$p->query('SHOW TRIGGERS')->fetchAll();
    foreach($triggers as $trigger){$definition=$p->query('SHOW CREATE TRIGGER '.$identifier($trigger['Trigger']))->fetch();$sql=preg_replace('/\bDEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i','',$definition['SQL Original Statement']);$write($file,$sql.";\n");}
    foreach(['payments','refunds','partner_ledger'] as $table)$financial[$table]=(string)$p->query('SELECT COALESCE(SUM(amount_minor),0) FROM '.$table)->fetchColumn();
    $write($file,"SET FOREIGN_KEY_CHECKS=1;\n");if(!fflush($file))throw new RuntimeException('Snapshot flush failed');
    if($metadata){$metadataFile=fopen($metadata,'x');if(!$metadataFile)throw new RuntimeException('Metadata destination unavailable');$created[]=$metadata;$write($metadataFile,json_encode(['tables'=>$counts,'triggers'=>count($triggers),'financial_totals'=>$financial,'created_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));if(!fflush($metadataFile))throw new RuntimeException('Metadata flush failed');}
    $p->commit();if(!fclose($file))throw new RuntimeException('Snapshot close failed');$file=null;chmod($output,0600);
    if($metadataFile){if(!fclose($metadataFile))throw new RuntimeException('Metadata close failed');$metadataFile=null;chmod($metadata,0600);}
    echo "Private database snapshot created.\n";
}catch(Throwable){if($p&&$p->inTransaction())$p->rollBack();if(is_resource($file))fclose($file);if(is_resource($metadataFile))fclose($metadataFile);foreach($created as $path)if(is_file($path))unlink($path);fwrite(STDERR,"Backup failed; partial snapshot removed. Check private setup and available space.\n");exit(1);}
