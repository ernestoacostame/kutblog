<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$result = list_content('pages', false, $page, $per_page);

admin_header(__("nav_pages"), 'pages');
?>
      <a href="<?= base_url() ?>/admin/editor.php?type=pages" class="btn btn-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?= __('list_new_page') ?>
      </a>
    </div>
  </div>
  <div class="page-body">
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">✓ <?= __("editor_saved") ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><span class="card-title"><?= $result['total'] ?> <?= __("nav_pages") ?></span></div>
      <?php if (empty($result['items'])): ?>
        <div class="card-body" style="text-align:center;color:var(--muted);padding:3.5rem 1.5rem">
          <div style="font-size:2rem;margin-bottom:0.75rem;opacity:0.6">📄</div>
          <p style="font-size:1rem;margin-bottom:0.75rem"><?= __("list_no_pages") ?></p>
          <a href="<?= base_url() ?>/admin/editor.php?type=pages" class="btn btn-primary btn-sm"><?= __("dash_create_first") ?></a>
        </div>
      <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= __("list_title_col", "Título") ?></th>
            <th><?= __("list_slug_col", "Ruta / Slug") ?></th>
            <th><?= __("list_status_col", "Estado") ?></th>
            <th><?= __("list_date_col", "Actualizado") ?></th>
            <th style="text-align:right"><?= __("list_actions_col", "Acciones") ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['items'] as $post): ?>
          <tr>
            <td>
              <a href="<?= base_url() ?>/admin/editor.php?type=pages&slug=<?= htmlspecialchars($post['slug']) ?>" style="font-weight:600">
                <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
              </a>
            </td>
            <td style="color:var(--muted);font-size:0.88rem"><code style="background:var(--surface2);padding:2px 6px;border-radius:4px;border:1px solid var(--border)">/<?= htmlspecialchars($post['slug']) ?></code></td>
            <td>
              <?php if ($post['status'] === 'published'): ?>
                <span class="badge badge-green">✓ <?= __('badge_published', 'Publicado') ?></span>
              <?php else: ?>
                <span class="badge badge-yellow">● <?= __('badge_draft', 'Borrador') ?></span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted);font-size:0.88rem"><?= format_date($post['updated_at'] ?? $post['created_at'] ?? 'now', 'admin') ?></td>
            <td style="text-align:right">
              <a href="<?= base_url() ?>/admin/editor.php?type=pages&slug=<?= htmlspecialchars($post['slug']) ?>" class="btn btn-secondary btn-sm"><?= __("edit", "Editar") ?></a>
              <?php if ($post['status'] === 'published'): ?>
              <a href="<?= base_url() ?>/page/<?= htmlspecialchars($post['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm"><?= __("view", "Ver") ?> ↗</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($result['pages'] > 1): ?>
      <div style="padding:1.25rem 1.6rem;display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;border-top:1px solid var(--border)">
          <?php if ($page > 1): ?>
          <a href="?page=1" class="page-btn">«</a>
          <a href="?page=<?= $page - 1 ?>" class="page-btn">‹</a>
          <?php endif; ?>
          
          <?php
          $start_page = max(1, min($page - 2, $result['pages'] - 4));
          $end_page = min($result['pages'], $start_page + 4);
          
          if ($start_page > 1) {
              echo '<span style="color:var(--muted);padding:0 0.4rem">…</span>';
          }
          
          for ($i = $start_page; $i <= $end_page; $i++): ?>
          <a href="?page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          
          <?php if ($end_page < $result['pages']): ?>
          <span style="color:var(--muted);padding:0 0.4rem">…</span>
          <?php endif; ?>
          
          <?php if ($page < $result['pages']): ?>
          <a href="?page=<?= $page + 1 ?>" class="page-btn">›</a>
          <a href="?page=<?= $result['pages'] ?>" class="page-btn">»</a>
          <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body></html>
