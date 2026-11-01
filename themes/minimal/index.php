<?php
$heading = isset($category) ? 'Categoría: ' . $category :
           (isset($tag) ? 'Etiqueta: #' . $tag : null);

$current_page = $posts['page'] ?? 1;
$total_pages  = $posts['pages'] ?? 1;
?>

<?php if ($heading): ?>
<header class="page-header">
  <span class="page-header-badge"><?= isset($category) ? 'Explorando' : 'Filtrando' ?></span>
  <h1 class="page-header-title"><?= htmlspecialchars($heading) ?></h1>
  <p class="page-header-desc">Mostrando todos los artículos disponibles bajo esta selección.</p>
</header>
<?php endif; ?>

<?php if (empty($posts['items'])): ?>
<div class="empty-box">
  <div class="empty-code">∅</div>
  <h2 class="empty-title">Sin artículos por aquí</h2>
  <p class="empty-desc">No se han encontrado publicaciones en esta sección todavía.</p>
  <a href="<?= $base ?>/" class="btn-primary">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="post-grid">
  <?php foreach ($posts['items'] as $post):
    $post_url = $base . '/article/' . htmlspecialchars($post['slug']);
    $first_cat = !empty($post['categories'][0]) ? $post['categories'][0] : 'General';
    $has_image = !empty($post['featured_image']);
    $words = str_word_count(strip_tags($post['content'] ?? ''));
    $read_time = max(1, (int)ceil($words / 200));
    $has_podcast = !empty($post['podcast_meta']['audio_url']) || !empty($post['podcast_audio']);
  ?>
  <article class="post-card">
    <a href="<?= $post_url ?>" class="card-media" aria-label="<?= htmlspecialchars($post['title'] ?? 'Artículo') ?>">
      <?php if ($has_image): ?>
        <img src="<?= htmlspecialchars($post['featured_image']) ?>"
             alt="<?= htmlspecialchars($post['title'] ?? '') ?>"
             loading="lazy">
      <?php else: ?>
        <div class="card-placeholder">
          <svg class="card-placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <circle cx="8.5" cy="8.5" r="1.5"></circle>
            <polyline points="21 15 16 10 5 21"></polyline>
          </svg>
        </div>
      <?php endif; ?>

      <div class="card-badges">
        <span class="card-category"><?= htmlspecialchars($first_cat) ?></span>
        <?php if ($has_podcast): ?>
        <span class="card-category" style="background:var(--accent);color:#fff" title="Episodio de podcast">🎙️ Audio</span>
        <?php endif; ?>
      </div>
    </a>

    <div class="card-content">
      <div class="card-meta">
        <time datetime="<?= $post['created_at'] ?? '' ?>"><?= format_date($post['created_at'] ?? 'now') ?></time>
        <span class="meta-dot"></span>
        <span><?= $read_time ?> min de lectura</span>
      </div>

      <h2 class="card-title">
        <a href="<?= $post_url ?>">
          <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
        </a>
      </h2>

      <?php
      $excerpt = $post['excerpt'] ?? '';
      if (!$excerpt && !empty($post['content'])) {
          $excerpt = mb_substr(strip_tags($post['content']), 0, 130) . '…';
      }
      ?>
      <?php if (!empty($excerpt)): ?>
      <p class="card-excerpt"><?= htmlspecialchars($excerpt) ?></p>
      <?php endif; ?>

      <a href="<?= $post_url ?>" class="card-footer" aria-label="Leer <?= htmlspecialchars($post['title'] ?? 'publicación') ?>">
        <span>Leer publicación</span>
        <span class="card-footer-arrow">→</span>
      </a>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<!-- ── Pagination ────────────────────────────────────────────────────────── -->
<?php if ($total_pages > 1):
  $max_links = 5;
  $start = max(1, $current_page - 2);
  $end   = min($total_pages, $current_page + 2);
  
  if ($current_page <= 3) {
    $start = 1;
    $end   = min($max_links, $total_pages);
  }
  if ($current_page >= $total_pages - 2) {
    $start = max(1, $total_pages - $max_links + 1);
    $end   = $total_pages;
  }
  
  $query_params = $_GET;
?>
<nav class="pagination" aria-label="Paginación de artículos">
  <?php if ($current_page > 1):
    $query_params['page'] = $current_page - 1;
  ?>
  <a href="?<?= http_build_query($query_params) ?>" class="page-btn" aria-label="Página anterior">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++):
    $query_params['page'] = $i;
  ?>
  <a href="?<?= http_build_query($query_params) ?>" class="page-btn <?= $i === $current_page ? 'active' : '' ?>">
    <?= $i ?>
  </a>
  <?php endfor; ?>

  <?php if ($current_page < $total_pages):
    $query_params['page'] = $current_page + 1;
  ?>
  <a href="?<?= http_build_query($query_params) ?>" class="page-btn" aria-label="Página siguiente">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
  </a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; ?>
