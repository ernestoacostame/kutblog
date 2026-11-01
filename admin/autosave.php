<?php
// KutBlog - Autosave endpoint
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

if (!verify_csrf($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF error']); exit;
}

$type    = in_array($_POST['type'] ?? '', ['articles','pages']) ? $_POST['type'] : 'articles';
$slug    = trim($_POST['slug'] ?? '');
$title   = trim($_POST['title'] ?? '');
$content = $_POST['content'] ?? '';
$format  = in_array($_POST['content_format'] ?? '', ['html','markdown']) ? $_POST['content_format'] : 'html';

// Require at least a title or some content to autosave
if (!$title) {
    if (empty($content)) {
        echo json_encode(['ok' => false, 'error' => 'No title or content yet']); exit;
    }
    $title = 'Borrador sin título';
}

// If no slug yet, generate one from the title
if (!$slug) {
    $autoData = [
        'title'          => $title,
        'content'        => $content,
        'content_format' => $format,
        'status'         => 'draft',
        'excerpt'        => $_POST['excerpt'] ?? '',
        'categories'     => array_filter(array_map('trim', explode(',', $_POST['categories'] ?? ''))),
        'tags'           => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
        'featured_image' => $_POST['featured_image'] ?? '',
        'mastodon_url'   => $_POST['mastodon_url'] ?? '',
        'kutpod_url'     => trim($_POST['kutpod_url'] ?? ''),
        'podcast_meta'   => null,
        'series_id'      => trim($_POST['series_id'] ?? ''),
    ];
    if (cms_plugin_is_active('kutpod') && !empty($autoData['kutpod_url'])) {
        require_once dirname(__DIR__) . '/plugins/kutpod/kutpod.php';
        $autoData['podcast_meta'] = kutpod_resolve_meta_from_url($autoData['kutpod_url']);
    }
    $slug = save_content($type, $autoData);
    if ($type === 'articles' && cms_plugin_is_active('series') && !empty($autoData['series_id'])) {
        require_once dirname(__DIR__) . '/plugins/series/series.php';
        if (function_exists('series_assign_post')) {
            series_assign_post($slug, $autoData['series_id']);
        }
    }
    echo json_encode(['ok' => true, 'slug' => $slug, 'new' => true]);
    exit;
}

// Existing article — load and update content only (preserve status and other fields)
$existing = get_content($type, $slug) ?? [];
$existing['slug']           = $slug;
$existing['original_slug']  = $slug;
$existing['title']          = $title;
$existing['content']        = $content;
$existing['content_format'] = $format;
$existing['excerpt']        = $_POST['excerpt'] ?? ($existing['excerpt'] ?? '');
$existing['categories']     = array_filter(array_map('trim', explode(',', $_POST['categories'] ?? '')));
$existing['tags']           = array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')));
$existing['featured_image'] = $_POST['featured_image'] ?? ($existing['featured_image'] ?? '');
$existing['mastodon_url']   = $_POST['mastodon_url'] ?? ($existing['mastodon_url'] ?? '');
$existing['kutpod_url']     = trim($_POST['kutpod_url'] ?? ($existing['kutpod_url'] ?? ''));

if (isset($_POST['series_id'])) {
    $existing['series_id'] = trim($_POST['series_id']);
    if ($type === 'articles' && cms_plugin_is_active('series')) {
        require_once dirname(__DIR__) . '/plugins/series/series.php';
        if (function_exists('series_assign_post')) {
            series_assign_post($slug, $existing['series_id'] ?: null);
        }
    }
}

if (cms_plugin_is_active('kutpod') && !empty($existing['kutpod_url'])) {
    require_once dirname(__DIR__) . '/plugins/kutpod/kutpod.php';
    $existing['podcast_meta'] = kutpod_resolve_meta_from_url($existing['kutpod_url'], is_array($existing['podcast_meta'] ?? null) ? $existing['podcast_meta'] : []);
}

// Never change status to published via autosave
if (!isset($existing['status'])) $existing['status'] = 'draft';

$new_slug = save_content($type, $existing);
echo json_encode(['ok' => true, 'slug' => $new_slug, 'new' => false]);
