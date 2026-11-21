<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/content.php';

if (!is_installed()) { http_response_code(404); exit; }

$cache_file = CACHE_PATH . '/rss.xml';
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
    $config   = cms_config();
    $base     = base_url();
    $title    = site_title();
    $tagline  = $config['tagline'] ?? '';
    $per_page = (int)($config['rss_posts_limit'] ?? ($config['posts_per_page'] ?? 20));

    $result = list_content('articles', true, 1, $per_page);

    ob_start();
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
  xmlns:atom="http://www.w3.org/2005/Atom"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title><?= htmlspecialchars($title) ?></title>
    <link><?= htmlspecialchars($base) ?>/</link>
    <description><?= htmlspecialchars($tagline) ?></description>
    <language>es</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <atom:link href="<?= htmlspecialchars($base) ?>/rss.xml" rel="self" type="application/rss+xml"/>
    <generator>KutBlog <?= CMS_VERSION ?></generator>

    <?php foreach ($result['items'] as $post):
      $full    = !empty($post['slug']) ? (get_content('articles', $post['slug']) ?? $post) : $post;
      $url     = $base . '/article/' . rawurlencode($post['slug']);
      $pubdate = date('r', strtotime($post['created_at'] ?? 'now'));
      $excerpt = strip_tags($full['excerpt'] ?? ($post['_excerpt'] ?? substr(strip_tags($full['content'] ?? ''), 0, 300)));
      $cats    = $post['categories'] ?? [];
    ?>
    <item>
      <title><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></title>
      <link><?= htmlspecialchars($url) ?></link>
      <guid isPermaLink="true"><?= htmlspecialchars($url) ?></guid>
      <pubDate><?= $pubdate ?></pubDate>
      <description><?= htmlspecialchars($excerpt) ?></description>
      <content:encoded><![CDATA[<?= $full['content'] ?? '' ?>]]></content:encoded>
      <?php foreach ($cats as $cat): ?>
      <category><?= htmlspecialchars($cat) ?></category>
      <?php endforeach; ?>
      <?php if (!empty($post['featured_image'])): ?>
      <enclosure url="<?= htmlspecialchars($post['featured_image']) ?>" type="image/jpeg" length="0"/>
      <?php endif; ?>
    </item>
    <?php endforeach; ?>
  </channel>
</rss>
<?php
    $xml = ob_get_clean();
    if (!is_dir(CACHE_PATH)) @mkdir(CACHE_PATH, 0755, true);
    $tmp = @tempnam(CACHE_PATH, 'rss_');
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

$etag = '"rss-' . md5($xml) . '"';
header('Cache-Control: public, max-age=600');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
if (($ifNoneMatch !== '' && $ifNoneMatch === $etag) || ($ifModifiedSince !== false && $ifModifiedSince >= $mtime)) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
echo $xml;
