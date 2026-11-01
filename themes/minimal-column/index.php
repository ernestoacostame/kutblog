<?php
$heading = isset($category) ? 'Categoría: ' . $category :
           (isset($tag) ? 'Etiqueta: #' . $tag : null);

$current_page = $posts['page'] ?? 1;
$total_pages  = $posts['pages'] ?? 1;
?>

<?php if ($heading): ?>
<header class="feed-header">
  <div class="feed-filter-badge"><?= isset($category) ? 'Categoría' : 'Etiqueta' ?></div>
  <h1 class="feed-filter-title"><?= htmlspecialchars($heading) ?></h1>
  <a href="<?= $base ?>/" class="feed-filter-clear">✕ Ver todos los artículos</a>
</header>
<?php endif; ?>

<?php if (empty($posts['items'])): ?>
<div class="empty-state">
  <div class="empty-icon">∅</div>
  <h2 class="empty-title">Sin publicaciones</h2>
  <p class="empty-text">Aún no hay artículos publicados en esta sección.</p>
  <a href="<?= $base ?>/" class="btn-clean">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="posts-feed">
  <?php foreach ($posts['items'] as $post):
    $post_url = $base . '/article/' . htmlspecialchars($post['slug']);
    $first_cat = !empty($post['categories'][0]) ? $post['categories'][0] : 'General';
    $has_image = !empty($post['featured_image']);
    $words = str_word_count(strip_tags($post['content'] ?? ''));
    $read_time = max(1, (int)ceil($words / 200));
    $has_podcast = !empty($post['podcast_meta']['audio_url']) || !empty($post['podcast_audio']);
    
    $excerpt = $post['excerpt'] ?? '';
    if (!$excerpt && !empty($post['content'])) {
      $excerpt = mb_substr(strip_tags($post['content']), 0, 160) . '…';
    }
  ?>
  <article class="feed-item">
    <!-- Meta superior -->
    <div class="item-meta">
      <?php if (!empty($post['categories'][0])): ?>
        <a href="<?= $base ?>/category/<?= urlencode($first_cat) ?>" class="item-cat">
          <?= htmlspecialchars($first_cat) ?>
        </a>
        <span class="meta-dot">·</span>
      <?php endif; ?>
      <time datetime="<?= $post['created_at'] ?? '' ?>" class="item-date">
        <?= format_date($post['created_at'] ?? 'now') ?>
      </time>
      <span class="meta-dot">·</span>
      <span class="item-readtime"><?= $read_time ?> min de lectura</span>

      <?php if ($has_podcast): ?>
        <span class="item-podcast-pill" title="Contiene audio de podcast">🎙️ Episodio</span>
      <?php endif; ?>
    </div>

    <!-- Título principal -->
    <h2 class="item-title">
      <a href="<?= $post_url ?>">
        <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
      </a>
    </h2>

    <!-- Imagen destacada (si existe) -->
    <?php if ($has_image): ?>
    <a href="<?= $post_url ?>" class="item-media" aria-label="<?= htmlspecialchars($post['title'] ?? '') ?>">
      <img src="<?= htmlspecialchars($post['featured_image']) ?>"
           alt="<?= htmlspecialchars($post['title'] ?? '') ?>"
           loading="lazy">
    </a>
    <?php endif; ?>

    <!-- Resumen / Excerpt -->
    <?php if (!empty($excerpt)): ?>
    <p class="item-excerpt">
      <?= htmlspecialchars($excerpt) ?>
    </p>
    <?php endif; ?>

    <!-- Pie del artículo con enlace directo -->
    <div class="item-footer">
      <a href="<?= $post_url ?>" class="item-read-link">
        <span>Continuar leyendo</span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="arrow-icon">
          <line x1="5" y1="12" x2="19" y2="12"></line>
          <polyline points="12 5 19 12 12 19"></polyline>
        </svg>
      </a>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<!-- ── Paginación Minimalista de Una Columna ─────────────────────────────── -->
<?php if ($total_pages > 1):
  $max_links = 5;
  $start = max(1, $current_page - 2);
  $end   = min($total_pages, $current_page + 2);
  
  if ($current_page <= 3) {
    $start = 1;
    $end   = min($max_links, $total_pages);
  }
  if ($current_page > $total_pages - 3) {
    $start = max(1, $total_pages - $max_links + 1);
    $end   = $total_pages;
  }
  
  $query_params = $_GET;
  $build_page_url = function($p) use ($base, $query_params) {
    $query_params['page'] = $p;
    return $base . '?' . http_build_query($query_params);
  };
?>
<nav class="feed-pagination" aria-label="Paginación de artículos">
  <?php if ($current_page > 1): ?>
    <a href="<?= $build_page_url($current_page - 1) ?>" class="page-step-btn" aria-label="Página anterior">
      ← Anterior
    </a>
  <?php else: ?>
    <span class="page-step-btn is-disabled">← Anterior</span>
  <?php endif; ?>

  <div class="page-numbers">
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i == $current_page): ?>
        <span class="page-number-current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= $build_page_url($i) ?>" class="page-number-link"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>

  <?php if ($current_page < $total_pages): ?>
    <a href="<?= $build_page_url($current_page + 1) ?>" class="page-step-btn" aria-label="Página siguiente">
      Siguiente →
    </a>
  <?php else: ?>
    <span class="page-step-btn is-disabled">Siguiente →</span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; ?>

