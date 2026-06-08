<?php
/**
 * /includes/sidebar.php
 * Cabecera completa del layout: <!doctype>, <head>, <body>, topbar y drawer.
 * El archivo padre define ANTES del include:
 *   $base   string  Ruta relativa hasta la raíz del proyecto (ej. '../../')
 *   $active string  Slug del ítem activo
 *   $titulo string  Título a mostrar en la top-bar
 * Y AL FINAL del archivo padre incluye /includes/footer.php para cerrar el HTML.
 */

if (!isset($base, $active, $titulo)) {
    $base   = $base   ?? '../../';
    $active = $active ?? '';
    $titulo = $titulo ?? 'DIETISTA';
}

if (!esta_logueado()) {
    header('Location: ' . $base . 'login.php?e=sesion');
    exit;
}

$rol           = rol_actual();
$nombreUsuario = $_SESSION['usuario_nombre'] ?? 'Usuario';

$menus = [
    'cliente' => [
        ['dashboard', '🏠', 'Inicio',          'roles/cliente/cliente_dashboard.php'],
        ['dieta',     '🍽️', 'Mi dieta',        'roles/cliente/cliente_dieta.php'],
        ['lista',     '🛒', 'Lista de compra', 'roles/cliente/cliente_lista_compra.php'],
        ['checkin',   '📝', 'Check-in',        'roles/cliente/cliente_checkin.php'],
        ['medidas',   '📏', 'Medidas',         'roles/cliente/cliente_medidas.php'],
        ['progresos', '📈', 'Progresos',       'roles/cliente/cliente_progresos.php'],
        ['fotos',     '📸', 'Fotos',           'roles/cliente/cliente_fotos.php'],
        ['objetivos', '🎯', 'Objetivos',       'roles/cliente/cliente_objetivos.php'],
        ['consultas', '📅', 'Consultas',       'roles/cliente/cliente_consultas.php'],
        ['chat',      '💬', 'Chat',            'mensajes.php'],
        ['perfil',    '👤', 'Mi perfil',       'roles/cliente/cliente_perfil.php'],
    ],
    'dietista' => [
        ['dashboard',  '🏠', 'Inicio',       'roles/dietista/dietista_dashboard.php'],
        ['clientes',   '👥', 'Mis clientes', 'roles/dietista/dietista_ficha.php'],
        ['dietas',     '🍽️', 'Dietas',       'roles/dietista/dietista_dietas.php'],
        ['plantillas', '📑', 'Plantillas',   'roles/dietista/dietista_plantillas.php'],
        ['alimentos',  '🥦', 'Alimentos',    'roles/dietista/dietista_alimentos.php'],
        ['calendario', '📆', 'Calendario',   'roles/dietista/dietista_calendario.php'],
        ['medidas',    '📏', 'Medidas',      'roles/dietista/dietista_medidas.php'],
        ['fotos',      '📸', 'Comparador',   'roles/dietista/dietista_comparador_fotos.php'],
        ['checkin',    '📝', 'Check-ins',    'roles/dietista/dietista_checkin.php'],
        ['consultas',  '📅', 'Consultas',    'roles/dietista/dietista_consultas.php'],
        ['objetivos',  '🎯', 'Objetivos',    'roles/dietista/dietista_objetivos.php'],
        ['reporte',    '📄', 'Reporte PDF',  'roles/dietista/dietista_reporte_pdf.php'],
        ['chat',      '💬', 'Chat',            'mensajes.php'],
        ['notificaciones', '🔔', 'Notificaciones', 'roles/dietista/dietista_notificaciones.php'],
        ['notas',           '📌', 'Notas',            'roles/dietista/dietista_notas.php'],
        ['perfil',     '👤', 'Mi perfil',    'roles/dietista/dietista_perfil.php'],

    ],
    'admin' => [
        ['dashboard',    '🏠', 'Inicio',       'roles/admin/admin_dashboard.php'],
        ['usuarios',     '👥', 'Usuarios',     'roles/admin/admin_usuarios.php'],
        ['alimentos',    '🥦', 'Alimentos',    'roles/admin/admin_alimentos.php'],
        ['asignaciones', '🔗', 'Asignaciones', 'roles/admin/admin_asignaciones.php'],
        ['banners',      '🖼️', 'Banners',      'roles/admin/admin_banners.php'],
        ['logs',         '📜', 'Logs',         'roles/admin/admin_logs.php'],
        ['chat',      '💬', 'Chat',            'mensajes.php'],
        ['notificaciones', '🔔', 'Notificaciones', 'roles/admin/admin_notificaciones.php'],
        ['perfil',       '👤', 'Mi perfil',    'roles/admin/admin_perfil.php'],

    ],
];
$itemsMenu = $menus[$rol] ?? [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2F9E73">
<title>DIETISTA · <?= e($titulo) ?></title>
<script>(function(){try{var t=localStorage.getItem('dietista_theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link rel="stylesheet" href="<?= e($base) ?>css/style.css">
</head>
<body>

<header class="topbar" role="banner">
  <button class="topbar-btn" id="btnAbrirMenu" type="button" aria-label="Abrir menú" aria-controls="appDrawer" aria-expanded="false">
    <span class="hamb" aria-hidden="true"></span>
  </button>

  <h1 class="topbar-title"><?= e($titulo) ?></h1>

  <button class="topbar-btn topbar-btn--notif" id="btnNotis" type="button" aria-label="Notificaciones">
    <span aria-hidden="true">🔔</span>
    <span class="topbar-badge" id="notisBadge" hidden>0</span>
  </button>
</header>

<aside class="drawer" id="appDrawer" aria-hidden="true" aria-label="Menú principal">
  <div class="drawer-header">
    <div class="drawer-user">
      <div class="drawer-avatar" aria-hidden="true">
        <?= e(mb_strtoupper(mb_substr($nombreUsuario, 0, 1, 'UTF-8'), 'UTF-8')) ?>
      </div>
      <div class="drawer-user-info">
        <div class="drawer-user-name"><?= e($nombreUsuario) ?></div>
        <div class="drawer-user-role"><?= e(ucfirst($rol)) ?></div>
      </div>
    </div>
    <button class="drawer-close" id="btnCerrarMenu" type="button" aria-label="Cerrar menú">×</button>
  </div>

  <nav class="drawer-nav" aria-label="Secciones">
    <ul class="drawer-list" role="list">
      <?php foreach ($itemsMenu as $item):
        [$slug, $icono, $texto, $href] = $item;
        $esActivo = ($slug === $active);
      ?>
        <li>
          <a class="drawer-link<?= $esActivo ? ' is-active' : '' ?>"
             href="<?= e($base . $href) ?>"
             <?= $esActivo ? 'aria-current="page"' : '' ?>>
            <span class="drawer-link-icon" aria-hidden="true"><?= $icono ?></span>
            <span class="drawer-link-text"><?= e($texto) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="drawer-footer">
    <a class="btn btn-outline btn-block" href="<?= e($base) ?>logout.php">Cerrar sesión</a>
  </div>
</aside>

<div class="drawer-backdrop" id="appDrawerBackdrop" hidden></div>

<div class="notis-panel" id="notisPanel" hidden aria-hidden="true">
  <div class="notis-panel-header">
    <h2 class="notis-panel-title">Notificaciones</h2>
    <button class="notis-panel-close" id="btnNotisCerrar" type="button" aria-label="Cerrar">×</button>
  </div>
  <ul class="notis-list" id="notisList" role="list">
    <li class="notis-empty">Cargando…</li>
  </ul>
</div>

<?php
// Banners del sistema (solo si hay conexión y banners activos)
if (isset($conn) && esta_logueado()):
    $__banners = [];
    if ($__resB = @$conn->query("SELECT id, mensaje FROM banners_sistema WHERE activo=1 ORDER BY id DESC LIMIT 5")) {
        $__banners = $__resB->fetch_all(MYSQLI_ASSOC);
    }
    foreach ($__banners as $__b): ?>
      <div class="sys-banner" role="status">
        <span class="sys-banner-icon" aria-hidden="true">📢</span>
        <p class="sys-banner-msg"><?= e($__b['mensaje']) ?></p>
      </div>
<?php endforeach; endif; ?>