<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/newsletter.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

if (!cms_plugin_is_active('newsletter')) {
    header('Location: ' . base_url() . '/admin/plugins.php');
    exit;
}

$csrf = generate_csrf();
$tab = in_array($_GET['tab'] ?? '', ['subscribers', 'send', 'smtp']) ? $_GET['tab'] : 'subscribers';
$msg = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // 1. Add subscriber manually
    if ($action === 'add_subscriber') {
        $email = trim($_POST['email'] ?? '');
        $res = newsletter_add_subscriber($email);
        if (!empty($res['ok'])) {
            $msg = "Suscriptor añadido ({$email}). Correo de confirmación enviado.";
        } else {
            $error = $res['error'] ?? 'Error al añadir suscriptor.';
        }
    }

    // 2. Delete subscriber
    elseif ($action === 'delete_subscriber') {
        $email = trim($_POST['email'] ?? '');
        if (newsletter_delete_subscriber($email)) {
            $msg = "Suscriptor eliminado: {$email}";
        } else {
            $error = "No se pudo eliminar el suscriptor.";
        }
    }

    // 3. Save SMTP settings
    elseif ($action === 'save_smtp') {
        $smtp = [
            'enabled'    => !empty($_POST['smtp_enabled']),
            'host'       => trim($_POST['smtp_host'] ?? ''),
            'port'       => (int)($_POST['smtp_port'] ?? 587),
            'secure'     => in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', 'none']) ? $_POST['smtp_secure'] : 'tls',
            'user'       => trim($_POST['smtp_user'] ?? ''),
            'pass'       => (string)($_POST['smtp_pass'] ?? ''),
            'from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'from_name'  => trim($_POST['smtp_from_name'] ?? 'KutBlog'),
        ];
        if (newsletter_save_config($smtp)) {
            $msg = "Configuración SMTP guardada correctamente.";
        } else {
            $error = "No se pudo guardar la configuración SMTP.";
        }
    }

    // 4. Test SMTP email
    elseif ($action === 'test_smtp') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Correo de prueba inválido.";
        } else {
            $testSubject = "Prueba de envío SMTP · " . (cms_config()['site_title'] ?? 'KutBlog');
            $testHtml = newsletter_email_shell('Prueba de envío SMTP', '
                <h2 style="margin:0 0 12px 0; font-size:20px; font-weight:700; color:#fafafa;">✓ Configuración de correo funcionando</h2>
                <p>Este es un correo de prueba enviado desde tu panel de administración en KutBlog para comprobar que la conexión SMTP está correctamente configurada.</p>
                <p style="margin-top:20px; font-size:13px; color:#a1a1aa;">Fecha de prueba: ' . date('d/m/Y H:i:s') . '</p>
            ');
            $res = newsletter_send_mail($testEmail, $testSubject, $testHtml);
            if ($res['ok']) {
                $msg = "¡Correo de prueba enviado con éxito a {$testEmail}!";
            } else {
                $error = "Fallo en el envío: " . ($res['error'] ?? 'Error desconocido');
            }
        }
    }

    // 5. Broadcast post to subscribers
    elseif ($action === 'broadcast_post') {
        $postSlug = trim($_POST['post_slug'] ?? '');
        $post = get_content('articles', $postSlug);
        if (!$post) {
            $error = "No se encontró el artículo a enviar.";
        } else {
            $force = !empty($_POST['force_resend']);
            $res = newsletter_broadcast_post($post, $force);
            if (!empty($res['ok'])) {
                $sent = $res['sent'] ?? 0;
                $total = $res['total'] ?? 0;
                $msg = "Boletín enviado: {$sent} de {$total} correos entregados.";
                if (!empty($res['errors'])) {
                    $error = "Algunos envíos fallaron: " . implode(', ', array_slice($res['errors'], 0, 3));
                }
            } else {
                $error = $res['error'] ?? 'Error al enviar el boletín.';
            }
        }
    }
}

// ── Export CSV action ────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export_csv' && verify_csrf($_GET['csrf'] ?? '')) {
    $subs = newsletter_get_subscribers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kutblog_suscriptores_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Estado', 'Fecha Registro', 'Fecha Confirmado']);
    foreach ($subs as $s) {
        fputcsv($out, [
            $s['email'],
            $s['status'],
            $s['created_at'] ?? '',
            $s['confirmed_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

$subs = newsletter_get_subscribers();
$smtpCfg = newsletter_config();

// Subscriber stats
$totalSubs = count($subs);
$confirmedSubs = count(array_filter($subs, fn($s) => ($s['status'] ?? '') === 'confirmed'));
$pendingSubs = count(array_filter($subs, fn($s) => ($s['status'] ?? '') === 'pending'));
$unsubscribedSubs = count(array_filter($subs, fn($s) => ($s['status'] ?? '') === 'unsubscribed'));

// All published posts for broadcast tab
$allPosts = list_content('articles', false, 1, 100)['items'] ?? [];
$publishedPosts = array_filter($allPosts, fn($p) => ($p['status'] ?? '') === 'published');

admin_header('Newsletter', 'newsletter');
?>
      <div style="display:flex; align-items:center; gap:0.5rem;">
        <?php if ($tab === 'subscribers'): ?>
          <a href="?action=export_csv&csrf=<?= $csrf ?>" class="btn btn-secondary btn-sm" title="Descargar lista en formato CSV">
            ↓ Exportar CSV
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Tabs navigation -->
    <div style="display:flex; gap:0.5rem; margin-bottom:1.5rem; border-bottom:1px solid var(--border); padding-bottom:0.75rem;">
      <a href="?tab=subscribers" class="btn <?= $tab === 'subscribers' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Suscriptores (<?= $confirmedSubs ?> activos)
      </a>
      <a href="?tab=send" class="btn <?= $tab === 'send' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Enviar Boletín
      </a>
      <a href="?tab=smtp" class="btn <?= $tab === 'smtp' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Configuración SMTP <?= !empty($smtpCfg['enabled']) ? '✓' : '' ?>
      </a>
    </div>

    <!-- TAB 1: SUSCRIPTORES -->
    <?php if ($tab === 'subscribers'): ?>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
        <div class="card" style="padding:1.25rem;">
          <div style="font-size:0.75rem; text-transform:uppercase; color:var(--muted); font-weight:600;">Confirmados</div>
          <div style="font-size:1.8rem; font-weight:700; color:#10b981; font-family:var(--font-mono); margin-top:4px;">
            <?= $confirmedSubs ?>
          </div>
          <div style="font-size:0.75rem; color:var(--muted); margin-top:2px;">Reciben boletines</div>
        </div>

        <div class="card" style="padding:1.25rem;">
          <div style="font-size:0.75rem; text-transform:uppercase; color:var(--muted); font-weight:600;">Pendientes (Opt-in)</div>
          <div style="font-size:1.8rem; font-weight:700; color:#f59e0b; font-family:var(--font-mono); margin-top:4px;">
            <?= $pendingSubs ?>
          </div>
          <div style="font-size:0.75rem; color:var(--muted); margin-top:2px;">Esperando confirmación</div>
        </div>

        <div class="card" style="padding:1.25rem;">
          <div style="font-size:0.75rem; text-transform:uppercase; color:var(--muted); font-weight:600;">Cancelados</div>
          <div style="font-size:1.8rem; font-weight:700; color:var(--muted); font-family:var(--font-mono); margin-top:4px;">
            <?= $unsubscribedSubs ?>
          </div>
          <div style="font-size:0.75rem; color:var(--muted); margin-top:2px;">Bajas voluntarias</div>
        </div>

        <div class="card" style="padding:1.25rem;">
          <div style="font-size:0.75rem; text-transform:uppercase; color:var(--muted); font-weight:600;">Total Registrados</div>
          <div style="font-size:1.8rem; font-weight:700; color:var(--text); font-family:var(--font-mono); margin-top:4px;">
            <?= $totalSubs ?>
          </div>
          <div style="font-size:0.75rem; color:var(--muted); margin-top:2px;">Histórico total</div>
        </div>
      </div>

      <!-- Add subscriber form -->
      <div class="card" style="margin-bottom:1.5rem; padding:1.25rem;">
        <h4 style="margin:0 0 0.75rem 0; font-size:0.95rem; font-weight:600; color:var(--text);">Añadir Suscriptor Manualmente</h4>
        <form method="POST" style="display:flex; gap:0.5rem; max-width:540px;">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add_subscriber">
          <input type="email" name="email" placeholder="correo@ejemplo.com" required
                 style="flex:1; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.5rem 0.75rem; color:var(--text); font-size:0.875rem;">
          <button type="submit" class="btn btn-primary btn-sm">Añadir y Enviar Confirmación</button>
        </form>
      </div>

      <!-- Subscribers table -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Lista de Suscriptores</h3>
          <span style="font-size:0.8rem; color:var(--muted);"><?= $totalSubs ?> contactos</span>
        </div>
        <?php if (empty($subs)): ?>
          <div style="padding:3.5rem 1rem; text-align:center; color:var(--muted);">
            <div style="font-size:2rem; margin-bottom:0.5rem; opacity:0.6;">✉️</div>
            <div style="font-weight:600; color:var(--text);">Aún no tienes suscriptores</div>
            <p style="font-size:0.85rem; margin-top:0.25rem;">Cuando los lectores se suscriban a través del formulario o los añadas manualmente, aparecerán aquí.</p>
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Correo Electrónico</th>
                <th>Estado</th>
                <th>Fecha de Registro</th>
                <th style="text-align:right;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subs as $item): ?>
              <tr>
                <td>
                  <span style="font-weight:600; color:var(--text);"><?= htmlspecialchars($item['email']) ?></span>
                </td>
                <td>
                  <?php if (($item['status'] ?? '') === 'confirmed'): ?>
                    <span class="badge" style="background:rgba(16,185,129,0.12); color:#10b981; border:1px solid rgba(16,185,129,0.25);">✓ Confirmado</span>
                  <?php elseif (($item['status'] ?? '') === 'pending'): ?>
                    <span class="badge" style="background:rgba(245,158,11,0.12); color:#f59e0b; border:1px solid rgba(245,158,11,0.25);">⏳ Pendiente</span>
                  <?php else: ?>
                    <span class="badge" style="background:rgba(113,113,122,0.15); color:var(--muted);">Cancelado</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:0.8rem; color:var(--text2); font-family:var(--font-mono);">
                  <?= htmlspecialchars($item['created_at'] ?? '—') ?>
                </td>
                <td style="text-align:right;">
                  <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este suscriptor?');">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_subscriber">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($item['email']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="padding:3px 8px; font-size:0.75rem;">Eliminar</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <!-- TAB 2: ENVIAR BOLETÍN -->
    <?php elseif ($tab === 'send'): ?>
      <div style="display:grid; grid-template-columns:360px 1fr; gap:1.5rem; align-items:start;">
        <!-- Left: Choose article and dispatch -->
        <div class="card" style="padding:1.25rem;">
          <h3 class="card-title" style="margin-bottom:1rem;">Enviar Artículo a Suscriptores</h3>
          <p style="font-size:0.85rem; color:var(--text2); margin-bottom:1.25rem; line-height:1.5;">
            Selecciona un artículo publicado para enviarlo en formato boletín a tus <strong><?= $confirmedSubs ?></strong> lectores confirmados.
          </p>

          <form method="POST" id="broadcast-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="broadcast_post">

            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.8rem; font-weight:600; color:var(--text); margin-bottom:0.4rem;">Artículo a Enviar</label>
              <select name="post_slug" id="broadcast-select" style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;" required>
                <option value="">-- Elige un artículo --</option>
                <?php foreach ($publishedPosts as $p): ?>
                  <option value="<?= htmlspecialchars($p['slug']) ?>" data-sent="<?= !empty($p['newsletter_sent_at']) ? '1' : '0' ?>" data-date="<?= htmlspecialchars($p['newsletter_sent_at'] ?? '') ?>">
                    <?= htmlspecialchars($p['title']) ?> <?= !empty($p['newsletter_sent_at']) ? ' (Ya enviado)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div id="already-sent-warn" style="display:none; background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.3); border-radius:8px; padding:0.75rem; margin-bottom:1rem; font-size:0.8rem; color:#f59e0b;">
              ⚠️ Este artículo ya fue enviado previamente. Si deseas reenviarlo a todos los suscriptores, marca la casilla a continuación:
              <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.5rem; font-weight:600; cursor:pointer;">
                <input type="checkbox" name="force_resend" value="1">
                <span>Forzar reenvío a los suscriptores</span>
              </label>
            </div>

            <div style="background:var(--bg2); border:1px solid var(--border); border-radius:8px; padding:0.75rem; margin-bottom:1.25rem; font-size:0.8rem; color:var(--muted);">
              👥 Destinatarios: <strong style="color:var(--text);"><?= $confirmedSubs ?> suscriptores confirmados</strong>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;" onclick="return confirm('¿Confirmas el envío de este boletín a todos tus suscriptores confirmados?');">
              ✉️ Enviar Boletín Ahora
            </button>
          </form>
        </div>

        <!-- Right: Live Preview -->
        <div class="card" style="padding:1.25rem;">
          <h3 class="card-title" style="margin-bottom:0.75rem;">Vista Previa del Boletín</h3>
          <p style="font-size:0.8rem; color:var(--muted); margin-bottom:1rem;">Así visualizarán tus lectores el correo en sus clientes de correo:</p>
          <div style="border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#09090b; min-height:480px;">
            <iframe id="preview-frame" sandbox="allow-same-origin" style="width:100%; height:480px; border:none; display:block;" srcdoc="<body style='background:#09090b;color:#71717a;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;'><p>Selecciona un artículo a la izquierda para previsualizarlo</p></body>"></iframe>
          </div>
        </div>
      </div>

      <script>
      (function(){
        var select = document.getElementById('broadcast-select');
        var warn = document.getElementById('already-sent-warn');
        var frame = document.getElementById('preview-frame');
        
        var postPreviews = <?= json_encode(array_combine(
          array_column($publishedPosts, 'slug'),
          array_map(fn($p) => newsletter_render_broadcast_email($p, 'demo_token'), $publishedPosts)
        ), JSON_UNESCAPED_UNICODE) ?>;

        select.addEventListener('change', function(){
          var val = this.value;
          var opt = this.options[this.selectedIndex];
          var isSent = opt ? opt.getAttribute('data-sent') === '1' : false;
          warn.style.display = isSent ? 'block' : 'none';

          if (val && postPreviews[val]) {
            frame.srcdoc = postPreviews[val];
          } else {
            frame.srcdoc = "<body style='background:#09090b;color:#71717a;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;'><p>Selecciona un artículo a la izquierda para previsualizarlo</p></body>";
          }
        });
      })();
      </script>

    <!-- TAB 3: CONFIGURACIÓN SMTP -->
    <?php elseif ($tab === 'smtp'): ?>
      <div style="max-width:680px;">
        <div class="card" style="padding:1.5rem; margin-bottom:1.5rem;">
          <h3 class="card-title" style="margin-bottom:0.5rem;">Configuración de Envío SMTP</h3>
          <p style="font-size:0.85rem; color:var(--text2); margin-bottom:1.5rem;">
            Configura el servidor SMTP para asegurar la máxima entregabilidad de los correos de confirmación y boletines sin caer en la bandeja de spam.
          </p>

          <form method="POST">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_smtp">

            <div style="margin-bottom:1.25rem;">
              <label style="display:flex; align-items:center; gap:0.6rem; font-weight:600; cursor:pointer;">
                <input type="checkbox" name="smtp_enabled" value="1" <?= !empty($smtpCfg['enabled']) ? 'checked' : '' ?>>
                <span>Habilitar entrega a través de servidor SMTP</span>
              </label>
              <div style="font-size:0.75rem; color:var(--muted); margin-left:1.7rem; margin-top:0.25rem;">
                Si está desactivado, el sistema utilizará la función nativa mail() de PHP de tu servidor web.
              </div>
            </div>

            <div style="display:grid; grid-template-columns:2fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Servidor Host SMTP</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtpCfg['host'] ?? '') ?>" placeholder="ej. smtp.gmail.com o mail.misitio.com"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Puerto</label>
                <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($smtpCfg['port'] ?? 587)) ?>" placeholder="587 o 465"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
            </div>

            <div style="margin-bottom:1rem;">
              <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Cifrado de Seguridad</label>
              <select name="smtp_secure" style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
                <option value="tls" <?= ($smtpCfg['secure'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS (Recomendado, Puerto 587)</option>
                <option value="ssl" <?= ($smtpCfg['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL / TLS Implícito (Puerto 465)</option>
                <option value="none" <?= ($smtpCfg['secure'] ?? '') === 'none' ? 'selected' : '' ?>>Sin cifrado (Puerto 25, no recomendado)</option>
              </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem;">
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Usuario SMTP</label>
                <input type="text" name="smtp_user" value="<?= htmlspecialchars($smtpCfg['user'] ?? '') ?>" placeholder="usuario o correo"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Contraseña SMTP</label>
                <input type="password" name="smtp_pass" value="<?= htmlspecialchars($smtpCfg['pass'] ?? '') ?>" placeholder="••••••••••••"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Nombre del Remitente</label>
                <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($smtpCfg['from_name'] ?? '') ?>" placeholder="Mi Blog"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:0.3rem;">Dirección de Envío (From)</label>
                <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($smtpCfg['from_email'] ?? '') ?>" placeholder="newsletter@misitio.com"
                       style="width:100%; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.6rem; color:var(--text); font-size:0.85rem;">
              </div>
            </div>

            <button type="submit" class="btn btn-primary">Guardar Configuración SMTP</button>
          </form>
        </div>

        <!-- Test email send card -->
        <div class="card" style="padding:1.5rem;">
          <h4 style="margin:0 0 0.5rem 0; font-size:0.95rem; font-weight:600;">Envío de Correo de Prueba</h4>
          <p style="font-size:0.8rem; color:var(--muted); margin-bottom:1rem;">
            Envía un mensaje de prueba para verificar que las credenciales SMTP funcionan correctamente.
          </p>
          <form method="POST" style="display:flex; gap:0.5rem;">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="test_smtp">
            <input type="email" name="test_email" placeholder="tu-correo@ejemplo.com" required
                   style="flex:1; background:var(--bg3); border:1px solid var(--border); border-radius:6px; padding:0.5rem 0.75rem; color:var(--text); font-size:0.85rem;">
            <button type="submit" class="btn btn-secondary btn-sm">Enviar Prueba</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>

