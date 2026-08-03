<?php
// tests/manual/test_cascading.php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/pricelists.php';
require_once __DIR__ . '/../../app/Controllers/BaseController.php';

try {
    global $pdo;

    // 1. Creare Listino con Sconto
    $listinoId = qk_listino_insert($pdo, [
        'nome' => 'Distributori Test',
        'valuta' => 'EUR',
        'attivo' => 1,
        'is_default' => 0,
        'sconto_default_perc' => '10.0'
    ]);
    
    // 2. Creare Prodotto
    $sku = 'SKU-CASC-' . rand(1000, 9999);
    $v = [
        'nome' => 'Test Cascading',
        'sku_interno' => $sku,
        'tipo' => 'semplice',
        'prezzo_vendita_base' => '100.00',
        'attivo' => '1'
    ];
    $prodId = qk_insert_prodotto($pdo, $v);

    // 3. Simulare salvataggio riga API
    // Il JS manda il computed price come prezzo_unitario, lo sconto non viene più inviato.
    $rigaData = [
        'listino_id' => $listinoId,
        'prodotto_id' => $prodId,
        'quantita_minima' => 0,
        'prezzo_unitario' => '90.00', // Prezzo computed dal JS (100 - 10%)
        'unita_misura_id' => 1,
        'unita_misura_soglia_id' => 1,
        'quantita_minima_intento' => 0,
        'quantita_minima_stock' => 0,
        'sconto_perc' => 0,
        'note' => ''
    ];
    $rigaId = qk_listino_prezzo_riga_insert($pdo, $rigaData);

    // 4. Verificare 
    $riga = qk_listino_prezzo_riga_dettaglio($pdo, $rigaId);
    if (abs((float)$riga['prezzo_unitario'] - 90.0) > 0.001) {
        throw new Exception("Lettura riga fallita. Prezzo: " . $riga['prezzo_unitario']);
    }

    $listino = qk_get_listino($pdo, $listinoId);
    if (abs((float)$listino['sconto_default_perc'] - 10.0) > 0.001) {
        throw new Exception("Sconto listino fallito. Sconto: " . $listino['sconto_default_perc']);
    }

    // Cleanup
    $pdo->exec("DELETE FROM listini_prezzi WHERE prodotto_id = $prodId");
    $pdo->exec("DELETE FROM listini WHERE id = $listinoId");
    $pdo->exec("DELETE FROM giacenze WHERE prodotto_id = $prodId");
    $pdo->exec("DELETE FROM prodotti_valutazione_costi WHERE prodotto_id = $prodId");
    $pdo->exec("DELETE FROM prodotti WHERE id = $prodId");
    
    echo "TEST CASCADING PASSATO!\n";
    exit(0);
} catch (Throwable $e) {
    if (isset($listinoId)) {
        $pdo->exec("DELETE FROM listini WHERE id = $listinoId");
    }
    if (isset($prodId)) {
        $pdo->exec("DELETE FROM listini_prezzi WHERE prodotto_id = $prodId");
        $pdo->exec("DELETE FROM giacenze WHERE prodotto_id = $prodId");
        $pdo->exec("DELETE FROM prodotti_valutazione_costi WHERE prodotto_id = $prodId");
        $pdo->exec("DELETE FROM prodotti WHERE id = $prodId");
    }
    echo "TEST FALLITO: " . $e->getMessage() . "\n";
    exit(1);
}
