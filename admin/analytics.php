<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/analytics.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

$range = in_array($_GET['range'] ?? '', ['today', '7d', '30d', 'all']) ? $_GET['range'] : '30d';
$filterDate = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : null;
$stats = analytics_get_stats($range, $filterDate);

// Helper to format seconds into m:ss
function format_dwell(int $sec): string {
    if ($sec <= 0) return '—';
    $m = floor($sec / 60);
    $s = $sec % 60;
    if ($m > 0) return "{$m}m " . sprintf('%02ds', $s);
    return "{$s}s";
}

// Title & kind resolver for paths
function resolve_path_info(string $path): array {
    $clean = trim($path, '/');
    if ($clean === '') {
        return ['title' => 'Página Principal', 'kind' => 'Inicio'];
    }
    if (str_starts_with($path, '/article/')) {
        $slug = trim(substr($path, 9), '/');
        $post = get_content('articles', $slug);
        return ['title' => ($post && !empty($post['title'])) ? $post['title'] : $slug, 'kind' => 'Artículo'];
    }
    if (str_starts_with($path, '/page/')) {
        $slug = trim(substr($path, 6), '/');
        $page = get_content('pages', $slug);
        return ['title' => ($page && !empty($page['title'])) ? $page['title'] : $slug, 'kind' => 'Página'];
    }
    if (str_starts_with($path, '/category/')) {
        return ['title' => 'Categoría: ' . urldecode(trim(substr($path, 10), '/')), 'kind' => 'Categoría'];
    }
    if (str_starts_with($path, '/tag/')) {
        return ['title' => 'Etiqueta: #' . urldecode(trim(substr($path, 5), '/')), 'kind' => 'Etiqueta'];
    }
    // Fallback: try direct slug lookup in articles or pages
    if (!str_contains($clean, '/')) {
        $post = get_content('articles', $clean);
        if ($post && !empty($post['title'])) {
            return ['title' => $post['title'], 'kind' => 'Artículo'];
        }
        $page = get_content('pages', $clean);
        if ($page && !empty($page['title'])) {
            return ['title' => $page['title'], 'kind' => 'Página'];
        }
    }
    return ['title' => $path, 'kind' => 'Ruta'];
}

admin_header('Estadísticas', 'analytics');
?>
      <div style="display:flex; align-items:center; gap:0.4rem;">
        <span style="font-size:0.8rem; color:var(--muted); margin-right:0.4rem;">Período:</span>
        <div style="display:inline-flex; background:var(--bg3); border-radius:8px; padding:2px; border:1px solid var(--border);">
          <a href="?range=today" class="btn btn-sm <?= $range === 'today' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; padding:4px 10px; font-size:0.75rem;">Hoy</a>
          <a href="?range=7d" class="btn btn-sm <?= $range === '7d' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; padding:4px 10px; font-size:0.75rem;">7 días</a>
          <a href="?range=30d" class="btn btn-sm <?= $range === '30d' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; padding:4px 10px; font-size:0.75rem;">30 días</a>
          <a href="?range=all" class="btn btn-sm <?= $range === 'all' ? 'btn-primary' : 'btn-secondary' ?>" style="border:none; padding:4px 10px; font-size:0.75rem;">Histórico</a>
        </div>
      </div>
    </div>
  </div>

  <div class="page-body">
    <!-- Notice badge: Zero cookies, privacy compliant -->
    <div style="display:flex; align-items:center; justify-content:space-between; background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:0.75rem 1.25rem; margin-bottom:1.5rem;">
      <div style="display:flex; align-items:center; gap:0.6rem; font-size:0.85rem; color:var(--text2);">
        <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:rgba(34,197,94,0.15); color:#22c55e; font-weight:700; font-size:0.75rem;">✓</span>
        <span><strong>Analíticas Privadas Nativas:</strong> Sin cookies de rastreo, sin scripts de terceros. Conforme a GDPR y privacidad estricta.</span>
      </div>
      <span style="font-size:0.75rem; color:var(--muted); font-family:var(--font-mono);">KutBlog v<?= CMS_VERSION ?></span>
    </div>

    <!-- 4 KPI Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
      <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--muted); font-weight:600; margin-bottom:0.4rem;">Vistas de Página</div>
        <div style="font-size:1.85rem; font-weight:700; color:var(--text); font-family:var(--font-mono);">
          <?= number_format($stats['total_views']) ?>
        </div>
        <div style="font-size:0.75rem; color:var(--muted); margin-top:0.25rem;">Total acumulado en el período</div>
      </div>

      <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--muted); font-weight:600; margin-bottom:0.4rem;">Lectores Únicos</div>
        <div style="font-size:1.85rem; font-weight:700; color:var(--text); font-family:var(--font-mono);">
          <?= number_format($stats['unique_visitors']) ?>
        </div>
        <div style="font-size:0.75rem; color:var(--muted); margin-top:0.25rem;">Visitantes anónimos identificados</div>
      </div>

      <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--muted); font-weight:600; margin-bottom:0.4rem;">Retención Media (Scroll)</div>
        <div style="font-size:1.85rem; font-weight:700; color:var(--accent); font-family:var(--font-mono);">
          <?= $stats['avg_depth'] ?>%
        </div>
        <div style="font-size:0.75rem; color:var(--muted); margin-top:0.25rem;">Profundidad de lectura promedio</div>
      </div>

      <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.78rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--muted); font-weight:600; margin-bottom:0.4rem;">Tiempo Activo Medio</div>
        <div style="font-size:1.85rem; font-weight:700; color:var(--text); font-family:var(--font-mono);">
          <?= format_dwell($stats['avg_dwell']) ?>
        </div>
        <div style="font-size:0.75rem; color:var(--muted); margin-top:0.25rem;">Lectura activa en pantalla</div>
      </div>
    </div>

    <!-- Trend Chart -->
    <div class="card" style="margin-bottom:1.5rem; padding:1.25rem;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
        <div>
          <h3 class="card-title" style="margin-bottom:0.2rem;">Tendencia de Tráfico</h3>
          <span style="font-size:0.78rem; color:var(--muted);">Evolución de páginas vistas y lectores diarios</span>
        </div>
        <div style="display:flex; align-items:center; gap:0.75rem;">
          <?php if ($filterDate): ?>
            <a href="?range=<?= urlencode($range) ?>" class="btn btn-sm btn-secondary" style="font-size:0.75rem; padding:3px 10px;">
              × Viendo solo <?= htmlspecialchars($filterDate) ?> (ver todo)
            </a>
          <?php endif; ?>
          <div style="display:flex; gap:1rem; font-size:0.75rem; color:var(--muted);">
            <span style="display:flex; align-items:center; gap:0.4rem;">
              <span style="display:inline-block; width:10px; height:10px; background:var(--accent); border-radius:2px;"></span>
              Vistas
            </span>
            <span style="display:flex; align-items:center; gap:0.4rem;">
              <span style="display:inline-block; width:10px; height:10px; background:rgba(99,102,241,0.3); border-radius:2px;"></span>
              Únicos
            </span>
          </div>
        </div>
      </div>

      <?php
      $timeline = $stats['timeline'];
      $maxVal = 1;
      foreach ($timeline as $t) {
          if ($t['views'] > $maxVal) $maxVal = $t['views'];
      }
      ?>

      <?php if (empty($timeline)): ?>
        <div style="padding:3rem 1rem; text-align:center; color:var(--muted);">
          <div style="font-size:2rem; margin-bottom:0.5rem; opacity:0.5;">📈</div>
          <div style="font-size:0.95rem; font-weight:600; color:var(--text);">Aún no hay visitas registradas en este período</div>
          <p style="font-size:0.8rem; margin-top:0.25rem;">Cuando los lectores visiten tu blog, verás las métricas reflejadas aquí en tiempo real.</p>
        </div>
      <?php else: ?>
        <div style="display:flex; align-items:flex-end; gap:6px; height:180px; padding-top:20px; border-bottom:1px solid var(--border);">
          <?php foreach ($timeline as $item): 
            $viewPct = round(($item['views'] / $maxVal) * 100);
            $uniqPct = round(($item['uniques'] / $maxVal) * 100);
            $isSelected = ($filterDate === $item['date']);
            $barUrl = $isSelected ? ('?range=' . urlencode($range)) : ('?range=' . urlencode($range) . '&date=' . urlencode($item['date']));
          ?>
            <a href="<?= htmlspecialchars($barUrl) ?>"
               style="flex:1; display:flex; flex-direction:column; align-items:center; height:100%; justify-content:flex-end; position:relative; text-decoration:none; border-radius:4px; <?= $isSelected ? 'background:rgba(99,102,241,0.14); outline:1px solid var(--accent);' : '' ?>"
               title="<?= htmlspecialchars($item['date']) ?>: <?= $item['views'] ?> vistas, <?= $item['uniques'] ?> únicos (clic para filtrar este día)">
              <div style="width:100%; max-width:24px; display:flex; gap:2px; align-items:flex-end; height:100%;">
                <div style="flex:1; background:var(--accent); height:<?= max(4, $viewPct) ?>%; border-radius:3px 3px 0 0; transition:height 0.2s;"></div>
                <div style="flex:1; background:rgba(99,102,241,0.3); height:<?= max(2, $uniqPct) ?>%; border-radius:3px 3px 0 0; transition:height 0.2s;"></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <div style="display:flex; justify-content:space-between; margin-top:0.5rem; font-size:0.7rem; color:var(--muted); font-family:var(--font-mono);">
          <span><?= htmlspecialchars($timeline[0]['date'] ?? '') ?></span>
          <span>Haz clic en cualquier barra para filtrar por día</span>
          <span><?= htmlspecialchars(end($timeline)['date'] ?? '') ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- 2 Column Section: Top Posts & Traffic Sources -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem; margin-bottom:1.5rem;">
      <!-- Top Articles & Pages -->
      <div class="card">
        <div class="card-header">
          <div>
            <h3 class="card-title">Artículos y Páginas Visitadas</h3>
            <span style="font-size:0.75rem; color:var(--muted);"><?= $filterDate ? 'Detalle del día ' . htmlspecialchars($filterDate) : 'Desglose por artículo con lectura, tiempo y última visita' ?></span>
          </div>
        </div>
        <?php if (empty($stats['top_paths'])): ?>
          <div style="padding:2.5rem 1rem; text-align:center; color:var(--muted); font-size:0.85rem;">
            Sin datos de páginas aún
          </div>
        <?php else: ?>
          <div style="max-height:460px; overflow-y:auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Artículo / Página</th>
                <th style="text-align:right;">Vistas</th>
                <th style="text-align:right;">Tiempo</th>
                <th style="text-align:right;">Scroll</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stats['top_paths'] as $row): 
                $info = resolve_path_info($row['path']);
                $publicHref = rtrim(base_url(), '/') . $row['path'];
              ?>
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:0.4rem; max-width:300px;">
                    <span class="badge" style="background:var(--bg3); border:1px solid var(--border); color:var(--muted); font-size:0.65rem; flex-shrink:0;"><?= htmlspecialchars($info['kind']) ?></span>
                    <a href="<?= htmlspecialchars($publicHref) ?>" target="_blank" rel="noopener" style="font-weight:600; color:var(--text); text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($info['title']) ?>">
                      <?= htmlspecialchars($info['title']) ?> ↗
                    </a>
                  </div>
                  <div style="font-size:0.72rem; color:var(--muted); font-family:var(--font-mono);">
                    <?= htmlspecialchars($row['path']) ?> · <?= $row['uniques'] ?> únicos<?= !empty($row['last_seen']) ? ' · ' . date('d/m H:i', $row['last_seen']) : '' ?>
                  </div>
                </td>
                <td style="text-align:right; font-weight:700; font-family:var(--font-mono); color:var(--text);">
                  <?= number_format($row['views']) ?>
                </td>
                <td style="text-align:right; font-family:var(--font-mono); font-size:0.8rem; color:var(--text2);">
                  <?= format_dwell((int)($row['avg_dwell'] ?? 0)) ?>
                </td>
                <td style="text-align:right;">
                  <span class="badge" style="background:rgba(99,102,241,0.12); color:var(--accent); font-family:var(--font-mono);">
                    <?= $row['avg_depth'] ? $row['avg_depth'] . '%' : '—' ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Traffic Sources & Referrers -->
      <div class="card">
        <div class="card-header">
          <div>
            <h3 class="card-title">Fuentes de Tráfico</h3>
            <span style="font-size:0.75rem; color:var(--muted);">Sitios y redes que enviaron lectores</span>
          </div>
        </div>
        <?php if (empty($stats['top_referrers'])): ?>
          <div style="padding:2.5rem 1rem; text-align:center; color:var(--muted); font-size:0.85rem;">
            Tráfico directo o sin referencias externas aún
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Referencia</th>
                <th style="text-align:right;">Visitas</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stats['top_referrers'] as $ref => $count): ?>
              <tr>
                <td>
                  <div style="font-weight:600; color:var(--text); display:flex; align-items:center; gap:0.4rem;">
                    <span>🌐</span>
                    <span><?= htmlspecialchars($ref) ?></span>
                  </div>
                </td>
                <td style="text-align:right; font-weight:700; font-family:var(--font-mono); color:var(--text);">
                  <?= number_format($count) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Devices & Browsers -->
    <div class="card" style="padding:1.25rem;">
      <h3 class="card-title" style="margin-bottom:1rem;">Dispositivos y Navegadores</h3>
      <?php
      $devTotal = array_sum($stats['devices']) ?: 1;
      $devDesktop = round(($stats['devices']['desktop'] / $devTotal) * 100);
      $devMobile  = round(($stats['devices']['mobile'] / $devTotal) * 100);
      $devTablet  = round(($stats['devices']['tablet'] / $devTotal) * 100);
      ?>
      <div style="margin-bottom:1rem;">
        <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:0.4rem; color:var(--text2);">
          <span>💻 Escritorio: <strong><?= $devDesktop ?>%</strong> (<?= $stats['devices']['desktop'] ?>)</span>
          <span>📱 Móvil: <strong><?= $devMobile ?>%</strong> (<?= $stats['devices']['mobile'] ?>)</span>
          <span>📟 Tableta: <strong><?= $devTablet ?>%</strong> (<?= $stats['devices']['tablet'] ?>)</span>
        </div>
        <div style="display:flex; height:10px; border-radius:5px; overflow:hidden; background:var(--bg3);">
          <div style="width:<?= $devDesktop ?>%; background:var(--accent);"></div>
          <div style="width:<?= $devMobile ?>%; background:#10b981;"></div>
          <div style="width:<?= $devTablet ?>%; background:#f59e0b;"></div>
        </div>
      </div>

      <?php if (!empty($stats['browsers'])): ?>
        <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:1rem; border-top:1px solid var(--border); padding-top:0.8rem;">
          <span style="font-size:0.75rem; color:var(--muted); align-self:center;">Navegadores:</span>
          <?php foreach ($stats['browsers'] as $br => $bCount): ?>
            <span class="badge" style="background:var(--bg3); border:1px solid var(--border); color:var(--text2); font-size:0.75rem;">
              <?= htmlspecialchars($br) ?>: <strong><?= $bCount ?></strong>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($stats['oses'])): ?>
        <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:0.75rem; border-top:1px solid var(--border); padding-top:0.8rem;">
          <span style="font-size:0.75rem; color:var(--muted); align-self:center;">Sistemas:</span>
          <?php foreach ($stats['oses'] as $osName => $osCount): ?>
            <span class="badge" style="background:var(--bg3); border:1px solid var(--border); color:var(--text2); font-size:0.75rem;">
              <?= htmlspecialchars($osName) ?>: <strong><?= $osCount ?></strong>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Visits Log -->
    <div class="card" style="margin-top:1.5rem;">
      <div class="card-header">
        <div>
          <h3 class="card-title">Actividad Reciente</h3>
          <span style="font-size:0.75rem; color:var(--muted);">Últimas visitas registradas (anónimas, sin IP)</span>
        </div>
      </div>
      <?php if (empty($stats['recent_views'])): ?>
        <div style="padding:2rem 1rem; text-align:center; color:var(--muted); font-size:0.85rem;">
          Sin visitas recientes en este filtro
        </div>
      <?php else: ?>
        <div style="max-height:380px; overflow-y:auto;">
          <table class="table">
            <thead>
              <tr>
                <th>Fecha / Hora</th>
                <th>Artículo / Ruta</th>
                <th>Origen</th>
                <th>Entorno</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($stats['recent_views'] as $rv):
                $rvInfo = resolve_path_info($rv['path']);
              ?>
              <tr>
                <td style="font-family:var(--font-mono); font-size:0.78rem; color:var(--muted); white-space:nowrap;">
                  <?= date('Y-m-d H:i', (int)$rv['ts']) ?>
                </td>
                <td>
                  <div style="font-weight:600; color:var(--text); max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?= htmlspecialchars($rvInfo['title']) ?>
                  </div>
                  <div style="font-size:0.7rem; color:var(--muted); font-family:var(--font-mono);">
                    <?= htmlspecialchars($rv['path']) ?>
                  </div>
                </td>
                <td style="font-size:0.8rem; color:var(--text2);">
                  <?= $rv['ref'] !== '' ? htmlspecialchars($rv['ref']) : '<span style="color:var(--muted);">Directo</span>' ?>
                </td>
                <td style="font-size:0.75rem; color:var(--muted); white-space:nowrap;">
                  <?= htmlspecialchars($rv['device']) ?> · <?= htmlspecialchars($rv['os']) ?> · <?= htmlspecialchars($rv['browser']) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>

