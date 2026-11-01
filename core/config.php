<?php
// KutBlog - Configuration
define('CMS_VERSION', '2.3.21');
define('CMS_NAME', 'KutBlog');
define('ROOT_PATH', dirname(__DIR__));
define('CONTENT_PATH', ROOT_PATH . '/content');
define('THEMES_PATH', ROOT_PATH . '/themes');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('CACHE_PATH', ROOT_PATH . '/cache');
define('CONFIG_FILE', ROOT_PATH . '/config.json');
define('INSTALLED_FLAG', ROOT_PATH . '/.installed');
require_once __DIR__ . '/image_cache.php';
require_once __DIR__ . '/media.php';

function cms_config(): array {
    if (!file_exists(CONFIG_FILE)) return [];
    return json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
}

function cms_save_config(array $data): void {
    file_put_contents(CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function is_installed(): bool {
    return file_exists(INSTALLED_FLAG);
}

// Auto-detect base URL
function base_url(): string {
    $config = cms_config();
    return $config['base_url'] ?? '';
}

function site_title(): string {
    $config = cms_config();
    return $config['site_title'] ?? CMS_NAME;
}

function site_logo(): string {
    $config = cms_config();
    $logo = $config['site_logo'] ?? '';
    if (!$logo) return '';
    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }
    return rtrim(base_url(), '/') . '/' . ltrim($logo, '/');
}

function cms_save_logo(array $file): ?string {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/avif'    => 'avif',
        'image/svg+xml' => 'svg',
        'image/svg'     => 'svg',
    ];

    $ext = $allowed[$mime] ?? '';
    if (!$ext) {
        $origExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($origExt === 'svg') {
            $content = @file_get_contents($file['tmp_name']);
            if ($content && str_contains($content, '<svg') && !str_contains($content, '<?php')) {
                $ext = 'svg';
            }
        }
    }

    if (!$ext) {
        return null;
    }

    $dir = ROOT_PATH . '/media/logo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $filename = 'logo_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
    $targetPath = $dir . '/' . $filename;

    $moved = is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $targetPath)
        : @copy($file['tmp_name'], $targetPath);

    if ($moved) {
        $config = cms_config();
        $oldLogo = $config['site_logo'] ?? '';
        if ($oldLogo && str_starts_with($oldLogo, 'media/logo/')) {
            $oldFile = ROOT_PATH . '/' . $oldLogo;
            if (file_exists($oldFile) && is_file($oldFile) && realpath($oldFile) !== realpath($targetPath)) {
                @unlink($oldFile);
            }
        }
        return 'media/logo/' . $filename;
    }

    return null;
}

function cms_remove_logo(): void {
    $config = cms_config();
    $oldLogo = $config['site_logo'] ?? '';
    if ($oldLogo && str_starts_with($oldLogo, 'media/logo/')) {
        $oldFile = ROOT_PATH . '/' . $oldLogo;
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    $config['site_logo'] = '';
    cms_save_config($config);
}

function active_theme(): string {
    $config = cms_config();
    return $config['theme'] ?? 'default';
}

function theme_color(): string {
    $config = cms_config();
    return $config['theme_color'] ?? '#6366f1';
}

// ── i18n ──────────────────────────────────────────────────────────────────
function detect_admin_lang(): string {
    // 1. Explicit override via GET param (persisted in session)
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['es','en'])) {
        @session_start();
        $_SESSION['admin_lang'] = $_GET['lang'];
        return $_GET['lang'];
    }
    // 2. Session preference
    @session_start();
    if (!empty($_SESSION['admin_lang'])) return $_SESSION['admin_lang'];
    // 3. Browser Accept-Language header
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
    return (stripos($accept, 'es') === 0 || strpos($accept, 'es-') !== false || strpos($accept, ',es') !== false) ? 'es' : 'en';
}

function __( string $key, string $fallback = '' ): string {
    static $strings = null;
    if ($strings === null) {
        $lang    = detect_admin_lang();
        $file    = ROOT_PATH . "/core/lang/{$lang}.php";
        $strings = file_exists($file) ? require $file : [];
        // Always fall back to English for missing keys
        if ($lang !== 'en') {
            $en_file = ROOT_PATH . '/core/lang/en.php';
            $en      = file_exists($en_file) ? require $en_file : [];
            $strings = array_merge($en, $strings);
        }
    }
    return htmlspecialchars($strings[$key] ?? $fallback ?: $key, ENT_QUOTES, 'UTF-8');
}

// Raw (no htmlspecialchars) — for use in JS strings or known-safe values
function __raw( string $key, string $fallback = '' ): string {
    static $strings_raw = null;
    if ($strings_raw === null) {
        $lang    = detect_admin_lang();
        $file    = ROOT_PATH . "/core/lang/{$lang}.php";
        $strings_raw = file_exists($file) ? require $file : [];
        if ($lang !== 'en') {
            $en = ROOT_PATH . '/core/lang/en.php';
            $strings_raw = array_merge(file_exists($en) ? require $en : [], $strings_raw);
        }
    }
    return $strings_raw[$key] ?? $fallback ?: $key;
}

function cms_plugin_is_active(string $id): bool {
    $config = cms_config();
    $active = $config['active_plugins'] ?? [];
    return is_array($active) && in_array($id, $active, true);
}

function cms_load_plugins(): void {
    $config = cms_config();
    $active = $config['active_plugins'] ?? [];
    if (is_array($active)) {
        foreach ($active as $plugin) {
            $file = ROOT_PATH . "/plugins/{$plugin}/{$plugin}.php";
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}
cms_load_plugins();

