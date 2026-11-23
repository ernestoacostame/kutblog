<?php
/**
 * KutPod Plugin - Webhook Endpoint for KutBlog
 * Receives real-time push events from KutPod when episodes are created, updated, or published
 */

header('Content-Type: application/json; charset=utf-8');

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}
require_once ROOT_PATH . '/core/config.php';
require_once ROOT_PATH . '/core/content.php';
require_once __DIR__ . '/kutpod.php';
require_once __DIR__ . '/sync.php';

$cfg = kutpod_config();

// 1. Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// 2. Validar Token Secreto (fail-closed si no está configurado)
$configuredToken = trim($cfg['webhook_secret'] ?? '');
if ($configuredToken === '') {
    http_response_code(403);
    echo json_encode(['error' => 'Webhook secret is not configured.']);
    exit;
}
if ($configuredToken !== '') {
    $incomingToken = '';
    
    if (!empty($_SERVER['HTTP_X_KUTPOD_SECRET'])) {
        $incomingToken = trim($_SERVER['HTTP_X_KUTPOD_SECRET']);
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $incomingToken = trim($m[1]);
    } elseif (!empty($_GET['token'])) {
        $incomingToken = trim($_GET['token']);
    }

    if (!hash_equals($configuredToken, $incomingToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing authorization token.']);
        exit;
    }
}

// 3. Obtener cuerpo JSON
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

$event = $payload['event'] ?? 'episode.published';
$epData = $payload['episode'] ?? $payload['data'] ?? $payload;

if (empty($epData['title']) && empty($epData['id'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Missing episode data in payload.']);
    exit;
}

// Si se ha configurado sincronizar únicamente un podcast específico
if (!empty($cfg['podcast_filter']) && $cfg['podcast_filter'] !== 'all') {
    $epPodcastSlug = $epData['podcast_slug'] ?? '';
    $epPodcastId = (string)($epData['podcast_id'] ?? '');
    if ($epPodcastSlug !== $cfg['podcast_filter'] && $epPodcastId !== (string)$cfg['podcast_filter']) {
        echo json_encode([
            'success' => true,
            'action'  => 'skipped',
            'message' => 'Episode ignored: podcast not matching active filter.'
        ]);
        exit;
    }
}

// 4. Procesar el evento
if ($event === 'episode.deleted') {
    $slug = sanitize_filename($epData['slug'] ?? '');
    $articlesDir = content_path('articles');
    $file = "$articlesDir/{$slug}.json";
    
    if (file_exists($file)) {
        if (!empty($cfg['delete_on_kutpod_delete'])) {
            @unlink($file);
            $actionTaken = 'deleted';
        } else {
            // Pasar a borrador
            $article = json_decode(file_get_contents($file), true);
            $article['status'] = 'draft';
            file_put_contents($file, json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $actionTaken = 'drafted';
        }
        invalidate_content_index('articles');
        if (function_exists('clear_cache')) clear_cache();
        
        echo json_encode(['success' => true, 'action' => $actionTaken, 'slug' => $slug]);
        exit;
    }
    
    echo json_encode(['success' => true, 'action' => 'none', 'message' => 'Article not found.']);
    exit;
}

// Episodio creado o actualizado
$result = kutpod_upsert_article_from_episode($epData, $cfg);
invalidate_content_index('articles');
if (function_exists('clear_cache')) clear_cache();

echo json_encode([
    'success' => true,
    'event'   => $event,
    'action'  => $result,
    'title'   => $epData['title'] ?? '',
    'slug'    => $epData['slug'] ?? ''
]);
