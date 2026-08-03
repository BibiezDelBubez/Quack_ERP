<?php
// tests/manual/test_costi_interni.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';

try {
    global $pdo;

    // Inseriamo prodotto di test
    $v = [
        'nome' => 'Test Costi',
        'sku_interno' => 'SKU-COSTI',
        'tipo' => 'semplice',
        'costo_acquisto_base' => '10.55',
        'costo_acquisto_medio' => '11.00', // Sola lettura ma passiamolo per vedere se si salva (backend ignora la diff)
        'unita_misura_acquisto_id' => '1',
        'attivo' => '1'
    ];
    
    $id = qk_insert_prodotto($pdo, $v);
    
    // Leggiamo
    $read = qk_load_prodotto_form($pdo, $id);
    if (abs((float)$read['costo_acquisto_base'] - 10.55) > 0.001) {
        throw new Exception("Lettura costo_acquisto_base fallita. Trovato: " . $read['costo_acquisto_base']);
    }
    
    // Aggiorniamo
    $v['costo_acquisto_base'] = '15.75';
    qk_update_prodotto($pdo, $id, $v);
    
    $read2 = qk_load_prodotto_form($pdo, $id);
    if (abs((float)$read2['costo_acquisto_base'] - 15.75) > 0.001) {
        throw new Exception("Lettura costo_acquisto_base dopo update fallita. Trovato: " . $read2['costo_acquisto_base']);
    }

    $pdo->exec("DELETE FROM prodotti_valutazione_costi WHERE prodotto_id = $id");
    $pdo->exec("DELETE FROM giacenze WHERE prodotto_id = $id");
    $pdo->exec("DELETE FROM prodotti WHERE id = $id");
    
    echo "TEST COSTI INTERNI PASSATO!\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($id)) {
        $pdo->exec("DELETE FROM prodotti_valutazione_costi WHERE prodotto_id = $id");
        $pdo->exec("DELETE FROM giacenze WHERE prodotto_id = $id");
        $pdo->exec("DELETE FROM prodotti WHERE id = $id");
    }
    echo "TEST FALLITO: " . $e->getMessage() . "\n";
    exit(1);
}
