<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../src/Kernel.php';
$options=getopt('', ['config:']);
$configPath=$options['config']??getenv('ZENITH_CONFIG');
if (!is_string($configPath)||!is_file($configPath)) { fwrite(STDERR,"Provide --config /absolute/private/config.php\n"); exit(2); }
try {
    $config=require $configPath; $config['session_enabled']=false; $k=new Zenith\Kernel($config);
    $lock=$k->one("SELECT GET_LOCK('zenith_schema_migrations',10) AS acquired");
    if ((int)$lock['acquired']!==1) throw new RuntimeException('Another migration is running');
    try {
        $k->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(180) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB');
        $files=glob(__DIR__.'/../migrations/*.sql'); sort($files,SORT_STRING);
        foreach ($files as $file) {
            $name=basename($file); $sql=(string)file_get_contents($file); $checksum=hash('sha256',$sql); $previous=$k->one('SELECT checksum FROM schema_migrations WHERE name=?',[$name]);
            if ($previous) { if (!hash_equals($previous['checksum'],$checksum)) throw new RuntimeException('Applied migration checksum mismatch: '.$name); continue; }
            $k->db->exec($sql);
            $k->execute('INSERT INTO schema_migrations (name,checksum,applied_at) VALUES (?,?,?)',[$name,$checksum,$k->now()]);
            echo 'Applied ', $name, PHP_EOL;
        }
    } finally { $k->one("SELECT RELEASE_LOCK('zenith_schema_migrations')"); }
} catch (Throwable $e) { fwrite(STDERR,"Migration failed at ".($name??"initialization")." (code ".$e->getCode()."). Inspect private setup and partial migration state before retrying.\n"); exit(1); }
