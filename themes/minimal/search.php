<?php
$count = count($results);
?>

<header class="page-header">
  <span class="page-header-badge">Búsqueda</span>
  <h1 class="page-header-title">Resultados para: &ldquo;<?= htmlspecialchars($query) ?>&rdquo;</h1>
  <p class="page-header-desc">
    <?= $count ?> <?= $count === 1 ? 'resultado encontrado' : 'resultados encontrados' ?>
  </p>
</header>

<?php if (empty($results)): ?>
<div class="empty-box">
  <div class="empty-code">🔍</div>
  <h2 class="empty-title">Sin coincidencias</h2>
  <p class="empty-desc">No hemos encontrado ningún contenido que coincida con tu búsqueda. Intenta con otras palabras clave.</p>
  <a href="<?= $base ?>/" class="btn-primary">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="post-grid">
  <?php foreach ($results as $item):
    $is_art = ($item['_type'] ?? '') === 'articles';
    $item_url = $base . '/' . ($is_art ? 'article' : 'page') . '/' . htmlspecialchars($item['slug']);
    $first_cat = !empty($item['categories'][0]) ? $item['categories'][0] : ($is_art ? 'Artículo' : 'Página');
    $has_image = !empty($item['featured_image']);
    $words = str_word_count(strip_tags($item['content'] ?? ''));
    $read_time = max(1, (int)ceil($words / 200));
  ?>
  <article class="post-card">
    <a href="<?= $item_url ?>" class="card-media" aria-label="<?= htmlspecialchars($item['title'] ?? '') ?>">
      <?php if ($has_image): ?>
        <img src="<?= htmlspecialchars($item['featured_image']) ?>"
             alt="<?= htmlspecialchars($item['title'] ?? '') ?>"
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
      </div>
    </a>

    <div class="card-content">
      <div class="card-meta">
        <time datetime="<?= $item['created_at'] ?? '' ?>"><?= format_date($item['created_at'] ?? 'now') ?></time>
        <span class="meta-dot"></span>
        <span><?= $read_time ?> min</span>
      </div>

      <h2 class="card-title">
        <a href="<?= $item_url ?>">
          <?= htmlspecialchars($item['title'] ?? 'Sin título') ?>
        </a>
      </h2>

      <?php
      $excerpt = $item['excerpt'] ?? '';
      if (!$excerpt && !empty($item['content'])) {
          $excerpt = mb_substr(strip_tags($item['content']), 0, 130) . '…';
      }
      ?>
      <?php if (!empty($excerpt)): ?>
      <p class="card-excerpt"><?= htmlspecialchars($excerpt) ?></p>
      <?php endif; ?>

      <a href="<?= $item_url ?>" class="card-footer" aria-label="Ver <?= htmlspecialchars($item['title'] ?? '') ?>">
        <span>Ver <?= $is_art ? 'artículo' : 'página' ?></span>
        <span class="card-footer-arrow">→</span>
      </a>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<?php endif; ?>
