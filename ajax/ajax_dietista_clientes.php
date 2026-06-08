<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok' => false, 'error' => 'sesion'], 401);

$rol = rol_actual();
$uid = usuario_id();

if (!in_array($rol, ['dietista', 'admin'], true)) {
    json_out(['ok' => false, 'error' => 'permiso'], 403);
}

$accion = $_GET['accion'] ?? 'listar';

switch ($accion) {

    // ---------- Listar / buscar ----------
    case 'listar': {
        $q     = trim($_GET['q'] ?? '');
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));

        // El admin puede ver todos; el dietista solo los suyos
        if ($rol === 'dietista') {
            if ($q !== '') {
                $sql = "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad
                        FROM usuarios
                        WHERE rol='cliente' AND id_dietista=? AND activo=1
                          AND (nombre_completo LIKE ? OR email LIKE ?)
                        ORDER BY nombre_completo ASC LIMIT ?";
                $like = '%' . $q . '%';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('issi', $uid, $like, $like, $limit);
            } else {
                $sql = "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad
                        FROM usuarios
                        WHERE rol='cliente' AND id_dietista=? AND activo=1
                        ORDER BY nombre_completo ASC LIMIT ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ii', $uid, $limit);
            }
        } else { // admin
            if ($q !== '') {
                $sql = "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad, id_dietista
                        FROM usuarios
                        WHERE rol='cliente' AND activo=1
                          AND (nombre_completo LIKE ? OR email LIKE ?)
                        ORDER BY nombre_completo ASC LIMIT ?";
                $like = '%' . $q . '%';
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssi', $like, $like, $limit);
            } else {
                $sql = "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad, id_dietista
                        FROM usuarios WHERE rol='cliente' AND activo=1
                        ORDER BY nombre_completo ASC LIMIT ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $limit);
            }
        }
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    // ---------- Detalle (con resumen para tarjeta) ----------
    case 'detalle': {
        $idC = (int)($_GET['id_cliente'] ?? 0);
        if ($idC <= 0) json_out(['ok' => false, 'error' => 'id'], 400);

        // Comprobar permiso
        if ($rol === 'dietista') {
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
            $stmt->bind_param('ii', $idC, $uid);
            $stmt->execute();
            $ok = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$ok) json_out(['ok' => false, 'error' => 'permiso'], 403);
        }

        // Cliente
        $stmt = $conn->prepare(
            "SELECT id, nombre_completo, email, fecha_registro, ultima_actividad, id_dietista
             FROM usuarios WHERE id=? AND rol='cliente'"
        );
        $stmt->bind_param('i', $idC);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$c) json_out(['ok' => false, 'error' => 'no_existe'], 404);

        // Peso actual e inicial
        $stmt = $conn->prepare(
            "SELECT
               (SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora ASC  LIMIT 1) AS peso_ini,
               (SELECT peso_kg FROM progresos_metricas WHERE id_cliente=? ORDER BY fecha_hora DESC LIMIT 1) AS peso_act,
               (SELECT COUNT(*) FROM progresos_metricas WHERE id_cliente=?) AS n_pesos,
               (SELECT COUNT(*) FROM objetivos WHERE id_cliente=? AND estado='activo') AS n_objetivos,
               (SELECT MAX(semana_inicio) FROM checkins_semanales WHERE id_cliente=?) AS ult_checkin"
        );
        $stmt->bind_param('iiiii', $idC, $idC, $idC, $idC, $idC);
        $stmt->execute();
        $resumen = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        json_out(['ok' => true, 'cliente' => $c, 'resumen' => $resumen]);
    } break;

    default:
        json_out(['ok' => false, 'error' => 'accion'], 400);
}