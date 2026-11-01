<?php
$is_page = ($type ?? '') === 'pages';
$content = $post['content'] ?? '';

// Render markdown if needed
if (($post['content_format'] ?? '') === 'markdown' && function_exists('flux_markdown')) {
    $content = flux_markdown($content);
}

// Auto-embed YouTube URLs (plain text URLs → iframe)
$content = preg_replace_callback(
    '#(?:<p>)?\s*(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([\w\-]{11})[^\s<]*)\s*(?:</p>)?#i',
    function($m) {
        $vid_id = $m[2];
        return '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . $vid_id
             . '" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
    },
    $content
);

$article_mastodon_url = $post['mastodon_url'] ?? '';
$words = str_word_count(strip_tags($post['content'] ?? ''));
$read_time = max(1, (int)ceil($words / 200));
?>

<article class="article-wrap">

  <!-- ── Prominent Featured Hero Image (Miniatura First) ────────────────── -->
  <?php if (!empty($post['featured_image'])): ?>
  <div class="article-hero">
    <img src="<?= htmlspecialchars($post['featured_image']) ?>"
         alt="<?= htmlspecialchars($post['title'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <header class="article-header">
    <?php if (!$is_page): ?>
    <div class="article-meta-top">
      <?php foreach ($post['categories'] ?? [] as $cat): ?>
      <a href="<?= $base ?>/category/<?= urlencode($cat) ?>" class="article-category">
        <?= htmlspecialchars($cat) ?>
      </a>
      <?php endforeach; ?>
      <span class="meta-dot"></span>
      <time datetime="<?= $post['created_at'] ?? '' ?>"><?= format_date($post['created_at'] ?? 'now', 'full') ?></time>
      <span class="meta-dot"></span>
      <span><?= $read_time ?> min de lectura</span>
    </div>
    <?php endif; ?>

    <h1 class="article-title"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></h1>

    <?php if (!empty($post['excerpt'])): ?>
    <p class="article-lead"><?= htmlspecialchars($post['excerpt']) ?></p>
    <?php endif; ?>
  </header>

  <!-- ── Content Body ──────────────────────────────────────────────────── -->
  <div class="prose">
    <?= $content ?>
  </div>

  <!-- ── Tags ──────────────────────────────────────────────────────────── -->
  <?php if (!$is_page && !empty($post['tags'])): ?>
  <div class="article-tags">
    <?php foreach ($post['tags'] as $tag): ?>
    <a href="<?= $base ?>/tag/<?= urlencode($tag) ?>" class="tag-badge">#<?= htmlspecialchars($tag) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Back Link ─────────────────────────────────────────────────────── -->
  <div class="article-back-bar">
    <a href="<?= $base ?>/" class="article-back-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      <span><?= $is_page ? 'Volver al inicio' : 'Volver a los artículos' ?></span>
    </a>
  </div>

  <!-- ── Mastodon Comments ─────────────────────────────────────────────── -->
  <?php if (!$is_page && $article_mastodon_url): ?>
  <section class="comments-section" id="mastodon-comments">
    <div class="comments-header">
      <h3 style="font-size:1.25rem;font-weight:800;color:var(--text);margin:0">Comentarios desde Fediverso</h3>
      <p style="font-size:0.9rem;color:var(--text-muted);margin:0">
        Para unirte a la conversación, <a href="<?= htmlspecialchars($article_mastodon_url) ?>" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600">responde a esta publicación en Mastodon</a>.
      </p>
      <div id="mc-stats" class="comments-stats" style="display:none">
        <span id="mc-favs">⭐ 0</span>
        <span id="mc-boosts">🔁 0</span>
        <span id="mc-replies">💬 0</span>
      </div>
    </div>

    <div id="mc-loading" style="color:var(--text-muted);font-size:0.95rem;padding:1.5rem 0">Cargando comentarios…</div>
    <div id="mc-list" class="mc-list"></div>
    <div id="mc-error" style="display:none;color:var(--text-muted);font-size:0.9rem;padding:1rem 0"></div>
  </section>

  <script>
  (function() {
    const mastodonPostUrl = <?= json_encode($article_mastodon_url) ?>;
    const apiUrl = <?= json_encode(base_url() . '/api/mastodon') ?>;

    fetch(apiUrl + '?url=' + encodeURIComponent(mastodonPostUrl))
      .then(r => r.json())
      .then(data => {
        const loading = document.getElementById('mc-loading');
        if (loading) loading.style.display = 'none';

        if (data.error) {
          const err = document.getElementById('mc-error');
          if (err) {
            err.textContent = 'No se pudieron cargar los comentarios.';
            err.style.display = '';
          }
          return;
        }

        const stats = document.getElementById('mc-stats');
        if (stats) {
          document.getElementById('mc-favs').textContent    = '⭐ ' + data.favourites;
          document.getElementById('mc-boosts').textContent  = '🔁 ' + data.reblogs;
          document.getElementById('mc-replies').textContent = '💬 ' + data.replies_count;
          stats.style.display = 'flex';
        }

        const list = document.getElementById('mc-list');
        if (!list) return;

        if (!data.comments || !data.comments.length) {
          list.innerHTML = '<p style="color:var(--text-muted);font-size:0.9rem;padding:1rem 0">Aún no hay comentarios. ¡Sé la primera persona en responder en Mastodon!</p>';
          return;
        }

        data.comments.forEach(c => {
          const date   = new Date(c.created_at).toLocaleDateString(<?= json_encode(site_lang() === 'es' ? 'es-ES' : 'en-US') ?>, { day:'numeric', month:'short', year:'numeric' });
          const depth  = Math.min(c.depth || 0, 4);
          const indent = depth * 22;
          const div    = document.createElement('div');
          div.className = 'mc-comment';
          if (indent > 0) {
            div.style.marginLeft = indent + 'px';
            div.style.borderLeft = '3px solid var(--accent)';
          }
          div.innerHTML = `
            <div class="mc-author">
              <div class="mc-avatar">
                <a href="${c.author.url}" target="_blank" rel="noopener">
                  <img src="${c.author.avatar}" alt="${c.author.display_name}" width="36" height="36" style="border-radius:50%">
                </a>
              </div>
              <div class="mc-author-info">
                <strong><a href="${c.author.url}" target="_blank" rel="noopener" style="color:var(--text)">${c.author.display_name}</a></strong>
                <span>@${c.author.acct} · ${date}</span>
              </div>
            </div>
            <div class="mc-body">${c.content}</div>
          `;
          list.appendChild(div);
        });
      })
      .catch(() => {
        const loading = document.getElementById('mc-loading');
        if (loading) loading.style.display = 'none';
      });
  })();
  </script>
  <?php endif; ?>

</article>
