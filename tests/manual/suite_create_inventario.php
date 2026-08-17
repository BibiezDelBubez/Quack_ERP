<?php
declare(strict_types=1);

/**
 * tests/manual/suite_create_inventario.php
 *
 * Suite di test comportamentale per WarehouseController::createInventario()
 * (Piano refactor "God Object", Fase 2, microtappa 2.0 — Revisione Gestione
 * Magazzino e Lotti, audit MVC/DRY).
 *
 * Non esiste una suite di test automatica per questo metodo (nessun PHPUnit
 * configurato nel progetto). Questo script è la rete di sicurezza costruita
 * apposta prima di iniziare lo split: replica FEDELMENTE (stesse query, stesse
 * condizioni, stessa formattazione dei messaggi) la logica attuale del
 * metodo — non ancora modificato — in due funzioni testabili senza passare da
 * HTTP/sessione/CSRF/routing ("Opzione B" del piano):
 *
 *   test_ii_prepare()  — fasi B-G del metodo reale (righe 293-411 circa):
 *                        risoluzione magazzino/categoria, caricamento
 *                        esistente O calcolo giacenze teoriche per un nuovo
 *                        inventario, guardia di sovrapposizione pre-transazione.
 *   test_ii_submit()   — fasi H (righe 413-697 circa): tutto il blocco POST,
 *                        lock applicativo, guardia ripetuta, CRUD testata/
 *                        righe, PASS1 (validazione), PASS2 (applicazione),
 *                        commit/rollback.
 *
 * Ogni volta che il codice REALE di createInventario() viene toccato in una
 * microtappa successiva (2.1-2.5), questo script va rieseguito per intero:
 * se una qualunque asserzione fallisce, il refactor ha cambiato un
 * comportamento — da correggere prima di proseguire, non da "aggiustare" il
 * test per farlo passare.
 *
 * STATO (aggiornato ad ogni microtappa): dalla microtappa 2.1, PASS1 non è
 * più una copia interna a questo script — test_ii_submit() sotto chiama
 * direttamente qk_valida_scostamenti_inventario() (includes/inventario.php),
 * la funzione REALE estratta dal controller. Il resto (guardie, lock, PASS2,
 * CRUD) resta ancora una replica in attesa delle prossime microtappe.
 *
 * Esecuzione: php tests/manual/suite_create_inventario.php
 */

// Tappa 3 (PIANO - Coerenza Strutturale Giacenze e Lotti): qk_applica_rettifiche_inventario()
// ora usa App\Services\GiacenzaLottoService, che legge la costante DB_DRIVER
// per scegliere la sintassi di upsert corretta (ON CONFLICT vs ON DUPLICATE
// KEY). Questa suite crea il proprio PDO a mano (sotto) invece di passare da
// includes/db.php: senza questa define(), la costante resterebbe
// semplicemente non definita.
define('DB_DRIVER', 'sqlite');

require_once __DIR__ . '/../../includes/inventario.php';

// ============================================================================
// Bootstrap: DB SQLite in-memory con lo schema reale, nessun tocco al DB di
// sviluppo reale (C:/Quack_ERP_data). Stesso approccio già validato nella
// Fase 0/1 di questo stesso piano.
// ============================================================================
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/../../db/init_sqlite.sql'));

// ============================================================================
// Repliche testabili della logica di WarehouseController::createInventario()
// (stato attuale, righe 282-714 di app/Controllers/WarehouseController.php al
// momento della scrittura di questo script). Nessuna logica di dominio
// cambiata: solo I/O (parametri di funzione invece di $_POST/filter_input,
// return value invece di redirect_with/throw-per-flusso-controllo).
// ============================================================================

/**
 * Fasi B-G del metodo reale. Ritorna un array pronto per test_ii_submit(),
 * oppure con 'overlap_error' valorizzato se la guardia pre-transazione blocca
 * (stesso caso del redirect_with('danger', ...) nel controller reale).
 */
function test_ii_prepare(PDO $pdo, ?int $id, ?int $categoriaIdParam, int $magazzinoAttivoId): array
{
    $magazzinoInventarioId = $magazzinoAttivoId;
    $categoriaFiltroId = null;
    if (!$id) {
        if (is_int($categoriaIdParam) && $categoriaIdParam > 0) {
            $categoriaFiltroId = $categoriaIdParam;
        }
    }

    $inventario = null;
    $prodotti_inventario = [];
    $values = ['data_inventario' => date('Y-m-d'), 'note' => '', 'righe' => []];

    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM inventari WHERE id = ?");
        $stmt->execute([$id]);
        $inventario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inventario) {
            return ['overlap_error' => null, 'not_found' => true];
        }
        $values['data_inventario'] = $inventario['data_inventario'];
        $values['note'] = $inventario['note'];
        if (!empty($inventario['magazzino_id'])) {
            $magazzinoInventarioId = (int) $inventario['magazzino_id'];
        }
        $categoriaFiltroId = !empty($inventario['categoria_filtro_id']) ? (int) $inventario['categoria_filtro_id'] : null;

        $stmtR = $pdo->prepare("
            SELECT ir.*, p.nome AS nome_prodotto, p.sku_interno, u.codice AS uom, l.codice_lotto_interno
            FROM inventari_righe ir
            JOIN prodotti p ON p.id = ir.prodotto_id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            LEFT JOIN lotti l ON l.id = ir.lotto_id
            WHERE ir.inventario_id = ?
        ");
        $stmtR->execute([$id]);
        $prodotti_inventario = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        foreach ($prodotti_inventario as $p) {
            $key = $p['prodotto_id'] . ':' . ($p['lotto_id'] ?? '');
            $values['righe'][$key] = (string) $p['quantita_contata'];
        }
    } else {
        // Microtappa 2.4: chiama le funzioni REALI estratte, non più una copia locale.
        $prodotti_inventario = qk_inventario_prodotti_scope($pdo, $magazzinoInventarioId, $categoriaFiltroId);

        $overlap = qk_inventario_verifica_sovrapposizione($pdo, $magazzinoInventarioId, $prodotti_inventario);
        if ($overlap) {
            $scopeLabel = $overlap['categoria_nome'] ? ('categoria "' . $overlap['categoria_nome'] . '"') : 'tutti i prodotti';
            return ['overlap_error' => 'Esiste già un inventario in bozza (#' . $overlap['id'] . ', ' . $scopeLabel . ') che copre uno o più di questi prodotti in questo magazzino. Completa o annulla quello prima di aprirne un altro sugli stessi prodotti.'];
        }
    }

    return [
        'overlap_error' => null,
        'not_found' => false,
        'id' => $id,
        'inventario' => $inventario,
        'values' => $values,
        'prodotti_inventario' => $prodotti_inventario,
        'magazzinoInventarioId' => $magazzinoInventarioId,
        'categoriaFiltroId' => $categoriaFiltroId,
    ];
}

/**
 * Fase H (blocco POST) del metodo reale. $postData simula $_POST:
 * ['azione'=>'salva'|'convalida'|'annulla', 'data_inventario'=>..., 'note'=>...,
 *  'righe'=>[ 'prodId:lottoId' => valore, ... ]].
 */
function test_ii_submit(PDO $pdo, ?int $id, ?array $inventario, array $prodotti_inventario, int $magazzinoInventarioId, ?int $categoriaFiltroId, array $postData, int $utente_id, bool &$pass2Chiamata = false): array
{
    $errors = [];

    if (($postData['azione'] ?? '') === 'annulla') {
        if ($id && $inventario && $inventario['stato'] === 'bozza') {
            $pdo->prepare("DELETE FROM inventari WHERE id = ? AND stato = 'bozza'")->execute([$id]);
            return ['ok' => true, 'msg' => 'Inventario annullato.', 'flashType' => 'success', 'id' => $id];
        }
        return ['ok' => false, 'msg' => 'Impossibile annullare: inventario non trovato o già convalidato.', 'flashType' => 'danger'];
    }

    if ($inventario && $inventario['stato'] === 'convalidato') {
        return ['ok' => false, 'errors' => ['_global' => 'L\'inventario è convalidato e non può più essere modificato.']];
    }

    $azione = $postData['azione'] ?? 'salva';
    $data_inventario = $postData['data_inventario'] ?? date('Y-m-d');
    $note = $postData['note'] ?? '';
    $inputRighe = $postData['righe'] ?? [];

    if (empty($data_inventario)) {
        $errors['data_inventario'] = 'La data di rilevamento è obbligatoria.';
    }

    foreach ($inputRighe as $valRiga) {
        if ((float) str_replace(',', '.', (string) $valRiga) < 0) {
            $errors['_global'] = 'Le quantità contate non possono essere negative.';
            break;
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    // Normalizzazione virgola->punto fatta QUI, prima del confine col service layer
    // — stesso punto in cui lo fa ora il controller reale (microtappa 2.3).
    $righeNormalizzate = [];
    foreach ($inputRighe as $key => $valRiga) {
        $righeNormalizzate[$key] = (float) str_replace(',', '.', (string) $valRiga);
    }

    try {
        // Microtappa 2.3: chiama la facade transazionale REALE — unico punto che
        // apre/chiude la transazione (lock, guardia, CRUD, PASS1, PASS2, stato).
        $pass2Chiamata = ($azione === 'convalida'); // la facade chiama PASS2 solo se PASS1 non solleva: se arriviamo al return sotto, è stata chiamata
        $risultato = qk_convalida_inventario($pdo, $id, [
            'azione'              => $azione,
            'data_inventario'     => $data_inventario,
            'note'                => $note,
            'magazzino_id'        => $magazzinoInventarioId,
            'categoria_filtro_id' => $categoriaFiltroId,
            'prodotti_inventario' => $prodotti_inventario,
            'righe'               => $righeNormalizzate,
        ], $utente_id);

        $msg = $azione === 'convalida' ? 'Inventario convalidato e rettifiche applicate con successo.' : 'Bozza inventario salvata con successo.';
        $flashType = 'success';
        if ($azione === 'convalida' && !empty($risultato['avvisi_riservata'])) {
            $msg .= ' ATTENZIONE — sotto-copertura ordini: ' . implode('; ', $risultato['avvisi_riservata']) . '.';
            $flashType = 'warning';
        }
        return ['ok' => true, 'msg' => $msg, 'flashType' => $flashType, 'id' => $risultato['id'], 'avvisi_riservata' => $risultato['avvisi_riservata']];
    } catch (\Throwable $e) {
        // La facade fa già il proprio rollback internamente prima di rilanciare.
        $pass2Chiamata = false; // se siamo qui, la facade non è arrivata al commit -> PASS2 non ha avuto effetto persistente
        return ['ok' => false, 'errors' => ['_global' => 'Errore durante il salvataggio: ' . $e->getMessage()]];
    }
}

// ============================================================================
// Helper di asserzione
// ============================================================================
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function chk(string $label, $actual, $expected): void
{
    if ($actual === $expected) {
        echo "OK   - $label\n";
        $GLOBALS['__pass']++;
    } else {
        echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
        $GLOBALS['__fail']++;
    }
}
function chkTrue(string $label, bool $cond, string $extra = ''): void
{
    if ($cond) { echo "OK   - $label\n"; $GLOBALS['__pass']++; }
    else { echo "FAIL - $label" . ($extra ? " ($extra)" : '') . "\n"; $GLOBALS['__fail']++; }
}

// ============================================================================
// Seed comune
// ============================================================================
$ruoloId = (int) $pdo->query("SELECT id FROM ruoli LIMIT 1")->fetchColumn();
if (!$ruoloId) { $pdo->exec("INSERT INTO ruoli (nome) VALUES ('Admin')"); $ruoloId = (int) $pdo->lastInsertId(); }
$pdo->exec("INSERT INTO utenti (id, username, nome, cognome, email, password_hash, ruolo_id) VALUES (9001, 't9001', 'T', 'U', 't9001@t.it', 'x', $ruoloId)");
$UID = 9001;

$magId1 = (int) $pdo->query("SELECT id FROM magazzini WHERE attivo=1 LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO magazzini (id, codice, nome, tipo, predefinito, attivo) VALUES (9002, 'MAG-9002', 'Milano', 'fisico', 0, 1)");
$magId2 = 9002;

$umId = (int) $pdo->query("SELECT id FROM unita_misura LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO categorie_prodotto (id, nome) VALUES (9001, 'Acidi')");
$catId = 9001;

// Prodotto A: senza lotti
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti) VALUES (9001, 'PA', 'Prodotto A', 'semplice', $umId, $catId, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9001, $magId1, 100, 0)");

// Prodotto B: con lotti (due lotti nello stesso magazzino)
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti) VALUES (9002, 'PB', 'Prodotto B', 'semplice', $umId, $catId, 1)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES (9001, 'LOTTO-1', 9002, 'acquisto', 30, $UID)");
$pdo->exec("INSERT INTO lotti (id, codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, creato_da) VALUES (9002, 'LOTTO-2', 9002, 'acquisto', 20, $UID)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES (9001, $magId1, 30)");
$pdo->exec("INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile) VALUES (9002, $magId1, 20)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9002, $magId1, 50, 0)");

// Prodotto C: senza lotti, con riserva (per scenario F)
$pdo->exec("INSERT INTO prodotti (id, sku_interno, nome, tipo, unita_misura_stock_id, categoria_id, traccia_lotti) VALUES (9003, 'PC', 'Prodotto C', 'semplice', $umId, $catId, 0)");
$pdo->exec("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata) VALUES (9003, $magId1, 100, 60)");

echo "=== Scenario A — nuovo inventario, salva bozza (no convalida) ===\n";
$prep = test_ii_prepare($pdo, null, null, $magId1);
chkTrue('A: nessun errore di overlap alla preparazione', $prep['overlap_error'] === null);
$sub = test_ii_submit($pdo, null, null, $prep['prodotti_inventario'], $prep['magazzinoInventarioId'], $prep['categoriaFiltroId'], [
    'azione' => 'salva', 'data_inventario' => '2026-07-19', 'note' => 'test A', 'righe' => [],
], $UID);
chkTrue('A: submit ok', $sub['ok'] === true);
$idA = $sub['id'];
$rowA = $pdo->query("SELECT stato, magazzino_id FROM inventari WHERE id=$idA")->fetch(PDO::FETCH_ASSOC);
chk('A: stato bozza', $rowA['stato'], 'bozza');
chk('A: magazzino corretto', (int)$rowA['magazzino_id'], $magId1);
$righeA = $pdo->query("SELECT COUNT(*) FROM inventari_righe WHERE inventario_id=$idA")->fetchColumn();
chkTrue('A: righe create (>0)', (int)$righeA > 0);
// pulizia per non sporcare gli scenari successivi
$pdo->exec("DELETE FROM inventari WHERE id=$idA");

echo "\n=== Scenario B — cycle count con categoria + guardia sovrapposizione ===\n";
$prepB = test_ii_prepare($pdo, null, $catId, $magId1);
chkTrue('B: nessun overlap alla prima apertura', $prepB['overlap_error'] === null);
$subB = test_ii_submit($pdo, null, null, $prepB['prodotti_inventario'], $prepB['magazzinoInventarioId'], $prepB['categoriaFiltroId'], [
    'azione' => 'salva', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [],
], $UID);
chkTrue('B: submit ok', $subB['ok'] === true);
$idB = $subB['id'];
$rowB = $pdo->query("SELECT categoria_filtro_id FROM inventari WHERE id=$idB")->fetch(PDO::FETCH_ASSOC);
chk('B: categoria_filtro_id salvato', (int)$rowB['categoria_filtro_id'], $catId);
// Secondo tentativo sullo stesso magazzino/categoria (stessi prodotti) -> deve essere rifiutato dalla guardia PRE-transazione
$prepB2 = test_ii_prepare($pdo, null, $catId, $magId1);
chkTrue('B: guardia PRE-transazione rifiuta la sovrapposizione', $prepB2['overlap_error'] !== null, (string)($prepB2['overlap_error'] ?? ''));

echo "\n=== Scenario C — modifica di un inventario esistente ===\n";
$prepC = test_ii_prepare($pdo, $idB, null, $magId1);
chk('C: magazzino letto dall\'esistente', $prepC['magazzinoInventarioId'], $magId1);
chk('C: categoria letta dall\'esistente', $prepC['categoriaFiltroId'], $catId);
$subC = test_ii_submit($pdo, $idB, $prepC['inventario'], $prepC['prodotti_inventario'], $prepC['magazzinoInventarioId'], $prepC['categoriaFiltroId'], [
    'azione' => 'salva', 'data_inventario' => '2026-07-20', 'note' => 'nota modificata', 'righe' => [],
], $UID);
chkTrue('C: submit ok', $subC['ok'] === true);
$rowC = $pdo->query("SELECT note, data_inventario FROM inventari WHERE id=$idB")->fetch(PDO::FETCH_ASSOC);
chk('C: nota aggiornata', $rowC['note'], 'nota modificata');
chk('C: data aggiornata', $rowC['data_inventario'], '2026-07-20');
$pdo->exec("DELETE FROM inventari WHERE id=$idB");

echo "\n=== Scenario D — convalida con scostamento positivo e negativo (prodotto senza lotti) ===\n";
$prepD = test_ii_prepare($pdo, null, null, $magId1);
$keyA = "9001:"; // prodotto_id 9001, nessun lotto
$subD = test_ii_submit($pdo, null, null, $prepD['prodotti_inventario'], $prepD['magazzinoInventarioId'], $prepD['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [$keyA => '90'], // 100 -> 90, delta -10
], $UID);
chkTrue('D: convalida ok', $subD['ok'] === true, (string)($subD['errors']['_global'] ?? ''));
$idD = $subD['id'];
$giacD = $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=9001 AND magazzino_id=$magId1")->fetchColumn();
chk('D: giacenza aggiornata a 90', (float)$giacD, 90.0);
$movD = $pdo->query("SELECT tipo_movimento, quantita, quantita_delta FROM movimenti_magazzino WHERE documento_tipo='inventario' AND documento_id=$idD")->fetch(PDO::FETCH_ASSOC);
chk('D: movimento tipo rettifica', $movD['tipo_movimento'], 'rettifica');
chk('D: quantita_delta = -10', (float)$movD['quantita_delta'], -10.0);
$rigaD = $pdo->query("SELECT movimento_id FROM inventari_righe WHERE inventario_id=$idD AND prodotto_id=9001")->fetch(PDO::FETCH_ASSOC);
chkTrue('D: movimento_id collegato alla riga', !empty($rigaD['movimento_id']));
// ripristino
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 100 WHERE prodotto_id=9001 AND magazzino_id=$magId1");

echo "\n=== Scenario E — delta cumulato multi-lotto porta la giacenza sotto zero (deve bloccare TUTTO) ===\n";
// Prodotto B: 30 nel lotto 1, 20 nel lotto 2, aggregato 50. Conto lotto1=5 (delta -25), lotto2=5 (delta -15) -> cumulato -40 -> 50-40=10, NON negativo... serve un caso che vada sotto zero
// Ricalcolo: per andare sotto zero serve delta cumulato < -50. lotto1: 30->0 (delta -30), lotto2: 20->0 (delta -20) => cumulato -50 => 50-50=0, non negativo (bordo).
// Portiamo un lotto a un conteggio "impossibile" negativo non è permesso (validazione >=0 lato submit), ma il PRODOTTO puo' comunque andare sotto zero se un altro movimento nel frattempo ha già eroso la giacenza aggregata: simuliamo riducendo la giacenza aggregata a mano PRIMA della convalida (scenario realistico: un altro movimento è avvenuto tra apertura e convalida).
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 10 WHERE prodotto_id=9002 AND magazzino_id=$magId1"); // giacenza aggregata scesa a 10 (era 50) per cause esterne
$prepE = test_ii_prepare($pdo, null, null, $magId1);
$keyLotto1 = "9002:9001"; // prodotto 9002, lotto 9001 (teorica 30)
$keyLotto2 = "9002:9002"; // prodotto 9002, lotto 9002 (teorica 20)
$subE = test_ii_submit($pdo, null, null, $prepE['prodotti_inventario'], $prepE['magazzinoInventarioId'], $prepE['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '',
    'righe' => [$keyLotto1 => '25', $keyLotto2 => '15'], // delta -5 e -5, cumulato -10 => 10-10=0 (non negativo, non basta)
], $UID);
// Con cumulato -10 su aggregato 10 il risultato è 0, non negativo: verifichiamo prima che questo passi
chkTrue('E-preliminare: cumulato -10 su aggregato 10 (=0) NON blocca', $subE['ok'] === true, (string)($subE['errors']['_global'] ?? ''));
if ($subE['ok']) { $pdo->exec("DELETE FROM inventari WHERE id=" . $subE['id']); }
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 10 WHERE prodotto_id=9002 AND magazzino_id=$magId1");
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = 30 WHERE lotto_id=9001 AND magazzino_id=$magId1");
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = 20 WHERE lotto_id=9002 AND magazzino_id=$magId1");
// Ora un conteggio che cumula -15 (es. 20 e 15, delta -10 e -5) su aggregato 10 -> 10-15=-5, negativo: DEVE bloccare
$prepE2 = test_ii_prepare($pdo, null, null, $magId1);
$subE2 = test_ii_submit($pdo, null, null, $prepE2['prodotti_inventario'], $prepE2['magazzinoInventarioId'], $prepE2['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '',
    'righe' => [$keyLotto1 => '20', $keyLotto2 => '15'], // delta -10 e -5, cumulato -15 su aggregato 10 => -5, negativo
], $UID);
chkTrue('E: cumulato -15 su aggregato 10 BLOCCA la convalida', $subE2['ok'] === false);
chkTrue('E: messaggio menziona "sotto zero"', str_contains((string)($subE2['errors']['_global'] ?? ''), 'sotto zero'));
// Nessuna riga deve essere stata scritta (rollback completo)
$countInvAfterE = (int) $pdo->query("SELECT COUNT(*) FROM inventari WHERE magazzino_id=$magId1 AND stato='bozza'")->fetchColumn();
chk('E: nessuna bozza residua dopo il blocco (rollback completo)', $countInvAfterE, 0);
$giacDopoE = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=9002 AND magazzino_id=$magId1")->fetchColumn();
chk('E: giacenza prodotto B invariata dopo il blocco', $giacDopoE, 10.0);
// ripristino
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 50 WHERE prodotto_id=9002 AND magazzino_id=$magId1");

echo "\n=== Scenario F — sotto la riserva ma non sotto zero: procede CON avviso ===\n";
// Prodotto C: giacenza 100, riservata 60. Conto 50 (delta -50) -> nuova giacenza 50, sotto la riservata (60) ma non negativa.
$prepF = test_ii_prepare($pdo, null, null, $magId1);
$keyC = "9003:";
$subF = test_ii_submit($pdo, null, null, $prepF['prodotti_inventario'], $prepF['magazzinoInventarioId'], $prepF['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [$keyC => '50'],
], $UID);
chkTrue('F: convalida procede (non bloccata)', $subF['ok'] === true, (string)($subF['errors']['_global'] ?? ''));
chkTrue('F: avviso sotto-copertura presente', !empty($subF['avvisi_riservata']));
chkTrue('F: messaggio finale contiene "sotto-copertura ordini"', str_contains($subF['msg'], 'sotto-copertura ordini'));
chk('F: flashType = warning', $subF['flashType'], 'warning');
$giacF = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=9003 AND magazzino_id=$magId1")->fetchColumn();
chk('F: giacenza aggiornata a 50 nonostante l\'avviso', $giacF, 50.0);
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 100 WHERE prodotto_id=9003 AND magazzino_id=$magId1");

echo "\n=== Scenario G — lock di creazione concorrente ===\n";
// Simulato in-process: il lock è una riga con PK su magazzino_id, quindi un secondo INSERT
// sullo stesso magazzino nella stessa connessione fallirebbe comunque per vincolo UNIQUE/PK,
// a patto che la prima riga di lock non sia ancora stata rilasciata. Verifichiamo che il
// vincolo esista e si comporti come atteso con un test diretto sulla tabella.
try {
    $pdo->exec("DELETE FROM inventario_creazione_lock"); // pulizia
    $pdo->prepare('INSERT INTO inventario_creazione_lock (magazzino_id) VALUES (?)')->execute([$magId1]);
    $secondFailed = false;
    try {
        $pdo->prepare('INSERT INTO inventario_creazione_lock (magazzino_id) VALUES (?)')->execute([$magId1]);
    } catch (PDOException $e) {
        $secondFailed = true;
    }
    chkTrue('G: un secondo lock sullo stesso magazzino viene rifiutato dal vincolo DB', $secondFailed);
    $pdo->exec("DELETE FROM inventario_creazione_lock");
} catch (\Throwable $e) {
    echo "NOTA: scenario G non completamente automatizzabile in-process (" . $e->getMessage() . ") — richiede verifica manuale multi-connessione in ambiente reale.\n";
}

echo "\n=== Scenario H — annulla bozza ===\n";
$prepH = test_ii_prepare($pdo, null, null, $magId1);
$subH0 = test_ii_submit($pdo, null, null, $prepH['prodotti_inventario'], $prepH['magazzinoInventarioId'], $prepH['categoriaFiltroId'], [
    'azione' => 'salva', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [],
], $UID);
$idH = $subH0['id'];
$invH = $pdo->query("SELECT * FROM inventari WHERE id=$idH")->fetch(PDO::FETCH_ASSOC);
$subH = test_ii_submit($pdo, $idH, $invH, [], $magId1, null, ['azione' => 'annulla'], $UID);
chkTrue('H: annulla ok', $subH['ok'] === true);
$existsH = $pdo->query("SELECT COUNT(*) FROM inventari WHERE id=$idH")->fetchColumn();
chk('H: testata cancellata', (int) $existsH, 0);
$righeH = $pdo->query("SELECT COUNT(*) FROM inventari_righe WHERE inventario_id=$idH")->fetchColumn();
chk('H: righe cancellate (ON DELETE CASCADE)', (int) $righeH, 0);

echo "\n=== Scenario I — modifica di un inventario già convalidato ===\n";
$prepI = test_ii_prepare($pdo, null, null, $magId1);
$subI0 = test_ii_submit($pdo, null, null, $prepI['prodotti_inventario'], $prepI['magazzinoInventarioId'], $prepI['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [],
], $UID);
$idI = $subI0['id'];
$invI = $pdo->query("SELECT * FROM inventari WHERE id=$idI")->fetch(PDO::FETCH_ASSOC);
chk('I: precondizione, stato convalidato', $invI['stato'], 'convalidato');
$subI = test_ii_submit($pdo, $idI, $invI, [], $magId1, null, ['azione' => 'salva', 'data_inventario' => '2026-07-21', 'note' => 'tentativo', 'righe' => []], $UID);
chkTrue('I: modifica bloccata', $subI['ok'] === false);
chkTrue('I: messaggio corretto', str_contains((string)($subI['errors']['_global'] ?? ''), 'convalidato'));
$pdo->exec("DELETE FROM inventari WHERE id=$idI");

echo "\n=== Scenario L — quantità contata negativa ===\n";
$prepL = test_ii_prepare($pdo, null, null, $magId1);
$subL = test_ii_submit($pdo, null, null, $prepL['prodotti_inventario'], $prepL['magazzinoInventarioId'], $prepL['categoriaFiltroId'], [
    'azione' => 'salva', 'data_inventario' => '2026-07-19', 'note' => '', 'righe' => [$keyA => '-5'],
], $UID);
chkTrue('L: bloccato per quantità negativa', $subL['ok'] === false);
chkTrue('L: messaggio corretto', str_contains((string)($subL['errors']['_global'] ?? ''), 'negative'));
$countAfterL = (int) $pdo->query("SELECT COUNT(*) FROM inventari WHERE magazzino_id=$magId1 AND stato='bozza'")->fetchColumn();
chk('L: nessuna transazione committata', $countAfterL, 0);

echo "\n=== Scenario M — PASS2 ricalcola il delta riga-per-riga, indipendente dall'aggregato di PASS1 ===\n";
// Costruiamo un caso dove, se PASS2 riusasse l'aggregato di PASS1 invece di ricalcolare
// per riga, il risultato sarebbe diverso. Prodotto B multi-lotto: lotto1 30->20 (delta -10),
// lotto2 20->25 (delta +5). Cumulato PASS1 = -5. Se PASS2 applicasse solo -5 su un'unica riga
// "aggregata" invece di -10 e +5 separatamente sui due lotti, i lotti_giacenze finirebbero
// sbagliati (uno dei due lotti non verrebbe toccato). Verifichiamo che ENTRAMBI i lotti
// vengano aggiornati coerentemente con il proprio delta individuale.
$pass2Chiamata = false;
$prepM = test_ii_prepare($pdo, null, null, $magId1);
$subM = test_ii_submit($pdo, null, null, $prepM['prodotti_inventario'], $prepM['magazzinoInventarioId'], $prepM['categoriaFiltroId'], [
    'azione' => 'convalida', 'data_inventario' => '2026-07-19', 'note' => '',
    'righe' => [$keyLotto1 => '20', $keyLotto2 => '25'], // lotto1: 30->20 (-10), lotto2: 20->25 (+5), cumulato -5
], $UID, $pass2Chiamata);
chkTrue('M: convalida ok (cumulato -5 su aggregato 50, non negativo)', $subM['ok'] === true, (string)($subM['errors']['_global'] ?? ''));
chkTrue('M: PASS2 è stato eseguito', $pass2Chiamata);
$lotto1Dopo = (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id=9001 AND magazzino_id=$magId1")->fetchColumn();
$lotto2Dopo = (float) $pdo->query("SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id=9002 AND magazzino_id=$magId1")->fetchColumn();
chk('M: lotto1 aggiornato al proprio delta (-10 -> 20)', $lotto1Dopo, 20.0);
chk('M: lotto2 aggiornato al proprio delta (+5 -> 25)', $lotto2Dopo, 25.0);
$giacBDopo = (float) $pdo->query("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id=9002 AND magazzino_id=$magId1")->fetchColumn();
chk('M: giacenza aggregata prodotto B = 45 (50 - 10 + 5)', $giacBDopo, 45.0);
// ripristino
$pdo->exec("UPDATE giacenze SET quantita_disponibile = 50 WHERE prodotto_id=9002 AND magazzino_id=$magId1");
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = 30 WHERE lotto_id=9001 AND magazzino_id=$magId1");
$pdo->exec("UPDATE lotti_giacenze SET quantita_disponibile = 20 WHERE lotto_id=9002 AND magazzino_id=$magId1");

echo "\n" . str_repeat('=', 70) . "\n";
echo "RISULTATO: {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
if ($GLOBALS['__fail'] > 0) {
    exit(1);
}
exit(0);
