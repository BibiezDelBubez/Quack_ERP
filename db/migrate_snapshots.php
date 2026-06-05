<?php
declare(strict_types=1);

/**
 * Migration script to populate snapshot columns for historical records in Quack ERP.
 * This runs as a data patch to ensure historical documents are consistent and self-contained,
 * rather than dynamically resolving changing registries at runtime.
 */

define('APP_ROOT_DIR', dirname(__DIR__));
$db_path = APP_ROOT_DIR . '/db/database.sqlite';

if (!file_exists($db_path)) {
    echo "Database SQLite non trovato in: $db_path\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    echo "Inizio migrazione dati snapshot...\n";

    // 1. Migrazione indirizzi di consegna per RFQ storiche
    $stRfqAddr = $pdo->query("SELECT id, indirizzo_consegna_id FROM rfq_testata WHERE (consegna_via IS NULL OR consegna_via = '') AND indirizzo_consegna_id IS NOT NULL");
    $rfqAddrRows = $stRfqAddr->fetchAll(PDO::FETCH_ASSOC);
    echo "Trovate " . count($rfqAddrRows) . " RFQ con indirizzo di consegna da storicizzare.\n";

    $stGetInd = $pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
    $stUpdateRfqAddr = $pdo->prepare("
        UPDATE rfq_testata SET
            consegna_via = ?, consegna_civico = ?, consegna_cap = ?, consegna_citta = ?,
            consegna_provincia = ?, consegna_nazione = ?, consegna_presso = ?,
            consegna_referente = ?, consegna_telefono = ?, consegna_note = ?
         WHERE id = ?
    ");

    $updatedAddrCount = 0;
    foreach ($rfqAddrRows as $row) {
        $stGetInd->execute([(int)$row['indirizzo_consegna_id']]);
        $ind = $stGetInd->fetch(PDO::FETCH_ASSOC);
        if ($ind) {
            $stUpdateRfqAddr->execute([
                $ind['via'] ?? '',
                $ind['civico'] ?? '',
                $ind['cap'] ?? '',
                $ind['citta'] ?? '',
                $ind['provincia'] ?? '',
                $ind['nazione'] ?? 'IT',
                $ind['nome_sede'] ?? '',
                $ind['referente_consegna'] ?? '',
                $ind['telefono_consegna'] ?? '',
                $ind['note_consegna'] ?? '',
                (int)$row['id']
            ]);
            $updatedAddrCount++;
        }
    }
    echo "Storicizzati $updatedAddrCount indirizzi di consegna su $updatedAddrCount RFQ.\n";

    // 2. Migrazione referenti (contatti) per RFQ storiche
    $stRfqCont = $pdo->query("SELECT id, contatto_id FROM rfq_testata WHERE (contatto_nome IS NULL OR contatto_nome = '') AND contatto_id IS NOT NULL");
    $rfqContRows = $stRfqCont->fetchAll(PDO::FETCH_ASSOC);
    echo "Trovate " . count($rfqContRows) . " RFQ con referente da storicizzare.\n";

    $stGetCont = $pdo->prepare("
        SELECT id, COALESCE(nome || ' ' || COALESCE(cognome, ''), ragione_sociale) AS nome_completo,
               email, telefono, cellulare
          FROM clienti_fornitori
         WHERE id = ?
    ");
    $stUpdateRfqCont = $pdo->prepare("
        UPDATE rfq_testata SET
            contatto_nome = ?, contatto_telefono = ?, contatto_email = ?
         WHERE id = ?
    ");

    $updatedRfqContCount = 0;
    foreach ($rfqContRows as $row) {
        $stGetCont->execute([(int)$row['contatto_id']]);
        $cont = $stGetCont->fetch(PDO::FETCH_ASSOC);
        if ($cont) {
            $nomeCompleto = $cont['nome_completo'] ?? '';
            $tel = $cont['telefono'] ?: $cont['cellulare'] ?: '';
            $stUpdateRfqCont->execute([
                trim($nomeCompleto),
                $tel,
                $cont['email'] ?? '',
                (int)$row['id']
            ]);
            $updatedRfqContCount++;
        }
    }
    echo "Storicizzati $updatedRfqContCount referenti su $updatedRfqContCount RFQ.\n";

    // 3. Migrazione referenti (contatti) per Preventivi Fornitore (offerte_testata) storici
    $stOffCont = $pdo->query("SELECT id, contatto_id FROM offerte_testata WHERE (contatto_nome IS NULL OR contatto_nome = '') AND contatto_id IS NOT NULL");
    $offContRows = $stOffCont->fetchAll(PDO::FETCH_ASSOC);
    echo "Trovate " . count($offContRows) . " offerte con referente da storicizzare.\n";

    $stUpdateOffCont = $pdo->prepare("
        UPDATE offerte_testata SET
            contatto_nome = ?, contatto_telefono = ?, contatto_email = ?
         WHERE id = ?
    ");

    $updatedOffContCount = 0;
    foreach ($offContRows as $row) {
        $stGetCont->execute([(int)$row['contatto_id']]);
        $cont = $stGetCont->fetch(PDO::FETCH_ASSOC);
        if ($cont) {
            $nomeCompleto = $cont['nome_completo'] ?? '';
            $tel = $cont['telefono'] ?: $cont['cellulare'] ?: '';
            $stUpdateOffCont->execute([
                trim($nomeCompleto),
                $tel,
                $cont['email'] ?? '',
                (int)$row['id']
            ]);
            $updatedOffContCount++;
        }
    }
    echo "Storicizzati $updatedOffContCount referenti su $updatedOffContCount offerte.\n";

    echo "Migrazione completata con successo!\n";

} catch (\Exception $e) {
    echo "Errore durante la migrazione: " . $e->getMessage() . "\n";
    exit(1);
}
