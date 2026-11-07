<?php
declare(strict_types=1);
// Consistent logical backup; run under the exclusive application lock (backup.sh).
if (PHP_SAPI!=='cli') exit(1);
$options=getopt('',['config:','output:']);
$config=$options['config']??getenv('ZENITH_CONFIG');$output=$options['output']??null;
if (!$config||!$output||file_exists($output)) {fwrite(STDERR,"Usage: php export-db.php --config /private/config.php --output /private/new-backup.sql\n");exit(2);}
$c=require $config;
$p=new PDO($c['db']['dsn'],$c['db']['user'],$c['db']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);
umask(0077);$f=fopen($output,'x');if(!$f)throw new RuntimeException('Private destination unavailable');
$identifier=static function(string $name):string {if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Unexpected identifier');return '`'.$name.'`';};
try {
    $p->exec("SET time_zone='+00:00'");$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $tables=$p->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
    fwrite($f,"-- A Zenith Fashion private logical backup, UTC ".gmdate(DATE_ATOM)."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach($tables as $row){
        $table=$identifier($row[0]);$create=$p->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        fwrite($f,$create[1].";\n");
        $rows=$p->query('SELECT * FROM '.$table);
        while($r=$rows->fetch()){
            $columns=implode(',',array_map($identifier,array_keys($r)));
            $values=implode(',',array_map(static fn($v)=>$v===null?'NULL':$p->quote((string)$v),array_values($r)));
            fwrite($f,'INSERT INTO '.$table.' ('.$columns.') VALUES ('.$values.");\n");
        }
        $rows->closeCursor();
    }
    $triggers=$p->query('SHOW TRIGGERS')->fetchAll();
    foreach($triggers as $trigger){
        $definition=$p->query('SHOW CREATE TRIGGER '.$identifier($trigger['Trigger']))->fetch();
        $sql=$definition['SQL Original Statement'];$sql=preg_replace('/\bDEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i','',$sql);
        fwrite($f,$sql.";\n");
    }
    fwrite($f,"SET FOREIGN_KEY_CHECKS=1;\n");$p->commit();fclose($f);chmod($output,0600);echo "Private database snapshot created.\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();fclose($f);unlink($output);fwrite(STDERR,"Backup failed; partial snapshot removed.\n");exit(1);}
