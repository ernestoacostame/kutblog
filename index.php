<?php
// KutBlog - Front-end Router

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/content.php';
require_once __DIR__ . '/core/theme.php';

// Redirect to installer if not installed
if (!is_installed()) {
    header('Location: ' . base_url() . '/install/');
    exit;
}

$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base     = rtrim(base_url(), '/');
$path     = $base ? substr($uri, strlen(parse_url($base, PHP_URL_PATH))) : $uri;
$path     = trim($path, '/');
$segments = $path ? explode('/', $path) : [];
$config   = cms_config();
$per_page = (int)($config['posts_per_page'] ?? 5);

$type = $segments[0] ?? '';
$slug = $segments[1] ?? ($segments[0] ?? '');

// ── Sitemap, robots & favicon ──────────────────────────────────────────────
if ($path === 'favicon.ico') {
    $config = cms_config();
    $customFav = $config['site_favicon'] ?? '';
    if ($customFav && str_ends_with(strtolower($customFav), '.ico')) {
        header('Location: ' . site_favicon(), true, 302);
        exit;
    }
    $icoPath = __DIR__ . '/favicon.ico';
    if (!file_exists($icoPath)) {
        $icoPath = __DIR__ . '/media/favicon/default.ico';
    }
    if (file_exists($icoPath)) {
        header('Content-Type: image/x-icon');
        header('Cache-Control: public, max-age=86400');
        readfile($icoPath);
        exit;
    }
    header('Location: ' . site_favicon(), true, 302);
    exit;
}
if ($path === 'favicon.svg') {
    header('Location: ' . site_favicon(), true, 302);
    exit;
}
if ($path === 'sitemap.xml') {
    require __DIR__ . '/sitemap.xml.php';
    exit;
}
if ($path === 'robots.txt') {
    header('Content-Type: text/plain');
    $robots = file_get_contents(__DIR__ . '/robots.txt');
    echo str_replace('SITEURL', rtrim(base_url(), '/'), $robots);
    exit;
}

// ── RSS feed ──────────────────────────────────────────────────────────────
if ($path === 'rss.xml' || $path === 'feed.xml' || $path === 'feed' || $path === 'feed/rss') {
    require __DIR__ . '/rss.xml.php';
    exit;
}

// ── Mastodon comments API ─────────────────────────────────────────────────
if ($path === 'api/mastodon') {
    require __DIR__ . '/api/mastodon.php';
    exit;
}

// ── Cookie-Free Analytics Beacon API ──────────────────────────────────────
if ($path === 'api/track' || $path === 'api/track.php') {
    require __DIR__ . '/api/track.php';
    exit;
}

// ── Newsletter Subscription & Opt-In API ──────────────────────────────────
if ($path === 'api/subscribe' || $path === 'api/subscribe.php') {
    require __DIR__ . '/api/subscribe.php';
    exit;
}

// ── Image Cache & Optimization API ────────────────────────────────────────
if ($path === 'api/img' || $path === 'api/img.php') {
    require __DIR__ . '/api/img.php';
    exit;
}

// ── ActivityPub Endpoints (Webfinger / Actor / Inbox / Outbox) ─────────────
if ($path === '.well-known/webfinger' || ($segments[0] ?? '') === 'users') {
    if (cms_plugin_is_active('fediverse')) {
        require_once ROOT_PATH . '/plugins/fediverse/activitypub.php';
        ap_route_request($path, $segments);
    } else {
        http_response_code(404);
        exit('Fediverso desactivado.');
    }
    exit;
}

// ── KutPod Podcast Integration (Webhooks, Sync, /podcasts) ───────────────────
if (cms_plugin_is_active('kutpod')) {
    require_once ROOT_PATH . '/plugins/kutpod/kutpod.php';
    if (kutpod_route_request($path, $segments)) {
        exit;
    }
}

// ── Homepage / blog listing ───────────────────────────────────────────────
if ($path === '' || $path === 'blog') {
    $page_num = max(1, (int)($_GET['page'] ?? 1));
    $posts    = list_content('articles', true, $page_num, $per_page);
    render_theme('index', ['posts' => $posts, 'page_num' => $page_num]);

// ── Single article ────────────────────────────────────────────────────────
} elseif ($type === 'article' && $slug) {
    $post = get_content('articles', $slug);
    if (!$post || $post['status'] !== 'published') { http_response_code(404); render_theme('404'); exit; }
    render_theme('single', ['post' => $post, 'type' => 'articles']);

// ── Single page ───────────────────────────────────────────────────────────
} elseif ($type === 'page' && $slug) {
    $post = get_content('pages', $slug);
    if (!$post || $post['status'] !== 'published') { http_response_code(404); render_theme('404'); exit; }
    render_theme('single', ['post' => $post, 'type' => 'pages']);

// ── Category archive ──────────────────────────────────────────────────────
} elseif ($type === 'category' && $slug) {
    $all      = list_content('articles', true, 1, 9999);
    $filtered = array_values(array_filter($all['items'], fn($p) => in_array(urldecode($slug), $p['categories'] ?? [])));
    render_theme('index', ['posts' => ['items' => $filtered, 'total' => count($filtered), 'pages' => 1, 'page' => 1], 'category' => urldecode($slug)]);

// ── Tag archive ───────────────────────────────────────────────────────────
} elseif ($type === 'tag' && $slug) {
    $all      = list_content('articles', true, 1, 9999);
    $filtered = array_values(array_filter($all['items'], fn($p) => in_array(urldecode($slug), $p['tags'] ?? [])));
    render_theme('index', ['posts' => ['items' => $filtered, 'total' => count($filtered), 'pages' => 1, 'page' => 1], 'tag' => urldecode($slug)]);

// ── Search ────────────────────────────────────────────────────────────────
} elseif ($type === 'search') {
    $q       = $_GET['q'] ?? '';
    $results = $q ? search_content($q) : [];
    render_theme('search', ['results' => $results, 'query' => $q]);

// ── Pretty URLs (slug only) ───────────────────────────────────────────────
} elseif ($path && !in_array($type, ['article','page','category','tag','search','api','admin','install','media'])) {
    $post = get_content('pages', $path) ?? get_content('articles', $path);
    if ($post && $post['status'] === 'published') {
        $post_type = file_exists(content_path('pages') . "/$path.json") ? 'pages' : 'articles';
        render_theme('single', ['post' => $post, 'type' => $post_type]);
    } else {
        http_response_code(404);
        render_theme('404');
    }

} else {
    http_response_code(404);
    render_theme('404');
}
