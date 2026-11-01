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

// Mastodon verification link from config
$mastodon_handle = cms_config()['mastodon_handle'] ?? '';
$mastodon_url    = cms_config()['mastodon_url'] ?? '';
$article_mastodon_url = $post['mastodon_url'] ?? '';
?>

<article class="single-article">

  <?php if (!empty($post['featured_image'])): ?>
  <div class="featured-image">
    <img src="<?= htmlspecialchars($post['featured_image']) ?>"
         alt="<?= htmlspecialchars($post['title']) ?>">
  </div>
  <?php endif; ?>

  <div class="article-inner">

    <?php if (!$is_page): ?>
    <div class="article-meta">
      <?php foreach ($post['categories'] ?? [] as $cat): ?>
      <a href="<?= $base ?>/category/<?= urlencode($cat) ?>" class="meta-cat"><?= htmlspecialchars($cat) ?></a>
      <?php endforeach; ?>
      <span class="meta-sep">·</span>
      <time class="meta-date">
        <?= format_date($post['created_at'] ?? 'now', 'full') ?>
      </time>
    </div>
    <?php endif; ?>

    <h1 class="article-title"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></h1>

    <?php if (!empty($post['excerpt'])): ?>
    <p class="article-lead"><?= htmlspecialchars($post['excerpt']) ?></p>
    <?php endif; ?>

    <div class="prose">
      <?= $content ?>
    </div>

    <?php if (!$is_page && !empty($post['tags'])): ?>
    <div class="article-tags">
      <?php foreach ($post['tags'] as $tag): ?>
      <a href="<?= $base ?>/tag/<?= urlencode($tag) ?>" class="tag-pill">#<?= htmlspecialchars($tag) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="article-back">
      <a href="<?= $base ?>/">← <?= $is_page ? 'Volver al inicio' : 'Volver a los artículos' ?></a>
    </div>

  </div>
</article>

<?php if (!$is_page && $article_mastodon_url): ?>
<!-- ── Mastodon Comments ─────────────────────────────────────────────── -->
<div class="mastodon-comments" id="mastodon-comments">
  <div class="mc-header">
    <svg width="20" height="20" viewBox="0 0 216.4 232" fill="currentColor" style="color:var(--accent);flex-shrink:0"><path d="M211.8 139.7c-3.7 19-33.1 39.8-66.9 43.9-17.6 2.1-34.9 4-53.4 3.2-30.2-1.4-54-7.2-54-7.2 0 2.9.2 5.7.5 8.4 3.7 27.9 27.7 29.5 50.5 30.3 22.9.8 43.4-5.6 43.4-5.6l.9 20.6s-16 8.6-44.6 10.2c-15.7.9-35.2-.4-58-6.2C9.7 221.9 1.4 182.9.1 143.3c-.4-10.8-.1-21-.1-29.5 0-37.3 24.4-48.2 24.4-48.2 12.3-5.6 33.4-8 55.3-8.2h.5c21.9.2 43 2.6 55.3 8.2 0 0 24.4 10.9 24.4 48.2 0 0 .3 27.5-3.5 37.9z"/><path fill="#fff" d="M178.4 73.3v65.3h-25.8V75.3c0-13.4-5.6-20.1-16.9-20.1-12.4 0-18.7 8-18.7 23.9v34.6H91.5V79.1c0-15.9-6.2-23.9-18.7-23.9-11.2 0-16.9 6.7-16.9 20.1v63.3H30.1V73.3c0-13.3 3.4-23.9 10.2-31.7 7-7.8 16.2-11.8 27.6-11.8 13.2 0 23.2 5.1 29.8 15.2l6.4 10.8 6.4-10.8c6.6-10.1 16.6-15.2 29.8-15.2 11.4 0 20.6 4 27.6 11.8 6.9 7.8 10.3 18.4 10.3 31.7z"/></svg>
    <div>
      <h3 style="font-size:1rem;font-weight:700;margin:0">Comentarios desde Mastodon</h3>
      <p style="font-size:0.82rem;color:var(--muted);margin:0">
        Para comentar, <a href="<?= htmlspecialchars($article_mastodon_url) ?>" target="_blank" rel="noopener" style="color:var(--accent)">responde a este post en Mastodon</a>.
      </p>
    </div>
  </div>

  <div id="mc-stats" class="mc-stats" style="display:none">
    <span id="mc-favs">⭐ 0</span>
    <span id="mc-boosts">🔁 0</span>
    <span id="mc-replies">💬 0</span>
  </div>

  <div id="mc-loading" class="mc-loading">Cargando comentarios…</div>
  <div id="mc-list" class="mc-list"></div>
  <div id="mc-error" style="display:none;color:var(--muted);font-size:0.875rem;padding:1rem 0"></div>
</div>

<script>
(function() {
  const mastodonPostUrl = <?= json_encode($article_mastodon_url) ?>;
  const apiUrl = <?= json_encode(base_url() . '/api/mastodon') ?>;

  fetch(apiUrl + '?url=' + encodeURIComponent(mastodonPostUrl))
    .then(r => r.json())
    .then(data => {
      document.getElementById('mc-loading').style.display = 'none';

      if (data.error) {
        document.getElementById('mc-error').textContent = 'No se pudieron cargar los comentarios.';
        document.getElementById('mc-error').style.display = '';
        return;
      }

      // Stats
      const stats = document.getElementById('mc-stats');
      document.getElementById('mc-favs').textContent    = '⭐ ' + data.favourites;
      document.getElementById('mc-boosts').textContent  = '🔁 ' + data.reblogs;
      document.getElementById('mc-replies').textContent = '💬 ' + data.replies_count;
      stats.style.display = 'flex';

      // Comments
      const list = document.getElementById('mc-list');
      if (!data.comments.length) {
        list.innerHTML = '<p style="color:var(--muted);font-size:0.875rem;padding:0.5rem 0">Aún no hay comentarios. ¡Sé el primero en responder en Mastodon!</p>';
        return;
      }

      data.comments.forEach(c => {
        const date   = new Date(c.created_at).toLocaleDateString(<?= json_encode(site_lang() === 'es' ? 'es-ES' : 'en-US') ?>, { day:'numeric', month:'short', year:'numeric' });
        const depth  = Math.min(c.depth || 0, 4); // max 4 levels indent
        const indent = depth * 20;
        const div    = document.createElement('div');
        div.className = 'mc-comment';
        div.style.marginLeft = indent + 'px';
        // Visual thread line for replies
        if (depth > 0) div.style.borderLeft = '2px solid rgba(var(--accent-rgb, 200,100,30), 0.25)';
        div.innerHTML = `
          <div class="mc-avatar">
            <a href="${c.author.url}" target="_blank" rel="noopener">
              <img src="${c.author.avatar}" alt="${escHtml(c.author.name)}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22><rect width=%2240%22 height=%2240%22 fill=%22%23555%22/><text x=%2220%22 y=%2226%22 text-anchor=%22middle%22 fill=%22white%22 font-size=%2218%22>${escHtml(c.author.name[0]||'?')}</text></svg>'">
            </a>
          </div>
          <div class="mc-body">
            <div class="mc-author">
              <a href="${c.author.url}" target="_blank" rel="noopener">${escHtml(c.author.name)}</a>
              <span class="mc-handle">@${escHtml(c.author.username)}</span>
              <time class="mc-date">${date}</time>
              ${c.favourites ? `<span class="mc-fav">⭐ ${c.favourites}</span>` : ''}
              ${c.reblogs ? `<span class="mc-fav">🔁 ${c.reblogs}</span>` : ''}
            </div>
            <div class="mc-content">${c.content}</div>
            <div class="mc-reply-link">
              <a href="${c.url}" target="_blank" rel="noopener">Responder en Mastodon →</a>
            </div>
          </div>`;
        list.appendChild(div);
      });
    })
    .catch(() => {
      document.getElementById('mc-loading').style.display = 'none';
      document.getElementById('mc-error').textContent = 'Error cargando comentarios.';
      document.getElementById('mc-error').style.display = '';
    });

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>
<?php endif; ?>

<style>
.single-article {
  background: var(--surface);
}
.featured-image { overflow: hidden; max-height: 380px; }
.featured-image img { width: 100%; height: 100%; object-fit: cover; display: block; }

.article-inner { padding: 1.75rem; }

.article-meta {
  display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.85rem; flex-wrap: wrap;
}
.meta-cat {
  font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--accent); text-decoration: none; background: rgba(var(--accent-rgb), 0.08);
  padding: 0.15rem 0.55rem; border-radius: 2px; transition: all 0.15s;
}
.meta-cat:hover { background: var(--accent); color: #fff; }
.meta-sep { color: #ccc; }
.meta-date { font-size: 0.82rem; color: var(--muted); font-family: var(--font-body); }

.article-title {
  font-family: var(--font); font-size: clamp(1.5rem, 3vw, 2.1rem); font-weight: 700;
  line-height: 1.2; letter-spacing: -0.01em; margin-bottom: 1rem;
}
.article-lead {
  font-family: var(--font-body); font-size: 1.05rem; color: var(--text2); line-height: 1.6;
  margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border);
}

/* Prose */
.prose { font-family: var(--font-body); font-size: 1rem; line-height: 1.8; color: var(--text2); }
.prose h2 { font-family: var(--font); font-size: 1.4rem; font-weight: 700; color: var(--text); margin: 2rem 0 0.75rem; padding-bottom: 0.35rem; border-bottom: 2px solid var(--accent); text-transform: uppercase; }
.prose h3 { font-family: var(--font); font-size: 1.15rem; font-weight: 700; color: var(--text); margin: 1.5rem 0 0.5rem; }
.prose p { margin-bottom: 1.25rem; }
.prose a { color: var(--accent); text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 2px; }
.prose a:hover { opacity: 0.8; }
.prose strong { color: var(--text); font-weight: 700; }
.prose blockquote { border-left: 3px solid var(--accent); background: #f9f9f9; margin: 1.5rem 0; padding: 0.75rem 1.25rem; color: var(--text2); font-style: italic; }
.prose pre { background: #1e1e2e; color: #cdd6f4; border-radius: 4px; padding: 1.25rem; overflow-x: auto; font-size: 0.875rem; margin: 1.5rem 0; border-left: 3px solid var(--accent); }
.prose code { background: #f0f0f0; border: 1px solid #e0e0e0; padding: 0.1rem 0.4rem; border-radius: 3px; font-size: 0.875em; color: #c7254e; }
.prose pre code { background: none; border: none; padding: 0; color: inherit; }
.prose ul, .prose ol { padding-left: 1.75rem; margin-bottom: 1.25rem; }
.prose li { margin-bottom: 0.35rem; }
.prose img { max-width: 100%; border-radius: 3px; margin: 1.5rem 0; display: block; }
.prose hr { border: none; border-top: 1px solid var(--border); margin: 2rem 0; }
.prose table { width: 100%; border-collapse: collapse; margin: 1.5rem 0; font-size: 0.9rem; }
.prose th { background: var(--header-bg); color: #fff; padding: 0.5rem 0.75rem; text-align: left; font-family: var(--font); font-size: 0.78rem; text-transform: uppercase; }
.prose td { padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--border); }
.prose tr:hover td { background: #f9f9f9; }

/* YouTube embed */
.yt-embed { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; margin: 1.5rem 0; border-radius: 4px; }
.yt-embed iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }

.article-tags { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border); }
.tag-pill { background: var(--bg); border: 1px solid var(--border); color: var(--text2); padding: 0.2rem 0.65rem; border-radius: 2px; font-size: 0.78rem; font-weight: 700; text-decoration: none; text-transform: uppercase; letter-spacing: 0.04em; transition: all 0.15s; }
.tag-pill:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

.article-back { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
.article-back a { color: var(--muted); text-decoration: none; font-size: 0.875rem; transition: color 0.15s; }
.article-back a:hover { color: var(--accent); }

/* ── Mastodon Comments ── */
.mastodon-comments {
  background: var(--surface);
  margin-top: 1.5rem;
  padding: 1.5rem;
}
.mc-header { display: flex; align-items: flex-start; gap: 0.85rem; margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
.mc-stats { display: flex; gap: 1.25rem; font-size: 0.85rem; color: var(--muted); margin-bottom: 1rem; }
.mc-loading { color: var(--muted); font-size: 0.875rem; padding: 0.5rem 0; }
.mc-list { display: flex; flex-direction: column; gap: 1.25rem; }
.mc-comment { display: flex; gap: 0.85rem; }
.mc-avatar { flex-shrink: 0; }
.mc-avatar img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; }
.mc-body { flex: 1; min-width: 0; }
.mc-author { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin-bottom: 0.35rem; font-size: 0.82rem; }
.mc-author a { font-weight: 700; color: var(--text); text-decoration: none; }
.mc-author a:hover { color: var(--accent); }
.mc-handle { color: var(--muted); }
.mc-date { color: var(--muted); margin-left: auto; }
.mc-fav { color: #f59e0b; font-size: 0.78rem; }
.mc-content { font-size: 0.9rem; line-height: 1.65; color: var(--text2); }
.mc-content a { color: var(--accent); }
.mc-content p { margin-bottom: 0.5rem; }
.mc-content p:last-child { margin-bottom: 0; }
.mc-reply-link { margin-top: 0.4rem; }
.mc-reply-link a { font-size: 0.75rem; color: var(--muted); text-decoration: none; }
.mc-reply-link a:hover { color: var(--accent); }

/* ── Audio player ── */
.media-audio {
  margin: 1.5rem 0;
  background: var(--surface, #f5f5f5);
  border: 1px solid var(--border, #e0e0e0);
  border-left: 3px solid var(--accent);
  border-radius: 8px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.media-audio::before {
  content: "🎵";
  font-size: 1.5rem;
  flex-shrink: 0;
}
.media-audio audio {
  width: 100%;
  height: 36px;
}

/* ── Video player ── */
.media-video {
  margin: 1.5rem 0;
  border-radius: 8px;
  overflow: hidden;
  background: #000;
  line-height: 0;
}
.media-video video {
  width: 100%;
  max-height: 540px;
  display: block;
}
</style>
