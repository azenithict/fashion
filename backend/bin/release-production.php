<?php
declare(strict_types=1);
// Owner-run public release of a staged production package to https://fashion.azenith.ca, replacing the separate preview.
// Order: verify → private backups (data + current public root) → migrate main DB → switch code + public files under the
// application lock → HTTPS health checks (automatic rollback of code/files on failure) → withdraw the preview entry.
// Never deletes data: replaced public files and the preview entry are moved into a private backup folder.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', '0'); umask(0077);
const PRIVATE_ROOT = '/home/isp85/fashion-private';
const PUBLIC_ROOT = '/home/isp85/fashion.azenith.ca';
const KEEP = ['.well-known', 'cgi-bin', '_fashion-preview', '.user.ini', 'php.ini', 'error_log'];
$run = static function (array $cmd): array {
    $pipes = []; $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Could not start a release step.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out, (string)$err];
};
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') continue;
        $s = $from.'/'.$name; $d = $to.'/'.$name;
        if (is_link($s)) throw new RuntimeException('Links are not allowed in the release.');
        if (is_dir($s)) { if (!is_dir($d) && !mkdir($d, 0755)) throw new RuntimeException('Could not create '.$name.'.'); chmod($d, 0755); $copyTree($s, $d); }
        else { $t = $d.'.tmp-'.bin2hex(random_bytes(4)); if (!copy($s, $t) || !chmod($t, 0644) || !rename($t, $d)) throw new RuntimeException('Could not publish '.$name.'.'); }
    }
};
$get = static function (string $url): array {
    $h = curl_init($url); curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($h); $status = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE); curl_close($h);
    return [$status, is_string($body) ? $body : ''];
};
$moved = []; $backupPublic = null; $previousCurrent = null; $switched = false; $lock = null;
try {
    $o = getopt('', ['release:']);
    $release = realpath($o['release'] ?? '') ?: '';
    if (!preg_match('#^'.preg_quote(PRIVATE_ROOT, '#').'/releases/[a-f0-9]{40}$#D', $release)) throw new RuntimeException('A privately staged production release is required.');
    $manifest = json_decode((string)file_get_contents($release.'/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['variant'] ?? '') !== 'production' || ($manifest['dirty'] ?? true) || basename($release) !== ($manifest['commit'] ?? '') || ($manifest['base_path'] ?? 'x') !== '') throw new RuntimeException('Wrong production package.');
    [$code] = $run([PHP_BINARY, $release.'/private/backend/bin/verify-release.php', $release]);
    if ($code !== 0) throw new RuntimeException('Release verification failed; nothing changed.');
    $config = require PRIVATE_ROOT.'/config.php';
    if (($config['preview_enabled'] ?? false) === true) throw new RuntimeException('Main configuration expected; nothing changed.');
    // First activation: no "current" link exists yet (the public root holds only an older static build).
    $current = PRIVATE_ROOT.'/current';
    if (file_exists($current) && !is_link($current)) throw new RuntimeException('Unexpected "current" entry; nothing changed.');
    $previousCurrent = is_link($current) ? readlink($current) : null;
    $db = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $applied = $db->query('SELECT name,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $files = []; foreach (glob($release.'/private/backend/migrations/*.sql') as $f) $files[basename($f)] = hash_file('sha256', $f);
    foreach ($applied as $name => $sum) if (!isset($files[$name]) || !hash_equals($sum, $files[$name])) throw new RuntimeException('Applied migration '.$name.' differs from this release; nothing changed.');

    // 1. Private backups: configuration, uploads and consistent database snapshot; then the current public root.
    if ($previousCurrent !== null) {
        [$code, $out] = $run(['/bin/bash', $release.'/private/backend/bin/backup.sh', PRIVATE_ROOT]);
        if ($code !== 0) throw new RuntimeException('Private data backup failed; nothing changed.');
        echo trim($out) === '' ? 'Private data backup created.' : trim($out), PHP_EOL;
    } else {
        $dump = PRIVATE_ROOT.'/backups/production-before-'.substr(basename($release), 0, 12).'-'.gmdate('Ymd\THis\Z').'.sql';
        [$code] = $run([PHP_BINARY, $release.'/private/backend/bin/export-db.php', '--config', PRIVATE_ROOT.'/config.php', '--output', $dump]);
        clearstatcache();
        if ($code !== 0 || !is_file($dump) || filesize($dump) < 1) throw new RuntimeException('Main database backup failed; nothing changed.');
        chmod($dump, 0600); echo 'Main database backup: ', $dump, PHP_EOL;
    }
    $backupPublic = PRIVATE_ROOT.'/backups/public-before-'.substr(basename($release), 0, 12).'-'.gmdate('Ymd\THis\Z');
    if (!mkdir($backupPublic, 0700, true)) throw new RuntimeException('Could not create the public backup folder; nothing changed.');

    // 2. Additive migrations on the main database.
    [$code, $out] = $run([PHP_BINARY, $release.'/private/backend/bin/migrate.php', '--config', PRIVATE_ROOT.'/config.php']);
    echo $out; if ($code !== 0) throw new RuntimeException('Migration stopped. The live site is unchanged; review before retrying.');

    // 3. Switch code and public files under the exclusive application lock.
    $lock = fopen(PRIVATE_ROOT.'/application.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not acquire the application lock.');
    $tmp = PRIVATE_ROOT.'/.current-'.bin2hex(random_bytes(4));
    if (!symlink($release.'/private', $tmp) || !rename($tmp, $current)) throw new RuntimeException('Could not switch the private release.');
    $switched = true;
    foreach (scandir(PUBLIC_ROOT) as $name) {
        if ($name === '.' || $name === '..' || in_array($name, KEEP, true) || str_ends_with($name, '.shtml')) continue;
        if (!rename(PUBLIC_ROOT.'/'.$name, $backupPublic.'/'.$name)) throw new RuntimeException('Could not set aside '.$name.'.');
        $moved[] = $name;
    }
    $copyTree($release.'/public', PUBLIC_ROOT);
    flock($lock, LOCK_UN); fclose($lock); $lock = null;

    // 4. Health checks on the real domain.
    $origin = rtrim((string)($config['public_origin'] ?? 'https://fashion.azenith.ca'), '/');
    [$s1, $home] = $get($origin.'/'); [$s2, $health] = $get($origin.'/api/v1/health');
    if ($s1 !== 200 || !str_contains($home, 'Custom Clothing') || $s2 !== 200 || !str_contains($health, '"data"')) throw new RuntimeException('Health check failed (home '.$s1.', API '.$s2.'); previous site restored.');

    // 5. One site: withdraw the separate preview entry (kept privately; preview database and config untouched).
    if (is_dir(PUBLIC_ROOT.'/_fashion-preview') && rename(PUBLIC_ROOT.'/_fashion-preview', $backupPublic.'/_fashion-preview')) echo 'Preview entry withdrawn (kept in ', $backupPublic, ').', PHP_EOL;
    file_put_contents(PRIVATE_ROOT.'/production-active.json', json_encode(['commit' => $manifest['commit'], 'release' => $release, 'previous_current' => $previousCurrent, 'previous_public' => $backupPublic, 'activated_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod(PRIVATE_ROOT.'/production-active.json', 0600);
    echo 'Released ', $manifest['commit'], ' to ', $origin, PHP_EOL, 'Home and API health verified. Previous public files: ', $backupPublic, PHP_EOL;
} catch (Throwable $e) {
    // Roll back code selection and public files; database migrations are additive and stay compatible.
    if ($switched && $previousCurrent === null) @unlink(PRIVATE_ROOT.'/current');
    if ($switched && $previousCurrent) { $tmp = PRIVATE_ROOT.'/.current-rb-'.bin2hex(random_bytes(4)); if (@symlink($previousCurrent, $tmp)) @rename($tmp, PRIVATE_ROOT.'/current'); }
    if ($moved && $backupPublic) {
        foreach (scandir(PUBLIC_ROOT) as $name) if ($name !== '.' && $name !== '..' && !in_array($name, KEEP, true) && !str_ends_with($name, '.shtml')) @rename(PUBLIC_ROOT.'/'.$name, $backupPublic.'/.failed-'.$name);
        foreach ($moved as $name) @rename($backupPublic.'/'.$name, PUBLIC_ROOT.'/'.$name);
    }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    fwrite(STDERR, ($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Release stopped; private setup review required.').PHP_EOL); exit(1);
}
