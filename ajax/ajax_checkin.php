<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) {
    json_out(['ok' => false, 'error' => 'sesion'], 401);
}

$uid   = usuario_id();
$rol   = rol_actual();
$accion = $_GET['accion'] ?? 'listar';

/**
 * Determina sobre qué cliente trabajamos.
 * - cliente: siempre sobre sí mismo.
 * - dietista: puede pedir ?id_cliente=X si ese cliente está asignado a él.
 * - admin: puede pedir cualquier ?id_cliente=X.
 */
function cliente_destino(mysqli $conn, int $uid, string $rol): int {
    if ($rol === 'cliente') return $uid;

    $idPedido = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
    if ($idPedido <= 0) return 0;

    if ($rol === 'admin') return $idPedido;

    if ($rol === 'dietista') {
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
        $stmt->bind_param('ii', $idPedido, $uid);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok ? $idPedido : 0;
    }
    return 0;
}

$idCliente = cliente_destino($conn, $uid, $rol);
if ($idCliente <= 0) {
    json_out(['ok' => false, 'error' => 'permiso'], 403);
}

switch ($accion) {

    // ------- Histórico (últimas N semanas) -------
    case 'listar': {
        $limit = max(1, min(52, (int)($_GET['limit'] ?? 12)));
        $stmt = $conn->prepare(
            "SELECT id, semana_inicio, hambre, energia, sueno, cumplimiento_dieta, animo,
                    observaciones, fecha_registro
             FROM checkins_semanales
             WHERE id_cliente = ?
             ORDER BY semana_inicio DESC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $idCliente, $limit);
        $stmt->execute();
        $items = [];
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'id'             => (int)$r['id'],
                'semana_inicio'  => $r['semana_inicio'],
                'hambre'         => (int)$r['hambre'],
                'energia'        => (int)$r['energia'],
                'sueno'          => (int)$r['sueno'],
                'cumplimiento'   => (int)$r['cumplimiento_dieta'],
                'animo'          => (int)$r['animo'],
                'media'          => round((
                    $r['hambre'] + $r['energia'] + $r['sueno'] +
                    $r['cumplimiento_dieta'] + $r['animo']
                ) / 5, 1),
                'observaciones'  => (string)($r['observaciones'] ?? ''),
                'fecha_registro' => $r['fecha_registro'],
            ];
        }
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    // ------- Check-in de la semana actual -------
    case 'actual': {
        $hoy   = new DateTime('today');
        $dow   = (int)$hoy->format('N');
        $lunes = (clone $hoy)->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');

        $stmt = $conn->prepare(
            "SELECT * FROM checkins_semanales
             WHERE id_cliente = ? AND semana_inicio = ? LIMIT 1"
        );
        $stmt->bind_param('is', $idCliente, $lunes);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        json_out([
            'ok' => true,
            'semana_inicio' => $lunes,
            'completado'    => (bool)$r,
            'datos'         => $r ?: null,
        ]);
    } break;

    // ------- Borrar (solo el propio cliente sobre sí mismo) -------
    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_out(['ok' => false, 'error' => 'metodo'], 405);
        }
        if ($rol !== 'cliente' || $idCliente !== $uid) {
            json_out(['ok' => false, 'error' => 'permiso'], 403);
        }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM checkins_semanales WHERE id=? AND id_cliente=?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $afect = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok' => $afect > 0]);
    } break;

    default:
        json_out(['ok' => false, 'error' => 'accion'], 400);
}