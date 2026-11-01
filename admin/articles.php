<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$csrf = generate_csrf();
$msg_feedback = '';

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $action = $_POST['bulk_action'] ?? '';
    $slugs = $_POST['slugs'] ?? [];
    if (!empty($slugs) && is_array($slugs)) {
        $count = count($slugs);
        if ($action === 'delete') {
            foreach ($slugs as $slug) {
                delete_content('articles', $slug);
            }
            $msg_feedback = "Se eliminaron {$count} artículos correctamente.";
        } elseif ($action === 'draft') {
            foreach ($slugs as $slug) {
                $p = get_content('articles', $slug);
                if ($p) {
                    $p['status'] = 'draft';
                    save_content('articles', $p);
                }
            }
            $msg_feedback = "Se cambiaron {$count} artículos a borrador.";
        } elseif ($action === 'publish') {
            foreach ($slugs as $slug) {
                $p = get_content('articles', $slug);
                if ($p) {
                    $p['status'] = 'published';
                    save_content('articles', $p);
                }
            }
            $msg_feedback = "Se publicaron {$count} artículos correctamente.";
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$status_filter = $_GET['status'] ?? null;
$published_only = false;
if ($status_filter === 'published') {
    $published_only = true;
} elseif ($status_filter === 'draft') {
    $published_only = false;
}
$all_items = list_content('articles', false, 1, 9999);
$filtered_items = $all_items['items'];
if ($status_filter === 'published') {
    $filtered_items = array_filter($filtered_items, fn($p) => ($p['status'] ?? '') === 'published');
} elseif ($status_filter === 'draft') {
    $filtered_items = array_filter($filtered_items, fn($p) => ($p['status'] ?? '') === 'draft');
} elseif ($status_filter === 'scheduled') {
    $filtered_items = array_filter($filtered_items, fn($p) => ($p['status'] ?? '') === 'scheduled');
}
$per_page = 10;
$total = count($filtered_items);
$pages = (int)ceil($total / $per_page);
$offset = ($page - 1) * $per_page;
$items = array_slice($filtered_items, $offset, $per_page);
$result = ['items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page];

admin_header(__("nav_articles"), 'articles');
?>
      <div style="display:flex;gap:0.5rem;align-items:center">
        <a href="<?= base_url() ?>/admin/calendar.php" class="btn btn-secondary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Calendario
        </a>
        <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <?= __('list_new_article') ?>
        </a>
      </div>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg_feedback): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg_feedback) ?></div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">✓ <?= __("editor_saved") ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">
            <?php if ($status_filter === 'published'): ?>
                <?= $result['total'] ?> <?= __("dash_published", "Publicados") ?>
            <?php elseif ($status_filter === 'scheduled'): ?>
                <?= $result['total'] ?> <?= __("status_scheduled", "Programados") ?>
            <?php elseif ($status_filter === 'draft'): ?>
                <?= $result['total'] ?> <?= __("dash_drafts", "Borradores") ?>
            <?php else: ?>
                <?= $result['total'] ?> <?= __("nav_articles") ?>
            <?php endif; ?>
        </span>
        <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap">
          <a href="articles.php" class="btn btn-sm <?= !$status_filter ? 'btn-primary' : 'btn-secondary' ?>"><?= __("all", "Todos") ?></a>
          <a href="articles.php?status=published" class="btn btn-sm <?= $status_filter === 'published' ? 'btn-primary' : 'btn-secondary' ?>"><?= __("dash_published", "Publicados") ?></a>
          <a href="articles.php?status=scheduled" class="btn btn-sm <?= $status_filter === 'scheduled' ? 'btn-primary' : 'btn-secondary' ?>">⏰ <?= __("status_scheduled", "Programados") ?></a>
          <a href="articles.php?status=draft" class="btn btn-sm <?= $status_filter === 'draft' ? 'btn-primary' : 'btn-secondary' ?>"><?= __("dash_drafts", "Borradores") ?></a>
        </div>
      </div>

      <?php if (empty($result['items'])): ?>
        <div class="card-body" style="text-align:center;color:var(--muted);padding:3.5rem 1.5rem">
          <div style="font-size:2rem;margin-bottom:0.75rem;opacity:0.6">📝</div>
          <p style="font-size:1rem;margin-bottom:0.75rem"><?= __("list_no_articles") ?></p>
          <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="btn btn-primary btn-sm"><?= __("dash_create_first") ?></a>
        </div>
      <?php else: ?>

      <!-- Bulk Actions Bar -->
      <form method="POST" id="bulk-form" onsubmit="return handleBulkSubmit(event)">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="bulk_action" id="bulk-action-val" value="">

        <div id="bulk-toolbar" style="display:none;padding:0.75rem 1.4rem;background:var(--surface2);border-bottom:1px solid var(--border);align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:0.75rem">
            <span style="font-weight:600;font-size:0.88rem;color:var(--text)" id="bulk-count">0 seleccionados</span>
            <span style="color:var(--muted);font-size:0.85rem">|</span>
            <span style="font-size:0.82rem;color:var(--muted)">Acciones en lote:</span>
          </div>
          <div style="display:flex;gap:0.5rem;align-items:center">
            <button type="button" class="btn btn-secondary btn-sm" onclick="submitBulk('publish')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              Publicar
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="submitBulk('draft')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Pasar a borrador
            </button>
            <button type="button" class="btn btn-danger btn-sm" onclick="submitBulk('delete')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              Eliminar
            </button>
          </div>
        </div>

      <table class="table">
        <thead>
          <tr>
            <th style="width:40px;padding-right:0">
              <input type="checkbox" id="check-all" title="Seleccionar todos" style="cursor:pointer;width:16px;height:16px;accent-color:var(--accent)" onchange="toggleSelectAll(this)">
            </th>
            <th><?= __("list_title_col", "Título") ?></th>
            <th><?= __("list_status_col", "Estado") ?></th>
            <th><?= __("list_categories_col", "Categorías") ?></th>
            <th><?= __("list_date_col", "Fecha") ?></th>
            <th style="text-align:right"><?= __("list_actions_col", "Acciones") ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['items'] as $post): ?>
          <tr>
            <td style="width:40px;padding-right:0">
              <input type="checkbox" name="slugs[]" value="<?= htmlspecialchars($post['slug']) ?>" class="item-check" style="cursor:pointer;width:16px;height:16px;accent-color:var(--accent)" onchange="updateBulkToolbar()">
            </td>
            <td>
              <a href="<?= base_url() ?>/admin/editor.php?type=articles&slug=<?= htmlspecialchars($post['slug']) ?>" style="font-weight:600">
                <?= htmlspecialchars($post['title'] ?? 'Sin título') ?>
              </a>
            </td>
            <td>
              <?php if (($post['status'] ?? '') === 'published'): ?>
                <span class="badge badge-green">✓ <?= __('badge_published', 'Publicado') ?></span>
              <?php elseif (($post['status'] ?? '') === 'scheduled'): ?>
                <span class="badge badge-blue">⏰ <?= __('badge_scheduled', 'Programado') ?></span>
              <?php else: ?>
                <span class="badge badge-yellow">● <?= __('badge_draft', 'Borrador') ?></span>
              <?php endif; ?>
            </td>
            <td style="color:var(--text2);font-size:0.88rem"><?= htmlspecialchars(implode(', ', $post['categories'] ?? [])) ?: '<span style="color:var(--muted)">—</span>' ?></td>
            <td style="color:var(--muted);font-size:0.88rem">
              <?= format_date($post['created_at'] ?? 'now', 'admin') ?>
              <?php if (($post['status'] ?? '') === 'scheduled'): ?>
                <div style="font-size:0.75rem;color:#3b82f6;font-weight:600">Programado</div>
              <?php endif; ?>
            </td>
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
      </form>
      <?php if ($result['pages'] > 1): ?>
      <div style="padding:1.25rem 1.6rem;display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;border-top:1px solid var(--border)">
          <?php if ($page > 1): ?>
          <a href="?page=1<?= $status_filter ? '&status=' . htmlspecialchars($status_filter) : '' ?>" class="page-btn">«</a>
          <a href="?page=<?= $page - 1 ?><?= $status_filter ? '&status=' . htmlspecialchars($status_filter) : '' ?>" class="page-btn">‹</a>
          <?php endif; ?>
          
          <?php
          $start_page = max(1, min($page - 2, $result['pages'] - 4));
          $end_page = min($result['pages'], $start_page + 4);
          
          if ($start_page > 1) {
              echo '<span style="color:var(--muted);padding:0 0.4rem">…</span>';
          }
          
          for ($i = $start_page; $i <= $end_page; $i++): ?>
          <a href="?page=<?= $i ?><?= $status_filter ? '&status=' . htmlspecialchars($status_filter) : '' ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
          
          <?php if ($end_page < $result['pages']): ?>
          <span style="color:var(--muted);padding:0 0.4rem">…</span>
          <?php endif; ?>
          
          <?php if ($page < $result['pages']): ?>
          <a href="?page=<?= $page + 1 ?><?= $status_filter ? '&status=' . htmlspecialchars($status_filter) : '' ?>" class="page-btn">›</a>
          <a href="?page=<?= $result['pages'] ?><?= $status_filter ? '&status=' . htmlspecialchars($status_filter) : '' ?>" class="page-btn">»</a>
          <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function updateBulkToolbar() {
  const checked = document.querySelectorAll('.item-check:checked');
  const total = document.querySelectorAll('.item-check').length;
  const toolbar = document.getElementById('bulk-toolbar');
  const countEl = document.getElementById('bulk-count');
  const checkAll = document.getElementById('check-all');

  if (checked.length > 0) {
    toolbar.style.display = 'flex';
    countEl.textContent = checked.length + (checked.length === 1 ? ' artículo seleccionado' : ' artículos seleccionados');
  } else {
    toolbar.style.display = 'none';
  }

  if (checkAll) {
    checkAll.checked = (checked.length === total && total > 0);
    checkAll.indeterminate = (checked.length > 0 && checked.length < total);
  }
}

function toggleSelectAll(master) {
  const checkboxes = document.querySelectorAll('.item-check');
  checkboxes.forEach(cb => cb.checked = master.checked);
  updateBulkToolbar();
}

function submitBulk(action) {
  const checked = document.querySelectorAll('.item-check:checked');
  if (checked.length === 0) return;

  if (action === 'delete') {
    if (!confirm('¿Estás seguro de que deseas eliminar permanentemente los ' + checked.length + ' artículos seleccionados?')) {
      return;
    }
  }

  document.getElementById('bulk-action-val').value = action;
  document.getElementById('bulk-form').submit();
}

function handleBulkSubmit(e) {
  const action = document.getElementById('bulk-action-val').value;
  if (!action) {
    e.preventDefault();
    return false;
  }
  return true;
}
</script>
</body></html>
