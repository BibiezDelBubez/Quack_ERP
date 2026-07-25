<?php
declare(strict_types=1);

/**
 * tests/manual/suite_magazzino_esplicito_preventivo_conferma.php
 *
 * PIANO - Magazzino Esplicito alla Conferma Ordine (P4b), §5, punto 2
 * (docs/Ciclo Vendite/PIANO - Magazzino Esplicito alla Conferma Ordine (P4b).md):
 * stesso scenario del punto 1 (Bari con scorta / Milano scarso, sessione !=
 * form) ma sul percorso "conferma preventivo -> ordine", incluso il
 * pre-check AJAX-equivalente (qk_preventivo_verifica_disponibilita() con
 * parametro esplicito, come chiamato da
 * SalesQuotesController::apiVerificaDisponibilitaOrdine()).
 *
 * Copre inoltre la retrocompatibilità di qk_preventivo_verifica_disponibilita():
 * senza magazzino esplicito (3° parametro omesso/null) deve continuare a
 * risolvere dalla sessione come prima del P4b (nessuna regressione per
 * eventuali altri chiamanti).
 *
 * Esecuzione: php tests/manual/suite_magazzino_esplicito_preventivo_conferma.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_magazzino_esplicito_preventivo_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/document_common.php';
require_once APP_ROOT_DIR . '/includes/magazzino.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

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
    if (is_bool($expected)) {
        $ok = ($expected === (bool)$actual);
    } else {
        $ok = abs((float)$expected - (float)$actual) < 0.0001;
    }
    if ($ok) {
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
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9451,'t9451','T','U','t9451@t.it','x',$ruoloId)");
$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (9451,'CLI-9451','cliente','azienda','Cliente Test')");
$pdo->exec("INSERT INTO unita_misura (id, codice, descrizione) VALUES (9451, 'pz9451', 'Pezzi Test')");
$pdo->exec("INSERT INTO listini (id, nome, valuta, is_default, attivo) VALUES (9451, 'Listino Test', 'EUR', 1, 1)");

// Due magazzini fisici radice reali: Bari (scorta abbondante) e Milano (scarso).
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9451, 'MAG-BARI-PRV', 'Bari', 'fisico', NULL, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, parent_id, attivo) VALUES (9452, 'MAG-MILANO-PRV', 'Milano', 'fisico', NULL, 1)");

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, traccia_lotti) VALUES
    (9451, 'PROD-9451', 'Prodotto Multimagazzino Preventivo Test', 'semplice', 9451, 0)");
// Bari: disponibile netto = 100 - 0 = 100 (abbondante).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9451, 9451, 100, 0)");
// Milano: disponibile netto = 2 - 0 = 2 (scarso).
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9451, 9452, 2, 0)");

// Sessione attiva puntata su Bari (dove la merce c'è) — sia il pre-check AJAX
// sia la conferma finale devono ignorarla quando arriva un magazzino esplicito.
$_SESSION['magazzino_attivo_id'] = 9451;

function nuovoPreventivoConRigaMag(PDO $pdo, int $cliId, int $uid, float $quantita, int $prodottoId): int
{
    static $seq = 0;
    $seq++;
    $numero = 'PRV-P4B-T5-' . $seq;
    $pdo->prepare("INSERT INTO preventivi (numero_preventivo, cliente_id, data_offerta, stato, listino_id, creato_da) VALUES (?, ?, '2026-07-25', 'inviato', 9451, ?)")
        ->execute([$numero, $cliId, $uid]);
    $preventivoId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO preventivi_righe (preventivo_id, prodotto_id, descrizione, quantita, prezzo_unitario, quantita_stock, unita_misura_id, unita_misura_stock_id)
                   VALUES (?, ?, 'Prodotto Multimagazzino Preventivo Test', ?, 10.00, ?, 9451, 9451)")
        ->execute([$preventivoId, $prodottoId, $quantita, $quantita]);

    return $preventivoId;
}

echo "=== Blocco 1: qk_preventivo_verifica_disponibilita() — retrocompatibilità senza magazzino esplicito ===\n";
$prevRetro = nuovoPreventivoConRigaMag($pdo, 9451, 9451, 5.0, 9451); // 5 < 100 (Bari, sessione) ma 5 > 2 (Milano)
$warningsRetro = qk_preventivo_verifica_disponibilita($pdo, $prevRetro);
chk("senza 3° parametro, risolve dalla sessione (Bari) -> nessun warning (comportamento pre-P4b invariato)", 0, count($warningsRetro));
$warningsRetroNull = qk_preventivo_verifica_disponibilita($pdo, $prevRetro, null);
chk("3° parametro esplicitamente null -> stesso comportamento (sessione, Bari)", 0, count($warningsRetroNull));

echo "\n=== Blocco 2: pre-check AJAX-equivalente con magazzino ESPLICITO, diverso dalla sessione ===\n";
echo "--- Sessione = Bari (9451, 100 disponibili), form = Milano (9452, 2 disponibili) ---\n";
$prevEsplicito = nuovoPreventivoConRigaMag($pdo, 9451, 9451, 5.0, 9451);
$magazzinoOrdineId = qk_valida_magazzino_ordine_esplicito($pdo, '9452');
chk("validazione esplicita restituisce Milano (9452)", 9452, $magazzinoOrdineId);

$warningsEsplicito = qk_preventivo_verifica_disponibilita($pdo, $prevEsplicito, $magazzinoOrdineId);
chk("con magazzino esplicito (Milano) il pre-check rileva lo scoperto (la sessione Bari lo avrebbe nascosto)", 1, count($warningsEsplicito));
if (count($warningsEsplicito) === 1) {
    chk("richiesto = 5", 5.0, $warningsEsplicito[0]['richiesto']);
    chk("disponibile a Milano = 2", 2.0, $warningsEsplicito[0]['disponibile']);
    chk("scoperto = 3", 3.0, $warningsEsplicito[0]['scoperto']);
}

echo "\n=== Blocco 3: conferma effettiva -> riserva scritta sul magazzino ESPLICITO, non sulla sessione ===\n";
$ordineId = qk_preventivo_conferma_ordine($pdo, $prevEsplicito, 9451, $magazzinoOrdineId, $warningsEsplicito);
$riga = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineId")->fetch(PDO::FETCH_ASSOC);
chk("ordine creato anche se scoperto (vendita allo scoperto mai bloccata)", true, $ordineId > 0);
chk("ordini_clienti.magazzino_id salvato = Milano (9452), NON Bari (9451, sessione)", 9452, (int)$riga['magazzino_id']);
chk("vendita_scoperto_confermata_da valorizzato (9451)", 9451, (int)$riga['vendita_scoperto_confermata_da']);

$riservataBari   = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9451 AND magazzino_id = 9451")->fetchColumn();
$riservataMilano = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = 9451 AND magazzino_id = 9452")->fetchColumn();
chk("riserva scritta su Milano (magazzino esplicito) = 5", 5.0, $riservataMilano);
chk("riserva su Bari (sessione, mai usata) resta 0 — prova che il campo esplicito e' stato rispettato anche alla conferma", 0.0, $riservataBari);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
