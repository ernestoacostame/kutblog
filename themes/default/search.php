<div style="margin-bottom:2rem">
  <h1 style="font-family:var(--font-heading);font-size:1.75rem;font-weight:700;letter-spacing:-0.03em">
    Search results for: <em style="color:var(--accent)"><?= htmlspecialchars($query) ?></em>
  </h1>
  <p style="color:var(--muted);margin-top:0.35rem"><?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?> found</p>
</div>

<?php if (empty($results)): ?>
<div style="text-align:center;padding:3rem 0;color:var(--muted)">
  <p style="font-size:1.1rem">No results found for "<?= htmlspecialchars($query) ?>".</p>
  <p style="margin-top:0.5rem;font-size:0.9rem">Try different keywords or <a href="<?= $base ?>/" style="color:var(--accent)">browse all articles</a>.</p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1.25rem">
  <?php foreach ($results as $item):
    $item_type = $item['_type'] ?? 'articles';
    $item_url = $base . '/' . ($item_type === 'articles' ? 'article' : 'page') . '/' . $item['slug'];
  ?>
  <article style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem">
    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem">
      <span style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted)">
        <?= $item_type === 'articles' ? 'Article' : 'Page' ?>
      </span>
      <span style="color:var(--border)">·</span>
      <span style="font-size:0.8rem;color:var(--muted)"><?= format_date($item['created_at'] ?? 'now') ?></span>
    </div>
    <h2 style="font-family:var(--font-heading);font-size:1.15rem;font-weight:600;margin-bottom:0.4rem">
      <a href="<?= $item_url ?>" style="color:var(--text);text-decoration:none"><?= htmlspecialchars($item['title'] ?? 'Untitled') ?></a>
    </h2>
    <?php $excerpt = $item['excerpt'] ?: substr(strip_tags($item['content'] ?? ''), 0, 200); ?>
    <?php if ($excerpt): ?>
    <p style="color:var(--text2);font-size:0.9rem;line-height:1.6"><?= htmlspecialchars($excerpt) ?>…</p>
    <?php endif; ?>
    <a href="<?= $item_url ?>" style="display:inline-flex;align-items:center;gap:0.3rem;color:var(--accent);font-size:0.85rem;font-weight:500;text-decoration:none;margin-top:0.75rem">
      Read more →
    </a>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
