<?php
// KutBlog - Configuration
define('CMS_VERSION', '2.3.23');
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

// ── Timezone & Localization ───────────────────────────────────────────────
function site_timezone(): string {
    $config = cms_config();
    $tz = $config['timezone'] ?? '';
    if ($tz && in_array($tz, timezone_identifiers_list(), true)) {
        return $tz;
    }
    return date_default_timezone_get() ?: 'UTC';
}

function cms_init_timezone(): void {
    $tz = site_timezone();
    if ($tz) {
        date_default_timezone_set($tz);
    }
}
cms_init_timezone();

function site_lang(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (!empty($_SESSION['admin_lang']) && in_array($_SESSION['admin_lang'], ['es', 'en'], true)) {
        return $_SESSION['admin_lang'];
    }
    $config = cms_config();
    if (!empty($config['language']) && in_array($config['language'], ['es', 'en'], true)) {
        return $config['language'];
    }
    return detect_admin_lang();
}

// ── i18n ──────────────────────────────────────────────────────────────────
function detect_admin_lang(): string {
    // 1. Explicit override via GET param (persisted in session)
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['es','en'], true)) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
        $_SESSION['admin_lang'] = $_GET['lang'];
        return $_GET['lang'];
    }
    // 2. Session preference
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
    if (!empty($_SESSION['admin_lang']) && in_array($_SESSION['admin_lang'], ['es', 'en'], true)) {
        return $_SESSION['admin_lang'];
    }
    // 3. Configured language
    $config = cms_config();
    if (!empty($config['language']) && in_array($config['language'], ['es', 'en'], true)) {
        return $config['language'];
    }
    // 4. Browser Accept-Language header
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
    return (stripos($accept, 'es') === 0 || strpos($accept, 'es-') !== false || strpos($accept, ',es') !== false) ? 'es' : 'en';
}

function month_name(int $month_num, bool $short = false, ?string $lang = null): string {
    $lang = $lang ?: site_lang();
    $m = max(1, min(12, $month_num));
    if ($lang === 'es') {
        $full = [1=>'enero', 2=>'febrero', 3=>'marzo', 4=>'abril', 5=>'mayo', 6=>'junio', 7=>'julio', 8=>'agosto', 9=>'septiembre', 10=>'octubre', 11=>'noviembre', 12=>'diciembre'];
        $short_list = [1=>'ene', 2=>'feb', 3=>'mar', 4=>'abr', 5=>'may', 6=>'jun', 7=>'jul', 8=>'ago', 9=>'sep', 10=>'oct', 11=>'nov', 12=>'dic'];
        return $short ? $short_list[$m] : $full[$m];
    } else {
        $full = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
        $short_list = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
        return $short ? $short_list[$m] : $full[$m];
    }
}

function day_name(int $weekday_num, bool $short = false, ?string $lang = null): string {
    $lang = $lang ?: site_lang();
    $w = (($weekday_num - 1) % 7) + 1;
    if ($w < 1) $w += 7;
    if ($lang === 'es') {
        $full = [1=>'lunes', 2=>'martes', 3=>'miércoles', 4=>'jueves', 5=>'viernes', 6=>'sábado', 7=>'domingo'];
        $short_list = [1=>'lun', 2=>'mar', 3=>'mié', 4=>'jue', 5=>'vie', 6=>'sáb', 7=>'dom'];
        return $short ? $short_list[$w] : $full[$w];
    } else {
        $full = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
        $short_list = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];
        return $short ? $short_list[$w] : $full[$w];
    }
}

function format_date(mixed $date, string $format = 'default', ?string $lang = null): string {
    if (empty($date)) return '';
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if ($ts === false) return '';

    $lang = $lang ?: site_lang();
    $day = (int)date('j', $ts);
    $month = (int)date('n', $ts);
    $year = date('Y', $ts);
    $time = date('H:i', $ts);

    $months_es = [1=>'enero', 2=>'febrero', 3=>'marzo', 4=>'abril', 5=>'mayo', 6=>'junio', 7=>'julio', 8=>'agosto', 9=>'septiembre', 10=>'octubre', 11=>'noviembre', 12=>'diciembre'];
    $months_es_short = [1=>'ene', 2=>'feb', 3=>'mar', 4=>'abr', 5=>'may', 6=>'jun', 7=>'jul', 8=>'ago', 9=>'sep', 10=>'oct', 11=>'nov', 12=>'dic'];
    $days_es = [1=>'lunes', 2=>'martes', 3=>'miércoles', 4=>'jueves', 5=>'viernes', 6=>'sábado', 7=>'domingo'];
    $days_es_short = [1=>'lun', 2=>'mar', 3=>'mié', 4=>'jue', 5=>'vie', 6=>'sáb', 7=>'dom'];

    $months_en = [1=>'January', 2=>'February', 3=>'March', 4=>'April', 5=>'May', 6=>'June', 7=>'July', 8=>'August', 9=>'September', 10=>'October', 11=>'November', 12=>'December'];
    $months_en_short = [1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr', 5=>'May', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec'];
    $days_en = [1=>'Monday', 2=>'Tuesday', 3=>'Wednesday', 4=>'Thursday', 5=>'Friday', 6=>'Saturday', 7=>'Sunday'];
    $days_en_short = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];

    if ($lang === 'es') {
        if ($format === 'default' || $format === 'full' || $format === 'long') {
            return "{$day} de {$months_es[$month]} de {$year}";
        }
        if ($format === 'datetime') {
            return "{$day} de {$months_es[$month]} de {$year}, {$time}";
        }
        if ($format === 'admin' || $format === 'short_datetime') {
            return "{$day} {$months_es_short[$month]}, {$year} {$time}";
        }
        if ($format === 'medium' || $format === 'short') {
            return "{$day} {$months_es_short[$month]}, {$year}";
        }
        if (in_array($format, ['F j, Y', 'F d, Y', 'j F, Y', 'd F, Y', 'j F Y', 'd F Y', 'd \d\e F, Y'], true)) {
            return "{$day} de {$months_es[$month]} de {$year}";
        }
        if (in_array($format, ['M j, Y', 'M d, Y', 'd M, Y', 'j M, Y', 'd M Y', 'j M Y', 'd M, Y.'], true)) {
            return "{$day} {$months_es_short[$month]}, {$year}";
        }
        if (in_array($format, ['M j, Y H:i', 'M d, Y H:i', 'd M, Y H:i', 'j M, Y H:i'], true)) {
            return "{$day} {$months_es_short[$month]}, {$year} {$time}";
        }
        $out = date($format, $ts);
        $out = str_replace(array_values($months_en), array_values($months_es), $out);
        $out = str_replace(array_values($months_en_short), array_values($months_es_short), $out);
        $out = str_replace(array_values($days_en), array_values($days_es), $out);
        $out = str_replace(array_values($days_en_short), array_values($days_es_short), $out);
        return $out;
    } else {
        if ($format === 'default' || $format === 'full' || $format === 'long') {
            return "{$months_en[$month]} {$day}, {$year}";
        }
        if ($format === 'datetime') {
            return "{$months_en[$month]} {$day}, {$year}, {$time}";
        }
        if ($format === 'admin' || $format === 'short_datetime') {
            return "{$months_en_short[$month]} {$day}, {$year} {$time}";
        }
        if ($format === 'medium' || $format === 'short') {
            return "{$months_en_short[$month]} {$day}, {$year}";
        }
        if (in_array($format, ['j \d\e F \d\e Y', 'd \d\e F \d\e Y'], true)) {
            return "{$months_en[$month]} {$day}, {$year}";
        }
        return date($format, $ts);
    }
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

