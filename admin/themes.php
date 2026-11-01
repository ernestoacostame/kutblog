<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$csrf = generate_csrf();
$msg = '';
$error = '';
$config = cms_config();
$tab = $_GET['tab'] ?? 'themes';

// Recursive delete helper for themes
function cms_delete_theme_dir(string $dir): bool {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = "$dir/$file";
        is_dir($path) ? cms_delete_theme_dir($path) : @unlink($path);
    }
    return @rmdir($dir);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'activate_theme') {
        require_permission('manage_settings');
        $themeKey = trim($_POST['theme'] ?? '');
        $themes = available_themes();
        if (isset($themes[$themeKey])) {
            $config['theme'] = $themeKey;
            cms_save_config($config);
            $msg = '✓ Tema activado correctamente.';
        } else {
            $error = 'Tema no encontrado.';
        }
        $tab = 'themes';
    } elseif ($action === 'delete_theme') {
        require_permission('manage_settings');
        $themeKey = trim($_POST['theme'] ?? '');
        $current = active_theme();

        if ($themeKey === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $themeKey)) {
            $error = 'Nombre de tema no válido.';
        } elseif ($themeKey === $current) {
            $error = 'No puedes eliminar el tema activo actualmente.';
        } elseif ($themeKey === 'default') {
            $error = 'El tema por defecto (default) no se puede eliminar.';
        } else {
            $themeDir = THEMES_PATH . '/' . $themeKey;
            if (is_dir($themeDir)) {
                if (cms_delete_theme_dir($themeDir)) {
                    $msg = "✓ Tema «{$themeKey}» eliminado correctamente.";
                } else {
                    $error = 'No se pudo eliminar la carpeta del tema. Comprueba los permisos.';
                }
            } else {
                $error = 'La carpeta del tema no existe.';
            }
        }
        $tab = 'themes';
    } elseif ($action === 'appearance') {
        require_permission('manage_settings');
        if (!empty($_POST['theme'])) {
            $config['theme'] = sanitize_filename($_POST['theme']);
        }
        $config['theme_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['theme_color'] ?? '') ? $_POST['theme_color'] : '#6366f1';
        $config['admin_scheme'] = in_array($_POST['admin_scheme'] ?? '', ['dark','midnight','slate','warm','light']) ? $_POST['admin_scheme'] : 'dark';

        // Logo handling
        if (!empty($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            cms_remove_logo();
            $config['site_logo'] = '';
        } elseif (!empty($_FILES['site_logo_file']['tmp_name'])) {
            $upLogo = cms_save_logo($_FILES['site_logo_file']);
            if ($upLogo) {
                $config['site_logo'] = $upLogo;
            } else {
                $error = 'Formato de imagen no permitido o archivo superior a 5 MB (se permiten PNG, SVG, JPG, WebP, GIF, AVIF).';
            }
        }

        // Favicon handling
        if (!empty($_POST['remove_favicon']) && $_POST['remove_favicon'] === '1') {
            cms_remove_favicon();
            $config['site_favicon'] = '';
        } elseif (!empty($_FILES['site_favicon_file']['tmp_name'])) {
            $upFav = cms_save_favicon($_FILES['site_favicon_file']);
            if ($upFav) {
                $config['site_favicon'] = $upFav;
            } else {
                $errFav = 'Formato de favicon no permitido (se admiten ICO, PNG, SVG o WebP, máx. 5 MB).';
                $error = $error ? ($error . ' ' . $errFav) : $errFav;
            }
        }

        cms_save_config($config);
        if (!$error) {
            $msg = '✓ Apariencia guardada correctamente.';
        }
        $tab = 'appearance';
    }
}

$themes = available_themes();
$current = active_theme();
admin_header(__("themes_title", "Temas y Apariencia"), 'themes');
?>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Navigation Tabs -->
    <div style="display:flex;gap:0.5rem;margin-bottom:1.75rem;border-bottom:1px solid var(--border);padding-bottom:0.75rem">
      <a href="themes.php?tab=themes" class="btn <?= $tab === 'themes' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:0.9rem">
        🎨 <?= __("themes_title", "Temas instalados") ?>
      </a>
      <a href="themes.php?tab=appearance" class="btn <?= $tab === 'appearance' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:0.9rem">
        ✨ <?= __("settings_appearance", "Personalizar apariencia") ?>
      </a>
    </div>

    <?php if ($tab === 'themes'): ?>
    <!-- Themes Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1.4rem;margin-bottom:2rem">
      <?php foreach ($themes as $key => $theme): ?>
      <div class="card" style="<?= $key === $current ? 'border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)' : '' ?>;display:flex;flex-direction:column;justify-content:space-between;border-radius:12px;overflow:hidden">
        <div>
          <!-- Thumbnail preview box -->
          <div style="height:140px;background:<?= $key === 'dark' || str_contains($key, 'dark') ? '#0f0f13' : '#f8f8fb' ?>;overflow:hidden;display:flex;align-items:center;justify-content:center;border-bottom:1px solid var(--border)">
            <?php if ($key === 'default'): ?>
            <div style="width:85%;background:#fff;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,0.1);padding:12px">
              <div style="height:8px;background:#e5e7eb;border-radius:4px;width:60%;margin-bottom:8px"></div>
              <div style="height:5px;background:#e5e7eb;border-radius:4px;margin-bottom:4px"></div>
              <div style="height:5px;background:#e5e7eb;border-radius:4px;width:80%;margin-bottom:4px"></div>
              <div style="height:5px;background:#e5e7eb;border-radius:4px;width:70%"></div>
            </div>
            <?php elseif ($key === 'dark' || str_contains($key, 'dark')): ?>
            <div style="width:85%;background:#17171e;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,0.4);padding:12px;border:1px solid #252530">
              <div style="height:8px;background:#2a2a35;border-radius:4px;width:60%;margin-bottom:8px"></div>
              <div style="height:5px;background:#2a2a35;border-radius:4px;margin-bottom:4px"></div>
              <div style="height:5px;background:#2a2a35;border-radius:4px;width:80%;margin-bottom:4px"></div>
              <div style="height:5px;background:#2a2a35;border-radius:4px;width:70%"></div>
            </div>
            <?php elseif ($key === 'calendar'): ?>
            <div style="width:85%;background:var(--surface,#fff);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.08);padding:10px;display:grid;grid-template-columns:1fr 50px;gap:8px;border:1px solid var(--border)">
              <div style="display:flex;flex-direction:column;gap:5px">
                <div style="height:6px;background:var(--accent);border-radius:3px;width:40%"></div>
                <div style="height:8px;background:var(--text);border-radius:3px;width:80%"></div>
                <div style="height:4px;background:var(--muted);border-radius:2px;width:95%"></div>
                <div style="height:4px;background:var(--muted);border-radius:2px;width:70%"></div>
              </div>
              <div style="border-left:1px dashed var(--border);padding-left:6px;display:flex;flex-direction:column;gap:5px;align-items:center;justify-content:center">
                <div style="width:10px;height:10px;border-radius:50%;background:var(--accent)"></div>
                <div style="height:3px;background:var(--muted);width:100%;border-radius:2px"></div>
                <div style="width:6px;height:6px;border-radius:50%;background:var(--border)"></div>
              </div>
            </div>
            <?php elseif ($key === 'minimal' || str_contains($key, 'minimal')): ?>
            <div style="width:85%;background:var(--surface,#fff);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.08);padding:8px;display:flex;flex-direction:column;gap:6px;border:1px solid var(--border)">
              <div style="height:48px;background:linear-gradient(135deg,rgba(99,102,241,0.25),rgba(15,23,42,0.1));border-radius:6px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center">
                <span style="font-size:0.65rem;color:var(--text-muted);font-weight:700">🖼️ THUMB</span>
              </div>
              <div style="height:6px;background:var(--text);border-radius:3px;width:70%"></div>
              <div style="height:4px;background:var(--muted);border-radius:2px;width:85%"></div>
            </div>
            <?php else: ?>
            <div style="color:var(--muted);font-size:0.88rem;font-weight:600"><?= htmlspecialchars($theme['label'] ?? $key) ?></div>
            <?php endif; ?>
          </div>

          <!-- Card Content -->
          <div style="padding:1.25rem 1.4rem 0.75rem">
            <div style="font-weight:700;font-size:1.05rem;color:var(--text)"><?= htmlspecialchars($theme['label'] ?? ucfirst($key)) ?></div>
            <?php if (!empty($theme['description'])): ?>
            <div style="font-size:0.88rem;color:var(--text2);margin-top:6px;line-height:1.5"><?= htmlspecialchars($theme['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($theme['author'])): ?>
            <div style="font-size:0.8rem;color:var(--muted);margin-top:4px">por <?= htmlspecialchars($theme['author']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Footer Actions Bar -->
        <div style="padding:0.85rem 1.4rem;display:flex;align-items:center;justify-content:space-between;gap:0.75rem;border-top:1px solid var(--border);margin-top:auto;background:var(--surface)">
          <div>
            <?php if ($key === $current): ?>
              <span class="badge badge-green" style="white-space:nowrap;flex-shrink:0;padding:0.35rem 0.85rem;font-size:0.82rem;font-weight:600;display:inline-flex;align-items:center;gap:0.35rem;border-radius:9999px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?= __("themes_active", "Activo") ?>
              </span>
            <?php else: ?>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="activate_theme">
                <input type="hidden" name="theme" value="<?= htmlspecialchars($key) ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="white-space:nowrap">
                  <?= __("themes_activate", "Activar") ?>
                </button>
              </form>
            <?php endif; ?>
          </div>

          <div>
            <?php if ($key !== $current && $key !== 'default'): ?>
              <form method="POST" style="margin:0" onsubmit="return confirm('¿Estás seguro de que deseas eliminar permanentemente el tema «<?= htmlspecialchars(addslashes($theme['label'] ?? $key)) ?>»? Esta acción no se puede deshacer.');">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete_theme">
                <input type="hidden" name="theme" value="<?= htmlspecialchars($key) ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="padding:0.35rem 0.65rem;font-size:0.8rem;display:inline-flex;align-items:center;gap:0.3rem" title="Eliminar tema">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  Eliminar
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Developer Guide Card -->
    <div class="card">
      <div class="card-header"><span class="card-title">Creación de temas personalizados</span></div>
      <div class="card-body">
        <p style="color:var(--text2);font-size:0.9rem;margin-bottom:1rem">Crear un tema es muy sencillo. Solo añade una carpeta dentro de <code>themes/</code> con estos archivos:</p>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:1.25rem;font-family:monospace;font-size:0.85rem;color:var(--green);line-height:1.8">
          themes/<br>
          └── mi-tema/<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── theme.json &nbsp;&nbsp;&nbsp;← Metadatos del tema<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── header.php &nbsp;&nbsp;← HTML &lt;head&gt; y navegación<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── footer.php &nbsp;&nbsp;← Pie de página<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── index.php &nbsp;&nbsp;&nbsp;← Listado del blog<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── single.php &nbsp;&nbsp;← Vista de artículo/página<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── 404.php &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;← Página no encontrada<br>
          &nbsp;&nbsp;&nbsp;&nbsp;├── search.php &nbsp;&nbsp;← Resultados de búsqueda<br>
          &nbsp;&nbsp;&nbsp;&nbsp;└── style.css &nbsp;&nbsp;&nbsp;← Estilos CSS (opcional)
        </div>
        <p style="color:var(--text2);font-size:0.85rem;margin-top:1rem">Variables PHP disponibles en plantillas: <code style="background:var(--bg);padding:1px 6px;border-radius:4px">$post</code>, <code style="background:var(--bg);padding:1px 6px;border-radius:4px">$posts</code>, <code style="background:var(--bg);padding:1px 6px;border-radius:4px">$site_title</code>, <code style="background:var(--bg);padding:1px 6px;border-radius:4px">$theme_color</code>, <code style="background:var(--bg);padding:1px 6px;border-radius:4px">$base</code></p>
      </div>
    </div>

    <?php else: ?>
    <!-- Appearance Customization Form -->
    <div style="max-width:680px">
      <div class="card">
        <div class="card-header"><span class="card-title"><?= __("settings_appearance", "Personalizar apariencia") ?></span></div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="appearance">

            <!-- Site Logo -->
            <div class="form-group" style="margin-bottom:1.6rem">
              <label class="form-label" style="font-weight:700"><?= __("field_site_logo", "Logotipo del blog") ?></label>
              <div style="display:flex;align-items:center;gap:1.5rem;padding:1.25rem;background:var(--surface2);border:1px solid var(--border2);border-radius:12px">
                <?php $currentLogo = site_logo(); ?>
                <div id="theme-logo-box" style="width:72px;height:72px;border-radius:10px;background:var(--surface);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid var(--border);padding:6px">
                  <?php if ($currentLogo): ?>
                    <img src="<?= htmlspecialchars($currentLogo) ?>" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain">
                  <?php else: ?>
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--muted)">
                      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                      <circle cx="8.5" cy="8.5" r="1.5"></circle>
                      <polyline points="21 15 16 10 5 21"></polyline>
                    </svg>
                  <?php endif; ?>
                </div>
                <div style="flex:1">
                  <input type="file" name="site_logo_file" accept="image/*,.svg" class="form-control" onchange="previewThemeLogo(this)">
                  <div style="display:flex;gap:1.25rem;align-items:center;margin-top:0.45rem">
                    <small style="color:var(--muted);font-size:0.8rem">PNG, SVG, JPG, WebP o GIF (máx. 5 MB)</small>
                    <?php if (!empty($config['site_logo'])): ?>
                      <label style="font-size:0.84rem;color:var(--red);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                        <input type="checkbox" name="remove_logo" value="1">
                        <?= __("btn_remove_logo", "Eliminar logotipo actual") ?>
                      </label>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Site Favicon -->
            <div class="form-group" style="margin-bottom:1.6rem">
              <label class="form-label" style="font-weight:700"><?= __("field_site_favicon", "Favicon del sitio") ?></label>
              <div style="display:flex;align-items:center;gap:1.5rem;padding:1.25rem;background:var(--surface2);border:1px solid var(--border2);border-radius:12px">
                <?php 
                  $currentFav = site_favicon();
                  $hasCustomFav = !empty($config['site_favicon']);
                ?>
                <div id="theme-favicon-box" style="width:52px;height:52px;border-radius:10px;background:var(--surface);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid var(--border);padding:6px">
                  <img src="<?= htmlspecialchars($currentFav) ?>" alt="Favicon" style="max-width:100%;max-height:100%;object-fit:contain">
                </div>
                <div style="flex:1">
                  <input type="file" name="site_favicon_file" accept=".ico,.png,.svg,.webp,image/x-icon,image/png,image/svg+xml" class="form-control" onchange="previewThemeFavicon(this)">
                  <div style="display:flex;gap:1.25rem;align-items:center;margin-top:0.45rem;flex-wrap:wrap">
                    <small style="color:var(--muted);font-size:0.8rem"><?= __("field_site_favicon_hint", "Icono para pestañas del navegador (ICO, PNG o SVG, máx. 5 MB)") ?></small>
                    <?php if ($hasCustomFav): ?>
                      <label style="font-size:0.84rem;color:var(--red);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                        <input type="checkbox" name="remove_favicon" value="1">
                        <?= __("btn_remove_favicon", "Restablecer favicon predeterminado") ?>
                      </label>
                    <?php else: ?>
                      <small style="color:var(--accent);font-size:0.8rem;font-weight:600">(Predeterminado de KutBlog activo)</small>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <!-- Active Theme selector -->
            <div class="form-group" style="margin-bottom:1.4rem">
              <label class="form-label" style="font-weight:600"><?= __("field_theme", "Tema activo") ?></label>
              <select name="theme" class="form-control">
                <?php foreach ($themes as $key => $theme): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= ($config['theme'] ?? 'default') === $key ? 'selected' : '' ?>>
                  <?= htmlspecialchars($theme['label'] ?? $key) ?>
                  <?php if (!empty($theme['description'])): ?> — <?= htmlspecialchars($theme['description']) ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Admin Scheme -->
            <div class="form-group" style="margin-bottom:1.4rem">
              <label class="form-label" style="font-weight:600"><?= __("field_admin_scheme", "Tema del panel de control") ?></label>
              <select name="admin_scheme" class="form-control">
                <?php
                $scheme_options = [
                  'dark'     => __("scheme_dark", "Oscuro"),
                  'midnight' => __("scheme_midnight", "Medianoche"),
                  'slate'    => __("scheme_slate", "Pizarra"),
                  'warm'     => __("scheme_warm", "Cálido"),
                  'light'    => __("scheme_light", "Claro"),
                ];
                $current_scheme = $config['admin_scheme'] ?? 'dark';
                foreach ($scheme_options as $key => $label):
                ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $current_scheme === $key ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Accent Color -->
            <div class="form-group" style="margin-bottom:1.6rem">
              <label class="form-label" style="font-weight:600"><?= __("field_accent", "Color de acento") ?></label>
              <div style="display:flex;align-items:center;gap:0.75rem">
                <input type="color" name="theme_color" id="color-pick" value="<?= htmlspecialchars($config['theme_color'] ?? '#6366f1') ?>"
                  style="width:50px;height:42px;padding:3px;border-radius:8px;cursor:pointer;border:1px solid var(--border)"
                  oninput="document.getElementById('color-text').value = this.value">
                <input type="text" id="color-text" value="<?= htmlspecialchars($config['theme_color'] ?? '#6366f1') ?>" style="flex:1"
                  pattern="^#[0-9a-fA-F]{6}$" class="form-control"
                  oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)) document.getElementById('color-pick').value = this.value">
              </div>
              <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap" id="swatches">
                <?php foreach (['#6366f1','#8b5cf6','#ec4899','#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#0ea5e9','#64748b'] as $c): ?>
                <button type="button" class="swatch" data-color="<?= $c ?>"
                  onclick="document.getElementById('color-pick').value = '<?= $c ?>'; document.getElementById('color-text').value = '<?= $c ?>'"
                  style="width:28px;height:28px;border-radius:50%;background:<?= $c ?>;border:2px solid <?= ($config['theme_color'] ?? '') === $c ? '#fff' : 'transparent' ?>;cursor:pointer"></button>
                <?php endforeach; ?>
              </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= __("btn_save_appearance", "Guardar apariencia") ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function previewThemeLogo(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById('theme-logo-box');
      box.innerHTML = '<img src="' + e.target.result + '" alt="Logo preview" style="max-width:100%;max-height:100%;object-fit:contain">';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function previewThemeFavicon(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById('theme-favicon-box');
      box.innerHTML = '<img src="' + e.target.result + '" alt="Favicon preview" style="max-width:100%;max-height:100%;object-fit:contain">';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>
</body></html>
