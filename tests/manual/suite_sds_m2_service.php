<?php
declare(strict_types=1);

/**
 * tests/manual/suite_sds_m2_service.php
 *
 * Checkpoint di M2 del piano:
 * docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS) su Prodotto-Fornitore.md
 *
 * Chiama direttamente i metodi di App\Services\SchedeSicurezzaService con
 * dati di prova su un database temporaneo fresco (init_sqlite.sql reale),
 * senza passare da nessun controller/HTTP — "logica isolata e testabile"
 * come richiesto dal piano per M2.
 *
 * Esecuzione: php tests/manual/suite_sds_m2_service.php
 */

define('DB_DIR', sys_get_temp_dir() . '/quack_test_sds_m2_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php'; // qk_is_docx_zip
require_once __DIR__ . '/../../app/Services/SchedeSicurezzaService.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $expected, $actual): void
{
    if ($expected === $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}
function chkTrue(string $label, bool $cond): void
{
    if ($cond) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n";
    $GLOBALS['__fail']++;
}

$svc = new \App\Services\SchedeSicurezzaService($pdo);

// --- Setup dati di supporto ---
$umId = (int)$pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
$pdo->prepare("INSERT INTO prodotti (sku_interno, nome, tipo, unita_misura_stock_id) VALUES (?, ?, 'semplice', ?)")
    ->execute(['SDS-M2-TEST-001', 'Prodotto Test M2', $umId]);
$prodottoId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (?, 'fornitore', 'azienda', ?, 1)")
    ->execute(['FOR-M2-TEST-A', 'Fornitore A Srl']);
$fornitoreAId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (?, 'fornitore', 'azienda', ?, 1)")
    ->execute(['FOR-M2-TEST-B', 'Fornitore B Srl']);
$fornitoreBId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id) VALUES (?, ?, ?)")
    ->execute([$prodottoId, $fornitoreAId, $umId]);
$pfAId = (int)$pdo->lastInsertId();

// Per §4.23 del piano, "fornitore disattivato" ai fini di questa feature è
// prodotti_fornitori.attivo = 0 (il rapporto specifico prodotto-fornitore),
// non clienti_fornitori.attivo (l'anagrafica fornitore in generale) — il
// select per una NUOVA SDS (M5) filtra sui prodotti_fornitori attivi.
$pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, unita_misura_acquisto_id, attivo) VALUES (?, ?, ?, 0)")
    ->execute([$prodottoId, $fornitoreBId, $umId]);
$pfBId = (int)$pdo->lastInsertId();

echo "=== validaLingua() / normalizzazione ===\n";
chk("validaLingua('it') -> 'it'", 'it', $svc->validaLingua('it'));
chk("validaLingua('IT') -> 'it' (normalizzata a minuscolo)", 'it', $svc->validaLingua('IT'));
chk("validaLingua('  En ') -> 'en' (trim + minuscolo)", 'en', $svc->validaLingua('  En '));

$linguaInvalidaRifiutata = false;
$messaggioLeggibile = '';
try {
    $svc->validaLingua('xx');
} catch (\RuntimeException $e) {
    $linguaInvalidaRifiutata = true;
    $messaggioLeggibile = $e->getMessage();
}
chkTrue("validaLingua('xx') solleva RuntimeException", $linguaInvalidaRifiutata);
chkTrue("messaggio di errore leggibile (non vuoto, cita 'xx')", str_contains($messaggioLeggibile, 'xx') && $messaggioLeggibile !== '');

$linguaVuotaRifiutata = false;
try {
    $svc->validaLingua('   ');
} catch (\RuntimeException $e) {
    $linguaVuotaRifiutata = true;
}
chkTrue("validaLingua('   ') (vuota dopo trim) rifiutata", $linguaVuotaRifiutata);

echo "\n=== generaNomeFisico() ===\n";
$nome1 = $svc->generaNomeFisico('pdf');
$nome2 = $svc->generaNomeFisico('pdf');
chkTrue("generaNomeFisico() produce nomi diversi ad ogni chiamata (token casuale)", $nome1 !== $nome2);
chkTrue("generaNomeFisico('pdf') termina con .pdf", str_ends_with($nome1, '.pdf'));
chkTrue("generaNomeFisico() genera 32 caratteri esadecimali + estensione", (bool)preg_match('/^[0-9a-f]{32}\.pdf$/', $nome1));

echo "\n=== checkOverlap() ===\n";
// Nessuna riga ancora: nessun overlap
$overlap0 = $svc->checkOverlap($pfAId, 'it', null, null);
chk("checkOverlap su gruppo vuoto -> null", null, $overlap0);

// Inserisci una riga IT "sempre valida"
$idRiga1 = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'it',
    'versione' => '1.0',
    'valido_dal' => null,
    'valido_al' => null,
]);
chkTrue("inserisciScheda() ritorna un id > 0", $idRiga1 > 0);

$rigaInserita = $pdo->query("SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRiga1")->fetch();
chk("riga appena inserita ha e_corrente = 1", 1, (int)$rigaInserita['e_corrente']);

// Un'altra riga IT nello stesso gruppo, senza date -> overlap con la prima
$overlap1 = $svc->checkOverlap($pfAId, 'it', null, null);
chkTrue("checkOverlap rileva conflitto con riga esistente stessa lingua/date aperte", $overlap1 !== null && (int)$overlap1['id'] === $idRiga1);

// Stessa combinazione ma escludendo se stessa (self_id) -> nessun conflitto (caso update senza cambi di date/lingua)
$overlapSelf = $svc->checkOverlap($pfAId, 'it', null, null, $idRiga1);
chk("checkOverlap con self_id = riga stessa -> null (nessun conflitto con se stessa)", null, $overlapSelf);

// Lingua diversa (EN), stesse date aperte -> nessun conflitto (gruppo diverso, §3.2)
$overlapEn = $svc->checkOverlap($pfAId, 'en', null, null);
chk("checkOverlap su lingua diversa (EN) -> null (gruppo scoping diverso)", null, $overlapEn);

// Date che non si sovrappongono (dopo la scadenza della prima, se ce ne fosse una) - la prima non ha valido_al quindi resta sempre aperta: qualunque nuova data confligge
$overlapDataFutura = $svc->checkOverlap($pfAId, 'it', '2030-01-01', null);
chkTrue("checkOverlap: nuova riga con valido_dal futuro confligge comunque con riga esistente senza valido_al (nessun limite superiore)", $overlapDataFutura !== null);

echo "\n=== ricalcolaCorrente() ===\n";
// Inserisci una seconda riga IT con date che NON si sovrappongono (aggiungendo prima valido_al alla prima)
$pdo->prepare("UPDATE prodotti_fornitori_schede_sicurezza SET valido_al = '2025-12-31' WHERE id = ?")->execute([$idRiga1]);
$idRiga2 = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'it',
    'versione' => '2.0',
    'valido_dal' => '2026-01-01',
    'valido_al' => null,
]);
$svc->ricalcolaCorrente($pfAId, 'it', $idRiga2);
$stateRiga1 = (int)$pdo->query("SELECT e_corrente FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRiga1")->fetchColumn();
$stateRiga2 = (int)$pdo->query("SELECT e_corrente FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRiga2")->fetchColumn();
chk("dopo ricalcolaCorrente: riga1 (vecchia) e_corrente = 0", 0, $stateRiga1);
chk("dopo ricalcolaCorrente: riga2 (nuova) e_corrente resta 1", 1, $stateRiga2);

echo "\n=== aggiornaScheda() — cambio lingua (§3.11) ===\n";
// riga2 è IT corrente. La spostiamo a DE (nessun conflitto in DE) -> permesso.
$svc->aggiornaScheda($idRiga2, [
    'lingua' => 'de',
    'versione' => '2.0',
    'valido_dal' => '2026-01-01',
    'valido_al' => null,
    'note' => 'Spostata a DE per test',
]);
$rigaDopoCambio = $pdo->query("SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRiga2")->fetch();
chk("dopo aggiornaScheda con cambio lingua: lingua = 'de'", 'de', $rigaDopoCambio['lingua']);
chkTrue("updated_at valorizzato esplicitamente dopo l'update", $rigaDopoCambio['updated_at'] !== null);
// Il gruppo IT non deve avere promozioni automatiche: nessuna riga IT resta e_corrente=1 (comportamento atteso, non un bug)
$correntiIt = (int)$pdo->query("SELECT COUNT(*) FROM prodotti_fornitori_schede_sicurezza WHERE prodotto_fornitore_id = $pfAId AND lingua = 'it' AND e_corrente = 1")->fetchColumn();
chk("gruppo IT di provenienza: zero righe correnti dopo il cambio lingua (nessuna promozione automatica, §3.11)", 0, $correntiIt);
// Poiché la riga era e_corrente=1, il chiamante (controller) chiamerebbe ricalcolaCorrente sul gruppo DE:
$svc->ricalcolaCorrente($pfAId, 'de', $idRiga2);
$correnteDe = (int)$pdo->query("SELECT e_corrente FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRiga2")->fetchColumn();
chk("riga spostata in DE resta e_corrente = 1 dopo ricalcolaCorrente sul nuovo gruppo", 1, $correnteDe);

echo "\n=== verificaEliminabile() / eliminaRiga() (§3.13) ===\n";
$idRigaFutura = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'fr',
    'valido_dal' => '2030-01-01',
    'valido_al' => null,
]);
$rigaFutura = $pdo->query("SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRigaFutura")->fetch();
$erroreFutura = $svc->verificaEliminabile($rigaFutura);
chk("verificaEliminabile su riga con valido_dal futuro -> null (eliminabile)", null, $erroreFutura);

$idRigaPassata = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'es',
    'valido_dal' => '2020-01-01',
    'valido_al' => null,
]);
$rigaPassata = $pdo->query("SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRigaPassata")->fetch();
$errorePassata = $svc->verificaEliminabile($rigaPassata);
chkTrue("verificaEliminabile su riga con valido_dal passato -> errore 'in_vigore'", $errorePassata !== null && $errorePassata['error'] === 'in_vigore');
chkTrue("messaggio errore cita la data valido_dal", str_contains($errorePassata['messaggio'], '2020-01-01'));

$idRigaSempre = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'pt',
    'valido_dal' => null,
    'valido_al' => null,
]);
$rigaSempre = $pdo->query("SELECT * FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRigaSempre")->fetch();
$erroreSempre = $svc->verificaEliminabile($rigaSempre);
chkTrue("verificaEliminabile su riga con valido_dal NULL (sempre) -> errore 'in_vigore'", $erroreSempre !== null && $erroreSempre['error'] === 'in_vigore');
chkTrue("messaggio errore usa 'sempre' quando valido_dal è NULL", str_contains($erroreSempre['messaggio'], 'sempre'));

$fileEliminato = $svc->eliminaRiga($idRigaFutura);
$rigaDopoEliminazione = $pdo->query("SELECT COUNT(*) FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRigaFutura")->fetchColumn();
chk("eliminaRiga() rimuove effettivamente la riga dal DB", 0, (int)$rigaDopoEliminazione);

echo "\n=== rimuoviSoloFile() (§3.13/§5.4bis) ===\n";
$idRigaConFile = $svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfAId,
    'lingua' => 'nl',
    'valido_dal' => '2020-01-01',
    'file_path' => 'schede_sicurezza/999/nl/test.pdf',
    'file_nome_orig' => 'SDS_originale.pdf',
]);
$vecchioPath = $svc->rimuoviSoloFile($idRigaConFile);
chk("rimuoviSoloFile ritorna il vecchio file_path", 'schede_sicurezza/999/nl/test.pdf', $vecchioPath);
$rigaSenzaFile = $pdo->query("SELECT file_path, file_rimosso_il FROM prodotti_fornitori_schede_sicurezza WHERE id = $idRigaConFile")->fetch();
chk("dopo rimuoviSoloFile: file_path = NULL", null, $rigaSenzaFile['file_path']);
chkTrue("dopo rimuoviSoloFile: file_rimosso_il valorizzato", $rigaSenzaFile['file_rimosso_il'] !== null);

// Ripetuto una seconda volta: non distruttivo, ritorna null (nessun path da cancellare)
$vecchioPath2 = $svc->rimuoviSoloFile($idRigaConFile);
chk("rimuoviSoloFile ripetuto una seconda volta ritorna null (file già rimosso)", null, $vecchioPath2);

echo "\n=== getSchede() — join fornitore + badge attivo/inattivo (§4.23) ===\n";
$svc->inserisciScheda([
    'prodotto_fornitore_id' => $pfBId, // fornitore B è disattivato
    'lingua' => 'it',
    'valido_dal' => null,
]);
$elenco = $svc->getSchede($prodottoId);
chkTrue("getSchede() ritorna un array non vuoto", count($elenco) > 0);
$rigaFornitoreB = null;
foreach ($elenco as $r) {
    if ((int)$r['prodotto_fornitore_id'] === $pfBId) { $rigaFornitoreB = $r; break; }
}
chkTrue("getSchede() include la riga del fornitore disattivato (mai nascosta, §4.23)", $rigaFornitoreB !== null);
if ($rigaFornitoreB !== null) {
    chk("getSchede(): fornitore_attivo = 0 per il fornitore disattivato", 0, (int)$rigaFornitoreB['fornitore_attivo']);
}

echo "\n=== validaMimeFile() ===\n";
$tmpPdf = tempnam(sys_get_temp_dir(), 'qk_test_pdf_');
file_put_contents($tmpPdf, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
$ext = $svc->validaMimeFile($tmpPdf);
chk("validaMimeFile su un vero PDF -> 'pdf'", 'pdf', $ext);
unlink($tmpPdf);

$tmpFake = tempnam(sys_get_temp_dir(), 'qk_test_fake_');
file_put_contents($tmpFake, "questo e' un file di testo semplice, non un PDF vero");
$rifiutato = false;
try {
    $svc->validaMimeFile($tmpFake);
} catch (\RuntimeException $e) {
    $rifiutato = true;
}
chkTrue("validaMimeFile su un file .txt rinominato/non valido -> rifiutato", $rifiutato);
unlink($tmpFake);

// --- Riepilogo ---
echo "\n=== Riepilogo ===\n";
echo "Passati: {$GLOBALS['__pass']}  Falliti: {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
