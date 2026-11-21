<?php
// KutBlog - Image upload endpoint for editor
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
    $err = $_FILES['upload']['error'] ?? -1;
    echo json_encode(['error' => "Upload error code $err"]); exit;
}

$allowed_mime = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/svg+xml' => 'svg',
    'image/avif'    => 'avif',
];
$file = $_FILES['upload'];

// Validate mime type by actually reading the file
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$real_mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mime[$real_mime])) {
    echo json_encode(['error' => "Tipo no permitido: $real_mime"]); exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['error' => 'El archivo supera 10 MB']); exit;
}

$media_path = ROOT_PATH . '/media';
if (!is_dir($media_path)) mkdir($media_path, 0755, true);

// Build filename: date-based subfolder + sanitized name + MIME-derived extension
$date_dir   = $media_path . '/' . date('Y/m');
if (!is_dir($date_dir)) mkdir($date_dir, 0755, true);

$ext        = $allowed_mime[$real_mime];
$clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
$clean_name = trim(preg_replace('/-+/', '-', $clean_name), '-') ?: 'image';
$filename   = $clean_name . '-' . substr(uniqid(), -6) . '.' . $ext;
$dest       = $date_dir . '/' . $filename;

if ($real_mime === 'image/svg+xml') {
    $rawSvg = @file_get_contents($file['tmp_name']);
    $cleanSvg = $rawSvg !== false ? cms_sanitize_svg($rawSvg) : null;
    if (!$cleanSvg) {
        echo json_encode(['error' => 'SVG inválido o contiene elementos no permitidos']); exit;
    }
    if (@file_put_contents($dest, $cleanSvg) === false) {
        echo json_encode(['error' => 'No se pudo guardar el archivo']); exit;
    }
} elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['error' => 'No se pudo guardar el archivo']); exit;
}

$url = rtrim(base_url(), '/') . '/media/' . date('Y/m') . '/' . $filename;
echo json_encode(['url' => $url, 'filename' => $filename]);
