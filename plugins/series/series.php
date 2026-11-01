<?php
/**
 * Plugin: Series de Artículos
 * Agrupa artículos en tutoriales o cursos secuenciales con orden personalizado.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

/**
 * Retorna la ruta al archivo de almacenamiento JSON.
 */
function series_data_file(): string {
    return ROOT_PATH . '/content/series.json';
}

/**
 * Carga los datos de series.
 */
function series_get_data(): array {
    $file = series_data_file();
    if (!file_exists($file)) {
        return ['series' => []];
    }
    $raw = @file_get_contents($file);
    if (!$raw) {
        return ['series' => []];
    }
    $data = @json_decode($raw, true);
    if (!is_array($data) || !isset($data['series']) || !is_array($data['series'])) {
        return ['series' => []];
    }
    return $data;
}

/**
 * Guarda los datos de series de forma atómica.
 */
function series_save_data(array $data): bool {
    $file = series_data_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload = json_encode([
        'series' => array_values($data['series'] ?? [])
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return @file_put_contents($file, $payload, LOCK_EX) !== false;
}

/**
 * Retorna todas las series disponibles.
 */
function series_get_all(): array {
    $data = series_get_data();
    return $data['series'];
}

/**
 * Busca una serie por su ID.
 */
function series_get_by_id(string $id): ?array {
    foreach (series_get_all() as $s) {
        if ($s['id'] === $id) return $s;
    }
    return null;
}

/**
 * Busca una serie por su Slug.
 */
function series_get_by_slug(string $slug): ?array {
    $slug = trim($slug);
    foreach (series_get_all() as $s) {
        if (($s['slug'] ?? '') === $slug) return $s;
    }
    return null;
}

/**
 * Genera un slug único para una serie.
 */
function series_generate_slug(string $title, ?string $currentId = null): string {
    $baseSlug = function_exists('slug_from_title') ? slug_from_title($title) : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (!$baseSlug) $baseSlug = 'serie';
    $slug = $baseSlug;
    $i = 1;

    $all = series_get_all();
    while (true) {
        $conflict = false;
        foreach ($all as $s) {
            if ($s['slug'] === $slug && $s['id'] !== $currentId) {
                $conflict = true;
                break;
            }
        }
        if (!$conflict) return $slug;
        $slug = $baseSlug . '-' . (++$i);
    }
}

/**
 * Crea una nueva serie.
 */
function series_create(string $title, string $description = '', string $cover = '', ?string $customSlug = null): ?array {
    $title = trim($title);
    if ($title === '') return null;

    $data = series_get_data();
    $id = 'ser_' . substr(md5(uniqid('', true)), 0, 8);
    $slug = $customSlug ? series_generate_slug($customSlug, $id) : series_generate_slug($title, $id);

    $serie = [
        'id'          => $id,
        'title'       => $title,
        'slug'        => $slug,
        'description' => trim($description),
        'cover'       => trim($cover),
        'posts'       => [],
        'created_at'  => date('c'),
        'updated_at'  => date('c')
    ];

    $data['series'][] = $serie;
    if (series_save_data($data)) {
        return $serie;
    }
    return null;
}

/**
 * Actualiza una serie existente.
 */
function series_update(string $id, array $fields): ?array {
    $data = series_get_data();
    $updated = null;

    foreach ($data['series'] as &$s) {
        if ($s['id'] === $id) {
            if (isset($fields['title']) && trim($fields['title']) !== '') {
                $s['title'] = trim($fields['title']);
            }
            if (isset($fields['slug']) && trim($fields['slug']) !== '') {
                $s['slug'] = series_generate_slug(trim($fields['slug']), $id);
            }
            if (isset($fields['description'])) {
                $s['description'] = trim($fields['description']);
            }
            if (isset($fields['cover'])) {
                $s['cover'] = trim($fields['cover']);
            }
            if (isset($fields['posts']) && is_array($fields['posts'])) {
                $s['posts'] = array_values(array_unique(array_filter(array_map('trim', $fields['posts']))));
            }
            $s['updated_at'] = date('c');
            $updated = $s;
            break;
        }
    }
    unset($s);

    if ($updated && series_save_data($data)) {
        return $updated;
    }
    return null;
}

/**
 * Elimina una serie.
 */
function series_delete(string $id): bool {
    $data = series_get_data();
    $idxFound = null;

    foreach ($data['series'] as $idx => $s) {
        if ($s['id'] === $id) {
            $idxFound = $idx;
            break;
        }
    }

    if ($idxFound === null) return false;
    array_splice($data['series'], $idxFound, 1);
    return series_save_data($data);
}

/**
 * Obtiene la serie a la que pertenece un artículo dado (por su slug o metadata).
 */
function series_get_for_post(string $postSlug): ?array {
    $postSlug = trim($postSlug);
    if ($postSlug === '') return null;

    foreach (series_get_all() as $s) {
        if (in_array($postSlug, $s['posts'] ?? [], true)) {
            return $s;
        }
    }
    return null;
}

/**
 * Asigna un artículo a una serie o lo desvincula si $seriesId es null/vacío.
 */
function series_assign_post(string $postSlug, ?string $seriesId): bool {
    $postSlug = trim($postSlug);
    if ($postSlug === '') return false;

    $data = series_get_data();
    $changed = false;

    // Primero retirar de cualquier serie existente
    foreach ($data['series'] as &$s) {
        $idx = array_search($postSlug, $s['posts'] ?? [], true);
        if ($idx !== false) {
            if ($s['id'] !== $seriesId) {
                array_splice($s['posts'], $idx, 1);
                $s['updated_at'] = date('c');
                $changed = true;
            }
        }
    }
    unset($s);

    // Si se especificó una nueva serie, añadirlo al final si no está
    if ($seriesId) {
        foreach ($data['series'] as &$s) {
            if ($s['id'] === $seriesId) {
                if (!in_array($postSlug, $s['posts'] ?? [], true)) {
                    $s['posts'][] = $postSlug;
                    $s['updated_at'] = date('c');
                    $changed = true;
                }
                break;
            }
        }
        unset($s);
    }

    return $changed ? series_save_data($data) : true;
}

/**
 * Remueve un artículo de una serie específica.
 */
function series_remove_post(string $seriesId, string $postSlug): bool {
    $postSlug = trim($postSlug);
    $data = series_get_data();
    $changed = false;

    foreach ($data['series'] as &$s) {
        if ($s['id'] === $seriesId) {
            $idx = array_search($postSlug, $s['posts'] ?? [], true);
            if ($idx !== false) {
                array_splice($s['posts'], $idx, 1);
                $s['updated_at'] = date('c');
                $changed = true;
            }
            break;
        }
    }
    unset($s);

    return $changed ? series_save_data($data) : false;
}

/**
 * Actualiza el orden completo de artículos de una serie.
 */
function series_set_posts_order(string $seriesId, array $postSlugs): bool {
    $data = series_get_data();
    $found = false;

    foreach ($data['series'] as &$s) {
        if ($s['id'] === $seriesId) {
            $s['posts'] = array_values(array_unique(array_filter(array_map('trim', $postSlugs))));
            $s['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($s);

    return $found ? series_save_data($data) : false;
}

/**
 * Evento cuando se elimina un artículo del blog: limpiarlo de todas las series.
 */
function series_on_delete_post(string $postSlug): void {
    series_assign_post($postSlug, null);
}

/**
 * Resuelve la navegación secuencial de un artículo dentro de su serie.
 */
function series_get_post_nav(string $postSlug): ?array {
    $series = series_get_for_post($postSlug);
    if (!$series || empty($series['posts'])) return null;

    $slugs = $series['posts'];
    $idx = array_search($postSlug, $slugs, true);
    if ($idx === false) return null;

    $total = count($slugs);
    $prevSlug = ($idx > 0) ? $slugs[$idx - 1] : null;
    $nextSlug = ($idx < $total - 1) ? $slugs[$idx + 1] : null;

    // Cargar información de los artículos
    $allPosts = [];
    $prevPost = null;
    $nextPost = null;

    foreach ($slugs as $pos => $s) {
        $p = function_exists('get_content') ? get_content('articles', $s) : null;
        $item = [
            'slug'   => $s,
            'title'  => $p['title'] ?? ucfirst(str_replace('-', ' ', $s)),
            'date'   => $p['created_at'] ?? '',
            'status' => $p['status'] ?? 'published',
            'pos'    => $pos + 1
        ];
        $allPosts[] = $item;

        if ($s === $prevSlug) $prevPost = $item;
        if ($s === $nextSlug) $nextPost = $item;
    }

    return [
        'series' => $series,
        'index'  => $idx,
        'part'   => $idx + 1,
        'total'  => $total,
        'prev'   => $prevPost,
        'next'   => $nextPost,
        'all'    => $allPosts
    ];
}

/**
 * Renderiza la píldora informativa superior sobre la serie.
 */
function series_render_banner(array $post): string {
    $slug = $post['slug'] ?? '';
    if (!$slug) return '';

    $nav = series_get_post_nav($slug);
    if (!$nav) return '';

    $base = rtrim(base_url(), '/');
    $series = $nav['series'];
    $part = $nav['part'];
    $total = $nav['total'];

    return '
    <div class="series-top-banner">
      <div class="series-top-banner-inner">
        <span class="series-badge">SERIE</span>
        <span class="series-text">
          Parte <strong>' . $part . '</strong> de <strong>' . $total . '</strong> en 
          <a href="' . $base . '/series/' . htmlspecialchars($series['slug']) . '" class="series-link">' . htmlspecialchars($series['title']) . '</a>
        </span>
        <a href="#series-index" class="series-jump-link">Ver entregas ↓</a>
      </div>
    </div>';
}

/**
 * Renderiza la caja de navegación completa al pie del artículo.
 */
function series_render_box(array $post): string {
    $slug = $post['slug'] ?? '';
    if (!$slug) return '';

    $nav = series_get_post_nav($slug);
    if (!$nav) return '';

    $base = rtrim(base_url(), '/');
    $series = $nav['series'];
    $part = $nav['part'];
    $total = $nav['total'];
    $prev = $nav['prev'];
    $next = $nav['next'];
    $all = $nav['all'];

    $html = '
    <div class="series-footer-box" id="series-index">
      <div class="series-footer-header">
        <div class="series-tag-row">
          <span class="series-badge-pill">SERIE DE ARTÍCULOS</span>
          <span class="series-meta-count">Entrega ' . $part . ' de ' . $total . '</span>
        </div>
        <h3 class="series-main-title">
          <a href="' . $base . '/series/' . htmlspecialchars($series['slug']) . '">' . htmlspecialchars($series['title']) . '</a>
        </h3>';

    if (!empty($series['description'])) {
        $html .= '<p class="series-lead-desc">' . htmlspecialchars($series['description']) . '</p>';
    }

    $html .= '</div>';

    // Botones de navegación Anterior / Siguiente
    $html .= '<div class="series-nav-grid">';

    if ($prev) {
        $html .= '
        <a href="' . $base . '/article/' . htmlspecialchars($prev['slug']) . '" class="series-nav-btn prev">
          <span class="series-nav-dir">← Entrega ' . ($part - 1) . '</span>
          <strong class="series-nav-title">' . htmlspecialchars($prev['title']) . '</strong>
        </a>';
    } else {
        $html .= '
        <div class="series-nav-btn disabled">
          <span class="series-nav-dir">Primera entrega</span>
          <strong class="series-nav-title">Estás en el inicio de la serie</strong>
        </div>';
    }

    if ($next) {
        $html .= '
        <a href="' . $base . '/article/' . htmlspecialchars($next['slug']) . '" class="series-nav-btn next">
          <span class="series-nav-dir">Entrega ' . ($part + 1) . ' →</span>
          <strong class="series-nav-title">' . htmlspecialchars($next['title']) . '</strong>
        </a>';
    } else {
        $html .= '
        <div class="series-nav-btn disabled">
          <span class="series-nav-dir">Última entrega</span>
          <strong class="series-nav-title">Has llegado al final de la serie</strong>
        </div>';
    }

    $html .= '</div>';

    // Lista desplegable de entregas
    $html .= '
      <details class="series-toc-accordion" open>
        <summary class="series-toc-summary">
          <span>Índice de la serie (' . $total . ' entregas)</span>
          <span class="series-toc-arrow">▾</span>
        </summary>
        <ol class="series-toc-list">';

    foreach ($all as $item) {
        $isCurrent = ($item['slug'] === $slug);
        $html .= '<li class="series-toc-item ' . ($isCurrent ? 'is-current' : '') . '">';
        if ($isCurrent) {
            $html .= '<span class="series-toc-bullet">▶</span> ';
            $html .= '<strong class="series-toc-active-title">' . htmlspecialchars($item['title']) . '</strong> ';
            $html .= '<span class="series-reading-pill">Leyendo ahora</span>';
        } else {
            $html .= '<a href="' . $base . '/article/' . htmlspecialchars($item['slug']) . '" class="series-toc-link">' . htmlspecialchars($item['title']) . '</a>';
        }
        $html .= '</li>';
    }

    $html .= '
        </ol>
      </details>
    </div>';

    return $html;
}

/**
 * Retorna los estilos CSS inyectados para el tema visual de las series.
 */
function series_get_inline_css(): string {
    return '
    <style id="kutblog-series-styles">
    .series-top-banner {
      margin: 0 0 1.5rem 0;
      padding: 0.65rem 1rem;
      background: var(--surface2, rgba(255,255,255,0.05));
      border: 1px solid var(--border, rgba(255,255,255,0.12));
      border-left: 4px solid var(--accent, #e05c1a);
      border-radius: 8px;
      font-size: 0.88rem;
      line-height: 1.4;
    }
    .series-top-banner-inner {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.6rem;
    }
    .series-badge {
      display: inline-block;
      padding: 0.15rem 0.45rem;
      font-size: 0.65rem;
      font-weight: 800;
      letter-spacing: 0.05em;
      border-radius: 4px;
      background: var(--accent, #e05c1a);
      color: #ffffff;
      text-transform: uppercase;
    }
    .series-link {
      font-weight: 700;
      color: var(--accent, #e05c1a);
      text-decoration: underline;
      text-underline-offset: 3px;
    }
    .series-jump-link {
      margin-left: auto;
      font-size: 0.78rem;
      color: var(--muted, #9ca3af);
      text-decoration: none;
      font-weight: 500;
    }
    .series-jump-link:hover {
      color: var(--text, #ffffff);
    }
    .series-footer-box {
      margin: 2.5rem 0 2rem 0;
      padding: 1.5rem 1.6rem;
      background: var(--surface2, rgba(255,255,255,0.03));
      border: 1px solid var(--border, rgba(255,255,255,0.12));
      border-radius: 12px;
      box-shadow: 0 4px 18px rgba(0,0,0,0.12);
    }
    .series-tag-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.4rem;
    }
    .series-badge-pill {
      font-size: 0.65rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--accent, #e05c1a);
    }
    .series-meta-count {
      font-size: 0.78rem;
      color: var(--muted, #a1a1aa);
      font-weight: 600;
    }
    .series-main-title {
      margin: 0 0 0.5rem 0;
      font-size: 1.25rem;
      font-weight: 800;
      line-height: 1.25;
    }
    .series-main-title a {
      color: var(--text, inherit);
      text-decoration: none;
    }
    .series-main-title a:hover {
      color: var(--accent, #e05c1a);
    }
    .series-lead-desc {
      margin: 0 0 1.25rem 0;
      font-size: 0.88rem;
      color: var(--muted, #a1a1aa);
      line-height: 1.5;
    }
    .series-nav-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
      margin: 1.25rem 0;
    }
    @media (max-width: 600px) {
      .series-nav-grid { grid-template-columns: 1fr; }
    }
    .series-nav-btn {
      display: flex;
      flex-direction: column;
      padding: 0.75rem 1rem;
      background: var(--surface, rgba(255,255,255,0.04));
      border: 1px solid var(--border, rgba(255,255,255,0.1));
      border-radius: 8px;
      text-decoration: none;
      transition: all 0.15s ease;
    }
    .series-nav-btn:hover:not(.disabled) {
      border-color: var(--accent, #e05c1a);
      background: rgba(var(--accent-rgb, 224, 92, 26), 0.08);
      transform: translateY(-1px);
    }
    .series-nav-btn.disabled {
      opacity: 0.45;
      cursor: default;
    }
    .series-nav-btn.next {
      text-align: right;
      align-items: flex-end;
    }
    .series-nav-dir {
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--accent, #e05c1a);
      margin-bottom: 0.2rem;
    }
    .series-nav-title {
      font-size: 0.9rem;
      color: var(--text, inherit);
      line-height: 1.3;
      font-weight: 600;
    }
    .series-toc-accordion {
      margin-top: 1.25rem;
      border-top: 1px solid var(--border, rgba(255,255,255,0.1));
      padding-top: 0.85rem;
    }
    .series-toc-summary {
      cursor: pointer;
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text, inherit);
      display: flex;
      justify-content: space-between;
      align-items: center;
      user-select: none;
      padding: 0.25rem 0;
    }
    .series-toc-summary::-webkit-details-marker { display: none; }
    .series-toc-arrow {
      font-size: 0.95rem;
      transition: transform 0.2s ease;
    }
    .series-toc-accordion[open] .series-toc-arrow {
      transform: rotate(180deg);
    }
    .series-toc-list {
      margin: 0.85rem 0 0 1.2rem;
      padding: 0;
      font-size: 0.88rem;
    }
    .series-toc-item {
      margin: 0.45rem 0;
      line-height: 1.4;
      color: var(--muted, #a1a1aa);
    }
    .series-toc-item.is-current {
      color: var(--text, #ffffff);
      font-weight: 600;
    }
    .series-toc-bullet {
      color: var(--accent, #e05c1a);
      font-size: 0.75rem;
      margin-right: 0.2rem;
    }
    .series-toc-link {
      color: var(--text, inherit);
      text-decoration: none;
    }
    .series-toc-link:hover {
      color: var(--accent, #e05c1a);
      text-decoration: underline;
    }
    .series-reading-pill {
      display: inline-block;
      margin-left: 0.5rem;
      padding: 0.1rem 0.4rem;
      font-size: 0.65rem;
      font-weight: 700;
      background: var(--accent, #e05c1a);
      color: #ffffff;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    </style>';
}

/**
 * Filtro de contenido para inyectar la barra superior y caja inferior en artículos de serie.
 */
function series_filter_content(string $content, array $post): string {
    $slug = $post['slug'] ?? '';
    if (!$slug) return $content;

    $series = series_get_for_post($slug);
    if (!$series) return $content;

    static $cssInjected = false;
    $css = '';
    if (!$cssInjected) {
        $css = series_get_inline_css();
        $cssInjected = true;
    }

    $top = series_render_banner($post);
    $bottom = series_render_box($post);

    return $css . $top . $content . $bottom;
}

/**
 * Enrutador para páginas públicas: /series y /series/{slug}.
 */
function series_route_request(string $path, array $segments): bool {
    $first = $segments[0] ?? '';
    if ($first !== 'series' && $first !== 'serie') {
        return false;
    }

    $sub = $segments[1] ?? '';
    if ($sub === '') {
        // Catálogo general de series (/series)
        series_render_catalog_page();
        return true;
    }

    // Detalle de una serie específica (/series/slug)
    $series = series_get_by_slug($sub);
    if (!$series) {
        http_response_code(404);
        render_theme('404');
        return true;
    }

    series_render_detail_page($series);
    return true;
}

/**
 * Renderiza la página del catálogo de todas las series (/series).
 */
function series_render_catalog_page(): void {
    $allSeries = series_get_all();
    $config = cms_config();
    $site_title = $config['site_title'] ?? CMS_NAME;
    $base = rtrim(base_url(), '/');

    $header_file = theme_file('header.php');
    if (file_exists($header_file)) include $header_file;
    ?>
    <div class="series-catalog-page" style="max-width:960px;margin:2rem auto;padding:0 1.25rem;">
      <?= series_get_inline_css() ?>
      <header class="series-catalog-header" style="margin-bottom:2.5rem;text-align:left;">
        <span class="series-badge" style="margin-bottom:0.6rem;">CATÁLOGO</span>
        <h1 style="font-size:clamp(1.75rem,4vw,2.5rem);font-weight:800;margin:0 0 0.6rem 0;line-height:1.2;">Series y Tutoriales</h1>
        <p style="font-size:1.05rem;color:var(--muted,#a1a1aa);max-width:680px;line-height:1.6;margin:0;">
          Guías paso a paso, cursos y colecciones temáticas organizadas en orden de lectura para facilitar tu aprendizaje.
        </p>
      </header>

      <?php if (empty($allSeries)): ?>
        <div style="padding:3.5rem 1.5rem;background:var(--surface2,rgba(255,255,255,0.03));border:1px dashed var(--border,rgba(255,255,255,0.15));border-radius:12px;text-align:center;">
          <div style="font-size:2.5rem;margin-bottom:0.75rem;">📚</div>
          <h3 style="font-size:1.2rem;font-weight:700;margin:0 0 0.5rem 0;">Aún no hay series publicadas</h3>
          <p style="color:var(--muted,#a1a1aa);margin:0 0 1.25rem 0;font-size:0.9rem;">Pronto añadiremos nuevas colecciones y cursos estructurados.</p>
          <a href="<?= $base ?>/" style="display:inline-block;padding:0.6rem 1.25rem;background:var(--accent,#e05c1a);color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.88rem;">← Volver al blog</a>
        </div>
      <?php else: ?>
        <div class="series-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;">
          <?php foreach ($allSeries as $s): 
            $count = count($s['posts'] ?? []);
            $firstSlug = $s['posts'][0] ?? null;
            $startUrl = $firstSlug ? ($base . '/article/' . htmlspecialchars($firstSlug)) : ($base . '/series/' . htmlspecialchars($s['slug']));
          ?>
            <article class="series-card" style="background:var(--surface,rgba(255,255,255,0.03));border:1px solid var(--border,rgba(255,255,255,0.1));border-radius:12px;overflow:hidden;display:flex;flex-direction:column;transition:transform 0.15s ease,box-shadow 0.15s ease;">
              <?php if (!empty($s['cover'])): ?>
                <a href="<?= $base ?>/series/<?= htmlspecialchars($s['slug']) ?>" style="display:block;height:160px;overflow:hidden;background:#111;">
                  <img src="<?= htmlspecialchars($s['cover']) ?>" alt="<?= htmlspecialchars($s['title']) ?>" style="width:100%;height:100%;object-fit:cover;">
                </a>
              <?php endif; ?>
              <div style="padding:1.4rem;flex:1;display:flex;flex-direction:column;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                  <span style="font-size:0.7rem;font-weight:800;letter-spacing:0.05em;color:var(--accent,#e05c1a);text-transform:uppercase;">SERIE</span>
                  <span style="font-size:0.75rem;color:var(--muted,#a1a1aa);font-weight:600;"><?= $count ?> entregas</span>
                </div>
                <h2 style="font-size:1.2rem;font-weight:800;margin:0 0 0.5rem 0;line-height:1.3;">
                  <a href="<?= $base ?>/series/<?= htmlspecialchars($s['slug']) ?>" style="color:var(--text,inherit);text-decoration:none;">
                    <?= htmlspecialchars($s['title']) ?>
                  </a>
                </h2>
                <?php if (!empty($s['description'])): ?>
                  <p style="font-size:0.85rem;color:var(--muted,#a1a1aa);line-height:1.5;margin:0 0 1.25rem 0;flex:1;">
                    <?= htmlspecialchars($s['description']) ?>
                  </p>
                <?php endif; ?>
                <div style="display:flex;gap:0.75rem;align-items:center;margin-top:auto;padding-top:1rem;border-top:1px solid var(--border,rgba(255,255,255,0.08));">
                  <a href="<?= $base ?>/series/<?= htmlspecialchars($s['slug']) ?>" style="font-size:0.82rem;font-weight:700;color:var(--accent,#e05c1a);text-decoration:none;">Ver índice →</a>
                  <?php if ($firstSlug): ?>
                    <a href="<?= $startUrl ?>" style="margin-left:auto;font-size:0.78rem;padding:0.4rem 0.75rem;background:var(--accent,#e05c1a);color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Comenzar</a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    $footer_file = theme_file('footer.php');
    if (file_exists($footer_file)) include $footer_file;
}

/**
 * Renderiza la página de detalle de una serie con su lista completa de artículos (/series/{slug}).
 */
function series_render_detail_page(array $series): void {
    $base = rtrim(base_url(), '/');
    $postsSlugs = $series['posts'] ?? [];
    $total = count($postsSlugs);

    $resolvedPosts = [];
    $totalMinutes = 0;

    foreach ($postsSlugs as $idx => $s) {
        $p = function_exists('get_content') ? get_content('articles', $s) : null;
        if ($p) {
            $words = str_word_count(strip_tags($p['content'] ?? ''));
            $mins = max(1, (int)ceil($words / 200));
            $totalMinutes += $mins;
            $resolvedPosts[] = [
                'pos'      => $idx + 1,
                'slug'     => $s,
                'title'    => $p['title'] ?? 'Sin título',
                'excerpt'  => $p['excerpt'] ?? (mb_substr(strip_tags($p['content'] ?? ''), 0, 140) . '…'),
                'created'  => $p['created_at'] ?? '',
                'mins'     => $mins,
                'image'    => $p['featured_image'] ?? ''
            ];
        } else {
            $resolvedPosts[] = [
                'pos'      => $idx + 1,
                'slug'     => $s,
                'title'    => ucfirst(str_replace('-', ' ', $s)),
                'excerpt'  => '',
                'created'  => '',
                'mins'     => 5,
                'image'    => ''
            ];
        }
    }

    $header_file = theme_file('header.php');
    if (file_exists($header_file)) include $header_file;
    ?>
    <div class="series-detail-page" style="max-width:880px;margin:2rem auto;padding:0 1.25rem;">
      <?= series_get_inline_css() ?>

      <!-- Migas de pan -->
      <nav style="display:flex;gap:0.4rem;align-items:center;font-size:0.8rem;color:var(--muted,#a1a1aa);margin-bottom:1.5rem;">
        <a href="<?= $base ?>/" style="color:inherit;text-decoration:none;">Inicio</a>
        <span>/</span>
        <a href="<?= $base ?>/series" style="color:inherit;text-decoration:none;">Series</a>
        <span>/</span>
        <span style="color:var(--text,inherit);font-weight:600;"><?= htmlspecialchars($series['title']) ?></span>
      </nav>

      <!-- Cabecera de la serie -->
      <header class="series-header-card" style="margin-bottom:2.5rem;padding:2rem;background:var(--surface2,rgba(255,255,255,0.03));border:1px solid var(--border,rgba(255,255,255,0.12));border-radius:12px;">
        <?php if (!empty($series['cover'])): ?>
          <div style="max-height:260px;overflow:hidden;border-radius:8px;margin-bottom:1.5rem;">
            <img src="<?= htmlspecialchars($series['cover']) ?>" alt="<?= htmlspecialchars($series['title']) ?>" style="width:100%;height:100%;object-fit:cover;">
          </div>
        <?php endif; ?>
        <span class="series-badge" style="margin-bottom:0.75rem;">SERIE DE ARTÍCULOS</span>
        <h1 style="font-size:clamp(1.8rem,4vw,2.6rem);font-weight:800;margin:0 0 0.75rem 0;line-height:1.2;">
          <?= htmlspecialchars($series['title']) ?>
        </h1>
        <?php if (!empty($series['description'])): ?>
          <p style="font-size:1.05rem;color:var(--muted,#a1a1aa);line-height:1.6;margin:0 0 1.5rem 0;">
            <?= htmlspecialchars($series['description']) ?>
          </p>
        <?php endif; ?>

        <!-- Métricas resumidas -->
        <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;font-size:0.85rem;color:var(--muted,#a1a1aa);padding-top:1rem;border-top:1px solid var(--border,rgba(255,255,255,0.08));">
          <div><strong style="color:var(--text,inherit);"><?= $total ?></strong> entregas en total</div>
          <div><strong style="color:var(--text,inherit);"><?= $totalMinutes ?> min</strong> de lectura estimada</div>
          <?php if (!empty($resolvedPosts[0])): ?>
            <a href="<?= $base ?>/article/<?= htmlspecialchars($resolvedPosts[0]['slug']) ?>" style="margin-left:auto;padding:0.45rem 1rem;background:var(--accent,#e05c1a);color:#fff;border-radius:6px;text-decoration:none;font-weight:700;font-size:0.85rem;">
              Comenzar con la Entrega #1 →
            </a>
          <?php endif; ?>
        </div>
      </header>

      <!-- Lista de entregas -->
      <section class="series-deliveries-list">
        <h2 style="font-size:1.3rem;font-weight:800;margin:0 0 1.25rem 0;">Índice y orden de lectura</h2>

        <?php if (empty($resolvedPosts)): ?>
          <p style="color:var(--muted,#a1a1aa);">No hay entregas asignadas a esta serie todavía.</p>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:1rem;">
            <?php foreach ($resolvedPosts as $item): ?>
              <article style="display:flex;gap:1.2rem;padding:1.25rem;background:var(--surface,rgba(255,255,255,0.03));border:1px solid var(--border,rgba(255,255,255,0.1));border-radius:10px;align-items:flex-start;">
                <div style="width:38px;height:38px;border-radius:8px;background:rgba(var(--accent-rgb,224,92,26),0.12);color:var(--accent,#e05c1a);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.05rem;flex-shrink:0;">
                  <?= $item['pos'] ?>
                </div>
                <div style="flex:1;min-width:0;">
                  <h3 style="font-size:1.15rem;font-weight:700;margin:0 0 0.35rem 0;line-height:1.3;">
                    <a href="<?= $base ?>/article/<?= htmlspecialchars($item['slug']) ?>" style="color:var(--text,inherit);text-decoration:none;">
                      <?= htmlspecialchars($item['title']) ?>
                    </a>
                  </h3>
                  <?php if (!empty($item['excerpt'])): ?>
                    <p style="font-size:0.88rem;color:var(--muted,#a1a1aa);line-height:1.5;margin:0 0 0.6rem 0;">
                      <?= htmlspecialchars($item['excerpt']) ?>
                    </p>
                  <?php endif; ?>
                  <div style="display:flex;gap:1rem;font-size:0.78rem;color:var(--muted,#a1a1aa);">
                    <?php if (!empty($item['created'])): ?>
                      <span><?= function_exists('format_date') ? format_date(strtotime($item['created']), 'date') : date('d/m/Y', strtotime($item['created'])) ?></span>
                    <?php endif; ?>
                    <span><?= $item['mins'] ?> min de lectura</span>
                    <a href="<?= $base ?>/article/<?= htmlspecialchars($item['slug']) ?>" style="margin-left:auto;color:var(--accent,#e05c1a);font-weight:700;text-decoration:none;">Leer entrega →</a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
    <?php
    $footer_file = theme_file('footer.php');
    if (file_exists($footer_file)) include $footer_file;
}

