<?php
declare(strict_types=1);

/**
 * tests/manual/suite_traccia_lotti_atomicita_tappa1.php
 *
 * Tappa 1 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Bug corretto da questa Tappa (caso reale: TEST-BROWSER-A, ordine ODC/2026/0005):
 * prima della Tappa 1, l'attivazione di traccia_lotti (0 -> 1) su un prodotto con
 * giacenza esistente avveniva in DUE transazioni indipendenti:
 *   1. qk_update_prodotto() salva prodotti.traccia_lotti = 1 e fa COMMIT.
 *   2. Il controller chiama POI qk_crea_lotti_conversione_iniziale(), che apre
 *      una SECONDA transazione, separata, per convertire la giacenza in lotti.
 * Se il passo 2 falliva per qualunque motivo (es. collisione sul nome del lotto
 * generato, indice UNIQUE idx_lotti_prodotto_codice_unique su
 * lotti(prodotto_id, codice_lotto_interno) — vedi db/init_sqlite.sql riga 1660),
 * il passo 1 NON tornava indietro: risultato, un prodotto "a lotti" (traccia_lotti=1)
 * con giacenza aggregata ma zero lotti veri — che blocca qualunque spedizione di
 * quel prodotto con l'errore "Nessun lotto disponibile in magazzino per questo
 * prodotto", anche su un ordine cliente reale già aperto.
 *
 * Dopo la Tappa 1, qk_crea_lotti_conversione_iniziale() adotta il pattern
 * $ownTransaction e viene chiamata DENTRO la transazione di qk_update_prodotto():
 * o vanno a buon fine entrambe, o nessuna delle due viene applicata.
 *
 * Questa suite chiama DIRETTAMENTE qk_update_prodotto() (la funzione reale),
 * bypassando il controller — stesso approccio di
 * tests/manual/suite_transition_guard_tappa1_inventari.php — perché tutta la
 * logica toccata da questa Tappa vive nel model, non nel controller (il
 * controller ora si limita a passare l'id utente e leggere il valore di ritorno).
 *
 * AGGIORNAMENTO 14/08/2026 (Tappa 2 dello stesso piano, eseguita subito dopo questa):
 * qk_crea_lotti_conversione_iniziale() ora genera nomi progressivi univoci
 * (INIZIALE-<mag>-<data>, poi -2, -3, ... per attivazioni ripetute lo stesso
 * giorno) e usa un lock applicativo per-prodotto
 * (prodotto_traccia_lotti_toggle_lock). Lo Scenario 5 qui sotto, che PRIMA della
 * Tappa 2 dimostrava il rollback usando una collisione di nome lotto (lo stesso
 * meccanismo di TEST-BROWSER-A), è stato aggiornato per usare come innesco il
 * lock già occupato (l'unico modo rimasto, dopo la Tappa 2, di far fallire
 * qk_crea_lotti_conversione_iniziale() in condizioni normali) — vedi
 * tests/manual/suite_lotti_conversione_unicita_lock_tappa2.php per la
 * copertura dedicata della Tappa 2 (univocità del nome, lock, rilascio del lock).
 *
 * FUORI SCOPO per questa suite:
 * - Il blocco della disattivazione (traccia_lotti 1 -> 0) con lotti ancora
 *   pieni è, ad oggi, un controllo che vive nel controller
 *   (ProductsController::edit(), righe ~142-171), non dentro qk_update_prodotto()
 *   — la Tappa 1 non lo tocca e non lo sposta. Verificato separatamente con un
 *   vero test HTTP end-to-end (sessione, CSRF, routing reali) durante
 *   l'esecuzione della Tappa 1, non ripetuto qui a livello di model dove quel
 *   controllo semplicemente non esiste.
 *
 * Esecuzione: php tests/manual/suite_traccia_lotti_atomicita_tappa1.php
 */

define('APP_ROOT_DIR', dirname(__DIR__, 2));
define('APP_ENV', 'development');
define('DB_DIR', sys_get_temp_dir() . '/quack_suite_tappa1_' . getmypid());
define('UPLOAD_DIR', DB_DIR . '/uploads');
define('DB_DRIVER', 'sqlite');
@mkdir(DB_DIR, 0775, true);
@mkdir(UPLOAD_DIR, 0775, true);

require_once APP_ROOT_DIR . '/includes/prodotti.php'; // richiede a cascata db.php (bootstrap schema) e magazzino.php

global $pdo;

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

// ── Setup dati minimi comuni a tutti gli scenari ──────────────────────────
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9201, 't9201', 'T', 'U', 't9201@t.it', 'x', $ruoloId)");
$UID = 9201;

// predefinito = 0 su entrambi: db/init_sqlite.sql (riga 1815) semina già un
// magazzino con predefinito = 1, e idx_magazzini_un_predefinito (riga 976) è
// un indice UNIQUE parziale che ammette un solo predefinito = 1 in tutto il
// database — questi due magazzini di test non hanno bisogno di essere quello
// predefinito.
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9201, 'MAG-9201', 'Test Tappa1 A', 'fisico', 0, 1)");
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9202, 'MAG-9202', 'Test Tappa1 B', 'fisico', 0, 1)");
$MAG_A = 9201;
$MAG_B = 9202;

$umId = (int) $pdo->query("SELECT id FROM unita_misura WHERE codice = 'kg'")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9201, 'Cat Test Tappa1')");
$catId = 9201;

/**
 * Costruisce un array $v realistico, nello stesso formato prodotto da
 * ProductsController::readPostValues()/defaultValues() (vedi app/Controllers/
 * ProductsController.php righe 355-400), per evitare di testare
 * qk_update_prodotto() con un input artificiale/incompleto.
 */
function buildV(int $catId, string $sku, string $nome, string $tracciaLotti): array
{
    return [
        'sku_interno'          => $sku,
        'sku_ecommerce'        => '',
        'nome'                 => $nome,
        'descrizione_breve'    => '',
        'descrizione_confezione'=> '',
        'descrizione_completa' => '',
        'tipo'                 => 'semplice',
        'categoria_id'         => (string) $catId,
        'note_interne'         => '',
        'attivo'               => '1',
        'traccia_lotti'        => $tracciaLotti,
        'unita_misura'         => 'kg',
        'unita_contenuto_id'   => '',
        'quantita_contenuto'   => '',
        'peso_netto_kg'        => '',
        'peso_lordo_kg'        => '',
        'ean'                  => '',
        'is_adr'               => '0',
        'sostanza_chimica_id'  => '',
        'prezzo_vendita_base'  => '',
        'markup_fallback_perc' => '',
        'costo_acquisto_base'  => '',
        'costo_acquisto_medio' => '',
        'unita_misura_acquisto_id' => '',
        'iva_vendita_default'  => '',
        'iva_acquisto_default' => '',
    ];
}

function creaProdottoSenzaLotti(PDO $pdo, int $id, int $umId, int $catId, string $sku): void
{
    $pdo->prepare(
        'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 0, 1)'
    )->execute([$id, $sku, 'Prodotto ' . $sku, 'semplice', $umId, $catId]);
}

function giacenza(PDO $pdo, int $prodottoId, int $magazzinoId, float $qta): void
{
    $pdo->prepare(
        'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile) VALUES (?, ?, ?)'
    )->execute([$prodottoId, $magazzinoId, $qta]);
}

function lottiDelProdotto(PDO $pdo, int $prodottoId): array
{
    $st = $pdo->prepare(
        'SELECT l.id, l.codice_lotto_interno, lg.magazzino_id, lg.quantita_disponibile
           FROM lotti l JOIN lotti_giacenze lg ON lg.lotto_id = l.id
          WHERE l.prodotto_id = ?
          ORDER BY lg.magazzino_id'
    );
    $st->execute([$prodottoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════
// Scenario 1 — Attivazione traccia_lotti su prodotto SENZA giacenza da nessuna
// parte: nessun lotto da creare, il salvataggio deve comunque riuscire senza
// errori e senza creare nulla in lotti/lotti_giacenze.
// ═══════════════════════════════════════════════════════════════════════
echo "=== Scenario 1: attivazione 0->1 senza giacenza (nessun lotto da convertire) ===\n";
$P1 = 9211;
creaProdottoSenzaLotti($pdo, $P1, $umId, $catId, 'T1-NO-STOCK');
$v1 = buildV($catId, 'T1-NO-STOCK', 'Prodotto Senza Stock', '1');
$ret1 = qk_update_prodotto($pdo, $P1, $v1, $UID);
chk('Scenario 1: lotti_conversione_creati = 0', 0, $ret1['lotti_conversione_creati']);
chk('Scenario 1: traccia_lotti salvato a 1', 1, (int) $pdo->query("SELECT traccia_lotti FROM prodotti WHERE id = $P1")->fetchColumn());
chk('Scenario 1: nessun lotto creato', 0, count(lottiDelProdotto($pdo, $P1)));
chk('Scenario 1: transazione chiusa correttamente', false, $pdo->inTransaction());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 2 — Attivazione con giacenza in UN solo magazzino: deve creare
// esattamente 1 lotto di conversione, che assorbe l'intera quantità.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 2: attivazione 0->1 con giacenza in un solo magazzino ===\n";
$P2 = 9212;
creaProdottoSenzaLotti($pdo, $P2, $umId, $catId, 'T1-SINGLE-WH');
giacenza($pdo, $P2, $MAG_A, 42.5);
$v2 = buildV($catId, 'T1-SINGLE-WH', 'Prodotto Single Warehouse', '1');
$ret2 = qk_update_prodotto($pdo, $P2, $v2, $UID);
$lotti2 = lottiDelProdotto($pdo, $P2);
chk('Scenario 2: lotti_conversione_creati = 1', 1, $ret2['lotti_conversione_creati']);
chk('Scenario 2: esattamente 1 lotto creato', 1, count($lotti2));
if (count($lotti2) === 1) {
    chk('Scenario 2: il lotto è nel magazzino giusto', $MAG_A, (int) $lotti2[0]['magazzino_id']);
    chk('Scenario 2: il lotto assorbe l\'intera quantità (42.5)', 42.5, (float) $lotti2[0]['quantita_disponibile']);
    chk('Scenario 2: codice lotto nel formato INIZIALE-<mag>-<data>', 1, (int) str_starts_with($lotti2[0]['codice_lotto_interno'], 'INIZIALE-' . $MAG_A . '-'));
}
chk('Scenario 2: transazione chiusa correttamente', false, $pdo->inTransaction());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 3 — Attivazione con giacenza in DUE magazzini: deve creare 2 lotti
// di conversione indipendenti, uno per magazzino, ciascuno con la quantità
// corretta di quella sede.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 3: attivazione 0->1 con giacenza in due magazzini ===\n";
$P3 = 9213;
creaProdottoSenzaLotti($pdo, $P3, $umId, $catId, 'T1-MULTI-WH');
giacenza($pdo, $P3, $MAG_A, 10.0);
giacenza($pdo, $P3, $MAG_B, 25.0);
$v3 = buildV($catId, 'T1-MULTI-WH', 'Prodotto Multi Warehouse', '1');
$ret3 = qk_update_prodotto($pdo, $P3, $v3, $UID);
$lotti3 = lottiDelProdotto($pdo, $P3);
chk('Scenario 3: lotti_conversione_creati = 2', 2, $ret3['lotti_conversione_creati']);
chk('Scenario 3: esattamente 2 lotti creati', 2, count($lotti3));
$perMagazzino = [];
foreach ($lotti3 as $l) { $perMagazzino[(int) $l['magazzino_id']] = (float) $l['quantita_disponibile']; }
chk('Scenario 3: quantità corretta nel magazzino A', 10.0, $perMagazzino[$MAG_A] ?? null);
chk('Scenario 3: quantità corretta nel magazzino B', 25.0, $perMagazzino[$MAG_B] ?? null);
chk('Scenario 3: transazione chiusa correttamente', false, $pdo->inTransaction());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 4 — Controprova di non regressione: un salvataggio normale che
// NON tocca traccia_lotti (resta 0, invariato) deve continuare a funzionare
// esattamente come prima, senza alcun tentativo di conversione.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 4: salvataggio normale, traccia_lotti invariato (nessuna regressione) ===\n";
$P4 = 9214;
creaProdottoSenzaLotti($pdo, $P4, $umId, $catId, 'T1-NO-CHANGE');
giacenza($pdo, $P4, $MAG_A, 5.0);
$v4 = buildV($catId, 'T1-NO-CHANGE', 'Prodotto Nome Modificato', '0');
$ret4 = qk_update_prodotto($pdo, $P4, $v4, $UID);
chk('Scenario 4: lotti_conversione_creati = 0 (traccia_lotti non è cambiato)', 0, $ret4['lotti_conversione_creati']);
chk('Scenario 4: nome aggiornato correttamente', 'Prodotto Nome Modificato', $pdo->query("SELECT nome FROM prodotti WHERE id = $P4")->fetchColumn());
chk('Scenario 4: nessun lotto creato', 0, count(lottiDelProdotto($pdo, $P4)));
chk('Scenario 4: giacenza aggregata invariata', 5.0, (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = $P4 AND magazzino_id = $MAG_A")->fetchColumn());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 5 — IL CASO CRITICO: la conversione fallisce, questa volta perché
// il lock applicativo per-prodotto (Tappa 2, prodotto_traccia_lotti_toggle_lock)
// risulta già occupato — simula una conversione concorrente già in corso sullo
// stesso prodotto. PRIMA della Tappa 1, un fallimento a questo punto avrebbe
// lasciato prodotti.traccia_lotti = 1 con zero lotti veri (il meccanismo esatto
// dietro il caso reale TEST-BROWSER-A/ODC-2026-0005, lì innescato da una
// collisione di nome invece che dal lock — la Tappa 2 ha chiuso quella
// collisione specifica, vedi suite_lotti_conversione_unicita_lock_tappa2.php,
// ma qk_crea_lotti_conversione_iniziale() può comunque fallire per altri motivi
// reali, come questo — ed è proprio quello che questo scenario dimostra: la
// garanzia di atomicità della Tappa 1 non dipende dalla causa specifica del
// fallimento). Dopo la Tappa 1, l'intero salvataggio deve tornare indietro:
// traccia_lotti resta 0, nessun lotto orfano, nessuna transazione aperta.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 5 (CRITICO): fallimento della conversione -> rollback completo ===\n";
$P5 = 9215;
creaProdottoSenzaLotti($pdo, $P5, $umId, $catId, 'T1-LOCK-BUSY');
giacenza($pdo, $P5, $MAG_A, 77.0);

// Precostruisce il lock già occupato per questo prodotto, come farebbe una
// conversione concorrente in corso in questo stesso momento.
$pdo->prepare('INSERT INTO prodotto_traccia_lotti_toggle_lock (prodotto_id) VALUES (?)')->execute([$P5]);

$v5 = buildV($catId, 'T1-LOCK-BUSY', 'Prodotto Lock Occupato', '1');
chkThrows(
    'Scenario 5: qk_update_prodotto() propaga l\'eccezione del lock occupato invece di salvare a metà',
    function () use ($pdo, $P5, $v5, $UID) {
        qk_update_prodotto($pdo, $P5, $v5, $UID);
    },
    'già in corso'
);

$statoDopoFallimento = $pdo->query("SELECT traccia_lotti, nome FROM prodotti WHERE id = $P5")->fetch(PDO::FETCH_ASSOC);
chk('Scenario 5: traccia_lotti NON è rimasto a 1 (rollback del flag, non solo dell\'eccezione)', 0, (int) $statoDopoFallimento['traccia_lotti']);
chk('Scenario 5: anche il nome è tornato al valore precedente (rollback COMPLETO, non parziale)', 'Prodotto ' . 'T1-LOCK-BUSY', $statoDopoFallimento['nome']);
// Nessun lotto deve essere stato creato: il fallimento avviene PRIMA di
// qualunque INSERT su lotti (il lock è il primo passo della funzione).
$stCount = $pdo->prepare('SELECT COUNT(*) FROM lotti WHERE prodotto_id = ?');
$stCount->execute([$P5]);
chk('Scenario 5: nessun lotto orfano creato', 0, (int) $stCount->fetchColumn());
chk('Scenario 5: nessuna transazione rimasta aperta dopo il rollback', false, $pdo->inTransaction());
// Il rollback deve aver ripristinato anche la riga di lock precostruita (il
// nostro INSERT manuale di setup, fuori dalla transazione di
// qk_update_prodotto, non viene toccato dal suo rollback) — la verifichiamo
// ancora presente, poi la rimuoviamo noi per liberare il prodotto.
$stLock = $pdo->prepare('SELECT COUNT(*) FROM prodotto_traccia_lotti_toggle_lock WHERE prodotto_id = ?');
$stLock->execute([$P5]);
chk('Scenario 5: il lock di setup (precostruito da questo test, non da qk_update_prodotto) è ancora presente', 1, (int) $stLock->fetchColumn());

// Controprova immediata: lo STESSO prodotto, riprovando l'attivazione dopo aver
// liberato il lock, deve ora riuscire normalmente (dimostra che il rollback ha
// davvero ripulito tutto, non lasciato residui che bloccherebbero un secondo
// tentativo).
$pdo->prepare('DELETE FROM prodotto_traccia_lotti_toggle_lock WHERE prodotto_id = ?')->execute([$P5]);
$ret5b = qk_update_prodotto($pdo, $P5, $v5, $UID);
chk('Scenario 5 (controprova): dopo aver liberato il lock, un secondo tentativo riesce', 1, $ret5b['lotti_conversione_creati']);
chk('Scenario 5 (controprova): traccia_lotti ora è 1', 1, (int) $pdo->query("SELECT traccia_lotti FROM prodotti WHERE id = $P5")->fetchColumn());

// ═══════════════════════════════════════════════════════════════════════
// Scenario 6 — qk_crea_lotti_conversione_iniziale() chiamata in isolamento
// (come faceva il vecchio codice del controller) deve continuare a funzionare
// esattamente come prima: apre la propria transazione quando non ce n'è già
// una in corso ($ownTransaction = true). Verifica di non-regressione sul
// comportamento standalone della funzione.
// ═══════════════════════════════════════════════════════════════════════
echo "\n=== Scenario 6: qk_crea_lotti_conversione_iniziale() chiamata da sola (non regressione) ===\n";
$P6 = 9216;
$pdo->prepare(
    'INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti, attivo)
     VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
)->execute([$P6, 'T1-STANDALONE', 'Prodotto Standalone', 'semplice', $umId, $catId]);
giacenza($pdo, $P6, $MAG_A, 8.0);
chk('Scenario 6: nessuna transazione in corso prima della chiamata', false, $pdo->inTransaction());
$creati6 = qk_crea_lotti_conversione_iniziale($pdo, $P6, $UID);
chk('Scenario 6: 1 lotto creato', 1, $creati6);
chk('Scenario 6: transazione propria aperta e richiusa correttamente', false, $pdo->inTransaction());

// ═══════════════════════════════════════════════════════════════════════
echo "\n=== RISULTATO ===\nOK: $pass  FALLITI: $fail\n";
if ($fail > 0) {
    exit(1);
}
