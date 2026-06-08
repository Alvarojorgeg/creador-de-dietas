<?php
/**
 * /ajax/ajax_estrategia.php
 * Guardar / listar / borrar estrategias nutricionales (dietista).
 *   GET  ?accion=historial&id_cliente=N
 *   POST ?accion=guardar&id_cliente=N         (form: _csrf, factor_p, factor_g, kcal, gramos_p/c/g, nombre)
 *   POST ?accion=borrar&id_cliente=N          (form: _csrf, id)
 */
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado())            json_out(['ok'=>false,'error'=>'sesion'],  401);
if (rol_actual() !== 'dietista') json_out(['ok'=>false,'error'=>'permiso'], 403);

$uid       = (int)usuario_id();
$accion    = $_GET['accion'] ?? '';
$idCliente = (int)($_REQUEST['id_cliente'] ?? 0);
if ($idCliente <= 0) json_out(['ok'=>false,'error'=>'cliente'], 400);

$stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente' AND activo=1");
$stmt->bind_param('ii', $idCliente, $uid); $stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) { $stmt->close(); json_out(['ok'=>false,'error'=>'propiedad'], 403); }
$stmt->close();

switch ($accion) {

    case 'historial': {
        $stmt = $conn->prepare(
            "SELECT id, fecha, nombre, kcal, factor_p, factor_g, gramos_p, gramos_c, gramos_g
             FROM historial_estrategias
             WHERE id_cliente = ?
             ORDER BY fecha DESC
             LIMIT 50"
        );
        $stmt->bind_param('i', $idCliente);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    case 'guardar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],   403);

        $fP    = (float)($_POST['factor_p'] ?? 0);
        $fG    = (float)($_POST['factor_g'] ?? 0);
        $kcal  = (int)  ($_POST['kcal']     ?? 0);
        $gP    = (int)round((float)($_POST['gramos_p'] ?? 0));
        $gC    = (int)round((float)($_POST['gramos_c'] ?? 0));
        $gG    = (int)round((float)($_POST['gramos_g'] ?? 0));
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $nombre = mb_substr($nombre, 0, 80, 'UTF-8');
        if ($nombre === '') $nombre = null;

        if ($fP < 0.5 || $fP > 4.0)      json_out(['ok'=>false,'error'=>'factor_p_rango'], 400);
        if ($fG < 0.2 || $fG > 2.5)      json_out(['ok'=>false,'error'=>'factor_g_rango'], 400);
        if ($kcal < 800 || $kcal > 6000) json_out(['ok'=>false,'error'=>'kcal_rango'],     400);

        $conn->begin_transaction();
        try {
            // Estrategia activa en ficha
            $st = $conn->prepare("SELECT id_cliente FROM fichas_anamnesis WHERE id_cliente=?");
            $st->bind_param('i', $idCliente); $st->execute();
            $hayFicha = (bool)$st->get_result()->fetch_assoc();
            $st->close();

            if ($hayFicha) {
                $st = $conn->prepare(
                    "UPDATE fichas_anamnesis
                     SET obj_kcal=?, obj_p=?, obj_c=?, obj_g=?,
                         factor_p=?, factor_g=?, fecha_estrategia=NOW()
                     WHERE id_cliente=?"
                );
                $st->bind_param('idddddi', $kcal, $gP, $gC, $gG, $fP, $fG, $idCliente);
                $st->execute();
                $st->close();
            }

            // Historial con nombre
            $st = $conn->prepare(
                "INSERT INTO historial_estrategias
                   (id_cliente, id_dietista, nombre, kcal, factor_p, factor_g, gramos_p, gramos_c, gramos_g)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->bind_param('iisidddii', $idCliente, $uid, $nombre, $kcal, $fP, $fG, $gP, $gC, $gG);
            $st->execute();
            $nuevoId = $st->insert_id;
            $st->close();

            $conn->commit();
            json_out(['ok'=>true, 'id'=>$nuevoId]);
        } catch (Exception $ex) {
            $conn->rollback();
            json_out(['ok'=>false,'error'=>'db','detail'=>$ex->getMessage()], 500);
        }
    } break;

    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null))  json_out(['ok'=>false,'error'=>'csrf'],   403);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok'=>false,'error'=>'id'], 400);

        $stmt = $conn->prepare("DELETE FROM historial_estrategias WHERE id=? AND id_cliente=? AND id_dietista=?");
        $stmt->bind_param('iii', $id, $idCliente, $uid); $stmt->execute();
        $af = $stmt->affected_rows; $stmt->close();
        json_out(['ok'=>$af>0]);
    } break;

    default: json_out(['ok'=>false,'error'=>'accion'], 400);
}
