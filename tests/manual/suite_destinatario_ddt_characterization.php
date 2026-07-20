<?php
declare(strict_types=1);

/**
 * tests/manual/suite_destinatario_ddt_characterization.php
 *
 * Fase 2 del piano:
 * docs/Ciclo Vendite/PIANO - Congelare Destinatario sul DDT.md
 *
 * "Characterization test": dimostra il comportamento ATTUALE (prima del fix)
 * — la query di SpedizioniController::ddtView() (righe 203-217) risolve il
 * destinatario del DDT dal vivo tramite JOIN su clienti_fornitori/indirizzi,
 * quindi un DDT già emesso "cambia" se l'anagrafica del cliente cambia dopo.
 *
 * Uso un database temporaneo da db/init_sqlite.sql (schema reale, già con le
 * nuove colonne destinatario_* aggiunte in Fase 3 — ma non ancora popolate da
 * nessun codice, quindi il comportamento di lettura è ancora quello vecchio:
 * questa suite non testa le colonne nuove, testa che la query dal vivo di
 * OGGI sia davvero soggetta al problema descritto nel piano).
 *
 * Esecuzione: php tests/manual/suite_destinatario_ddt_characterization.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_dest_ddt_char_' . uniqid());
mkdir(DB_DIR, 0777, true);

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

// ============================================================================
// Seed: cliente con indirizzo, ordine, DDT — creato ESATTAMENTE come lo crea
// oggi SalesOrdersController.php (righe 717-724): nessuna colonna
// destinatario_* valorizzata, solo destinatario_id/indirizzo_destinazione_id.
// ============================================================================
$adminId = (int)$pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, codice_fiscale, attivo)
            VALUES (9001, 'CLI-9001', 'cliente', 'azienda', 'Cliente Originale Srl', '00000000091', 'CLITEST91', 1)");
$pdo->exec("INSERT INTO indirizzi (id, soggetto_id, tipo, predefinito, via, civico, cap, citta, provincia, nazione)
            VALUES (9001, 9001, 'spedizione', 1, 'Via Originale', '10', '20100', 'Milano', 'MI', 'IT')");

$pdo->prepare("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, indirizzo_spedizione_id, data_ordine, stato, stato_fatturazione,
               cliente_ragione_sociale, consegna_via, consegna_civico, consegna_cap, consegna_citta, consegna_provincia, consegna_nazione, creato_da)
               VALUES (9001, 'ODC-TEST-0001', 9001, 9001, '2026-07-20', 'in_lavorazione', 'da_fatturare',
               'Cliente Originale Srl', 'Via Originale', '10', '20100', 'Milano', 'MI', 'IT', ?)")->execute([$adminId]);

$pdo->prepare("INSERT INTO documenti_trasporto (numero_documento_trasporto, ordine_id, data, destinatario_id, indirizzo_destinazione_id, porto, stato, creato_da)
               VALUES ('DDT/TEST/0001', 9001, '2026-07-20', 9001, 9001, 'franco', 'emesso', ?)")->execute([$adminId]);

// ============================================================================
// 1. La query REALE di ddtView() (righe 203-217, riprodotta identica),
//    eseguita SUBITO dopo la creazione: deve mostrare i dati originali.
// ============================================================================
$sqlDdtView = "
    SELECT d.*,
           COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS destinatario_nome,
           cf.partita_iva AS destinatario_piva,
           cf.codice_fiscale AS destinatario_cf,
           a.via, a.civico, a.cap, a.citta, a.provincia, a.nazione, a.nome_sede
    FROM documenti_trasporto d
    JOIN ordini_clienti o ON o.id = d.ordine_id
    JOIN clienti_fornitori cf ON cf.id = d.destinatario_id
    LEFT JOIN indirizzi a ON a.id = d.indirizzo_destinazione_id
    WHERE d.id = ?
";

$ddt1 = $pdo->prepare($sqlDdtView);
$ddt1->execute([1]);
$r1 = $ddt1->fetch();

echo "=== 1. Subito dopo la creazione del DDT ===\n";
chk("destinatario_nome = 'Cliente Originale Srl'", 'Cliente Originale Srl', $r1['destinatario_nome']);
chk("via = 'Via Originale'", 'Via Originale', $r1['via']);
chk("citta = 'Milano'", 'Milano', $r1['citta']);

// ============================================================================
// 2. Il cliente cambia ragione sociale e sede DOPO l'emissione del DDT
//    (fusione, trasloco — scenario reale descritto nella REVISIONE §1.5).
// ============================================================================
$pdo->exec("UPDATE clienti_fornitori SET ragione_sociale = 'Cliente Rinominato Dopo Fusione Srl' WHERE id = 9001");
$pdo->exec("UPDATE indirizzi SET via = 'Via Nuova Dopo Trasloco', citta = 'Roma', cap = '00100' WHERE id = 9001");

// ============================================================================
// 3. Riapro lo STESSO DDT (stessa query, nessuna modifica al record DDT
//    stesso): con il codice di OGGI, la vista cambia — bug riprodotto.
// ============================================================================
$ddt2 = $pdo->prepare($sqlDdtView);
$ddt2->execute([1]);
$r2 = $ddt2->fetch();

echo "\n=== 2. Riaprendo lo stesso DDT DOPO il cambio anagrafica cliente (comportamento ATTUALE, da correggere) ===\n";
chk("BUG CONFERMATO: destinatario_nome ora mostra il nome NUOVO, non quello del giorno dell'emissione", 'Cliente Rinominato Dopo Fusione Srl', $r2['destinatario_nome']);
chk("BUG CONFERMATO: via ora mostra l'indirizzo NUOVO, non quello del giorno dell'emissione", 'Via Nuova Dopo Trasloco', $r2['via']);
chk("BUG CONFERMATO: citta ora mostra 'Roma', non 'Milano' (dove la merce fu davvero consegnata)", 'Roma', $r2['citta']);

echo "\n=== RIEPILOGO ===\n";
echo "OK: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
echo "\nConclusione: se tutte le asserzioni sono OK, il bug descritto nel piano è\n";
echo "confermato riproducibile con dati reali, non solo ipotizzato dalla lettura\n";
echo "del codice. Questa suite va poi sostituita/affiancata da\n";
echo "suite_destinatario_ddt_confirm.php (Fase 7) che verifica il comportamento\n";
echo "CORRETTO dopo il fix.\n";
