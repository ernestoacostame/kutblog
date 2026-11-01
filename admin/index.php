<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$articles = list_content('articles', false, 1, 9999);
$pages = list_content('pages', false, 1, 9999);
$published_articles = array_filter($articles['items'], fn($p) => ($p['status'] ?? '') === 'published');
$scheduled_articles = array_filter($articles['items'], fn($p) => ($p['status'] ?? '') === 'scheduled');
$draft_articles     = array_filter($articles['items'], fn($p) => ($p['status'] ?? '') === 'draft');
$recent = array_slice($articles['items'], 0, 8);

admin_header(__("dash_title"), 'dashboard');
?>
      <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="btn btn-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?= __("nav_new_article") ?>
      </a>
    </div>
  </div>
  <div class="page-body">
    <div class="stats-grid">
      <a href="<?= base_url() ?>/admin/articles.php" class="stat-card interactive-stat">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="stat-label"><?= __("dash_total", "Total artículos") ?></div>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="opacity:0.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="stat-value accent"><?= $articles['total'] ?></div>
      </a>
      <a href="<?= base_url() ?>/admin/articles.php?status=published" class="stat-card interactive-stat">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="stat-label"><?= __("dash_published", "Publicados") ?></div>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2" style="opacity:0.8"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-value" style="color:var(--green)"><?= count($published_articles) ?></div>
      </a>
      <a href="<?= base_url() ?>/admin/articles.php?status=draft" class="stat-card interactive-stat">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="stat-label"><?= __("dash_drafts", "Borradores") ?></div>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2" style="opacity:0.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="stat-value" style="color:var(--yellow)"><?= $articles['total'] - count($published_articles) ?></div>
      </a>
      <a href="<?= base_url() ?>/admin/pages.php" class="stat-card interactive-stat">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <div class="stat-label"><?= __("dash_pages", "Páginas") ?></div>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:0.6"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
        </div>
        <div class="stat-value"><?= $pages['total'] ?></div>
      </a>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= __("dash_recent", "Artículos recientes") ?></span>
        <a href="<?= base_url() ?>/admin/articles.php" class="btn btn-secondary btn-sm"><?= __("dash_view_all", "Ver todos") ?> →</a>
      </div>
      <?php if (empty($recent)): ?>
        <div class="card-body" style="text-align:center; color:var(--muted); padding: 3rem 1.5rem;">
          <div style="font-size:2rem;margin-bottom:0.75rem;opacity:0.6">✍️</div>
          <p style="font-size:1rem;margin-bottom:0.75rem"><?= __("dash_no_articles") ?></p>
          <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="btn btn-primary btn-sm"><?= __("dash_create_first") ?></a>
        </div>
      <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= __("list_title_col", "Título") ?></th>
            <th><?= __("list_status_col", "Estado") ?></th>
            <th><?= __("list_date_col", "Fecha") ?></th>
            <th style="text-align:right"><?= __("list_actions_col", "Acciones") ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $post): ?>
          <tr>
            <td>
              <a href="<?= base_url() ?>/admin/editor.php?type=articles&slug=<?= htmlspecialchars($post['slug']) ?>" style="font-weight:600">
                <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
              </a>
            </td>
            <td>
              <?php if (($post['status'] ?? '') === 'published'): ?>
                <span class="badge badge-green">✓ <?= __("dash_published", "Publicado") ?></span>
              <?php elseif (($post['status'] ?? '') === 'scheduled'): ?>
                <span class="badge badge-blue">⏰ <?= __("status_scheduled", "Programado") ?></span>
              <?php else: ?>
                <span class="badge badge-yellow">● <?= __("dash_drafts", "Borrador") ?></span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted);font-size:0.9rem"><?= format_date($post['created_at'] ?? 'now', 'admin') ?></td>
            <td style="text-align:right">
              <a href="<?= base_url() ?>/admin/editor.php?type=articles&slug=<?= htmlspecialchars($post['slug']) ?>" class="btn btn-secondary btn-sm"><?= __("edit", "Editar") ?></a>
              <?php if ($post['status'] === 'published'): ?>
              <a href="<?= base_url() ?>/article/<?= htmlspecialchars($post['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm"><?= __("view", "Ver") ?> ↗</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<style>
.interactive-stat {
  display: block;
  text-decoration: none;
  color: inherit;
  cursor: pointer;
  transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}
.interactive-stat:hover {
  background: var(--surface2);
  border-color: var(--accent);
  transform: translateY(-3px);
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
</style>
</body></html>
