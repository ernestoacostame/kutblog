<?php
// KutBlog - Media Folders Plugin (Virtual Folders Taxonomy)
// Organiza archivos en carpetas y subcarpetas sin romper URLs públicas.

if (!defined('ROOT_PATH')) {
    require_once dirname(dirname(__DIR__)) . '/core/config.php';
}

function mf_data_file(): string {
    return CONTENT_PATH . '/media_folders.json';
}

/**
 * Obtiene toda la estructura de carpetas y asignaciones.
 */
function mf_get_data(): array {
    $file = mf_data_file();
    if (!file_exists($file)) {
        return [
            'folders' => [],
            'assignments' => []
        ];
    }
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return ['folders' => [], 'assignments' => []];
    }
    if (!isset($data['folders']) || !is_array($data['folders'])) {
        $data['folders'] = [];
    }
    if (!isset($data['assignments']) || !is_array($data['assignments'])) {
        $data['assignments'] = [];
    }
    return $data;
}

/**
 * Guarda los datos de carpetas y asignaciones de forma atómica.
 */
function mf_save_data(array $data): bool {
    $file = mf_data_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload = json_encode([
        'folders' => array_values($data['folders'] ?? []),
        'assignments' => empty($data['assignments']) ? new stdClass() : $data['assignments']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return @file_put_contents($file, $payload, LOCK_EX) !== false;
}

/**
 * Retorna la lista plana de carpetas.
 */
function mf_get_folders(): array {
    $data = mf_get_data();
    return $data['folders'];
}

/**
 * Retorna una carpeta por su ID o null si no existe.
 */
function mf_get_folder(string $id): ?array {
    foreach (mf_get_folders() as $f) {
        if ($f['id'] === $id) return $f;
    }
    return null;
}

/**
 * Retorna un árbol jerárquico de carpetas.
 */
function mf_get_tree(?string $parentId = null): array {
    $folders = mf_get_folders();
    $tree = [];
    foreach ($folders as $f) {
        $pId = $f['parent_id'] ?? null;
        if ($pId === $parentId || ($parentId === null && empty($pId))) {
            $f['children'] = mf_get_tree($f['id']);
            $tree[] = $f;
        }
    }
    return $tree;
}

/**
 * Crea una nueva carpeta virtual.
 */
function mf_create_folder(string $name, ?string $parentId = null, string $color = ''): ?array {
    $name = trim($name);
    if ($name === '') return null;

    $data = mf_get_data();
    $id = 'f_' . substr(md5(uniqid('', true)), 0, 8);

    // Validar parent_id si existe
    if ($parentId !== null && $parentId !== '') {
        $parentExists = false;
        foreach ($data['folders'] as $f) {
            if ($f['id'] === $parentId) {
                $parentExists = true;
                break;
            }
        }
        if (!$parentExists) $parentId = null;
    } else {
        $parentId = null;
    }

    $folder = [
        'id'         => $id,
        'name'       => $name,
        'parent_id'  => $parentId,
        'color'      => $color ?: '#ef4444',
        'created_at' => date('c')
    ];

    $data['folders'][] = $folder;
    if (mf_save_data($data)) {
        return $folder;
    }
    return null;
}

/**
 * Renombra una carpeta.
 */
function mf_rename_folder(string $id, string $newName): bool {
    $newName = trim($newName);
    if ($newName === '') return false;

    $data = mf_get_data();
    $found = false;
    foreach ($data['folders'] as &$f) {
        if ($f['id'] === $id) {
            $f['name'] = $newName;
            $found = true;
            break;
        }
    }
    unset($f);

    return $found ? mf_save_data($data) : false;
}

/**
 * Elimina una carpeta.
 * Reasigna sus subcarpetas al padre superior y desasigna sus archivos.
 */
function mf_delete_folder(string $id): bool {
    $data = mf_get_data();
    $parentId = null;
    $targetIndex = null;

    foreach ($data['folders'] as $idx => $f) {
        if ($f['id'] === $id) {
            $parentId = $f['parent_id'] ?? null;
            $targetIndex = $idx;
            break;
        }
    }

    if ($targetIndex === null) return false;

    // Eliminar la carpeta
    array_splice($data['folders'], $targetIndex, 1);

    // Mover subcarpetas al abuelo
    foreach ($data['folders'] as &$f) {
        if (($f['parent_id'] ?? null) === $id) {
            $f['parent_id'] = $parentId;
        }
    }
    unset($f);

    // Desasignar archivos pertenecientes a la carpeta eliminada
    foreach ($data['assignments'] as $rel => $fId) {
        if ($fId === $id) {
            unset($data['assignments'][$rel]);
        }
    }

    return mf_save_data($data);
}

/**
 * Obtiene la carpeta de un archivo por su ruta relativa.
 */
function mf_get_file_folder(string $rel): ?string {
    $data = mf_get_data();
    $rel = ltrim($rel, '/');
    return $data['assignments'][$rel] ?? null;
}

/**
 * Asigna un archivo a una carpeta (o lo desasigna si $folderId es null o vacío).
 */
function mf_assign_file(string $rel, ?string $folderId): bool {
    $data = mf_get_data();
    $rel = ltrim($rel, '/');

    if ($folderId === null || $folderId === '' || $folderId === 'all' || $folderId === 'unassigned') {
        unset($data['assignments'][$rel]);
    } else {
        // Verificar que la carpeta exista
        $exists = false;
        foreach ($data['folders'] as $f) {
            if ($f['id'] === $folderId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) return false;
        $data['assignments'][$rel] = $folderId;
    }

    return mf_save_data($data);
}

/**
 * Calcula conteo de archivos por carpeta.
 */
function mf_get_counts(array $files): array {
    $data = mf_get_data();
    $assignments = $data['assignments'];

    $counts = [
        '_all' => count($files),
        '_unassigned' => 0
    ];

    foreach ($data['folders'] as $f) {
        $counts[$f['id']] = 0;
    }

    foreach ($files as $f) {
        $rel = ltrim($f['rel'] ?? '', '/');
        $folderId = $assignments[$rel] ?? null;
        if ($folderId && isset($counts[$folderId])) {
            $counts[$folderId]++;
        } else {
            $counts['_unassigned']++;
        }
    }

    return $counts;
}

/**
 * Genera el HTML de las opciones `<option>` para selects de carpetas.
 */
function mf_render_folder_options(?string $selectedId = null, string $prefix = '', ?string $parentId = null): string {
    $tree = mf_get_tree($parentId);
    $html = '';

    foreach ($tree as $node) {
        $sel = ($selectedId === $node['id']) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($node['id']) . '"' . $sel . '>';
        $html .= htmlspecialchars($prefix . '📁 ' . $node['name']);
        $html .= '</option>';
        if (!empty($node['children'])) {
            $html .= mf_render_folder_options_recursive($node['children'], $selectedId, $prefix . '    ');
        }
    }

    return $html;
}

function mf_render_folder_options_recursive(array $children, ?string $selectedId, string $prefix): string {
    $html = '';
    foreach ($children as $child) {
        $sel = ($selectedId === $child['id']) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($child['id']) . '"' . $sel . '>';
        $html .= htmlspecialchars($prefix . '📂 ' . $child['name']);
        $html .= '</option>';
        if (!empty($child['children'])) {
            $html .= mf_render_folder_options_recursive($child['children'], $selectedId, $prefix . '    ');
        }
    }
    return $html;
}
