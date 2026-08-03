<?php
// tests/manual/test_rfq_autocomplete.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';
require_once __DIR__ . '/../../app/Controllers/RfqController.php';

try {
    global $pdo;
    
    // Seed test data
    $pdo->exec("INSERT INTO prodotti (id, tipo, nome, sku_interno, descrizione_confezione, unita_misura_stock_id, attivo) 
                VALUES (999999, 'semplice', 'Test Prodotto RFQ', 'SKU-TEST-RFQ', 'Secchio da 5 kg', 1, 1)");
                
    // Execute function
    $result = qk_acquisti_prodotti_search($pdo, 'SKU-TEST-RFQ', 0);
    
    // Cleanup
    $pdo->exec("DELETE FROM prodotti WHERE id = 999999");
    
    if (empty($result)) {
        throw new Exception("Nessun prodotto trovato");
    }
    
    $row = $result[0];
    
    if (!array_key_exists('formato', $row)) {
        throw new Exception("Chiave 'formato' mancante nel risultato!");
    }
    
    if ($row['formato'] !== 'Secchio da 5 kg') {
        throw new Exception("Valore 'formato' errato: " . $row['formato']);
    }
    
    echo "TEST PASSATO: Chiave 'formato' valorizzata correttamente a 'Secchio da 5 kg'.\n";
    exit(0);
    
} catch (Throwable $e) {
    // Cleanup on error
    if (isset($pdo)) {
        $pdo->exec("DELETE FROM prodotti WHERE id = 999999");
    }
    echo "TEST FALLITO: " . $e->getMessage() . "\n";
    exit(1);
}
