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
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                off.numero AS offerta_numero,
                r.numero   AS rfq_numero,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM ordini_testata ot
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

        $numero = qk_next_numero_acquisto($pdo, 'ordine');

        $pdo->prepare(
            "INSERT INTO ordini_testata
                (numero, offerta_id, rfq_id, fornitore_id, stato,
                 data_ordine, condizioni_pagamento, valuta,
                 note_fornitore, note_interne,
                 imponibile, totale_iva, totale,
                 creato_da, riferimento_rfq_interno, azienda_interna_id,
                 contatto_id, contatto_nome, contatto_telefono, contatto_email,
                 fornitore_telefono, fornitore_email, listino_acquisto_id,
                 metodo_pagamento_id, resa_porto, created_at)
             VALUES (?,?,?,?,'bozza',DATE('now'),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)"
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
            $offerta['contatto_id'],
            $offerta['contatto_nome'],
            $offerta['contatto_telefono'],
            $offerta['contatto_email'],
            $offerta['fornitore_telefono'],
            $offerta['fornitore_email'],
            $offerta['listino_acquisto_id'],
            $offerta['metodo_pagamento_id'],
            $offerta['resa_porto'],
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
                 tolleranza_esubero_perc, data_consegna_prevista, quantita_stock, unita_misura_stock_id, note)
             VALUES (?,?,?,?,?,?,?,0,?,?,?,?,?,?,0,?,?,?,?)"
        );
        $stPian = $pdo->prepare(
            "INSERT INTO ordini_righe_pianificazione
                (ordine_riga_id, riga_padre_id, data_consegna_prevista, quantita_pianificata, stato)
             VALUES (?,NULL,?,?,'attesa')"
        );

        foreach ($stRighe->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $prodotto_id = $r['prodotto_id'];
            $um_id = $r['unita_misura_id'];
            $qta = (float)$r['quantita_offerta'];

            // Convert to stock UoM
            $quantita_stock = $qta;
            $unita_misura_stock_id = $um_id;
            if ($prodotto_id && $um_id) {
                $stProdUm = $pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = ?');
                $stProdUm->execute([$prodotto_id]);
                $prodUm = $stProdUm->fetch();
                if ($prodUm) {
                    $unita_misura_stock_id = (int)$prodUm['unita_misura_stock_id'];
                    $content_um_id = $prodUm['unita_contenuto_id'] ? (int)$prodUm['unita_contenuto_id'] : null;
                    $factor = qk_prodotto_get_conversion_factor($pdo, (int)$prodotto_id);
                    if ((int)$um_id === $unita_misura_stock_id) {
                        $quantita_stock = $qta;
                    } elseif ($content_um_id !== null && (int)$um_id === $content_um_id && $factor > 0) {
                        $quantita_stock = round($qta / $factor, 3);
                    }
                }
            }

            $stRiga->execute([
                $ordine_id, $r['id'], $r['posizione_riga'],
                $r['prodotto_id'], $r['descrizione'], $r['sku_fornitore'],
                $r['quantita_offerta'], $r['unita_misura_id'],
                $r['prezzo_unitario'], $r['sconto_perc'], $r['iva_perc'],
                $r['imponibile_riga'], $r['iva_riga'],
                $r['data_consegna_prevista'],
                $quantita_stock, $unita_misura_stock_id,
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
        if (!in_array($ordine['stato'], ['bozza', 'in_approvazione'], true)) {
            throw new \RuntimeException("L'ordine non è in uno stato approvabile (stato attuale: {$ordine['stato']}).");
        }
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
    $pdo->beginTransaction();
    try {
        $ordine = _ordine_carica($pdo, $id);
        if ($ordine['stato'] !== 'approvato') {
            throw new \RuntimeException('Solo un ordine approvato può essere inviato.');
        }
        $pdo->prepare(
            "UPDATE ordini_testata SET stato = 'inviato', inviato_il = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$id]);

        _ordine_aggiorna_in_arrivo($pdo, $id, '+');
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
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
        if (in_array($ordine['stato'], ['completato', 'annullato'], true)) {
            throw new \RuntimeException("L'ordine non può essere annullato (stato: {$ordine['stato']}).");
        }
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
    $totOrd = (float)$row['tot_ord'];
    $totRic = (float)$row['tot_ric'];

    if ($totRic <= 0) {
        $stato = 'inviato';
    } elseif ($totRic >= $totOrd) {
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
 * @param string $segno '+' quando l'ordine viene inviato, '-' quando viene annullato
 */
function _ordine_aggiorna_in_arrivo(PDO $pdo, int $ordine_id, string $segno): void
{
    $righe = $pdo->prepare('SELECT prodotto_id, quantita_stock FROM ordini_righe WHERE ordine_id = ?');
    $righe->execute([$ordine_id]);
    $op = $segno === '+' ? '+' : '-';
    $st = $pdo->prepare(
        "UPDATE giacenze
            SET quantita_in_arrivo = MAX(0, quantita_in_arrivo $op ?)
          WHERE prodotto_id = ?"
    );
    foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st->execute([$r['quantita_stock'] ?? $r['quantita_ordinata'], $r['prodotto_id']]);
    }
}
