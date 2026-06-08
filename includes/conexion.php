<?php
/**
 * /includes/conexion.php
 * Conexión BD + sesión PERSISTENTE + timezone España + helpers.
 */

// ===================== CONFIG BD ============================
define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    'rootroot');
define('DB_NAME',    'dietista');
define('DB_CHARSET', 'utf8mb4');

// ===================== ZONA HORARIA · ESPAÑA ================
// Toda la app trabaja en hora española (PHP + MySQL).
date_default_timezone_set('Europe/Madrid');

// ===================== SESIÓN PERSISTENTE ===================
if (session_status() === PHP_SESSION_NONE) {
    $oneYear = 60 * 60 * 24 * 365;
    ini_set('session.cookie_lifetime',  (string)$oneYear);
    ini_set('session.gc_maxlifetime',   (string)$oneYear);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite',  'Lax');

    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($esHttps) ini_set('session.cookie_secure', '1');

    session_set_cookie_params([
        'lifetime' => $oneYear,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $esHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!empty($_SESSION['usuario_id'])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $oneYear,
            'path'     => $params['path']     ?: '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// ===================== CONEXIÓN MYSQLI ======================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    die('Error de conexión a la base de datos.');
}
$conn->set_charset(DB_CHARSET);

// Sincronizar el timezone de MySQL con el de PHP (Europe/Madrid).
// Calculamos el offset actual (gestiona DST automáticamente).
$tz = new DateTimeZone('Europe/Madrid');
$offsetSec = $tz->getOffset(new DateTime());
$hh = intdiv($offsetSec, 3600);
$mm = abs(intdiv($offsetSec % 3600, 60));
$offsetStr = sprintf('%+03d:%02d', $hh, $mm);
$conn->query("SET time_zone = '$offsetStr'");

// ===================== HELPERS ==============================
function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function esta_logueado(): bool {
    return !empty($_SESSION['usuario_id']) && !empty($_SESSION['rol']);
}
function rol_actual(): ?string  { return $_SESSION['rol'] ?? null; }
function usuario_id(): ?int     { return isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null; }

function requiere_login(string $base = '../../'): void {
    if (!esta_logueado()) {
        header('Location: ' . $base . 'login.php?e=sesion');
        exit;
    }
}
function requiere_rol($roles, string $base = '../../'): void {
    requiere_login($base);
    $roles = (array)$roles;
    if (!in_array($_SESSION['rol'], $roles, true)) {
        header('Location: ' . $base . 'login.php?e=permiso');
        exit;
    }
}
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_check(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}
function ruta_dashboard(string $rol): string {
    return 'roles/' . $rol . '/' . $rol . '_dashboard.php';
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function log_admin(mysqli $conn, int $idUsuario, string $accion, string $descripcion): void {
    $stmt = $conn->prepare(
        "INSERT INTO logs_admin (id_usuario_accion, accion, descripcion) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('iss', $idUsuario, $accion, $descripcion);
    $stmt->execute();
    $stmt->close();
}