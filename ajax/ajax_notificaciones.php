<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) {
    json_out(['ok' => false, 'error' => 'sesion'], 401);
}

$uid    = usuario_id();
$accion = $_GET['accion'] ?? 'listar';

switch ($accion) {

    // ------- Listar últimas notificaciones del usuario -------
    case 'listar':
        $stmt = $conn->prepare(
            "SELECT id, tipo, texto, url, leida, fecha
             FROM notificaciones
             WHERE id_usuario = ?
             ORDER BY fecha DESC
             LIMIT 20"
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($n = $res->fetch_assoc()) {
            $items[] = [
                'id'     => (int)$n['id'],
                'tipo'   => $n['tipo'],
                'texto'  => $n['texto'],
                'url'    => $n['url'],
                'leida'  => (int)$n['leida'] === 1,
                'tiempo' => tiempo_relativo($n['fecha']),
                'icono'  => icono_por_tipo($n['tipo']),
            ];
        }
        $stmt->close();

        $r2 = $conn->prepare("SELECT COUNT(*) c FROM notificaciones WHERE id_usuario = ? AND leida = 0");
        $r2->bind_param('i', $uid);
        $r2->execute();
        $noLeidas = (int)($r2->get_result()->fetch_assoc()['c'] ?? 0);
        $r2->close();

        json_out(['ok' => true, 'items' => $items, 'no_leidas' => $noLeidas]);
        break;

    // ------- Marcar TODAS como leídas -------
    case 'marcar_leidas':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'metodo'], 405);
        $stmt = $conn->prepare("UPDATE notificaciones SET leida = 1 WHERE id_usuario = ? AND leida = 0");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
        json_out(['ok' => true]);
        break;

    // ------- Borrar UNA notificación -------
    case 'borrar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'metodo'], 405);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'error' => 'id'], 400);
        $stmt = $conn->prepare("DELETE FROM notificaciones WHERE id = ? AND id_usuario = ?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok' => $af > 0]);
        break;

    // ------- Borrar TODAS las notificaciones del usuario -------
    case 'borrar_todas':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'metodo'], 405);
        $stmt = $conn->prepare("DELETE FROM notificaciones WHERE id_usuario = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok' => true, 'count' => $count]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'accion'], 400);
}

// ============================================================
function tiempo_relativo(string $fecha): string {
    $ts  = strtotime($fecha);
    $dif = time() - $ts;
    if ($dif < 60)        return 'hace un momento';
    if ($dif < 3600)      return 'hace ' . floor($dif / 60)   . ' min';
    if ($dif < 86400)     return 'hace ' . floor($dif / 3600) . ' h';
    if ($dif < 86400 * 7) return 'hace ' . floor($dif / 86400). ' d';
    return date('d/m/Y', $ts);
}

function icono_por_tipo(?string $tipo): string {
    switch ($tipo) {
        case 'consulta':  return '📅';
        case 'dieta':     return '🍽️';
        case 'mensaje':   return '💬';
        case 'objetivo':  return '🎯';
        case 'medida':    return '📏';
        case 'checkin':   return '📝';
        default:          return '🔔';
    }
}