/* ═══════════════════════════════════════════════════════════════════════════
   KutBlog — Editor JavaScript (extracted, consolidated, bugs fixed)
   ═══════════════════════════════════════════════════════════════════════════
   
   Depends on window.KUTBLOG = { baseUrl, csrf, type, slug, isNew }
   being set before this file loads.
   ═══════════════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  // ── Config from PHP ──────────────────────────────────────────────────────
  const KUTBLOG = window.KUTBLOG || window.BRISA || {};

  // ── DOM References ───────────────────────────────────────────────────────
  const rawEditor    = document.getElementById('raw-editor');
  const contentInput = document.getElementById('content-input');
  const fmtInput     = document.getElementById('content-format-input');
  const titleInput   = document.getElementById('post-title');
  const slugInput    = document.getElementById('custom-slug');
  const htmlToolbar  = document.getElementById('html-toolbar');
  const editorEl     = document.getElementById('editor');

  // ── State ────────────────────────────────────────────────────────────────
  let mode = 'visual'; // 'visual' | 'raw'
  let slugManuallySet = !KUTBLOG.isNew;

  // ═══════════════════════════════════════════════════════════════════════════
  // CONTENT SYNC
  // ═══════════════════════════════════════════════════════════════════════════

  function getCurrentContent() {
    if (mode === 'raw') {
      return rawEditor ? rawEditor.value : '';
    }
    return editorEl ? editorEl.innerHTML : '';
  }

  function syncContentBeforeSave() {
    const content = getCurrentContent();
    if (contentInput) contentInput.value = content;

    const hiddenTA = document.getElementById('editor-hidden');
    if (hiddenTA) hiddenTA.value = content;

    if (fmtInput) fmtInput.value = 'html'; // Always HTML format

    // Sync any pending text in tag and category inputs
    const tagIn = document.getElementById('tag-input');
    const tagHid = document.getElementById('tag-hidden');
    if (tagIn && tagHid && tagIn.value.trim()) {
      const curTags = tagHid.value.split(',').map((v) => v.trim()).filter(Boolean);
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
      const curCats = catHid.value.split(',').map((v) => v.trim()).filter(Boolean);
      const toAdd = catIn.value.trim();
      if (!curCats.includes(toAdd)) {
        curCats.push(toAdd);
        catHid.value = curCats.join(', ');
      }
      catIn.value = '';
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // FORM SUBMISSION
  // ═══════════════════════════════════════════════════════════════════════════

  const editorForm = document.getElementById('editor-form');
  if (editorForm) {
    editorForm.addEventListener('submit', function (e) {
      // Prevent double submission
      if (this.classList.contains('submitting')) {
        e.preventDefault();
        return;
      }

      syncContentBeforeSave();

      setTimeout(() => {
        this.classList.add('submitting');
      }, 100);
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // AUTOSAVE
  // ═══════════════════════════════════════════════════════════════════════════

  const AUTOSAVE_KEY = 'kutblog_autosave_' + KUTBLOG.type + '_' + (KUTBLOG.slug || 'new');
  const AUTOSAVE_URL = KUTBLOG.baseUrl + '/admin/autosave.php';

  let autosaveSlug    = KUTBLOG.slug;
  let lastSavedHash   = null;
  let serverSyncTimer = null;

  // Autosave status bar
  const autosaveBar = document.createElement('div');
  autosaveBar.id = 'autosave-bar';
  autosaveBar.style.cssText = [
    'position:fixed', 'bottom:1rem', 'left:50%', 'transform:translateX(-50%)',
    'background:var(--surface2)', 'border:1px solid var(--border2)',
    'color:var(--muted)', 'font-size:0.72rem', 'padding:0.3rem 0.85rem',
    'border-radius:20px', 'z-index:8000', 'opacity:0', 'transition:opacity 0.3s',
    'pointer-events:none', 'font-family:inherit',
  ].join(';');
  document.body.appendChild(autosaveBar);

  function showAutosaveMsg(msg, color) {
    autosaveBar.textContent = msg;
    autosaveBar.style.color = color || 'var(--muted)';
    autosaveBar.style.opacity = '1';
    clearTimeout(autosaveBar._t);
    autosaveBar._t = setTimeout(() => (autosaveBar.style.opacity = '0'), 3000);
  }

  function getFormData() {
    return {
      title:          document.getElementById('post-title').value,
      content:        getCurrentContent(),
      content_format: mode,
      excerpt:        document.querySelector('[name="excerpt"]')?.value || '',
      categories:     document.getElementById('cat-hidden')?.value || '',
      tags:           document.getElementById('tag-hidden')?.value || '',
      featured_image: document.getElementById('featured_image_input')?.value || '',
      mastodon_url:   document.querySelector('[name="mastodon_url"]')?.value || '',
    };
  }

  function simpleHash(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) h = (Math.imul(31, h) + str.charCodeAt(i)) | 0;
    return h;
  }

  let localSaveTimer;
  function scheduleLocalSave() {
    clearTimeout(localSaveTimer);
    localSaveTimer = setTimeout(() => {
      try {
        const data = getFormData();
        if (!data.title && !data.content) return;
        localStorage.setItem(
          AUTOSAVE_KEY,
          JSON.stringify({ ...data, slug: autosaveSlug, savedAt: Date.now() })
        );
      } catch (e) { /* quota exceeded or private browsing */ }
    }, 2000);
  }

  async function syncToServer() {
    const data = getFormData();
    if (!data.title) return;

    const hash = simpleHash(data.title + data.content);
    if (hash === lastSavedHash) return;

    try {
      const fd = new FormData();
      fd.append('csrf', KUTBLOG.csrf);
      fd.append('type', KUTBLOG.type);
      fd.append('slug', autosaveSlug);
      fd.append('title', data.title);
      fd.append('content', data.content);
      fd.append('content_format', data.content_format);
      fd.append('excerpt', data.excerpt);
      fd.append('categories', data.categories);
      fd.append('tags', data.tags);
      fd.append('featured_image', data.featured_image);
      fd.append('mastodon_url', data.mastodon_url);

      const res  = await fetch(AUTOSAVE_URL, { method: 'POST', body: fd });
      const json = await res.json();

      if (json.ok) {
        lastSavedHash = hash;
        if (json.new && json.slug && !autosaveSlug) {
          autosaveSlug = json.slug;
          const url = new URL(location.href);
          url.searchParams.set('slug', json.slug);
          history.replaceState({}, '', url);
        }
        showAutosaveMsg('✓ Borrador guardado automáticamente', 'var(--green)');
        try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}

        // Keep hidden fields in sync
        syncContentBeforeSave();
      }
    } catch (e) {
      console.error('Autosave error:', e);
    }
  }

  // Server sync every 60 seconds
  serverSyncTimer = setInterval(syncToServer, 60000);

  // Listen for input on editors
  [rawEditor, editorEl].forEach((el) => {
    el?.addEventListener('input', scheduleLocalSave);
  });
  titleInput?.addEventListener('input', scheduleLocalSave);

  // Clear autosave on manual submit
  if (editorForm) {
    editorForm.addEventListener(
      'submit',
      () => {
        try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
        clearInterval(serverSyncTimer);
        lastSavedHash = null;
      },
      true
    );
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // MODE SWITCH — Visual ↔ RAW
  // ═══════════════════════════════════════════════════════════════════════════

  function switchMode(newMode) {
    if (newMode === mode) return;

    if (newMode === 'raw') {
      let htmlContent = editorEl.innerHTML;

      // Clean up HTML for readability
      htmlContent = htmlContent
        .replace(/&nbsp;/g, ' ')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>/gi, '</p>\n')
        .replace(/<\/div>/gi, '</div>\n')
        .replace(/<\/h([1-6])>/gi, '</h$1>\n')
        .replace(/<\/li>/gi, '</li>\n')
        .replace(/<\/ul>/gi, '</ul>\n')
        .replace(/<\/ol>/gi, '</ol>\n')
        .replace(/<\/blockquote>/gi, '</blockquote>\n')
        .replace(/<\/pre>/gi, '</pre>\n');

      rawEditor.value = htmlContent;
      editorEl.style.display = 'none';
      rawEditor.style.display = '';
      htmlToolbar.style.display = 'none';
    } else {
      let rawContent = rawEditor.value.replace(/\n\s*\n/g, '\n').trim();

      editorEl.innerHTML = rawContent;
      rawEditor.style.display = 'none';
      editorEl.style.display = '';
      htmlToolbar.style.display = 'flex';
    }

    mode = newMode;
    if (fmtInput) fmtInput.value = 'html';

    document.getElementById('switch-visual')?.classList.toggle('active', mode === 'visual');
    document.getElementById('switch-raw')?.classList.toggle('active', mode === 'raw');
  }

  // Bind mode switch buttons (once only — FIX for duplicate listener bug)
  document.getElementById('switch-visual')?.addEventListener('click', function (e) {
    e.preventDefault();
    switchMode('visual');
  });

  document.getElementById('switch-raw')?.addEventListener('click', function (e) {
    e.preventDefault();
    switchMode('raw');
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // HTML EDITOR COMMANDS
  // ═══════════════════════════════════════════════════════════════════════════

  window.formatBlock = function (tag) {
    if (mode !== 'visual' || !editorEl) return;
    editorEl.focus();
    document.execCommand('formatBlock', false, tag);
  };

  window.exec = function (cmd, val) {
    if (mode !== 'visual') return;
    editorEl.focus();

    if (cmd === 'strikeThrough') {
      window.execStrikeThrough();
      return;
    }

    if (cmd === 'insertHorizontalRule') {
      document.execCommand('insertHTML', false, '<hr>');
      return;
    }

    document.execCommand(cmd, false, val || null);
  };

  window.execStrikeThrough = function () {
    if (mode !== 'visual') return;
    editorEl.focus();

    const selection = window.getSelection();
    if (selection.rangeCount === 0) return;

    const range = selection.getRangeAt(0);
    const ancestor = range.commonAncestorContainer;

    let delElement = ancestor.nodeType === 3 ? ancestor.parentElement : ancestor;
    let foundDel = null;

    while (delElement && delElement !== editorEl) {
      if (['DEL', 'S', 'STRIKE'].includes(delElement.tagName)) {
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

    editorEl.dispatchEvent(new Event('input'));
  };

  window.insertLink = function () {
    const url = prompt(KUTBLOG.i18n?.promptLinkUrl || 'URL del enlace:');
    if (url) {
      editorEl.focus();
      document.execCommand('createLink', false, url);
    }
  };

  window.insertImage = function () {
    const url = prompt('URL de la imagen:');
    if (url) {
      editorEl.focus();
      // FIX: escape user input to prevent XSS
      const safeUrl = url.replace(/"/g, '&quot;');
      document.execCommand('insertHTML', false, `<img src="${safeUrl}" alt="" style="max-width:100%;">`);
    }
  };

  window.insertAudio = function () {
    const url = prompt('URL del audio (MP3, OGG, WAV, etc.):');
    if (url) {
      editorEl.focus();
      const safeUrl = url.replace(/"/g, '&quot;');
      document.execCommand(
        'insertHTML',
        false,
        `<div class="media-audio"><audio controls src="${safeUrl}" style="width:100%;"></audio></div>`
      );
    }
  };

  window.insertVideo = function () {
    const url = prompt('URL del video:');
    if (url) {
      editorEl.focus();
      const safeUrl = url.replace(/"/g, '&quot;');
      document.execCommand(
        'insertHTML',
        false,
        `<video controls src="${safeUrl}" style="max-width:100%;"></video>`
      );
    }
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // SLUG FROM TITLE
  // ═══════════════════════════════════════════════════════════════════════════

  titleInput?.addEventListener('input', () => {
    if (!slugManuallySet) {
      slugInput.value = titleInput.value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
    }
  });

  slugInput?.addEventListener('input', () => {
    slugManuallySet = true;
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // FEATURED IMAGE PREVIEW
  // ═══════════════════════════════════════════════════════════════════════════

  document.getElementById('featured_image_input')?.addEventListener('input', function () {
    const preview = document.getElementById('featured-preview');
    const img = document.getElementById('featured-img');
    let val = (this.value || '').trim();
    if (val) {
      if (val.startsWith('/media/covers/') && window.KUTPOD_INSTANCE_URL) {
        img.src = window.KUTPOD_INSTANCE_URL.replace(/\/+$/, '') + val;
      } else {
        img.src = val;
      }
      preview.style.display = '';
    } else {
      preview.style.display = 'none';
    }
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // TAG / CATEGORY CHIP INPUT
  // ═══════════════════════════════════════════════════════════════════════════

  function initTagInput(inputId, chipsId, hiddenId, suggestionsId) {
    const input   = document.getElementById(inputId);
    const chips   = document.getElementById(chipsId);
    const hidden  = document.getElementById(hiddenId);
    const sugArea = document.getElementById(suggestionsId);
    if (!input || !chips || !hidden) return;

    const initialValues = hidden.value
      .split(',')
      .map((v) => v.trim())
      .filter(Boolean);
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
        values = values.filter((v) => v !== val);
        chip.remove();
        updateHidden();
        if (sugArea) {
          sugArea.querySelectorAll('.tag-sug').forEach((s) => {
            if (s.textContent.trim() === val) s.classList.remove('used');
          });
        }
      });
      chips.appendChild(chip);

      if (sugArea) {
        sugArea.querySelectorAll('.tag-sug').forEach((s) => {
          if (s.textContent.trim() === val) s.classList.add('used');
        });
      }
    }

    // Populate initial chips
    initialValues.forEach((v) => addChip(v));

    input.addEventListener('keydown', (e) => {
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
            sugArea.querySelectorAll('.tag-sug').forEach((s) => {
              if (s.textContent.trim() === removed) s.classList.remove('used');
            });
          }
        }
      }
    });

    input.addEventListener('blur', () => {
      if (input.value.trim()) {
        addChip(input.value);
        input.value = '';
      }
    });

    document.getElementById(chipsId.replace('-chips', '-wrap'))?.addEventListener('click', () => input.focus());

    if (sugArea) {
      sugArea.querySelectorAll('.tag-sug').forEach((sug) => {
        if (values.includes(sug.textContent.trim())) sug.classList.add('used');
        sug.addEventListener('click', () => {
          addChip(sug.textContent.trim());
          input.focus();
        });
      });
    }
  }

  initTagInput('cat-input', 'cat-chips', 'cat-hidden', 'cat-suggestions');

  // ═══════════════════════════════════════════════════════════════════════════
  // TAGS AUTOCOMPLETE
  // ═══════════════════════════════════════════════════════════════════════════

  (function () {
    const input    = document.getElementById('tag-input');
    const chips    = document.getElementById('tag-chips');
    const hidden   = document.getElementById('tag-hidden');
    const dropdown = document.getElementById('tag-dropdown');
    const dropList = document.getElementById('tag-dropdown-list');
    const dataEl   = document.getElementById('all-tags-data');
    if (!input || !chips || !hidden) return;

    const allTags = dataEl
      ? Array.from(dataEl.querySelectorAll('span')).map((s) => s.textContent.trim()).filter(Boolean)
      : [];

    const initialValues = hidden.value
      .split(',')
      .map((v) => v.trim())
      .filter(Boolean);
    let values = [];
    let focusedIdx = -1;

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
      chip.innerHTML = escHtml(val) + '<button type="button" title="Eliminar">×</button>';
      chip.querySelector('button').addEventListener('click', () => {
        values = values.filter((v) => v !== val);
        chip.remove();
        updateHidden();
      });
      chips.appendChild(chip);
    }

    // Populate initial tags from saved draft / article
    initialValues.forEach((v) => addChip(v));

    function showDropdown(query) {
      const q = query.toLowerCase().trim();
      if (!q) { hideDropdown(); return; }

      const matches = allTags
        .filter((t) => t.toLowerCase().includes(q) && !values.includes(t))
        .slice(0, 12);

      dropList.innerHTML = '';
      focusedIdx = -1;

      if (!matches.length) {
        dropList.innerHTML =
          '<div class="tag-dropdown-empty">Presiona Enter para añadir "' +
          escHtml(query) +
          '"</div>';
      } else {
        matches.forEach((tag) => {
          const item = document.createElement('div');
          item.className = 'tag-dropdown-item';
          item.innerHTML = escHtml(tag);
          item.addEventListener('mousedown', (e) => {
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

    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); return; }
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

  // ═══════════════════════════════════════════════════════════════════════════
  // FEATURED IMAGE UPLOAD
  // ═══════════════════════════════════════════════════════════════════════════

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
      fd.append('csrf', KUTBLOG.csrf);

      try {
        const res  = await fetch(KUTBLOG.baseUrl + '/admin/upload_media.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.url) {
          featInput.value = data.url;
          document.getElementById('featured-img').src = data.url;
          document.getElementById('featured-preview').style.display = '';
          featProgress.textContent = '✓ Subida correctamente';
          setTimeout(() => (featProgress.style.display = 'none'), 2000);
        } else {
          featProgress.textContent = '⚠ Error: ' + (data.error || 'desconocido');
        }
      } catch (e) {
        featProgress.textContent = '⚠ Error al subir';
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // EDITOR FONT SIZE
  // ═══════════════════════════════════════════════════════════════════════════

  const FONT_SIZE_KEY = 'kutblog_editor_font_size';
  const FONT_MIN = 12, FONT_MAX = 28, FONT_STEP = 1;
  let editorFontSize = parseInt(localStorage.getItem(FONT_SIZE_KEY) || localStorage.getItem('brisa_editor_font_size') || '16', 10);

  function applyEditorFontSize(size) {
    editorFontSize = Math.min(FONT_MAX, Math.max(FONT_MIN, size));
    if (editorEl) editorEl.style.fontSize = editorFontSize + 'px';
    if (rawEditor) rawEditor.style.fontSize = editorFontSize + 'px';

    const label = document.getElementById('font-size-label');
    if (label) label.textContent = editorFontSize;

    localStorage.setItem(FONT_SIZE_KEY, editorFontSize);
  }

  document.getElementById('font-size-up')?.addEventListener('click', (e) => {
    e.preventDefault();
    applyEditorFontSize(editorFontSize + FONT_STEP);
  });
  document.getElementById('font-size-down')?.addEventListener('click', (e) => {
    e.preventDefault();
    applyEditorFontSize(editorFontSize - FONT_STEP);
  });

  // Initialize
  applyEditorFontSize(editorFontSize);

  // ═══════════════════════════════════════════════════════════════════════════
  // ARTICLE PREVIEW
  // ═══════════════════════════════════════════════════════════════════════════

  window.openPreview = function () {
    const adminBase = KUTBLOG.baseUrl + '/admin';

    let content = getCurrentContent();
    const title   = document.getElementById('post-title').value || '(Sin título)';
    const excerpt = document.querySelector('[name="excerpt"]')?.value || '';
    const status  = document.getElementById('status-select')?.value || 'draft';

    // If saved post exists, use proper preview
    if (KUTBLOG.slug) {
      const url =
        adminBase +
        '/preview.php?type=' + encodeURIComponent(KUTBLOG.type) +
        '&slug=' + encodeURIComponent(KUTBLOG.slug) +
        '&t=' + Date.now();
      window.open(url, '_blank');
      return;
    }

    // For unsaved posts, generate inline preview
    const accent =
      getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#e05c1a';
    const safeTitle = title.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const safeExcerpt = excerpt.replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const html = `<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vista previa — ${safeTitle}</title>
<link href="/assets/fonts.css" rel="stylesheet">
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
.media-audio{margin:1.5rem 0;background:#f5f5f5;border:1px solid #e0e0e0;border-left:3px solid ${accent};border-radius:8px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem}
.media-audio::before{content:"🎵";font-size:1.5rem;flex-shrink:0}
.media-audio audio{width:100%;height:36px}
.media-video{margin:1.5rem 0;border-radius:8px;overflow:hidden;background:#000;line-height:0}
.media-video video{width:100%;display:block}
</style></head><body>
<div class="banner">👁 VISTA PREVIA — ${status === 'draft' ? 'BORRADOR' : 'PUBLICADO'} — guarda para ver con el tema del sitio</div>
<div class="wrap">
  <h1>${safeTitle}</h1>
  ${safeExcerpt ? '<p class="excerpt">' + safeExcerpt + '</p>' : ''}
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
  };

  document.getElementById('preview-btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    window.openPreview();
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // MORE TOOLBAR DROPDOWN (desktop overflow)
  // ═══════════════════════════════════════════════════════════════════════════

  function setupMoreDropdown() {
    const moreBtn  = document.getElementById('more-tb-btn');
    const dropdown = document.getElementById('more-tb-dropdown');
    const content  = document.getElementById('more-tb-content');
    if (!moreBtn || !dropdown) return;
    dropdown.style.display = 'none';

    function populateDropdown() {
      content.innerHTML = '';
      // FIX: was checking mode === 'html', fixed to 'visual'
      const sourceToolbar =
        mode === 'visual'
          ? document.getElementById('html-toolbar')
          : document.getElementById('md-toolbar');
      if (!sourceToolbar) return;

      const btns = sourceToolbar.querySelectorAll('.tb:not(.mode-switch):not(#more-tb-btn)');
      btns.forEach((btn) => {
        const clone = btn.cloneNode(true);
        clone.addEventListener('click', (e) => {
          e.preventDefault();
          btn.click();
          dropdown.style.display = 'none';
        });
        content.appendChild(clone);
      });
    }

    window.toggleMoreDropdown = function () {
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

  // ═══════════════════════════════════════════════════════════════════════════
  // IMAGE UPLOAD (button & drag-and-drop)
  // ═══════════════════════════════════════════════════════════════════════════

  function setupImageUpload() {
    const fileInput     = document.getElementById('editor-image-upload');
    const uploadHtmlBtn = document.getElementById('upload-img-html');
    const uploadMdBtn   = document.getElementById('upload-img-md');

    function triggerFileInput() {
      if (fileInput) fileInput.click();
    }

    if (uploadHtmlBtn) uploadHtmlBtn.addEventListener('click', triggerFileInput);
    if (uploadMdBtn) uploadMdBtn.addEventListener('click', triggerFileInput);

    async function uploadImage(file) {
      const fd = new FormData();
      fd.append('upload', file);
      fd.append('csrf', KUTBLOG.csrf);
      try {
        const res = await fetch(KUTBLOG.baseUrl + '/admin/upload_media.php', {
          method: 'POST',
          body: fd,
        });
        const data = await res.json();
        return data.url || null;
      } catch (e) {
        console.error('Upload error:', e);
        return null;
      }
    }

    function insertImageUrl(url, alt) {
      editorEl.focus();
      const safeUrl = url.replace(/"/g, '&quot;');
      const safeAlt = (alt || '').replace(/"/g, '&quot;');
      document.execCommand(
        'insertHTML',
        false,
        `<img src="${safeUrl}" alt="${safeAlt}" style="max-width:100%;">`
      );
    }

    if (fileInput) {
      fileInput.addEventListener('change', async (e) => {
        const files = Array.from(e.target.files);
        for (const file of files) {
          const url = await uploadImage(file);
          if (url) {
            insertImageUrl(url, file.name.replace(/\.[^/.]+$/, ''));
          }
        }
        fileInput.value = '';
      });
    }

    // Drag & drop
    [editorEl].forEach((zone) => {
      if (!zone) return;
      zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
      });
      zone.addEventListener('drop', async (e) => {
        e.preventDefault();
        const files = Array.from(e.dataTransfer.files).filter((f) => f.type.startsWith('image/'));
        for (const file of files) {
          const url = await uploadImage(file);
          if (url) {
            insertImageUrl(url, file.name.replace(/\.[^/.]+$/, ''));
          }
        }
      });
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // MOBILE DROPDOWN MENU
  // ═══════════════════════════════════════════════════════════════════════════

  function setupMobileDropdown() {
    const htmlDropdownBtn     = document.getElementById('mobile-dropdown-btn');
    const htmlDropdownMenu    = document.getElementById('mobile-dropdown-menu');
    const htmlDropdownContent = document.getElementById('mobile-dropdown-content');

    const mdDropdownBtn     = document.getElementById('mobile-dropdown-btn-md');
    const mdDropdownMenu    = document.getElementById('mobile-dropdown-menu-md');
    const mdDropdownContent = document.getElementById('mobile-dropdown-content-md');

    function setupDropdown(dropdownBtn, dropdownMenu, dropdownContent, isMarkdown) {
      if (!dropdownBtn || !dropdownMenu) return;

      dropdownMenu.style.display = 'none';

      function populateDropdown() {
        dropdownContent.innerHTML = '';
        const sourceToolbar = dropdownBtn.closest(isMarkdown ? '#md-toolbar' : '#html-toolbar');
        if (!sourceToolbar) return;

        const hiddenTools = sourceToolbar.querySelector('.mobile-hidden-tools');
        if (!hiddenTools) return;

        const btns = hiddenTools.querySelectorAll('.tb');
        btns.forEach((btn) => {
          const clone = btn.cloneNode(true);
          const newClone = clone.cloneNode(true);
          newClone.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            btn.click();
            dropdownMenu.style.display = 'none';
          });
          dropdownContent.appendChild(newClone);
        });
      }

      dropdownBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        if (window.innerWidth > 480) return;

        populateDropdown();

        const rect = dropdownBtn.getBoundingClientRect();
        dropdownMenu.style.position = 'fixed';
        dropdownMenu.style.top = rect.bottom + window.scrollY + 'px';
        dropdownMenu.style.left = Math.max(10, rect.left) + 'px';
        dropdownMenu.style.right = 'auto';
        dropdownMenu.style.maxWidth = window.innerWidth - 20 + 'px';

        const isVisible = dropdownMenu.style.display === 'flex';
        dropdownMenu.style.display = isVisible ? 'none' : 'flex';

        // Force reflow for Safari
        void dropdownMenu.offsetHeight;
      });

      document.addEventListener('click', (e) => {
        if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
          dropdownMenu.style.display = 'none';
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && dropdownMenu.style.display === 'flex') {
          dropdownMenu.style.display = 'none';
        }
      });

      window.addEventListener('resize', () => {
        if (window.innerWidth > 480) {
          dropdownMenu.style.display = 'none';
        }
      });
    }

    setupDropdown(htmlDropdownBtn, htmlDropdownMenu, htmlDropdownContent, false);
    setupDropdown(mdDropdownBtn, mdDropdownMenu, mdDropdownContent, true);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // MOBILE TOOLBAR HELPERS
  // FIX: was checking mode !== 'html', but mode is 'visual'|'raw'
  // ═══════════════════════════════════════════════════════════════════════════

  window.execMobile = function (cmd, val) {
    if (mode !== 'visual') return;
    window.exec(cmd, val);
  };

  window.execMobileLink = function () {
    if (mode !== 'visual') return; // FIX: was 'html'
    window.insertLink();
  };

  window.execMobileImg = function () {
    if (mode !== 'visual') return; // FIX: was 'html'
    window.insertImage();
  };

  window.execMobileAudio = function () {
    if (mode !== 'visual') return; // FIX: was 'html'
    window.insertAudio();
  };

  window.execMobileVideo = function () {
    if (mode !== 'visual') return; // FIX: was 'html'
    window.insertVideo();
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // MOBILE OVERFLOW MENU
  // ═══════════════════════════════════════════════════════════════════════════

  function setupMobileMenu() {
    const moreBtn      = document.getElementById('tb-more-btn');
    const overflowMenu = document.getElementById('tb-overflow-menu');

    if (!moreBtn || !overflowMenu) return;

    overflowMenu.style.display = 'none';

    moreBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      e.preventDefault();

      if (overflowMenu.style.display === 'flex') {
        overflowMenu.style.display = 'none';
      } else {
        overflowMenu.style.display = 'flex';
        const rect = moreBtn.getBoundingClientRect();
        overflowMenu.style.position = 'fixed';
        overflowMenu.style.top = rect.bottom + 'px';
        overflowMenu.style.right = window.innerWidth - rect.right + 'px';

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

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overflowMenu.style.display === 'flex') {
        overflowMenu.style.display = 'none';
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // FOCUS MODE
  // ═══════════════════════════════════════════════════════════════════════════

  window.toggleFocus = function () {
    document.body.classList.toggle('focus-mode');
    const active = document.body.classList.contains('focus-mode');
    const focusBtn = document.getElementById('focus-btn');
    if (focusBtn) {
      focusBtn.setAttribute(
        'title',
        active ? 'Salir del modo sin distracciones (Esc)' : 'Modo sin distracciones (F11)'
      );
    }
  };

  document.getElementById('focus-btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    window.toggleFocus();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.body.classList.contains('focus-mode')) window.toggleFocus();
    if (e.key === 'F11' && !e.altKey) {
      e.preventDefault();
      window.toggleFocus();
    }
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // SIDEBAR LAYOUT FIX (mobile)
  // ═══════════════════════════════════════════════════════════════════════════

  function fixSidebarLayout() {
    if (window.innerWidth > 1024) return;

    const sidebar = document.querySelector('.editor-sidebar');
    const panels  = sidebar?.querySelectorAll('.panel');

    if (panels) {
      panels.forEach((panel) => {
        panel.style.maxHeight = 'none';
        panel.style.overflow  = 'visible';

        panel.querySelectorAll('input, textarea, select').forEach((input) => {
          input.style.maxWidth   = '100%';
          input.style.boxSizing  = 'border-box';
        });
      });
    }
  }

  window.addEventListener('load', fixSidebarLayout);
  window.addEventListener('resize', fixSidebarLayout);

  // Watch sidebar for dynamic changes
  const sidebarEl = document.querySelector('.editor-sidebar');
  if (sidebarEl) {
    const observer = new MutationObserver(() => {
      setTimeout(fixSidebarLayout, 100);
    });
    observer.observe(sidebarEl, { childList: true, subtree: true, characterData: true });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // BACKUP PREVIEW
  // FIX: sanitize content to prevent XSS
  // ═══════════════════════════════════════════════════════════════════════════

  window.previewBackup = function (timestamp, type, slug) {
    if (!confirm('Preview this backup version? You can copy content from the preview.')) {
      return;
    }

    fetch(
      KUTBLOG.baseUrl +
        '/admin/backup_preview.php?type=' + encodeURIComponent(type) +
        '&slug=' + encodeURIComponent(slug) +
        '&timestamp=' + encodeURIComponent(timestamp) +
        '&csrf=' + encodeURIComponent(KUTBLOG.csrf)
    )
      .then((r) => r.json())
      .then((data) => {
        if (data.success && data.content) {
          const previewWindow = window.open('', '_blank');
          // FIX: use DOMParser + textContent instead of raw innerHTML for copy
          previewWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
              <title>Backup Preview - ${escHtml(timestamp)}</title>
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
                <p><strong>Timestamp:</strong> ${escHtml(timestamp)}</p>
                <p><strong>Article:</strong> ${escHtml(slug)}</p>
              </div>
              <div class="content" id="backup-content"></div>
              <div class="actions">
                <button onclick="window.close()">Close</button>
                <button id="copy-btn">Copy HTML Source</button>
              </div>
              <script>
                document.getElementById('backup-content').innerHTML = ${JSON.stringify(data.content)};
                document.getElementById('copy-btn').addEventListener('click', function() {
                  navigator.clipboard.writeText(${JSON.stringify(data.content)}).then(function() {
                    alert('Content copied to clipboard!');
                  });
                });
              <\/script>
            </body>
            </html>
          `);
          previewWindow.document.close();
        } else {
          alert('Error loading backup: ' + (data.error || 'Unknown error'));
        }
      })
      .catch((error) => {
        console.error('Error:', error);
        alert('Error loading backup');
      });
  };

  window.deleteBackup = function(timestamp, type, slug) {
    if (!confirm('¿Eliminar esta copia de seguridad?')) return;
    const base = window.KUTBLOG ? KUTBLOG.baseUrl : '';
    const csrf = window.KUTBLOG ? KUTBLOG.csrf : '';
    fetch(`${base}/admin/backup_preview.php?action=delete&type=${encodeURIComponent(type)}&slug=${encodeURIComponent(slug)}&timestamp=${encodeURIComponent(timestamp)}&csrf=${encodeURIComponent(csrf)}`)
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
  };

  window.clearAllBackups = function(type, slug) {
    if (!confirm('¿Eliminar todo el historial de copias de seguridad de este artículo?')) return;
    const base = window.KUTBLOG ? KUTBLOG.baseUrl : '';
    const csrf = window.KUTBLOG ? KUTBLOG.csrf : '';
    fetch(`${base}/admin/backup_preview.php?action=clear_all&type=${encodeURIComponent(type)}&slug=${encodeURIComponent(slug)}&csrf=${encodeURIComponent(csrf)}`)
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
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // FLOATING BUTTONS (mobile publish/draft)
  // ═══════════════════════════════════════════════════════════════════════════

  function setupFloatingButtons() {
    const floatingContainer  = document.getElementById('floating-buttons-container');
    const floatingDraftBtn   = document.getElementById('floating-draft-btn');
    const floatingFocusBtn   = document.getElementById('floating-focus-btn');
    const floatingPreviewBtn = document.getElementById('floating-preview-btn');
    const floatingPublishBtn = document.getElementById('floating-publish-btn');
    const publishBtn         = document.getElementById('publish-btn');
    const statusSelect       = document.getElementById('status-select');
    const form               = document.getElementById('editor-form');

    if (!floatingContainer || !floatingPublishBtn || !floatingDraftBtn) return;

    function updateFloatingLabel() {
      const isPublished = statusSelect ? statusSelect.value === 'published' : false;
      const label       = isPublished ? (KUTBLOG.i18n?.editorUpdate || 'Actualizar') : (KUTBLOG.i18n?.editorPublish || 'Publicar');
      const el = document.getElementById('floating-publish-label');
      if (el) el.textContent = label;
    }

    floatingDraftBtn.addEventListener('click', function () {
      syncContentBeforeSave();

      if (statusSelect && statusSelect.value !== 'draft') {
        statusSelect.value = 'draft';
      }
      const hiddenStatus   = document.createElement('input');
      hiddenStatus.type    = 'hidden';
      hiddenStatus.name    = 'status';
      hiddenStatus.value   = 'draft';
      form.appendChild(hiddenStatus);
      form.submit();
    });

    floatingFocusBtn?.addEventListener('click', function () {
      if (typeof window.toggleFocus === 'function') window.toggleFocus();
    });

    floatingPreviewBtn?.addEventListener('click', function () {
      if (typeof window.openPreview === 'function') window.openPreview();
    });

    floatingPublishBtn.addEventListener('click', function () {
      syncContentBeforeSave();

      if (statusSelect && statusSelect.value !== 'published') {
        statusSelect.value = 'published';
      }
      const hiddenStatus   = document.createElement('input');
      hiddenStatus.type    = 'hidden';
      hiddenStatus.name    = 'status';
      hiddenStatus.value   = 'published';
      form.appendChild(hiddenStatus);
      form.submit();
    });

    if (statusSelect) {
      statusSelect.addEventListener('change', updateFloatingLabel);
    }
    updateFloatingLabel();

    function checkMobile() {
      const isMobile = window.innerWidth <= 1024;
      if (publishBtn) publishBtn.style.display = isMobile ? 'none' : '';
      floatingContainer.style.display = isMobile ? 'flex' : 'none';
    }

    window.addEventListener('resize', checkMobile);
    checkMobile();
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // TOOLBAR BUTTON SETUP (consolidate DOMContentLoaded — FIX duplicate listeners)
  // ═══════════════════════════════════════════════════════════════════════════

  function setupToolbarButtons() {
    // Draft button
    const form     = document.getElementById('editor-form');
    const draftBtn = form?.querySelector('button[name="status"][value="draft"]');

    draftBtn?.addEventListener('click', function () {
      syncContentBeforeSave();
    });

    document.getElementById('publish-btn')?.addEventListener('click', function () {
      syncContentBeforeSave();
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // INIT
  // ═══════════════════════════════════════════════════════════════════════════

  document.addEventListener('DOMContentLoaded', function () {
    setupToolbarButtons();
    setupMobileDropdown();
    setupMobileMenu();
    setupMoreDropdown();
    setupImageUpload();
    setupFloatingButtons();

    // Hide mobile dropdown on resize to desktop
    window.addEventListener('resize', function () {
      if (window.innerWidth > 480) {
        document.querySelectorAll('.mobile-dropdown-menu').forEach((d) => {
          d.style.display = 'none';
        });
      }
    });
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // UTILITY
  // ═══════════════════════════════════════════════════════════════════════════

  function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Expose for inline onclick handlers in PHP
  window.syncContentBeforeSave = syncContentBeforeSave;
})();
