<?php
$count = count($results);
?>

<header class="feed-header">
  <div class="feed-filter-badge">Búsqueda</div>
  <h1 class="feed-filter-title">Resultados para: &ldquo;<?= htmlspecialchars($query) ?>&rdquo;</h1>
  <p class="feed-filter-count">
    <?= $count ?> <?= $count === 1 ? 'coincidencia encontrada' : 'coincidencias encontradas' ?>
  </p>
</header>

<?php if (empty($results)): ?>
<div class="empty-state">
  <div class="empty-icon">🔍</div>
  <h2 class="empty-title">Sin resultados</h2>
  <p class="empty-text">No encontramos ningún contenido que coincida con tu búsqueda. Intenta con otros términos.</p>
  <a href="<?= $base ?>/" class="btn-clean">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="posts-feed">
  <?php foreach ($results as $item):
    $is_art = ($item['_type'] ?? '') === 'articles';
    $item_url = $base . '/' . ($is_art ? 'article' : 'page') . '/' . htmlspecialchars($item['slug']);
    $first_cat = !empty($item['categories'][0]) ? $item['categories'][0] : ($is_art ? 'Artículo' : 'Página');
    $has_image = !empty($item['featured_image']);
    $words = str_word_count(strip_tags($item['content'] ?? ''));
    $read_time = max(1, (int)ceil($words / 200));

    $excerpt = $item['excerpt'] ?? '';
    if (!$excerpt && !empty($item['content'])) {
      $excerpt = mb_substr(strip_tags($item['content']), 0, 160) . '…';
    }
  ?>
  <article class="feed-item">
    <div class="item-meta">
      <span class="item-cat"><?= htmlspecialchars($first_cat) ?></span>
      <span class="meta-dot">·</span>
      <time datetime="<?= $item['created_at'] ?? '' ?>" class="item-date">
        <?= format_date($item['created_at'] ?? 'now') ?>
      </time>
      <span class="meta-dot">·</span>
      <span class="item-readtime"><?= $read_time ?> min de lectura</span>
    </div>

    <h2 class="item-title">
      <a href="<?= $item_url ?>">
        <?= htmlspecialchars($item['title'] ?? 'Sin título') ?>
      </a>
    </h2>

    <?php if ($has_image): ?>
    <a href="<?= $item_url ?>" class="item-media" aria-label="<?= htmlspecialchars($item['title'] ?? '') ?>">
      <img src="<?= htmlspecialchars($item['featured_image']) ?>"
           alt="<?= htmlspecialchars($item['title'] ?? '') ?>"
           loading="lazy">
    </a>
    <?php endif; ?>

    <?php if (!empty($excerpt)): ?>
    <p class="item-excerpt">
      <?= htmlspecialchars($excerpt) ?>
    </p>
    <?php endif; ?>

    <div class="item-footer">
      <a href="<?= $item_url ?>" class="item-read-link">
        <span>Leer <?= $is_art ? 'artículo' : 'página' ?></span>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="arrow-icon">
          <line x1="5" y1="12" x2="19" y2="12"></line>
          <polyline points="12 5 19 12 12 19"></polyline>
        </svg>
      </a>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

