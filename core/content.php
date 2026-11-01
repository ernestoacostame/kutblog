<?php
// KutBlog - Content Manager (File-based, no DB)
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/auth.php';

function content_path(string $type): string {
    return CONTENT_PATH . '/' . $type;
}

function slug_from_title(string $title): string {
    $slug = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    if (function_exists('iconv')) {
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($translit !== false) $slug = $translit;
    }
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    return trim(strtolower($slug), '-');
}

function save_content_backup(string $type, array $data): void {
    $dir = content_path($type);
    $slug = $data['slug'] ?? 'unknown';
    
    // Create backup directory if it doesn't exist
    $backup_dir = CACHE_PATH . '/content_backups/' . $type;
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    // Create a backup with timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $backup_file = $backup_dir . '/' . $slug . '_' . $timestamp . '.json';
    
    // Save the backup
    file_put_contents($backup_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Keep only the last 10 backups for each slug
    $backups = glob($backup_dir . '/' . $slug . '_*.json');
    if (count($backups) > 10) {
        // Sort by creation time (oldest first)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Remove oldest backups beyond limit
        for ($i = 0; $i < count($backups) - 10; $i++) {
            unlink($backups[$i]);
        }
    }
}

function get_content_backups(string $type, string $slug): array {
    $backup_dir = CACHE_PATH . '/content_backups/' . $type;
    if (!is_dir($backup_dir)) {
        return [];
    }
    
    $backups = glob($backup_dir . '/' . $slug . '_*.json');
    $result = [];
    
    foreach ($backups as $backup_file) {
        $timestamp = basename($backup_file, '.json');
        $timestamp = substr($timestamp, strlen($slug) + 1); // Remove slug prefix
        
        $result[] = [
            'file' => $backup_file,
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', filemtime($backup_file)),
            'data' => json_decode(file_get_contents($backup_file), true)
        ];
    }
    
    // Sort by timestamp (newest first)
    usort($result, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $result;
}

/**
 * Sanitiza URLs locales file:/// para prevenir Security Error en navegadores modernos (Firefox, etc.)
 */
function cms_sanitize_local_file_urls(string $html): string {
    if ($html === '' || !str_contains($html, 'file:')) {
        return $html;
    }
    // 1. Anchors: <a ... href="file://...">label</a> -> <code>label</code>
    $html = preg_replace_callback('/<a\s+[^>]*href=["\']?file:\/+[^\s"\'>]*["\']?[^>]*>(.*?)<\/a>/is', function($m) {
        return '<code>' . $m[1] . '</code>';
    }, $html);

    // 2. Embedded elements: <(img|source|audio|video|iframe|embed|object|script|link) ... (src|href|data)="file://..."> -> remove tag
    $html = preg_replace('/<(?:img|audio|video|source|iframe|embed|object|script|link)\s+[^>]*(?:src|href|data)=["\']?file:\/+[^\s"\'>]*["\']?[^>]*>/is', '', $html);

    // 3. Fallback: Any remaining src/href/data attribute pointing to file:/ -> point to #
    $html = preg_replace('/\b(href|src|data)=["\']?file:\/+[^\s"\'>]*["\']?/i', '$1="#"', $html);

    return $html;
}

function cms_sanitize_content_item(array $data): array {
    if (!empty($data['content'])) {
        $data['content'] = cms_sanitize_local_file_urls($data['content']);
    }
    if (!empty($data['excerpt'])) {
        $data['excerpt'] = cms_sanitize_local_file_urls($data['excerpt']);
    }
    if (!empty($data['featured_image']) && preg_match('#^file:/#i', $data['featured_image'])) {
        $data['featured_image'] = '';
    }
    return $data;
}

function save_content(string $type, array $data): string {
    $dir = content_path($type);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    
    $slug = $data['slug'] ?? slug_from_title($data['title'] ?? 'untitled');
    $slug = sanitize_filename($slug);
    
    // Ensure unique slug — skip if the file already belongs to this article
    $original = $slug;
    $i = 1;
    while (file_exists("$dir/$slug.json")
        && ($data['original_slug'] ?? '') !== $slug
        && ($data['slug'] ?? '')          !== $slug) {
        $slug = $original . '-' . $i++;
    }
    
    // Delete old file if slug changed
    if (!empty($data['original_slug']) && $data['original_slug'] !== $slug) {
        $old = "$dir/{$data['original_slug']}.json";
        if (file_exists($old)) unlink($old);

        if ($type === 'articles' && cms_plugin_is_active('fediverse')) {
            require_once __DIR__ . '/../plugins/fediverse/activitypub.php';
            ap_update_slug($data['original_slug'], $slug);
        }
    }
    
    unset($data['original_slug']);
    $data['slug'] = $slug;
    $data['updated_at'] = date('c');
    if (empty($data['created_at'])) {
        $data['created_at'] = date('c');
    } else {
        $ts = strtotime($data['created_at']);
        $data['created_at'] = $ts ? date('c', $ts) : date('c');
    }

    // Sanitizar enlaces y recursos locales file:/// para prevenir Security Error en navegadores
    $data = cms_sanitize_content_item($data);
    
    // Create a backup before saving
    save_content_backup($type, $data);
    
    file_put_contents("$dir/$slug.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Clear index and page cache
    invalidate_content_index($type);
    clear_cache();
    
    return $slug;
}

function get_content(string $type, string $slug): ?array {
    $file = content_path($type) . "/$slug.json";
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);

    if ($data) {
        $data = cms_sanitize_content_item($data);
    }

    if ($data && $type === 'articles' && empty($data['mastodon_url'])) {
        if (cms_plugin_is_active('fediverse')) {
            $config = cms_config();
            $username = $config['fediverse_username'] ?? 'blog';
            $data['mastodon_url'] = base_url() . "/users/{$username}/notes/{$slug}";
        }
    }
    return $data;
}

function delete_content(string $type, string $slug): bool {
    $file = content_path($type) . "/$slug.json";
    if (!file_exists($file)) return false;

    // Notificar al Fediverso de la eliminación antes de purgar el archivo
    if ($type === 'articles' && cms_plugin_is_active('fediverse')) {
        require_once ROOT_PATH . '/plugins/fediverse/activitypub.php';
        if (function_exists('ap_delete_article')) {
            ap_delete_article($slug);
        }
    }

    // Desvincular de series si el plugin está activo
    if ($type === 'articles' && cms_plugin_is_active('series')) {
        require_once ROOT_PATH . '/plugins/series/series.php';
        if (function_exists('series_on_delete_post')) {
            series_on_delete_post($slug);
        }
    }

    unlink($file);
    invalidate_content_index($type);
    clear_cache();
    return true;
}

/**
 * High-performance content index cache.
 * Avoids reading and parsing hundreds of JSON files from disk on every query.
 */
function get_content_index(string $type, bool $force = false): array {
    if (!$force && isset($GLOBALS['__content_index_cache'][$type])) {
        return $GLOBALS['__content_index_cache'][$type];
    }

    $dir = content_path($type);
    if (!is_dir($dir)) return [];

    $cache_dir = CACHE_PATH;
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . "/content_index_{$type}.json";

    // Use cached index if it exists and directory has not been modified
    if (!$force && file_exists($cache_file)) {
        $index_mtime = filemtime($cache_file);
        $dir_mtime = filemtime($dir);
        if ($index_mtime >= $dir_mtime) {
            $raw = @file_get_contents($cache_file);
            $cached = json_decode($raw, true);
            if (is_array($cached)) {
                $GLOBALS['__content_index_cache'][$type] = $cached;
                return $cached;
            }
        }
    }

    // Build index from disk
    $files = glob("$dir/*.json");
    $items = [];
    if ($files) {
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $data = json_decode($raw, true);
            if (!$data || !is_array($data)) continue;
            $items[] = cms_sanitize_content_item($data);
        }
    }

    // Sort descending by created_at
    usort($items, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    // Atomic cache write
    @file_put_contents($cache_file, json_encode($items, JSON_UNESCAPED_UNICODE), LOCK_EX);
    $GLOBALS['__content_index_cache'][$type] = $items;
    return $items;
}

function invalidate_content_index(?string $type = null): void {
    if ($type !== null) {
        unset($GLOBALS['__content_index_cache'][$type]);
        $file = CACHE_PATH . "/content_index_{$type}.json";
        if (file_exists($file)) @unlink($file);
    } else {
        unset($GLOBALS['__content_index_cache']);
        $files = glob(CACHE_PATH . '/content_index_*.json');
        if ($files) {
            foreach ($files as $f) @unlink($f);
        }
    }
}

/**
 * Revisa si existen artículos con estado 'scheduled' cuya fecha de publicación
 * programada ya haya sido alcanzada, y los publica automáticamente.
 */
function check_scheduled_posts(): void {
    static $already_checked = false;
    if ($already_checked) return;
    $already_checked = true;

    $items = get_content_index('articles', false);
    $now = time();
    $to_publish = [];

    foreach ($items as $item) {
        if (($item['status'] ?? '') === 'scheduled') {
            $post_time = strtotime($item['created_at'] ?? '');
            if ($post_time && $post_time <= $now) {
                $to_publish[] = $item['slug'];
            }
        }
    }

    if (empty($to_publish)) return;

    $updated = false;
    foreach ($to_publish as $slug) {
        $post = get_content('articles', $slug);
        if ($post && ($post['status'] ?? '') === 'scheduled') {
            $post['status'] = 'published';
            save_content('articles', $post);
            $updated = true;

            // Trigger Fediverse si está activo
            if (cms_plugin_is_active('fediverse')) {
                require_once dirname(__DIR__) . '/plugins/fediverse/activitypub.php';
                if (function_exists('ap_publish_article')) {
                    ap_publish_article($post, false);
                }
            }

            // Trigger Newsletter si estaba configurado y el plugin está activo
            if (cms_plugin_is_active('newsletter') && !empty($post['newsletter_broadcast'])) {
                require_once dirname(__DIR__) . '/core/newsletter.php';
                if (function_exists('newsletter_broadcast_post')) {
                    newsletter_broadcast_post($post, false);
                }
            }
        }
    }

    if ($updated) {
        invalidate_content_index('articles');
        clear_cache();
    }
}

function list_content(string $type, bool $published_only = false, int $page = 1, int $per_page = 10): array {
    if ($type === 'articles') {
        check_scheduled_posts();
    }
    $dir = content_path($type);
    if (!is_dir($dir)) return ['items' => [], 'total' => 0, 'pages' => 0];

    // Read from optimized index cache instead of disk glob + parsing each file
    $items = get_content_index($type);

    if ($published_only) {
        $items = array_values(array_filter($items, fn($d) => ($d['status'] ?? 'draft') === 'published'));
    }

    $total = count($items);
    $pages = (int)ceil($total / $per_page);
    $offset = ($page - 1) * $per_page;

    return [
        'items' => array_slice($items, $offset, $per_page),
        'total' => $total,
        'pages' => $pages,
        'page' => $page
    ];
}

function search_content(string $query): array {
    $results = [];
    $query_lower = strtolower(trim($query));
    if ($query_lower === '') return [];

    foreach (['articles', 'pages'] as $type) {
        // Search through indexed records fast
        $items = get_content_index($type);
        foreach ($items as $data) {
            $transcript = !empty($data['podcast_meta']['transcript']) ? (' ' . strtolower($data['podcast_meta']['transcript'])) : '';
            $haystack = strtolower(($data['title'] ?? '') . ' ' . strip_tags($data['excerpt'] ?? '') . ' ' . strip_tags($data['content'] ?? '')) . $transcript;
            if (str_contains($haystack, $query_lower)) {
                $data['_type'] = $type;
                if (!empty($data['podcast_meta']['is_podcast'])) {
                    $data['_is_podcast'] = true;
                }
                $results[] = $data;
            }
        }
    }
    return $results;
}

function content_exists(string $type, string $slug): bool {
    return file_exists(content_path($type) . "/$slug.json");
}

function clear_cache(): void {
    $files = glob(CACHE_PATH . '/page_*.html');
    if ($files) foreach ($files as $f) @unlink($f);
    invalidate_content_index();
}

// ── Trash & Soft-Delete (quireINK inspired) ──────────────────────────────
function trash_path(?string $type = null): string {
    $base = CONTENT_PATH . '/trash';
    $dir = $type ? $base . '/' . $type : $base;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function trash_content(string $type, string $slug): bool {
    $src = content_path($type) . "/$slug.json";
    if (!file_exists($src)) return false;

    $data = json_decode(file_get_contents($src), true) ?? [];
    $data['trashed_at'] = date('c');

    $destDir = trash_path($type);
    $dest = $destDir . "/$slug.json";

    file_put_contents($dest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @unlink($src);

    // Si estaba federado en ActivityPub, retirar la nota
    if ($type === 'articles' && cms_plugin_is_active('fediverse')) {
        require_once ROOT_PATH . '/plugins/fediverse/activitypub.php';
        if (function_exists('ap_delete_article')) {
            ap_delete_article($slug);
        }
    }

    invalidate_content_index($type);
    clear_cache();
    return true;
}

function restore_content(string $type, string $slug): bool {
    $src = trash_path($type) . "/$slug.json";
    if (!file_exists($src)) return false;

    $data = json_decode(file_get_contents($src), true) ?? [];
    unset($data['trashed_at']);

    $dest = content_path($type) . "/$slug.json";
    file_put_contents($dest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @unlink($src);

    // Si estaba publicado, re-federar si corresponde
    if ($type === 'articles' && ($data['status'] ?? '') === 'published' && cms_plugin_is_active('fediverse')) {
        require_once ROOT_PATH . '/plugins/fediverse/activitypub.php';
        if (function_exists('ap_publish_article')) {
            ap_publish_article($data, false);
        }
    }

    invalidate_content_index($type);
    clear_cache();
    return true;
}

function purge_content(string $type, string $slug): bool {
    $src = trash_path($type) . "/$slug.json";
    if (file_exists($src)) {
        @unlink($src);
        return true;
    }
    return false;
}

function list_trash(?string $type = null): array {
    $items = [];
    $types = $type ? [$type] : ['articles', 'pages', 'media'];

    foreach ($types as $t) {
        $dir = trash_path($t);
        if (!is_dir($dir)) continue;

        if ($t === 'media') {
            $files = glob("$dir/*");
            foreach ($files as $f) {
                if (!is_file($f)) continue;
                $fn = basename($f);
                $items[] = [
                    'type'       => 'media',
                    'slug'       => $fn,
                    'title'      => $fn,
                    'file'       => $fn,
                    'size'       => filesize($f),
                    'trashed_at' => date('c', filemtime($f)),
                ];
            }
        } else {
            $files = glob("$dir/*.json");
            foreach ($files as $f) {
                $raw = @file_get_contents($f);
                $data = $raw ? json_decode($raw, true) : null;
                if ($data) {
                    $items[] = [
                        'type'       => $t,
                        'slug'       => $data['slug'] ?? basename($f, '.json'),
                        'title'      => $data['title'] ?? basename($f, '.json'),
                        'status'     => $data['status'] ?? 'draft',
                        'trashed_at' => $data['trashed_at'] ?? date('c', filemtime($f)),
                        'date'       => $data['date'] ?? ($data['created_at'] ?? ''),
                    ];
                }
            }
        }
    }

    usort($items, fn($a, $b) => strcmp($b['trashed_at'] ?? '', $a['trashed_at'] ?? ''));
    return $items;
}

function empty_trash(?string $type = null): int {
    $types = $type ? [$type] : ['articles', 'pages', 'media'];
    $count = 0;
    foreach ($types as $t) {
        $dir = trash_path($t);
        if (!is_dir($dir)) continue;
        $files = glob("$dir/*");
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
                $count++;
            }
        }
    }
    return $count;
}

function trash_media(string $rel): bool {
    $rel = ltrim(str_replace(['..', '//'], '', $rel), '/');
    if (!defined('MEDIA_PATH')) define('MEDIA_PATH', ROOT_PATH . '/media');
    $src = MEDIA_PATH . '/' . $rel;
    if (!file_exists($src) || !is_file($src)) return false;

    $destDir = trash_path('media');
    $dest = $destDir . '/' . basename($rel);
    if (@rename($src, $dest) || (@copy($src, $dest) && @unlink($src))) {
        return true;
    }
    return false;
}

function restore_media(string $fn): bool {
    $fn = basename($fn);
    $src = trash_path('media') . '/' . $fn;
    if (!file_exists($src)) return false;

    if (!defined('MEDIA_PATH')) define('MEDIA_PATH', ROOT_PATH . '/media');
    $dest = MEDIA_PATH . '/' . $fn;
    if (@rename($src, $dest) || (@copy($src, $dest) && @unlink($src))) {
        return true;
    }
    return false;
}

function purge_media(string $fn): bool {
    $fn = basename($fn);
    $src = trash_path('media') . '/' . $fn;
    if (file_exists($src)) {
        return @unlink($src);
    }
    return false;
}

// ── Media Usage Scanner (Orphan / Unused detection) ───────────────────────
function media_scan_usages(): array {
    $usedMap = []; // filename => array of ['title' => ..., 'slug' => ..., 'type' => ...]
    $allTypes = ['articles', 'pages'];
    $scannedCount = 0;

    foreach ($allTypes as $type) {
        $dir = content_path($type);
        if (!is_dir($dir)) continue;
        $files = glob("$dir/*.json");
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            if (!$raw) continue;
            $post = json_decode($raw, true);
            if (!$post) continue;
            $scannedCount++;

            $title = $post['title'] ?? basename($f, '.json');
            $slug  = $post['slug'] ?? basename($f, '.json');

            // 1. Featured image
            if (!empty($post['featured_image'])) {
                $feat = basename(parse_url($post['featured_image'], PHP_URL_PATH) ?? $post['featured_image']);
                if ($feat) {
                    $usedMap[$feat][] = ['title' => $title, 'slug' => $slug, 'type' => $type, 'field' => 'Portada'];
                }
            }

            // 2. Images in content
            $content = $post['content'] ?? '';
            if ($content) {
                // HTML <img>
                if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
                    foreach ($m[1] as $imgUrl) {
                        $fn = basename(parse_url($imgUrl, PHP_URL_PATH) ?? $imgUrl);
                        if ($fn) {
                            $usedMap[$fn][] = ['title' => $title, 'slug' => $slug, 'type' => $type, 'field' => 'Cuerpo'];
                        }
                    }
                }
                // Markdown ![alt](url)
                if (preg_match_all('/!\[.*?\]\(([^)\s]+)\)/i', $content, $m)) {
                    foreach ($m[1] as $imgUrl) {
                        $fn = basename(parse_url($imgUrl, PHP_URL_PATH) ?? $imgUrl);
                        if ($fn) {
                            $usedMap[$fn][] = ['title' => $title, 'slug' => $slug, 'type' => $type, 'field' => 'Cuerpo'];
                        }
                    }
                }
            }
        }
    }

    return [
        'map'     => $usedMap,
        'scanned' => $scannedCount,
    ];
}


