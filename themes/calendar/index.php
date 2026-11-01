<?php
$heading = isset($category) ? 'Categoría: ' . $category :
           (isset($tag) ? 'Etiqueta: #' . $tag : null);

$current_page = $posts['page'] ?? 1;
$total_pages  = $posts['pages'] ?? 1;
?>

<?php if ($heading): ?>
<div class="calendar-filter-bar">
  <div class="filter-badge"><?= isset($category) ? 'Categoría' : 'Etiqueta' ?></div>
  <h1 class="filter-title"><?= htmlspecialchars($heading) ?></h1>
  <a href="<?= $base ?>/" class="filter-clear-link">✕ Ver todas las publicaciones</a>
</div>
<?php endif; ?>

<?php if (empty($posts['items'])): ?>
<div class="calendar-empty">
  <div class="empty-icon">📅</div>
  <h2>No hay publicaciones aún</h2>
  <p>Aún no se han publicado artículos en esta sección.</p>
  <a href="<?= $base ?>/" class="calendar-btn">← Volver al inicio</a>
</div>
<?php else: ?>

<div class="calendar-two-columns">
  <!-- ── COLUMNA IZQUIERDA: Artículos (Estilo compacto SystemInside) ──────── -->
  <section class="calendar-posts-stream" id="posts-stream">
    <?php
    $first_post_info = null;
    foreach ($posts['items'] as $idx => $post):
      $post_url = $base . '/article/' . htmlspecialchars($post['slug']);
      $ts = !empty($post['created_at']) ? strtotime($post['created_at']) : time();
      $day = date('d', $ts);
      $month_short = ucfirst(month_name((int)date('n', $ts), true));
      $month_str = mb_strtoupper($month_short);
      $year = date('Y', $ts);
      $weekday = ucfirst(day_name((int)date('N', $ts), false));
      $date_formatted = format_date($ts, 'full');

      $first_cat = !empty($post['categories'][0]) ? $post['categories'][0] : null;
      $has_image = !empty($post['featured_image']);
      $words = str_word_count(strip_tags($post['content'] ?? ''));
      $read_time = max(1, (int)ceil($words / 200));
      $has_podcast = !empty($post['podcast_meta']['audio_url']) || !empty($post['podcast_audio']);

      $excerpt = $post['excerpt'] ?? '';
      if (!$excerpt && !empty($post['content'])) {
        $excerpt = mb_substr(strip_tags($post['content']), 0, 140) . '…';
      }

      if ($idx === 0) {
        $first_post_info = [
            'day' => $day,
            'month' => $month_str,
            'year' => $year,
            'weekday' => $weekday,
            'title' => $post['title'] ?? 'Sin título'
        ];
      }
    ?>
    <article
      class="calendar-card <?= $has_image ? 'has-thumb' : '' ?> <?= $idx === 0 ? 'is-active-card' : '' ?>"
      id="post-<?= htmlspecialchars($post['slug']) ?>"
      data-post-slug="<?= htmlspecialchars($post['slug']) ?>"
      data-day="<?= $day ?>"
      data-month="<?= $month_str ?>"
      data-year="<?= $year ?>"
      data-weekday="<?= $weekday ?>"
      data-title="<?= htmlspecialchars($post['title'] ?? 'Sin título') ?>"
    >
      <?php if ($has_image): ?>
      <a href="<?= $post_url ?>" class="card-thumb" aria-label="<?= htmlspecialchars($post['title'] ?? '') ?>">
        <img src="<?= htmlspecialchars($post['featured_image']) ?>"
             alt="<?= htmlspecialchars($post['title'] ?? '') ?>"
             loading="<?= $idx === 0 ? 'eager' : 'lazy' ?>">
      </a>
      <?php endif; ?>

      <div class="card-body">
        <!-- Meta con fecha e información -->
        <div class="card-meta">
          <span class="card-date-badge"><?= $day ?> <?= $month_short ?></span>
          <?php if ($first_cat): ?>
            <a href="<?= $base ?>/category/<?= urlencode($first_cat) ?>" class="card-cat-link">
              <?= htmlspecialchars($first_cat) ?>
            </a>
            <span class="meta-dot">·</span>
          <?php endif; ?>
          <span class="card-readtime"><?= $read_time ?> min</span>
          <?php if ($has_podcast): ?>
            <span class="meta-dot">·</span>
            <span class="card-podcast-badge" title="Episodio con audio disponible">🎙️ Podcast</span>
          <?php endif; ?>
        </div>

        <!-- Título -->
        <h2 class="card-title">
          <a href="<?= $post_url ?>">
            <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
          </a>
        </h2>

        <!-- Extracto -->
        <?php if (!empty($excerpt)): ?>
        <p class="card-excerpt">
          <?= htmlspecialchars($excerpt) ?>
        </p>
        <?php endif; ?>

        <!-- Enlace Leer -->
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

    <!-- Paginación -->
    <?php if ($total_pages > 1):
      $max_links = 5;
      $start = max(1, $current_page - 2);
      $end   = min($total_pages, $start + $max_links - 1);
      if ($end - $start < $max_links - 1) {
        $start = max(1, $end - $max_links + 1);
      }
      $query_params = $_GET;
    ?>
    <nav class="calendar-pagination" aria-label="Páginas de publicaciones">
      <?php if ($current_page > 1):
        $query_params['page'] = $current_page - 1;
      ?>
        <a href="<?= $base ?>/?<?= http_build_query($query_params) ?>" class="page-step">← Anterior</a>
      <?php endif; ?>

      <div class="page-numbers">
        <?php for ($p = $start; $p <= $end; $p++):
          $query_params['page'] = $p;
          $is_current = ($p == $current_page);
        ?>
          <a href="<?= $base ?>/?<?= http_build_query($query_params) ?>"
             class="page-num <?= $is_current ? 'is-active' : '' ?>"
             <?= $is_current ? 'aria-current="page"' : '' ?>>
            <?= $p ?>
          </a>
        <?php endfor; ?>
      </div>

      <?php if ($current_page < $total_pages):
        $query_params['page'] = $current_page + 1;
      ?>
        <a href="<?= $base ?>/?<?= http_build_query($query_params) ?>" class="page-step">Siguiente →</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>

  <!-- ── COLUMNA DERECHA: Barra Lateral Fija (Timeline & Calendario) ────── -->
  <aside class="calendar-timeline-aside">
    <div class="timeline-sticky-box" id="timeline-sticky-box">
      <!-- Widget Visual del Calendario Dinámico (Simple y recto) -->
      <div class="calendar-active-widget">
        <div class="widget-calendar-card">
          <div class="cal-card-header">
            <span class="cal-card-month" id="cal-widget-month"><?= $first_post_info['month'] ?? mb_strtoupper(month_name((int)date('n'), true)) ?></span>
            <span class="cal-card-year" id="cal-widget-year"><?= $first_post_info['year'] ?? date('Y') ?></span>
          </div>
          <div class="cal-card-body">
            <span class="cal-card-day" id="cal-widget-day"><?= $first_post_info['day'] ?? date('d') ?></span>
            <span class="cal-card-weekday" id="cal-widget-weekday"><?= $first_post_info['weekday'] ?? ucfirst(day_name((int)date('N'), false)) ?></span>
          </div>
        </div>

        <div class="widget-post-info">
          <div class="widget-label">Publicación enfocada</div>
          <div class="widget-active-title" id="cal-widget-title">
            <?= htmlspecialchars($first_post_info['title'] ?? 'Explorar cronología') ?>
          </div>
        </div>
      </div>

      <!-- Barra de Línea de Tiempo (Timeline interactivo) -->
      <div class="timeline-scroll-container">
        <div class="timeline-header">
          <span class="timeline-title">Línea de tiempo</span>
          <span class="timeline-count"><?= count($posts['items']) ?></span>
        </div>

        <div class="timeline-track" id="timeline-track">
          <div class="timeline-line"></div>
          <?php
          $current_year_group = null;
          foreach ($posts['items'] as $idx => $post):
            $ts = !empty($post['created_at']) ? strtotime($post['created_at']) : time();
            $post_year = date('Y', $ts);
            $post_day = date('d', $ts);
            $month_short = ucfirst(month_name((int)date('n', $ts), true));
            $slug = htmlspecialchars($post['slug']);

            if ($post_year !== $current_year_group):
              $current_year_group = $post_year;
          ?>
            <div class="timeline-year-marker"><?= $post_year ?></div>
          <?php endif; ?>

            <div
              class="timeline-node <?= $idx === 0 ? 'is-active' : '' ?>"
              id="timeline-node-<?= $slug ?>"
              data-target="post-<?= $slug ?>"
              role="button"
              tabindex="0"
              title="Ir a: <?= htmlspecialchars($post['title'] ?? '') ?>"
            >
              <div class="timeline-node-bullet">
                <span class="bullet-inner"></span>
              </div>
              <div class="timeline-node-content">
                <div class="node-date"><?= $post_day ?> <?= $month_short ?></div>
                <div class="node-title"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </aside>
</div>

<!-- ── Script de sincronización interactiva Timeline & Scroll ─────────── -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const posts = Array.from(document.querySelectorAll('.calendar-card'));
  const timelineNodes = Array.from(document.querySelectorAll('.timeline-node'));
  const trackContainer = document.querySelector('.timeline-scroll-container');

  const widgetMonth   = document.getElementById('cal-widget-month');
  const widgetYear    = document.getElementById('cal-widget-year');
  const widgetDay     = document.getElementById('cal-widget-day');
  const widgetWeekday = document.getElementById('cal-widget-weekday');
  const widgetTitle   = document.getElementById('cal-widget-title');

  if (!posts.length || !timelineNodes.length) return;

  let activeSlug = null;
  let isUserClicking = false;
  let clickTimeout = null;

  function setActivePost(postEl, smoothScrollTimeline = true) {
    const slug = postEl.getAttribute('data-post-slug');
    if (!slug || slug === activeSlug) return;
    activeSlug = slug;

    // 1. Actualizar clase en post cards
    posts.forEach(p => {
      p.classList.toggle('is-active-card', p === postEl);
    });

    // 2. Actualizar nodos del timeline
    let activeNode = null;
    timelineNodes.forEach(node => {
      const match = node.getAttribute('data-target') === 'post-' + slug;
      node.classList.toggle('is-active', match);
      if (match) activeNode = node;
    });

    // 3. Actualizar widget visual del calendario
    if (widgetDay && widgetMonth && widgetYear) {
      widgetDay.textContent = postEl.getAttribute('data-day') || '';
      widgetMonth.textContent = postEl.getAttribute('data-month') || '';
      widgetYear.textContent = postEl.getAttribute('data-year') || '';
      if (widgetWeekday) {
        widgetWeekday.textContent = postEl.getAttribute('data-weekday') || '';
      }
      if (widgetTitle) {
        widgetTitle.textContent = postEl.getAttribute('data-title') || '';
      }
    }

    // 4. Auto scroll dentro del timeline si es necesario
    if (smoothScrollTimeline && activeNode && trackContainer) {
      const containerRect = trackContainer.getBoundingClientRect();
      const nodeRect = activeNode.getBoundingClientRect();
      if (nodeRect.top < containerRect.top + 30 || nodeRect.bottom > containerRect.bottom - 30) {
        activeNode.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    }
  }

  // Clic en nodo del timeline -> scroll suave al artículo
  timelineNodes.forEach(node => {
    function jumpToPost(e) {
      e.preventDefault();
      const targetId = node.getAttribute('data-target');
      const targetEl = document.getElementById(targetId);
      if (targetEl) {
        isUserClicking = true;
        clearTimeout(clickTimeout);

        setActivePost(targetEl, false);

        const headerOffset = 70;
        const elementPosition = targetEl.getBoundingClientRect().top;
        const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

        window.scrollTo({
          top: offsetPosition,
          behavior: 'smooth'
        });

        clickTimeout = setTimeout(() => {
          isUserClicking = false;
        }, 800);
      }
    }

    node.addEventListener('click', jumpToPost);
    node.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        jumpToPost(e);
      }
    });
  });

  // IntersectionObserver para detectar cuál artículo está enfocado
  const observerOptions = {
    root: null,
    rootMargin: '-10% 0px -60% 0px',
    threshold: [0, 0.2, 0.5, 0.8]
  };

  const observer = new IntersectionObserver((entries) => {
    if (isUserClicking) return;

    const visibleEntries = entries.filter(e => e.isIntersecting);
    if (visibleEntries.length > 0) {
      visibleEntries.sort((a, b) => b.intersectionRatio - a.intersectionRatio);
      setActivePost(visibleEntries[0].target);
    }
  }, observerOptions);

  posts.forEach(p => observer.observe(p));

  // Fallback para scroll fino al llegar al top
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (ticking || isUserClicking) return;
    ticking = true;
    requestAnimationFrame(() => {
      if (window.scrollY < 80 && posts[0]) {
        setActivePost(posts[0]);
      }
      ticking = false;
    });
  }, { passive: true });
});
</script>

<?php endif; ?>
