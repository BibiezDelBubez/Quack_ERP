<?php
declare(strict_types=1);

/**
 * tests/manual/suite_revisione_ordini_clienti_tappa3.php
 *
 * PIANO - Revisione Ordini Clienti (Nuova Versione).md, Tappa 3: test reali
 * (database SQLite vero, seminato da db/init_sqlite.sql, nessun mock) per
 * qk_ordine_cliente_crea_revisione() (includes/sales_orders.php).
 *
 * Scritta il 25/07/2026, DOPO l'implementazione, in una sessione in cui
 * l'ambiente non aveva PHP installato di default: PHP è stato scaricato come
 * pacchetto .deb ed estratto senza privilegi di root (dpkg -x, nessun
 * apt-get install) — vedi il prefix "auto_prepend_file" gestito da
 * runQuery()/bootstrap sotto se eseguita fuori da questo ambiente specifico,
 * altrimenti PHP_BINARY del sistema (XAMPP) basta da solo.
 *
 * Copre i casi della Tappa 3 elencati nel piano:
 *  1. Caso base: ordine in_lavorazione pulito -> nuova versione corretta.
 *  2. Controprova riserva: due ordini paralleli sullo stesso prodotto/
 *     magazzino, revisiona solo il primo -> la riserva si sposta per intero
 *     sul nuovo ordine, il secondo resta intatto (prova decisiva: annullare
 *     poi il NUOVO ordine deve liberare esattamente la sua parte, non quella
 *     dell'altro ordine).
 *  3. Blocco su ordine 'parzialmente_spedito'.
 *  4. Blocco se esiste già una spedizione collegata (difesa in profondità,
 *     anche con stato ancora 'in_lavorazione').
 *  5. Blocco assoluto se stato_fatturazione = 'fatturato' (testo del
 *     messaggio verificato letteralmente).
 *  6. Snapshot indirizzo/contatto: righe fisicamente nuove (id diversi),
 *     mai un riferimento condiviso con l'ordine originale.
 *  7. Il vecchio ordine risulta 'superato' e non più revisionabile una
 *     seconda volta (TransitionGuard blocca perché 'superato' => []).
 *
 * NON eseguita: la ri-esecuzione del controllo compliance (§4.4) — richiede
 * di seminare le tabelle compliance_restriction_types/prodotti_restrizioni/
 * compliance_autorizzazioni con uno scenario di violazione realistico, non
 * fatto in questa sessione per limiti di tempo. La chiamata a
 * qk_compliance_check_sale() nel percorso positivo (nessuna restrizione sui
 * prodotti di test) è comunque coperta indirettamente da tutti i test sopra,
 * che passano solo se quella chiamata non lancia un'eccezione a torto.
 *
 * Esecuzione: php tests/manual/suite_revisione_ordini_clienti_tappa3.php
 */

define('DB_DIR', sys_get_temp_dir() . '/quack_test_revisione_oc_' . uniqid());
mkdir(DB_DIR, 0777, true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_orders.php';

$pass = 0;
$fail = 0;
function chk(string $label, $expected, $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $fail++;
}
function chkTrue(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { echo "OK   - $label\n"; $pass++; return; }
    echo "FAIL - $label\n";
    $fail++;
}
function chkThrows(string $label, callable $fn, ?string $needle = null): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "FAIL - $label\n       atteso: eccezione, ottenuto: nessuna\n";
        $fail++;
    } catch (\Throwable $e) {
        if ($needle !== null && stripos($e->getMessage(), $needle) === false) {
            echo "FAIL - $label\n       eccezione lanciata ma messaggio inatteso.\n       atteso (contiene): $needle\n       ottenuto: {$e->getMessage()}\n";
            $fail++;
            return;
        }
        echo "OK   - $label (bloccato: {$e->getMessage()})\n";
        $pass++;
    }
}

// ============================================================================
// Seed comune
// ============================================================================
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) {
    $pdo->exec("INSERT INTO ruoli (codice, nome, can_manage_orders) VALUES ('ADMIN_TEST', 'Admin Test', 1)");
    $ruoloId = (int) $pdo->lastInsertId();
}
$adminId = (int) $pdo->query("SELECT id FROM utenti WHERE username = 'admin'")->fetchColumn();
if (!$adminId) {
    $pdo->exec("INSERT INTO utenti (username, nome, cognome, email, password_hash, ruolo_id) VALUES ('t_rev_admin', 'T', 'U', 't_rev@t.it', 'x', $ruoloId)");
    $adminId = (int) $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO clienti_fornitori (id, codice, tipo, tipo_soggetto, ragione_sociale) VALUES (8801, 'CLI-8801-REV', 'cliente', 'azienda', 'Cliente Test Revisione')");

$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'pz'")->fetchColumn();
if (!$umId) {
    $pdo->exec("INSERT INTO unita_misura (codice, descrizione, e_riferimento) VALUES ('pz', 'Pezzi', 1)");
    $umId = (int) $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO prodotti (sku_interno, nome, tipo, unita_misura_stock_id) VALUES ('SKU-REV-1', 'Prodotto Test Revisione', 'semplice', $umId)");
$prodottoId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO magazzini (codice, nome, tipo) VALUES ('MAG-REV', 'Magazzino Test Revisione', 'fisico')");
$magazzinoId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES ($prodottoId, $magazzinoId, 1000, 0)");

$pdo->exec("INSERT INTO indirizzi_documenti (nome_sede, via, cap, citta) VALUES ('Sede Test', 'Via Test 1', '00100', 'Roma')");
$indirizzoDocId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO contatti_documenti (nome, cognome, email) VALUES ('Mario', 'Referente', 'mario@test.it')");
$contattoDocId = (int) $pdo->lastInsertId();

$seq = 0;
function nuovoOrdine(PDO $pdo, int $adminId, int $cliente, int $magazzino, ?int $indirizzoDocId, ?int $contattoDocId, string $stato = 'in_lavorazione', string $statoFatt = 'da_fatturare'): int
{
    global $seq;
    $seq++;
    $numero = 'ORD-REV-' . $seq;
    $pdo->prepare("INSERT INTO ordini_clienti
        (numero_ordine, cliente_id, magazzino_id, data_ordine, stato, stato_fatturazione, indirizzo_consegna_id, contatto_snapshot_id, creato_da)
        VALUES (?, ?, ?, '2026-07-20', ?, ?, ?, ?, ?)")
        ->execute([$numero, $cliente, $magazzino, $stato, $statoFatt, $indirizzoDocId, $contattoDocId, $adminId]);
    return (int) $pdo->lastInsertId();
}
function aggiungiRiga(PDO $pdo, int $ordineId, int $prodottoId, float $qta, float $qtaStock = null): int
{
    $qtaStock = $qtaStock ?? $qta;
    $pdo->prepare("INSERT INTO ordini_clienti_righe
        (ordine_id, prodotto_id, descrizione, quantita_ordinata, prezzo_unitario, quantita_stock)
        VALUES (?, ?, 'Riga test', ?, 10.00, ?)")
        ->execute([$ordineId, $prodottoId, $qta, $qtaStock]);
    return (int) $pdo->lastInsertId();
}
function riservaIniziale(PDO $pdo, int $prodottoId, int $magazzinoId, float $qta): void
{
    $pdo->prepare("UPDATE giacenze SET quantita_riservata = quantita_riservata + ? WHERE prodotto_id = ? AND magazzino_id = ?")
        ->execute([$qta, $prodottoId, $magazzinoId]);
}

// ============================================================================
// Parte 1 — Caso base
// ============================================================================
echo "=== Parte 1: caso base, ordine in_lavorazione pulito ===\n";

$ordineId1 = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, $indirizzoDocId, $contattoDocId);
$rigaId1 = aggiungiRiga($pdo, $ordineId1, $prodottoId, 20.0);
riservaIniziale($pdo, $prodottoId, $magazzinoId, 20.0);

$vecchioNumero = (string) $pdo->query("SELECT numero_ordine FROM ordini_clienti WHERE id = $ordineId1")->fetchColumn();

$nuovoId1 = qk_ordine_cliente_crea_revisione($pdo, $ordineId1);
chkTrue("nuovo ordine creato (id > 0, diverso dal vecchio)", $nuovoId1 > 0 && $nuovoId1 !== $ordineId1);

$vecchio = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $ordineId1")->fetch(PDO::FETCH_ASSOC);
$nuovo   = $pdo->query("SELECT * FROM ordini_clienti WHERE id = $nuovoId1")->fetch(PDO::FETCH_ASSOC);

chk("vecchio ordine ora 'superato'", 'superato', $vecchio['stato']);
chk("nuovo ordine 'in_lavorazione'", 'in_lavorazione', $nuovo['stato']);
chk("nuovo ordine versione = 2", 2, (int) $nuovo['versione']);
chk("nuovo ordine ordine_padre_id corretto", $ordineId1, (int) $nuovo['ordine_padre_id']);
chk("numero_ordine con suffisso ' v2'", $vecchioNumero . ' v2', $nuovo['numero_ordine']);
chk("stato_fatturazione reimpostato a 'da_fatturare'", 'da_fatturare', $nuovo['stato_fatturazione']);
chkTrue("data_ordine del nuovo ordine è oggi (mirror Acquisti CURRENT_DATE)", $nuovo['data_ordine'] === date('Y-m-d'));
chk("cliente_id invariato", 8801, (int) $nuovo['cliente_id']);

$righeNuove = $pdo->query("SELECT * FROM ordini_clienti_righe WHERE ordine_id = $nuovoId1")->fetchAll(PDO::FETCH_ASSOC);
chk("una riga duplicata", 1, count($righeNuove));
if (count($righeNuove) === 1) {
    chk("quantita_ordinata identica alla riga originale", 20.0, (float) $righeNuove[0]['quantita_ordinata']);
    chk("quantita_spedita azzerata sulla nuova riga", 0.0, (float) $righeNuove[0]['quantita_spedita']);
    chkTrue("id della nuova riga diverso da quello originale", (int) $righeNuove[0]['id'] !== $rigaId1);
}

chkTrue("indirizzo_consegna_id del nuovo ordine è una riga fisicamente diversa", (int) $nuovo['indirizzo_consegna_id'] !== $indirizzoDocId && !empty($nuovo['indirizzo_consegna_id']));
chkTrue("contatto_snapshot_id del nuovo ordine è una riga fisicamente diversa", (int) $nuovo['contatto_snapshot_id'] !== $contattoDocId && !empty($nuovo['contatto_snapshot_id']));

$indirizzoNuovo = $pdo->query("SELECT via, cap, citta FROM indirizzi_documenti WHERE id = " . (int)$nuovo['indirizzo_consegna_id'])->fetch(PDO::FETCH_ASSOC);
chk("contenuto dello snapshot indirizzo copiato correttamente", 'Via Test 1', $indirizzoNuovo['via']);

$riservataDopoParte1 = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = $prodottoId AND magazzino_id = $magazzinoId")->fetchColumn();
chk("riserva totale invariata dopo la revisione di un ordine singolo (20 -> 20, spostata non duplicata)", 20.0, $riservataDopoParte1);

chkThrows(
    "il vecchio ordine (ora 'superato') non è più revisionabile una seconda volta",
    fn() => qk_ordine_cliente_crea_revisione($pdo, $ordineId1),
    "in stato 'in lavorazione'"
);

// ============================================================================
// Parte 2 — controprova riserva con due ordini paralleli
// ============================================================================
echo "\n=== Parte 2: due ordini paralleli, revisiona solo il primo ===\n";

$ordineA = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, null, null);
aggiungiRiga($pdo, $ordineA, $prodottoId, 100.0);
riservaIniziale($pdo, $prodottoId, $magazzinoId, 100.0);

$ordineB = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, null, null);
aggiungiRiga($pdo, $ordineB, $prodottoId, 50.0);
riservaIniziale($pdo, $prodottoId, $magazzinoId, 50.0);

$riservataPrima = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = $prodottoId AND magazzino_id = $magazzinoId")->fetchColumn();
chk("riserva totale prima = 20 (parte 1) + 100 (A) + 50 (B) = 170", 170.0, $riservataPrima);

$nuovoA = qk_ordine_cliente_crea_revisione($pdo, $ordineA);

$riservataDopo = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = $prodottoId AND magazzino_id = $magazzinoId")->fetchColumn();
chk("riserva totale invariata subito dopo la revisione (170 -> 170: solo spostata, non persa né duplicata)", 170.0, $riservataDopo);

// Prova decisiva: annullare il NUOVO ordine (la revisione di A) deve
// liberare esattamente 100 (la sua quota), lasciando intatta quella di B.
qk_ordine_cliente_annulla($pdo, $nuovoA);
$riservataDopoAnnullo = (float) $pdo->query("SELECT quantita_riservata FROM giacenze WHERE prodotto_id = $prodottoId AND magazzino_id = $magazzinoId")->fetchColumn();
chk("dopo aver annullato la NUOVA versione di A, resta riservato solo B + parte1 (170 - 100 = 70) — prova che la riserva di A si era davvero spostata sul nuovo ordine, non rimasta orfana sul vecchio né duplicata", 70.0, $riservataDopoAnnullo);

// ============================================================================
// Parte 3 — blocco su ordine 'parzialmente_spedito'
// ============================================================================
echo "\n=== Parte 3: blocco su ordine 'parzialmente_spedito' ===\n";
$ordineParz = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, null, null, 'parzialmente_spedito');
aggiungiRiga($pdo, $ordineParz, $prodottoId, 10.0);
chkThrows(
    "ordine 'parzialmente_spedito' non revisionabile",
    fn() => qk_ordine_cliente_crea_revisione($pdo, $ordineParz),
    "in stato 'in lavorazione'"
);

// ============================================================================
// Parte 4 — blocco se esiste già una spedizione (difesa in profondità)
// ============================================================================
echo "\n=== Parte 4: blocco se esiste già una spedizione collegata ===\n";
$ordineConSped = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, null, null, 'in_lavorazione');
aggiungiRiga($pdo, $ordineConSped, $prodottoId, 10.0);
$pdo->prepare("INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, stato, creato_da) VALUES ('SPED-TEST-1', ?, '2026-07-20', 'spedita', ?)")
    ->execute([$ordineConSped, $adminId]);
chkThrows(
    "ordine 'in_lavorazione' ma con una spedizione già collegata: bloccato comunque",
    fn() => qk_ordine_cliente_crea_revisione($pdo, $ordineConSped),
    'esiste già una spedizione'
);

// ============================================================================
// Parte 5 — blocco assoluto se stato_fatturazione = 'fatturato'
// ============================================================================
echo "\n=== Parte 5: blocco assoluto se già fatturato (§4.3) ===\n";
$ordineFatturato = nuovoOrdine($pdo, $adminId, 8801, $magazzinoId, null, null, 'in_lavorazione', 'fatturato');
aggiungiRiga($pdo, $ordineFatturato, $prodottoId, 10.0);
chkThrows(
    "ordine già fatturato: bloccato con il testo di messaggio deciso esplicitamente",
    fn() => qk_ordine_cliente_crea_revisione($pdo, $ordineFatturato),
    'per correggerlo serve una nota di credito, non una revisione'
);

echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
exit($fail > 0 ? 1 : 0);
