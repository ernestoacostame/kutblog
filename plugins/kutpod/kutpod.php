<?php
/**
 * KutPod Plugin for KutBlog
 * Main Bootstrapper and Extension Manager
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}
if (!defined('CONTENT_PATH')) {
    define('CONTENT_PATH', ROOT_PATH . '/content');
}

// ── Configuración del Plugin ──────────────────────────────────────────────────
function kutpod_config_file(): string {
    return CONTENT_PATH . '/kutpod_config.json';
}

function kutpod_config(bool $fresh = false): array {
    static $cached = null;
    if (!$fresh && $cached !== null) {
        return $cached;
    }

    $defaults = [
        'connection_mode'        => 'local', // 'local' (SQLite) o 'remote' (API)
        'instance_url'           => 'http://localhost:8000',
        'local_db_path'          => '/home/elav/Developer/KutStudio/kutpod/storage/kutpod.db',
        'webhook_secret'         => '',
        'podcast_filter'         => 'all', // 'all' o slug específico del show
        'categorize_by_show'     => true,  // Asigna el nombre del show como categoría
        'default_status'         => 'draft', // 'draft' o 'published'
        'default_category'       => 'Podcast',
        'auto_update_notes'      => true,
        'delete_on_kutpod_delete'=> false,
        'player_position'        => 'top', // 'top', 'bottom', 'manual'
        'player_theme'           => 'auto', // 'auto', 'dark', 'light'
        'enable_timestamps'      => true,
        'enable_transcripts'     => true,
        'enable_stats_badge'     => true,
        'enable_directory'       => true,
        'directory_slug'         => 'podcasts',
        'directory_title'        => 'Episodios y Podcasts',
        'directory_desc'         => 'Explora todos los episodios de nuestro podcast, escucha en el navegador y accede a notas y transcripciones completas.',
        'enable_related'         => true,
        'search_transcripts'     => true
    ];

    $file = kutpod_config_file();
    if (!file_exists($file)) {
        // Generar token secreto si es la primera vez
        $defaults['webhook_secret'] = bin2hex(random_bytes(16));
        kutpod_save_config($defaults);
        $cached = $defaults;
        return $cached;
    }

    $data = json_decode((string)@file_get_contents($file), true) ?? [];
    $cached = array_merge($defaults, $data);
    return $cached;
}

function kutpod_save_config(array $data): void {
    $file = kutpod_config_file();
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    kutpod_config(true);
}

// ── Parser Universal de URLs de KutPod ─────────────────────────────────────────
/**
 * Parsea cualquier formato de URL de KutPod (pública, embed, tracker o API)
 * y extrae base_url, podcast_slug (sin @), episode_slug y episode_id.
 */
function kutpod_parse_episode_url(string $url): ?array {
    $url = trim($url);
    if (empty($url)) return null;

    // Limpiar caracteres circundantes de enlaces Markdown o etiquetas HTML
    $url = trim($url, " \t\n\r\0\x0B<>\"'()");

    $parts = @parse_url($url);
    if (!$parts || empty($parts['path'])) return null;

    $base = '';
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        $base = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port']) && !in_array((int)$parts['port'], [80, 443])) {
            $base .= ':' . $parts['port'];
        }
    }

    $path = trim($parts['path'], '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $podcastSlug = '';
    $episodeSlug = '';
    $episodeId = 0;

    // 1. Parámetros Query (?id=42 o ?show=flash&ep=slug)
    if (!empty($query['id']) && is_numeric($query['id'])) {
        $episodeId = (int)$query['id'];
    }
    if (!empty($query['show'])) {
        $podcastSlug = ltrim($query['show'], '@');
    }
    if (!empty($query['ep'])) {
        $episodeSlug = $query['ep'];
    }

    // 2. Coincidencias en el Path
    if (!$episodeSlug && !$episodeId) {
        // Embed con show: embed/@show/ep o embed/show/ep
        if (preg_match('#^embed/@?([a-z0-9\-]+)/([a-z0-9\-]+)#i', $path, $m)) {
            $podcastSlug = $m[1];
            $episodeSlug = $m[2];
        }
        // Embed de un solo slug o ID: embed/123 o embed/mi-ep
        elseif (preg_match('#^embed/([a-z0-9\-]+)#i', $path, $m)) {
            if (is_numeric($m[1])) {
                $episodeId = (int)$m[1];
            } else {
                $episodeSlug = $m[1];
            }
        }
        // Tracker URL de OP3: r/show/ep o r/show/ep/audio.mp3
        elseif (preg_match('#^r/([a-z0-9\-]+)/([a-z0-9\-]+)#i', $path, $m)) {
            $podcastSlug = $m[1];
            $episodeSlug = $m[2];
        }
        // Episodio público con show: @show/episodes/ep o @show/ep o shows/show/ep
        elseif (preg_match('#^(?:shows/|@)([a-z0-9\-]+)/(?:episodes/)?([a-z0-9\-]+)#i', $path, $m)) {
            $ignored = ['episodes', 'admin', 'api', 'install', 'p', 'site.php', 'index.php', 'feed', 'users', 'r', 'media', 'uploads', 'assets', 'themes', 'content', 'category', 'tag', 'article', 'articles', 'page', 'pages'];
            if (!in_array(strtolower($m[1]), $ignored)) {
                $podcastSlug = $m[1];
                $episodeSlug = $m[2];
            }
        }
        // Episodio directo: episodes/ep-slug o episode/ep-slug
        elseif (preg_match('#^(?:episodes|episode)/([a-z0-9\-]+)#i', $path, $m)) {
            $episodeSlug = $m[1];
        }
    }

    if (!$episodeSlug && !$episodeId) {
        return null;
    }

    return [
        'base_url'     => $base,
        'podcast_slug' => $podcastSlug,
        'episode_slug' => $episodeSlug,
        'episode_id'   => $episodeId,
        'raw_url'      => $url,
    ];
}

/**
 * Resuelve la URL de la portada para que sea absoluta y accesible desde cualquier navegador
 */
if (!function_exists('kutpod_resolve_cover_url')) {
    function kutpod_resolve_cover_url(?string $cover, array $cfg, array $ep = []): string {
        if (empty($cover)) return '';
        $cover = trim($cover);
        if (preg_match('#^https?://#i', $cover)) {
            return $cover;
        }
        $base = '';
        if (!empty($ep['instance_url'])) {
            $base = rtrim($ep['instance_url'], '/');
        } elseif (!empty($cfg['instance_url'])) {
            $base = rtrim($cfg['instance_url'], '/');
        } elseif (!empty($ep['audio_url']) && preg_match('#^https?://#i', $ep['audio_url'])) {
            $parts = parse_url($ep['audio_url']);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $base = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) && !in_array((int)$parts['port'], [80, 443]) ? ':' . $parts['port'] : '');
            }
        }
        if (!$base) {
            $base = 'http://localhost:8000';
        }
        return $base . (str_starts_with($cover, '/') ? '' : '/') . $cover;
    }
}

/**
 * Obtiene la ruta al archivo de base de datos SQLite de KutPod resolviendo variaciones comunes
 */
function kutpod_db_path(): ?string {
    $cfg = kutpod_config();
    $candidates = [];
    if (!empty($cfg['local_db_path'])) {
        $candidates[] = $cfg['local_db_path'];
        // Variaciones de mayúsculas/minúsculas para Linux
        $candidates[] = str_ireplace('/kutpod/storage/', '/KutPod/storage/', $cfg['local_db_path']);
        $candidates[] = str_ireplace('/KutPod/storage/', '/kutpod/storage/', $cfg['local_db_path']);
    }
    // Ubicaciones estándar en la estructura KutStudio
    $candidates[] = ROOT_PATH . '/../KutPod/storage/kutpod.db';
    $candidates[] = ROOT_PATH . '/../kutpod/storage/kutpod.db';
    $candidates[] = dirname(ROOT_PATH) . '/KutPod/storage/kutpod.db';
    $candidates[] = dirname(ROOT_PATH) . '/kutpod/storage/kutpod.db';

    foreach ($candidates as $cand) {
        if (!empty($cand) && file_exists($cand) && is_readable($cand) && filesize($cand) > 0) {
            return $cand;
        }
    }
    foreach ($candidates as $cand) {
        if (!empty($cand) && file_exists($cand) && is_readable($cand)) {
            return $cand;
        }
    }
    return null;
}

/**
 * Conexión opcional a la base de datos local SQLite de KutPod
 */
function kutpod_db(): ?PDO {
    static $pdo = null;
    static $attempted = false;
    if ($attempted) return $pdo;
    $attempted = true;

    if (!extension_loaded('pdo_sqlite')) {
        return null;
    }

    $dbPath = kutpod_db_path();
    if (!$dbPath) {
        return null;
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_SILENT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 2,
        ]);
        return $pdo;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Consulta un episodio en la BD local de KutPod si está disponible
 */
function kutpod_lookup_episode(?string $podcastSlug, ?string $episodeSlug, int $episodeId = 0): ?array {
    $db = kutpod_db();
    if (!$db) return null;

    try {
        if ($episodeId > 0) {
            $stmt = $db->prepare("
                SELECT e.*, p.slug as podcast_slug, p.title as podcast_title, p.cover_image as podcast_cover
                FROM episodes e
                JOIN podcasts p ON e.podcast_id = p.id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stmt->execute([$episodeId]);
            $r = $stmt->fetch();
            if ($r) return $r;
        }

        if ($podcastSlug && $episodeSlug) {
            $stmt = $db->prepare("
                SELECT e.*, p.slug as podcast_slug, p.title as podcast_title, p.cover_image as podcast_cover
                FROM episodes e
                JOIN podcasts p ON e.podcast_id = p.id
                WHERE p.slug = ? AND e.slug = ?
                LIMIT 1
            ");
            $stmt->execute([$podcastSlug, $episodeSlug]);
            $r = $stmt->fetch();
            if ($r) return $r;
        }

        if ($episodeSlug) {
            $stmt = $db->prepare("
                SELECT e.*, p.slug as podcast_slug, p.title as podcast_title, p.cover_image as podcast_cover
                FROM episodes e
                JOIN podcasts p ON e.podcast_id = p.id
                WHERE e.slug = ?
                LIMIT 1
            ");
            $stmt->execute([$episodeSlug]);
            $r = $stmt->fetch();
            if ($r) return $r;
        }
    } catch (\Throwable $e) {}

    return null;
}

/**
 * Resuelve y enriquece metadatos a partir de una URL de KutPod
 */
function kutpod_resolve_meta_from_url(string $url, array $existingMeta = []): array {
    $parsed = kutpod_parse_episode_url($url);
    if (!$parsed) return $existingMeta;

    $meta = $existingMeta;
    $meta['is_podcast'] = true;
    $meta['source_url'] = $parsed['raw_url'];

    if (!empty($parsed['podcast_slug'])) {
        $meta['podcast_slug'] = $parsed['podcast_slug'];
    }
    if (!empty($parsed['episode_slug'])) {
        $meta['episode_slug'] = $parsed['episode_slug'];
    }
    if (!empty($parsed['episode_id'])) {
        $meta['episode_id'] = $parsed['episode_id'];
    }

    $dbEp = kutpod_lookup_episode($meta['podcast_slug'] ?? '', $meta['episode_slug'] ?? '', $meta['episode_id'] ?? 0);
    if ($dbEp) {
        $meta['podcast_slug']  = $dbEp['podcast_slug'] ?? ($meta['podcast_slug'] ?? '');
        $meta['podcast_title'] = $dbEp['podcast_title'] ?? ($meta['podcast_title'] ?? '');
        $meta['episode_slug']  = $dbEp['slug'] ?? ($meta['episode_slug'] ?? '');
        $meta['episode_id']    = (int)($dbEp['id'] ?? 0);
        $meta['duration_secs'] = (int)($dbEp['duration_secs'] ?? 0);
        $meta['duration_fmt']  = $dbEp['duration_fmt'] ?? ($meta['duration_secs'] ? gmdate('H:i:s', $meta['duration_secs']) : '');
        $meta['season']        = (int)($dbEp['season'] ?? 0);
        $meta['number']        = (int)($dbEp['number'] ?? 0);
        $meta['downloads']     = (int)($dbEp['downloads_op3'] ?? 0);
        if (!empty($dbEp['audio_url'])) {
            $meta['audio_url'] = $dbEp['audio_url'];
        }
        if (empty($meta['transcript']) && !empty($dbEp['transcript'])) {
            $meta['transcript'] = $dbEp['transcript'];
        }
    }

    return $meta;
}

// ── Enrutador de peticiones del Plugin ────────────────────────────────────────
function kutpod_route_request(string $path, array $segments): bool {
    $cfg = kutpod_config();

    // 1. Webhook receptor
    if ($path === 'api/kutpod/webhook' || $path === 'api/kutpod-webhook') {
        require __DIR__ . '/webhook.php';
        return true;
    }

    // 2. Sincronización manual / API
    if ($path === 'api/kutpod/sync') {
        require_once __DIR__ . '/sync.php';
        $stats = kutpod_run_sync();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($stats);
        return true;
    }

    // 3. Servir assets estáticos del plugin con cabeceras correctas
    if ($path === 'plugins/kutpod/assets/kutpod-blog.css') {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        readfile(__DIR__ . '/assets/kutpod-blog.css');
        return true;
    }
    if ($path === 'plugins/kutpod/assets/kutpod-blog.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        readfile(__DIR__ . '/assets/kutpod-blog.js');
        return true;
    }

    // 4. Directorio público de episodios (/podcasts o /episodios)
    $dirSlug = $cfg['directory_slug'] ?: 'podcasts';
    if ($cfg['enable_directory'] && ($path === $dirSlug || $path === 'podcasts' || $path === 'episodios')) {
        $header_file = theme_file('header.php');
        if (file_exists($header_file)) include $header_file;
        kutpod_theme_header('directory', []);
        require __DIR__ . '/templates/directory.php';
        kutpod_theme_footer('directory', []);
        $footer_file = theme_file('footer.php');
        if (file_exists($footer_file)) include $footer_file;
        return true;
    }

    return false;
}

// ── Filtro de contenido para artículos ─────────────────────────────────────────
function kutpod_filter_content(string $content, array $post): string {
    $cfg = kutpod_config();
    $meta = $post['podcast_meta'] ?? [];

    // 1. Detectar si hay una URL en kutpod_url o en el cuerpo
    $parsed = null;
    $foundUrl = '';

    if (!empty($post['kutpod_url'])) {
        $parsed = kutpod_parse_episode_url($post['kutpod_url']);
        if ($parsed) $foundUrl = $post['kutpod_url'];
    }

    if (!$parsed) {
        // Shortcode [kutpod url="..."]
        if (preg_match('/\[kutpod\s+url=["\']([^"\']+)["\']\]/i', $content, $sm)) {
            $parsed = kutpod_parse_episode_url($sm[1]);
            if ($parsed) $foundUrl = $sm[0];
        }
        // Buscar cualquier URL de KutPod en el texto
        elseif (preg_match_all('#https?://[^\s"\'<>()]+#i', $content, $allUrls)) {
            $instHost = parse_url($cfg['instance_url'] ?? '', PHP_URL_HOST);
            foreach ($allUrls[0] as $u) {
                $uHost = parse_url($u, PHP_URL_HOST);
                // Solo analizar si la URL coincide con la instancia de KutPod o contiene 'kutpod' / 'op3.dev'
                if ($instHost && strcasecmp($uHost ?? '', $instHost) !== 0 && !str_contains($u, 'kutpod') && !str_contains($u, 'op3.dev')) {
                    continue;
                }
                $pu = kutpod_parse_episode_url($u);
                if ($pu) {
                    $parsed = $pu;
                    $foundUrl = $u;
                    break;
                }
            }
        }
    }

    $isPodcast = !empty($meta['is_podcast']) || !empty($meta['audio_url']) || !empty($parsed);

    // 1. Caso Artículo de Podcast
    if ($isPodcast) {
        // Enriquecer $meta con los datos parseados si faltan
        if ($parsed) {
            if (empty($meta['podcast_slug']) && !empty($parsed['podcast_slug'])) {
                $meta['podcast_slug'] = $parsed['podcast_slug'];
            }
            if (empty($meta['episode_slug']) && !empty($parsed['episode_slug'])) {
                $meta['episode_slug'] = $parsed['episode_slug'];
            }
            if (empty($meta['episode_id']) && !empty($parsed['episode_id'])) {
                $meta['episode_id'] = $parsed['episode_id'];
            }
        }

        // Si tenemos BD local, podemos rellenar estadísticas/slugs faltantes
        if (!empty($meta['episode_slug']) || !empty($meta['episode_id'])) {
            $dbEp = kutpod_lookup_episode($meta['podcast_slug'] ?? '', $meta['episode_slug'] ?? '', $meta['episode_id'] ?? 0);
            if ($dbEp) {
                if (empty($meta['podcast_slug'])) $meta['podcast_slug'] = $dbEp['podcast_slug'];
                if (empty($meta['episode_slug'])) $meta['episode_slug'] = $dbEp['slug'];
                if (empty($meta['downloads']) && !empty($dbEp['downloads_op3'])) $meta['downloads'] = (int)$dbEp['downloads_op3'];
                if (empty($meta['duration_secs']) && !empty($dbEp['duration_secs'])) $meta['duration_secs'] = (int)$dbEp['duration_secs'];
                if (empty($meta['duration_fmt']) && !empty($dbEp['duration_fmt'])) $meta['duration_fmt'] = $dbEp['duration_fmt'];
                if (empty($meta['season']) && !empty($dbEp['season'])) $meta['season'] = (int)$dbEp['season'];
                if (empty($meta['number']) && !empty($dbEp['number'])) $meta['number'] = (int)$dbEp['number'];
                if (empty($meta['transcript']) && !empty($dbEp['transcript'])) $meta['transcript'] = $dbEp['transcript'];
                if (empty($meta['podcast_title']) && !empty($dbEp['podcast_title'])) $meta['podcast_title'] = $dbEp['podcast_title'];
            }
        }

        // Determinar host/base de KutPod (priorizar la de la URL ingresada si es válida)
        $instanceUrl = (!empty($parsed['base_url']))
            ? rtrim($parsed['base_url'], '/')
            : rtrim($cfg['instance_url'] ?: 'http://localhost:8000', '/');

        $pSlug  = trim($meta['podcast_slug'] ?? ($parsed['podcast_slug'] ?? ''));
        $epSlug = trim($meta['episode_slug'] ?? ($parsed['episode_slug'] ?? ''));
        $epId   = (int)($meta['episode_id'] ?? ($parsed['episode_id'] ?? 0));
        $theme  = $cfg['player_theme'] ?? 'auto';

        // Si todavía no tenemos epSlug ni epId, intentar usar el slug del post como último recurso
        if (!$epSlug && !$epId && !empty($post['slug'])) {
            $epSlug = $post['slug'];
        }

        // Construir embedSrc correcto sin forzar @default
        $embedSrc = '';
        if ($epId > 0) {
            $embedSrc = $instanceUrl . "/embed/" . $epId . "?theme=" . urlencode($theme);
        } elseif ($pSlug && $epSlug) {
            $embedSrc = $instanceUrl . "/embed/@" . rawurlencode($pSlug) . "/" . rawurlencode($epSlug) . "?theme=" . urlencode($theme);
        } elseif ($epSlug) {
            $embedSrc = $instanceUrl . "/embed/" . rawurlencode($epSlug) . "?theme=" . urlencode($theme);
        }

        if (!$embedSrc) {
            return $content;
        }

        // Construir barra de estadísticas
        $statsBar = '';
        if (!empty($cfg['enable_stats_badge'])) {
            $downloads = (int)($meta['downloads'] ?? 0);
            $durFmt = $meta['duration_fmt'] ?? (!empty($meta['duration_secs']) ? gmdate('H:i:s', $meta['duration_secs']) : '');
            $season = (int)($meta['season'] ?? 0);
            $number = (int)($meta['number'] ?? 0);

            $statsBar = '<div class="kutpod-stats-bar">';
            if ($season > 0 && $number > 0) {
                $statsBar .= '<span class="kutpod-stat-pill">🎙️ T' . $season . ' · Ep ' . $number . '</span>';
            } elseif (!empty($meta['podcast_title'])) {
                $statsBar .= '<span class="kutpod-stat-pill">🎙️ ' . htmlspecialchars($meta['podcast_title']) . '</span>';
            }
            if ($durFmt) {
                $statsBar .= '<span class="kutpod-stat-pill">⏱️ ' . htmlspecialchars($durFmt) . '</span>';
            }
            if ($downloads > 0) {
                $popularClass = ($downloads > 500) ? ' kutpod-stat-popular' : '';
                $statsBar .= '<span class="kutpod-stat-pill' . $popularClass . '">🎧 ' . number_format($downloads) . ' escuchas</span>';
            }
            $statsBar .= '</div>';
        }

        // Construir HTML del reproductor
        $playerHtml = '
        <div class="kutpod-player-container">
          <iframe id="kutpod-player-frame" src="' . htmlspecialchars($embedSrc) . '" class="kutpod-player-frame" style="width:100%; height:175px; min-height:175px; border:none;" allow="encrypted-media; fullscreen" loading="lazy"></iframe>
          ' . $statsBar . '
        </div>';

        // Procesar Timestamps Interactivos en el texto
        if (!empty($cfg['enable_timestamps'])) {
            $content = preg_replace_callback(
                '/(?:^|[\(\[\s])(\d{1,2}:\d{2}(?::\d{2})?)(?:[\)\]\s])\s*([-–—:]?\s*)([^\n<]+)/u',
                function($m) {
                    $timeStr = $m[1];
                    $sep = $m[2];
                    $title = $m[3];

                    $parts = array_map('intval', explode(':', $timeStr));
                    $secs = (count($parts) === 3) ? ($parts[0] * 3600 + $parts[1] * 60 + $parts[2]) : ($parts[0] * 60 + $parts[1]);

                    return ' <button type="button" class="kp-timestamp" data-time="' . $secs . '" title="Saltar al minuto ' . htmlspecialchars($timeStr) . '">'
                         . '<svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg> '
                         . htmlspecialchars($timeStr) . '</button> ' . $sep . $title;
                },
                $content
            );
        }

        // Transcripción colapsable (SEO)
        $transcriptHtml = '';
        if (!empty($cfg['enable_transcripts']) && !empty($meta['transcript'])) {
            $transcriptHtml = '
            <details class="kutpod-transcript-details">
              <summary class="kutpod-transcript-summary">
                <span>📝 Transcripción completa del episodio</span>
                <span style="font-size: 0.8rem; opacity: 0.7;">Click para expandir</span>
              </summary>
              <div class="kutpod-transcript-body">' . htmlspecialchars($meta['transcript']) . '</div>
            </details>';
        }

        // Limpiar la URL raw del contenido si el usuario la pegó sola en un párrafo o línea
        if (!empty($foundUrl) && empty($post['kutpod_url'])) {
            $content = preg_replace('#<p>\s*' . preg_quote($foundUrl, '#') . '\s*</p>#i', '', $content);
            $content = preg_replace('#^\s*' . preg_quote($foundUrl, '#') . '\s*$#m', '', $content);
        }

        // Posicionar el reproductor
        $pos = $cfg['player_position'] ?? 'top';
        if ($pos === 'manual' && str_contains($content, '[kutpod]')) {
            $content = str_replace('[kutpod]', $playerHtml, $content);
        } elseif (str_contains($content, '[kutpod]')) {
            $content = str_replace('[kutpod]', $playerHtml, $content);
        } elseif ($pos === 'bottom') {
            $content = $content . $playerHtml . $transcriptHtml;
        } else {
            // Top por defecto
            $content = $playerHtml . $content . $transcriptHtml;
        }

        return $content;
    }

    // 2. Caso Artículo Estándar (No Podcast) -> Mostrar Episodio Relacionado al pie
    if (!empty($cfg['enable_related'])) {
        $relatedList = kutpod_find_related_episodes($post, 4);
        if (!empty($relatedList)) {
            $content .= kutpod_render_related_box($relatedList, $cfg);
        }
    }

    return $content;
}

/**
 * Busca episodios directamente en KutPod (vía SQLite local o REST API remota)
 * y los puntúa según etiquetas, categorías y palabras clave del post.
 */
function kutpod_search_episodes_direct(array $postTags, array $postCats, array $titleKeywords, array $cfg): array {
    $instanceUrl = rtrim($cfg['instance_url'] ?: 'http://localhost:8000', '/');
    $db = kutpod_db();
    $episodes = [];

    $sql = "
        SELECT e.id, e.slug, e.title, e.cover, e.duration_secs, e.notes_md, e.custom_tags as ep_tags,
               e.audio_url, e.published_at, e.created_at,
               p.slug AS podcast_slug, p.title AS podcast_title, p.category, p.subcategory, 
               p.custom_tags AS podcast_tags, p.cover AS podcast_cover
        FROM episodes e
        JOIN podcasts p ON p.id = e.podcast_id
        WHERE e.hidden = 0 AND e.status = 'published' AND p.hidden = 0
        ORDER BY COALESCE(e.published_at, e.created_at) DESC
        LIMIT 100
    ";

    // 1. Consulta directa a la base de datos SQLite si está disponible (PDO)
    if ($db) {
        try {
            $stmt = $db->query($sql);
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                $episodes[] = [
                    'id'            => (int)$row['id'],
                    'slug'          => $row['slug'],
                    'title'         => $row['title'],
                    'cover'         => $row['cover'] ?: ($row['podcast_cover'] ?? ''),
                    'duration_secs' => (int)($row['duration_secs'] ?? 0),
                    'notes_md'      => $row['notes_md'] ?? '',
                    'custom_tags'   => trim(($row['ep_tags'] ?? '') . ',' . ($row['podcast_tags'] ?? ''), ','),
                    'podcast_slug'  => $row['podcast_slug'],
                    'podcast_title' => $row['podcast_title'],
                    'category'      => trim(($row['category'] ?? '') . ' ' . ($row['subcategory'] ?? '')),
                    'audio_url'     => $row['audio_url'] ?? '',
                    'published_at'  => $row['published_at'] ?: $row['created_at'],
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 1b. Fallback por CLI sqlite3 si pdo_sqlite no está cargado en PHP
    if (empty($episodes)) {
        $dbPath = kutpod_db_path();
        if ($dbPath && function_exists('shell_exec')) {
            try {
                $rawJson = @shell_exec("sqlite3 " . escapeshellarg($dbPath) . " -json " . escapeshellarg($sql));
                if ($rawJson) {
                    $cliRows = @json_decode($rawJson, true);
                    if (is_array($cliRows)) {
                        foreach ($cliRows as $row) {
                            $episodes[] = [
                                'id'            => (int)$row['id'],
                                'slug'          => $row['slug'],
                                'title'         => $row['title'],
                                'cover'         => $row['cover'] ?: ($row['podcast_cover'] ?? ''),
                                'duration_secs' => (int)($row['duration_secs'] ?? 0),
                                'notes_md'      => $row['notes_md'] ?? '',
                                'custom_tags'   => trim(($row['ep_tags'] ?? '') . ',' . ($row['podcast_tags'] ?? ''), ','),
                                'podcast_slug'  => $row['podcast_slug'],
                                'podcast_title' => $row['podcast_title'],
                                'category'      => trim(($row['category'] ?? '') . ' ' . ($row['subcategory'] ?? '')),
                                'audio_url'     => $row['audio_url'] ?? '',
                                'published_at'  => $row['published_at'] ?: $row['created_at'],
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }
    }

    // 2. Si no hubo SQLite local o no arrojó resultados, consultar vía HTTP a la instancia KutPod
    if (empty($episodes)) {
        $cacheDir = CONTENT_PATH . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/kutpod_remote_eps.json';
        $failFile  = $cacheDir . '/kutpod_remote_fail.flag';

        $cached = null;
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 300)) {
            $cached = @json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                $episodes = $cached;
            }
        }

        $inNegativeCache = file_exists($failFile) && (time() - filemtime($failFile) < 60);
        if (empty($episodes) && $instanceUrl && !$inNegativeCache) {
            $urlsToTry = [
                $instanceUrl . '/api/episodes',
                $instanceUrl . '/episodes?json=1',
                $instanceUrl . '/site.php?r=episodes&json=1'
            ];
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'header'  => "User-Agent: KutBlog-KutPod/1.0\r\nAccept: application/json\r\n"
                ]
            ]);
            foreach ($urlsToTry as $u) {
                $raw = @file_get_contents($u, false, $ctx);
                if ($raw) {
                    $json = @json_decode($raw, true);
                    if (!empty($json['items']) && is_array($json['items'])) {
                        $episodes = $json['items'];
                        @file_put_contents($cacheFile, json_encode($episodes));
                        if (file_exists($failFile)) @unlink($failFile);
                        break;
                    }
                }
            }
            if (empty($episodes)) {
                @touch($failFile);
                if (file_exists($cacheFile)) {
                    $episodes = @json_decode(file_get_contents($cacheFile), true) ?: [];
                }
            }
        } elseif (empty($episodes) && file_exists($cacheFile)) {
            $episodes = @json_decode(file_get_contents($cacheFile), true) ?: [];
        }
    }

    if (empty($episodes)) {
        return [];
    }

    // 3. Evaluar y puntuar coincidencias
    $scored = [];
    foreach ($episodes as $row) {
        $epTitleLower = mb_strtolower($row['title'] ?? '', 'UTF-8');
        $epNotesLower = mb_strtolower(strip_tags($row['notes_md'] ?? ''), 'UTF-8');
        $rawTags = array_map('trim', explode(',', mb_strtolower($row['custom_tags'] ?? '', 'UTF-8')));
        $epTags = array_filter($rawTags);
        $pCatLower = mb_strtolower($row['category'] ?? '', 'UTF-8');
        $pTitleLower = mb_strtolower($row['podcast_title'] ?? '', 'UTF-8');

        $score = 0;

        // Coincidencia por etiquetas del post
        foreach ($postTags as $tag) {
            if ($tag === '') continue;
            if (in_array($tag, $epTags, true)) {
                $score += 15;
            }
            if (str_contains($epTitleLower, $tag)) {
                $score += 12;
            }
            if (str_contains($pTitleLower, $tag)) {
                $score += 8;
            }
            if (str_contains($pCatLower, $tag)) {
                $score += 6;
            }
            if (str_contains($epNotesLower, $tag)) {
                $score += 4;
            }
        }

        // Coincidencia por categorías del post
        foreach ($postCats as $cat) {
            if ($cat === '') continue;
            if (str_contains($pCatLower, $cat)) {
                $score += 10;
            }
            if (str_contains($pTitleLower, $cat)) {
                $score += 8;
            }
            if (str_contains($epTitleLower, $cat)) {
                $score += 6;
            }
            if (in_array($cat, $epTags, true)) {
                $score += 8;
            }
        }

        // Palabras clave del título del post
        foreach ($titleKeywords as $kw) {
            if ($kw === '') continue;
            if (str_contains($epTitleLower, $kw)) {
                $score += 5;
            }
            if (str_contains($pTitleLower, $kw)) {
                $score += 3;
            }
            if (str_contains($epNotesLower, $kw)) {
                $score += 2;
            }
        }

        if ($score >= 4) {
            $pSlug = $row['podcast_slug'] ?: 'default';
            $epSlug = $row['slug'] ?: ($row['id'] ?? '');
            $coverUrl = kutpod_resolve_cover_url($row['cover'] ?? '', $cfg, [
                'podcast_slug' => $pSlug,
                'instance_url' => $instanceUrl
            ]);

            $durSecs = (int)($row['duration_secs'] ?? 0);
            $durFmt = $durSecs > 0 ? gmdate($durSecs >= 3600 ? 'H:i:s' : 'i:s', $durSecs) : ($row['duration'] ?? '');

            $url = $instanceUrl . '/@' . rawurlencode($pSlug) . '/' . rawurlencode($epSlug);
            $theme = $cfg['player_theme'] ?? 'auto';
            $embedSrc = $instanceUrl . '/embed/@' . rawurlencode($pSlug) . '/' . rawurlencode($epSlug) . '?theme=' . urlencode($theme);

            $scored[] = [
                'score'              => $score,
                'title'              => $row['title'],
                'slug'               => $epSlug,
                'cover'              => $coverUrl,
                'featured_image'     => $coverUrl,
                'url'                => $url,
                'embed_src'          => $embedSrc,
                'is_external_kutpod' => true,
                'podcast_meta'       => [
                    'is_podcast'    => true,
                    'podcast_slug'  => $pSlug,
                    'podcast_title' => $row['podcast_title'] ?? '',
                    'episode_slug'  => $epSlug,
                    'episode_id'    => (int)($row['id'] ?? 0),
                    'duration_secs' => $durSecs,
                    'duration_fmt'  => $durFmt,
                    'audio_url'     => $row['audio_url'] ?? '',
                    'instance_url'  => $instanceUrl
                ]
            ];
        }
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return $scored;
}

/**
 * Busca hasta $limit episodios relacionados con este artículo según categorías o etiquetas
 */
function kutpod_find_related_episodes(array $post, int $limit = 4): array {
    $cfg = kutpod_config();
    $postSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($post['slug'] ?? ''));
    $cacheDir = defined('CACHE_PATH') ? CACHE_PATH : (ROOT_PATH . '/cache');
    $relCacheFile = $postSlug !== '' ? ($cacheDir . '/kutpod_rel_' . $postSlug . '.json') : '';

    if ($relCacheFile !== '' && file_exists($relCacheFile) && (time() - filemtime($relCacheFile) < 3600)) {
        $cachedRel = @json_decode((string)@file_get_contents($relCacheFile), true);
        if (is_array($cachedRel)) {
            return array_slice($cachedRel, 0, $limit);
        }
    }

    $postCats = array_values(array_filter(array_map('trim', array_map('strtolower', $post['categories'] ?? []))));
    $postTags = array_values(array_filter(array_map('trim', array_map('strtolower', $post['tags'] ?? []))));
    $postTitle = trim($post['title'] ?? '');

    // Palabras clave del título (longitud >= 3, omitiendo stop-words comunes)
    $titleKeywords = [];
    if (!empty($postTitle)) {
        $words = preg_split('/[\s,\.\-_:;\?!=\(\)\[\]"\'\/]+/u', mb_strtolower($postTitle, 'UTF-8'));
        $stopWords = ['para', 'como', 'este', 'esta', 'estos', 'estas', 'sobre', 'desde', 'hacia', 'hasta', 'entre', 'donde', 'cuando', 'quien', 'cual', 'with', 'from', 'that', 'this', 'your', 'have', 'more', 'con', 'las', 'los', 'del', 'una', 'uno', 'unos', 'unas'];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, $stopWords, true)) {
                $titleKeywords[] = $w;
            }
        }
    }

    if (empty($postCats) && empty($postTags) && empty($titleKeywords)) {
        return [];
    }

    $results = [];
    $seenSlugs = [];

    // 1. Primero: Buscar entre artículos de podcast ya importados en KutBlog (si existen)
    $all = list_content('articles', true, 1, 9999)['items'] ?? [];
    foreach ($all as $item) {
        if (empty($item['podcast_meta']['is_podcast']) && empty($item['podcast_meta']['audio_url']) && empty($item['kutpod_url'])) {
            continue;
        }
        if (($item['slug'] ?? '') === ($post['slug'] ?? '')) continue;

        $itemCats = array_map('strtolower', $item['categories'] ?? []);
        $itemTags = array_map('strtolower', $item['tags'] ?? []);
        $itemTitle = mb_strtolower($item['title'] ?? '', 'UTF-8');

        $score = 0;
        foreach ($postTags as $t) {
            if (in_array($t, $itemTags, true)) $score += 15;
            elseif (str_contains($itemTitle, $t)) $score += 10;
        }
        foreach ($postCats as $c) {
            if (in_array($c, $itemCats, true)) $score += 8;
            elseif (str_contains($itemTitle, $c)) $score += 6;
        }
        foreach ($titleKeywords as $kw) {
            if (str_contains($itemTitle, $kw)) $score += 4;
        }

        if ($score >= 6) {
            $epSlug = $item['podcast_meta']['episode_slug'] ?? $item['slug'];
            $itemCopy = $item;
            $itemCopy['score'] = $score;
            $itemCopy['is_external_kutpod'] = false;
            $results[] = $itemCopy;
            $seenSlugs[strtolower($epSlug)] = true;
            $seenSlugs[strtolower($item['slug'])] = true;
        }
    }

    // 2. Segundo: Buscar directamente en KutPod (SQLite o REST API)
    $direct = kutpod_search_episodes_direct($postTags, $postCats, $titleKeywords, $cfg);
    foreach ($direct as $ep) {
        $epSlug = strtolower($ep['slug'] ?? '');
        if (!empty($seenSlugs[$epSlug])) {
            continue; // Ya incluido como artículo local
        }
        $seenSlugs[$epSlug] = true;
        $results[] = $ep;
    }

    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

    if ($relCacheFile !== '') {
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $toCache = array_slice($results, 0, max($limit, 10));
        @file_put_contents($relCacheFile, json_encode($toCache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return array_slice($results, 0, $limit);
}

/**
 * Busca un único episodio más relacionado (compatibilidad)
 */
function kutpod_find_related_episode(array $post): ?array {
    $matches = kutpod_find_related_episodes($post, 1);
    return !empty($matches) ? $matches[0] : null;
}

/**
 * Renderiza el bloque de episodio relacionado (con reproductor principal y mini-playlist interactiva si hay más de uno)
 */
function kutpod_render_related_box($episodesOrSingle, array $cfg): string {
    $base = base_url();
    $instanceUrl = rtrim($cfg['instance_url'] ?: 'http://localhost:8000', '/');

    if (empty($episodesOrSingle)) return '';

    // Soporte tanto para un único episodio como para una lista de episodios
    $episodes = isset($episodesOrSingle['title']) ? [$episodesOrSingle] : $episodesOrSingle;
    $main = $episodes[0];
    $others = array_slice($episodes, 1);

    $meta = $main['podcast_meta'] ?? [];
    $pSlug = $meta['podcast_slug'] ?? 'default';
    $epSlug = $meta['episode_slug'] ?? ($main['slug'] ?? '');
    
    $durSecs = (int)($meta['duration_secs'] ?? 0);
    $durFmt = $meta['duration_fmt'] ?: ($durSecs > 0 ? gmdate($durSecs >= 3600 ? 'H:i:s' : 'i:s', $durSecs) : '');

    $rawCover = $main['cover'] ?? ($main['featured_image'] ?? '');
    $cover = kutpod_resolve_cover_url($rawCover, $cfg, [
        'podcast_slug' => $pSlug,
        'instance_url' => $instanceUrl
    ]);
    if (empty($cover)) {
        $cover = $base . '/plugins/kutpod/assets/cover-placeholder.png';
    }

    $isExternal = !empty($main['is_external_kutpod']);
    $listenUrl = $isExternal 
        ? (!empty($main['url']) ? $main['url'] : ($instanceUrl . '/@' . rawurlencode($pSlug) . '/' . rawurlencode($epSlug)))
        : ($base . '/article/' . htmlspecialchars($main['slug']));
    
    $listenText = $isExternal ? 'Escuchar en KutPod ↗' : 'Escuchar y ver notas completas →';
    $targetAttr = $isExternal ? ' target="_blank" rel="noopener noreferrer"' : '';

    $theme = $cfg['player_theme'] ?? 'auto';
    $embedSrc = !empty($main['embed_src']) 
        ? $main['embed_src'] 
        : ($instanceUrl . "/embed/@" . rawurlencode($pSlug) . "/" . rawurlencode($epSlug) . "?theme=" . urlencode($theme));

    $showName = !empty($meta['podcast_title']) ? htmlspecialchars($meta['podcast_title']) : '';

    // Construir Mini Playlist si hay más episodios
    $playlistHtml = '';
    if (!empty($others)) {
        $playlistHtml = '
        <div class="kutpod-related-playlist">
          <div class="kutpod-playlist-header">
            <span class="kutpod-playlist-title">Más episodios recomendados</span>
            <span class="kutpod-playlist-count">' . count($others) . ' más</span>
          </div>
          <div class="kutpod-playlist-list">';

        foreach ($others as $other) {
            $oMeta = $other['podcast_meta'] ?? [];
            $oPSlug = $oMeta['podcast_slug'] ?? 'default';
            $oEpSlug = $oMeta['episode_slug'] ?? ($other['slug'] ?? '');
            $oSecs = (int)($oMeta['duration_secs'] ?? 0);
            $oDurFmt = $oMeta['duration_fmt'] ?: ($oSecs > 0 ? gmdate($oSecs >= 3600 ? 'H:i:s' : 'i:s', $oSecs) : '');
            
            $oRawCover = $other['cover'] ?? ($other['featured_image'] ?? '');
            $oCover = kutpod_resolve_cover_url($oRawCover, $cfg, [
                'podcast_slug' => $oPSlug,
                'instance_url' => $instanceUrl
            ]);
            if (empty($oCover)) {
                $oCover = $base . '/plugins/kutpod/assets/cover-placeholder.png';
            }

            $oIsExt = !empty($other['is_external_kutpod']);
            $oListenUrl = $oIsExt 
                ? (!empty($other['url']) ? $other['url'] : ($instanceUrl . '/@' . rawurlencode($oPSlug) . '/' . rawurlencode($oEpSlug)))
                : ($base . '/article/' . htmlspecialchars($other['slug']));
            $oListenText = $oIsExt ? 'Escuchar en KutPod ↗' : 'Escuchar y ver notas completas →';
            $oTargetAttr = $oIsExt ? ' target="_blank" rel="noopener noreferrer"' : '';

            $oEmbed = !empty($other['embed_src']) 
                ? $other['embed_src'] 
                : ($instanceUrl . "/embed/@" . rawurlencode($oPSlug) . "/" . rawurlencode($oEpSlug) . "?theme=" . urlencode($theme));
            $oShow = !empty($oMeta['podcast_title']) ? htmlspecialchars($oMeta['podcast_title']) : '';

            $playlistHtml .= '
            <div class="kutpod-playlist-item" 
                 data-embed-src="' . htmlspecialchars($oEmbed) . '" 
                 data-title="' . htmlspecialchars($other['title']) . '" 
                 data-url="' . htmlspecialchars($oListenUrl) . '" 
                 data-listen-text="' . htmlspecialchars($oListenText) . '" 
                 data-cover="' . htmlspecialchars($oCover) . '" 
                 data-meta="' . htmlspecialchars($oDurFmt ? '⏱️ ' . $oDurFmt : '') . '" 
                 data-showname="' . htmlspecialchars($oShow) . '">
              <button type="button" class="kutpod-playlist-item-play" aria-label="Reproducir">▶</button>
              <img src="' . htmlspecialchars($oCover) . '" alt="" class="kutpod-playlist-item-cover" onerror="this.src=\'' . $base . '/plugins/kutpod/assets/cover-placeholder.png\'">
              <div class="kutpod-playlist-item-details">
                <h5 class="kutpod-playlist-item-title">' . htmlspecialchars($other['title']) . '</h5>
                <div class="kutpod-playlist-item-meta">
                  ' . ($oDurFmt ? '<span>⏱️ ' . htmlspecialchars($oDurFmt) . '</span>' : '') . '
                  ' . ($oShow ? '<span>· ' . $oShow . '</span>' : '') . '
                </div>
              </div>
              <a href="' . htmlspecialchars($oListenUrl) . '"' . $oTargetAttr . ' class="kutpod-playlist-item-link" title="Abrir en KutPod" onclick="event.stopPropagation()">↗</a>
            </div>';
        }

        $playlistHtml .= '
          </div>
        </div>';
    }

    return '
    <div class="kutpod-related-card">
      <div class="kutpod-related-header">
        <span>🎙️ ¿Te apasiona este tema? Escucha el episodio en nuestro Podcast</span>
        ' . ($showName !== '' ? '<span class="kutpod-related-showname" style="font-weight:500; opacity:0.8; font-size:0.8rem; margin-left:auto;">' . $showName . '</span>' : '') . '
      </div>
      <div class="kutpod-related-player-wrap" style="border-radius:12px; overflow:hidden;">
        <iframe src="' . htmlspecialchars($embedSrc) . '" class="kutpod-player-frame" style="width:100%; height:175px; min-height:175px; border:none; display:block;" allow="encrypted-media; fullscreen" loading="lazy"></iframe>
      </div>
      ' . $playlistHtml . '
    </div>';
}

// ── Inyección de Scripts y Metadatos en Header y Footer ────────────────────────
function kutpod_theme_header(string $template = '', array $vars = []): void {
    $base = base_url();
    $cssVer = @filemtime(__DIR__ . '/assets/kutpod-blog.css') ?: '1';
    echo "\n  <!-- KutPod Podcast Integration Styles -->\n";
    echo "  <link rel=\"stylesheet\" href=\"{$base}/plugins/kutpod/assets/kutpod-blog.css?v={$cssVer}\">\n";

    // Si es un post con podcast, inyectar Schema.org PodcastEpisode y OpenGraph Audio
    $post = $vars['post'] ?? null;
    if ($post && !empty($post['podcast_meta']['is_podcast'])) {
        $meta = $post['podcast_meta'];
        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'PodcastEpisode',
            'name'          => $post['title'],
            'description'   => $post['excerpt'] ?: strip_tags($post['content']),
            'datePublished' => $post['created_at'],
            'url'           => $base . '/article/' . $post['slug']
        ];
        if (!empty($meta['audio_url'])) {
            $schema['associatedMedia'] = [
                '@type'         => 'AudioObject',
                'contentUrl'    => $meta['audio_url'],
                'duration'      => !empty($meta['duration_secs']) ? 'PT' . $meta['duration_secs'] . 'S' : null
            ];
            echo "  <meta property=\"og:audio\" content=\"" . htmlspecialchars($meta['audio_url']) . "\">\n";
            echo "  <meta property=\"og:audio:type\" content=\"audio/mpeg\">\n";
        }
        echo "  <script type=\"application/ld+json\">" . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
    }
}

function kutpod_theme_footer(string $template = '', array $vars = []): void {
    $base = base_url();
    $jsVer = @filemtime(__DIR__ . '/assets/kutpod-blog.js') ?: '1';
    echo "\n  <!-- KutPod Interactive Reader Script -->\n";
    echo "  <script src=\"{$base}/plugins/kutpod/assets/kutpod-blog.js?v={$jsVer}\" defer></script>\n";
}
