<div style="background:var(--surface);border:1px solid var(--border);border-top:3px solid var(--accent);padding:1.5rem">
  <h1 style="font-family:var(--font);font-size:1.3rem;font-weight:700;text-transform:uppercase;margin-bottom:0.35rem">
    Resultados: <span style="color:var(--accent)"><?= htmlspecialchars($query) ?></span>
  </h1>
  <p style="color:var(--muted);font-size:0.85rem;margin-bottom:1.5rem"><?= count($results) ?> resultado<?= count($results) !== 1 ? 's' : '' ?></p>

  <?php if (empty($results)): ?>
  <p style="color:var(--muted)">No se encontraron resultados para "<?= htmlspecialchars($query) ?>". <a href="<?= $base ?>/" style="color:var(--accent)">Volver al inicio</a>.</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:1rem">
    <?php foreach ($results as $item):
      $item_url = $base . '/' . (($item['_type'] ?? '') === 'articles' ? 'article' : 'page') . '/' . $item['slug'];
    ?>
    <div style="padding-bottom:1rem;border-bottom:1px solid var(--border)">
      <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:0.3rem">
        <?= ($item['_type'] ?? '') === 'articles' ? 'Artículo' : 'Página' ?>
        · <?= format_date($item['created_at'] ?? 'now') ?>
      </div>
      <h2 style="font-family:var(--font);font-size:1.1rem;font-weight:700;margin-bottom:0.35rem">
        <a href="<?= $item_url ?>" style="color:var(--text);text-decoration:none"><?= htmlspecialchars($item['title'] ?? 'Sin título') ?></a>
      </h2>
      <?php $exc = $item['excerpt'] ?: substr(strip_tags($item['content'] ?? ''), 0, 200); ?>
      <?php if ($exc): ?>
      <p style="color:var(--text2);font-size:0.875rem;line-height:1.6"><?= htmlspecialchars($exc) ?>…</p>
      <?php endif; ?>
      <a href="<?= $item_url ?>" style="color:var(--accent);font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;text-decoration:none">Leer más →</a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
