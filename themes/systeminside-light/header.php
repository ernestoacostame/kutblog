<!DOCTYPE html>
<html lang="<?= site_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($post) ? htmlspecialchars($post['title']) . ' — ' : '' ?><?= htmlspecialchars($site_title) ?></title>
<meta name="description" content="<?= isset($post) ? htmlspecialchars(strip_tags($post['excerpt'] ?? substr(strip_tags($post['content'] ?? ''), 0, 160))) : htmlspecialchars($config['tagline'] ?? $site_title) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($site_title) ?> RSS" href="<?= $base ?>/rss.xml">
<link rel="canonical" href="<?= $base . '/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/') ?>">
<?= cms_favicon_tags() ?>
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
if ($mastodon_url): ?><link rel="me" href="<?= htmlspecialchars($mastodon_url) ?>"><?php endif; ?>
<?php if (cms_plugin_is_active('fediverse')): ?>
<link rel="alternate" type="application/activity+json" href="<?= htmlspecialchars(base_url() . '/users/' . ($config['fediverse_username'] ?? 'blog')) ?>">
<?php endif; ?>
<?php if ($mastodon_handle): ?><meta name="fediverse:creator" content="<?= htmlspecialchars($mastodon_handle) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:ital,wght@0,300;0,400;0,700;1,400&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
<style>
<?= get_theme_css_vars() ?>

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:         #f4f4f0;
  --surface:    #ffffff;
  --surface2:   #f0f0ec;
  --border:     #e0e0da;
  --border2:    #d0d0ca;
  --text:       #1a1a1a;
  --text2:      #444444;
  --muted:      #888888;
  --header-bg:  #ffffff;
  --sidebar-w:  290px;
  --font:       'Roboto Condensed', Arial Narrow, sans-serif;
  --font-body:  'Roboto', sans-serif;
}

html { scroll-behavior: smooth; height: 100%; }
body { background: var(--bg); color: var(--text); font-family: var(--font); font-size: 16px; line-height: 1.5; -webkit-font-smoothing: antialiased; min-height: 100%; display: flex; flex-direction: column; }
.site-wrap { flex: 1; }

/* ── HEADER ── */
.site-header { background: var(--header-bg); position: sticky; top: 0; z-index: 200; border-bottom: 1px solid var(--border); box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
.header-inner { display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; height: 56px; max-width: 1200px; margin: 0 auto; }
.site-logo { text-decoration: none; display: flex; align-items: center; gap: 0.6rem; line-height: 1; }
.site-logo-img { height: 32px; width: auto; max-width: 140px; object-fit: contain; vertical-align: middle; }
.logo-main  { font-family: var(--font); font-size: 1.75rem; font-weight: 700; color: #1a1a1a; letter-spacing: -0.02em; text-transform: uppercase; }
.logo-accent { font-family: var(--font); font-size: 1.75rem; font-weight: 300; color: var(--accent); letter-spacing: -0.02em; text-transform: uppercase; }
.hamburger { background: none; border: none; cursor: pointer; padding: 0.5rem; display: flex; flex-direction: column; gap: 5px; }
.hamburger span { display: block; width: 24px; height: 2px; background: #555; transition: all 0.25s; }
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* ── NAV DROPDOWN ── */
.nav-dropdown { background: #fff; border-bottom: 2px solid var(--accent); box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; max-height: 0; transition: max-height 0.3s ease; position: fixed; top: 56px; left: 0; right: 0; z-index: 300; }
.nav-dropdown.open { max-height: 400px; }
.nav-list { max-width: 1200px; margin: 0 auto; padding: 1rem 1.5rem; display: flex; flex-wrap: wrap; gap: 0.25rem; }
.nav-list a { color: #555; text-decoration: none; font-family: var(--font); font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.04em; padding: 0.4rem 0.85rem; border-radius: 3px; transition: all 0.15s; display: block; }
.nav-list a:hover { color: #1a1a1a; background: rgba(0,0,0,0.05); }

/* ── LAYOUT ── */
.site-wrap { max-width: 1200px; margin: 0 auto; padding: 1.5rem; display: grid; grid-template-columns: 1fr var(--sidebar-w); gap: 2rem; align-items: start; }

/* ── SEARCH ── */
.search-bar { margin-bottom: 1.5rem; }
.search-bar form { display: flex; background: #fff; border: 1px solid var(--border); border-radius: 3px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
.search-bar input { flex: 1; border: none; padding: 0.7rem 1rem; font-family: var(--font); font-size: 0.95rem; color: var(--text); background: transparent; outline: none; }
.search-bar input::placeholder { color: #bbb; }
.search-bar button { background: none; border: none; padding: 0 0.85rem; cursor: pointer; color: #aaa; display: flex; align-items: center; transition: color 0.15s; }
.search-bar button:hover { color: var(--accent); }
.search-icon { display: block; width: 18px; height: 18px; }

.site-main { min-width: 0; }

/* ── SIDEBAR ── */
.site-sidebar { min-width: 0; background: var(--surface); border: 1px solid var(--border); }
.sidebar-widget { margin-bottom: 0; border-bottom: 1px solid var(--border); }
.sidebar-widget:last-child { border-bottom: none; }
.widget-title { background: transparent; color: #888; font-family: var(--font); font-size: 1.1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 0.85rem 1rem 0.4rem; }
.widget-body { padding: 0.25rem 1rem 0.85rem; }
.cat-list { list-style: none; }
.cat-list li { padding: 0.1rem 0; border-bottom: none; }
.cat-list a { display: block; padding: 0.35rem 0; color: var(--accent); text-decoration: none; font-size: 0.875rem; font-weight: 700; transition: color 0.15s; }
.cat-list a:hover { color: var(--text); }

/* ── FOOTER ── */
.site-footer { background: #f0f0ec; border-top: 1px solid var(--border); margin-top: 2rem; padding: 2rem 1.5rem; text-align: center; }
.footer-inner { max-width: 1200px; margin: 0 auto; }
.footer-nav { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.25rem; margin-bottom: 1rem; }
.footer-nav a { color: #888; text-decoration: none; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.06em; padding: 0.25rem 0.6rem; transition: color 0.15s; }
.footer-nav a:hover { color: var(--accent); }
.footer-text { color: #aaa; font-size: 0.8rem; margin-top: 0.75rem; }
.footer-text a { color: var(--accent); text-decoration: none; }

@media (max-width: 768px) {
  .site-wrap { grid-template-columns: 1fr; padding: 1rem; }
  .site-sidebar { order: 2; }
  .site-main { order: 1; }
}
</style>
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <?php $words = explode(' ', $site_title, 2); $w1 = strtoupper($words[0]); $w2 = strtoupper($words[1] ?? ''); ?>
    <a href="<?= $base ?>/" class="site-logo">
      <?php if (!empty($site_logo)): ?>
        <img src="<?= htmlspecialchars($site_logo) ?>" alt="<?= htmlspecialchars($site_title) ?>" class="site-logo-img">
      <?php endif; ?>
      <span class="logo-main"><?= htmlspecialchars($w1) ?></span>
      <?php if ($w2): ?><span class="logo-accent"><?= htmlspecialchars($w2) ?></span><?php endif; ?>
    </a>
    <button class="hamburger" id="menu-btn" aria-label="Menú" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</header>
<nav class="nav-dropdown" id="nav-dropdown" aria-hidden="true">
  <div class="nav-list">
    <a href="<?= $base ?>/">Home</a>
    <?php require_once ROOT_PATH . '/core/content.php'; $nav_pages = list_content('pages', true, 1, 20); foreach ($nav_pages['items'] as $np): ?>
    <a href="<?= $base ?>/page/<?= htmlspecialchars($np['slug']) ?>"><?= htmlspecialchars($np['title']) ?></a>
    <?php endforeach; ?>
    <a href="<?= $base ?>/rss.xml" class="nav-rss" title="Feed RSS">RSS</a>
    <a href="<?= $base ?>/admin" class="nav-admin" title="Panel de Administración">Admin</a>
  </div>
</nav>
<div class="site-wrap">
  <main class="site-main">
    <div class="search-bar">
      <form action="<?= $base ?>/search" method="GET">
        <input type="text" name="q" placeholder="Para Buscar oprima Enter" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <button type="submit" aria-label="Buscar">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="16.5" y1="16.5" x2="22" y2="22"/></svg>
        </button>
      </form>
    </div>
