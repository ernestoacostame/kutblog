<?php
// KutBlog - Public Subscription, Confirmation & Unsubscribe Endpoint

require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/newsletter.php';

if (!cms_plugin_is_active('newsletter')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'El plugin de Newsletter no está activo.']);
        exit;
    }
    render_notice_page('Servicio no disponible', '✉️', 'El boletín de noticias no se encuentra disponible actualmente.');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Helper to render a standalone notification page
function render_notice_page(string $title, string $icon, string $message, string $submessage = ''): void {
    $base = base_url();
    $cfg = cms_config();
    $siteTitle = htmlspecialchars($cfg['site_title'] ?? 'KutBlog');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> · <?= $siteTitle ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Literata:ital,wght@0,400;0,600;1,400&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #09090b;
      --card: #18181b;
      --border: #27272a;
      --text: #f4f4f5;
      --text2: #a1a1aa;
      --muted: #71717a;
      --accent: #6366f1;
      --font-ui: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }
    body {
      margin: 0;
      padding: 0;
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-ui);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      box-sizing: border-box;
      padding: 1.5rem;
    }
    .notice-card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 16px;
      max-width: 480px;
      width: 100%;
      padding: 2.5rem 2rem;
      text-align: center;
      box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    }
    .notice-icon {
      font-size: 3rem;
      margin-bottom: 1rem;
      display: inline-block;
    }
    .notice-title {
      font-size: 1.5rem;
      font-weight: 700;
      margin: 0 0 0.75rem 0;
      letter-spacing: -0.02em;
    }
    .notice-message {
      font-size: 1rem;
      line-height: 1.6;
      color: var(--text2);
      margin: 0 0 1.5rem 0;
    }
    .notice-sub {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 2rem;
    }
    .btn-return {
      display: inline-block;
      background: var(--accent);
      color: #fff;
      font-weight: 600;
      font-size: 0.95rem;
      text-decoration: none;
      padding: 0.75rem 1.75rem;
      border-radius: 8px;
      transition: opacity 0.15s;
    }
    .btn-return:hover {
      opacity: 0.9;
    }
  </style>
</head>
<body>
  <div class="notice-card">
    <div class="notice-icon"><?= $icon ?></div>
    <h1 class="notice-title"><?= htmlspecialchars($title) ?></h1>
    <p class="notice-message"><?= htmlspecialchars($message) ?></p>
    <?php if ($submessage): ?>
      <p class="notice-sub"><?= htmlspecialchars($submessage) ?></p>
    <?php endif; ?>
    <div>
      <a href="<?= htmlspecialchars($base) ?>" class="btn-return">Volver al blog</a>
    </div>
  </div>
</body>
</html>
    <?php
    exit;
}

// ── GET: Confirm or Unsubscribe ──────────────────────────────────────────────
if ($method === 'GET') {
    if (!empty($_GET['confirm'])) {
        $sub = newsletter_confirm_token($_GET['confirm']);
        if ($sub) {
            render_notice_page(
                '¡Suscripción Confirmada!',
                '🎉',
                'Gracias por unirte a nuestro boletín. A partir de ahora recibirás nuestras nuevas publicaciones directamente en tu correo.',
                'Dirección confirmada: ' . $sub['email']
            );
        } else {
            render_notice_page(
                'Enlace No Válido o Expirado',
                '⚠️',
                'El enlace de confirmación no es válido o ya ha sido utilizado.',
                'Si deseas suscribirte, puedes ingresar tu correo nuevamente en el blog.'
            );
        }
    }

    if (!empty($_GET['unsubscribe'])) {
        $ok = newsletter_unsubscribe_token($_GET['unsubscribe']);
        if ($ok) {
            render_notice_page(
                'Suscripción Cancelada',
                '👋',
                'Te has dado de baja de nuestro boletín. Lamentamos verte partir.',
                'No volverás a recibir correos automáticos. Puedes volver a suscribirte en cualquier momento.'
            );
        } else {
            render_notice_page(
                'Enlace No Encontrado',
                'ℹ️',
                'No pudimos encontrar esa suscripción o ya fue cancelada con anterioridad.'
            );
        }
    }

    header('Location: ' . base_url());
    exit;
}

// ── POST: New Subscription ───────────────────────────────────────────────────
if ($method === 'POST') {
    // Check if JSON request or form POST
    $input = file_get_contents('php://input');
    $jsonData = json_decode($input, true);
    
    // Honeypot check (quireINK silent drop)
    $honeypot = trim($_POST['website'] ?? ($jsonData['website'] ?? ''));
    if ($honeypot !== '') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'message' => '¡Te hemos enviado un correo de confirmación!']);
        exit;
    }

    $email = trim($_POST['email'] ?? ($jsonData['email'] ?? ''));
    $res = newsletter_add_subscriber($email);

    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

