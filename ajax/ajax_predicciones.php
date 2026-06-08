<?php
// Funciona tanto en /d/ajax_predicciones.php como en /d/ajax/ajax_predicciones.php
$_inc = is_file(__DIR__ . '/includes/conexion.php')
      ? __DIR__ . '/includes/'
      : __DIR__ . '/../includes/';
require_once $_inc . 'conexion.php';
require_once $_inc . 'predicciones.php';

// Base para redirección si no tiene permisos
$_base = is_file(__DIR__ . '/includes/conexion.php') ? '' : '../';
requiere_rol('cliente', $_base);

header('Content-Type: application/json; charset=utf-8');

$uid = usuario_id();
$mes = $_GET['mes'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    json_out(['ok' => false, 'error' => 'Mes inválido'], 400);
}

$desde   = $mes . '-01';
$hasta   = date('Y-m-t', strtotime($desde));
$hoyStr  = date('Y-m-d');
$mesHoy  = date('Y-m');

$esMesActual = ($mes === $mesHoy);
$esPasado    = ($mes < $mesHoy);

if ($esMesActual) {
    $desde = $hoyStr;
}

if ($esPasado) {
    json_out([
        'ok'             => false,
        'es_pasado'      => true,
        'mes'            => $mes,
        'desde'          => $desde,
        'hasta'          => $hasta,
        'es_mes_actual'  => false,
        'razon'          => 'Mes ya transcurrido.',
    ]);
}

$pred = predecir_cliente_rango($conn, $uid, $desde, $hasta, false);
$pred['mes']           = $mes;
$pred['es_mes_actual'] = $esMesActual;
$pred['es_pasado']     = false;

$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
list($yy, $mm) = explode('-', $mes);
$pred['mes_nombre'] = $meses[(int)$mm - 1] . ' ' . $yy;

json_out($pred);