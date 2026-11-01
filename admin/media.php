<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

if (cms_plugin_is_active('media-folders')) {
    require_once ROOT_PATH . '/plugins/media-folders/media-folders.php';
}

if (!defined('MEDIA_PATH')) define('MEDIA_PATH', ROOT_PATH . '/media');
if (!is_dir(MEDIA_PATH)) mkdir(MEDIA_PATH, 0755, true);

$csrf  = generate_csrf();
$msg   = '';
$error = '';

// File type classification
function media_type(string $ext): string {
    $audio = ['mp3','ogg','oga','wav','m4a','weba','flac','aac'];
    $video = ['mp4','m4v','webm','ogv','mov','avi'];
    $image = ['jpg','jpeg','png','gif','webp','svg','avif'];
    $ext   = strtolower($ext);
    if (in_array($ext, $audio)) return 'audio';
    if (in_array($ext, $video)) return 'video';
    if (in_array($ext, $image)) return 'image';
    return 'other';
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        // Move to trash (quireINK soft-delete)
        $rel  = ltrim(str_replace(['..','//'], '', $_POST['file'] ?? ''), '/');
        $path = MEDIA_PATH . '/' . $rel;
        if ($rel && file_exists($path) && is_file($path)) {
            require_once ROOT_PATH . '/core/content.php';
            trash_media($rel);
            media_delete_meta($rel);
            $msg = 'Archivo movido a la papelera. <a href="trash.php?tab=media" style="color:var(--accent);font-weight:600;margin-left:6px;">Ver papelera →</a>';
        }
    } elseif ($action === 'save_meta') {
        // Save alt text, title, caption, description and rename file
        $rel = ltrim(str_replace(['..','//'], '', $_POST['file'] ?? ''), '/');
        $alt = trim($_POST['alt'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $caption = trim($_POST['caption'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $newFilename = trim($_POST['filename'] ?? '');

        $renamedInfo = null;
        if (!empty($newFilename)) {
            $renamedInfo = media_rename_file($rel, $newFilename);
            if (!empty($renamedInfo['error'])) {
                if (!empty($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => $renamedInfo['error']]);
                    exit;
                }
                $error = $renamedInfo['error'];
            } elseif (!empty($renamedInfo['rel'])) {
                $rel = $renamedInfo['rel'];
            }
        }

        $saved = media_save_meta($rel, [
            'alt'         => $alt,
            'title'       => $title,
            'caption'     => $caption,
            'description' => $description,
        ]);

        if (isset($_POST['folder_id']) && function_exists('mf_assign_file')) {
            mf_assign_file($rel, $_POST['folder_id'] ?: null);
        }

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => $saved,
                'meta' => media_get_meta($rel),
                'renamed' => $renamedInfo,
                'folder_id' => $_POST['folder_id'] ?? null
            ]);
            exit;
        }

        $msg = __('media_meta_saved');
    } elseif (!empty($_FILES['upload']['tmp_name'])) {
        // Quick upload from media manager
        $file = $_FILES['upload'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_media = [
            'image/jpeg','image/png','image/gif','image/webp','image/svg+xml','image/avif',
            'audio/mpeg','audio/mp4','audio/ogg','audio/wav','audio/webm','audio/flac',
            'video/mp4','video/webm','video/ogg','video/quicktime',
        ];

        if (!in_array($mime, $allowed_media)) {
            $error = "Tipo no permitido: $mime";
        } else {
            $type = explode('/', $mime)[0];
            $max  = ['image'=>10,'audio'=>200,'video'=>2048][$type] ?? 10;
            if ($file['size'] > $max * 1024 * 1024) {
                $error = "El archivo supera el límite de {$max} MB";
            } else {
                $date_dir = MEDIA_PATH . '/' . date('Y/m');
                if (!is_dir($date_dir)) mkdir($date_dir, 0755, true);
                $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $clean    = trim(preg_replace('/-+/','-',preg_replace('/[^a-z0-9_-]/','-',strtolower(pathinfo($file['name'],PATHINFO_FILENAME)))),'-');
                $filename = $clean . '-' . substr(md5(uniqid()),0,6) . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $date_dir . '/' . $filename);
                
                $rel_new = date('Y/m') . '/' . $filename;
                
                // If alt text was supplied upon upload
                if (!empty($_POST['upload_alt'])) {
                    media_save_meta($rel_new, ['alt' => $_POST['upload_alt']]);
                }

                // If folder was selected upon upload
                if (!empty($_POST['upload_folder']) && function_exists('mf_assign_file')) {
                    mf_assign_file($rel_new, $_POST['upload_folder']);
                }

                $msg = "Subido: $filename";
            }
        }
    }
}

// Collect all media metadata
$all_meta = media_get_all_meta();

// Scan usage across posts and pages (quireINK orphan detection)
require_once ROOT_PATH . '/core/content.php';
$usageInfo = function_exists('media_scan_usages') ? media_scan_usages() : ['map' => [], 'scanned' => 0];
$usageMap  = $usageInfo['map'] ?? [];

// Collect all media files recursively
$files = [];
$base_url = rtrim(base_url(), '/');

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MEDIA_PATH, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    $mt  = media_type($ext);
    if ($mt === 'other') continue;
    $rel = str_replace(MEDIA_PATH . '/', '', $f->getPathname());
    $fn  = $f->getFilename();
    $meta = $all_meta[$rel] ?? ['alt' => '', 'title' => '', 'caption' => '', 'description' => ''];
    $usedIn = $usageMap[$fn] ?? ($usageMap[$rel] ?? []);
    $isUsed = !empty($usedIn);

    $folderId = function_exists('mf_get_file_folder') ? (mf_get_file_folder($rel) ?? '') : '';
    $files[] = [
        'name'    => $fn,
        'rel'     => $rel,
        'size'    => $f->getSize(),
        'time'    => $f->getMTime(),
        'url'     => $base_url . '/media/' . $rel,
        'ext'     => $ext,
        'type'    => $mt,
        'meta'    => $meta,
        'folder'  => $folderId,
        'is_used' => $isUsed,
        'used_in' => $usedIn,
    ];
}
usort($files, fn($a, $b) => $b['time'] - $a['time']);

// Stats
$total_size   = array_sum(array_column($files, 'size'));
$by_type      = array_count_values(array_column($files, 'type'));
$used_count   = count(array_filter($files, fn($f) => !empty($f['is_used'])));
$unused_count = count(array_filter($files, fn($f) => empty($f['is_used'])));

// Media Folders Plugin Data
$mf_active = cms_plugin_is_active('media-folders');
$mf_counts = ($mf_active && function_exists('mf_get_counts')) ? mf_get_counts($files) : [];
$mf_tree   = ($mf_active && function_exists('mf_get_tree')) ? mf_get_tree() : [];

// Filter
$filter = $_GET['type'] ?? 'all';
if ($filter === 'used') {
    $visible = array_values(array_filter($files, fn($f) => !empty($f['is_used'])));
} elseif ($filter === 'unused') {
    $visible = array_values(array_filter($files, fn($f) => empty($f['is_used'])));
} elseif ($filter === 'all') {
    $visible = $files;
} else {
    $visible = array_values(array_filter($files, fn($f) => ($f['type'] ?? '') === $filter));
}

// JSON / AJAX API endpoint for media picker
if (!empty($_GET['json']) || !empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'files' => $visible]);
    exit;
}

admin_header(__("media_title"), 'media');
?>
      <label class="btn btn-primary" style="cursor:pointer">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?= __("media_upload") ?>
        <input type="file" name="upload" form="upload-form"
          accept="image/*,audio/*,video/*,.mp3,.ogg,.wav,.m4a,.mp4,.webm,.ogv,.flac"
          style="display:none" onchange="document.getElementById('upload-form').submit()">
      </label>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= $msg ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form id="upload-form" method="POST" enctype="multipart/form-data" style="display:none">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="upload_folder" id="upload-form-folder" value="">
    </form>

    <?php if ($mf_active): ?>
    <div class="media-folders-wrapper">
      <aside class="media-folders-pane">
        <div class="media-folders-header">
          <span class="media-folders-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            Carpetas
          </span>
          <button type="button" class="btn btn-xs btn-primary" onclick="openNewFolderModal()" title="Crear nueva carpeta">+ Nueva</button>
        </div>

        <div class="media-folders-list" id="media-folders-tree">
          <div class="media-folder-row is-active" data-folder-id="all" onclick="filterByFolder('all', this)">
            <span class="mf-icon">📁</span>
            <span class="mf-name">Todos</span>
            <span class="mf-count" id="mf-cnt-all"><?= count($files) ?></span>
          </div>
          <div class="media-folder-row" data-folder-id="unassigned" onclick="filterByFolder('unassigned', this)">
            <span class="mf-icon">📂</span>
            <span class="mf-name">Sin carpeta</span>
            <span class="mf-count" id="mf-cnt-unassigned"><?= $mf_counts['_unassigned'] ?? 0 ?></span>
          </div>
          <div class="media-folder-divider"></div>
          <?php
          if (!function_exists('render_media_sidebar_tree')) {
              function render_media_sidebar_tree(array $tree, array $counts, int $depth = 0) {
                  foreach ($tree as $folder) {
                      $fId = $folder['id'];
                      $cnt = $counts[$fId] ?? 0;
                      ?>
                      <div class="media-folder-row mf-user-folder" data-folder-id="<?= htmlspecialchars($fId) ?>" data-depth="<?= $depth ?>" style="padding-left:<?= 0.65 + ($depth * 1.1) ?>rem" onclick="filterByFolder('<?= htmlspecialchars($fId) ?>', this)">
                        <span class="mf-icon">📁</span>
                        <span class="mf-name" title="<?= htmlspecialchars($folder['name']) ?>"><?= htmlspecialchars($folder['name']) ?></span>
                        <span class="mf-count" id="mf-cnt-<?= htmlspecialchars($fId) ?>"><?= $cnt ?></span>
                        <div class="mf-actions" onclick="event.stopPropagation()">
                          <button type="button" class="mf-act-btn" onclick="openNewFolderModal('<?= htmlspecialchars($fId) ?>', '<?= htmlspecialchars(addslashes($folder['name'])) ?>')" title="Crear subcarpeta">＋</button>
                          <button type="button" class="mf-act-btn" onclick="renameFolderPrompt('<?= htmlspecialchars($fId) ?>', '<?= htmlspecialchars(addslashes($folder['name'])) ?>')" title="Renombrar">✎</button>
                          <button type="button" class="mf-act-btn mf-del" onclick="deleteFolderPrompt('<?= htmlspecialchars($fId) ?>', '<?= htmlspecialchars(addslashes($folder['name'])) ?>')" title="Eliminar">✕</button>
                        </div>
                      </div>
                      <?php
                      if (!empty($folder['children'])) {
                          render_media_sidebar_tree($folder['children'], $counts, $depth + 1);
                      }
                  }
              }
          }
          if (!empty($mf_tree)) {
              render_media_sidebar_tree($mf_tree, $mf_counts);
          }
          ?>
        </div>
      </aside>
      <div class="media-main-pane">
        <div id="media-folder-banner" class="media-folder-banner" style="display:none;align-items:center;justify-content:space-between;padding:0.6rem 1rem;background:var(--surface2);border:1px solid var(--border);border-radius:10px;margin-bottom:1rem">
          <div style="display:flex;align-items:center;gap:0.5rem">
            <span style="font-size:0.85rem;color:var(--muted)">Carpeta:</span>
            <strong id="active-folder-label" style="color:var(--text);font-size:0.95rem">—</strong>
            <span id="active-folder-count-badge" class="badge" style="background:var(--accent-light, rgba(239,68,68,0.12));color:var(--accent);font-size:0.75rem;padding:2px 8px;border-radius:10px;font-weight:700">0</span>
          </div>
          <button type="button" class="btn btn-xs btn-secondary" onclick="filterByFolder('all')">✕ Ver todos</button>
        </div>
    <?php endif; ?>

    <!-- Filter tabs & Stats (quireINK style) -->
    <div style="display:flex;gap:0.5rem;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center">
      <?php 
      $tabs = [
        'all'    => ['label' => __("media_filter_all"), 'count' => count($files)],
        'image'  => ['label' => __("media_filter_image"), 'count' => ($by_type['image'] ?? 0)],
        'audio'  => ['label' => __("media_filter_audio"), 'count' => ($by_type['audio'] ?? 0)],
        'video'  => ['label' => __("media_filter_video"), 'count' => ($by_type['video'] ?? 0)],
        'used'   => ['label' => 'En uso', 'count' => $used_count],
        'unused' => ['label' => 'Sin usar (huérfanos)', 'count' => $unused_count],
      ];
      foreach ($tabs as $key => $tabInfo): ?>
      <a href="?type=<?= $key ?>" class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-secondary' ?>">
        <?= htmlspecialchars($tabInfo['label']) ?>
        <span style="opacity:0.75;font-size:0.75rem;font-weight:600">(<?= $tabInfo['count'] ?>)</span>
      </a>
      <?php endforeach; ?>
      <span style="margin-left:auto;color:var(--muted);font-size:0.84rem;font-weight:500">
        <?= count($files) ?> archivos · <?= round($total_size/1024/1024,1) ?> MB
      </span>
    </div>

    <?php if (empty($visible)): ?>
    <div style="text-align:center;padding:4.5rem 2rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted)">
      <div style="font-size:3rem;margin-bottom:1rem">📂</div>
      <p style="font-size:1.05rem;font-weight:500"><?= __("media_empty") ?></p>
    </div>
    <?php else: ?>
    <div class="media-grid" id="media-grid">
      <?php foreach ($visible as $f): 
        $has_alt = !empty($f['meta']['alt']);
        $has_title = !empty($f['meta']['title']);
      ?>
      <div class="media-item" 
           data-rel="<?= htmlspecialchars($f['rel']) ?>"
           data-name="<?= htmlspecialchars($f['name']) ?>"
           data-url="<?= htmlspecialchars($f['url']) ?>"
           data-type="<?= $f['type'] ?>"
           data-folder="<?= htmlspecialchars($f['folder'] ?? '') ?>"
           data-size="<?= round($f['size']/1024) ?> KB"
           data-date="<?= date('Y-m-d H:i', $f['time']) ?>"
           data-alt="<?= htmlspecialchars($f['meta']['alt'] ?? '') ?>"
           data-title="<?= htmlspecialchars($f['meta']['title'] ?? '') ?>"
           data-caption="<?= htmlspecialchars($f['meta']['caption'] ?? '') ?>"
           data-description="<?= htmlspecialchars($f['meta']['description'] ?? '') ?>"
           draggable="true"
           ondragstart="handleMediaDragStart(event, this)"
           onclick="openMediaModal(this)">
        <div class="media-thumb">

          <!-- Usage status badge (quireINK orphan detector) -->
          <?php if (!empty($f['is_used'])): ?>
            <span class="media-badge-usage used" style="position:absolute;top:6px;left:6px;z-index:3;font-size:0.68rem;font-weight:600;padding:2px 6px;border-radius:4px;background:rgba(16,185,129,0.9);color:#fff;backdrop-filter:blur(4px);" title="En uso en: <?= htmlspecialchars(implode(', ', array_column($f['used_in'], 'title'))) ?>">
              ✓ En uso (<?= count($f['used_in']) ?>)
            </span>
          <?php else: ?>
            <span class="media-badge-usage orphan" style="position:absolute;top:6px;left:6px;z-index:3;font-size:0.68rem;font-weight:600;padding:2px 6px;border-radius:4px;background:rgba(245,158,11,0.9);color:#fff;backdrop-filter:blur(4px);" title="Huérfano: No está referenciado en ningún artículo o página">
              ⚠ Sin usar
            </span>
          <?php endif; ?>

          <?php if ($f['type'] === 'image'): ?>
            <img src="<?= htmlspecialchars(image_cache_url($f['url'], 340, 340)) ?>" alt="<?= htmlspecialchars($f['meta']['alt'] ?: $f['name']) ?>" loading="lazy">
            <?php if ($has_alt): ?>
              <span class="media-badge-alt" title="Alt: <?= htmlspecialchars($f['meta']['alt']) ?>">ALT ✓</span>
            <?php else: ?>
              <span class="media-badge-noalt" title="Sin texto alternativo">Sin ALT</span>
            <?php endif; ?>

          <?php elseif ($f['type'] === 'audio'): ?>
            <div class="media-audio-thumb">
              <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" style="color:var(--accent)"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
              <span class="media-type-badge"><?= strtoupper($f['ext']) ?></span>
            </div>

          <?php else: ?>
            <div class="media-video-thumb">
              <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" style="color:var(--accent)"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
              <span class="media-type-badge"><?= strtoupper($f['ext']) ?></span>
            </div>
          <?php endif; ?>

          <div class="media-overlay" onclick="event.stopPropagation()">
            <button class="media-copy" onclick="copyUrl(<?= htmlspecialchars(json_encode($f['url'])) ?>)" title="<?= __raw('media_copy_url') ?>">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
            <?php if ($f['type'] === 'audio'): ?>
            <button class="media-preview-btn" onclick="previewAudio(<?= htmlspecialchars(json_encode($f['url'])) ?>)" title="<?= __raw('media_preview') ?>">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </button>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm(<?= json_encode(__raw('media_confirm_del')) ?>)">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="file" value="<?= htmlspecialchars($f['rel']) ?>">
              <button type="submit" class="media-del" title="<?= __raw('media_delete') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
              </button>
            </form>
          </div>
        </div>
        <div class="media-name" title="<?= htmlspecialchars($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></div>
        <div class="media-meta">
          <span><?= round($f['size']/1024) ?> KB</span>
          <?php if (!empty($f['meta']['alt'])): ?>
            <span style="color:var(--green);font-size:0.75rem;font-weight:600">· Alt configurado</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($mf_active): ?>
      </div><!-- /.media-main-pane -->
    </div><!-- /.media-folders-wrapper -->
    <?php endif; ?>

    <!-- Media Inspector Modal -->
    <div id="media-modal" class="modal-backdrop" style="display:none" onclick="if(event.target===this)closeMediaModal()">
      <div class="modal-card media-modal-dialog">
        <div class="media-modal-header">
          <div>
            <h3 class="modal-title" style="margin:0;font-size:1.25rem"><?= __('media_modal_title') ?></h3>
            <span id="modal-filename" style="font-size:0.86rem;color:var(--muted);font-weight:500"></span>
          </div>
          <button type="button" class="modal-close-btn" onclick="closeMediaModal()">✕</button>
        </div>

        <div class="media-modal-body">
          <!-- Left: Preview & File Details -->
          <div class="media-modal-preview-pane">
            <div id="modal-preview-container" class="media-modal-thumb-box"></div>
            <div class="media-meta-specs">
              <div class="spec-row">
                <span class="spec-label">Ruta:</span>
                <span class="spec-value" id="modal-rel-path"></span>
              </div>
              <div class="spec-row">
                <span class="spec-label">Tamaño:</span>
                <span class="spec-value" id="modal-size"></span>
              </div>
              <div class="spec-row">
                <span class="spec-label">Fecha:</span>
                <span class="spec-value" id="modal-date"></span>
              </div>
            </div>

            <!-- Quick copy actions -->
            <div class="media-quick-actions">
              <button type="button" class="btn btn-secondary btn-sm" onclick="copyModalUrl()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <?= __('media_copy_url') ?>
              </button>
              <button type="button" class="btn btn-secondary btn-sm" onclick="copyModalMarkdown()">
                <?= __('media_copy_md') ?>
              </button>
              <button type="button" class="btn btn-secondary btn-sm" onclick="copyModalHtml()">
                <?= __('media_copy_html') ?>
              </button>
            </div>
          </div>

          <!-- Right: Editable Metadata Form -->
          <form id="media-meta-form" class="media-modal-form-pane" onsubmit="saveMediaMetadata(event)">
            <input type="hidden" id="modal-input-rel" name="file" value="">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save_meta">

            <div class="form-group" style="margin-bottom:1.15rem">
              <label for="modal-input-filename" class="form-label" style="font-weight:700">
                <?= __('media_filename', 'Nombre del archivo') ?>
              </label>
              <input type="text" id="modal-input-filename" name="filename" class="form-control" placeholder="nombre-del-archivo.jpg" style="font-size:0.95rem">
              <small style="display:block;margin-top:0.35rem;color:var(--muted);font-size:0.8rem">
                Puedes renombrar el archivo en el servidor. La extensión original se conserva de forma segura.
              </small>
            </div>

            <?php if ($mf_active && function_exists('mf_render_folder_options')): ?>
            <div class="form-group" style="margin-bottom:1.15rem">
              <label for="modal-input-folder" class="form-label" style="font-weight:700">
                Carpeta
              </label>
              <select id="modal-input-folder" name="folder_id" class="form-control" style="font-size:0.95rem">
                <option value="">(Sin carpeta / Raíz)</option>
                <?= mf_render_folder_options() ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom:1.15rem">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem">
                <label for="modal-input-alt" class="form-label" style="margin-bottom:0;font-weight:700">
                  <?= __('media_alt') ?>
                </label>
                <span class="badge" style="background:rgba(16,185,129,0.12);color:var(--green);font-size:0.75rem;padding:0.2rem 0.5rem;border-radius:6px;font-weight:600">SEO & Accesibilidad</span>
              </div>
              <input type="text" id="modal-input-alt" name="alt" class="form-control" placeholder="Ej: Atardecer dorado en el malecón con palmeras" style="font-size:0.95rem">
              <small style="display:block;margin-top:0.35rem;color:var(--muted);font-size:0.8rem">
                <?= __('media_alt_desc') ?>
              </small>
            </div>

            <div class="form-group" style="margin-bottom:1.15rem">
              <label for="modal-input-title" class="form-label" style="font-weight:600">
                <?= __('media_title_field') ?>
              </label>
              <input type="text" id="modal-input-title" name="title" class="form-control" placeholder="Título que aparece al pasar el cursor" style="font-size:0.95rem">
            </div>

            <div class="form-group" style="margin-bottom:1.15rem">
              <label for="modal-input-caption" class="form-label" style="font-weight:600">
                <?= __('media_caption') ?>
              </label>
              <input type="text" id="modal-input-caption" name="caption" class="form-control" placeholder="Leyenda o pie de imagen visible" style="font-size:0.95rem">
            </div>

            <div class="form-group" style="margin-bottom:1.35rem">
              <label for="modal-input-description" class="form-label" style="font-weight:600">
                <?= __('media_description') ?>
              </label>
              <textarea id="modal-input-description" name="description" rows="3" class="form-control" placeholder="Detalles adicionales o notas sobre este medio…" style="resize:vertical;font-size:0.92rem"></textarea>
            </div>

            <div style="display:flex;gap:0.75rem;align-items:center;justify-content:flex-end;margin-top:auto;padding-top:1rem;border-top:1px solid var(--border)">
              <span id="modal-save-feedback" style="display:none;font-size:0.88rem;color:var(--green);font-weight:600;margin-right:auto">
                ✓ Guardado correctamente
              </span>
              <button type="button" class="btn btn-secondary" onclick="closeMediaModal()"><?= __('users_cancel') ?></button>
              <button type="submit" id="modal-save-btn" class="btn btn-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <?= __('media_save_meta') ?>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Audio preview player (hidden, shown on play) -->
    <div id="audio-player-bar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:var(--sidebar);border-top:1px solid var(--border);padding:1rem 2rem;z-index:500;align-items:center;gap:1.25rem;box-shadow:0 -4px 20px rgba(0,0,0,0.25)">
      <span style="font-size:0.95rem;font-weight:600;color:var(--text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" id="audio-player-name">—</span>
      <audio id="audio-player" controls style="flex:1;min-width:200px;height:40px"></audio>
      <button onclick="document.getElementById('audio-player-bar').style.display='none';document.getElementById('audio-player').pause()"
        style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.35rem;padding:0.4rem;display:flex;align-items:center;justify-content:center">✕</button>
    </div>

    <div id="copy-toast" style="display:none;position:fixed;bottom:2rem;right:2rem;background:var(--green);color:#fff;padding:0.8rem 1.4rem;border-radius:10px;font-size:0.95rem;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,0.25);z-index:9999">
      ✓ <?= __("media_copied", "Copiado al portapapeles") ?>
    </div>
  </div>
</div>

<style>
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(195px, 1fr)); gap: 1.4rem; }
.media-item { cursor: pointer; transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); user-select: none; }
.media-item:hover { transform: translateY(-3px); }
.media-thumb {
  position: relative; aspect-ratio: 1; background: var(--surface2);
  border-radius: 14px; overflow: hidden; border: 1px solid var(--border);
  box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}
.media-thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.25s ease; }
.media-item:hover .media-thumb img { transform: scale(1.05); }

.media-badge-alt {
  position: absolute; top: 8px; left: 8px;
  background: rgba(16, 185, 129, 0.92); color: #fff;
  font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em;
  padding: 0.2rem 0.5rem; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);
  z-index: 2; pointer-events: none;
}
.media-badge-noalt {
  position: absolute; top: 8px; left: 8px;
  background: rgba(0, 0, 0, 0.5); color: rgba(255,255,255,0.75);
  font-size: 0.65rem; font-weight: 700;
  padding: 0.15rem 0.45rem; border-radius: 6px;
  z-index: 2; pointer-events: none;
}

.media-audio-thumb, .media-video-thumb {
  width: 100%; height: 100%; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 0.85rem;
}
.media-type-badge {
  font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em;
  background: rgba(var(--accent-rgb),0.18); color: var(--accent);
  padding: 0.25rem 0.75rem; border-radius: 20px;
}

.media-overlay {
  position: absolute; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; gap: 0.6rem;
  opacity: 0; backdrop-filter: blur(2px); transition: opacity 0.2s;
  z-index: 3;
}
.media-item:hover .media-overlay { opacity: 1; }
.media-copy, .media-del, .media-preview-btn {
  background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4);
  color: #fff; border-radius: 9px; width: 38px; height: 38px; cursor: pointer;
  display: flex; align-items: center; justify-content: center; transition: all 0.15s;
}
.media-copy:hover, .media-preview-btn:hover { background: rgba(255,255,255,0.38); transform: scale(1.08); }
.media-del:hover { background: rgba(244,63,94,0.85); transform: scale(1.08); }

.media-name { font-size: 0.9rem; font-weight: 600; color: var(--text); margin-top: 0.6rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-meta { font-size: 0.8rem; font-weight: 500; color: var(--muted); margin-top: 0.15rem; display: flex; align-items: center; gap: 0.4rem; }

/* Modal Styles */
.media-modal-dialog {
  width: 900px; max-width: 95vw; max-height: 90vh;
  display: flex; flex-direction: column; padding: 0; overflow: hidden;
}
.media-modal-header {
  padding: 1.25rem 1.6rem; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
  background: var(--surface);
}
.media-modal-body {
  display: grid; grid-template-columns: 360px 1fr;
  flex: 1; overflow-y: auto;
}
@media (max-width: 820px) {
  .media-modal-body { grid-template-columns: 1fr; }
}
.media-modal-preview-pane {
  padding: 1.6rem; background: var(--surface2);
  border-right: 1px solid var(--border); display: flex; flex-direction: column; gap: 1.2rem;
}
.media-modal-thumb-box {
  width: 100%; aspect-ratio: 4/3; max-height: 250px;
  background: var(--bg); border: 1px solid var(--border); border-radius: 12px;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.media-modal-thumb-box img {
  max-width: 100%; max-height: 100%; object-fit: contain;
}
.media-meta-specs {
  display: flex; flex-direction: column; gap: 0.45rem; font-size: 0.82rem;
  background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 0.85rem 1rem;
}
.spec-row { display: flex; justify-content: space-between; gap: 0.5rem; }
.spec-label { color: var(--muted); font-weight: 600; }
.spec-value { color: var(--text); font-weight: 500; word-break: break-all; }

.media-quick-actions {
  display: flex; flex-direction: column; gap: 0.5rem;
}
.media-quick-actions .btn {
  justify-content: center; font-size: 0.85rem; padding: 0.55rem 0.85rem;
}
.media-modal-form-pane {
  padding: 1.6rem; display: flex; flex-direction: column; background: var(--surface);
}
.modal-close-btn {
  background: none; border: none; font-size: 1.35rem; color: var(--muted); cursor: pointer;
  padding: 0.35rem 0.5rem; border-radius: 8px; transition: background 0.15s;
}
.modal-close-btn:hover { background: var(--surface2); color: var(--text); }

/* Media Folders Layout */
.media-folders-wrapper {
  display: flex;
  gap: 1.5rem;
  align-items: flex-start;
  margin-top: 0.5rem;
}
.media-folders-pane {
  width: 250px;
  min-width: 250px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1rem;
  position: sticky;
  top: 1.5rem;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.media-folders-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.85rem;
  padding-bottom: 0.6rem;
  border-bottom: 1px solid var(--border);
}
.media-folders-title {
  font-weight: 700;
  font-size: 0.95rem;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 0.45rem;
}
.media-folders-list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  max-height: calc(100vh - 200px);
  overflow-y: auto;
}
.media-folder-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.65rem;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.88rem;
  color: var(--text);
  transition: all 0.15s ease;
  position: relative;
  user-select: none;
}
.media-folder-row:hover {
  background: var(--surface2);
}
.media-folder-row.is-active {
  background: var(--accent-light, rgba(239,68,68,0.1));
  color: var(--accent);
  font-weight: 600;
}
.media-folder-row.drag-over {
  outline: 2px dashed var(--accent);
  background: var(--accent-light, rgba(239,68,68,0.15));
  transform: scale(1.02);
}
.media-folder-row .mf-icon {
  font-size: 1rem;
  line-height: 1;
}
.media-folder-row .mf-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.media-folder-row .mf-count {
  font-size: 0.75rem;
  color: var(--muted);
  font-weight: 600;
  background: var(--surface2);
  padding: 1px 6px;
  border-radius: 10px;
}
.media-folder-row.is-active .mf-count {
  background: rgba(239,68,68,0.2);
  color: var(--accent);
}
.media-folder-divider {
  height: 1px;
  background: var(--border);
  margin: 0.4rem 0;
}
.mf-actions {
  display: none;
  align-items: center;
  gap: 2px;
  margin-left: 4px;
}
.media-folder-row:hover .mf-actions {
  display: flex;
}
.mf-act-btn {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 0.8rem;
  padding: 2px 4px;
  border-radius: 4px;
  line-height: 1;
}
.mf-act-btn:hover {
  background: var(--border);
  color: var(--text);
}
.mf-act-btn.mf-del:hover {
  color: var(--red, #ef4444);
}
.media-main-pane {
  flex: 1;
  min-width: 0;
}
@media (max-width: 860px) {
  .media-folders-wrapper {
    flex-direction: column;
  }
  .media-folders-pane {
    width: 100%;
    position: static;
  }
}
</style>

<script>
let currentModalItem = null;

function copyUrl(url) {
  navigator.clipboard.writeText(url).then(() => {
    showToast('✓ URL copiada al portapapeles');
  });
}

function showToast(text) {
  const t = document.getElementById('copy-toast');
  t.textContent = text;
  t.style.display = 'block';
  setTimeout(() => t.style.display = 'none', 2400);
}

function previewAudio(url) {
  const bar    = document.getElementById('audio-player-bar');
  const player = document.getElementById('audio-player');
  const name   = document.getElementById('audio-player-name');
  player.src   = url;
  name.textContent = url.split('/').pop();
  bar.style.display = 'flex';
  player.play();
}

function openMediaModal(itemEl) {
  currentModalItem = itemEl;
  const rel = itemEl.dataset.rel;
  const name = itemEl.dataset.name;
  const url = itemEl.dataset.url;
  const type = itemEl.dataset.type;
  const size = itemEl.dataset.size;
  const date = itemEl.dataset.date;
  const alt = itemEl.dataset.alt || '';
  const title = itemEl.dataset.title || '';
  const caption = itemEl.dataset.caption || '';
  const description = itemEl.dataset.description || '';

  document.getElementById('modal-filename').textContent = name;
  document.getElementById('modal-rel-path').textContent = rel;
  document.getElementById('modal-size').textContent = size;
  document.getElementById('modal-date').textContent = date;

  document.getElementById('modal-input-rel').value = rel;
  document.getElementById('modal-input-filename').value = name;
  document.getElementById('modal-input-alt').value = alt;
  document.getElementById('modal-input-title').value = title;
  document.getElementById('modal-input-caption').value = caption;
  document.getElementById('modal-input-description').value = description;

  const folder = itemEl.dataset.folder || '';
  const folderInput = document.getElementById('modal-input-folder');
  if (folderInput) folderInput.value = folder;

  const container = document.getElementById('modal-preview-container');
  container.innerHTML = '';
  if (type === 'image') {
    const img = document.createElement('img');
    img.src = url;
    img.alt = alt || name;
    container.appendChild(img);
  } else if (type === 'audio') {
    container.innerHTML = `<audio controls src="${url}" style="width:90%"></audio>`;
  } else if (type === 'video') {
    container.innerHTML = `<video controls src="${url}" style="max-width:100%;max-height:100%"></video>`;
  }

  document.getElementById('modal-save-feedback').style.display = 'none';
  document.getElementById('media-modal').style.display = 'flex';
}

function closeMediaModal() {
  document.getElementById('media-modal').style.display = 'none';
  currentModalItem = null;
}

function saveMediaMetadata(e) {
  e.preventDefault();
  const form = document.getElementById('media-meta-form');
  const fd = new FormData(form);
  fd.append('ajax', '1');

  const btn = document.getElementById('modal-save-btn');
  const origText = btn.innerHTML;
  btn.disabled = true;
  btn.textContent = 'Guardando…';

  fetch('media.php', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = origText;
    if (data.ok) {
      const fb = document.getElementById('modal-save-feedback');
      fb.style.display = 'inline';
      setTimeout(() => fb.style.display = 'none', 3000);

      // Handle rename update
      if (data.renamed && data.renamed.renamed) {
        if (currentModalItem) {
          currentModalItem.dataset.rel = data.renamed.rel;
          currentModalItem.dataset.name = data.renamed.filename;
          currentModalItem.dataset.url = data.renamed.url;
          const nameEl = currentModalItem.querySelector('.media-name');
          if (nameEl) {
            nameEl.textContent = data.renamed.filename;
            nameEl.title = data.renamed.filename;
          }
        }
        document.getElementById('modal-filename').textContent = data.renamed.filename;
        document.getElementById('modal-rel-path').textContent = data.renamed.rel;
        document.getElementById('modal-input-rel').value = data.renamed.rel;
        document.getElementById('modal-input-filename').value = data.renamed.filename;
      }

      // Update data attributes in DOM element
      if (currentModalItem) {
        currentModalItem.dataset.alt = data.meta.alt || '';
        currentModalItem.dataset.title = data.meta.title || '';
        currentModalItem.dataset.caption = data.meta.caption || '';
        currentModalItem.dataset.description = data.meta.description || '';
        if (data.folder_id !== undefined) {
          currentModalItem.dataset.folder = data.folder_id || '';
          if (typeof updateFolderCountsFromDOM === 'function') {
            updateFolderCountsFromDOM();
          }
        }

        // Update badge
        const thumb = currentModalItem.querySelector('.media-thumb');
        let altBadge = thumb.querySelector('.media-badge-alt, .media-badge-noalt');
        if (data.meta.alt) {
          if (!altBadge) {
            altBadge = document.createElement('span');
            thumb.appendChild(altBadge);
          }
          altBadge.className = 'media-badge-alt';
          altBadge.textContent = 'ALT ✓';
          altBadge.title = 'Alt: ' + data.meta.alt;
        } else if (altBadge) {
          altBadge.className = 'media-badge-noalt';
          altBadge.textContent = 'Sin ALT';
          altBadge.title = 'Sin texto alternativo';
        }
      }
      showToast('✓ Metadatos guardados correctamente');
    } else {
      alert(data.error || 'Error al guardar los metadatos.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = origText;
    alert('Error al guardar los metadatos.');
  });
}

function copyModalUrl() {
  if (!currentModalItem) return;
  copyUrl(currentModalItem.dataset.url);
}

function copyModalMarkdown() {
  if (!currentModalItem) return;
  const url = currentModalItem.dataset.url;
  const alt = document.getElementById('modal-input-alt').value.trim() || currentModalItem.dataset.name;
  const md = `![${alt}](${url})`;
  navigator.clipboard.writeText(md).then(() => {
    showToast('✓ Markdown copiado: ' + md);
  });
}

function copyModalHtml() {
  if (!currentModalItem) return;
  const url = currentModalItem.dataset.url;
  const alt = document.getElementById('modal-input-alt').value.trim() || currentModalItem.dataset.name;
  const title = document.getElementById('modal-input-title').value.trim();
  const titleAttr = title ? ` title="${title.replace(/"/g, '&quot;')}"` : '';
  const html = `<img src="${url}" alt="${alt.replace(/"/g, '&quot;')}"${titleAttr}>`;
  navigator.clipboard.writeText(html).then(() => {
    showToast('✓ HTML copiado al portapapeles');
  });
}

let currentActiveFolderId = 'all';

function filterByFolder(folderId, rowEl) {
  currentActiveFolderId = folderId;

  document.querySelectorAll('.media-folder-row').forEach(r => {
    r.classList.toggle('is-active', r.dataset.folderId === folderId);
  });

  const upFolderInput = document.getElementById('upload-form-folder');
  if (upFolderInput) {
    upFolderInput.value = (folderId === 'all' || folderId === 'unassigned') ? '' : folderId;
  }

  const banner = document.getElementById('media-folder-banner');
  const label = document.getElementById('active-folder-label');
  const badge = document.getElementById('active-folder-count-badge');

  let visibleCount = 0;
  let folderName = 'Todos';

  if (rowEl) {
    folderName = rowEl.querySelector('.mf-name')?.textContent || folderId;
  } else {
    const activeRow = document.querySelector(`.media-folder-row[data-folder-id="${folderId}"]`);
    if (activeRow) folderName = activeRow.querySelector('.mf-name')?.textContent || folderId;
  }

  if (banner && label) {
    if (folderId === 'all') {
      banner.style.display = 'none';
    } else {
      label.textContent = folderName;
      banner.style.display = 'flex';
    }
  }

  const items = document.querySelectorAll('.media-grid .media-item');
  items.forEach(it => {
    const itFolder = it.dataset.folder || '';
    let matches = false;
    if (folderId === 'all') {
      matches = true;
    } else if (folderId === 'unassigned') {
      matches = (itFolder === '');
    } else {
      matches = (itFolder === folderId);
    }
    it.style.display = matches ? '' : 'none';
    if (matches) visibleCount++;
  });

  if (badge) badge.textContent = visibleCount;
}

function handleMediaDragStart(e, itemEl) {
  e.dataTransfer.setData('text/plain', itemEl.dataset.rel);
  e.dataTransfer.effectAllowed = 'move';
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.media-folder-row').forEach(row => {
    row.addEventListener('dragover', (e) => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      row.classList.add('drag-over');
    });
    row.addEventListener('dragleave', () => {
      row.classList.remove('drag-over');
    });
    row.addEventListener('drop', async (e) => {
      e.preventDefault();
      row.classList.remove('drag-over');
      const rel = e.dataTransfer.getData('text/plain');
      if (!rel) return;

      const targetFolderId = row.dataset.folderId;
      const actualFolderId = (targetFolderId === 'all' || targetFolderId === 'unassigned') ? '' : targetFolderId;

      const fd = new FormData();
      fd.append('action', 'move_file');
      fd.append('file', rel);
      fd.append('folder_id', actualFolderId);
      fd.append('csrf', <?= json_encode($csrf) ?>);

      try {
        const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          const item = document.querySelector(`.media-item[data-rel="${CSS.escape(rel)}"]`);
          if (item) {
            item.dataset.folder = actualFolderId;
            if (currentActiveFolderId !== 'all') {
              if (currentActiveFolderId === 'unassigned' && actualFolderId !== '') {
                item.style.display = 'none';
              } else if (currentActiveFolderId !== 'unassigned' && actualFolderId !== currentActiveFolderId) {
                item.style.display = 'none';
              }
            }
          }
          updateFolderCountsFromDOM();
          showToast('✓ Archivo movido a la carpeta');
        } else {
          alert(data.error || 'No se pudo mover el archivo');
        }
      } catch(err) {
        alert('Error de conexión al mover archivo');
      }
    });
  });
});

function updateFolderCountsFromDOM() {
  const counts = { all: 0, unassigned: 0 };
  document.querySelectorAll('.media-grid .media-item').forEach(it => {
    counts.all++;
    const fId = it.dataset.folder || '';
    if (!fId) counts.unassigned++;
    else counts[fId] = (counts[fId] || 0) + 1;
  });

  const cntAll = document.getElementById('mf-cnt-all');
  if (cntAll) cntAll.textContent = counts.all;
  const cntUn = document.getElementById('mf-cnt-unassigned');
  if (cntUn) cntUn.textContent = counts.unassigned;

  document.querySelectorAll('.media-folder-row.mf-user-folder').forEach(r => {
    const fId = r.dataset.folderId;
    const cntEl = document.getElementById('mf-cnt-' + fId);
    if (cntEl) cntEl.textContent = counts[fId] || 0;
  });
}

async function openNewFolderModal(parentId = null, parentName = '') {
  const promptMsg = parentName ? `Nombre para la subcarpeta en "${parentName}":` : 'Nombre de la nueva carpeta:';
  const name = prompt(promptMsg);
  if (!name || name.trim() === '') return;

  const fd = new FormData();
  fd.append('action', 'create_folder');
  fd.append('name', name.trim());
  if (parentId) fd.append('parent_id', parentId);
  fd.append('csrf', <?= json_encode($csrf) ?>);

  try {
    const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Error al crear la carpeta');
    }
  } catch(e) {
    alert('Error al conectar con el servidor');
  }
}

async function renameFolderPrompt(id, currentName) {
  const newName = prompt('Nuevo nombre para la carpeta:', currentName);
  if (!newName || newName.trim() === '') return;

  const fd = new FormData();
  fd.append('action', 'rename_folder');
  fd.append('id', id);
  fd.append('name', newName.trim());
  fd.append('csrf', <?= json_encode($csrf) ?>);

  try {
    const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Error al renombrar');
    }
  } catch(e) {
    alert('Error de conexión');
  }
}

async function deleteFolderPrompt(id, name) {
  if (!confirm(`¿Eliminar la carpeta "${name}"?\n(Los archivos permanecerán en la biblioteca)`)) return;

  const fd = new FormData();
  fd.append('action', 'delete_folder');
  fd.append('id', id);
  fd.append('csrf', <?= json_encode($csrf) ?>);

  try {
    const res = await fetch('<?= base_url() ?>/plugins/media-folders/api.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      location.reload();
    } else {
      alert(data.error || 'Error al eliminar');
    }
  } catch(e) {
    alert('Error de conexión');
  }
}

// ESC closes modal
window.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('media-modal').style.display !== 'none') {
    closeMediaModal();
  }
});

// Drag & drop upload
document.body.addEventListener('dragover', e => { e.preventDefault(); document.body.style.outline = '3px dashed var(--accent)'; });
document.body.addEventListener('dragleave', () => { document.body.style.outline = ''; });
document.body.addEventListener('drop', e => {
  e.preventDefault();
  document.body.style.outline = '';
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const form  = document.getElementById('upload-form');
  const input = document.createElement('input');
  input.type  = 'file';
  input.name  = 'upload';
  input.style.display = 'none';
  const dt = new DataTransfer();
  dt.items.add(file);
  form.querySelectorAll('input[type=file]').forEach(i => i.remove());
  form.appendChild(input);
  input.files = dt.files;
  form.submit();
});
</script>
</body></html>
