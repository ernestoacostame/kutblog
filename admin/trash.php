<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$csrf = generate_csrf();
$tab = in_array($_GET['tab'] ?? '', ['articles', 'pages', 'media']) ? $_GET['tab'] : 'articles';
$action = $_GET['action'] ?? '';
$slug = $_GET['slug'] ?? '';
$msg = '';
$error = '';

// Handle actions
if ($action && verify_csrf($_GET['csrf'] ?? '')) {
    if ($action === 'restore' && $slug) {
        if ($tab === 'media') {
            if (restore_media($slug)) $msg = "Archivo '{$slug}' restaurado correctamente.";
            else $error = "No se pudo restaurar el archivo.";
        } else {
            if (restore_content($tab, $slug)) $msg = "Elemento restaurado a {$tab}.";
            else $error = "No se pudo restaurar el elemento.";
        }
    } elseif ($action === 'purge' && $slug) {
        if ($tab === 'media') {
            if (purge_media($slug)) $msg = "Archivo eliminado definitivamente.";
            else $error = "Error al eliminar el archivo.";
        } else {
            if (purge_content($tab, $slug)) $msg = "Elemento eliminado definitivamente.";
            else $error = "Error al eliminar el elemento.";
        }
    } elseif ($action === 'empty') {
        $count = empty_trash($tab);
        $msg = "Se vació la papelera de {$tab} ({$count} elementos eliminados).";
    }
}

$items = list_trash($tab);
$totalArticles = count(list_trash('articles'));
$totalPages    = count(list_trash('pages'));
$totalMedia    = count(list_trash('media'));

admin_header('Papelera', 'trash');
?>
      <div style="display:flex; align-items:center; gap:0.5rem;">
        <?php if (!empty($items)): ?>
        <a href="?tab=<?= $tab ?>&action=empty&csrf=<?= $csrf ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('¿Seguro que deseas vaciar definitivamente todos los elementos de esta papelera?')">
          Vaciar papelera
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Tabs -->
    <div style="display:flex; gap:0.5rem; margin-bottom:1.5rem; border-bottom:1px solid var(--border); padding-bottom:0.75rem;">
      <a href="?tab=articles" class="btn <?= $tab === 'articles' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Entradas (<?= $totalArticles ?>)
      </a>
      <a href="?tab=pages" class="btn <?= $tab === 'pages' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Páginas (<?= $totalPages ?>)
      </a>
      <a href="?tab=media" class="btn <?= $tab === 'media' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Medios (<?= $totalMedia ?>)
      </a>
    </div>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Elementos en la papelera</h3>
        <span style="font-size:0.8rem; color:var(--muted);"><?= count($items) ?> elementos</span>
      </div>

      <?php if (empty($items)): ?>
        <div style="padding:3.5rem 1.5rem; text-align:center; color:var(--muted);">
          <div style="font-size:2.2rem; margin-bottom:0.5rem; opacity:0.6;">🗑️</div>
          <div style="font-size:1rem; font-weight:600; color:var(--text);">La papelera está vacía</div>
          <p style="font-size:0.85rem; margin-top:0.25rem;">No hay ningún elemento eliminado temporalmente en esta sección.</p>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Título / Archivo</th>
              <th>Fecha de eliminación</th>
              <th style="text-align:right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <div style="font-weight:600; color:var(--text);">
                  <?= htmlspecialchars($item['title']) ?>
                </div>
                <div style="font-size:0.75rem; color:var(--muted); font-family:var(--font-mono); margin-top:2px;">
                  <?= htmlspecialchars($item['slug']) ?>
                  <?php if (!empty($item['size'])): ?>
                    · <?= round($item['size'] / 1024, 1) ?> KB
                  <?php endif; ?>
                </div>
              </td>
              <td style="font-size:0.82rem; color:var(--text2);">
                <?= !empty($item['trashed_at']) ? format_date($item['trashed_at'], 'admin') : '—' ?>
              </td>
              <td style="text-align:right;">
                <div style="display:inline-flex; gap:0.4rem;">
                  <a href="?tab=<?= $tab ?>&action=restore&slug=<?= urlencode($item['slug']) ?>&csrf=<?= $csrf ?>"
                     class="btn btn-secondary btn-sm" title="Restaurar este elemento">
                    Restaurar
                  </a>
                  <a href="?tab=<?= $tab ?>&action=purge&slug=<?= urlencode($item['slug']) ?>&csrf=<?= $csrf ?>"
                     class="btn btn-danger btn-sm"
                     onclick="return confirm('¿Eliminar definitivamente este elemento? Esta acción no se puede deshacer.')"
                     title="Eliminar permanentemente">
                    Eliminar
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>

