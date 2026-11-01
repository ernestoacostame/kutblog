<?php
/**
 * Plugin Series - API Endpoint para AJAX
 */

require_once dirname(__DIR__, 2) . '/core/config.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/content.php';
require_once __DIR__ . '/series.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$csrf = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

switch ($action) {
    case 'create_series':
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $cover = trim($_POST['cover'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$title) {
            echo json_encode(['ok' => false, 'error' => 'El título de la serie es obligatorio']);
            exit;
        }

        $series = series_create($title, $description, $cover, $slug ?: null);
        if ($series) {
            echo json_encode(['ok' => true, 'series' => $series]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Error al guardar la serie']);
        }
        exit;

    case 'update_series':
        $id = trim($_POST['id'] ?? '');
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'ID de serie requerido']);
            exit;
        }

        $fields = [];
        if (isset($_POST['title'])) $fields['title'] = trim($_POST['title']);
        if (isset($_POST['description'])) $fields['description'] = trim($_POST['description']);
        if (isset($_POST['cover'])) $fields['cover'] = trim($_POST['cover']);
        if (isset($_POST['slug'])) $fields['slug'] = trim($_POST['slug']);

        $updated = series_update($id, $fields);
        if ($updated) {
            echo json_encode(['ok' => true, 'series' => $updated]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar la serie']);
        }
        exit;

    case 'delete_series':
        $id = trim($_POST['id'] ?? '');
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'ID de serie requerido']);
            exit;
        }

        if (series_delete($id)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar la serie']);
        }
        exit;

    case 'set_order':
        $id = trim($_POST['id'] ?? '');
        $postsRaw = $_POST['posts'] ?? [];
        if (is_string($postsRaw)) {
            $postsRaw = json_decode($postsRaw, true) ?: [];
        }

        if (!$id || !is_array($postsRaw)) {
            echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
            exit;
        }

        if (series_set_posts_order($id, $postsRaw)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Error al reordenar los artículos']);
        }
        exit;

    case 'add_post':
        $id = trim($_POST['id'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$id || !$slug) {
            echo json_encode(['ok' => false, 'error' => 'Serie y artículo son obligatorios']);
            exit;
        }

        if (series_assign_post($slug, $id)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Error al añadir el artículo']);
        }
        exit;

    case 'remove_post':
        $id = trim($_POST['id'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!$id || !$slug) {
            echo json_encode(['ok' => false, 'error' => 'Serie y artículo son obligatorios']);
            exit;
        }

        if (series_remove_post($id, $slug)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Error al remover el artículo']);
        }
        exit;

    case 'get_series_posts':
        $id = trim($_GET['id'] ?? $_POST['id'] ?? '');
        $series = series_get_by_id($id);
        if (!$series) {
            echo json_encode(['ok' => false, 'error' => 'Serie no encontrada']);
            exit;
        }

        $postSlugs = $series['posts'] ?? [];
        $items = [];
        foreach ($postSlugs as $idx => $s) {
            $p = get_content('articles', $s);
            $items[] = [
                'pos'    => $idx + 1,
                'slug'   => $s,
                'title'  => $p['title'] ?? ucfirst(str_replace('-', ' ', $s)),
                'status' => $p['status'] ?? 'published'
            ];
        }

        // Obtener todos los artículos para el selector de añadir
        $allArticles = list_content('articles', false, 1, 9999)['items'] ?? [];
        $available = [];
        foreach ($allArticles as $art) {
            $artSlug = $art['slug'] ?? '';
            if ($artSlug && !in_array($artSlug, $postSlugs, true)) {
                $available[] = [
                    'slug'  => $artSlug,
                    'title' => $art['title'] ?? $artSlug
                ];
            }
        }

        echo json_encode([
            'ok'        => true,
            'series'    => $series,
            'posts'     => $items,
            'available' => $available
        ]);
        exit;

    default:
        echo json_encode(['ok' => false, 'error' => 'Acción no reconocida']);
        exit;
}
