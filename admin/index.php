<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$CONFIG_PATH   = __DIR__ . '/../config.php';
$SERVICES_PATH = __DIR__ . '/../services.json';
$CSS_PATH      = __DIR__ . '/../assets/console.css';

function loadConfig($path) {
    if (!file_exists($path)) {
        return ['password_hash' => 'CHANGE_ME', 'hostname' => 'raspberrypi'];
    }
    $cfg = @include $path;
    return is_array($cfg) ? $cfg : ['password_hash' => 'CHANGE_ME', 'hostname' => 'raspberrypi'];
}

function saveConfig($path, $cfg) {
    file_put_contents($path, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
}

$config    = loadConfig($CONFIG_PATH);
$hostname  = (string) (gethostname() ?: ($config['hostname'] ?? 'raspberrypi'));   // ver index.php
$is_first  = $config['password_hash'] === 'CHANGE_ME';
$logged_in = !empty($_SESSION['admin_ok']);
$error     = '';
$success   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = 'Token de solicitud inválido, recarga la página';
        $action = '';
    }

    if ($action === 'setup' && $is_first) {
        $pw  = $_POST['password']  ?? '';
        $pw2 = $_POST['password2'] ?? '';
        if (strlen($pw) < 6) {
            $error = 'Mínimo 6 caracteres';
        } elseif ($pw !== $pw2) {
            $error = 'Las contraseñas no coinciden';
        } else {
            $config['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            saveConfig($CONFIG_PATH, $config);
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $is_first  = false;
            $logged_in = true;
            $success   = 'Contraseña establecida';
        }
    }

    if ($action === 'login' && !$is_first && !$logged_in) {
        $pw = $_POST['password'] ?? '';
        if (password_verify($pw, $config['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $logged_in = true;
        } else {
            $error = 'Acceso denegado';
        }
    }

    if ($action === 'change_password' && $logged_in) {
        $cur  = $_POST['current']       ?? '';
        $new  = $_POST['new_password']  ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        if (!password_verify($cur, $config['password_hash'])) {
            $error = 'La contraseña actual no es correcta';
        } elseif (strlen($new) < 6) {
            $error = 'Mínimo 6 caracteres';
        } elseif ($new !== $new2) {
            $error = 'Las contraseñas nuevas no coinciden';
        } else {
            $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            saveConfig($CONFIG_PATH, $config);
            $success = 'Contraseña actualizada';
        }
    }
}

$cssVersion = file_exists($CSS_PATH) ? filemtime($CSS_PATH) : 0;

// Campo de contraseña con botón mostrar/ocultar
function pw_field(string $id, string $name, string $label, string $extra = ''): void { ?>
  <label class="form-label" for="<?= $id ?>"><?= $label ?></label>
  <div class="input-group">
    <input type="password" id="<?= $id ?>" name="<?= $name ?>" class="form-control form-control-console" <?= $extra ?> required>
    <button type="button" class="btn btn-console pw-toggle" aria-controls="<?= $id ?>" aria-pressed="false" aria-label="Mostrar contraseña">ver</button>
  </div>
<?php }
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title><?= htmlspecialchars($hostname) ?> :: admin</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' fill='%23000'/%3E%3Crect x='2.5' y='2.5' width='11' height='11' fill='none' stroke='%2333ff33' stroke-width='1.5'/%3E%3Crect x='6' y='6' width='4' height='4' fill='%2333ff33'/%3E%3C/svg%3E">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
<link href="../assets/console.css?v=<?= $cssVersion ?>" rel="stylesheet">
<style>
  .auth-card { width: 100%; max-width: 380px; }

  .table {
    --bs-table-bg: transparent; --bs-table-color: var(--console-fg);
    --bs-table-border-color: var(--console-line);
    --bs-table-hover-bg: rgba(51,255,51,.06); --bs-table-hover-color: var(--console-bright);
  }
  .table thead th {
    color: var(--console-dim); font-weight: 500; font-size: .75rem;
    letter-spacing: .08em; text-transform: uppercase; white-space: nowrap;
  }
  .table-wrap { border: 1px solid var(--console-line); border-radius: var(--bs-border-radius); background: var(--console-panel); }
  .table-wrap .table { margin-bottom: 0; }
  .table-wrap.is-busy { opacity: .6; pointer-events: none; }
  .url-cell { max-width: 220px; word-break: break-all; font-size: .85rem; }
  .url-mobile { word-break: break-all; }
  .drag-handle { cursor: grab; color: var(--console-dim); user-select: none; font-size: 1.1rem; }
  .drag-handle:active { cursor: grabbing; }
  tr.drag-over td { border-top: 2px solid var(--console-fg); }
  .move-btn { --bs-btn-padding-x: .45rem; }

  .modal {
    --bs-modal-bg: var(--console-panel); --bs-modal-color: var(--console-fg);
    --bs-modal-border-color: var(--console-fg);
    --bs-modal-header-border-color: var(--console-line); --bs-modal-footer-border-color: var(--console-line);
  }
  .modal-content { box-shadow: 0 0 18px rgba(51,255,51,.25); }
  .modal-backdrop { --bs-backdrop-opacity: .75; }
  .preview .card { --bs-card-bg: #000; }

  .toast-area { z-index: 1090; }
  .toast {
    --bs-toast-bg: var(--console-panel); --bs-toast-color: var(--console-fg);
    --bs-toast-border-color: var(--console-fg); --bs-toast-box-shadow: 0 0 10px rgba(51,255,51,.35);
  }
  .toast.toast-err {
    --bs-toast-bg: var(--console-crit-panel); --bs-toast-color: var(--console-crit);
    --bs-toast-border-color: var(--console-crit); --bs-toast-box-shadow: 0 0 10px rgba(255,85,85,.35);
  }
</style>
</head>
<body>

<?php if ($is_first || !$logged_in): ?>
<!-- ═══════════════  SETUP / LOGIN  ═══════════════ -->
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
  <div class="card auth-card">
    <div class="card-body p-4">
      <div class="boot-block mb-4 small text-console-dim">
        <?php if ($is_first): ?>
          <div>[admin] primera ejecución detectada</div>
          <div>[ !! ] no hay contraseña configurada</div>
          <div class="text-body">&gt; define una contraseña para continuar_</div>
        <?php else: ?>
          <div>[admin] <?= htmlspecialchars($hostname) ?></div>
          <div>[auth] se requieren credenciales</div>
          <div class="text-body">&gt; introduce la contraseña_</div>
        <?php endif; ?>
      </div>

      <?php if ($error): ?>
        <div class="small text-console-err mb-3" role="alert">[fail] <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="action" value="<?= $is_first ? 'setup' : 'login' ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <?php if ($is_first): ?>
          <div class="mb-3"><?php pw_field('pw', 'password', 'Contraseña', 'minlength="6" autofocus'); ?></div>
          <div class="mb-3"><?php pw_field('pw2', 'password2', 'Confirmar contraseña', 'minlength="6"'); ?></div>
          <button type="submit" class="btn btn-console w-100">INICIALIZAR</button>
        <?php else: ?>
          <div class="mb-3"><?php pw_field('pw', 'password', 'Contraseña', 'autofocus'); ?></div>
          <button type="submit" class="btn btn-console w-100">ACCEDER</button>
        <?php endif; ?>
      </form>

      <div class="small text-console-dim mt-3 text-center"><a href="../">&larr; volver al dashboard</a></div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════  PANEL  ═══════════════ -->
<div class="container py-4">

  <div class="boot-block mb-4 small text-console-dim">
    <div>[admin] sesión iniciada · host: <?= htmlspecialchars($hostname) ?></div>
    <div>[ ok ] <span id="svcCount">0 servicio(s)</span> en services.json</div>
  </div>

  <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 border-bottom pb-3 mb-4">
    <h1 class="h4 mb-0 text-break"><?= htmlspecialchars($hostname) ?>:~/admin</h1>
    <span class="small text-console-dim text-nowrap">
      <a href="../">[dashboard]</a>
      <form method="POST" class="d-inline">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn-link-console">[salir]</button>
      </form>
    </span>
  </div>

  <!-- Servicios -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h6 text-console-dim mb-0">// servicios</h2>
    <button type="button" class="btn btn-sm btn-console" id="addBtn" onclick="openNew()">+ AÑADIR</button>
  </div>

  <div class="table-wrap table-responsive mb-2" id="tableWrap">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th style="width:1%">Orden</th>
          <th>Glifo</th>
          <th>Servicio</th>
          <th class="d-none d-md-table-cell">URL local</th>
          <th class="d-none d-md-table-cell">URL global</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody id="svcBody">
        <tr><td colspan="6" class="text-console-dim text-center py-4">cargando...</td></tr>
      </tbody>
    </table>
  </div>
  <p class="small text-console-dim mb-5">↑/↓ o arrastra ⠿ para cambiar el orden; el dashboard agrupa por la primera parte del tag (<code class="text-console-dim">home</code>/automation).</p>

  <!-- Cambiar contraseña -->
  <h2 class="h6 text-console-dim mb-3">// cambiar contraseña</h2>
  <div class="card mb-4">
    <div class="card-body">
      <form method="POST" autocomplete="off" style="max-width:560px">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="mb-3"><?php pw_field('cur', 'current', 'Contraseña actual'); ?></div>
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6"><?php pw_field('new', 'new_password', 'Nueva', 'minlength="6"'); ?></div>
          <div class="col-12 col-md-6"><?php pw_field('new2', 'new_password2', 'Confirmar nueva', 'minlength="6"'); ?></div>
        </div>
        <button type="submit" class="btn btn-sm btn-console">ACTUALIZAR CONTRASEÑA</button>
      </form>
    </div>
  </div>

  <hr>
  <footer class="small text-console-dim d-flex justify-content-between flex-wrap gap-2">
    <span>panel de administración</span>
    <span>github JRobertoMA</span>
  </footer>
</div>

<!-- ═══════════════  MODAL: AÑADIR / EDITAR  ═══════════════ -->
<div class="modal fade" id="svcModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="svcForm" novalidate>
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="modalTitle">// añadir_servicio</h2>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="small text-console-err mb-3 d-none" id="formErr" role="alert"></div>
        <div class="row g-4">
          <div class="col-12 col-md-7">
            <div class="row g-3">
              <div class="col-12 col-sm-6">
                <label class="form-label" for="fName">Nombre <span class="text-console-err">*</span></label>
                <input type="text" id="fName" class="form-control form-control-console" placeholder="Jellyfin" maxlength="60">
                <div class="invalid-feedback" id="fNameErr"></div>
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label" for="fId">ID (slug)</label>
                <input type="text" id="fId" class="form-control form-control-console" placeholder="jellyfin">
                <div class="invalid-feedback" id="fIdErr"></div>
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label" for="fTag">Tag <span class="text-console-dim">(área/tipo)</span></label>
                <input type="text" id="fTag" class="form-control form-control-console" placeholder="media/streaming">
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label" for="fGlyph">Glifo</label>
                <input type="text" id="fGlyph" class="form-control form-control-console" placeholder="JF" maxlength="5">
              </div>
              <div class="col-12">
                <label class="form-label" for="fLocal">URL local <span class="text-console-err">*</span></label>
                <input type="url" id="fLocal" class="form-control form-control-console" placeholder="http://192.168.1.10:8096">
                <div class="invalid-feedback" id="fLocalErr"></div>
              </div>
              <div class="col-12">
                <label class="form-label" for="fGlobal">URL global <span class="text-console-dim">(opcional)</span></label>
                <input type="url" id="fGlobal" class="form-control form-control-console" placeholder="https://jellyfin.tudominio.com">
                <div class="invalid-feedback" id="fGlobalErr"></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-5 preview" aria-hidden="true">
            <div class="form-label">Vista previa</div>
            <div class="small text-console-dim mb-2" id="pvArea">// otros</div>
            <div class="card">
              <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <h3 class="h6 mb-0 text-break" id="pvName">—</h3>
                  <span class="small text-console-dim" id="pvIdx">SVC_01</span>
                </div>
                <div class="mb-3"><span class="badge badge-console" id="pvBadge"></span></div>
                <div class="d-flex gap-2 mt-auto" id="pvBtns">
                  <span class="btn btn-sm btn-console-primary" id="pvLocal">LOCAL</span>
                  <span class="btn btn-sm btn-console" id="pvGlobal">GLOBAL</span>
                </div>
                <div class="small text-console-dim mt-auto d-none" id="pvNoUrl">sin URL configurada</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-console" data-bs-dismiss="modal">CANCELAR</button>
        <button type="submit" class="btn btn-sm btn-console" id="saveBtn">GUARDAR</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════  MODAL: CONFIRMAR  ═══════════════ -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h6 mb-0" id="confirmTitle">// confirmar</h2>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body small" id="confirmBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm btn-console" data-bs-dismiss="modal">CANCELAR</button>
        <button type="button" class="btn btn-sm btn-console-danger" id="confirmOk">ELIMINAR</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3 toast-area" id="toastArea"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
<script>
const CSRF  = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const FLASH = <?= json_encode(['error' => $error, 'success' => $success], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

const svcModal     = new bootstrap.Modal('#svcModal');
const confirmModal = new bootstrap.Modal('#confirmModal');
const $ = id => document.getElementById(id);

let services = [];
let editingIndex = -1;
let busy = false;

function esc(s) {
  return String(s || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Reglas compartidas con api/services.php ────────────────────
function validUrl(v) {
  try { const u = new URL(v); return u.protocol === 'http:' || u.protocol === 'https:'; }
  catch { return false; }
}
const slugify     = s => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const normGlyph   = s => String(s).replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 5);
const defaultGlyph = name => normGlyph(name).slice(0, 2) || 'SV';
const areaOf      = tag => (String(tag).split('/')[0].trim().toLowerCase()) || 'otros';
const pad2        = n => String(n).padStart(2, '0');

// ── Toasts ─────────────────────────────────────────────────────
function toast(msg, isErr = false) {
  const el = document.createElement('div');
  el.className = 'toast align-items-center' + (isErr ? ' toast-err' : '');
  el.setAttribute('role', isErr ? 'alert' : 'status');
  el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>'
    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>';
  el.querySelector('.toast-body').textContent = (isErr ? '[fail] ' : '[ ok ] ') + msg;
  $('toastArea').appendChild(el);
  el.addEventListener('hidden.bs.toast', () => el.remove());
  new bootstrap.Toast(el, { delay: isErr ? 5000 : 3000 }).show();
}
const showErr = msg => toast(msg, true);
const showOk  = msg => toast(msg);

// ── Cargar y pintar ────────────────────────────────────────────
async function load() {
  try {
    const res = await fetch('../api/services.php', { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    services = await res.json();
    renderTable();
  } catch (e) {
    showErr('No se pudo cargar services.json');
  }
}

function urlLink(url, dim = false) {
  return url
    ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer"${dim ? ' class="text-console-dim"' : ''}>${esc(url)}</a>`
    : '<span class="text-console-dim">—</span>';
}

function renderTable() {
  const tbody = $('svcBody');
  $('svcCount').textContent = services.length + ' servicio(s)';

  if (!services.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-console-dim text-center py-4">No hay servicios. Pulsa «+ AÑADIR».</td></tr>';
    return;
  }

  tbody.innerHTML = '';
  const last = services.length - 1;
  services.forEach((s, i) => {
    const tr = document.createElement('tr');
    tr.dataset.idx = i;
    tr.draggable = true;
    tr.innerHTML = `
      <td class="text-nowrap">
        <span class="drag-handle d-none d-md-inline me-1" title="Arrastrar para reordenar" aria-hidden="true">⠿</span>
        <button type="button" class="btn btn-sm btn-console move-btn" data-dir="-1" onclick="moveSvc(${i}, -1)"
                aria-label="Subir ${esc(s.name)}" ${i === 0 ? 'disabled' : ''}>↑</button>
        <button type="button" class="btn btn-sm btn-console move-btn" data-dir="1" onclick="moveSvc(${i}, 1)"
                aria-label="Bajar ${esc(s.name)}" ${i === last ? 'disabled' : ''}>↓</button>
      </td>
      <td><span class="badge badge-console">${esc(s.glyph)}</span></td>
      <td>
        <div class="fw-semibold">${esc(s.name)}</div>
        <div class="small text-console-dim">› ${esc(s.tag)}</div>
        <div class="small d-md-none mt-1 url-mobile">${s.local ? urlLink(s.local) : ''}</div>
        <div class="small d-md-none url-mobile">${s.global ? urlLink(s.global, true) : ''}</div>
      </td>
      <td class="url-cell d-none d-md-table-cell">${urlLink(s.local)}</td>
      <td class="url-cell d-none d-md-table-cell">${urlLink(s.global, true)}</td>
      <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-console" onclick="editSvc(${i})" aria-label="Editar ${esc(s.name)}">EDITAR</button>
        <button type="button" class="btn btn-sm btn-console-danger ms-1" onclick="deleteSvc(${i})" aria-label="Borrar ${esc(s.name)}">BORRAR</button>
      </td>
    `;
    tbody.appendChild(tr);
  });

  attachDnd();
}

// ── Guardar: se envía una copia y solo se adopta si el servidor la acepta ──
function setBusy(on) {
  busy = on;
  $('tableWrap').classList.toggle('is-busy', on);
  ['addBtn', 'saveBtn', 'confirmOk'].forEach(id => $(id).disabled = on);
  $('saveBtn').textContent = on ? 'guardando…' : (editingIndex >= 0 ? 'ACTUALIZAR' : 'GUARDAR');
}

async function commit(next, okMsg) {
  if (busy) return false;
  setBusy(true);
  try {
    const res = await fetch('../api/services.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(next),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'No se pudo guardar');
    await load();          // lo que quedó en disco tras el saneado del servidor
    if (okMsg) showOk(okMsg);
    return true;
  } catch (e) {
    showErr(e.message);
    return false;
  } finally {
    setBusy(false);
  }
}

// ── Borrar ─────────────────────────────────────────────────────
let pendingConfirm = null;
$('confirmOk').addEventListener('click', () => {
  confirmModal.hide();
  if (pendingConfirm) pendingConfirm();
  pendingConfirm = null;
});

function deleteSvc(i) {
  if (busy) return;
  $('confirmBody').textContent = `¿Eliminar «${services[i].name}» del dashboard?`;
  pendingConfirm = () => commit(services.filter((_, j) => j !== i), 'Servicio eliminado');
  confirmModal.show();
}

// ── Reordenar (↑/↓ y arrastre) ─────────────────────────────────
async function reorder(from, to) {
  if (busy || from === to || to < 0 || to >= services.length) return;
  const next = services.slice();
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return commit(next, 'Orden guardado');
}

async function moveSvc(i, dir) {
  const ok = await reorder(i, i + dir);
  if (!ok) return;
  // Mantener el foco en la fila movida para poder seguir con el teclado
  const row = document.querySelector(`#svcBody tr[data-idx="${i + dir}"]`);
  const btn = row && (row.querySelector(`.move-btn[data-dir="${dir}"]:not(:disabled)`) || row.querySelector('.move-btn:not(:disabled)'));
  if (btn) btn.focus();
}

let dragRow = null;
function attachDnd() {
  document.querySelectorAll('#svcBody tr').forEach(tr => {
    tr.addEventListener('dragstart', e => {
      if (busy) { e.preventDefault(); return; }
      dragRow = tr;
      tr.style.opacity = '0.4';
      e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragend', () => { tr.style.opacity = ''; dragRow = null; });
    tr.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (dragRow && dragRow !== tr) tr.classList.add('drag-over');
    });
    tr.addEventListener('dragleave', () => tr.classList.remove('drag-over'));
    tr.addEventListener('drop', e => {
      e.preventDefault();
      tr.classList.remove('drag-over');
      if (!dragRow || dragRow === tr) return;
      reorder(parseInt(dragRow.dataset.idx, 10), parseInt(tr.dataset.idx, 10));
    });
  });
}

// ── Modal añadir / editar ──────────────────────────────────────
const FIELDS = ['fName', 'fId', 'fTag', 'fGlyph', 'fLocal', 'fGlobal'];

function resetForm() {
  editingIndex = -1;
  FIELDS.forEach(id => $(id).value = '');
  clearInvalid();
  $('modalTitle').textContent = '// añadir_servicio';
  $('saveBtn').textContent    = 'GUARDAR';
  updatePreview();
}

function openNew() {
  if (busy) return;
  resetForm();
  svcModal.show();
}

function editSvc(i) {
  if (busy) return;
  resetForm();
  editingIndex = i;
  const s = services[i];
  $('fName').value   = s.name;
  $('fId').value     = s.id;
  $('fTag').value    = s.tag;
  $('fGlyph').value  = s.glyph;
  $('fLocal').value  = s.local;
  $('fGlobal').value = s.global || '';
  $('modalTitle').textContent = '// editar_servicio';
  $('saveBtn').textContent    = 'ACTUALIZAR';
  updatePreview();
  svcModal.show();
}

$('svcModal').addEventListener('shown.bs.modal', () => $('fName').focus());

// ID automático mientras se crea; al editar no se pisa el existente
$('fName').addEventListener('input', () => {
  if (editingIndex < 0) $('fId').value = slugify($('fName').value);
});
$('fGlyph').addEventListener('input', () => { $('fGlyph').value = normGlyph($('fGlyph').value); });

function setInvalid(id, msg) {
  $(id).classList.add('is-invalid');
  $(id + 'Err').textContent = msg;
}
function clearInvalid(id) {
  const els = id ? [$(id)] : document.querySelectorAll('#svcForm .is-invalid');
  els.forEach(el => el.classList.remove('is-invalid'));
  $('formErr').classList.add('d-none');
}

$('svcForm').addEventListener('input', e => {
  if (e.target.classList.contains('is-invalid')) clearInvalid(e.target.id);
  updatePreview();
});

function updatePreview() {
  const name   = $('fName').value.trim();
  const tag    = $('fTag').value.trim();
  const glyph  = normGlyph($('fGlyph').value) || defaultGlyph(name);
  const local  = validUrl($('fLocal').value.trim());
  const global = validUrl($('fGlobal').value.trim());
  const idx    = editingIndex >= 0 ? editingIndex + 1 : services.length + 1;

  $('pvArea').textContent  = '// ' + areaOf(tag);
  $('pvName').textContent  = name || '—';
  $('pvIdx').textContent   = 'SVC_' + pad2(idx);
  $('pvBadge').textContent = (name ? glyph + ' · ' : '') + tag.toUpperCase();
  $('pvLocal').classList.toggle('d-none', !local);
  $('pvGlobal').classList.toggle('d-none', !global);
  $('pvGlobal').className = 'btn btn-sm ' + (local ? 'btn-console' : 'btn-console-primary') + (global ? '' : ' d-none');
  $('pvNoUrl').classList.toggle('d-none', local || global);
}

$('svcForm').addEventListener('submit', async e => {
  e.preventDefault();
  if (busy) return;   // un doble clic no envía dos veces
  clearInvalid();

  const name   = $('fName').value.trim();
  const local  = $('fLocal').value.trim();
  const global = $('fGlobal').value.trim();
  const id     = $('fId').value.trim() || slugify(name);

  if (!name) setInvalid('fName', 'El nombre es obligatorio');
  if (!/^[a-z0-9-]+$/.test(id)) {
    setInvalid('fId', 'Solo minúsculas, números y guiones');
  } else if (services.some((s, j) => j !== editingIndex && s.id === id)) {
    setInvalid('fId', 'Ya existe un servicio con este ID');
  }
  if (!local)                 setInvalid('fLocal', 'La URL local es obligatoria');
  else if (!validUrl(local))  setInvalid('fLocal', 'Debe empezar por http:// o https://');
  if (global && !validUrl(global)) setInvalid('fGlobal', 'Debe empezar por http:// o https://');

  const firstInvalid = document.querySelector('#svcForm .is-invalid');
  if (firstInvalid) { firstInvalid.focus(); return; }

  const svc = {
    id,
    name,
    tag:   $('fTag').value.trim(),
    glyph: normGlyph($('fGlyph').value) || defaultGlyph(name),
    local,
    global,
  };

  const isEdit = editingIndex >= 0;
  const next = services.slice();
  if (isEdit) next[editingIndex] = svc;
  else        next.push(svc);

  if (await commit(next, isEdit ? 'Servicio actualizado' : 'Servicio añadido')) {
    svcModal.hide();
  } else {
    $('formErr').textContent = '[fail] No se guardó; revisa el mensaje y vuelve a intentarlo';
    $('formErr').classList.remove('d-none');
  }
});

// ── Init ───────────────────────────────────────────────────────
load();
if (FLASH.error)   showErr(FLASH.error);
if (FLASH.success) showOk(FLASH.success);
</script>
<?php endif; ?>

<script>
// Mostrar / ocultar contraseña (login, setup y cambio de contraseña)
document.querySelectorAll('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
  const input = document.getElementById(btn.getAttribute('aria-controls'));
  const show  = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.textContent = show ? 'ocultar' : 'ver';
  btn.setAttribute('aria-pressed', String(show));
  btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
}));
</script>
</body>
</html>
