<?php
// KutBlog - Mastodon Comments Fetcher
require_once dirname(__DIR__) . '/core/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$post_url = trim($_GET['url'] ?? '');
if (!$post_url || !filter_var($post_url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid URL']); exit;
}

// Intercept local ActivityPub Note URLs
$base_host = parse_url(base_url(), PHP_URL_HOST);
$req_host = parse_url($post_url, PHP_URL_HOST);

if ($req_host === $base_host) {
    $path = parse_url($post_url, PHP_URL_PATH);
    if (preg_match('#/users/[^/]+/notes/([^/]+)$#', $path, $m)) {
        $slug = $m[1];
        if (cms_plugin_is_active('fediverse')) {
            require_once dirname(__DIR__) . '/plugins/fediverse/activitypub.php';
            $comments = ap_get_comments_for_slug($slug);
            $stats = ap_get_note_stats($slug);

            echo json_encode([
                'url'           => $post_url,
                'favourites'    => $stats['favourites'],
                'reblogs'       => $stats['reblogs'],
                'replies_count' => count($comments),
                'comments'      => $comments,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!preg_match('#^(https://[^/]+)/@[^/]+/(\d+)$#', $post_url, $m)) {
    echo json_encode(['error' => 'Not a valid Mastodon status URL', 'url' => $post_url]); exit;
}
if (!cms_is_safe_remote_url($post_url)) {
    echo json_encode(['error' => 'Unsafe or disallowed remote URL']); exit;
}
$instance  = $m[1];
$status_id = $m[2];

// Cache for 3 minutes
$cache_key = CACHE_PATH . '/mastodon_' . md5($post_url) . '.json';
if (file_exists($cache_key) && (time() - filemtime($cache_key)) < 180) {
    echo file_get_contents($cache_key); exit;
}

$status_data  = mastodon_get("$instance/api/v1/statuses/$status_id");
$context_data = mastodon_get("$instance/api/v1/statuses/$status_id/context");

if (!$status_data) {
    echo json_encode(['error' => "Could not reach $instance"]); exit;
}
if (!$context_data) {
    $context_data = ['descendants' => []];
}

$result = [
    'url'           => $post_url,
    'favourites'    => (int)($status_data['favourites_count'] ?? 0),
    'reblogs'       => (int)($status_data['reblogs_count'] ?? 0),
    'replies_count' => (int)($status_data['replies_count'] ?? 0),
    'comments'      => [],
];

// Build a map of all descendants by ID for threading
$all_replies = [];
foreach ($context_data['descendants'] ?? [] as $reply) {
    if (!empty($reply['reblog'])) continue; // skip boosts
    $all_replies[$reply['id']] = $reply;
}

// Include ALL descendants — direct replies AND replies to replies
// This shows the full conversation thread
foreach ($all_replies as $id => $reply) {
    $acct = $reply['account'] ?? [];
    $in_reply_to = (string)($reply['in_reply_to_id'] ?? '');

    // Determine indent level for threaded display
    $depth = 0;
    $parent_id = $in_reply_to;
    $visited = [];
    while ($parent_id && $parent_id !== $status_id && isset($all_replies[$parent_id]) && !in_array($parent_id, $visited)) {
        $visited[] = $parent_id;
        $depth++;
        $parent_id = (string)($all_replies[$parent_id]['in_reply_to_id'] ?? '');
        if ($depth > 5) break; // safety limit
    }

    $result['comments'][] = [
        'id'           => $reply['id'],
        'url'          => $reply['url'],
        'created_at'   => $reply['created_at'],
        'content'      => $reply['content'] ?? '',
        'in_reply_to'  => $in_reply_to,
        'depth'        => $depth,
        'author'       => [
            'name'     => $reply['account']['display_name'] ?: ($reply['account']['username'] ?? '?'),
            'username' => $reply['account']['acct'] ?? '',
            'avatar'   => $reply['account']['avatar_static'] ?? $reply['account']['avatar'] ?? '',
            'url'      => $reply['account']['url'] ?? '',
        ],
        'favourites'   => (int)($reply['favourites_count'] ?? 0),
        'reblogs'      => (int)($reply['reblogs_count'] ?? 0),
    ];
}

// Sort by created_at ascending
usort($result['comments'], fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

$json = json_encode($result, JSON_UNESCAPED_UNICODE);
file_put_contents($cache_key, $json);
echo $json;

function mastodon_get(string $url): ?array {
    if (!cms_is_safe_remote_url($url)) {
        return null;
    }
    $ctx  = stream_context_create(['http' => [
        'timeout'         => 10,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'header'          => "User-Agent: KutBlog/1.0\r\nAccept: application/json\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') return null;
    $data = json_decode($body, true);
    if (!is_array($data) || isset($data['error'])) return null;
    return $data;
}
