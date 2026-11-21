<?php
// KutBlog - Media upload endpoint (images, audio, video)
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']); exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    echo json_encode(['error' => 'Security error']); exit;
}

if (empty($_FILES['upload']['tmp_name']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
    $codes = [1=>'demasiado grande (php.ini)',2=>'demasiado grande (form)',3=>'parcial',4=>'sin archivo',6=>'sin carpeta tmp',7=>'no se puede escribir'];
    $err = $_FILES['upload']['error'] ?? -1;
    echo json_encode(['error' => 'Error de subida: ' . ($codes[$err] ?? "código $err")]); exit;
}

$file = $_FILES['upload'];

// Detect real MIME type from file content
$finfo     = finfo_open(FILEINFO_MIME_TYPE);
$real_mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = [
    // Images
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/svg+xml'   => 'svg',
    'image/avif'      => 'avif',
    // Audio
    'audio/mpeg'      => 'mp3',
    'audio/mp4'       => 'm4a',
    'audio/ogg'       => 'ogg',
    'audio/wav'       => 'wav',
    'audio/webm'      => 'weba',
    'audio/flac'      => 'flac',
    // Video
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogv',
    'video/quicktime' => 'mov',
];

if (!isset($allowed[$real_mime])) {
    echo json_encode(['error' => "Tipo no permitido: $real_mime. Soportados: imágenes, audio (mp3, ogg, wav, m4a) y vídeo (mp4, webm, ogv)"]); exit;
}

// Size limits: 10 MB images, 200 MB audio, 2 GB video
$ext  = $allowed[$real_mime];
$type = explode('/', $real_mime)[0]; // 'image', 'audio', 'video'

$max_sizes = ['image' => 10, 'audio' => 200, 'video' => 2048]; // MB
$max_bytes = ($max_sizes[$type] ?? 10) * 1024 * 1024;

if ($file['size'] > $max_bytes) {
    echo json_encode(['error' => "El archivo supera el límite de {$max_sizes[$type]} MB para {$type}"]); exit;
}

// Build destination path: /media/YYYY/MM/
$media_path = ROOT_PATH . '/media';
$date_dir   = $media_path . '/' . date('Y/m');
if (!is_dir($date_dir)) mkdir($date_dir, 0755, true);

$clean    = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
$clean    = trim(preg_replace('/-+/', '-', $clean), '-') ?: 'file';
$filename = $clean . '-' . substr(uniqid(), -6) . '.' . $ext;
$dest     = $date_dir . '/' . $filename;

if ($real_mime === 'image/svg+xml') {
    $rawSvg = @file_get_contents($file['tmp_name']);
    $cleanSvg = $rawSvg !== false ? cms_sanitize_svg($rawSvg) : null;
    if (!$cleanSvg) {
        echo json_encode(['error' => 'SVG inválido o contiene elementos no permitidos']); exit;
    }
    if (@file_put_contents($dest, $cleanSvg) === false) {
        echo json_encode(['error' => 'No se pudo guardar el archivo. Verifica los permisos de /media/']); exit;
    }
} elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['error' => 'No se pudo guardar el archivo. Verifica los permisos de /media/']); exit;
}

$url = rtrim(base_url(), '/') . '/media/' . date('Y/m') . '/' . $filename;
echo json_encode([
    'url'      => $url,
    'filename' => $filename,
    'type'     => $type,   // 'image', 'audio', 'video'
    'mime'     => $real_mime,
    'size'     => $file['size'],
]);
