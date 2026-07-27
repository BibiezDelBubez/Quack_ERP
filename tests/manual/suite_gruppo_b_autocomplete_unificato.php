<?php
declare(strict_types=1);

/**
 * tests/manual/suite_gruppo_b_autocomplete_unificato.php
 *
 * Suite di regressione comportamentale per le modifiche backend del Gruppo B
 * (docs/plans/PIANO - Unificazione Autocomplete (qkAutocomplete verso QkM2O).md):
 *
 *   - qk_get_listini_vendita_attivi(): nuovo parametro $q (D2)
 *   - qk_search_categorie(): nuovo parametro $excludeId (D4)
 *   - SalesQuotesController::apiListini(): nuovo endpoint (D2)
 *   - SalesQuotesController::apiClienti(): rimosso il guard "query vuota -> []"
 *     (D3, necessario perché QkPicker carica sempre con q='' al primo apri)
 *
 * Non si limita a verificare l'assenza di eccezioni: verifica il contenuto
 * reale dei risultati (quali righe tornano, quali no) e la retrocompatibilità
 * con tutti i chiamanti esistenti verificati via grep sul repo prima della
 * modifica (nessuno passava un argomento posizionale in più).
 *
 * Esecuzione: php tests/manual/suite_gruppo_b_autocomplete_unificato.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DRIVER', 'sqlite');
define('DB_DIR', sys_get_temp_dir() . '/quack_test_gruppo_b_' . uniqid());
mkdir(DB_DIR, 0777, true);
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');
define('BASE_URL', '');

require_once APP_ROOT_DIR . '/includes/functions.php';
require_once APP_ROOT_DIR . '/includes/prodotti.php';
require_once APP_ROOT_DIR . '/includes/tabelle_base.php';
require_once APP_ROOT_DIR . '/includes/sales_quotes.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(APP_ROOT_DIR . '/db/init_sqlite.sql'));

$fail = 0;
$total = 0;

function check(string $label, callable $fn): void {
    global $fail, $total;
    $total++;
    try {
        $fn();
        echo "OK   - $label\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL - $label: " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("Asserzione fallita: $msg");
    }
}

// ============================================================================
// Gruppo A — qk_get_listini_vendita_attivi() con $q (D2)
// ============================================================================

$pdo->exec("INSERT INTO listini (nome, valuta, attivo) VALUES ('Listino Standard', 'EUR', 1)");
$pdo->exec("INSERT INTO listini (nome, valuta, attivo) VALUES ('Listino Export USA', 'USD', 1)");
$pdo->exec("INSERT INTO listini (nome, valuta, attivo) VALUES ('Listino Disattivato', 'EUR', 0)");

check('listini: $q vuoto ritorna tutti gli attivi, mai i disattivati', function () use ($pdo) {
    $rows = qk_get_listini_vendita_attivi($pdo, false, '');
    $nomi = array_column($rows, 'nome');
    assertTrue(count($rows) === 2, 'attese 2 righe (solo attivi), trovate ' . count($rows));
    assertTrue(in_array('Listino Standard', $nomi, true), 'Listino Standard mancante');
    assertTrue(in_array('Listino Export USA', $nomi, true), 'Listino Export USA mancante');
    assertTrue(!in_array('Listino Disattivato', $nomi, true), 'Listino Disattivato non doveva comparire');
});

check('listini: $q che matcha un solo nome ritorna solo quello', function () use ($pdo) {
    $rows = qk_get_listini_vendita_attivi($pdo, false, 'Export');
    assertTrue(count($rows) === 1, 'attesa 1 riga, trovate ' . count($rows));
    assertTrue($rows[0]['nome'] === 'Listino Export USA', 'nome inatteso: ' . $rows[0]['nome']);
});

check('listini: $q che non matcha nulla ritorna array vuoto', function () use ($pdo) {
    $rows = qk_get_listini_vendita_attivi($pdo, false, 'NonEsiste123');
    assertTrue(count($rows) === 0, 'attesa 0 righe, trovate ' . count($rows));
});

check('listini: retrocompatibilità — firma a 1 argomento (tutti i chiamanti pre-esistenti) invariata', function () use ($pdo) {
    $rows = qk_get_listini_vendita_attivi($pdo);
    assertTrue(count($rows) === 2, 'la firma originale deve continuare a restituire tutti gli attivi');
});

check('listini: retrocompatibilità — firma a 2 argomenti ($excludeDefault) invariata', function () use ($pdo) {
    $pdo->exec("UPDATE listini SET is_default = 1 WHERE nome = 'Listino Standard'");
    $rows = qk_get_listini_vendita_attivi($pdo, true);
    $nomi = array_column($rows, 'nome');
    assertTrue(!in_array('Listino Standard', $nomi, true), 'excludeDefault doveva escludere il listino di default');
    assertTrue(in_array('Listino Export USA', $nomi, true), 'il non-default doveva restare');
});

// ============================================================================
// Gruppo B — qk_search_categorie() con $excludeId (D4)
// ============================================================================

$pdo->exec("INSERT INTO categorie_prodotto (nome, posizione_categoria) VALUES ('Solventi', 0)");
$catSolventiId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO categorie_prodotto (nome, posizione_categoria) VALUES ('Solventi Speciali', 0)");
$catSpecialiId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO categorie_prodotto (nome, posizione_categoria) VALUES ('Acidi', 0)");

check('categorie: senza excludeId, la ricerca "Solventi" trova entrambe le righe che matchano', function () use ($pdo) {
    $rows = qk_search_categorie($pdo, 'Solventi');
    assertTrue(count($rows) === 2, 'attese 2 righe, trovate ' . count($rows));
});

check('categorie: con excludeId = se stessa, la categoria non compare anche se il nome matcherebbe', function () use ($pdo, $catSolventiId) {
    $rows = qk_search_categorie($pdo, 'Solventi', 50, $catSolventiId);
    $ids = array_column($rows, 'id');
    assertTrue(count($rows) === 1, 'attesa 1 riga (solo "Solventi Speciali"), trovate ' . count($rows));
    assertTrue(!in_array($catSolventiId, $ids, true), 'la categoria esclusa non doveva comparire');
});

check('categorie: excludeId con $q vuoto esclude comunque dalla lista completa', function () use ($pdo, $catSolventiId) {
    $rows = qk_search_categorie($pdo, '', 50, $catSolventiId);
    $ids = array_column($rows, 'id');
    assertTrue(count($rows) === 2, 'attese 2 righe su 3 totali, trovate ' . count($rows));
    assertTrue(!in_array($catSolventiId, $ids, true), 'la categoria esclusa non doveva comparire');
});

check('categorie: excludeId = null (default) non cambia il comportamento preesistente', function () use ($pdo) {
    $rows = qk_search_categorie($pdo, '', 50, null);
    assertTrue(count($rows) === 3, 'attese tutte e 3 le righe, trovate ' . count($rows));
});

check('categorie: retrocompatibilità — firma a 2 argomenti (unico chiamante reale pre-modifica) invariata', function () use ($pdo) {
    $rows = qk_search_categorie($pdo, 'Acidi');
    assertTrue(count($rows) === 1 && $rows[0]['nome'] === 'Acidi', 'comportamento a 2 argomenti alterato');
});

// ============================================================================
// Gruppo C — replica della logica di SalesQuotesController::apiListini() (D2)
// Stesso approccio già usato in suite_categorie_tag_save.php per logica che
// vive nel controller (header()/exit() rendono i metodi non richiamabili
// direttamente in un test CLI): si testa la stessa trasformazione dati come
// funzione a sé. Se il controller reale cambia questa formula, aggiornare
// qui di conseguenza — non è un test sul controller, è un test sulla
// trasformazione che il controller applica.
// ============================================================================

function replica_apiListini_transform(array $listini): array {
    return array_map(fn($l) => ['id' => $l['id'], 'nome' => $l['nome'], 'label' => $l['nome'] . ' (' . $l['valuta'] . ')'], $listini);
}

check('apiListini (replica): compone label come "nome (valuta)", stessa formula della view _form.php:953', function () use ($pdo) {
    $rows = replica_apiListini_transform(qk_get_listini_vendita_attivi($pdo, false, 'Export'));
    assertTrue(count($rows) === 1, 'attesa 1 riga');
    assertTrue($rows[0]['label'] === 'Listino Export USA (USD)', 'label inattesa: ' . $rows[0]['label']);
});

// ============================================================================
// Gruppo D — qk_search_clienti() con $q vuoto (dipendenza del fix D3 su
// SalesQuotesController::apiClienti(), che ora inoltra sempre $q senza
// intercettare la stringa vuota prima di chiamare la funzione)
// ============================================================================

$pdo->exec("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES ('CLI001', 'cliente', 'azienda', 'Rossi Chimica SRL', 1)");
$pdo->exec("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, nome, cognome, attivo) VALUES ('CLI002', 'cliente', 'privato', 'Mario', 'Bianchi', 1)");
$pdo->exec("INSERT INTO clienti_fornitori (codice, tipo, tipo_soggetto, ragione_sociale, attivo) VALUES ('CLI003', 'cliente', 'azienda', 'Cliente Disattivato SRL', 0)");

check('qk_search_clienti: $q vuoto ritorna tutti i clienti attivi (il fix D3 dipende da questo)', function () use ($pdo) {
    $rows = qk_search_clienti($pdo, '');
    assertTrue(count($rows) === 2, 'attese 2 righe (solo attivi), trovate ' . count($rows));
});

check('qk_search_clienti: mai un cliente attivo=0', function () use ($pdo) {
    $rows = qk_search_clienti($pdo, '');
    $labels = array_column($rows, 'label');
    assertTrue(!in_array('Cliente Disattivato SRL', $labels, true), 'il cliente disattivato non doveva comparire');
});

// ============================================================================
// Esito
// ============================================================================

echo "\n" . ($fail === 0 ? "TUTTI I TEST OK ($total/$total)" : "$fail/$total TEST FALLITI") . "\n";

@unlink(DB_DIR . '/database.sqlite');
@rmdir(DB_DIR . '/backups');
@rmdir(DB_DIR);

exit($fail === 0 ? 0 : 1);
