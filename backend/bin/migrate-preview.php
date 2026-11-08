<?php
declare(strict_types=1);
// Owner-run schema update for the isolated private preview only:
// verify the staged release → private backup of the preview database → apply pending additive migrations → code activation.
// Never touches the main database, configuration, storage or public homepage.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0'); umask(0077);
$run = static function (array $cmd): array {
    $pipes = []; $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Could not start '.basename($cmd[1] ?? 'process').'.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out, (string)$err];
};
try {
    $o = getopt('', ['release:']); $private = '/home/isp85/fashion-private';
    $release = realpath($o['release'] ?? '') ?: '';
    if (!preg_match('#^'.preg_quote($private, '#').'/preview-releases/[a-f0-9]{40}$#D', $release)) throw new RuntimeException('A privately staged preview release is required.');
    $manifest = json_decode((string)file_get_contents($release.'/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['variant'] ?? '') !== 'preview' || ($manifest['dirty'] ?? true) || basename($release) !== ($manifest['commit'] ?? '') || ($manifest['base_path'] ?? '') !== '/_fashion-preview') throw new RuntimeException('Wrong preview package.');
    [$code] = $run([PHP_BINARY, $release.'/private/backend/bin/verify-release.php', $release]);
    if ($code !== 0) throw new RuntimeException('Release verification failed.');
    $previewPath = $private.'/config-preview.php'; $mainPath = $private.'/config.php';
    $preview = require $previewPath; $main = require $mainPath;
    if (($preview['preview_enabled'] ?? false) !== true || ($preview['public_base_path'] ?? '') !== '/_fashion-preview') throw new RuntimeException('Existing isolated preview configuration required.');
    $dbName = static fn(array $c): string => preg_match('/dbname=([^;]+)/', (string)($c['db']['dsn'] ?? ''), $m) ? $m[1] : '';
    if ($dbName($preview) === '' || $dbName($preview) === $dbName($main) || ($preview['db']['user'] ?? '') === ($main['db']['user'] ?? '')) throw new RuntimeException('Preview and main databases/users must be distinct; stopped.');
    $db = new PDO($preview['db']['dsn'], $preview['db']['user'], $preview['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $applied = $db->query('SELECT name,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $files = []; foreach (glob($release.'/private/backend/migrations/*.sql') as $f) $files[basename($f)] = hash_file('sha256', $f);
    foreach ($applied as $name => $sum) if (!isset($files[$name]) || !hash_equals($sum, $files[$name])) throw new RuntimeException('Applied migration '.$name.' differs from this release; stopped for review.');
    $pending = array_keys(array_diff_key($files, $applied)); sort($pending, SORT_STRING);
    if ($pending) {
        $backup = $private.'/backups/preview-before-migration-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.sql';
        [$code, , $err] = $run([PHP_BINARY, $release.'/private/backend/bin/export-db.php', '--config', $previewPath, '--output', $backup]);
        clearstatcache();
        if ($code !== 0 || !is_file($backup) || filesize($backup) < 1) throw new RuntimeException('Preview backup failed; no migration applied.');
        chmod($backup, 0600);
        echo 'Preview database backup: ', $backup, PHP_EOL;
        [$code, $out] = $run([PHP_BINARY, $release.'/private/backend/bin/migrate.php', '--config', $previewPath]);
        echo $out;
        if ($code !== 0) throw new RuntimeException('Migration stopped. Old preview code is still active; restore from the backup above only after review.');
        echo 'Applied to preview database: ', implode(', ', $pending), PHP_EOL;
    } else echo 'No pending preview migrations.', PHP_EOL;
    [$code, $out, $err] = $run([PHP_BINARY, $release.'/private/backend/bin/activate-preview.php', '--release', $release]);
    echo $out; if ($code !== 0) throw new RuntimeException('Activation stopped: '.trim($err).' Schema changes are additive; the previous preview code remains compatible.');
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Preview migration stopped; private setup review required.').PHP_EOL); exit(1);
}
