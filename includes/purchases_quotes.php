<?php
declare(strict_types=1);
/**
 * includes/purchases_quotes.php
 * Funzioni DB per le Offerte (preventivi ricevuti dai fornitori).
 *
 * Ciclo di vita: in_valutazione → accettata | rifiutata
 * Dipende da: includes/purchases_common.php
 */

require_once __DIR__ . '/purchases_common.php';
require_once __DIR__ . '/transizioni_stati.php';
require_once __DIR__ . '/../app/Services/TransitionGuard.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco offerte con filtri opzionali.
 *
 * @param int $rfq_id  Filtra per RFQ specifica; 0 = tutte
 */
/** @internal */
function _qk_offerte_where(string $search, string $stato, string $dal, string $al, int $rfq_id): array
{
    $where = ['1=1']; $params = [];
    if ($search !== '') {
        $where[] = '(o.numero LIKE ? OR COALESCE(cf.ragione_sociale, cf.nome) LIKE ? OR r.numero LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($stato  !== '') { $where[] = 'o.stato = ?';       $params[] = $stato; }
    if ($dal    !== '') { $where[] = 'o.data_offerta >= ?'; $params[] = $dal; }
    if ($al     !== '') { $where[] = 'o.data_offerta <= ?'; $params[] = $al; }
    if ($rfq_id > 0)   { $where[] = 'o.rfq_id = ?';       $params[] = $rfq_id; }
    return [$where, $params];
}

function qk_offerte_count(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = '', int $rfq_id = 0): int
{
    [$where, $params] = _qk_offerte_where($search, $stato, $dal, $al, $rfq_id);
    $st = $pdo->prepare('SELECT COUNT(*) FROM offerte_testata o LEFT JOIN clienti_fornitori cf ON cf.id = o.fornitore_id LEFT JOIN rfq_testata r ON r.id = o.rfq_id WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function qk_offerte_list(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = '', int $rfq_id = 0, string $sort = 'data', string $dir = 'desc', int $limit = 50, int $offset = 0): array
{
    $sortMap = [
        'data'      => 'o.data_offerta',
        'numero'    => 'o.numero',
        'fornitore' => 'fornitore_nome',
        'stato'     => 'o.stato',
        'scadenza'  => 'o.data_scadenza_offerta',
        'totale'    => 'o.totale',
    ];
    $orderCol = $sortMap[$sort] ?? 'o.data_offerta';
    $orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

    [$where, $params] = _qk_offerte_where($search, $stato, $dal, $al, $rfq_id);
    $params[] = $limit;
    $params[] = $offset;

    $st = $pdo->prepare(
        'SELECT o.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                r.numero AS rfq_numero
           FROM offerte_testata o
      LEFT JOIN clienti_fornitori cf ON cf.id = o.fornitore_id
      LEFT JOIN rfq_testata r        ON r.id  = o.rfq_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY ' . $orderCol . ' ' . $orderDir . ', o.id DESC
          LIMIT ? OFFSET ?'
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dettaglio offerta: testata + righe (con riferimento alla riga RFQ corrispondente).
 *
 * @return array{offerta:array, righe:array}|null
 */
function qk_offerta_detail(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT o.*,
                snap_addr.indirizzo_master_id AS indirizzo_consegna_master_id,
                snap_addr.personalizzato AS indirizzo_consegna_personalizzato,
                snap_addr.hash_originale AS indirizzo_consegna_hash_originale,
                snap_cont.contatto_master_id AS contatto_master_id,
                snap_cont.personalizzato AS contatto_personalizzato,
                snap_cont.hash_originale AS contatto_hash_originale,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                r.numero AS rfq_numero,
                r.riferimento_rfq_interno AS rfq_riferimento_interno,
                r.consegna_via,
                r.consegna_civico,
                r.consegna_cap,
                r.consegna_citta,
                r.consegna_provincia,
                r.consegna_nazione,
                r.consegna_presso,
                r.consegna_referente,
                r.consegna_telefono,
                r.consegna_note,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM offerte_testata o
      LEFT JOIN indirizzi_documenti snap_addr ON snap_addr.id = o.indirizzo_consegna_id
      LEFT JOIN contatti_documenti snap_cont ON snap_cont.id = o.contatto_id
      LEFT JOIN clienti_fornitori cf ON cf.id = o.fornitore_id
      LEFT JOIN rfq_testata r ON r.id = o.rfq_id
      LEFT JOIN metodi_pagamento mp ON mp.id = o.metodo_pagamento_id
      LEFT JOIN listini_acquisto la ON la.id = o.listino_acquisto_id
          WHERE o.id = ?'
    );
    $st->execute([$id]);
    $offerta = $st->fetch(PDO::FETCH_ASSOC);
    if (!$offerta) return null;

    $stRighe = $pdo->prepare(
        'SELECT orr.*, p.nome AS prodotto_nome, p.sku_interno, um.codice AS uom,
                rr.quantita_richiesta AS qta_richiesta_rfq,
                rr.prodotto_id AS req_prodotto_id,
                p_req.nome AS req_prodotto_nome, p_req.sku_interno AS req_sku_interno
           FROM offerte_righe orr
           JOIN prodotti p  ON p.id  = orr.prodotto_id
      LEFT JOIN unita_misura um ON um.id = orr.unita_misura_id
      LEFT JOIN rfq_righe rr   ON rr.id = orr.rfq_riga_id
      LEFT JOIN prodotti p_req ON p_req.id = rr.prodotto_id
          WHERE orr.offerta_id = ?
          ORDER BY orr.posizione_riga'
    );
    $stRighe->execute([$id]);

    return [
        'offerta' => $offerta,
        'righe'   => $stRighe->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// SCRITTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crea o aggiorna un'offerta con le sue righe.
 * I totali di riga (imponibile, iva) vengono calcolati automaticamente.
 *
 * @param int $id  0 = nuovo inserimento
 * @return int  ID dell'offerta salvata
 */
function qk_offerta_save(PDO $pdo, array $v, array $righe, int $utente_id, int $id = 0): int
{
    $pdo->beginTransaction();
    try {
        // Prepara i payload per gli snapshot del contatto
        $nomeParts = explode(' ', trim((string)($v['contatto_nome'] ?? '')), 2);
        $cNome = $nomeParts[0] ?? '';
        $cCognome = $nomeParts[1] ?? '';
        $contPayload = [
            'contatto_master_id'  => $v['contatto_id'] ?? null,
            'nome'                => $cNome,
            'cognome'             => $cCognome,
            'email'               => $v['contatto_email'] ?? null,
            'telefono'            => $v['contatto_telefono'] ?? null,
            'cellulare'           => null
        ];

        $existingRow = null;
        if ($id > 0) {
            $stCheck = $pdo->prepare('SELECT stato, indirizzo_consegna_id, contatto_id FROM offerte_testata WHERE id = ?');
            $stCheck->execute([$id]);
            $existingRow = $stCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                (new \App\Services\TransitionGuard(qk_transizioni_offerte_fornitore(), 'offerta'))
                    ->assicuraConMessaggio($existingRow['stato'], 'in_valutazione', 'Un preventivo già accettato o rifiutato non è modificabile.');
            }
        }

        // Prepara i payload per gli snapshot dell'indirizzo e del contatto
        $addrPayload = [
            'indirizzo_master_id' => $v['indirizzo_consegna_master_id'] ?? $v['indirizzo_consegna_id'] ?? null,
            'nome_sede'           => $v['consegna_presso'] ?? null,
            'presso'              => $v['consegna_presso'] ?? null,
            'via'                 => $v['consegna_via'] ?? '',
            'civico'              => $v['consegna_civico'] ?? null,
            'cap'                 => $v['consegna_cap'] ?? '',
            'citta'               => $v['consegna_citta'] ?? '',
            'provincia'           => $v['consegna_provincia'] ?? null,
            'nazione'             => $v['consegna_nazione'] ?? 'IT',
            'referente_consegna'  => $v['consegna_referente'] ?? null,
            'telefono_consegna'   => $v['consegna_telefono'] ?? null,
            'note_consegna'       => $v['consegna_note'] ?? null
        ];

        // Se stiamo creando da zero ed è collegata a una RFQ e non è stato passato un indirizzo modificato ad-hoc
        $snapAddrId = $existingRow ? (int)$existingRow['indirizzo_consegna_id'] : null;
        if ($id === 0 && empty($v['consegna_via']) && !empty($v['rfq_id'])) {
            $stRfq = $pdo->prepare('SELECT indirizzo_consegna_id FROM rfq_testata WHERE id = ?');
            $stRfq->execute([(int)$v['rfq_id']]);
            $rfqSnapAddrId = $stRfq->fetchColumn();
            if ($rfqSnapAddrId) {
                // Clona lo snapshot dell'indirizzo RFQ
                $stAddr = $pdo->prepare('SELECT * FROM indirizzi_documenti WHERE id = ?');
                $stAddr->execute([(int)$rfqSnapAddrId]);
                $addrRow = $stAddr->fetch(PDO::FETCH_ASSOC);
                if ($addrRow) {
                    unset($addrRow['id']);
                    // ==============================================================================
                    // [AI WARNING - ARCHITETTURA IBRIDA]
                    // Le FK puntano agli snapshot storici. Il DB live è stato allineato. NON toccare.
                    // ==============================================================================
                    $snapAddrId = qk_upsert_document_address($pdo, $addrRow, null);
                }
            }
        } else {
            // Altrimenti eseguiamo l'upsert con il payload corrente
            $snapAddrId = qk_upsert_document_address($pdo, $addrPayload, $existingRow ? (int)$existingRow['indirizzo_consegna_id'] : null);
        }

        // Snapshot per il contatto
        $snapContId = qk_upsert_document_contact($pdo, $contPayload, $existingRow ? (int)$existingRow['contatto_id'] : null);

        if ($id === 0) {
            $numero = qk_next_numero_acquisto($pdo, 'offerta');
            $st = $pdo->prepare(
                'INSERT INTO offerte_testata
                    (numero, rfq_id, fornitore_id, stato,
                     data_offerta, data_scadenza_offerta,
                     condizioni_pagamento, valuta, riferimento_fornitore,
                     note_fornitore, note_interne, allegato_path,
                     indirizzo_consegna_id,
                     contatto_id, contatto_nome, contatto_telefono, contatto_email,
                     fornitore_telefono, fornitore_email, listino_acquisto_id,
                     metodo_pagamento_id, resa_porto, creato_da, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
            );
            $st->execute([
                $numero,
                ($v['rfq_id'] ?? '') !== '' ? (int)$v['rfq_id'] : null,
                (int)$v['fornitore_id'],
                $v['stato'] ?? 'in_valutazione',
                $v['data_offerta'] ?? date('Y-m-d'),
                ($v['data_scadenza_offerta'] ?? '') !== '' ? $v['data_scadenza_offerta'] : null,
                $v['condizioni_pagamento'] ?? '',
                $v['valuta'] ?? 'EUR',
                $v['riferimento_fornitore'] ?? '',
                $v['note_fornitore'] ?? '',
                $v['note_interne'] ?? '',
                $v['allegato_path'] ?? null,
                $snapAddrId,
                $snapContId,
                $v['contatto_nome'] ?? null,
                $v['contatto_telefono'] ?? null,
                $v['contatto_email'] ?? null,
                $v['fornitore_telefono'] ?? null,
                $v['fornitore_email'] ?? null,
                ($v['listino_acquisto_id'] ?? '') !== '' ? (int)$v['listino_acquisto_id'] : null,
                ($v['metodo_pagamento_id'] ?? '') !== '' ? (int)$v['metodo_pagamento_id'] : null,
                ($v['resa_porto'] ?? '') !== '' ? $v['resa_porto'] : null,
                $utente_id,
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE offerte_testata SET
                    rfq_id = ?, fornitore_id = ?, stato = ?,
                    data_offerta = ?, data_scadenza_offerta = ?,
                    condizioni_pagamento = ?, valuta = ?, riferimento_fornitore = ?,
                    note_fornitore = ?, note_interne = ?, allegato_path = ?,
                    indirizzo_consegna_id = ?,
                    contatto_id = ?, contatto_nome = ?, contatto_telefono = ?, contatto_email = ?,
                    fornitore_telefono = ?, fornitore_email = ?, listino_acquisto_id = ?,
                    metodo_pagamento_id = ?, resa_porto = ?
                  WHERE id = ?'
            )->execute([
                ($v['rfq_id'] ?? '') !== '' ? (int)$v['rfq_id'] : null,
                (int)$v['fornitore_id'],
                $v['stato'] ?? 'in_valutazione',
                $v['data_offerta'] ?? date('Y-m-d'),
                ($v['data_scadenza_offerta'] ?? '') !== '' ? $v['data_scadenza_offerta'] : null,
                $v['condizioni_pagamento'] ?? '',
                $v['valuta'] ?? 'EUR',
                $v['riferimento_fornitore'] ?? '',
                $v['note_fornitore'] ?? '',
                $v['note_interne'] ?? '',
                $v['allegato_path'] ?? null,
                $snapAddrId,
                $snapContId,
                $v['contatto_nome'] ?? null,
                $v['contatto_telefono'] ?? null,
                $v['contatto_email'] ?? null,
                $v['fornitore_telefono'] ?? null,
                $v['fornitore_email'] ?? null,
                ($v['listino_acquisto_id'] ?? '') !== '' ? (int)$v['listino_acquisto_id'] : null,
                ($v['metodo_pagamento_id'] ?? '') !== '' ? (int)$v['metodo_pagamento_id'] : null,
                ($v['resa_porto'] ?? '') !== '' ? $v['resa_porto'] : null,
                $id,
            ]);
            $pdo->prepare('DELETE FROM offerte_righe WHERE offerta_id = ?')->execute([$id]);
        }

        _offerta_insert_righe($pdo, $id, $righe);
        _offerta_ricalcola_totali($pdo, $id);

        $pdo->commit();
        return $id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TRANSIZIONI DI STATO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Accetta un'offerta.
 * Effetti collaterali:
 *   - Le altre offerte in_valutazione sulla stessa RFQ vengono rifiutate.
 *   - La RFQ di riferimento viene chiusa.
 */
function qk_offerta_accetta(PDO $pdo, int $id, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $offerta = _offerta_check_valutabile($pdo, $id);

        $pdo->prepare(
            "UPDATE offerte_testata SET stato = 'accettata',
             valutata_il = CURRENT_TIMESTAMP, valutata_da = ? WHERE id = ?"
        )->execute([$utente_id, $id]);

        if ($offerta['rfq_id']) {
            // Rifiuta le altre offerte sulla stessa RFQ
            $pdo->prepare(
                "UPDATE offerte_testata
                    SET stato = 'rifiutata', valutata_il = CURRENT_TIMESTAMP, valutata_da = ?
                  WHERE rfq_id = ? AND id != ? AND stato = 'in_valutazione'"
            )->execute([$utente_id, $offerta['rfq_id'], $id]);

            // Chiude la RFQ con esito "offerta accettata" — distinto dalla
            // chiusura manuale senza esito (vedi qk_rfq_chiudi()), così lo
            // stato 'chiusa' non è più ambiguo su come/perché si è arrivati lì.
            //
            // Guardia aggiunta in Tappa 5 (PIANO - Motore Unificato di
            // Transizioni di Stato): prima non esisteva ALCUN controllo sullo
            // stato della RFQ qui. In pratica non dovrebbe mai poter scattare
            // su una RFQ non più 'inviata', perché _offerta_check_valutabile()
            // sopra blocca già il caso normale (un'offerta resta 'in_valutazione'
            // solo finché la sua RFQ non viene chiusa, e qk_rfq_chiudi() rifiuta
            // già tutte le offerte in_valutazione quando chiude) — ma è comunque
            // una seconda linea di difesa, stesso principio già applicato in
            // includes/purchases_receipts.php per il controllo su ordini_testata
            // dentro qk_ricezione_conferma().
            $stRfqStato = $pdo->prepare('SELECT stato FROM rfq_testata WHERE id = ?');
            $stRfqStato->execute([$offerta['rfq_id']]);
            $statoRfqAttuale = $stRfqStato->fetchColumn();
            if ($statoRfqAttuale !== false) {
                (new \App\Services\TransitionGuard(qk_transizioni_rfq(), 'RFQ'))
                    ->assicuraConMessaggio(
                        (string)$statoRfqAttuale,
                        'chiusa',
                        "Impossibile chiudere la RFQ collegata: non è più nello stato corretto (stato attuale: {$statoRfqAttuale})."
                    );
            }
            $pdo->prepare("UPDATE rfq_testata SET stato = 'chiusa', esito_chiusura = 'offerta_accettata' WHERE id = ?")
               ->execute([$offerta['rfq_id']]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_offerta_rifiuta(PDO $pdo, int $id, int $utente_id, string $motivo = ''): void
{
    $pdo->beginTransaction();
    try {
        _offerta_check_valutabile($pdo, $id);
        $pdo->prepare(
            "UPDATE offerte_testata SET stato = 'rifiutata',
             valutata_il = CURRENT_TIMESTAMP, valutata_da = ?, motivo_rifiuto = ? WHERE id = ?"
        )->execute([$utente_id, $motivo, $id]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER PRIVATI
// ─────────────────────────────────────────────────────────────────────────────

/** Carica l'offerta e verifica che sia in stato in_valutazione. */
function _offerta_check_valutabile(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM offerte_testata WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('Offerta non trovata.');
    (new \App\Services\TransitionGuard(qk_transizioni_offerte_fornitore(), 'offerta'))
        ->assicuraConMessaggio($row['stato'], 'in_valutazione', "L'offerta è già stata valutata (stato: {$row['stato']}).");
    return $row;
}

/** Inserisce le righe di un'offerta calcolando i totali di riga. */
function _offerta_insert_righe(PDO $pdo, int $offerta_id, array $righe): void
{
    $st = $pdo->prepare(
        'INSERT INTO offerte_righe
            (offerta_id, rfq_riga_id, posizione_riga, prodotto_id, descrizione, sku_fornitore,
             quantita_offerta, unita_misura_id,
             prezzo_unitario, sconto_perc, iva_perc, imponibile_riga, iva_riga,
             data_consegna_prevista, note, flag_sostituto, offerta_riga_padre_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $map = [];
    $parentRows = [];
    $childRows = [];

    foreach ($righe as $pos => $r) {
        $r['posizione_riga'] = $pos;
        $padre_id = $r['offerta_riga_padre_id'] ?? '';
        if ($padre_id === '') {
            $parentRows[] = $r;
        } else {
            $childRows[] = $r;
        }
    }

    foreach ($parentRows as $r) {
        $qta        = (float)($r['quantita_offerta'] ?? 0);
        $prezzo     = (float)($r['prezzo_unitario'] ?? 0);
        $sconto     = (float)($r['sconto_perc'] ?? 0);
        // Fallback centralizzato (PIANO - Aliquota IVA su Prodotti e Cliente
        // Esente, Livello Intermedio, Fase 4) — mai più "22" ricopiato a mano.
        $iva        = (float)($r['iva_perc'] ?? QK_IVA_ALIQUOTA_FALLBACK);
        
        $totali     = qk_calcola_totali_riga($qta, $prezzo, $sconto, $iva);
        $imponibile = $totali['imponibile'];
        $ivaRiga    = $totali['iva_riga'];

        $st->execute([
            $offerta_id,
            ($r['rfq_riga_id'] ?? '') !== '' ? (int)$r['rfq_riga_id'] : null,
            (int)$r['posizione_riga'],
            (int)$r['prodotto_id'],
            $r['descrizione'] ?? '',
            $r['sku_fornitore'] ?? '',
            $qta,
            ($r['unita_misura_id'] ?? '') !== '' ? (int)$r['unita_misura_id'] : null,
            $prezzo,
            $sconto,
            $iva,
            $imponibile,
            $ivaRiga,
            ($r['data_consegna_prevista'] ?? '') !== '' ? $r['data_consegna_prevista'] : null,
            $r['note'] ?? '',
            (int)($r['flag_sostituto'] ?? 0),
            null
        ]);

        $newId = (int)$pdo->lastInsertId();
        if (($r['temp_id'] ?? '') !== '') {
            $map[(string)$r['temp_id']] = $newId;
        }
    }

    foreach ($childRows as $r) {
        $qta        = (float)($r['quantita_offerta'] ?? 0);
        $prezzo     = (float)($r['prezzo_unitario'] ?? 0);
        $sconto     = (float)($r['sconto_perc'] ?? 0);
        // Fallback centralizzato (PIANO - Aliquota IVA su Prodotti e Cliente
        // Esente, Livello Intermedio, Fase 4) — mai più "22" ricopiato a mano.
        $iva        = (float)($r['iva_perc'] ?? QK_IVA_ALIQUOTA_FALLBACK);

        $totali     = qk_calcola_totali_riga($qta, $prezzo, $sconto, $iva);
        $imponibile = $totali['imponibile'];
        $ivaRiga    = $totali['iva_riga'];

        $padre_temp_id = (string)($r['offerta_riga_padre_id'] ?? '');
        $newPadreId = isset($map[$padre_temp_id]) ? $map[$padre_temp_id] : null;

        $st->execute([
            $offerta_id,
            ($r['rfq_riga_id'] ?? '') !== '' ? (int)$r['rfq_riga_id'] : null,
            (int)$r['posizione_riga'],
            (int)$r['prodotto_id'],
            $r['descrizione'] ?? '',
            $r['sku_fornitore'] ?? '',
            $qta,
            ($r['unita_misura_id'] ?? '') !== '' ? (int)$r['unita_misura_id'] : null,
            $prezzo,
            $sconto,
            $iva,
            $imponibile,
            $ivaRiga,
            ($r['data_consegna_prevista'] ?? '') !== '' ? $r['data_consegna_prevista'] : null,
            $r['note'] ?? '',
            (int)($r['flag_sostituto'] ?? 0),
            $newPadreId
        ]);
    }
}

/** Ricalcola e salva imponibile/iva/totale sulla testata offerta. */
function _offerta_ricalcola_totali(PDO $pdo, int $offerta_id): void
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(imponibile_riga),0) AS imp,
                COALESCE(SUM(iva_riga),0) AS iva
           FROM offerte_righe WHERE offerta_id = ?'
    );
    $st->execute([$offerta_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $imp = round((float)$row['imp'], 2);
    $iva = round((float)$row['iva'], 2);

    $pdo->prepare(
        'UPDATE offerte_testata SET imponibile = ?, totale_iva = ?, totale = ? WHERE id = ?'
    )->execute([$imp, $iva, round($imp + $iva, 2), $offerta_id]);
}
