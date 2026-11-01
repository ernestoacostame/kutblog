<?php
$is_page = ($type ?? '') === 'pages';
$content = $post['content'] ?? '';

// Render markdown si aplica
if (($post['content_format'] ?? '') === 'markdown' && function_exists('flux_markdown')) {
    $content = flux_markdown($content);
}

// Auto-embed YouTube URLs
$content = preg_replace_callback(
    '#(?:<p>)?\s*(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([\w\-]{11})[^\s<]*)\s*(?:</p>)?#i',
    function($m) {
        $vid_id = $m[2];
        return '<div class="video-container"><iframe src="https://www.youtube-nocookie.com/embed/' . $vid_id
             . '" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
    },
    $content
);

// Sanitizar enlaces y recursos file:/// para prevenir Security Error en navegadores
$content = preg_replace_callback('/<a\s+[^>]*href=["\']file:\/\/[^"\']*["\'][^>]*>(.*?)<\/a>/is', function($m) {
    return '<code>' . $m[1] . '</code>';
}, $content);
$content = preg_replace('/<(?:img|audio|video|source)\s+[^>]*src=["\']file:\/\/[^"\']*["\'][^>]*>/is', '', $content);

$featured_image = $post['featured_image'] ?? '';
if (str_starts_with($featured_image, 'file://')) {
    $featured_image = '';
}

$article_mastodon_url = $post['mastodon_url'] ?? '';
$words = str_word_count(strip_tags($post['content'] ?? ''));
$read_time = max(1, (int)ceil($words / 200));

$ts = !empty($post['created_at']) ? strtotime($post['created_at']) : time();
$day = date('d', $ts);
$month_short = ucfirst(month_name((int)date('n', $ts), true));
$month_str = mb_strtoupper($month_short);
$year = date('Y', $ts);
$weekday = ucfirst(day_name((int)date('N', $ts), false));
$time_str = date('H:i', $ts);
$date_full = format_date($ts, 'full');

// Recent posts for sidebar timeline
$recent_articles = function_exists('list_content') ? list_content('articles', true, 1, 6) : ['items' => []];
?>

<div class="calendar-two-columns">
  <!-- ── COLUMNA IZQUIERDA: Contenido del Artículo ──────────────────────── -->
  <article class="calendar-article-col" id="article-content-wrapper">
    <!-- Navegación superior -->
    <div class="single-top-bar">
      <a href="<?= $base ?>/" class="back-timeline-btn">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 19"></polyline>
        </svg>
        <span><?= $is_page ? 'Volver al inicio' : 'Volver a la cronología' ?></span>
      </a>
    </div>

    <!-- Encabezado del artículo -->
    <header class="single-header">
      <?php if (!$is_page): ?>
      <div class="single-meta">
        <div class="card-date-badge">
          <span class="badge-day"><?= $day ?></span>
          <span class="badge-month"><?= $month_short ?></span>
        </div>
        <div class="single-meta-chips">
          <?php foreach ($post['categories'] ?? [] as $cat): ?>
          <a href="<?= $base ?>/category/<?= urlencode($cat) ?>" class="card-cat-link">
            <?= htmlspecialchars($cat) ?>
          </a>
          <span class="meta-dot">·</span>
          <?php endforeach; ?>
          <span class="card-readtime"><?= $read_time ?> min de lectura</span>
          <span class="meta-dot">·</span>
          <time datetime="<?= $post['created_at'] ?? '' ?>" class="single-date-str"><?= $date_full ?></time>
        </div>
      </div>
      <?php endif; ?>

      <h1 class="single-title"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></h1>

      <?php if (!empty($post['excerpt'])): ?>
      <p class="single-excerpt"><?= htmlspecialchars($post['excerpt']) ?></p>
      <?php endif; ?>
    </header>

    <!-- Imagen destacada -->
    <?php if (!empty($featured_image)): ?>
    <div class="single-featured-image">
      <img src="<?= htmlspecialchars($featured_image) ?>"
           alt="<?= htmlspecialchars($post['title'] ?? '') ?>">
    </div>
    <?php endif; ?>

    <!-- Reproductor de Audio / Podcast (si aplica) -->
    <?php
    $podcast_audio = $post['podcast_meta']['audio_url'] ?? $post['podcast_audio'] ?? null;
    if ($podcast_audio):
    ?>
    <div class="podcast-player-box">
      <div class="podcast-player-badge">🎙️ Episodio de Podcast</div>
      <audio controls preload="metadata" class="podcast-native-audio">
        <source src="<?= htmlspecialchars($podcast_audio) ?>" type="audio/mpeg">
        Tu navegador no soporta reproducción de audio.
      </audio>
    </div>
    <?php endif; ?>

    <!-- Contenido Prose -->
    <div class="calendar-prose">
      <?= $content ?>
    </div>

    <!-- Etiquetas -->
    <?php if (!$is_page && !empty($post['tags'])): ?>
    <div class="single-tags-list">
      <span class="tags-title">Etiquetas:</span>
      <?php foreach ($post['tags'] as $tag): ?>
      <a href="<?= $base ?>/tag/<?= urlencode($tag) ?>" class="single-tag-chip">#<?= htmlspecialchars($tag) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Navegación inferior -->
    <div class="single-bottom-bar">
      <a href="<?= $base ?>/" class="back-timeline-btn">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 19"></polyline>
        </svg>
        <span><?= $is_page ? 'Volver al inicio' : 'Volver a la cronología' ?></span>
      </a>

      <button type="button" class="btn-share" id="copy-article-link-btn">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
        <span id="copy-btn-text">Copiar enlace</span>
      </button>
    </div>

    <!-- Comentarios de Mastodon / Fediverso (si están configurados) -->
    <?php if (!$is_page && $article_mastodon_url): ?>
    <section class="comments-fediverse-card" id="mastodon-comments">
      <div class="comments-card-header">
        <div class="comments-title">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
            <path d="M21.327 8.566c0-4.339-2.843-5.61-2.843-5.61-1.433-.658-3.894-.935-6.451-.956h-.063c-2.557.021-5.016.298-6.45.956 0 0-2.843 1.272-2.843 5.61 0 .993-.019 2.18.049 3.48.2 3.822 2.109 7.423 5.294 7.609 2.05.12 3.864-.53 4.908-1.144l-.105-1.55c-1.353.432-2.73.468-4.04.14-1.286-.32-1.92-1.12-1.996-2.072 1.05.257 2.14.398 3.25.407.72.006 1.44-.04 2.16-.135 2.17-.286 4.07-.94 4.07-3.21 0-.69-.02-1.42-.04-2.19-.04-1.57-.12-3.08-.12-3.08zm-3.32 5.09h-1.97v-4.57c0-1.18-.5-1.78-1.5-1.78-1.1 0-1.65.71-1.65 2.14v2.7h-1.74v-2.7c0-1.43-.55-2.14-1.65-2.14-1 0-1.5.6-1.5 1.78v4.57H5.97v-4.81c0-1.18.3-2.12.91-2.81.63-.7 1.48-1.06 2.53-1.06 1.23 0 2.19.47 2.87 1.42l.68 1.15.68-1.15c.68-.95 1.64-1.42 2.87-1.42 1.05 0 1.9.36 2.53 1.06.61.69.91 1.63.91 2.81v4.81z"/>
          </svg>
          <span>Respuestas desde el Fediverso</span>
        </div>
        <a href="<?= htmlspecialchars($article_mastodon_url) ?>" target="_blank" rel="noopener noreferrer" class="btn-reply-fediverse">
          Responder en Mastodon ↗
        </a>
      </div>
      <div id="mastodon-comments-list" class="comments-stream">
        <p class="comments-loading">Cargando conversación federada…</p>
      </div>
    </section>

    <script>
    (function() {
      const url = <?= json_encode($article_mastodon_url) ?>;
      if (!url) return;
      const match = url.match(/https?:\/\/([^/]+)\/@([^/]+)\/(\d+)/);
      if (!match) return;
      const [, instance, , statusId] = match;
      const container = document.getElementById('mastodon-comments-list');

      fetch(`https://${instance}/api/v1/statuses/${statusId}/context`)
        .then(r => r.json())
        .then(data => {
          const descendants = data.descendants || [];
          if (descendants.length === 0) {
            container.innerHTML = '<p class="comments-empty">No hay respuestas aún. ¡Sé el primero en responder desde Mastodon!</p>';
            return;
          }
          let html = '';
          descendants.forEach(d => {
            const date = new Date(d.created_at).toLocaleDateString(<?= json_encode(site_lang() === 'es' ? 'es-ES' : 'en-US') ?>, { day: 'numeric', month: 'short', year: 'numeric' });
            html += `
              <div class="fediverse-comment-item">
                <img src="${d.account.avatar_static}" alt="${d.account.display_name}" class="fedi-avatar" loading="lazy">
                <div class="fedi-content">
                  <div class="fedi-meta">
                    <strong class="fedi-name">${d.account.display_name}</strong>
                    <span class="fedi-handle">@${d.account.acct}</span>
                    <span class="meta-dot">·</span>
                    <time class="fedi-date">${date}</time>
                  </div>
                  <div class="fedi-body">${d.content}</div>
                </div>
              </div>`;
          });
          container.innerHTML = html;
        })
        .catch(() => {
          container.innerHTML = '<p class="comments-empty">No se pudieron cargar los comentarios en este momento.</p>';
        });
    })();
    </script>
    <?php endif; ?>
  </article>

  <!-- ── COLUMNA DERECHA: Sticky Calendar, Progreso y Navegación ────────── -->
  <aside class="calendar-timeline-aside">
    <div class="timeline-sticky-box">
      <!-- Tarjeta del Calendario de Publicación -->
      <div class="calendar-active-widget">
        <div class="widget-calendar-card">
          <div class="cal-card-header">
            <span class="cal-card-month"><?= $month_str ?></span>
            <span class="cal-card-year"><?= $year ?></span>
          </div>
          <div class="cal-card-body">
            <span class="cal-card-day"><?= $day ?></span>
            <span class="cal-card-weekday"><?= $weekday ?></span>
          </div>
        </div>

        <div class="widget-post-info">
          <div class="widget-label">Fecha de publicación</div>
          <div class="widget-publish-time">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span><?= $time_str ?> hrs</span>
          </div>
          <div class="widget-active-title" style="margin-top:0.35rem">
            <?= htmlspecialchars($post['title'] ?? '') ?>
          </div>
        </div>
      </div>

      <!-- Barra de Progreso de Lectura -->
      <div class="reading-progress-card">
        <div class="progress-info">
          <span class="progress-label">Progreso de lectura</span>
          <span class="progress-percent" id="reading-percent">0%</span>
        </div>
        <div class="progress-track">
          <div class="progress-fill" id="reading-fill" style="width: 0%"></div>
        </div>
      </div>

      <!-- Mini Cronología de Artículos Recientes -->
      <?php if (!empty($recent_articles['items'])): ?>
      <div class="timeline-scroll-container">
        <div class="timeline-header">
          <span class="timeline-title">Otras publicaciones</span>
        </div>

        <div class="timeline-track">
          <div class="timeline-line"></div>
          <?php foreach ($recent_articles['items'] as $item):
            $i_ts = !empty($item['created_at']) ? strtotime($item['created_at']) : time();
            $i_day = date('d', $i_ts);
            $i_month = ucfirst(month_name((int)date('n', $i_ts), true));
            $is_current_post = ($item['slug'] === ($post['slug'] ?? ''));
          ?>
            <a
              href="<?= $base ?>/article/<?= htmlspecialchars($item['slug']) ?>"
              class="timeline-node <?= $is_current_post ? 'is-active is-current-view' : '' ?>"
              title="<?= htmlspecialchars($item['title'] ?? '') ?>"
            >
              <div class="timeline-node-bullet">
                <span class="bullet-inner"></span>
              </div>
              <div class="timeline-node-content">
                <div class="node-date"><?= $i_day ?> <?= $i_month ?></div>
                <div class="node-title"><?= htmlspecialchars($item['title'] ?? 'Sin título') ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // ── Copiar enlace al portapapeles ─────────────────────────────────────────
  const copyBtn = document.getElementById('copy-article-link-btn');
  const copyBtnText = document.getElementById('copy-btn-text');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      navigator.clipboard.writeText(window.location.href).then(() => {
        copyBtnText.textContent = '¡Copiado!';
        copyBtn.style.borderColor = 'var(--accent)';
        copyBtn.style.color = 'var(--accent)';
        setTimeout(() => {
          copyBtnText.textContent = 'Copiar enlace';
          copyBtn.style.borderColor = '';
          copyBtn.style.color = '';
        }, 2000);
      });
    });
  }

  // ── Indicador de Progreso de Lectura con requestAnimationFrame ───────────
  const readingFill = document.getElementById('reading-fill');
  const readingPercent = document.getElementById('reading-percent');
  const articleEl = document.getElementById('article-content-wrapper');

  if (readingFill && readingPercent && articleEl) {
    let ticking = false;

    function updateProgress() {
      const rect = articleEl.getBoundingClientRect();
      const totalHeight = rect.height - window.innerHeight;
      if (totalHeight <= 0) {
        readingFill.style.width = '100%';
        readingPercent.textContent = '100%';
        return;
      }
      const scrolled = -rect.top;
      const percent = Math.min(100, Math.max(0, Math.round((scrolled / totalHeight) * 100)));
      readingFill.style.width = percent + '%';
      readingPercent.textContent = percent + '%';
    }

    function onScrollProgress() {
      if (!ticking) {
        requestAnimationFrame(() => {
          updateProgress();
          ticking = false;
        });
        ticking = true;
      }
    }

    window.addEventListener('scroll', onScrollProgress, { passive: true });
    window.addEventListener('resize', onScrollProgress, { passive: true });
    updateProgress();
  }
});
</script>

