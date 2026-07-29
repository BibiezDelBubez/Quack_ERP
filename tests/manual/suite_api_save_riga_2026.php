<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';
require_once __DIR__ . '/../../app/Controllers/SalesPricelistsController.php';

// Disabilita eventuale output estraneo
error_reporting(E_ALL);
ini_set('display_errors', '1');

register_shutdown_function(function() use ($pdo) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
});

$pdo->beginTransaction();

// Setup
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ', 'Pezzi') ON CONFLICT(id) DO NOTHING");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9999, 'TEST-API', 'Prodotto API', 'semplice', 999, 1)");

// Mock per bypassare Auth e CSRF
$_SESSION['csrf_token'] = 'test-token';
$_POST = [
    'csrf_token'      => 'test-token',
    'listino_id'      => '', // Test Inserimento Prezzo Base (deve tradursi in NULL)
    'riga_id'         => 0,
    'prodotto_id'     => 9999,
    'quantita_minima' => 0,
    'prezzo_unitario' => 12.34,
    'sconto_perc'     => 0,
    'unita_misura_id' => 999,
    'note'            => 'Prezzo Base API Test'
];

$controller = new SalesPricelistsController($pdo);
$controller->apiSaveRiga(); // Questo farà exit e stamperà il JSON
