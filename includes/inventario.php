<?php

declare(strict_types=1);

// Dipendenze esplicite (CLAUDE.md, regola require_once in testa al file): questo
// file viene caricato anche in isolamento da tests/manual/suite_create_inventario.php,
// senza passare da app/bootstrap.php — non può contare sul fatto che siano già
// stati inclusi altrove (Tappa 1, PIANO - Motore Unificato di Transizioni di Stato).
require_once __DIR__ . '/transizioni_stati.php';
require_once __DIR__ . '/../app/Services/TransitionGuard.php';

// =============================================================================
// inventario.php
// Service layer per l'Inventario Fisico, estratto da
// WarehouseController::createInventario() (Piano refactor "God Object" —
// Revisione Gestione Magazzino e Lotti, audit MVC/DRY, Fase 2). Il metodo
// controller originale (433 righe) mischiava routing HTTP, lock applicativo,
// validazione, business logic transazionale e persistenza in un unico blocco;
// qui vive la logica di dominio, estratta un pezzo alla volta e verificata ad
// ogni passo contro tests/manual/suite_create_inventario.php (44 asserzioni,
// scenari A-M) per garantire zero cambi di comportamento.
//
// Vincolo architetturale che regola tutto questo file (vedi il piano): PDO non
// supporta transazioni annidate. Solo la facade transazionale (aggiunta in
// microtappa 2.3) apre/chiude la transazione — tutte le altre funzioni qui
// sono pure rispetto a beginTransaction/commit: operano sul $pdo che ricevono,
// dentro la transazione già aperta dal chiamante.
// =============================================================================

/**
 * PASS 1 dell'inventario fisico (microtappa 2.1): valida TUTTI gli scostamenti
 * PRIMA che qualunque rettifica venga applicata (vedi qk_applica_rettifiche_inventario,
 * microtappa 2.2, che deve essere chiamata dal chiamante SOLO se 'violazioni' è
 * vuoto). Non lancia eccezioni e non tocca il database in scrittura: è il
 * chiamante (la facade, microtappa 2.3) a decidere cosa fare del risultato.
 *
 * Un prodotto a lotti ha una riga per lotto in `inventari_righe`, ma
 * `giacenze.quantita_disponibile` è un aggregato PER PRODOTTO (somma di tutti
 * i lotti). Controllare ogni riga isolatamente contro lo stesso valore di
 * giacenza sarebbe sbagliato quando un prodotto ha più lotti in conteggio
 * nella stessa sessione: due delta negativi indipendenti possono sembrare
 * entrambi innocui presi da soli, ma sommati insieme possono portare la
 * giacenza reale sotto zero. Per questo qui si sommano prima i delta PER
 * PRODOTTO, poi si controlla il totale cumulato una sola volta a prodotto.
 *
 * @param array $righeSalvate righe di `inventari_righe` già lette dal chiamante
 *                             (ognuna con almeno prodotto_id, quantita_contata,
 *                             giacenza_teorica)
 * @return array{violazioni: list<string>, avvisi_riservata: list<string>}
 */
function qk_valida_scostamenti_inventario(PDO $pdo, int $magazzinoId, array $righeSalvate): array
{
    $deltaPerProdotto = [];
    foreach ($righeSalvate as $riga) {
        $delta = (float) $riga['quantita_contata'] - (float) $riga['giacenza_teorica'];
        if (abs($delta) < 0.0001) {
            continue;
        }
        $pid = (int) $riga['prodotto_id'];
        $deltaPerProdotto[$pid] = ($deltaPerProdotto[$pid] ?? 0.0) + $delta;
    }

    $stNomeProdotto = $pdo->prepare('SELECT nome FROM prodotti WHERE id = ?');
    $stGiacChk = $pdo->prepare("SELECT quantita_disponibile, quantita_riservata FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");

    $violazioni = [];
    $avvisiRiservata = [];
    foreach ($deltaPerProdotto as $pid => $deltaTotale) {
        if (abs($deltaTotale) < 0.0001) {
            continue;
        }
        $stGiacChk->execute([$pid, $magazzinoId]);
        $giacChk = $stGiacChk->fetch(PDO::FETCH_ASSOC);
        $qtaCorrenteChk = $giacChk ? (float) $giacChk['quantita_disponibile'] : 0.0;
        $riservataChk = $giacChk ? (float) $giacChk['quantita_riservata'] : 0.0;
        $nuovaQtaChk = $qtaCorrenteChk + $deltaTotale;

        $stNomeProdotto->execute([$pid]);
        $nomeProdotto = (string) ($stNomeProdotto->fetchColumn() ?: ('#' . $pid));

        if ($nuovaQtaChk < -0.0001) {
            $violazioni[] = sprintf('"%s": giacenza risultante %.3f', $nomeProdotto, $nuovaQtaChk);
        } elseif ($nuovaQtaChk < $riservataChk - 0.0001) {
            $avvisiRiservata[] = sprintf('"%s": disponibile %.3f dopo la rettifica, ma %.3f già riservata per ordini in corso', $nomeProdotto, $nuovaQtaChk, $riservataChk);
        }
    }

    return ['violazioni' => $violazioni, 'avvisi_riservata' => $avvisiRiservata];
}

/**
 * PASS 2 dell'inventario fisico (microtappa 2.2): applica le rettifiche riga
 * per riga — da chiamare SOLO dopo che qk_valida_scostamenti_inventario() ha
 * restituito 'violazioni' vuoto (il chiamante, la facade, è responsabile di
 * questo ordine: qui non si ripete il controllo). Nessuna transazione propria:
 * opera dentro quella già aperta dal chiamante.
 *
 * Ricalcola il delta RIGA PER RIGA (quantita_contata - giacenza_teorica),
 * indipendentemente dal delta aggregato per prodotto già calcolato da PASS1:
 * scelta intenzionale, non un'ottimizzazione mancata. Un prodotto a lotti ha
 * più righe (una per lotto) che devono aggiornare `lotti_giacenze` ciascuna
 * per il proprio valore — riusare l'aggregato di PASS1 applicandolo a una sola
 * riga lascerebbe gli altri lotti non aggiornati (vedi scenario M della suite
 * di test: due lotti con delta opposti, es. -10 e +5, cumulato -5 — se
 * applicato come valore unico a una riga sola, l'altro lotto resterebbe
 * silenziosamente disallineato dalla realtà).
 */
function qk_applica_rettifiche_inventario(PDO $pdo, int $inventarioId, int $magazzinoId, array $righeSalvate, int $utenteId): void
{
    foreach ($righeSalvate as $riga) {
        $delta = (float) $riga['quantita_contata'] - (float) $riga['giacenza_teorica'];
        if (abs($delta) < 0.0001) {
            continue; // nessuna rettifica necessaria
        }

        $quantitaAssoluta = abs($delta);

        // 1. Crea il movimento di magazzino, per QUESTO magazzino.
        // `quantita` resta la magnitudine (invariante di schema);
        // `quantita_delta` porta il segno per la UI storico movimenti
        // (vedi stesso pattern in qk_insert_movimento_manuale).
        $stmtMov = $pdo->prepare("
            INSERT INTO movimenti_magazzino
            (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, documento_tipo, documento_id, canale_origine, note, creato_da)
            VALUES (?, ?, ?, 'rettifica', ?, ?, 'inventario', ?, 'manuale', ?, ?)
        ");
        $noteMov = sprintf("Rettifica da inventario #%d. Scostamento: %+.3f", $inventarioId, $delta);
        $stmtMov->execute([
            $riga['prodotto_id'],
            $riga['lotto_id'],
            $magazzinoId,
            $quantitaAssoluta,
            $delta,
            $inventarioId,
            $noteMov,
            $utenteId,
        ]);
        $movimentoId = (int) $pdo->lastInsertId();

        // Associa il movimento alla riga
        $pdo->prepare("UPDATE inventari_righe SET movimento_id = ? WHERE id = ?")->execute([$movimentoId, $riga['id']]);

        // 2. Aggiorna la giacenza di QUESTO magazzino
        $stGiac = $pdo->prepare("SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");
        $stGiac->execute([$riga['prodotto_id'], $magazzinoId]);
        $giac = $stGiac->fetch(PDO::FETCH_ASSOC);

        if ($giac) {
            $nuovaQta = (float) $giac['quantita_disponibile'] + $delta;
            $pdo->prepare("UPDATE giacenze SET quantita_disponibile = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$nuovaQta, $giac['id']]);
        } else {
            $pdo->prepare("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                                 VALUES (?, ?, ?, 0, 0, 0, datetime('now'))")
                ->execute([$riga['prodotto_id'], $magazzinoId, $delta]);
        }

        // 3. Aggiorna quantità disponibile del lotto in QUESTO magazzino
        //    (lotti_giacenze, non più il campo aggregato lotti.quantita_disponibile)
        if ($riga['lotto_id']) {
            $pdo->prepare("
                INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
                VALUES (?, ?, ?, datetime('now'))
                ON CONFLICT(lotto_id, magazzino_id) DO UPDATE SET
                    quantita_disponibile = quantita_disponibile + excluded.quantita_disponibile,
                    updated_at = excluded.updated_at
            ")->execute([$riga['lotto_id'], $magazzinoId, $delta]);
        }
    }
}

/**
 * WHERE dinamico condiviso da qk_inventari_count()/qk_inventari_list()
 * (Fase 3, microtappa 3.2 — stesso pattern di _qk_stock_where() in
 * includes/magazzino.php, per non calcolare il filtro due volte con il
 * rischio che le due copie divergano).
 *
 * @return array{sql:string, params:list<mixed>}
 */
function _qk_inventari_where(string $search, string $stato): array
{
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[]  = '(u.username LIKE ? OR i.note LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (in_array($stato, ['bozza', 'convalidato'], true)) {
        $where[]  = 'i.stato = ?';
        $params[] = $stato;
    }
    return ['sql' => $where ? 'WHERE ' . implode(' AND ', $where) : '', 'params' => $params];
}

/**
 * Conta gli inventari fisici che corrispondono ai filtri (Fase 3, microtappa
 * 3.2). Estratto da WarehouseController::inventari().
 */
function qk_inventari_count(PDO $pdo, string $search = '', string $stato = ''): int
{
    $w = _qk_inventari_where($search, $stato);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventari i JOIN utenti u ON u.id = i.creato_da ' . $w['sql']);
    $stmt->execute($w['params']);
    return (int) $stmt->fetchColumn();
}

/**
 * Lista paginata degli inventari fisici (Fase 3, microtappa 3.2). $sort è
 * una chiave whitelisted ('id'|'data'|'stato'|'creatore', vedi
 * qk_pagination_params() nel controller), non una colonna SQL — mappata qui
 * dentro, stesso pattern già in uso in qk_stock_list().
 */
function qk_inventari_list(PDO $pdo, string $search, string $stato, string $sort, string $dir, int $limit, int $offset): array
{
    $sortColMap = [
        'id'       => 'i.id',
        'data'     => 'i.data_inventario',
        'stato'    => 'i.stato',
        'creatore' => 'u.username',
    ];
    $sortCol = $sortColMap[$sort] ?? $sortColMap['id'];
    $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

    $w = _qk_inventari_where($search, $stato);
    $stmt = $pdo->prepare(
        'SELECT i.*, u.username AS creatore_username
         FROM inventari i
         JOIN utenti u ON u.id = i.creato_da
         ' . $w['sql'] . '
         ORDER BY ' . $sortCol . ' ' . $dir . '
         LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($w['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Righe teoriche per un NUOVO inventario fisico (microtappa 2.4): tutti i
 * prodotti attivi non-kit del magazzino, oppure solo quelli della categoria
 * scelta per un cycle count (Tappa 4.3). Se un prodotto traccia i lotti, una
 * riga per lotto con giacenza_teorica dal relativo lotti_giacenze; altrimenti
 * una riga sola dalla giacenza aggregata (giacenze).
 *
 * Un inventario ESISTENTE non passa da qui: le sue righe vengono lette da
 * inventari_righe (già salvate alla creazione) dal chiamante — lo scope
 * (magazzino e categoria) resta quello fissato alla creazione, non va
 * ricalcolato.
 */
function qk_inventario_prodotti_scope(PDO $pdo, int $magazzinoId, ?int $categoriaFiltroId): array
{
    $condCategoria = $categoriaFiltroId ? 'AND p.categoria_id = ?' : '';
    $params = [$magazzinoId, $magazzinoId];
    if ($categoriaFiltroId) {
        $params[] = $categoriaFiltroId;
    }
    $stmt = $pdo->prepare("
        SELECT p.id AS prodotto_id, p.nome AS nome_prodotto, p.sku_interno, u.codice AS uom,
               l.id AS lotto_id, l.codice_lotto_interno,
               CASE WHEN p.traccia_lotti = 1 THEN COALESCE(lg.quantita_disponibile, 0)
                    ELSE COALESCE(g.quantita_disponibile, 0)
               END AS giacenza_teorica
        FROM prodotti p
        LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
        LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
        LEFT JOIN lotti l ON l.prodotto_id = p.id AND (p.traccia_lotti = 1)
        LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
        WHERE p.attivo = 1 AND p.tipo != 'kit' $condCategoria
        ORDER BY p.nome COLLATE NOCASE, l.codice_lotto_interno ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Guardia anti-sovrapposizione (Tappa 4.3, unificata in microtappa 2.4): true
 * se esiste già una bozza, per questo magazzino, che copre uno o più degli
 * stessi prodotti — due cycle count su categorie diverse possono coesistere,
 * ma non su prodotti in comune.
 *
 * Chiamata due volte con lo stesso identico controllo (verificate bit a bit
 * identiche prima di unificarle in questa unica funzione): una volta dal
 * controller PRIMA della transazione (redirect immediato, comodo su
 * GET/preview) e una volta da qk_convalida_inventario() DENTRO il lock
 * applicativo — quest'ultima è l'unica che conta davvero contro le race
 * condition (vedi commento lì sul perché il lock da solo non basta).
 *
 * @return array{id:int, categoria_nome:?string}|null
 */
function qk_inventario_verifica_sovrapposizione(PDO $pdo, int $magazzinoId, array $prodottiInventario): ?array
{
    if (empty($prodottiInventario)) {
        return null;
    }
    $prodIds = array_values(array_unique(array_column($prodottiInventario, 'prodotto_id')));
    $in = implode(',', array_map('intval', $prodIds));
    $stmt = $pdo->prepare("
        SELECT i.id, cat.nome AS categoria_nome
        FROM inventari i
        JOIN inventari_righe ir ON ir.inventario_id = i.id
        LEFT JOIN categorie_prodotto cat ON cat.id = i.categoria_filtro_id
        WHERE i.magazzino_id = ? AND i.stato = 'bozza' AND ir.prodotto_id IN ($in)
        LIMIT 1
    ");
    $stmt->execute([$magazzinoId]);
    $overlap = $stmt->fetch(PDO::FETCH_ASSOC);
    return $overlap ?: null;
}

/**
 * Facade transazionale per il salvataggio/convalida di un inventario fisico
 * (microtappa 2.3). UNICO punto di questo dominio che apre/chiude una
 * transazione SQL: PDO non supporta transazioni annidate, quindi lock
 * applicativo, guardia di sovrapposizione ripetuta, CRUD testata/righe,
 * qk_valida_scostamenti_inventario() (PASS1), qk_applica_rettifiche_inventario()
 * (PASS2) e il cambio di stato a 'convalidato' devono restare tutti dentro lo
 * stesso `beginTransaction()`/`commit()` — garanzia atomica documentata in
 * db/migrations/2026_07_10_inventario_creazione_lock.php (il lock e l'INSERT
 * della testata devono succedere o fallire insieme).
 *
 * L'id dell'inventario non è più una variabile mutata per riferimento a metà
 * strada (come nel controller originale): questa funzione lo RESTITUISCE
 * sempre nel valore di ritorno, che sia un id nuovo o quello già esistente
 * ricevuto in ingresso. Allo stesso modo, $avvisiRiservata (l'avviso
 * "sotto-copertura ordini" popolato solo da PASS1) torna esplicitamente nel
 * valore di ritorno — il chiamante non deve fare altro che leggerlo.
 *
 * @param array $input ['azione'=>'salva'|'convalida', 'data_inventario', 'note',
 *                       'magazzino_id', 'categoria_filtro_id'(nullable),
 *                       'prodotti_inventario' (righe teoriche, calcolate dal
 *                       chiamante — dipende dal branch nuovo/esistente, che è
 *                       routing/UI, non dominio di scrittura),
 *                       'righe' => [chiave "prodottoId:lottoId" => quantità
 *                       contata già normalizzata in float, non $_POST grezzo]]
 * @return array{id:int, avvisi_riservata:list<string>}
 * @throws \Throwable qualunque eccezione sollevata durante la transazione
 *                     (lock occupato, sovrapposizione, PASS1 con violazioni)
 *                     dopo aver già fatto rollback — il chiamante la cattura
 *                     e la mostra come errore, come già faceva il controller.
 */
function qk_convalida_inventario(PDO $pdo, ?int $id, array $input, int $utenteId): array
{
    $azione = $input['azione'] ?? 'salva';
    $dataInventario = $input['data_inventario'];
    $note = $input['note'];
    $magazzinoInventarioId = (int) $input['magazzino_id'];
    $categoriaFiltroId = $input['categoria_filtro_id'] ?? null;
    $prodottiInventario = $input['prodotti_inventario'];
    $inputRighe = $input['righe'] ?? [];

    $pdo->beginTransaction();
    try {
        $avvisiRiservata = [];

        if (!$id) {
            // Lock applicativo (Tappa 4.3): il controllo di sovrapposizione qui
            // sotto è un check-then-act (SELECT poi, più tardi, INSERT) — una
            // semplice transazione NON basta a renderlo atomico: due richieste
            // concorrenti potrebbero entrambe superare la SELECT prima che una
            // delle due scriva (race "phantom read"). Un vincolo PRIMARY KEY,
            // invece, viene sempre controllato in modo atomico dal motore
            // all'INSERT: la seconda richiesta concorrente sullo stesso magazzino
            // fallisce/si accoda deterministicamente. La riga di lock viene
            // rilasciata (DELETE) più sotto, nella stessa transazione, subito
            // dopo l'inserimento della testata — non è un lock persistente.
            try {
                $pdo->prepare('INSERT INTO inventario_creazione_lock (magazzino_id) VALUES (?)')
                    ->execute([$magazzinoInventarioId]);
            } catch (PDOException $eLock) {
                throw new \RuntimeException('Un\'altra creazione di inventario per questo magazzino è in corso in questo momento. Riprova tra qualche istante.');
            }

            // Ripeti la guardia anti-sovrapposizione (già fatta dal chiamante, fuori
            // transazione, solo per il redirect immediato in caso di GET/preview)
            // QUI DENTRO alla transazione, ora sotto la copertura del lock appena
            // preso: nessun'altra richiesta concorrente può essere arrivata fin
            // qui nel frattempo per lo stesso magazzino.
            $overlapChk = qk_inventario_verifica_sovrapposizione($pdo, $magazzinoInventarioId, $prodottiInventario);
            if ($overlapChk) {
                $scopeLabelChk = $overlapChk['categoria_nome'] ? ('categoria "' . $overlapChk['categoria_nome'] . '"') : 'tutti i prodotti';
                throw new \RuntimeException(
                    'Esiste già un inventario in bozza (#' . $overlapChk['id'] . ', ' . $scopeLabelChk . ') che copre uno o più di questi prodotti in questo magazzino. Completa o annulla quello prima di aprirne un altro sugli stessi prodotti.'
                );
            }

            // Inserisci testata (magazzino e categoria filtro fissati alla
            // creazione, non più cambiabili dopo)
            $stmt = $pdo->prepare("
                INSERT INTO inventari (data_inventario, magazzino_id, categoria_filtro_id, note, creato_da, stato)
                VALUES (?, ?, ?, ?, ?, 'bozza')
            ");
            $stmt->execute([$dataInventario, $magazzinoInventarioId, $categoriaFiltroId, $note, $utenteId]);
            $id = (int) $pdo->lastInsertId();

            // Rilascia il lock: la testata è scritta (dentro questa stessa
            // transazione), quindi da qui in poi una nuova creazione concorrente
            // vedrebbe comunque questa bozza tramite la guardia di sovrapposizione
            // sopra — non serve più tenere occupato il lock fino al commit finale.
            $pdo->prepare('DELETE FROM inventario_creazione_lock WHERE magazzino_id = ?')
                ->execute([$magazzinoInventarioId]);
        } else {
            // Guardia di stato (Tappa 1, PIANO - Motore Unificato di Transizioni
            // di Stato): prima di questa modifica il controllo "solo le bozze
            // sono modificabili" viveva SOLO in WarehouseController::editInventory(),
            // sulla lettura di $inventario fatta PRIMA di aprire questa transazione
            // — un check-then-act non protetto dalla transazione (a differenza
            // della guardia di sovrapposizione sopra, ripetuta apposta qui dentro
            // per lo stesso motivo). Rilettura fresca dello stato, dentro la
            // transazione, come unico punto davvero autoritativo.
            $statoAttualeStmt = $pdo->prepare('SELECT stato FROM inventari WHERE id = ?');
            $statoAttualeStmt->execute([$id]);
            $statoAttuale = (string) $statoAttualeStmt->fetchColumn();

            $guardInventario = new \App\Services\TransitionGuard(qk_transizioni_inventari(), 'inventario');
            $guardInventario->assicuraConMessaggio(
                $statoAttuale,
                'bozza',
                'Impossibile salvare: l\'inventario è già convalidato e non può più essere modificato.'
            );

            // Aggiorna testata
            $stmt = $pdo->prepare("UPDATE inventari SET data_inventario = ?, note = ? WHERE id = ?");
            $stmt->execute([$dataInventario, $note, $id]);
            // Rimuovi vecchie righe temporaneamente
            $pdo->prepare("DELETE FROM inventari_righe WHERE inventario_id = ?")->execute([$id]);
        }

        // Reinserisci righe
        $stmtIns = $pdo->prepare("
            INSERT INTO inventari_righe (inventario_id, prodotto_id, lotto_id, giacenza_teorica, quantita_contata)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($prodottiInventario as $p) {
            $key = $p['prodotto_id'] . ':' . ($p['lotto_id'] ?? '');
            $contata = isset($inputRighe[$key]) ? (float) $inputRighe[$key] : (float) $p['giacenza_teorica'];
            $lottoId = $p['lotto_id'] ? (int) $p['lotto_id'] : null;
            $stmtIns->execute([$id, $p['prodotto_id'], $lottoId, $p['giacenza_teorica'], $contata]);
        }

        // Convalida: genera i movimenti logistici e allinea le giacenze
        if ($azione === 'convalida') {
            // Recupera le righe appena salvate
            $stmtRighe = $pdo->prepare("SELECT * FROM inventari_righe WHERE inventario_id = ?");
            $stmtRighe->execute([$id]);
            $righeSalvate = $stmtRighe->fetchAll(PDO::FETCH_ASSOC);

            // PASS1 deve completarsi interamente PRIMA che PASS2 scriva qualunque
            // rettifica: se ci sono violazioni, si esce con throw senza mai
            // chiamare PASS2 (vedi qk_valida_scostamenti_inventario per il perché
            // del calcolo aggregato per prodotto, non per singola riga).
            $risultatoValidazione = qk_valida_scostamenti_inventario($pdo, $magazzinoInventarioId, $righeSalvate);
            $avvisiRiservata = $risultatoValidazione['avvisi_riservata'];
            if (!empty($risultatoValidazione['violazioni'])) {
                throw new \RuntimeException(
                    'Impossibile convalidare: alcune rettifiche porterebbero la giacenza sotto zero — ' .
                    implode('; ', $risultatoValidazione['violazioni']) . '. Verifica il conteggio prima di riprovare.'
                );
            }

            qk_applica_rettifiche_inventario($pdo, $id, $magazzinoInventarioId, $righeSalvate, $utenteId);

            // Guardia sulla transizione vera e propria (bozza -> convalidato).
            // Per un inventario appena creato in questa stessa chiamata (ramo
            // !$id sopra) lo stato è sempre 'bozza' per costruzione (appena
            // inserito); per un inventario esistente la guardia sopra ha già
            // verificato che sia ancora 'bozza' prima di arrivare qui — questo
            // secondo controllo, fatto immediatamente prima della scrittura
            // che cambia stato, non è ridondante: è lo stesso principio già
            // applicato alla guardia di sovrapposizione, verificare più vicino
            // possibile al punto di scrittura, non solo a monte.
            $guardConvalida = new \App\Services\TransitionGuard(qk_transizioni_inventari(), 'inventario');
            $guardConvalida->assicura('bozza', 'convalidato');

            // Segna inventario come convalidato
            $pdo->prepare("UPDATE inventari SET stato = 'convalidato' WHERE id = ?")->execute([$id]);
        }

        $pdo->commit();
        return ['id' => $id, 'avvisi_riservata' => $avvisiRiservata];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
