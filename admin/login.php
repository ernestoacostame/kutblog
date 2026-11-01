<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';

if (!is_installed()) { header('Location: ../install/'); exit; }
if (is_logged_in()) { header('Location: ./'); exit; }

$error = '';
$step = 'password'; // 'password' or '2fa'

session_start_secure();
if (!empty($_SESSION['2fa_pending_user'])) {
    $step = '2fa';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_rate_limited()) {
        $error = __("login_rate_limited", "Demasiados intentos fallidos. Inténtalo de nuevo en 15 minutos.");
    } else {
        $action = $_POST['action'] ?? 'login';

        if ($action === 'login') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $result = login($username, $password);

            if ($result === true) {
                header('Location: ./');
                exit;
            } elseif ($result === '2fa_required') {
                $step = '2fa';
            } else {
                $error = __("login_error", "Usuario o contraseña incorrectos.");
                sleep(1);
            }
        } elseif ($action === 'verify_2fa') {
            $code = trim($_POST['two_factor_code'] ?? '');
            if (login_verify_2fa($code)) {
                header('Location: ./');
                exit;
            } else {
                $error = "Código de autenticación o código de recuperación incorrecto.";
                $step = '2fa';
                sleep(1);
            }
        } elseif ($action === 'cancel_2fa') {
            unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_pending_time']);
            $step = 'password';
        }
    }
}

$csrf = generate_csrf();
$color = theme_color();
$c = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6366f1';
$rgb = hexdec(substr($c,1,2)).','.hexdec(substr($c,3,2)).','.hexdec(substr($c,5,2));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $step === '2fa' ? 'Verificación 2FA' : 'Iniciar sesión' ?> — <?= htmlspecialchars(site_title()) ?></title>
<?= cms_favicon_tags() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --accent: <?= $color ?>;
    --accent-rgb: <?= $rgb ?>;
    --bg: #090a10;
    --surface: #131622;
    --surface2: #1b1f30;
    --border: #23283c;
    --border2: #2e344e;
    --text: #f1f3f9;
    --text2: #9ea8c2;
    --muted: #64708d;
    --radius: 16px;
  }
  body {
    background: var(--bg); color: var(--text);
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 15.5px; line-height: 1.6;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 2rem 1.5rem;
    -webkit-font-smoothing: antialiased;
  }
  .wrap { width: 100%; max-width: 420px; }
  .logo { text-align: center; margin-bottom: 2.25rem; }
  .logo h1 { font-family: 'Outfit', sans-serif; font-size: 2.3rem; font-weight: 800; letter-spacing: -0.04em; }
  .logo h1 span { color: var(--accent); }
  .logo p { color: var(--muted); font-size: 1rem; margin-top: 0.35rem; font-weight: 500; }
  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 2.25rem;
    box-shadow: 0 12px 36px rgba(0,0,0,0.35);
  }
  .field { margin-bottom: 1.35rem; }
  label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--text); margin-bottom: 0.55rem; }
  input {
    width: 100%; background: var(--bg); border: 1px solid var(--border2);
    border-radius: 10px; padding: 0.85rem 1.1rem; color: var(--text);
    font-family: inherit; font-size: 1rem; transition: border-color 0.2s, box-shadow 0.2s;
  }
  input:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.25);
  }
  .btn {
    width: 100%; background: var(--accent); color: #fff; border: none;
    border-radius: 10px; padding: 0.9rem 1.25rem; font-family: inherit;
    font-size: 1rem; font-weight: 700; cursor: pointer; margin-top: 0.5rem;
    box-shadow: 0 4px 14px rgba(var(--accent-rgb), 0.35);
    transition: opacity 0.2s, transform 0.15s, box-shadow 0.2s;
  }
  .btn:hover {
    opacity: 0.95; transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(var(--accent-rgb), 0.45);
  }
  .btn-outline {
    background: transparent; color: var(--muted); border: 1px solid var(--border2);
    box-shadow: none; font-size: 0.9rem; font-weight: 600;
  }
  .btn-outline:hover {
    background: var(--surface2); color: var(--text); box-shadow: none;
  }
  .error {
    background: rgba(244,63,94,0.12); border: 1px solid rgba(244,63,94,0.3);
    border-radius: 10px; padding: 0.85rem 1.1rem; color: #f87171;
    font-size: 0.92rem; font-weight: 500; margin-bottom: 1.5rem;
    display: flex; align-items: center; gap: 0.65rem;
  }
  .back { text-align: center; margin-top: 1.5rem; }
  .back a {
    color: var(--muted); font-size: 0.92rem; font-weight: 500;
    text-decoration: none; transition: color 0.15s;
  }
  .back a:hover { color: var(--text); }
  .code-input {
    letter-spacing: 0.25em; text-align: center; font-size: 1.4rem; font-weight: 700;
    font-family: monospace;
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <h1>Kut<span>Blog</span></h1>
    <p><?= $step === '2fa' ? 'Verificación de dos pasos' : __("login_title") ?></p>
  </div>
  <div class="card">
    <?php if ($error): ?><div class="error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($step === '2fa'): ?>
      <div style="text-align:center;margin-bottom:1.5rem">
        <div style="font-size:2rem;margin-bottom:0.5rem">🛡️</div>
        <p style="font-size:0.92rem;color:var(--text2)">
          Introduce el código de 6 dígitos de tu aplicación de autenticación o un código de recuperación.
        </p>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="verify_2fa">
        <div class="field">
          <label style="text-align:center">Código de seguridad (TOTP)</label>
          <input type="text" name="two_factor_code" class="code-input" placeholder="000000" maxlength="12" required autofocus autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn">Verificar y Acceder</button>
      </form>
      <form method="POST" style="margin-top:0.75rem">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="cancel_2fa">
        <button type="submit" class="btn btn-outline">← Cancelar y volver</button>
      </form>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="login">
        <div class="field">
          <label><?= __("login_username") ?></label>
          <input type="text" name="username" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label><?= __("login_password") ?></label>
          <input type="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn"><?= __("login_btn") ?></button>
      </form>
    <?php endif; ?>
  </div>
  <div class="back"><a href="<?= base_url() ?>/"><?= __("login_back") ?></a></div>
</div>
</body>
</html>
