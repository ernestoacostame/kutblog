<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';

if (is_installed()) {
    header('Location: ' . base_url() . '/admin/');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_title = trim($_POST['site_title'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $base_url = rtrim(trim($_POST['base_url'] ?? ''), '/');

    if (!$site_title || !$username || !$password) {
        $error = 'All fields are required.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one uppercase letter and one number.';
    } else {
        // Create directories
        foreach (['content/articles', 'content/pages', 'uploads', 'cache'] as $dir) {
            $full = ROOT_PATH . '/' . $dir;
            if (!is_dir($full)) mkdir($full, 0755, true);
        }

        // Add .htaccess to content and cache
        file_put_contents(ROOT_PATH . '/content/.htaccess', "Order allow,deny\nDeny from all");
        file_put_contents(ROOT_PATH . '/cache/.htaccess', "Order allow,deny\nDeny from all");

        $enable_fediverse = isset($_POST['enable_fediverse']) && $_POST['enable_fediverse'] === '1';

        // Save config
        cms_save_config([
            'site_title' => $site_title,
            'base_url' => $base_url,
            'admin_user' => $username,
            'admin_pass' => hash_password($password),
            'theme' => 'default',
            'theme_color' => '#6366f1',
            'installed_at' => date('c'),
            'version' => CMS_VERSION,
            'active_plugins' => $enable_fediverse ? ['fediverse'] : [],
            'fediverse_username' => 'blog'
        ]);

        // Create sample content
        require_once dirname(__DIR__) . '/core/content.php';

        save_content('articles', [
            'title' => 'Welcome to ' . $site_title,
            'content' => '<p>This is your first article. Start writing and share your thoughts with the world.</p><p>You can edit or delete this article from the admin panel.</p>',
            'excerpt' => 'Welcome to your new KutBlog site.',
            'status' => 'published',
            'categories' => ['General'],
            'tags' => ['welcome'],
        ]);

        save_content('pages', [
            'title' => 'About',
            'slug' => 'about',
            'content' => '<p>This is the About page. Tell your visitors who you are.</p>',
            'status' => 'published',
        ]);

        save_content('pages', [
            'title' => 'Contact',
            'slug' => 'contact',
            'content' => '<p>You can reach me at: <a href="mailto:hello@example.com">hello@example.com</a></p>',
            'status' => 'published',
        ]);

        // Mark as installed
        file_put_contents(INSTALLED_FLAG, date('c'));
        $success = true;
    }
}

// Auto-detect base URL
$detected_base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Install KutBlog</title>
<link rel="icon" type="image/svg+xml" href="../media/favicon/default.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --accent: #6366f1;
    --bg: #0f0f13;
    --surface: #18181f;
    --border: #2a2a35;
    --text: #e8e8f0;
    --muted: #6b6b80;
    --radius: 12px;
  }
  body { background: var(--bg); color: var(--text); font-family: 'Outfit', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
  .installer { width: 100%; max-width: 520px; }
  .logo { text-align: center; margin-bottom: 2.5rem; }
  .logo h1 { font-size: 2.5rem; font-weight: 700; letter-spacing: -0.04em; }
  .logo h1 span { color: var(--accent); }
  .logo p { color: var(--muted); margin-top: 0.5rem; font-size: 0.95rem; }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 2rem; }
  .step-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--accent); margin-bottom: 1.5rem; }
  .field { margin-bottom: 1.25rem; }
  label { display: block; font-size: 0.85rem; font-weight: 500; color: var(--muted); margin-bottom: 0.5rem; }
  input[type="text"], input[type="password"], input[type="url"] {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: 8px;
    padding: 0.75rem 1rem; color: var(--text); font-family: inherit; font-size: 0.95rem;
    transition: border-color 0.2s;
  }
  input:focus { outline: none; border-color: var(--accent); }
  .hint { font-size: 0.78rem; color: var(--muted); margin-top: 0.4rem; }
  .divider { border: none; border-top: 1px solid var(--border); margin: 1.5rem 0; }
  .btn { width: 100%; background: var(--accent); color: #fff; border: none; border-radius: 8px; padding: 0.9rem; font-family: inherit; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; margin-top: 0.5rem; }
  .btn:hover { opacity: 0.85; }
  .error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); border-radius: 8px; padding: 0.75rem 1rem; color: #f87171; font-size: 0.9rem; margin-bottom: 1.25rem; }
  .success-wrap { text-align: center; }
  .success-icon { font-size: 3rem; margin-bottom: 1rem; }
  .success-wrap h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
  .success-wrap p { color: var(--muted); margin-bottom: 1.5rem; }
  .btn-goto { display: inline-block; background: var(--accent); color: #fff; border-radius: 8px; padding: 0.75rem 2rem; text-decoration: none; font-weight: 600; transition: opacity 0.2s; }
  .btn-goto:hover { opacity: 0.85; }
  .password-rules { font-size: 0.78rem; color: var(--muted); margin-top: 0.4rem; }
</style>
</head>
<body>
<div class="installer">
  <div class="logo">
    <h1>Kut<span>Blog</span></h1>
    <p>Simple, fast, file-based publishing</p>
  </div>
  <div class="card">
    <?php if ($success): ?>
      <div class="success-wrap">
        <div class="success-icon">🚀</div>
        <h2>Installation Complete!</h2>
        <p>Your site is ready. Head to the admin panel to start publishing.</p>
        <a href="<?= htmlspecialchars($detected_base) ?>/admin/" class="btn-goto">Go to Admin Panel →</a>
      </div>
    <?php else: ?>
      <div class="step-label">Setup · Step 1 of 1</div>
      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST">
        <div class="field">
          <label>Site Title</label>
          <input type="text" name="site_title" value="<?= htmlspecialchars($_POST['site_title'] ?? 'My Blog') ?>" required placeholder="My Awesome Blog">
        </div>
        <div class="field">
          <label>Base URL</label>
          <input type="url" name="base_url" value="<?= htmlspecialchars($_POST['base_url'] ?? $detected_base) ?>" required>
          <div class="hint">Auto-detected. Change only if incorrect.</div>
        </div>
        <hr class="divider">
        <div class="field">
          <label>Admin Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required placeholder="admin" autocomplete="off">
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required autocomplete="new-password">
          <div class="password-rules">Min. 8 chars, one uppercase letter, one number.</div>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="password2" required autocomplete="new-password">
        </div>
        <div class="field" style="display:flex; align-items:center; gap:0.5rem; margin-top:1.25rem; margin-bottom:1.25rem">
          <input type="checkbox" name="enable_fediverse" id="enable_fediverse" value="1" checked style="width:16px; height:16px; accent-color:var(--accent); cursor:pointer">
          <label for="enable_fediverse" style="margin:0; cursor:pointer; user-select:none; color:var(--text); font-size:0.9rem">Habilitar el Fediverso (ActivityPub)</label>
        </div>
        <button type="submit" class="btn">Install KutBlog →</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
