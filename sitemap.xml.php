<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/content.php';

if (!is_installed()) { http_response_code(404); exit; }

$cache_file = CACHE_PATH . '/sitemap.xml';
$xml = null;
$mtime = 0;

if (file_exists($cache_file) && filesize($cache_file) > 0) {
    $mtime = (int)filemtime($cache_file);
    $cfgMtime = file_exists(CONFIG_FILE) ? (int)filemtime(CONFIG_FILE) : 0;
    if ($mtime >= $cfgMtime) {
        $xml = @file_get_contents($cache_file);
    }
}

if ($xml === null || $xml === false || $xml === '') {
    $base   = rtrim(base_url(), '/');
    $result = list_content('articles', true, 1, 9999);
    $pages  = list_content('pages', true, 1, 999);

    ob_start();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= htmlspecialchars($base) ?>/</loc>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <?php foreach ($pages['items'] as $page): ?>
  <url>
    <loc><?= htmlspecialchars($base) ?>/page/<?= rawurlencode($page['slug']) ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($page['updated_at'] ?? $page['created_at'] ?? 'now')) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
  </url>
  <?php endforeach; ?>
  <?php foreach ($result['items'] as $post): ?>
  <url>
    <loc><?= htmlspecialchars($base) ?>/article/<?= rawurlencode($post['slug']) ?></loc>
    <lastmod><?= date('Y-m-d', strtotime($post['updated_at'] ?? $post['created_at'] ?? 'now')) ?></lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
  </url>
  <?php endforeach; ?>
</urlset>
<?php
    $xml = ob_get_clean();
    if (!is_dir(CACHE_PATH)) @mkdir(CACHE_PATH, 0755, true);
    $tmp = @tempnam(CACHE_PATH, 'sitemap_');
    if ($tmp !== false) {
        if (@file_put_contents($tmp, $xml) !== false) {
            @chmod($tmp, 0644);
            if (!@rename($tmp, $cache_file)) @unlink($tmp);
        } else {
            @unlink($tmp);
        }
    }
    $mtime = file_exists($cache_file) ? (int)filemtime($cache_file) : time();
}

$etag = '"sitemap-' . md5($xml) . '"';
header('Cache-Control: public, max-age=600');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
if (($ifNoneMatch !== '' && $ifNoneMatch === $etag) || ($ifModifiedSince !== false && $ifModifiedSince >= $mtime)) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
echo $xml;
