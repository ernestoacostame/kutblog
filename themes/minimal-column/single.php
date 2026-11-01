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

$article_mastodon_url = $post['mastodon_url'] ?? '';
$words = str_word_count(strip_tags($post['content'] ?? ''));
$read_time = max(1, (int)ceil($words / 200));
?>

<article class="single-article">
  <!-- Volver -->
  <div class="article-top-nav">
    <a href="<?= $base ?>/" class="back-link">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      <span><?= $is_page ? 'Volver al inicio' : 'Volver a artículos' ?></span>
    </a>
  </div>

  <header class="single-header">
    <?php if (!$is_page): ?>
    <div class="single-meta">
      <?php foreach ($post['categories'] ?? [] as $cat): ?>
      <a href="<?= $base ?>/category/<?= urlencode($cat) ?>" class="item-cat">
        <?= htmlspecialchars($cat) ?>
      </a>
      <?php endforeach; ?>
      <span class="meta-dot">·</span>
      <time datetime="<?= $post['created_at'] ?? '' ?>" class="item-date">
        <?= format_date($post['created_at'] ?? 'now', 'full') ?>
      </time>
      <span class="meta-dot">·</span>
      <span class="item-readtime"><?= $read_time ?> min de lectura</span>
    </div>
    <?php endif; ?>

    <h1 class="single-title"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></h1>

    <?php if (!empty($post['excerpt'])): ?>
    <p class="single-lead"><?= htmlspecialchars($post['excerpt']) ?></p>
    <?php endif; ?>
  </header>

  <!-- Imagen destacada principal -->
  <?php if (!empty($post['featured_image'])): ?>
  <div class="single-hero-media">
    <img src="<?= htmlspecialchars($post['featured_image']) ?>"
         alt="<?= htmlspecialchars($post['title'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <!-- Cuerpo de contenido (Prose tipográfica) -->
  <div class="prose">
    <?= $content ?>
  </div>

  <!-- Etiquetas -->
  <?php if (!$is_page && !empty($post['tags'])): ?>
  <div class="single-tags">
    <span class="tags-label">Etiquetas:</span>
    <?php foreach ($post['tags'] as $tag): ?>
    <a href="<?= $base ?>/tag/<?= urlencode($tag) ?>" class="tag-chip">#<?= htmlspecialchars($tag) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Barra inferior para volver -->
  <div class="single-bottom-nav">
    <a href="<?= $base ?>/" class="back-link">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      <span><?= $is_page ? 'Volver al inicio' : 'Volver a artículos' ?></span>
    </a>
  </div>

  <!-- Comentarios de Mastodon / Fediverso (si están configurados) -->
  <?php if (!$is_page && $article_mastodon_url): ?>
  <section class="comments-block" id="mastodon-comments">
    <div class="comments-header">
      <h3 class="comments-title">Comentarios en el Fediverso</h3>
      <p class="comments-subtitle">
        Participa respondiendo a la publicación original en <a href="<?= htmlspecialchars($article_mastodon_url) ?>" target="_blank" rel="noopener">Mastodon</a>.
      </p>
      <div id="mc-stats" class="comments-stats" style="display:none">
        <span id="mc-favs">⭐ 0</span>
        <span id="mc-boosts">🔁 0</span>
        <span id="mc-replies">💬 0</span>
      </div>
    </div>

    <div id="mc-loading" class="comments-loading">Cargando comentarios…</div>
    <div id="mc-list" class="comments-list"></div>
    <div id="mc-error" class="comments-error" style="display:none"></div>
  </section>

  <script>
  (function() {
    const mastodonUrl = <?= json_encode($article_mastodon_url) ?>;
    if (!mastodonUrl) return;

    const parts = mastodonUrl.match(/^https?:\/\/([^/]+)\/@([^/]+)\/([0-9]+)/);
    if (!parts) {
      document.getElementById('mc-loading').style.display = 'none';
      return;
    }

    const host = parts[1];
    const statusId = parts[3];
    const apiUrl = `https://${host}/api/v1/statuses/${statusId}`;

    fetch(apiUrl)
      .then(r => r.json())
      .then(status => {
        const stats = document.getElementById('mc-stats');
        if (stats) {
          document.getElementById('mc-favs').textContent = `⭐ ${status.favourites_count ?? 0}`;
          document.getElementById('mc-boosts').textContent = `🔁 ${status.reblogs_count ?? 0}`;
          document.getElementById('mc-replies').textContent = `💬 ${status.replies_count ?? 0}`;
          stats.style.display = 'flex';
        }

        return fetch(`${apiUrl}/context`);
      })
      .then(r => r.json())
      .then(ctx => {
        const loading = document.getElementById('mc-loading');
        if (loading) loading.style.display = 'none';

        const replies = (ctx && ctx.descendants) ? ctx.descendants : [];
        const list = document.getElementById('mc-list');
        if (!list) return;

        if (replies.length === 0) {
          list.innerHTML = '<p class="no-comments">Aún no hay respuestas. ¡Sé el primero en responder en Mastodon!</p>';
          return;
        }

        list.innerHTML = replies.map(r => {
          const author = r.account;
          const authorName = escapeHtml(author.display_name || author.username);
          const authorUrl = escapeHtml(author.url);
          const avatar = escapeHtml(author.avatar_static || author.avatar);
          const handle = escapeHtml(`@${author.acct}`);
          const date = new Date(r.created_at).toLocaleDateString(<?= json_encode(site_lang() === 'es' ? 'es-ES' : 'en-US') ?>, { day: 'numeric', month: 'short', year: 'numeric' });
          const content = r.content;

          return `
            <div class="comment-item">
              <div class="comment-header">
                <img src="${avatar}" alt="${authorName}" class="comment-avatar" loading="lazy">
                <div class="comment-meta">
                  <a href="${authorUrl}" target="_blank" rel="noopener" class="comment-author">${authorName}</a>
                  <span class="comment-handle">${handle}</span>
                  <span class="meta-dot">·</span>
                  <time class="comment-date">${date}</time>
                </div>
              </div>
              <div class="comment-body prose">${content}</div>
            </div>
          `;
        }).join('');
      })
      .catch(err => {
        const loading = document.getElementById('mc-loading');
        if (loading) loading.style.display = 'none';
        const errDiv = document.getElementById('mc-error');
        if (errDiv) {
          errDiv.textContent = 'No se pudieron cargar los comentarios en este momento.';
          errDiv.style.display = 'block';
        }
      });

    function escapeHtml(str) {
      const d = document.createElement('div');
      d.textContent = str;
      return d.innerHTML;
    }
  })();
  </script>
  <?php endif; ?>
</article>

