<?php
// KutBlog - Media Folders AJAX API
require_once dirname(dirname(__DIR__)) . '/core/config.php';
require_once dirname(dirname(__DIR__)) . '/core/auth.php';
require_once __DIR__ . '/media-folders.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$csrf = $_POST['csrf'] ?? ($_GET['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'create_folder':
        $name = trim($_POST['name'] ?? '');
        $parentId = trim($_POST['parent_id'] ?? '') ?: null;
        $color = trim($_POST['color'] ?? '');
        if ($name === '') {
            echo json_encode(['ok' => false, 'error' => 'El nombre de la carpeta no puede estar vacío']);
            exit;
        }
        $folder = mf_create_folder($name, $parentId, $color);
        if ($folder) {
            echo json_encode(['ok' => true, 'folder' => $folder]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se pudo crear la carpeta']);
        }
        break;

    case 'rename_folder':
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($id === '' || $name === '') {
            echo json_encode(['ok' => false, 'error' => 'Parámetros incompletos']);
            exit;
        }
        $ok = mf_rename_folder($id, $name);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Error al renombrar la carpeta']);
        break;

    case 'delete_folder':
        $id = trim($_POST['id'] ?? '');
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'ID no proporcionado']);
            exit;
        }
        $ok = mf_delete_folder($id);
        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Error al eliminar la carpeta']);
        break;

    case 'move_file':
        $file = trim($_POST['file'] ?? '');
        $folderId = trim($_POST['folder_id'] ?? '');
        if ($file === '') {
            echo json_encode(['ok' => false, 'error' => 'Archivo no especificado']);
            exit;
        }
        $ok = mf_assign_file($file, $folderId);
        echo json_encode(['ok' => $ok, 'file' => $file, 'folder_id' => $folderId]);
        break;

    case 'batch_move':
        $files = $_POST['files'] ?? [];
        $folderId = trim($_POST['folder_id'] ?? '');
        if (!is_array($files)) {
            echo json_encode(['ok' => false, 'error' => 'Lista de archivos inválida']);
            exit;
        }
        $success = 0;
        foreach ($files as $f) {
            if (is_string($f) && trim($f) !== '') {
                if (mf_assign_file(trim($f), $folderId)) {
                    $success++;
                }
            }
        }
        echo json_encode(['ok' => true, 'moved' => $success]);
        break;

    case 'get_tree':
        echo json_encode([
            'ok' => true,
            'tree' => mf_get_tree(),
            'folders' => mf_get_folders(),
            'assignments' => mf_get_data()['assignments']
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
        break;
}
