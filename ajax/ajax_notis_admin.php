<?php
/**
 * /ajax/ajax_notis_admin.php
 * Gestión de notificaciones desde el panel del admin / dietista.
 *
 * Acciones:
 *   - editar         (POST: id, tipo, texto, url)
 *   - borrar         (POST: id)
 *   - borrar_todas   (POST: solo borra las que el rol tenga permiso)
 *
 * Permisos:
 *   - admin   : sobre TODAS las notificaciones del sistema
 *   - dietista: solo sobre las dirigidas a clientes propios
 */
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok'=>false,'error'=>'sesion'], 401);

$uid = usuario_id();
$rol = rol_actual();
if (!in_array($rol, ['admin','dietista'], true)) json_out(['ok'=>false,'error'=>'permiso'], 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

$accion = $_POST['accion'] ?? '';
$tiposOk = ['info','consulta','dieta','mensaje','objetivo','medida','checkin'];

/**
 * Verifica si el usuario actual tiene permiso para tocar la noti $idNoti.
 */
function puedo_tocar(mysqli $conn, int $idNoti, string $rol, int $uid): bool {
    if ($idNoti <= 0) return false;
    if ($rol === 'admin') {
        $stmt = $conn->prepare("SELECT id FROM notificaciones WHERE id = ?");
        $stmt->bind_param('i', $idNoti);
    } else { // dietista
        $stmt = $conn->prepare(
            "SELECT n.id FROM notificaciones n
             JOIN usuarios u ON u.id = n.id_usuario
             WHERE n.id = ? AND u.id_dietista = ? AND u.rol = 'cliente'"
        );
        $stmt->bind_param('ii', $idNoti, $uid);
    }
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

switch ($accion) {

    // -------- BORRAR UNA --------
    case 'borrar': {
        $id = (int)($_POST['id'] ?? 0);
        if (!puedo_tocar($conn, $id, $rol, $uid)) json_out(['ok'=>false,'error'=>'permiso'], 403);

        $stmt = $conn->prepare("DELETE FROM notificaciones WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        if ($rol === 'admin') log_admin($conn, $uid, 'NOTIFICACION_BORRADA', "id=$id");
        json_out(['ok' => $af > 0]);
        break;
    }

    // -------- EDITAR --------
    case 'editar': {
        $id    = (int)($_POST['id'] ?? 0);
        $tipo  = $_POST['tipo'] ?? 'info';
        $texto = trim((string)($_POST['texto'] ?? ''));
        $url   = trim((string)($_POST['url']   ?? ''));

        if (!puedo_tocar($conn, $id, $rol, $uid)) json_out(['ok'=>false,'error'=>'permiso'], 403);
        if (!in_array($tipo, $tiposOk, true))    $tipo = 'info';
        if ($texto === '')                       json_out(['ok'=>false,'error'=>'texto_vacio']);
        if (mb_strlen($texto) > 500)             json_out(['ok'=>false,'error'=>'texto_largo']);
        $url = ($url === '') ? null : $url;

        $stmt = $conn->prepare("UPDATE notificaciones SET tipo=?, texto=?, url=? WHERE id=?");
        $stmt->bind_param('sssi', $tipo, $texto, $url, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($rol === 'admin') log_admin($conn, $uid, 'NOTIFICACION_EDITADA', "id=$id");

        $iconos = ['consulta'=>'📅','dieta'=>'🍽️','mensaje'=>'💬','objetivo'=>'🎯','medida'=>'📏','checkin'=>'📝'];
        $icono  = $iconos[$tipo] ?? '🔔';
        json_out(['ok'=>$ok, 'tipo'=>$tipo, 'texto'=>$texto, 'url'=>$url, 'icono'=>$icono]);
        break;
    }

    // -------- BORRAR TODAS --------
    case 'borrar_todas': {
        if ($rol === 'admin') {
            $count = (int)$conn->query("SELECT COUNT(*) c FROM notificaciones")->fetch_assoc()['c'];
            $conn->query("DELETE FROM notificaciones");
            log_admin($conn, $uid, 'NOTIFICACIONES_BORRADAS_TODAS', "Borradas $count");
            json_out(['ok'=>true, 'count'=>$count]);
        } else {
            // Solo las dirigidas a clientes del dietista
            $stmt = $conn->prepare(
                "DELETE n FROM notificaciones n
                 JOIN usuarios u ON u.id = n.id_usuario
                 WHERE u.id_dietista = ? AND u.rol = 'cliente'"
            );
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $count = $stmt->affected_rows;
            $stmt->close();
            json_out(['ok'=>true, 'count'=>$count]);
        }
        break;
    }

    default:
        json_out(['ok'=>false,'error'=>'accion'], 400);
}
