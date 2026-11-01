<?php
$query = htmlspecialchars($query ?? '');
$count = count($results ?? []);
?>

<div class="calendar-filter-bar">
  <div class="filter-badge">Búsqueda</div>
  <h1 class="filter-title">Resultados para: «<?= $query ?>»</h1>
  <p class="filter-subtitle"><?= $count === 1 ? '1 resultado encontrado' : $count . ' resultados encontrados' ?></p>
  <a href="<?= $base ?>/" class="filter-clear-link">✕ Ver todas las publicaciones</a>
</div>

<?php if (empty($results)): ?>
<div class="calendar-empty">
  <div class="empty-icon">🔍</div>
  <h2>Sin coincidencias</h2>
  <p>No se encontraron publicaciones que coincidan con tu búsqueda. Intenta con otras palabras clave.</p>
  <a href="<?= $base ?>/" class="calendar-btn">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="calendar-two-columns">
  <section class="calendar-posts-stream">
    <?php foreach ($results as $idx => $post):
      $post_url = $base . '/article/' . htmlspecialchars($post['slug']);
      $ts = !empty($post['created_at']) ? strtotime($post['created_at']) : time();
      $day = date('d', $ts);
      $month_short = ucfirst(month_name((int)date('n', $ts), true));
      $year = date('Y', $ts);
      $first_cat = !empty($post['categories'][0]) ? $post['categories'][0] : 'General';
      $words = str_word_count(strip_tags($post['content'] ?? ''));
      $read_time = max(1, (int)ceil($words / 200));

      $excerpt = $post['excerpt'] ?? '';
      if (!$excerpt && !empty($post['content'])) {
        $excerpt = mb_substr(strip_tags($post['content']), 0, 160) . '…';
      }
    ?>
    <?php $has_image = !empty($post['featured_image']); ?>
    <article class="calendar-card <?= $has_image ? 'has-thumb' : '' ?>" id="post-<?= htmlspecialchars($post['slug']) ?>">
      <?php if ($has_image): ?>
      <a href="<?= $post_url ?>" class="card-thumb" aria-label="<?= htmlspecialchars($post['title'] ?? '') ?>">
        <img src="<?= htmlspecialchars($post['featured_image']) ?>"
             alt="<?= htmlspecialchars($post['title'] ?? '') ?>"
             loading="lazy">
      </a>
      <?php endif; ?>

      <div class="card-body">
        <div class="card-meta">
          <span class="card-date-badge"><?= $day ?> <?= $month_short ?></span>
          <a href="<?= $base ?>/category/<?= urlencode($first_cat) ?>" class="card-cat-link">
            <?= htmlspecialchars($first_cat) ?>
          </a>
          <span class="meta-dot">·</span>
          <span class="card-readtime"><?= $read_time ?> min</span>
          <span class="meta-dot">·</span>
          <time datetime="<?= $post['created_at'] ?? '' ?>"><?= $year ?></time>
        </div>

        <h2 class="card-title">
          <a href="<?= $post_url ?>">
            <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
          </a>
        </h2>

        <?php if (!empty($excerpt)): ?>
        <p class="card-excerpt">
          <?= htmlspecialchars($excerpt) ?>
        </p>
        <?php endif; ?>

        <div class="card-actions">
          <a href="<?= $post_url ?>" class="card-read-more">
            <span>Leer</span>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="5" y1="12" x2="19" y2="12"></line>
              <polyline points="12 5 19 12 12 19"></polyline>
            </svg>
          </a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </section>

  <!-- Columna lateral informativa -->
  <aside class="calendar-timeline-aside">
    <div class="timeline-sticky-box">
      <div class="calendar-active-widget">
        <div class="widget-calendar-card">
          <div class="cal-card-header">
            <span class="cal-card-month">BÚSQUEDA</span>
            <span class="cal-card-year">RESULTADOS</span>
          </div>
          <div class="cal-card-body">
            <span class="cal-card-day"><?= $count ?></span>
            <span class="cal-card-weekday">Encontrados</span>
          </div>
        </div>
        <div class="widget-post-info">
          <div class="widget-label">Término buscado</div>
          <div class="widget-active-title">«<?= $query ?>»</div>
        </div>
      </div>
    </div>
  </aside>
</div>

<?php endif; ?>

