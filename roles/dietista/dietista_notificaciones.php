<?php
if (!function_exists('icono_por_tipo_noti')) {
    function icono_por_tipo_noti(?string $tipo): string {
        switch ($tipo) {
            case 'consulta': return '📅';
            case 'dieta':    return '🍽️';
            case 'mensaje':  return '💬';
            case 'objetivo': return '🎯';
            case 'medida':   return '📏';
            case 'checkin':  return '📝';
            default:         return '🔔';
        }
    }
}
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';
$enviadas = 0;

/**
 * Comprueba si la notif id pertenece a un cliente del dietista.
 */
function noti_propia(mysqli $conn, int $idNoti, int $uidDietista): bool {
    $stmt = $conn->prepare(
        "SELECT n.id FROM notificaciones n
         JOIN usuarios u ON u.id = n.id_usuario
         WHERE n.id = ? AND u.id_dietista = ? AND u.rol = 'cliente'"
    );
    $stmt->bind_param('ii', $idNoti, $uidDietista);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? 'crear';

        if ($accion === 'borrar') {
            $idDel = (int)($_POST['id_noti'] ?? 0);
            if ($idDel <= 0 || !noti_propia($conn, $idDel, $uid)) $error = 'Sin permiso.';
            else {
                $stmt = $conn->prepare("DELETE FROM notificaciones WHERE id=?");
                $stmt->bind_param('i', $idDel);
                $stmt->execute();
                $stmt->close();
                $ok = 'Notificación borrada.';
            }
        }
        elseif ($accion === 'editar') {
            $idEd  = (int)($_POST['id_noti'] ?? 0);
            if ($idEd <= 0 || !noti_propia($conn, $idEd, $uid)) $error = 'Sin permiso.';
            else {
                $tipo  = $_POST['tipo']  ?? 'info';
                $texto = trim((string)($_POST['texto'] ?? ''));
                $url   = trim((string)($_POST['url']   ?? ''));
                $tiposOk = ['info','consulta','dieta','mensaje','objetivo','medida','checkin'];
                if (!in_array($tipo, $tiposOk, true)) $tipo = 'info';
                $url = $url === '' ? null : $url;

                if ($texto === '')               $error = 'El texto no puede quedar vacío.';
                elseif (mb_strlen($texto) > 500) $error = 'Texto demasiado largo.';
                else {
                    $stmt = $conn->prepare("UPDATE notificaciones SET tipo=?, texto=?, url=? WHERE id=?");
                    $stmt->bind_param('sssi', $tipo, $texto, $url, $idEd);
                    if ($stmt->execute()) $ok = 'Notificación actualizada.';
                    else $error = 'No se pudo actualizar.';
                    $stmt->close();
                }
            }
        }
        else {  // crear
            $tipo    = $_POST['tipo'] ?? 'info';
            $texto   = trim((string)($_POST['texto'] ?? ''));
            $url     = trim((string)($_POST['url']   ?? ''));
            $destino = $_POST['destino'] ?? '';
            $idCli   = (int)($_POST['id_cliente'] ?? 0);

            $tiposOk = ['info','consulta','dieta','mensaje','objetivo','medida','checkin'];
            if (!in_array($tipo, $tiposOk, true)) $tipo = 'info';
            if ($texto === '')               $error = 'Escribe un texto para la notificación.';
            elseif (mb_strlen($texto) > 500) $error = 'El texto es demasiado largo (máx 500 caracteres).';

            $url = $url === '' ? null : $url;

            $idsDestino = [];
            if (!$error) {
                if ($destino === 'cliente') {
                    if ($idCli <= 0) $error = 'Selecciona un cliente.';
                    else {
                        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
                        $stmt->bind_param('ii', $idCli, $uid);
                        $stmt->execute();
                        if (!$stmt->get_result()->fetch_assoc()) $error = 'Cliente no válido.';
                        else $idsDestino = [$idCli];
                        $stmt->close();
                    }
                }
                elseif ($destino === 'todos_mis_clientes') {
                    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id_dietista=? AND rol='cliente' AND activo=1");
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) $idsDestino[] = (int)$r['id'];
                    $stmt->close();
                }
                else $error = 'Destino no válido.';
            }

            if (!$error && empty($idsDestino)) $error = 'No tienes clientes activos para enviar la notificación.';

            if (!$error) {
                $stmt = $conn->prepare("INSERT INTO notificaciones (id_usuario, tipo, texto, url, leida) VALUES (?, ?, ?, ?, 0)");
                foreach ($idsDestino as $id) {
                    $stmt->bind_param('isss', $id, $tipo, $texto, $url);
                    if ($stmt->execute()) $enviadas++;
                }
                $stmt->close();
                $ok = "✅ Enviadas $enviadas notificacion" . ($enviadas === 1 ? '' : 'es') . '.';
            }
        }
    }
}

// Mis clientes
$stmt = $conn->prepare("SELECT id, nombre_completo, email FROM usuarios WHERE id_dietista=? AND rol='cliente' AND activo=1 ORDER BY nombre_completo");
$stmt->bind_param('i', $uid);
$stmt->execute();
$misClientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Historial (solo notis enviadas a mis clientes)
$ultimas = [];
if ($misClientes) {
    $ids = array_column($misClientes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tipos = str_repeat('i', count($ids));
    $stmt = $conn->prepare(
        "SELECT n.id, n.tipo, n.texto, n.url, n.fecha, n.leida, u.nombre_completo
         FROM notificaciones n JOIN usuarios u ON u.id = n.id_usuario
         WHERE n.id_usuario IN ($placeholders)
         ORDER BY n.fecha DESC LIMIT 50"
    );
    $stmt->bind_param($tipos, ...$ids);
    $stmt->execute();
    $ultimas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$base   = '../../';
$active = 'notificaciones';
$titulo = 'Notificaciones';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <header class="ck-header">
    <h2 class="h1">🔔 Notificaciones a clientes</h2>
    <p class="text-soft">Envía un aviso a un cliente o a todos. Aparecerá en su campanita.</p>
  </header>

  <form method="post" class="card noti-form">
    <h2 class="card-title">✉️ Crear notificación</h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="crear">

    <?php if (!$misClientes): ?>
      <p class="text-muted">Aún no tienes clientes asignados.</p>
    <?php else: ?>
      <label class="field"><span class="field-label">Tipo</span>
        <select class="field-select" name="tipo">
          <option value="info">🔔 Información</option>
          <option value="consulta">📅 Consulta</option>
          <option value="dieta">🍽️ Dieta</option>
          <option value="mensaje">💬 Mensaje</option>
          <option value="objetivo">🎯 Objetivo</option>
          <option value="medida">📏 Medida</option>
          <option value="checkin">📝 Check-in</option>
        </select>
      </label>
      <label class="field"><span class="field-label">Texto <span class="text-muted">(máx. 500)</span></span>
        <textarea class="field-textarea" name="texto" rows="3" maxlength="500" required></textarea>
      </label>
      <label class="field"><span class="field-label">URL <span class="text-muted">(opcional)</span></span>
        <input class="field-input" type="text" name="url" maxlength="255">
      </label>
      <label class="field"><span class="field-label">Destinatario</span>
        <select class="field-select" name="destino" id="destinoSel" required>
          <option value="">— Elegir —</option>
          <option value="cliente">👤 Un cliente concreto…</option>
          <option value="todos_mis_clientes">🥗 Todos mis clientes (<?= count($misClientes) ?>)</option>
        </select>
      </label>
      <label class="field" id="wrapCliente" hidden><span class="field-label">Selecciona cliente</span>
        <select class="field-select" name="id_cliente">
          <option value="">— Elegir cliente —</option>
          <?php foreach ($misClientes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre_completo']) ?> · <?= e($c['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary btn-block">📨 Enviar</button>
    <?php endif; ?>
  </form>

  <?php if ($ultimas): ?>
    <section class="card">
    <header class="dash-card-header">
      <h3 class="card-title">📜 Últimas enviadas</h3>
      <button type="button" class="btn btn-ghost btn-mini noti-borrar-todas-btn"
              id="btnBorrarTodasNotis" data-scope="admin">
        🗑️ Borrar todas
      </button>
    </header>
      <ul class="noti-history" role="list">
        <?php foreach ($ultimas as $n):
          $icono = icono_por_tipo_noti($n['tipo']);
        ?>
          <li class="noti-history-item<?= (int)$n['leida'] === 0 ? ' is-pending' : '' ?>"
              data-id="<?= (int)$n['id'] ?>"
              data-tipo="<?= e($n['tipo']) ?>"
              data-texto="<?= e($n['texto']) ?>"
              data-url="<?= e($n['url'] ?? '') ?>">
            <span class="noti-history-icon"><?= e($icono) ?></span>
            <div class="noti-history-body">
              <div class="noti-history-text"><?= e(mb_strimwidth($n['texto'], 0, 140, '…', 'UTF-8')) ?></div>
              <div class="noti-history-meta">
                Para: <strong><?= e($n['nombre_completo']) ?></strong>
                · <?= e(date('d/m H:i', strtotime($n['fecha']))) ?>
                <?php if ((int)$n['leida'] === 0): ?>
                  <span class="dt-pill dt-pill--warn">sin leer</span>
                <?php else: ?>
                  <span class="dt-pill dt-pill--ok">leída</span>
                <?php endif; ?>
              </div>
              <div class="noti-history-actions">
                <button type="button" class="noti-action-btn noti-edit" data-id="<?= (int)$n['id'] ?>">✏️ Editar</button>
                <button type="button" class="noti-action-btn noti-action-btn--danger noti-delete" data-id="<?= (int)$n['id'] ?>">🗑️ Borrar</button>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

</main>

<div id="modalEditNoti" class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h3 class="modal-title">✏️ Editar notificación</h3>
      <button type="button" class="modal-close" id="modalEditClose" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" class="noti-edit-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="accion" value="editar">
        <input type="hidden" name="id_noti" id="edId">

        <label class="field"><span class="field-label">Tipo</span>
          <select class="field-select" name="tipo" id="edTipo">
            <option value="info">🔔 Información</option>
            <option value="consulta">📅 Consulta</option>
            <option value="dieta">🍽️ Dieta</option>
            <option value="mensaje">💬 Mensaje</option>
            <option value="objetivo">🎯 Objetivo</option>
            <option value="medida">📏 Medida</option>
            <option value="checkin">📝 Check-in</option>
          </select>
        </label>
        <label class="field"><span class="field-label">Texto</span>
          <textarea class="field-textarea" name="texto" id="edTexto" rows="3" maxlength="500" required></textarea>
        </label>
        <label class="field"><span class="field-label">URL <span class="text-muted">(opcional)</span></span>
          <input class="field-input" type="text" name="url" id="edUrl" maxlength="255">
        </label>
        <button type="submit" class="btn btn-primary btn-block">💾 Guardar</button>
      </form>
    </div>
  </div>
</div>

<form method="post" id="formDelNoti" style="display:none;">
  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="accion" value="borrar">
  <input type="hidden" name="id_noti" id="delId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const CSRF = document.querySelector('input[name="_csrf"]').value;
  // Auto-detectar base: si la página está en /roles/admin/ o /roles/dietista/ → ../../
  const ENDPOINT = '../../ajax/ajax_notis_admin.php';

  // ============= Toggle del "elegir usuario/cliente" del form crear =============
  const selDest = document.getElementById('destinoSel');
  const wrapUser = document.getElementById('wrapUser') || document.getElementById('wrapCliente');
  if (selDest && wrapUser) {
    const valorEspecifico = wrapUser.id === 'wrapUser' ? 'usuario' : 'cliente';
    function toggle() { wrapUser.hidden = (selDest.value !== valorEspecifico); }
    selDest.addEventListener('change', toggle); toggle();
  }

  // ============= Util: toast/alert mini en pantalla =============
  function toast(msg, tipo) {
    const t = document.createElement('div');
    t.className = 'noti-toast noti-toast--' + (tipo || 'ok');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('is-show'));
    setTimeout(() => {
      t.classList.remove('is-show');
      setTimeout(() => t.remove(), 250);
    }, 2200);
  }

  // ============= POST helper =============
  async function postAjax(params) {
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    for (const k in params) fd.append(k, params[k] == null ? '' : params[k]);
    try {
      const r = await fetch(ENDPOINT, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      });
      return await r.json();
    } catch (e) { return { ok: false, error: 'red' }; }
  }

  // ============= EDITAR notificación (modal) =============
  const modal    = document.getElementById('modalEditNoti');
  const closeBtn = document.getElementById('modalEditClose');
  const formEd   = document.getElementById('formEditNoti') || modal?.querySelector('form');

  let liEdit = null;  // li que se está editando

  function abrirEdit(li) {
    liEdit = li;
    document.getElementById('edId').value    = li.dataset.id;
    document.getElementById('edTipo').value  = li.dataset.tipo  || 'info';
    document.getElementById('edTexto').value = li.dataset.texto || '';
    document.getElementById('edUrl').value   = li.dataset.url   || '';
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function cerrarEdit() { modal.hidden = true; document.body.style.overflow = ''; liEdit = null; }

  closeBtn && closeBtn.addEventListener('click', cerrarEdit);
  modal    && modal.addEventListener('click', e => { if (e.target === modal) cerrarEdit(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal && !modal.hidden) cerrarEdit(); });

  if (formEd) {
    formEd.addEventListener('submit', async function (e) {
      e.preventDefault();
      const id    = document.getElementById('edId').value;
      const tipo  = document.getElementById('edTipo').value;
      const texto = document.getElementById('edTexto').value.trim();
      const url   = document.getElementById('edUrl').value.trim();
      if (!texto) { toast('El texto no puede estar vacío', 'error'); return; }

      const btn = formEd.querySelector('button[type=submit]');
      btn.disabled = true;
      const r = await postAjax({ accion: 'editar', id, tipo, texto, url });
      btn.disabled = false;

      if (!r.ok) { toast('No se pudo actualizar', 'error'); return; }
      // Actualizar el li sin recargar
      if (liEdit) {
        liEdit.dataset.tipo  = r.tipo;
        liEdit.dataset.texto = r.texto;
        liEdit.dataset.url   = r.url || '';
        const icon = liEdit.querySelector('.noti-history-icon');
        const txt  = liEdit.querySelector('.noti-history-text');
        if (icon) icon.textContent = r.icono;
        if (txt)  txt.textContent  = r.texto.length > 140 ? r.texto.slice(0, 140) + '…' : r.texto;
      }
      cerrarEdit();
      toast('Notificación actualizada ✓', 'ok');
    });
  }

  // ============= Botones EDITAR / BORRAR de cada item =============
  function ligarItems(root) {
    (root || document).querySelectorAll('.noti-edit').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const li = btn.closest('.noti-history-item');
        if (li) abrirEdit(li);
      });
    });

    (root || document).querySelectorAll('.noti-delete').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        if (!confirm('¿Borrar esta notificación? No se puede deshacer.')) return;
        const li = btn.closest('.noti-history-item');
        const id = btn.dataset.id;
        btn.disabled = true;
        const r = await postAjax({ accion: 'borrar', id: id });
        if (!r.ok) { btn.disabled = false; toast('No se pudo borrar', 'error'); return; }
        // animar y eliminar del DOM
        li.style.transition = 'opacity .2s, transform .2s, max-height .3s';
        li.style.opacity = '0';
        li.style.transform = 'translateX(20px)';
        setTimeout(() => {
          li.style.maxHeight = '0';
          li.style.padding = '0';
          li.style.margin = '0';
          setTimeout(() => {
            li.remove();
            comprobarVacio();
          }, 250);
        }, 200);
        toast('Borrada ✓', 'ok');
      });
    });
  }
  ligarItems();

  // ============= BORRAR TODAS =============
  const btnBorrarTodas = document.getElementById('btnBorrarTodasNotis');
  if (btnBorrarTodas) {
    btnBorrarTodas.addEventListener('click', async () => {
      const msg = btnBorrarTodas.dataset.scope === 'admin'
        ? '⚠️ ¿Borrar TODAS las notificaciones del sistema? Esto afectará a todos los usuarios y no se puede deshacer.'
        : '⚠️ ¿Borrar TODAS las notificaciones que enviaste a tus clientes? No se puede deshacer.';
      if (!confirm(msg)) return;
      if (!confirm('Confirma de nuevo. Esta acción es DEFINITIVA.')) return;

      btnBorrarTodas.disabled = true;
      const r = await postAjax({ accion: 'borrar_todas' });
      btnBorrarTodas.disabled = false;
      if (!r.ok) { toast('No se pudieron borrar', 'error'); return; }

      // Quitar TODOS los items del histórico
      document.querySelectorAll('.noti-history-item').forEach(li => li.remove());
      comprobarVacio();
      toast('Borradas ' + (r.count || 0) + ' notificaciones', 'ok');
    });
  }

  // ============= Estado vacío =============
  function comprobarVacio() {
    const cont = document.querySelector('.noti-history');
    if (!cont) return;
    if (cont.querySelectorAll('.noti-history-item').length === 0) {
      cont.innerHTML = '<li class="text-muted" style="padding:var(--sp-3); text-align:center; list-style:none;">No quedan notificaciones.</li>';
      if (btnBorrarTodas) btnBorrarTodas.hidden = true;
    }
  }
});
</script>


<?php include __DIR__ . '/../../includes/footer.php'; ?>