<?php
// KutBlog - Admin Layout
function admin_header(string $page_title = '', string $active = ''): void {
    $color = theme_color();
    $title = site_title();
    $user  = $_SESSION['username'] ?? 'Admin';
    $profile = function_exists('current_user') ? current_user() : null;
    $displayName = !empty($profile['name']) ? $profile['name'] : $user;
    $avatarUrl = !empty($profile['avatar']) && function_exists('user_avatar_url') ? user_avatar_url($profile['avatar']) : '';
    $c     = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6366f1';
    $rgb   = hexdec(substr($c,1,2)).','.hexdec(substr($c,3,2)).','.hexdec(substr($c,5,2));

    // Admin theme colors (stored in config)
    $config = cms_config();
    $admin_scheme = $config['admin_scheme'] ?? 'dark';

    // quireINK inspired zinc/neutral & refined paper palettes
    $schemes = [
        'dark'     => ['--bg:#09090b','--sidebar:#121215','--surface:#18181b','--surface2:#27272a','--border:#27272a','--border2:#3f3f46','--text:#f4f4f5','--text2:#a1a1aa','--muted:#71717a'],
        'midnight' => ['--bg:#030712','--sidebar:#0b0f19','--surface:#111827','--surface2:#1f2937','--border:#1f2937','--border2:#374151','--text:#f9fafb','--text2:#9ca3af','--muted:#6b7280'],
        'slate'    => ['--bg:#0f172a','--sidebar:#1e293b','--surface:#1e293b','--surface2:#334155','--border:#334155','--border2:#475569','--text:#f8fafc','--text2:#94a3b8','--muted:#64748b'],
        'warm'     => ['--bg:#141210','--sidebar:#1a1714','--surface:#231f1c','--surface2:#2e2925','--border:#38322c','--border2:#4a423a','--text:#f5f2ed','--text2:#b8aea4','--muted:#857b70'],
        'light'    => ['--bg:#fafaf9','--sidebar:#ffffff','--surface:#ffffff','--surface2:#f4f4f5','--border:#e4e4e7','--border2:#d4d4d8','--text:#09090b','--text2:#52525b','--muted:#71717a'],
    ];
    $scheme_vars = implode(';', $schemes[$admin_scheme] ?? $schemes['dark']);

    require_once dirname(__DIR__) . '/core/updater.php';
    $cms_update_cache = cms_update_cached();
    $cms_update_available = false;
    $cms_available_ver = '';
    if ($cms_update_cache && !empty($cms_update_cache['available']) && !empty($cms_update_cache['new_version'])) {
        if (version_compare($cms_update_cache['new_version'], CMS_VERSION, '>')) {
            $cms_update_available = true;
            $cms_available_ver = $cms_update_cache['new_version'];
        }
    }
    $cms_check_stale = cms_update_cache_stale();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detect_admin_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ? htmlspecialchars($page_title) . ' — ' : '' ?>Admin · <?= htmlspecialchars($title) ?></title>
<?= cms_favicon_tags() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,500;0,7..72,600;0,7..72,700;1,7..72,400&family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --accent: <?= $color ?>;
  --accent-rgb: <?= $rgb ?>;
  <?= $scheme_vars ?>;
  --green: #10b981; --red: #e11d48; --yellow: #f59e0b;
  --pen-red: #c23b2b; --ink-danger: #d97066;
  --radius-sm: 6px;
  --radius: 10px;
  --radius-lg: 12px;
  --sidebar-w: 250px;
  --sidebar-w-collapsed: 68px;
  --topbar-h: 58px;
  --font-sans: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  --font-reading: 'Literata', Georgia, 'Outfit', serif;
  --font-mono: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
}
html, body { min-height: 100%; height: 100%; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-sans);
  font-size: 14.5px;
  line-height: 1.55;
  display: flex;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* ── SIDEBAR ── */
.sidebar {
  width: var(--sidebar-w); min-height: 100vh; background: var(--sidebar);
  border-right: 1px solid var(--border); display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 200;
  transition: width 0.22s cubic-bezier(.4,0,.2,1), transform 0.22s;
  overflow: hidden;
}
body.sidebar-collapsed .sidebar { width: var(--sidebar-w-collapsed); }
body.sidebar-hidden .sidebar { transform: translateX(-100%); }

/* Logo */
.sidebar-logo {
  padding: 0 1.1rem; border-bottom: 1px solid var(--border);
  font-family: 'Outfit', sans-serif;
  font-size: 1.35rem; font-weight: 800; letter-spacing: -0.03em; color: var(--text);
  display: flex; align-items: center; justify-content: space-between;
  height: var(--topbar-h); flex-shrink: 0; white-space: nowrap; overflow: hidden;
}
.sidebar-logo span { color: var(--accent); }
.sidebar-logo small {
  display: block; font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.76rem; font-weight: 500; color: var(--muted); letter-spacing: 0;
  margin-top: -2px;
}
.logo-text { overflow: hidden; transition: opacity 0.15s, width 0.22s; }
body.sidebar-collapsed .logo-text { opacity: 0; width: 0; }

/* Toggle button */
.sidebar-toggle {
  background: none; border: none; color: var(--muted); cursor: pointer;
  padding: 0.45rem; border-radius: var(--radius-sm); flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: color 0.15s, background 0.15s;
}
.sidebar-toggle:hover { color: var(--text); background: var(--surface2); }

/* New article button in sidebar */
.sidebar-new-btn {
  margin: 1rem 0.85rem 0.5rem; flex-shrink: 0;
  display: flex; align-items: center; gap: 0.65rem;
  background: var(--accent); color: #fff; border-radius: var(--radius-sm);
  padding: 0.75rem 1rem; text-decoration: none; font-size: 0.95rem; font-weight: 600;
  box-shadow: 0 4px 14px rgba(var(--accent-rgb), 0.35);
  transition: opacity 0.15s, transform 0.15s, box-shadow 0.15s, padding 0.22s;
  white-space: nowrap; overflow: hidden;
}
.sidebar-new-btn:hover { opacity: 0.95; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(var(--accent-rgb), 0.45); }
.sidebar-new-btn .icon { flex-shrink: 0; }
.btn-new-label { overflow: hidden; transition: opacity 0.15s, width 0.22s; }
body.sidebar-collapsed .btn-new-label { opacity: 0; width: 0; }
body.sidebar-collapsed .sidebar-new-btn { padding: 0.75rem; justify-content: center; margin: 0.85rem 0.5rem; }

/* Nav */
.sidebar-nav { flex: 1; padding: 0.35rem 0.65rem; overflow-y: auto; overflow-x: hidden; }
.nav-section {
  font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--muted); padding: 1.15rem 0.85rem 0.45rem;
  white-space: nowrap; overflow: hidden; transition: opacity 0.15s;
}
body.sidebar-collapsed .nav-section { opacity: 0; height: 0; padding: 0; margin: 0; }

.nav-item {
  display: flex; align-items: center; gap: 0.75rem; padding: 0.65rem 0.85rem;
  border-radius: 9px; color: var(--text2); text-decoration: none; font-size: 0.95rem;
  font-weight: 500; transition: all 0.18s ease; margin-bottom: 2px;
  white-space: nowrap; overflow: hidden; position: relative;
}
.nav-item:hover { background: var(--surface2); color: var(--text); transform: translateX(2px); }
.nav-item.active {
  background: rgba(var(--accent-rgb), 0.14); color: var(--accent); font-weight: 600;
  box-shadow: inset 3px 0 0 var(--accent);
}
.nav-item .icon { width: 19px; height: 19px; opacity: 0.85; flex-shrink: 0; }
.nav-label { overflow: hidden; transition: opacity 0.15s, width 0.22s; }
body.sidebar-collapsed .nav-label { opacity: 0; width: 0; }

/* Tooltip on collapsed */
body.sidebar-collapsed .nav-item:hover::after {
  content: attr(data-label);
  position: absolute; left: calc(var(--sidebar-w-collapsed) + 8px); top: 50%;
  transform: translateY(-50%);
  background: var(--surface2); color: var(--text); border: 1px solid var(--border2);
  padding: 0.4rem 0.8rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 500;
  box-shadow: 0 4px 16px rgba(0,0,0,0.25); white-space: nowrap;
  pointer-events: none; z-index: 999;
}

/* Footer */
.sidebar-footer {
  padding: 0.85rem 0.75rem; border-top: 1px solid var(--border); flex-shrink: 0;
  overflow: hidden;
}
.user-row {
  display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.65rem;
  border-radius: var(--radius-sm); overflow: hidden;
}
.user-avatar {
  width: 34px; height: 34px; background: var(--accent); border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.85rem; font-weight: 700; color: #fff; flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(var(--accent-rgb), 0.35);
}
.user-name { font-size: 0.92rem; font-weight: 600; color: var(--text); overflow: hidden; transition: opacity 0.15s, width 0.22s; white-space: nowrap; }
body.sidebar-collapsed .user-name { opacity: 0; width: 0; }
.logout-btn {
  display: flex; align-items: center; gap: 0.6rem;
  padding: 0.55rem 0.85rem; border-radius: var(--radius-sm); background: rgba(244,63,94,0.1);
  color: var(--red); text-decoration: none; font-size: 0.88rem; font-weight: 600;
  transition: background 0.15s; white-space: nowrap; overflow: hidden; margin-top: 0.35rem;
}
.logout-btn:hover { background: rgba(244,63,94,0.18); }
.logout-label { overflow: hidden; transition: opacity 0.15s, width 0.22s; }
body.sidebar-collapsed .logout-label { opacity: 0; width: 0; }

/* ── TOPBAR ── */
.main { margin-left: var(--sidebar-w); flex: 1; min-height: 100vh; display: flex; flex-direction: column; transition: margin-left 0.22s; }
body.sidebar-collapsed .main { margin-left: var(--sidebar-w-collapsed); }
body.sidebar-hidden .main { margin-left: 0; }

.topbar {
  border-bottom: 1px solid var(--border); padding: 0 2rem;
  display: flex; align-items: center; justify-content: space-between;
  background: var(--sidebar); position: sticky; top: 0; z-index: 50;
  height: var(--topbar-h); gap: 1.25rem;
  backdrop-filter: blur(12px);
}
.topbar-left { display: flex; align-items: center; gap: 1rem; min-width: 0; }
.topbar-title {
  font-family: var(--font-reading);
  font-size: 1.25rem; font-weight: 600; letter-spacing: -0.015em; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.topbar-actions { display: flex; align-items: center; gap: 0.65rem; flex-shrink: 0; }

/* Mobile toggle */
.mobile-menu-btn {
  display: none; background: none; border: none; color: var(--text2);
  cursor: pointer; padding: 0.45rem; border-radius: var(--radius-sm);
}
.mobile-menu-btn:hover { color: var(--text); background: var(--surface2); }

/* Overlay for mobile */
.sidebar-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65);
  z-index: 150; backdrop-filter: blur(3px);
}
body.sidebar-open .sidebar-overlay { display: block; }
body.sidebar-open .sidebar { transform: translateX(0) !important; }

/* Scheme switcher in topbar */
.scheme-dots { display: flex; gap: 6px; align-items: center; }
.scheme-dot {
  width: 16px; height: 16px; border-radius: 50%; cursor: pointer;
  border: 2px solid transparent; transition: border-color 0.15s, transform 0.15s;
  text-decoration: none; display: block;
}
.scheme-dot:hover { transform: scale(1.18); }
.scheme-dot.active { border-color: var(--text); }

/* ── MAIN CONTENT ── */
.page-body { padding: 1.75rem 2rem; flex: 1; }

/* ── COMPONENTS (quireINK relief grammar) ── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
  padding: 0.5rem 0.95rem; border-radius: var(--radius-sm);
  font-family: inherit; font-size: 0.88rem; font-weight: 500; cursor: pointer;
  border: 1px solid transparent; text-decoration: none;
  transition: all 0.15s cubic-bezier(0.2,0.7,0.3,1); line-height: 1.4;
  white-space: nowrap;
}
.btn:active { transform: translateY(1px); }
.btn-primary {
  background: var(--accent); color: #fff;
  border-color: rgba(0,0,0,0.12);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.2), 0 1px 2px rgba(0,0,0,0.22);
}
.btn-primary:hover {
  opacity: 0.92;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.25), 0 2px 4px rgba(0,0,0,0.26);
}
.btn-secondary {
  background: var(--surface); color: var(--text); border: 1px solid var(--border2);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.06), 0 1px 1.5px rgba(0,0,0,0.06);
}
.btn-secondary:hover { color: var(--text); background: var(--surface2); border-color: var(--border2); }
.btn-danger {
  background: transparent; color: var(--ink-danger); border: 1px solid var(--ink-danger);
}
.btn-danger:hover { background: var(--pen-red); color: #fff; border-color: var(--pen-red); }
.btn-sm { padding: 0.35rem 0.72rem; font-size: 0.8rem; border-radius: 5px; }

.card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  overflow: hidden; margin-bottom: 1.5rem;
}
.card-header {
  padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 0.75rem;
}
.card-title {
  font-family: var(--font-reading); font-size: 1.15rem; font-weight: 600;
  color: var(--text); letter-spacing: -0.01em;
}
.card-body { padding: 1.4rem; }

.badge {
  display: inline-flex; align-items: center; gap: 0.35rem;
  padding: 0.25rem 0.65rem; border-radius: 9999px;
  font-size: 0.78rem; font-weight: 600; letter-spacing: 0.01em;
}
.badge-green { background: rgba(16,185,129,0.12); color: var(--green); border: 1px solid rgba(16,185,129,0.25); }
.badge-yellow { background: rgba(245,158,11,0.12); color: var(--yellow); border: 1px solid rgba(245,158,11,0.25); }
.badge-blue { background: rgba(59,130,246,0.12); color: #3b82f6; border: 1px solid rgba(59,130,246,0.25); }
.badge-purple { background: rgba(139,92,246,0.12); color: #8b5cf6; border: 1px solid rgba(139,92,246,0.25); }
.badge-red { background: rgba(244,63,94,0.12); color: var(--red); border: 1px solid rgba(244,63,94,0.25); }
.badge-gray { background: var(--surface2); color: var(--text2); border: 1px solid var(--border2); }

.table { width: 100%; border-collapse: collapse; }
.table th {
  text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.06em; color: var(--muted); padding: 0.85rem 1.25rem;
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
.table td {
  padding: 0.95rem 1.25rem; border-bottom: 1px solid var(--border);
  font-size: 0.9rem; vertical-align: middle; color: var(--text);
}
.table tr:last-child td { border-bottom: none; }
.table tr:hover td { background: var(--surface2); }
.table a { color: var(--text); text-decoration: none; font-weight: 500; }
.table a:hover { color: var(--accent); }

/* Responsive table */
@media (max-width: 640px) {
  .table thead { display: none; }
  .table, .table tbody, .table tr, .table td { display: block; width: 100%; }
  .table tr { border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 1rem; padding: 0.75rem; }
  .table td { border: none; padding: 0.45rem 0.65rem; display: flex; align-items: center; gap: 0.75rem; }
  .table td::before { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--muted); min-width: 90px; flex-shrink: 0; }
}

.stats-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1.25rem; margin-bottom: 1.75rem;
}
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.25rem 1.4rem;
  transition: all 0.2s ease; position: relative; overflow: hidden;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.stat-label {
  font-size: 0.78rem; font-weight: 600; color: var(--muted);
  text-transform: uppercase; letter-spacing: 0.05em;
}
.stat-value {
  font-family: var(--font-reading);
  font-size: 2.1rem; font-weight: 700; margin-top: 0.35rem; letter-spacing: -0.03em;
}
.stat-value.accent { color: var(--accent); }

.alert {
  padding: 0.85rem 1.15rem; border-radius: 8px; font-size: 0.9rem; font-weight: 500;
  margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.65rem;
}
.alert-success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.25); color: var(--green); }
.alert-error { background: rgba(244,63,94,0.12); border: 1px solid rgba(244,63,94,0.25); color: var(--red); }

input[type="text"], input[type="password"], input[type="url"], input[type="email"], input[type="number"], select, textarea {
  background: var(--surface); border: 1px solid var(--border2); border-radius: var(--radius-sm);
  padding: 0.55rem 0.8rem; color: var(--text); font-family: inherit; font-size: 0.9rem;
  box-shadow: inset 0 1px 1.5px rgba(0,0,0,0.1);
  transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
}
input:focus, select:focus, textarea:focus {
  outline: none; border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.18), inset 0 1px 1.5px rgba(0,0,0,0.1);
}
.form-label {
  display: block; font-size: 0.9rem; font-weight: 600; color: var(--text);
  margin-bottom: 0.5rem; letter-spacing: 0.01em;
}
.form-group { margin-bottom: 1.35rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
@media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }

.pagination { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-top: 1.5rem; }
.page-btn {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 36px; height: 36px; padding: 0 8px; border-radius: var(--radius-sm);
  font-size: 0.9rem; font-weight: 600; text-decoration: none; color: var(--text2);
  background: var(--surface); border: 1px solid var(--border); transition: all 0.18s ease;
}
.page-btn:hover, .page-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ── LIGHT SCHEME overrides ── */
body.scheme-light {
  --green: #059669; --red: #dc2626; --yellow: #d97706;
}
body.scheme-light .sidebar { border-right-color: var(--border); box-shadow: 2px 0 12px rgba(0,0,0,0.04); }
body.scheme-light .topbar  { box-shadow: 0 1px 6px rgba(0,0,0,0.04); }
body.scheme-light input[type="text"],
body.scheme-light input[type="password"],
body.scheme-light input[type="url"],
body.scheme-light input[type="email"],
body.scheme-light input[type="number"],
body.scheme-light select,
body.scheme-light textarea {
  background: #fff; color: #111827; border-color: var(--border2);
}
body.scheme-light .card  { box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
body.scheme-light .table tr:hover td { background: #f8f9fc; }
body.scheme-light .alert-success { background: #ecfdf5; border-color: #6ee7b7; color: #065f46; }
body.scheme-light .alert-error   { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
body.scheme-light .badge-green { background: #d1fae5; color: #065f46; }
body.scheme-light .badge-yellow { background: #fef3c7; color: #92400e; }
body.scheme-light .badge-blue   { background: #dbeafe; color: #1e40af; }
body.scheme-light .badge-purple { background: #ede9fe; color: #5b21b6; }
body.scheme-light .badge-gray  { background: #f3f4f6; color: #4b5563; }
body.scheme-light .floating-buttons-container {
  background: #ffffff !important;
  border-color: #d0d0ca !important;
  box-shadow: 0 6px 24px rgba(0,0,0,0.1) !important;
}
body.scheme-light .floating-draft-btn,
body.scheme-light .floating-focus-btn,
body.scheme-light .floating-preview-btn {
  background: #f0f0ec !important;
  color: #1a1a1a !important;
  border-color: #d0d0ca !important;
}
body.scheme-light .floating-draft-btn:hover,
body.scheme-light .floating-focus-btn:hover,
body.scheme-light .floating-preview-btn:hover {
  background: #e0e0da !important;
}
body.scheme-light .floating-publish-btn {
  background: var(--accent) !important;
  color: #ffffff !important;
}

/* ── RESPONSIVE ── */
@media (max-width: 1024px) {
  .sidebar { transform: translateX(-100%); width: var(--sidebar-w) !important; }
  body.sidebar-open .sidebar { transform: translateX(0); }
  .main { margin-left: 0 !important; }
  .mobile-menu-btn { display: flex; }
  .page-body { padding: 1.25rem; }
  .topbar { padding: 0 1.25rem; }

  /* Table: card layout on mobile — NO phantom first column */
  .table { display: block; }
  .table thead { display: none; }
  .table tbody { display: block; }
  .table tr { display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.85rem; border-bottom: 1px solid var(--border); align-items: center; }
  .table td { display: inline-flex; align-items: center; border: none; padding: 0; font-size: 0.88rem; }
  .table td:first-child { width: 100%; font-weight: 600; font-size: 1rem; }
  .table td:last-child { margin-left: auto; }

  /* Stats grid: 2 columns on mobile */
  .stats-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }

  /* Card header: wrap on mobile */
  .card-header { flex-wrap: wrap; }

  /* Form rows: single column */
  .form-row { grid-template-columns: 1fr !important; }

  /* Settings sections: full width */
  .settings-grid { grid-template-columns: 1fr !important; }
}
@media (min-width: 1025px) {
  .sidebar-overlay { display: none !important; }
}

/* Update notification banner */
.update-notification-banner {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px;
  margin: 1.5rem 2rem 0.5rem 2rem;
  background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.14) 0%, rgba(16, 185, 129, 0.1) 100%);
  border: 1px solid rgba(var(--accent-rgb), 0.28);
  border-radius: var(--radius);
  color: var(--text);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
  animation: fadeInBanner 0.25s ease-out;
}
@keyframes fadeInBanner {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: translateY(0); }
}
.update-notification-banner .update-banner-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: var(--accent);
  color: #ffffff;
  flex-shrink: 0;
  box-shadow: 0 4px 12px rgba(var(--accent-rgb), 0.35);
}
.update-notification-banner .update-banner-body {
  flex: 1;
  min-width: 0;
}
.update-notification-banner .update-banner-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 2px;
}
.update-notification-banner .update-banner-desc {
  font-size: 0.85rem;
  color: var(--text2);
}
.update-notification-banner .update-banner-desc strong {
  color: var(--accent);
}
.update-notification-banner .update-banner-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.update-notification-banner .btn-update-banner {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--accent);
  color: #ffffff !important;
  text-decoration: none !important;
  padding: 0.45rem 1rem;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
  font-weight: 600;
  transition: all 0.15s ease;
  box-shadow: 0 2px 8px rgba(var(--accent-rgb), 0.3);
}
.update-notification-banner .btn-update-banner:hover {
  filter: brightness(1.1);
  transform: translateY(-1px);
}
.update-notification-banner .btn-dismiss-banner {
  background: transparent;
  border: none;
  color: var(--muted);
  font-size: 1.1rem;
  padding: 4px 8px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s;
  line-height: 1;
}
.update-notification-banner .btn-dismiss-banner:hover {
  background: rgba(255, 255, 255, 0.08);
  color: var(--text);
}
</style>
</head>
<body class="scheme-<?= $admin_scheme ?>" id="admin-body">
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-text">
      <div>Kut<span>Blog</span></div>
      <small><?= htmlspecialchars(mb_strimwidth($title, 0, 22, '…')) ?></small>
    </div>
    <button class="sidebar-toggle" id="sidebar-toggle" title="<?= __("nav_collapse") ?>" aria-label="<?= __("nav_collapse") ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
  </div>

  <!-- New article quick button -->
  <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="sidebar-new-btn" data-label="Nuevo artículo">
    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    <span class="btn-new-label"><?= __("nav_new_article") ?></span>
  </a>

  <nav class="sidebar-nav">
    <div class="nav-section">Contenido</div>
    <a href="<?= base_url() ?>/admin/" class="nav-item <?= $active === 'dashboard' ? 'active' : '' ?>" data-label="Panel">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span class="nav-label"><?= __("nav_dashboard") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/articles.php" class="nav-item <?= $active === 'articles' ? 'active' : '' ?>" data-label="Artículos">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <span class="nav-label"><?= __("nav_articles") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/calendar.php" class="nav-item <?= $active === 'calendar' ? 'active' : '' ?>" data-label="Calendario">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span class="nav-label">Calendario</span>
    </a>
    <a href="<?= base_url() ?>/admin/pages.php" class="nav-item <?= $active === 'pages' ? 'active' : '' ?>" data-label="Páginas">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
      <span class="nav-label"><?= __("nav_pages") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/media.php" class="nav-item <?= $active === 'media' ? 'active' : '' ?>" data-label="Media">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <span class="nav-label"><?= __("nav_media") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/trash.php" class="nav-item <?= $active === 'trash' ? 'active' : '' ?>" data-label="Papelera">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
      <span class="nav-label">Papelera</span>
    </a>
    <a href="<?= base_url() ?>/admin/analytics.php" class="nav-item <?= $active === 'analytics' ? 'active' : '' ?>" data-label="Estadísticas">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      <span class="nav-label">Estadísticas</span>
    </a>
    <?php if (cms_plugin_is_active('newsletter')): ?>
    <a href="<?= base_url() ?>/admin/newsletter.php" class="nav-item <?= $active === 'newsletter' ? 'active' : '' ?>" data-label="Newsletter">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      <span class="nav-label">Newsletter</span>
    </a>
    <?php endif; ?>
    <?php if (cms_plugin_is_active('series')): ?>
    <a href="<?= base_url() ?>/admin/plugins.php?action=settings&plugin=series" class="nav-item <?= $active === 'series' ? 'active' : '' ?>" data-label="Series">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="12" y2="14"/></svg>
      <span class="nav-label">Series</span>
    </a>
    <?php endif; ?>

    <div class="nav-section">Herramientas</div>
    <?php if (cms_plugin_is_active('wp-import')): ?>
    <a href="<?= base_url() ?>/admin/plugins.php?action=settings&plugin=wp-import" class="nav-item <?= $active === 'import' ? 'active' : '' ?>" data-label="Importar WP">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <span class="nav-label"><?= __("nav_import_wp") ?></span>
    </a>
    <?php endif; ?>
    <?php if (cms_plugin_is_active('search-replace')): ?>
    <a href="<?= base_url() ?>/admin/plugins.php?action=settings&plugin=search-replace" class="nav-item <?= $active === 'tools' ? 'active' : '' ?>" data-label="Buscar y reemplazar">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <span class="nav-label"><?= __("nav_search_replace") ?></span>
    </a>
    <?php endif; ?>
    <a href="<?= base_url() ?>/admin/export.php" class="nav-item <?= $active === 'export' ? 'active' : '' ?>" data-label="Exportar">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      <span class="nav-label"><?= __("nav_export") ?></span>
    </a>

    <div class="nav-section">Configuración</div>
    <a href="<?= base_url() ?>/admin/settings.php" class="nav-item <?= $active === 'settings' ? 'active' : '' ?>" data-label="Ajustes">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      <span class="nav-label"><?= __("nav_settings") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/users.php" class="nav-item <?= $active === 'users' ? 'active' : '' ?>" data-label="Usuarios">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="nav-label"><?= __("nav_users") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/plugins.php" class="nav-item <?= $active === 'plugins' ? 'active' : '' ?>" data-label="Plugins">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
      <span class="nav-label">Plugins</span>
    </a>
    <a href="<?= base_url() ?>/admin/themes.php" class="nav-item <?= $active === 'themes' ? 'active' : '' ?>" data-label="Temas">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
      <span class="nav-label"><?= __("nav_themes") ?></span>
    </a>
    <a href="<?= base_url() ?>/admin/update.php" class="nav-item <?= $active === 'update' ? 'active' : '' ?>" data-label="Actualizaciones" style="display:flex;align-items:center;">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
      <span class="nav-label"><?= __("nav_updates", "Actualizaciones") ?></span>
      <span class="sidebar-update-badge" id="cms-update-badge" style="margin-left:auto;background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;<?= $cms_update_available ? '' : 'display:none;' ?>">v<?= htmlspecialchars($cms_available_ver) ?></span>
    </a>

    <div class="nav-section">Sitio</div>
    <a href="<?= base_url() ?>/" target="_blank" class="nav-item" data-label="Ver sitio">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      <span class="nav-label"><?= __("nav_view_site") ?></span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-row">
      <?php if ($avatarUrl): ?>
        <img src="<?= htmlspecialchars(image_cache_url($avatarUrl, 68, 68)) ?>" alt="<?= htmlspecialchars($displayName) ?>" class="user-avatar" style="object-fit:cover;border:1px solid var(--border)">
      <?php else: ?>
        <div class="user-avatar"><?= strtoupper(substr($displayName ?: $user, 0, 1)) ?></div>
      <?php endif; ?>
      <span class="user-name" title="<?= htmlspecialchars($displayName) ?>"><?= htmlspecialchars($displayName) ?></span>
    </div>
    <a href="<?= base_url() ?>/admin/logout.php" class="logout-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      <span class="logout-label"><?= __("nav_sign_out") ?></span>
    </a>
  </div>
</aside>

<div class="main" id="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Menú">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-title"><?= htmlspecialchars($page_title) ?></div>
    </div>
    <div class="topbar-actions">
      <!-- Language switcher -->
      <?php $cur_lang = detect_admin_lang(); ?>
      <div style="display:flex;gap:3px;align-items:center;background:var(--surface2);padding:3px;border-radius:8px;border:1px solid var(--border);" title="Idioma del panel / Panel language">
        <a href="?lang=es" style="font-size:0.8rem;font-weight:700;padding:0.25rem 0.6rem;border-radius:6px;text-decoration:none;transition:all 0.15s;
          background:<?= $cur_lang==='es' ? 'var(--accent)' : 'transparent' ?>;
          color:<?= $cur_lang==='es' ? '#ffffff' : 'var(--muted)' ?>;">ES</a>
        <a href="?lang=en" style="font-size:0.8rem;font-weight:700;padding:0.25rem 0.6rem;border-radius:6px;text-decoration:none;transition:all 0.15s;
          background:<?= $cur_lang==='en' ? 'var(--accent)' : 'transparent' ?>;
          color:<?= $cur_lang==='en' ? '#ffffff' : 'var(--muted)' ?>;">EN</a>
      </div>

  <div id="cms-update-banner" class="update-notification-banner" style="display:none;">
    <div class="update-banner-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>
    </div>
    <div class="update-banner-body">
      <div class="update-banner-title"><?= __('cms_update_available_title', '¡Nueva versión disponible!') ?></div>
      <div class="update-banner-desc">
        <?= __raw('cms_update_available_desc', 'Hay una nueva versión de KutBlog (:version). Actualmente tienes instalada la v:current.', [
          'version' => '<strong id="cms-update-ver-label">v' . htmlspecialchars($cms_available_ver) . '</strong>',
          'current' => CMS_VERSION
        ]) ?>
      </div>
    </div>
    <div class="update-banner-actions">
      <a href="<?= base_url() ?>/admin/update.php" class="btn-update-banner"><?= __('cms_update_now_btn', 'Actualizar ahora') ?> →</a>
      <button type="button" class="btn-dismiss-banner" onclick="dismissUpdateBanner('cms')" title="Descartar">✕</button>
    </div>
  </div>

<script>
function dismissUpdateBanner(app) {
  var ver = document.getElementById(app + '-update-ver-label')?.innerText || '1';
  sessionStorage.setItem('dismissed_update_' + app, ver);
  var b = document.getElementById(app + '-update-banner');
  if (b) b.style.display = 'none';
}

// Run immediately — body already exists at this point
(function() {
  const COLLAPSED_KEY = 'kutblog_sidebar_collapsed';

  // Restore collapsed state before paint to avoid flicker
  if (localStorage.getItem(COLLAPSED_KEY) === '1') {
    document.getElementById('admin-body').classList.add('sidebar-collapsed');
  }

  document.addEventListener('DOMContentLoaded', function() {
    const body          = document.getElementById('admin-body');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const mobileBtn     = document.getElementById('mobile-menu-btn');
    const overlay       = document.getElementById('sidebar-overlay');

    // ── Update banner positioning & visibility ─────────────────────────────
    const banner   = document.getElementById('cms-update-banner');
    const badge    = document.getElementById('cms-update-badge');
    const verLabel = document.getElementById('cms-update-ver-label');
    const topbar   = document.querySelector('.topbar');

    if (banner && topbar && topbar.parentNode) {
      topbar.parentNode.insertBefore(banner, topbar.nextSibling);
    }

    const isAvailable = <?= $cms_update_available ? 'true' : 'false' ?>;
    const dismissed   = sessionStorage.getItem('dismissed_update_cms');
    const currentDismissed = verLabel ? verLabel.innerText : '';

    if (isAvailable && '<?= $active ?>' !== 'update' && dismissed !== currentDismissed) {
      if (banner) banner.style.display = 'flex';
    }

    <?php if (!empty($cms_check_stale)): ?>
    fetch('<?= base_url() ?>/api/update.php?action=check', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data && data.available && data.new_version) {
          const currentVer = '<?= CMS_VERSION ?>';
          if (data.new_version !== currentVer) {
            if (verLabel) verLabel.innerText = 'v' + data.new_version;
            if (badge) {
              badge.innerText = 'v' + data.new_version;
              badge.style.display = 'inline-block';
            }
            const dismissedNow = sessionStorage.getItem('dismissed_update_cms');
            if (banner && dismissedNow !== ('v' + data.new_version) && '<?= $active ?>' !== 'update') {
              banner.style.display = 'flex';
            }
          }
        }
      })
      .catch(function() {});
    <?php endif; ?>

    // ── Desktop: collapse/expand ──────────────────────────────────────────
    if (sidebarToggle) {
      sidebarToggle.addEventListener('click', function() {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem(COLLAPSED_KEY,
          body.classList.contains('sidebar-collapsed') ? '1' : '0');
      });
    }

    // ── Mobile: open/close drawer ─────────────────────────────────────────
    if (mobileBtn) {
      mobileBtn.addEventListener('click', function() {
        body.classList.toggle('sidebar-open');
      });
    }
    if (overlay) {
      overlay.addEventListener('click', function() {
        body.classList.remove('sidebar-open');
      });
    }

  });
})();
</script>
<?php } ?>
