<?php
// KutBlog - Configuration
define('CMS_VERSION', '2.3.45');
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

/**
 * Resize raster image using available system driver (GD, GraphicsMagick, ImageMagick, FFmpeg).
 */
function cms_resize_image_file(string $srcPath, string $destPath, int $maxW = 128, int $maxH = 128, string $targetFormat = 'png'): bool {
    // 1. Try PHP GD extension if available
    if (function_exists('imagecreatefromstring')) {
        $raw = @file_get_contents($srcPath);
        if ($raw) {
            $src = @imagecreatefromstring($raw);
            if ($src) {
                $orig_w = imagesx($src);
                $orig_h = imagesy($src);
                if ($orig_w > 0 && $orig_h > 0) {
                    $ratio = min($maxW / $orig_w, $maxH / $orig_h);
                    $new_w = max(1, (int)round($orig_w * $ratio));
                    $new_h = max(1, (int)round($orig_h * $ratio));
                    $dst = imagecreatetruecolor($new_w, $new_h);
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefilledrectangle($dst, 0, 0, $new_w, $new_h, $transparent);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $saved = imagepng($dst, $destPath, 8);
                    imagedestroy($src);
                    imagedestroy($dst);
                    if ($saved && file_exists($destPath) && filesize($destPath) > 0) return true;
                }
            }
        }
    }

    // 2. Try GraphicsMagick (gm)
    $gm = is_executable('/usr/bin/gm') ? '/usr/bin/gm' : (trim((string)@shell_exec('which gm 2>/dev/null')) ?: '');
    if ($gm) {
        $cmd = escapeshellarg($gm) . ' convert ' . escapeshellarg($srcPath) . ' -background none -resize ' . escapeshellarg("{$maxW}x{$maxH}") . ' ' . escapeshellarg($destPath) . ' 2>&1';
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($destPath) && filesize($destPath) > 0) return true;
    }

    // 3. Try ImageMagick (convert / magick)
    $im = is_executable('/usr/bin/convert') ? '/usr/bin/convert' : (trim((string)@shell_exec('which convert 2>/dev/null')) ?: '');
    if (!$im) $im = is_executable('/usr/bin/magick') ? '/usr/bin/magick' : (trim((string)@shell_exec('which magick 2>/dev/null')) ?: '');
    if ($im) {
        $cmd = escapeshellarg($im) . ' ' . escapeshellarg($srcPath) . ' -background none -resize ' . escapeshellarg("{$maxW}x{$maxH}") . ' ' . escapeshellarg($destPath) . ' 2>&1';
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($destPath) && filesize($destPath) > 0) return true;
    }

    // 4. Try FFmpeg
    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : (trim((string)@shell_exec('which ffmpeg 2>/dev/null')) ?: '');
    if ($ffmpeg) {
        $cmd = escapeshellarg($ffmpeg) . ' -y -v error -i ' . escapeshellarg($srcPath) . ' -vf ' . escapeshellarg("scale={$maxW}:{$maxH}:force_original_aspect_ratio=decrease") . ' ' . escapeshellarg($destPath) . ' 2>&1';
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($destPath) && filesize($destPath) > 0) return true;
    }

    return @copy($srcPath, $destPath);
}

/**
 * Creates an official MS Windows ICO file wrapping PNG data.
 */
function cms_generate_ico(string $pngPath, string $icoPath, int $w = 128, int $h = 128): bool {
    $pngData = @file_get_contents($pngPath);
    if (!$pngData) return false;
    $size = strlen($pngData);
    $header = pack('vvv', 0, 1, 1);
    $entry = pack('CCCCvvVV', $w >= 256 ? 0 : $w, $h >= 256 ? 0 : $h, 0, 0, 1, 32, $size, 22);
    return (bool)@file_put_contents($icoPath, $header . $entry . $pngData);
}

function site_favicon(): string {
    $config = cms_config();
    $fav = $config['site_favicon'] ?? '';
    if ($fav) {
        if (preg_match('#^https?://#i', $fav)) {
            return $fav;
        }
        $localPath = ROOT_PATH . '/' . ltrim($fav, '/');
        // Auto-downscale oversized legacy raster favicons (e.g. 2750x2750)
        if (file_exists($localPath) && is_file($localPath) && !str_ends_with(strtolower($localPath), '.svg')) {
            $info = @getimagesize($localPath);
            if ($info && ($info[0] > 192 || $info[1] > 192)) {
                cms_resize_image_file($localPath, $localPath, 128, 128, 'png');
                $icoPath = preg_replace('/\.[a-z0-9]+$/i', '.ico', $localPath);
                cms_generate_ico($localPath, $icoPath, 128, 128);
            }
        }
        return rtrim(base_url(), '/') . '/' . ltrim($fav, '/');
    }
    return rtrim(base_url(), '/') . '/media/favicon/default.svg';
}

function site_favicon_mime(string $url = ''): string {
    $url = $url ?: site_favicon();
    $path = parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION));
    return match ($ext) {
        'svg'        => 'image/svg+xml',
        'png'        => 'image/png',
        'ico'        => 'image/x-icon',
        'webp'       => 'image/webp',
        'gif'        => 'image/gif',
        'jpg','jpeg' => 'image/jpeg',
        default      => 'image/x-icon',
    };
}

function cms_favicon_tags(): string {
    $url = site_favicon();
    $mime = site_favicon_mime($url);
    $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $baseUrl = rtrim(base_url(), '/');

    // Corresponding ICO URL if available
    $icoUrl = '';
    $config = cms_config();
    $fav = $config['site_favicon'] ?? '';
    if ($fav) {
        $candidateIco = preg_replace('/\.[a-z0-9]+$/i', '.ico', $fav);
        if (file_exists(ROOT_PATH . '/' . ltrim($candidateIco, '/'))) {
            $icoUrl = htmlspecialchars($baseUrl . '/' . ltrim($candidateIco, '/'), ENT_QUOTES, 'UTF-8');
        }
    }
    if (!$icoUrl) {
        $icoUrl = htmlspecialchars($baseUrl . '/media/favicon/default.ico', ENT_QUOTES, 'UTF-8');
    }

    $html = '';
    if ($mime === 'image/svg+xml') {
        $html .= '<link rel="icon" type="image/svg+xml" href="' . $escapedUrl . '">' . "\n";
        $html .= '<link rel="alternate icon" type="image/x-icon" href="' . $icoUrl . '">' . "\n";
        $pngFallback = preg_replace('/\.svg$/i', '.png', $fav);
        if ($fav && file_exists(ROOT_PATH . '/' . ltrim($pngFallback, '/'))) {
            $pngUrl = htmlspecialchars($baseUrl . '/' . ltrim($pngFallback, '/'), ENT_QUOTES, 'UTF-8');
        } else {
            $pngUrl = htmlspecialchars($baseUrl . '/media/favicon/default.png', ENT_QUOTES, 'UTF-8');
        }
        $html .= '<link rel="apple-touch-icon" href="' . $pngUrl . '">' . "\n";
    } else {
        $html .= '<link rel="icon" type="' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . '" sizes="32x32" href="' . $escapedUrl . '">' . "\n";
        $html .= '<link rel="shortcut icon" href="' . ($icoUrl ?: $escapedUrl) . '">' . "\n";
        if ($icoUrl && $icoUrl !== $escapedUrl) {
            $html .= '<link rel="alternate icon" type="image/x-icon" href="' . $icoUrl . '">' . "\n";
        }
        $html .= '<link rel="apple-touch-icon" href="' . $escapedUrl . '">' . "\n";
    }
    return $html;
}

function cms_save_favicon(array $file): ?string {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/x-icon'               => 'ico',
        'image/vnd.microsoft.icon'  => 'ico',
        'image/png'                  => 'png',
        'image/svg+xml'              => 'svg',
        'image/svg'                  => 'svg',
        'image/webp'                 => 'webp',
        'image/gif'                  => 'gif',
        'image/jpeg'                 => 'jpg',
    ];

    $ext = $allowed[$mime] ?? '';
    $origExt = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    if (!$ext) {
        if ($origExt === 'ico') {
            $ext = 'ico';
        } elseif ($origExt === 'svg') {
            $content = @file_get_contents($file['tmp_name']);
            if ($content && str_contains($content, '<svg') && !str_contains($content, '<?php')) {
                $ext = 'svg';
            }
        }
    }

    if (!$ext || !in_array($ext, ['ico', 'png', 'svg', 'webp', 'gif', 'jpg'])) {
        return null;
    }

    $dir = ROOT_PATH . '/media/favicon';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $hash = substr(md5(uniqid()), 0, 8);

    if ($ext === 'svg') {
        $filename = 'favicon_' . $hash . '.svg';
        $targetPath = $dir . '/' . $filename;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $targetPath)
            : @copy($file['tmp_name'], $targetPath);
        if (!$moved) return null;

        // Try to generate a 128x128 PNG counterpart and ICO counterpart
        $pngPath = $dir . '/favicon_' . $hash . '.png';
        $icoPath = $dir . '/favicon_' . $hash . '.ico';
        if (cms_resize_image_file($targetPath, $pngPath, 128, 128, 'png')) {
            cms_generate_ico($pngPath, $icoPath, 128, 128);
        }
    } else {
        // Any raster format (PNG, JPG, WebP, GIF, ICO) -> resize to 128x128 PNG
        $filename = 'favicon_' . $hash . '.png';
        $targetPath = $dir . '/' . $filename;
        $icoPath = $dir . '/favicon_' . $hash . '.ico';

        $resized = cms_resize_image_file($file['tmp_name'], $targetPath, 128, 128, 'png');
        if (!$resized) {
            $moved = is_uploaded_file($file['tmp_name'])
                ? move_uploaded_file($file['tmp_name'], $targetPath)
                : @copy($file['tmp_name'], $targetPath);
            if (!$moved) return null;
        }

        // Generate official .ico wrapper
        cms_generate_ico($targetPath, $icoPath, 128, 128);
    }

    // Clean up old custom favicons (excluding default.svg / default.ico)
    $config = cms_config();
    $oldFav = $config['site_favicon'] ?? '';
    if ($oldFav && str_starts_with($oldFav, 'media/favicon/') && !str_contains($oldFav, 'default.')) {
        $oldFile = ROOT_PATH . '/' . $oldFav;
        if (file_exists($oldFile) && is_file($oldFile) && realpath($oldFile) !== realpath($targetPath)) {
            @unlink($oldFile);
        }
        $oldIco = preg_replace('/\.[a-z0-9]+$/i', '.ico', $oldFile);
        if (file_exists($oldIco) && is_file($oldIco) && realpath($oldIco) !== realpath($icoPath ?? '')) {
            @unlink($oldIco);
        }
    }

    return 'media/favicon/' . $filename;
}

function cms_remove_favicon(): void {
    $config = cms_config();
    $oldFav = $config['site_favicon'] ?? '';
    if ($oldFav && str_starts_with($oldFav, 'media/favicon/') && !str_contains($oldFav, 'default.')) {
        $oldFile = ROOT_PATH . '/' . $oldFav;
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
        $oldIco = preg_replace('/\.[a-z0-9]+$/i', '.ico', $oldFile);
        if (file_exists($oldIco) && is_file($oldIco)) {
            @unlink($oldIco);
        }
    }
    $config['site_favicon'] = '';
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
    if (!empty($_COOKIE['kutblog_lang']) && in_array($_COOKIE['kutblog_lang'], ['es', 'en'], true)) {
        return $_COOKIE['kutblog_lang'];
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
    // 1. Explicit override via GET param (persisted in session and cookie)
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['es','en'], true)) {
        $lang = $_GET['lang'];
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
        $_SESSION['admin_lang'] = $lang;
        $_COOKIE['kutblog_lang'] = $lang;
        if (!headers_sent()) {
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                       (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                       (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') ||
                       (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            setcookie('kutblog_lang', $lang, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => false,
                'samesite' => 'Lax'
            ]);
        }
        return $lang;
    }
    // 2. Cookie preference
    if (!empty($_COOKIE['kutblog_lang']) && in_array($_COOKIE['kutblog_lang'], ['es', 'en'], true)) {
        return $_COOKIE['kutblog_lang'];
    }
    // 3. Session preference
    if (session_status() === PHP_SESSION_NONE && !headers_sent() && isset($_COOKIE[session_name()])) @session_start();
    if (!empty($_SESSION['admin_lang']) && in_array($_SESSION['admin_lang'], ['es', 'en'], true)) {
        return $_SESSION['admin_lang'];
    }
    // 4. Configured language
    $config = cms_config();
    if (!empty($config['language']) && in_array($config['language'], ['es', 'en'], true)) {
        return $config['language'];
    }
    // 5. Browser Accept-Language header
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

function __( string $key, $fallback = '', array $replace = [] ): string {
    if (is_array($fallback)) {
        $replace = $fallback;
        $fallback = '';
    }
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
    $text = $strings[$key] ?? ((string)$fallback !== '' ? (string)$fallback : $key);
    if (!empty($replace)) {
        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, (string)$v, $text);
        }
    }
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Raw (no htmlspecialchars) — for use in JS strings or known-safe values
function __raw( string $key, $fallback = '', array $replace = [] ): string {
    if (is_array($fallback)) {
        $replace = $fallback;
        $fallback = '';
    }
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
    $text = $strings_raw[$key] ?? ((string)$fallback !== '' ? (string)$fallback : $key);
    if (!empty($replace)) {
        foreach ($replace as $k => $v) {
            $text = str_replace(':' . $k, (string)$v, $text);
        }
    }
    return $text;
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

