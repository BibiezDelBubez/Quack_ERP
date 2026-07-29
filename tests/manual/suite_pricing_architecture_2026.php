<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/pricing.php';

function chk(string $desc, $expected, $actual) {
    if ($expected !== $actual) {
        echo "[FAIL] $desc\n";
        echo "  Expected: " . json_encode($expected) . "\n";
        echo "  Actual:   " . json_encode($actual) . "\n";
        exit(1);
    }
    echo "[OK] $desc\n";
}

try {
    $pdo->beginTransaction();

    // 1. Setup
    $pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (999, 'PZ', 'Pezzi') ON CONFLICT(id) DO NOTHING");
    $pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, attivo) VALUES (9999, 'TEST-PRICE', 'Prodotto Test Pricing', 'semplice', 999, 1)");
    
    // Inserisco Prezzo Base (listino_id IS NULL)
    $pdo->exec("INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, quantita_minima_stock) VALUES (NULL, 9999, 0, 10.50, 999, 0)");
    
    // Inserisco Listino Commerciale ID 99
    $pdo->exec("INSERT INTO listini (id, nome, valuta, attivo, is_default) VALUES (99, 'Listino VIP', 'EUR', 1, 0)");
    $pdo->exec("INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, quantita_minima_stock) VALUES (99, 9999, 0, 8.00, 999, 0)");

    // 2. Test 1: Lettura Prezzo Base
    // Nessun listino passato -> Fallisce Level 1, va a Level 2 (Base)
    $resBase = qk_get_sale_price($pdo, 9999, null, 1.0, date('Y-m-d'));
    chk("Prezzo Base recuperato correttamente", 10.50, $resBase['prezzo_unitario']);
    chk("Origine del Prezzo Base e' 'base'", 'base', $resBase['origine']);

    // 3. Test 2: Lettura Listino VIP
    $resVip = qk_get_sale_price($pdo, 9999, 99, 1.0, date('Y-m-d'));
    chk("Prezzo VIP recuperato correttamente", 8.00, $resVip['prezzo_unitario']);
    chk("Origine del Prezzo VIP e' 'dedicato'", 'dedicato', $resVip['origine']);

    echo "\nTutti i test E2E architettura Pricing superati con successo.\n";
    $pdo->rollBack();
    exit(0);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[ERRORE FATALE] " . $e->getMessage() . "\n";
    exit(1);
}
