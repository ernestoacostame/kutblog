<?php
// KutBlog - Image Caching and Optimization Engine

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (!defined('CACHE_PATH')) {
    define('CACHE_PATH', ROOT_PATH . '/cache');
}
define('CACHE_IMAGES_PATH', CACHE_PATH . '/images');

/**
 * Ensures the cache directory exists and is protected.
 */
function image_cache_ensure_dir(): string {
    if (!is_dir(CACHE_IMAGES_PATH)) {
        @mkdir(CACHE_IMAGES_PATH, 0755, true);
    }
    // Prevent PHP execution inside cache folder
    $htaccess = CACHE_IMAGES_PATH . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<FilesMatch \"\\.php$\">\n    Order allow,deny\n    Deny from all\n</FilesMatch>\n");
    }
    return CACHE_IMAGES_PATH;
}

/**
 * Detect available image processing driver.
 */
function image_cache_driver(): string {
    if (is_executable('/usr/bin/gm')) {
        return 'graphicsmagick';
    }
    if (is_executable('/usr/bin/convert')) {
        return 'imagemagick';
    }
    if (function_exists('imagecreatetruecolor')) {
        return 'gd';
    }
    return 'none';
}

/**
 * Returns human-readable stats about the image cache.
 */
function image_cache_info(): array {
    $dir = image_cache_ensure_dir();
    $count = 0;
    $total_bytes = 0;

    if (is_dir($dir)) {
        $files = scandir($dir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
                $p = $dir . '/' . $f;
                if (is_file($p)) {
                    $count++;
                    $total_bytes += filesize($p);
                }
            }
        }
    }

    return [
        'count'  => $count,
        'bytes'  => $total_bytes,
        'size'   => $total_bytes > 1048576 ? round($total_bytes / 1048576, 2) . ' MB' : round($total_bytes / 1024, 1) . ' KB',
        'driver' => image_cache_driver(),
    ];
}

/**
 * Empties the image cache directory.
 */
function image_cache_clear(): int {
    $dir = image_cache_ensure_dir();
    $deleted = 0;
    if (is_dir($dir)) {
        $files = scandir($dir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
                $p = $dir . '/' . $f;
                if (is_file($p) && @unlink($p)) {
                    $deleted++;
                }
            }
        }
    }
    return $deleted;
}

/**
 * Builds an optimized cached image URL.
 */
function image_cache_url(string $src, int $width = 0, int $height = 0, int $quality = 82): string {
    if (empty($src)) return '';

    // If external URL without local media path, return as is
    $base = rtrim(function_exists('base_url') ? base_url() : '', '/');
    $clean = $src;

    // Strip base url if present
    if ($base && str_starts_with($clean, $base)) {
        $clean = substr($clean, strlen($base));
    }
    $clean = ltrim($clean, '/');

    // Only cache media / uploads
    if (!str_starts_with($clean, 'media/') && !str_starts_with($clean, 'uploads/')) {
        return $src;
    }

    $params = ['src' => $clean];
    if ($width > 0) $params['w'] = $width;
    if ($height > 0) $params['h'] = $height;
    if ($quality > 0 && $quality !== 82) $params['q'] = $quality;

    return $base . '/api/img.php?' . http_build_query($params);
}

/**
 * Resolves safe local path for a media file.
 */
function image_cache_resolve_path(string $src): ?string {
    $clean = ltrim(str_replace(['../', '..\\', "\0"], '', $src), '/');
    
    // Strip leading base or media/
    $candidates = [
        ROOT_PATH . '/' . $clean,
        ROOT_PATH . '/media/' . $clean,
        ROOT_PATH . '/uploads/' . $clean,
    ];

    foreach ($candidates as $cand) {
        $real = realpath($cand);
        if ($real && is_file($real)) {
            $allowed_media   = realpath(ROOT_PATH . '/media');
            $allowed_uploads = realpath(ROOT_PATH . '/uploads');
            if (($allowed_media && str_starts_with($real, $allowed_media)) ||
                ($allowed_uploads && str_starts_with($real, $allowed_uploads))) {
                return $real;
            }
        }
    }
    return null;
}

/**
 * Serves an optimized image with aggressive HTTP caching (ETag, 304 Not Modified, Cache-Control).
 */
function image_cache_serve(string $src, int $width = 0, int $height = 0, int $quality = 82): void {
    $real_path = image_cache_resolve_path($src);
    if (!$real_path || !is_file($real_path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Error 404: Imagen no encontrada.');
    }

    $ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
    $mtime = filemtime($real_path);

    // SVGs are vector: stream directly with 1-year cache and sandbox CSP
    if ($ext === 'svg') {
        $etag = '"svg-' . md5($real_path . '_' . $mtime) . '"';
        image_cache_check_client_cache($mtime, $etag);
        header('Content-Type: image/svg+xml');
        header('Content-Security-Policy: sandbox');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Content-Length: ' . filesize($real_path));
        readfile($real_path);
        exit;
    }

    $width   = max(0, min(3840, $width));
    if ($width > 0) {
        $allowed_widths = [320, 480, 640, 800, 960, 1280, 1600, 1920];
        $snapped = 1920;
        foreach ($allowed_widths as $aw) {
            if ($width <= $aw) {
                $snapped = $aw;
                break;
            }
        }
        $width = $snapped;
    }
    $height  = max(0, min(2160, $height));
    $quality = max(30, min(100, $quality ?: 82));

    // Check browser WebP support
    $accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
    $supports_webp = str_contains($accept_header, 'image/webp');
    $driver = image_cache_driver();
    $target_ext = ($supports_webp && in_array($driver, ['graphicsmagick', 'imagemagick', 'gd'])) ? 'webp' : ($ext === 'png' ? 'png' : 'jpg');

    $cache_dir = image_cache_ensure_dir();
    $cache_key = md5($real_path . '_' . $mtime . "_{$width}x{$height}_q{$quality}_" . $target_ext);
    $cache_file = $cache_dir . '/' . $cache_key . '.' . $target_ext;

    $etag = '"img-' . $cache_key . '"';
    image_cache_check_client_cache($mtime, $etag);

    // Generate cached image if missing or outdated
    if (!file_exists($cache_file) || filesize($cache_file) === 0) {
        $lock_file = $cache_file . '.lock';
        $lock_fp = @fopen($lock_file, 'c');
        if ($lock_fp) {
            @flock($lock_fp, LOCK_EX);
        }

        if (!file_exists($cache_file) || filesize($cache_file) === 0) {
            $ok = false;
            $tmp_file = $cache_dir . '/' . $cache_key . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp.' . $target_ext;

            // Resize / optimize using driver
            if ($driver === 'graphicsmagick') {
                $cmd = 'gm convert ' . escapeshellarg($real_path) . ' -auto-orient ';
                if ($width > 0 && $height > 0) {
                    $cmd .= '-resize ' . escapeshellarg("{$width}x{$height}^") . ' -gravity center -extent ' . escapeshellarg("{$width}x{$height}") . ' ';
                } elseif ($width > 0) {
                    $cmd .= '-resize ' . escapeshellarg((string)$width) . ' ';
                } elseif ($height > 0) {
                    $cmd .= '-resize ' . escapeshellarg("x{$height}") . ' ';
                }
                $cmd .= '+profile "*" -quality ' . (int)$quality . ' ' . escapeshellarg($tmp_file) . ' 2>&1';
                exec($cmd, $out, $ret);
                $ok = ($ret === 0 && file_exists($tmp_file) && filesize($tmp_file) > 0);
            } elseif ($driver === 'imagemagick') {
                $cmd = 'convert ' . escapeshellarg($real_path) . ' -auto-orient ';
                if ($width > 0 && $height > 0) {
                    $cmd .= '-resize ' . escapeshellarg("{$width}x{$height}^") . ' -gravity center -extent ' . escapeshellarg("{$width}x{$height}") . ' ';
                } elseif ($width > 0) {
                    $cmd .= '-resize ' . escapeshellarg((string)$width) . ' ';
                } elseif ($height > 0) {
                    $cmd .= '-resize ' . escapeshellarg("x{$height}") . ' ';
                }
                $cmd .= '-strip -quality ' . (int)$quality . ' ' . escapeshellarg($tmp_file) . ' 2>&1';
                exec($cmd, $out, $ret);
                $ok = ($ret === 0 && file_exists($tmp_file) && filesize($tmp_file) > 0);
            } elseif ($driver === 'gd' && function_exists('imagecreatefromstring')) {
                $ok = image_cache_resize_gd($real_path, $tmp_file, $width, $height, $quality, $target_ext);
            }

            if ($ok && file_exists($tmp_file) && filesize($tmp_file) > 0) {
                @chmod($tmp_file, 0644);
                if (!@rename($tmp_file, $cache_file)) {
                    @unlink($tmp_file);
                }
            } else {
                @unlink($tmp_file);
                // Fallback: copy original if conversion not feasible
                if (@copy($real_path, $tmp_file)) {
                    @chmod($tmp_file, 0644);
                    if (!@rename($tmp_file, $cache_file)) {
                        @unlink($tmp_file);
                    }
                }
                $target_ext = $ext;
            }
        }

        if ($lock_fp) {
            @flock($lock_fp, LOCK_UN);
            @fclose($lock_fp);
        }
    }

    $final_file = (file_exists($cache_file) && filesize($cache_file) > 0) ? $cache_file : $real_path;
    $final_mime = match ($target_ext) {
        'webp' => 'image/webp',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        default => 'image/jpeg',
    };

    header('Content-Type: ' . $final_mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Content-Length: ' . filesize($final_file));
    readfile($final_file);
    exit;
}

/**
 * Handle 304 Not Modified headers.
 */
function image_cache_check_client_cache(int $mtime, string $etag): void {
    $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
    $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;

    if (($if_none_match && $if_none_match === $etag) || ($if_modified_since && $if_modified_since >= $mtime)) {
        header('HTTP/1.1 304 Not Modified');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=31536000, immutable');
        exit;
    }
}

/**
 * Resize helper using PHP GD extension.
 */
function image_cache_resize_gd(string $src_path, string $dest_path, int $w, int $h, int $q, string $target_ext): bool {
    $raw = @file_get_contents($src_path);
    if (!$raw) return false;

    $src = @imagecreatefromstring($raw);
    if (!$src) return false;

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);
    if ($orig_w <= 0 || $orig_h <= 0) {
        imagedestroy($src);
        return false;
    }

    // Determine target dimensions
    if ($w > 0 && $h > 0) {
        $target_w = $w;
        $target_h = $h;
        // Crop center
        $ratio = max($target_w / $orig_w, $target_h / $orig_h);
        $crop_w = (int)round($target_w / $ratio);
        $crop_h = (int)round($target_h / $ratio);
        $crop_x = (int)round(($orig_w - $crop_w) / 2);
        $crop_y = (int)round(($orig_h - $crop_h) / 2);
    } elseif ($w > 0) {
        $target_w = $w;
        $target_h = (int)round(($orig_h / $orig_w) * $w);
        $crop_w = $orig_w; $crop_h = $orig_h;
        $crop_x = 0; $crop_y = 0;
    } elseif ($h > 0) {
        $target_h = $h;
        $target_w = (int)round(($orig_w / $orig_h) * $h);
        $crop_w = $orig_w; $crop_h = $orig_h;
        $crop_x = 0; $crop_y = 0;
    } else {
        $target_w = $orig_w;
        $target_h = $orig_h;
        $crop_w = $orig_w; $crop_h = $orig_h;
        $crop_x = 0; $crop_y = 0;
    }

    $dst = imagecreatetruecolor($target_w, $target_h);
    if ($target_ext === 'png' || $target_ext === 'webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, $crop_x, $crop_y, $target_w, $target_h, $crop_w, $crop_h);

    $saved = false;
    if ($target_ext === 'webp' && function_exists('imagewebp')) {
        $saved = imagewebp($dst, $dest_path, $q);
    } elseif ($target_ext === 'png' && function_exists('imagepng')) {
        $saved = imagepng($dst, $dest_path, 8);
    } elseif (function_exists('imagejpeg')) {
        $saved = imagejpeg($dst, $dest_path, $q);
    }

    imagedestroy($src);
    imagedestroy($dst);
    return $saved;
}
