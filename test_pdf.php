<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/purchases_rfq.php';


// Prendiamo l'ultima RFQ inserita
$stmt = $pdo->query("SELECT id FROM rfq_testata ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch();
if (!$row) {
    die("Nessuna RFQ trovata nel DB.\n");
}
$rfq_id = (int)$row['id'];

$detail = qk_rfq_detail($pdo, $rfq_id);
if (!$detail) {
    die("Dettaglio RFQ non trovato.\n");
}

$rfq = $detail['rfq'];
$righe = $detail['righe'];

$azienda = qk_resolve_azienda_emittente($pdo, !empty($rfq['azienda_interna_id']) ? (int)$rfq['azienda_interna_id'] : null);
        
$indirizzoFornitore = '';
if (!empty($rfq['fornitore_id'])) {
    $indForn = qk_rfq_get_fornitore_indirizzo($pdo, (int)$rfq['fornitore_id']);
    if ($indForn) {
        $indirizzoFornitore = $indForn['via'] . ' ' . $indForn['civico'] . '<br>' . $indForn['cap'] . ' ' . $indForn['citta'] . ' (' . $indForn['provincia'] . ')';
    }
}

$service = new \App\Services\RfqDocumentService();

// TEST 1: PDF
echo "Generazione PDF in corso...\n";
$pdfBinary = $service->generatePdf($rfq, $righe, $azienda, $indirizzoFornitore);
if (strlen($pdfBinary) > 1000) {
    echo "OK: Generato PDF di " . strlen($pdfBinary) . " bytes.\n";
} else {
    echo "ERRORE: PDF troppo piccolo (" . strlen($pdfBinary) . " bytes).\n";
}

// TEST 2: DOCX
echo "Generazione DOCX in corso...\n";
$docxPath = $service->generateDocx($rfq, $righe, $azienda, $indirizzoFornitore);
if (file_exists($docxPath) && filesize($docxPath) > 1000) {
    echo "OK: Generato DOCX di " . filesize($docxPath) . " bytes al path: $docxPath\n";
    unlink($docxPath);
} else {
    echo "ERRORE: Generazione DOCX fallita.\n";
}
