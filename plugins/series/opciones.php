<?php
/**
 * Panel de Gestión de Series de Artículos
 */
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

require_once __DIR__ . '/series.php';

$allSeries = series_get_all();
$totalSeries = count($allSeries);
$totalArticlesInSeries = 0;
foreach ($allSeries as $s) {
    $totalArticlesInSeries += count($s['posts'] ?? []);
}

$csrfToken = generate_csrf();
$base = rtrim(base_url(), '/');
?>

<div class="series-admin-wrap" style="max-width:1100px;margin:0 auto;">

  <!-- Summary KPI Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="card" style="padding:1.25rem 1.4rem;display:flex;align-items:center;gap:1.2rem;">
      <div style="width:48px;height:48px;border-radius:10px;background:rgba(var(--accent-rgb, 224,92,26),0.12);color:var(--accent,#e05c1a);display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
        📚
      </div>
      <div>
        <div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Series Creadas</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--text);line-height:1.2;"><?= $totalSeries ?></div>
      </div>
    </div>

    <div class="card" style="padding:1.25rem 1.4rem;display:flex;align-items:center;gap:1.2rem;">
      <div style="width:48px;height:48px;border-radius:10px;background:rgba(59,130,246,0.12);color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
        📑
      </div>
      <div>
        <div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Entregas Agrupadas</div>
        <div style="font-size:1.6rem;font-weight:800;color:var(--text);line-height:1.2;"><?= $totalArticlesInSeries ?></div>
      </div>
    </div>

    <div class="card" style="padding:1.25rem 1.4rem;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;">Página Pública</div>
        <a href="<?= $base ?>/series" target="_blank" style="font-size:0.95rem;font-weight:700;color:var(--accent);text-decoration:none;">/series ↗</a>
      </div>
      <button type="button" class="btn btn-primary" onclick="openCreateModal()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nueva Serie
      </button>
    </div>
  </div>

  <!-- Series List Table Card -->
  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:1rem 1.4rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
      <div>
        <h3 style="margin:0;font-size:1.1rem;font-weight:700;">Colecciones y Series</h3>
        <span style="font-size:0.8rem;color:var(--muted);">Organiza artículos paso a paso para tus lectores</span>
      </div>
    </div>

    <?php if (empty($allSeries)): ?>
      <div style="padding:3.5rem 1.5rem;text-align:center;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">🗂️</div>
        <h4 style="font-size:1.15rem;font-weight:700;margin:0 0 0.4rem 0;">No tienes ninguna serie creada</h4>
        <p style="font-size:0.85rem;color:var(--muted);max-width:440px;margin:0 auto 1.25rem auto;">
          Crea tu primera serie para agrupar guías, tutoriales o cursos en entregas numeradas.
        </p>
        <button type="button" class="btn btn-primary" onclick="openCreateModal()">Crear primera serie</button>
      </div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="table" style="width:100%;margin:0;border-collapse:collapse;font-size:0.88rem;">
          <thead>
            <tr style="background:var(--surface2);color:var(--muted);border-bottom:1px solid var(--border);text-align:left;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">
              <th style="padding:0.75rem 1.25rem;width:60px;">Portada</th>
              <th style="padding:0.75rem 1rem;">Título y Slug</th>
              <th style="padding:0.75rem 1rem;">Entregas</th>
              <th style="padding:0.75rem 1rem;">Última Modificación</th>
              <th style="padding:0.75rem 1.25rem;text-align:right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allSeries as $s): 
              $count = count($s['posts'] ?? []);
            ?>
              <tr style="border-bottom:1px solid var(--border);transition:background 0.15s ease;">
                <td style="padding:0.75rem 1.25rem;">
                  <?php if (!empty($s['cover'])): ?>
                    <img src="<?= htmlspecialchars($s['cover']) ?>" alt="" style="width:48px;height:36px;object-fit:cover;border-radius:4px;border:1px solid var(--border);">
                  <?php else: ?>
                    <div style="width:48px;height:36px;border-radius:4px;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:1rem;">
                      📖
                    </div>
                  <?php endif; ?>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <strong style="color:var(--text);font-size:0.95rem;display:block;"><?= htmlspecialchars($s['title']) ?></strong>
                  <span style="font-size:0.75rem;color:var(--muted);font-family:monospace;">/series/<?= htmlspecialchars($s['slug']) ?></span>
                  <?php if (!empty($s['description'])): ?>
                    <div style="font-size:0.8rem;color:var(--muted);margin-top:0.25rem;max-width:480px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?= htmlspecialchars($s['description']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <span style="display:inline-block;padding:0.2rem 0.55rem;border-radius:12px;background:rgba(var(--accent-rgb,224,92,26),0.12);color:var(--accent);font-weight:700;font-size:0.78rem;">
                    <?= $count ?> <?= $count === 1 ? 'entrega' : 'entregas' ?>
                  </span>
                </td>
                <td style="padding:0.75rem 1rem;font-size:0.8rem;color:var(--muted);">
                  <?= !empty($s['updated_at']) ? date('d/m/Y H:i', strtotime($s['updated_at'])) : '-' ?>
                </td>
                <td style="padding:0.75rem 1.25rem;text-align:right;">
                  <div style="display:inline-flex;gap:0.4rem;align-items:center;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick='openManageDeliveries(<?= json_encode($s['id']) ?>)' title="Ordenar y gestionar artículos">
                      📑 Entregas
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($s) ?>)' title="Editar datos de la serie">
                      ✏️
                    </button>
                    <a href="<?= $base ?>/series/<?= htmlspecialchars($s['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Ver en el sitio público">
                      ↗
                    </a>
                    <button type="button" class="btn btn-danger btn-sm" onclick='deleteSeries(<?= json_encode($s['id']) ?>, <?= json_encode($s['title']) ?>)' title="Eliminar serie">
                      🗑️
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

<!-- Modal 1: Crear / Editar Serie -->
<div id="series-edit-modal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
  <div class="card" style="max-width:520px;width:100%;margin:0;padding:1.5rem;box-shadow:0 8px 32px rgba(0,0,0,0.5);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">
      <h3 id="modal-series-title" style="margin:0;font-size:1.15rem;font-weight:700;">Nueva Serie</h3>
      <button type="button" onclick="closeEditModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted);">✕</button>
    </div>

    <form id="series-form" onsubmit="handleSaveSeries(event)">
      <input type="hidden" id="form-series-id" name="id" value="">
      
      <div style="margin-bottom:1rem;">
        <label class="form-label" for="form-series-name" style="display:block;margin-bottom:0.35rem;font-weight:600;font-size:0.85rem;">Título de la serie *</label>
        <input type="text" id="form-series-name" class="form-control" placeholder="Ej: Introducción a Docker" required style="width:100%;padding:0.5rem 0.75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.9rem;">
      </div>

      <div style="margin-bottom:1rem;">
        <label class="form-label" for="form-series-slug" style="display:block;margin-bottom:0.35rem;font-weight:600;font-size:0.85rem;">Slug amigable (opcional)</label>
        <input type="text" id="form-series-slug" class="form-control" placeholder="Ej: introduccion-a-docker" style="width:100%;padding:0.5rem 0.75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.9rem;">
        <div style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem;">Si lo dejas en blanco se genera automáticamente.</div>
      </div>

      <div style="margin-bottom:1rem;">
        <label class="form-label" for="form-series-desc" style="display:block;margin-bottom:0.35rem;font-weight:600;font-size:0.85rem;">Descripción</label>
        <textarea id="form-series-desc" class="form-control" rows="3" placeholder="Resumen o temario del curso/serie..." style="width:100%;padding:0.5rem 0.75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.88rem;resize:vertical;"></textarea>
      </div>

      <div style="margin-bottom:1.5rem;">
        <label class="form-label" for="form-series-cover" style="display:block;margin-bottom:0.35rem;font-weight:600;font-size:0.85rem;">Imagen de portada (URL o ruta)</label>
        <div style="display:flex;gap:0.5rem;">
          <input type="text" id="form-series-cover" class="form-control" placeholder="/media/2026/portada.jpg" style="flex:1;padding:0.5rem 0.75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.9rem;">
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:0.75rem;padding-top:1rem;border-top:1px solid var(--border);">
        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancelar</button>
        <button type="submit" id="btn-save-series" class="btn btn-primary">Guardar Serie</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 2: Gestión y Reordenación de Entregas -->
<div id="series-deliveries-modal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99999;align-items:center;justify-content:center;padding:1rem;">
  <div class="card" style="max-width:680px;width:100%;margin:0;padding:1.5rem;box-shadow:0 8px 32px rgba(0,0,0,0.5);max-height:90vh;display:flex;flex-direction:column;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid var(--border);">
      <div>
        <h3 id="modal-deliveries-series-title" style="margin:0;font-size:1.15rem;font-weight:700;">Entregas de la Serie</h3>
        <span style="font-size:0.78rem;color:var(--muted);">Arrastra o usa los botones para definir el orden de lectura</span>
      </div>
      <button type="button" onclick="closeDeliveriesModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted);">✕</button>
    </div>

    <!-- Agregar artículo a la serie -->
    <div style="padding:0.85rem;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:1rem;display:flex;gap:0.6rem;align-items:center;">
      <select id="select-add-post" class="form-control" style="flex:1;padding:0.45rem 0.65rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.85rem;">
        <option value="">Cargando artículos disponibles...</option>
      </select>
      <button type="button" class="btn btn-secondary btn-sm" onclick="handleAddPostToSeries()">+ Añadir</button>
    </div>

    <!-- Lista interactiva de entregas -->
    <div id="deliveries-list" style="flex:1;overflow-y:auto;padding-right:0.25rem;display:flex;flex-direction:column;gap:0.4rem;min-height:180px;">
      <!-- Rellenado dinámicamente -->
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;padding-top:1rem;margin-top:1rem;border-top:1px solid var(--border);">
      <span id="deliveries-status-msg" style="font-size:0.8rem;color:var(--muted);"></span>
      <div style="display:flex;gap:0.6rem;">
        <button type="button" class="btn btn-secondary" onclick="closeDeliveriesModal()">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const API_URL = <?= json_encode($base . '/plugins/series/api.php') ?>;

let currentActiveSeriesId = null;
let currentDeliveries = [];

function openCreateModal() {
  document.getElementById('modal-series-title').textContent = 'Nueva Serie';
  document.getElementById('form-series-id').value = '';
  document.getElementById('form-series-name').value = '';
  document.getElementById('form-series-slug').value = '';
  document.getElementById('form-series-desc').value = '';
  document.getElementById('form-series-cover').value = '';
  const modal = document.getElementById('series-edit-modal');
  modal.style.display = 'flex';
  document.getElementById('form-series-name').focus();
}

function openEditModal(series) {
  document.getElementById('modal-series-title').textContent = 'Editar Serie';
  document.getElementById('form-series-id').value = series.id || '';
  document.getElementById('form-series-name').value = series.title || '';
  document.getElementById('form-series-slug').value = series.slug || '';
  document.getElementById('form-series-desc').value = series.description || '';
  document.getElementById('form-series-cover').value = series.cover || '';
  const modal = document.getElementById('series-edit-modal');
  modal.style.display = 'flex';
  document.getElementById('form-series-name').focus();
}

function closeEditModal() {
  document.getElementById('series-edit-modal').style.display = 'none';
}

function handleSaveSeries(e) {
  e.preventDefault();
  const id = document.getElementById('form-series-id').value.trim();
  const title = document.getElementById('form-series-name').value.trim();
  const slug = document.getElementById('form-series-slug').value.trim();
  const description = document.getElementById('form-series-desc').value.trim();
  const cover = document.getElementById('form-series-cover').value.trim();

  const btn = document.getElementById('btn-save-series');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  const action = id ? 'update_series' : 'create_series';
  const fd = new FormData();
  fd.append('action', action);
  fd.append('csrf', CSRF_TOKEN);
  if (id) fd.append('id', id);
  fd.append('title', title);
  fd.append('slug', slug);
  fd.append('description', description);
  fd.append('cover', cover);

  fetch(API_URL, {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false;
    btn.textContent = 'Guardar Serie';
    if (d.ok) {
      window.location.reload();
    } else {
      alert(d.error || 'Ocurrió un error al guardar');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Guardar Serie';
    alert('Error al conectar con el servidor');
  });
}

function deleteSeries(id, title) {
  if (!confirm(`¿Eliminar la serie "${title}"? Los artículos continuarán publicados en el blog, solo se desvincularán de esta serie.`)) {
    return;
  }
  const fd = new FormData();
  fd.append('action', 'delete_series');
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', id);

  fetch(API_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) window.location.reload();
      else alert(d.error || 'Error al eliminar');
    });
}

// ── Gestión de Entregas y Orden ──────────────────────────
function openManageDeliveries(seriesId) {
  currentActiveSeriesId = seriesId;
  const modal = document.getElementById('series-deliveries-modal');
  modal.style.display = 'flex';
  loadDeliveries(seriesId);
}

function closeDeliveriesModal() {
  document.getElementById('series-deliveries-modal').style.display = 'none';
  window.location.reload();
}

function loadDeliveries(seriesId) {
  const listEl = document.getElementById('deliveries-list');
  listEl.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--muted);">Cargando entregas...</div>';

  fetch(`${API_URL}?action=get_series_posts&id=${encodeURIComponent(seriesId)}&csrf=${encodeURIComponent(CSRF_TOKEN)}`)
    .then(r => r.json())
    .then(d => {
      if (!d.ok) {
        listEl.innerHTML = `<div style="color:var(--red);padding:1rem;">${d.error || 'Error al cargar'}</div>`;
        return;
      }
      document.getElementById('modal-deliveries-series-title').textContent = `Entregas: ${d.series.title}`;
      currentDeliveries = d.posts || [];
      renderDeliveriesList();

      // Rellenar selector de artículos disponibles
      const sel = document.getElementById('select-add-post');
      sel.innerHTML = '<option value="">— Seleccionar artículo para añadir —</option>';
      (d.available || []).forEach(art => {
        const opt = document.createElement('option');
        opt.value = art.slug;
        opt.textContent = art.title;
        sel.appendChild(opt);
      });
    });
}

function renderDeliveriesList() {
  const listEl = document.getElementById('deliveries-list');
  listEl.innerHTML = '';

  if (!currentDeliveries.length) {
    listEl.innerHTML = '<div style="text-align:center;padding:2.5rem 1rem;color:var(--muted);font-size:0.88rem;">No hay entregas en esta serie. Selecciona un artículo arriba y pulsa Añadir.</div>';
    return;
  }

  currentDeliveries.forEach((item, idx) => {
    const row = document.createElement('div');
    row.className = 'delivery-row';
    row.style.display = 'flex';
    row.style.alignItems = 'center';
    row.style.gap = '0.6rem';
    row.style.padding = '0.55rem 0.85rem';
    row.style.background = 'var(--surface)';
    row.style.border = '1px solid var(--border)';
    row.style.borderRadius = '6px';
    row.draggable = true;
    row.dataset.idx = idx;

    row.innerHTML = `
      <span style="cursor:grab;color:var(--muted);font-size:0.9rem;" title="Arrastrar para ordenar">⋮⋮</span>
      <span style="width:24px;height:24px;border-radius:4px;background:rgba(var(--accent-rgb,224,92,26),0.12);color:var(--accent);display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:0.75rem;">
        ${idx + 1}
      </span>
      <span style="flex:1;font-size:0.88rem;color:var(--text);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        ${escapeHtml(item.title)}
      </span>
      <div style="display:inline-flex;gap:0.25rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="moveDelivery(${idx}, -1)" ${idx === 0 ? 'disabled' : ''} title="Subir">▲</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="moveDelivery(${idx}, 1)" ${idx === currentDeliveries.length - 1 ? 'disabled' : ''} title="Bajar">▼</button>
        <button type="button" class="btn btn-danger btn-sm" onclick="removeDelivery('${escapeHtml(item.slug)}')" title="Quitar de la serie">✕</button>
      </div>
    `;

    // Eventos Drag & Drop
    row.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('text/plain', idx);
      row.style.opacity = '0.5';
    });
    row.addEventListener('dragend', () => {
      row.style.opacity = '1';
    });
    row.addEventListener('dragover', (e) => {
      e.preventDefault();
      row.style.borderTop = '2px solid var(--accent)';
    });
    row.addEventListener('dragleave', () => {
      row.style.borderTop = '1px solid var(--border)';
    });
    row.addEventListener('drop', (e) => {
      e.preventDefault();
      row.style.borderTop = '1px solid var(--border)';
      const fromIdx = parseInt(e.dataTransfer.getData('text/plain'), 10);
      const toIdx = idx;
      if (!isNaN(fromIdx) && fromIdx !== toIdx) {
        const moved = currentDeliveries.splice(fromIdx, 1)[0];
        currentDeliveries.splice(toIdx, 0, moved);
        renderDeliveriesList();
        saveDeliveriesOrder();
      }
    });

    listEl.appendChild(row);
  });
}

function moveDelivery(idx, direction) {
  const target = idx + direction;
  if (target < 0 || target >= currentDeliveries.length) return;
  const temp = currentDeliveries[idx];
  currentDeliveries[idx] = currentDeliveries[target];
  currentDeliveries[target] = temp;
  renderDeliveriesList();
  saveDeliveriesOrder();
}

function saveDeliveriesOrder() {
  const msgEl = document.getElementById('deliveries-status-msg');
  msgEl.textContent = 'Guardando orden...';

  const slugs = currentDeliveries.map(d => d.slug);
  const fd = new FormData();
  fd.append('action', 'set_order');
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', currentActiveSeriesId);
  fd.append('posts', JSON.stringify(slugs));

  fetch(API_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        msgEl.textContent = '✓ Orden guardado';
        setTimeout(() => { msgEl.textContent = ''; }, 2000);
      } else {
        msgEl.textContent = 'Error al guardar';
      }
    });
}

function handleAddPostToSeries() {
  const sel = document.getElementById('select-add-post');
  const slug = sel.value;
  if (!slug) return;

  const fd = new FormData();
  fd.append('action', 'add_post');
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', currentActiveSeriesId);
  fd.append('slug', slug);

  fetch(API_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        loadDeliveries(currentActiveSeriesId);
      } else {
        alert(d.error || 'Error al añadir artículo');
      }
    });
}

function removeDelivery(slug) {
  if (!confirm('¿Quitar este artículo de la serie?')) return;

  const fd = new FormData();
  fd.append('action', 'remove_post');
  fd.append('csrf', CSRF_TOKEN);
  fd.append('id', currentActiveSeriesId);
  fd.append('slug', slug);

  fetch(API_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        loadDeliveries(currentActiveSeriesId);
      } else {
        alert(d.error || 'Error al remover');
      }
    });
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>'"]/g, tag => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[tag] || tag));
}
</script>

