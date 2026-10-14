<?php
declare(strict_types=1);
/**
 * Dashboard de servicios self-hosted
 * Renderiza las tarjetas desde services.json agrupadas por área (lo que va antes de «/» en el tag);
 * los stats del host y el enlace preferido LOCAL/GLOBAL se resuelven por JS.
 */

$servicesPath = __DIR__ . '/services.json';
$configPath   = __DIR__ . '/config.php';
$cssPath      = __DIR__ . '/assets/console.css';

// Solo se usa hostname (como respaldo); el resto de config.php (hash de la contraseña) no sale de aquí
$cfg      = file_exists($configPath) ? @include $configPath : [];
$cfg      = is_array($cfg) ? $cfg : [];
// Nombre real del host (el contenedor comparte su UTS con `uts: host`); config.php solo como respaldo
$hostname = (string) (gethostname() ?: ($cfg['hostname'] ?? 'raspberrypi'));

$raw      = file_exists($servicesPath) ? @file_get_contents($servicesPath) : false;
$services = $raw !== false ? json_decode($raw, true) : [];
$services = is_array($services) ? array_values(array_filter($services, 'is_array')) : [];

// Mismo criterio que safeUrl() en api/services.php: solo http/https
function safe_url(mixed $raw): string {
    $url = filter_var(trim((string) $raw), FILTER_VALIDATE_URL);
    return ($url && preg_match('/^https?:\/\//i', $url)) ? $url : '';
}

// Áreas en el orden en que aparecen en services.json (el admin decide el orden)
$areas = [];
foreach ($services as $i => $s) {
    $tag  = trim((string) ($s['tag'] ?? ''));
    $area = $tag !== '' ? strtolower(trim(explode('/', $tag)[0])) : '';
    $areas[$area !== '' ? $area : 'otros'][] = $i;
}

$cssVersion = file_exists($cssPath) ? filemtime($cssPath) : 0;
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($hostname) ?> :: servicios</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' fill='%23000'/%3E%3Crect x='2.5' y='2.5' width='11' height='11' fill='none' stroke='%2333ff33' stroke-width='1.5'/%3E%3Crect x='6' y='6' width='4' height='4' fill='%2333ff33'/%3E%3C/svg%3E">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<link href="assets/console.css?v=<?= $cssVersion ?>" rel="stylesheet">
<style>
  .stat-warn { color: var(--console-warn); }
  .stat-crit { color: var(--console-crit); font-weight: 600; }
  #statLine.stale .v { opacity: .5; }
  @media (max-width: 575.98px) {
    .page-title { font-size: 1rem; }
  }
</style>
</head>
<body>
<div class="container py-4">

  <div class="boot-block mb-4 small text-console-dim">
    <div>[boot] host: <?= htmlspecialchars($hostname) ?></div>
    <div>[ ok ] <?= count($services) ?> servicio(s) en <?= count($areas) ?> área(s)</div>
    <div class="d-flex flex-wrap column-gap-2" id="statLine" aria-live="off">
      <span class="text-nowrap">[stat]</span>
      <span class="stat text-nowrap" id="stUptime">uptime <span class="v">--</span></span>
      <span class="stat text-nowrap" id="stMem">· ram <span class="v">--</span></span>
      <span class="stat text-nowrap" id="stLoad">· load <span class="v">--</span></span>
      <span class="stat text-nowrap d-none" id="stTemp">· temp <span class="v">--</span></span>
      <span class="stat text-nowrap" id="stLat">· lat <span class="v">--</span></span>
      <span class="stat text-nowrap" id="stFresh">· act. <span class="v">--</span></span>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 border-bottom pb-3 mb-4">
    <h1 class="h4 mb-0 text-break page-title"><?= htmlspecialchars($hostname) ?>:~/servicios</h1>
    <span class="small text-console-dim text-nowrap">
      <span class="d-none d-sm-inline"><?= date('Y-m-d H:i') ?> ·</span>
      <button type="button" id="netToggle" class="btn-link-console" title="Qué enlace abre la tarjeta: auto (según la red desde la que entras), local o global">[red: auto]</button>
      · <a href="admin/">[admin]</a>
    </span>
  </div>

  <?php if (!empty($services)): ?>
  <div class="d-flex gap-2 align-items-center mb-4">
    <input type="text" id="filter" class="form-control form-control-console" placeholder="filtrar…  ( / para buscar · enter abre )" autocomplete="off" aria-label="Filtrar servicios" aria-describedby="filterCount">
    <span id="filterCount" class="small text-console-dim text-nowrap" aria-live="polite"><?= count($services) ?>/<?= count($services) ?></span>
  </div>

  <p id="noMatch" class="text-console-dim border rounded p-4 mb-5 d-none" style="border-style:dashed !important;">sin coincidencias para «<span id="noMatchQ"></span>»</p>

  <?php foreach ($areas as $area => $indexes): ?>
  <section class="area mb-4">
    <h2 class="h6 text-console-dim mb-3">// <?= htmlspecialchars((string) $area) ?></h2>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
      <?php foreach ($indexes as $i):
        $s      = $services[$i];
        $name   = (string) ($s['name'] ?? '');
        $tag    = (string) ($s['tag'] ?? '');
        $glyph  = (string) ($s['glyph'] ?? '');
        $local  = safe_url($s['local'] ?? '');
        $global = safe_url($s['global'] ?? '');
        $pref   = $local !== '' ? $local : $global;   // sin JS: la local manda
      ?>
        <div class="col filter-item"
             data-name="<?= htmlspecialchars(strtolower($name . ' ' . $tag . ' ' . $area)) ?>"
             data-local="<?= htmlspecialchars($local) ?>"
             data-global="<?= htmlspecialchars($global) ?>">
          <div class="card h-100">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h3 class="h6 mb-0">
                  <?php if ($pref !== ''): ?>
                    <a href="<?= htmlspecialchars($pref) ?>" class="stretched-link svc-link" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($name) ?></a>
                  <?php else: ?>
                    <?= htmlspecialchars($name) ?>
                  <?php endif; ?>
                </h3>
                <span class="small text-console-dim">SVC_<?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              </div>
              <div class="mb-3">
                <span class="badge badge-console"><?= htmlspecialchars($glyph !== '' ? $glyph . ' · ' : '') ?><?= htmlspecialchars(strtoupper($tag)) ?></span>
              </div>
              <?php if ($local !== '' || $global !== ''): ?>
                <div class="d-flex gap-2 mt-auto">
                  <?php if ($local !== ''): ?>
                    <a href="<?= htmlspecialchars($local) ?>" class="btn btn-sm btn-console position-relative z-2 url-btn" data-net="local" target="_blank" rel="noopener noreferrer" aria-label="Abrir <?= htmlspecialchars($name) ?> en red local">LOCAL</a>
                  <?php endif; ?>
                  <?php if ($global !== ''): ?>
                    <a href="<?= htmlspecialchars($global) ?>" class="btn btn-sm btn-console position-relative z-2 url-btn" data-net="global" target="_blank" rel="noopener noreferrer" aria-label="Abrir <?= htmlspecialchars($name) ?> desde internet">GLOBAL</a>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="small text-console-dim mt-auto">sin URL configurada</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  <?php else: ?>
    <p class="text-console-dim border rounded p-4 mb-5" style="border-style:dashed !important;">No hay servicios. Añádelos desde el <a href="admin/">admin</a>.</p>
  <?php endif; ?>

  <hr>
  <footer class="small text-console-dim d-flex justify-content-between flex-wrap gap-2">
    <span>panel generado dinámicamente · <?= count($services) ?> servicio(s)</span>
    <span>github JRobertoMA</span>
  </footer>

</div>

<script>
  const $ = id => document.getElementById(id);
  const items    = document.querySelectorAll('.filter-item');
  const sections = document.querySelectorAll('section.area');
  const filterInput = $('filter');

  /* ── Filtro ─────────────────────────────────────────────── */
  function applyFilter() {
    const q = filterInput.value.trim().toLowerCase();
    let shown = 0;
    items.forEach(el => {
      const ok = el.dataset.name.includes(q);
      el.classList.toggle('d-none', !ok);
      if (ok) shown++;
    });
    sections.forEach(sec => sec.classList.toggle('d-none', !sec.querySelector('.filter-item:not(.d-none)')));
    $('filterCount').textContent = shown + '/' + items.length;
    $('noMatchQ').textContent = q;
    $('noMatch').classList.toggle('d-none', shown > 0);
  }

  if (filterInput) {
    filterInput.addEventListener('input', applyFilter);
    filterInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        filterInput.value = '';
        applyFilter();
        filterInput.blur();
      } else if (e.key === 'Enter') {
        const first = [...items].find(el => !el.classList.contains('d-none'));
        const link  = first && first.querySelector('.svc-link');
        if (link) window.open(link.href, '_blank', 'noopener');
      }
    });
    document.addEventListener('keydown', e => {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.target.closest('input, textarea, select, [contenteditable="true"]')) return;
      e.preventDefault();
      filterInput.focus();
      filterInput.select();
    });
  }

  /* ── Enlace preferido LOCAL / GLOBAL ────────────────────── */
  const NET_KEY   = 'docker-apps.net';
  const NET_MODES = ['auto', 'local', 'global'];
  const isLan = (h => h === 'localhost' || h === '[::1]'
    || /^(127|10)\./.test(h) || /^192\.168\./.test(h)
    || /^172\.(1[6-9]|2\d|3[01])\./.test(h) || /\.local$/.test(h))(location.hostname);

  let netMode = 'auto';
  try { const m = localStorage.getItem(NET_KEY); if (NET_MODES.includes(m)) netMode = m; } catch (e) {}

  function applyNet() {
    const net = netMode === 'auto' ? (isLan ? 'local' : 'global') : netMode;
    $('netToggle').textContent = '[red: ' + netMode + (netMode === 'auto' ? ' → ' + net : '') + ']';
    items.forEach(el => {
      const which = net === 'local'
        ? (el.dataset.local ? 'local' : 'global')
        : (el.dataset.global ? 'global' : 'local');
      const url  = el.dataset[which];
      const link = el.querySelector('.svc-link');
      if (link && url) link.href = url;
      el.querySelectorAll('.url-btn').forEach(btn => {
        const pref = btn.dataset.net === which;
        btn.classList.toggle('btn-console-primary', pref);
        btn.classList.toggle('btn-console', !pref);
      });
    });
  }

  $('netToggle').addEventListener('click', () => {
    netMode = NET_MODES[(NET_MODES.indexOf(netMode) + 1) % NET_MODES.length];
    try { localStorage.setItem(NET_KEY, netMode); } catch (e) {}
    applyNet();
  });
  applyNet();

  /* ── Stats del host ─────────────────────────────────────── */
  const STATS_MS = 30000, LAT_MS = 15000, FRESH_MS = 5000;
  let lastOk = 0, timers = [];

  const level = (v, warn, crit) => v >= crit ? 'stat-crit' : (v >= warn ? 'stat-warn' : '');

  function setStat(id, text, cls = '', title = '') {
    const v = $(id).querySelector('.v');
    v.textContent = text;
    v.className = 'v ' + cls;
    if (title) $(id).title = title;
  }

  function renderFresh() {
    const stale = $('statLine').classList.contains('stale');
    let txt = '--';
    if (lastOk) {
      const s = Math.round((Date.now() - lastOk) / 1000);
      txt = 'hace ' + (s < 60 ? s + ' s' : Math.floor(s / 60) + ' min');
    }
    setStat('stFresh', stale ? txt + ' · sin conexión' : txt, stale ? 'stat-warn' : '');
  }

  async function fetchStats() {
    try {
      const res = await fetch('api/stats.php', { cache: 'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const s = await res.json();
      const pct = s.mem_total ? Math.round(s.mem_used * 100 / s.mem_total) : 0;
      setStat('stUptime', s.uptime);
      setStat('stMem', pct + '%', level(pct, 85, 95), `RAM ${s.mem_used}/${s.mem_total} MB (${pct} %)`);
      setStat('stLoad', String(s.load1), '', `load 1/5/15 min: ${s.load1} / ${s.load5} / ${s.load15}`);
      if (s.temp_c !== null) {
        $('stTemp').classList.remove('d-none');
        setStat('stTemp', s.temp_c + '°C', level(s.temp_c, 70, 80), `CPU ${s.temp_c} °C`);
      }
      lastOk = Date.now();
      $('statLine').classList.remove('stale');
    } catch (e) {
      console.error('[dashboard] fetchStats:', e);
      $('statLine').classList.add('stale');   // se conservan los últimos valores, atenuados
    }
    renderFresh();
  }

  async function measureLat() {
    const t0 = performance.now();
    try {
      await fetch('api/ping.php?_=' + Date.now(), { cache: 'no-store' });
      const ms = Math.round(performance.now() - t0);
      setStat('stLat', ms + 'ms', level(ms, 300, Infinity));
    } catch (e) {
      setStat('stLat', '--');
    }
  }

  function startPolling() {
    stopPolling();
    fetchStats();
    measureLat();
    timers = [setInterval(fetchStats, STATS_MS), setInterval(measureLat, LAT_MS), setInterval(renderFresh, FRESH_MS)];
  }
  function stopPolling() {
    timers.forEach(clearInterval);
    timers = [];
  }

  // Sin polling con la pestaña oculta; al volver se refresca al momento
  document.addEventListener('visibilitychange', () => document.hidden ? stopPolling() : startPolling());
  startPolling();
</script>
</body>
</html>
