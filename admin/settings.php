<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once dirname(__DIR__) . '/core/users.php';
require_once dirname(__DIR__) . '/core/image_cache.php';
require_once __DIR__ . '/layout.php';
require_login();

$csrf = generate_csrf();
$config = cms_config();
$msg = '';
$error = '';
$active_section = $_GET['section'] ?? 'general';

$currentUser = current_user();
$username = $currentUser['username'] ?? ($_SESSION['username'] ?? 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Error de seguridad CSRF.';
    } else {
        $action = $_POST['action'] ?? '';
        $active_section = $_POST['active_section'] ?? $active_section;

        if ($action === 'general') {
            require_permission('manage_settings');
            $config['site_title'] = trim($_POST['site_title'] ?? '');
            $config['tagline'] = trim($_POST['tagline'] ?? '');
            $config['base_url'] = rtrim(trim($_POST['base_url'] ?? ''), '/');
            $config['posts_per_page'] = max(1, (int)($_POST['posts_per_page'] ?? 8));
            $config['rss_posts_limit'] = max(1, (int)($_POST['rss_posts_limit'] ?? 20));
            $config['footer_text'] = trim($_POST['footer_text'] ?? '');
            $config['language'] = in_array($_POST['language'] ?? '', ['es', 'en'], true) ? $_POST['language'] : 'es';
            $config['timezone'] = in_array($_POST['timezone'] ?? '', timezone_identifiers_list(), true) ? $_POST['timezone'] : (date_default_timezone_get() ?: 'UTC');
            cms_save_config($config);
            $msg = __("saved_general", "Ajustes generales guardados.");
            $active_section = 'general';
        }

        if ($action === 'appearance') {
            require_permission('manage_settings');
            $config['theme'] = sanitize_filename($_POST['theme'] ?? 'default');
            $config['theme_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['theme_color'] ?? '') ? $_POST['theme_color'] : '#6366f1';
            $config['admin_scheme'] = in_array($_POST['admin_scheme'] ?? '', ['dark','midnight','slate','warm','light']) ? $_POST['admin_scheme'] : 'dark';

            // Handle logo upload or removal
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

            // Handle favicon upload or removal
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
                $msg = 'Apariencia guardada correctamente.';
            }
            $active_section = 'appearance';
        }

        if ($action === 'mastodon') {
            require_permission('manage_settings');
            $config['mastodon_handle'] = trim($_POST['mastodon_handle'] ?? '');
            $config['mastodon_url']    = trim($_POST['mastodon_url'] ?? '');
            cms_save_config($config);
            $msg = 'Ajustes de Mastodon guardados.';
            $active_section = 'mastodon';
        }

        if ($action === 'update_profile') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $bio   = trim($_POST['bio'] ?? '');

            if ($currentUser) {
                $currentUser['name']  = $name ?: $currentUser['username'];
                $currentUser['email'] = $email;
                $currentUser['bio']   = $bio;

                // Handle avatar upload or removal
                if (!empty($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1') {
                    if (!empty($currentUser['avatar']) && str_starts_with($currentUser['avatar'], 'media/avatars/')) {
                        $oldFile = ROOT_PATH . '/' . $currentUser['avatar'];
                        if (file_exists($oldFile)) @unlink($oldFile);
                    }
                    $currentUser['avatar'] = '';
                } elseif (!empty($_FILES['avatar_file']['tmp_name'])) {
                    $up = user_save_avatar($currentUser['username'], $_FILES['avatar_file']);
                    if ($up) {
                        $currentUser['avatar'] = $up;
                    } else {
                        $error = 'Formato de imagen no permitido o archivo superior a 8 MB.';
                    }
                }

                if (!$error) {
                    save_user($currentUser);
                    $msg = 'Perfil y foto de avatar actualizados con éxito.';
                }
            }
            $active_section = 'profile';
        }

        if ($action === 'security_password') {
            $current_pass = $_POST['current_password'] ?? '';
            $new_pass     = $_POST['new_password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';

            if (!$currentUser || !verify_password($current_pass, $currentUser['password'])) {
                $error = 'La contraseña actual no es correcta.';
            } elseif ($new_pass !== $confirm_pass) {
                $error = 'Las nuevas contraseñas no coinciden.';
            } elseif (strlen($new_pass) < 8) {
                $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
            } else {
                $currentUser['password'] = hash_password($new_pass);
                save_user($currentUser);
                $config['admin_pass'] = $currentUser['password'];
                cms_save_config($config);
                $msg = 'Contraseña actualizada con éxito.';
            }
            $active_section = 'security';
        }

        if ($action === 'enable_2fa') {
            $secret = trim($_POST['secret'] ?? '');
            $code   = trim($_POST['code'] ?? '');

            if (!$secret || !totp_verify($secret, $code)) {
                $error = 'Código de verificación incorrecto. Asegúrate de que la hora de tu teléfono esté sincronizada.';
            } else {
                $recoveryCodes = totp_generate_recovery_codes(8);
                $currentUser['two_factor_enabled'] = true;
                $currentUser['two_factor_secret']  = $secret;
                $currentUser['recovery_codes']     = array_map(fn($c) => ['code' => $c, 'used' => false], $recoveryCodes);
                save_user($currentUser);
                $_SESSION['show_recovery_codes'] = $recoveryCodes;
                $msg = '¡Autenticación en dos pasos (2FA) activada con éxito!';
            }
            $active_section = 'security';
        }

        if ($action === 'disable_2fa') {
            $password = $_POST['password'] ?? '';
            if (!$currentUser || !verify_password($password, $currentUser['password'])) {
                $error = 'Contraseña incorrecta para desactivar 2FA.';
            } else {
                $currentUser['two_factor_enabled'] = false;
                $currentUser['two_factor_secret']  = '';
                $currentUser['recovery_codes']     = [];
                save_user($currentUser);
                $msg = 'Autenticación en dos pasos (2FA) desactivada.';
            }
            $active_section = 'security';
        }

        if ($action === 'regenerate_recovery_codes') {
            $password = $_POST['password'] ?? '';
            if (!$currentUser || !verify_password($password, $currentUser['password'])) {
                $error = 'Contraseña incorrecta.';
            } else {
                $recoveryCodes = totp_generate_recovery_codes(8);
                $currentUser['recovery_codes'] = array_map(fn($c) => ['code' => $c, 'used' => false], $recoveryCodes);
                save_user($currentUser);
                $_SESSION['show_recovery_codes'] = $recoveryCodes;
                $msg = 'Nuevos códigos de recuperación generados.';
            }
            $active_section = 'security';
        }

        if ($action === 'clear_image_cache') {
            require_permission('manage_settings');
            $count = image_cache_clear();
            $msg = __("image_cache_cleared", "Caché de imágenes vaciada correctamente.") . " ({$count} " . ($count === 1 ? 'archivo eliminado' : 'archivos eliminados') . ')';
            $active_section = 'cache';
        }

        $config = cms_config();
        $currentUser = current_user();
    }
}

$cache_info = image_cache_info();
$themes = available_themes();
$is2FAActive = !empty($currentUser['two_factor_enabled']) && !empty($currentUser['two_factor_secret']);
$candidateSecret = totp_generate_secret(16);
$otpauthUrl = totp_otpauth_url($currentUser['name'] ?? $username, $candidateSecret, site_title());

$newRecoveryCodes = $_SESSION['show_recovery_codes'] ?? null;
unset($_SESSION['show_recovery_codes']);

admin_header(__("settings_title"), 'settings');
?>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Modal: Recovery Codes Download/View -->
    <?php if ($newRecoveryCodes): ?>
    <div id="recovery-codes-modal" style="position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1.5rem;backdrop-filter:blur(4px)">
      <div class="card" style="width:100%;max-width:500px;box-shadow:0 16px 40px rgba(0,0,0,0.5)">
        <div class="card-header">
          <span class="card-title">🛡️ Guarda tus códigos de recuperación</span>
          <button onclick="document.getElementById('recovery-codes-modal').style.display='none'" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem">✕</button>
        </div>
        <div class="card-body">
          <p style="font-size:0.92rem;color:var(--text2);margin-bottom:1rem;line-height:1.5">
            Si pierdes el acceso a tu aplicación de autenticación, necesitarás uno de estos códigos para iniciar sesión. <strong>Cada código solo puede utilizarse una vez.</strong>
          </p>
          <div style="background:var(--bg);border:1px solid var(--border2);border-radius:10px;padding:1.25rem;display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;font-family:monospace;font-size:1.1rem;font-weight:700;color:var(--accent);text-align:center">
            <?php foreach ($newRecoveryCodes as $c): ?>
              <div><?= htmlspecialchars($c) ?></div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:0.75rem;margin-top:1.5rem;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="copyRecoveryCodes(<?= htmlspecialchars(json_encode(implode("\n", $newRecoveryCodes))) ?>)">Copiar códigos</button>
            <button class="btn btn-primary" onclick="document.getElementById('recovery-codes-modal').style.display='none'">He guardado los códigos</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Layout: Sidebar de Secciones + Panel de Contenido -->
    <div class="settings-layout">
      <!-- Sidebar de Preferencias -->
      <aside class="settings-sidebar">
        <div class="settings-sidebar-title">Secciones</div>
        <nav class="settings-nav">
          <a href="#general" class="settings-nav-item <?= $active_section === 'general' ? 'active' : '' ?>" onclick="switchSection('general', event)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span><?= __("settings_general") ?></span>
          </a>
          <a href="<?= base_url() ?>/admin/themes.php?tab=appearance" class="settings-nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
            <span><?= __("settings_appearance") ?> ↗</span>
          </a>
          <a href="#profile" class="settings-nav-item <?= $active_section === 'profile' ? 'active' : '' ?>" onclick="switchSection('profile', event)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Mi Perfil & Avatar</span>
          </a>
          <a href="#security" class="settings-nav-item <?= $active_section === 'security' ? 'active' : '' ?>" onclick="switchSection('security', event)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>Seguridad & 2FA</span>
            <?php if ($is2FAActive): ?><span class="badge badge-green" style="font-size:0.68rem;padding:0.15rem 0.45rem;margin-left:auto">2FA</span><?php endif; ?>
          </a>
          <a href="<?= base_url() ?>/admin/users.php" class="settings-nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Gestión de Usuarios</span>
            <span style="font-size:0.75rem;color:var(--muted);margin-left:auto">↗</span>
          </a>
          <a href="#cache" class="settings-nav-item <?= $active_section === 'cache' ? 'active' : '' ?>" onclick="switchSection('cache', event)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span>Multimedia & Caché</span>
          </a>
          <a href="#mastodon" class="settings-nav-item <?= $active_section === 'mastodon' ? 'active' : '' ?>" onclick="switchSection('mastodon', event)">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            <span><?= __("settings_mastodon") ?></span>
          </a>
        </nav>
      </aside>

      <!-- Contenedor Principal de Ajustes -->
      <main class="settings-content">

        <!-- 1. General Settings -->
        <div id="section-general" class="settings-section-card <?= $active_section === 'general' ? 'active' : '' ?>">
          <div class="card">
            <div class="card-header"><span class="card-title"><?= __("settings_general") ?></span></div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="general">
                <input type="hidden" name="active_section" value="general">
                <div class="form-group">
                  <label class="form-label"><?= __("field_site_title") ?></label>
                  <input type="text" name="site_title" value="<?= htmlspecialchars($config['site_title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label"><?= __("field_tagline") ?></label>
                  <input type="text" name="tagline" value="<?= htmlspecialchars($config['tagline'] ?? '') ?>" placeholder="Una breve descripción">
                </div>
                <div class="form-group">
                  <label class="form-label"><?= __("field_base_url") ?></label>
                  <input type="url" name="base_url" value="<?= htmlspecialchars($config['base_url'] ?? '') ?>" required>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label"><?= __("field_posts_per_page") ?></label>
                    <input type="number" name="posts_per_page" value="<?= (int)($config['posts_per_page'] ?? 8) ?>" min="1" max="100">
                  </div>
                  <div class="form-group">
                    <label class="form-label"><?= __("field_rss_posts_limit") ?></label>
                    <input type="number" name="rss_posts_limit" value="<?= (int)($config['rss_posts_limit'] ?? 20) ?>" min="1" max="100">
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label"><?= __("field_language", "Idioma del sitio") ?></label>
                    <select name="language" class="form-control">
                      <option value="es" <?= ($config['language'] ?? 'es') === 'es' ? 'selected' : '' ?>>Español</option>
                      <option value="en" <?= ($config['language'] ?? 'es') === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label"><?= __("field_timezone", "Zona horaria") ?></label>
                    <select name="timezone" class="form-control">
                      <?php
                      $cur_tz = $config['timezone'] ?? date_default_timezone_get() ?: 'UTC';
                      $timezones = timezone_identifiers_list();
                      foreach ($timezones as $tz): ?>
                        <option value="<?= htmlspecialchars($tz) ?>" <?= $cur_tz === $tz ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label"><?= __("field_footer_text") ?></label>
                  <input type="text" name="footer_text" value="<?= htmlspecialchars($config['footer_text'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary"><?= __("btn_save_settings") ?></button>
              </form>
            </div>
          </div>
        </div>

        <!-- 2. Appearance -->
        <div id="section-appearance" class="settings-section-card <?= $active_section === 'appearance' ? 'active' : '' ?>">
          <div class="card">
            <div class="card-header"><span class="card-title"><?= __("settings_appearance") ?></span></div>
            <div class="card-body">
              <div style="background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.25);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <div>
                  <strong style="color:var(--text);font-size:0.95rem">🎨 Sección unificada</strong>
                  <div style="color:var(--text2);font-size:0.85rem;margin-top:2px">La apariencia, logotipo, esquemas y temas se gestionan conjuntamente desde la sección <strong>Temas</strong>.</div>
                </div>
                <a href="<?= base_url() ?>/admin/themes.php?tab=appearance" class="btn btn-primary btn-sm">Ir a Temas y Apariencia →</a>
              </div>
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="appearance">
                <input type="hidden" name="active_section" value="appearance">

                <!-- Logo del Blog -->
                <div class="form-group" style="margin-bottom:1.5rem">
                  <label class="form-label" style="font-weight:700"><?= __("field_site_logo", "Logotipo del blog") ?></label>
                  <div style="display:flex;align-items:center;gap:1.5rem;padding:1.25rem;background:var(--surface2);border:1px solid var(--border2);border-radius:12px">
                    <?php $currentLogo = site_logo(); ?>
                    <div id="settings-logo-box" style="width:72px;height:72px;border-radius:10px;background:var(--surface);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid var(--border);padding:6px">
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
                      <input type="file" name="site_logo_file" accept="image/*,.svg" class="form-control" onchange="previewSiteLogo(this, 'settings-logo-box')">
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

                <!-- Favicon del Sitio -->
                <div class="form-group" style="margin-bottom:1.5rem">
                  <label class="form-label" style="font-weight:700"><?= __("field_site_favicon", "Favicon del sitio") ?></label>
                  <div style="display:flex;align-items:center;gap:1.5rem;padding:1.25rem;background:var(--surface2);border:1px solid var(--border2);border-radius:12px">
                    <?php 
                      $currentFav = site_favicon();
                      $hasCustomFav = !empty($config['site_favicon']);
                    ?>
                    <div id="settings-favicon-box" style="width:52px;height:52px;border-radius:10px;background:var(--surface);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid var(--border);padding:6px">
                      <img src="<?= htmlspecialchars($currentFav) ?>" alt="Favicon" style="max-width:100%;max-height:100%;object-fit:contain">
                    </div>
                    <div style="flex:1">
                      <input type="file" name="site_favicon_file" accept=".ico,.png,.svg,.webp,image/x-icon,image/png,image/svg+xml" class="form-control" onchange="previewSiteFavicon(this, 'settings-favicon-box')">
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

                <div class="form-group">
                  <label class="form-label"><?= __("field_theme") ?></label>
                  <select name="theme">
                    <?php foreach ($themes as $key => $theme): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= ($config['theme'] ?? 'default') === $key ? 'selected' : '' ?>>
                      <?= htmlspecialchars($theme['label'] ?? $key) ?>
                      <?php if (!empty($theme['description'])): ?> — <?= htmlspecialchars($theme['description']) ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label"><?= __("field_admin_scheme") ?></label>
                  <select name="admin_scheme">
                    <?php
                    $scheme_options = [
                      'dark'     => __("scheme_dark"),
                      'midnight' => __("scheme_midnight"),
                      'slate'    => __("scheme_slate"),
                      'warm'     => __("scheme_warm"),
                      'light'    => __("scheme_light"),
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
                <div class="form-group">
                  <label class="form-label"><?= __("field_accent") ?></label>
                  <div style="display:flex;align-items:center;gap:0.75rem">
                    <input type="color" name="theme_color" id="color-pick" value="<?= htmlspecialchars($config['theme_color'] ?? '#6366f1') ?>"
                      style="width:50px;height:42px;padding:3px;border-radius:8px;cursor:pointer;border:1px solid var(--border)">
                    <input type="text" id="color-text" value="<?= htmlspecialchars($config['theme_color'] ?? '#6366f1') ?>" style="flex:1"
                      pattern="^#[0-9a-fA-F]{6}$">
                  </div>
                  <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap" id="swatches">
                    <?php foreach (['#6366f1','#8b5cf6','#ec4899','#ef4444','#f97316','#eab308','#22c55e','#14b8a6','#0ea5e9','#64748b'] as $c): ?>
                    <button type="button" class="swatch" data-color="<?= $c ?>"
                      style="width:28px;height:28px;border-radius:50%;background:<?= $c ?>;border:2px solid <?= ($config['theme_color'] ?? '') === $c ? '#fff' : 'transparent' ?>;cursor:pointer"></button>
                    <?php endforeach; ?>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= __("btn_save_appearance") ?></button>
              </form>
            </div>
          </div>
        </div>

        <!-- 3. Mi Perfil & Avatar -->
        <div id="section-profile" class="settings-section-card <?= $active_section === 'profile' ? 'active' : '' ?>">
          <div class="card">
            <div class="card-header"><span class="card-title">Mi Perfil y Foto de Avatar</span></div>
            <div class="card-body">
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="active_section" value="profile">

                <!-- Avatar Box -->
                <div style="display:flex;align-items:center;gap:1.5rem;padding:1.4rem;background:var(--surface2);border:1px solid var(--border2);border-radius:12px;margin-bottom:1.5rem">
                  <?php 
                    $profileAvatar = !empty($currentUser['avatar']) ? user_avatar_url($currentUser['avatar']) : '';
                  ?>
                  <div id="settings-avatar-box" style="width:76px;height:76px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;box-shadow:0 4px 14px rgba(var(--accent-rgb),0.3);border:2px solid var(--border)">
                    <?php if ($profileAvatar): ?>
                      <img src="<?= htmlspecialchars(image_cache_url($profileAvatar, 150, 150)) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                      <span style="font-size:2rem;font-weight:700;color:#fff"><?= strtoupper(substr($currentUser['name'] ?? $currentUser['username'] ?? 'A', 0, 1)) ?></span>
                    <?php endif; ?>
                  </div>
                  <div style="flex:1">
                    <label class="form-label" style="margin-bottom:0.35rem;font-weight:700">Foto de perfil / Avatar</label>
                    <input type="file" name="avatar_file" accept="image/*" class="form-control" onchange="previewUserAvatar(this, 'settings-avatar-box')">
                    <div style="display:flex;gap:1.25rem;align-items:center;margin-top:0.45rem">
                      <small style="color:var(--muted);font-size:0.8rem">JPG, PNG, WebP o GIF (máx. 8 MB)</small>
                      <?php if ($profileAvatar): ?>
                        <label style="font-size:0.84rem;color:var(--red);display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;font-weight:600">
                          <input type="checkbox" name="remove_avatar" value="1">
                          Eliminar avatar actual
                        </label>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label">Nombre para mostrar</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($currentUser['name'] ?? '') ?>" placeholder="Tu nombre público">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Correo electrónico</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" placeholder="tu@correo.com">
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:1.5rem">
                  <label class="form-label">Biografía o nota personal</label>
                  <textarea name="bio" rows="3" class="form-control" placeholder="Escribe algo sobre ti…"><?= htmlspecialchars($currentUser['bio'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Guardar Perfil y Avatar</button>
              </form>
            </div>
          </div>
        </div>

        <!-- 4. Security & 2FA -->
        <div id="section-security" class="settings-section-card <?= $active_section === 'security' ? 'active' : '' ?>">
          <!-- 2FA Card -->
          <div class="card">
            <div class="card-header">
              <span class="card-title">Autenticación en Dos Pasos (2FA TOTP)</span>
              <?php if ($is2FAActive): ?>
                <span class="badge badge-green">✓ 2FA Activado y Protegido</span>
              <?php else: ?>
                <span class="badge badge-yellow">● Desactivado</span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($is2FAActive): ?>
                <p style="font-size:0.95rem;color:var(--text2);margin-bottom:1.5rem;line-height:1.6">
                  Tu cuenta está protegida con verificación en dos pasos mediante aplicación de autenticación (Google Authenticator, Authy, 1Password, etc.).
                </p>

                <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
                  <!-- Regenerar códigos de recuperación -->
                  <form method="POST" onsubmit="return confirm('¿Regenerar códigos de recuperación? Los anteriores dejarán de ser válidos.')">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="regenerate_recovery_codes">
                    <input type="hidden" name="active_section" value="security">
                    <div style="display:flex;gap:0.5rem">
                      <input type="password" name="password" placeholder="Tu contraseña actual" required style="max-width:200px">
                      <button type="submit" class="btn btn-secondary">Regenerar códigos</button>
                    </div>
                  </form>

                  <!-- Desactivar 2FA -->
                  <form method="POST" onsubmit="return confirm('¿Estás seguro de que deseas desactivar la autenticación en dos pasos? Tu cuenta será menos segura.')">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="disable_2fa">
                    <input type="hidden" name="active_section" value="security">
                    <div style="display:flex;gap:0.5rem">
                      <input type="password" name="password" placeholder="Tu contraseña actual" required style="max-width:200px">
                      <button type="submit" class="btn btn-danger">Desactivar 2FA</button>
                    </div>
                  </form>
                </div>

              <?php else: ?>
                <p style="font-size:0.95rem;color:var(--text2);margin-bottom:1.25rem;line-height:1.6">
                  La autenticación en dos pasos añade una capa crucial de seguridad a tu cuenta solicitando un código temporal de 6 dígitos cada vez que inicias sesión.
                </p>

                <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
                  <div style="display:grid;grid-template-columns:auto 1fr;gap:1.5rem;align-items:center">
                    <div style="background:#ffffff;padding:14px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,0.12);min-width:188px;min-height:188px" id="qr-container">
                      <div id="qrcode"></div>
                    </div>
                    <div>
                      <h4 style="font-size:1rem;font-weight:700;color:var(--text);margin-bottom:0.5rem">Paso 1: Escanea el código o introduce la clave</h4>
                      <p style="font-size:0.88rem;color:var(--muted);margin-bottom:0.75rem">
                        Abre la cámara de tu iPhone o tu aplicación (Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.) y escanea el código QR, o introduce la clave manualmente:
                      </p>
                      <div style="display:flex;align-items:center;gap:0.5rem;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:0.5rem 0.85rem;max-width:360px;margin-bottom:0.75rem">
                        <code style="font-size:1.1rem;font-weight:700;color:var(--accent);letter-spacing:0.1em;flex:1" id="secret-text"><?= htmlspecialchars(chunk_split($candidateSecret, 4, ' ')) ?></code>
                        <button type="button" onclick="navigator.clipboard.writeText('<?= $candidateSecret ?>');alert('Clave copiada')" class="btn btn-secondary btn-sm">Copiar</button>
                      </div>
                      <a href="<?= htmlspecialchars($otpauthUrl) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:0.4rem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                        Abrir directamente en la app de autenticación (si estás en el móvil)
                      </a>
                    </div>
                  </div>
                </div>

                <form method="POST">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="enable_2fa">
                  <input type="hidden" name="active_section" value="security">
                  <input type="hidden" name="secret" value="<?= $candidateSecret ?>">
                  <h4 style="font-size:1rem;font-weight:700;color:var(--text);margin-bottom:0.5rem">Paso 2: Introduce el código de 6 dígitos para confirmar</h4>
                  <div style="display:flex;gap:0.75rem;max-width:340px">
                    <input type="text" name="code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required
                      style="letter-spacing:0.25em;text-align:center;font-size:1.25rem;font-weight:700">
                    <button type="submit" class="btn btn-primary" style="white-space:nowrap">Activar 2FA</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- Password Change Card -->
          <div class="card">
            <div class="card-header"><span class="card-title">Cambiar mi contraseña</span></div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="security_password">
                <input type="hidden" name="active_section" value="security">
                <div class="form-group">
                  <label class="form-label">Contraseña actual</label>
                  <input type="password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label">Nueva contraseña</label>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Confirmar nueva contraseña</label>
                    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                  </div>
                </div>
                <button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
              </form>
            </div>
          </div>
        </div>

        <!-- 4. Multimedia & Cache -->
        <div id="section-cache" class="settings-section-card <?= $active_section === 'cache' ? 'active' : '' ?>">
          <div class="card">
            <div class="card-header"><span class="card-title"><?= __("settings_image_cache", "Caché de Imágenes y Rendimiento") ?></span></div>
            <div class="card-body">
              <p style="font-size:0.95rem;color:var(--text2);margin-bottom:1.5rem;line-height:1.6">
                <?= __("image_cache_desc", "Las imágenes se redimensionan y optimizan automáticamente en formato WebP con cabeceras de caché ultrarrápida (1 año + ETag 304).") ?>
              </p>
              <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.75rem">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0;border-bottom:1px solid var(--border)">
                  <span style="font-size:0.95rem;color:var(--muted);font-weight:500"><?= __("image_cache_driver", "Motor de optimización") ?></span>
                  <span class="badge badge-green">✓ <?= htmlspecialchars(ucfirst($cache_info['driver'])) ?><?= $cache_info['driver'] === 'graphicsmagick' ? ' (WebP activo)' : '' ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0;border-bottom:1px solid var(--border)">
                  <span style="font-size:0.95rem;color:var(--muted);font-weight:500"><?= __("image_cache_files", "Imágenes en caché") ?></span>
                  <span style="font-size:1.05rem;font-weight:700;color:var(--text)"><?= $cache_info['count'] ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0">
                  <span style="font-size:0.95rem;color:var(--muted);font-weight:500"><?= __("image_cache_size", "Espacio en disco") ?></span>
                  <span style="font-size:1.05rem;font-weight:700;color:var(--text)"><?= $cache_info['size'] ?></span>
                </div>
              </div>
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="clear_image_cache">
                <input type="hidden" name="active_section" value="cache">
                <button type="submit" class="btn btn-secondary">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  <?= __("image_cache_clear_btn", "Vaciar caché de imágenes") ?>
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- 5. Mastodon -->
        <div id="section-mastodon" class="settings-section-card <?= $active_section === 'mastodon' ? 'active' : '' ?>">
          <div class="card">
            <div class="card-header"><span class="card-title"><?= __("settings_mastodon") ?></span></div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="mastodon">
                <input type="hidden" name="active_section" value="mastodon">
                <div class="form-group">
                  <label class="form-label">Tu handle de Mastodon</label>
                  <input type="text" name="mastodon_handle" value="<?= htmlspecialchars($config['mastodon_handle'] ?? '') ?>" placeholder="@usuario@mastodon.social">
                  <div style="font-size:0.82rem;color:var(--muted);margin-top:0.4rem">
                    Se usa para la verificación de perfil. Añade <code style="background:var(--surface2);padding:2px 6px;border-radius:4px">rel="me"</code> a tu perfil de Mastodon.
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">URL de tu perfil en Mastodon</label>
                  <input type="url" name="mastodon_url" value="<?= htmlspecialchars($config['mastodon_url'] ?? '') ?>" placeholder="https://mastodon.social/@usuario">
                </div>
                <div style="background:var(--surface2);border:1px solid var(--border2);border-radius:10px;padding:1rem 1.25rem;font-size:0.88rem;color:var(--text2);margin-bottom:1.25rem">
                  <strong>Verificación:</strong> Añade este enlace a tu sitio con el atributo <code>rel="me"</code>:<br>
                  <code style="color:var(--accent);display:inline-block;margin-top:0.35rem">&lt;a rel="me" href="<?= htmlspecialchars($config['mastodon_url'] ?? 'https://mastodon.social/@usuario') ?>"&gt;Mastodon&lt;/a&gt;</code>
                </div>
                <button type="submit" class="btn btn-primary"><?= __("btn_save_mastodon") ?></button>
              </form>
            </div>
          </div>
        </div>

      </main>
    </div>
  </div>
</div>

<style>
.settings-layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 2rem;
  align-items: start;
}
.settings-sidebar {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 1.25rem 0.85rem;
  position: sticky;
  top: calc(var(--topbar-h) + 1.5rem);
  box-shadow: 0 4px 16px rgba(0,0,0,0.04);
}
.settings-sidebar-title {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--muted);
  padding: 0 0.75rem 0.75rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: 0.5rem;
}
.settings-nav {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.settings-nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.85rem;
  border-radius: 9px;
  color: var(--text2);
  font-size: 0.92rem;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.15s ease;
}
.settings-nav-item:hover {
  background: var(--surface2);
  color: var(--text);
  transform: translateX(2px);
}
.settings-nav-item.active {
  background: rgba(var(--accent-rgb), 0.14);
  color: var(--accent);
  box-shadow: inset 3px 0 0 var(--accent);
}
.settings-section-card {
  display: none;
}
.settings-section-card.active {
  display: block;
  animation: fadeIn 0.2s ease;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 900px) {
  .settings-layout { grid-template-columns: 1fr; }
  .settings-sidebar { position: static; }
  .settings-nav { flex-direction: row; overflow-x: auto; padding-bottom: 0.5rem; }
  .settings-nav-item { white-space: nowrap; }
}
</style>

<script src="assets/js/qrcode.min.js"></script>
<script>
function switchSection(name, e) {
  if (e) e.preventDefault();
  document.querySelectorAll('.settings-nav-item').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.settings-section-card').forEach(el => el.classList.remove('active'));

  const link = document.querySelector(`.settings-nav-item[href="#${name}"]`);
  if (link) link.classList.add('active');

  const sec = document.getElementById(`section-${name}`);
  if (sec) sec.classList.add('active');

  history.replaceState(null, null, `?section=${name}`);
  if (name === 'security') {
    setTimeout(renderQRCode, 50);
  }
}

function copyRecoveryCodes(text) {
  navigator.clipboard.writeText(text).then(() => {
    alert('Códigos de recuperación copiados al portapapeles.');
  });
}

// Swatches
const colorPick = document.getElementById('color-pick');
const colorText = document.getElementById('color-text');
if (colorPick && colorText) {
  colorPick.addEventListener('input', () => { colorText.value = colorPick.value; });
  colorText.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(colorText.value)) colorPick.value = colorText.value; });
  document.querySelectorAll('.swatch').forEach(s => {
    s.addEventListener('click', () => {
      colorPick.value = s.dataset.color;
      colorText.value = s.dataset.color;
      document.querySelectorAll('.swatch').forEach(x => x.style.borderColor = 'transparent');
      s.style.borderColor = '#fff';
    });
  });
}

// Real ISO/IEC 18004 compliant QR Code generation for 2FA setup
function renderQRCode() {
  const container = document.getElementById('qrcode');
  if (!container || typeof QRCode === 'undefined') return;
  const url = <?= json_encode($otpauthUrl) ?>;

  container.innerHTML = '';
  new QRCode(container, {
    text: url,
    width: 172,
    height: 172,
    colorDark: '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });
}
renderQRCode();

function previewUserAvatar(input, targetId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById(targetId);
      box.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover">`;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function previewSiteLogo(input, targetId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById(targetId);
      box.innerHTML = `<img src="${e.target.result}" alt="Logo" style="max-width:100%;max-height:100%;object-fit:contain">`;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function previewSiteFavicon(input, targetId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById(targetId);
      box.innerHTML = `<img src="${e.target.result}" alt="Favicon" style="max-width:100%;max-height:100%;object-fit:contain">`;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// Restore section from hash or query
window.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash.replace('#', '');
  const urlParams = new URLSearchParams(window.location.search);
  const sec = hash || urlParams.get('section');
  if (sec && document.getElementById(`section-${sec}`)) {
    switchSection(sec);
  }
});
</script>
</body></html>
