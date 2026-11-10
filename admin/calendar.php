<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/content.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();

// Auto-check any matured scheduled posts
check_scheduled_posts();

// Determine current month & year
$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Prev and Next month navigation links
$prev_month = $month - 1;
$prev_year  = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year  = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

// Localized month title
$month_title = ucfirst(month_name($month)) . ' ' . $year;

// Retrieve all articles
$all_articles = list_content('articles', false, 1, 9999)['items'] ?? [];

// Map articles by day of this month
$articles_by_day = [];
$month_published_count = 0;
$month_scheduled_count = 0;
$month_draft_count = 0;

foreach ($all_articles as $post) {
    if (empty($post['created_at'])) continue;
    $ts = strtotime($post['created_at']);
    if (!$ts) continue;

    $p_year  = (int)date('Y', $ts);
    $p_month = (int)date('n', $ts);
    $p_day   = (int)date('j', $ts);

    if ($p_year === $year && $p_month === $month) {
        $articles_by_day[$p_day][] = $post;
        $st = $post['status'] ?? 'draft';
        if ($st === 'published') $month_published_count++;
        elseif ($st === 'scheduled') $month_scheduled_count++;
        else $month_draft_count++;
    }
}

// Days calculation for grid
$first_day_ts   = mktime(0, 0, 0, $month, 1, $year);
$days_in_month  = (int)date('t', $first_day_ts);
// ISO-8601 day of week: 1 (Monday) to 7 (Sunday)
$start_weekday  = (int)date('N', $first_day_ts); 
$empty_days_before = $start_weekday - 1;

$today_year  = (int)date('Y');
$today_month = (int)date('n');
$today_day   = (int)date('j');

admin_header('Calendario de publicaciones', 'calendar');
?>
      <div style="display:flex;gap:0.5rem;align-items:center">
        <a href="<?= base_url() ?>/admin/articles.php" class="btn btn-secondary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          Lista de artículos
        </a>
        <a href="<?= base_url() ?>/admin/editor.php?type=articles" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Nuevo artículo
        </a>
      </div>
    </div>
  </div>

  <div class="page-body">
    <!-- Header bar with month navigation and summary metrics -->
    <div class="card" style="margin-bottom:1.25rem">
      <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;padding:1rem 1.4rem">
        <div style="display:flex;align-items:center;gap:0.85rem">
          <h2 style="font-size:1.35rem;font-weight:700;margin:0;letter-spacing:-0.02em"><?= htmlspecialchars($month_title) ?></h2>
          <div style="display:flex;gap:0.25rem;align-items:center">
            <a href="?year=<?= $prev_year ?>&month=<?= $prev_month ?>" class="btn btn-secondary btn-sm" title="Mes anterior">‹</a>
            <a href="?year=<?= $today_year ?>&month=<?= $today_month ?>" class="btn btn-secondary btn-sm" style="font-size:0.78rem">Hoy</a>
            <a href="?year=<?= $next_year ?>&month=<?= $next_month ?>" class="btn btn-secondary btn-sm" title="Mes siguiente">›</a>
          </div>
        </div>

        <div style="display:flex;gap:1rem;align-items:center;font-size:0.85rem;color:var(--text2);flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:0.4rem">
            <span style="width:9px;height:9px;border-radius:50%;background:var(--green);display:inline-block"></span>
            <strong><?= $month_published_count ?></strong> publicados
          </div>
          <div style="display:flex;align-items:center;gap:0.4rem">
            <span style="width:9px;height:9px;border-radius:50%;background:#3b82f6;display:inline-block"></span>
            <strong><?= $month_scheduled_count ?></strong> programados
          </div>
          <?php if ($month_draft_count > 0): ?>
          <div style="display:flex;align-items:center;gap:0.4rem">
            <span style="width:9px;height:9px;border-radius:50%;background:var(--yellow);display:inline-block"></span>
            <strong><?= $month_draft_count ?></strong> borradores
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Calendar Grid Container -->
    <div class="card" style="padding:0;overflow:hidden">
      <!-- Weekday headers -->
      <div class="cal-weekdays">
        <?php for ($d = 1; $d <= 7; $d++): ?>
          <div><?= htmlspecialchars(ucfirst(day_name($d, false))) ?></div>
        <?php endfor; ?>
      </div>

      <!-- Days Grid -->
      <div class="cal-grid">
        <?php
        // Empty slots before month start
        for ($i = 0; $i < $empty_days_before; $i++): ?>
          <div class="cal-day cal-day-empty"></div>
        <?php endfor; ?>

        <?php for ($d = 1; $d <= $days_in_month; $d++): 
          $is_today = ($year === $today_year && $month === $today_month && $d === $today_day);
          $day_date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
          $day_posts = $articles_by_day[$d] ?? [];
        ?>
          <div class="cal-day <?= $is_today ? 'is-today' : '' ?>">
            <div class="cal-day-head">
              <span class="cal-day-num <?= $is_today ? 'today-badge' : '' ?>"><?= $d ?></span>
              <a href="<?= base_url() ?>/admin/editor.php?type=articles&date=<?= $day_date_str ?>" class="cal-add-btn" title="Crear artículo para el <?= $d ?> de <?= $months_es[$month] ?>">+</a>
            </div>

            <div class="cal-day-posts">
              <?php foreach ($day_posts as $p): 
                $st = $p['status'] ?? 'draft';
                $pill_class = $st === 'published' ? 'cal-pill-pub' : ($st === 'scheduled' ? 'cal-pill-sched' : 'cal-pill-draft');
                $p_time = !empty($p['created_at']) ? date('H:i', strtotime($p['created_at'])) : '';
              ?>
                <a href="<?= base_url() ?>/admin/editor.php?type=articles&slug=<?= htmlspecialchars($p['slug']) ?>" class="cal-pill <?= $pill_class ?>" title="<?= htmlspecialchars($p['title']) ?> (<?= $st === 'published' ? 'Publicado' : ($st === 'scheduled' ? 'Programado' : 'Borrador') ?>)">
                  <span class="cal-pill-time"><?= $p_time ?></span>
                  <span class="cal-pill-title"><?= htmlspecialchars($p['title'] ?: 'Sin título') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endfor; ?>

        <?php
        // Complete row with trailing empty days
        $total_slots = $empty_days_before + $days_in_month;
        $trailing_empty = (7 - ($total_slots % 7)) % 7;
        for ($j = 0; $j < $trailing_empty; $j++): ?>
          <div class="cal-day cal-day-empty"></div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</div>

<style>
.cal-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background: var(--surface2);
  border-bottom: 1px solid var(--border);
  text-align: center;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.65rem 0;
}
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  background: var(--border);
  gap: 1px;
}
.cal-day {
  background: var(--surface);
  min-height: 120px;
  padding: 8px 10px;
  display: flex;
  flex-direction: column;
  position: relative;
  transition: background 0.15s ease;
  min-width: 0;
  overflow: hidden;
}
.cal-day:hover {
  background: var(--surface2);
}
.cal-day.cal-day-empty {
  background: var(--bg);
  opacity: 0.35;
  pointer-events: none;
}
.cal-day.is-today {
  background: rgba(var(--accent-rgb), 0.04);
}
.cal-day-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.cal-day-num {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text2);
}
.today-badge {
  background: var(--accent);
  color: #fff;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.76rem;
  font-weight: 700;
}
.cal-add-btn {
  opacity: 0;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  line-height: 1;
  color: var(--text);
  background: var(--surface2);
  border: 1px solid var(--border);
  text-decoration: none;
  transition: all 0.15s ease;
}
.cal-day:hover .cal-add-btn {
  opacity: 1;
}
.cal-add-btn:hover {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
}
.cal-day-posts {
  display: flex;
  flex-direction: column;
  gap: 4px;
  overflow-y: auto;
  max-height: 130px;
}
.cal-pill {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 3px 6px;
  border-radius: 5px;
  font-size: 0.76rem;
  text-decoration: none;
  line-height: 1.25;
  transition: transform 0.1s ease, filter 0.15s ease;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
  max-width: 100%;
}
.cal-pill:hover {
  transform: translateY(-1px);
  filter: brightness(1.1);
}
.cal-pill-time {
  font-size: 0.68rem;
  font-weight: 700;
  opacity: 0.85;
  font-family: var(--font-mono);
  flex-shrink: 0;
}
.cal-pill-title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
}
.cal-pill-pub {
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.3);
}
.cal-pill-sched {
  background: rgba(59, 130, 246, 0.15);
  color: #3b82f6;
  border: 1px solid rgba(59, 130, 246, 0.35);
}
.cal-pill-draft {
  background: rgba(245, 158, 11, 0.12);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.25);
}

@media (max-width: 850px) {
  .cal-weekdays { font-size: 0.7rem; }
  .cal-day { min-height: 80px; padding: 4px; }
  .cal-pill { font-size: 0.7rem; padding: 2px 4px; }
  .cal-pill-time { display: none; }
}
</style>
</body></html>

