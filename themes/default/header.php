<!DOCTYPE html>
<html lang="<?= site_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($post) ? htmlspecialchars($post['title']) . ' — ' : '' ?><?= htmlspecialchars($site_title) ?></title>
<meta name="description" content="<?= isset($post) ? htmlspecialchars(strip_tags($post['excerpt'] ?? substr(strip_tags($post['content'] ?? ''), 0, 160))) : htmlspecialchars($config['tagline'] ?? $site_title) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($site_title) ?> RSS" href="<?= $base ?>/rss.xml">
<link rel="canonical" href="<?= $base . '/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/') ?>">
<?php if (isset($post)): ?>
<meta property="og:title"       content="<?= htmlspecialchars($post['title'] ?? '') ?>">
<meta property="og:type"        content="article">
<meta property="og:url"         content="<?= $base ?>/article/<?= htmlspecialchars($post['slug'] ?? '') ?>">
<?php if (!empty($post['featured_image'])): ?>
<meta property="og:image"       content="<?= htmlspecialchars($post['featured_image']) ?>">
<?php endif; ?>
<meta property="og:description" content="<?= htmlspecialchars(strip_tags($post['excerpt'] ?? substr(strip_tags($post['content'] ?? ''), 0, 200))) ?>">
<?php endif; ?>
<?php
$mastodon_url    = $config['mastodon_url'] ?? '';
$mastodon_handle = $config['mastodon_handle'] ?? '';
if ($mastodon_url): ?>
<link rel="me" href="<?= htmlspecialchars($mastodon_url) ?>">
<?php endif; ?>
<?php if (cms_plugin_is_active('fediverse')): ?>
<link rel="alternate" type="application/activity+json" href="<?= htmlspecialchars(base_url() . '/users/' . ($config['fediverse_username'] ?? 'blog')) ?>">
<?php endif; ?>
<?php if ($mastodon_handle): ?>
<meta name="fediverse:creator" content="<?= htmlspecialchars($mastodon_handle) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:ital,wght@0,300;0,400;0,700;1,400&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
<style>
<?= get_theme_css_vars() ?>

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg: #fafaf8;
  --surface: #ffffff;
  --border: #e8e8e4;
  --text: #1a1a1a;
  --text2: #4a4a4a;
  --muted: #8a8a8a;
  --radius: 10px;
  --max-w: 720px;
  --font-body: 'Roboto', sans-serif;
  --font-heading: 'Roboto Condensed', Arial Narrow, sans-serif;
  --font: 'Roboto Condensed', Arial Narrow, sans-serif;
}

html { scroll-behavior: smooth; }
body { background: var(--bg); color: var(--text); font-family: var(--font-body); font-size: 16px; line-height: 1.6; -webkit-font-smoothing: antialiased; }

/* Header */
.site-header {
  background: rgba(250,250,248,0.92); backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100;
}
.header-inner {
  max-width: 1100px; margin: 0 auto; padding: 0 2rem;
  display: flex; align-items: center; justify-content: space-between; height: 60px;
}
.site-title {
  font-family: var(--font-heading); font-size: 1.3rem; font-weight: 700;
  color: var(--text); text-decoration: none; letter-spacing: -0.02em;
  display: inline-flex; align-items: center; gap: 0.6rem;
}
.site-logo-img {
  height: 32px; width: auto; max-width: 140px; object-fit: contain; vertical-align: middle;
}
.site-title span { color: var(--accent); }
/* Hamburger */
.hamburger {
  background: none; border: none; cursor: pointer;
  padding: 0.4rem; display: flex; flex-direction: column; gap: 5px;
}
.hamburger span {
  display: block; width: 22px; height: 2px;
  background: currentColor; transition: all 0.22s;
  color: inherit;
}
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* Dropdown nav */
.nav-dropdown {
  overflow: hidden; max-height: 0;
  transition: max-height 0.28s ease;
  position: fixed; top: 60px; left: 0; right: 0; z-index: 300;
  border-bottom: 2px solid var(--accent);
}
.nav-dropdown.open { max-height: 400px; }
.nav-list {
  max-width: 1100px; margin: 0 auto;
  padding: 0.85rem 2rem; display: flex; flex-wrap: wrap; gap: 0.25rem;
}
.nav-list a {
  text-decoration: none; font-size: 0.9rem; font-weight: 500;
  padding: 0.4rem 0.85rem; border-radius: 6px; display: block; transition: all 0.15s;
}
/* Light variant */
.hamburger { color: var(--text2); }
.hamburger:hover { color: var(--text); }
.nav-dropdown { background: rgba(250,250,248,0.97); backdrop-filter: blur(12px); box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.nav-list a { color: var(--text2); }
.nav-list a:hover { background: var(--border); color: var(--text); }

.search-form { display: flex; align-items: center; }
.search-form input {
  background: var(--border); border: none; border-radius: 20px; padding: 0.35rem 0.9rem;
  font-family: inherit; font-size: 0.85rem; color: var(--text); width: 160px;
  transition: width 0.2s;
}
.search-form input:focus { outline: none; width: 200px; background: var(--surface); box-shadow: 0 0 0 2px var(--accent-light); }
.search-form input::placeholder { color: var(--muted); }

/* Main layout */
.site-main { max-width: 1100px; margin: 0 auto; padding: 3rem 2rem; }

/* Footer */
.site-footer { border-top: 1px solid var(--border); background: var(--surface); margin-top: 4rem; }
.footer-inner { max-width: 1100px; margin: 0 auto; padding: 2rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
.footer-text { color: var(--muted); font-size: 0.85rem; }
.footer-links { display: flex; gap: 1rem; }
.footer-links a { color: var(--muted); font-size: 0.85rem; text-decoration: none; }
.footer-links a:hover { color: var(--accent); }

/* Cards / Blog listing */
.posts-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
.post-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  overflow: hidden; transition: box-shadow 0.2s, transform 0.2s;
  display: flex; flex-direction: column;
}
.post-card:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.08); transform: translateY(-2px); }
.post-card-image { aspect-ratio: 16/9; overflow: hidden; background: var(--border); }
.post-card-image img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
.post-card:hover .post-card-image img { transform: scale(1.03); }
.post-card-body { padding: 1.25rem; flex: 1; display: flex; flex-direction: column; }
.post-card-meta { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; flex-wrap: wrap; }
.post-date { font-size: 0.78rem; color: var(--muted); }
.post-category { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--accent); background: var(--accent-light); padding: 0.15rem 0.5rem; border-radius: 20px; text-decoration: none; }
.post-card-title { font-family: var(--font-heading); font-size: 1.15rem; font-weight: 600; line-height: 1.4; margin-bottom: 0.6rem; letter-spacing: -0.01em; }
.post-card-title a { color: var(--text); text-decoration: none; }
.post-card-title a:hover { color: var(--accent); }
.post-excerpt { color: var(--text2); font-size: 0.9rem; line-height: 1.6; flex: 1; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.post-read-more { margin-top: 1rem; display: inline-flex; align-items: center; gap: 0.35rem; color: var(--accent); font-size: 0.875rem; font-weight: 500; text-decoration: none; }
.post-read-more:hover { gap: 0.55rem; }

/* Single article */
.article-header { margin-bottom: 2.5rem; max-width: var(--max-w); }
.article-meta { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.article-title { font-family: var(--font-heading); font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 700; line-height: 1.2; letter-spacing: -0.03em; }
.article-featured { width: 100%; max-width: var(--max-w); aspect-ratio: 16/9; object-fit: cover; border-radius: var(--radius); margin-bottom: 2.5rem; }

/* Prose content */
.prose { max-width: var(--max-w); line-height: 1.8; font-size: 1.05rem; color: var(--text2); }
.prose h2 { font-family: var(--font-heading); font-size: 1.6rem; font-weight: 700; color: var(--text); margin: 2.5rem 0 1rem; letter-spacing: -0.02em; }
.prose h3 { font-family: var(--font-heading); font-size: 1.25rem; font-weight: 600; color: var(--text); margin: 2rem 0 0.75rem; }
.prose p { margin-bottom: 1.5rem; }
.prose a { color: var(--accent); text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 3px; }
.prose a:hover { opacity: 0.8; }
.prose strong { color: var(--text); font-weight: 600; }
.prose blockquote { border-left: 3px solid var(--accent); padding: 0.75rem 1.25rem; margin: 1.5rem 0; color: var(--text); font-style: italic; font-family: var(--font-heading); font-size: 1.1rem; background: var(--accent-light); border-radius: 0 8px 8px 0; }
.prose pre { background: #1e1e2e; color: #cdd6f4; border-radius: 10px; padding: 1.25rem; overflow-x: auto; font-size: 0.875rem; margin: 1.5rem 0; line-height: 1.6; }
.prose code { background: var(--border); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.875em; }
.prose pre code { background: none; padding: 0; }
.prose ul, .prose ol { padding-left: 1.75rem; margin-bottom: 1.5rem; }
.prose li { margin-bottom: 0.4rem; }
.prose img { max-width: 100%; border-radius: 10px; margin: 1.5rem 0; }
.prose hr { border: none; border-top: 1px solid var(--border); margin: 2.5rem 0; }

/* Tags */
.tags { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 2rem; }
.tag { background: var(--border); color: var(--text2); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; text-decoration: none; transition: all 0.15s; }
.tag:hover { background: var(--accent-light); color: var(--accent); }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 3rem; }
.page-link { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); color: var(--text2); text-decoration: none; font-size: 0.875rem; transition: all 0.15s; }
.page-link:hover, .page-link.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* Page header (for static pages) */
.page-header { margin-bottom: 2.5rem; max-width: var(--max-w); border-bottom: 1px solid var(--border); padding-bottom: 1.5rem; }
.page-title { font-family: var(--font-heading); font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 700; letter-spacing: -0.03em; }
.page-subtitle { color: var(--muted); margin-top: 0.5rem; font-size: 1rem; }

/* Responsive */
@media (max-width: 640px) {
  .header-inner { padding: 0 1rem; }
  .site-main { padding: 2rem 1rem; }
  .posts-grid { grid-template-columns: 1fr; }
  .search-form input { width: 130px; }
  .footer-inner { flex-direction: column; text-align: center; }
}

/* YouTube embed */
.yt-embed { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; margin: 1.5rem 0; border-radius: 8px; }
.yt-embed iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }
</style>
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a href="<?= $base ?>/" class="site-title">
      <?php if (!empty($site_logo)): ?>
        <img src="<?= htmlspecialchars($site_logo) ?>" alt="<?= htmlspecialchars($site_title) ?>" class="site-logo-img">
      <?php endif; ?>
      <span><?= htmlspecialchars($site_title) ?></span>
    </a>
    <div style="display:flex;align-items:center;gap:0.75rem">
      <form class="search-form" action="<?= $base ?>/search" method="GET">
        <input type="text" name="q" placeholder="Buscar…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
      </form>
      <button class="hamburger" id="menu-btn" aria-label="Menú" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<nav class="nav-dropdown" id="nav-dropdown" aria-hidden="true">
  <div class="nav-list">
    <a href="<?= $base ?>/">Inicio</a>
    <?php
    require_once ROOT_PATH . '/core/content.php';
    $nav_pages = list_content('pages', true, 1, 20);
    foreach ($nav_pages['items'] as $np):
    ?>
    <a href="<?= $base ?>/page/<?= htmlspecialchars($np['slug']) ?>"><?= htmlspecialchars($np['title']) ?></a>
    <?php endforeach; ?>
    <a href="<?= $base ?>/rss.xml" class="nav-rss" title="Feed RSS">RSS</a>
    <a href="<?= $base ?>/admin" class="nav-admin" title="Panel de Administración">Admin</a>
  </div>
</nav>

<script>
(function() {
  var btn = document.getElementById('menu-btn');
  var nav = document.getElementById('nav-dropdown');
  if (!btn || !nav) return;
  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    var open = nav.classList.toggle('open');
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open);
  });
  document.addEventListener('click', function(e) {
    if (!btn.contains(e.target) && !nav.contains(e.target)) {
      nav.classList.remove('open');
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    }
  });
})();
</script>
<main class="site-main">
