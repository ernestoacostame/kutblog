<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

// Collect existing categories and tags for autocomplete
$all_cats = [];
$all_tags_list = [];
foreach (['articles', 'pages'] as $_ctype) {
    $_dir = CONTENT_PATH . '/' . $_ctype;
    if (!is_dir($_dir)) continue;
    foreach (glob("$_dir/*.json") as $_f) {
        $_p = json_decode(file_get_contents($_f), true);
        foreach ($_p['categories'] ?? [] as $_c) if ($_c) $all_cats[$_c] = true;
        foreach ($_p['tags'] ?? [] as $_t) if ($_t) $all_tags_list[$_t] = true;
    }
}
$all_cats = array_keys($all_cats);
$all_tags_list = array_keys($all_tags_list);
sort($all_cats);
sort($all_tags_list);

$type = in_array($_GET['type'] ?? '', ['articles', 'pages']) ? $_GET['type'] : 'articles';
$slug = $_GET['slug'] ?? '';
$post = $slug ? get_content($type, $slug) : null;
$is_new = !$post;
$csrf = generate_csrf();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { $error = 'Security error. Refresh and try again.'; }
    else {
        $target_status = in_array($_POST['status'] ?? '', ['published', 'draft', 'scheduled']) ? $_POST['status'] : 'draft';
        $post_created_at = !empty($_POST['created_at']) ? date('c', strtotime($_POST['created_at'])) : ($post['created_at'] ?? date('c'));
        
        // Si el usuario seleccionó fecha en el futuro y no forzó 'draft'
        if ($target_status === 'published' && strtotime($post_created_at) > time()) {
            $target_status = 'scheduled';
        }

        $data = [
            'title'          => trim($_POST['title'] ?? ''),
            'content'        => $_POST['content'] ?? '',
            'content_format' => in_array($_POST['content_format'] ?? '', ['markdown', 'html']) ? $_POST['content_format'] : 'html',
            'excerpt'        => trim($_POST['excerpt'] ?? ''),
            'slug'           => sanitize_filename(trim($_POST['custom_slug'] ?? '') ?: slug_from_title($_POST['title'] ?? '')),
            'status'         => $target_status,
            'categories'     => array_filter(array_map('trim', explode(',', $_POST['categories'] ?? ''))),
            'tags'           => array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))),
            'featured_image' => trim($_POST['featured_image'] ?? ''),
            'mastodon_url'   => trim($_POST['mastodon_url'] ?? ''),
            'kutpod_url'     => trim($_POST['kutpod_url'] ?? ($post['kutpod_url'] ?? '')),
            'podcast_meta'   => $post['podcast_meta'] ?? null,
            'series_id'      => trim($_POST['series_id'] ?? ($post['series_id'] ?? '')),
            'original_slug'  => $slug,
            'created_at'     => $post_created_at,
        ];
        if (!$data['title']) { $error = __("editor_error_title"); }
        else {
            if (cms_plugin_is_active('kutpod')) {
                require_once dirname(__DIR__) . '/plugins/kutpod/kutpod.php';
                if (!empty($data['kutpod_url'])) {
                    $data['podcast_meta'] = kutpod_resolve_meta_from_url($data['kutpod_url'], is_array($data['podcast_meta']) ? $data['podcast_meta'] : []);
                }
            }
            $new_slug = save_content($type, $data);
            if ($type === 'articles' && cms_plugin_is_active('series')) {
                require_once dirname(__DIR__) . '/plugins/series/series.php';
                if (function_exists('series_assign_post')) {
                    $chosenSeries = isset($_POST['series_id']) ? trim($_POST['series_id']) : null;
                    series_assign_post($new_slug, $chosenSeries ?: null);
                }
            }
            $msg = match($target_status) {
                'scheduled' => sprintf(__('editor_scheduled_for', '¡Artículo programado para el %s!'), format_date($post_created_at, 'datetime')),
                'published' => __('editor_published', '¡Artículo publicado con éxito!'),
                default     => __('editor_saved', '¡Guardado correctamente!')
            };
            $slug = $new_slug;
            $post = get_content($type, $new_slug);
            $is_new = false;

            if ($type === 'articles') {
                if (cms_plugin_is_active('fediverse')) {
                    require_once dirname(__DIR__) . '/plugins/fediverse/activitypub.php';
                    if ($post['status'] === 'published') {
                        $resend = !empty($_POST['fediverse_resend']);
                        ap_publish_article($post, $resend);
                    } elseif ($post['status'] === 'draft' || $post['status'] === 'scheduled') {
                        // Si estaba federado y se pasa a borrador o programado, eliminar la publicación en el Fediverso
                        ap_delete_article($slug);
                    }
                }

                // Envío de Newsletter al publicar de inmediato (los programados se enviarán al llegar la fecha)
                if (cms_plugin_is_active('newsletter') && $post['status'] === 'published' && !empty($_POST['newsletter_broadcast'])) {
                    require_once dirname(__DIR__) . '/core/newsletter.php';
                    if (function_exists('newsletter_broadcast_post')) {
                        $nRes = newsletter_broadcast_post($post, !empty($_POST['newsletter_resend']));
                        if (!empty($nRes['sent'])) {
                            $msg .= ' · Boletín enviado a ' . $nRes['sent'] . ' suscriptores';
                        }
                    }
                }
            }
        }
    }
}

$label = $type === 'articles' ? __raw('nav_articles') : __raw('nav_pages');
$content_format = in_array($post['content_format'] ?? '', ['markdown', 'html']) ? $post['content_format'] : 'html';
$post_raw_content = $post['content'] ?? '';
$initial_html = ($content_format === 'markdown' && function_exists('flux_markdown'))
    ? flux_markdown($post_raw_content)
    : $post_raw_content;
$initial_markdown = ($content_format === 'markdown')
    ? $post_raw_content
    : '';
admin_header(($is_new ? __raw('editor_new_article') : __raw('editor_edit_article')), $type);
?>
    </div>
  </div>
  <link rel="stylesheet" href="assets/css/editor.css">
  <script src="assets/js/prosemirror-bundle.min.js"></script>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="editor-form" method="POST">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="content" id="content-input" value="<?= htmlspecialchars($post_raw_content) ?>">
      <input type="hidden" name="content_format" id="content-format-input" value="<?= htmlspecialchars($content_format) ?>">

      <?php
      $cur_status = $post['status'] ?? 'draft';
      if ($is_new && !empty($_GET['date'])) {
          $post['created_at'] = date('c', strtotime($_GET['date'] . ' 09:00:00'));
          if (strtotime($post['created_at']) > time()) {
              $cur_status = 'scheduled';
          }
      }
      $status_label = match($cur_status) {
          'published' => 'Publicado',
          'scheduled' => 'Programado',
          default     => 'Borrador'
      };
      $status_class = match($cur_status) {
          'published' => 'pub',
          'scheduled' => 'scheduled',
          default     => 'draft'
      };
      $save_btn_label = __('save', 'Guardar');
      ?>

      <div class="desk-layout">
        <!-- Main Writing Desk Area -->
        <div class="desk-main">
          <div class="desk-sheet">
            <!-- Sheet Chrome (Action Line) -->
            <div class="desk-chrome">
              <div class="desk-chrome-left">
                <a href="<?= base_url() ?>/admin/<?= $type ?>.php" class="desk-chrome-back">
                  ← <?= $type === 'articles' ? 'Entradas' : 'Páginas' ?>
                </a>
                <span class="sheet-status-pill <?= $status_class ?>" id="desk-status-pill">
                  <?= $status_label ?>
                </span>
                <span class="desk-metrics" id="desk-metrics">0 palabras · 1 min de lectura</span>
              </div>
              <div class="desk-chrome-right">
                <!-- Editor Mode Switcher: WYSIWYG, RAW, Markdown -->
                <div class="editor-mode-switcher" role="group" aria-label="Modo de edición">
                  <button type="button" class="mode-btn <?= $content_format !== 'markdown' ? 'active' : '' ?>" id="btn-mode-wysiwyg" data-mode="wysiwyg" title="Editor Visual (WYSIWYG)">WYSIWYG</button>
                  <button type="button" class="mode-btn" id="btn-mode-raw" data-mode="raw" title="Código HTML directo (RAW)">RAW</button>
                  <button type="button" class="mode-btn <?= $content_format === 'markdown' ? 'active' : '' ?>" id="btn-mode-markdown" data-mode="markdown" title="Editor Markdown">Markdown</button>
                </div>
                <?php if ($post && ($post['status'] ?? '') === 'published'): ?>
                <a href="<?= base_url() ?>/<?= $type === 'articles' ? 'article' : 'page' ?>/<?= htmlspecialchars($post['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Ver en el sitio web">Ver ↗</a>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary btn-sm" id="preview-btn" title="Previsualizar">Vista previa</button>
                <button type="submit" form="editor-form" class="btn btn-primary btn-sm" id="publish-btn" title="<?= __("save") ?>">
                  <?= $save_btn_label ?>
                </button>
              </div>
            </div>

            <!-- Title Area (Seamless Editorial Heading) -->
            <div class="desk-title-wrap">
              <input type="text" name="title" id="post-title" class="desk-title-input" placeholder="Título del artículo..."
                value="<?= htmlspecialchars($post['title'] ?? '') ?>" required autocomplete="off">
            </div>

            <!-- Writing Desk: Find & Replace Bar (Ctrl+F) -->
            <div id="find-replace-bar" class="writing-find-bar" style="display:none;">
              <div class="find-row">
                <div class="find-input-wrap">
                  <input type="text" id="find-input" placeholder="Buscar en el texto (Ctrl+F)..." autocomplete="off" spellcheck="false">
                  <span id="find-counter" class="find-counter">0/0</span>
                </div>
                <button type="button" id="find-prev-btn" class="find-btn" title="Anterior (Shift+Enter)">↑</button>
                <button type="button" id="find-next-btn" class="find-btn" title="Siguiente (Enter)">↓</button>
                <button type="button" id="find-toggle-replace" class="find-btn" title="Alternar reemplazo">⇄</button>
                <button type="button" id="find-close-btn" class="find-btn find-close" title="Cerrar (Esc)">✕</button>
              </div>
              <div class="replace-row" id="replace-row" style="display:none;">
                <div class="find-input-wrap">
                  <input type="text" id="replace-input" placeholder="Reemplazar por..." autocomplete="off" spellcheck="false">
                </div>
                <button type="button" id="replace-one-btn" class="find-action-btn">Reemplazar</button>
                <button type="button" id="replace-all-btn" class="find-action-btn">Todo</button>
              </div>
            </div>

            <!-- Writing Canvas Container -->
            <div class="desk-canvas-wrap" style="position:relative;">
              <!-- Writing Desk: Floating Bubble Selection Menu -->
              <div id="bubble-menu" class="writing-bubble-menu" style="display:none;" role="toolbar" aria-label="Formato de texto">
                <button type="button" class="bubble-btn" data-cmd="bold" title="Negrita (Ctrl+B)"><b>B</b></button>
                <button type="button" class="bubble-btn" data-cmd="italic" title="Cursiva (Ctrl+I)"><i>I</i></button>
                <button type="button" class="bubble-btn" data-cmd="underline" title="Subrayado (Ctrl+U)"><u>U</u></button>
                <button type="button" class="bubble-btn" data-cmd="strikeThrough" title="Tachado"><s>S</s></button>
                <button type="button" class="bubble-btn" data-cmd="highlight" title="Resaltador (Highlighter)">
                  <mark class="bubble-pen-mark">A</mark>
                </button>
                <button type="button" class="bubble-btn" data-cmd="code" title="Código en línea"><code>&lt;&gt;</code></button>
                <div class="bubble-sep"></div>
                <button type="button" class="bubble-btn" data-cmd="align-left" title="Alinear a la izquierda (Ctrl+Shift+L)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="21" y1="6" x2="3" y2="6"></line>
                    <line x1="15" y1="12" x2="3" y2="12"></line>
                    <line x1="19" y1="18" x2="3" y2="18"></line>
                  </svg>
                </button>
                <button type="button" class="bubble-btn" data-cmd="align-center" title="Alinear al centro (Ctrl+Shift+E)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="6"></line>
                    <line x1="21" y1="12" x2="3" y2="12"></line>
                    <line x1="18" y1="18" x2="6" y2="18"></line>
                  </svg>
                </button>
                <button type="button" class="bubble-btn" data-cmd="align-right" title="Alinear a la derecha (Ctrl+Shift+R)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="21" y1="6" x2="3" y2="6"></line>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                    <line x1="21" y1="18" x2="5" y2="18"></line>
                  </svg>
                </button>
                <div class="bubble-sep"></div>
                <button type="button" class="bubble-btn" data-cmd="link" title="Enlace">🔗</button>
              </div>

              <!-- Writing Desk: Floating Image Alignment & Size Menu -->
              <div id="image-bubble-menu" class="writing-bubble-menu image-align-toolbar" style="display:none;" role="toolbar" aria-label="Alineación y tamaño de imagen">
                <!-- Group 1: Alignment (Segmented Control) -->
                <div class="img-tb-group" role="group" aria-label="Alineación">
                  <button type="button" class="img-tb-btn bubble-btn" data-img-align="left" title="Alinear a la izquierda (flotar con texto)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="4" width="8" height="8" rx="1"></rect>
                      <line x1="14" y1="5" x2="21" y2="5"></line>
                      <line x1="14" y1="9" x2="21" y2="9"></line>
                      <line x1="3" y1="15" x2="21" y2="15"></line>
                      <line x1="3" y1="19" x2="21" y2="19"></line>
                    </svg>
                  </button>
                  <button type="button" class="img-tb-btn bubble-btn" data-img-align="center" title="Centrar">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <line x1="3" y1="4" x2="21" y2="4"></line>
                      <rect x="7" y="8" width="10" height="8" rx="1"></rect>
                      <line x1="3" y1="20" x2="21" y2="20"></line>
                    </svg>
                  </button>
                  <button type="button" class="img-tb-btn bubble-btn" data-img-align="right" title="Alinear a la derecha (flotar con texto)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="13" y="4" width="8" height="8" rx="1"></rect>
                      <line x1="3" y1="5" x2="10" y2="5"></line>
                      <line x1="3" y1="9" x2="10" y2="9"></line>
                      <line x1="3" y1="15" x2="21" y2="15"></line>
                      <line x1="3" y1="19" x2="21" y2="19"></line>
                    </svg>
                  </button>
                  <button type="button" class="img-tb-btn bubble-btn" data-img-align="full" title="Ancho completo">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <line x1="2" y1="4" x2="22" y2="4"></line>
                      <rect x="2" y="7" width="20" height="10" rx="1"></rect>
                      <line x1="2" y1="20" x2="22" y2="20"></line>
                    </svg>
                  </button>
                </div>

                <div class="img-tb-sep"></div>

                <!-- Group 2: Size Presets & Custom (Segmented Control) -->
                <div class="img-tb-group" role="group" aria-label="Tamaño">
                  <button type="button" class="img-tb-btn bubble-btn img-size-btn" data-size="25%" title="Tamaño: 25%">25%</button>
                  <button type="button" class="img-tb-btn bubble-btn img-size-btn" data-size="50%" title="Tamaño: 50%">50%</button>
                  <button type="button" class="img-tb-btn bubble-btn img-size-btn" data-size="75%" title="Tamaño: 75%">75%</button>
                  <button type="button" class="img-tb-btn bubble-btn img-size-btn" data-size="100%" title="Tamaño: 100% (Original)">100%</button>
                  <button type="button" class="img-tb-btn bubble-btn" id="img-btn-custom-size" title="Personalizar tamaño (px o %)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M15 3h6v6"></path>
                      <path d="M9 21H3v-6"></path>
                      <path d="M21 3l-7 7"></path>
                      <path d="M3 21l7-7"></path>
                    </svg>
                  </button>
                </div>

                <div class="img-tb-sep"></div>

                <!-- Group 3: Alt Text & Delete -->
                <div class="img-tb-group" role="group" aria-label="Acciones">
                  <button type="button" class="img-tb-btn bubble-btn img-tb-alt" id="img-btn-alt" title="Editar texto alternativo (alt)">ALT</button>
                  <button type="button" class="img-tb-btn bubble-btn img-tb-delete" id="img-btn-delete" title="Eliminar imagen">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="3 6 5 6 21 6"></polyline>
                      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                      <line x1="10" y1="11" x2="10" y2="17"></line>
                      <line x1="14" y1="11" x2="14" y2="17"></line>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Writing Desk: Slash Command Palette (/) -->
              <div id="slash-menu" class="writing-slash-menu" style="display:none;" role="menu" aria-label="Insertar bloque">
                <div class="slash-header">Insertar bloque (ProseMirror)</div>
                <div class="slash-items">
                  <div class="slash-item active" data-cmd="h2" role="menuitem">
                    <span class="slash-icon">H2</span>
                    <div class="slash-text">
                      <span class="slash-title">Título 2</span>
                      <span class="slash-desc">Encabezado de sección</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="h3" role="menuitem">
                    <span class="slash-icon">H3</span>
                    <div class="slash-text">
                      <span class="slash-title">Título 3</span>
                      <span class="slash-desc">Subencabezado secundario</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="quote" role="menuitem">
                    <span class="slash-icon">❝</span>
                    <div class="slash-text">
                      <span class="slash-title">Cita</span>
                      <span class="slash-desc">Bloque de cita editorial</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="callout" role="menuitem">
                    <span class="slash-icon">💡</span>
                    <div class="slash-text">
                      <span class="slash-title">Nota destacada</span>
                      <span class="slash-desc">Recuadro informativo callout</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="code" role="menuitem">
                    <span class="slash-icon">{ }</span>
                    <div class="slash-text">
                      <span class="slash-title">Bloque de código</span>
                      <span class="slash-desc">Código con fondo oscuro</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="ul" role="menuitem">
                    <span class="slash-icon">•</span>
                    <div class="slash-text">
                      <span class="slash-title">Lista con viñetas</span>
                      <span class="slash-desc">Lista desordenada</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="ol" role="menuitem">
                    <span class="slash-icon">1.</span>
                    <div class="slash-text">
                      <span class="slash-title">Lista numerada</span>
                      <span class="slash-desc">Lista de pasos ordenada</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="image" role="menuitem">
                    <span class="slash-icon">🖼</span>
                    <div class="slash-text">
                      <span class="slash-title">Imagen</span>
                      <span class="slash-desc">Insertar imagen o foto</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="audio" role="menuitem">
                    <span class="slash-icon">🎵</span>
                    <div class="slash-text">
                      <span class="slash-title">Audio</span>
                      <span class="slash-desc">Reproductor MP3, podcast o sonido</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="video" role="menuitem">
                    <span class="slash-icon">🎬</span>
                    <div class="slash-text">
                      <span class="slash-title">Video</span>
                      <span class="slash-desc">YouTube, Vimeo o archivo MP4/WebM</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="hr" role="menuitem">
                    <span class="slash-icon">―</span>
                    <div class="slash-text">
                      <span class="slash-title">Separador</span>
                      <span class="slash-desc">Línea divisoria horizontal</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="align-left" role="menuitem">
                    <span class="slash-icon">⇤</span>
                    <div class="slash-text">
                      <span class="slash-title">Alinear a la izquierda</span>
                      <span class="slash-desc">Alinear el texto a la izquierda</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="align-center" role="menuitem">
                    <span class="slash-icon">≡</span>
                    <div class="slash-text">
                      <span class="slash-title">Centrar texto</span>
                      <span class="slash-desc">Alinear el párrafo al centro</span>
                    </div>
                  </div>
                  <div class="slash-item" data-cmd="align-right" role="menuitem">
                    <span class="slash-icon">⇥</span>
                    <div class="slash-text">
                      <span class="slash-title">Alinear a la derecha</span>
                      <span class="slash-desc">Alinear el párrafo a la derecha</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ProseMirror Mount Target -->
              <div id="prosemirror-mount" class="desk-prosemirror-mount" style="<?= $content_format === 'markdown' ? 'display:none;' : '' ?>"></div>

              <!-- Fallback / Legacy Visual Editor (hidden when ProseMirror active) -->
              <div id="editor" class="prose-editor" contenteditable="true" style="display:none;"><?= $initial_html ?></div>

              <!-- RAW Editor Textarea (hidden by default) -->
              <textarea id="raw-editor" class="desk-raw-editor" style="display:none;"><?= htmlspecialchars($initial_html) ?></textarea>

              <!-- Markdown Editor Textarea -->
              <textarea id="markdown-editor" class="desk-raw-editor desk-markdown-editor" style="<?= $content_format === 'markdown' ? 'display:block;' : 'display:none;' ?>" placeholder="Escribe aquí en formato Markdown..."><?= htmlspecialchars($initial_markdown) ?></textarea>
            </div>
          </div>
        </div>

        <!-- Attributes Inspector Sheet (quireINK Aesthetic) -->
        <aside class="editor-sidebar editor-sidebar-sheet">
          <div class="sheet-head">
            <span class="sheet-head-title">Propiedades</span>
            <span class="sheet-status-pill <?= $status_class ?>" id="sheet-header-pill">
              <?= $status_label ?>
            </span>
          </div>

          <!-- 1. Estado de publicación (Segmented Control) -->
          <div class="sheet-group">
            <label class="sheet-label">Estado de publicación</label>
            <div class="sheet-segmented">
              <label class="sheet-seg-item <?= $cur_status === 'draft' ? 'active' : '' ?>">
                <input type="radio" name="status_radio" value="draft" <?= $cur_status === 'draft' ? 'checked' : '' ?> class="sheet-seg-radio">
                <span class="sheet-dot draft"></span>
                <span><?= __("status_draft") ?></span>
              </label>
              <label class="sheet-seg-item <?= $cur_status === 'scheduled' ? 'active' : '' ?>">
                <input type="radio" name="status_radio" value="scheduled" <?= $cur_status === 'scheduled' ? 'checked' : '' ?> class="sheet-seg-radio">
                <span class="sheet-dot scheduled"></span>
                <span><?= __("status_scheduled", "Programado") ?></span>
              </label>
              <label class="sheet-seg-item <?= $cur_status === 'published' ? 'active' : '' ?>">
                <input type="radio" name="status_radio" value="published" <?= $cur_status === 'published' ? 'checked' : '' ?> class="sheet-seg-radio">
                <span class="sheet-dot pub"></span>
                <span><?= __("status_published") ?></span>
              </label>
            </div>
            <!-- Campo oculto sincronizado para compatibilidad con form submit y scripts -->
            <input type="hidden" name="status" id="status-select" value="<?= htmlspecialchars($cur_status) ?>">
          </div>

          <!-- 2. Fecha de publicación -->
          <div class="sheet-group">
            <div class="sheet-label-split" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
              <label class="sheet-label" for="post-created-at" style="margin-bottom:0">Fecha de publicación</label>
              <button type="button" id="btn-set-now" class="sheet-now-btn" style="background:none;border:none;color:var(--accent);font-size:0.75rem;cursor:pointer;padding:0;font-weight:600">Ahora</button>
            </div>
            <input type="datetime-local" name="created_at" id="post-created-at"
              value="<?= htmlspecialchars(isset($post['created_at']) && $post['created_at'] ? date('Y-m-d\TH:i', strtotime($post['created_at'])) : date('Y-m-d\TH:i')) ?>"
              style="width:100%;padding:0.5rem 0.75rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);color:var(--text);font-size:0.88rem;outline:none">
            <div class="sheet-hint" id="date-hint">Modifica la fecha para retroceder la publicación o fijar una fecha futura para programarla.</div>
          </div>

          <!-- 3. URL Slug -->
          <div class="sheet-group">
            <label class="sheet-label" for="custom-slug">Slug de enlace</label>
            <div class="sheet-field-prefix">
              <span class="sheet-prefix-text">/<?= $type === 'articles' ? 'article' : 'page' ?>/</span>
              <input type="text" name="custom_slug" id="custom-slug"
                value="<?= htmlspecialchars($post['slug'] ?? '') ?>"
                placeholder="<?= __raw("panel_slug") ?>">
            </div>
            <div class="sheet-hint"><?= __("panel_slug_hint") ?></div>
          </div>

          <!-- 3. Extracto / Excerpt -->
          <div class="sheet-group">
            <div class="sheet-label-split">
              <label class="sheet-label" for="post-excerpt">Extracto</label>
              <span class="sheet-count" id="excerpt-counter"><?= mb_strlen($post['excerpt'] ?? '') ?>/200</span>
            </div>
            <textarea name="excerpt" id="post-excerpt" rows="3" maxlength="200" placeholder="<?= __raw("panel_excerpt_ph") ?>"><?= htmlspecialchars($post['excerpt'] ?? '') ?></textarea>
            <div class="sheet-hint">Breve resumen visible en listados y tarjetas sociales.</div>
          </div>

          <?php if ($type === 'articles'): ?>
          <!-- 4. Categorías -->
          <div class="sheet-group">
            <label class="sheet-label">Categorías</label>
            <div class="tag-input-wrap" id="cat-wrap">
              <div class="tag-chips" id="cat-chips"></div>
              <input type="text" id="cat-input" placeholder="<?= __raw("panel_cat_ph") ?>" autocomplete="off">
              <input type="hidden" name="categories" id="cat-hidden"
                value="<?= htmlspecialchars(implode(', ', $post['categories'] ?? [])) ?>">
            </div>
            <?php if (!empty($all_cats)): ?>
            <div class="tag-suggestions" id="cat-suggestions">
              <?php foreach ($all_cats as $c): ?>
              <span class="tag-sug" data-target="cat">+ <?= htmlspecialchars($c) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- 5. Etiquetas -->
          <div class="sheet-group">
            <label class="sheet-label">Etiquetas</label>
            <div class="tag-input-wrap" id="tag-wrap" style="position:relative">
              <div class="tag-chips" id="tag-chips"></div>
              <input type="text" id="tag-input" placeholder="<?= __raw("panel_tag_ph") ?>" autocomplete="off">
              <input type="hidden" name="tags" id="tag-hidden"
                value="<?= htmlspecialchars(implode(', ', $post['tags'] ?? [])) ?>">
            </div>
            <!-- Inline autocomplete dropdown -->
            <div id="tag-dropdown" class="tag-dropdown" style="display:none">
              <div id="tag-dropdown-list"></div>
            </div>
            <div class="sheet-hint">Escribe y presiona Enter para añadir.</div>
            <!-- Hidden data list for JS -->
            <div id="all-tags-data" style="display:none"><?php
              foreach ($all_tags_list as $t) echo '<span>' . htmlspecialchars($t) . '</span>';
            ?></div>
            <?php if (!empty($all_tags_list)): ?>
            <div class="tag-suggestions" id="tag-quick-suggestions">
              <?php foreach (array_slice($all_tags_list, 0, 8) as $t): ?>
              <span class="tag-sug tag-quick-sug">+ <?= htmlspecialchars($t) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <?php if (cms_plugin_is_active('series')): 
            require_once dirname(__DIR__) . '/plugins/series/series.php';
            $all_editor_series = function_exists('series_get_all') ? series_get_all() : [];
            $cur_series_assigned = function_exists('series_get_for_post') ? series_get_for_post($slug ?: ($post['slug'] ?? '')) : null;
            $selected_series_id = $cur_series_assigned['id'] ?? ($post['series_id'] ?? '');
          ?>
          <!-- Serie de Artículos -->
          <div class="sheet-group">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem">
              <label class="sheet-label" for="series-select" style="margin:0">Serie de artículos</label>
              <a href="<?= base_url() ?>/admin/plugins.php?action=settings&plugin=series" target="_blank" style="font-size:0.75rem;color:var(--accent);text-decoration:none">+ Gestionar</a>
            </div>
            <select name="series_id" id="series-select" class="form-control" style="width:100%;padding:0.45rem 0.6rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:0.85rem">
              <option value="">— Ninguna serie —</option>
              <?php foreach ($all_editor_series as $s): ?>
                <option value="<?= htmlspecialchars($s['id']) ?>" <?= $selected_series_id === $s['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($s['title']) ?> (<?= count($s['posts'] ?? []) ?> entregas)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="sheet-hint">Agrupa este artículo dentro de una secuencia o tutorial ordenado.</div>
          </div>
          <?php endif; ?>
          <?php endif; ?>

          <!-- 6. Imagen Destacada (Picture Slot) -->
          <div class="sheet-group">
            <label class="sheet-label">Imagen Destacada</label>
            <?php
              $featuredRaw = $post['featured_image'] ?? '';
              $featuredPreview = $featuredRaw;
              $kutpodInstanceUrl = '';
              if (cms_plugin_is_active('kutpod') && function_exists('kutpod_config')) {
                  $kpCfg = kutpod_config();
                  $kutpodInstanceUrl = rtrim($kpCfg['instance_url'] ?? '', '/');
                  if ($kutpodInstanceUrl && str_starts_with($featuredPreview, '/media/covers/')) {
                      $featuredPreview = $kutpodInstanceUrl . $featuredPreview;
                  }
              }
            ?>
            <div class="sheet-picture-slot">
              <div id="featured-preview" class="sheet-picture-frame" style="<?= empty($featuredRaw) ? 'display:none;' : '' ?>">
                <img src="<?= htmlspecialchars($featuredPreview) ?>" alt="" id="featured-img" class="sheet-picture-img">
              </div>
              <div id="featured-empty-slot" class="sheet-picture-empty" style="<?= empty($featuredRaw) ? '' : 'display:none;' ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <span>Sin imagen seleccionada</span>
              </div>
              <div class="sheet-picture-actions">
                <label class="btn btn-secondary btn-sm" style="cursor:pointer">
                  <span>Elegir archivo</span>
                  <input type="file" id="feat-upload" accept="image/*" style="display:none">
                </label>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openFeaturedImagePicker()">Biblioteca</button>
                <button type="button" id="feat-drop-btn" class="btn btn-danger btn-sm" style="<?= empty($featuredRaw) ? 'display:none;' : '' ?>">Quitar</button>
              </div>
              <input type="text" name="featured_image" id="featured_image_input" placeholder="O pega una URL: https://…"
                value="<?= htmlspecialchars($featuredRaw) ?>" style="font-size:0.82rem;">
              <div id="feat-upload-progress" style="display:none;font-size:0.78rem;color:var(--muted);margin-top:0.35rem"></div>
            </div>
          </div>

          <?php if (!cms_plugin_is_active('fediverse')): ?>
          <div class="sheet-group">
            <label class="sheet-label">URL de Mastodon</label>
            <input type="text" name="mastodon_url" placeholder="<?= __raw("panel_mastodon_ph") ?>"
              value="<?= htmlspecialchars($post['mastodon_url'] ?? '') ?>">
            <div class="sheet-hint"><?= __("panel_mastodon_hint") ?></div>
          </div>
          <?php endif; ?>

          <?php if (cms_plugin_is_active('kutpod')): 
              $kpMeta = $post['podcast_meta'] ?? null;
          ?>
          <div class="sheet-group">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
              <label class="sheet-label" style="margin-bottom:0">🎙️ KutPod Podcast</label>
              <?php if ($kpMeta): ?>
                <span class="badge badge-green" style="font-size:0.7rem; padding:1px 6px;">Sincronizado</span>
              <?php endif; ?>
            </div>
            
            <?php if ($kpMeta): ?>
              <div style="font-size:0.82rem; margin-bottom:0.75rem; background:var(--surface2); border:1px solid var(--border); padding:8px 10px; border-radius:6px;">
                <div style="font-weight:600; color:var(--text);"><?= htmlspecialchars($kpMeta['podcast_title'] ?: 'Podcast') ?></div>
                <div style="color:var(--muted); margin-top:2px;">
                  Temp <?= $kpMeta['season'] ?? 1 ?> · Ep <?= $kpMeta['number'] ?? 0 ?> · <?= htmlspecialchars($kpMeta['duration_fmt'] ?? '') ?>
                </div>
                <?php if (!empty($kpMeta['downloads'])): ?>
                  <div style="color:var(--muted); margin-top:2px;">🎧 <?= number_format($kpMeta['downloads']) ?> descargas</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <input type="text" name="kutpod_url" placeholder="https://kutpod.com/@show/ep"
              value="<?= htmlspecialchars($post['kutpod_url'] ?? '') ?>">
            <div class="sheet-hint">Pega aquí la URL pública del episodio para incrustar el reproductor en este post.</div>
          </div>
          <?php endif; ?>

          <?php if (cms_plugin_is_active('fediverse') && $post && $post['status'] === 'published'): 
              require_once dirname(__DIR__) . '/plugins/fediverse/activitypub.php';
              $status = ap_get_post_delivery_status($post['slug']);
          ?>
          <div class="sheet-group">
            <label class="sheet-label">Fediverso (ActivityPub)</label>
            <div style="margin-bottom:0.75rem; padding-bottom:0.75rem; border-bottom:1px solid var(--border)">
              <?php if (!$status): ?>
                <div style="font-size:0.82rem; color:var(--muted)">Este artículo aún no ha sido federado.</div>
              <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:0.35rem; font-size:0.82rem">
                  <?php if ($status['total'] === 0): ?>
                    <div style="color:var(--text); display:flex; align-items:center; gap:0.35rem">
                      <span style="font-size:1rem">📢</span> Publicado en Outbox local (sin seguidores).
                    </div>
                  <?php elseif ($status['pending'] > 0): ?>
                    <div style="color:var(--yellow); display:flex; align-items:center; gap:0.35rem">
                      <span style="font-size:1rem">⏳</span> Enviando: <strong><?= $status['delivered'] ?> / <?= $status['total'] ?></strong>
                    </div>
                  <?php elseif ($status['failed'] > 0 && $status['delivered'] === 0): ?>
                    <div style="color:var(--red); display:flex; align-items:center; gap:0.35rem">
                      <span style="font-size:1rem">⚠</span> Envío fallido.
                    </div>
                  <?php else: ?>
                    <div style="color:var(--green); display:flex; align-items:center; gap:0.35rem">
                      <span style="font-size:1rem">✓</span> Compartido con éxito en <strong><?= $status['delivered'] ?></strong> servidores.
                    </div>
                  <?php endif; ?>
                  <div style="font-size:0.72rem; color:var(--muted)">
                    Última federación: <?= format_date($status['published'], 'datetime') ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.82rem; color:var(--text)">
              <input type="checkbox" name="fediverse_resend" value="1" style="width:auto; margin:0">
              <strong>Reenviar al Fediverso</strong>
            </label>
            <div class="sheet-hint">
              Vuelve a compartir este artículo con tus seguidores.
            </div>
          </div>
          <?php endif; ?>

          <?php if ($post && function_exists('get_content_backups')): 
              $backups = get_content_backups($type, $slug);
              if (!empty($backups)): ?>
          <div class="sheet-group" id="panel-version-history">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem">
              <label class="sheet-label" style="margin-bottom:0">Historial de versiones</label>
              <button type="button" class="btn btn-danger btn-sm" onclick="clearAllBackups('<?= htmlspecialchars($type) ?>', '<?= htmlspecialchars($slug) ?>')" style="padding:0.15rem 0.5rem;font-size:0.7rem;" title="Limpiar todas las versiones">
                Limpiar todo
              </button>
            </div>
            <div class="sheet-hint" style="margin-bottom:0.5rem">
              Últimas 10 versiones guardadas.
            </div>
            <div id="backup-list-container" style="max-height:200px; overflow-y:auto; border:1px solid var(--border); border-radius:6px; padding:0.25rem;">
              <?php foreach ($backups as $backup): ?>
              <div id="backup-item-<?= htmlspecialchars($backup['timestamp']) ?>" style="padding:0.4rem 0.5rem; border-bottom:1px solid var(--border2); font-size:0.78rem; display:flex; justify-content:space-between; align-items:center; gap:0.4rem;">
                <span style="font-family:var(--font-mono);font-size:0.72rem"><?= htmlspecialchars($backup['date']) ?></span>
                <div style="display:flex;gap:0.3rem;align-items:center">
                  <button type="button" class="btn btn-secondary btn-sm" 
                          onclick="previewBackup('<?= htmlspecialchars($backup['timestamp']) ?>', '<?= htmlspecialchars($type) ?>', '<?= htmlspecialchars($slug) ?>')"
                          style="padding:0.15rem 0.45rem; font-size:0.68rem;">
                    Ver
                  </button>
                  <button type="button" class="btn btn-danger btn-sm" 
                          onclick="deleteBackup('<?= htmlspecialchars($backup['timestamp']) ?>', '<?= htmlspecialchars($type) ?>', '<?= htmlspecialchars($slug) ?>')"
                          style="padding:0.15rem 0.4rem; font-size:0.68rem;" title="Eliminar este respaldo">
                    ✕
                  </button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; endif; ?>

          <?php if ($type === 'articles' && cms_plugin_is_active('newsletter')): 
              require_once dirname(__DIR__) . '/core/newsletter.php';
              $newsSubs = function_exists('newsletter_get_subscribers') ? newsletter_get_subscribers() : [];
              $activeSubsCount = count(array_filter($newsSubs, fn($s) => ($s['status'] ?? '') === 'confirmed'));
              $newsSentAt = $post['newsletter_sent_at'] ?? '';
          ?>
          <div class="sheet-group">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
              <label class="sheet-label" style="margin-bottom:0">✉️ Newsletter</label>
              <?php if ($newsSentAt): ?>
                <span class="badge" style="background:rgba(16,185,129,0.12); color:#10b981; font-size:0.7rem; border:1px solid rgba(16,185,129,0.25);">Enviado</span>
              <?php endif; ?>
            </div>

            <?php if ($newsSentAt): ?>
              <div style="font-size:0.75rem; color:var(--muted); margin-bottom:0.6rem;">
                Enviado a suscriptores el <?= format_date($newsSentAt, 'datetime') ?>.
              </div>
              <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.82rem; color:var(--text)">
                <input type="checkbox" name="newsletter_resend" value="1" style="width:auto; margin:0">
                <strong>Reenviar a los suscriptores</strong>
              </label>
              <input type="hidden" name="newsletter_broadcast" value="1">
            <?php else: ?>
              <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.82rem; color:var(--text)">
                <input type="checkbox" name="newsletter_broadcast" value="1" style="width:auto; margin:0">
                <span>Enviar a suscriptores al publicar</span>
              </label>
              <div class="sheet-hint">
                Se enviará en formato boletín a <?= $activeSubsCount ?> lectores confirmados.
              </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($post): ?>
          <div class="sheet-group">
            <label class="sheet-label" style="color:var(--ink-danger)">Zona de peligro</label>
            <a href="<?= base_url() ?>/admin/delete.php?type=<?= $type ?>&slug=<?= htmlspecialchars($slug) ?>&csrf=<?= $csrf ?>"
               class="btn btn-danger" style="width:100%;justify-content:center"
               onclick="return confirm('<?= __raw("confirm_delete_article") ?>')">
              Eliminar <?= $label ?>
            </a>
          </div>
          <?php endif; ?>
        </aside>
      </div>
    </form>
  </div>
</div>


<style>
/* ── Editor Mode Switcher (WYSIWYG, RAW, Markdown) ───────────── */
.editor-mode-switcher {
  display: inline-flex;
  align-items: center;
  background: var(--surface2);
  border: 1px solid var(--border2);
  border-radius: 8px;
  padding: 2px;
  gap: 2px;
  user-select: none;
}
body.scheme-light .editor-mode-switcher {
  background: rgba(0, 0, 0, 0.05);
  border-color: rgba(0, 0, 0, 0.1);
}
.editor-mode-switcher .mode-btn {
  background: transparent;
  border: none;
  color: var(--muted);
  font-size: 0.76rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
  line-height: 1.2;
  font-family: inherit;
}
.editor-mode-switcher .mode-btn:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.06);
}
body.scheme-light .editor-mode-switcher .mode-btn:hover {
  background: rgba(0, 0, 0, 0.04);
}
.editor-mode-switcher .mode-btn.active {
  background: var(--surface);
  color: var(--accent);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
}
body.scheme-light .editor-mode-switcher .mode-btn.active {
  background: #ffffff;
  color: var(--accent);
}

.desk-markdown-editor {
  font-family: var(--font-mono, 'JetBrains Mono', 'Fira Code', Menlo, Consolas, monospace) !important;
  font-size: 0.95rem !important;
  line-height: 1.7 !important;
  tab-size: 2 !important;
}

/* ── Writing Desk: Floating Bubble Menu ──────────────────────── */
.writing-bubble-menu {
  position: absolute;
  z-index: 1200;
  background: var(--surface);
  border: 1px solid var(--border2);
  border-radius: 8px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  display: flex;
  align-items: center;
  padding: 3px;
  gap: 2px;
  transition: opacity 0.1s ease, transform 0.1s ease;
  user-select: none;
}
.bubble-btn {
  background: none;
  border: none;
  color: var(--text);
  padding: 4px 7px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  transition: all 0.12s ease;
}
.bubble-btn:hover {
  background: var(--surface2);
  color: var(--text);
}
.bubble-btn svg {
  display: block;
}
.bubble-btn.active {
  background: var(--accent);
  color: #fff;
}
.bubble-btn.active svg {
  stroke: #fff;
}
.bubble-btn-danger:hover {
  background: rgba(244, 63, 94, 0.18) !important;
  color: #f43f5e !important;
}
.bubble-sep {
  width: 1px;
  height: 18px;
  background: var(--border2);
  margin: 0 3px;
}
.bubble-pen-mark {
  background: #d5f856;
  color: #2c3208;
  padding: 1px 4px;
  border-radius: 2px;
  font-weight: 700;
}
body[data-theme="dark"] .bubble-pen-mark, .dark .bubble-pen-mark {
  background: #5e721d;
  color: #f4f7e2;
}

/* ── Writing Desk: Floating Image Alignment & Size Menu ────── */
#image-bubble-menu:not(.is-active) {
  display: none !important;
  visibility: hidden !important;
  opacity: 0 !important;
  pointer-events: none !important;
}

#image-bubble-menu.image-align-toolbar {
  position: fixed !important;
  display: none !important;
  flex-direction: row !important;
  flex-wrap: nowrap !important;
  align-items: center !important;
  justify-content: flex-start !important;
  width: max-content !important;
  min-width: 0 !important;
  max-width: calc(100vw - 24px) !important;
  height: 32px !important;
  min-height: 32px !important;
  max-height: 32px !important;
  padding: 3px 4px !important;
  margin: 0 !important;
  gap: 4px !important;
  background: rgba(24, 24, 27, 0.96) !important;
  backdrop-filter: blur(12px) !important;
  -webkit-backdrop-filter: blur(12px) !important;
  border: 1px solid rgba(255, 255, 255, 0.12) !important;
  border-radius: 8px !important;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.45) !important;
  z-index: 9999 !important;
  user-select: none !important;
  box-sizing: border-box !important;
}

#image-bubble-menu.image-align-toolbar.is-active {
  display: inline-flex !important;
  visibility: visible !important;
  opacity: 1 !important;
  pointer-events: auto !important;
}

body.scheme-light #image-bubble-menu.image-align-toolbar {
  background: rgba(255, 255, 255, 0.96) !important;
  border-color: rgba(0, 0, 0, 0.12) !important;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.15) !important;
}

#image-bubble-menu .img-tb-group {
  display: inline-flex !important;
  flex-direction: row !important;
  flex-wrap: nowrap !important;
  align-items: center !important;
  height: 26px !important;
  gap: 2px !important;
  background: rgba(255, 255, 255, 0.06) !important;
  border: 1px solid rgba(255, 255, 255, 0.08) !important;
  border-radius: 6px !important;
  padding: 1px 2px !important;
  margin: 0 !important;
  box-sizing: border-box !important;
  flex: 0 0 auto !important;
}
body.scheme-light #image-bubble-menu .img-tb-group {
  background: rgba(0, 0, 0, 0.04) !important;
  border-color: rgba(0, 0, 0, 0.08) !important;
}

#image-bubble-menu .img-tb-btn {
  background: transparent !important;
  border: none !important;
  color: rgba(255, 255, 255, 0.72) !important;
  height: 22px !important;
  min-width: 24px !important;
  padding: 0 5px !important;
  margin: 0 !important;
  border-radius: 4px !important;
  cursor: pointer !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-family: inherit !important;
  font-size: 11px !important;
  font-weight: 500 !important;
  line-height: 1 !important;
  transition: all 0.12s ease !important;
  box-sizing: border-box !important;
  flex: 0 0 auto !important;
  flex-shrink: 0 !important;
  text-decoration: none !important;
  float: none !important;
  clear: none !important;
  position: static !important;
}
body.scheme-light #image-bubble-menu .img-tb-btn {
  color: rgba(0, 0, 0, 0.7) !important;
}
#image-bubble-menu .img-tb-btn:hover {
  background: rgba(255, 255, 255, 0.12) !important;
  color: #ffffff !important;
}
body.scheme-light #image-bubble-menu .img-tb-btn:hover {
  background: rgba(0, 0, 0, 0.08) !important;
  color: #000000 !important;
}
#image-bubble-menu .img-tb-btn.active {
  background: var(--accent) !important;
  color: #ffffff !important;
  font-weight: 600 !important;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25) !important;
}
#image-bubble-menu .img-tb-btn.active svg {
  stroke: #ffffff !important;
}
#image-bubble-menu .img-tb-btn svg {
  display: block !important;
  pointer-events: none !important;
  margin: 0 !important;
  width: 13px !important;
  height: 13px !important;
}

#image-bubble-menu .img-tb-btn[data-size] {
  min-width: 32px !important;
  padding: 0 4px !important;
  font-size: 10.5px !important;
  font-weight: 600 !important;
  font-variant-numeric: tabular-nums !important;
  letter-spacing: -0.01em !important;
}

#image-bubble-menu .img-tb-sep {
  width: 1px !important;
  height: 14px !important;
  background: rgba(255, 255, 255, 0.12) !important;
  margin: 0 1px !important;
  flex: 0 0 1px !important;
}
body.scheme-light #image-bubble-menu .img-tb-sep {
  background: rgba(0, 0, 0, 0.1) !important;
}

#image-bubble-menu .img-tb-alt {
  font-size: 10px !important;
  font-weight: 700 !important;
  letter-spacing: 0.04em !important;
  padding: 0 5px !important;
  min-width: 26px !important;
}

#image-bubble-menu .img-tb-delete {
  color: #f87171 !important;
}
#image-bubble-menu .img-tb-delete:hover {
  background: rgba(239, 68, 68, 0.2) !important;
  color: #ef4444 !important;
}

/* ── Writing Desk: Slash Command Palette ─────────────────────── */
.writing-slash-menu {
  position: absolute;
  z-index: 1250;
  background: var(--surface);
  border: 1px solid var(--border2);
  border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.35);
  width: 290px;
  max-height: 340px;
  overflow-y: auto;
  padding: 5px;
  user-select: none;
}
.writing-slash-menu::-webkit-scrollbar { width: 5px; }
.writing-slash-menu::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
.slash-header {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--muted);
  padding: 6px 10px 4px;
}
.slash-items {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.slash-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.12s;
}
.slash-item:hover, .slash-item.active {
  background: var(--surface2);
}
.slash-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: var(--bg);
  border: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  color: var(--accent);
  flex-shrink: 0;
}
.slash-text {
  min-width: 0;
}
.slash-title {
  display: block;
  font-size: 0.83rem;
  font-weight: 600;
  color: var(--text);
}
.slash-desc {
  display: block;
  font-size: 0.72rem;
  color: var(--muted);
}

/* ── Writing Desk: Find & Replace Bar ────────────────────────── */
.writing-find-bar {
  position: absolute;
  top: 44px;
  right: 18px;
  z-index: 1100;
  width: 360px;
  background: var(--surface);
  border: 1px solid var(--border2);
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.25);
  padding: 6px 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.find-row, .replace-row {
  display: flex;
  align-items: center;
  gap: 4px;
}
.find-input-wrap {
  display: flex;
  align-items: center;
  background: var(--bg);
  border: 1px solid var(--border2);
  border-radius: 5px;
  padding: 0 6px;
  flex: 1;
}
.find-input-wrap input {
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  padding: 4px 6px !important;
  font-size: 0.82rem !important;
  color: var(--text) !important;
  width: 100% !important;
  outline: none !important;
}
.find-counter {
  font-size: 0.72rem;
  color: var(--muted);
  white-space: nowrap;
  padding-right: 4px;
  font-variant-numeric: tabular-nums;
}
.find-btn {
  background: var(--surface2);
  border: 1px solid var(--border);
  color: var(--text);
  border-radius: 5px;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 13px;
  transition: all 0.12s;
  flex-shrink: 0;
}
.find-btn:hover { background: var(--border); }
.find-close:hover { color: var(--red); }
.find-action-btn {
  background: var(--surface2);
  border: 1px solid var(--border);
  color: var(--text);
  border-radius: 5px;
  padding: 4px 8px;
  font-size: 0.75rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.12s;
  white-space: nowrap;
}
.find-action-btn:hover { background: var(--border); }

/* Highlights for matches */
mark.find-match {
  background: #d5f856;
  color: #2c3208;
  border-radius: 2px;
}
mark.find-match.current {
  background: #f59e0b;
  color: #fff;
  outline: 2px solid #f59e0b;
}

/* Callouts in editor & rendering */
.callout-note {
  margin: 1.25rem 0;
  padding: 12px 16px;
  border-radius: 8px;
  border-left: 3px solid var(--accent);
  background: rgba(var(--accent-rgb), 0.08);
  font-size: 0.95rem;
  color: var(--text);
}

.desk-layout,
.editor-layout {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) 330px !important;
  gap: 1.5rem !important;
  align-items: start !important;
  width: 100% !important;
}

@media (max-width: 1024px) {
  .desk-layout,
  .editor-layout {
    grid-template-columns: 1fr !important;
  }
}

.desk-main {
  min-width: 0 !important;
}

.desk-sheet {
  background: var(--surface) !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--radius) !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
  padding: 2.25rem 3rem 5rem !important;
  min-height: calc(100vh - 120px) !important;
  position: relative !important;
}

@media (max-width: 768px) {
  .desk-sheet {
    padding: 1.5rem 1.25rem 4rem !important;
  }
}

.desk-chrome {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding-bottom: 1.25rem !important;
  margin-bottom: 2rem !important;
  border-bottom: 1px solid var(--border) !important;
  gap: 1rem !important;
  flex-wrap: wrap !important;
}

.desk-chrome-left {
  display: flex !important;
  align-items: center !important;
  gap: 0.75rem !important;
}

.desk-chrome-back {
  color: var(--muted) !important;
  text-decoration: none !important;
  font-size: 0.85rem !important;
  font-weight: 500 !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 0.35rem !important;
  padding: 4px 8px !important;
  border-radius: 6px !important;
  transition: all 0.12s !important;
}

.desk-chrome-back:hover {
  color: var(--text) !important;
  background: var(--surface2) !important;
}

.desk-metrics {
  font-size: 0.8rem !important;
  color: var(--muted) !important;
  font-family: var(--font-mono) !important;
  padding-left: 0.5rem !important;
  border-left: 1px solid var(--border) !important;
}

.desk-chrome-right {
  display: flex !important;
  align-items: center !important;
  gap: 0.5rem !important;
}

.desk-title-input {
  width: 100% !important;
  border: none !important;
  background: transparent !important;
  outline: none !important;
  font-family: 'Literata', Georgia, serif !important;
  font-size: 2.35rem !important;
  font-weight: 700 !important;
  line-height: 1.25 !important;
  letter-spacing: -0.02em !important;
  color: var(--text) !important;
  margin-bottom: 1.75rem !important;
  padding: 0 !important;
}

.desk-title-input::placeholder {
  color: var(--muted) !important;
  opacity: 0.6 !important;
}

.ProseMirror {
  outline: none !important;
  font-family: 'Literata', Georgia, serif !important;
  font-size: 19px !important;
  line-height: 1.75 !important;
  color: var(--text) !important;
  letter-spacing: -0.003em !important;
  min-height: 480px !important;
}

.ProseMirror p {
  margin: 0 0 1.35rem 0 !important;
}

.ProseMirror p.is-editor-empty:first-child::before {
  content: attr(data-placeholder) !important;
  float: left !important;
  color: var(--muted) !important;
  opacity: 0.5 !important;
  pointer-events: none !important;
  height: 0 !important;
}

.editor-sidebar-sheet {
  background: var(--surface) !important;
  border: 1px solid var(--border) !important;
  border-radius: var(--radius) !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06) !important;
  padding: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  gap: 0 !important;
  max-height: calc(100vh - 120px) !important;
  overflow-y: auto !important;
  position: sticky !important;
  top: 15px !important;
  width: 330px !important;
}

@media (max-width: 1024px) {
  .editor-sidebar-sheet {
    width: 100% !important;
    max-width: 100% !important;
    position: static !important;
    max-height: none !important;
  }
}

.editor-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 1.5rem;
  align-items: start;
}
.editor-main {
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  height: calc(100vh - 130px);
  min-height: 500px;
  overflow: hidden;
}

/* ── Format Bar ───────────────────────────────────── */
.format-bar {
  height: 36px;
  background: var(--surface);
  border-bottom: 1px solid var(--border-soft);
  display: flex;
  align-items: center;
  padding: 0 8px;
  gap: 2px;
  flex-shrink: 0;
  z-index: 1000;
  flex-wrap: nowrap;
  overflow-x: auto;
  position: sticky;
  top: 0;
}

.title-area {
  padding: 1.25rem 1.5rem 0;
  border-bottom: 1px solid var(--border-soft);
}

.title-area input {
  background: transparent;
  border: none;
  padding: 0;
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--text);
  letter-spacing: -0.03em;
  width: 100%;
  margin-bottom: 1rem;
}

.title-area input::placeholder {
  color: var(--muted);
}

.title-area input:focus {
  outline: none;
}

/* Ajustar el editor-wrap para que ocupe el espacio restante */
.editor-wrap {
  flex: 1;
  overflow-y: auto;
  position: relative;
  min-height: 0;
  padding: 0;
}

.fmt-select {
  background: none;
  border: none;
  font-family: 'DM Sans', sans-serif;
  font-size: 13px;
  color: var(--text-muted);
  cursor: pointer;
  padding: 4px 6px;
  border-radius: 5px;
  outline: none;
  transition: all .12s;
  min-height: 34px;
  max-width: 140px; /* Nuevo: reducir ancho */
}

/* Ajustar para pantallas más pequeñas */
@media (max-width: 1024px) {
  .fmt-select {
    max-width: 110px;
    font-size: 12px;
    padding: 3px 5px;
    min-height: 30px;
  }
}

@media (max-width: 480px) {
  .fmt-select {
    max-width: 90px;
    font-size: 11px;
    padding: 3px 4px;
    min-height: 28px;
  }
}

.fmt-select:hover {
  background: var(--surface2);
  color: var(--text);
}

.fmt-btn {
  background: none;
  border: none;
  border-radius: 5px;
  min-width: 36px; /* Cambiar de width a min-width */
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 15px;
  font-family: 'DM Sans', sans-serif;
  font-weight: 500;
  transition: all .12s;
  padding: 10px; /* Añadir padding de 10px */
  box-sizing: border-box; /* Para incluir padding en el tamaño total */
}

.fmt-btn.active {
  background: var(--accent-bg);
  color: var(--accent);
  padding: 2px 20px !important; /* Padding específico para active */
}

/* Ajustar el hover */
.fmt-btn:hover {
  background: var(--surface2);
  color: var(--text);
}

/* Fix for markdown preview button */
#md-preview-btn {
  min-width: 36px !important;
  width: 36px !important;
  height: 34px !important;
  padding: 8px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

#md-preview-btn svg {
  width: 16px !important;
  height: 16px !important;
  stroke: currentColor !important;
  fill: none !important;
  display: block !important;
}

/* Ensure SVG icons are properly centered in buttons */
.fmt-btn svg {
  display: block;
  margin: 0 auto;
}

/* Ajustar para responsive */
@media (max-width: 1024px) {
  .fmt-btn {
    padding: 8px;
    min-width: 32px;
    height: 30px;
    font-size: 14px;
  }
  
  .fmt-btn.active {
    padding: 1px 16px !important;
  }
  
  #md-preview-btn {
    min-width: 32px !important;
    width: 32px !important;
    height: 30px !important;
    padding: 6px !important;
  }
  
  #md-preview-btn svg {
    width: 14px !important;
    height: 14px !important;
  }
}

@media (max-width: 480px) {
  .fmt-btn {
    padding: 6px;
    min-width: 30px;
    height: 28px;
    font-size: 13px;
  }
  
  .fmt-btn.active {
    padding: 1px 12px !important;
  }
  
  #md-preview-btn {
    min-width: 30px !important;
    width: 30px !important;
    height: 28px !important;
    padding: 5px !important;
  }
  
  #md-preview-btn svg {
    width: 12px !important;
    height: 12px !important;
  }
}

/* Font size controls */
.font-size-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    min-width: 24px;
    text-align: center;
    font-family: 'DM Sans', sans-serif;
    user-select: none;
}

/* Asegurar que los botones con texto tengan suficiente espacio */
.fmt-btn[title*="Cita"],
.fmt-btn[title*="Código"],
.fmt-btn[title*="Lista"] {
  min-width: 40px;
}

/* Responsive adjustments for font size controls */
@media (max-width: 1024px) {
    .font-size-label {
        font-size: 12px;
        min-width: 22px;
    }
}

@media (max-width: 480px) {
    .font-size-label {
        font-size: 11px;
        min-width: 20px;
    }
}

/* Ajustar iconos específicos */
.fmt-btn b,
.fmt-btn i,
.fmt-btn u,
.fmt-btn s,
.fmt-btn code {
  font-style: normal;
  font-weight: normal;
  text-decoration: none;
}

/* Asegurar que los separadores se ajusten a la nueva altura */
.fmt-sep {
  height: 22px;
  align-self: center;
}

/* Añadir estilos para los botones mode-switch */
.mode-switch {
  min-width: 50px !important;
  padding: 10px !important;
}

.mode-switch.active {
  padding: 2px 20px !important;
}

.fmt-sep {
  width: 1px;
  height: 22px;
  background: var(--border);
  margin: 0 6px;
}

/* Responsive design para format-bar */
@media (max-width: 1024px) {
  .format-bar {
    padding: 0 6px;
    gap: 2px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  
  #html-toolbar, #md-toolbar {
    gap: 2px;
    flex-wrap: nowrap;
  }
  
  .fmt-btn {
    width: 32px;
    height: 30px;
    font-size: 14px;
  }
  
  .fmt-select {
    font-size: 12px;
    padding: 3px 5px;
    min-height: 30px;
  }
  
  .fmt-sep {
    margin: 0 4px;
    height: 20px;
  }
  
  #toolbar-right {
    gap: 6px;
  }
  
  .mode-switch {
    font-size: 12px;
    padding: 3px 8px;
  }
}

@media (max-width: 480px) {
  .format-bar {
    height: 38px;
  }
  
  .fmt-btn {
    width: 30px;
    height: 28px;
    font-size: 13px;
  }
  
  .fmt-select {
    font-size: 11px;
    max-width: 85px;
    padding: 3px 4px;
    min-height: 28px;
  }
  
  #toolbar-right .mode-switch {
    display: none;
  }
  
  .fmt-sep {
    margin: 0 3px;
    height: 18px;
  }
}

/* ── TITLEBAR ─────────────────────────────────────── */
#titlebar {
  height: 44px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  padding: 0 12px;
  gap: 8px;
  flex-shrink: 0;
  user-select: none;
}
#titlebar .logo {
  font-family: 'Lora', serif;
  font-size: 15px;
  font-weight: 500;
  color: var(--text);
  letter-spacing: -0.3px;
}
#titlebar .sep { flex: 1; }
#titlebar .site-badge {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; color: var(--text-muted);
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 20px; padding: 3px 10px; gap: 6px; cursor: pointer;
  transition: all .15s;
}
#titlebar .site-badge:hover { border-color: var(--accent); color: var(--accent); }
#titlebar .site-badge .dot {
  width: 6px; height: 6px; border-radius: 50%; background: var(--accent);
}
.tb-btn {
  background: none; border: 1px solid transparent;
  border-radius: 6px; padding: 5px 8px;
  color: var(--text-muted); cursor: pointer; font-size: 12px;
  display: flex; align-items: center; gap: 5px;
  font-family: 'DM Sans', sans-serif; transition: all .15s;
}
.tb-btn:hover { background: var(--surface2); border-color: var(--border); color: var(--text); }
.tb-btn.primary {
  background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 500;
}
.tb-btn.primary:hover { background: var(--accent-light); }

/* ── FORMATTING TOOLBAR ───────────────────────────── */
#format-bar {
  height: 36px;
  background: var(--surface);
  border-bottom: 1px solid var(--border-soft);
  display: flex; align-items: center; padding: 0 8px; gap: 2px; flex-shrink: 0;
}
.fmt-btn {
  background: none; border: none; border-radius: 4px;
  width: 28px; height: 26px; display: flex; align-items: center; justify-content: center;
  color: var(--text-muted); cursor: pointer; font-size: 13px;
  font-family: 'DM Sans', sans-serif; font-weight: 500; transition: all .12s;
}
.fmt-btn:hover { background: var(--surface2); color: var(--text); }
.fmt-btn.active { background: var(--accent-bg); color: var(--accent); }
.fmt-sep { width: 1px; height: 18px; background: var(--border); margin: 0 4px; }
.fmt-select {
  background: none; border: none; font-family: 'DM Sans', sans-serif;
  font-size: 12px; color: var(--text-muted); cursor: pointer; padding: 2px 4px;
  border-radius: 4px; outline: none; transition: all .12s;
}
.fmt-select:hover { background: var(--surface2); color: var(--text); }

/* Ajustar el layout principal */
.page-body {
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 57px);
  overflow: visible;
}

.editor-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 1.5rem;
  align-items: start;
  flex: 1;
  min-height: 0;
  height: auto;
}

.editor-main {
  display: flex; 
  flex-direction: column;
  background: var(--surface); 
  border: 1px solid var(--border);
  border-radius: var(--radius);
  height: auto;
  min-height: 500px;
  overflow: visible;
}

/* Ajustar el área del editor */
.editor-wrap { 
  flex: 1; 
  overflow-y: auto; 
  position: relative; 
  min-height: 0; 
  display: flex;
  flex-direction: column;
  max-height: 70vh; /* Limit height for editor area */
}

/* Asegurar que las toolbars internas funcionen correctamente */
#html-toolbar, #md-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
  overflow-x: auto;
}

/* Responsive */
@media (max-width: 1024px) {
  #titlebar {
    padding: 0 8px;
    gap: 4px;
    height: 40px;
  }
  .tb-btn {
    padding: 4px 6px;
    font-size: 11px;
  }
  #format-bar {
    height: 32px;
    padding: 0 4px;
    overflow-x: auto;
  }
  .fmt-btn {
    width: 26px;
    height: 24px;
    font-size: 12px;
  }
  .fmt-select {
    font-size: 11px;
  }
}
.tb {
  background: none;
  border: none;
  color: var(--text2);
  padding: 0.5rem;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.825rem;
  font-family: inherit;
  transition: all 0.1s;
  line-height: 1;
  white-space: nowrap;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
}
.tb:hover {
  background: var(--surface2);
  color: var(--text);
}
.tb.active {
  background: rgba(var(--accent-rgb), 0.15);
  color: var(--accent);
}
.tb-icon {
  min-width: 40px;
  min-height: 40px;
  padding: 0.5rem;
}
.tb-icon svg {
  width: 18px;
  height: 18px;
}
.tb-mode {
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid var(--border2) !important;
  border-radius: 4px !important;
  padding: 0.4rem 0.75rem !important;
  min-width: auto;
}
.tb-mode.active {
  background: var(--accent) !important;
  color: #fff !important;
  border-color: var(--accent) !important;
}
@media (max-width: 1024px) {
  .toolbar {
    padding: 0.4rem 0.6rem;
    overflow-x: auto;
  }
  .tb {
    min-width: 36px;
    min-height: 36px;
    padding: 0.4rem;
  }
  .tb-icon svg {
    width: 16px;
    height: 16px;
  }
  .toolbar-sep {
    height: 20px;
    margin: 0 4px;
  }
}

/* Mobile toolbar */
@media (max-width: 1024px) {
  .toolbar {
    overflow-x: auto;
    padding: 0.4rem 0.5rem;
    -webkit-overflow-scrolling: touch;
    flex-wrap: nowrap;
  }
  #html-toolbar, #md-toolbar {
    gap: 2px;
    flex-wrap: nowrap;
  }
  .tb {
    min-width: 36px;
    min-height: 36px;
    padding: 0.3rem;
    flex-shrink: 0;
  }
  .toolbar-sep {
    height: 20px;
    margin: 0 3px;
    flex-shrink: 0;
  }
  .tb-mode {
    font-size: 0.7rem;
    padding: 0.3rem 0.5rem !important;
    flex-shrink: 0;
  }
  /* Hide some buttons on very small screens */
  @media (max-width: 480px) {
    .tb:nth-child(n+8) {
      display: none;
    }
  }
}

/* ── Floating toolbar ── */
.float-toolbar {
  position: fixed;
  background: #1e1e2e;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 8px;
  padding: 4px 6px;
  display: flex; align-items: center; gap: 2px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.4);
  z-index: 9000;
  animation: ftb-pop 0.12s ease;
}
@keyframes ftb-pop {
  from { opacity:0; transform: translateY(4px) scale(0.97); }
  to   { opacity:1; transform: translateY(0) scale(1); }
}
.ftb {
  background: none; border: none; color: #cdd6f4;
  padding: 0.3rem 0.45rem; border-radius: 5px;
  cursor: pointer; font-size: 0.82rem; font-family: inherit;
  line-height: 1; transition: background 0.1s;
}
.ftb:hover { background: rgba(255,255,255,0.12); }
.ftb-sep { width: 1px; height: 16px; background: rgba(255,255,255,0.15); margin: 0 3px; }
/* Arrow */
.float-toolbar::after {
  content: '';
  position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%);
  width: 8px; height: 8px;
  background: #1e1e2e; border-right: 1px solid rgba(255,255,255,0.12);
  border-bottom: 1px solid rgba(255,255,255,0.12);
  transform: translateX(-50%) rotate(45deg);
}

/* ── Editor panes ── */
.editor-wrap { 
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
  overflow: hidden;
  position: relative;
  -webkit-overflow-scrolling: touch;
}
.prose-editor {
  min-height: 100%;
  padding: 1.5rem; 
  outline: none; 
  line-height: 1.8;
  color: var(--text); 
  font-size: 1rem;
}
/* TinyMCE container */
#editor {
  flex: 1;
  min-height: 100%;
  width: 100%;
  border: none;
  background: var(--surface);
  color: var(--text);
  font-size: 1rem;
  line-height: 1.8;
  padding: 1.5rem;
  resize: none;
  overflow-y: auto;
}
/* Ensure TinyMCE iframe fills the container */
.tox-tinymce {
  border: none !important;
  border-radius: 0 !important;
  height: 100% !important;
}
.tox-editor-container {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.tox-sidebar-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.tox .tox-edit-area {
  flex: 1;
}
.tox .tox-edit-area__iframe {
  flex: 1;
}
.prose-editor h2 { font-size: 1.5rem; font-weight: 700; margin: 1.5rem 0 0.75rem; }
.prose-editor h3 { font-size: 1.2rem; font-weight: 600; margin: 1.25rem 0 0.5rem; }
#editor p, #editor > p, [contenteditable] p { margin-top: 0 !important; margin-bottom: 1em !important; display: block !important; }
.prose-editor p { margin-top: 0 !important; margin-bottom: 1em !important; }
.prose-editor a { color: var(--accent); }
.prose-editor blockquote { border-left: 3px solid var(--accent); padding: 0.5rem 1rem; margin: 1rem 0; color: var(--text2); font-style: italic; background: var(--surface2); border-radius: 0 8px 8px 0; }
.prose-editor pre { background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.875rem; overflow-x: auto; margin: 1rem 0; }
.prose-editor ul, .prose-editor ol { padding-left: 1.5rem; margin: 0 0 1rem; }
.prose-editor li { margin-bottom: 0.25rem; }
.ProseMirror img,
.prose-editor img {
  max-width: 100%;
  border-radius: 8px;
  margin: 0.5rem 0;
  transition: outline 0.15s;
}
.ProseMirror img.align-center,
.prose-editor img.align-center,
.desk-sheet img.align-center,
.ProseMirror img[data-align="center"],
.desk-sheet img[data-align="center"] {
  display: block !important;
  margin: 1.5rem auto !important;
  float: none !important;
  clear: both;
}
.ProseMirror img.align-left,
.prose-editor img.align-left,
.desk-sheet img.align-left,
.ProseMirror img[data-align="left"],
.desk-sheet img[data-align="left"] {
  float: left !important;
  margin: 0.6rem 1.6rem 1rem 0 !important;
  display: block !important;
}
.ProseMirror img.align-right,
.prose-editor img.align-right,
.desk-sheet img.align-right,
.ProseMirror img[data-align="right"],
.desk-sheet img[data-align="right"] {
  float: right !important;
  margin: 0.6rem 0 1rem 1.6rem !important;
  display: block !important;
}
.ProseMirror img.align-full,
.prose-editor img.align-full,
.desk-sheet img.align-full,
.ProseMirror img[data-align="full"],
.desk-sheet img[data-align="full"] {
  display: block !important;
  width: 100% !important;
  max-width: 100% !important;
  margin: 1.8rem auto !important;
  float: none !important;
  clear: both;
}
.ProseMirror::after,
.prose-editor::after {
  content: "";
  display: table;
  clear: both;
}
.ProseMirror img.ProseMirror-selectednode,
.prose-editor img.ProseMirror-selectednode {
  outline: 2px solid var(--accent) !important;
  outline-offset: 3px !important;
}
.prose-editor hr { border: none; border-top: 1px solid var(--border); margin: 2rem 0; }
.prose-editor code { background: var(--surface2); padding: 0.1rem 0.35rem; border-radius: 3px; font-size: 0.875em; }
.prose-editor strong { font-weight: 700; }
.prose-editor em { font-style: italic; }
.prose-editor del { text-decoration: line-through; color: var(--muted); }

.html-editor {
  width: 100%; min-height: 100%; padding: 1.5rem;
  background: #1a1a24; color: #a8e6cf;
  font-family: 'Fira Code', 'Cascadia Code', 'Courier New', monospace;
  font-size: 0.9rem; border: none; resize: vertical; line-height: 1.7;
  tab-size: 2;
}
.html-editor:focus, .md-editor:focus { outline: none; }

/* RAW HTML editor styling */
.raw-editor {
  width: 100%;
  min-height: 100%;
  height: 100%;
  max-height: none;
  padding: 1.5rem;
  background: var(--surface2);
  color: var(--text);
  font-family: 'Fira Code', 'Cascadia Code', 'Courier New', monospace;
  font-size: 1rem;
  border: none;
  resize: none;
  line-height: 1.7;
  tab-size: 2;
  overflow-y: auto;
  overflow-x: hidden;
  box-sizing: border-box;
  flex: 1;
  white-space: pre-wrap;
  word-wrap: break-word;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}

.raw-editor:focus {
  outline: none;
  box-shadow: 0 0 0 2px var(--accent);
}

/* Syntax highlighting for RAW editor */
.raw-editor .tag { color: #ff79c6; }
.raw-editor .attr { color: #50fa7b; }
.raw-editor .value { color: #f1fa8c; }
.raw-editor .comment { color: #6272a4; }


/* ── Focus mode ── */
body.focus-mode .sidebar,
body.focus-mode .topbar,
body.focus-mode .editor-sidebar,
body.focus-mode .alert { display: none !important; }
body.focus-mode .main { margin-left: 0 !important; }
body.focus-mode .page-body { padding: 0; }
body.focus-mode .editor-layout { grid-template-columns: 1fr; }
body.focus-mode .editor-main { border-radius: 0; border-left: none; border-right: none; border-top: none; height: 100vh !important; top: 0 !important; }
body.focus-mode .toolbar { top: 0; border-top: none; }
body.focus-mode .prose-editor, body.focus-mode .md-editor { max-width: 760px; margin: 0 auto; }
body.focus-mode #focus-btn { color: var(--accent); }
.focus-exit-hint { display: none; position: fixed; bottom: 1rem; left: 50%; transform: translateX(-50%); z-index: 9999; width: 100%; max-width: 300px; }
body.focus-mode .focus-exit-hint { display: flex; justify-content: center; align-items: center; }
.focus-exit-btn {
  background: var(--accent);
  color: #fff;
  border: none;
  border-radius: 50px;
  padding: 1rem 2rem;
  font-size: 1rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  box-shadow: 0 6px 20px rgba(0,0,0,0.3);
  transition: all 0.2s;
  white-space: nowrap;
  text-align: center;
}
.focus-exit-btn:hover {
  opacity: 0.9;
  transform: scale(1.05);
}
.focus-exit-btn:active {
  transform: scale(0.98);
}
body.focus-mode .floating-buttons-container { display: none !important; }

/* ── Tag chips ── */
.tag-input-wrap {
  background: var(--surface); border: 1px solid var(--border2); border-radius: var(--radius-sm);
  padding: 0.35rem 0.5rem; min-height: 36px; display: flex; flex-wrap: wrap; gap: 0.3rem;
  align-items: center; cursor: text; box-shadow: inset 0 1px 1.5px rgba(0,0,0,0.1);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.tag-input-wrap:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.18), inset 0 1px 1.5px rgba(0,0,0,0.1); }
.tag-chip {
  display: inline-flex; align-items: center; gap: 0.25rem;
  background: var(--text); color: var(--bg);
  padding: 2px 7px; border-radius: 9999px; font-size: 0.74rem; font-weight: 600;
  letter-spacing: -0.01em;
}
.tag-chip button { background: none; border: none; color: inherit; cursor: pointer; font-size: 0.85rem; line-height: 1; padding: 0 0 0 2px; opacity: 0.65; }
.tag-chip button:hover { opacity: 1; }
.tag-input-wrap input { background: none; border: none; outline: none; font-family: inherit; font-size: 0.84rem; color: var(--text); padding: 0; min-width: 80px; flex: 1; box-shadow: none !important; }
.tag-suggestions { display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.45rem; }
.tag-sug { background: var(--surface2); border: 1px solid var(--border); color: var(--text2); padding: 2px 7px; border-radius: 9999px; font-size: 0.73rem; cursor: pointer; transition: all 0.15s; line-height: 1.3; }
.tag-sug:hover { background: var(--border); color: var(--text); border-color: var(--border2); }
.tag-sug.used { display: none; }

/* Tag autocomplete dropdown */
.tag-dropdown {
  background: var(--surface); border: 1px solid var(--border2);
  border-radius: 0 0 7px 7px; max-height: 180px; overflow-y: auto;
  margin-top: -1px; box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}
.tag-dropdown-item {
  padding: 0.45rem 0.75rem; font-size: 0.82rem; cursor: pointer;
  color: var(--text); transition: background 0.1s;
  display: flex; align-items: center; justify-content: space-between;
}
.tag-dropdown-item:hover, .tag-dropdown-item.focused {
  background: var(--surface2); color: var(--accent);
}
.tag-dropdown-item .tag-count {
  font-size: 0.7rem; color: var(--muted); background: var(--bg);
  padding: 0.1rem 0.4rem; border-radius: 20px;
}
.tag-dropdown-empty {
  padding: 0.5rem 0.75rem; font-size: 0.8rem; color: var(--muted); font-style: italic;
}

/* ── quireINK Inspired Attributes Inspector Sheet ── */
.editor-sidebar-sheet { 
  display: flex; 
  flex-direction: column; 
  gap: 0; 
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  padding: 0;
  max-height: calc(100vh - 120px);
  overflow-y: auto;
  position: sticky;
  top: 15px;
}
.editor-sidebar-sheet::-webkit-scrollbar { width: 5px; }
.editor-sidebar-sheet::-webkit-scrollbar-track { background: transparent; }
.editor-sidebar-sheet::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
.editor-sidebar-sheet::-webkit-scrollbar-thumb:hover { background: var(--muted); }
.editor-sidebar-sheet { scrollbar-width: thin; scrollbar-color: var(--border) transparent; }

.sheet-head {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--surface);
  position: sticky;
  top: 0;
  z-index: 5;
}
.sheet-head-title {
  font-family: var(--font-sans);
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--text);
  letter-spacing: -0.01em;
}
.sheet-status-pill {
  font-size: 0.72rem;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 9999px;
  letter-spacing: 0.02em;
}
.sheet-status-pill.draft {
  background: var(--surface2);
  color: var(--text2);
  border: 1px solid var(--border2);
}
.sheet-status-pill.pub {
  background: rgba(16, 185, 129, 0.12);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.25);
}
.sheet-status-pill.scheduled {
  background: rgba(59, 130, 246, 0.12);
  color: #3b82f6;
  border: 1px solid rgba(59, 130, 246, 0.25);
}

.sheet-group {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
}
.sheet-group:last-child {
  border-bottom: none;
}
.sheet-label {
  display: block;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 6px;
  letter-spacing: -0.01em;
}
.sheet-label-split {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.sheet-label-split .sheet-label {
  margin-bottom: 0;
}
.sheet-count {
  font-size: 0.72rem;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}
.sheet-hint {
  font-size: 0.75rem;
  color: var(--muted);
  line-height: 1.4;
  margin-top: 5px;
}

/* Status Segmented Control (Draft vs Scheduled vs Published) */
.sheet-segmented {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 4px;
  background: var(--surface2);
  padding: 3px;
  border-radius: 7px;
  border: 1px solid var(--border);
}
.sheet-seg-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 5px 8px;
  border-radius: 5px;
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--text2);
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}
.sheet-seg-item:hover { color: var(--text); }
.sheet-seg-item.active {
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
  box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}
.sheet-seg-radio {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.sheet-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.sheet-dot.draft { background: var(--muted); }
.sheet-dot.scheduled { background: #3b82f6; }
.sheet-dot.pub { background: #10b981; }

/* URL Slug Affix Input */
.sheet-field-prefix {
  display: flex !important;
  align-items: center !important;
  border: 1px solid var(--border2) !important;
  border-radius: var(--radius-sm) !important;
  background: var(--surface) !important;
  box-shadow: inset 0 1px 1.5px rgba(0,0,0,0.1) !important;
  overflow: hidden !important;
  transition: border-color 0.15s, box-shadow 0.15s !important;
  width: 100% !important;
}
.sheet-field-prefix:focus-within {
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.18), inset 0 1px 1.5px rgba(0,0,0,0.1) !important;
}
.sheet-prefix-text {
  padding: 0 4px 0 9px !important;
  font-size: 0.8rem !important;
  color: var(--muted) !important;
  user-select: none !important;
  font-family: var(--font-mono) !important;
  white-space: nowrap !important;
  flex-shrink: 0 !important;
  display: inline-flex !important;
  align-items: center !important;
  line-height: 1 !important;
}
.sheet-field-prefix input {
  border: none !important;
  box-shadow: none !important;
  padding: 0.5rem 0.65rem 0.5rem 2px !important;
  background: transparent !important;
  border-radius: 0 !important;
  font-family: var(--font-mono) !important;
  font-size: 0.82rem !important;
  min-width: 0 !important;
  flex: 1 1 auto !important;
  width: auto !important;
  outline: none !important;
}

/* Picture Slot (16:9 Image Preview) */
.sheet-picture-slot {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sheet-picture-frame {
  aspect-ratio: 16 / 9;
  width: 100%;
  border-radius: 7px;
  overflow: hidden;
  border: 1px solid var(--border);
  background: var(--surface2);
  position: relative;
}
.sheet-picture-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.sheet-picture-empty {
  aspect-ratio: 16 / 9;
  width: 100%;
  border-radius: 7px;
  border: 1.5px dashed var(--border2);
  background: rgba(0,0,0,0.02);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: var(--muted);
  font-size: 0.78rem;
}
.sheet-picture-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

.panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; }
.panel-title { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin-bottom: 0.65rem; }
.panel-hint { font-size: 0.75rem; color: var(--muted); margin-top: 0.4rem; }
.panel input, .panel select, .panel textarea { margin-top: 0; font-size: 0.85rem; }

/* ── RESPONSIVE ── */
@media (max-width: 1024px) {
  /* Prevenir scroll horizontal en todo */
  html, body {
    overflow-x: hidden !important;
    overflow-y: auto !important;
    position: relative;
    height: auto;
    min-height: 100vh;
  }
  
  /* Corregir el page-body principal */
  .page-body {
    display: block !important;
    height: auto !important;
    min-height: 100vh !important;
    overflow-y: visible !important;
    padding: 0.75rem !important;
    position: relative;
  }
  
  /* Cambiar completamente el layout del editor en móvil */
  .editor-layout {
    display: flex !important;
    flex-direction: column !important;
    grid-template-columns: 1fr !important;
    gap: 1rem !important;
    min-height: auto !important; /* Changed from 100vh */
    width: 100% !important;
    max-width: 100% !important;
    position: relative;
  }
  
  /* Ajustar el editor principal */
  .editor-main {
    order: 1;
    width: 100% !important;
    max-width: 100% !important;
    height: auto !important;
    min-height: 50vh !important;
    max-height: none !important;
    position: relative !important;
    margin: 0 !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius) !important;
    box-sizing: border-box;
    overflow: visible !important;
  }
  
  /* Ajustar el wrapper del editor */
  .editor-wrap {
    height: auto !important;
    min-height: 300px !important;
    max-height: none !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 1rem 0.75rem !important;
    position: relative;
    -webkit-overflow-scrolling: touch !important;
    overscroll-behavior: contain !important;
  }
  
  /* Ajustar el título */
  .title-area {
    padding: 1rem 0.75rem 0 !important;
    margin: 0 !important;
    width: 100% !important;
    box-sizing: border-box;
  }
  
  /* IMPORTANTE: Arreglar el sidebar */
  .editor-sidebar {
    order: 2;
    width: 100% !important;
    max-width: 100% !important;
    padding: 0 !important;
    margin: 1rem 0 120px !important; /* Más espacio para botones flotantes */
    display: block !important;
    position: relative !important;
    overflow: visible !important;
    z-index: 10;
    box-sizing: border-box;
    max-height: none !important;
    overflow-y: visible !important;
    position: static !important;
  }
  
  /* Ajustar los paneles del sidebar */
  .editor-sidebar .panel {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 0 1rem 0 !important;
    padding: 1.25rem !important;
    box-sizing: border-box;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    position: relative;
    overflow: visible;
    left: 0 !important;
    right: 0 !important;
  }
  
  /* Asegurar que el último panel tenga espacio extra */
  .editor-sidebar .panel:last-child {
    margin-bottom: 2rem !important;
  }
  
  /* Ensure Danger Zone is visible */
  .editor-sidebar .panel[style*="color:var(--red)"] {
    margin-top: 2rem !important;
    border: 1px solid var(--red) !important;
    background: rgba(255, 0, 0, 0.05) !important;
  }
  
  /* Ajustar inputs dentro de paneles */
  .panel input[type="text"],
  .panel input[type="url"],
  .panel textarea,
  .panel select {
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
    font-size: 16px !important;
    padding: 12px !important;
    border-radius: 8px;
    border: 1px solid var(--border2);
    background: var(--surface2);
    color: var(--text);
    margin-top: 0.5rem;
  }
  
  /* Fix para el área de categorías y tags */
  .tag-input-wrap {
    width: 100% !important;
    max-width: 100% !important;
    min-height: 46px;
    padding: 8px !important;
    box-sizing: border-box;
    overflow: visible !important;
  }
  
  .tag-chips {
    max-width: 100% !important;
    overflow-x: auto !important;
    flex-wrap: wrap !important;
    -webkit-overflow-scrolling: touch !important;
  }
  
  /* Ajustar botones flotantes */
  .floating-buttons-container {
    position: fixed !important;
    bottom: 20px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: 90% !important;
    max-width: 400px !important;
    z-index: 10050 !important;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    box-shadow: 0 -2px 20px rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    padding: 6px !important;
  }
  
  /* Asegurar que el formulario se pueda scroll */
  #editor-form {
    min-height: auto !important; /* Changed from 100vh */
    height: auto !important;
    overflow: visible !important;
    position: relative;
  }
  
  /* Arreglar cualquier problema de overflow */
  .prose-editor, #editor, .md-editor {
    max-width: 100% !important;
    overflow-wrap: break-word !important;
    word-wrap: break-word !important;
  }
  
  /* Asegurar que todos los paneles sean visibles */
  .editor-sidebar .panel {
    opacity: 1 !important;
    visibility: visible !important;
    display: block !important;
  }
  
  /* Asegurar que los botones dentro de los paneles sean tocables */
  .panel .btn {
    min-height: 44px;
    padding: 12px 20px;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  /* Asegurar que las imágenes destacadas se vean bien */
  #featured-preview {
    max-width: 100%;
    overflow: hidden;
  }
  
  #featured-img {
    max-width: 100%;
    height: auto;
    max-height: 150px;
  }
  
  /* Arreglar el área de sugerencias de tags */
  .tag-suggestions {
    margin-top: 8px;
    flex-wrap: wrap;
  }
  
  .tag-sug {
    font-size: 13px;
    padding: 5px 10px;
    margin: 3px;
  }
  
  .tag-chip {
    font-size: 14px;
    padding: 6px 10px;
    margin: 2px;
  }
  
  /* Asegurar que los botones de upload sean fácilmente tocables */
  label[for="feat-upload"] {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    min-width: 44px;
    padding: 10px 15px;
    margin: 5px 0;
  }
  
  /* Arreglar el spacing en el panel de imagen destacada */
  #feat-upload-progress {
    margin-top: 8px;
    font-size: 13px;
    line-height: 1.4;
  }
  
  .topbar-actions .btn span { display: none; }
  
  /* Ensure toolbar has proper spacing */
  .toolbar {
    padding: 0.4rem 0.75rem;
    margin-left: 0;
    margin-right: 0;
    width: 100%;
    box-sizing: border-box;
  }
  
  .tb-primary {
    padding-left: 0;
    padding-right: 0;
  }
}

@media (max-width: 480px) {
  .page-body {
    padding: 0.5rem !important;
  }
  
  .editor-sidebar {
    margin: 1rem 0 110px !important;
  }
  
  .editor-sidebar .panel {
    padding: 1rem !important;
  }
  
  .panel-title {
    font-size: 13px !important;
    margin-bottom: 0.75rem !important;
  }
  
  .panel-hint {
    font-size: 12px !important;
    line-height: 1.4 !important;
  }
  
  /* Ajustar botones flotantes en pantallas muy pequeñas */
  .floating-buttons-container {
    width: 95% !important;
    bottom: 10px !important;
    padding: 5px !important;
  }
  
  .floating-draft-btn,
  .floating-focus-btn,
  .floating-preview-btn,
  .floating-publish-btn {
    padding: 10px 12px !important;
    font-size: 0.9rem !important;
    min-height: 44px !important;
  }
  
  .floating-draft-btn span,
  .floating-focus-btn span,
  .floating-preview-btn span,
  .floating-publish-btn span {
    display: inline !important; /* Mostrar texto siempre */
  }
  
  /* Asegurar que los tag inputs sean compactos */
  .tag-input-wrap {
    min-height: 42px !important;
    padding: 6px !important;
  }
  
  .tag-chip {
    font-size: 13px !important;
    padding: 5px 8px !important;
    margin: 2px !important;
  }
  
  .tag-chip button {
    width: 18px;
    height: 18px;
    font-size: 16px;
  }
  
  /* Asegurar que el dropdown de tags se vea bien */
  .tag-dropdown {
    width: 100% !important;
    left: 0 !important;
    right: 0 !important;
    max-width: none !important;
    box-sizing: border-box !important;
  }
  
  /* Ajustar el textarea del excerpt */
  .panel textarea[name="excerpt"] {
    min-height: 80px;
    font-size: 14px;
  }
  
  .raw-editor {
    height: auto !important;
    min-height: 250px !important;
    max-height: none !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
    overscroll-behavior: contain !important;
  }
  .prose-editor { 
    padding: 1rem 0.75rem; 
    margin: 0;
  }
  
  .editor-main {
    border-left: 0;
    border-right: 0;
  }
  
  .editor-wrap {
    padding: 0 0.5rem;
  }
  
  #editor, .md-editor {
    padding: 1rem 0.5rem;
    -webkit-overflow-scrolling: touch !important;
    overflow-y: auto !important;
  }
  
  .title-area {
    padding: 1rem 0.5rem 0;
  }
  
  .toolbar {
    padding: 0.4rem 0.5rem;
  }
}
/* More toolbar dropdown */
.more-tb-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background: var(--surface2);
    border: 1px solid var(--border2);
    border-radius: 8px;
    padding: 0.5rem;
    z-index: 10000;
    max-width: 300px;
    max-height: 400px;
    overflow-y: auto;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    margin-top: 4px;
    flex-wrap: wrap;
    gap: 4px;
}
.more-tb-dropdown .tb {
    min-width: 36px;
    min-height: 36px;
    font-size: 0.8rem;
}

/* Mobile dropdown menu */
.mobile-dropdown-menu {
  display: none;
  position: fixed;
  top: 100%;
  right: 0;
  background: rgba(var(--surface2-rgb, 40, 40, 50), 0.98);
  border: 1px solid var(--border2);
  border-radius: 8px;
  padding: 0.5rem;
  z-index: 10050;
  max-width: 300px;
  max-height: 400px;
  overflow-y: auto;
  box-shadow: 0 8px 24px rgba(0,0,0,0.2);
  margin-top: 4px;
  flex-wrap: wrap;
  gap: 4px;
  transform: translateZ(0);
  -webkit-transform: translateZ(0);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  /* Safari specific fixes */
  -webkit-overflow-scrolling: touch;
  will-change: transform, opacity;
  isolation: isolate; /* Create new stacking context */
}

/* Add a wrapper to ensure proper stacking */
.mobile-dropdown-menu::before {
  content: '';
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: -1;
  background: transparent;
}

.mobile-dropdown-menu .tb {
  min-width: 36px;
  min-height: 36px;
  font-size: 0.8rem;
}

/* Mobile dropdown button */
.mobile-dropdown-btn {
  display: none !important;
}

/* Ensure the toolbar has proper stacking context */
.toolbar {
  position: relative;
  z-index: 10000;
  isolation: isolate;
}

/* Fix for Safari's stacking context issues */
#editor, .md-editor, .prose-editor {
  position: relative;
  z-index: 1;
  isolation: isolate;
}

/* Ensure editor-sidebar has lower z-index */
.editor-sidebar {
  position: relative;
  z-index: 1;
}

/* Add this for Safari to properly handle fixed positioning */
@supports (-webkit-touch-callout: none) {
  .mobile-dropdown-menu {
    position: absolute;
    transform: translate3d(0, 0, 0);
    -webkit-transform: translate3d(0, 0, 0);
  }
  
  .toolbar {
    position: sticky;
    top: 0;
    z-index: 10000;
  }
}

/* Desktop view - hide mobile dropdown elements */
@media (min-width: 1025px) {
  .mobile-dropdown-menu {
    display: none !important;
  }
  
  .mobile-dropdown-btn {
    display: none !important;
  }
  
  .mobile-hidden-tools {
    display: flex !important;
  }
}

/* For screens between 481px and 1024px, show all tools */
@media (min-width: 481px) and (max-width: 1024px) {
  .mobile-hidden-tools {
    display: flex !important;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
  }
  
  .mobile-dropdown-btn {
    display: none !important;
  }
  
  .mobile-dropdown-menu {
    display: none !important;
  }
}

/* Mobile view */
@media (max-width: 480px) {
  .mobile-hidden-tools {
    display: none !important;
  }
  
  .mobile-dropdown-btn {
    display: flex !important;
  }
  
  /* Adjust toolbar spacing for mobile */
  #html-toolbar, #md-toolbar {
    gap: 2px;
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  
  /* Ensure the dropdown button appears after the link button */
  #html-toolbar .mobile-dropdown-btn,
  #md-toolbar .mobile-dropdown-btn {
    margin-left: auto;
    flex-shrink: 0;
  }
  
  /* Hide specific buttons that are in the dropdown */
  #html-toolbar .tb:nth-child(n+15):not(.mobile-dropdown-btn),
  #md-toolbar .tb:nth-child(n+13):not(.mobile-dropdown-btn) {
    display: none !important;
  }
  
  /* Safari specific mobile fixes */
  @supports (-webkit-touch-callout: none) {
    .mobile-dropdown-menu {
      position: fixed;
      z-index: 10060;
      transform: translate3d(0, 0, 0);
      -webkit-transform: translate3d(0, 0, 0);
    }
    
    .toolbar {
      position: sticky;
      top: 0;
      z-index: 10050;
      background: var(--surface);
    }
    
    /* Prevent body scroll when dropdown is open */
    body.dropdown-open {
      overflow: hidden;
      position: fixed;
      width: 100%;
      height: 100%;
    }

    .editor-sidebar .panel {
      -webkit-overflow-scrolling: touch;
      overflow: visible;
    }
    
    .panel input[type="text"],
    .panel input[type="url"],
    .panel textarea,
    .panel select {
      font-size: 16px;
      line-height: 1.5;
    }

    /* Safari iOS specific scroll fix */
    .editor-wrap {
      -webkit-overflow-scrolling: touch !important;
      overflow-y: auto !important;
    }
    .raw-editor {
      -webkit-overflow-scrolling: touch !important;
      overflow-y: auto !important;
    }
    .prose-editor {
      -webkit-overflow-scrolling: touch !important;
      overflow-y: auto !important;
    }
  }
}

@media (max-width: 1024px) {
    /* hide unwanted right toolbar items */
    #toolbar-right .mode-switch,
    #font-size-group,
    #more-tb-btn,
    #focus-btn,
    #preview-btn {
        display: none !important;
    }
}
@media (min-width: 1025px) {
    #more-tb-btn {
        display: none;
    }
}

/* Ensure editor elements have proper z-index */
#editor, .md-editor, .prose-editor {
  position: relative;
  z-index: 1;
}

/* Fix for Safari textarea/editor z-index */
#editor, #md-editor {
  isolation: isolate;
}

/* SVG icon styles for toolbar */
.fmt-btn svg {
  width: 16px;
  height: 16px;
  stroke: currentColor;
  fill: none;
  vertical-align: middle;
}

/* Ensure buttons with SVG maintain proper spacing */
.fmt-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px;
}

/* Adjust for smaller screens */
@media (max-width: 1024px) {
  .fmt-btn svg {
    width: 14px;
    height: 14px;
  }
}

@media (max-width: 480px) {
  .fmt-btn svg {
    width: 12px;
    height: 12px;
  }
}

/* Añadir un indicador visual de que hay más contenido abajo */
@media (max-width: 1024px) {
  .editor-sidebar::after {
    content: "↓ Hay más contenido abajo";
    display: block;
    text-align: center;
    font-size: 12px;
    color: var(--muted);
    padding: 10px 0 20px 0;
    opacity: 0.7;
    border-top: 1px solid var(--border2);
    margin-top: 1rem;
  }
  
  /* Ocultar en pantallas muy pequeñas */
  @media (max-width: 480px) {
    .editor-sidebar::after {
      display: none;
    }
  }
}

/* Asegurar que no haya contenido oculto */
@media (max-width: 1024px) {
  .editor-sidebar > *:not(.panel) {
    display: none !important;
  }
}

/* Fix para el height del contenedor en iOS */
@media (max-width: 1024px) {
  html, body {
    height: 100%;
  }
  
  body {
    display: flex;
    flex-direction: column;
  }
  
  .main {
    flex: 1;
    overflow-y: auto;
  }
}

/* Fix strikethrough styling */
.prose-editor del {
  text-decoration: line-through !important;
  color: var(--text);
  background: rgba(255, 0, 0, 0.1);
  padding: 0.1rem 0.2rem;
  border-radius: 3px;
  text-decoration-color: var(--red);
  text-decoration-thickness: 2px;
}

/* Ensure strikethrough works properly in headers */
.prose-editor h1 del,
.prose-editor h2 del,
.prose-editor h3 del,
.prose-editor h4 del {
  text-decoration: line-through !important;
  text-decoration-color: var(--red);
  text-decoration-thickness: 3px;
  background: rgba(255, 0, 0, 0.15);
}

/* Fix for inline strikethrough */
.prose-editor del,
#editor del,
[contenteditable] del {
  text-decoration: line-through !important;
  text-decoration-color: var(--red) !important;
  text-decoration-thickness: 2px !important;
  position: relative;
  display: inline;
}

/* Fix text decoration inheritance */
.prose-editor * {
  text-decoration: none !important;
}

.prose-editor del {
  text-decoration: line-through !important;
}

/* Audio player styling */
.media-audio {
  margin: 1.5rem 0;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  border-radius: 8px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.media-audio::before {
  content: "🎵";
  font-size: 1.5rem;
  flex-shrink: 0;
}

.media-audio audio {
  width: 100%;
  height: 36px;
  flex: 1;
}

/* Audio player in preview */
.md-preview-pane .media-audio {
  margin: 1.5rem 0;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  border-radius: 8px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.md-preview-pane .media-audio::before {
  content: "🎵";
  font-size: 1.5rem;
  flex-shrink: 0;
}

.md-preview-pane .media-audio audio {
  width: 100%;
  height: 36px;
  flex: 1;
}

/* Video & Embed styling */
.media-video {
  margin: 1.5rem 0;
  border-radius: 8px;
  overflow: hidden;
  background: #000;
  line-height: 0;
  max-width: 100%;
}
.media-video video {
  width: 100%;
  display: block;
  border-radius: 8px;
}
.yt-embed {
  position: relative;
  padding-bottom: 56.25%;
  height: 0;
  overflow: hidden;
  margin: 1.5rem 0;
  border-radius: 8px;
}
.yt-embed iframe {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  border: none;
}

/* Fix for contenteditable areas */
#editor del,
[contenteditable] del {
  text-decoration: line-through !important;
}

/* Ensure strikethrough is visible in all contexts */
del, s, strike {
  text-decoration: line-through !important;
  position: relative;
}

/* Fix for Safari strikethrough rendering */
@supports (-webkit-touch-callout: none) {
  .prose-editor del {
    -webkit-text-decoration-line: line-through;
    -webkit-text-decoration-color: var(--red);
    -webkit-text-decoration-thickness: 2px;
  }
}

/* Style for horizontal rule button */
.fmt-btn[title="Separador horizontal"] svg {
  /* No rotation needed for HR icon */
  transform: none;
}

/* Markdown preview strikethrough */
.md-preview-pane del {
  text-decoration: line-through !important;
  color: var(--text);
  background: rgba(255, 0, 0, 0.1);
  padding: 0.1rem 0.2rem;
  border-radius: 3px;
  text-decoration-color: var(--red);
  text-decoration-thickness: 2px;
}

.md-preview-pane s {
  text-decoration: line-through !important;
}

/* Ensure markdown strikethrough ~~text~~ is rendered properly */
.md-preview-pane s,
.md-preview-pane del {
  text-decoration: line-through !important;
}

</style>
<input type="file" id="editor-image-upload" accept="image/*" multiple style="display:none">

<script>
// ── State ──────────────────────────────────────────────────────────────────
let mode            = 'visual';  // 'visual' | 'raw'
let slugManuallySet = <?= $is_new ? 'false' : 'true' ?>;
window.KUTPOD_INSTANCE_URL = <?= json_encode($kutpodInstanceUrl ?? '') ?>;

const rawEditor    = document.getElementById('raw-editor');
const contentInput = document.getElementById('content-input');
const fmtInput     = document.getElementById('content-format-input');
const titleInput   = document.getElementById('post-title');
const slugInput    = document.getElementById('custom-slug');
const htmlToolbar  = document.getElementById('html-toolbar');

// ═══════════════════════════════════════════════════════════════════════════
// WRITING DESK ENHANCEMENTS (QUIREINK INSPIRED)
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// WRITING DESK & PROSEMIRROR INITIALIZATION (QUIREINK ENGINE)
// ═══════════════════════════════════════════════════════════════════════════

(function initWritingDesk() {
  const pmMount = document.getElementById('prosemirror-mount');
  const fallbackEditor = document.getElementById('editor');
  const rawEditor = document.getElementById('raw-editor');
  const markdownEditor = document.getElementById('markdown-editor');
  const contentInput = document.getElementById('content-input');
  const fmtInput = document.getElementById('content-format-input');
  const postTitle = document.getElementById('post-title');
  const bubbleMenu = document.getElementById('bubble-menu');
  const imageBubbleMenu = document.getElementById('image-bubble-menu');
  const slashMenu = document.getElementById('slash-menu');

  function hideImageBubble() {
    if (imageBubbleMenu) {
      imageBubbleMenu.classList.remove('is-active');
      imageBubbleMenu.style.setProperty('display', 'none', 'important');
    }
  }
  function showImageBubble() {
    if (imageBubbleMenu) {
      imageBubbleMenu.classList.add('is-active');
      imageBubbleMenu.style.setProperty('display', 'inline-flex', 'important');
    }
  }
  window.hideImageBubble = hideImageBubble;
  window.showImageBubble = showImageBubble;
  const findBar = document.getElementById('find-replace-bar');
  const deskMetrics = document.getElementById('desk-metrics');
  const publishBtn = document.getElementById('publish-btn');
  const deskStatusPill = document.getElementById('desk-status-pill');

  let isDirty = false;
  let initialFormat = <?= json_encode($content_format) ?>;
  let originalHtml = <?= json_encode($initial_html) ?>;
  let originalMarkdown = <?= json_encode($initial_markdown) ?>;
  window.currentEditorMode = initialFormat === 'markdown' ? 'markdown' : 'wysiwyg';
  let mode = window.currentEditorMode;

  // 1. UPDATE METRICS (Words & Reading Time)
  function updateMetrics(text) {
    if (!deskMetrics) return;
    const words = (text.trim().match(/\S+/g) || []).length;
    const minutes = Math.max(1, Math.ceil(words / 220));
    deskMetrics.textContent = `${words.toLocaleString()} palabras · ${minutes} min de lectura`;
  }

  function markDirty() {
    if (!isDirty) {
      isDirty = true;
      if (publishBtn) {
        publishBtn.style.opacity = '1';
      }
    }
  }

  // 2. TITLE ENTER KEY -> JUMP TO BODY
  if (postTitle) {
    postTitle.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (window.pmEditor) {
          window.pmEditor.commands.focus('start');
        } else if (fallbackEditor) {
          fallbackEditor.focus();
        }
      }
    });
    postTitle.addEventListener('input', () => {
      markDirty();
      if (window.triggerAutosave) window.triggerAutosave();
    });
  }

  // 3. MOUNT PROSEMIRROR (TIPTAP ENGINE)
  if (window.KutProseMirror && pmMount) {
    const { Editor, Extension, StarterKit, Underline, Highlight, Link, Image, Placeholder } = window.KutProseMirror;

    const TextAlign = Extension ? Extension.create({
      name: 'textAlign',
      addOptions() {
        return {
          types: ['heading', 'paragraph', 'blockquote'],
          alignments: ['left', 'center', 'right', 'justify'],
          defaultAlignment: 'left',
        };
      },
      addGlobalAttributes() {
        return [
          {
            types: this.options.types,
            attributes: {
              textAlign: {
                default: this.options.defaultAlignment,
                parseHTML: element => element.style.textAlign || this.options.defaultAlignment,
                renderHTML: attributes => {
                  if (!attributes.textAlign || attributes.textAlign === this.options.defaultAlignment) {
                    return {};
                  }
                  return { style: `text-align: ${attributes.textAlign}` };
                },
              },
            },
          },
        ];
      },
      addCommands() {
        return {
          setTextAlign: (alignment) => ({ commands }) => {
            if (!this.options.alignments.includes(alignment)) {
              return false;
            }
            let ran = false;
            for (const type of this.options.types) {
              if (commands.updateAttributes(type, { textAlign: alignment })) {
                ran = true;
              }
            }
            return ran;
          },
          unsetTextAlign: () => ({ commands }) => {
            let ran = false;
            for (const type of this.options.types) {
              if (commands.resetAttributes(type, 'textAlign')) {
                ran = true;
              }
            }
            return ran;
          },
          toggleTextAlign: (alignment) => ({ editor, commands }) => {
            if (!this.options.alignments.includes(alignment)) {
              return false;
            }
            if (editor.isActive({ textAlign: alignment })) {
              return commands.unsetTextAlign();
            }
            return commands.setTextAlign(alignment);
          },
        };
      },
      addKeyboardShortcuts() {
        return {
          'Mod-Shift-l': () => this.editor.commands.setTextAlign('left'),
          'Mod-Shift-e': () => this.editor.commands.setTextAlign('center'),
          'Mod-Shift-r': () => this.editor.commands.setTextAlign('right'),
          'Mod-Shift-j': () => this.editor.commands.setTextAlign('justify'),
        };
      },
    }) : null;

    const CustomImage = (Image && typeof Image.extend === 'function') ? Image.extend({
      addAttributes() {
        return {
          ...this.parent?.(),
          width: {
            default: null,
            parseHTML: element => element.getAttribute('width') || element.style.width || null,
            renderHTML: attributes => {
              if (!attributes.width) return {};
              return { width: attributes.width };
            }
          },
          align: {
            default: 'center',
            parseHTML: element => {
              if (element.classList.contains('align-left') || element.getAttribute('data-align') === 'left' || element.style.float === 'left') return 'left';
              if (element.classList.contains('align-right') || element.getAttribute('data-align') === 'right' || element.style.float === 'right') return 'right';
              if (element.classList.contains('align-full') || element.getAttribute('data-align') === 'full') return 'full';
              if (element.classList.contains('align-center') || element.getAttribute('data-align') === 'center' || element.style.margin?.includes('auto')) return 'center';
              return 'center';
            },
            renderHTML: attributes => {
              const a = attributes.align || 'center';
              return {
                'data-align': a,
                class: `prose-image align-${a}`
              };
            }
          }
        };
      },
      renderHTML({ node, HTMLAttributes }) {
        const align = node.attrs.align || 'center';
        const width = node.attrs.width || null;
        let style = '';

        if (align === 'left') {
          style = 'float: left; margin: 0.6rem 1.6rem 1rem 0; display: block;';
          if (width) style += ` width: ${width}; max-width: 100%;`;
          else style += ' max-width: 50%;';
        } else if (align === 'right') {
          style = 'float: right; margin: 0.6rem 0 1rem 1.6rem; display: block;';
          if (width) style += ` width: ${width}; max-width: 100%;`;
          else style += ' max-width: 50%;';
        } else if (align === 'full') {
          style = 'display: block; width: 100%; max-width: 100%; margin: 1.8rem auto; float: none; clear: both;';
        } else {
          // center (default)
          style = 'display: block; margin: 1.5rem auto; float: none; clear: both;';
          if (width) style += ` width: ${width}; max-width: 100%;`;
        }

        const mergedAttrs = {
          ...this.options?.HTMLAttributes,
          ...HTMLAttributes,
          class: `prose-image align-${align}`,
          'data-align': align,
          style: style.trim()
        };
        if (width) {
          mergedAttrs.width = width;
        }

        return ['img', mergedAttrs];
      }
    }) : Image;

    const extensions = [
      StarterKit.configure({
        heading: { levels: [2, 3, 4] },
        codeBlock: { HTMLAttributes: { class: 'prose-code-block' } },
        blockquote: { HTMLAttributes: { class: 'prose-blockquote' } }
      }),
      Underline,
      Highlight.configure({ multicolor: false }),
      Link.configure({
        openOnClick: false,
        HTMLAttributes: { class: 'prose-link' }
      }),
      (CustomImage || Image).configure({
        inline: false
      }),
      Placeholder.configure({
        placeholder: 'Comienza a escribir tu historia, o pulsa "/" para insertar bloques...'
      })
    ];
    if (TextAlign) extensions.push(TextAlign);

    window.pmEditor = new Editor({
      element: pmMount,
      extensions: extensions,
      content: originalHtml || '<p></p>',
      onUpdate({ editor }) {
        const html = editor.getHTML();
        if (contentInput) contentInput.value = html;
        if (rawEditor) rawEditor.value = html;
        if (fallbackEditor) fallbackEditor.innerHTML = html;
        updateMetrics(editor.getText());
        markDirty();
        if (window.triggerAutosave) {
          window.triggerAutosave();
        }
        if (!editor.isActive('image')) {
          hideImageBubble();
        }
      },
      onSelectionUpdate({ editor }) {
        updateBubblePosition(editor);
      },
      onTransaction({ editor }) {
        if (!editor.isActive('image')) {
          hideImageBubble();
        }
      },
      onBlur({ editor }) {
        setTimeout(() => {
          if (!editor.isFocused) {
            const activeEl = document.activeElement;
            if (!imageBubbleMenu?.contains(activeEl) && !imageBubbleMenu?.matches(':hover')) {
              hideImageBubble();
            }
            if (!bubbleMenu?.contains(activeEl) && !bubbleMenu?.matches(':hover')) {
              if (bubbleMenu) bubbleMenu.style.display = 'none';
            }
          }
        }, 150);
      }
    });

    // Initial word count
    updateMetrics(window.pmEditor.getText());

    // Drag & Drop image upload onto ProseMirror
    pmMount.addEventListener('dragover', (e) => e.preventDefault());
    pmMount.addEventListener('drop', (e) => {
      const files = Array.from(e.dataTransfer?.files || []).filter(f => f.type.startsWith('image/'));
      if (files.length > 0) {
        e.preventDefault();
        files.forEach(uploadAndInsertPmImage);
      }
    });

    // Ensure clicking an image in ProseMirror selects the image node and triggers the toolbar
    pmMount.addEventListener('click', (e) => {
      const img = e.target.closest('img');
      if (img && window.pmEditor) {
        try {
          const pos = window.pmEditor.view.posAtDOM(img, 0);
          if (typeof pos === 'number') {
            window.pmEditor.chain().focus().setNodeSelection(pos).run();
            updateBubblePosition(window.pmEditor);
          }
        } catch (err) {}
      }
    });

    pmMount.addEventListener('mouseup', () => {
      setTimeout(() => {
        if (window.pmEditor) updateBubblePosition(window.pmEditor);
      }, 20);
    });

    pmMount.addEventListener('keyup', () => {
      setTimeout(() => {
        if (window.pmEditor) updateBubblePosition(window.pmEditor);
      }, 20);
    });

  } else if (fallbackEditor) {
    // Fallback if bundle fails to load
    fallbackEditor.style.display = 'block';
    updateMetrics(fallbackEditor.innerText || '');
    fallbackEditor.addEventListener('input', () => {
      const html = fallbackEditor.innerHTML;
      if (contentInput) contentInput.value = html;
      if (rawEditor) rawEditor.value = html;
      updateMetrics(fallbackEditor.innerText || '');
      markDirty();
    });
  }

  async function uploadAndInsertPmImage(file) {
    const fd = new FormData();
    fd.append('upload', file);
    fd.append('csrf', <?= json_encode($csrf) ?>);
    try {
      const r = await fetch(<?= json_encode(base_url() . '/admin/media.php') ?>, { method: 'POST', body: fd });
      const txt = await r.text();
      // Match uploaded filename or path
      const m = txt.match(/media\/([^\s"'<>]+\.(?:jpg|jpeg|png|webp|gif|avif))/i);
      if (m && window.pmEditor) {
        const fullUrl = <?= json_encode(base_url()) ?> + '/' + m[0];
        window.pmEditor.chain().focus().setImage({ src: fullUrl, alt: file.name }).run();
      }
    } catch(err) {
      console.warn('Upload image error:', err);
    }
  }

  // 4. FLOATING BUBBLE SELECTION MENU & IMAGE ALIGNMENT MENU
  function updateBubblePosition(editor) {
    if (!editor || !editor.view) return;
    const isWysiwyg = (window.currentEditorMode === 'wysiwyg' || mode === 'wysiwyg' || mode === 'visual');
    if (!isWysiwyg) {
      hideImageBubble();
      if (bubbleMenu) bubbleMenu.style.display = 'none';
      return;
    }
    const { from, to, empty } = editor.state.selection;

    // Check if an image node is selected
    if (editor.isActive('image')) {
      if (bubbleMenu) bubbleMenu.style.display = 'none';
      if (imageBubbleMenu) {
        const attrs = editor.getAttributes('image');
        const align = attrs.align || 'center';
        const width = attrs.width || '100%';

        imageBubbleMenu.querySelectorAll('[data-img-align]').forEach(btn => {
          btn.classList.toggle('active', btn.getAttribute('data-img-align') === align);
        });

        imageBubbleMenu.querySelectorAll('[data-size]').forEach(btn => {
          btn.classList.toggle('active', btn.getAttribute('data-size') === width || (width === '100%' && btn.getAttribute('data-size') === '100%'));
        });

        const view = editor.view;
        let nodeDom = null;
        if (typeof view.nodeDOM === 'function') {
          const dom = view.nodeDOM(from);
          if (dom) {
            nodeDom = (dom.tagName === 'IMG') ? dom : dom.querySelector('img');
          }
        }
        if (!nodeDom) {
          const selected = view.dom.querySelector('.ProseMirror-selectednode');
          if (selected) {
            nodeDom = (selected.tagName === 'IMG') ? selected : selected.querySelector('img');
          }
        }
        const imgRect = (nodeDom && typeof nodeDom.getBoundingClientRect === 'function') ? nodeDom.getBoundingClientRect() : null;

        showImageBubble();
        const menuRect = imageBubbleMenu.getBoundingClientRect();

        let left, top;
        if (imgRect && imgRect.width > 0) {
          left = imgRect.left + (imgRect.width / 2) - (menuRect.width / 2);
          top = imgRect.top - menuRect.height - 8;
          if (top < 10) {
            top = imgRect.bottom + 8;
          }
        } else {
          const start = view.coordsAtPos(from);
          const end = view.coordsAtPos(to);
          left = ((start.left + end.left) / 2) - (menuRect.width / 2);
          top = start.top - menuRect.height - 8;
          if (top < 10) {
            top = end.bottom + 8;
          }
        }

        imageBubbleMenu.style.position = 'fixed';
        imageBubbleMenu.style.left = `${Math.max(10, Math.min(window.innerWidth - menuRect.width - 10, left))}px`;
        imageBubbleMenu.style.top = `${Math.max(10, top)}px`;
        imageBubbleMenu.style.zIndex = '9999';
      }
      return;
    } else {
      hideImageBubble();
    }

    if (!bubbleMenu) return;
    if (empty) {
      bubbleMenu.style.display = 'none';
      return;
    }

    // Toggle active classes on bubble buttons
    bubbleMenu.querySelector('[data-cmd="bold"]')?.classList.toggle('active', editor.isActive('bold'));
    bubbleMenu.querySelector('[data-cmd="italic"]')?.classList.toggle('active', editor.isActive('italic'));
    bubbleMenu.querySelector('[data-cmd="underline"]')?.classList.toggle('active', editor.isActive('underline'));
    bubbleMenu.querySelector('[data-cmd="strikeThrough"]')?.classList.toggle('active', editor.isActive('strike'));
    bubbleMenu.querySelector('[data-cmd="highlight"]')?.classList.toggle('active', editor.isActive('highlight'));
    bubbleMenu.querySelector('[data-cmd="code"]')?.classList.toggle('active', editor.isActive('code'));
    bubbleMenu.querySelector('[data-cmd="align-left"]')?.classList.toggle('active', editor.isActive({ textAlign: 'left' }));
    bubbleMenu.querySelector('[data-cmd="align-center"]')?.classList.toggle('active', editor.isActive({ textAlign: 'center' }));
    bubbleMenu.querySelector('[data-cmd="align-right"]')?.classList.toggle('active', editor.isActive({ textAlign: 'right' }));
    bubbleMenu.querySelector('[data-cmd="link"]')?.classList.toggle('active', editor.isActive('link'));

    const view = editor.view;
    const start = view.coordsAtPos(from);
    const end = view.coordsAtPos(to);

    bubbleMenu.style.display = 'flex';
    const menuRect = bubbleMenu.getBoundingClientRect();
    const left = ((start.left + end.left) / 2) - (menuRect.width / 2);
    let top = start.top - menuRect.height - 10;
    if (top < 10) {
      top = end.bottom + 10;
    }

    bubbleMenu.style.position = 'fixed';
    bubbleMenu.style.left = `${Math.max(10, Math.min(window.innerWidth - menuRect.width - 10, left))}px`;
    bubbleMenu.style.top = `${Math.max(10, top)}px`;
    bubbleMenu.style.zIndex = '9999';
  }

  // Handle image alignment toolbar buttons
  imageBubbleMenu?.querySelectorAll('[data-img-align]').forEach(btn => {
    btn.addEventListener('mousedown', (e) => {
      e.preventDefault();
      const align = btn.getAttribute('data-img-align');
      if (window.pmEditor) {
        window.pmEditor.chain().focus().updateAttributes('image', { align }).run();
        updateBubblePosition(window.pmEditor);
        setTimeout(() => { if (window.pmEditor) updateBubblePosition(window.pmEditor); }, 30);
      }
    });
  });

  // Handle image size presets
  imageBubbleMenu?.querySelectorAll('[data-size]').forEach(btn => {
    btn.addEventListener('mousedown', (e) => {
      e.preventDefault();
      const width = btn.getAttribute('data-size');
      if (window.pmEditor) {
        window.pmEditor.chain().focus().updateAttributes('image', { width }).run();
        updateBubblePosition(window.pmEditor);
        setTimeout(() => { if (window.pmEditor) updateBubblePosition(window.pmEditor); }, 30);
      }
    });
  });

  // Handle custom size prompt
  imageBubbleMenu?.querySelector('#img-btn-custom-size')?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (window.pmEditor) {
      const currentWidth = window.pmEditor.getAttributes('image').width || '100%';
      const newWidth = prompt('Introduce el ancho deseado para la imagen (ej: 400px, 60%, 350px):', currentWidth);
      if (newWidth !== null && newWidth.trim()) {
        const val = newWidth.trim();
        const formatted = /^\d+$/.test(val) ? val + 'px' : val;
        window.pmEditor.chain().focus().updateAttributes('image', { width: formatted }).run();
        updateBubblePosition(window.pmEditor);
      }
    }
  });

  imageBubbleMenu?.querySelector('#img-btn-alt')?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    if (window.pmEditor) {
      const currentAlt = window.pmEditor.getAttributes('image').alt || '';
      const newAlt = prompt('Texto alternativo (alt) de la imagen:', currentAlt);
      if (newAlt !== null) {
        window.pmEditor.chain().focus().updateAttributes('image', { alt: newAlt }).run();
      }
    }
  });

  function findImagePos(img, view) {
    if (!img || !view) return null;
    try {
      const rawPos = view.posAtDOM(img, 0);
      if (typeof rawPos === 'number') {
        if (view.state.doc.nodeAt(rawPos)?.type?.name === 'image') return rawPos;
        if (rawPos > 0 && view.state.doc.nodeAt(rawPos - 1)?.type?.name === 'image') return rawPos - 1;
      }
    } catch(e) {}
    try {
      const src = img.getAttribute('src');
      let found = null;
      view.state.doc.descendants((node, pos) => {
        if (found !== null) return false;
        if (node.type.name === 'image' && node.attrs.src === src) {
          found = pos;
          return false;
        }
      });
      return found;
    } catch(e) {}
    return null;
  }

  // Handle delete image button
  imageBubbleMenu?.querySelector('#img-btn-delete')?.addEventListener('mousedown', (e) => {
    e.preventDefault();
    e.stopPropagation();
    hideImageBubble();
    if (window.pmEditor) {
      window.pmEditor.chain().focus().deleteSelection().run();
      hideImageBubble();
      setTimeout(hideImageBubble, 30);
      setTimeout(hideImageBubble, 100);
    }
  });

  // Clicking an image in the editor directly selects it
  pmMount?.addEventListener('click', (e) => {
    const img = e.target.closest('img');
    if (img && window.pmEditor) {
      const pos = findImagePos(img, window.pmEditor.view);
      if (typeof pos === 'number' && pos >= 0) {
        window.pmEditor.chain().focus().setNodeSelection(pos).run();
        updateBubblePosition(window.pmEditor);
      }
    }
  });

  // Backspace or Delete key removes selected image
  pmMount?.addEventListener('keydown', (e) => {
    if ((e.key === 'Backspace' || e.key === 'Delete') && window.pmEditor && window.pmEditor.isActive('image')) {
      e.preventDefault();
      hideImageBubble();
      window.pmEditor.chain().focus().deleteSelection().run();
      hideImageBubble();
      setTimeout(hideImageBubble, 50);
    }
  });

  bubbleMenu?.querySelectorAll('.bubble-btn').forEach(btn => {
    btn.addEventListener('mousedown', (e) => {
      e.preventDefault();
      const cmd = btn.getAttribute('data-cmd');
      if (window.pmEditor) {
        if (cmd === 'bold') window.pmEditor.chain().focus().toggleBold().run();
        else if (cmd === 'italic') window.pmEditor.chain().focus().toggleItalic().run();
        else if (cmd === 'underline') window.pmEditor.chain().focus().toggleUnderline().run();
        else if (cmd === 'strikeThrough') window.pmEditor.chain().focus().toggleStrike().run();
        else if (cmd === 'highlight') window.pmEditor.chain().focus().toggleHighlight().run();
        else if (cmd === 'code') window.pmEditor.chain().focus().toggleCode().run();
        else if (cmd === 'align-left') window.pmEditor.chain().focus().setTextAlign('left').run();
        else if (cmd === 'align-center') window.pmEditor.chain().focus().setTextAlign('center').run();
        else if (cmd === 'align-right') window.pmEditor.chain().focus().setTextAlign('right').run();
        else if (cmd === 'link') {
          const prev = window.pmEditor.getAttributes('link').href || '';
          const url = prompt('Introduce la URL del enlace:', prev);
          if (url === null) return;
          if (url === '') window.pmEditor.chain().focus().unsetLink().run();
          else window.pmEditor.chain().focus().setLink({ href: url }).run();
        }
        updateBubblePosition(window.pmEditor);
      } else {
        if (cmd === 'align-left') document.execCommand('justifyLeft', false, null);
        else if (cmd === 'align-center') document.execCommand('justifyCenter', false, null);
        else if (cmd === 'align-right') document.execCommand('justifyRight', false, null);
        else document.execCommand(cmd, false, null);
      }
    });
  });

  // 5. SLASH COMMAND PALETTE (/)
  let slashActive = false;
  let slashIdx = 0;

  function closeSlashMenu() {
    slashActive = false;
    if (slashMenu) slashMenu.style.display = 'none';
  }

  function openSlashMenu() {
    if (!slashMenu) return;
    if (window.pmEditor) {
      const { from } = window.pmEditor.state.selection;
      const coords = window.pmEditor.view.coordsAtPos(from);
      slashMenu.style.position = 'fixed';
      slashMenu.style.top = `${coords.bottom + 6}px`;
      slashMenu.style.left = `${Math.min(window.innerWidth - 300, Math.max(10, coords.left))}px`;
      slashMenu.style.zIndex = '9999';
      slashMenu.style.display = 'block';
      slashActive = true;
      slashIdx = 0;
      updateSlashSelection();
    }
  }

  function updateSlashSelection() {
    if (!slashMenu) return;
    const items = slashMenu.querySelectorAll('.slash-item');
    items.forEach((it, i) => {
      it.classList.toggle('active', i === slashIdx);
      if (i === slashIdx) it.scrollIntoView({ block: 'nearest' });
    });
  }

  function executeSlashCommand(cmd) {
    closeSlashMenu();
    if (!window.pmEditor) return;

    const { $from } = window.pmEditor.state.selection;
    const from = $from.pos - 1;
    const to = $from.pos;
    const chain = window.pmEditor.chain().focus().deleteRange({ from, to });

    switch (cmd) {
      case 'h2':
        chain.toggleHeading({ level: 2 }).run();
        break;
      case 'h3':
        chain.toggleHeading({ level: 3 }).run();
        break;
      case 'quote':
        chain.toggleBlockquote().run();
        break;
      case 'callout':
        chain.insertContent('<blockquote class="callout-note"><strong>💡 Nota:</strong> Escribe tu apunte destacado aquí...</blockquote><p></p>').run();
        break;
      case 'code':
        chain.toggleCodeBlock().run();
        break;
      case 'ul':
        chain.toggleBulletList().run();
        break;
      case 'ol':
        chain.toggleOrderedList().run();
        break;
      case 'table':
        chain.insertContent('<table style="width:100%; border-collapse:collapse; margin:1.25rem 0; border:1px solid var(--border);"><thead><tr style="background:var(--bg3);"><th style="border:1px solid var(--border); padding:8px 12px; text-align:left;">Encabezado 1</th><th style="border:1px solid var(--border); padding:8px 12px; text-align:left;">Encabezado 2</th></tr></thead><tbody><tr><td style="border:1px solid var(--border); padding:8px 12px;">Dato 1</td><td style="border:1px solid var(--border); padding:8px 12px;">Dato 2</td></tr><tr><td style="border:1px solid var(--border); padding:8px 12px;">Dato 3</td><td style="border:1px solid var(--border); padding:8px 12px;">Dato 4</td></tr></tbody></table><p></p>').run();
        break;
      case 'image':
        insertImage();
        break;
      case 'audio':
        const audioUrl = prompt('Introduce la URL del audio (MP3, OGG, WAV o podcast):');
        if (audioUrl) {
          chain.insertContent(`<div class="media-audio"><audio controls src="${audioUrl}" style="width:100%;"></audio></div><p></p>`).run();
        }
        break;
      case 'video':
        const videoUrl = prompt('Introduce la URL del video (YouTube, Vimeo o archivo MP4/WebM):');
        if (videoUrl) {
          const ytMatch = videoUrl.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i);
          const vmMatch = videoUrl.match(/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/(?:[^\/]*)\/videos\/|album\/(?:\d+)\/video\/|video\/|)(\d+)/i);
          if (ytMatch && ytMatch[1]) {
            chain.insertContent(`<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/${ytMatch[1]}" allowfullscreen loading="lazy"></iframe></div><p></p>`).run();
          } else if (vmMatch && vmMatch[1]) {
            chain.insertContent(`<div class="yt-embed"><iframe src="https://player.vimeo.com/video/${vmMatch[1]}" allowfullscreen loading="lazy"></iframe></div><p></p>`).run();
          } else {
            chain.insertContent(`<div class="media-video"><video controls src="${videoUrl}" style="max-width:100%; border-radius:8px;"></video></div><p></p>`).run();
          }
        }
        break;
      case 'hr':
        chain.setHorizontalRule().run();
        break;
      case 'align-left':
        chain.setTextAlign('left').run();
        break;
      case 'align-center':
        chain.setTextAlign('center').run();
        break;
      case 'align-right':
        chain.setTextAlign('right').run();
        break;
    }
  }

  slashMenu?.querySelectorAll('.slash-item').forEach((item, idx) => {
    item.addEventListener('click', () => {
      executeSlashCommand(item.getAttribute('data-cmd'));
    });
    item.addEventListener('mouseenter', () => {
      slashIdx = idx;
      updateSlashSelection();
    });
  });

  window.addEventListener('keydown', (e) => {
    if (slashActive) {
      const items = slashMenu.querySelectorAll('.slash-item');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        slashIdx = (slashIdx + 1) % items.length;
        updateSlashSelection();
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        slashIdx = (slashIdx - 1 + items.length) % items.length;
        updateSlashSelection();
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (items[slashIdx]) {
          executeSlashCommand(items[slashIdx].getAttribute('data-cmd'));
        }
        return;
      }
      if (e.key === 'Escape' || e.key === 'Backspace') {
        closeSlashMenu();
        return;
      }
    }

    if (e.key === '/' && !slashActive && window.pmEditor && window.pmEditor.isFocused) {
      setTimeout(() => {
        const { $from } = window.pmEditor.state.selection;
        if ($from.parent.textContent === '/') {
          openSlashMenu();
        }
      }, 20);
    }
  });

  document.addEventListener('mousedown', (e) => {
    if (slashActive && !slashMenu.contains(e.target)) {
      closeSlashMenu();
    }
    if (imageBubbleMenu && imageBubbleMenu.style.display !== 'none') {
      if (!imageBubbleMenu.contains(e.target) && !e.target.closest('img')) {
        hideImageBubble();
      }
    }
    if (bubbleMenu && bubbleMenu.style.display !== 'none') {
      if (!bubbleMenu.contains(e.target) && !pmMount?.contains(e.target)) {
        bubbleMenu.style.display = 'none';
      }
    }
  });

  window.addEventListener('scroll', () => {
    if (imageBubbleMenu && imageBubbleMenu.style.display !== 'none') {
      if (window.pmEditor && window.pmEditor.isActive('image')) {
        updateBubblePosition(window.pmEditor);
      } else {
        hideImageBubble();
      }
    }
    if (bubbleMenu && bubbleMenu.style.display !== 'none') {
      if (window.pmEditor) {
        updateBubblePosition(window.pmEditor);
      }
    }
  }, { passive: true });

  // 6. EDITOR MODE SWITCHER: WYSIWYG, RAW, MARKDOWN
  function htmlToMarkdown(html) {
    if (!html) return '';
    const div = document.createElement('div');
    div.innerHTML = html;

    function walk(node) {
      if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent;
      }
      if (node.nodeType !== Node.ELEMENT_NODE) {
        return '';
      }

      const tag = node.tagName.toLowerCase();
      const inner = Array.from(node.childNodes).map(walk).join('');

      switch (tag) {
        case 'h1': return `\n\n# ${inner.trim()}\n\n`;
        case 'h2': return `\n\n## ${inner.trim()}\n\n`;
        case 'h3': return `\n\n### ${inner.trim()}\n\n`;
        case 'h4': return `\n\n#### ${inner.trim()}\n\n`;
        case 'h5': return `\n\n##### ${inner.trim()}\n\n`;
        case 'h6': return `\n\n###### ${inner.trim()}\n\n`;
        case 'p': return `\n\n${inner.trim()}\n\n`;
        case 'br': return "\n";
        case 'hr': return "\n\n---\n\n";
        case 'strong':
        case 'b': return `**${inner.trim()}**`;
        case 'em':
        case 'i': return `*${inner.trim()}*`;
        case 'del':
        case 's':
        case 'strike': return `~~${inner.trim()}~~`;
        case 'code':
          if (node.parentNode && node.parentNode.tagName.toLowerCase() === 'pre') {
            return inner;
          }
          return `\`${inner}\``;
        case 'pre': {
          const code = node.querySelector('code');
          const lang = code ? (code.className.match(/language-(\w+)/)?.[1] || '') : '';
          const txt = code ? code.textContent : node.textContent;
          return `\n\n\`\`\`${lang}\n${txt.trim()}\n\`\`\`\n\n`;
        }
        case 'blockquote': {
          const lines = inner.trim().split('\n');
          return '\n\n' + lines.map(l => `> ${l}`).join('\n') + '\n\n';
        }
        case 'ul': {
          const items = Array.from(node.children)
            .filter(c => c.tagName.toLowerCase() === 'li')
            .map(li => `- ${walk(li).trim()}`)
            .join('\n');
          return `\n\n${items}\n\n`;
        }
        case 'ol': {
          let idx = 1;
          const items = Array.from(node.children)
            .filter(c => c.tagName.toLowerCase() === 'li')
            .map(li => `${idx++}. ${walk(li).trim()}`)
            .join('\n');
          return `\n\n${items}\n\n`;
        }
        case 'li': return inner.trim();
        case 'a': {
          const href = node.getAttribute('href') || '';
          const title = node.getAttribute('title');
          const titlePart = title ? ` "${title}"` : '';
          return `[${inner.trim() || href}](${href}${titlePart})`;
        }
        case 'img': {
          const src = node.getAttribute('src') || '';
          const alt = node.getAttribute('alt') || '';
          const title = node.getAttribute('title');
          const titlePart = title ? ` "${title}"` : '';
          return `![${alt}](${src}${titlePart})`;
        }
        case 'table': {
          const rows = Array.from(node.querySelectorAll('tr'));
          if (!rows.length) return '';
          let md = '\n\n';
          rows.forEach((tr, rIdx) => {
            const cells = Array.from(tr.querySelectorAll('th, td'));
            md += '| ' + cells.map(c => walk(c).trim().replace(/\|/g, '\\|')).join(' | ') + ' |\n';
            if (rIdx === 0) {
              md += '| ' + cells.map(() => '---').join(' | ') + ' |\n';
            }
          });
          return md + '\n';
        }
        default:
          if (['iframe', 'audio', 'video', 'figure', 'details', 'summary'].includes(tag)) {
            return `\n\n${node.outerHTML}\n\n`;
          }
          return inner;
      }
    }

    let res = walk(div);
    res = res.replace(/\n{3,}/g, '\n\n').trim();
    return res;
  }

  function clientSimpleMarkdown(md) {
    if (!md) return '<p></p>';
    let html = md
      .replace(/^###### (.*$)/gim, '<h6>$1</h6>')
      .replace(/^##### (.*$)/gim, '<h5>$1</h5>')
      .replace(/^#### (.*$)/gim, '<h4>$1</h4>')
      .replace(/^### (.*$)/gim, '<h3>$1</h3>')
      .replace(/^## (.*$)/gim, '<h2>$1</h2>')
      .replace(/^# (.*$)/gim, '<h1>$1</h1>')
      .replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>')
      .replace(/\*\*\*(.*?)\*\*\*/gim, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/gim, '<em>$1</em>')
      .replace(/~~(.*?)~~/gim, '<del>$1</del>')
      .replace(/`([^`]+)`/gim, '<code>$1</code>')
      .replace(/!\[(.*?)\]\((.*?)\)/gim, '<img alt="$1" src="$2">')
      .replace(/\[(.*?)\]\((.*?)\)/gim, '<a href="$2">$1</a>')
      .replace(/\n\n/gim, '</p><p>');
    return '<p>' + html + '</p>';
  }

  window.markdownToHtmlSync = clientSimpleMarkdown;

  async function markdownToHtml(md) {
    if (!md || !md.trim()) return '<p></p>';
    try {
      const fd = new FormData();
      fd.append('md', md);
      const res = await fetch('markdown_preview.php', { method: 'POST', body: fd });
      if (res.ok) {
        const text = await res.text();
        return text || '<p></p>';
      }
    } catch (e) {
      console.warn('Server markdown parsing failed:', e);
    }
    return clientSimpleMarkdown(md);
  }

  async function switchEditorMode(newMode) {
    if (newMode === mode) return;
    const prevMode = mode;
    mode = newMode;
    window.currentEditorMode = newMode;

    document.querySelectorAll('.editor-mode-switcher .mode-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.mode === newMode);
    });

    hideImageBubble();
    if (slashMenu) slashMenu.style.display = 'none';
    if (bubbleMenu) bubbleMenu.style.display = 'none';

    let currentHtml = '';
    let currentMd = '';

    if (prevMode === 'wysiwyg') {
      currentHtml = window.pmEditor ? window.pmEditor.getHTML() : (fallbackEditor?.innerHTML || '');
    } else if (prevMode === 'raw') {
      currentHtml = rawEditor ? rawEditor.value : '';
    } else if (prevMode === 'markdown') {
      currentMd = markdownEditor ? markdownEditor.value : '';
    }

    if (newMode === 'wysiwyg') {
      let targetHtml = currentHtml;
      if (prevMode === 'markdown') {
        targetHtml = await markdownToHtml(currentMd);
      }
      if (window.pmEditor) {
        window.pmEditor.commands.setContent(targetHtml || '<p></p>');
        if (pmMount) pmMount.style.display = 'block';
      } else if (fallbackEditor) {
        fallbackEditor.innerHTML = targetHtml || '';
        fallbackEditor.style.display = 'block';
      }
      if (rawEditor) rawEditor.style.display = 'none';
      if (markdownEditor) markdownEditor.style.display = 'none';
      if (contentInput) contentInput.value = targetHtml;
      if (fmtInput) fmtInput.value = 'html';
      updateMetrics(window.pmEditor ? window.pmEditor.getText() : (fallbackEditor?.innerText || ''));

    } else if (newMode === 'raw') {
      let targetHtml = currentHtml;
      if (prevMode === 'markdown') {
        targetHtml = await markdownToHtml(currentMd);
      }
      if (rawEditor) {
        rawEditor.value = targetHtml;
        rawEditor.style.display = 'block';
      }
      if (pmMount) pmMount.style.display = 'none';
      if (fallbackEditor) fallbackEditor.style.display = 'none';
      if (markdownEditor) markdownEditor.style.display = 'none';
      if (contentInput) contentInput.value = targetHtml;
      if (fmtInput) fmtInput.value = 'html';
      updateMetrics(rawEditor ? rawEditor.value : '');

    } else if (newMode === 'markdown') {
      let targetMd = currentMd;
      if (prevMode === 'wysiwyg' || prevMode === 'raw') {
        targetMd = htmlToMarkdown(currentHtml);
      }
      if (markdownEditor) {
        markdownEditor.value = targetMd;
        markdownEditor.style.display = 'block';
      }
      if (pmMount) pmMount.style.display = 'none';
      if (fallbackEditor) fallbackEditor.style.display = 'none';
      if (rawEditor) rawEditor.style.display = 'none';
      if (contentInput) contentInput.value = targetMd;
      if (fmtInput) fmtInput.value = 'markdown';
      updateMetrics(markdownEditor ? markdownEditor.value : '');
    }

    markDirty();
  }

  window.switchEditorMode = switchEditorMode;

  document.querySelectorAll('.editor-mode-switcher .mode-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      switchEditorMode(btn.dataset.mode);
    });
  });

  markdownEditor?.addEventListener('input', () => {
    markDirty();
    updateMetrics(markdownEditor.value);
    if (window.triggerAutosave) window.triggerAutosave();
  });

  rawEditor?.addEventListener('input', () => {
    markDirty();
    updateMetrics(rawEditor.value);
    if (window.triggerAutosave) window.triggerAutosave();
  });

  // 7. FIND & REPLACE (Ctrl+F / Ctrl+H)
  const findInput = document.getElementById('find-input');
  const replaceInput = document.getElementById('replace-input');
  const findCounter = document.getElementById('find-counter');
  const replaceRow = document.getElementById('replace-row');
  let currentMatches = [];
  let currentMatchIdx = -1;

  function clearFindHighlights() {
    const target = pmMount || fallbackEditor;
    if (!target) return;
    target.querySelectorAll('mark.find-match').forEach(m => {
      const text = document.createTextNode(m.textContent);
      m.parentNode.replaceChild(text, m);
    });
    target.normalize();
    currentMatches = [];
    currentMatchIdx = -1;
    if (findCounter) findCounter.textContent = '0/0';
  }

  function performSearch() {
    clearFindHighlights();
    const query = findInput ? findInput.value.trim() : '';
    if (!query) return;

    const target = pmMount || fallbackEditor;
    if (!target) return;

    const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT, null, false);
    const nodesToReplace = [];
    let node;
    while ((node = walker.nextNode())) {
      if (node.parentElement && (node.parentElement.tagName === 'SCRIPT' || node.parentElement.tagName === 'STYLE')) continue;
      const val = node.nodeValue;
      if (val.toLowerCase().includes(query.toLowerCase())) {
        nodesToReplace.push(node);
      }
    }

    const qLen = query.length;
    nodesToReplace.forEach(textNode => {
      const val = textNode.nodeValue;
      const lower = val.toLowerCase();
      const qLower = query.toLowerCase();
      const parent = textNode.parentNode;
      if (!parent) return;

      const frag = document.createDocumentFragment();
      let start = 0;
      let pos;
      while ((pos = lower.indexOf(qLower, start)) !== -1) {
        if (pos > start) {
          frag.appendChild(document.createTextNode(val.substring(start, pos)));
        }
        const mark = document.createElement('mark');
        mark.className = 'find-match';
        mark.textContent = val.substring(pos, pos + qLen);
        frag.appendChild(mark);
        currentMatches.push(mark);
        start = pos + qLen;
      }
      if (start < val.length) {
        frag.appendChild(document.createTextNode(val.substring(start)));
      }
      parent.replaceChild(frag, textNode);
    });

    if (currentMatches.length > 0) {
      currentMatchIdx = 0;
      highlightCurrentMatch();
    } else {
      findCounter.textContent = '0/0';
    }
  }

  function highlightCurrentMatch() {
    currentMatches.forEach((m, idx) => {
      m.classList.toggle('current', idx === currentMatchIdx);
    });
    if (currentMatches[currentMatchIdx]) {
      currentMatches[currentMatchIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
      findCounter.textContent = (currentMatchIdx + 1) + '/' + currentMatches.length;
    }
  }

  function openFindBar(showReplace = false) {
    if (!findBar) return;
    findBar.style.display = 'flex';
    if (replaceRow) replaceRow.style.display = showReplace ? 'flex' : 'none';
    findInput?.focus();
    findInput?.select();
    if (findInput && findInput.value) performSearch();
  }

  function closeFindBar() {
    if (findBar) findBar.style.display = 'none';
    clearFindHighlights();
    if (window.pmEditor) window.pmEditor.commands.focus();
    else if (fallbackEditor) fallbackEditor.focus();
  }

  findInput?.addEventListener('input', performSearch);
  findInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (e.shiftKey) {
        if (currentMatches.length) {
          currentMatchIdx = (currentMatchIdx - 1 + currentMatches.length) % currentMatches.length;
          highlightCurrentMatch();
        }
      } else {
        if (currentMatches.length) {
          currentMatchIdx = (currentMatchIdx + 1) % currentMatches.length;
          highlightCurrentMatch();
        }
      }
    } else if (e.key === 'Escape') {
      closeFindBar();
    }
  });

  document.getElementById('find-next-btn')?.addEventListener('click', () => {
    if (currentMatches.length) {
      currentMatchIdx = (currentMatchIdx + 1) % currentMatches.length;
      highlightCurrentMatch();
    }
  });

  document.getElementById('find-prev-btn')?.addEventListener('click', () => {
    if (currentMatches.length) {
      currentMatchIdx = (currentMatchIdx - 1 + currentMatches.length) % currentMatches.length;
      highlightCurrentMatch();
    }
  });

  document.getElementById('find-toggle-replace')?.addEventListener('click', () => {
    if (replaceRow) {
      replaceRow.style.display = replaceRow.style.display === 'none' ? 'flex' : 'none';
    }
  });

  document.getElementById('find-close-btn')?.addEventListener('click', closeFindBar);

  document.getElementById('replace-one-btn')?.addEventListener('click', () => {
    if (currentMatches.length > 0 && currentMatchIdx >= 0) {
      const cur = currentMatches[currentMatchIdx];
      const repl = replaceInput?.value || '';
      const textNode = document.createTextNode(repl);
      cur.parentNode.replaceChild(textNode, cur);
      performSearch();
      markDirty();
    }
  });

  document.getElementById('replace-all-btn')?.addEventListener('click', () => {
    const repl = replaceInput?.value || '';
    currentMatches.forEach(m => {
      const textNode = document.createTextNode(repl);
      m.parentNode.replaceChild(textNode, m);
    });
    clearFindHighlights();
    performSearch();
    markDirty();
  });

  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'f' || e.key === 'F')) {
      e.preventDefault();
      openFindBar(false);
    } else if ((e.ctrlKey || e.metaKey) && (e.key === 'h' || e.key === 'H')) {
      e.preventDefault();
      openFindBar(true);
    } else if (e.key === 'Escape' && findBar && findBar.style.display !== 'none') {
      closeFindBar();
    }
  });

  // 8. DESK SHEET CLICK-TO-FOCUS
  const deskSheet = document.querySelector('.desk-sheet');
  if (deskSheet) {
    deskSheet.addEventListener('click', (e) => {
      if (e.target === deskSheet || e.target.classList.contains('desk-canvas-wrap')) {
        if (window.pmEditor) {
          window.pmEditor.commands.focus('end');
        } else if (fallbackEditor) {
          fallbackEditor.focus();
        }
      }
    });
  }
})();

// ── Submit sync ────────────────────────────────────────────────────────────
document.getElementById('editor-form').addEventListener('submit', function(e) {
  console.log('Form submission started, mode:', mode);
  
  // Prevent double submission
  if (this.classList.contains('submitting')) {
    e.preventDefault();
    console.log('Prevented double submission');
    return;
  }
  
  // Always sync content before submission
  syncContentBeforeSave();

  // Ensure any pending text in tag or category input is captured
  const tagIn = document.getElementById('tag-input');
  const tagHid = document.getElementById('tag-hidden');
  if (tagIn && tagHid && tagIn.value.trim()) {
    const curTags = tagHid.value.split(',').map(v => v.trim()).filter(Boolean);
    const toAdd = tagIn.value.trim();
    if (!curTags.includes(toAdd)) {
      curTags.push(toAdd);
      tagHid.value = curTags.join(', ');
    }
    tagIn.value = '';
  }
  const catIn = document.getElementById('cat-input');
  const catHid = document.getElementById('cat-hidden');
  if (catIn && catHid && catIn.value.trim()) {
    const curCats = catHid.value.split(',').map(v => v.trim()).filter(Boolean);
    const toAdd = catIn.value.trim();
    if (!curCats.includes(toAdd)) {
      curCats.push(toAdd);
      catHid.value = curCats.join(', ');
    }
    catIn.value = '';
  }
  
  // Verify content was captured
  const capturedContent = contentInput.value;
  console.log('Captured content length:', capturedContent.length);
  
  if (capturedContent.length === 0) {
    console.warn('Warning: Empty content captured!');
  }
  
  // Add a small delay to ensure content is captured
  setTimeout(() => {
    this.classList.add('submitting');
    console.log('Form marked as submitting');
  }, 100);
});

// ── Autosave ──────────────────────────────────────────────────────────────
const AUTOSAVE_KEY  = 'kutblog_autosave_' + <?= json_encode($type) ?> + '_' + <?= json_encode($slug ?: 'new') ?>;
const AUTOSAVE_URL  = <?= json_encode(base_url() . '/admin/autosave.php') ?>;
const AUTOSAVE_CSRF = <?= json_encode(generate_csrf()) ?>;

let autosaveSlug    = <?= json_encode($slug) ?>;
let lastSavedHash   = null;
let serverSyncTimer = null;

const autosaveBar = document.createElement('div');
autosaveBar.id = 'autosave-bar';
autosaveBar.style.cssText = [
  'position:fixed','bottom:1rem','left:50%','transform:translateX(-50%)',
  'background:var(--surface2)','border:1px solid var(--border2)',
  'color:var(--muted)','font-size:0.72rem','padding:0.3rem 0.85rem',
  'border-radius:20px','z-index:8000','opacity:0','transition:opacity 0.3s',
  'pointer-events:none','font-family:inherit',
].join(';');
document.body.appendChild(autosaveBar);

function showAutosaveMsg(msg, color) {
  autosaveBar.textContent = msg;
  autosaveBar.style.color = color || 'var(--muted)';
  autosaveBar.style.opacity = '1';
  clearTimeout(autosaveBar._t);
  autosaveBar._t = setTimeout(() => autosaveBar.style.opacity = '0', 3000);
}

function getCurrentContent() {
  const curMode = window.currentEditorMode || mode;
  if (curMode === 'markdown') {
    const mdEd = document.getElementById('markdown-editor');
    return mdEd ? mdEd.value : '';
  }
  if (curMode === 'raw') {
    const rawEd = document.getElementById('raw-editor');
    return rawEd ? rawEd.value : '';
  }
  if (window.pmEditor) {
    return window.pmEditor.getHTML();
  }
  const editor = document.getElementById('editor');
  return editor ? editor.innerHTML : (contentInput ? contentInput.value : '');
}

function getFormData() {
  const curMode = window.currentEditorMode || mode;
  const titleEl = document.getElementById('post-title');
  return {
    title:          titleEl ? titleEl.value.trim() : '',
    content:        getCurrentContent(),
    content_format: curMode === 'markdown' ? 'markdown' : 'html',
    excerpt:        document.querySelector('[name="excerpt"]')?.value || '',
    categories:     document.getElementById('cat-hidden')?.value || '',
    tags:           document.getElementById('tag-hidden')?.value || '',
    featured_image: document.getElementById('featured_image_input')?.value || '',
    mastodon_url:   document.querySelector('[name="mastodon_url"]')?.value || '',
    kutpod_url:     document.querySelector('[name="kutpod_url"]')?.value || '',
    series_id:      document.getElementById('series-select')?.value || '',
  };
}

function simpleHash(str) {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = Math.imul(31, h) + str.charCodeAt(i) | 0;
  return h;
}

let localSaveTimer = null;
function scheduleLocalSave() {
  clearTimeout(localSaveTimer);
  localSaveTimer = setTimeout(() => {
    try {
      const data = getFormData();
      if (!data.title && !data.content) return;
      localStorage.setItem(AUTOSAVE_KEY, JSON.stringify({
        ...data,
        slug: autosaveSlug,
        savedAt: Date.now(),
      }));
    } catch(e) {}
  }, 1000);
}

let serverSyncDebounce = null;
function scheduleServerSync(delay = 3500) {
  clearTimeout(serverSyncDebounce);
  serverSyncDebounce = setTimeout(() => {
    syncToServer();
  }, delay);
}

window.triggerAutosave = function() {
  scheduleLocalSave();
  scheduleServerSync(3500);
};

async function syncToServer() {
  const data = getFormData();
  // Don't autosave if both title and content are empty
  if (!data.title && (!data.content || data.content === '<p></p>' || data.content === '')) return;

  const hash = simpleHash(data.title + '|' + data.content + '|' + data.excerpt + '|' + data.categories + '|' + data.tags + '|' + data.featured_image);
  if (hash === lastSavedHash) return;

  try {
    const fd = new FormData();
    const csrfVal = document.querySelector('input[name="csrf"]')?.value || AUTOSAVE_CSRF;
    fd.append('csrf',           csrfVal);
    fd.append('type',           <?= json_encode($type) ?>);
    fd.append('slug',           autosaveSlug || '');
    fd.append('title',          data.title || 'Borrador sin título');
    fd.append('content',        data.content);
    fd.append('content_format', data.content_format);
    fd.append('excerpt',        data.excerpt);
    fd.append('categories',     data.categories);
    fd.append('tags',           data.tags);
    fd.append('featured_image', data.featured_image);
    fd.append('mastodon_url',   data.mastodon_url);
    fd.append('kutpod_url',     data.kutpod_url);
    if (data.series_id !== undefined) fd.append('series_id', data.series_id);

    const res  = await fetch(AUTOSAVE_URL, { method: 'POST', body: fd });
    const json = await res.json();

    if (json.ok) {
      lastSavedHash = hash;
      if (json.new && json.slug && !autosaveSlug) {
        autosaveSlug = json.slug;
        const url = new URL(location.href);
        url.searchParams.set('slug', json.slug);
        history.replaceState({}, '', url);

        const customSlugInput = document.getElementById('custom-slug');
        if (customSlugInput && !customSlugInput.value) {
          customSlugInput.value = json.slug;
        }
      }
      showAutosaveMsg('✓ Borrador guardado automáticamente', 'var(--green)');
      try { localStorage.removeItem(AUTOSAVE_KEY); } catch(e) {}
      
      // Update the hidden form fields to keep them in sync
      if (contentInput) contentInput.value = data.content;
      const edHidden = document.getElementById('editor-hidden');
      if (edHidden) edHidden.value = data.content;
      if (fmtInput) fmtInput.value = data.content_format;
      
      console.log('Autosave completed, content synced');
    } else {
      console.warn('Autosave error response:', json);
    }
  } catch(e) {
    console.error('Autosave error:', e);
  }
}

serverSyncTimer = setInterval(syncToServer, 30000);

[rawEditor, document.getElementById('editor')].forEach(el => {
  el?.addEventListener('input', () => window.triggerAutosave && window.triggerAutosave());
});
document.getElementById('post-title')?.addEventListener('input', () => window.triggerAutosave && window.triggerAutosave());
document.querySelector('[name="excerpt"]')?.addEventListener('input', () => window.triggerAutosave && window.triggerAutosave());
document.getElementById('cat-hidden')?.addEventListener('change', () => window.triggerAutosave && window.triggerAutosave());
document.getElementById('tag-hidden')?.addEventListener('change', () => window.triggerAutosave && window.triggerAutosave());
document.getElementById('series-select')?.addEventListener('change', () => window.triggerAutosave && window.triggerAutosave());
document.getElementById('featured_image_input')?.addEventListener('input', () => window.triggerAutosave && window.triggerAutosave());

document.getElementById('editor-form')?.addEventListener('submit', () => {
  try { localStorage.removeItem(AUTOSAVE_KEY); } catch(e) {}
  clearInterval(serverSyncTimer);
  clearTimeout(serverSyncDebounce);
  lastSavedHash = null;
}, true);

// Function to sync content before save
function syncContentBeforeSave() {
  const curMode = window.currentEditorMode || mode;
  const contentInput = document.getElementById('content-input');
  const fmtInput = document.getElementById('content-format-input');
  const edHid = document.getElementById('editor-hidden');

  if (curMode === 'markdown') {
    const mdEd = document.getElementById('markdown-editor');
    const mdContent = mdEd ? mdEd.value : '';
    if (contentInput) contentInput.value = mdContent;
    if (fmtInput) fmtInput.value = 'markdown';
    if (edHid) edHid.value = mdContent;
    return;
  }

  if (curMode === 'raw') {
    const rawEd = document.getElementById('raw-editor');
    const rawContent = rawEd ? rawEd.value : '';
    if (contentInput) contentInput.value = rawContent;
    if (fmtInput) fmtInput.value = 'html';
    if (edHid) edHid.value = rawContent;
    return;
  }

  // WYSIWYG
  let htmlContent = '';
  if (window.pmEditor) {
    htmlContent = window.pmEditor.getHTML();
  } else {
    const editor = document.getElementById('editor');
    htmlContent = editor ? editor.innerHTML : '';
  }
  if (contentInput) contentInput.value = htmlContent;
  if (fmtInput) fmtInput.value = 'html';
  if (edHid) edHid.value = htmlContent;
}

function switchMode(newMode) {
  if (window.switchEditorMode) {
    window.switchEditorMode(newMode === 'visual' ? 'wysiwyg' : newMode);
  }
}

// ── HTML Editor Toolbar ──────────────────────────────────────────────────
// Función para manejar el dropdown de formatos (HTML mode)
function formatBlock(tag) {
  const editor = document.getElementById('editor');
  if (mode !== 'visual' || !editor) return;
  
  editor.focus();
  document.execCommand('formatBlock', false, tag);
}

function exec(cmd, val = null) {
  if (mode !== 'visual') return;
  const editor = document.getElementById('editor');
  editor.focus();
  
  // Special handling for strikethrough to ensure it works properly
  if (cmd === 'strikeThrough') {
    execStrikeThrough();
    return;
  }
  
  // Special handling for horizontal rule
  if (cmd === 'insertHorizontalRule') {
    document.execCommand('insertHTML', false, '<hr>');
    return;
  }
  
  // Use normal execCommand for other commands
  document.execCommand(cmd, false, val);
}

function execStrikeThrough() {
  if (mode !== 'visual') return;
  const editor = document.getElementById('editor');
  editor.focus();

  const selection = window.getSelection();
  if (selection.rangeCount === 0) return;

  const range = selection.getRangeAt(0);
  const ancestor = range.commonAncestorContainer;

  let delElement = ancestor.nodeType === 3 ? ancestor.parentElement : ancestor;
  let foundDel = null;

  while (delElement && delElement !== editor) {
    if (delElement.tagName === 'DEL' || delElement.tagName === 'S' || delElement.tagName === 'STRIKE') {
      foundDel = delElement;
      break;
    }
    delElement = delElement.parentElement;
  }

  if (foundDel) {
    const newRange = document.createRange();
    newRange.selectNode(foundDel);
    selection.removeAllRanges();
    selection.addRange(newRange);
    document.execCommand('insertHTML', false, foundDel.innerHTML);
  } else {
    const selectedText = range.toString();
    if (selectedText) {
      const div = document.createElement('div');
      div.appendChild(range.cloneContents());
      document.execCommand('insertHTML', false, `<del>${div.innerHTML}</del>`);
    } else {
      document.execCommand('insertHTML', false, '<del>texto tachado</del>');
    }
  }

  editor.dispatchEvent(new Event('input'));
}

function insertLink() {
  const url = prompt(<?= json_encode(__raw('prompt_link_url')) ?>);
  if (url) {
    const editor = document.getElementById('editor');
    editor.focus();
    document.execCommand('createLink', false, url);
  }
}

function insertImage(callback) {
  openImageInsertModal(callback);
}


function insertAudio() {
  const url = prompt('URL del audio (MP3, OGG, WAV, etc.):');
  if (url) {
    const editor = document.getElementById('editor');
    editor.focus();
    document.execCommand('insertHTML', false, `<div class="media-audio"><audio controls src="${url}" style="width:100%;"></audio></div>`);
  }
}

function insertVideo() {
  const url = prompt('URL del video:');
  if (url) {
    const editor = document.getElementById('editor');
    editor.focus();
    document.execCommand('insertHTML', false, `<video controls src="${url}" style="max-width:100%;"></video>`);
  }
}

// Connect toolbar buttons
function setupToolbarButtons() {
  // Font size buttons
  const fontSizeUp = document.getElementById('font-size-up');
  if (fontSizeUp) {
    fontSizeUp.addEventListener('click', e => {
      e.preventDefault();
      applyEditorFontSize(editorFontSize + 1);
    });
  }

  const fontSizeDown = document.getElementById('font-size-down');
  if (fontSizeDown) {
    fontSizeDown.addEventListener('click', e => {
      e.preventDefault();
      applyEditorFontSize(editorFontSize - 1);
    });
  }

  // Preview button
  const previewBtn = document.getElementById('preview-btn');
  if (previewBtn) {
    previewBtn.addEventListener('click', e => {
      e.preventDefault();
      openPreview();
    });
  }

  // Focus button
  const focusBtn = document.getElementById('focus-btn');
  if (focusBtn) {
    focusBtn.addEventListener('click', e => {
      e.preventDefault();
      toggleFocus();
    });
  }
}

// Initialize toolbar buttons when the page loads
document.addEventListener('DOMContentLoaded', function() {
  setupToolbarButtons();
  
  // Also set up mode switchers
  document.getElementById('switch-visual')?.addEventListener('click', () => switchMode('visual'));
  document.getElementById('switch-raw')?.addEventListener('click', () => switchMode('raw'));
  
  // Update hidden textarea before form submission for all buttons
  const form = document.getElementById('editor-form');
  const draftBtn = form.querySelector('button[name="status"][value="draft"]');
  const publishBtn = document.getElementById('publish-btn');
  
  if (draftBtn) {
    draftBtn.addEventListener('click', function(e) {
      // Sync content before saving
      syncContentBeforeSave();
    });
  }
  
  if (publishBtn) {
    publishBtn.addEventListener('click', function(e) {
      // Sync content before saving
      syncContentBeforeSave();
    });
  }
  
  // Also update the form submit handler to be more robust
  form.addEventListener('submit', function(e) {
    // Sync content one more time before submission
    syncContentBeforeSave();
  });
});


// ── Slug from title ────────────────────────────────────────────────────────
titleInput.addEventListener('input', () => {
  if (!slugManuallySet) {
    slugInput.value = titleInput.value
      .toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '');
  }
});
slugInput.addEventListener('input', () => { slugManuallySet = true; });

// ── Segmented status controller (quireINK style) ────────────────────────
function updateStatusUI(val) {
  const statusSelect = document.getElementById('status-select');
  if (statusSelect) statusSelect.value = val;
  
  document.querySelectorAll('.sheet-seg-item').forEach(item => {
    const r = item.querySelector('input[type="radio"]');
    if (r && r.value === val) {
      item.classList.add('active');
      r.checked = true;
    } else {
      item.classList.remove('active');
    }
  });
  
  const statusLabels = { 'published': 'Publicado', 'scheduled': 'Programado', 'draft': 'Borrador' };
  const statusClasses = { 'published': 'pub', 'scheduled': 'scheduled', 'draft': 'draft' };
  
  const pillHeader = document.getElementById('sheet-header-pill');
  if (pillHeader) {
    pillHeader.className = 'sheet-status-pill ' + (statusClasses[val] || 'draft');
    pillHeader.textContent = statusLabels[val] || 'Borrador';
  }
  const pillDesk = document.getElementById('desk-status-pill');
  if (pillDesk) {
    pillDesk.className = 'sheet-status-pill ' + (statusClasses[val] || 'draft');
    pillDesk.textContent = statusLabels[val] || 'Borrador';
  }
}

document.querySelectorAll('input[name="status_radio"]').forEach(radio => {
  radio.addEventListener('change', function() {
    updateStatusUI(this.value);
  });
});

// ── Date picker & auto-scheduling ─────────────────────────────────────────
const postCreatedAtInput = document.getElementById('post-created-at');
const btnSetNow = document.getElementById('btn-set-now');
const dateHint = document.getElementById('date-hint');

if (btnSetNow && postCreatedAtInput) {
  btnSetNow.addEventListener('click', () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    postCreatedAtInput.value = now.toISOString().slice(0, 16);
    if (dateHint) dateHint.textContent = 'Fecha fijada a la hora actual.';
    const curStatus = document.getElementById('status-select')?.value;
    if (curStatus === 'scheduled') {
      updateStatusUI('published');
    }
  });
}

if (postCreatedAtInput) {
  postCreatedAtInput.addEventListener('change', function() {
    const chosenTime = new Date(this.value).getTime();
    const nowTime = Date.now();
    const curStatus = document.getElementById('status-select')?.value;
    if (chosenTime > nowTime) {
      if (curStatus !== 'draft') {
        updateStatusUI('scheduled');
      }
      if (dateHint) dateHint.innerHTML = '<span style="color:#3b82f6">📅 Fecha futura: el artículo se programará para publicarse automáticamente en este momento.</span>';
    } else {
      if (curStatus === 'scheduled') {
        updateStatusUI('published');
      }
      if (dateHint) dateHint.textContent = 'Modifica la fecha para retroceder la publicación o fijar una fecha futura para programarla.';
    }
  });
}

// ── Excerpt character counter ──────────────────────────────────────────────
const excerptArea = document.getElementById('post-excerpt');
const excerptCount = document.getElementById('excerpt-counter');
if (excerptArea && excerptCount) {
  excerptArea.addEventListener('input', () => {
    excerptCount.textContent = `${excerptArea.value.length}/200`;
  });
}

// ── Featured image preview & drop (Picture slot) ───────────────────────────
const featEmpty = document.getElementById('featured-empty-slot');
const featDropBtn = document.getElementById('feat-drop-btn');

function updateFeaturedPreviewState(url) {
  const preview = document.getElementById('featured-preview');
  const img = document.getElementById('featured-img');
  url = (url || '').trim();
  if (url) {
    if (url.startsWith('/media/covers/') && window.KUTPOD_INSTANCE_URL) {
      img.src = window.KUTPOD_INSTANCE_URL.replace(/\/+$/, '') + url;
    } else {
      img.src = url;
    }
    if (preview) preview.style.display = '';
    if (featEmpty) featEmpty.style.display = 'none';
    if (featDropBtn) featDropBtn.style.display = '';
  } else {
    if (preview) preview.style.display = 'none';
    if (featEmpty) featEmpty.style.display = '';
    if (featDropBtn) featDropBtn.style.display = 'none';
  }
}

document.getElementById('featured_image_input')?.addEventListener('input', function() {
  updateFeaturedPreviewState(this.value);
});

featDropBtn?.addEventListener('click', function() {
  const input = document.getElementById('featured_image_input');
  if (input) input.value = '';
  updateFeaturedPreviewState('');
});

// Quick tag suggestions click
document.querySelectorAll('.tag-quick-sug').forEach(btn => {
  btn.addEventListener('click', function() {
    const term = this.textContent.replace(/^\+\s*/, '').trim();
    const tagInput = document.getElementById('tag-input');
    if (tagInput && term) {
      tagInput.value = term;
      const enterEvt = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true });
      tagInput.dispatchEvent(enterEvt);
    }
  });
});

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// ── Tag/Category chip input ───────────────────────────────────────────────
function initTagInput(inputId, chipsId, hiddenId, suggestionsId) {
  const input    = document.getElementById(inputId);
  const chips    = document.getElementById(chipsId);
  const hidden   = document.getElementById(hiddenId);
  const sugArea  = document.getElementById(suggestionsId);
  if (!input || !chips || !hidden) return;

  const initialValues = hidden.value.split(',').map(v => v.trim()).filter(Boolean);
  let values = [];

  function updateHidden() {
    hidden.value = values.join(', ');
  }

  function addChip(val) {
    val = val.trim();
    if (!val || values.includes(val)) return;
    values.push(val);
    updateHidden();

    const chip = document.createElement('span');
    chip.className = 'tag-chip';
    chip.innerHTML = `${escHtml(val)}<button type="button" title="Eliminar">×</button>`;
    chip.querySelector('button').addEventListener('click', () => {
      values = values.filter(v => v !== val);
      chip.remove();
      updateHidden();
      if (sugArea) {
        sugArea.querySelectorAll('.tag-sug').forEach(s => {
          if (s.textContent.trim() === val) s.classList.remove('used');
        });
      }
    });
    chips.appendChild(chip);

    if (sugArea) {
      sugArea.querySelectorAll('.tag-sug').forEach(s => {
        if (s.textContent.trim() === val) s.classList.add('used');
      });
    }
  }

  // Populate initial values
  initialValues.forEach(v => addChip(v));

  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addChip(input.value);
      input.value = '';
    } else if (e.key === 'Backspace' && !input.value && values.length) {
      const last = chips.lastElementChild;
      if (last) {
        const removed = values.pop();
        last.remove();
        updateHidden();
        if (sugArea && removed) {
          sugArea.querySelectorAll('.tag-sug').forEach(s => {
            if (s.textContent.trim() === removed) s.classList.remove('used');
          });
        }
      }
    }
  });

  input.addEventListener('blur', () => {
    if (input.value.trim()) { addChip(input.value); input.value = ''; }
  });

  document.getElementById(chipsId.replace('-chips','-wrap'))
    ?.addEventListener('click', () => input.focus());

  if (sugArea) {
    sugArea.querySelectorAll('.tag-sug').forEach(sug => {
      if (values.includes(sug.textContent.trim())) sug.classList.add('used');
      sug.addEventListener('click', () => {
        addChip(sug.textContent.trim());
        input.focus();
      });
    });
  }
}

initTagInput('cat-input', 'cat-chips', 'cat-hidden', 'cat-suggestions');

// ── Tags autocomplete ─────────────────────────────────────────────────────
(function() {
  const input      = document.getElementById('tag-input');
  const chips      = document.getElementById('tag-chips');
  const hidden     = document.getElementById('tag-hidden');
  const dropdown   = document.getElementById('tag-dropdown');
  const dropList   = document.getElementById('tag-dropdown-list');
  const dataEl     = document.getElementById('all-tags-data');
  if (!input || !chips || !hidden) return;

  const allTags = dataEl
    ? Array.from(dataEl.querySelectorAll('span')).map(s => s.textContent.trim()).filter(Boolean)
    : [];

  const initialValues = hidden.value.split(',').map(v => v.trim()).filter(Boolean);
  let values = [];
  let focusedIdx = -1;

  function updateHidden() { hidden.value = values.join(', '); }

  function addChip(val) {
    val = val.trim();
    if (!val || values.includes(val)) return;
    values.push(val);
    updateHidden();

    const chip = document.createElement('span');
    chip.className = 'tag-chip';
    chip.innerHTML = escHtml(val) + '<button type="button" title="Eliminar">×</button>';
    chip.querySelector('button').addEventListener('click', () => {
      values = values.filter(v => v !== val);
      chip.remove();
      updateHidden();
    });
    chips.appendChild(chip);
  }

  // Populate initial tags from saved draft / article
  initialValues.forEach(v => addChip(v));

  function showDropdown(query) {
    const q = query.toLowerCase().trim();
    if (!q) { hideDropdown(); return; }

    const matches = allTags
      .filter(t => t.toLowerCase().includes(q) && !values.includes(t))
      .slice(0, 12);

    dropList.innerHTML = '';
    focusedIdx = -1;

    if (!matches.length) {
      dropList.innerHTML = '<div class="tag-dropdown-empty">Presiona Enter para añadir "' + escHtml(query) + '"</div>';
    } else {
      matches.forEach((tag, i) => {
        const item = document.createElement('div');
        item.className = 'tag-dropdown-item';
        item.innerHTML = escHtml(tag);
        item.addEventListener('mousedown', e => {
          e.preventDefault();
          addChip(tag);
          input.value = '';
          hideDropdown();
          input.focus();
        });
        dropList.appendChild(item);
      });
    }
    dropdown.style.display = 'block';
  }

  function hideDropdown() {
    dropdown.style.display = 'none';
    focusedIdx = -1;
  }

  function moveFocus(dir) {
    const items = dropList.querySelectorAll('.tag-dropdown-item');
    if (!items.length) return;
    items[focusedIdx]?.classList.remove('focused');
    focusedIdx = Math.max(0, Math.min(focusedIdx + dir, items.length - 1));
    items[focusedIdx]?.classList.add('focused');
    items[focusedIdx]?.scrollIntoView({ block: 'nearest' });
  }

  input.addEventListener('input', () => showDropdown(input.value));
  input.addEventListener('focus', () => { if (input.value) showDropdown(input.value); });
  input.addEventListener('blur', () => {
    if (input.value.trim()) {
      addChip(input.value);
      input.value = '';
    }
    setTimeout(hideDropdown, 150);
  });

  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); moveFocus(-1); return; }
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      const focused = dropList.querySelector('.tag-dropdown-item.focused');
      if (focused) {
        addChip(focused.textContent);
      } else {
        addChip(input.value);
      }
      input.value = '';
      hideDropdown();
      return;
    }
    if (e.key === 'Backspace' && !input.value && values.length) {
      values.pop();
      chips.lastElementChild?.remove();
      updateHidden();
    }
    if (e.key === 'Escape') hideDropdown();
  });

  document.getElementById('tag-wrap')?.addEventListener('click', () => input.focus());
})();

// ── Featured image upload ─────────────────────────────────────────────────
const featUpload   = document.getElementById('feat-upload');
const featInput    = document.getElementById('featured_image_input');
const featProgress = document.getElementById('feat-upload-progress');

if (featUpload) {
  featUpload.addEventListener('change', async () => {
    const file = featUpload.files[0];
    if (!file) return;
    featProgress.textContent = 'Subiendo…';
    featProgress.style.display = '';

    const fd = new FormData();
    fd.append('upload', file);
    fd.append('csrf', <?= json_encode(generate_csrf()) ?>);

    try {
      const res  = await fetch(<?= json_encode(base_url() . '/admin/upload_media.php') ?>, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.url) {
        featInput.value = data.url;
        updateFeaturedPreviewState(data.url);
        featProgress.textContent = '✓ Subida correctamente';
        setTimeout(() => featProgress.style.display = 'none', 2000);
      } else {
        featProgress.textContent = '⚠ Error: ' + (data.error || 'desconocido');
      }
    } catch(e) {
      featProgress.textContent = '⚠ Error al subir';
    }
  });
}

// ── Editor font size ──────────────────────────────────────────────────────
const FONT_SIZE_KEY = 'kutblog_editor_font_size';
const FONT_MIN = 12, FONT_MAX = 28, FONT_STEP = 1;
let editorFontSize = parseInt(localStorage.getItem(FONT_SIZE_KEY) || localStorage.getItem('brisa_editor_font_size') || '16', 10);

function applyEditorFontSize(size) {
  editorFontSize = Math.min(FONT_MAX, Math.max(FONT_MIN, size));
  
  // Apply to both editors
  const visualEditor = document.getElementById('editor');
  const rawEditor = document.getElementById('raw-editor');
  
  if (visualEditor) visualEditor.style.fontSize = editorFontSize + 'px';
  if (rawEditor) rawEditor.style.fontSize = editorFontSize + 'px';
  
  // Update the label
  const label = document.getElementById('font-size-label');
  if (label) label.textContent = editorFontSize;
  
  localStorage.setItem(FONT_SIZE_KEY, editorFontSize);
}

// Update event listeners to handle both sets of buttons
document.getElementById('font-size-up')?.addEventListener('click', () => applyEditorFontSize(editorFontSize + FONT_STEP));
document.getElementById('font-size-down')?.addEventListener('click', () => applyEditorFontSize(editorFontSize - FONT_STEP));

// Initialize font size on page load
applyEditorFontSize(editorFontSize);

// ── Article Preview ───────────────────────────────────────────────────────
const previewBtn   = document.getElementById('preview-btn');
const postSlug     = <?= json_encode($post['slug'] ?? '') ?>;
const postType     = <?= json_encode($type) ?>;
const siteBase     = <?= json_encode(base_url()) ?>;

previewBtn?.addEventListener('click', openPreview);

function openPreview() {
  if (typeof window.hideImageBubble === 'function') {
    window.hideImageBubble();
  } else {
    const ibm = document.getElementById('image-bubble-menu');
    if (ibm) {
      ibm.classList.remove('is-active');
      ibm.style.setProperty('display', 'none', 'important');
    }
  }
  const adminBase = siteBase + '/admin';
  
  // Get current content
  let content = getCurrentContent();
  const curMode = window.currentEditorMode || mode;
  if (curMode === 'markdown') {
    content = window.markdownToHtmlSync ? window.markdownToHtmlSync(content) : content;
  }
  
  const title   = document.getElementById('post-title').value || '(Sin título)';
  const excerpt = document.querySelector('[name="excerpt"]')?.value || '';
  const status  = document.getElementById('status-select')?.value || 'draft';
  
  // If we have a saved post, use the proper preview URL
  if (postSlug) {
    const url = adminBase + '/preview.php?type=' + encodeURIComponent(postType)
              + '&slug=' + encodeURIComponent(postSlug)
              + '&t=' + Date.now();
    window.open(url, '_blank');
    return;
  }
  
  // For unsaved posts, create a temporary preview
  const accent  = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e05c1a';
  
  const html = `<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vista previa — ${title.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:#f1f1f1;color:#1a1a1a;font-family:'Roboto',sans-serif;font-size:16px;line-height:1.5;padding:2rem 1rem}
.banner{position:sticky;top:0;background:#f59e0b;color:#000;font-weight:700;font-size:0.78rem;text-align:center;padding:0.4rem 1rem;margin:-2rem -1rem 2rem;letter-spacing:0.04em}
.wrap{max-width:780px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:2rem}
h1{font-family:'Roboto Condensed',sans-serif;font-size:clamp(1.5rem,4vw,2.2rem);font-weight:700;margin-bottom:1rem;line-height:1.2}
.excerpt{color:#555;font-size:1.05rem;margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid #e0e0e0;font-style:italic}
.prose{font-size:1rem;line-height:1.8;color:#444}
.prose h2{font-family:'Roboto Condensed',sans-serif;font-size:1.4rem;font-weight:700;color:#1a1a1a;margin:2rem 0 0.75rem;border-bottom:2px solid ${accent};padding-bottom:0.3rem;text-transform:uppercase}
.prose h3{font-family:'Roboto Condensed',sans-serif;font-size:1.15rem;font-weight:700;color:#1a1a1a;margin:1.5rem 0 0.5rem}
.prose p{margin-bottom:1.25rem}
.prose a{color:${accent}}
.prose strong{color:#1a1a1a;font-weight:700}
.prose blockquote{border-left:3px solid ${accent};background:#f9f9f9;padding:0.75rem 1.25rem;margin:1.5rem 0;color:#555;font-style:italic}
.prose pre{background:#1e1e2e;color:#cdd6f4;border-radius:4px;padding:1rem;overflow-x:auto;font-size:0.875rem;margin:1.5rem 0}
.prose code{background:#f0f0f0;border:1px solid #e0e0e0;padding:.1rem .4rem;border-radius:3px;font-size:.875em;color:#c7254e}
.prose pre code{background:none;border:none;padding:0;color:inherit}
.prose ul,.prose ol{padding-left:1.75rem;margin-bottom:1.25rem}
.prose li{margin-bottom:.35rem}
.prose img{max-width:100%;border-radius:4px;margin:1.5rem 0;display:block}
.prose hr{border:none;border-top:1px solid #e0e0e0;margin:2rem 0}
.yt-embed{position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:1.5rem 0}
.yt-embed iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:none}
.media-audio{margin:1.5rem 0;background:#f5f5f5;border:1px solid #e0e0e0;border-left:3px solid ${accent};border-radius:8px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem}
.media-audio::before{content:"🎵";font-size:1.5rem;flex-shrink:0}
.media-audio audio{width:100%;height:36px}
.media-video{margin:1.5rem 0;border-radius:8px;overflow:hidden;background:#000;line-height:0}
.media-video video{width:100%;display:block}
</style></head><body>
<div class="banner">👁 VISTA PREVIA — ${status === 'draft' ? 'BORRADOR' : 'PUBLICADO'} — guarda el artículo para ver la vista previa con el tema activo del sitio</div>
<div class="wrap">
  <h1>${title.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</h1>
  ${excerpt ? '<p class="excerpt">' + excerpt.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</p>' : ''}
  <div class="prose">${content}</div>
</div>
</body></html>`;

  const blob = new Blob([html], { type: 'text/html' });
  const url  = URL.createObjectURL(blob);
  const tab  = window.open(url, '_blank');
  if (tab) {
    tab.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
  } else {
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }
}

// ── More toolbar dropdown ────────────────────────────────────────────────
function setupMoreDropdown() {
  const moreBtn = document.getElementById('more-tb-btn');
  const dropdown = document.getElementById('more-tb-dropdown');
  const content = document.getElementById('more-tb-content');
  if (!moreBtn || !dropdown) return;
  dropdown.style.display = 'none';

  function populateDropdown() {
    content.innerHTML = '';
    const sourceToolbar = mode === 'html' ? document.getElementById('html-toolbar') : document.getElementById('md-toolbar');
    if (!sourceToolbar) return;
    // Clone buttons except mode switchers and more button
    const btns = sourceToolbar.querySelectorAll('.tb:not(.mode-switch):not(#more-tb-btn)');
    btns.forEach(btn => {
      const clone = btn.cloneNode(true);
      // ensure click works
      clone.addEventListener('click', (e) => {
        e.preventDefault();
        btn.click();
        dropdown.style.display = 'none';
      });
      content.appendChild(clone);
    });
  }

  window.toggleMoreDropdown = function() {
    populateDropdown();
    const isVisible = dropdown.style.display === 'flex';
    dropdown.style.display = isVisible ? 'none' : 'flex';
  };

  moreBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    window.toggleMoreDropdown();
  });

  document.addEventListener('click', (e) => {
    if (!moreBtn.contains(e.target) && !dropdown.contains(e.target)) {
      dropdown.style.display = 'none';
    }
  });
}

// ── Image upload (button & drag‑and‑drop) ────────────────────────────────
function setupImageUpload() {
  const fileInput = document.getElementById('editor-image-upload');
  const uploadHtmlBtn = document.getElementById('upload-img-html');
  const uploadMdBtn = document.getElementById('upload-img-md');

  function triggerFileInput() {
    if (fileInput) fileInput.click();
  }

  if (uploadHtmlBtn) uploadHtmlBtn.addEventListener('click', triggerFileInput);
  if (uploadMdBtn) uploadMdBtn.addEventListener('click', triggerFileInput);

  async function uploadImage(file) {
    const fd = new FormData();
    fd.append('upload', file);
    fd.append('csrf', <?= json_encode(generate_csrf()) ?>);
    try {
      const res = await fetch(<?= json_encode(base_url() . '/admin/upload_media.php') ?>, {
        method: 'POST',
        body: fd
      });
      const data = await res.json();
      return data.url || null;
    } catch (e) {
      console.error('Upload error:', e);
      return null;
    }
  }

  async function insertImageUrl(url, alt = '') {
    const editor = document.getElementById('editor');
    editor.focus();
    document.execCommand('insertHTML', false, `<img src="${url}" alt="${alt}" style="max-width:100%;">`);
  }

  fileInput.addEventListener('change', async (e) => {
    const files = Array.from(e.target.files);
    for (const file of files) {
      const url = await uploadImage(file);
      if (url) {
        await insertImageUrl(url, file.name.replace(/\.[^/.]+$/, ''));
      }
    }
    fileInput.value = '';
  });

  // Drag & drop
  const dropZones = [document.getElementById('editor')];
  dropZones.forEach(zone => {
    if (!zone) return;
    zone.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
    });
    zone.addEventListener('drop', async (e) => {
      e.preventDefault();
      const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
      for (const file of files) {
        const url = await uploadImage(file);
        if (url) {
          await insertImageUrl(url, file.name.replace(/\.[^/.]+$/, ''));
        }
      }
    });
  });
}

// ── Mobile Dropdown Menu ──────────────────────────────────────────────────
function setupMobileDropdown() {
  const htmlDropdownBtn = document.getElementById('mobile-dropdown-btn');
  const htmlDropdownMenu = document.getElementById('mobile-dropdown-menu');
  const htmlDropdownContent = document.getElementById('mobile-dropdown-content');
  
  const mdDropdownBtn = document.getElementById('mobile-dropdown-btn-md');
  const mdDropdownMenu = document.getElementById('mobile-dropdown-menu-md');
  const mdDropdownContent = document.getElementById('mobile-dropdown-content-md');
  
  function setupDropdown(dropdownBtn, dropdownMenu, dropdownContent, isMarkdown = false) {
    if (!dropdownBtn || !dropdownMenu) return;
    
    dropdownMenu.style.display = 'none';
    
    function populateDropdown() {
      dropdownContent.innerHTML = '';
      const sourceSelector = isMarkdown ? '.mobile-hidden-tools' : '.mobile-hidden-tools';
      const sourceToolbar = dropdownBtn.closest(isMarkdown ? '#md-toolbar' : '#html-toolbar');
      if (!sourceToolbar) return;
      
      const hiddenTools = sourceToolbar.querySelector(sourceSelector);
      if (!hiddenTools) return;
      
      // Clone all buttons from the hidden tools section
      const btns = hiddenTools.querySelectorAll('.tb');
      btns.forEach(btn => {
        const clone = btn.cloneNode(true);
        // Remove any existing event listeners and add new ones
        const newClone = clone.cloneNode(true);
        newClone.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          btn.click();
          dropdownMenu.style.display = 'none';
        });
        dropdownContent.appendChild(newClone);
      });
      
      // Also clone separators if any
      const separators = hiddenTools.querySelectorAll('.toolbar-sep');
      separators.forEach(sep => {
        const clone = sep.cloneNode(true);
        clone.style.margin = '4px 2px';
        dropdownContent.appendChild(clone);
      });
    }
    
    dropdownBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      // Check if we're on mobile (≤480px)
      if (window.innerWidth > 480) {
        return; // Don't show dropdown on desktop/tablet
      }
      
      populateDropdown();
      
      // Position the dropdown - fixed positioning for Safari compatibility
      const rect = dropdownBtn.getBoundingClientRect();
      dropdownMenu.style.position = 'fixed';
      dropdownMenu.style.top = (rect.bottom + window.scrollY) + 'px';
      dropdownMenu.style.left = Math.max(10, rect.left) + 'px';
      dropdownMenu.style.right = 'auto';
      dropdownMenu.style.maxWidth = (window.innerWidth - 20) + 'px';
      
      const isVisible = dropdownMenu.style.display === 'flex';
      dropdownMenu.style.display = isVisible ? 'none' : 'flex';
      
      // Force reflow for Safari
      dropdownMenu.offsetHeight;
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
      if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
        dropdownMenu.style.display = 'none';
      }
    });
    
    // Close on escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && dropdownMenu.style.display === 'flex') {
        dropdownMenu.style.display = 'none';
      }
    });
    
    // Close dropdown on window resize
    window.addEventListener('resize', () => {
      if (window.innerWidth > 480) {
        dropdownMenu.style.display = 'none';
      }
    });
  }
  
  // Setup HTML toolbar dropdown
  setupDropdown(htmlDropdownBtn, htmlDropdownMenu, htmlDropdownContent, false);
  
  // Setup Markdown toolbar dropdown
  setupDropdown(mdDropdownBtn, mdDropdownMenu, mdDropdownContent, true);
}

// ── Mobile toolbar helpers ────────────────────────────────────────────────
window.execMobile = function(cmd, val) {
  if (mode !== 'visual') return;
  exec(cmd, val);
};
window.execMobileLink = function() {
  if (mode !== 'html') return;
  insertLink();
};
window.execMobileImg = function() {
  if (mode !== 'html') return;
  insertImage();
};
window.execMobileAudio = function() {
  if (mode !== 'html') return;
  insertAudio();
};
window.execMobileVideo = function() {
  if (mode !== 'html') return;
  insertVideo();
};

// Mobile menu toggle for toolbar overflow
function setupMobileMenu() {
  const moreBtn = document.getElementById('tb-more-btn');
  const overflowMenu = document.getElementById('tb-overflow-menu');
  
  if (!moreBtn || !overflowMenu) return;
  
  // Initially hide the menu
  overflowMenu.style.display = 'none';
  
  moreBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    e.preventDefault();
    
    if (overflowMenu.style.display === 'flex') {
      overflowMenu.style.display = 'none';
    } else {
      overflowMenu.style.display = 'flex';
      // Position the menu
      const rect = moreBtn.getBoundingClientRect();
      overflowMenu.style.position = 'fixed';
      overflowMenu.style.top = rect.bottom + 'px';
      overflowMenu.style.right = (window.innerWidth - rect.right) + 'px';
      
      // Close on click outside
      setTimeout(() => {
        const clickOutside = (event) => {
          if (!moreBtn.contains(event.target) && !overflowMenu.contains(event.target)) {
            overflowMenu.style.display = 'none';
            document.removeEventListener('click', clickOutside);
          }
        };
        document.addEventListener('click', clickOutside);
      }, 10);
    }
  });
  
  // Close on escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && overflowMenu.style.display === 'flex') {
      overflowMenu.style.display = 'none';
    }
  });
}

// Initialize mobile menu
document.addEventListener('DOMContentLoaded', function() {
  setupMobileDropdown();
  setupMobileMenu();
  setupMoreDropdown();
  setupImageUpload();
  
  // Hide mobile dropdown on resize to desktop
  window.addEventListener('resize', function() {
    if (window.innerWidth > 480) {
      const dropdowns = document.querySelectorAll('.mobile-dropdown-menu');
      dropdowns.forEach(dropdown => {
        dropdown.style.display = 'none';
      });
    }
  });
});

// ── Focus mode ────────────────────────────────────────────────────────────
window.toggleFocus = function() {
  document.body.classList.toggle('focus-mode');
  const active = document.body.classList.contains('focus-mode');
  const focusBtn = document.getElementById('focus-btn');
  if (focusBtn) {
    focusBtn.setAttribute('title', active ? 'Salir del modo sin distracciones (Esc)' : 'Modo sin distracciones (F11)');
  }
};

const focusBtn = document.getElementById('focus-btn');
if (focusBtn) {
  focusBtn.addEventListener('click', toggleFocus);
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.body.classList.contains('focus-mode')) toggleFocus();
  if (e.key === 'F11' && !e.altKey) { e.preventDefault(); toggleFocus(); }
});

// Añadir al final del script existente, después de la función adjustMobileLayout()
function fixSidebarLayout() {
  if (window.innerWidth <= 1024) {
    const sidebar = document.querySelector('.editor-sidebar');
    const panels = sidebar?.querySelectorAll('.panel');
    
    if (panels) {
      panels.forEach(panel => {
        // Asegurar que cada panel tenga suficiente espacio
        panel.style.maxHeight = 'none';
        panel.style.overflow = 'visible';
        
        // Arreglar cualquier input dentro del panel
        const inputs = panel.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
          input.style.maxWidth = '100%';
          input.style.boxSizing = 'border-box';
        });
      });
    }
  }
}

// Ejecutar cuando se cargue y cuando cambie el tamaño
window.addEventListener('load', fixSidebarLayout);
window.addEventListener('resize', fixSidebarLayout);

// También ejecutar cuando se modifiquen los tags o categorías
const observer = new MutationObserver(function(mutations) {
  mutations.forEach(function(mutation) {
    if (mutation.type === 'childList' || mutation.type === 'characterData') {
      setTimeout(fixSidebarLayout, 100);
    }
  });
});

// Observar cambios en el sidebar
const sidebar = document.querySelector('.editor-sidebar');
if (sidebar) {
  observer.observe(sidebar, { 
    childList: true, 
    subtree: true,
    characterData: true 
  });
}

// fixIOSLayout removed – default layout works on iOS now.

// ── Backup Preview ────────────────────────────────────────────────────────
function previewBackup(timestamp, type, slug) {
    if (!confirm('Preview this backup version? You can copy content from the preview.')) {
        return;
    }
    
    fetch(`<?= base_url() ?>/admin/backup_preview.php?type=${encodeURIComponent(type)}&slug=${encodeURIComponent(slug)}&timestamp=${encodeURIComponent(timestamp)}&csrf=<?= $csrf ?>`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.content) {
                // Open preview in a new window
                const previewWindow = window.open('', '_blank');
                const html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Backup Preview - ${timestamp}</title>
                        <style>
                            body { font-family: sans-serif; padding: 2rem; max-width: 800px; margin: 0 auto; }
                            .header { background: #f0f0f0; padding: 1rem; border-radius: 4px; margin-bottom: 2rem; }
                            .content { line-height: 1.6; }
                            .actions { margin-top: 2rem; padding: 1rem; background: #f9f9f9; border-radius: 4px; }
                            button { padding: 0.5rem 1rem; margin-right: 0.5rem; cursor: pointer; }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h2>Backup Preview</h2>
                            <p><strong>Timestamp:</strong> ${timestamp}</p>
                            <p><strong>Article:</strong> ${slug}</p>
                        </div>
                        <div class="content">${data.content}</div>
                        <div class="actions">
                            <button onclick="window.close()">Close</button>
                            <button onclick="copyToClipboard('${data.content.replace(/'/g, "\\'")}')">Copy Content</button>
                        </div>
                        <script>
                            function copyToClipboard(text) {
                                navigator.clipboard.writeText(text).then(() => {
                                    alert('Content copied to clipboard!');
                                });
                            }
                        <\/script>
                    </body>
                    </html>
                `;
                previewWindow.document.write(html);
                previewWindow.document.close();
            } else {
                alert('Error loading backup: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading backup');
        });
}

function deleteBackup(timestamp, type, slug) {
    if (!confirm('¿Eliminar esta copia de seguridad?')) return;
    fetch(`<?= base_url() ?>/admin/backup_preview.php?action=delete&type=${encodeURIComponent(type)}&slug=${encodeURIComponent(slug)}&timestamp=${encodeURIComponent(timestamp)}&csrf=<?= $csrf ?>`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const el = document.getElementById('backup-item-' + timestamp);
                if (el) el.remove();
                const list = document.getElementById('backup-list-container');
                if (list && list.children.length === 0) {
                    const panel = document.getElementById('panel-version-history');
                    if (panel) panel.remove();
                }
            } else {
                alert('Error: ' + (data.error || 'No se pudo eliminar el backup'));
            }
        })
        .catch(() => alert('Error al eliminar la copia de seguridad.'));
}

function clearAllBackups(type, slug) {
    if (!confirm('¿Eliminar todo el historial de copias de seguridad de este artículo?')) return;
    fetch(`<?= base_url() ?>/admin/backup_preview.php?action=clear_all&type=${encodeURIComponent(type)}&slug=${encodeURIComponent(slug)}&csrf=<?= $csrf ?>`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const panel = document.getElementById('panel-version-history');
                if (panel) panel.remove();
            } else {
                alert('Error: ' + (data.error || 'No se pudo limpiar el historial'));
            }
        })
        .catch(() => alert('Error al limpiar las copias de seguridad.'));
}

// ═══════════════════════════════════════════════════════════════════════════
// MODAL INSERTAR IMAGEN (URL / Cargar Ordenador / Biblioteca)
// ═══════════════════════════════════════════════════════════════════════════
window._imgModalCallback = null;
window._imgSelectedFile = null;
window._imgSelectedLibUrl = null;
window._imgLibFiles = [];
window._imgLibLoaded = false;

function openImageInsertModal(callback) {
  window._imgModalCallback = callback || null;
  resetUploadTab();
  const urlInput = document.getElementById('img-url-input');
  const urlAlt = document.getElementById('img-url-alt');
  const libAlt = document.getElementById('img-lib-alt');
  if (urlInput) urlInput.value = '';
  if (urlAlt) urlAlt.value = '';
  if (libAlt) libAlt.value = '';
  const urlPreview = document.getElementById('img-url-preview-wrap');
  if (urlPreview) urlPreview.style.display = 'none';

  switchImageModalTab('upload');
  const modal = document.getElementById('image-insert-modal');
  if (modal) modal.style.display = 'flex';
  loadMediaLibrary(false);
}

function closeImageInsertModal() {
  const modal = document.getElementById('image-insert-modal');
  if (modal) modal.style.display = 'none';
  window._imgModalCallback = null;
}

function switchImageModalTab(tab) {
  const tabs = ['upload', 'library', 'url'];
  tabs.forEach(t => {
    const btn = document.getElementById('tab-btn-' + t);
    const panel = document.getElementById('img-panel-' + t);
    if (btn) btn.classList.toggle('active', t === tab);
    if (panel) panel.style.display = (t === tab) ? 'flex' : 'none';
  });
  if (tab === 'library') {
    loadMediaLibrary(false);
  }
}

function handleImageFileSelected(file) {
  if (!file || !file.type.startsWith('image/')) {
    alert('Por favor, selecciona un archivo de imagen válido.');
    return;
  }
  window._imgSelectedFile = file;
  const preview = document.getElementById('img-upload-preview');
  const filename = document.getElementById('img-upload-filename');
  const filesize = document.getElementById('img-upload-filesize');
  const selectedWrap = document.getElementById('img-upload-selected');
  const dropzone = document.getElementById('img-dropzone');
  const submitBtn = document.getElementById('img-upload-submit-btn');
  const altInput = document.getElementById('img-upload-alt');

  if (preview) preview.src = URL.createObjectURL(file);
  if (filename) filename.textContent = file.name;
  if (filesize) filesize.textContent = (file.size / 1024).toFixed(1) + ' KB';
  if (selectedWrap) selectedWrap.style.display = 'flex';
  if (dropzone) dropzone.style.display = 'none';

  if (altInput && !altInput.value) {
    altInput.value = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
  }

  if (submitBtn) submitBtn.disabled = false;
  const statusEl = document.getElementById('img-upload-status');
  if (statusEl) statusEl.style.display = 'none';
}

function resetUploadTab() {
  window._imgSelectedFile = null;
  const fileInput = document.getElementById('img-file-input');
  const preview = document.getElementById('img-upload-preview');
  const selectedWrap = document.getElementById('img-upload-selected');
  const dropzone = document.getElementById('img-dropzone');
  const submitBtn = document.getElementById('img-upload-submit-btn');
  const statusEl = document.getElementById('img-upload-status');
  const altInput = document.getElementById('img-upload-alt');

  if (fileInput) fileInput.value = '';
  if (preview) preview.src = '';
  if (selectedWrap) selectedWrap.style.display = 'none';
  if (dropzone) dropzone.style.display = 'block';
  if (submitBtn) submitBtn.disabled = true;
  if (statusEl) statusEl.style.display = 'none';
  if (altInput) altInput.value = '';
}

async function submitUploadTab() {
  if (!window._imgSelectedFile) return;
  const statusEl = document.getElementById('img-upload-status');
  const submitBtn = document.getElementById('img-upload-submit-btn');
  const altInput = document.getElementById('img-upload-alt');

  if (statusEl) {
    statusEl.className = 'img-status-msg info';
    statusEl.textContent = 'Subiendo imagen al servidor...';
    statusEl.style.display = 'block';
  }
  if (submitBtn) submitBtn.disabled = true;

  const fd = new FormData();
  fd.append('upload', window._imgSelectedFile);
  fd.append('csrf', <?= json_encode($csrf) ?>);

  try {
    const res = await fetch(<?= json_encode(base_url() . '/admin/upload_media.php') ?>, {
      method: 'POST',
      body: fd
    });
    const data = await res.json();
    if (data.error) {
      if (statusEl) {
        statusEl.className = 'img-status-msg error';
        statusEl.textContent = data.error;
      }
      if (submitBtn) submitBtn.disabled = false;
      return;
    }
    if (data.url) {
      if (statusEl) {
        statusEl.className = 'img-status-msg info';
        statusEl.textContent = '✓ Imagen subida con éxito';
      }
      const altText = (altInput && altInput.value.trim()) || window._imgSelectedFile.name.replace(/\.[^/.]+$/, '');
      finishImageInsert(data.url, altText);
      window._imgLibLoaded = false;
    }
  } catch (err) {
    if (statusEl) {
      statusEl.className = 'img-status-msg error';
      statusEl.textContent = 'Error al subir la imagen: ' + err.message;
    }
    if (submitBtn) submitBtn.disabled = false;
  }
}

async function loadMediaLibrary(force = false) {
  const grid = document.getElementById('img-lib-grid');
  if (!grid) return;
  if (window._imgLibLoaded && !force) {
    renderMediaLibraryGrid(window._imgLibFiles);
    return;
  }
  grid.innerHTML = '<div class="img-lib-empty">Cargando biblioteca de medios...</div>';
  try {
    const res = await fetch(<?= json_encode(base_url() . '/admin/media.php?json=1&type=image') ?>);
    const data = await res.json();
    if (data && data.ok && Array.isArray(data.files)) {
      window._imgLibFiles = data.files;
      window._imgLibLoaded = true;
      renderMediaLibraryGrid(window._imgLibFiles);
    } else {
      grid.innerHTML = '<div class="img-lib-empty">No se pudieron cargar las imágenes.</div>';
    }
  } catch (err) {
    grid.innerHTML = '<div class="img-lib-empty">Error al cargar la biblioteca: ' + err.message + '</div>';
  }
}

function renderMediaLibraryGrid(files) {
  const grid = document.getElementById('img-lib-grid');
  const submitBtn = document.getElementById('img-lib-submit-btn');
  if (!grid) return;
  grid.innerHTML = '';
  window._imgSelectedLibUrl = null;
  if (submitBtn) submitBtn.disabled = true;

  if (!files || files.length === 0) {
    grid.innerHTML = '<div class="img-lib-empty"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin-bottom:8px;opacity:0.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>No hay imágenes en la biblioteca.<br>Puedes subir una desde la pestaña "Cargar del ordenador".</div>';
    return;
  }

  files.forEach(file => {
    const item = document.createElement('div');
    item.className = 'img-lib-item';
    item.title = file.name;

    const img = document.createElement('img');
    img.src = file.url;
    img.alt = (file.meta && file.meta.alt) ? file.meta.alt : file.name;
    img.loading = 'lazy';

    const label = document.createElement('div');
    label.className = 'img-lib-item-name';
    label.textContent = file.name;

    item.appendChild(img);
    item.appendChild(label);

    item.addEventListener('click', () => {
      document.querySelectorAll('.img-lib-item.selected').forEach(el => el.classList.remove('selected'));
      item.classList.add('selected');
      window._imgSelectedLibUrl = file.url;
      const altInput = document.getElementById('img-lib-alt');
      const metaAlt = (file.meta && file.meta.alt) ? file.meta.alt : file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
      if (altInput) altInput.value = metaAlt;
      if (submitBtn) submitBtn.disabled = false;
    });

    item.addEventListener('dblclick', () => {
      window._imgSelectedLibUrl = file.url;
      const altInput = document.getElementById('img-lib-alt');
      const metaAlt = (file.meta && file.meta.alt) ? file.meta.alt : file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
      finishImageInsert(window._imgSelectedLibUrl, (altInput && altInput.value.trim()) || metaAlt);
    });

    grid.appendChild(item);
  });
}

function filterMediaLibrary(query) {
  const q = (query || '').toLowerCase().trim();
  if (!q) {
    renderMediaLibraryGrid(window._imgLibFiles);
    return;
  }
  const filtered = window._imgLibFiles.filter(f => (f.name || '').toLowerCase().includes(q) || ((f.meta && f.meta.alt) || '').toLowerCase().includes(q));
  renderMediaLibraryGrid(filtered);
}

function submitLibraryTab() {
  if (!window._imgSelectedLibUrl) return;
  const altInput = document.getElementById('img-lib-alt');
  const alt = altInput ? altInput.value.trim() : '';
  finishImageInsert(window._imgSelectedLibUrl, alt);
}

function previewUrlImage(url) {
  const wrap = document.getElementById('img-url-preview-wrap');
  const img = document.getElementById('img-url-preview-el');
  const clean = (url || '').trim();
  if (clean.startsWith('http://') || clean.startsWith('https://') || clean.startsWith('/')) {
    if (img) img.src = clean;
  } else {
    if (wrap) wrap.style.display = 'none';
  }
}

function submitUrlTab() {
  const urlInput = document.getElementById('img-url-input');
  const altInput = document.getElementById('img-url-alt');
  const url = (urlInput && urlInput.value.trim()) || '';
  if (!url) {
    if (urlInput) urlInput.focus();
    return;
  }
  const alt = (altInput && altInput.value.trim()) || '';
  finishImageInsert(url, alt);
}

function finishImageInsert(url, alt) {
  if (window._imgModalCallback && typeof window._imgModalCallback === 'function') {
    window._imgModalCallback(url, alt);
  } else {
    const curMode = window.currentEditorMode || mode;
    if (curMode === 'markdown') {
      const mdEd = document.getElementById('markdown-editor');
      if (mdEd) {
        const mdImg = `![${alt || ''}](${url})\n`;
        const start = mdEd.selectionStart || 0;
        const end = mdEd.selectionEnd || 0;
        mdEd.value = mdEd.value.substring(0, start) + mdImg + mdEd.value.substring(end);
        mdEd.selectionStart = mdEd.selectionEnd = start + mdImg.length;
        mdEd.focus();
        mdEd.dispatchEvent(new Event('input', { bubbles: true }));
      }
    } else if (curMode === 'raw') {
      const rawEd = document.getElementById('raw-editor');
      if (rawEd) {
        const htmlImg = `<img src="${url}" alt="${alt || ''}" class="prose-image align-center">\n`;
        const start = rawEd.selectionStart || 0;
        const end = rawEd.selectionEnd || 0;
        rawEd.value = rawEd.value.substring(0, start) + htmlImg + rawEd.value.substring(end);
        rawEd.selectionStart = rawEd.selectionEnd = start + htmlImg.length;
        rawEd.focus();
        rawEd.dispatchEvent(new Event('input', { bubbles: true }));
      }
    } else if (window.pmEditor) {
      window.pmEditor.chain().focus().setImage({ src: url, alt: alt || '' }).run();
    } else {
      const editor = document.getElementById('editor');
      if (editor) {
        editor.focus();
        document.execCommand('insertHTML', false, `<img src="${url}" alt="${alt || ''}" style="max-width:100%;">`);
      }
    }
  }
  closeImageInsertModal();
}

function openFeaturedImagePicker() {
  openImageInsertModal(function(url) {
    const featInput = document.getElementById('featured_image_input');
    if (featInput) {
      featInput.value = url;
      updateFeaturedPreviewState(url);
    }
  });
}

document.addEventListener('DOMContentLoaded', function() {
  const dropzone = document.getElementById('img-dropzone');
  const fileInput = document.getElementById('img-file-input');

  if (dropzone && fileInput) {
    dropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => {
      dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropzone.classList.remove('dragover');
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        handleImageFileSelected(e.dataTransfer.files[0]);
      }
    });
    fileInput.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        handleImageFileSelected(e.target.files[0]);
      }
    });
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      const modal = document.getElementById('image-insert-modal');
      if (modal && modal.style.display !== 'none') {
        closeImageInsertModal();
      }
    }
  });
});

// Keyboard resize listener removed – no longer needed.

// No longer needed – default touch behavior is now allowed.

</script>

<!-- Modal: Insertar Imagen (Cargar ordenador / Biblioteca / URL) -->
<div id="image-insert-modal" class="image-modal-backdrop" style="display:none;" onclick="if(event.target===this)closeImageInsertModal()">
  <div class="image-modal-card" role="dialog" aria-modal="true" aria-labelledby="image-modal-heading">
    <div class="image-modal-header">
      <div class="image-modal-title-wrap">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
          <circle cx="8.5" cy="8.5" r="1.5"></circle>
          <polyline points="21 15 16 10 5 21"></polyline>
        </svg>
        <h3 id="image-modal-heading">Insertar imagen</h3>
      </div>
      <button type="button" class="image-modal-close" onclick="closeImageInsertModal()" aria-label="Cerrar">✕</button>
    </div>

    <!-- Navigation Tabs -->
    <div class="image-modal-nav">
      <button type="button" id="tab-btn-upload" class="img-tab-btn active" onclick="switchImageModalTab('upload')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span>Cargar del ordenador</span>
      </button>
      <button type="button" id="tab-btn-library" class="img-tab-btn" onclick="switchImageModalTab('library')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
        <span>Biblioteca</span>
      </button>
      <button type="button" id="tab-btn-url" class="img-tab-btn" onclick="switchImageModalTab('url')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        <span>Enlace URL</span>
      </button>
    </div>

    <!-- TAB 1: Subir del ordenador -->
    <div class="img-tab-panel" id="img-panel-upload">
      <div id="img-dropzone" class="img-dropzone">
        <input type="file" id="img-file-input" accept="image/*" style="display:none">
        <div class="img-drop-icon">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </div>
        <div class="img-drop-title">Arrastra una imagen aquí o <span style="cursor:pointer;" onclick="document.getElementById('img-file-input').click()">haz clic para examinar</span></div>
        <div class="img-drop-hint">Formatos: JPG, PNG, WebP, GIF, SVG, AVIF (hasta 10 MB)</div>
      </div>

      <div id="img-upload-selected" class="img-selected-preview" style="display:none;">
        <div class="img-selected-thumb-wrap">
          <img id="img-upload-preview" src="" alt="Vista previa">
        </div>
        <div class="img-selected-info">
          <div id="img-upload-filename" class="img-selected-name"></div>
          <div id="img-upload-filesize" class="img-selected-meta"></div>
          <button type="button" class="btn btn-secondary btn-sm" onclick="resetUploadTab()" style="margin-top:5px;font-size:0.78rem;padding:2px 8px;">Cambiar imagen</button>
        </div>
      </div>

      <div class="img-form-group" style="margin-top: 1rem;">
        <label for="img-upload-alt" class="img-form-label">Texto alternativo (alt)</label>
        <input type="text" id="img-upload-alt" class="img-form-control" placeholder="Describe la imagen para accesibilidad y SEO">
      </div>

      <div id="img-upload-status" class="img-status-msg" style="display:none;"></div>

      <div class="img-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeImageInsertModal()">Cancelar</button>
        <button type="button" id="img-upload-submit-btn" class="btn btn-primary" onclick="submitUploadTab()" disabled>
          Subir e insertar
        </button>
      </div>
    </div>

    <!-- TAB 2: Biblioteca de medios -->
    <div class="img-tab-panel" id="img-panel-library" style="display:none;">
      <div class="img-lib-toolbar">
        <input type="text" id="img-lib-search" class="img-form-control" placeholder="Buscar por nombre de archivo..." oninput="filterMediaLibrary(this.value)">
        <button type="button" class="btn btn-secondary btn-sm" onclick="loadMediaLibrary(true)" title="Recargar biblioteca">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </button>
      </div>

      <div id="img-lib-grid" class="img-lib-grid">
        <!-- Rendered dynamically -->
      </div>

      <div class="img-form-group" style="margin-top: 0.85rem;">
        <label for="img-lib-alt" class="img-form-label">Texto alternativo (alt)</label>
        <input type="text" id="img-lib-alt" class="img-form-control" placeholder="Descripción de la imagen seleccionada">
      </div>

      <div class="img-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeImageInsertModal()">Cancelar</button>
        <button type="button" id="img-lib-submit-btn" class="btn btn-primary" onclick="submitLibraryTab()" disabled>
          Insertar imagen seleccionada
        </button>
      </div>
    </div>

    <!-- TAB 3: URL Directa -->
    <div class="img-tab-panel" id="img-panel-url" style="display:none;">
      <div class="img-form-group">
        <label for="img-url-input" class="img-form-label">URL de la imagen *</label>
        <input type="url" id="img-url-input" class="img-form-control" placeholder="https://ejemplo.com/imagen.jpg" oninput="previewUrlImage(this.value)">
      </div>

      <div class="img-form-group" style="margin-top: 0.85rem;">
        <label for="img-url-alt" class="img-form-label">Texto alternativo (alt)</label>
        <input type="text" id="img-url-alt" class="img-form-control" placeholder="Descripción de la imagen">
      </div>

      <div id="img-url-preview-wrap" class="img-url-preview-box" style="display:none;">
        <img id="img-url-preview-el" src="" alt="Vista previa" onload="document.getElementById('img-url-preview-wrap').style.display='flex'" onerror="document.getElementById('img-url-preview-wrap').style.display='none'">
      </div>

      <div class="img-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeImageInsertModal()">Cancelar</button>
        <button type="button" id="img-url-submit-btn" class="btn btn-primary" onclick="submitUrlTab()">
          Insertar imagen
        </button>
      </div>
    </div>
  </div>
</div>

<!-- focus hint -->
<div class="focus-exit-hint">
  <button onclick="window.toggleFocus && window.toggleFocus()" class="focus-exit-btn"><?= __("focus_exit_hint") ?> (Salir)</button>
</div>

<!-- Floating buttons for mobile -->
<div id="floating-buttons-container" class="floating-buttons-container">
    <button id="floating-focus-btn" class="floating-focus-btn" type="button" title="Modo sin distracción">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
    </button>
    <button id="floating-preview-btn" class="floating-preview-btn" type="button" title="Vista previa">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
    <button id="floating-publish-btn" class="floating-publish-btn" type="button" title="<?= __("save") ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        <span id="floating-publish-label"><?= __("save") ?></span>
    </button>
</div>

<script>
// Floating buttons logic for mobile & Ctrl+S shortcut
(function() {
    const floatingContainer = document.getElementById('floating-buttons-container');
    const floatingFocusBtn  = document.getElementById('floating-focus-btn');
    const floatingPreviewBtn= document.getElementById('floating-preview-btn');
    const floatingPublishBtn= document.getElementById('floating-publish-btn');
    const publishBtn        = document.getElementById('publish-btn');
    const form              = document.getElementById('editor-form');

    if (!floatingContainer || !floatingPublishBtn) return;

    floatingFocusBtn?.addEventListener('click', function() {
        if (typeof window.toggleFocus === 'function') {
            window.toggleFocus();
        }
    });

    floatingPreviewBtn?.addEventListener('click', function() {
        if (typeof window.openPreview === 'function') {
            window.openPreview();
        }
    });

    floatingPublishBtn?.addEventListener('click', function() {
        if (typeof syncContentBeforeSave === 'function') {
            syncContentBeforeSave();
        }
        form?.submit();
    });

    function checkMobile() {
        const isMobile = window.innerWidth <= 1024;
        if (isMobile) {
            if (publishBtn) publishBtn.style.display = 'none';
            floatingContainer.style.display = 'flex';
        } else {
            if (publishBtn) publishBtn.style.display = '';
            floatingContainer.style.display = 'none';
        }
    }

    window.addEventListener('resize', checkMobile);
    checkMobile();

    // Ctrl+S / Cmd+S save shortcut
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (typeof syncContentBeforeSave === 'function') {
                syncContentBeforeSave();
            }
            form?.submit();
        }
    });
})();
</script>

<style>
.floating-buttons-container {
    display: none;
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10000;
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--surface);
    border-radius: 50px;
    padding: 6px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.3);
    border: 1px solid var(--border);
}
.floating-draft-btn,
.floating-focus-btn,
.floating-preview-btn,
.floating-publish-btn {
    border: none;
    border-radius: 50px;
    padding: 14px 22px;
    font-size: 1rem;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    white-space: nowrap;
    min-height: 52px;
}
.floating-draft-btn,
.floating-focus-btn,
.floating-preview-btn {
    background: var(--surface2);
    color: var(--text);
    border: 1px solid var(--border2);
}
.floating-draft-btn:hover,
.floating-focus-btn:hover,
.floating-preview-btn:hover {
    background: var(--border);
}
.floating-draft-btn:active,
.floating-focus-btn:active,
.floating-preview-btn:active {
    transform: scale(0.98);
}
.floating-publish-btn {
    background: var(--accent);
    color: #fff;
}
.floating-publish-btn:hover {
    opacity: 0.9;
    transform: scale(1.05);
}
.floating-publish-btn:active {
    transform: scale(0.98);
}
.floating-focus-btn,
.floating-preview-btn {
    gap: 0;
}
.floating-focus-btn span,
.floating-preview-btn span {
    display: none;
}
@media (max-width: 1024px) {
    #publish-btn,
    button[name="status"][value="draft"] {
        display: none !important;
    }
    .floating-buttons-container {
        display: flex;
    }
    @media (max-width: 480px) {
        .floating-buttons-container {
            padding: 5px;
        }
        .floating-draft-btn,
        .floating-focus-btn,
        .floating-preview-btn,
        .floating-publish-btn {
            padding: 12px 16px;
            font-size: 0.95rem;
            min-height: 48px;
        }
        .floating-draft-btn svg,
        .floating-focus-btn svg,
        .floating-preview-btn svg,
        .floating-publish-btn svg {
            width: 16px;
            height: 16px;
        }
    }
}

/* Light scheme overrides for floating buttons */
body.scheme-light .floating-buttons-container {
    background: #ffffff;
    border-color: #d0d0ca;
    box-shadow: 0 6px 24px rgba(0,0,0,0.1);
}
body.scheme-light .floating-draft-btn,
body.scheme-light .floating-focus-btn,
body.scheme-light .floating-preview-btn {
    background: #f0f0ec;
    color: #1a1a1a;
    border-color: #d0d0ca;
}
body.scheme-light .floating-draft-btn:hover,
body.scheme-light .floating-focus-btn:hover,
body.scheme-light .floating-preview-btn:hover {
    background: #e0e0da;
}
body.scheme-light .floating-publish-btn {
    background: var(--accent);
    color: #ffffff;
}

/* ── Modal Insertar Imagen ── */
.image-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.72);
  backdrop-filter: blur(4px);
  z-index: 99999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
}
.image-modal-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  width: 780px;
  max-width: 95vw;
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35);
  overflow: hidden;
  animation: imgModalFadeIn 0.16s ease-out;
}
@keyframes imgModalFadeIn {
  from { opacity: 0; transform: scale(0.97) translateY(4px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
.image-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
}
.image-modal-title-wrap {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  color: var(--text);
}
.image-modal-title-wrap h3 {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.image-modal-close {
  background: none;
  border: none;
  font-size: 1.25rem;
  color: var(--muted);
  cursor: pointer;
  padding: 0.3rem 0.5rem;
  border-radius: 6px;
  transition: all 0.15s;
  line-height: 1;
}
.image-modal-close:hover {
  background: var(--surface2);
  color: var(--text);
}
.image-modal-nav {
  display: flex;
  gap: 6px;
  padding: 0.6rem 1.4rem;
  background: var(--surface2);
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
}
.img-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 0.9rem;
  border-radius: 8px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--muted);
  font-size: 0.86rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s;
}
.img-tab-btn:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.05);
}
.img-tab-btn.active {
  background: var(--surface);
  color: var(--accent);
  border-color: var(--border);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.img-tab-panel {
  padding: 1.4rem;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
}
.img-dropzone {
  border: 2px dashed var(--border);
  border-radius: 12px;
  padding: 2.2rem 1.5rem;
  text-align: center;
  cursor: pointer;
  background: var(--bg);
  transition: border-color 0.2s, background 0.2s;
}
.img-dropzone:hover, .img-dropzone.dragover {
  border-color: var(--accent);
  background: rgba(var(--accent-rgb), 0.04);
}
.img-drop-icon {
  color: var(--accent);
  margin-bottom: 0.6rem;
  display: flex;
  justify-content: center;
}
.img-drop-title {
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--text);
}
.img-drop-title span {
  color: var(--accent);
  text-decoration: underline;
}
.img-drop-hint {
  font-size: 0.8rem;
  color: var(--muted);
  margin-top: 0.35rem;
}
.img-selected-preview {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.9rem 1rem;
  background: var(--surface2);
  border: 1px solid var(--border);
  border-radius: 10px;
  margin-top: 0.9rem;
}
.img-selected-thumb-wrap {
  width: 72px;
  height: 72px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--bg);
  border: 1px solid var(--border);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.img-selected-thumb-wrap img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.img-selected-info {
  flex: 1;
  overflow: hidden;
}
.img-selected-name {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.img-selected-meta {
  font-size: 0.78rem;
  color: var(--muted);
  margin-top: 2px;
}
.img-form-group {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.img-form-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.img-form-control {
  width: 100%;
  padding: 0.65rem 0.85rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text);
  font-size: 0.9rem;
  font-family: inherit;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.img-form-control:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.15);
}
.img-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1.4rem;
  padding-top: 1rem;
  border-top: 1px solid var(--border);
}
.img-status-msg {
  font-size: 0.84rem;
  font-weight: 600;
  margin-top: 0.75rem;
  padding: 0.5rem 0.75rem;
  border-radius: 6px;
}
.img-status-msg.error {
  background: rgba(244, 63, 94, 0.15);
  color: #f43f5e;
}
.img-status-msg.info {
  background: rgba(var(--accent-rgb), 0.12);
  color: var(--accent);
}
/* Library Grid */
.img-lib-toolbar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 0.85rem;
}
.img-lib-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  grid-auto-rows: 130px;
  gap: 12px;
  height: 360px;
  max-height: 52vh;
  overflow-y: auto;
  padding: 8px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 10px;
}
.img-lib-item {
  position: relative;
  width: 100%;
  height: 130px;
  border-radius: 8px;
  overflow: hidden;
  border: 2px solid var(--border);
  cursor: pointer;
  background: var(--surface2);
  transition: transform 0.15s, border-color 0.15s, box-shadow 0.15s;
  box-sizing: border-box;
}
.img-lib-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.img-lib-item:hover {
  border-color: var(--accent);
  transform: translateY(-2px);
  z-index: 2;
}
.img-lib-item.selected {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px var(--accent);
}
.img-lib-item.selected::after {
  content: '✓';
  position: absolute;
  top: 6px;
  right: 6px;
  background: var(--accent);
  color: #fff;
  border-radius: 50%;
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: bold;
  box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.img-lib-item-name {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 14px 6px 4px;
  background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.4) 60%, transparent 100%);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  pointer-events: none;
}
.img-lib-empty {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: var(--muted);
  font-size: 0.88rem;
  padding: 2rem;
  text-align: center;
}
/* URL Preview */
.img-url-preview-box {
  margin-top: 1rem;
  padding: 0.75rem;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  display: flex;
  justify-content: center;
  align-items: center;
  max-height: 180px;
  overflow: hidden;
}
.img-url-preview-box img {
  max-width: 100%;
  max-height: 160px;
  border-radius: 6px;
  object-fit: contain;
}
</style>
</body></html>
