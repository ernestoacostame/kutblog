<?php
$heading = isset($category) ? 'Categoría: ' . $category :
           (isset($tag) ? 'Etiqueta: ' . $tag : null);
$current_page = $posts['page'] ?? 1;
$total_pages  = $posts['pages'] ?? 1;
?>
<?php if ($heading): ?>
<div style="margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border)">
  <h1 style="font-family:var(--font);font-size:1.3rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--muted)"><?= htmlspecialchars($heading) ?></h1>
</div>
<?php endif; ?>

<?php if (empty($posts['items'])): ?>
<div style="padding:2rem;text-align:center;color:var(--muted)">No hay artículos publicados todavía.</div>
<?php else: ?>
<div class="post-list">
  <?php foreach ($posts['items'] as $post): ?>
  <article class="post-row">
    <?php if (!empty($post['featured_image'])): ?>
    <a href="<?= $base ?>/article/<?= htmlspecialchars($post['slug']) ?>" class="post-thumb">
      <img src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" loading="lazy">
    </a>
    <?php endif; ?>
    <div class="post-row-body">
      <h2 class="post-row-title">
        <a href="<?= $base ?>/article/<?= htmlspecialchars($post['slug']) ?>"><?= htmlspecialchars($post['title'] ?? 'Sin título') ?></a>
      </h2>
      <div class="post-row-meta">
        <span class="post-date"><?= format_date($post['created_at'] ?? 'now') ?></span>
        <?php foreach ($post['categories'] ?? [] as $cat): ?>
        <a href="<?= $base ?>/category/<?= urlencode($cat) ?>" class="post-cat"><?= htmlspecialchars($cat) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<?php if ($total_pages > 1):
  $current_page = $posts['page'] ?? 1;
  $total_pages  = $posts['pages'] ?? 1;
  $max_links    = 5; // Show only 5 page links
  
  // Calculate the start and end of the page range
  $start = max(1, $current_page - 2);
  $end = min($total_pages, $current_page + 2);
  
  // Adjust if we're near the beginning
  if ($current_page <= 3) {
    $start = 1;
    $end = min($max_links, $total_pages);
  }
  
  // Adjust if we're near the end
  if ($current_page >= $total_pages - 2) {
    $start = max(1, $total_pages - $max_links + 1);
    $end = $total_pages;
  }
?>
<div class="pagination">
  <?php if ($current_page > 1): ?>
    <a href="?page=1" class="page-link page-nav" title="Primera">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="11 18 5 12 11 6"/><polyline points="18 18 12 12 18 6"/></svg>
    </a>
    <a href="?page=<?= $current_page - 1 ?>" class="page-link page-nav" title="Anterior">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
  <?php endif; ?>
  
  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="?page=<?= $i ?>" class="page-link <?= $i === $current_page ? 'active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
  
  <?php if ($current_page < $total_pages): ?>
    <a href="?page=<?= $current_page + 1 ?>" class="page-link page-nav" title="Siguiente">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <a href="?page=<?= $total_pages ?>" class="page-link page-nav" title="Última">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 18 19 12 13 6"/><polyline points="6 18 12 12 6 6"/></svg>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<style>
.post-list { display: flex; flex-direction: column; }
.post-row { background: transparent; padding: 0.9rem 0; border-bottom: 1px solid var(--border); display: grid; grid-template-columns: 1fr; transition: background 0.15s; }
.post-row:last-child { border-bottom: none; }
.post-row:hover { background: var(--surface2); padding-left: 0.5rem; padding-right: 0.5rem; margin: 0 -0.5rem; }
.post-row:has(.post-thumb) { grid-template-columns: 180px 1fr; gap: 1rem; align-items: start; }
.post-thumb { display: block; overflow: hidden; border-radius: 2px; flex-shrink: 0; }
.post-thumb img { width: 100%; aspect-ratio: 16/9; object-fit: cover; transition: transform 0.3s; display: block; }
.post-row:hover .post-thumb img { transform: scale(1.03); }
.post-row-body { display: flex; flex-direction: column; gap: 0.3rem; }
.post-row-title { font-family: var(--font); font-size: 1.5rem; font-weight: 700; line-height: 1.25; }
.post-row-title a { color: var(--text2); text-decoration: none; transition: color 0.15s; }
.post-row-title a:hover { color: var(--accent); }
.post-row-meta { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.post-date { font-size: 0.78rem; color: var(--muted); font-family: var(--font-body); }
.post-cat { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--accent); text-decoration: none; padding: 0.1rem 0.45rem; background: rgba(var(--accent-rgb), 0.08); border-radius: 2px; transition: all 0.15s; }
.post-cat:hover { background: var(--accent); color: #fff; }
.pagination { display: flex; gap: 0.3rem; margin-top: 1.5rem; flex-wrap: wrap; align-items: center; }
.page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 0.35rem; background: var(--surface); border: 1px solid var(--border); color: var(--text2); text-decoration: none; font-size: 0.82rem; font-family: var(--font); font-weight: 700; transition: all 0.15s; border-radius: 2px; }
.page-link:hover, .page-link.active { background: var(--accent); border-color: var(--accent); color: #fff; }
@media (max-width: 600px) { .post-row:has(.post-thumb) { grid-template-columns: 1fr; } }
</style>
