<?php
declare(strict_types=1);

/**
 * tests/manual/suite_correzioni_ordini_tappa9.php
 *
 * PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7/Tappa 8-9
 * (26/07/2026, autorizzato dall'utente "sì, costruiscila"): verifica
 * end-to-end le funzioni di richiesta/approvazione/rifiuto di una
 * correzione su un ordine già fatturato, con database SQLite reale.
 *
 * Copre:
 *  1. Richiesta bloccata su un ordine NON fatturato (deve usare "Crea Nuova
 *     Versione" invece).
 *  2. Richiesta creata correttamente su un ordine fatturato, righe salvate.
 *  3. Approvazione richiede il riferimento documento esterno (CHECK a
 *     schema + validazione applicativa).
 *  4. Approvazione riuscita: stato passa ad 'approvata', log scritto.
 *  5. Rifiuto richiede un motivo, stato passa a 'rifiutata'.
 *  6. Maker-checker: con l'impostazione attiva, il creatore non può
 *     approvare la propria richiesta a meno di avere il bypass (admin).
 *  7. Permesso 'approvare_correzioni'/'bypass_maker_checker' presenti solo
 *     per il ruolo admin nella mappa can().
 *
 * Esecuzione: php tests/manual/suite_correzioni_ordini_tappa9.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_correzioni_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/compliance.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';
require_once APP_ROOT_DIR . '/includes/correzioni_ordini.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$pass = 0;
$fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    $ok = is_bool($expected) ? ($expected === (bool)$actual) : ($expected === $actual);
    if ($ok) { echo "OK   - $label (" . var_export($actual, true) . ")\n"; $pass++; }
    else { echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n"; $fail++; }
}
function chkThrows(string $label, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       non ha lanciato eccezione\n";
        $fail++;
    } catch (\Throwable $e) {
        echo "OK   - $label (eccezione: {$e->getMessage()})\n";
        $pass++;
    }
}

// --- Seed base ---
// ruoli 'admin'/'commerciale' esistono già (seed di db/init_sqlite.sql,
// riga ~1878) — riusati per id invece di reinserirli (violerebbe lo UNIQUE
// su ruoli.codice, verificato con l'errore reale prima di questa correzione).
$ruoloAdminId = (int) $pdo->query("SELECT id FROM ruoli WHERE codice = 'admin'")->fetchColumn();
$ruoloCommercialeId = (int) $pdo->query("SELECT id FROM ruoli WHERE codice = 'commerciale'")->fetchColumn();
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9701, 'admin_test', 'Anna', 'Admin', 'admin_test@example.com', 'x', {$ruoloAdminId})");
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9702, 'mario_test', 'Mario', 'Rossi', 'mario_test@example.com', 'x', {$ruoloCommercialeId})");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES (9701, 'CLI-TEST-9701', 'cliente', 'azienda', 'Cliente Test SRL', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9701, 'PZ', 'Pezzo')");
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES (9701, 'SKU-TEST-9701', 'Prodotto Test', 'semplice', 9701, 0)");

// Ordine NON fatturato
$pdo->exec("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, stato, stato_fatturazione, data_ordine, creato_da) VALUES (9701, 'ORD-TEST-9701', 9701, 'in_lavorazione', 'da_fatturare', '2026-07-26', 9701)");
$pdo->exec("INSERT INTO ordini_clienti_righe (id, ordine_id, prodotto_id, descrizione, quantita_ordinata, prezzo_unitario, unita_misura_id) VALUES (9701, 9701, 9701, 'Prodotto Test', 500, 10.0000, 9701)");

// Ordine FATTURATO (il caso che questo piano copre)
$pdo->exec("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, stato, stato_fatturazione, data_ordine, creato_da) VALUES (9702, 'ORD-TEST-9702', 9701, 'completato', 'fatturato', '2026-07-26', 9701)");
$pdo->exec("INSERT INTO ordini_clienti_righe (id, ordine_id, prodotto_id, descrizione, quantita_ordinata, prezzo_unitario, unita_misura_id) VALUES (9702, 9702, 9701, 'Prodotto Test', 500, 10.0000, 9701)");

echo "\n=== 1. Richiesta bloccata su ordine NON fatturato ===\n";
chkThrows('Richiesta su ordine non fatturato deve fallire', function () use ($pdo) {
    qk_correzione_ordine_richiedi($pdo, 9701, 'test', [['riga_ordine_id' => 9701, 'quantita_corretta' => 50]], 9702);
});

echo "\n=== 2. Richiesta creata correttamente su ordine fatturato ===\n";
$richiestaId = qk_correzione_ordine_richiedi(
    $pdo,
    9702,
    'Errore di battitura: dovevano essere 50, non 500',
    [['riga_ordine_id' => 9702, 'quantita_corretta' => 50, 'prezzo_unitario_corretta' => 10.0]],
    9702 // creata da Mario (commerciale)
);
chk('Richiesta creata con id valido', true, $richiestaId > 0);

$hR = $pdo->prepare("SELECT * FROM richieste_correzione_ordine WHERE id = ?");
$hR->execute([$richiestaId]);
$richiesta = $hR->fetch();
chk('Stato iniziale = in_approvazione', 'in_approvazione', $richiesta['stato']);
chk('creato_da = Mario (9702)', 9702, (int)$richiesta['creato_da']);

$righeRichiesta = qk_correzione_ordine_righe($pdo, $richiestaId);
chk('Una riga di correzione salvata', 1, count($righeRichiesta));
chk('Quantità originale = 500', true, abs(500 - (float)$righeRichiesta[0]['quantita_originale']) < 0.001);
chk('Quantità corretta = 50', true, abs(50 - (float)$righeRichiesta[0]['quantita_corretta']) < 0.001);

$logCount = (int)$pdo->query("SELECT COUNT(*) FROM log_stati_ordini WHERE tipo_documento = 'richiesta_correzione_ordine' AND documento_id = {$richiestaId}")->fetchColumn();
chk('Log scritto per la creazione', 1, $logCount);

echo "\n=== 3. Approvazione senza riferimento esterno deve fallire ===\n";
chkThrows('Approvazione senza riferimento esterno', function () use ($pdo, $richiestaId) {
    qk_correzione_ordine_approva($pdo, $richiestaId, '', 9701);
});

echo "\n=== 4. Approvazione riuscita (maker-checker di default disattivo: un solo utente basta) ===\n";
qk_correzione_ordine_approva($pdo, $richiestaId, 'SGA-NC-2026-001', 9701);
$hR->execute([$richiestaId]);
$richiestaApprovata = $hR->fetch();
chk('Stato = approvata', 'approvata', $richiestaApprovata['stato']);
chk('Riferimento esterno salvato', 'SGA-NC-2026-001', $richiestaApprovata['riferimento_doc_esterno']);
chk('chiusa_da valorizzato', 9701, (int)$richiestaApprovata['chiusa_da']);

chkThrows('Approvare una richiesta già chiusa deve fallire', function () use ($pdo, $richiestaId) {
    qk_correzione_ordine_approva($pdo, $richiestaId, 'ALTRO-RIF', 9701);
});

echo "\n=== 5. Rifiuto (nuova richiesta) ===\n";
$richiestaId2 = qk_correzione_ordine_richiedi($pdo, 9702, null, [['riga_ordine_id' => 9702, 'quantita_corretta' => 400]], 9702);
chkThrows('Rifiuto senza motivo deve fallire', function () use ($pdo, $richiestaId2) {
    qk_correzione_ordine_rifiuta($pdo, $richiestaId2, '', 9701);
});
qk_correzione_ordine_rifiuta($pdo, $richiestaId2, 'Quantità non congrua, verificare con il cliente', 9701);
$hR->execute([$richiestaId2]);
$richiestaRifiutata = $hR->fetch();
chk('Stato = rifiutata', 'rifiutata', $richiestaRifiutata['stato']);
chk('Motivo rifiuto salvato', 'Quantità non congrua, verificare con il cliente', $richiestaRifiutata['motivo_rifiuto']);

echo "\n=== 6. Maker-checker: creatore non può approvare da solo se attivo, admin sì (bypass) ===\n";
set_impostazione($pdo, 'maker_checker_correzioni_attivo', '1');

$richiestaId3 = qk_correzione_ordine_richiedi($pdo, 9702, null, [['riga_ordine_id' => 9702, 'quantita_corretta' => 300]], 9702);

// Simula sessione di Mario (commerciale, creatore della richiesta)
$_SESSION['qk_user'] = ['id' => 9702];
$_SESSION['qk_user_last_check'] = 0; // forza rilettura ruolo dal DB
chkThrows('Mario (creatore, senza bypass) non può approvare la propria richiesta', function () use ($pdo, $richiestaId3) {
    qk_correzione_ordine_approva($pdo, $richiestaId3, 'SGA-NC-2026-002', 9702);
});

// Simula sessione di Anna (admin, ha bypass_maker_checker)
$_SESSION['qk_user'] = ['id' => 9701];
$_SESSION['qk_user_last_check'] = 0;
qk_correzione_ordine_approva($pdo, $richiestaId3, 'SGA-NC-2026-002', 9701);
$hR->execute([$richiestaId3]);
$richiestaBypass = $hR->fetch();
chk('Admin approva comunque (anche se non è il creatore, qui) — stato approvata', 'approvata', $richiestaBypass['stato']);

echo "\n=== 7. Permessi nella mappa can() ===\n";
$_SESSION['qk_user'] = ['id' => 9701];
$_SESSION['qk_user_last_check'] = 0; // forza rilettura ruolo dal DB, come sopra
chk("admin ha 'approvare_correzioni'", true, can('approvare_correzioni'));
chk("admin ha 'bypass_maker_checker'", true, can('bypass_maker_checker'));
$_SESSION['qk_user'] = ['id' => 9702];
$_SESSION['qk_user_last_check'] = 0;
chk("commerciale NON ha 'approvare_correzioni'", false, can('approvare_correzioni'));

echo "\n=== Riepilogo ===\n";
echo "Passati: $pass  Falliti: $fail\n";
exit($fail > 0 ? 1 : 0);
