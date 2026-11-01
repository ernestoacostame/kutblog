<?php
// KutBlog - Media Folders Options & Dashboard
require_once __DIR__ . '/media-folders.php';

$data = mf_get_data();
$folders = $data['folders'];
$assignments = $data['assignments'];

// Scan media files on disk to calculate stats
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MEDIA_PATH, FilesystemIterator::SKIP_DOTS));
$allFiles = [];
foreach ($iter as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg','avif','mp3','ogg','wav','m4a','mp4','webm','flac','pdf'])) {
        $allFiles[] = str_replace(MEDIA_PATH . '/', '', $f->getPathname());
    }
}

$totalFiles = count($allFiles);
$assignedCount = 0;
foreach ($allFiles as $rel) {
    if (!empty($assignments[$rel])) {
        $assignedCount++;
    }
}
$unassignedCount = $totalFiles - $assignedCount;

$msg = '';
$err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $act = $_POST['plugin_action'] ?? '';

    if ($act === 'create') {
        $name = trim($_POST['folder_name'] ?? '');
        $parent = trim($_POST['parent_id'] ?? '') ?: null;
        if ($name) {
            $created = mf_create_folder($name, $parent);
            if ($created) {
                $msg = "Carpeta '{$name}' creada correctamente.";
                $data = mf_get_data();
                $folders = $data['folders'];
            } else {
                $err = "No se pudo crear la carpeta.";
            }
        }
    } elseif ($act === 'auto_organize') {
        // Auto-organize by existing year/month directory
        $count = 0;
        foreach ($allFiles as $rel) {
            if (preg_match('#^(\d{4})/(\d{2})/#', $rel, $m)) {
                $yearName = $m[1];
                $monthName = $m[1] . '/' . $m[2];

                // Find or create Year folder
                $yearFolderId = null;
                foreach ($folders as $f) {
                    if ($f['name'] === $yearName && empty($f['parent_id'])) {
                        $yearFolderId = $f['id'];
                        break;
                    }
                }
                if (!$yearFolderId) {
                    $newY = mf_create_folder($yearName, null);
                    if ($newY) {
                        $yearFolderId = $newY['id'];
                        $folders[] = $newY;
                    }
                }

                // Find or create Month subfolder
                $monthFolderId = null;
                foreach ($folders as $f) {
                    if ($f['name'] === $m[2] && ($f['parent_id'] ?? null) === $yearFolderId) {
                        $monthFolderId = $f['id'];
                        break;
                    }
                }
                if (!$monthFolderId && $yearFolderId) {
                    $newM = mf_create_folder($m[2], $yearFolderId);
                    if ($newM) {
                        $monthFolderId = $newM['id'];
                        $folders[] = $newM;
                    }
                }

                $target = $monthFolderId ?: $yearFolderId;
                if ($target) {
                    mf_assign_file($rel, $target);
                    $count++;
                }
            }
        }
        $data = mf_get_data();
        $folders = $data['folders'];
        $assignments = $data['assignments'];
        $msg = "Se han organizado {$count} archivos automáticamente en carpetas por año y mes.";
    } elseif ($act === 'cleanup') {
        // Clean orphaned assignments
        $cleaned = 0;
        foreach ($assignments as $rel => $fId) {
            if (!file_exists(MEDIA_PATH . '/' . $rel)) {
                unset($assignments[$rel]);
                $cleaned++;
            }
        }
        $data['assignments'] = $assignments;
        mf_save_data($data);
        $msg = "Se limpiaron {$cleaned} referencias de archivos inexistentes.";
    }
}
?>

<div style="max-width:960px;margin:0 auto">
  <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Intro Card -->
  <div class="card" style="margin-bottom:1.5rem">
    <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap">
      <div>
        <h2 style="font-size:1.35rem;font-weight:700;margin-bottom:0.35rem">Carpetas Multimedia Virtuales</h2>
        <p style="color:var(--muted);font-size:0.92rem;margin:0">
          Organiza tus imágenes, vídeos, audios y documentos en carpetas y subcarpetas sin cambiar la URL física ni romper enlaces en publicaciones anteriores.
        </p>
      </div>
      <a href="<?= base_url() ?>/admin/media.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:0.45rem">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        Ir a la Biblioteca Multimedia →
      </a>
    </div>
  </div>

  <!-- Stats Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1.25rem;border-left:4px solid var(--accent)">
      <div style="font-size:0.8rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:0.04em">Total de Carpetas</div>
      <div style="font-size:2rem;font-weight:800;color:var(--text);margin-top:0.3rem"><?= count($folders) ?></div>
    </div>
    <div class="card" style="padding:1.25rem;border-left:4px solid #10b981">
      <div style="font-size:0.8rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:0.04em">Archivos Organizados</div>
      <div style="font-size:2rem;font-weight:800;color:var(--text);margin-top:0.3rem"><?= $assignedCount ?></div>
    </div>
    <div class="card" style="padding:1.25rem;border-left:4px solid #f59e0b">
      <div style="font-size:0.8rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:0.04em">Sin Organizar</div>
      <div style="font-size:2rem;font-weight:800;color:var(--text);margin-top:0.3rem"><?= $unassignedCount ?></div>
    </div>
    <div class="card" style="padding:1.25rem;border-left:4px solid #3b82f6">
      <div style="font-size:0.8rem;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:0.04em">Total Archivos</div>
      <div style="font-size:2rem;font-weight:800;color:var(--text);margin-top:0.3rem"><?= $totalFiles ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;align-items:start">
    <!-- Left: Folders Management -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <span class="card-title">Estructura de Carpetas</span>
        <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('new-folder-form-card').scrollIntoView({behavior:'smooth'})">
          + Nueva Carpeta
        </button>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($folders)): ?>
          <div style="padding:3rem 1.5rem;text-align:center;color:var(--muted)">
            <div style="font-size:2.5rem;margin-bottom:0.5rem">📂</div>
            <p style="margin:0;font-weight:500">Aún no has creado ninguna carpeta.</p>
            <p style="margin:0.4rem 0 0;font-size:0.86rem">Crea tu primera carpeta a la derecha o pulsa en "Auto-organizar".</p>
          </div>
        <?php else: ?>
          <div style="divide-y:1px solid var(--border)">
            <?php 
            $tree = mf_get_tree();
            function render_admin_tree(array $nodes, int $depth = 0) {
                global $csrf;
                foreach ($nodes as $node): ?>
                  <div style="display:flex;align-items:center;justify-content:space-between;padding:0.85rem 1.25rem;padding-left:<?= 1.25 + ($depth * 1.5) ?>rem;border-bottom:1px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:0.6rem">
                      <span style="font-size:1.2rem">📁</span>
                      <strong style="font-size:0.95rem;color:var(--text)"><?= htmlspecialchars($node['name']) ?></strong>
                      <span style="font-size:0.75rem;color:var(--muted);background:var(--surface2);padding:2px 6px;border-radius:4px;font-weight:600">
                        ID: <?= htmlspecialchars($node['id']) ?>
                      </span>
                    </div>
                    <div style="display:flex;gap:0.4rem">
                      <button type="button" class="btn btn-xs btn-secondary" onclick="renameFolderPrompt('<?= $node['id'] ?>', '<?= htmlspecialchars(addslashes($node['name'])) ?>')">
                        Renombrar
                      </button>
                      <button type="button" class="btn btn-xs btn-danger" onclick="deleteFolderPrompt('<?= $node['id'] ?>', '<?= htmlspecialchars(addslashes($node['name'])) ?>')">
                        Eliminar
                      </button>
                    </div>
                  </div>
                  <?php if (!empty($node['children'])) render_admin_tree($node['children'], $depth + 1); ?>
                <?php endforeach;
            }
            render_admin_tree($tree);
            ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: Create Folder & Tools -->
    <div style="display:flex;flex-direction:column;gap:1.5rem">
      <div class="card" id="new-folder-form-card">
        <div class="card-header">
          <span class="card-title">Crear Carpeta</span>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="csrf" value="<?= generate_csrf() ?>">
            <input type="hidden" name="plugin_action" value="create">

            <div class="form-group" style="margin-bottom:1rem">
              <label class="form-label" style="font-weight:600">Nombre de la carpeta</label>
              <input type="text" name="folder_name" class="form-control" placeholder="Ej: Banners, Tutoriales, Logos..." required>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem">
              <label class="form-label" style="font-weight:600">Carpeta superior (opcional)</label>
              <select name="parent_id" class="form-control">
                <option value="">(Ninguna - Carpeta Principal)</option>
                <?= mf_render_folder_options() ?>
              </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%">
              + Crear Carpeta
            </button>
          </form>
        </div>
      </div>

      <!-- Quick Tools Card -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Herramientas Rápidas</span>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:0.75rem">
          <form method="POST" onsubmit="return confirm('¿Deseas organizar automáticamente todos tus archivos existentes en carpetas por Año y Mes?')">
            <input type="hidden" name="csrf" value="<?= generate_csrf() ?>">
            <input type="hidden" name="plugin_action" value="auto_organize">
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;text-align:left;display:flex;align-items:center;gap:0.5rem">
              <span>📅</span> Auto-organizar por Año/Mes
            </button>
          </form>

          <form method="POST" onsubmit="return confirm('¿Limpiar referencias de archivos que ya fueron eliminados del disco?')">
            <input type="hidden" name="csrf" value="<?= generate_csrf() ?>">
            <input type="hidden" name="plugin_action" value="cleanup">
            <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;text-align:left;display:flex;align-items:center;gap:0.5rem">
              <span>🧹</span> Limpiar referencias huérfanas
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
async function renameFolderPrompt(id, currentName) {
  const newName = prompt('Nuevo nombre para la carpeta:', currentName);
  if (!newName || newName.trim() === '' || newName === currentName) return;

  const fd = new FormData();
  fd.append('action', 'rename_folder');
  fd.append('id', id);
  fd.append('name', newName.trim());
  fd.append('csrf', <?= json_encode(generate_csrf()) ?>);

  try {
    const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Error al renombrar');
    }
  } catch(e) {
    alert('Error de conexión');
  }
}

async function deleteFolderPrompt(id, name) {
  if (!confirm(`¿Eliminar la carpeta "${name}"?\n(Los archivos permanecerán en la biblioteca, pero ya no pertenecerán a esta carpeta)`)) return;

  const fd = new FormData();
  fd.append('action', 'delete_folder');
  fd.append('id', id);
  fd.append('csrf', <?= json_encode(generate_csrf()) ?>);

  try {
    const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Error al eliminar');
    }
  } catch(e) {
    alert('Error de conexión');
  }
}
</script>
