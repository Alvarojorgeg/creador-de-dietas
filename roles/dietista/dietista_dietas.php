<?php
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('dietista', '../../');

function guardar_bloques_dieta(mysqli $conn, int $idDieta, array $bloques): void {
    $stmt = $conn->prepare("DELETE FROM comidas_bloques WHERE id_dieta=?");
    $stmt->bind_param('i', $idDieta); $stmt->execute(); $stmt->close();

    foreach ($bloques as $iB => $b) {
        $nomB = mb_substr(trim($b['nombre'] ?? ''), 0, 50);
        if ($nomB === '') $nomB = 'Bloque ' . ($iB + 1);
        $stmt = $conn->prepare("INSERT INTO comidas_bloques (id_dieta, nombre_bloque, orden) VALUES (?, ?, ?)");
        $stmt->bind_param('isi', $idDieta, $nomB, $iB); $stmt->execute();
        $idBloque = $stmt->insert_id; $stmt->close();

        $alis = is_array($b['alimentos'] ?? null) ? $b['alimentos'] : [];
        foreach ($alis as $ali) {
            $idA = (int)($ali['id_alimento'] ?? 0);
            $gr  = (float)($ali['gramos'] ?? 0);
            if ($idA > 0 && $gr > 0) {
                $stmt = $conn->prepare("INSERT INTO dieta_alimentos (id_bloque, id_alimento, cantidad_gr) VALUES (?, ?, ?)");
                $stmt->bind_param('iid', $idBloque, $idA, $gr); $stmt->execute(); $stmt->close();
            }
        }
    }
}

function calcular_tdee_cliente(array $a, ?float $peso): ?array {
    if (!$peso || $peso <= 0) return null;
    if (empty($a['fecha_nacimiento']) || empty($a['altura_cm'])) return null;

    try { $bd = new DateTime((string)$a['fecha_nacimiento']); $edad = (int)$bd->diff(new DateTime())->y; }
    catch (Throwable $e) { return null; }
    if ($edad < 10 || $edad > 100) return null;

    $altura = (float)$a['altura_cm'];
    $sexo   = strtoupper(substr((string)($a['sexo'] ?? 'M'), 0, 1));

    if (in_array($sexo, ['F','W','H'], true)) {
        $bmr = 10*$peso + 6.25*$altura - 5*$edad - 161;
    } else {
        $bmr = 10*$peso + 6.25*$altura - 5*$edad + 5;
    }

    $pasos     = max(0, (int)($a['pasos_diarios'] ?? 5000));
    $neatPasos = $pasos * 0.04;

    $trabajoMap = ['sedentario'=>0,'ligero'=>150,'moderado'=>350,'activo'=>500,'muy_activo'=>700];
    $neatTrab   = $trabajoMap[strtolower((string)$a['tipo_trabajo'])] ?? 0;

    $minSes  = max(0, (int)($a['min_sesion'] ?? 60));
    $diasGym = max(0, min(7, (int)($a['dias_gym'] ?? 0)));
    $entrenoMap = ['pesas'=>7,'fuerza'=>7,'cardio'=>9,'mixto'=>8,'hiit'=>11,'crossfit'=>10,'yoga'=>4];
    $kPorMin = $entrenoMap[strtolower((string)$a['tipo_entreno'])] ?? 7;
    $eat = $minSes * $kPorMin;

    $factor = (float)($a['factor_actividad'] ?? 1.0); if ($factor <= 0) $factor = 1.0;
    $base       = $bmr + ($neatPasos + $neatTrab) * $factor;
    $tdeeDesc   = $base * 1.1;
    $tdeeEntr   = ($base + $eat) * 1.1;
    $tdeePond   = ($tdeeDesc * (7 - $diasGym) + $tdeeEntr * $diasGym) / 7;

    return ['descanso'=>(int)round($tdeeDesc), 'entreno'=>(int)round($tdeeEntr), 'pond'=>(int)round($tdeePond)];
}

$uid       = usuario_id();
$idEditar  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$idClienteNuevo = isset($_GET['id_cliente']) ? (int)$_GET['id_cliente'] : 0;
$modoEditor = ($idEditar > 0) || (($_GET['accion'] ?? '') === 'nueva');

$ok = ''; $error = '';
if (($_GET['saved'] ?? '') === '1') $ok = '¡Dieta guardada correctamente!';

// POST guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paquete_json'])) {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $idDieta = (int)($_POST['id'] ?? 0);
        $paq = json_decode($_POST['paquete_json'], true);

        if (!is_array($paq) || empty($paq['nombre'])) {
            $error = 'Datos de la dieta inválidos.';
        } else {
            $nombre  = mb_substr(trim($paq['nombre']), 0, 100);
            $icono   = mb_substr($paq['icono'] ?? '🍽️', 0, 4, 'UTF-8');
            $color   = preg_match('/^#[0-9A-Fa-f]{6}$/', $paq['color'] ?? '') ? $paq['color'] : '#2F9E73';
            $objKcal = (float)($paq['obj_kcal'] ?? 0);
            $objP    = (float)($paq['obj_p'] ?? 0);
            $objC    = (float)($paq['obj_c'] ?? 0);
            $objG    = (float)($paq['obj_g'] ?? 0);

            // ---------- Persistencia de los selectores de estrategia ----------
            $estrBase   = in_array($paq['estr_base'] ?? '', ['pond','entreno','descanso'], true)
                          ? $paq['estr_base'] : 'pond';
            $estrDef    = (int)($paq['estr_deficit'] ?? -10);
            if ($estrDef < -50 || $estrDef > 50) $estrDef = -10;
            $estrEstrId = max(0, (int)($paq['estr_estrategia_id'] ?? 0));

            $idsCliValidos = [];
            if (is_array($paq['ids_clientes'] ?? null)) {
                foreach ($paq['ids_clientes'] as $idTest) {
                    $idTest = (int)$idTest;
                    if ($idTest <= 0) continue;
                    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
                    $stmt->bind_param('ii', $idTest, $uid); $stmt->execute();
                    if ($stmt->get_result()->fetch_assoc()) $idsCliValidos[] = $idTest;
                    $stmt->close();
                }
            }
            $idCliSingle = (int)($paq['id_cliente'] ?? 0);
            if ($idCliSingle > 0) {
                $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND id_dietista=? AND rol='cliente'");
                $stmt->bind_param('ii', $idCliSingle, $uid); $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) $idCliSingle = 0;
                $stmt->close();
            }
            $bloques = is_array($paq['bloques'] ?? null) ? $paq['bloques'] : [];

            $conn->begin_transaction();
            try {
                if ($idDieta > 0) {
                    $idClienteSave = $idCliSingle > 0 ? $idCliSingle : null;
                    $stmt = $conn->prepare("SELECT id FROM dietas_base WHERE id=? AND id_dietista=?");
                    $stmt->bind_param('ii', $idDieta, $uid); $stmt->execute();
                    if (!$stmt->get_result()->fetch_assoc()) throw new Exception('No tienes permisos.');
                    $stmt->close();
                    $stmt = $conn->prepare(
                        "UPDATE dietas_base SET nombre=?, icono=?, color=?, kcal_objetivo=?,
                           prot_objetivo=?, carb_objetivo=?, grasas_objetivo=?, id_cliente=?,
                           estr_base=?, estr_deficit=?, estr_estrategia_id=?
                         WHERE id=? AND id_dietista=?"
                    );
                    $stmt->bind_param('sssddddisiiii',
                        $nombre, $icono, $color, $objKcal,
                        $objP, $objC, $objG, $idClienteSave,
                        $estrBase, $estrDef, $estrEstrId,
                        $idDieta, $uid
                    );
                    $stmt->execute(); $stmt->close();
                    guardar_bloques_dieta($conn, $idDieta, $bloques);
                    $primerIdCreado = $idDieta;
                } else {
                    $listaCrear = !empty($idsCliValidos) ? $idsCliValidos
                                : ($idCliSingle > 0 ? [$idCliSingle] : [null]);
                    $primerIdCreado = 0;
                    foreach ($listaCrear as $idClienteSave) {
                        $stmt = $conn->prepare(
                            "INSERT INTO dietas_base
                               (id_dietista, id_cliente, nombre, icono, color,
                                kcal_objetivo, prot_objetivo, carb_objetivo, grasas_objetivo,
                                estr_base, estr_deficit, estr_estrategia_id)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param('iisssddddsii',
                            $uid, $idClienteSave, $nombre, $icono, $color,
                            $objKcal, $objP, $objC, $objG,
                            $estrBase, $estrDef, $estrEstrId
                        );
                        $stmt->execute(); $nuevoId = $stmt->insert_id; $stmt->close();
                        guardar_bloques_dieta($conn, $nuevoId, $bloques);
                        if ($primerIdCreado === 0) $primerIdCreado = $nuevoId;
                    }
                    $idDieta = $primerIdCreado;
                }
                $conn->commit();
                $sufijo = (count($idsCliValidos) > 1) ? '&multi=' . count($idsCliValidos) : '';
                header("Location: dietista_dietas.php?id={$idDieta}&saved=1{$sufijo}"); exit;
            } catch (Exception $ex) {
                $conn->rollback();
                $error = 'Error al guardar: ' . $ex->getMessage();
            }
        }
    }
}

// POST borrar
if (!$modoEditor && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'borrar') {
    if (csrf_check($_POST['_csrf'] ?? null)) {
        $idDel = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM dietas_base WHERE id=? AND id_dietista=?");
        $stmt->bind_param('ii', $idDel, $uid); $stmt->execute(); $stmt->close();
        $ok = 'Dieta borrada.';
    }
}

// MODO LISTADO
if (!$modoEditor) {
    $clienteF = isset($_GET['cliente']) ? (int)$_GET['cliente'] : 0;
    $where = "d.id_dietista=? AND d.id_cliente IS NOT NULL";
    $params = [$uid]; $tipos = 'i';
    if ($clienteF > 0) { $where .= " AND d.id_cliente=?"; $params[] = $clienteF; $tipos .= 'i'; }

    $sql = "SELECT d.*, u.nombre_completo AS cliente
            FROM dietas_base d
            LEFT JOIN usuarios u ON u.id = d.id_cliente
            WHERE $where ORDER BY d.id DESC LIMIT 100";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($tipos, ...$params); $stmt->execute();
    $dietas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, nombre_completo FROM usuarios WHERE id_dietista=? AND rol='cliente' AND activo=1 ORDER BY nombre_completo");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $listaCli = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $base = '../../'; $active = 'dietas'; $titulo = 'Dietas';
    include __DIR__ . '/../../includes/sidebar.php';
    ?>
    <main class="page">
      <?php if ($ok):    ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

      <div class="card form-actions">
        <a class="btn btn-primary btn-block" href="dietista_dietas.php?accion=nueva">➕ Nueva dieta</a>
      </div>

      <form method="get" class="card">
        <label class="field">
          <span class="field-label">Filtrar por cliente</span>
          <select class="field-select" name="cliente" onchange="this.form.submit()">
            <option value="0">— Todos los clientes —</option>
            <?php foreach ($listaCli as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $clienteF === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>

      <section class="card">
        <h2 class="card-title">🍽️ <?= count($dietas) ?> dieta<?= count($dietas) === 1 ? '' : 's' ?></h2>
        <?php if (!$dietas): ?>
          <p class="text-muted">Aún no hay dietas. Pulsa "Nueva dieta".</p>
        <?php else: ?>
          <ul class="dt-clients" role="list">
            <?php foreach ($dietas as $d): ?>
              <li class="dt-client">
                <a class="dt-client-link" href="?id=<?= (int)$d['id'] ?>">
                  <div class="chats-avatar" style="background: <?= e($d['color']) ?>;"><?= e($d['icono']) ?></div>
                  <div class="dt-client-info">
                    <div class="dt-client-name"><?= e($d['nombre']) ?></div>
                    <div class="dt-client-meta">
                      <span class="text-muted">👤 <?= e($d['cliente'] ?? '—') ?></span>
                      <span class="dt-pill dt-pill--ok"><?= (int)$d['kcal_objetivo'] ?> kcal</span>
                    </div>
                  </div>
                </a>
                <form method="post" class="inline-form" onsubmit="return confirm('¿Borrar la dieta?');">
                  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="accion" value="borrar">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-mini">🗑️</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </main>
    <?php include __DIR__ . '/../../includes/footer.php'; ?>
    <?php exit;
}

// MODO EDITOR
$dietaCargada = null;
if ($idEditar > 0) {
    $stmt = $conn->prepare("SELECT * FROM dietas_base WHERE id=? AND id_dietista=?");
    $stmt->bind_param('ii', $idEditar, $uid); $stmt->execute();
    $dietaCargada = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$dietaCargada) { header('Location: dietista_dietas.php'); exit; }

    $bloques = [];
    $stmt = $conn->prepare("SELECT id, nombre_bloque FROM comidas_bloques WHERE id_dieta=? ORDER BY orden ASC");
    $stmt->bind_param('i', $idEditar); $stmt->execute();
    $rsB = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rsB as $b) {
        $alis = [];
        $st = $conn->prepare("SELECT id_alimento, cantidad_gr FROM dieta_alimentos WHERE id_bloque=? ORDER BY id ASC");
        $st->bind_param('i', $b['id']); $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
            $alis[] = ['id_alimento' => (int)$a['id_alimento'], 'gramos' => (float)$a['cantidad_gr']];
        }
        $st->close();
        $bloques[] = ['nombre' => $b['nombre_bloque'], 'alimentos' => $alis];
    }
    $dietaCargada['bloques'] = $bloques;
}

$stmt = $conn->prepare(
    "SELECT id, nombre, marca, racion_base_gr, kcal, proteinas, carbos, grasas
     FROM alimentos WHERE aprobado_global=1 OR id_dietista=? ORDER BY nombre"
);
$stmt->bind_param('i', $uid); $stmt->execute();
$alimentos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Clientes con anamnesis + peso + estrategias guardadas
$stmt = $conn->prepare(
    "SELECT u.id, u.nombre_completo,
            f.obj_kcal, f.obj_p, f.obj_c, f.obj_g,
            f.factor_p, f.factor_g,
            f.sexo, f.fecha_nacimiento, f.altura_cm,
            f.pasos_diarios, f.dias_gym, f.min_sesion,
            f.tipo_entreno, f.tipo_trabajo, f.factor_actividad,
            (SELECT pm.peso_kg FROM progresos_metricas pm
             WHERE pm.id_cliente = u.id AND pm.peso_kg IS NOT NULL
             ORDER BY pm.fecha_hora DESC LIMIT 1) AS peso
     FROM usuarios u
     LEFT JOIN fichas_anamnesis f ON f.id_cliente = u.id
     WHERE u.id_dietista=? AND u.rol='cliente' AND u.activo=1
     ORDER BY u.nombre_completo"
);
$stmt->bind_param('i', $uid); $stmt->execute();
$clientesRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$estrategiasPorCliente = [];
$stmt = $conn->prepare(
    "SELECT h.id, h.id_cliente, h.nombre, h.factor_p, h.factor_g, h.kcal, h.fecha
     FROM historial_estrategias h
     JOIN usuarios u ON u.id = h.id_cliente
     WHERE u.id_dietista=? AND u.rol='cliente'
     ORDER BY h.fecha DESC, h.id DESC"
);
$stmt->bind_param('i', $uid); $stmt->execute();
$rsE = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($rsE as $e) {
    $cid = (int)$e['id_cliente'];
    if (!isset($estrategiasPorCliente[$cid])) $estrategiasPorCliente[$cid] = [];
    $estrategiasPorCliente[$cid][] = [
        'id'        => (int)$e['id'],
        'nombre'    => $e['nombre'] ?: ('Estrategia ' . date('d/m/Y', strtotime($e['fecha']))),
        'factor_p'  => (float)$e['factor_p'],
        'factor_g'  => (float)$e['factor_g'],
        'kcal'      => (int)$e['kcal'],
    ];
}

$clientes = [];
foreach ($clientesRaw as $c) {
    $peso = isset($c['peso']) ? (float)$c['peso'] : null;
    $tdee = calcular_tdee_cliente($c, $peso);
    $cid  = (int)$c['id'];
    $clientes[] = [
        'id'              => $cid,
        'nombre_completo' => $c['nombre_completo'],
        'obj_kcal'        => $c['obj_kcal'] !== null ? (float)$c['obj_kcal'] : null,
        'obj_p'           => $c['obj_p']    !== null ? (float)$c['obj_p']    : null,
        'obj_c'           => $c['obj_c']    !== null ? (float)$c['obj_c']    : null,
        'obj_g'           => $c['obj_g']    !== null ? (float)$c['obj_g']    : null,
        'factor_p'        => $c['factor_p'] !== null ? (float)$c['factor_p'] : null,
        'factor_g'        => $c['factor_g'] !== null ? (float)$c['factor_g'] : null,
        'peso'            => $peso,
        'tdee_descanso'   => $tdee['descanso'] ?? null,
        'tdee_entreno'    => $tdee['entreno']  ?? null,
        'tdee_pond'       => $tdee['pond']     ?? null,
        'estrategias'     => $estrategiasPorCliente[$cid] ?? [],
    ];
}

$iconosSug = ['🍽️','🍎','🥗','🍳','🥑','🍱','🍔','🍕','🌮','🥦','🐟','🍣','🥩','🍗','🥪','🌯','🍲','🥘','🍜','🍰','🥞','🍯','💪','🔥','✅','📅'];

$base = '../../'; $active = 'dietas'; $titulo = $dietaCargada ? 'Editar dieta' : 'Nueva dieta';
include __DIR__ . '/../../includes/sidebar.php';
?>
<main class="page editor-dieta">

  <?php if ($ok):    ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <a class="btn btn-outline btn-mini" href="dietista_dietas.php">← Volver a dietas</a>

  <form id="editor-form" method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $idEditar ?>">
    <input type="hidden" name="paquete_json" id="paquete_json">

    <h2 class="card-title"><?= $dietaCargada ? '✏️ Editar dieta' : '➕ Nueva dieta' ?></h2>

    <label class="field">
      <span class="field-label">Nombre</span>
      <input class="field-input" type="text" id="in_nombre" maxlength="100" required placeholder="Ej: Definición semana 4">
    </label>

    <div class="grid-2">
      <label class="field">
        <span class="field-label">Icono</span>
        <select class="field-select" id="in_icono">
          <?php foreach ($iconosSug as $ic): ?>
            <option value="<?= e($ic) ?>"><?= e($ic) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field-label">Color</span>
        <input class="field-input" type="color" id="in_color" value="#2F9E73">
      </label>
    </div>

    <label class="field" id="cli_single_wrap" hidden>
      <span class="field-label">Cliente asignado</span>
      <select class="field-select" id="in_cliente">
        <option value="">— Sin cliente (plantilla) —</option>
        <?php foreach ($clientes as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <fieldset class="field cli-multi" id="cli_multi_wrap">
      <legend class="field-label">Asignar a uno o varios clientes <span class="text-muted">(sin marcar = plantilla)</span></legend>
      <div class="cli-multi-list">
        <?php foreach ($clientes as $c): ?>
          <label class="cli-multi-item">
            <input type="checkbox" class="in_cli_multi" value="<?= (int)$c['id'] ?>">
            <span><?= e($c['nombre_completo']) ?></span>
          </label>
        <?php endforeach; ?>
        <?php if (empty($clientes)): ?>
          <p class="text-muted">Aún no tienes clientes asignados.</p>
        <?php endif; ?>
      </div>
    </fieldset>

    <h3 class="card-title">🎯 Objetivos diarios</h3>
    <p class="text-soft">Elige base + déficit + estrategia y se calculan los macros automáticamente.</p>

    <div class="estr-controls">
      <label class="field">
        <span class="field-label">Base kcal</span>
        <select class="field-select" id="estr_base">
          <option value="pond">Media ponderada</option>
          <option value="entreno">Día entreno</option>
          <option value="descanso">Día descanso</option>
        </select>
      </label>

      <label class="field">
        <span class="field-label">Déficit / superávit</span>
        <select class="field-select" id="estr_deficit">
          <option value="15">+15% Volumen agresivo</option>
          <option value="10">+10% Volumen</option>
          <option value="5">+5% Recomp / superávit ligero</option>
          <option value="0">Mantenimiento (0%)</option>
          <option value="-5">−5% Definición suave</option>
          <option value="-10" selected>−10% Definición</option>
          <option value="-15">−15% Definición moderada</option>
          <option value="-20">−20% Definición agresiva</option>
          <option value="-25">−25% Cetogénica / muy agresiva</option>
        </select>
      </label>

      <label class="field">
        <span class="field-label">Tipo de estrategia</span>
        <select class="field-select" id="estr_estrategia">
          <option value="0">— Selecciona cliente —</option>
        </select>
      </label>
    </div>

    <p class="estr-preview" id="estr_preview"><span class="text-muted">Selecciona un cliente para ver su TDEE.</span></p>

    <div class="editor-objs">
      <label class="field obj-field obj-field--k">
        <span class="field-label">Kcal</span>
        <input class="field-input obj-input obj-input--k" type="number" id="in_kcal" min="0" step="1" placeholder="2000">
      </label>
      <label class="field obj-field obj-field--p">
        <span class="field-label">Proteínas (g)</span>
        <input class="field-input obj-input obj-input--p" type="number" id="in_p" min="0" step="0.1" placeholder="150">
      </label>
      <label class="field obj-field obj-field--c">
        <span class="field-label">Carbos (g)</span>
        <input class="field-input obj-input obj-input--c" type="number" id="in_c" min="0" step="0.1" placeholder="200">
      </label>
      <label class="field obj-field obj-field--g">
        <span class="field-label">Grasas (g)</span>
        <input class="field-input obj-input obj-input--g" type="number" id="in_g" min="0" step="0.1" placeholder="60">
      </label>
    </div>
  </form>

  <aside class="card live-panel" id="live-panel">
    <h3 class="card-title">📊 Resumen en tiempo real</h3>
    <div class="live-content">
      <div class="live-chart-wrap">
        <canvas id="macroChart"></canvas>
        <div class="live-chart-center">
          <div class="live-chart-kcal" id="live_chart_kcal">0</div>
          <div class="live-chart-kcal-lbl">kcal</div>
        </div>
      </div>
      <div class="live-bars">
        <div class="live-bar live-bar--k">
          <div class="live-bar-head"><span>Kcal</span><span><strong id="live_kcal">0</strong> <span class="live-bar-obj" id="lbl_kcal">/ 0</span></span></div>
          <div class="live-bar-track"><div class="live-bar-fill" id="bar_kcal"></div></div>
        </div>
        <div class="live-bar live-bar--p">
          <div class="live-bar-head"><span>Proteínas</span><span><strong id="live_p">0</strong>g <span class="live-bar-obj" id="lbl_p">/ 0g</span></span></div>
          <div class="live-bar-track"><div class="live-bar-fill" id="bar_p"></div></div>
        </div>
        <div class="live-bar live-bar--c">
          <div class="live-bar-head"><span>Carbos</span><span><strong id="live_c">0</strong>g <span class="live-bar-obj" id="lbl_c">/ 0g</span></span></div>
          <div class="live-bar-track"><div class="live-bar-fill" id="bar_c"></div></div>
        </div>
        <div class="live-bar live-bar--g">
          <div class="live-bar-head"><span>Grasas</span><span><strong id="live_g">0</strong>g <span class="live-bar-obj" id="lbl_g">/ 0g</span></span></div>
          <div class="live-bar-track"><div class="live-bar-fill" id="bar_g"></div></div>
        </div>
      </div>
    </div>
  </aside>

  <section id="bloques-container"></section>

  <div class="form-actions">
    <button type="button" id="btn_add_bloque" class="btn btn-outline btn-block">➕ Añadir bloque</button>
    <button type="button" id="btn_guardar" class="btn btn-primary btn-block">💾 Guardar dieta</button>
  </div>

</main>

<div id="modal_alimentos" class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h3 class="modal-title">🥕 Añadir alimentos</h3>
      <button type="button" class="modal-close" id="modal_close">✕</button>
    </div>
    <div class="modal-body">
      <label class="field">
        <input class="field-input" type="search" id="modal_search" placeholder="Buscar alimento..." autocomplete="off">
      </label>
      <p class="text-soft text-mini">Marca varios alimentos y ajusta los gramos de cada uno. Pulsa "Añadir" para agregarlos todos al bloque.</p>
      <ul class="modal-results" id="modal_results"></ul>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-primary btn-block" id="modal_add" disabled>Selecciona alimentos</button>
    </div>
  </div>
</div>

<div class="mini-stats" id="mini-stats">
  <div class="mini-stats-item"><span class="mini-stats-lbl">Kcal</span><strong id="mini_kcal">0/0</strong></div>
  <div class="mini-stats-item mini-stats-item--p"><span class="mini-stats-lbl">P</span><strong id="mini_p">0/0</strong></div>
  <div class="mini-stats-item mini-stats-item--c"><span class="mini-stats-lbl">C</span><strong id="mini_c">0/0</strong></div>
  <div class="mini-stats-item mini-stats-item--g"><span class="mini-stats-lbl">G</span><strong id="mini_g">0/0</strong></div>
</div>

<script>
  window.BBDD_ALIMENTOS = <?= json_encode($alimentos, JSON_UNESCAPED_UNICODE) ?>;
  window.CLIENTES_INFO  = <?= json_encode($clientes, JSON_UNESCAPED_UNICODE) ?>;
  window.DIETA_INICIAL  = <?= json_encode($dietaCargada, JSON_UNESCAPED_UNICODE) ?>;
  window.PRECARGAR_CLIENTE = <?= (int)$idClienteNuevo ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= e($base) ?>js/editor_dieta.js" defer></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
