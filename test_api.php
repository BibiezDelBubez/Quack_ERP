<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

require_once __DIR__ . '/app/Controllers/BaseController.php';
require_once __DIR__ . '/app/Controllers/SalesPricelistsController.php';

$controller = new SalesPricelistsController($pdo);

// Simulate $_GET
$_GET['prodotto_id'] = 1;
$_GET['listino_id'] = '';
$_GET['cliente_id'] = 2;
$_GET['quantita'] = 1;

try {
    ob_start();
    $controller->apiOttieniPrezzo();
    $output = ob_get_clean();
    echo "OUTPUT:\n" . $output;
} catch (Throwable $e) {
    ob_end_clean();
    echo "FATAL ERROR:\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
}
