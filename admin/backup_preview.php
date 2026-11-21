<?php
// KutBlog - Backup Preview & Management endpoint
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$csrf = $_REQUEST['csrf'] ?? '';
if (!verify_csrf($csrf)) {
    echo json_encode(['success' => false, 'error' => 'Error de verificación CSRF.']); exit;
}

$type = in_array($_REQUEST['type'] ?? '', ['articles','pages']) ? $_REQUEST['type'] : 'articles';
$slug = basename(trim($_REQUEST['slug'] ?? ''));
$action = trim($_REQUEST['action'] ?? 'preview');

if (!$slug || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
    echo json_encode(['success' => false, 'error' => 'Parámetro slug inválido.']); exit;
}

$backup_dir = CACHE_PATH . '/content_backups/' . $type;

// Action: Clear all backups for this slug
if ($action === 'clear_all') {
    if (!is_dir($backup_dir)) {
        echo json_encode(['success' => true, 'count' => 0]); exit;
    }
    $files = glob($backup_dir . '/' . $slug . '_*.json');
    $count = 0;
    if ($files) {
        foreach ($files as $f) {
            if (@unlink($f)) $count++;
        }
    }
    echo json_encode(['success' => true, 'count' => $count]); exit;
}

// Action: Delete single backup
if ($action === 'delete') {
    $timestamp = basename(trim($_REQUEST['timestamp'] ?? ''));
    if (!$timestamp || !preg_match('/^[a-z0-9_-]+$/i', $timestamp)) {
        echo json_encode(['success' => false, 'error' => 'Timestamp inválido.']); exit;
    }
    $backup_file = $backup_dir . '/' . $slug . '_' . $timestamp . '.json';
    if (!file_exists($backup_file)) {
        echo json_encode(['success' => false, 'error' => 'Copia de seguridad no encontrada.']); exit;
    }
    if (@unlink($backup_file)) {
        echo json_encode(['success' => true, 'timestamp' => $timestamp]); exit;
    }
    echo json_encode(['success' => false, 'error' => 'No se pudo eliminar el archivo.']); exit;
}

// Action: Preview backup
$timestamp = basename(trim($_REQUEST['timestamp'] ?? ''));
if (!$timestamp || !preg_match('/^[a-z0-9_-]+$/i', $timestamp)) {
    echo json_encode(['success' => false, 'error' => 'Timestamp inválido.']); exit;
}

$backup_file = $backup_dir . '/' . $slug . '_' . $timestamp . '.json';
if (!file_exists($backup_file)) {
    echo json_encode(['success' => false, 'error' => 'Copia de seguridad no encontrada.']); exit;
}

$data = json_decode(file_get_contents($backup_file), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Archivo de respaldo corrupto o inválido.']); exit;
}

echo json_encode([
    'success' => true,
    'content' => $data['content'] ?? '',
    'title' => $data['title'] ?? '',
    'timestamp' => $timestamp
]);
