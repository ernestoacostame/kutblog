<?php
// KutBlog - Newsletter System (Double Opt-In, SMTP delivery, Broadcast on Publish)
// Inspired by quireINK's clean architecture and zero-spam compliance

if (!defined('ROOT_PATH')) {
    require_once dirname(__DIR__) . '/core/config.php';
}

function newsletter_dir(): string {
    $dir = CONTENT_PATH . '/newsletter';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir;
}

function newsletter_file(): string {
    return newsletter_dir() . '/subscribers.json';
}

function newsletter_config(): array {
    $cfg = cms_config();
    return $cfg['smtp'] ?? [
        'enabled'    => false,
        'host'       => '',
        'port'       => 587,
        'secure'     => 'tls', // 'tls', 'ssl', or 'none'
        'user'       => '',
        'pass'       => '',
        'from_email' => '',
        'from_name'  => $cfg['site_title'] ?? 'KutBlog',
    ];
}

function newsletter_save_config(array $smtp): bool {
    $cfg = cms_config();
    $cfg['smtp'] = array_merge($cfg['smtp'] ?? [], $smtp);
    $ok = file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    cms_config(true); // refresh in-request cache
    return $ok;
}

function newsletter_get_subscribers(): array {
    $file = newsletter_file();
    if (!file_exists($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function newsletter_save_subscribers(array $subs): bool {
    $file = newsletter_file();
    return file_put_contents($file, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Socket-based SMTP mailer supporting STARTTLS and SSL
 */
function newsletter_smtp_send(array $smtp, string $to, string $subject, string $htmlBody, array $headers = []): array {
    $host = trim($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $user = trim($smtp['user'] ?? '');
    $pass = (string)($smtp['pass'] ?? '');
    $secure = strtolower(trim($smtp['secure'] ?? 'tls'));
    $fromEmail = trim($smtp['from_email'] ?? '') ?: ($user ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $fromName  = trim($smtp['from_name'] ?? '') ?: 'KutBlog';

    if (empty($host)) {
        return ['ok' => false, 'error' => 'smtp_not_configured'];
    }

    $socketHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $errno = 0;
    $errstr = '';
    $timeout = 10;

    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['ok' => false, 'error' => "No se pudo conectar a {$host}:{$port} ({$errstr})"];
    }

    stream_set_timeout($socket, $timeout);

    $readResp = function() use ($socket): string {
        $resp = '';
        while ($line = fgets($socket, 515)) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $resp;
    };

    $sendCmd = function(string $cmd, array $expectedCodes) use ($socket, $readResp): array {
        fputs($socket, $cmd . "\r\n");
        $resp = $readResp();
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expectedCodes)) {
            return ['ok' => false, 'resp' => $resp, 'code' => $code];
        }
        return ['ok' => true, 'resp' => $resp, 'code' => $code];
    };

    $greeting = $readResp();
    if ((int)substr($greeting, 0, 3) !== 220) {
        fclose($socket);
        return ['ok' => false, 'error' => "Respuesta de servidor inválida: {$greeting}"];
    }

    $clientDomain = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $ehlo = $sendCmd("EHLO {$clientDomain}", [250]);
    if (!$ehlo['ok']) {
        $ehlo = $sendCmd("HELO {$clientDomain}", [250]);
        if (!$ehlo['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "EHLO falló: {$ehlo['resp']}"];
        }
    }

    // STARTTLS if requested and not already ssl://
    if ($secure === 'tls' && $port !== 465) {
        $tls = $sendCmd("STARTTLS", [220]);
        if ($tls['ok']) {
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($socket);
                return ['ok' => false, 'error' => 'Fallo al iniciar handshake TLS'];
            }
            // Re-EHLO after TLS negotiation
            $sendCmd("EHLO {$clientDomain}", [250]);
        }
    }

    // AUTH LOGIN if credentials provided
    if ($user !== '' && $pass !== '') {
        $auth = $sendCmd("AUTH LOGIN", [334]);
        if (!$auth['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "AUTH LOGIN no soportado: {$auth['resp']}"];
        }
        $uResp = $sendCmd(base64_encode($user), [334]);
        if (!$uResp['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "Usuario rechazado: {$uResp['resp']}"];
        }
        $pResp = $sendCmd(base64_encode($pass), [235]);
        if (!$pResp['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "Contraseña rechazada: {$pResp['resp']}"];
        }
    }

    // MAIL FROM & RCPT TO
    $fromCmd = $sendCmd("MAIL FROM:<{$fromEmail}>", [250]);
    if (!$fromCmd['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "MAIL FROM falló: {$fromCmd['resp']}"];
    }

    $toCmd = $sendCmd("RCPT TO:<{$to}>", [250, 251]);
    if (!$toCmd['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "RCPT TO falló: {$toCmd['resp']}"];
    }

    // DATA
    $dataCmd = $sendCmd("DATA", [354]);
    if (!$dataCmd['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "DATA falló: {$dataCmd['resp']}"];
    }

    $msgHeaders = [
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
        "To: <{$to}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
        "X-Mailer: KutBlog-Newsletter/2.0"
    ];
    foreach ($headers as $k => $v) {
        $msgHeaders[] = "{$k}: {$v}";
    }

    $rawMessage = implode("\r\n", $msgHeaders) . "\r\n\r\n" . $htmlBody . "\r\n.\r\n";
    fputs($socket, $rawMessage);
    $finalResp = $readResp();
    $sendCmd("QUIT", [221]);
    fclose($socket);

    if ((int)substr($finalResp, 0, 3) === 250) {
        return ['ok' => true, 'error' => ''];
    }
    return ['ok' => false, 'error' => "Error al enviar mensaje: {$finalResp}"];
}

/**
 * Universal sendMail: uses SMTP if enabled, otherwise falls back to mail()
 */
function newsletter_send_mail(string $to, string $subject, string $htmlBody, array $headers = []): array {
    $smtp = newsletter_config();
    if (!empty($smtp['enabled']) && !empty($smtp['host'])) {
        return newsletter_smtp_send($smtp, $to, $subject, $htmlBody, $headers);
    }

    // Fallback to PHP mail()
    $fromEmail = trim($smtp['from_email'] ?? '') ?: 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $fromName  = trim($smtp['from_name'] ?? '') ?: 'KutBlog';

    $mailHeaders = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
        "Reply-To: <{$fromEmail}>",
        "X-Mailer: KutBlog-Newsletter/2.0"
    ];
    foreach ($headers as $k => $v) {
        $mailHeaders[] = "{$k}: {$v}";
    }

    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $mailHeaders));
    return ['ok' => (bool)$sent, 'error' => $sent ? '' : 'mail_function_failed'];
}

/**
 * Add subscriber with Double Opt-In
 */
function newsletter_add_subscriber(string $email, string $name = ''): array {
    $email = strtolower(trim($email));
    $name = trim($name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Correo electrónico no válido'];
    }

    $subs = newsletter_get_subscribers();
    $now = time();
    $existingKey = null;

    foreach ($subs as $k => $sub) {
        if (strtolower($sub['email']) === $email) {
            $existingKey = $k;
            break;
        }
    }

    if ($existingKey && $subs[$existingKey]['status'] === 'confirmed') {
        if (!empty($name) && empty($subs[$existingKey]['name'])) {
            $subs[$existingKey]['name'] = $name;
            newsletter_save_subscribers($subs);
        }
        return ['ok' => true, 'already_confirmed' => true, 'message' => '¡Ya estás suscrito a nuestro boletín!'];
    }

    // Anti-bombing cooldown: 1 confirm email per 15 minutes per address
    if ($existingKey && !empty($subs[$existingKey]['confirm_sent_at'])) {
        if ($now - $subs[$existingKey]['confirm_sent_at'] < 900) {
            return ['ok' => true, 'cooldown' => true, 'message' => 'Ya hemos enviado un correo de confirmación recientemente. Por favor, revisa tu bandeja de entrada o spam.'];
        }
    }

    $token = $existingKey ? ($subs[$existingKey]['token'] ?? bin2hex(random_bytes(16))) : bin2hex(random_bytes(16));
    $key = $existingKey ?: ('sub_' . substr(md5($email), 0, 10));

    $subs[$key] = [
        'email'           => $email,
        'name'            => $name ?: ($existingKey ? ($subs[$existingKey]['name'] ?? '') : ''),
        'status'          => 'pending',
        'token'           => $token,
        'created_at'      => $existingKey ? $subs[$existingKey]['created_at'] : date('Y-m-d H:i:s'),
        'confirm_sent_at' => $now,
    ];
    newsletter_save_subscribers($subs);

    // Send confirmation email
    $confirmUrl = rtrim(base_url(), '/') . '/api/subscribe.php?confirm=' . urlencode($token);
    $unsubUrl   = rtrim(base_url(), '/') . '/api/subscribe.php?unsubscribe=' . urlencode($token);
    $subject    = 'Confirma tu suscripción a ' . (cms_config()['site_title'] ?? 'KutBlog');
    $html       = newsletter_render_confirm_email($email, $confirmUrl);

    newsletter_send_mail($email, $subject, $html, [
        'List-Unsubscribe' => "<{$unsubUrl}>"
    ]);

    return ['ok' => true, 'message' => '¡Te hemos enviado un correo de confirmación! Haz clic en el enlace del mensaje para activar tu suscripción.'];
}

/**
 * Confirm subscriber token
 */
function newsletter_confirm_token(string $token): ?array {
    $token = trim($token);
    if ($token === '') return null;

    $subs = newsletter_get_subscribers();
    foreach ($subs as $k => &$sub) {
        if (($sub['token'] ?? '') === $token) {
            $sub['status'] = 'confirmed';
            $sub['confirmed_at'] = date('Y-m-d H:i:s');
            newsletter_save_subscribers($subs);
            return $sub;
        }
    }
    return null;
}

/**
 * Unsubscribe subscriber token
 */
function newsletter_unsubscribe_token(string $token): bool {
    $token = trim($token);
    if ($token === '') return false;

    $subs = newsletter_get_subscribers();
    foreach ($subs as $k => &$sub) {
        if (($sub['token'] ?? '') === $token) {
            $sub['status'] = 'unsubscribed';
            $sub['unsubscribed_at'] = date('Y-m-d H:i:s');
            return newsletter_save_subscribers($subs);
        }
    }
    return false;
}

/**
 * Delete a subscriber
 */
function newsletter_delete_subscriber(string $email): bool {
    $email = strtolower(trim($email));
    $subs = newsletter_get_subscribers();
    $changed = false;
    foreach ($subs as $k => $sub) {
        if (strtolower($sub['email']) === $email) {
            unset($subs[$k]);
            $changed = true;
            break;
        }
    }
    return $changed ? newsletter_save_subscribers($subs) : false;
}

/**
 * Import subscribers from CSV content (Substack, Mailchimp, Ghost, or generic CSV)
 */
function newsletter_import_subscribers_csv(string $csvContent, bool $autoConfirm = true, bool $updateExisting = true): array {
    // Strip UTF-8 BOM if present
    if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
        $csvContent = substr($csvContent, 3);
    }
    $csvContent = trim($csvContent);
    if ($csvContent === '') {
        return ['ok' => false, 'error' => 'El archivo o contenido CSV está vacío.'];
    }

    // Detect delimiter using the first non-empty line
    $firstLine = strtok($csvContent, "\r\n");
    $delimiters = [',', ';', "\t"];
    $counts = [];
    foreach ($delimiters as $d) {
        $counts[$d] = substr_count($firstLine, $d);
    }
    arsort($counts);
    $delimiter = array_key_first($counts) ?: ',';
    if (($counts[$delimiter] ?? 0) === 0) {
        $delimiter = ',';
    }

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $csvContent);
    rewind($stream);

    // Read first row to detect headers
    $firstRow = fgetcsv($stream, 0, $delimiter);
    if ($firstRow === false || empty($firstRow)) {
        fclose($stream);
        return ['ok' => false, 'error' => 'No se pudieron leer filas del archivo CSV.'];
    }

    $emailCol = null;
    $nameCol = null;
    $dateCol = null;
    $cancelDateCol = null;
    $typeCol = null;

    $hasHeader = false;
    foreach ($firstRow as $idx => $val) {
        $clean = strtolower(trim($val, "\"' \t\n\r\0\x0B"));
        if ($emailCol === null && (
            $clean === 'email' || $clean === 'e-mail' || $clean === 'correo' || $clean === 'mail' ||
            str_contains($clean, 'email') || str_contains($clean, 'correo')
        )) {
            $emailCol = $idx;
            $hasHeader = true;
        } elseif ($nameCol === null && (
            $clean === 'name' || $clean === 'nombre' || $clean === 'full name' ||
            $clean === 'first name' || $clean === 'subscriber name'
        )) {
            $nameCol = $idx;
            $hasHeader = true;
        } elseif ($dateCol === null && (
            $clean === 'start date' || $clean === 'date' || $clean === 'fecha' ||
            $clean === 'created_at' || $clean === 'created at' || $clean === 'subscription date'
        )) {
            $dateCol = $idx;
            $hasHeader = true;
        } elseif ($cancelDateCol === null && (
            $clean === 'cancel date' || $clean === 'canceled at' || $clean === 'cancelled at' ||
            $clean === 'unsubscribe date' || $clean === 'fecha baja'
        )) {
            $cancelDateCol = $idx;
            $hasHeader = true;
        } elseif ($typeCol === null && (
            $clean === 'type' || $clean === 'tipo' || $clean === 'status' || $clean === 'estado'
        )) {
            $typeCol = $idx;
            $hasHeader = true;
        }
    }

    // If first row didn't look like a header (e.g. contains an actual email), treat it as a data row
    $rowsToProcess = [];
    if (!$hasHeader || filter_var(trim($firstRow[$emailCol ?? 0] ?? ''), FILTER_VALIDATE_EMAIL)) {
        // No header: assume col 0 is email, col 1 is name (if available)
        $emailCol = 0;
        $nameCol = (count($firstRow) > 1) ? 1 : null;
        $rowsToProcess[] = $firstRow;
    }

    while (($r = fgetcsv($stream, 0, $delimiter)) !== false) {
        if (!empty($r) && array_filter($r, fn($v) => trim($v) !== '')) {
            $rowsToProcess[] = $r;
        }
    }
    fclose($stream);

    if (empty($rowsToProcess)) {
        return ['ok' => false, 'error' => 'No se encontraron registros de suscriptores para importar.'];
    }

    $subs = newsletter_get_subscribers();
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $invalid = 0;

    foreach ($rowsToProcess as $row) {
        $email = '';
        if ($emailCol !== null && isset($row[$emailCol])) {
            $email = strtolower(trim($row[$emailCol]));
        }

        // Fallback: search row for an email address if emailCol failed
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $foundEmail = false;
            foreach ($row as $cell) {
                $cellTrim = strtolower(trim($cell));
                if (filter_var($cellTrim, FILTER_VALIDATE_EMAIL)) {
                    $email = $cellTrim;
                    $foundEmail = true;
                    break;
                }
            }
            if (!$foundEmail) {
                $invalid++;
                continue;
            }
        }

        $name = ($nameCol !== null && isset($row[$nameCol])) ? trim($row[$nameCol]) : '';
        
        // Parse date
        $dateRaw = ($dateCol !== null && isset($row[$dateCol])) ? trim($row[$dateCol]) : '';
        $createdAt = date('Y-m-d H:i:s');
        if (!empty($dateRaw)) {
            $ts = strtotime($dateRaw);
            if ($ts !== false && $ts > 0) {
                $createdAt = date('Y-m-d H:i:s', $ts);
            }
        }

        // Check if canceled/unsubscribed
        $isCancelled = false;
        if ($cancelDateCol !== null && !empty(trim($row[$cancelDateCol] ?? ''))) {
            $isCancelled = true;
        }
        if ($typeCol !== null && in_array(strtolower(trim($row[$typeCol] ?? '')), ['cancel', 'cancelled', 'canceled', 'unsubscribed', 'baja'], true)) {
            $isCancelled = true;
        }

        $targetStatus = $isCancelled ? 'unsubscribed' : ($autoConfirm ? 'confirmed' : 'pending');

        // Check if existing subscriber
        $existingKey = null;
        foreach ($subs as $k => $s) {
            if (strtolower($s['email']) === $email) {
                $existingKey = $k;
                break;
            }
        }

        if ($existingKey !== null) {
            if ($updateExisting) {
                $changed = false;
                if (!empty($name) && (empty($subs[$existingKey]['name']) || $subs[$existingKey]['name'] !== $name)) {
                    $subs[$existingKey]['name'] = $name;
                    $changed = true;
                }
                if ($subs[$existingKey]['status'] === 'pending' && $autoConfirm && !$isCancelled) {
                    $subs[$existingKey]['status'] = 'confirmed';
                    $subs[$existingKey]['confirmed_at'] = $createdAt;
                    $changed = true;
                } elseif ($isCancelled && $subs[$existingKey]['status'] !== 'unsubscribed') {
                    $subs[$existingKey]['status'] = 'unsubscribed';
                    $subs[$existingKey]['unsubscribed_at'] = date('Y-m-d H:i:s');
                    $changed = true;
                }
                if ($changed) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
            continue;
        }

        // New subscriber
        $token = bin2hex(random_bytes(16));
        $key = 'sub_' . substr(md5($email), 0, 10);

        $subs[$key] = [
            'email'           => $email,
            'name'            => $name,
            'status'          => $targetStatus,
            'token'           => $token,
            'created_at'      => $createdAt,
            'confirmed_at'    => ($targetStatus === 'confirmed') ? $createdAt : null,
            'unsubscribed_at' => ($targetStatus === 'unsubscribed') ? date('Y-m-d H:i:s') : null,
            'source'          => 'csv_import',
        ];
        $imported++;
    }

    if ($imported > 0 || $updated > 0) {
        newsletter_save_subscribers($subs);
    }

    return [
        'ok'       => true,
        'imported' => $imported,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'invalid'  => $invalid,
        'total'    => count($rowsToProcess)
    ];
}

/**
 * Responsive HTML shell for emails
 */
function newsletter_email_shell(string $title, string $innerHtml, string $unsubUrl = ''): string {
    $cfg = cms_config();
    $siteTitle = htmlspecialchars($cfg['site_title'] ?? 'KutBlog');
    $siteUrl = htmlspecialchars(base_url());
    $unsubBlock = $unsubUrl ? "
      <p style=\"margin-top:24px; font-size:12px; color:#71717a; text-align:center; line-height:1.5;\">
        Recibiste este correo porque estás suscrito a <strong>{$siteTitle}</strong>.<br>
        Si ya no deseas recibir nuestros boletines, puedes <a href=\"{$unsubUrl}\" style=\"color:#a1a1aa; text-decoration:underline;\">cancelar tu suscripción</a> en cualquier momento.
      </p>" : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0; padding:0; background-color:#09090b; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#f4f4f5;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#09090b; width:100%; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px; background-color:#18181b; border:1px solid #27272a; border-radius:12px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.4);">
          <!-- Header -->
          <tr>
            <td style="padding:24px 32px; border-bottom:1px solid #27272a; text-align:left;">
              <a href="{$siteUrl}" style="text-decoration:none; font-size:20px; font-weight:700; color:#fafafa; letter-spacing:-0.02em;">
                {$siteTitle}
              </a>
            </td>
          </tr>
          <!-- Body -->
          <tr>
            <td style="padding:32px 32px 40px 32px; text-align:left; font-size:16px; line-height:1.75; color:#d4d4d8;">
              {$innerHtml}
            </td>
          </tr>
        </table>
        {$unsubBlock}
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Format post HTML content for email clients
 * - Converts relative URLs to absolute URLs
 * - Embeds email-safe YouTube preview cards instead of iframes (which are blocked in email clients)
 * - Embeds email-safe audio cards with direct listen/download links
 * - Inlines clean styling for headings, paragraphs, lists, blockquotes, code, tables, images
 */
function newsletter_format_content_for_email(string $html, string $base, string $postUrl): string {
    if (trim($html) === '') return '';

    // 1. Convert relative URLs to absolute in src and href attributes
    $html = preg_replace_callback(
        '/\b(src|href)=["\'](\/[^"\']*)["\']/i',
        function ($m) use ($base) {
            return $m[1] . '="' . htmlspecialchars($base . $m[2]) . '"';
        },
        $html
    );

    // Also relative paths like src="media/..." without leading slash
    $html = preg_replace_callback(
        '/\bsrc=["\'](media\/[^"\']*)["\']/i',
        function ($m) use ($base) {
            return 'src="' . htmlspecialchars($base . '/' . $m[1]) . '"';
        },
        $html
    );

    // 2. Convert YouTube iframes into email-safe clickable video preview cards
    $html = preg_replace_callback(
        '/(?:<div[^>]*class=["\'][^"\']*yt-embed[^"\']*["\'][^>]*>\s*)?<iframe[^>]*src=["\'][^"\']*(?:youtube(?:-nocookie)?\.com\/embed\/|youtu\.be\/)([a-zA-Z0-9_-]{11})[^"\']*["\'][^>]*><\/iframe>(?:\s*<\/div>)?/i',
        function ($m) {
            $vid = $m[1];
            return '<div style="margin:24px 0; border:1px solid #27272a; border-radius:8px; overflow:hidden; background-color:#000000; text-align:center;">'
                . '<a href="https://www.youtube.com/watch?v=' . $vid . '" target="_blank" style="text-decoration:none; display:block;">'
                . '<img src="https://img.youtube.com/vi/' . $vid . '/hqdefault.jpg" alt="Ver video en YouTube" style="width:100%; max-width:580px; height:auto; display:block; margin:0 auto; border:none;">'
                . '<div style="padding:10px 16px; background-color:#18181b; color:#fafafa; font-size:13px; font-weight:600; text-align:center;">'
                . '▶ Ver video en YouTube ↗'
                . '</div></a></div>';
        },
        $html
    );

    // 3. Convert Vimeo iframes into email-safe clickable card
    $html = preg_replace_callback(
        '/(?:<div[^>]*class=["\'][^"\']*yt-embed[^"\']*["\'][^>]*>\s*)?<iframe[^>]*src=["\'][^"\']*player\.vimeo\.com\/video\/(\d+)[^"\']*["\'][^>]*><\/iframe>(?:\s*<\/div>)?/i',
        function ($m) {
            $vid = $m[1];
            return '<div style="margin:24px 0; padding:16px; border:1px solid #27272a; border-radius:8px; background-color:#18181b; text-align:center;">'
                . '<a href="https://vimeo.com/' . $vid . '" target="_blank" style="color:#818cf8; text-decoration:none; font-weight:600; font-size:14px;">'
                . '🎬 Reproducir video en Vimeo ↗'
                . '</a></div>';
        },
        $html
    );

    // 4. Convert <audio> player into email-safe podcast/audio banner
    $html = preg_replace_callback(
        '/(?:<div[^>]*class=["\'][^"\']*media-audio[^"\']*["\'][^>]*>\s*)?<audio[^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/audio>(?:\s*<\/div>)?/is',
        function ($m) {
            $src = $m[1];
            return '<div style="margin:22px 0; background-color:#27272a; border-left:4px solid #6366f1; border-radius:8px; padding:14px 18px; font-size:14px;">'
                . '<span style="font-size:18px; margin-right:8px; vertical-align:middle;">🎵</span>'
                . '<a href="' . htmlspecialchars($src) . '" target="_blank" style="color:#818cf8; text-decoration:none; font-weight:600;">'
                . 'Reproducir audio / Escuchar episodio ↗'
                . '</a></div>';
        },
        $html
    );

    // 5. Convert <video> tags into link box
    $html = preg_replace_callback(
        '/<video[^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/video>/is',
        function ($m) {
            $src = $m[1];
            return '<div style="margin:22px 0; background-color:#18181b; border:1px solid #27272a; border-radius:8px; padding:16px; text-align:center;">'
                . '<a href="' . htmlspecialchars($src) . '" target="_blank" style="color:#818cf8; text-decoration:none; font-weight:600; font-size:14px;">'
                . '🎬 Reproducir archivo de video en el navegador ↗'
                . '</a></div>';
        },
        $html
    );

    // 6. Style typography elements with inline CSS
    // Paragraphs
    $html = preg_replace('/<p\b(?![^>]*style=)[^>]*>/i', '<p style="margin:0 0 18px 0; font-size:16px; line-height:1.75; color:#d4d4d8;">', $html);

    // Headings
    $html = preg_replace('/<h2\b(?![^>]*style=)[^>]*>/i', '<h2 style="margin:34px 0 14px 0; font-size:22px; font-weight:700; color:#fafafa; line-height:1.3; letter-spacing:-0.02em;">', $html);
    $html = preg_replace('/<h3\b(?![^>]*style=)[^>]*>/i', '<h3 style="margin:26px 0 12px 0; font-size:18px; font-weight:600; color:#fafafa; line-height:1.35;">', $html);
    $html = preg_replace('/<h4\b(?![^>]*style=)[^>]*>/i', '<h4 style="margin:22px 0 10px 0; font-size:16px; font-weight:600; color:#fafafa; line-height:1.4;">', $html);

    // Callout notes & blockquotes
    $html = preg_replace('/<blockquote\b[^>]*class=["\'][^"\']*callout-note[^"\']*["\'][^>]*>/i', '<blockquote style="margin:22px 0; padding:14px 18px; border-left:4px solid #6366f1; background-color:rgba(99,102,241,0.1); border-radius:0 8px 8px 0; color:#f4f4f5; font-style:normal; font-size:15px; line-height:1.65;">', $html);
    $html = preg_replace('/<blockquote\b(?![^>]*style=)[^>]*>/i', '<blockquote style="margin:22px 0; padding:10px 0 10px 18px; border-left:3px solid #6366f1; color:#a1a1aa; font-style:italic; font-size:16px; line-height:1.65; background-color:#18181b; border-radius:0 6px 6px 0;">', $html);

    // Pre & Code
    $html = preg_replace('/<pre\b(?![^>]*style=)[^>]*>/i', '<pre style="background-color:#18181b; border:1px solid #27272a; border-radius:8px; padding:14px 18px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; color:#e4e4e7; overflow-x:auto; line-height:1.55; margin:22px 0;">', $html);
    $html = preg_replace('/<code\b(?![^>]*style=)[^>]*>/i', '<code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.9em; background-color:#27272a; color:#a5b4fc; padding:2px 5px; border-radius:4px;">', $html);

    // Lists
    $html = preg_replace('/<ul\b(?![^>]*style=)[^>]*>/i', '<ul style="margin:0 0 18px 0; padding-left:24px; color:#d4d4d8; line-height:1.75;">', $html);
    $html = preg_replace('/<ol\b(?![^>]*style=)[^>]*>/i', '<ol style="margin:0 0 18px 0; padding-left:24px; color:#d4d4d8; line-height:1.75;">', $html);
    $html = preg_replace('/<li\b(?![^>]*style=)[^>]*>/i', '<li style="margin-bottom:6px;">', $html);

    // Links (that don't already have a style attribute)
    $html = preg_replace('/<a\b(?![^>]*style=)([^>]*)>/i', '<a style="color:#818cf8; text-decoration:underline; text-underline-offset:3px;"$1>', $html);

    // Images (ensure max-width & styling)
    $html = preg_replace('/<img\b(?![^>]*style=)([^>]*)>/i', '<img style="max-width:100% !important; height:auto !important; border-radius:8px; margin:22px 0; display:block;"$1>', $html);

    // Horizontal Rule
    $html = preg_replace('/<hr\b(?![^>]*style=)[^>]*>/i', '<hr style="border:none; border-top:1px solid #27272a; margin:32px 0;">', $html);

    // Tables
    $html = preg_replace('/<table\b(?![^>]*style=)[^>]*>/i', '<table style="width:100%; border-collapse:collapse; margin:24px 0; font-size:14px; border:1px solid #27272a;">', $html);
    $html = preg_replace('/<th\b(?![^>]*style=)[^>]*>/i', '<th style="padding:8px 12px; background-color:#27272a; color:#fafafa; border:1px solid #3f3f46; text-align:left;">', $html);
    $html = preg_replace('/<td\b(?![^>]*style=)[^>]*>/i', '<td style="padding:8px 12px; border:1px solid #27272a; color:#d4d4d8;">', $html);

    return $html;
}

/**
 * Confirmation Email Template
 */
function newsletter_render_confirm_email(string $email, string $confirmUrl): string {
    $cfg = cms_config();
    $siteTitle = htmlspecialchars($cfg['site_title'] ?? 'KutBlog');
    $inner = <<<HTML
      <h2 style="margin:0 0 16px 0; font-size:22px; font-weight:700; color:#fafafa; letter-spacing:-0.02em;">Confirma tu suscripción</h2>
      <p style="margin:0 0 20px 0;">¡Hola! Gracias por tu interés en seguir las publicaciones de <strong>{$siteTitle}</strong>.</p>
      <p style="margin:0 0 28px 0;">Para completar el proceso y asegurarnos de que esta dirección de correo te pertenece, por favor haz clic en el siguiente botón:</p>
      <div style="text-align:center; margin:32px 0;">
        <a href="{$confirmUrl}" style="display:inline-block; background-color:#6366f1; color:#ffffff; font-size:15px; font-weight:600; text-decoration:none; padding:12px 28px; border-radius:8px; box-shadow:0 2px 8px rgba(99,102,241,0.3);">
          Confirmar suscripción
        </a>
      </div>
      <p style="margin:24px 0 0 0; font-size:13px; color:#71717a;">
        Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
        <a href="{$confirmUrl}" style="color:#818cf8; word-break:break-all;">{$confirmUrl}</a>
      </p>
      <p style="margin:16px 0 0 0; font-size:12px; color:#71717a;">
        Si tú no solicitaste esta suscripción, puedes ignorar este mensaje tranquilamente.
      </p>
HTML;
    return newsletter_email_shell('Confirma tu suscripción', $inner);
}

/**
 * Broadcast Article Email Template
 */
function newsletter_render_broadcast_email(array $post, string $token): string {
    $base = rtrim(base_url(), '/');
    $postUrl = $base . '/article/' . rawurlencode($post['slug']);
    $unsubUrl = $base . '/api/subscribe.php?unsubscribe=' . urlencode($token);
    $title = htmlspecialchars($post['title'] ?? 'Nuevo artículo');
    $excerpt = htmlspecialchars($post['excerpt'] ?? '');
    
    // Cover / Featured Image
    $coverHtml = '';
    if (!empty($post['featured_image'])) {
        $cover = $post['featured_image'];
        if (str_starts_with($cover, '/')) $cover = $base . $cover;
        elseif (!str_starts_with($cover, 'http://') && !str_starts_with($cover, 'https://')) $cover = $base . '/' . $cover;
        $coverHtml = '<div style="margin:0 0 24px 0; border-radius:8px; overflow:hidden;"><a href="' . $postUrl . '"><img src="' . htmlspecialchars($cover) . '" alt="' . $title . '" style="width:100%; height:auto; display:block; border-radius:8px;"></a></div>';
    }

    // Date & Reading time metadata
    $dateStr = format_date($post['created_at'] ?? 'now', 'medium');
    $author = htmlspecialchars($post['author'] ?? ($post['user'] ?? ''));
    $words = str_word_count(strip_tags($post['content'] ?? ''));
    $readingMin = max(1, (int)ceil($words / 220));
    $metaParts = [];
    if ($author) $metaParts[] = "Por {$author}";
    $metaParts[] = $dateStr;
    $metaParts[] = "{$readingMin} min de lectura";
    $metaLine = implode(' · ', $metaParts);

    // Format full article content for email
    $rawContent = $post['content'] ?? '';
    $formattedBody = newsletter_format_content_for_email($rawContent, $base, $postUrl);

    // Optional lead / excerpt
    $excerptHtml = '';
    if ($excerpt !== '') {
        $excerptHtml = '<p style="margin:0 0 24px 0; font-size:17px; color:#a1a1aa; line-height:1.65; font-style:italic; border-bottom:1px solid #27272a; padding-bottom:18px;">' . $excerpt . '</p>';
    }

    $inner = <<<HTML
      {$coverHtml}
      <h1 style="margin:0 0 14px 0; font-size:26px; font-weight:700; color:#fafafa; line-height:1.25; letter-spacing:-0.02em;">
        <a href="{$postUrl}" style="color:#fafafa; text-decoration:none;">{$title}</a>
      </h1>
      <div style="font-size:13px; color:#71717a; margin-bottom:20px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        {$metaLine}
      </div>
      {$excerptHtml}
      <div class="article-body" style="font-size:16px; line-height:1.75; color:#d4d4d8;">
        {$formattedBody}
      </div>
      <div style="margin:36px 0 10px 0; padding-top:24px; border-top:1px solid #27272a; text-align:center;">
        <a href="{$postUrl}" style="display:inline-block; background-color:#6366f1; color:#ffffff; font-size:14px; font-weight:600; text-decoration:none; padding:12px 26px; border-radius:8px; box-shadow:0 2px 8px rgba(99,102,241,0.3);">
          Ver o comentar en el blog ↗
        </a>
      </div>
HTML;
    return newsletter_email_shell($post['title'] ?? 'Nuevo artículo', $inner, $unsubUrl);
}

/**
 * Broadcast post to all confirmed subscribers
 */
function newsletter_broadcast_post(array $post, bool $force = false): array {
    if (empty($post['slug'])) return ['ok' => false, 'sent' => 0, 'error' => 'Artículo inválido'];
    if (!$force && !empty($post['newsletter_sent_at'])) {
        return ['ok' => true, 'sent' => 0, 'message' => 'Este artículo ya fue enviado previamente a los suscriptores.'];
    }

    $subs = newsletter_get_subscribers();
    $confirmed = array_filter($subs, fn($s) => ($s['status'] ?? '') === 'confirmed');

    if (empty($confirmed)) {
        return ['ok' => true, 'sent' => 0, 'message' => 'No hay suscriptores confirmados en la lista.'];
    }

    $sentCount = 0;
    $errors = [];
    $logFile = newsletter_dir() . '/sends.jsonl';

    foreach ($confirmed as $sub) {
        $email = $sub['email'];
        $token = $sub['token'] ?? '';
        $subject = '📰 ' . ($post['title'] ?? 'Nuevo artículo');
        $html = newsletter_render_broadcast_email($post, $token);
        $unsubUrl = rtrim(base_url(), '/') . '/api/subscribe.php?unsubscribe=' . urlencode($token);

        $res = newsletter_send_mail($email, $subject, $html, [
            'List-Unsubscribe' => "<{$unsubUrl}>"
        ]);

        $logEntry = [
            'ts'      => time(),
            'email'   => $email,
            'slug'    => $post['slug'],
            'success' => $res['ok'],
            'error'   => $res['error'] ?? ''
        ];
        @file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

        if ($res['ok']) {
            $sentCount++;
        } else {
            $errors[] = $email . ': ' . ($res['error'] ?? 'Error desconocido');
        }
    }

    // Update post with sent timestamp
    require_once ROOT_PATH . '/core/content.php';
    $post['newsletter_sent_at'] = date('Y-m-d H:i:s');
    save_content('articles', $post);

    return [
        'ok'     => true,
        'sent'   => $sentCount,
        'total'  => count($confirmed),
        'errors' => $errors
    ];
}

/**
 * Render responsive newsletter subscription box widget for frontend
 */
function newsletter_render_box(): string {
    $base = rtrim(base_url(), '/');
    return '
    <div class="kutblog-newsletter-widget" style="margin:2.5rem 0 1.5rem 0; padding:1.75rem; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.1); border-radius:12px; text-align:center;">
      <div style="font-size:1.75rem; margin-bottom:0.35rem;">📬</div>
      <h3 style="margin:0 0 0.4rem 0; font-size:1.15rem; font-weight:700;">Suscríbete al Boletín</h3>
      <p style="margin:0 auto 1.25rem auto; font-size:0.875rem; color:var(--muted, #a1a1aa); max-width:440px; line-height:1.5;">
        Recibe nuestras nuevas publicaciones directamente en tu correo. Cero spam, baja con un clic cuando quieras.
      </p>
      <form style="display:flex; max-width:420px; margin:0 auto; gap:0.5rem;" onsubmit="handleKutBlogNewsletter(event, this)">
        <input type="text" name="website" style="display:none !important;" tabindex="-1" autocomplete="off">
        <input type="email" name="email" placeholder="tu-correo@ejemplo.com" required
               style="flex:1; padding:0.6rem 0.85rem; border-radius:8px; border:1px solid var(--border, #3f3f46); background:rgba(0,0,0,0.2); color:var(--text, #fff); font-size:0.875rem;">
        <button type="submit" style="padding:0.6rem 1.1rem; border-radius:8px; border:none; background:var(--accent, #6366f1); color:#fff; font-weight:600; font-size:0.875rem; cursor:pointer;">
          Suscribirme
        </button>
      </form>
      <div class="nl-widget-msg" style="display:none; margin-top:0.85rem; font-size:0.85rem; font-weight:500;"></div>
      <script>
      function handleKutBlogNewsletter(e, form) {
        e.preventDefault();
        var btn = form.querySelector("button");
        var msg = form.parentNode.querySelector(".nl-widget-msg");
        var email = form.querySelector("input[name=email]").value;
        var hp = form.querySelector("input[name=website]").value;
        btn.disabled = true;
        btn.textContent = "...";
        fetch("' . $base . '/api/subscribe.php", {
          method: "POST",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({email: email, website: hp})
        }).then(function(r){ return r.json(); })
        .then(function(d){
          msg.style.display = "block";
          if (d.ok) {
            msg.style.color = "#10b981";
            msg.textContent = d.message || "✓ Te hemos enviado un correo de confirmación.";
            form.reset();
          } else {
            msg.style.color = "#ef4444";
            msg.textContent = d.error || "Ocurrió un error. Inténtalo de nuevo.";
          }
          btn.disabled = false;
          btn.textContent = "Suscribirme";
        }).catch(function(){
          msg.style.display = "block";
          msg.style.color = "#ef4444";
          msg.textContent = "Error al conectar con el servidor.";
          btn.disabled = false;
          btn.textContent = "Suscribirme";
        });
      }
      </script>
    </div>';
}
