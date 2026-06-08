<?php
require_once __DIR__ . '/includes/conexion.php';
requiere_login('');

$uid = usuario_id();
$rol = rol_actual();

/**
 * Comprueba si el usuario actual puede hablar con otro.
 *  - cliente <-> su dietista asignado
 *  - dietista <-> sus clientes asignados, o cualquier admin
 *  - admin <-> cualquiera (para soporte)
 */
function puede_chatear(mysqli $conn, int $uid, string $rol, int $otroId): bool {
    if ($otroId <= 0 || $otroId === $uid) return false;
    $stmt = $conn->prepare("SELECT id, rol, id_dietista FROM usuarios WHERE id=?");
    $stmt->bind_param('i', $otroId);
    $stmt->execute();
    $otro = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$otro) return false;

    if ($rol === 'admin')    return true;
    if ($rol === 'cliente')  return ($otro['rol'] === 'dietista' || $otro['rol'] === 'admin');
    if ($rol === 'dietista') {
        if ($otro['rol'] === 'cliente' && (int)$otro['id_dietista'] === $uid) return true;
        if ($otro['rol'] === 'admin') return true;
        return false;
    }
    return false;
}

// ============================================================
// ENDPOINT AJAX (?ajax=1) — devuelve mensajes nuevos en JSON
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $con   = (int)($_GET['con'] ?? 0);
    $desde = (int)($_GET['desde'] ?? 0);
    if (!puede_chatear($conn, $uid, $rol, $con)) json_out(['ok'=>false,'error'=>'permiso'], 403);

    $stmt = $conn->prepare(
        "SELECT id, id_remitente, id_destinatario, mensaje, fecha_hora, leido
         FROM chats_mensajes
         WHERE ((id_remitente=? AND id_destinatario=?) OR (id_remitente=? AND id_destinatario=?))
           AND id > ?
         ORDER BY id ASC
         LIMIT 200"
    );
    $stmt->bind_param('iiiii', $uid, $con, $con, $uid, $desde);
    $stmt->execute();
    $msgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Marcar como leídos los que me llegan
    $u = $conn->prepare(
        "UPDATE chats_mensajes SET leido=1
         WHERE id_destinatario=? AND id_remitente=? AND leido=0"
    );
    $u->bind_param('ii', $uid, $con);
    $u->execute();
    $u->close();

    json_out(['ok'=>true, 'mensajes'=>$msgs, 'yo'=>$uid]);
}

// ============================================================
// POST: enviar mensaje
// ============================================================
$enviado_ok = false;
$error_env = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error_env = 'Sesión expirada.';
    } else {
        $destino = (int)($_POST['destino'] ?? 0);
        $msg     = trim((string)($_POST['mensaje'] ?? ''));
        if ($msg === '' || mb_strlen($msg) > 2000) {
            $error_env = 'El mensaje está vacío o es demasiado largo.';
        } elseif (!puede_chatear($conn, $uid, $rol, $destino)) {
            $error_env = 'No puedes enviar mensajes a ese usuario.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO chats_mensajes (id_remitente, id_destinatario, mensaje) VALUES (?,?,?)"
            );
            $stmt->bind_param('iis', $uid, $destino, $msg);
            $enviado_ok = $stmt->execute();
            $stmt->close();

            // Si vino por AJAX, devolver JSON
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json; charset=utf-8');
                json_out(['ok'=>$enviado_ok]);
            }
        }
    }
    // Redirección PRG (post-redirect-get) para evitar reenvío al refrescar
    if ($enviado_ok && empty($_POST['ajax'])) {
        header('Location: chats.php?con=' . (int)$_POST['destino']);
        exit;
    }
}

// ============================================================
// DETERMINAR INTERLOCUTOR (?con=ID)
// ============================================================
$conId = isset($_GET['con']) ? (int)$_GET['con'] : 0;

// Cliente: si no se especifica, auto-elige su dietista
if ($conId === 0 && $rol === 'cliente') {
    $stmt = $conn->prepare("SELECT id_dietista FROM usuarios WHERE id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r && $r['id_dietista']) $conId = (int)$r['id_dietista'];
}

$interlocutor = null;
if ($conId > 0 && puede_chatear($conn, $uid, $rol, $conId)) {
    $stmt = $conn->prepare("SELECT id, nombre_completo, rol FROM usuarios WHERE id=?");
    $stmt->bind_param('i', $conId);
    $stmt->execute();
    $interlocutor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ============================================================
// CARGAR LISTADO DE CONVERSACIONES (dietista/admin)
// ============================================================
$conversaciones = [];
if (in_array($rol, ['dietista','admin'], true)) {
    if ($rol === 'dietista') {
        // Sus clientes asignados (aunque no hayan escrito aún) + cualquiera con quien haya hablado
        $sql = "SELECT u.id, u.nombre_completo, u.rol,
                       MAX(m.fecha_hora) AS ultimo,
                       (SELECT COUNT(*) FROM chats_mensajes m2
                        WHERE m2.id_remitente=u.id AND m2.id_destinatario=? AND m2.leido=0) AS no_leidos
                FROM usuarios u
                LEFT JOIN chats_mensajes m
                  ON (m.id_remitente=u.id AND m.id_destinatario=?)
                  OR (m.id_destinatario=u.id AND m.id_remitente=?)
                WHERE (u.id_dietista=? AND u.rol='cliente') OR u.rol='admin'
                GROUP BY u.id
                ORDER BY (ultimo IS NULL), ultimo DESC, u.nombre_completo ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiii', $uid, $uid, $uid, $uid);
    } else { // admin
        $sql = "SELECT u.id, u.nombre_completo, u.rol,
                       MAX(m.fecha_hora) AS ultimo,
                       (SELECT COUNT(*) FROM chats_mensajes m2
                        WHERE m2.id_remitente=u.id AND m2.id_destinatario=? AND m2.leido=0) AS no_leidos
                FROM usuarios u
                LEFT JOIN chats_mensajes m
                  ON (m.id_remitente=u.id AND m.id_destinatario=?)
                  OR (m.id_destinatario=u.id AND m.id_remitente=?)
                WHERE u.id <> ? AND u.activo=1
                GROUP BY u.id
                ORDER BY (ultimo IS NULL), ultimo DESC, u.nombre_completo ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiii', $uid, $uid, $uid, $uid);
    }
    $stmt->execute();
    $conversaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ============================================================
// CARGAR MENSAJES (si hay interlocutor seleccionado)
// ============================================================
$mensajes = [];
if ($interlocutor) {
    $stmt = $conn->prepare(
        "SELECT id, id_remitente, id_destinatario, mensaje, fecha_hora, leido
         FROM chats_mensajes
         WHERE (id_remitente=? AND id_destinatario=?) OR (id_remitente=? AND id_destinatario=?)
         ORDER BY id ASC
         LIMIT 500"
    );
    $stmt->bind_param('iiii', $uid, $conId, $conId, $uid);
    $stmt->execute();
    $mensajes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Marcar como leídos los recibidos
    $u = $conn->prepare(
        "UPDATE chats_mensajes SET leido=1
         WHERE id_destinatario=? AND id_remitente=? AND leido=0"
    );
    $u->bind_param('ii', $uid, $conId);
    $u->execute();
    $u->close();
}

$base   = '';   // chats.php vive en raíz
$active = 'chat';
$titulo = $interlocutor ? $interlocutor['nombre_completo'] : 'Chat';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="page chats-page">

  <?php if ($error_env): ?>
    <div class="alert alert-danger" role="alert"><?= e($error_env) ?></div>
  <?php endif; ?>

  <div class="chats-layout">

    <!-- Lista de conversaciones (solo dietista/admin) -->
    <?php if (in_array($rol, ['dietista','admin'], true)): ?>
      <aside class="chats-list">
        <?php if (!$conversaciones): ?>
          <p class="text-muted">Aún no hay conversaciones.</p>
        <?php else: foreach ($conversaciones as $c):
          $sel = $interlocutor && (int)$c['id'] === (int)$interlocutor['id']; ?>
          <a class="chats-list-item<?= $sel ? ' is-active' : '' ?>" href="?con=<?= (int)$c['id'] ?>">
            <div class="chats-avatar">
              <?= e(mb_strtoupper(mb_substr($c['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
            </div>
            <div class="chats-list-info">
              <div class="chats-list-name">
                <?= e($c['nombre_completo']) ?>
                <span class="chats-list-role"><?= e(ucfirst($c['rol'])) ?></span>
              </div>
              <?php if (!empty($c['ultimo'])): ?>
                <div class="chats-list-time"><?= e(date('d/m H:i', strtotime($c['ultimo']))) ?></div>
              <?php endif; ?>
            </div>
            <?php if ((int)$c['no_leidos'] > 0): ?>
              <span class="chats-badge"><?= (int)$c['no_leidos'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; endif; ?>
      </aside>
    <?php endif; ?>

    <!-- Panel de conversación -->
    <section class="chats-room">
      <?php if (!$interlocutor): ?>
        <div class="chats-empty">
          <p class="text-muted">
            <?php if ($rol === 'cliente'): ?>
              No tienes dietista asignado todavía. Cuando lo tengas, podrás hablar con él/ella desde aquí.
            <?php else: ?>
              Selecciona una conversación de la lista para empezar.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>

        <header class="chats-head">
          <div class="chats-avatar">
            <?= e(mb_strtoupper(mb_substr($interlocutor['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
          </div>
          <div>
            <div class="chats-head-name"><?= e($interlocutor['nombre_completo']) ?></div>
            <div class="chats-head-role"><?= e(ucfirst($interlocutor['rol'])) ?></div>
          </div>
        </header>

        <div class="chats-stream"
             id="chatStream"
             data-con="<?= (int)$interlocutor['id'] ?>"
             data-yo="<?= (int)$uid ?>"
             data-last="<?= !empty($mensajes) ? (int)end($mensajes)['id'] : 0 ?>">
          <?php if (!$mensajes): ?>
            <p class="chats-empty-stream text-muted">Aún no hay mensajes. ¡Rompe el hielo!</p>
          <?php else: foreach ($mensajes as $m):
            $mio = ((int)$m['id_remitente'] === $uid); ?>
            <div class="chats-msg<?= $mio ? ' is-mine' : '' ?>" data-id="<?= (int)$m['id'] ?>">
              <div class="chats-msg-bubble"><?= nl2br(e($m['mensaje'])) ?></div>
              <div class="chats-msg-time"><?= e(date('H:i', strtotime($m['fecha_hora']))) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <form method="post" class="chats-form" id="chatForm">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="accion" value="enviar">
          <input type="hidden" name="destino" value="<?= (int)$interlocutor['id'] ?>">
          <textarea class="chats-input" name="mensaje" placeholder="Escribe un mensaje..." rows="1" maxlength="2000" required></textarea>
          <button type="submit" class="chats-send" aria-label="Enviar">➤</button>
        </form>

      <?php endif; ?>
    </section>
  </div>

</main>

<script src="<?= e($base) ?>js/chats.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>