<?php
require_once __DIR__ . '/../includes/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) json_out(['ok' => false, 'error' => 'sesion'], 401);
if (rol_actual() !== 'admin') json_out(['ok' => false, 'error' => 'permiso'], 403);

$uid    = usuario_id();
$accion = $_GET['accion'] ?? 'listar';

$rolesValidos = ['admin','dietista','cliente'];

switch ($accion) {

    // ---------- Listar / buscar ----------
    case 'listar': {
        $q     = trim($_GET['q'] ?? '');
        $rol   = $_GET['rol'] ?? '';
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));

        $where = "1=1"; $params = []; $tipos = '';
        if (in_array($rol, $rolesValidos, true)) {
            $where .= " AND u.rol=?"; $params[] = $rol; $tipos .= 's';
        }
        if ($q !== '') {
            $where .= " AND (u.nombre_completo LIKE ? OR u.email LIKE ?)";
            $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $tipos .= 'ss';
        }

        $sql = "SELECT u.id, u.rol, u.email, u.nombre_completo, u.id_dietista, u.activo,
                       u.fecha_registro, u.ultimo_login,
                       d.nombre_completo AS dietista_nombre
                FROM usuarios u
                LEFT JOIN usuarios d ON d.id = u.id_dietista
                WHERE $where
                ORDER BY u.rol, u.nombre_completo
                LIMIT ?";
        $params[] = $limit; $tipos .= 'i';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        json_out(['ok' => true, 'items' => $items]);
    } break;

    // ---------- Detalle ----------
    case 'detalle': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_out(['ok'=>false,'error'=>'id'], 400);
        $stmt = $conn->prepare(
            "SELECT u.id, u.rol, u.email, u.nombre_completo, u.id_dietista, u.activo,
                    u.fecha_registro, u.ultimo_login, u.ultima_actividad,
                    d.nombre_completo AS dietista_nombre
             FROM usuarios u
             LEFT JOIN usuarios d ON d.id = u.id_dietista
             WHERE u.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) json_out(['ok'=>false,'error'=>'no_existe'], 404);
        json_out(['ok'=>true,'usuario'=>$u]);
    } break;

    // ---------- Crear ----------
    case 'crear': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $rol         = $_POST['rol'] ?? '';
        $email       = trim($_POST['email'] ?? '');
        $nombre      = trim($_POST['nombre_completo'] ?? '');
        $idDietista  = ($_POST['id_dietista'] ?? '') === '' ? null : (int)$_POST['id_dietista'];
        $activo      = isset($_POST['activo']) ? 1 : 0;
        $pass        = (string)($_POST['password_nueva'] ?? '');
        if ($pass === '') $pass = '1234';

        if (!in_array($rol, $rolesValidos, true))                 json_out(['ok'=>false,'error'=>'rol'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))           json_out(['ok'=>false,'error'=>'email'], 400);
        if ($nombre === '')                                       json_out(['ok'=>false,'error'=>'nombre'], 400);
        if ($rol !== 'cliente') $idDietista = null;

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "INSERT INTO usuarios (rol, email, password, nombre_completo, id_dietista, activo)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssii', $rol, $email, $hash, $nombre, $idDietista, $activo);
        if (!$stmt->execute()) {
            $err = ($conn->errno === 1062) ? 'email_duplicado' : 'db';
            $stmt->close();
            json_out(['ok'=>false, 'error'=>$err], 400);
        }
        $newId = $stmt->insert_id;
        $stmt->close();
        log_admin($conn, $uid, 'CREAR_USUARIO', "Creado nuevo {$rol} ({$email}) vía AJAX");
        json_out(['ok'=>true, 'id'=>$newId]);
    } break;

    // ---------- Actualizar ----------
    case 'actualizar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);

        $id          = (int)($_POST['id'] ?? 0);
        $rol         = $_POST['rol'] ?? '';
        $email       = trim($_POST['email'] ?? '');
        $nombre      = trim($_POST['nombre_completo'] ?? '');
        $idDietista  = ($_POST['id_dietista'] ?? '') === '' ? null : (int)$_POST['id_dietista'];
        $activo      = isset($_POST['activo']) ? 1 : 0;
        $passNueva   = (string)($_POST['password_nueva'] ?? '');

        if ($id <= 0)                                             json_out(['ok'=>false,'error'=>'id'], 400);
        if (!in_array($rol, $rolesValidos, true))                 json_out(['ok'=>false,'error'=>'rol'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))           json_out(['ok'=>false,'error'=>'email'], 400);
        if ($nombre === '')                                       json_out(['ok'=>false,'error'=>'nombre'], 400);
        if ($rol !== 'cliente') $idDietista = null;

        if ($passNueva !== '') {
            $hash = password_hash($passNueva, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                "UPDATE usuarios SET rol=?, email=?, nombre_completo=?, id_dietista=?, activo=?, password=?
                 WHERE id=?"
            );
            $stmt->bind_param('sssiisi', $rol, $email, $nombre, $idDietista, $activo, $hash, $id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE usuarios SET rol=?, email=?, nombre_completo=?, id_dietista=?, activo=?
                 WHERE id=?"
            );
            $stmt->bind_param('sssiii', $rol, $email, $nombre, $idDietista, $activo, $id);
        }
        if (!$stmt->execute()) {
            $err = ($conn->errno === 1062) ? 'email_duplicado' : 'db';
            $stmt->close();
            json_out(['ok'=>false, 'error'=>$err], 400);
        }
        $stmt->close();
        log_admin($conn, $uid, 'EDITAR_USUARIO', "Modificados datos de ID #{$id} ({$email}) vía AJAX");
        json_out(['ok'=>true]);
    } break;

    // ---------- Toggle activo ----------
    case 'toggle_activo': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $uid) json_out(['ok'=>false,'error'=>'no_te_desactives'], 400);
        $stmt = $conn->prepare("UPDATE usuarios SET activo = 1 - activo WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        log_admin($conn, $uid, 'TOGGLE_USUARIO', "Cambiado estado activo del usuario #{$id} vía AJAX");
        json_out(['ok'=>true]);
    } break;

    // ---------- Borrar ----------
    case 'borrar': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $uid) json_out(['ok'=>false,'error'=>'no_te_borres'], 400);

        $stmt = $conn->prepare("SELECT rol, email FROM usuarios WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u) json_out(['ok'=>false,'error'=>'no_existe'], 404);

        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $af = $stmt->affected_rows;
        $stmt->close();
        if ($af > 0) {
            log_admin($conn, $uid, 'BORRAR_USUARIO', "Eliminado {$u['rol']}: {$u['email']} vía AJAX");
        }
        json_out(['ok'=>$af > 0]);
    } break;

    // ---------- Reset password ----------
    case 'reset_password': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'metodo'], 405);
        if (!csrf_check($_POST['_csrf'] ?? null)) json_out(['ok'=>false,'error'=>'csrf'], 403);
        $id   = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password_nueva'] ?? '1234');
        if (strlen($pass) < 4) json_out(['ok'=>false,'error'=>'corta'], 400);
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $stmt->close();
        log_admin($conn, $uid, 'RESET_PASSWORD', "Reset de contraseña del usuario #{$id}");
        json_out(['ok'=>true]);
    } break;

    default:
        json_out(['ok'=>false, 'error'=>'accion'], 400);
}