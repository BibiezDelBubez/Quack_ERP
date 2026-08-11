<?php
declare(strict_types=1);

/**
 * Bug 2, Parte A (audit/PIANO - Correzioni Enterprise Post-Test Manuale (10-08-2026).md, §2.3/
 * §2.5, test PB1.7): questo test verificava e approvava "listino_id vuoto -> tradotto in NULL".
 * Quel comportamento è stato deliberatamente invertito il 10/08/2026 (confermato con l'utente):
 * il prezzo base ora vive sempre a listino_id=1 esplicito (fix Bug B2, 07/08/2026), quindi un
 * listino_id vuoto in ingresso non ha più un caso d'uso legittimo noto — apiSaveRiga() lo
 * rifiuta esplicitamente invece di salvare silenziosamente una riga NULL invisibile a
 * qk_get_sale_price(). Questo test verifica ORA il rifiuto, non più l'inserimento.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';
require_once __DIR__ . '/../../app/Controllers/SalesPricelistsController.php';

// Disabilita eventuale output estraneo
error_reporting(E_ALL);
ini_set('display_errors', '1');

$prodottoId = 9999;

// apiSaveRiga() termina con exit() a fine esecuzione: la verifica dell'output e del DB gira
// in uno shutdown function, registrato PRIMA della chiamata, cosi' viene eseguita comunque.
register_shutdown_function(function () use ($prodottoId) {
    global $pdo;

    $raw = ob_get_contents();
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $ok = true;
    $data = json_decode($raw, true);

    if (!is_array($data) || ($data['ok'] ?? null) !== false) {
        echo "[FAIL] Ci si aspettava {\"ok\":false}, ricevuto: $raw\n";
        $ok = false;
    } else {
        echo "[OK] Risposta ok:false ricevuta (" . ($data['error'] ?? '') . ")\n";
    }

    $count = (int) $pdo->query("SELECT COUNT(*) FROM listini_prezzi WHERE prodotto_id = $prodottoId")->fetchColumn();
    if ($count === 0) {
        echo "[OK] Nessuna riga creata in listini_prezzi per il prodotto di test\n";
    } else {
        echo "[FAIL] Attese 0 righe, trovate $count\n";
        $ok = false;
    }

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo $ok ? "\nPB1.7 superato: listino_id vuoto viene rifiutato.\n" : "\nPB1.7 FALLITO.\n";
});

$pdo->beginTransaction();

// Setup
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ', 'Pezzi') ON CONFLICT(id) DO NOTHING");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES ($prodottoId, 'TEST-API', 'Prodotto API', 'semplice', 999, 1)");

// Mock per bypassare Auth e CSRF
$_SESSION['csrf_token'] = 'test-token';
$_POST = [
    'csrf_token'      => 'test-token',
    'listino_id'      => '', // PB1.7: listino_id vuoto -> ora deve essere RIFIUTATO, non tradotto in NULL
    'riga_id'         => 0,
    'prodotto_id'     => $prodottoId,
    'quantita_minima' => 0,
    'prezzo_unitario' => 12.34,
    'sconto_perc'     => 0,
    'unita_misura_id' => 999,
    'note'            => 'Prezzo Base API Test'
];

ob_start();
$controller = new SalesPricelistsController($pdo);
$controller->apiSaveRiga(); // exit qui dentro -> prosegue nello shutdown function sopra
