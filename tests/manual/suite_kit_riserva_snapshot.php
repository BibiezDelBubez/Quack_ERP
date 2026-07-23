<?php
declare(strict_types=1);

/**
 * tests/manual/suite_kit_riserva_snapshot.php
 *
 * AUDIT 3 §3.3 (docs/Ciclo Vendite/AUDIT 3 - Conformita a Prezzi-UoM,
 * Anagrafiche, Magazzino e AGENTS.md.md):
 * "Rilascio riserva Kit basato sulla composizione attuale, non su uno
 * snapshot al momento della riserva."
 *
 * Problema originale: qk_riserva_stock_ordine() e qk_rilascia_stock_ordine()
 * (includes/magazzino.php) leggevano SEMPRE la ricetta "dal vivo" da
 * componenti_kit, sia per riservare sia per rilasciare. Se la distinta di
 * un Kit veniva corretta in anagrafica DOPO che un ordine era stato
 * riservato, l'annullamento/rilascio calcolava le quantita' da liberare
 * sulla ricetta NUOVA, non su quella realmente usata al momento della
 * riserva — lasciando una riserva "fantasma" residua (o liberandone di
 * piu' del dovuto), silenziosamente clampata a 0 senza errore visibile.
 *
 * Fix: qk_riserva_stock_ordine() ora accetta un $rigaId opzionale; se
 * presente e il prodotto e' un Kit, salva la composizione realmente usata
 * in ordini_clienti_righe.kit_componenti_snapshot (colonna JSON, aggiunta
 * in questa stessa modifica). qk_rilascia_stock_ordine() (stesso nuovo
 * parametro opzionale) legge PRIMA lo snapshot congelato sulla riga; solo
 * se assente (rigaId non passato, o riga pre-esistente alla migrazione)
 * ricade sul comportamento legacy (lettura della ricetta live).
 *
 * Verifica, con database SQLite reale e chiamate alle funzioni vere
 * (nessuna imitazione):
 *  - Scenario 1: riserva con ricetta A, la ricetta cambia in anagrafica,
 *    l'ordine viene annullato — il rilascio usa la ricetta A (congelata),
 *    non quella nuova. Verificata anche la forma esatta dello snapshot
 *    salvato.
 *  - Scenario 2: chiamata legacy senza rigaId (compatibilita' con
 *    qualunque chiamante non ancora aggiornato) — comportamento identico
 *    a prima della modifica, sempre sulla ricetta live.
 *  - Scenario 3: rigaId passato ma nessuno snapshot salvato sulla riga
 *    (riga creata prima di questa modifica) — fallback automatico e
 *    silenzioso sulla ricetta live, nessun errore.
 *
 * Esecuzione: php tests/manual/suite_kit_riserva_snapshot.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_kit_snapshot_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', ''); // evita di caricare includes/config.php (dipende da $_SERVER['DOCUMENT_ROOT'], assente in CLI)

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/sales_orders.php';

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
    if (abs((float)$expected - (float)$actual) < 0.0001) {
        echo "OK   - $label ($actual)\n";
        $pass++;
        return;
    }
    echo "FAIL - $label\n       atteso:   $expected\n       ottenuto: $actual\n";
    $fail++;
}

// ── Seed minimo ──────────────────────────────────────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9001,'t9001','T','U','t9001@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9001,'CLI-9001','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, attivo) VALUES (9001, 'MAG9001', 'Magazzino Test', 1)");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9001, 'pz9001', 'Pezzi Test'), (9002, 'kg9001', 'Chilogrammi Test')");

// Prodotto Kit + componente
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9001, 'KIT-9001', 'Kit Test', 'kit', 9001, 0),
    (9002, 'COMP-A-9001', 'Componente A', 'semplice', 9002, 0)");

// Ricetta ORIGINALE al momento della riserva: 2 kg di COMP-A per unita' di Kit
$pdo->exec("INSERT INTO componenti_kit (kit_id, componente_id, quantita) VALUES (9001, 9002, 2.0)");

echo "=== Scenario 1: riserva con ricetta 2kg, poi la ricetta cambia a 3kg, poi annullo l'ordine ===\n";

$pdo->exec("INSERT INTO ordini_clienti (id, numero_ordine, cliente_id, magazzino_id, data_ordine, stato, stato_fatturazione, creato_da) VALUES (9001, 'ORD-9001', 9001, 9001, '2026-07-23', 'in_lavorazione', 'da_fatturare', 9001)");
$pdo->exec("INSERT INTO ordini_clienti_righe (id, ordine_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita, quantita_stock) VALUES (9001, 9001, 9001, 'Kit Test', 1, 0, 1)");
qk_riserva_stock_ordine($pdo, 9001, 1.0, 9001, 9001); // rigaId = 9001

$riservataDopoRiserva = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9002 AND magazzino_id = 9001")->fetchColumn();
chk('COMP-A riservato dopo la riserva = 2kg (1 unita Kit * 2kg)', 2.0, $riservataDopoRiserva);

$snapshotSalvato = $pdo->query("SELECT kit_componenti_snapshot FROM ordini_clienti_righe WHERE id = 9001")->fetchColumn();
chk('snapshot salvato non e vuoto', true, !empty($snapshotSalvato));
$decoded = json_decode((string)$snapshotSalvato, true);
chk('snapshot contiene 1 componente', 1, count($decoded));
chk('snapshot componente_id corretto', 9002, $decoded[0]['componente_id']);
chk('snapshot quantita corretta (2.0)', 2.0, $decoded[0]['quantita']);

// LA RICETTA CAMBIA: da 2kg a 3kg per unita (correzione in anagrafica DOPO la riserva)
$pdo->exec("UPDATE componenti_kit SET quantita = 3.0 WHERE kit_id = 9001 AND componente_id = 9002");

qk_ordine_cliente_annulla($pdo, 9001, 9001);

$riservataDopoAnnullo = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9002 AND magazzino_id = 9001")->fetchColumn();
chk('COMP-A riservato dopo annullo = 0 (rilasciati i 2kg REALMENTE riservati, non i 3kg della ricetta nuova)', 0.0, $riservataDopoAnnullo);

echo "\n=== Scenario 2: nessuno snapshot (chiamata legacy senza rigaId) -> fallback su ricetta live ===\n";
$pdo->exec("DELETE FROM giacenze WHERE prodotto_id = 9002");
$pdo->exec("UPDATE componenti_kit SET quantita = 5.0 WHERE kit_id = 9001 AND componente_id = 9002");

qk_riserva_stock_ordine($pdo, 9001, 1.0, 9001); // NESSUN rigaId
$riservataLegacy = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9002 AND magazzino_id = 9001")->fetchColumn();
chk('riserva legacy (senza rigaId) usa la ricetta live (5kg)', 5.0, $riservataLegacy);

qk_rilascia_stock_ordine($pdo, 9001, 1.0, 9001); // NESSUN rigaId
$riservataLegacyDopo = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9002 AND magazzino_id = 9001")->fetchColumn();
chk('rilascio legacy (senza rigaId) libera correttamente usando la ricetta live', 0.0, $riservataLegacyDopo);

echo "\n=== Scenario 3: rigaId fornito ma senza snapshot salvato (riga pre-esistente alla correzione) -> fallback live ===\n";
$pdo->exec("DELETE FROM giacenze WHERE prodotto_id = 9002");
$pdo->exec("INSERT INTO ordini_clienti_righe (id, ordine_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita, quantita_stock, kit_componenti_snapshot) VALUES (9002, 9001, 9001, 'Kit Test Legacy Riga', 1, 0, 1, NULL)");
qk_riserva_stock_ordine($pdo, 9001, 1.0, 9001); // riserva "a mano" senza passare rigaId, per simulare dati pre-esistenti
qk_rilascia_stock_ordine($pdo, 9001, 1.0, 9001, 9002); // rigaId=9002 ma snapshot NULL su quella riga
$dopoRilascioConRigaSenzaSnapshot = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9002 AND magazzino_id = 9001")->fetchColumn();
chk('rilascio con rigaId ma senza snapshot salvato ricade sulla ricetta live, nessun errore', 0.0, $dopoRilascioConRigaSenzaSnapshot);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
