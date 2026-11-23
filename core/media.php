<?php
// KutBlog - Media Management & Metadata Storage

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

if (!function_exists('media_meta_file')) {
function media_meta_file(): string {
    return ROOT_PATH . '/content/media_metadata.json';
}
}

/**
 * Normalizes an absolute URL or path to a relative media path.
 * e.g. "http://example.com/media/2026/10/photo.jpg" -> "2026/10/photo.jpg"
 * e.g. "/media/2026/10/photo.jpg" -> "2026/10/photo.jpg"
 * e.g. "2026/10/photo.jpg" -> "2026/10/photo.jpg"
 */
function media_normalize_rel(string $path_or_url): string {
    $path = parse_url($path_or_url, PHP_URL_PATH) ?? $path_or_url;
    $path = str_replace('\\', '/', $path);
    
    // Strip everything up to /media/
    if (preg_match('#(?:^|/)media/(.+)$#i', $path, $m)) {
        $path = $m[1];
    }
    
    // Clean directory traversals
    $segs = explode('/', $path);
    $clean = array_filter($segs, fn($s) => $s !== '' && $s !== '.' && $s !== '..');
    return implode('/', $clean);
}

/**
 * Returns all media metadata indexed by relative file path.
 */
function media_get_all_meta(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $file = media_meta_file();
    if (!file_exists($file)) {
        $cache = [];
        return $cache;
    }

    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

/**
 * Retrieves metadata for a specific media relative path or URL.
 */
function media_get_meta(string $rel_or_url): array {
    $rel = media_normalize_rel($rel_or_url);
    if (!$rel) {
        return [
            'alt'         => '',
            'title'       => '',
            'caption'     => '',
            'description' => '',
            'updated_at'  => '',
        ];
    }

    $all = media_get_all_meta();
    $meta = $all[$rel] ?? [];

    return [
        'alt'         => (string)($meta['alt'] ?? ''),
        'title'       => (string)($meta['title'] ?? ''),
        'caption'     => (string)($meta['caption'] ?? ''),
        'description' => (string)($meta['description'] ?? ''),
        'updated_at'  => (string)($meta['updated_at'] ?? ''),
    ];
}

/**
 * Saves metadata for a media file.
 */
function media_save_meta(string $rel_or_url, array $data): bool {
    $rel = media_normalize_rel($rel_or_url);
    if (!$rel) return false;

    $file = media_meta_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $all = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $all = json_decode($raw, true) ?: [];
    }

    $all[$rel] = [
        'alt'         => trim((string)($data['alt'] ?? '')),
        'title'       => trim((string)($data['title'] ?? '')),
        'caption'     => trim((string)($data['caption'] ?? '')),
        'description' => trim((string)($data['description'] ?? '')),
        'updated_at'  => date('c'),
    ];

    $fp = @fopen($file, 'c+');
    if (!$fp) return false;

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    fclose($fp);
    return false;
}

/**
 * Removes metadata when a file is deleted.
 */
function media_delete_meta(string $rel_or_url): bool {
    $rel = media_normalize_rel($rel_or_url);
    if (!$rel) return false;

    $file = media_meta_file();
    if (!file_exists($file)) return true;

    $raw = @file_get_contents($file);
    $all = json_decode($raw, true) ?: [];

    if (!isset($all[$rel])) return true;

    unset($all[$rel]);
    file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return true;
}

/**
 * Renames a media file on disk and updates its metadata key.
 */
function media_rename_file(string $old_rel_or_url, string $new_name): array {
    $old_rel = media_normalize_rel($old_rel_or_url);
    if (!$old_rel) return ['error' => 'Ruta de archivo inválida.'];

    $old_path = MEDIA_PATH . '/' . $old_rel;
    if (!file_exists($old_path) || !is_file($old_path)) {
        return ['error' => 'El archivo no existe en disco.'];
    }

    $old_ext = strtolower(pathinfo($old_path, PATHINFO_EXTENSION));
    $dir = dirname($old_rel);

    // Sanitize new name
    $new_name = trim($new_name);
    // Remove directory traversal characters
    $new_name = str_replace(['/', '\\', '..'], '', $new_name);

    $clean_base = preg_replace('/[^a-zA-Z0-9_\-]/', '-', pathinfo($new_name, PATHINFO_FILENAME));
    $clean_base = trim(preg_replace('/-+/', '-', $clean_base), '-');
    if (!$clean_base) {
        return ['error' => 'El nombre del archivo no es válido.'];
    }
    $new_filename = $clean_base . '.' . $old_ext;

    $new_rel = ($dir === '.' || $dir === '') ? $new_filename : ($dir . '/' . $new_filename);

    if ($new_rel === $old_rel) {
        return [
            'success'  => true,
            'rel'      => $old_rel,
            'filename' => basename($old_rel),
            'url'      => base_url() . '/media/' . $old_rel,
            'renamed'  => false
        ];
    }

    $new_path = MEDIA_PATH . '/' . $new_rel;
    if (file_exists($new_path)) {
        return ['error' => 'Ya existe un archivo con ese nombre en esta carpeta.'];
    }

    if (!@rename($old_path, $new_path)) {
        return ['error' => 'No se pudo renombrar el archivo en el servidor.'];
    }

    // Migrate metadata
    $meta_file = media_meta_file();
    if (file_exists($meta_file)) {
        $raw = @file_get_contents($meta_file);
        $all = json_decode($raw, true) ?: [];
        if (isset($all[$old_rel])) {
            $all[$new_rel] = $all[$old_rel];
            unset($all[$old_rel]);
            file_put_contents($meta_file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }

    return [
        'success'  => true,
        'rel'      => $new_rel,
        'filename' => $new_filename,
        'url'      => base_url() . '/media/' . $new_rel,
        'renamed'  => true
    ];
}
