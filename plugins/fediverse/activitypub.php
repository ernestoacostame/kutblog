<?php
// ActivityPub Engine for KutBlog Fediverse Plugin

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(dirname(__DIR__)));
}

// ---------------------------------------------------------------------------
// DB Setup
// ---------------------------------------------------------------------------
define('AP_SCHEMA_VERSION', 2);
function ap_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $path = ROOT_PATH . '/content/fediverse.db';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA cache_size = -32000');
    $pdo->exec('PRAGMA temp_store = MEMORY');
    $pdo->exec('PRAGMA mmap_size = 67108864');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // DDL (and WAL) only when the schema version is outdated
    $ver = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver < AP_SCHEMA_VERSION) {
        $pdo->exec('PRAGMA journal_mode = WAL');
        ap_ensure_schema($pdo);
        $pdo->exec('PRAGMA user_version = ' . (int) AP_SCHEMA_VERSION);
    }
    return $pdo;
}

function ap_q(string $sql, array $params = []): array {
    $st = ap_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function ap_one(string $sql, array $params = []): ?array {
    $st = ap_db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r ?: null;
}

function ap_exec(string $sql, array $params = []): int {
    $st = ap_db()->prepare($sql);
    $st->execute($params);
    return (int) ap_db()->lastInsertId();
}

function ap_ensure_schema(?PDO $pdo = null): void {
    if ($pdo === null) { ap_db(); return; } // ap_db() runs the DDL when needed
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_keys (
        id          INTEGER PRIMARY KEY DEFAULT 1,
        public_key  TEXT NOT NULL,
        private_key TEXT NOT NULL,
        created_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_outbox (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        activity_id TEXT NOT NULL UNIQUE,
        type        TEXT NOT NULL,
        object_json TEXT NOT NULL,
        published   TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_delivery (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        outbox_id   INTEGER NOT NULL REFERENCES ap_outbox(id) ON DELETE CASCADE,
        inbox       TEXT NOT NULL,
        status      TEXT NOT NULL DEFAULT 'pending',
        attempts    INTEGER NOT NULL DEFAULT 0,
        last_error  TEXT,
        next_try    TEXT NOT NULL DEFAULT (datetime('now')),
        delivered_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_followers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_url   TEXT NOT NULL UNIQUE,
        handle      TEXT NOT NULL,
        inbox       TEXT NOT NULL,
        name        TEXT,
        avatar      TEXT,
        followed_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_comments (
        id           TEXT PRIMARY KEY,
        post_slug    TEXT NOT NULL,
        actor_url    TEXT NOT NULL,
        author_name  TEXT NOT NULL,
        author_username TEXT NOT NULL,
        author_avatar TEXT,
        content      TEXT NOT NULL,
        published_at TEXT NOT NULL,
        in_reply_to  TEXT,
        depth        INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_interactions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        note_id     TEXT NOT NULL,
        actor_url   TEXT NOT NULL,
        type        TEXT NOT NULL, -- 'like' or 'announce'
        created_at  TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(note_id, actor_url, type)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ap_blocks (
        domain      TEXT PRIMARY KEY,
        reason      TEXT,
        created_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // High performance database indexes for lightning-fast queries
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_comments_slug ON ap_comments(post_slug, published_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_comments_reply ON ap_comments(in_reply_to)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_delivery_status_next ON ap_delivery(status, next_try)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_interactions_note ON ap_interactions(note_id, type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_followers_inbox ON ap_followers(inbox)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_outbox_published ON ap_outbox(published)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ap_delivery_outbox ON ap_delivery(outbox_id)");
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function ap_is_enabled(): bool {
    return cms_plugin_is_active('fediverse');
}

function ap_base_url(): string {
    $config = cms_config();
    $domain = $config['base_url'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim($domain, '/');
}

function ap_handle_domain(): string {
    $url = ap_base_url();
    return parse_url($url, PHP_URL_HOST) ?: $url;
}

function ap_keypair(): array {
    $row = ap_one("SELECT * FROM ap_keys WHERE id = 1");
    if ($row) return $row;
    
    // Generate RSA key pair
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $priv);
    $pub = openssl_pkey_get_details($res)['key'];
    
    ap_exec("INSERT OR IGNORE INTO ap_keys (id, public_key, private_key) VALUES (1, ?, ?)", [$pub, $priv]);
    return ['public_key' => $pub, 'private_key' => $priv];
}

function ap_json(array $payload, string $type = 'application/activity+json'): void {
    header("Content-Type: $type; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Endpoints Router
// ---------------------------------------------------------------------------
function ap_route_request(string $path, array $segments): void {
    if (!ap_is_enabled()) {
        http_response_code(404);
        exit('Fediverso desactivado.');
    }

    $ap = $_GET['ap'] ?? '';
    if ($path === '.well-known/webfinger') {
        ap_endpoint_webfinger();
        exit;
    }

    // users/{slug}
    if ($segments[0] === 'users' && isset($segments[1])) {
        $username = strtolower(cms_config()['fediverse_username'] ?? 'blog');
        $requested = strtolower($segments[1]);

        if ($requested !== $username) {
            http_response_code(404);
            exit('Usuario no encontrado.');
        }

        $action = $segments[2] ?? '';
        if ($action === '') {
            ap_endpoint_actor($username);
        } elseif ($action === 'inbox') {
            ap_endpoint_inbox($username);
        } elseif ($action === 'outbox') {
            ap_endpoint_outbox($username);
        } elseif ($action === 'followers') {
            ap_endpoint_followers($username);
        } elseif ($action === 'notes' && isset($segments[3])) {
            ap_endpoint_note($username, $segments[3]);
        } else {
            http_response_code(404);
            exit('Endpoint desconocido.');
        }
        exit;
    }
}

// ---------------------------------------------------------------------------
// 1. Webfinger
// ---------------------------------------------------------------------------
function ap_endpoint_webfinger(): void {
    $resource = $_GET['resource'] ?? '';
    if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $m)) {
        http_response_code(400);
        exit;
    }
    
    $user = strtolower($m[1]);
    $username = strtolower(cms_config()['fediverse_username'] ?? 'blog');
    
    if ($user !== $username) {
        http_response_code(404);
        exit('Usuario no encontrado.');
    }

    $actor = ap_base_url() . "/users/" . $username;
    ap_json([
        'subject' => "acct:$username@" . ap_handle_domain(),
        'aliases' => [$actor],
        'links' => [
            ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actor],
            ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => ap_base_url()],
        ],
    ], 'application/jrd+json');
}

// ---------------------------------------------------------------------------
// 2. Actor
// ---------------------------------------------------------------------------
function ap_get_image_mime(string $url): string {
    $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png': return 'image/png';
        case 'gif': return 'image/gif';
        case 'webp': return 'image/webp';
        case 'svg': return 'image/svg+xml';
        case 'avif': return 'image/avif';
        default: return 'image/jpeg';
    }
}

function ap_make_actor_array(string $username): array {
    $config = cms_config();
    $kp = ap_keypair();
    $url = ap_base_url() . "/users/$username";
    
    $actor = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id' => $url,
        'type' => 'Person',
        'preferredUsername' => $username,
        'name' => !empty($config['fediverse_name']) ? $config['fediverse_name'] : ($config['site_title'] ?? 'KutBlog'),
        'summary' => !empty($config['fediverse_bio']) ? $config['fediverse_bio'] : ($config['tagline'] ?? ''),
        'manuallyApprovesFollowers' => false,
        'url' => ap_base_url(),
        'inbox' => "$url/inbox",
        'outbox' => "$url/outbox",
        'followers' => "$url/followers",
        'publicKey' => [
            'id' => "$url#main-key",
            'owner' => $url,
            'publicKeyPem' => $kp['public_key'],
        ],
    ];

    if (!empty($config['fediverse_avatar'])) {
        $actor['icon'] = [
            'type' => 'Image',
            'mediaType' => ap_get_image_mime($config['fediverse_avatar']),
            'url' => $config['fediverse_avatar']
        ];
    }
    if (!empty($config['fediverse_cover'])) {
        $actor['image'] = [
            'type' => 'Image',
            'mediaType' => ap_get_image_mime($config['fediverse_cover']),
            'url' => $config['fediverse_cover']
        ];
    }

    return $actor;
}

function ap_endpoint_actor(string $username): void {
    ap_json(ap_make_actor_array($username));
}

// ---------------------------------------------------------------------------
// 3. Outbox
// ---------------------------------------------------------------------------
function ap_endpoint_outbox(string $username): void {
    $items = ap_q("SELECT * FROM ap_outbox ORDER BY id DESC LIMIT 40");
    $total = (int)(ap_one("SELECT count(*) c FROM ap_outbox")['c'] ?? 0);
    $url = ap_base_url() . "/users/$username/outbox";
    ap_json([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $url,
        'type' => 'OrderedCollection',
        'totalItems' => $total,
        'orderedItems' => array_map(fn($i) => json_decode($i['object_json'], true), $items),
    ]);
}

// ---------------------------------------------------------------------------
// 4. Followers
// ---------------------------------------------------------------------------
function ap_endpoint_followers(string $username): void {
    $rows = ap_q("SELECT actor_url FROM ap_followers");
    ap_json([
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => ap_base_url() . "/users/$username/followers",
        'type' => 'OrderedCollection',
        'totalItems' => count($rows),
        'orderedItems' => array_column($rows, 'actor_url'),
    ]);
}

// ---------------------------------------------------------------------------
// 5. Note Original
// ---------------------------------------------------------------------------
function ap_endpoint_note(string $username, string $slug): void {
    // Strip timestamps from resends
    $originalSlug = preg_replace('/-\d+$/', '', $slug);
    
    require_once ROOT_PATH . '/core/content.php';
    $post = get_content('articles', $originalSlug);
    
    $actor = ap_base_url() . "/users/$username";
    $noteId = "$actor/notes/$slug";

    // Si el artículo fue eliminado o ya no está publicado, responder con Tombstone 410 Gone (W3C ActivityPub)
    if (!$post || ($post['status'] ?? 'draft') !== 'published') {
        http_response_code(410);
        header('Content-Type: application/activity+json; charset=utf-8');
        echo json_encode([
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $noteId,
            'type'       => 'Tombstone',
            'formerType' => 'Note',
            'deleted'    => gmdate('Y-m-d\TH:i:s\Z')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $content = $post['content'] ?? '';
    // Format markdown if needed
    if (($post['content_format'] ?? '') === 'markdown' && function_exists('flux_markdown')) {
        $content = flux_markdown($content);
    }
    
    // Truncate for microblogging if long, or just strip HTML tags nicely
    $excerpt = strip_tags($post['excerpt'] ?? substr(strip_tags($content), 0, 400));
    
    $note = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $noteId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => ["$actor/followers"],
        'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($post['created_at'] ?? 'now')),
        'url' => ap_base_url() . "/article/{$post['slug']}",
        'content' => "<p><strong>" . htmlspecialchars($post['title']) . "</strong></p>"
                     . "<p>" . htmlspecialchars($excerpt) . "</p>"
                     . "<p><a href=\"" . ap_base_url() . "/article/{$post['slug']}\">Leer artículo completo</a></p>",
    ];
    ap_json($note);
}

// ---------------------------------------------------------------------------
// 6. Inbox (Receiver)
// ---------------------------------------------------------------------------
function ap_endpoint_inbox(string $username): void {
    $raw = file_get_contents('php://input');
    $a = json_decode($raw, true);
    if (!$a || empty($a['type'])) {
        http_response_code(400);
        exit;
    }

    ap_log("Inbox: Recibida actividad del tipo: " . $a['type'] . " de: " . ($a['actor'] ?? 'desconocido'));

    // Verify signature and actor ownership
    $verifiedActor = ap_verify_signature($raw);
    if (!$verifiedActor || !is_string($verifiedActor)) {
        // Safe delete cleanups for missing actors
        if ($a['type'] === 'Delete') {
            $actorUrl = $a['actor'] ?? '';
            $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
            if ($actorUrl && $obj === $actorUrl) {
                ap_exec("DELETE FROM ap_followers WHERE actor_url = ?", [$actorUrl]);
                http_response_code(202);
                exit;
            }
        }
        http_response_code(401);
        exit('Firma no válida');
    }

    $actor = $a['actor'] ?? '';
    if ($actor !== $verifiedActor) {
        http_response_code(403);
        exit('Actor no coincide con la clave firmante');
    }

    switch ($a['type']) {
        case 'Follow':
            $info = ap_fetch_actor($actor);
            if ($info) {
                $handle = $info['preferredUsername'] ?? 'usuario';
                $host = parse_url($actor, PHP_URL_HOST);
                $full_handle = "@{$handle}@{$host}";
                $name = $info['name'] ?? $handle;
                $avatar = $info['icon']['url'] ?? '';

                ap_exec("INSERT OR IGNORE INTO ap_followers (actor_url, handle, inbox, name, avatar) VALUES (?, ?, ?, ?, ?)",
                    [$actor, $full_handle, ($info['inbox'] ?? ''), $name, $avatar]);
                
                ap_send_accept($username, $a);
                ap_log("Inbox: Guardado seguidor: $full_handle");
            }
            break;

        case 'Undo':
            if (($a['object']['type'] ?? '') === 'Follow') {
                ap_exec("DELETE FROM ap_followers WHERE actor_url = ?", [$a['actor']]);
                ap_log("Inbox: Seguidor eliminado: " . $a['actor']);
            }
            break;

        case 'Like':
        case 'Announce':
            $objId = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
            if ($objId) {
                ap_exec("INSERT OR IGNORE INTO ap_interactions (note_id, actor_url, type) VALUES (?, ?, ?)",
                    [$objId, $actor, strtolower($a['type'])]);
            }
            break;

        case 'Create':
            if (($a['object']['type'] ?? '') === 'Note') {
                $note = $a['object'];
                $inReplyTo = $note['inReplyTo'] ?? '';
                
                // Identify target article
                $post_slug = '';
                $depth = 0;
                $in_reply_to_comment = '';
                
                $actor_url = ap_base_url() . "/users/" . $username;
                $note_prefix = $actor_url . "/notes/";

                if (strpos($inReplyTo, $note_prefix) === 0) {
                    $post_slug = substr($inReplyTo, strlen($note_prefix));
                } else {
                    // Thread replies
                    $parent = ap_one("SELECT * FROM ap_comments WHERE id = ?", [$inReplyTo]);
                    if ($parent) {
                        $post_slug = $parent['post_slug'];
                        $in_reply_to_comment = $parent['id'];
                        $depth = $parent['depth'] + 1;
                    }
                }

                if ($post_slug) {
                    $info = ap_fetch_actor($actor);
                    $author_name = $info['name'] ?? $info['preferredUsername'] ?? 'usuario';
                    $author_username = $info['preferredUsername'] ?? 'usuario';
                    $host = parse_url($actor, PHP_URL_HOST);
                    $full_username = "@{$author_username}@{$host}";
                    $author_avatar = $info['icon']['url'] ?? '';

                    $clean_content = ap_sanitize_comment_html((string)($note['content'] ?? ''));

                    ap_exec("INSERT OR REPLACE INTO ap_comments (id, post_slug, actor_url, author_name, author_username, author_avatar, content, published_at, in_reply_to, depth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                        $note['id'],
                        $post_slug,
                        $actor,
                        $author_name,
                        $full_username,
                        $author_avatar,
                        $clean_content,
                        $note['published'] ?? date('c'),
                        $in_reply_to_comment ?: null,
                        $depth
                    ]);
                    ap_log("Inbox: Nuevo comentario guardado en '$post_slug' por $full_username");

                    // Notify Admin via DM
                    ap_dm_admin($post_slug, $note['url'] ?? $note['id'], $full_username);
                }
            }
            break;

        case 'Delete':
            $obj = is_array($a['object'] ?? null) ? ($a['object']['id'] ?? '') : ($a['object'] ?? '');
            if ($obj === $a['actor']) {
                ap_exec("DELETE FROM ap_followers WHERE actor_url = ?", [$a['actor']]);
            } else {
                ap_exec("DELETE FROM ap_comments WHERE id = ?", [$obj]);
            }
            break;
    }

    http_response_code(202);
}

// ---------------------------------------------------------------------------
// 7. Publicar / Borrar Artículos
// ---------------------------------------------------------------------------
function ap_publish_article(array $post, bool $resend = false): void {
    if (!ap_is_enabled()) return;

    $username = cms_config()['fediverse_username'] ?? 'blog';
    $base = ap_base_url();
    $actor = "$base/users/$username";
    
    $slug = $post['slug'];
    $suffix = $resend ? '-' . time() : '';
    
    $noteId = "$actor/notes/{$slug}{$suffix}";
    $actId = "$actor/activities/{$slug}{$suffix}";

    // Check if Create activity for this article already exists to prevent duplicates
    if (!$resend) {
        $searchUrl = "$actor/notes/{$slug}";
        $existing = ap_one("SELECT id FROM ap_outbox WHERE activity_id LIKE ?", ["%activities/{$slug}%"]);
        if ($existing) {
            ap_log("Outbox: El artículo '$slug' ya está publicado. Omitiendo duplicado.");
            return;
        }
    }

    $content = $post['content'] ?? '';
    if (($post['content_format'] ?? '') === 'markdown' && function_exists('flux_markdown')) {
        $content = flux_markdown($content);
    }
    
    $excerpt = strip_tags($post['excerpt'] ?? substr(strip_tags($content), 0, 400));

    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => ["$actor/followers"],
        'published' => gmdate('Y-m-d\TH:i:s\Z', strtotime($post['created_at'] ?? 'now')),
        'url' => "$base/article/{$slug}",
        'content' => "<p><strong>" . htmlspecialchars($post['title']) . "</strong></p>"
                     . "<p>" . htmlspecialchars($excerpt) . "</p>"
                     . "<p><a href=\"$base/article/{$slug}\">Leer artículo completo</a></p>",
    ];

    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => $note['published'],
        'to' => $note['to'],
        'cc' => $note['cc'],
        'object' => $note,
    ];

    $oid = ap_exec("INSERT INTO ap_outbox (activity_id, type, object_json) VALUES (?, ?, ?)",
        [$actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    // Queue for all followers
    $followers = ap_q("SELECT DISTINCT inbox FROM ap_followers WHERE inbox != ''");
    $ins = ap_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
    foreach ($followers as $f) {
        $ins->execute([$oid, $f['inbox']]);
    }

    ap_log("Outbox: Artículo '$slug' publicado. Cola de envío iniciada.");

    // Trigger delivery queue worker asynchronously
    ap_trigger_delivery();
}

function ap_delete_article(string $slug): void {
    if (!ap_is_enabled()) return;

    $username = cms_config()['fediverse_username'] ?? 'blog';
    $base = ap_base_url();
    $actor = "$base/users/$username";
    
    // Obtener el noteId real de la actividad Create previa en el outbox
    $noteId = "$actor/notes/{$slug}";
    $existing = ap_one("SELECT object_json FROM ap_outbox WHERE type = 'Create' AND activity_id LIKE ? ORDER BY id DESC LIMIT 1", ["%$slug%"]);
    if ($existing) {
        $createData = json_decode($existing['object_json'], true);
        if (!empty($createData['object']['id'])) {
            $noteId = $createData['object']['id'];
        }
    }

    $actId = "$actor/activities/delete-" . bin2hex(random_bytes(8));

    // Estructura Delete estandarizada W3C / Mastodon con Tombstone
    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $actId,
        'type'     => 'Delete',
        'actor'    => $actor,
        'to'       => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'       => ["$actor/followers"],
        'object'   => [
            'id'   => $noteId,
            'type' => 'Tombstone'
        ]
    ];

    $oid = ap_exec("INSERT INTO ap_outbox (activity_id, type, object_json) VALUES (?, ?, ?)",
        [$actId, 'Delete', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    // Recopilar inboxes de seguidores y de instancias que interactuaron
    $inboxes = [];
    foreach (ap_q("SELECT DISTINCT inbox FROM ap_followers WHERE inbox != ''") as $f) {
        $inboxes[$f['inbox']] = true;
    }
    foreach (ap_q("SELECT DISTINCT actor_url FROM ap_interactions WHERE note_id = ? OR note_id LIKE ?", [$noteId, "%$slug%"]) as $i) {
        $u = parse_url($i['actor_url']);
        if (!empty($u['host']) && !empty($u['scheme'])) {
            $inboxes["{$u['scheme']}://{$u['host']}/inbox"] = true;
        }
    }

    $ins = ap_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
    foreach (array_keys($inboxes) as $inbox) {
        $ins->execute([$oid, $inbox]);
    }

    ap_log("Outbox: Envío de eliminación de '$slug' encolada para " . count($inboxes) . " destinos.");

    // Entrega inmediata sincrónica para no depender exclusivamente de cron en segundo plano
    if (function_exists('ap_deliver_pending') && !empty($inboxes)) {
        try {
            ap_deliver_pending(count($inboxes));
        } catch (Throwable $e) {
            ap_log("Entrega inmediata de Delete: " . $e->getMessage());
        }
    }

    ap_trigger_delivery();
}

function ap_update_actor(): void {
    if (!ap_is_enabled()) return;

    $username = cms_config()['fediverse_username'] ?? 'blog';
    $base = ap_base_url();
    $actorUrl = "$base/users/$username";
    
    $actId = "$actorUrl/activities/update-" . time();
    $actorObj = ap_make_actor_array($username);

    $activity = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id' => $actId,
        'type' => 'Update',
        'actor' => $actorUrl,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'object' => $actorObj,
    ];

    $oid = ap_exec("INSERT INTO ap_outbox (activity_id, type, object_json) VALUES (?, ?, ?)",
        [$actId, 'Update', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    // Queue for all followers
    $followers = ap_q("SELECT DISTINCT inbox FROM ap_followers WHERE inbox != ''");
    $ins = ap_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)");
    foreach ($followers as $f) {
        $ins->execute([$oid, $f['inbox']]);
    }

    ap_log("Outbox: Perfil de Fediverso actualizado. Actividad Update encolada.");
    ap_trigger_delivery();
}

function ap_trigger_delivery(): void {
    $script = ROOT_PATH . '/cli/ap-deliver.php';
    if (file_exists($script)) {
        // Execute background non-blocking task
        exec("php " . escapeshellarg($script) . " > /dev/null 2>&1 &");
    }
}

// ---------------------------------------------------------------------------
// 8. Signatures & HTTP Requests
// ---------------------------------------------------------------------------
function ap_post_signed(string $url, string $body): void {
    ap_log("Entrega: POST firmado a $url");
    $kp = ap_keypair();
    $u = parse_url($url);
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    
    $path = empty($u['path']) ? '/' : $u['path'];
    if (!empty($u['query'])) $path .= '?' . $u['query'];
    
    $signingString = "(request-target): post {$path}\nhost: {$u['host']}\ndate: $date\ndigest: $digest";
    openssl_sign($signingString, $sig, $kp['private_key'], OPENSSL_ALGO_SHA256);
    
    $username = cms_config()['fediverse_username'] ?? 'blog';
    $keyId = ap_base_url() . "/users/{$username}#main-key";
    
    $sigHeader = sprintf(
        'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="%s"',
        $keyId, base64_encode($sig)
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            "Host: {$u['host']}",
            "Date: $date",
            "Digest: $digest",
            "Signature: $sigHeader",
            "Content-Type: application/activity+json",
            "Accept: application/activity+json",
        ],
        CURLOPT_USERAGENT => 'KutBlog/1.0 (+' . ap_base_url() . ')',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FAILONERROR => true,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($http >= 400 || $resp === false) {
        ap_log("Entrega fallida a $url: HTTP $http $err.");
        throw new RuntimeException("HTTP $http $err");
    }
    ap_log("Entrega exitosa a $url: HTTP $http");
}

function ap_verify_signature(string $body): bool {
    $sig = $_SERVER['HTTP_SIGNATURE'] ?? '';
    if (!$sig && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'signature') {
                $sig = $v;
                break;
            }
        }
    }
    if (!$sig) {
        ap_log("Firma fallida: Cabecera 'Signature' no encontrada.");
        return false;
    }
    
    $parts = [];
    foreach (explode(',', $sig) as $kv) {
        if (preg_match('/(\w+)="([^"]*)"/', trim($kv), $m)) $parts[$m[1]] = $m[2];
    }
    if (empty($parts['keyId']) || empty($parts['signature']) || empty($parts['headers'])) {
        ap_log("Firma fallida: Componentes incompletos en la cabecera.");
        return false;
    }

    $actorUrl = preg_replace('/#.*/', '', $parts['keyId']);
    $actor = ap_fetch_actor($actorUrl);
    if (empty($actor) || empty($actor['publicKey']['publicKeyPem'])) {
        ap_log("Firma fallida: No se pudo obtener la clave pública del actor.");
        return false;
    }

    $signingString = [];
    foreach (explode(' ', $parts['headers']) as $h) {
        if ($h === '(request-target)') {
            $method = strtolower($_SERVER['REQUEST_METHOD']);
            $signingString[] = "(request-target): $method " . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        } else {
            $val = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '';
            if (!$val && function_exists('getallheaders')) {
                $headers = getallheaders();
                foreach ($headers as $k => $v) {
                    if (strtolower($k) === strtolower($h)) {
                        $val = $v;
                        break;
                    }
                }
            }
            $signingString[] = "$h: $val";
        }
    }

    $ok = openssl_verify(implode("\n", $signingString), base64_decode($parts['signature']),
        $actor['publicKey']['publicKeyPem'], OPENSSL_ALGO_SHA256);
        
    if ($ok !== 1) {
        ap_log("Firma fallida: openssl_verify devolvió $ok.");
        return false;
    }

    $digestHeader = $_SERVER['HTTP_DIGEST'] ?? '';
    if ($digestHeader !== '') {
        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $rawBody, true));
        if (!hash_equals($expectedDigest, trim($digestHeader))) {
            ap_log("Firma fallida: Digest no coincide.");
            return false;
        }
    }

    $dateHeader = $_SERVER['HTTP_DATE'] ?? '';
    if ($dateHeader !== '') {
        $ts = strtotime($dateHeader);
        if ($ts === false || abs(time() - $ts) > 300) {
            ap_log("Firma fallida: Date expirado o inválido.");
            return false;
        }
    }

    return $actor['id'] ?? $actorUrl;
}

function ap_sanitize_comment_html(string $html): string {
    $html = trim($html);
    if ($html === '' || !class_exists('DOMDocument')) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $allowedTags = ['p', 'br', 'a', 'span', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre'];
    $dropCompletely = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'svg', 'math'];

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="ap-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $dropCompletely): void {
        if (!$node->hasChildNodes()) {
            return;
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->localName ?? $child->nodeName);
                if (in_array($tag, $dropCompletely, true)) {
                    $node->removeChild($child);
                    continue;
                }
                $sanitizeNode($child);
                if (!in_array($tag, $allowedTags, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                // Strip all attributes; on <a> keep only https?:// href
                $hrefVal = null;
                if ($tag === 'a' && $child->hasAttribute('href')) {
                    $rawHref = trim($child->getAttribute('href'));
                    if (preg_match('#^https?://#i', $rawHref)) {
                        $hrefVal = $rawHref;
                    }
                }
                $attrNames = [];
                foreach ($child->attributes as $attr) {
                    $attrNames[] = $attr->nodeName;
                }
                foreach ($attrNames as $name) {
                    $child->removeAttribute($name);
                }
                if ($tag === 'a' && $hrefVal !== null) {
                    $child->setAttribute('href', $hrefVal);
                    $child->setAttribute('rel', 'nofollow noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                $node->removeChild($child);
            }
        }
    };

    $root = $dom->getElementById('ap-root') ?: $dom->documentElement;
    if (!$root) {
        return '';
    }
    $sanitizeNode($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}

function ap_fetch_actor(string $url): ?array {
    if (!function_exists('cms_is_safe_remote_url') || !cms_is_safe_remote_url($url)) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/activity+json'],
        CURLOPT_USERAGENT => 'KutBlog/1.0 (+' . ap_base_url() . ')',
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

function ap_resolve_webfinger(string $acct): ?array {
    $acct = ltrim(trim($acct), '@');
    $parts = explode('@', $acct);
    if (count($parts) !== 2) return null;
    $host = $parts[1];
    $url = "https://$host/.well-known/webfinger?resource=acct:$acct";
    if (function_exists('cms_is_safe_remote_url') && !cms_is_safe_remote_url($url)) return null;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/jrd+json, application/json'],
        CURLOPT_USERAGENT => 'KutBlog/1.0 (+' . ap_base_url() . ')',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 10,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    $res = $r ? json_decode($r, true) : null;
    
    if (!$res || empty($res['links'])) return null;
    foreach ($res['links'] as $l) {
        if ($l['rel'] === 'self' && $l['type'] === 'application/activity+json') {
            return ap_fetch_actor($l['href']);
        }
    }
    return null;
}

function ap_send_accept(string $username, array $follow): void {
    $base = ap_base_url();
    $actor = "$base/users/$username";
    $accept = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => "$actor/accepts/" . bin2hex(random_bytes(8)),
        'type' => 'Accept',
        'actor' => $actor,
        'object' => $follow,
    ];
    $inbox = ap_fetch_actor($follow['actor'])['inbox'] ?? null;
    if ($inbox) {
        try {
            $oid = ap_exec("INSERT INTO ap_outbox (activity_id, type, object_json) VALUES (?, ?, ?)",
                [$accept['id'], 'Accept', json_encode($accept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            ap_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $inbox]);
        } catch (Throwable $e) {}
    }
}

// ---------------------------------------------------------------------------
// 9. Admin DM Notifications
// ---------------------------------------------------------------------------
function ap_dm_admin(string $post_slug, string $commentUrl, string $authorHandle): void {
    $config = cms_config();
    $adminActor = $config['admin_mastodon_actor'] ?? '';
    $adminInbox = $config['admin_mastodon_inbox'] ?? '';
    if (!$adminActor || !$adminInbox) return;

    $username = $config['fediverse_username'] ?? 'blog';
    $base = ap_base_url();
    $actor = "$base/users/$username";
    
    $noteId = "$actor/notes/dm_" . bin2hex(random_bytes(8));
    $actId = "$actor/activities/dm_" . bin2hex(random_bytes(8));

    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'attributedTo' => $actor,
        'to' => [$adminActor], // direct message to admin
        'cc' => [],
        'published' => gmdate('c'),
        'content' => "<p>Tienes un nuevo comentario de <strong>@{$authorHandle}</strong> en tu artículo <strong>$post_slug</strong>.</p><p><a href=\"{$commentUrl}\">Ver comentario original</a></p>",
        'tag' => [
            [
                'type' => 'Mention',
                'href' => $adminActor,
                'name' => '@admin'
            ]
        ]
    ];

    $activity = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $actId,
        'type' => 'Create',
        'actor' => $actor,
        'published' => $note['published'],
        'to' => $note['to'],
        'cc' => $note['cc'],
        'object' => $note,
    ];

    try {
        $oid = ap_exec("INSERT INTO ap_outbox (activity_id, type, object_json) VALUES (?, ?, ?)",
            [$actId, 'Create', json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        ap_db()->prepare("INSERT INTO ap_delivery (outbox_id, inbox) VALUES (?, ?)")->execute([$oid, $adminInbox]);
        ap_trigger_delivery();
    } catch (Throwable $e) {}
}

// ---------------------------------------------------------------------------
// 10. Delivery Worker Queue
// ---------------------------------------------------------------------------
function ap_deliver_pending(int $batch = 50): int {
    $rows = ap_q("SELECT * FROM ap_delivery 
                  WHERE status = 'pending' AND next_try <= datetime('now', '+1 minute')
                  ORDER BY id LIMIT ?", [$batch]);
    $sent = 0;
    foreach ($rows as $d) {
        try {
            $outbox = ap_one("SELECT object_json FROM ap_outbox WHERE id = ?", [$d['outbox_id']]);
            if (!$outbox) {
                ap_db()->prepare("UPDATE ap_delivery SET status='failed', last_error='Outbox item missing' WHERE id = ?")->execute([$d['id']]);
                continue;
            }

            ap_post_signed($d['inbox'], $outbox['object_json']);
            ap_db()->prepare("UPDATE ap_delivery SET status='delivered', delivered_at=datetime('now') WHERE id = ?")
                  ->execute([$d['id']]);
            $sent++;
        } catch (Throwable $e) {
            $attempts = $d['attempts'] + 1;
            $backoff = min(86400, pow(2, $attempts) * 60); // up to 1 day
            $next = gmdate('Y-m-d H:i:s', time() + $backoff);
            $status = $attempts >= 8 ? 'failed' : 'pending';
            ap_db()->prepare("UPDATE ap_delivery SET attempts=?, last_error=?, next_try=?, status=? WHERE id = ?")
                  ->execute([$attempts, $e->getMessage(), $next, $status, $d['id']]);
        }
    }
    return $sent;
}

// ---------------------------------------------------------------------------
// 11. Comment Extraction helpers
// ---------------------------------------------------------------------------
function ap_get_comments_for_slug(string $slug): array {
    $rows = ap_q("SELECT * FROM ap_comments WHERE post_slug = ? ORDER BY published_at ASC", [$slug]);
    $comments = [];
    foreach ($rows as $r) {
        // Find depth by tracing parents
        $depth = $r['depth'];
        
        $comments[] = [
            'id'           => $r['id'],
            'url'          => $r['id'], // default to id
            'created_at'   => $r['published_at'],
            'content'      => $r['content'],
            'in_reply_to'  => $r['in_reply_to'],
            'depth'        => $depth,
            'author'       => [
                'name'     => $r['author_name'],
                'username' => $r['author_username'],
                'avatar'   => $r['author_avatar'],
                'url'      => $r['actor_url'],
            ],
            'favourites'   => 0, // not tracked per-comment
            'reblogs'      => 0,
        ];
    }
    return $comments;
}

function ap_get_note_stats(string $slug): array {
    $noteId = ap_base_url() . "/users/" . (cms_config()['fediverse_username'] ?? 'blog') . "/notes/" . $slug;
    $likes = (int)(ap_one("SELECT count(*) c FROM ap_interactions WHERE note_id = ? AND type = 'like'", [$noteId])['c'] ?? 0);
    $boosts = (int)(ap_one("SELECT count(*) c FROM ap_interactions WHERE note_id = ? AND type = 'announce'", [$noteId])['c'] ?? 0);
    return ['favourites' => $likes, 'reblogs' => $boosts];
}

function ap_get_post_delivery_status(string $slug): ?array {
    if (!ap_is_enabled()) return null;
    
    // Find the latest outbox item related to this slug
    $row = ap_one("SELECT id, activity_id, published FROM ap_outbox WHERE activity_id LIKE ? ORDER BY id DESC LIMIT 1", ["%/activities/{$slug}%"]);
    if (!$row) return null;
    
    $outbox_id = $row['id'];
    $stats = ap_one("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM ap_delivery 
        WHERE outbox_id = ?
    ", [$outbox_id]);
    
    return [
        'published' => $row['published'],
        'total' => (int)($stats['total'] ?? 0),
        'delivered' => (int)($stats['delivered'] ?? 0),
        'failed' => (int)($stats['failed'] ?? 0),
        'pending' => (int)($stats['pending'] ?? 0),
    ];
}

function ap_update_slug(string $old_slug, string $new_slug): void {
    ap_exec("UPDATE ap_comments SET post_slug = ? WHERE post_slug = ?", [$new_slug, $old_slug]);
}

function ap_log(string $msg): void {
    $file = ROOT_PATH . '/cache/activitypub.log';
    $date = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$date] $msg\n", FILE_APPEND);
}
