<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/taxonomies.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';

require_login();

$tab = in_array($_GET['tab'] ?? '', ['categories', 'tags']) ? $_GET['tab'] : 'categories';
$csrf = generate_csrf();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Error de validación de seguridad (CSRF). Por favor recarga e intenta de nuevo.';
    } else {
        $action = $_POST['action'] ?? '';
        $postTaxonomy = in_array($_POST['taxonomy'] ?? '', ['categories', 'tags']) ? $_POST['taxonomy'] : $tab;

        if ($action === 'save') {
            $name = trim($_POST['name'] ?? '');
            $slug = trim($_POST['slug'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $orig = trim($_POST['original_name'] ?? '');

            if ($name === '') {
                $error = 'El nombre no puede estar vacío.';
            } else {
                $res = cms_taxonomy_save($postTaxonomy, $name, $slug, $desc, $orig !== '' ? $orig : null);
                if (!empty($res['error'])) {
                    $error = $res['error'];
                } else {
                    $tab = $postTaxonomy;
                    $renamed = $res['renamed_posts'] ?? 0;
                    if ($renamed > 0) {
                        $msg = __("tax_saved", "Término guardado con éxito.") . " Se actualizaron {$renamed} artículo(s) asociados.";
                    } else {
                        $msg = __("tax_saved", "Término guardado con éxito.");
                    }
                }
            }
        } elseif ($action === 'delete') {
            $name = trim($_POST['name'] ?? '');
            $removePosts = !empty($_POST['remove_from_posts']);

            if ($name === '') {
                $error = 'Nombre no especificado.';
            } else {
                $res = cms_taxonomy_delete($postTaxonomy, $name, $removePosts);
                if (!empty($res['error'])) {
                    $error = $res['error'];
                } else {
                    $tab = $postTaxonomy;
                    $affected = $res['affected_posts'] ?? 0;
                    if ($affected > 0) {
                        $msg = __("tax_deleted", "Término eliminado con éxito.") . " Se desvinculó de {$affected} artículo(s).";
                    } else {
                        $msg = __("tax_deleted", "Término eliminado con éxito.");
                    }
                }
            }
        } elseif ($action === 'merge') {
            $source = trim($_POST['source_name'] ?? '');
            $target = trim($_POST['target_name'] ?? '');

            if ($source === '' || $target === '') {
                $error = 'Debes especificar el término de origen y el término de destino.';
            } else {
                $res = cms_taxonomy_merge($postTaxonomy, $source, $target);
                if (!empty($res['error'])) {
                    $error = $res['error'];
                } else {
                    $tab = $postTaxonomy;
                    $affected = $res['affected_posts'] ?? 0;
                    $msg = __("tax_merged", "Término fusionado con éxito.") . " {$affected} artículo(s) ahora usan «{$target}».";
                }
            }
        }
    }
}

$items = cms_taxonomies_get($tab);
$totalItems = count($items);
$inUseCount = count(array_filter($items, fn($i) => $i['count'] > 0));
$unusedCount = $totalItems - $inUseCount;

$isCats = ($tab === 'categories');
$pageTitle = $isCats ? __("tax_tab_categories", "Categorías") : __("tax_tab_tags", "Etiquetas");
$newItemLabel = $isCats ? __("tax_new_category", "Nueva categoría") : __("tax_new_tag", "Nueva etiqueta");
$singularName = $isCats ? 'categoría' : 'etiqueta';

admin_header(__("tax_title", "Categorías y Etiquetas"), 'taxonomies');
?>
      <button type="button" class="btn btn-primary" onclick="openNewModal()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?= $newItemLabel ?>
      </button>
    </div>
  </div>

<div class="page-body">
  <?php if ($msg): ?>
    <div class="alert alert-success" style="margin-bottom: 1.25rem;">✓ <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.25rem;">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Pestañas Superiores -->
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
    <div style="display:flex; gap:0.5rem; background:var(--surface2); padding:4px; border-radius:10px; border:1px solid var(--border);">
      <a href="?tab=categories" class="btn btn-sm <?= $isCats ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; font-weight:600; padding:6px 16px;">
        📁 <?= __("tax_tab_categories", "Categorías") ?> (<?= count(cms_taxonomies_get('categories')) ?>)
      </a>
      <a href="?tab=tags" class="btn btn-sm <?= !$isCats ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; font-weight:600; padding:6px 16px;">
        🏷️ <?= __("tax_tab_tags", "Etiquetas") ?> (<?= count(cms_taxonomies_get('tags')) ?>)
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
      <div style="display:flex; align-items:center; gap:0.75rem;">
        <span class="card-title"><?= $totalItems ?> <?= $pageTitle ?></span>
        <span style="font-size:0.82rem; color:var(--muted)">
          (<?= $inUseCount ?> <?= __("tax_in_use", "en uso") ?> · <?= $unusedCount ?> <?= __("tax_future_unassigned", "futuras / sin asignar") ?>)
        </span>
      </div>

      <!-- Buscador en tiempo real -->
      <div style="position:relative; min-width:240px;">
        <input type="text" id="tax-search" class="form-control" placeholder="<?= __("tax_search_placeholder", "Buscar :type...", ['type' => mb_strtolower($pageTitle, 'UTF-8')]) ?>" oninput="filterTaxonomies(this.value)" style="padding-left:32px; font-size:0.88rem; height:36px;">
        <svg style="position:absolute; left:10px; top:10px; opacity:0.5;" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
    </div>

    <?php if (empty($items)): ?>
      <div class="card-body" style="text-align:center; color:var(--muted); padding:3.5rem 1.5rem;">
        <div style="font-size:2.5rem; margin-bottom:0.75rem; opacity:0.6;"><?= $isCats ? '📁' : '🏷️' ?></div>
        <p style="font-size:1rem; margin-bottom:1rem;"><?= $isCats ? __("tax_empty_cats", "No hay categorías registradas.") : __("tax_empty_tags", "No hay etiquetas registradas.") ?></p>
        <button type="button" class="btn btn-primary btn-sm" onclick="openNewModal()"><?= $newItemLabel ?></button>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table" id="tax-table">
          <thead>
            <tr>
              <th style="min-width:180px;"><?= __("tax_name_col", "Nombre") ?></th>
              <th style="min-width:140px;"><?= __("tax_slug_col", "Slug / Ruta") ?></th>
              <th><?= __("tax_desc_col", "Descripción") ?></th>
              <th style="text-align:center; width:120px;"><?= __("tax_posts_col", "Artículos") ?></th>
              <th style="text-align:right; min-width:180px;"><?= __("list_actions_col", "Acciones") ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): 
              $termName = $item['name'];
              $termSlug = $item['slug'];
              $termDesc = $item['description'] ?? '';
              $termCount = (int)$item['count'];
              $frontendUrl = base_url() . ($isCats ? '/category/' : '/tag/') . urlencode($termSlug);
              $adminFilterUrl = base_url() . '/admin/articles.php?' . ($isCats ? 'category=' : 'tag=') . urlencode($termName);
            ?>
            <tr class="tax-row" data-name="<?= htmlspecialchars(mb_strtolower($termName, 'UTF-8')) ?>" data-slug="<?= htmlspecialchars(mb_strtolower($termSlug, 'UTF-8')) ?>">
              <td>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <span style="font-size:1.1rem; opacity:0.7;"><?= $isCats ? '📁' : '🏷️' ?></span>
                  <strong><?= htmlspecialchars($termName) ?></strong>
                  <?php if ($termCount === 0): ?>
                    <span class="badge" style="background:rgba(99,102,241,0.15); color:var(--primary); font-size:0.72rem; padding:2px 6px;"><?= __("tax_badge_future", "Futura") ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <code style="background:var(--surface2); padding:2px 6px; border-radius:4px; border:1px solid var(--border); font-size:0.82rem;">
                  /<?= $isCats ? 'category' : 'tag' ?>/<?= htmlspecialchars($termSlug) ?>
                </code>
              </td>
              <td style="color:var(--text2); font-size:0.88rem; max-width:320px;">
                <?= $termDesc ? htmlspecialchars($termDesc) : '<span style="color:var(--muted)">—</span>' ?>
              </td>
              <td style="text-align:center;">
                <?php if ($termCount > 0): ?>
                  <a href="<?= $adminFilterUrl ?>" title="<?= __("tax_posts_col", "Artículos") ?>" class="badge badge-green" style="text-decoration:none; display:inline-flex; align-items:center; gap:4px; font-weight:600; padding:3px 8px; white-space:nowrap;">
                    <?= $termCount ?> post<?= $termCount > 1 ? 's' : '' ?> ↗
                  </a>
                <?php else: ?>
                  <span class="badge" style="background:var(--surface2); color:var(--muted); padding:3px 8px; white-space:nowrap;">0 posts</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:0.35rem; align-items:center;">
                  <a href="<?= $frontendUrl ?>" target="_blank" class="btn btn-secondary btn-sm" title="<?= __("tax_view_on_blog", "Ver en el blog") ?>">↗</a>
                  <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($termName) ?>, <?= json_encode($termSlug) ?>, <?= json_encode($termDesc) ?>)' title="<?= __("tax_edit_title", "Editar") ?>">
                    <?= __("edit", "Editar") ?>
                  </button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick='openMergeModal(<?= json_encode($termName) ?>)' title="<?= __("tax_merge_title", "Fusionar término") ?>">
                    <?= __("tax_btn_merge", "Fusionar") ?>
                  </button>
                  <button type="button" class="btn btn-danger btn-sm" onclick='openDeleteModal(<?= json_encode($termName) ?>, <?= $termCount ?>)' title="<?= __("tax_delete_title", "Eliminar término") ?>">
                    ✕
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<!-- Modal: Crear / Editar Término -->
<div id="modal-tax-form" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
  <div class="card" style="width:100%; max-width:480px; margin:1rem; box-shadow:0 12px 35px rgba(0,0,0,0.4);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title" id="form-modal-title"><?= $newItemLabel ?></span>
      <button type="button" onclick="closeModals()" style="background:none; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="taxonomy" value="<?= $tab ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="original_name" id="form-original-name" value="">

      <div class="card-body" style="display:flex; flex-direction:column; gap:1.1rem;">
        <div>
          <label style="display:block; font-size:0.88rem; font-weight:600; margin-bottom:0.4rem;">
            <?= __("tax_field_name", "Nombre") ?> *
          </label>
          <input type="text" name="name" id="form-name" class="form-control" required placeholder="Ej. Linux, Tutoriales, etc." style="width:100%;">
          <div style="font-size:0.78rem; color:var(--muted); margin-top:4px;" id="rename-warning" style="display:none;">
            ⚠ <?= __("tax_rename_warning", "Si cambias el nombre, se renombrará automáticamente en todos los artículos asociados.") ?>
          </div>
        </div>

        <div>
          <label style="display:block; font-size:0.88rem; font-weight:600; margin-bottom:0.4rem;">
            <?= __("tax_field_slug", "Slug / Ruta") ?>
          </label>
          <input type="text" name="slug" id="form-slug" class="form-control" placeholder="<?= __("tax_slug_placeholder", "dejar vacío para auto-generar") ?>" style="width:100%;">
          <div style="font-size:0.78rem; color:var(--muted); margin-top:4px;">
            <?= __("tax_slug_hint", "Identificador en la URL:") ?> <code>/<?= $isCats ? 'category' : 'tag' ?>/slug</code>
          </div>
        </div>

        <div>
          <label style="display:block; font-size:0.88rem; font-weight:600; margin-bottom:0.4rem;">
            <?= __("tax_field_desc", "Descripción (opcional)") ?>
          </label>
          <textarea name="description" id="form-description" class="form-control" rows="3" placeholder="<?= __("tax_desc_placeholder", "Breve descripción...") ?>" style="width:100%;"></textarea>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; padding:1rem 1.25rem; border-top:1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeModals()"><?= __("cancel", "Cancelar") ?></button>
        <button type="submit" class="btn btn-primary" id="form-submit-btn"><?= __("save", "Guardar") ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Fusionar Término -->
<div id="modal-tax-merge" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
  <div class="card" style="width:100%; max-width:480px; margin:1rem; box-shadow:0 12px 35px rgba(0,0,0,0.4);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title"><?= __("tax_merge_title", "Fusionar término") ?></span>
      <button type="button" onclick="closeModals()" style="background:none; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="taxonomy" value="<?= $tab ?>">
      <input type="hidden" name="action" value="merge">
      <input type="hidden" name="source_name" id="merge-source-name" value="">

      <div class="card-body" style="display:flex; flex-direction:column; gap:1.1rem;">
        <p style="font-size:0.88rem; color:var(--text); line-height:1.5;">
          <?= __("tax_merge_intro", "Vas a fusionar el término:") ?> <strong id="merge-source-display" style="color:var(--primary);"></strong>
        </p>
        <div class="sheet-hint" style="font-size:0.82rem; color:var(--muted); background:var(--surface2); padding:8px 12px; border-radius:6px; border:1px solid var(--border);">
          <?= __("tax_merge_desc", "Todos los artículos que tengan este término pasarán a tener el término de destino seleccionado.") ?>
        </div>

        <div>
          <label style="display:block; font-size:0.88rem; font-weight:600; margin-bottom:0.4rem;">
            <?= __("tax_target_term", "Término de destino") ?> *
          </label>
          <select name="target_name" id="merge-target-select" class="form-control" required style="width:100%;">
            <option value=""><?= __("tax_select_target", "-- Selecciona un término existente --") ?></option>
            <?php foreach ($items as $it): ?>
              <option value="<?= htmlspecialchars($it['name']) ?>"><?= htmlspecialchars($it['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; padding:1rem 1.25rem; border-top:1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeModals()"><?= __("cancel", "Cancelar") ?></button>
        <button type="submit" class="btn btn-primary"><?= __("tax_btn_merge_confirm", "Confirmar fusión") ?></button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Eliminar Término -->
<div id="modal-tax-delete" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(3px);">
  <div class="card" style="width:100%; max-width:440px; margin:1rem; box-shadow:0 12px 35px rgba(0,0,0,0.4);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <span class="card-title" style="color:var(--danger);"><?= __("tax_delete_title", "Eliminar término") ?></span>
      <button type="button" onclick="closeModals()" style="background:none; border:none; color:var(--muted); font-size:1.4rem; cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="taxonomy" value="<?= $tab ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="name" id="delete-name" value="">

      <div class="card-body" style="display:flex; flex-direction:column; gap:1.1rem;">
        <p style="font-size:0.92rem; color:var(--text); line-height:1.5;">
          <?= __("tax_delete_intro", "¿Estás seguro de que deseas eliminar permanentemente:") ?> <strong id="delete-name-display" style="color:var(--danger);"></strong>
        </p>

        <div id="delete-posts-warning" style="display:none; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.25); border-radius:8px; padding:10px 14px; font-size:0.85rem; color:var(--text);">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0;">
            <input type="checkbox" name="remove_from_posts" value="1" checked style="width:16px; height:16px;">
            <span><?= __("tax_delete_posts_hint", "Desvincular también este término de todos los artículos que lo usan.") ?></span>
          </label>
        </div>
      </div>

      <div class="card-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; padding:1rem 1.25rem; border-top:1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeModals()"><?= __("cancel", "Cancelar") ?></button>
        <button type="submit" class="btn btn-danger"><?= __("tax_btn_delete_confirm", "Eliminar") ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function filterTaxonomies(query) {
  query = query.toLowerCase().trim();
  const rows = document.querySelectorAll('.tax-row');
  rows.forEach(row => {
    const name = row.dataset.name || '';
    const slug = row.dataset.slug || '';
    if (!query || name.includes(query) || slug.includes(query)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

function openNewModal() {
  document.getElementById('form-modal-title').innerText = <?= json_encode(__raw($isCats ? 'tax_new_category' : 'tax_new_tag', $newItemLabel)) ?>;
  document.getElementById('form-original-name').value = '';
  document.getElementById('form-name').value = '';
  document.getElementById('form-slug').value = '';
  document.getElementById('form-description').value = '';
  document.getElementById('form-submit-btn').innerText = <?= json_encode(__raw('save', 'Guardar')) ?>;
  document.getElementById('rename-warning').style.display = 'none';
  showModal('modal-tax-form');
  setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function openEditModal(name, slug, desc) {
  document.getElementById('form-modal-title').innerText = <?= json_encode(__raw('tax_edit_title', 'Editar')) ?> + ': ' + name;
  document.getElementById('form-original-name').value = name;
  document.getElementById('form-name').value = name;
  document.getElementById('form-slug').value = slug;
  document.getElementById('form-description').value = desc || '';
  document.getElementById('form-submit-btn').innerText = <?= json_encode(__raw('tax_save_changes', 'Guardar cambios')) ?>;
  document.getElementById('rename-warning').style.display = 'block';
  showModal('modal-tax-form');
  setTimeout(() => document.getElementById('form-name').focus(), 50);
}
  document.getElementById('rename-warning').style.display = 'block';
  showModal('modal-tax-form');
  setTimeout(() => document.getElementById('form-name').focus(), 50);
}

function openMergeModal(name) {
  document.getElementById('merge-source-name').value = name;
  document.getElementById('merge-source-display').innerText = '«' + name + '»';
  const select = document.getElementById('merge-target-select');
  for (let i = 0; i < select.options.length; i++) {
    select.options[i].disabled = (select.options[i].value.toLowerCase() === name.toLowerCase());
  }
  select.value = '';
  showModal('modal-tax-merge');
}

function openDeleteModal(name, count) {
  document.getElementById('delete-name').value = name;
  document.getElementById('delete-name-display').innerText = '«' + name + '»';
  const warning = document.getElementById('delete-posts-warning');
  if (count > 0) {
    warning.style.display = 'block';
  } else {
    warning.style.display = 'none';
  }
  showModal('modal-tax-delete');
}

function showModal(id) {
  closeModals();
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex';
}

function closeModals() {
  document.querySelectorAll('.modal-backdrop').forEach(m => m.style.display = 'none');
}

// Cerrar modales con Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeModals();
});
</script>

</body></html>
