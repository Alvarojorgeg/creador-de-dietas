<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok'=>false,'error'=>'sesion'], 401);

$accion    = $_GET['accion'] ?? 'listar';
$idCliente = cliente_destino_actual($conn);
if ($idCliente <= 0) json_out(['ok'=>false,'error'=>'permiso'], 403);

$rol = rol_actual();
$uid = usuario_id();
$tiposValidos   = ['peso','grasa','medida','custom'];
$estadosValidos = ['activo','completado','fallado','cancelado'];

switch ($accion) {

    case 'listar': {
        $soloActivos = !empty($_GET['solo_activos']);
        $sql = "SELECT id, titulo, descripcion, tipo, valor_inicial, valor_objetivo,
                       unidad, fecha_inicio, fecha_limite, estado, fecha_completado, fecha_creacion
                FROM objetivos WHERE id_cliente = ?";
        if ($soloActivos) $sql .= " AND estado = 'activo'";
        $sql .= " ORDER BY (estado='activo') DESC, fecha_creacion DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $idCliente);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok'=>true,'items'=>$items]);
    } break;

    case 'guardar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $idEdit  = (int)($_POST['id'] ?? 0);
        $titulo  = trim($_POST['titulo'] ?? '');
        $desc    = trim($_POST['descripcion'] ?? '');
        $tipo    = $_POST['tipo'] ?? 'peso';
        $vi      = ($_POST['valor_inicial']  ?? '') === '' ? null : (float)$_POST['valor_inicial'];
        $vo      = ($_POST['valor_objetivo'] ?? '') === '' ? null : (float)$_POST['valor_objetivo'];
        $unidad  = trim($_POST['unidad'] ?? '');
        $finicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
        $flimite = $_POST['fecha_limite'] ?? null;
        if ($flimite === '') $flimite = null;

        if ($titulo === '')              json_out(['ok'=>false,'error'=>'titulo_requerido'], 400);
        if (!in_array($tipo, $tiposValidos, true)) json_out(['ok'=>false,'error'=>'tipo'], 400);

        // Si el creador es dietista o admin, guardamos quién lo asignó
        $idDietistaAsign = ($rol !== 'cliente') ? $uid : null;

        if ($idEdit > 0) {
            $stmt = $conn->prepare(
                "UPDATE objetivos SET titulo=?, descripcion=?, tipo=?, valor_inicial=?, valor_objetivo=?,
                    unidad=?, fecha_inicio=?, fecha_limite=?
                 WHERE id=? AND id_cliente=?"
            );
            $stmt->bind_param('sssddsssii',
                $titulo,$desc,$tipo,$vi,$vo,$unidad,$finicio,$flimite,$idEdit,$idCliente);
            $ok = $stmt->execute();
            $stmt->close();
            json_out(['ok'=>$ok]);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO objetivos
                   (id_cliente, id_dietista, titulo, descripcion, tipo, valor_inicial, valor_objetivo,
                    unidad, fecha_inicio, fecha_limite, estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'activo')"
            );
            $stmt->bind_param('iisssddsss',
                $idCliente,$idDietistaAsign,$titulo,$desc,$tipo,$vi,$vo,$unidad,$finicio,$flimite);
            $ok = $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            json_out(['ok'=>$ok,'id'=>$newId]);
        }
    } break;

    case 'estado': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $id     = (int)($_POST['id'] ?? 0);
        $nuevo  = $_POST['estado'] ?? '';
        if (!in_array($nuevo, $estadosValidos, true)) json_out(['ok'=>false,'error'=>'estado'], 400);
        $compFecha = ($nuevo === 'completado') ? date('Y-m-d') : null;

        $stmt = $conn->prepare(
            "UPDATE objetivos SET estado=?, fecha_completado=? WHERE id=? AND id_cliente=?"
        );
        $stmt->bind_param('ssii', $nuevo, $compFecha, $id, $idCliente);
        $ok = $stmt->execute();
        $stmt->close();
        json_out(['ok'=>$ok]);
    } break;

    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM objetivos WHERE id=? AND id_cliente=?");
        $stmt->bind_param('ii', $id, $idCliente);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        json_out(['ok'=>$af>0]);
    } break;

    default:
        json_out(['ok'=>false,'error'=>'accion'], 400);
}