<?php
declare(strict_types=1);

/**
 * tests/manual/suite_destinatario_ddt_confirm.php
 *
 * Fase 7 del piano:
 * docs/Ciclo Vendite/PIANO - Congelare Destinatario sul DDT.md
 *
 * Conferma POST-fix: usa la funzione VERA qk_resolve_destinatario_ddt_stampa()
 * (includes/document_common.php) su un database temporaneo fresco da
 * db/init_sqlite.sql, non un mock. Verifica sia il ramo "congelato" (DDT nuovo,
 * con le colonne destinatario_* popolate come le popola oggi
 * SalesOrdersController.php) sia il ramo "legacy" (DDT storico senza quelle
 * colonne, deve ricadere sulla risoluzione dal vivo senza errori).
 *
 * Esecuzione: php tests/manual/suite_destinatario_ddt_confirm.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_dest_ddt_confirm_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/document_common.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $expected, $actual): void
{
    if ($expected == $actual) { echo "OK   - $label\n"; $GLOBALS['__pass']++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

$adminId = (int)$pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();

// ============================================================================
// Seed comune
// ============================================================================
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, attivo)
            VALUES (9001, 'CLI-9001', 'cliente', 'azienda', 'Cliente Originale Srl', '00000000091', 'CLITEST91', 1)");
$pdo->exec("INSERT INTO indirizzi (id, soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione)
            VALUES (9001, 9001, 'spedizione', 1, 'Via Originale', '10', '20100', 'Milano', 'MI', 'IT')");

$pdo->prepare("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, indirizzo_spedizione_id, data_ordine, stato, stato_fatturazione,
               cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale,
               consegna_via, consegna_civico, consegna_cap, consegna_citta, consegna_provincia, consegna_nazione, consegna_presso, creato_da)
               VALUES (9001, 'ODC-TEST-0001', 9001, 9001, '2026-07-20', 'in_lavorazione', 'da_fatturare',
               'Cliente Originale Srl', '00000000091', 'CLITEST91',
               'Via Originale', '10', '20100', 'Milano', 'MI', 'IT', 'Magazzino Interno', ?)")->execute([$adminId]);

// ============================================================================
// 1. DDT NUOVO, creato esattamente come lo popola oggi SalesOrdersController.php
//    (copiando da ordini_clienti — nessuna nuova query, solo un INSERT con i
//    campi destinatario_* valorizzati).
// ============================================================================
$order = $pdo->query("SELECT * FROM ordini_clienti WHERE id = 9001")->fetch();

$pdo->prepare("
    INSERT INTO documenti_trasporto (numero_documento_trasporto, ordine_id, data, destinatario_id, indirizzo_destinazione_id, porto, stato,
        destinatario_ragione_sociale, destinatario_partita_iva, destinatario_codice_fiscale,
        destinatario_via, destinatario_civico, destinatario_cap, destinatario_citta, destinatario_provincia, destinatario_nazione, destinatario_nome_sede,
        creato_da)
    VALUES ('DDT/TEST/0001', 9001, '2026-07-20', 9001, 9001, 'franco', 'emesso',
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
")->execute([
    $order['cliente_ragione_sociale'], $order['cliente_partita_iva'], $order['cliente_codice_fiscale'],
    $order['consegna_via'], $order['consegna_civico'], $order['consegna_cap'], $order['consegna_citta'], $order['consegna_provincia'], $order['consegna_nazione'], $order['consegna_presso'],
    $adminId,
]);
$ddtNuovoId = (int)$pdo->lastInsertId();

echo "=== 1. DDT nuovo, subito dopo la creazione ===\n";
$ddtRow = $pdo->query("SELECT * FROM documenti_trasporto WHERE id = $ddtNuovoId")->fetch();
$dest1 = qk_resolve_destinatario_ddt_stampa($pdo, $ddtRow);
chk("ragione_sociale = 'Cliente Originale Srl'", 'Cliente Originale Srl', $dest1['ragione_sociale']);
chk("via = 'Via Originale'", 'Via Originale', $dest1['via']);
chk("citta = 'Milano'", 'Milano', $dest1['citta']);
chk("nome_sede = 'Magazzino Interno'", 'Magazzino Interno', $dest1['nome_sede']);

// ============================================================================
// 2. Il cliente cambia ragione sociale e indirizzo DOPO l'emissione del DDT.
// ============================================================================
$pdo->exec("UPDATE clienti_fornitori SET ragione_sociale = 'Cliente Rinominato Dopo Fusione Srl' WHERE id = 9001");
$pdo->exec("UPDATE indirizzi SET via = 'Via Nuova Dopo Trasloco', citta = 'Roma' WHERE id = 9001");

echo "\n=== 2. Riapro lo stesso DDT DOPO il cambio anagrafica: deve mostrare ANCORA i dati originali ===\n";
$ddtRowRiletto = $pdo->query("SELECT * FROM documenti_trasporto WHERE id = $ddtNuovoId")->fetch();
$dest2 = qk_resolve_destinatario_ddt_stampa($pdo, $ddtRowRiletto);
chk("FIX CONFERMATO: ragione_sociale resta 'Cliente Originale Srl' (non quella rinominata)", 'Cliente Originale Srl', $dest2['ragione_sociale']);
chk("FIX CONFERMATO: via resta 'Via Originale' (non quella nuova)", 'Via Originale', $dest2['via']);
chk("FIX CONFERMATO: citta resta 'Milano' (non 'Roma')", 'Milano', $dest2['citta']);

// ============================================================================
// 3. DDT LEGACY: colonne destinatario_* vuote (simulando un record creato
//    prima di questa migrazione) — deve ricadere sulla risoluzione dal vivo,
//    senza errori, mostrando i dati ATTUALI (comportamento intenzionale per
//    i soli record storici, non un difetto).
// ============================================================================
$pdo->prepare("INSERT INTO documenti_trasporto (numero_documento_trasporto, ordine_id, data, destinatario_id, indirizzo_destinazione_id, porto, stato, creato_da)
               VALUES ('DDT/TEST/0002-LEGACY', 9001, '2026-06-01', 9001, 9001, 'franco', 'emesso', ?)")->execute([$adminId]);
$ddtLegacyId = (int)$pdo->lastInsertId();

echo "\n=== 3. DDT legacy (colonne destinatario_* vuote): fallback dal vivo ===\n";
$ddtLegacyRow = $pdo->query("SELECT * FROM documenti_trasporto WHERE id = $ddtLegacyId")->fetch();
$dest3 = qk_resolve_destinatario_ddt_stampa($pdo, $ddtLegacyRow);
chk("ramo legacy: nessun errore, ragione_sociale risolta dal vivo (quella attuale, rinominata)", 'Cliente Rinominato Dopo Fusione Srl', $dest3['ragione_sociale']);
chk("ramo legacy: via risolta dal vivo (quella attuale)", 'Via Nuova Dopo Trasloco', $dest3['via']);

echo "\n=== RIEPILOGO ===\n";
echo "OK: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
if ($GLOBALS['__fail'] > 0) {
    echo "\nATTENZIONE: asserzioni fallite, il fix non è completo o corretto.\n";
}
