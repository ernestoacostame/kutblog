<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$pluginsDir = dirname(__DIR__) . '/plugins';
if (!is_dir($pluginsDir)) {
    @mkdir($pluginsDir, 0755, true);
}

// Helper to get available plugins
function get_available_plugins(): array {
    global $pluginsDir;
    if (!is_dir($pluginsDir)) return [];

    $plugins = [];
    $folders = array_diff(scandir($pluginsDir), ['.', '..']);
    foreach ($folders as $folder) {
        $path = "$pluginsDir/$folder";
        if (is_dir($path) && file_exists("$path/plugin.json")) {
            $meta = json_decode(file_get_contents("$path/plugin.json"), true);
            if ($meta && isset($meta['id'])) {
                $meta['folder'] = $folder;
                $meta['has_options'] = file_exists("$path/opciones.php") || file_exists("$path/options.php");
                $plugins[$meta['id']] = $meta;
            }
        }
    }
    return $plugins;
}

$csrf = generate_csrf();
$msg = '';
$error = '';

// Handle activation/deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Security error.';
    } else {
        $action = $_POST['action'] ?? '';
        $plugin_id = $_POST['plugin_id'] ?? '';
        $config = cms_config();
        $active = $config['active_plugins'] ?? [];

        if ($action === 'activate') {
            if (!in_array($plugin_id, $active)) {
                $active[] = $plugin_id;
                $config['active_plugins'] = array_values($active);
                cms_save_config($config);
                $msg = 'Plugin activado correctamente.';
            }
        } elseif ($action === 'deactivate') {
            $active = array_values(array_diff($active, [$plugin_id]));
            $config['active_plugins'] = $active;
            cms_save_config($config);
            $msg = 'Plugin desactivado correctamente.';
        }
    }
}

$available_plugins = get_available_plugins();
$config = cms_config();
$active_plugins = $config['active_plugins'] ?? [];

$sub_action = $_GET['action'] ?? '';
$pluginId = $_GET['plugin'] ?? '';

// Router for plugin settings
if ($sub_action === 'settings' && $pluginId !== '') {
    if (!isset($available_plugins[$pluginId])) {
        header('Location: ' . base_url() . '/admin/plugins.php');
        exit;
    }
    if (!in_array($pluginId, $active_plugins)) {
        header('Location: ' . base_url() . '/admin/plugins.php?error=inactive');
        exit;
    }

    $plugin = $available_plugins[$pluginId];
    $folder = $plugin['folder'];
    $optionsFile = "$pluginsDir/$folder/opciones.php";
    if (!file_exists($optionsFile)) {
        $optionsFile = "$pluginsDir/$folder/options.php";
    }

    if (file_exists($optionsFile)) {
        // Load layout header for configuration view
        $active_nav = ($pluginId === 'wp-import') ? 'import' : (($pluginId === 'search-replace') ? 'tools' : (($pluginId === 'series') ? 'series' : 'plugins'));
        $page_header_title = ($pluginId === 'wp-import' || $pluginId === 'search-replace') ? htmlspecialchars($plugin['name']) : ('Configurar ' . htmlspecialchars($plugin['name']));
        admin_header($page_header_title, $active_nav);
        ?>
        </div>
      </div>
      <div class="page-body">
        <?php
        require $optionsFile;
        ?>
      </div>
      </body>
      </html>
        <?php
        exit;
    } else {
        header('Location: ' . base_url() . '/admin/plugins.php');
        exit;
    }
}

admin_header('Plugins', 'plugins');
?>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error || (($_GET['error'] ?? '') === 'inactive')): ?>
        <div class="alert alert-error">⚠ <?= htmlspecialchars($error ?: 'El plugin debe estar activo para configurarlo.') ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:2rem">
      <div class="card-header">
        <span class="card-title">Plugins instalados</span>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($available_plugins)): ?>
          <div style="text-align:center; padding:3rem; color:var(--muted)">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom:0.75rem; opacity:0.6"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <p>No se encontraron plugins instalados en la carpeta `/plugins/`.</p>
          </div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column">
            <?php foreach ($available_plugins as $id => $plugin): 
                $is_active = in_array($id, $active_plugins);
            ?>
              <div style="display:flex; align-items:center; justify-content:space-between; gap:1.5rem; padding:1.25rem 1.5rem; border-bottom:1px solid var(--border); transition:background-color 0.15s">
                <div style="flex:1; min-width:0">
                  <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap">
                    <span style="font-weight:600; font-size:1rem; color:var(--text)"><?= htmlspecialchars($plugin['name']) ?></span>
                    <span class="badge <?= $is_active ? 'badge-green' : 'badge-gray' ?>" style="font-size:0.7rem">
                      <?= $is_active ? 'Activo' : 'Inactivo' ?>
                    </span>
                  </div>
                  <?php if (!empty($plugin['description'])): ?>
                    <p style="font-size:0.85rem; color:var(--text2); margin:0.35rem 0 0.5rem; line-height:1.4"><?= htmlspecialchars($plugin['description']) ?></p>
                  <?php endif; ?>
                  <div style="display:flex; gap:1rem; font-size:0.75rem; color:var(--muted)">
                    <span>Versión: <strong><?= htmlspecialchars($plugin['version'] ?? '1.0') ?></strong></span>
                    <span>Autor: <strong><?= htmlspecialchars($plugin['author'] ?? 'Desconocido') ?></strong></span>
                  </div>
                </div>
                
                <div style="display:flex; align-items:center; gap:0.5rem; flex-shrink:0">
                  <?php if ($is_active && $plugin['has_options']): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= base_url() ?>/admin/plugins.php?action=settings&plugin=<?= urlencode($id) ?>">
                      Configurar
                    </a>
                  <?php endif; ?>

                  <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="plugin_id" value="<?= htmlspecialchars($id) ?>">
                    <?php if ($is_active): ?>
                      <input type="hidden" name="action" value="deactivate">
                      <button type="submit" class="btn btn-danger btn-sm" style="min-width:90px; justify-content:center">
                        Desactivar
                      </button>
                    <?php else: ?>
                      <input type="hidden" name="action" value="activate">
                      <button type="submit" class="btn btn-primary btn-sm" style="min-width:90px; justify-content:center">
                        Activar
                      </button>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
