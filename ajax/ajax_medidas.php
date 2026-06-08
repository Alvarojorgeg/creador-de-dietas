<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) {
    json_out(['ok' => false, 'error' => 'sesion'], 401);
}

$accion    = $_GET['accion'] ?? 'listar';
$idCliente = cliente_destino_actual($conn);
if ($idCliente <= 0) json_out(['ok' => false, 'error' => 'permiso'], 403);

$camposNum = ['cintura','cadera','pecho','cuello','hombros','brazo_izq','brazo_der','muslo_izq','muslo_der','pantorrilla'];

switch ($accion) {

    case 'listar': {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $stmt = $conn->prepare(
            "SELECT id, fecha, cintura, cadera, pecho, cuello, hombros,
                    brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas
             FROM medidas_corporales
             WHERE id_cliente = ?
             ORDER BY fecha DESC, id DESC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $idCliente, $limit);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    case 'ultima': {
        $stmt = $conn->prepare(
            "SELECT * FROM medidas_corporales WHERE id_cliente = ?
             ORDER BY fecha DESC, id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $idCliente);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        json_out(['ok' => true, 'item' => $r ?: null]);
    } break;

    case 'guardar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $idEdit = (int)($_POST['id'] ?? 0);
        $fecha  = $_POST['fecha'] ?? date('Y-m-d');
        $notas  = trim($_POST['notas'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

        $vals = [];
        foreach ($camposNum as $c) {
            $v = $_POST[$c] ?? '';
            $vals[$c] = ($v === '' || $v === null) ? null : (float)$v;
        }

        if ($idEdit > 0) {
            $stmt = $conn->prepare(
                "UPDATE medidas_corporales SET fecha=?, cintura=?, cadera=?, pecho=?, cuello=?,
                    hombros=?, brazo_izq=?, brazo_der=?, muslo_izq=?, muslo_der=?, pantorrilla=?, notas=?
                 WHERE id=? AND id_cliente=?"
            );
            $stmt->bind_param('sddddddddddsii',
                $fecha,
                $vals['cintura'], $vals['cadera'], $vals['pecho'], $vals['cuello'],
                $vals['hombros'], $vals['brazo_izq'], $vals['brazo_der'],
                $vals['muslo_izq'], $vals['muslo_der'], $vals['pantorrilla'],
                $notas, $idEdit, $idCliente);
            $ok = $stmt->execute();
            $stmt->close();
            json_out(['ok'=>$ok]);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO medidas_corporales
                   (id_cliente, fecha, cintura, cadera, pecho, cuello, hombros,
                    brazo_izq, brazo_der, muslo_izq, muslo_der, pantorrilla, notas)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('isdddddddddds',
                $idCliente, $fecha,
                $vals['cintura'], $vals['cadera'], $vals['pecho'], $vals['cuello'],
                $vals['hombros'], $vals['brazo_izq'], $vals['brazo_der'],
                $vals['muslo_izq'], $vals['muslo_der'], $vals['pantorrilla'],
                $notas);
            $ok = $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            json_out(['ok'=>$ok, 'id'=>$newId]);
        }
    } break;

    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM medidas_corporales WHERE id=? AND id_cliente=?");
        $stmt->bind_param('ii', $id, $idCliente);
        $stmt->execute();
        $afect = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok'=>$afect>0]);
    } break;

    default:
        json_out(['ok'=>false, 'error'=>'accion'], 400);
}