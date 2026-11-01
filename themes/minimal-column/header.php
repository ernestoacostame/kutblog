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

<!-- Google Fonts: Plus Jakarta Sans -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap" rel="stylesheet">

<!-- Anti-Flicker Theme Detection -->
<script>
(function() {
  try {
    const saved = localStorage.getItem('kut_theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (saved === 'dark' || (!saved && prefersDark)) {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.setAttribute('data-theme', 'light');
    }
  } catch(e) {}
})();
</script>

<style>
<?= get_theme_css_vars() ?>
</style>
<link rel="stylesheet" href="<?= theme_asset_url('style.css') ?>">
</head>
<body>

<header class="site-header">
  <div class="header-container header-inner">
    <!-- Brand / Logo -->
    <a href="<?= $base ?>/" class="brand" aria-label="<?= htmlspecialchars($site_title) ?>">
      <?php if (!empty($site_logo)): ?>
        <img src="<?= htmlspecialchars($site_logo) ?>" alt="<?= htmlspecialchars($site_title) ?>" class="brand-logo">
      <?php else: ?>
        <span class="brand-badge"><?= mb_strtoupper(mb_substr($site_title, 0, 1)) ?></span>
      <?php endif; ?>
      <span class="brand-name"><?= htmlspecialchars($site_title) ?></span>
    </a>

    <!-- Desktop Navigation -->
    <nav class="nav-desktop" aria-label="Navegación principal">
      <a href="<?= $base ?>/" class="nav-item <?= (empty($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] === '/' || strpos($_SERVER['REQUEST_URI'], '/?page=') === 0) ? 'is-active' : '' ?>">Inicio</a>
      <?php
      $nav_pages = list_content('pages', true, 1, 20);
      $current_slug = $post['slug'] ?? '';
      foreach ($nav_pages['items'] as $np):
        $is_act = ($current_slug === $np['slug']);
      ?>
      <a href="<?= $base ?>/page/<?= htmlspecialchars($np['slug']) ?>" class="nav-item <?= $is_act ? 'is-active' : '' ?>">
        <?= htmlspecialchars($np['title']) ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <!-- Header Actions (Search, Theme Toggle, Mobile Menu) -->
    <div class="header-actions">
      <!-- Search Button -->
      <button class="action-btn" id="search-toggle-btn" title="Buscar" aria-label="Buscar" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
      </button>

      <!-- Dark / Light Theme Toggle -->
      <button class="action-btn" id="theme-toggle-btn" title="Cambiar tema (Claro/Oscuro)" aria-label="Cambiar tema (Claro/Oscuro)">
        <!-- Sun icon (for dark mode) -->
        <svg id="icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
          <circle cx="12" cy="12" r="5"></circle>
          <line x1="12" y1="1" x2="12" y2="3"></line>
          <line x1="12" y1="21" x2="12" y2="23"></line>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
          <line x1="1" y1="12" x2="3" y2="12"></line>
          <line x1="21" y1="12" x2="23" y2="12"></line>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
        </svg>
        <!-- Moon icon (for light mode) -->
        <svg id="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
        </svg>
      </button>

      <!-- Mobile Hamburger Button -->
      <button class="action-btn menu-toggle-btn" id="mobile-menu-btn" title="Menú" aria-label="Abrir menú" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="3" y1="12" x2="21" y2="12"></line>
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
      </button>
    </div>
  </div>

  <!-- Search Popdown -->
  <div class="search-drawer" id="search-drawer" aria-hidden="true">
    <div class="header-container">
      <form class="search-form" action="<?= $base ?>/search" method="GET">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" class="search-icon">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" name="q" id="search-input" placeholder="Buscar en el blog y pulsar Enter…" autocomplete="off" required>
        <button type="button" class="search-close-btn" id="search-close-btn" aria-label="Cerrar búsqueda">✕</button>
      </form>
    </div>
  </div>

  <!-- Mobile Drawer -->
  <div class="mobile-drawer" id="mobile-drawer" aria-hidden="true">
    <div class="header-container">
      <nav class="mobile-nav" aria-label="Menú móvil">
        <a href="<?= $base ?>/" class="mobile-nav-item">Inicio</a>
        <?php foreach ($nav_pages['items'] as $np): ?>
        <a href="<?= $base ?>/page/<?= htmlspecialchars($np['slug']) ?>" class="mobile-nav-item"><?= htmlspecialchars($np['title']) ?></a>
        <?php endforeach; ?>
        <a href="<?= $base ?>/rss.xml" class="mobile-nav-item">RSS Feed</a>
        <a href="<?= $base ?>/admin" class="mobile-nav-item">Admin</a>
      </nav>
    </div>
  </div>
</header>

<main class="site-main">
  <div class="site-column">
