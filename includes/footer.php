<?php
/**
 * /includes/footer.php
 * Cierra el layout abierto por sidebar.php.
 * Espera que la variable $base esté definida (la define la vista).
 */
if (!isset($base)) { $base = '../../'; }
?>

<script src="<?= e($base) ?>js/sidebar.js" defer></script>
</body>
</html>

