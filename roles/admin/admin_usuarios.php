<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid   = usuario_id();
$ok    = '';
$error = '';

/**
 * Wipe completo de datos de un cliente:
 * - Borra: pesos, medidas, check-ins, fotos, objetivos, dietas asignadas, calendario,
 *          estrategias, notificaciones.
 * - Resetea: campos de estrategia en fichas_anamnesis (mantiene el cuestionario).
 * - Conserva: cuenta usuario, chats, notas del dietista, consultas, ficha anamnesis.
 *
 * Devuelve un array con el conteo por categoría o null si falla.
 */
function wipe_datos_cliente(mysqli $conn, int $idCliente): ?array {
    if ($idCliente <= 0) return null;

    $stats = [];
    $conn->begin_transaction();
    try {
        // --- Borrar archivos físicos de fotos ---
        $stmt = $conn->prepare("SELECT archivo_url FROM archivos_boveda WHERE id_cliente=?");
        $stmt->bind_param('i', $idCliente); $stmt->execute();
        $rsFotos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $stats['archivos_fisicos'] = 0;
        $rootDir = realpath(__DIR__ . '/../../');
        foreach ($rsFotos as $f) {
            $path = $rootDir . '/' . ltrim((string)$f['archivo_url'], '/');
            if ($path && is_file($path)) {
                if (@unlink($path)) $stats['archivos_fisicos']++;
            }
        }
        // Limpiar el directorio del cliente si está vacío
        $dirCliente = $rootDir . '/uploads/fotos/' . $idCliente;
        if (is_dir($dirCliente)) {
            // borrar lo que quede (por si quedaron huérfanos)
            $files = glob($dirCliente . '/*');
            foreach ($files as $fp) { if (is_file($fp)) @unlink($fp); }
            @rmdir($dirCliente);
        }

        // --- Borrar registros en BD ---
        $tablas = [
            'archivos_boveda'         => 'id_cliente',
            'progresos_metricas'      => 'id_cliente',
            'medidas_corporales'      => 'id_cliente',
            'checkins_semanales'      => 'id_cliente',
            'objetivos'               => 'id_cliente',
            'historial_estrategias'   => 'id_cliente',
            'calendario_asignaciones' => 'id_cliente',
            'dietas_base'             => 'id_cliente', // cascada a comidas_bloques, dieta_alimentos, calendario
            'notificaciones'          => 'id_usuario',
        ];
        foreach ($tablas as $tabla => $columna) {
            $stmt = $conn->prepare("DELETE FROM `{$tabla}` WHERE `{$columna}`=?");
            $stmt->bind_param('i', $idCliente);
            $stmt->execute();
            $stats[$tabla] = $stmt->affected_rows;
            $stmt->close();
        }

        // --- Reset campos de estrategia en fichas_anamnesis ---
        $stmt = $conn->prepare(
            "UPDATE fichas_anamnesis SET
               obj_kcal = 0, obj_p = 0, obj_c = 0, obj_g = 0,
               factor_p = 2.0, factor_g = 0.9, fecha_estrategia = NULL
             WHERE id_cliente = ?"
        );
        $stmt->bind_param('i', $idCliente);
        $stmt->execute();
        $stats['fichas_anamnesis_reset'] = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();
        return $stats;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Wipe cliente fallido: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// POST: CRUD de usuarios + WIPE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar_usuario') {
            $idEdit       = (int)($_POST['id'] ?? 0);
            $rol          = $_POST['rol'] ?? '';
            $usuario      = trim($_POST['usuario'] ?? '');
            $email        = trim($_POST['email'] ?? '');
            $nombre       = trim($_POST['nombre_completo'] ?? '');
            $idDietista   = ($_POST['id_dietista'] ?? '') === '' ? null : (int)$_POST['id_dietista'];
            $activo       = (int)($_POST['activo'] ?? 1);
            $passNueva    = (string)($_POST['password_nueva'] ?? '');

            if (!in_array($rol, ['admin','dietista','cliente'], true))
                $error = 'Rol no válido.';
            elseif ($usuario === '' || !preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $usuario))
                $error = 'Usuario inválido. 3 a 50 caracteres: letras, números, _ . -';
            elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
                $error = 'Email inválido.';
            elseif ($nombre === '')
                $error = 'Nombre obligatorio.';
            elseif ($rol !== 'cliente') {
                $idDietista = null;
            }

            if (!$error) {
                if ($idEdit > 0) {
                    if ($passNueva !== '') {
                        $hash = password_hash($passNueva, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare(
                            "UPDATE usuarios SET rol=?, usuario=?, email=?, nombre_completo=?, id_dietista=?, activo=?, password=?
                             WHERE id=?"
                        );
                        $stmt->bind_param('ssssiisi', $rol, $usuario, $email, $nombre, $idDietista, $activo, $hash, $idEdit);
                    } else {
                        $stmt = $conn->prepare(
                            "UPDATE usuarios SET rol=?, usuario=?, email=?, nombre_completo=?, id_dietista=?, activo=?
                             WHERE id=?"
                        );
                        $stmt->bind_param('ssssiii', $rol, $usuario, $email, $nombre, $idDietista, $activo, $idEdit);
                    }
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'EDITAR_USUARIO', "Modificados datos de ID #{$idEdit} ({$usuario})");
                        $ok = 'Usuario actualizado.';
                    } else {
                        if ($conn->errno === 1062) {
                            $error = (stripos($conn->error, 'email') !== false)
                                ? 'Ese email ya está en uso.'
                                : 'Ese nombre de usuario ya existe.';
                        } else {
                            $error = 'No se pudo actualizar.';
                        }
                    }
                    $stmt->close();
                } else {
                    if ($passNueva === '') $passNueva = '1234';
                    $hash = password_hash($passNueva, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare(
                        "INSERT INTO usuarios (rol, usuario, email, password, nombre_completo, id_dietista, activo)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('sssssii', $rol, $usuario, $email, $hash, $nombre, $idDietista, $activo);
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'CREAR_USUARIO', "Creado nuevo {$rol} ({$usuario}, {$email})");
                        $ok = "Usuario creado. Contraseña inicial: " . ($passNueva === '1234' ? '1234' : '(personalizada)');
                    } else {
                        if ($conn->errno === 1062) {
                            $error = (stripos($conn->error, 'email') !== false)
                                ? 'Ese email ya está en uso.'
                                : 'Ese nombre de usuario ya existe.';
                        } else {
                            $error = 'No se pudo crear.';
                        }
                    }
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'borrar_usuario') {
            $idDel = (int)$_POST['id'];
            if ($idDel === $uid) {
                $error = 'No puedes borrarte a ti mismo.';
            } else {
                $stmt = $conn->prepare("SELECT rol, usuario FROM usuarios WHERE id=?");
                $stmt->bind_param('i', $idDel);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($u) {
                    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id=?");
                    $stmt->bind_param('i', $idDel);
                    if ($stmt->execute()) {
                        log_admin($conn, $uid, 'BORRAR_USUARIO', "Eliminado {$u['rol']}: {$u['usuario']}");
                        $ok = 'Usuario borrado.';
                    } else $error = 'No se pudo borrar.';
                    $stmt->close();
                }
            }
        }
        elseif ($accion === 'toggle_activo') {
            $idT = (int)$_POST['id'];
            if ($idT === $uid) {
                $error = 'No puedes desactivarte a ti mismo.';
            } else {
                $stmt = $conn->prepare("UPDATE usuarios SET activo = 1 - activo WHERE id=?");
                $stmt->bind_param('i', $idT);
                $stmt->execute();
                $stmt->close();
                log_admin($conn, $uid, 'TOGGLE_ACTIVO', "Cambio estado activo de ID #{$idT}");
                $ok = 'Estado actualizado.';
            }
        }
        elseif ($accion === 'wipe_cliente') {
            // --- WIPE: reinicia plan del cliente conservando la cuenta ---
            $idW = (int)$_POST['id'];
            $confirma = trim((string)($_POST['confirmar'] ?? ''));
            if ($idW === $uid) {
                $error = 'No puedes hacer wipe a ti mismo.';
            } elseif ($confirma !== 'WIPE') {
                $error = 'Confirmación incorrecta. Debes escribir exactamente WIPE.';
            } else {
                // Verificar que sea cliente
                $stmt = $conn->prepare("SELECT rol, usuario, nombre_completo FROM usuarios WHERE id=?");
                $stmt->bind_param('i', $idW); $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$u) {
                    $error = 'Usuario no existe.';
                } elseif ($u['rol'] !== 'cliente') {
                    $error = 'Solo se puede hacer wipe de clientes.';
                } else {
                    $stats = wipe_datos_cliente($conn, $idW);
                    if ($stats === null) {
                        $error = 'El wipe falló. Revisa los logs del servidor.';
                    } else {
                        $totales = array_sum(array_map('intval', $stats));
                        log_admin($conn, $uid, 'WIPE_CLIENTE',
                            "Wipe de datos del cliente {$u['usuario']} (ID #{$idW}). Registros afectados: " .
                            json_encode($stats, JSON_UNESCAPED_UNICODE)
                        );
                        $ok = 'Wipe realizado para ' . $u['nombre_completo']
                            . '. Total registros afectados: ' . $totales
                            . '. El cliente queda como recién registrado.';
                    }
                }
            }
        }
    }
}

// --- Edición ---
$editando = null;
if (isset($_GET['edit'])) {
    $idE = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id=?");
    $stmt->bind_param('i', $idE);
    $stmt->execute();
    $editando = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// --- Filtros ---
$q     = trim($_GET['q']   ?? '');
$rolF  = $_GET['rol']      ?? 'todos';
$estF  = $_GET['estado']   ?? 'todos';

$where = "1=1"; $params = []; $tipos = '';
if (in_array($rolF, ['admin','dietista','cliente'], true)) {
    $where .= " AND u.rol = ?"; $params[] = $rolF; $tipos .= 's';
}
if ($estF === 'activos')   { $where .= " AND u.activo=1"; }
if ($estF === 'inactivos') { $where .= " AND u.activo=0"; }
if ($q !== '') {
    $where .= " AND (u.nombre_completo LIKE ? OR u.usuario LIKE ? OR u.email LIKE ?)";
    $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $params[] = $like; $tipos .= 'sss';
}

$sql = "SELECT u.*, d.nombre_completo AS dietista_nombre,
               (SELECT COUNT(*) FROM usuarios c WHERE c.id_dietista = u.id AND c.activo=1) AS n_clientes
        FROM usuarios u
        LEFT JOIN usuarios d ON d.id = u.id_dietista
        WHERE $where ORDER BY u.rol, u.nombre_completo
        LIMIT 300";
$stmt = $conn->prepare($sql);
if ($tipos !== '') $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Dietistas activos (para el selector)
$stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE rol='dietista' AND activo=1 ORDER BY nombre_completo");
$stmt->execute();
$dietistas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function val_ad(array $arr, string $k, $d=''): string {
    return isset($arr[$k]) && $arr[$k] !== null ? (string)$arr[$k] : (string)$d;
}

$base   = '../../';
$active = 'usuarios';
$titulo = 'Usuarios';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page">

  <?php if ($ok):    ?><div class="alert alert-success" role="status"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"  role="alert"><?= e($error) ?></div><?php endif; ?>

  <!-- Form crear/editar usuario -->
  <form method="post" class="card" id="form-usuario">
    <h2 class="card-title"><?= $editando ? '✏️ Editar usuario #' . (int)$editando['id'] : '➕ Nuevo usuario' ?></h2>
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="accion" value="guardar_usuario">
    <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Rol</span>
        <select class="field-select" name="rol" required>
          <?php foreach (['cliente'=>'Cliente','dietista'=>'Dietista','admin'=>'Admin'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= val_ad($editando ?: [], 'rol', 'cliente') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Activo</span>
        <select class="field-select" name="activo">
          <?php $actVal = $editando ? (int)$editando['activo'] : 1; ?>
          <option value="1" <?= $actVal === 1 ? 'selected' : '' ?>>Sí</option>
          <option value="0" <?= $actVal === 0 ? 'selected' : '' ?>>No</option>
        </select>
      </label>
    </div>

    <label class="field">
      <span class="field-label">Nombre completo</span>
      <input class="field-input" type="text" name="nombre_completo" required maxlength="160"
             value="<?= e(val_ad($editando ?: [], 'nombre_completo')) ?>">
    </label>

    <label class="field">
      <span class="field-label">Usuario <span class="text-muted">(para login, único)</span></span>
      <input class="field-input" type="text" name="usuario" required maxlength="50"
             autocapitalize="none" spellcheck="false"
             pattern="[a-zA-Z0-9_.\-]{3,50}"
             title="3 a 50 caracteres. Letras, números, guion bajo, punto o guion."
             value="<?= e(val_ad($editando ?: [], 'usuario')) ?>">
    </label>

    <label class="field">
      <span class="field-label">Correo <span class="text-muted">(también sirve para login)</span></span>
      <input class="field-input" type="email" name="email" required maxlength="150"
             autocapitalize="none" spellcheck="false"
             value="<?= e(val_ad($editando ?: [], 'email')) ?>">
    </label>

    <label class="field">
      <span class="field-label">Dietista asignado <span class="text-muted">(solo clientes)</span></span>
      <select class="field-select" name="id_dietista">
        <option value="">— Sin asignar —</option>
        <?php foreach ($dietistas as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)val_ad($editando ?: [], 'id_dietista') === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['nombre_completo']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field">
      <span class="field-label">Contraseña <?= $editando ? '<span class="text-muted">(dejar vacío para no cambiar)</span>' : '<span class="text-muted">(opcional, por defecto 1234)</span>' ?></span>
      <input class="field-input" type="text" name="password_nueva" autocomplete="new-password">
    </label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-block"><?= $editando ? 'Actualizar usuario' : 'Crear usuario' ?></button>
      <?php if ($editando): ?>
        <a class="btn btn-outline btn-block" href="admin_usuarios.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Filtros -->
  <form method="get" class="card">
    <h3 class="card-title">🔎 Filtros</h3>
    <label class="field">
      <span class="field-label">Buscar</span>
      <input class="field-input" type="search" name="q" placeholder="Nombre o email" value="<?= e($q) ?>">
    </label>
    <div class="grid-2">
      <label class="field">
        <span class="field-label">Rol</span>
        <select class="field-select" name="rol" onchange="this.form.submit()">
          <option value="todos"    <?= $rolF==='todos'    ? 'selected' : '' ?>>Todos</option>
          <option value="admin"    <?= $rolF==='admin'    ? 'selected' : '' ?>>Admins</option>
          <option value="dietista" <?= $rolF==='dietista' ? 'selected' : '' ?>>Dietistas</option>
          <option value="cliente"  <?= $rolF==='cliente'  ? 'selected' : '' ?>>Clientes</option>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Estado</span>
        <select class="field-select" name="estado" onchange="this.form.submit()">
          <option value="todos"     <?= $estF==='todos'     ? 'selected' : '' ?>>Todos</option>
          <option value="activos"   <?= $estF==='activos'   ? 'selected' : '' ?>>Activos</option>
          <option value="inactivos" <?= $estF==='inactivos' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </label>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Aplicar filtros</button>
  </form>

  <!-- Listado -->
  <section class="card">
    <header class="dash-card-header">
      <h3 class="card-title">👥 <?= count($usuarios) ?> usuario<?= count($usuarios) === 1 ? '' : 's' ?></h3>
      <a class="dash-card-link" href="#form-usuario">+ Nuevo</a>
    </header>

    <?php if (!$usuarios): ?>
      <p class="text-muted">Sin usuarios con esos filtros.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Rol</th>
              <th>Dietista / Clientes</th>
              <th>Última actividad</th>
              <th>Estado</th>
              <th class="td-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($usuarios as $u): ?>
              <tr class="<?= !(int)$u['activo'] ? 'is-inactive' : '' ?>">
                <td>
                  <div class="usr-cell">
                    <div class="chats-avatar usr-cell-avatar"><?= e(mb_strtoupper(mb_substr($u['nombre_completo'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                    <div class="usr-cell-info">
                      <strong><?= e($u['nombre_completo']) ?></strong>
                      <span class="text-muted">@<?= e($u['usuario'] ?? '') ?><?php if (!empty($u['email'])): ?> · <?= e($u['email']) ?><?php endif; ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="dt-pill dt-pill--<?= $u['rol']==='admin'?'warn':($u['rol']==='dietista'?'info':'ok') ?>"><?= e($u['rol']) ?></span>
                </td>
                <td>
                  <?php if ($u['rol'] === 'cliente'): ?>
                    <?= e($u['dietista_nombre'] ?? '—') ?>
                    <?php if (empty($u['dietista_nombre'])): ?>
                      <span class="dt-pill dt-pill--warn">sin asignar</span>
                    <?php endif; ?>
                  <?php elseif ($u['rol'] === 'dietista'): ?>
                    <strong><?= (int)$u['n_clientes'] ?></strong> <span class="text-muted">cliente<?= $u['n_clientes']==1?'':'s' ?></span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($u['ultima_actividad'])): ?>
                    <span class="text-muted"><?= e(date('d/m/Y H:i', strtotime($u['ultima_actividad']))) ?></span>
                  <?php else: ?>
                    <span class="text-muted">Nunca</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((int)$u['activo']): ?>
                    <span class="dt-pill dt-pill--ok">✓ Activo</span>
                  <?php else: ?>
                    <span class="dt-pill dt-pill--warn">Inactivo</span>
                  <?php endif; ?>
                </td>
                <td class="td-actions">
                  <a class="btn btn-ghost btn-mini" href="?edit=<?= (int)$u['id'] ?>#form-usuario" title="Editar">✏️</a>
                  <?php if ((int)$u['id'] !== $uid): ?>
                    <form method="post" class="inline-form" title="Activar/desactivar">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="accion" value="toggle_activo">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-mini"><?= (int)$u['activo'] ? '🚫' : '↩️' ?></button>
                    </form>

                    <?php if ($u['rol'] === 'cliente'): ?>
                      <!-- WIPE: solo clientes -->
                      <form method="post" class="inline-form"
                            onsubmit="return confirmarWipe(this, <?= e(json_encode($u['nombre_completo'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);"
                            title="Wipe · reiniciar plan del cliente (no borra cuenta)">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="accion" value="wipe_cliente">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="confirmar" value="">
                        <button type="submit" class="btn btn-ghost btn-mini btn-wipe">🧹</button>
                      </form>
                    <?php endif; ?>

                    <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar este usuario PERMANENTEMENTE?\n\nEsto eliminará la cuenta y todos sus datos.');">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="accion" value="borrar_usuario">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-mini" title="Borrar cuenta">🗑️</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</main>

<script>
function confirmarWipe(form, nombre) {
  const msg = '⚠️ WIPE DE DATOS DEL CLIENTE\n\n' +
    'Cliente: ' + nombre + '\n\n' +
    'Esta acción BORRARÁ permanentemente:\n' +
    '  • Pesos y métricas de progreso\n' +
    '  • Medidas corporales\n' +
    '  • Check-ins semanales\n' +
    '  • Fotos subidas\n' +
    '  • Objetivos\n' +
    '  • Dietas asignadas y calendario\n' +
    '  • Historial de estrategias\n' +
    '  • Notificaciones\n\n' +
    'Se MANTENDRÁN: cuenta, cuestionario inicial (anamnesis), chats, consultas, notas del dietista.\n\n' +
    'Para confirmar, escribe exactamente la palabra: WIPE';
  const r = prompt(msg, '');
  if (r === null) return false;          // Canceló
  if (r.trim() !== 'WIPE') {
    alert('Confirmación incorrecta. La acción se ha cancelado.');
    return false;
  }
  // Inyectar la confirmación validada en el formulario
  const input = form.querySelector('input[name="confirmar"]');
  if (input) input.value = 'WIPE';
  return true;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
