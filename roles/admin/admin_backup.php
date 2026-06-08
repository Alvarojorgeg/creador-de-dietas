<?php
/**
 * /roles/admin/admin_backup.php
 * Genera y descarga un .sql con la copia completa de la base de datos.
 */
require_once __DIR__ . '/../../includes/conexion.php';
requiere_rol('admin', '../../');

$uid = usuario_id();
log_admin($conn, $uid, 'BACKUP_DB', 'Descargada copia de seguridad de la base de datos');

// ---- Cabeceras para descarga ----
$fname = 'dietista_backup_' . date('Y-m-d_His') . '.sql';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

// ---- Helpers ----
function esc_value(mysqli $conn, $v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . $conn->real_escape_string((string)$v) . "'";
}

// Encabezado
echo "-- =============================================================\n";
echo "-- DIETISTA · Copia de seguridad de la base de datos\n";
echo "-- Base de datos: " . DB_NAME . "\n";
echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "-- Generada por: usuario_id=$uid\n";
echo "-- =============================================================\n\n";

echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "SET TIME_ZONE='+00:00';\n\n";

// ---- Listar tablas ----
$tablas = [];
$res = $conn->query("SHOW TABLES");
while ($r = $res->fetch_array()) $tablas[] = $r[0];

foreach ($tablas as $t) {

    echo "\n-- -----------------------------------------------------------\n";
    echo "-- Tabla: `$t`\n";
    echo "-- -----------------------------------------------------------\n";

    echo "DROP TABLE IF EXISTS `$t`;\n";

    // CREATE TABLE
    $res2 = $conn->query("SHOW CREATE TABLE `$t`");
    $r2 = $res2->fetch_array();
    echo $r2[1] . ";\n\n";

    // Datos
    $count = (int)$conn->query("SELECT COUNT(*) c FROM `$t`")->fetch_assoc()['c'];
    if ($count === 0) continue;

    echo "-- $count fila" . ($count === 1 ? '' : 's') . "\n";

    // Obtener nombres de columnas
    $colsRes = $conn->query("SHOW COLUMNS FROM `$t`");
    $colNames = [];
    while ($c = $colsRes->fetch_assoc()) $colNames[] = '`' . $c['Field'] . '`';
    $colList = implode(', ', $colNames);

    // Volcar en bloques de 200 filas
    $offset = 0;
    $batch  = 200;
    while ($offset < $count) {
        $rs = $conn->query("SELECT * FROM `$t` LIMIT $offset, $batch");
        $rows = [];
        while ($r = $rs->fetch_assoc()) {
            $vals = array_map(function ($v) use ($conn) { return esc_value($conn, $v); }, array_values($r));
            $rows[] = '(' . implode(', ', $vals) . ')';
        }
        if ($rows) {
            echo "INSERT INTO `$t` ($colList) VALUES\n";
            echo implode(",\n", $rows);
            echo ";\n";
        }
        $offset += $batch;
        flush();
    }
}

echo "\n\nSET FOREIGN_KEY_CHECKS=1;\n";
echo "-- Fin del backup\n";
exit;
