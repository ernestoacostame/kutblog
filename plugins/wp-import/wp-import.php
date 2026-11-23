<?php
// WordPress Importer Plugin for KutBlog
// Main plugin loader & functions

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

if (!defined('MEDIA_PATH')) {
    define('MEDIA_PATH', ROOT_PATH . '/media');
}
if (!is_dir(MEDIA_PATH)) {
    @mkdir(MEDIA_PATH, 0755, true);
}

/**
 * Downloads an image referenced in WordPress export or post and saves it into /media/.
 */
function wp_download_image(string $src_url, string $base_url, array &$log): string {
    if (function_exists('cms_is_safe_remote_url') && !cms_is_safe_remote_url($src_url)) {
        $log[] = "  ⚠ URL bloqueada por seguridad: $src_url";
        return $src_url;
    }

    // Extract path preserving WP structure e.g. /wp-content/uploads/2025/02/img.jpg
    $parsed   = parse_url($src_url);
    $url_path = $parsed['path'] ?? '';

    // Keep only the part after /uploads/ (or full path if not WP uploads)
    if (preg_match('#/uploads/(.+)$#', $url_path, $m)) {
        $rel_path = $m[1]; // e.g. 2025/02/image.jpg
    } else {
        $rel_path = ltrim($url_path, '/');
    }

    // Sanitize each path segment and strip traversal segments
    $parts     = explode('/', str_replace('\\', '/', $rel_path));
    $parts     = array_values(array_filter(array_map(fn($p) => preg_replace('/[^a-zA-Z0-9._\-]/', '-', $p), $parts), fn($p) => $p !== '' && $p !== '.' && $p !== '..'));
    if (empty($parts)) return $src_url;
    $rel_path  = implode('/', $parts);

    $media_path = defined('MEDIA_PATH') ? MEDIA_PATH : ROOT_PATH . '/media';
    $dest_dir   = $media_path . '/' . dirname($rel_path);
    $dest_file  = $media_path . '/' . $rel_path;
    $local_url  = $base_url . '/media/' . $rel_path;

    // Already downloaded
    if (file_exists($dest_file)) return $local_url;

    // Create directory structure
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $realMedia = realpath($media_path);
    $realDestDir = realpath($dest_dir);
    if (!$realMedia || !$realDestDir || ($realDestDir !== $realMedia && !str_starts_with($realDestDir, $realMedia . DIRECTORY_SEPARATOR))) {
        return $src_url;
    }

    // Download with timeout (no auto-redirects for SSRF safety)
    $ctx  = stream_context_create(['http' => [
        'timeout'          => 20,
        'follow_location'  => false,
        'ignore_errors'    => true,
        'header'           => "User-Agent: KutBlog/1.0 (compatible)\r\n",
    ]]);
    $data = @file_get_contents($src_url, false, $ctx);

    if ($data === false || strlen($data) < 100) {
        $log[] = "  ⚠ No se pudo descargar: $src_url";
        return $src_url; // leave original URL on failure
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_buffer($finfo, $data) : '';
    if ($finfo) finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'];
    if (!in_array($mime, $allowedMimes, true)) {
        $log[] = "  ⚠ Tipo de archivo no permitido ($mime): $src_url";
        return $src_url;
    }
    if ($mime === 'image/svg+xml' && function_exists('cms_sanitize_svg')) {
        $data = cms_sanitize_svg($data);
    }

    file_put_contents($dest_file, $data);
    $log[] = "  ↓ Imagen: $rel_path (" . round(strlen($data)/1024) . " KB)";
    return $local_url;
}

/**
 * Rewrites external image URLs in HTML content to local /media/ URLs.
 */
function wp_rewrite_images(string $html, string $base_url, array &$log): string {
    return preg_replace_callback(
        '/(<img[^>]+\ssrc=")([^"]+)("[^>]*>)/i',
        function($m) use ($base_url, &$log) {
            $src = $m[2];
            // Only rewrite external absolute URLs
            if (!preg_match('#^https?://#i', $src)) return $m[0];
            // Skip if already pointing to our domain
            $host = parse_url($base_url, PHP_URL_HOST) ?? '';
            if ($host && str_contains($src, $host)) return $m[0];
            $new_src = wp_download_image($src, $base_url, $log);
            return $m[1] . $new_src . $m[3];
        },
        $html
    );
}

/**
 * Builds attachment ID -> URL map for featured images from WP export channel.
 */
function wp_build_attachment_map(object $channel): array {
    $map = [];
    foreach ($channel->item as $item) {
        $wp = $item->children('wp', true);
        if ((string)$wp->post_type !== 'attachment') continue;
        $id  = (string)$wp->post_id;
        $url = (string)$wp->attachment_url;
        if (!$url) $url = (string)$item->guid;
        if ($id && $url) $map[$id] = $url;
    }
    return $map;
}

/**
 * Imports content from WordPress XML (WXR export file).
 */
function import_wordpress_xml(string $xml_content, bool $download_images = true): array {
    $log      = [];
    $base_url = rtrim(base_url(), '/');

    try {
        // Fix common encoding issues in WP exports
        $xml_content = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u',
            '', $xml_content
        );

        libxml_use_internal_errors(true);
        $xml = new SimpleXMLElement($xml_content, LIBXML_NOCDATA);
        $xml->registerXPathNamespace('wp',      'http://wordpress.org/export/1.2/');
        $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xml->registerXPathNamespace('dc',      'http://purl.org/dc/elements/1.1/');
        $xml->registerXPathNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');

        $channel     = $xml->channel;
        $attach_map  = wp_build_attachment_map($channel); // id -> url map for featured images
        $imported    = 0;
        $skipped     = 0;
        $img_count   = 0;

        foreach ($channel->item as $item) {
            $wp         = $item->children('wp', true);
            $content_ns = $item->children('http://purl.org/rss/1.0/modules/content/');
            $excerpt_ns = $item->children('http://wordpress.org/export/1.2/excerpt/');

            $post_type = (string)$wp->post_type;
            $status    = (string)$wp->status;

            // Skip attachments, nav menu items, etc.
            if (!in_array($post_type, ['post', 'page'])) { $skipped++; continue; }

            $type  = $post_type === 'post' ? 'articles' : 'pages';
            $title = html_entity_decode((string)$item->title, ENT_QUOTES, 'UTF-8');
            $slug  = (string)$wp->post_name ?: slug_from_title($title);
            $body  = (string)$content_ns->encoded;
            $exc   = strip_tags((string)$excerpt_ns->encoded);
            $date  = (string)$wp->post_date;

            // Remove Gutenberg block comments (<!-- wp:paragraph --> etc.)
            $body = preg_replace('/<!--\s*wp:[a-zA-Z\/\-]+(\s+\{[^}]*\})?\s*-->/s', '', $body);
            $body = preg_replace('/<!--\s*\/wp:[a-zA-Z\/\-]+\s*-->/s', '', $body);

            // Unwrap empty figure wrappers left by Gutenberg, keep img inside
            $body = preg_replace('/<figure[^>]*>\s*(<img[^>]+>)\s*<\/figure>/i', '$1', $body);
            $body = trim($body);

            // ── Download images referenced in content ──────────────────
            $img_before = $img_count;
            if ($download_images && $body) {
                $img_log = [];
                $body    = wp_rewrite_images($body, $base_url, $img_log);
                $log     = array_merge($log, $img_log);
                $img_count += count($img_log);
            }

            // ── Featured image from WP meta ────────────────────────────
            $featured      = '';
            $thumbnail_id  = '';
            foreach ($wp->postmeta as $meta) {
                if ((string)$meta->meta_key === '_thumbnail_id') {
                    $thumbnail_id = (string)$meta->meta_value;
                    break;
                }
            }
            if ($thumbnail_id && isset($attach_map[$thumbnail_id])) {
                $feat_src = $attach_map[$thumbnail_id];
                if ($download_images) {
                    $img_log  = [];
                    $featured = wp_download_image($feat_src, $base_url, $img_log);
                    $log      = array_merge($log, $img_log);
                } else {
                    $featured = $feat_src;
                }
            }

            // ── Categories and tags ───────────────────────────────────
            $categories = [];
            $tags       = [];
            foreach ($item->category as $cat) {
                $domain = (string)$cat['domain'];
                $val    = html_entity_decode((string)$cat, ENT_QUOTES, 'UTF-8');
                if ($domain === 'category' && $val && $val !== 'Uncategorized') $categories[] = $val;
                if ($domain === 'post_tag' && $val) $tags[] = $val;
            }

            save_content($type, [
                'title'          => $title,
                'slug'           => $slug,
                'content'        => $body,
                'excerpt'        => $exc,
                'status'         => $status === 'publish' ? 'published' : 'draft',
                'categories'     => array_values(array_unique($categories)),
                'tags'           => array_values(array_unique($tags)),
                'featured_image' => $featured,
                'created_at'     => $date ?: date('c'),
                'wp_import'      => true,
            ]);

            $imgs_this = $img_count - $img_before;
            $img_str   = $imgs_this > 0 ? " ($imgs_this img)" : '';
            $log[]     = "✓ $type: $title$img_str";
            $imported++;
        }

        $log[] = "---";
        $log[] = "✅ Completado: $imported importados, $skipped omitidos, $img_count imágenes descargadas.";

    } catch (Exception $e) {
        $log[] = "❌ Error: " . $e->getMessage();
    }
    return $log;
}

/**
 * Downloads a single image from external URL to /media/.
 */
function wp_import_download_image(string $url): ?string {
    if (!preg_match('#^https?://#i', $url)) return null;
    if (function_exists('cms_is_safe_remote_url') && !cms_is_safe_remote_url($url)) return null;

    $parsed   = parse_url($url);
    $url_path = $parsed['path'] ?? '';

    if (preg_match('#/uploads/(.+)$#', $url_path, $m)) {
        $rel_path = $m[1];
    } else {
        $rel_path = ltrim($url_path, '/');
    }

    $parts    = explode('/', str_replace('\\', '/', $rel_path));
    $parts    = array_values(array_filter(array_map(fn($p) => preg_replace('/[^a-zA-Z0-9._\-]/', '-', $p), $parts), fn($p) => $p !== '' && $p !== '.' && $p !== '..'));
    if (empty($parts)) return null;
    $rel_path = implode('/', $parts);

    $media_path = defined('MEDIA_PATH') ? MEDIA_PATH : ROOT_PATH . '/media';
    $dest_dir   = $media_path . '/' . dirname($rel_path);
    $dest_file  = $media_path . '/' . $rel_path;

    if (file_exists($dest_file)) return $rel_path;
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $realMedia = realpath($media_path);
    $realDestDir = realpath($dest_dir);
    if (!$realMedia || !$realDestDir || ($realDestDir !== $realMedia && !str_starts_with($realDestDir, $realMedia . DIRECTORY_SEPARATOR))) {
        return null;
    }

    $ctx  = stream_context_create(['http' => [
        'timeout'         => 15,
        'follow_location' => false,
        'header'          => "User-Agent: KutBlog/1.0\r\n",
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 100) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_buffer($finfo, $data) : '';
    if ($finfo) finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'];
    if (!in_array($mime, $allowedMimes, true)) return null;
    if ($mime === 'image/svg+xml' && function_exists('cms_sanitize_svg')) {
        $data = cms_sanitize_svg($data);
    }

    file_put_contents($dest_file, $data);
    return $rel_path;
}

/**
 * Scans all existing articles and pages and downloads any external images to /media/.
 */
function wp_import_process_all_images(): array {
    @set_time_limit(300);
    $base_url = rtrim(base_url(), '/');
    $count    = 0;
    $log      = [];

    foreach (['articles', 'pages'] as $type) {
        $dir = CONTENT_PATH . '/' . $type;
        if (!is_dir($dir)) continue;

        foreach (glob("$dir/*.json") as $file) {
            $post = json_decode(file_get_contents($file), true);
            if (empty($post['content'])) continue;

            $changed = false;

            // Rewrite <img src="..."> tags
            $post['content'] = preg_replace_callback(
                '/(<img[^>]+\ssrc=")([^"]+)("[^>]*>)/i',
                function($m) use ($base_url, &$count, &$changed) {
                    $src = $m[2];
                    if (!preg_match('#^https?://#i', $src)) return $m[0];
                    $host = parse_url($base_url, PHP_URL_HOST) ?? '';
                    if ($host && str_contains($src, $host)) return $m[0];
                    $rel = wp_import_download_image($src);
                    if (!$rel) return $m[0];
                    $changed = true;
                    $count++;
                    return $m[1] . $base_url . '/media/' . $rel . $m[3];
                },
                $post['content']
            );

            // Rewrite featured_image
            if (!empty($post['featured_image'])) {
                $src = $post['featured_image'];
                $host = parse_url($base_url, PHP_URL_HOST) ?? '';
                if (preg_match('#^https?://#i', $src) && !str_contains($src, $host)) {
                    $rel = wp_import_download_image($src);
                    if ($rel) {
                        $post['featured_image'] = $base_url . '/media/' . $rel;
                        $changed = true;
                        $count++;
                    }
                }
            }

            if ($changed) {
                file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $log[] = ['ok', "Actualizado: " . ($post['title'] ?? basename($file))];
            }
        }
    }

    if (function_exists('clear_cache')) {
        clear_cache();
    }
    $log[] = ['done', "Completado. $count imágenes descargadas y URLs reescritas."];
    return ['count' => $count, 'log' => $log];
}
