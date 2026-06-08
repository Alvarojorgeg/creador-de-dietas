<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok' => false, 'error' => 'sesion'], 401);

$rol = rol_actual();
$uid = usuario_id();

$accion = $_GET['accion'] ?? 'listar';

$tiposVal = ['inicial','seguimiento','revision','rescate'];

/** Verifica que el dietista puede operar sobre el cliente dado */
function dt_puede_sobre_cliente(mysqli $conn, int $uid, int $idCliente): bool {
    if ($idCliente <= 0) return false;
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
    $stmt->bind_param('ii', $idCliente, $uid);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

switch ($accion) {

    // ---------- Listar consultas (filtros opcionales) ----------
    case 'listar': {
        $filtro    = $_GET['filtro']     ?? 'todas'; // todas | proximas | pasadas
        $idClienteF = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;

        // cliente solo ve las suyas
        $where = '';
        $params = []; $tipos = '';

        if ($rol === 'cliente') {
            $where = "c.id_cliente = ?";
            $params[] = $uid; $tipos .= 'i';
        } elseif ($rol === 'dietista') {
            $where = "c.id_dietista = ?";
            $params[] = $uid; $tipos .= 'i';
            if ($idClienteF > 0) {
                $where .= " AND c.id_cliente = ?";
                $params[] = $idClienteF; $tipos .= 'i';
            }
        } elseif ($rol === 'admin') {
            $where = "1=1";
            if ($idClienteF > 0) {
                $where .= " AND c.id_cliente = ?";
                $params[] = $idClienteF; $tipos .= 'i';
            }
        }

        if ($filtro === 'proximas')     $where .= " AND c.fecha >= NOW()";
        elseif ($filtro === 'pasadas')  $where .= " AND c.fecha <  NOW()";

        $order = $filtro === 'pasadas' ? "c.fecha DESC" : "c.fecha ASC";

        $sql = "SELECT c.id, c.fecha, c.duracion_min, c.tipo, c.asistio, c.proxima_cita,
                       c.id_cliente, c.id_dietista,
                       uc.nombre_completo AS cliente,
                       ud.nombre_completo AS dietista
                FROM consultas c
                JOIN usuarios uc ON uc.id = c.id_cliente
                JOIN usuarios ud ON ud.id = c.id_dietista
                WHERE $where
                ORDER BY $order
                LIMIT 200";
        $stmt = $conn->prepare($sql);
        if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    // ---------- Detalle ----------
    case 'detalle': {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT c.*, uc.nombre_completo AS cliente, ud.nombre_completo AS dietista
             FROM consultas c
             JOIN usuarios uc ON uc.id = c.id_cliente
             JOIN usuarios ud ON ud.id = c.id_dietista
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$c) json_out(['ok' => false, 'error' => 'no_existe'], 404);

        // Permisos
        if ($rol === 'cliente' && (int)$c['id_cliente']  !== $uid) json_out(['ok'=>false,'error'=>'permiso'], 403);
        if ($rol === 'dietista' && (int)$c['id_dietista'] !== $uid) json_out(['ok'=>false,'error'=>'permiso'], 403);

        // El cliente NO puede ver notas_privadas
        if ($rol === 'cliente') unset($c['notas_privadas']);

        json_out(['ok' => true, 'item' => $c]);
    } break;

    // ---------- Crear (solo dietista) ----------
    case 'crear': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        if ($rol !== 'dietista') json_out(['ok'=>false,'error'=>'permiso'], 403);

        $idCliente = (int)($_POST['id_cliente'] ?? 0);
        if (!dt_puede_sobre_cliente($conn, $uid, $idCliente)) {
            json_out(['ok' => false, 'error' => 'cliente'], 400);
        }

        $fecha     = str_replace('T', ' ', (string)($_POST['fecha'] ?? ''));
        if (strlen($fecha) === 16) $fecha .= ':00';
        $duracion  = (int)($_POST['duracion_min'] ?? 30);
        $tipo      = $_POST['tipo'] ?? 'seguimiento';
        $asistio   = isset($_POST['asistio']) ? 1 : 0;
        $notasPriv = trim($_POST['notas_privadas']    ?? '');
        $notasComp = trim($_POST['notas_compartidas'] ?? '');
        $plan      = trim($_POST['plan_siguiente']    ?? '');
        $proxCita  = $_POST['proxima_cita'] ?? null;
        if ($proxCita === '') $proxCita = null;

        if (!in_array($tipo, $tiposVal, true))    json_out(['ok'=>false,'error'=>'tipo'], 400);
        if (strtotime($fecha) === false)          json_out(['ok'=>false,'error'=>'fecha'], 400);
        if ($duracion < 5 || $duracion > 600)     json_out(['ok'=>false,'error'=>'duracion'], 400);

        $stmt = $conn->prepare(
            "INSERT INTO consultas
               (id_cliente, id_dietista, fecha, duracion_min, tipo, asistio,
                notas_privadas, notas_compartidas, plan_siguiente, proxima_cita)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('iisisissss',
            $idCliente, $uid, $fecha, $duracion, $tipo, $asistio,
            $notasPriv, $notasComp, $plan, $proxCita);
        $ok = $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        json_out(['ok' => $ok, 'id' => $newId]);
    } break;

    // ---------- Actualizar (solo dietista propietario) ----------
    case 'actualizar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        if ($rol !== 'dietista') json_out(['ok'=>false,'error'=>'permiso'], 403);

        $id        = (int)($_POST['id'] ?? 0);
        $fecha     = str_replace('T', ' ', (string)($_POST['fecha'] ?? ''));
        if (strlen($fecha) === 16) $fecha .= ':00';
        $duracion  = (int)($_POST['duracion_min'] ?? 30);
        $tipo      = $_POST['tipo'] ?? 'seguimiento';
        $asistio   = isset($_POST['asistio']) ? 1 : 0;
        $notasPriv = trim($_POST['notas_privadas']    ?? '');
        $notasComp = trim($_POST['notas_compartidas'] ?? '');
        $plan      = trim($_POST['plan_siguiente']    ?? '');
        $proxCita  = $_POST['proxima_cita'] ?? null;
        if ($proxCita === '') $proxCita = null;

        if (!in_array($tipo, $tiposVal, true))    json_out(['ok'=>false,'error'=>'tipo'], 400);
        if (strtotime($fecha) === false)          json_out(['ok'=>false,'error'=>'fecha'], 400);

        $stmt = $conn->prepare(
            "UPDATE consultas SET fecha=?, duracion_min=?, tipo=?, asistio=?,
                notas_privadas=?, notas_compartidas=?, plan_siguiente=?, proxima_cita=?
             WHERE id=? AND id_dietista=?"
        );
        $stmt->bind_param('sisissssii',
            $fecha, $duracion, $tipo, $asistio,
            $notasPriv, $notasComp, $plan, $proxCita, $id, $uid);
        $ok = $stmt->execute();
        $stmt->close();
        json_out(['ok' => $ok]);
    } break;

    // ---------- Borrar (solo dietista propietario) ----------
    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        if ($rol !== 'dietista') json_out(['ok'=>false,'error'=>'permiso'], 403);

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM consultas WHERE id=? AND id_dietista=?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok' => $af > 0]);
    } break;

    default:
        json_out(['ok' => false, 'error' => 'accion'], 400);
}