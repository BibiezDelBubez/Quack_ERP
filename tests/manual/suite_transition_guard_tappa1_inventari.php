<?php
declare(strict_types=1);

/**
 * tests/manual/suite_transition_guard_tappa1_inventari.php
 *
 * Tappa 1 del piano:
 * docs/plans/PIANO - Motore Unificato di Transizioni di Stato.md
 *
 * tests/manual/suite_create_inventario.php (preesistente) NON basta da sola a
 * verificare questa tappa: il suo "Scenario I" richiama una REPLICA interna
 * allo script (test_ii_submit(), righe 150-152 del file) che duplica a mano
 * il vecchio controllo del controller — non chiama mai la funzione reale
 * qk_convalida_inventario() per quel percorso. Superarlo non dimostra niente
 * sulla guardia nuova appena aggiunta dentro includes/inventario.php.
 *
 * Questa suite chiama DIRETTAMENTE qk_convalida_inventario() (la funzione
 * reale, non una replica), bypassando apposta il controller, per verificare
 * il punto che conta davvero: che la guardia AUTORITATIVA a livello di model
 * blocchi comunque un tentativo di scrittura su un inventario già
 * convalidato, anche se qualcosa a monte (controller, richiesta duplicata,
 * race condition) non l'avesse già bloccato prima.
 *
 * Esecuzione: php tests/manual/suite_transition_guard_tappa1_inventari.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));

require_once APP_ROOT_DIR . '/includes/inventario.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

$pass = 0; $fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected == $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}
function chkThrows(string $label, callable $fn, ?string $sottostringaMessaggio = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna eccezione (l'operazione NON è stata bloccata!)\n";
        $fail++;
    } catch (\Throwable $e) {
        if ($sottostringaMessaggio !== null && !str_contains($e->getMessage(), $sottostringaMessaggio)) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio inatteso: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato correttamente: {$e->getMessage()})\n";
        $pass++;
    }
}

// ── Setup dati minimi (stesso pattern, stesse colonne, già verificato in
//    tests/manual/suite_create_inventario.php — non reinventato a mano) ────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9101, 't9101', 'T', 'U', 't9101@t.it', 'x', $ruoloId)");
$UID = 9101;

$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9102, 'MAG-9102', 'Test Tappa1', 'fisico', 0, 1)");
$magId = 9102;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9101, 'Cat Test')");
$catId = 9101;

$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti) VALUES (9101, 'P9101', 'Prodotto Test', 'semplice', $umId, $catId, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9101, $magId, 100, 0)");

$prodottiInventario = [['prodotto_id' => 9101, 'lotto_id' => null, 'giacenza_teorica' => 100.0]];

echo "=== Setup: crea + convalida un inventario reale (stato -> convalidato) ===\n";
$r1 = qk_convalida_inventario($pdo, null, [
    'azione' => 'convalida',
    'data_inventario' => '2026-07-22',
    'note' => '',
    'magazzino_id' => $magId,
    'categoria_filtro_id' => null,
    'prodotti_inventario' => $prodottiInventario,
    'righe' => ['9101:' => 100.0],
], $UID);
$id = $r1['id'];
$statoDopoSetup = $pdo->query("SELECT stato FROM inventari WHERE id = $id")->fetchColumn();
chk('Setup: inventario creato e convalidato correttamente', 'convalidato', $statoDopoSetup);

echo "\n=== Caso critico: tentativo di 'salva' su un inventario GIA' convalidato, chiamando qk_convalida_inventario() DIRETTAMENTE ===\n";
// Questo è esattamente lo scenario che, prima della Tappa 1, non era protetto
// a livello di model: solo il controller lo bloccava, con una lettura di
// stato fatta PRIMA di aprire la transazione (finestra di race condition).
chkThrows(
    "azione='salva' su inventario convalidato viene bloccata dalla guardia autoritativa",
    function () use ($pdo, $id, $prodottiInventario, $magId, $UID) {
        qk_convalida_inventario($pdo, $id, [
            'azione' => 'salva',
            'data_inventario' => '2026-07-23',
            'note' => 'tentativo non autorizzato',
            'magazzino_id' => $magId,
            'categoria_filtro_id' => null,
            'prodotti_inventario' => $prodottiInventario,
            'righe' => ['9101:' => 999.0],
        ], $UID);
    },
    'già convalidato'
);

// Verifica che il blocco sia REALE: nessun dato deve essere cambiato (rollback).
$dopoTentativo = $pdo->query("SELECT note, stato FROM inventari WHERE id = $id")->fetch();
chk('Dopo il tentativo bloccato: nota NON modificata (rollback reale, non solo eccezione)', 'tentativo non autorizzato' === $dopoTentativo['note'] ? 'NON aggiornata (bug)' : 'invariata', 'invariata');
chk('Dopo il tentativo bloccato: stato resta convalidato', 'convalidato', $dopoTentativo['stato']);

echo "\n=== Caso critico 2: tentativo di 'convalida' (ri-convalida) su un inventario GIA' convalidato ===\n";
chkThrows(
    "azione='convalida' ripetuta su inventario già convalidato viene bloccata",
    function () use ($pdo, $id, $prodottiInventario, $magId, $UID) {
        qk_convalida_inventario($pdo, $id, [
            'azione' => 'convalida',
            'data_inventario' => '2026-07-23',
            'note' => '',
            'magazzino_id' => $magId,
            'categoria_filtro_id' => null,
            'prodotti_inventario' => $prodottiInventario,
            'righe' => ['9101:' => 100.0],
        ], $UID);
    },
    'già convalidato'
);

echo "\n=== Controprova: la stessa identica operazione funziona ANCORA su un inventario in bozza (nessuna regressione) ===\n";
$r2 = qk_convalida_inventario($pdo, null, [
    'azione' => 'salva',
    'data_inventario' => '2026-07-22',
    'note' => 'bozza normale',
    'magazzino_id' => $magId,
    'categoria_filtro_id' => null,
    'prodotti_inventario' => $prodottiInventario,
    'righe' => ['9101:' => 100.0],
], $UID);
$statoBozza = $pdo->query("SELECT stato, note FROM inventari WHERE id = {$r2['id']}")->fetch();
chk("Un 'salva' normale su una bozza appena creata funziona ancora (self-loop bozza->bozza)", 'bozza', $statoBozza['stato']);
chk("La nota è stata scritta correttamente (nessun blocco spurio)", 'bozza normale', $statoBozza['note']);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
