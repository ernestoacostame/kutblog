<?php
// KutBlog - Authentication, User Roles & Security Engine
require_once __DIR__ . '/users.php';

/**
 * Retorna la duración en segundos configurada para recordar la sesión (por defecto 30 días).
 */
function auth_get_remember_duration(): int {
    $config = function_exists('cms_config') ? cms_config() : [];
    $days = (int)($config['remember_me_days'] ?? 30);
    if ($days < 1) $days = 30;
    return $days * 86400;
}

function session_start_secure(bool $isRemembered = false): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $hasRememberCookie = $isRemembered || !empty($_COOKIE['kutblog_remember']);
        $lifetime = $hasRememberCookie ? auth_get_remember_duration() : 0;

        if ($hasRememberCookie) {
            @ini_set('session.gc_maxlifetime', (string)auth_get_remember_duration());
        }

        if (!headers_sent()) {
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        @session_start();
        // Regenerate session ID periodically to prevent fixation
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && (!isset($_SESSION['last_regen']) || time() - $_SESSION['last_regen'] > 1800)) {
            @session_regenerate_id(true);
            $_SESSION['last_regen'] = time();
        }
    }
}

function hash_password(string $password): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2
        ]);
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Attempts primary login with username and password.
 * Returns:
 *   true           -> Login successful
 *   '2fa_required' -> Password valid, but 2FA token required to complete login
 *   false          -> Credentials incorrect or account blocked
 */
function login(string $username, string $password, bool $remember = false): bool|string {
    $username = trim($username);
    if (!$username || !$password) return false;

    // Retrieve user from user storage
    $user = get_user($username);

    // Fallback: migrate from config if not yet created in users storage
    if (!$user) {
        $config = function_exists('cms_config') ? cms_config() : [];
        if (!empty($config['admin_user']) && strcasecmp($username, $config['admin_user']) === 0) {
            if (verify_password($password, $config['admin_pass'] ?? '')) {
                // Auto-create user
                $user = [
                    'username' => $config['admin_user'],
                    'name'     => 'Administrador',
                    'email'    => 'admin@localhost',
                    'password' => $config['admin_pass'],
                    'role'     => 'admin',
                    'status'   => 'active',
                    'two_factor_enabled' => false,
                    'created_at' => date('c'),
                ];
                save_user($user);
            }
        }
    }

    if (!$user) {
        log_failed_attempt();
        return false;
    }

    // Check account status
    if (($user['status'] ?? 'active') !== 'active') {
        log_failed_attempt();
        return false;
    }

    // Verify password
    if (!verify_password($password, $user['password'] ?? '')) {
        log_failed_attempt();
        return false;
    }

    session_start_secure($remember);

    // Check if 2FA is enabled for this user
    if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
        $_SESSION['2fa_pending_user']     = $user['username'];
        $_SESSION['2fa_pending_time']     = time();
        $_SESSION['2fa_pending_remember'] = $remember;
        return '2fa_required';
    }

    // Direct login without 2FA
    $_SESSION['logged_in']     = true;
    $_SESSION['username']      = $user['username'];
    $_SESSION['user_role']     = $user['role'] ?? 'admin';
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['remember_me']   = $remember;
    unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_remember']);

    if ($remember) {
        auth_issue_remember_token($user);
    }

    $user['last_login'] = date('c');
    save_user($user);
    reset_failed_attempts();
    return true;
}

/**
 * Completes login by verifying 2FA TOTP code or backup recovery code.
 */
function login_verify_2fa(string $code, ?bool $remember = null): bool {
    session_start_secure();
    $username = $_SESSION['2fa_pending_user'] ?? null;
    $pendingTime = $_SESSION['2fa_pending_time'] ?? 0;
    $remember = $remember ?? (!empty($_SESSION['2fa_pending_remember']));

    // Timeout: 5 minutes to complete 2FA challenge
    if (!$username || time() - $pendingTime > 300) {
        unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_remember']);
        return false;
    }

    $user = get_user($username);
    if (!$user) return false;

    $secret = $user['two_factor_secret'] ?? '';
    $valid = false;

    // Check 6-digit TOTP
    if ($secret && totp_verify($secret, $code)) {
        $valid = true;
    } elseif (totp_verify_recovery_code($user, $code)) {
        // Recovery code used
        $valid = true;
    }

    if ($valid) {
        $_SESSION['logged_in']     = true;
        $_SESSION['username']      = $user['username'];
        $_SESSION['user_role']     = $user['role'] ?? 'admin';
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['remember_me']   = $remember;
        unset($_SESSION['2fa_pending_user'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_remember']);

        if ($remember) {
            auth_issue_remember_token($user);
        }

        $user['last_login'] = date('c');
        save_user($user);
        reset_failed_attempts();
        return true;
    }

    log_failed_attempt();
    return false;
}

/**
 * Genera y registra un token persistente de "Recordarme" para el usuario y establece la cookie.
 */
function auth_issue_remember_token(array &$user): string {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $duration  = auth_get_remember_duration();
    $expires   = time() + $duration;

    if (!isset($user['remember_tokens']) || !is_array($user['remember_tokens'])) {
        $user['remember_tokens'] = [];
    }

    // Purgar tokens expirados
    $user['remember_tokens'] = array_values(array_filter(
        $user['remember_tokens'],
        fn($t) => ($t['expires'] ?? 0) > time()
    ));

    // Mantener un máximo de 10 dispositivos recordados por cuenta
    if (count($user['remember_tokens']) >= 10) {
        array_shift($user['remember_tokens']);
    }

    $tokenEntry = [
        'selector'         => $selector,
        'hashed_validator' => hash('sha256', $validator),
        'expires'          => $expires,
        'created_at'       => date('c'),
        'last_used'        => date('c'),
        'user_agent'       => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido', 0, 160),
        'ip'               => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    $user['remember_tokens'][] = $tokenEntry;
    save_user($user);

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $cookiePayload = base64_encode($user['username'] . ':' . $selector . ':' . $validator);

    if (!headers_sent()) {
        setcookie('kutblog_remember', $cookiePayload, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    $_SESSION['remember_me']       = true;
    $_SESSION['remember_selector'] = $selector;
    $_SESSION['remember_expires']  = $expires;

    return $cookiePayload;
}

/**
 * Elimina la cookie de "Recordarme" del navegador y limpia variables de sesión.
 */
function auth_clear_remember_cookie(): void {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if (!headers_sent()) {
        setcookie('kutblog_remember', '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    unset($_COOKIE['kutblog_remember']);
    unset($_SESSION['remember_me'], $_SESSION['remember_selector'], $_SESSION['remember_expires']);
}

/**
 * Revoca un token de recordarme por su selector para un usuario dado.
 */
function auth_revoke_remember_token(string $username, string $selector): bool {
    $user = get_user($username);
    if (!$user || empty($user['remember_tokens']) || !is_array($user['remember_tokens'])) {
        return false;
    }
    $origCount = count($user['remember_tokens']);
    $user['remember_tokens'] = array_values(array_filter(
        $user['remember_tokens'],
        fn($t) => ($t['selector'] ?? '') !== $selector
    ));
    if (count($user['remember_tokens']) !== $origCount) {
        save_user($user);
        return true;
    }
    return false;
}

/**
 * Revoca todas las sesiones recordadas de un usuario (opcionalmente conservando el dispositivo actual).
 */
function auth_revoke_all_remember_tokens(string $username, ?string $exceptSelector = null): bool {
    $user = get_user($username);
    if (!$user) return false;
    if ($exceptSelector) {
        $user['remember_tokens'] = array_values(array_filter(
            $user['remember_tokens'] ?? [],
            fn($t) => ($t['selector'] ?? '') === $exceptSelector
        ));
    } else {
        $user['remember_tokens'] = [];
    }
    return save_user($user);
}

/**
 * Retorna la lista de dispositivos recordados activos para un usuario.
 */
function auth_get_active_tokens(string $username): array {
    $user = get_user($username);
    if (!$user || empty($user['remember_tokens']) || !is_array($user['remember_tokens'])) {
        return [];
    }
    $now = time();
    $active = [];
    foreach ($user['remember_tokens'] as $t) {
        if (($t['expires'] ?? 0) > $now) {
            $active[] = $t;
        }
    }
    usort($active, fn($a, $b) => ($b['expires'] ?? 0) <=> ($a['expires'] ?? 0));
    return $active;
}

/**
 * Valida la cookie persistente de "Recordarme" y autentica la sesión si es válida.
 */
function auth_check_remember_cookie(): bool {
    $raw = $_COOKIE['kutblog_remember'] ?? '';
    if (!$raw) return false;

    if (strpos($raw, ':') === false) {
        $decoded = base64_decode($raw, true);
        if ($decoded && strpos($decoded, ':') !== false) {
            $raw = $decoded;
        }
    }

    $parts = explode(':', $raw);
    if (count($parts) !== 3) {
        auth_clear_remember_cookie();
        return false;
    }

    [$username, $selector, $validator] = $parts;
    $user = get_user($username);
    if (!$user || ($user['status'] ?? 'active') !== 'active') {
        auth_clear_remember_cookie();
        return false;
    }

    $tokens = $user['remember_tokens'] ?? [];
    if (!is_array($tokens) || empty($tokens)) {
        auth_clear_remember_cookie();
        return false;
    }

    $foundToken = null;
    $foundIndex = null;
    $now = time();

    foreach ($tokens as $idx => $t) {
        if (($t['selector'] ?? '') === $selector) {
            $foundToken = $t;
            $foundIndex = $idx;
            break;
        }
    }

    if (!$foundToken || ($foundToken['expires'] ?? 0) < $now) {
        auth_clear_remember_cookie();
        return false;
    }

    $calcHash = hash('sha256', $validator);
    if (!hash_equals($foundToken['hashed_validator'], $calcHash)) {
        // Alerta de seguridad: token adulterado, revocar y limpiar
        auth_revoke_remember_token($username, $selector);
        auth_clear_remember_cookie();
        return false;
    }

    // Rotación preventiva de validador para renovar el ticket de seguridad
    $newValidator = bin2hex(random_bytes(32));
    $duration = auth_get_remember_duration();
    $newExpires = $now + $duration;

    $tokens[$foundIndex]['hashed_validator'] = hash('sha256', $newValidator);
    $tokens[$foundIndex]['expires'] = $newExpires;
    $tokens[$foundIndex]['last_used'] = date('c');
    $tokens[$foundIndex]['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $user['remember_tokens'] = $tokens;
    $user['last_login'] = date('c');
    save_user($user);

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookiePayload = base64_encode($username . ':' . $selector . ':' . $newValidator);

    if (!headers_sent()) {
        setcookie('kutblog_remember', $cookiePayload, [
            'expires'  => $newExpires,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    session_start_secure(true);
    $_SESSION['logged_in']         = true;
    $_SESSION['username']          = $user['username'];
    $_SESSION['user_role']         = $user['role'] ?? 'admin';
    $_SESSION['login_time']        = $now;
    $_SESSION['last_activity']     = $now;
    $_SESSION['remember_me']       = true;
    $_SESSION['remember_selector'] = $selector;
    $_SESSION['remember_expires']  = $newExpires;

    return true;
}

function logout(): void {
    if (!empty($_COOKIE['kutblog_remember'])) {
        $raw = $_COOKIE['kutblog_remember'];
        if (strpos($raw, ':') === false) {
            $decoded = base64_decode($raw, true);
            if ($decoded) $raw = $decoded;
        }
        $parts = explode(':', $raw);
        if (count($parts) === 3) {
            auth_revoke_remember_token($parts[0], $parts[1]);
        }
    }
    auth_clear_remember_cookie();
    session_start_secure();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function is_logged_in(): bool {
    session_start_secure();

    // 1. Sesión PHP activa
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['username'])) {
        $now = time();
        $isRemembered = !empty($_SESSION['remember_me']);

        if ($isRemembered) {
            $maxExpire = $_SESSION['remember_expires'] ?? ($now + auth_get_remember_duration());
            if ($now > $maxExpire) {
                logout();
                return false;
            }
        } else {
            // Sesión estándar: ventana deslizante de 24 horas de inactividad
            $lastAct = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $now;
            if ($now - $lastAct > 86400) {
                logout();
                return false;
            }
        }

        $_SESSION['last_activity'] = $now;
        return true;
    }

    // 2. Sin sesión en memoria: intentar auto-login desde token persistente de "Recordarme"
    if (!empty($_COOKIE['kutblog_remember'])) {
        if (auth_check_remember_cookie()) {
            return true;
        }
    }

    return false;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . base_url() . '/admin/login.php');
        exit;
    }
}

function require_permission(string $capability): void {
    require_login();
    if (!user_can($capability)) {
        http_response_code(403);
        exit('Acceso denegado: no tienes permisos para realizar esta acción.');
    }
}

function generate_csrf(): string {
    session_start_secure();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    session_start_secure();
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function log_failed_attempt(): void {
    $dir = defined('CACHE_PATH') ? CACHE_PATH : dirname(__DIR__) . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/failed_logins.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data[$ip] = ($data[$ip] ?? 0) + 1;
    $data[$ip . '_time'] = time();
    @file_put_contents($file, json_encode($data));
}

function reset_failed_attempts(): void {
    $dir = defined('CACHE_PATH') ? CACHE_PATH : dirname(__DIR__) . '/cache';
    $file = $dir . '/failed_logins.json';
    if (!file_exists($file)) return;
    $data = json_decode(file_get_contents($file), true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    unset($data[$ip], $data[$ip . '_time']);
    @file_put_contents($file, json_encode($data));
}

function is_rate_limited(): bool {
    $dir = defined('CACHE_PATH') ? CACHE_PATH : dirname(__DIR__) . '/cache';
    $file = $dir . '/failed_logins.json';
    if (!file_exists($file)) return false;
    $data = json_decode(file_get_contents($file), true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $attempts = $data[$ip] ?? 0;
    $last_time = $data[$ip . '_time'] ?? 0;
    // Reset after 15 minutes
    if (time() - $last_time > 900) return false;
    return $attempts >= 5;
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitize_filename(string $name): string {
    $name = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '-', $name));
    return preg_replace('/-+/', '-', trim($name, '-'));
}
