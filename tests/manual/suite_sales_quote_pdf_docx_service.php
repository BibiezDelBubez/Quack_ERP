<?php
declare(strict_types=1);

/**
 * tests/manual/suite_sales_quote_pdf_docx_service.php
 *
 * Test End-to-End della generazione PDF/DOCX Preventivi di Vendita via SalesQuoteDocumentService.
 *
 * Protocollo della Simulazione Logica (AGENTS.md):
 * 1. Inizializzazione DB SQLite in memoria con schema completo db/init_sqlite.sql.
 * 2. Inserimento di dati reali di prova (Azienda, Cliente, Preventivo, Righe Articoli con sconti e UdM).
 * 3. Chiamata a SalesQuoteDocumentService::generatePdf() e verifica magic bytes %PDF-.
 * 4. Chiamata a SalesQuoteDocumentService::generateDocx() e verifica archivio Zip OpenXML (word/document.xml).
 *
 * Esecuzione: php tests/manual/suite_sales_quote_pdf_docx_service.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');

define('DB_DIR', sys_get_temp_dir() . '/quack_test_pdf_docx_' . uniqid());
if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}

define('UPLOAD_DIR', sys_get_temp_dir() . '/quack_test_upload_' . uniqid());
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

require_once APP_ROOT_DIR . '/vendor/autoload.php';
require_once APP_ROOT_DIR . '/includes/word_helper.php';
require_once APP_ROOT_DIR . '/includes/db.php';
require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/anagrafiche.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';
require_once APP_ROOT_DIR . '/app/Services/SalesQuoteDocumentService.php';

// Inizializzazione DB in memoria con schema db/init_sqlite.sql
$pdo = new PDO('sqlite::memory:');
$GLOBALS['pdo'] = $pdo;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

function init_test_schema(PDO $pdo, string $sqlFile): void
{
    $raw = file_get_contents($sqlFile);
    $statements = [];
    $buf        = '';
    $len        = strlen($raw);
    $i          = 0;
    $in_string  = null;
    $in_trigger = false;

    while ($i < $len) {
        $ch = $raw[$i];

        if ($in_string === null && $ch === '-' && isset($raw[$i + 1]) && $raw[$i + 1] === '-') {
            while ($i < $len && $raw[$i] !== "\n" && $raw[$i] !== "\r") {
                $i++;
            }
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            if ($in_string === null) {
                $in_string = $ch;
            } elseif ($in_string === $ch) {
                if (isset($raw[$i + 1]) && $raw[$i + 1] === $ch) {
                    $buf .= $ch;
                    $i++;
                } else {
                    $in_string = null;
                }
            }
        }

        if ($in_string === null) {
            $lookahead = substr($raw, $i, 14);
            if (preg_match('/^CREATE\s+TRIGGER/i', $lookahead)) {
                $in_trigger = true;
            }
        }

        if ($ch === ';' && $in_string === null) {
            if ($in_trigger) {
                $checkEnd = substr($raw, max(0, $i - 10), 11);
                if (preg_match('/END\s*;$/i', $checkEnd)) {
                    $in_trigger = false;
                    $stmt = trim($buf);
                    if ($stmt !== '') {
                        $statements[] = $stmt;
                    }
                    $buf = '';
                    $i++;
                    continue;
                }
            } else {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buf = '';
                $i++;
                continue;
            }
        }

        $buf .= $ch;
        $i++;
    }

    $stmt = trim($buf);
    if ($stmt !== '' && stripos($stmt, 'pragma journal_mode') !== 0) {
        $statements[] = $stmt;
    }

    try {
        echo "Statements parsed: " . count($statements) . "\n";
        $pdo->beginTransaction();
        foreach ($statements as $s) {
            if ($s !== '' && stripos($s, 'pragma journal_mode') !== 0) {
                $pdo->exec($s);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "ERR SCHEMA INIT: " . $e->getMessage() . "\n";
        throw $e;
    }
}

init_test_schema($pdo, APP_ROOT_DIR . '/db/init_sqlite.sql');

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function chk(string $label, $expected, $actual): void
{
    if ($expected === $actual) {
        echo "OK   - $label\n";
        $GLOBALS['__pass']++;
        return;
    }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

echo "=== INIZIO SUITE E2E: SALES QUOTE DOCUMENT SERVICE ===\n\n";

// 1. Popolamento Dati di Prova
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo_soggetto, ragione_sociale, tipo, partita_iva, email, telefono)
            VALUES (1, 'AZ-1', 'azienda', 'Acme SpA', 'entrambi', 'IT12345678901', 'info@acme.test', '02123456')");

$pdo->exec("INSERT INTO aziende_interne (id, soggetto_id, predefinita)
            VALUES (1, 1, 1)");

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo_soggetto, ragione_sociale, tipo, partita_iva)
            VALUES (10, 'CLI-10', 'azienda', 'Cliente Test Srl', 'cliente', 'IT09876543210')");

$pdo->exec("INSERT INTO preventivi (id, numero_preventivo, data_offerta, data_scadenza, cliente_id, cliente_ragione_sociale, cliente_indirizzo, cliente_partita_iva, stato, imponibile, totale_iva, totale, sconto_globale_perc, azienda_interna_id, creato_da)
            VALUES (100, 'PREV-2026/001', '2026-07-01', '2026-08-01', 10, 'Cliente Test Srl', 'Via Roma 1, Milano', 'IT09876543210', 'bozza', 500.00, 110.00, 610.00, 0.00, 1, 1)");

$pdo->exec("INSERT INTO preventivi_righe (preventivo_id, descrizione, quantita, prezzo_unitario, sconto_perc, imponibile_riga)
            VALUES (100, 'Prodotto Alfa Test', 10.0, 50.00, 0.0, 500.00)");

// 2. Simulazione preparaDatiStampaOfferta
$detail = qk_get_offerta($pdo, 100);
chk('Detail preventivo estratto', true, $detail !== null);

$quota = $detail['offerta'];
$righe = $detail['righe'];
$azienda = qk_resolve_azienda_emittente_stampa($pdo, $quota);

$dati = [
    'quota'               => $quota,
    'righe'               => $righe,
    'azienda'             => $azienda,
    'imponibile'          => (float) $quota['imponibile'],
    'totale_iva'          => (float) $quota['totale_iva'],
    'totale'              => (float) $quota['totale'],
    'sconto_globale_perc' => (float) $quota['sconto_globale_perc'],
];

$service = new \App\Services\SalesQuoteDocumentService();

/**
 * Cattura Warning/Notice/Deprecated PHP durante una chiamata: il caso critico
 * qui sono i dati mancanti/undefined-index dentro un template che scrive
 * l'output DENTRO un ob_start() attivo (generatePdf()) — un warning così non
 * arriva mai sullo stdout del test, finisce silenziosamente incollato dentro
 * l'HTML del PDF stesso. Verificato a fondo (AGENTS.md, regola sui controlli:
 * "non limitarti al solo controllo sintattico"), non solo "il file esiste".
 */
function eseguiSenzaWarning(string $label, callable $fn)
{
    $warnings = [];
    set_error_handler(function ($errno, $errstr) use (&$warnings) {
        $warnings[] = $errstr;
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    try {
        $result = $fn();
    } finally {
        restore_error_handler();
    }
    chk("$label — nessun warning/notice PHP emesso durante la generazione", [], $warnings);
    return $result;
}

// 3. Test Generazione PDF — caso semplice (1 riga, nessuno sconto)
echo "\n--- TEST GENERAZIONE PDF (caso semplice) ---\n";
try {
    $pdfBytes = eseguiSenzaWarning('PDF caso semplice', fn() => $service->generatePdf($dati));
    chk('PDF e una stringa non vuota', true, is_string($pdfBytes) && strlen($pdfBytes) > 1000);
    chk('PDF inizia con magic bytes %PDF-', '%PDF', substr($pdfBytes, 0, 4));
    chk('PDF ha il trailer %%EOF (file non troncato)', true, str_contains(substr($pdfBytes, -1024), '%%EOF'));
} catch (\Throwable $e) {
    chk('Eccezione PDF non attesa: ' . $e->getMessage(), true, false);
}

// 4. Test Generazione DOCX — caso semplice
echo "\n--- TEST GENERAZIONE DOCX (caso semplice) ---\n";
try {
    $tempPath = eseguiSenzaWarning('DOCX caso semplice', fn() => $service->generateDocx($dati));
    chk('File DOCX temporaneo creato', true, file_exists($tempPath));
    chk('Dimensione file DOCX > 2000 byte', true, filesize($tempPath) > 2000);

    // Validazione struttura OpenXML Zip
    $zip = new ZipArchive();
    $zipOpenResult = $zip->open($tempPath);
    chk('Archivio Zip DOCX apribile', true, $zipOpenResult === true);

    if ($zipOpenResult === true) {
        $hasDocXml = $zip->locateName('word/document.xml') !== false;
        chk('Presente word/document.xml nell archivio DOCX', true, $hasDocXml);

        // Verifica CONTENUTO reale, non solo struttura: i dati veri del
        // preventivo devono comparire nell'XML del documento, altrimenti un
        // DOCX "strutturalmente valido" potrebbe comunque essere vuoto o con
        // placeholder sbagliati e questo test non se ne accorgerebbe.
        $documentXml = $zip->getFromName('word/document.xml');
        chk('Numero preventivo presente nel DOCX', true, str_contains($documentXml, 'PREV-2026/001'));
        chk('Ragione sociale cliente presente nel DOCX', true, str_contains($documentXml, 'Cliente Test Srl'));
        chk('Descrizione prodotto presente nel DOCX', true, str_contains($documentXml, 'Prodotto Alfa Test'));
        $zip->close();
    }

    // Pulizia file temporaneo
    @unlink($tempPath);
    chk('File DOCX temporaneo rimosso', false, file_exists($tempPath));
} catch (\Throwable $e) {
    chk('Eccezione DOCX non attesa: ' . $e->getMessage(), true, false);
}

// 5. Caso ricco: 3 righe (con sconto riga), sconto globale, contatto,
// indirizzo di consegna diverso dalla sede, note cliente — esercita TUTTI i
// rami condizionali del template PDF (_pdf_template.php) e del DOCX
// (SalesQuoteDocumentService::generateDocx), non solo il ramo "vuoto" del
// test originale (nessuno di questi campi era valorizzato prima: se uno di
// quei rami avesse un bug — es. undefined index — questo test base non
// l'avrebbe mai potuto scoprire).
echo "\n--- TEST GENERAZIONE PDF+DOCX (caso ricco: sconti, contatto, consegna, note) ---\n";
$pdo->exec("INSERT INTO preventivi (
                id, numero_preventivo, riferimento_interno, oggetto, data_offerta, data_scadenza,
                cliente_id, cliente_ragione_sociale, cliente_indirizzo, cliente_partita_iva,
                contatto_nome, contatto_email, contatto_telefono,
                consegna_presso, consegna_via, consegna_civico, consegna_cap, consegna_citta, consegna_provincia,
                consegna_note, note_cliente,
                condizioni_pagamento, trasporto,
                stato, imponibile, totale_iva, totale, sconto_globale_perc, azienda_interna_id, creato_da
            ) VALUES (
                101, 'PREV-2026/002', 'RIF-INT-42', 'Fornitura solventi ADR', '2026-07-10', '2026-08-10',
                10, 'Cliente Test Srl', 'Via Roma 1, Milano', 'IT09876543210',
                'Mario Rossi', 'mario.rossi@clientetest.it', '3331234567',
                'Magazzino Nord', 'Via Industriale 9', '9', '20100', 'Milano', 'MI',
                'Consegna solo mattina', 'Grazie per la disponibilita',
                '30 GG D.F.F.M.', 'Franco destino',
                'bozza', 1450.00, 319.00, 1769.00, 5.00, 1, 1
            )");
$pdo->exec("INSERT INTO preventivi_righe (preventivo_id, descrizione, quantita, prezzo_unitario, sconto_perc, imponibile_riga, posizione_riga)
            VALUES
            (101, 'Solvente ADR Classe 3 - Fusto 25kg', 4.0, 120.00, 10.0, 432.00, 1),
            (101, 'Biocida Concentrato', 20.0, 35.00, 0.0, 700.00, 2),
            (101, 'Servizio trasporto ADR dedicato', 1.0, 318.00, 0.0, 318.00, 3)
            ");

$detail2 = qk_get_offerta($pdo, 101);
chk('Detail preventivo ricco estratto', true, $detail2 !== null);
chk('3 righe caricate', 3, count($detail2['righe']));

$quota2 = $detail2['offerta'];
$azienda2 = qk_resolve_azienda_emittente_stampa($pdo, $quota2);
$dati2 = [
    'quota'               => $quota2,
    'righe'               => $detail2['righe'],
    'azienda'             => $azienda2,
    'imponibile'          => (float) $quota2['imponibile'],
    'totale_iva'          => (float) $quota2['totale_iva'],
    'totale'              => (float) $quota2['totale'],
    'sconto_globale_perc' => (float) $quota2['sconto_globale_perc'],
];

try {
    // 1. Verifica contenuto HTML renderizzato dal template PDF (_pdf_template.php)
    $quota = $dati2['quota'];
    $righe = $dati2['righe'];
    $azienda = $dati2['azienda'];
    $imponibile = $dati2['imponibile'];
    $totale_iva = $dati2['totale_iva'];
    $totale = $dati2['totale'];
    $sconto_globale_perc = $dati2['sconto_globale_perc'];

    ob_start();
    require APP_ROOT_DIR . '/app/views/sales/quotes/_pdf_template.php';
    $pdfHtml = ob_get_clean();

    chk('PDF Template HTML - Numero preventivo presente', true, str_contains($pdfHtml, 'PREV-2026/002'));
    chk('PDF Template HTML - Oggetto preventivo presente', true, str_contains($pdfHtml, 'Fornitura solventi ADR'));
    chk('PDF Template HTML - Descrizione riga 1 (ADR) presente', true, str_contains($pdfHtml, 'Solvente ADR Classe 3 - Fusto 25kg'));
    chk('PDF Template HTML - Descrizione riga 2 presente', true, str_contains($pdfHtml, 'Biocida Concentrato'));
    chk('PDF Template HTML - Descrizione riga 3 (servizio) presente', true, str_contains($pdfHtml, 'Servizio trasporto ADR dedicato'));
    chk('PDF Template HTML - Sconto riga 1 (10%) presente', true, str_contains($pdfHtml, 'Sconto 10%'));
    chk('PDF Template HTML - Sconto globale (5%) presente', true, str_contains($pdfHtml, 'Sconto Globale (5%)'));
    chk('PDF Template HTML - Nome contatto (Alla c.a.) presente', true, str_contains($pdfHtml, 'Mario Rossi'));
    chk('PDF Template HTML - Email contatto presente', true, str_contains($pdfHtml, 'mario.rossi@clientetest.it'));
    chk('PDF Template HTML - Telefono contatto presente', true, str_contains($pdfHtml, '3331234567'));
    chk('PDF Template HTML - Indirizzo consegna (presso/via) presente', true, str_contains($pdfHtml, 'Magazzino Nord') && str_contains($pdfHtml, 'Via Industriale 9'));
    chk('PDF Template HTML - Note consegna presenti', true, str_contains($pdfHtml, 'Consegna solo mattina'));
    chk('PDF Template HTML - Note cliente presenti', true, str_contains($pdfHtml, 'Grazie per la disponibilita'));
    chk('PDF Template HTML - Modalita pagamento presenti', true, str_contains($pdfHtml, '30 GG D.F.F.M.'));
    chk('PDF Template HTML - Condizioni trasporto presenti', true, str_contains($pdfHtml, 'Franco destino'));

    // 2. Generazione effettiva PDF tramite mPDF
    $pdfBytes2 = eseguiSenzaWarning('PDF caso ricco', fn() => $service->generatePdf($dati2));
    chk('PDF caso ricco e una stringa non vuota', true, is_string($pdfBytes2) && strlen($pdfBytes2) > 1000);
    chk('PDF caso ricco inizia con magic bytes %PDF-', '%PDF', substr($pdfBytes2, 0, 4));
    chk('PDF caso ricco (3 righe+contatto+consegna) e piu grande del caso semplice (1 riga)', true, strlen($pdfBytes2) > strlen($pdfBytes));
} catch (\Throwable $e) {
    chk('Eccezione PDF caso ricco non attesa: ' . $e->getMessage(), true, false);
}

try {
    $tempPath2 = eseguiSenzaWarning('DOCX caso ricco', fn() => $service->generateDocx($dati2));
    chk('File DOCX caso ricco creato', true, file_exists($tempPath2));

    $zip2 = new ZipArchive();
    $ok2 = $zip2->open($tempPath2);
    chk('Archivio Zip DOCX caso ricco apribile', true, $ok2 === true);
    if ($ok2 === true) {
        $xml2 = $zip2->getFromName('word/document.xml');
        chk('DOCX XML - Numero preventivo caso ricco presente', true, str_contains($xml2, 'PREV-2026/002'));
        chk('DOCX XML - Oggetto preventivo presente', true, str_contains($xml2, 'Fornitura solventi ADR'));
        chk('DOCX XML - Descrizione riga 1 (ADR) presente', true, str_contains($xml2, 'Solvente ADR Classe 3'));
        chk('DOCX XML - Descrizione riga 2 presente', true, str_contains($xml2, 'Biocida Concentrato'));
        chk('DOCX XML - Descrizione riga 3 (servizio) presente', true, str_contains($xml2, 'Servizio trasporto ADR dedicato'));
        chk('DOCX XML - Nome contatto (Alla c.a.) presente', true, str_contains($xml2, 'Mario Rossi'));
        chk('DOCX XML - Email e telefono contatto presenti', true, str_contains($xml2, 'mario.rossi@clientetest.it') && str_contains($xml2, '3331234567'));
        chk('DOCX XML - Indirizzo consegna (presso/via) presente', true, str_contains($xml2, 'Magazzino Nord') && str_contains($xml2, 'Via Industriale 9'));
        chk('DOCX XML - Note consegna presenti', true, str_contains($xml2, 'Consegna solo mattina'));
        chk('DOCX XML - Note cliente presenti', true, str_contains($xml2, 'Grazie per la disponibilita'));
        chk('DOCX XML - Modalita pagamento presenti', true, str_contains($xml2, '30 GG D.F.F.M.'));
        chk('DOCX XML - Condizioni trasporto presenti', true, str_contains($xml2, 'Franco destino'));
        chk('DOCX XML - Sconto riga 1 (10%) visualizzato', true, str_contains($xml2, 'Sconto 10%'));
        chk('DOCX XML - Sconto globale (5%) visualizzato', true, str_contains($xml2, 'Sconto Globale (5%)'));
        chk('DOCX XML - Nome azienda emittente (blocco firma) presente', true, stripos($xml2, 'Acme SpA') !== false);
        $zip2->close();
    }
    @unlink($tempPath2);
} catch (\Throwable $e) {
    chk('Eccezione DOCX caso ricco non attesa: ' . $e->getMessage(), true, false);
}

// 6. Caso limite: preventivo SENZA azienda_interna_id risolvibile (nessuna
// azienda predefinita in DB) — verifica che qk_resolve_azienda_emittente_stampa()
// + il template non esplodano quando $azienda e' un array vuoto/parziale.
echo "\n--- TEST CASO LIMITE: nessuna azienda emittente risolvibile ---\n";
$pdoVuoto = new PDO('sqlite::memory:');
$pdoVuoto->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoVuoto->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
init_test_schema($pdoVuoto, APP_ROOT_DIR . '/db/init_sqlite.sql');
$pdoVuoto->exec("INSERT INTO clienti_fornitori (id, codice, tipo_soggetto, ragione_sociale, tipo) VALUES (10, 'CLI-10', 'azienda', 'Cliente Senza Azienda', 'cliente')");
$pdoVuoto->exec("INSERT INTO preventivi (id, numero_preventivo, data_offerta, cliente_id, cliente_ragione_sociale, stato, imponibile, totale_iva, totale, sconto_globale_perc, creato_da)
                 VALUES (200, 'PREV-2026/999', '2026-07-01', 10, 'Cliente Senza Azienda', 'bozza', 100.00, 22.00, 122.00, 0.00, 1)");
$pdoVuoto->exec("INSERT INTO preventivi_righe (preventivo_id, descrizione, quantita, prezzo_unitario, sconto_perc, imponibile_riga) VALUES (200, 'Prodotto Solo', 1.0, 100.00, 0.0, 100.00)");
$detail3 = qk_get_offerta($pdoVuoto, 200);
$azienda3 = qk_resolve_azienda_emittente_stampa($pdoVuoto, $detail3['offerta']);
$dati3 = [
    'quota' => $detail3['offerta'], 'righe' => $detail3['righe'], 'azienda' => $azienda3,
    'imponibile' => 100.0, 'totale_iva' => 22.0, 'totale' => 122.0, 'sconto_globale_perc' => 0.0,
];
try {
    $pdfBytes3 = eseguiSenzaWarning('PDF senza azienda emittente', fn() => $service->generatePdf($dati3));
    chk('PDF senza azienda emittente comunque generato', '%PDF', substr($pdfBytes3, 0, 4));
} catch (\Throwable $e) {
    chk('Eccezione PDF caso senza azienda non attesa: ' . $e->getMessage(), true, false);
}

echo "\n=== RIEPILOGO TEST ===\n";
echo "PASS: {$GLOBALS['__pass']}\n";
echo "FAIL: {$GLOBALS['__fail']}\n";

if ($GLOBALS['__fail'] > 0) {
    echo "\nRisultato: FALLITO\n";
    exit(1);
}

echo "\nRisultato: SUCCESSO LOGICO VERIFICATO (EXIT CODE 0)\n";
exit(0);
