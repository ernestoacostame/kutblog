<?php
// ============================================================================
// KutBlog · API endpoint para actualizaciones
// ============================================================================
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/updater.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión admin y permisos
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado. Debes iniciar sesión como administrador.']);
    exit;
}
require_permission('manage_settings');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Todas las acciones excepto 'check' son mutantes: exigir POST + CSRF válido
if ($action !== 'check') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido. Se requiere POST.']);
        exit;
    }
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Error de verificación CSRF.']);
        exit;
    }
}

switch ($action) {

    // Guardar el Token de GitHub
    case 'save_token':
        $token = trim($_POST['token'] ?? '');
        cms_set_setting('update_github_token', $token);
        echo json_encode(['success' => true]);
        break;

    // Comprobar si hay actualizaciones disponibles
    case 'check':
        $result = cms_update_check();
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Descargar el ZIP del release
    case 'download':
        set_time_limit(300);
        $zipUrlRaw = $_POST['zip_url'] ?? '';
        $zipUrl = $zipUrlRaw ? base64_decode($zipUrlRaw) : '';
        
        if (!$zipUrl) {
            echo json_encode(['error' => 'Faltan parámetros (zip_url).']);
            break;
        }

        // Validar que la URL pertenezca a GitHub o al servidor oficial KutStudio
        if (!preg_match('#^https://(?:(?:api\.)?github\.com/|(?:www\.)?kutstudio\.(?:org|com)/)#i', $zipUrl)) {
            echo json_encode(['error' => 'URL de descarga no válida. Solo se permiten URLs de GitHub o KutStudio.']);
            break;
        }

        $dl = cms_update_download($zipUrl);
        echo json_encode($dl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Aplicar la actualización (creando un backup pre-update automático)
    case 'apply':
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        $zipPath = $_POST['zip_path'] ?? '';
        $newVersion = $_POST['new_version'] ?? '';

        if (!$zipPath || !$newVersion) {
            echo json_encode(['error' => 'Faltan parámetros (zip_path, new_version).']);
            break;
        }

        // Evitar path traversal en zip_path
        $realZipPath = realpath($zipPath);
        $realUpdateDir = realpath(cms_update_dir());
        if (!$realZipPath || !$realUpdateDir || !str_starts_with($realZipPath, $realUpdateDir)) {
            echo json_encode(['error' => 'Ruta del ZIP no válida o fuera del directorio de actualizaciones.']);
            break;
        }

        // Sanitizar versión
        $newVersion = preg_replace('/[^0-9.]/', '', $newVersion);

        $result = cms_update_apply($zipPath, $newVersion);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Subida manual de archivo ZIP para actualizar
    case 'upload':
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        if (empty($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['zip_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize en php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo permitido.',
                UPLOAD_ERR_PARTIAL    => 'El archivo solo se subió parcialmente.',
                UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal en el servidor.',
                UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco.',
                UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la subida.',
            ];
            echo json_encode(['error' => $errMap[$errCode] ?? 'Error al subir el archivo (código ' . $errCode . ').']);
            break;
        }

        $uploaded = $_FILES['zip_file'];
        $origName = $uploaded['name'] ?? '';
        if (!str_ends_with(strtolower($origName), '.zip')) {
            echo json_encode(['error' => 'El archivo subido debe tener extensión .zip']);
            break;
        }

        $destDir = cms_update_dir();
        $destPath = $destDir . '/manual-' . date('Ymd_His') . '.zip';
        if (!move_uploaded_file($uploaded['tmp_name'], $destPath)) {
            echo json_encode(['error' => 'No se pudo mover el archivo ZIP a ' . $destDir . '. Verifica permisos de escritura.']);
            break;
        }

        $result = cms_update_apply_zip($destPath);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Revertir a una versión guardada en el historial
    case 'rollback':
        set_time_limit(120);
        $backupFile = $_POST['backup_file'] ?? null;
        $result = cms_update_rollback($backupFile);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Eliminar copia de seguridad individual
    case 'delete_backup':
        $backupFile = $_POST['backup_file'] ?? '';
        if (!$backupFile) {
            echo json_encode(['error' => 'Falta el parámetro backup_file.']);
            break;
        }
        $result = cms_update_delete_backup($backupFile);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Eliminar copias de seguridad en lote
    case 'delete_backups_bulk':
        $rawFiles = $_POST['backup_files'] ?? '';
        $files = is_array($rawFiles) ? $rawFiles : (json_decode($rawFiles, true) ?: array_filter(explode(',', $rawFiles)));
        if (empty($files)) {
            echo json_encode(['error' => 'No se especificaron archivos de respaldo.']);
            break;
        }
        $deleted = 0;
        foreach ($files as $f) {
            $f = trim($f);
            if ($f && cms_update_delete_backup($f)['success'] ?? false) {
                $deleted++;
            }
        }
        echo json_encode(['success' => true, 'deleted_count' => $deleted], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Eliminar todos los respaldos de rollback
    case 'delete_all_backups':
        $rollbacks = cms_update_get_available_rollbacks();
        $deleted = 0;
        foreach ($rollbacks as $rb) {
            if (cms_update_delete_backup($rb['filename'])['success'] ?? false) {
                $deleted++;
            }
        }
        echo json_encode(['success' => true, 'deleted_count' => $deleted], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    // Limpiar historial de actualizaciones
    case 'clear_history':
        cms_set_setting('update_history', []);
        echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no reconocida: ' . $action]);
        break;
}
