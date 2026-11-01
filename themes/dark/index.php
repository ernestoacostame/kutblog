<?php
$heading = isset($category) ? 'Category: ' . ucfirst($category) :
           (isset($tag) ? 'Tag: ' . $tag : 'Latest Articles');
?>
<div style="margin-bottom:2.5rem">
  <h1 style="font-family:var(--font-heading);font-size:clamp(1.5rem,3vw,2rem);font-weight:700;letter-spacing:-0.03em">
    <?= htmlspecialchars($heading) ?>
  </h1>
  <?php if (isset($config['tagline']) && $config['tagline'] && !isset($category) && !isset($tag)): ?>
  <p style="color:var(--muted);margin-top:0.4rem"><?= htmlspecialchars($config['tagline']) ?></p>
  <?php endif; ?>
</div>

<?php if (empty($posts['items'])): ?>
<div style="text-align:center;padding:4rem 0;color:var(--muted)">
  <div style="font-size:2.5rem;margin-bottom:1rem">✍️</div>
  <p>No articles published yet.</p>
</div>
<?php else: ?>
<div class="posts-grid">
  <?php foreach ($posts['items'] as $post): ?>
  <article class="post-card">
    <?php if (!empty($post['featured_image'])): ?>
    <div class="post-card-image">
      <img src="<?= htmlspecialchars($post['featured_image']) ?>" alt="<?= htmlspecialchars($post['title']) ?>" loading="lazy">
    </div>
    <?php endif; ?>
    <div class="post-card-body">
      <div class="post-card-meta">
        <span class="post-date"><?= format_date($post['created_at'] ?? 'now') ?></span>
        <?php if (!empty($post['categories'][0])): ?>
        <a href="<?= $base ?>/category/<?= urlencode($post['categories'][0]) ?>" class="post-category">
          <?= htmlspecialchars($post['categories'][0]) ?>
        </a>
        <?php endif; ?>
      </div>
      <h2 class="post-card-title">
        <a href="<?= $base ?>/article/<?= htmlspecialchars($post['slug']) ?>"><?= htmlspecialchars($post['title'] ?? 'Untitled') ?></a>
      </h2>
      <?php if (!empty($post['excerpt']) || !empty($post['content'])): ?>
      <p class="post-excerpt"><?= htmlspecialchars(strip_tags($post['excerpt'] ?: substr(strip_tags($post['content']), 0, 200))) ?></p>
      <?php endif; ?>
      <a href="<?= $base ?>/article/<?= htmlspecialchars($post['slug']) ?>" class="post-read-more">
        Read more <span>→</span>
      </a>
    </div>
  </article>
  <?php endforeach; ?>
</div>

<?php if ($posts['pages'] > 1):
  $current_page = $posts['page'] ?? 1;
  $total_pages  = $posts['pages'];
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
    <a href="?page=1" class="page-link" title="First">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="11 18 5 12 11 6"/><polyline points="18 18 12 12 18 6"/></svg>
    </a>
    <a href="?page=<?= $current_page - 1 ?>" class="page-link" title="Previous">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </a>
  <?php endif; ?>
  
  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="?page=<?= $i ?>" class="page-link <?= $i === $current_page ? 'active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
  
  <?php if ($current_page < $total_pages): ?>
    <a href="?page=<?= $current_page + 1 ?>" class="page-link" title="Next">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <a href="?page=<?= $total_pages ?>" class="page-link" title="Last">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="13 18 19 12 13 6"/><polyline points="6 18 12 12 6 6"/></svg>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
