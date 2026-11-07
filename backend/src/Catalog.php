<?php
declare(strict_types=1);
namespace Zenith;

// Administrator-managed catalog: designs (products), physical materials, construction options and licensed media.
// Nothing is public until verified, photographed with a recorded licence and moved to a public status.
final class Catalog
{
    private const STAFF = ['operations','admin'];
    private const TABLES = ['products' => 'catalog_products', 'materials' => 'catalog_materials', 'options' => 'catalog_options'];
    private const OWNER = ['products' => 'product', 'materials' => 'material', 'options' => 'option'];
    private const IMAGE_TYPES = ['image/jpeg','image/png','image/webp'];
    public function __construct(private Kernel $k) {}

    public function handle(string $method, string $path, array $input): ?array
    {
        $k = $this->k;
        if (!str_starts_with($path, '/catalog/')) return null;
        // Public, read-only views.
        if ($method === 'GET' && $path === '/catalog/products') return $this->publicList();
        if ($method === 'GET' && preg_match('#^/catalog/products/([a-z0-9]+(?:-[a-z0-9]+)*)$#D', $path, $m)) return $this->publicProduct($m[1]);
        if ($method === 'GET' && $path === '/catalog/materials') return $this->publicMaterials(is_string($_GET['kind'] ?? null) ? $_GET['kind'] : null);
        if ($method === 'GET' && preg_match('#^/catalog/media/([a-f0-9]{32})$#D', $path, $m)) $this->serveMedia($m[1], is_string($_GET['size'] ?? null) ? $_GET['size'] : 'display');
        // Staff administration.
        $u = $k->actor(self::STAFF);
        if ($method === 'GET' && $path === '/catalog/admin') return [
            'products' => $k->all('SELECT * FROM catalog_products ORDER BY updated_at DESC'),
            'materials' => array_map(fn($r) => $this->decode($r), $k->all('SELECT * FROM catalog_materials ORDER BY kind,name')),
            'options' => $k->all('SELECT * FROM catalog_options ORDER BY garment,group_key,label'),
        ];
        if ($method === 'GET' && preg_match('#^/catalog/admin/(products|materials|options)/([a-f0-9]{32})$#D', $path, $m)) return $this->adminDetail($m[1], $m[2]);
        if ($method === 'POST' && $path === '/catalog/media') {
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) $k->fail(422, 'invalid_upload', 'Choose a JPEG, PNG or WebP photograph.');
            return $this->ingest($input, $file['tmp_name'], true);
        }
        if (preg_match('#^/catalog/media/([a-f0-9]{32})(/status)?$#D', $path, $m) && ($method === 'PATCH' || ($method === 'POST' && isset($m[2])))) return $k->transaction(fn() => isset($m[2]) ? $this->mediaStatus($m[1], $input) : $this->mediaUpdate($m[1], $input));
        if ($method === 'POST' && preg_match('#^/catalog/(products|materials|options)$#D', $path, $m)) return $this->create($m[1], $input, $u);
        if (preg_match('#^/catalog/(products|materials|options)/([a-f0-9]{32})(?:/(status|links))?$#D', $path, $m)) {
            [$type, $id, $action] = [$m[1], $m[2], $m[3] ?? ''];
            if ($method === 'PATCH' && $action === '') return $k->transaction(fn() => $this->update($type, $id, $input));
            if ($method === 'POST' && $action === 'status') return $k->transaction(fn() => $this->status($type, $id, $input));
            if ($method === 'POST' && $action === 'links' && $type === 'products') return $k->transaction(fn() => $this->links($id, $input));
        }
        $k->fail(404, 'not_found', 'Catalog route not found.');
    }

    // ---- field validation -------------------------------------------------
    private function spec(string $type, bool $create): array
    {
        $c = $this->k->contract;
        $s = match ($type) {
            'products' => ['name' => ['s',160,true], 'description' => ['s',4000,true], 'category' => ['enum',$c['catalogCategories'],true], 'faq' => ['faq',0,false], 'supplier_reference' => ['s',160,false], 'min_order_quantity' => ['i',1,100000]],
            'materials' => ['name' => ['s',160,true], 'composition' => ['s',200,false], 'colour_name' => ['s',80,false], 'colour_hex' => ['hex',0,false], 'pattern' => ['s',80,false], 'weight_gsm' => ['i',20,2000], 'weave' => ['s',80,false], 'finish' => ['s',80,false], 'stretch' => ['s',80,false], 'care' => ['s',500,false], 'suitable_garments' => ['garments',0,true], 'supplier_reference' => ['s',160,false], 'private_cost_minor' => ['i',0,100000000]],
            'options' => ['label' => ['s',120,true], 'description' => ['s',500,false]],
        };
        if ($create) $s += match ($type) {
            'products' => ['sku' => ['code',60,true], 'slug' => ['slug',80,true], 'garment' => ['enum',$c['garments'],true]],
            'materials' => ['kind' => ['enum',$c['materialKinds'],true], 'supplier_code' => ['code',80,true]],
            'options' => ['garment' => ['enum',$c['garments'],true], 'group_key' => ['key',40,true]],
        };
        return $s;
    }
    private function fields(string $type, array $input, bool $create): array
    {
        $k = $this->k; $out = [];
        foreach ($this->spec($type, $create) as $key => [$kind, $a, $b]) {
            if (!array_key_exists($key, $input)) { if ($create && $b === true && $kind !== 'i') $k->fail(422, 'invalid_input', "Invalid $key."); continue; }
            $v = $input[$key];
            if ($v === null || $v === '') { if ($b === true && $kind !== 'i') $k->fail(422, 'invalid_input', "Invalid $key."); $out[$key] = $kind === 'faq' ? '[]' : null; continue; }
            $out[$key] = match ($kind) {
                's' => $k->text($input, $key, $a, (bool)$b),
                'i' => is_int($v) && $v >= $a && $v <= $b ? $v : $k->fail(422, 'invalid_input', "Invalid $key."),
                'enum' => is_string($v) && in_array($v, $a, true) ? $v : $k->fail(422, 'invalid_input', "Invalid $key."),
                'hex' => is_string($v) && preg_match('/^#[0-9a-f]{6}$/iD', $v) ? strtolower($v) : $k->fail(422, 'invalid_input', "Invalid $key."),
                'code' => is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,'.($a - 1).'}$/D', $v) ? $v : $k->fail(422, 'invalid_input', "Invalid $key."),
                'slug' => is_string($v) && strlen($v) <= $a && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $v) && !preg_match('/^[a-f0-9]{32}$/D', $v) ? $v : $k->fail(422, 'invalid_input', "Invalid $key."),
                'key' => is_string($v) && preg_match('/^[a-z][a-z_]{1,'.($a - 1).'}$/D', $v) ? $v : $k->fail(422, 'invalid_input', "Invalid $key."),
                'garments' => is_array($v) && $v !== [] && array_is_list($v) && !array_diff($v, $k->contract['garments']) ? json_encode(array_values(array_unique($v)), JSON_THROW_ON_ERROR) : $k->fail(422, 'invalid_input', "Invalid $key."),
                'faq' => $this->faq($v),
            };
        }
        return $out;
    }
    private function faq(mixed $v): string
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 12) $this->k->fail(422, 'invalid_input', 'Invalid faq.');
        $items = [];
        foreach ($v as $item) {
            if (!is_array($item)) $this->k->fail(422, 'invalid_input', 'Invalid faq.');
            $items[] = ['q' => $this->k->text($item, 'q', 200), 'a' => $this->k->text($item, 'a', 1500)];
        }
        return json_encode($items, JSON_THROW_ON_ERROR);
    }

    // ---- create / update / status ----------------------------------------
    private function create(string $type, array $input, array $u): array
    {
        $k = $this->k; $f = $this->fields($type, $input, true);
        if ($type === 'products') $f += ['faq' => $f['faq'] ?? '[]'];
        $id = $k->id(); $now = $k->now();
        $f += ['id' => $id, 'status' => 'draft', 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now];
        try { $k->execute('INSERT INTO '.self::TABLES[$type].' ('.implode(',', array_keys($f)).') VALUES ('.implode(',', array_fill(0, count($f), '?')).')', array_values($f)); }
        catch (\PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1062) $k->fail(409, 'duplicate', 'An entry with this code, slug or label already exists.'); throw $e; }
        $k->audit('catalog.created', self::TABLES[$type], $id);
        return $this->adminDetail($type, $id);
    }
    private function update(string $type, string $id, array $input): array
    {
        $k = $this->k; $row = $this->row($type, $id, true);
        if ($row['status'] === 'archived') $k->fail(409, 'archived', 'Archived entries cannot be changed.');
        $f = $this->fields($type, $input, false);
        if ($f === []) $k->fail(422, 'invalid_input', 'No changes supplied.');
        if ($type === 'materials' && isset($f['suitable_garments'])) {
            $removed = array_diff(json_decode($row['suitable_garments'], true), json_decode($f['suitable_garments'], true));
            if ($removed && $k->one('SELECT l.product_id FROM catalog_links l JOIN catalog_products p ON p.id=l.product_id WHERE l.target_type=\'material\' AND l.target_id=? AND p.garment IN ('.implode(',', array_fill(0, count($removed), '?')).') LIMIT 1', array_merge([$id], array_values($removed))))
                $k->fail(409, 'linked', 'Unlink this material from affected designs before removing a garment type.');
        }
        $k->execute('UPDATE '.self::TABLES[$type].' SET '.implode(',', array_map(fn($c) => "$c=?", array_keys($f))).',updated_at=? WHERE id=?', [...array_values($f), $k->now(), $id]);
        $k->audit('catalog.updated', self::TABLES[$type], $id, ['fields' => array_keys($f)]);
        return $this->adminDetail($type, $id);
    }
    private function status(string $type, string $id, array $input): array
    {
        $k = $this->k; $row = $this->row($type, $id, true); $to = $input['status'] ?? null;
        $map = $type === 'products' ? $k->contract['catalogTransitions'] : $k->contract['componentTransitions'];
        if (!is_string($to) || !in_array($to, $map[$row['status']] ?? [], true)) $k->fail(409, 'invalid_transition', 'This status change is not allowed from the current state.');
        $needsReference = in_array($to, ['approved','available_to_order','available'], true);
        $reference = $k->text($input, 'reference', 200, $needsReference);
        $problems = $this->blockers($type, $row, $to);
        if ($problems) $k->fail(409, 'not_ready', implode(' ', $problems));
        $set = ['status' => $to]; $now = $k->now();
        if ($to === 'approved' || $to === 'available') $set += ['verification_reference' => $reference, 'verified_at' => $now];
        if ($to === 'draft') $set += ['verification_reference' => null, 'verified_at' => null];
        if ($type === 'products' && in_array($to, $k->contract['catalogPublicStatuses'], true) && !$row['published_at']) $set['published_at'] = $now;
        $k->execute('UPDATE '.self::TABLES[$type].' SET '.implode(',', array_map(fn($c) => "$c=?", array_keys($set))).',updated_at=? WHERE id=?', [...array_values($set), $now, $id]);
        $k->audit('catalog.status_changed', self::TABLES[$type], $id, ['from' => $row['status'], 'to' => $to, 'reference' => $reference]);
        return $this->adminDetail($type, $id);
    }
    // Server-side publication rules; the admin UI shows these before an attempt.
    private function blockers(string $type, array $row, string $to, ?string $excludeMedia = null): array
    {
        $k = $this->k; $p = [];
        $media = array_filter($this->media(self::OWNER[$type], $row['id'], true), fn($m) => $m['id'] !== $excludeMedia);
        if ($type === 'products') {
            if (in_array($to, $k->contract['catalogPublicStatuses'], true)) {
                if (!$row['verified_at']) $p[] = 'Verify the design before publishing.';
                if (!$media) $p[] = 'Approve at least one licensed photograph.';
            }
            if ($to === 'available_to_order') {
                if (!array_filter($media, fn($m) => $m['source'] !== 'licensed_editorial')) $p[] = 'Orderable designs need an original, supplier-authorized or sample photograph (editorial images are not enough).';
                if (!$k->one("SELECT m.id FROM catalog_links l JOIN catalog_materials m ON m.id=l.target_id WHERE l.product_id=? AND l.target_type='material' AND m.kind='fabric' AND m.status='available' LIMIT 1", [$row['id']])) $p[] = 'Link at least one available, verified fabric.';
            }
        } elseif ($to === 'available' && $type === 'materials' && in_array($row['kind'], $k->contract['photoRequiredMaterialKinds'], true) && !$media) {
            $p[] = 'Approve an actual photograph of this material first.';
        }
        return $p;
    }
    private function links(string $id, array $input): array
    {
        $k = $this->k; $product = $this->row('products', $id, true);
        if ($product['status'] === 'archived') $k->fail(409, 'archived', 'Archived entries cannot be changed.');
        $sets = [];
        foreach (['material' => 'material_ids', 'option' => 'option_ids'] as $kind => $key) {
            $ids = $input[$key] ?? [];
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 200) $k->fail(422, 'invalid_input', "Invalid $key.");
            foreach ($ids as $target) {
                if (!is_string($target) || !preg_match('/^[a-f0-9]{32}$/D', $target)) $k->fail(422, 'invalid_input', "Invalid $key.");
                $r = $kind === 'material' ? $k->one('SELECT status,suitable_garments FROM catalog_materials WHERE id=?', [$target]) : $k->one('SELECT status,garment FROM catalog_options WHERE id=?', [$target]);
                if (!$r || $r['status'] === 'archived') $k->fail(422, 'invalid_input', "Unknown or archived item in $key.");
                $fits = $kind === 'material' ? in_array($product['garment'], json_decode($r['suitable_garments'], true), true) : $r['garment'] === $product['garment'];
                if (!$fits) $k->fail(422, 'incompatible', 'An item is not compatible with this garment type.');
            }
            $sets[$kind] = array_values(array_unique($ids));
        }
        $k->execute('DELETE FROM catalog_links WHERE product_id=?', [$id]); $now = $k->now();
        foreach ($sets as $kind => $ids) foreach ($ids as $target) $k->execute('INSERT INTO catalog_links (product_id,target_type,target_id,created_at) VALUES (?,?,?,?)', [$id, $kind, $target, $now]);
        if ($product['status'] === 'available_to_order' && $this->blockers('products', $product, 'available_to_order')) $k->fail(409, 'not_ready', 'An orderable design must keep at least one available fabric. Move it to inquiry-only first.');
        $k->execute('UPDATE catalog_products SET updated_at=? WHERE id=?', [$now, $id]);
        $k->audit('catalog.links_replaced', 'catalog_products', $id, ['materials' => count($sets['material']), 'options' => count($sets['option'])]);
        return $this->adminDetail('products', $id);
    }

    // ---- media ------------------------------------------------------------
    public function ingest(array $input, string $tmp, bool $uploaded): array
    {
        $k = $this->k; $u = $k->actor(self::STAFF); $k->rateLimit('catalog-media:'.$u['id'], 120, 3600);
        $type = array_search($input['owner_type'] ?? null, self::OWNER, true);
        if ($type === false) $k->fail(422, 'invalid_input', 'Invalid owner_type.');
        $ownerId = $k->text($input, 'owner_id', 32);
        $source = $input['source'] ?? null;
        if (!is_string($source) || !in_array($source, $k->contract['mediaSources'], true)) $k->fail(422, 'invalid_input', 'Invalid source.');
        $licence = $k->text($input, 'licence_reference', 200); $holder = $k->text($input, 'rights_holder', 160); $alt = $k->text($input, 'alt_text', 250, false);
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > 15 * 1024 * 1024) $k->fail(422, 'invalid_upload', 'Photographs must be no larger than 15 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::IMAGE_TYPES, true)) $k->fail(422, 'invalid_upload', 'Only JPEG, PNG and WebP photographs are accepted.');
        $info = getimagesize($tmp);
        if (!$info || $info[0] * $info[1] > 40000000) $k->fail(422, 'invalid_upload', 'Invalid image or dimensions are too large.');
        if (max($info[0], $info[1]) < 800) $k->fail(422, 'invalid_upload', 'Use a photograph at least 800 pixels on its longest side.');
        $folder = $k->config['storage_path'].'/catalog';
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) $k->fail(503, 'storage_unavailable', 'Private media storage is unavailable.');
        return $k->transaction(function () use ($k, $u, $type, $ownerId, $source, $licence, $holder, $alt, $tmp, $uploaded, $size, $mime, $info, $folder): array {
            $owner = $this->row($type, $ownerId, true);
            if ($owner['status'] === 'archived') $k->fail(409, 'archived', 'Archived entries cannot be changed.');
            if ((int)$k->one("SELECT COUNT(*) n FROM catalog_media WHERE owner_type=? AND owner_id=? AND status<>'withdrawn'", [self::OWNER[$type], $ownerId])['n'] >= 12) $k->fail(422, 'media_limit', 'Each entry may have up to twelve active photographs.');
            $name = bin2hex(random_bytes(32)); $target = $folder.'/'.$name; $written = [$target];
            $sha = hash_file('sha256', $tmp);
            if (!($uploaded ? move_uploaded_file($tmp, $target) : copy($tmp, $target))) $k->fail(503, 'storage_unavailable', 'The photograph could not be stored.');
            chmod($target, 0600);
            try {
                // Re-encoded derivatives strip embedded metadata; originals stay staff-only.
                $display = $this->derivative($target, $folder, 1600, $written); $thumb = $this->derivative($target, $folder, 600, $written);
                $id = $k->id(); $now = $k->now();
                $order = (int)$k->one('SELECT COALESCE(MAX(sort_order),0)+1 n FROM catalog_media WHERE owner_type=? AND owner_id=?', [self::OWNER[$type], $ownerId])['n'];
                $k->execute('INSERT INTO catalog_media (id,owner_type,owner_id,storage_name,display_name,thumb_name,mime,byte_size,width,height,sha256,alt_text,sort_order,featured,source,licence_reference,rights_holder,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,\'pending\',?,?,?)',
                    [$id, self::OWNER[$type], $ownerId, $name, $display, $thumb, $mime, $size, $info[0], $info[1], $sha, $alt, $order, $source, $licence, $holder, $u['id'], $now, $now]);
            } catch (\Throwable $e) { foreach ($written as $f) if (is_file($f)) unlink($f); throw $e; }
            $k->audit('catalog.media_uploaded', 'catalog_media', $id, ['owner' => self::OWNER[$type], 'owner_id' => $ownerId, 'source' => $source, 'licence_reference' => $licence]);
            return $k->one('SELECT * FROM catalog_media WHERE id=?', [$id]);
        });
    }
    private function derivative(string $path, string $folder, int $max, array &$written): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) return null;
        $w = imagesx($src); $h = imagesy($src); $scale = min(1, $max / max($w, $h));
        $dw = max(1, (int)round($w * $scale)); $dh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($dw, $dh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
        $name = bin2hex(random_bytes(32)); $target = $folder.'/'.$name; $written[] = $target;
        $ok = imagejpeg($dst, $target, 82);
        if (!$ok) return null;
        chmod($target, 0600); return $name;
    }
    private function mediaUpdate(string $id, array $input): array
    {
        $k = $this->k; $m = $this->mediaRow($id);
        if ($m['status'] === 'withdrawn') $k->fail(409, 'withdrawn', 'Withdrawn photographs cannot be changed.');
        $set = [];
        if (array_key_exists('alt_text', $input)) { $set['alt_text'] = $k->text($input, 'alt_text', 250, $m['status'] === 'approved'); }
        if (array_key_exists('sort_order', $input)) { if (!is_int($input['sort_order']) || $input['sort_order'] < 0 || $input['sort_order'] > 1000) $k->fail(422, 'invalid_input', 'Invalid sort_order.'); $set['sort_order'] = $input['sort_order']; }
        if (($input['featured'] ?? null) === true) { $k->execute('UPDATE catalog_media SET featured=0 WHERE owner_type=? AND owner_id=?', [$m['owner_type'], $m['owner_id']]); $set['featured'] = 1; }
        if ($set === []) $k->fail(422, 'invalid_input', 'No changes supplied.');
        $k->execute('UPDATE catalog_media SET '.implode(',', array_map(fn($c) => "$c=?", array_keys($set))).',updated_at=? WHERE id=?', [...array_values($set), $k->now(), $id]);
        $k->audit('catalog.media_updated', 'catalog_media', $id, ['fields' => array_keys($set)]);
        return $k->one('SELECT * FROM catalog_media WHERE id=?', [$id]);
    }
    private function mediaStatus(string $id, array $input): array
    {
        $k = $this->k; $m = $this->mediaRow($id); $to = $input['status'] ?? null;
        $allowed = ['pending' => ['approved','withdrawn'], 'approved' => ['withdrawn'], 'withdrawn' => []][$m['status']];
        if (!is_string($to) || !in_array($to, $allowed, true)) $k->fail(409, 'invalid_transition', 'This status change is not allowed from the current state.');
        if ($to === 'approved' && trim($m['alt_text']) === '') $k->fail(409, 'not_ready', 'Add descriptive alt text before approving.');
        if ($to === 'withdrawn' && $m['status'] === 'approved') {
            $type = array_search($m['owner_type'], self::OWNER, true); $owner = $this->row($type, $m['owner_id'], true);
            if ($owner['status'] !== 'draft' && $owner['status'] !== 'archived' && $this->blockers($type, $owner, $owner['status'], $id))
                $k->fail(409, 'not_ready', 'This published entry depends on the photograph. Approve a replacement or unpublish first.');
        }
        $k->execute('UPDATE catalog_media SET status=?,featured=IF(?=\'withdrawn\',0,featured),updated_at=? WHERE id=?', [$to, $to, $k->now(), $id]);
        $k->audit('catalog.media_'.$to, 'catalog_media', $id);
        return $k->one('SELECT * FROM catalog_media WHERE id=?', [$id]);
    }
    private function serveMedia(string $id, string $size): never
    {
        $k = $this->k; $m = $k->one('SELECT * FROM catalog_media WHERE id=?', [$id]);
        $staff = in_array($k->user['role'] ?? '', self::STAFF, true);
        $public = $m && $m['status'] === 'approved' && $this->ownerPublic($m['owner_type'], $m['owner_id']);
        if (!$m || (!$public && !$staff)) $k->fail(404, 'not_found', 'Photograph not found.');
        if (!in_array($size, ['thumb','display','original'], true) || ($size === 'original' && !$staff)) $k->fail(404, 'not_found', 'Photograph not found.');
        $name = $size === 'original' ? $m['storage_name'] : ($m[$size.'_name'] ?? null);
        $mime = 'image/jpeg';
        // Without the GD extension no derivative exists; the licensed original is served instead.
        if (!$name) $name = $m['storage_name'];
        if ($name === $m['storage_name']) $mime = $m['mime'];
        $file = $k->config['storage_path'].'/catalog/'.$name;
        if (!is_file($file)) $k->fail(404, 'not_found', 'Photograph not found.');
        header('Content-Type: '.$mime); header('Content-Length: '.(string)filesize($file));
        header('Cache-Control: '.($public && $size !== 'original' ? 'public, max-age=86400' : 'no-store, private'));
        header('Content-Disposition: inline'); header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file); exit;
    }

    // ---- public read models -----------------------------------------------
    private function ownerPublic(string $owner, string $id): bool
    {
        $c = $this->k->contract;
        return match ($owner) {
            'product' => (bool)$this->k->one('SELECT id FROM catalog_products WHERE id=? AND status IN (?,?)', [$id, ...$c['catalogPublicStatuses']]),
            'material' => (bool)$this->k->one("SELECT id FROM catalog_materials WHERE id=? AND status='available'", [$id]),
            'option' => (bool)$this->k->one("SELECT id FROM catalog_options WHERE id=? AND status='available'", [$id]),
            default => false,
        };
    }
    private function publicMedia(array $rows): array
    {
        return array_map(fn($m) => ['id' => $m['id'], 'alt_text' => $m['alt_text'], 'width' => (int)$m['width'], 'height' => (int)$m['height'], 'featured' => (bool)$m['featured'], 'editorial' => $m['source'] === 'licensed_editorial'], $rows);
    }
    private function publicList(): array
    {
        $c = $this->k->contract;
        $rows = $this->k->all('SELECT id,slug,name,category,garment,status,description FROM catalog_products WHERE status IN (?,?) ORDER BY published_at DESC', $c['catalogPublicStatuses']);
        return array_map(function ($p) {
            $media = $this->publicMedia($this->media('product', $p['id'], true));
            return ['slug' => $p['slug'], 'name' => $p['name'], 'category' => $p['category'], 'garment' => $p['garment'], 'status' => $p['status'], 'summary' => mb_substr($p['description'], 0, 220), 'featured_media' => $media[0] ?? null];
        }, $rows);
    }
    public function publicProduct(string $slug): array
    {
        $k = $this->k; $c = $k->contract;
        $p = $k->one('SELECT * FROM catalog_products WHERE slug=? AND status IN (?,?)', [$slug, ...$c['catalogPublicStatuses']]);
        if (!$p) $k->fail(404, 'not_found', 'This design is not available.');
        $materials = $k->all("SELECT m.* FROM catalog_links l JOIN catalog_materials m ON m.id=l.target_id WHERE l.product_id=? AND l.target_type='material' AND m.status='available' ORDER BY m.kind,m.name", [$p['id']]);
        $options = $k->all("SELECT o.* FROM catalog_links l JOIN catalog_options o ON o.id=l.target_id WHERE l.product_id=? AND l.target_type='option' AND o.status='available' ORDER BY o.group_key,o.label", [$p['id']]);
        return [
            'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'category' => $p['category'], 'garment' => $p['garment'], 'status' => $p['status'],
            'orderable' => $p['status'] === 'available_to_order', 'description' => $p['description'], 'faq' => json_decode($p['faq'], true),
            'min_order_quantity' => $p['min_order_quantity'] === null ? null : (int)$p['min_order_quantity'], 'pricing' => 'quote_required',
            'measurement_requirements' => $c['requiredMeasurements'][$p['garment']] ?? [],
            'media' => $this->publicMedia($this->media('product', $p['id'], true)),
            'materials' => array_map(fn($m) => $this->publicMaterial($m), $materials),
            'options' => array_map(fn($o) => ['id' => $o['id'], 'group' => $o['group_key'], 'label' => $o['label'], 'description' => $o['description'], 'media' => $this->publicMedia($this->media('option', $o['id'], true))], $options),
        ];
    }
    private function publicMaterials(?string $kind): array
    {
        if ($kind !== null && !in_array($kind, $this->k->contract['materialKinds'], true)) $this->k->fail(422, 'invalid_input', 'Invalid kind.');
        $rows = $kind ? $this->k->all("SELECT * FROM catalog_materials WHERE status='available' AND kind=? ORDER BY name", [$kind]) : $this->k->all("SELECT * FROM catalog_materials WHERE status='available' ORDER BY kind,name");
        return array_map(fn($m) => $this->publicMaterial($m), $rows);
    }
    private function publicMaterial(array $m): array
    {
        $out = array_intersect_key($m, array_flip(['id','kind','name','composition','colour_name','colour_hex','pattern','weight_gsm','weave','finish','stretch','care']));
        $out['weight_gsm'] = $m['weight_gsm'] === null ? null : (int)$m['weight_gsm'];
        $out['suitable_garments'] = json_decode($m['suitable_garments'], true);
        $out['media'] = $this->publicMedia($this->media('material', $m['id'], true));
        return $out;
    }

    // ---- shared helpers ---------------------------------------------------
    private function adminDetail(string $type, string $id): array
    {
        $row = $this->decode($this->row($type, $id));
        $map = $type === 'products' ? $this->k->contract['catalogTransitions'] : $this->k->contract['componentTransitions'];
        $transitions = [];
        foreach ($map[$row['status']] ?? [] as $to) $transitions[] = ['status' => $to, 'blockers' => $this->blockers($type, $this->row($type, $id), $to), 'reference_required' => in_array($to, ['approved','available_to_order','available'], true)];
        $out = ['type' => $type, 'record' => $row, 'media' => $this->media(self::OWNER[$type], $id, false), 'transitions' => $transitions];
        if ($type === 'products') {
            $out['links'] = ['material_ids' => array_column($this->k->all("SELECT target_id FROM catalog_links WHERE product_id=? AND target_type='material'", [$id]), 'target_id'), 'option_ids' => array_column($this->k->all("SELECT target_id FROM catalog_links WHERE product_id=? AND target_type='option'", [$id]), 'target_id')];
            $out['public_preview'] = in_array($row['status'], $this->k->contract['catalogPublicStatuses'], true) ? '/designs/item/?slug='.$row['slug'] : null;
        }
        return $out;
    }
    private function decode(array $r): array
    {
        foreach (['faq','suitable_garments'] as $key) if (isset($r[$key]) && is_string($r[$key])) $r[$key] = json_decode($r[$key], true);
        return $r;
    }
    private function row(string $type, string $id, bool $lock = false): array
    {
        $r = $this->k->one('SELECT * FROM '.self::TABLES[$type].' WHERE id=?'.($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$r) $this->k->fail(404, 'not_found', 'Catalog entry not found.');
        return $r;
    }
    private function mediaRow(string $id): array
    {
        $m = $this->k->one('SELECT * FROM catalog_media WHERE id=? FOR UPDATE', [$id]);
        if (!$m) $this->k->fail(404, 'not_found', 'Photograph not found.');
        return $m;
    }
    private function media(string $owner, string $id, bool $approvedOnly): array
    {
        return $this->k->all('SELECT * FROM catalog_media WHERE owner_type=? AND owner_id=?'.($approvedOnly ? " AND status='approved'" : '').' ORDER BY featured DESC, sort_order, created_at', [$owner, $id]);
    }
}
