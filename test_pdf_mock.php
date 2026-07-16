<?php
require_once __DIR__ . '/app/bootstrap.php';

$rfq = [
    'numero' => 'RFQ-2026-001',
    'versione' => 1,
    'riferimento_rfq_interno' => 'PRJ-X',
    'data_emissione' => '2026-07-15',
    'data_scadenza' => '2026-08-15',
    'fornitore_nome' => 'Acme Corp',
    'contatto_nome' => 'Wile E. Coyote',
    'contatto_telefono' => '555-1234',
    'contatto_email' => 'wile@acme.com',
    'fornitore_telefono' => '',
    'fornitore_email' => '',
    'consegna_via' => 'Via Fasulla',
    'consegna_civico' => '123',
    'consegna_cap' => '00100',
    'consegna_citta' => 'Roma',
    'consegna_provincia' => 'RM',
    'consegna_presso' => '',
    'consegna_referente' => '',
    'consegna_telefono' => '',
    'resa_porto' => 'porto_franco',
    'incoterm_codice' => 'DDP',
    'incoterm_luogo' => 'Magazzino Centrale',
    'data_consegna_prevista' => '2026-09-01',
    'metodo_pagamento_descrizione' => 'Bonifico 30gg',
    'condizioni_pagamento' => '',
    'note_fornitore' => 'Consegnare entro le 12:00',
];

$righe = [
    [
        'sku_fornitore' => 'ACM-01',
        'descrizione' => 'Anvil',
        'prodotto_nome' => 'Incudine',
        'formato' => 'Pesante',
        'quantita_richiesta' => 2,
        'uom' => 'pz',
        'prezzo_indicativo' => 150.00
    ],
    [
        'sku_fornitore' => 'ACM-02',
        'descrizione' => 'Rocket',
        'prodotto_nome' => 'Razzo',
        'formato' => 'ACME XL',
        'quantita_richiesta' => 1,
        'uom' => 'pz',
        'prezzo_indicativo' => 500.00
    ]
];

$azienda = [
    'ragione_sociale' => 'Gemina S.r.l.',
    'partita_iva' => 'IT12345678901',
    'email' => 'acquisti@gemina.it',
    'telefono' => '06-1234567',
    'logo_path' => '',
    'header_path' => ''
];

$indirizzoFornitore = 'Desert Road 1<br>80000 Arizona (AZ)';

require_once __DIR__ . '/app/Services/RfqDocumentService.php';
$service = new \App\Services\RfqDocumentService();

echo "Generazione PDF in corso...\n";
$pdfBinary = $service->generatePdf($rfq, $righe, $azienda, $indirizzoFornitore);
if (strlen($pdfBinary) > 1000) {
    echo "OK: Generato PDF di " . strlen($pdfBinary) . " bytes.\n";
} else {
    echo "ERRORE: PDF troppo piccolo (" . strlen($pdfBinary) . " bytes).\n";
}

echo "Generazione DOCX in corso...\n";
$docxPath = $service->generateDocx($rfq, $righe, $azienda, $indirizzoFornitore);
if (file_exists($docxPath) && filesize($docxPath) > 1000) {
    echo "OK: Generato DOCX di " . filesize($docxPath) . " bytes al path: $docxPath\n";
    unlink($docxPath);
} else {
    echo "ERRORE: Generazione DOCX fallita.\n";
}
