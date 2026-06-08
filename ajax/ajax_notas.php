<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok'=>false,'error'=>'sesion'], 401);
if (rol_actual() !== 'dietista') json_out(['ok'=>false,'error'=>'permiso'], 403);

$uid    = usuario_id();
$accion = $_GET['accion'] ?? 'listar';

// Colores permitidos
$COLORES_OK = ['amarillo','rosa','azul','verde','naranja','lila'];

switch ($accion) {

    // ------- Listar todas las notas del dietista -------
    case 'listar':
        $stmt = $conn->prepare(
            "SELECT id, contenido, color, fecha_creacion, fecha_actualizacion
             FROM notas_dietista
             WHERE id_dietista = ?
             ORDER BY fecha_actualizacion DESC"
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($items as $n) {
            $out[] = [
                'id'        => (int)$n['id'],
                'contenido' => (string)$n['contenido'],
                'color'     => $n['color'],
                'fecha'     => $n['fecha_actualizacion'],
            ];
        }
        json_out(['ok'=>true, 'notas'=>$out]);
        break;

    // ------- Crear nota vacía -------
    case 'crear':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $color = $_POST['color'] ?? 'amarillo';
        if (!in_array($color, $COLORES_OK, true)) $color = 'amarillo';

        $stmt = $conn->prepare("INSERT INTO notas_dietista (id_dietista, contenido, color) VALUES (?, '', ?)");
        $stmt->bind_param('is', $uid, $color);
        if (!$stmt->execute()) json_out(['ok'=>false,'error'=>'db'], 500);
        $newId = $stmt->insert_id;
        $stmt->close();
        json_out(['ok'=>true, 'id'=>$newId, 'color'=>$color]);
        break;

    // ------- Editar contenido/color de una nota -------
    case 'editar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $id        = (int)($_POST['id'] ?? 0);
        $contenido = (string)($_POST['contenido'] ?? '');
        $color     = $_POST['color'] ?? null;

        if ($id <= 0) json_out(['ok'=>false,'error'=>'id'], 400);
        if (mb_strlen($contenido) > 5000) $contenido = mb_substr($contenido, 0, 5000);

        if ($color !== null && in_array($color, $COLORES_OK, true)) {
            $stmt = $conn->prepare(
                "UPDATE notas_dietista SET contenido=?, color=? WHERE id=? AND id_dietista=?"
            );
            $stmt->bind_param('ssii', $contenido, $color, $id, $uid);
        } else {
            $stmt = $conn->prepare(
                "UPDATE notas_dietista SET contenido=? WHERE id=? AND id_dietista=?"
            );
            $stmt->bind_param('sii', $contenido, $id, $uid);
        }
        if (!$stmt->execute()) json_out(['ok'=>false,'error'=>'db'], 500);
        $af = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok'=>true, 'guardado'=>$af >= 0]);
        break;

    // ------- Borrar nota -------
    case 'borrar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) json_out(['ok'=>false,'error'=>'id'], 400);

        $stmt = $conn->prepare("DELETE FROM notas_dietista WHERE id=? AND id_dietista=?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok'=>$af > 0]);
        break;

    default:
        json_out(['ok'=>false,'error'=>'accion'], 400);
}
