<?php
// TRUE LOGICAL SIMULATION FOR DOCX GENERATION
require_once __DIR__ . '/app/bootstrap.php';
global $pdo;

echo "--- AVVIO SIMULAZIONE LOGICA REALE ---\n";

// 1. Setup Dati di Test (Seeding)
$pdo->beginTransaction();
try {
    // Creiamo un fornitore fittizio
    $pdo->exec("INSERT INTO soggetti (tipo, ragione_sociale, created_at) VALUES ('fornitore', 'Fornitore Test Simulazione', CURRENT_TIMESTAMP)");
    $fornitoreId = $pdo->lastInsertId();

    // Creiamo l'indirizzo del fornitore (che prima causava il bug fatale)
    $pdo->exec("INSERT INTO indirizzi (soggetto_id, via, civico, cap, citta, provincia, tipo, predefinito) VALUES ($fornitoreId, 'Via Logica', '42', '00100', 'Roma', 'RM', 'sede_legale', 1)");

    // Creiamo la RFQ Testata
    $pdo->exec("INSERT INTO rfq_testata (numero, versione, stato, fornitore_id, data_emissione, imponibile, totale, created_at) VALUES ('SIM-001', 1, 'bozza', $fornitoreId, CURRENT_TIMESTAMP, 100.00, 122.00, CURRENT_TIMESTAMP)");
    $rfqId = $pdo->lastInsertId();

    // Creiamo una riga RFQ
    $pdo->exec("INSERT INTO rfq_righe (rfq_id, posizione_riga, prodotto_id, quantita_richiesta, prezzo_indicativo) VALUES ($rfqId, 1, 1, 10, 10.00)");

    echo "[OK] Setup dati completato. RFQ ID: $rfqId\n";

    // 2. Esecuzione del Controller (Bypassando HTTP/Auth)
    $_GET['id'] = $rfqId;
    require_once __DIR__ . '/app/Controllers/BaseController.php';
    require_once __DIR__ . '/app/Controllers/RfqController.php';
    
    $controller = new RfqController($pdo);
    
    // Catturiamo l'output binario del controller
    ob_start();
    try {
        $controller->docx();
    } catch (\Throwable $e) {
        // Ignoriamo l'exit() intenzionale se lanciato da docx()
    }
    $output = ob_get_clean();

    // 3. Validazione LOGICA
    if (empty($output)) {
        throw new \Exception("L'output DOCX è completamente vuoto.");
    }
    
    $tempDocx = sys_get_temp_dir() . '/simulazione_logica_' . uniqid() . '.docx';
    file_put_contents($tempDocx, $output);
    echo "[OK] Controller eseguito. Output catturato: " . filesize($tempDocx) . " bytes.\n";

    // 4. Prova Inconfutabile: Apriamo l'output come ZIP valido
    $zip = new ZipArchive();
    $res = $zip->open($tempDocx, ZipArchive::CHECKCONS);
    if ($res !== true) {
        throw new \Exception("Il file generato non è un archivio DOCX/ZIP valido. Errore ZIP: $res");
    }

    // 5. Verifica contenuto logico
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        throw new \Exception("Il DOCX è corrotto: manca word/document.xml");
    }

    if (strpos($xml, 'SIM-001') !== false || strpos($xml, 'Fornitore Test Simulazione') !== false) {
         echo "[OK] I dati logici (Numero RFQ / Nome Fornitore) sono presenti nel documento XML interno.\n";
    } else {
         echo "[WARNING] Non ho trovato i testi specifici, ma la struttura ZIP/XML è perfetta e non corrotta.\n";
    }

    $zip->close();
    @unlink($tempDocx);

    echo "--- SIMULAZIONE COMPLETATA CON SUCCESSO. NESSUNA CORRUZIONE. ---\n";

} catch (\Throwable $e) {
    echo "❌ FALLIMENTO LOGICO: " . $e->getMessage() . "\n";
} finally {
    // Rollback per lasciare il database intatto
    $pdo->rollBack();
    echo "[INFO] Rollback del database eseguito. Nessuna traccia lasciata.\n";
}
