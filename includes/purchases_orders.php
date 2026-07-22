<?php
declare(strict_types=1);
/**
 * includes/purchases_orders.php
 * Funzioni DB per gli Ordini di Acquisto.
 *
 * Ciclo di vita: bozza → in_approvazione → approvato → inviato
 *                → parzialmente_ricevuto → completato | annullato
 *
 * Dipende da: includes/purchases_common.php
 */

require_once __DIR__ . '/purchases_common.php';
require_once __DIR__ . '/prodotti.php';
require_once __DIR__ . '/magazzino.php';
require_once __DIR__ . '/transizioni_stati.php';
require_once __DIR__ . '/../app/Services/TransitionGuard.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco ordini con filtri.
 */
/** @internal */
function _qk_ordini_where(string $search, string $stato, string $dal, string $al): array
{
    $where = ['1=1']; $params = [];
    if ($search !== '') {
        $where[] = '(ot.numero LIKE ? OR COALESCE(cf.ragione_sociale, cf.nome) LIKE ? OR r.numero LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($stato !== '') { $where[] = 'ot.stato = ?'; $params[] = $stato; }
    if ($dal  !== '') { $where[] = 'ot.data_ordine >= ?'; $params[] = $dal; }
    if ($al   !== '') { $where[] = 'ot.data_ordine <= ?'; $params[] = $al; }
    return [$where, $params];
}

function qk_ordini_count(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = ''): int
{
    [$where, $params] = _qk_ordini_where($search, $stato, $dal, $al);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ordini_testata ot LEFT JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id LEFT JOIN rfq_testata r ON r.id = ot.rfq_id WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function qk_ordini_list(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = '', string $sort = 'data', string $dir = 'desc', int $limit = 50, int $offset = 0): array
{
    $sortMap = [
        'data'      => 'ot.data_ordine',
        'numero'    => 'ot.numero',
        'fornitore' => 'fornitore_nome',
        'stato'     => 'ot.stato',
        'consegna'  => 'ot.data_consegna_prevista',
        'totale'    => 'ot.totale',
    ];
    $orderCol = $sortMap[$sort] ?? 'ot.data_ordine';
    $orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

    [$where, $params] = _qk_ordini_where($search, $stato, $dal, $al);
    $params[] = $limit;
    $params[] = $offset;

    $st = $pdo->prepare(
        'SELECT ot.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                off.numero AS offerta_numero,
                r.numero   AS rfq_numero
           FROM ordini_testata ot
      LEFT JOIN clienti_fornitori cf  ON cf.id  = ot.fornitore_id
      LEFT JOIN offerte_testata   off ON off.id  = ot.offerta_id
      LEFT JOIN rfq_testata       r   ON r.id    = ot.rfq_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY ' . $orderCol . ' ' . $orderDir . ', ot.id DESC
          LIMIT ? OFFSET ?'
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dettaglio ordine: testata + righe (con pianificazione) + ricezioni collegate.
 *
 * @return array{ordine:array, righe:array, ricezioni:array}|null
 */
function qk_ordine_detail(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT ot.*,
                snap_addr.indirizzo_master_id AS indirizzo_consegna_master_id,
                snap_addr.personalizzato AS indirizzo_consegna_personalizzato,
                snap_addr.hash_originale AS indirizzo_consegna_hash_originale,
                snap_cont.contatto_master_id AS contatto_master_id,
                snap_cont.personalizzato AS contatto_personalizzato,
                snap_cont.hash_originale AS contatto_hash_originale,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                off.numero AS offerta_numero,
                r.numero   AS rfq_numero,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM ordini_testata ot
      LEFT JOIN indirizzi_documenti snap_addr ON snap_addr.id = ot.indirizzo_consegna_id
      LEFT JOIN contatti_documenti snap_cont ON snap_cont.id = ot.contatto_id
      LEFT JOIN clienti_fornitori cf  ON cf.id  = ot.fornitore_id
      LEFT JOIN offerte_testata   off ON off.id = ot.offerta_id
      LEFT JOIN rfq_testata       r   ON r.id   = ot.rfq_id
      LEFT JOIN metodi_pagamento mp ON mp.id = ot.metodo_pagamento_id
      LEFT JOIN listini_acquisto la ON la.id = ot.listino_acquisto_id
          WHERE ot.id = ?'
    );
    $st->execute([$id]);
    $ordine = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ordine) return null;

    // Righe ordine, ciascuna con la sua pianificazione consegna
    $stRighe = $pdo->prepare(
        'SELECT orr.*, p.nome AS prodotto_nome, p.sku_interno, um.codice AS uom
           FROM ordini_righe orr
           JOIN prodotti p ON p.id = orr.prodotto_id
      LEFT JOIN unita_misura um ON um.id = orr.unita_misura_id
          WHERE orr.ordine_id = ?
          ORDER BY orr.posizione_riga'
    );
    $stRighe->execute([$id]);
    $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);

    // Carica pianificazione per ogni riga
    $stPian = $pdo->prepare(
        'SELECT * FROM ordini_righe_pianificazione WHERE ordine_riga_id = ? ORDER BY data_consegna_prevista, id'
    );
    foreach ($righe as &$r) {
        $stPian->execute([$r['id']]);
        $r['pianificazione'] = $stPian->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($r);

    $stRicezioni = $pdo->prepare(
        'SELECT id, numero, stato, data_effettiva, ddt_numero, created_at
           FROM ricezioni_testata WHERE ordine_id = ? ORDER BY created_at DESC'
    );
    $stRicezioni->execute([$id]);

    return [
        'ordine'    => $ordine,
        'righe'     => $righe,
        'ricezioni' => $stRicezioni->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// GENERAZIONE ORDINE DA OFFERTA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Genera un ordine di acquisto a partire da un'offerta accettata.
 * Le righe offerta vengono copiate nelle righe ordine con prezzi cristallizzati.
 * Per ogni riga viene creata una tranche di pianificazione iniziale.
 *
 * @return int  ID del nuovo ordine
 * @throws RuntimeException se l'offerta non è accettata o esiste già un ordine
 */
function qk_ordine_da_offerta(PDO $pdo, int $offerta_id, int $utente_id): int
{
    $pdo->beginTransaction();
    try {
        $stOff = $pdo->prepare('SELECT * FROM offerte_testata WHERE id = ?');
        $stOff->execute([$offerta_id]);
        $offerta = $stOff->fetch(PDO::FETCH_ASSOC);
        if (!$offerta) throw new \RuntimeException('Offerta non trovata.');
        // Precondizione cross-entità (offerte_testata.stato usato come gate per
        // un'azione su ordini_testata, non una transizione di QUESTA entità):
        // lasciato come confronto diretto invece di TransitionGuard, stesso
        // principio già applicato in includes/purchases_receipts.php per il
        // controllo su ordini_testata dentro qk_ricezione_conferma(). 'accettata'
        // è terminale in qk_transizioni_offerte_fornitore() (nessuna transizione
        // in uscita): aggiungere un self-loop qui per riusare TransitionGuard
        // implicherebbe erroneamente che un'offerta accettata possa essere
        // "ri-accettata", che non è il significato di questo controllo.
        if ($offerta['stato'] !== 'accettata') {
            throw new \RuntimeException("Solo un'offerta accettata può generare un ordine.");
        }

        $esistente = $pdo->prepare('SELECT COUNT(*) FROM ordini_testata WHERE offerta_id = ?');
        $esistente->execute([$offerta_id]);
        if ((int)$esistente->fetchColumn() > 0) {
            throw new \RuntimeException('Esiste già un ordine per questa offerta.');
        }

        $riferimento_rfq_interno = '';
        $azienda_interna_id = null;
        if (!empty($offerta['rfq_id'])) {
            $stRfq = $pdo->prepare('SELECT riferimento_rfq_interno, azienda_interna_id FROM rfq_testata WHERE id = ?');
            $stRfq->execute([$offerta['rfq_id']]);
            $rfqRow = $stRfq->fetch(PDO::FETCH_ASSOC);
            if ($rfqRow) {
                $riferimento_rfq_interno = $rfqRow['riferimento_rfq_interno'] ?? '';
                $azienda_interna_id = $rfqRow['azienda_interna_id'] ?? null;
            }
        }

        // Clona indirizzo snapshot
        $snapAddrId = null;
        $consegna_via = null;
        $consegna_civico = null;
        $consegna_cap = null;
        $consegna_citta = null;
        $consegna_provincia = null;
        $consegna_nazione = 'IT';
        $consegna_presso = null;
        $consegna_referente = null;
        $consegna_telefono = null;
        $consegna_note = null;

        if ($offerta['indirizzo_consegna_id']) {
            $stAddr = $pdo->prepare('SELECT * FROM indirizzi_documenti WHERE id = ?');
            $stAddr->execute([(int)$offerta['indirizzo_consegna_id']]);
            $addr = $stAddr->fetch(PDO::FETCH_ASSOC);
            if ($addr) {
                unset($addr['id']);
                // ==============================================================================
                // [AI WARNING - ARCHITETTURA IBRIDA]
                // `indirizzo_consegna_id` punta a `indirizzi_documenti` (snapshot).
                // NON toccare questa logica: il database SQLite live è ora allineato per supportare 
                // questa Foreign Key strutturale come da specifica del progetto.
                // ==============================================================================
                $snapAddrId = qk_upsert_document_address($pdo, $addr, null);
                
                $consegna_via = $addr['via'] ?? null;
                $consegna_civico = $addr['civico'] ?? null;
                $consegna_cap = $addr['cap'] ?? null;
                $consegna_citta = $addr['citta'] ?? null;
                $consegna_provincia = $addr['provincia'] ?? null;
                $consegna_nazione = $addr['nazione'] ?? 'IT';
                $consegna_presso = $addr['presso'] ?? null;
                $consegna_referente = $addr['referente_consegna'] ?? null;
                $consegna_telefono = $addr['telefono_consegna'] ?? null;
                $consegna_note = $addr['note_consegna'] ?? null;
            }
        }

        // Clona contatto snapshot
        $snapContId = null;
        if ($offerta['contatto_id']) {
            $stCont = $pdo->prepare('SELECT * FROM contatti_documenti WHERE id = ?');
            $stCont->execute([(int)$offerta['contatto_id']]);
            $cont = $stCont->fetch(PDO::FETCH_ASSOC);
            if ($cont) {
                unset($cont['id']);
                $snapContId = qk_upsert_document_contact($pdo, $cont, null);
            }
        }

        $numero = qk_next_numero_acquisto($pdo, 'ordine');

        // Magazzino destinazione dell'ordine (Piano Multi-Magazzino §2.7/§3.4):
        // precompilato con il magazzino attivo di sessione alla creazione,
        // modificabile dal dettaglio ordine finché resta in stato non terminale
        // (PurchaseOrdersController::edit()).
        try {
            $magazzinoDestinazioneId = qk_magazzino_attivo_id($pdo);
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            $magazzinoDestinazioneId = null; // dataset senza magazzini seedati: non blocca la creazione ordine
        }

        // BUG PREESISTENTE corretto qui (segnalato e approvato dall'utente, 22/07/2026):
        // la VALUES sotto aveva solo 33 placeholder '?' invece dei 35 necessari (38 colonne
        // dichiarate - 3 valori letterali 'bozza'/DATE('now')/CURRENT_TIMESTAMP), causando
        // SEMPRE l'errore SQLite "table ordini_testata has 38 columns but 36 values were
        // supplied" — questa strada (genera ordine da offerta accettata) non aveva mai
        // funzionato. L'array PHP di execute() qui sotto era già corretto (35 valori, nello
        // stesso ordine delle 35 colonne non letterali, verificato uno per uno prima di
        // correggere) — mancavano solo 2 '?' nel testo della query, non un problema di dati.
        $pdo->prepare(
            "INSERT INTO ordini_testata
                (numero, offerta_id, rfq_id, fornitore_id, stato,
                 data_ordine, condizioni_pagamento, valuta,
                 note_fornitore, note_interne,
                 imponibile, totale_iva, totale,
                 creato_da, riferimento_rfq_interno, azienda_interna_id,
                 indirizzo_consegna_id,
                 consegna_via, consegna_civico, consegna_cap, consegna_citta,
                 consegna_provincia, consegna_nazione, consegna_presso,
                 consegna_referente, consegna_telefono, consegna_note,
                 contatto_id, contatto_nome, contatto_telefono, contatto_email,
                 fornitore_telefono, fornitore_email, listino_acquisto_id,
                 metodo_pagamento_id, resa_porto, magazzino_destinazione_id, created_at)
             VALUES (?,?,?,?,'bozza',DATE('now'),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)"
        )->execute([
            $numero,
            $offerta_id,
            $offerta['rfq_id'],
            $offerta['fornitore_id'],
            $offerta['condizioni_pagamento'],
            $offerta['valuta'],
            $offerta['note_fornitore'],
            '',
            $offerta['imponibile'],
            $offerta['totale_iva'],
            $offerta['totale'],
            $utente_id,
            $riferimento_rfq_interno,
            $azienda_interna_id,
            $snapAddrId,
            $consegna_via,
            $consegna_civico,
            $consegna_cap,
            $consegna_citta,
            $consegna_provincia,
            $consegna_nazione,
            $consegna_presso,
            $consegna_referente,
            $consegna_telefono,
            $consegna_note,
            $snapContId,
            $offerta['contatto_nome'],
            $offerta['contatto_telefono'],
            $offerta['contatto_email'],
            $offerta['fornitore_telefono'],
            $offerta['fornitore_email'],
            $offerta['listino_acquisto_id'],
            $offerta['metodo_pagamento_id'],
            $offerta['resa_porto'],
            $magazzinoDestinazioneId,
        ]);
        $ordine_id = (int) $pdo->lastInsertId();

        // Copia righe offerta → ordine
        $stRighe = $pdo->prepare('SELECT * FROM offerte_righe WHERE offerta_id = ? ORDER BY posizione_riga');
        $stRighe->execute([$offerta_id]);

        $stRiga = $pdo->prepare(
            "INSERT INTO ordini_righe
                (ordine_id, offerta_riga_id, posizione_riga, prodotto_id, descrizione, sku_fornitore,
                 quantita_ordinata, quantita_ricevuta, unita_misura_id,
                 prezzo_unitario, sconto_perc, iva_perc, imponibile_riga, iva_riga,
                 tolleranza_esubero_perc, data_consegna_prevista,
                 quantita_stock, system_unit_price_stock, unita_misura_stock_id, quantita_contenuto_snapshot,
                 num_factor_snapshot, den_factor_snapshot, note)
             VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?)"
        );
        $stPian = $pdo->prepare(
            "INSERT INTO ordini_righe_pianificazione
                (ordine_riga_id, riga_padre_id, data_consegna_prevista, quantita_pianificata, stato)
             VALUES (?,NULL,?,?,'attesa')"
        );

        foreach ($stRighe->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $prodotto_id = $r['prodotto_id'];
            $um_id = $r['unita_misura_id'];
            $qta = (string)$r['quantita_offerta'];

            $pu = (string)($r['prezzo_unitario'] ?? '0');

            // Conversione quantità e prezzo in UoM stock (centralizzata in qk_uom_converti_riga)
            $uomResult = qk_uom_converti_riga($pdo, $prodotto_id ? (int)$prodotto_id : null, $um_id ? (int)$um_id : null, $qta, $pu);
            $quantita_stock              = $uomResult['quantita_stock'];
            $system_unit_price_stock     = $uomResult['system_unit_price_stock'];
            $unita_misura_stock_id       = $uomResult['unita_misura_stock_id'];
            $quantita_contenuto_snapshot = $uomResult['quantita_contenuto_snapshot'];
            $num_factor_snapshot         = $uomResult['num_factor_snapshot'];
            $den_factor_snapshot         = $uomResult['den_factor_snapshot'];

            $stRiga->execute([
                $ordine_id, $r['id'], $r['posizione_riga'],
                $r['prodotto_id'], $r['descrizione'], $r['sku_fornitore'],
                $r['quantita_offerta'], $r['unita_misura_id'],
                $r['prezzo_unitario'], $r['sconto_perc'], $r['iva_perc'],
                $r['imponibile_riga'], $r['iva_riga'],
                $r['data_consegna_prevista'],
                $quantita_stock, $system_unit_price_stock, $unita_misura_stock_id, $quantita_contenuto_snapshot,
                $num_factor_snapshot, $den_factor_snapshot,
                $r['note'],
            ]);
            $riga_id = (int) $pdo->lastInsertId();
            $stPian->execute([$riga_id, $r['data_consegna_prevista'], $r['quantita_offerta']]);
        }

        $pdo->commit();
        return $ordine_id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TRANSIZIONI DI STATO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Approva un ordine (bozza | in_approvazione → approvato).
 */
function qk_ordine_approva(PDO $pdo, int $id, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $ordine = _ordine_carica($pdo, $id);
        (new \App\Services\TransitionGuard(qk_transizioni_ordini_fornitore(), 'ordine fornitore'))
            ->assicuraConMessaggio($ordine['stato'], 'approvato', "L'ordine non è in uno stato approvabile (stato attuale: {$ordine['stato']}).");
        $pdo->prepare(
            "UPDATE ordini_testata
                SET stato = 'approvato', approvato_il = CURRENT_TIMESTAMP, approvato_da = ?
              WHERE id = ?"
        )->execute([$utente_id, $id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Segna l'ordine come inviato al fornitore (approvato → inviato).
 * Aggiorna giacenze.quantita_in_arrivo per i prodotti dell'ordine.
 */
function qk_ordine_invia(PDO $pdo, int $id): void
{
    $manageTrans = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $manageTrans = true;
    }
    try {
        $ordine = _ordine_carica($pdo, $id);
        (new \App\Services\TransitionGuard(qk_transizioni_ordini_fornitore(), 'ordine fornitore'))
            ->assicuraConMessaggio($ordine['stato'], 'inviato', 'Solo un ordine approvato può essere inviato.');
        $pdo->prepare(
            "UPDATE ordini_testata SET stato = 'inviato', inviato_il = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$id]);

        _ordine_aggiorna_in_arrivo($pdo, $id, '+');
        if ($manageTrans) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($manageTrans) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Annulla un ordine.
 * Se era già inviato, decrementa quantita_in_arrivo nelle giacenze.
 */
function qk_ordine_annulla(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $ordine = _ordine_carica($pdo, $id);
        // REVISIONE Ciclo Acquisti §1.10 (deciso 14/07/2026): un ordine 'superato'
        // (sostituito da una revisione più recente via qk_ordine_crea_revisione())
        // è ormai solo storico e non deve più poter cambiare stato per nessun motivo,
        // esattamente come completato/annullato.
        (new \App\Services\TransitionGuard(qk_transizioni_ordini_fornitore(), 'ordine fornitore'))
            ->assicuraConMessaggio($ordine['stato'], 'annullato', "L'ordine non può essere annullato (stato: {$ordine['stato']}).");
        $pdo->prepare("UPDATE ordini_testata SET stato = 'annullato' WHERE id = ?")
           ->execute([$id]);

        if (in_array($ordine['stato'], ['inviato', 'parzialmente_ricevuto'], true)) {
            _ordine_aggiorna_in_arrivo($pdo, $id, '-');
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Aggiorna i campi di logistica/note dell'ordine modificabili dal form di dettaglio
 * (note fornitore/interne, riferimenti, data consegna prevista, magazzino destinazione).
 * Spostata da PurchaseOrdersController::edit() (era un UPDATE scritto a mano nel
 * controller) — vedi Revisione Ciclo Acquisti §3bis A.1.
 *
 * @param array{note_fornitore:string,note_interne:string,riferimento_fornitore:string,
 *              altri_riferimenti:string,data_consegna_prevista:?string,magazzino_destinazione_id:?int} $data
 */
function qk_ordine_aggiorna_note(PDO $pdo, int $id, array $data): void
{
    $pdo->prepare(
        'UPDATE ordini_testata SET
            note_fornitore = ?, note_interne = ?,
            riferimento_fornitore = ?, altri_riferimenti = ?,
            data_consegna_prevista = ?, magazzino_destinazione_id = ?
          WHERE id = ?'
    )->execute([
        $data['note_fornitore'],
        $data['note_interne'],
        $data['riferimento_fornitore'],
        $data['altri_riferimenti'],
        $data['data_consegna_prevista'],
        $data['magazzino_destinazione_id'],
        $id,
    ]);
}

/**
 * Aggiorna lo stato ricezione dell'ordine in base alle quantità ricevute.
 * Chiamato da acquisti_ricezioni.php dopo ogni conferma ricezione.
 *
 * @param 'parzialmente_ricevuto'|'completato' $statoNuovo
 */
function qk_ordine_aggiorna_stato_ricezione(PDO $pdo, int $ordine_id): void
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(quantita_ordinata),0) AS tot_ord,
                COALESCE(SUM(quantita_ricevuta),0)  AS tot_ric
           FROM ordini_righe WHERE ordine_id = ?'
    );
    $st->execute([$ordine_id]);
    $row    = $st->fetch(PDO::FETCH_ASSOC);
    $totOrd = (string)($row['tot_ord'] ?? '0');
    $totRic = (string)($row['tot_ric'] ?? '0');

    if (bccomp($totRic, '0', 3) <= 0) {
        $stato = 'inviato';
    } elseif (bccomp($totRic, $totOrd, 3) >= 0) {
        $stato = 'completato';
    } else {
        $stato = 'parzialmente_ricevuto';
    }

    $pdo->prepare('UPDATE ordini_testata SET stato = ? WHERE id = ?')
       ->execute([$stato, $ordine_id]);
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER PRIVATI
// ─────────────────────────────────────────────────────────────────────────────

function _ordine_carica(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM ordini_testata WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('Ordine non trovato.');
    return $row;
}

/**
 * Incrementa o decrementa giacenze.quantita_in_arrivo per tutte le righe ordine.
 *
 * REVISIONE Ciclo Acquisti §1.3 (deciso 14/07/2026, implementato 17/07/2026):
 * per ogni riga si usa il RESIDUO non ancora ricevuto (in unità di stock), non
 * la quantità totale originariamente ordinata. qk_ricezione_conferma() decrementa
 * già quantita_in_arrivo della quantità ricevuta a ogni conferma parziale — se
 * questa funzione sottraesse di nuovo l'intero totale in fase di annullamento,
 * si otterrebbe un doppio conteggio che può azzerare erroneamente la giacenza-
 * in-arrivo dovuta ad ALTRI ordini ancora aperti sullo stesso prodotto/magazzino.
 * Il fattore di conversione è costante per riga (non cambia tra ricezioni
 * parziali della stessa riga), quindi la proporzione ricevuta nell'unità
 * d'ordine si traduce esattamente nella stessa proporzione in unità di stock:
 * residuo_stock = quantita_stock * (quantita_ordinata - quantita_ricevuta) / quantita_ordinata.
 * Per i chiamanti '+' (qk_ordine_invia) e per qk_ordine_crea_revisione(),
 * quantita_ricevuta è sempre 0 in quel momento (verificato sui rispettivi
 * controlli di stato a monte), quindi il residuo coincide sempre col totale:
 * nessun cambio di comportamento per quei due casi, la correzione impatta solo
 * l'annullamento di un ordine 'parzialmente_ricevuto'.
 *
 * @param string $segno '+' quando l'ordine viene inviato, '-' quando viene annullato
 */
function _ordine_aggiorna_in_arrivo(PDO $pdo, int $ordine_id, string $segno): void
{
    // Magazzino destinazione dell'ordine (Piano Multi-Magazzino §3.4/§2.7); finché la
    // UI di creazione ordine non lo imposta esplicitamente (§3.8, task #13), si ricade
    // sul magazzino attivo, coerente con le altre approssimazioni interim del piano.
    $stOrd = $pdo->prepare('SELECT magazzino_destinazione_id FROM ordini_testata WHERE id = ?');
    $stOrd->execute([$ordine_id]);
    $magazzinoId = (int)($stOrd->fetchColumn() ?: 0);
    if ($magazzinoId <= 0) {
        $magazzinoId = qk_magazzino_attivo_id($pdo);
    }

    $righe = $pdo->prepare('SELECT prodotto_id, quantita_stock, quantita_ordinata, quantita_ricevuta FROM ordini_righe WHERE ordine_id = ?');
    $righe->execute([$ordine_id]);
    $op = $segno === '+' ? '+' : '-';
    $st = $pdo->prepare(
        "UPDATE giacenze
            SET quantita_in_arrivo = MAX(0, quantita_in_arrivo $op ?)
          WHERE prodotto_id = ? AND magazzino_id = ?"
    );
    $stIns = $pdo->prepare(
        "INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
         VALUES (?, ?, 0, 0, MAX(0, ?), 0, CURRENT_TIMESTAMP)"
    );
    foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qtaStock     = (string)($r['quantita_stock'] ?? '0');
        $qtaOrdinata  = (string)($r['quantita_ordinata'] ?? '0');
        $qtaRicevuta  = (string)($r['quantita_ricevuta'] ?? '0');

        if (bccomp($qtaOrdinata, '0', 3) > 0) {
            $diff = bcsub($qtaOrdinata, $qtaRicevuta, 3);
            if (bccomp($diff, '0', 3) <= 0) {
                $qta = '0.000';
            } else {
                $moltiplicato = bcmul($qtaStock, $diff, 6);
                $qta = bcdiv($moltiplicato, $qtaOrdinata, 3);
            }
        } else {
            $qta = $qtaStock;
        }

        $st->execute([$qta, $r['prodotto_id'], $magazzinoId]);
        if ($st->rowCount() === 0) {
            $stIns->execute([$r['prodotto_id'], $magazzinoId, $op === '+' ? $qta : 0]);
        }
    }
}

/**
 * Crea una nuova revisione dell'ordine d'acquisto (Order Amendment).
 * Clona l'ordine incrementando la colonna versione, clona le righe, duplica gli snapshot degli indirizzi/contatti,
 * e imposta lo stato dell'ordine precedente a 'superato'.
 *
 * @param PDO $pdo Istanza di connessione al database
 * @param int $ordine_id ID dell'ordine di acquisto da revisionare
 * @return int ID dell'ordine appena clonato (nuova revisione in bozza)
 */
function qk_ordine_crea_revisione(PDO $pdo, int $ordine_id): int
{
    $pdo->beginTransaction();
    try {
        $ordine = _ordine_carica($pdo, $ordine_id);

        // Verifica lo stato: solo ordini già inviati/confermati possono subire amendment
        (new \App\Services\TransitionGuard(qk_transizioni_ordini_fornitore(), 'ordine fornitore'))
            ->assicuraConMessaggio($ordine['stato'], 'superato', "È possibile revisionare solo ordini in stato 'inviato'.");

        // REVISIONE Ciclo Acquisti, sez. "Stati ordine/ciclo di vita" (deciso 14/07/2026,
        // rivisto e implementato 17/07/2026): il solo controllo sullo stato dell'ordine
        // non basta. qk_ricezione_apri() crea una ricezioni_testata in 'bozza' collegata
        // all'ordine SENZA aggiornare quantita_ricevuta né lo stato dell'ordine (li tocca
        // solo qk_ricezione_conferma()) — quindi è possibile aprire una ricezione bozza
        // mentre l'ordine è ancora 'inviato', poi revisionarlo (ancora permesso), e infine
        // confermare la ricezione rimasta agganciata alla versione ormai 'superato': i dati
        // finirebbero sulla versione vecchia, invisibili in quella nuova (davvero attiva) —
        // "ricezione orfana". Best practice dei grandi ERP (SAP: "se esistono documenti a
        // valle, meglio creare un nuovo documento invece di modificare quello esistente";
        // Odoo: mai aggiornare un PO automaticamente durante una ricezione, per non rompere
        // tracciabilità/responsabilità) — qui si blocca la causa alla radice: niente
        // revisione finché esiste una ricezione ancora aperta su questo ordine.
        $stRicApert = $pdo->prepare("SELECT COUNT(*) FROM ricezioni_testata WHERE ordine_id = ? AND stato = 'bozza'");
        $stRicApert->execute([$ordine_id]);
        if ((int)$stRicApert->fetchColumn() > 0) {
            throw new \RuntimeException('Impossibile revisionare: esiste una ricezione ancora in bozza collegata a questo ordine. Completa o annulla quella ricezione prima di creare una nuova revisione.');
        }

        // 1. Duplica Snapshot Indirizzo Consegna se presente
        $nuovoIndirizzoId = null;
        if (!empty($ordine['indirizzo_consegna_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM indirizzi_documenti WHERE id = ?");
            $stmt->execute([$ordine['indirizzo_consegna_id']]);
            $addr = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($addr) {
                unset($addr['id']);
                $columns = implode(', ', array_keys($addr));
                $placeholders = implode(', ', array_fill(0, count($addr), '?'));
                $stmtIns = $pdo->prepare("INSERT INTO indirizzi_documenti ($columns) VALUES ($placeholders)");
                $stmtIns->execute(array_values($addr));
                $nuovoIndirizzoId = (int)$pdo->lastInsertId();
            }
        }

        // 2. Duplica Snapshot Contatto Referente se presente
        $nuovoContattoId = null;
        if (!empty($ordine['contatto_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM contatti_documenti WHERE id = ?");
            $stmt->execute([$ordine['contatto_id']]);
            $cont = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cont) {
                unset($cont['id']);
                $columns = implode(', ', array_keys($cont));
                $placeholders = implode(', ', array_fill(0, count($cont), '?'));
                $stmtIns = $pdo->prepare("INSERT INTO contatti_documenti ($columns) VALUES ($placeholders)");
                $stmtIns->execute(array_values($cont));
                $nuovoContattoId = (int)$pdo->lastInsertId();
            }
        }

        // 3. Genera nuovo numero ordine incrementando la versione
        // Nel sistema Quack gli ordini seguono una logica a codice o semplicemente a numero
        // Es: ORD/2026/0001 v2
        $nuovaVersione = (int)($ordine['versione'] ?? 1) + 1;
        $baseNumero = preg_replace('/ v\d+$/', '', $ordine['numero']);
        $nuovoNumero = $baseNumero . ' v' . $nuovaVersione;

        // 4. Inserisce la testata del nuovo ordine (in stato 'bozza')
        $stmtInsOrd = $pdo->prepare('INSERT INTO ordini_testata (
            numero, offerta_id, rfq_id, fornitore_id, stato, data_ordine, data_consegna_prevista,
            indirizzo_consegna_id, incoterm_codice, incoterm_luogo, consegna_via, consegna_civico,
            consegna_cap, consegna_citta, consegna_provincia, consegna_nazione, consegna_presso,
            consegna_referente, consegna_telefono, consegna_note, condizioni_pagamento, valuta,
            riferimento_fornitore, altri_riferimenti, note_fornitore, note_interne, imponibile,
            totale_iva, totale, creato_da, contatto_id, contatto_nome, contatto_telefono,
            contatto_email, fornitore_telefono, fornitore_email, listino_acquisto_id,
            metodo_pagamento_id, resa_porto, versione, ordine_padre_id, magazzino_destinazione_id
        ) VALUES (?, ?, ?, ?, \'bozza\', CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        
        $stmtInsOrd->execute([
            $nuovoNumero,
            $ordine['offerta_id'],
            $ordine['rfq_id'],
            $ordine['fornitore_id'],
            $ordine['data_consegna_prevista'],
            $nuovoIndirizzoId,
            $ordine['incoterm_codice'],
            $ordine['incoterm_luogo'],
            $ordine['consegna_via'],
            $ordine['consegna_civico'],
            $ordine['consegna_cap'],
            $ordine['consegna_citta'],
            $ordine['consegna_provincia'],
            $ordine['consegna_nazione'],
            $ordine['consegna_presso'],
            $ordine['consegna_referente'],
            $ordine['consegna_telefono'],
            $ordine['consegna_note'],
            $ordine['condizioni_pagamento'],
            $ordine['valuta'],
            $ordine['riferimento_fornitore'],
            $ordine['altri_riferimenti'],
            $ordine['note_fornitore'],
            $ordine['note_interne'],
            $ordine['imponibile'],
            $ordine['totale_iva'],
            $ordine['totale'],
            $ordine['creato_da'],
            $nuovoContattoId,
            $ordine['contatto_nome'],
            $ordine['contatto_telefono'],
            $ordine['contatto_email'],
            $ordine['fornitore_telefono'],
            $ordine['fornitore_email'],
            $ordine['listino_acquisto_id'],
            $ordine['metodo_pagamento_id'],
            $ordine['resa_porto'],
            $nuovaVersione,
            $ordine_id,
            $ordine['magazzino_destinazione_id'] ?? null,
        ]);

        $nuovoOrdineId = (int)$pdo->lastInsertId();

        // 5. Duplica le righe dell'ordine
        $stmtRighe = $pdo->prepare("SELECT * FROM ordini_righe WHERE ordine_id = ?");
        $stmtRighe->execute([$ordine_id]);
        $righe = $stmtRighe->fetchAll(PDO::FETCH_ASSOC);

        $stmtInsRiga = $pdo->prepare('INSERT INTO ordini_righe (
            ordine_id, offerta_riga_id, posizione_riga, prodotto_id, descrizione, sku_fornitore,
            quantita_ordinata, quantita_ricevuta, unita_misura_id,
            prezzo_unitario, sconto_perc, iva_perc, imponibile_riga, iva_riga,
            tolleranza_esubero_perc, data_consegna_prevista, quantita_stock, unita_misura_stock_id, quantita_contenuto_snapshot,
            num_factor_snapshot, den_factor_snapshot, note
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($righe as $riga) {
            $stmtInsRiga->execute([
                $nuovoOrdineId,
                $riga['offerta_riga_id'],
                $riga['posizione_riga'],
                $riga['prodotto_id'],
                $riga['descrizione'],
                $riga['sku_fornitore'],
                $riga['quantita_ordinata'],
                $riga['unita_misura_id'],
                $riga['prezzo_unitario'],
                $riga['sconto_perc'],
                $riga['iva_perc'],
                $riga['imponibile_riga'],
                $riga['iva_riga'],
                $riga['tolleranza_esubero_perc'],
                $riga['data_consegna_prevista'],
                $riga['quantita_stock'],
                $riga['unita_misura_stock_id'],
                $riga['quantita_contenuto_snapshot'],
                $riga['num_factor_snapshot'],
                $riga['den_factor_snapshot'],
                $riga['note']
            ]);
        }

        // 6. Imposta lo stato dell'ordine precedente a 'superato'
        // Riduciamo la quantita_in_arrivo dell'ordine precedente poiché il nuovo parte da bozza
        _ordine_aggiorna_in_arrivo($pdo, $ordine_id, '-');
        
        $stmtUpdOld = $pdo->prepare("UPDATE ordini_testata SET stato = 'superato' WHERE id = ?");
        $stmtUpdOld->execute([$ordine_id]);

        $pdo->commit();
        return $nuovoOrdineId;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Verifica se l'ordine ha tutte le righe con quantità e prezzo maggiori di zero.
 * (Estratto dal Controller)
 */
function qk_ordine_check_righe_valide(PDO $pdo, int $ordine_id): bool
{
    $stCheck = $pdo->prepare("SELECT COUNT(*) FROM ordini_righe WHERE ordine_id = ? AND (prezzo_unitario <= 0 OR quantita_ordinata <= 0)");
    $stCheck->execute([$ordine_id]);
    return ((int)$stCheck->fetchColumn() === 0);
}

/**
 * Verifica se esiste una ricezione in bozza per l'ordine.
 * (Estratto dal Controller)
 */
function qk_ordine_has_ricezione_bozza(PDO $pdo, int $ordine_id): bool
{
    $stCheckRec = $pdo->prepare("SELECT id FROM ricezioni_testata WHERE ordine_id = ? AND stato = 'bozza' LIMIT 1");
    $stCheckRec->execute([$ordine_id]);
    return (bool)$stCheckRec->fetch();
}

/**
 * Segna l'ordine come fatturato.
 * (Estratto dal Controller)
 */
function qk_ordine_segna_fatturato(PDO $pdo, int $ordine_id): void
{
    $pdo->prepare("UPDATE ordini_testata SET stato_fatturazione = 'fatturato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$ordine_id]);
}

/**
 * Segna l'ordine come da fatturare.
 * (Estratto dal Controller)
 */
function qk_ordine_segna_da_fatturare(PDO $pdo, int $ordine_id): void
{
    $pdo->prepare("UPDATE ordini_testata SET stato_fatturazione = 'da_fatturare', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$ordine_id]);
}

