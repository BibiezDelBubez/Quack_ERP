<?php
declare(strict_types=1);
/**
 * includes/acquisti_offerte.php
 * Funzioni DB per le Offerte (preventivi ricevuti dai fornitori).
 *
 * Ciclo di vita: in_valutazione → accettata | rifiutata
 * Dipende da: includes/acquisti_comune.php
 */

require_once __DIR__ . '/acquisti_comune.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco offerte con filtri opzionali.
 *
 * @param int $rfq_id  Filtra per RFQ specifica; 0 = tutte
 */
function qk_offerte_list(PDO $pdo, string $search = '', string $stato = '', int $rfq_id = 0): array
{
    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[]  = '(o.numero LIKE ? OR COALESCE(cf.ragione_sociale, cf.nome) LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($stato !== '') {
        $where[]  = 'o.stato = ?';
        $params[] = $stato;
    }
    if ($rfq_id > 0) {
        $where[]  = 'o.rfq_id = ?';
        $params[] = $rfq_id;
    }

    $st = $pdo->prepare(
        'SELECT o.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                r.numero AS rfq_numero
           FROM offerte_testata o
      LEFT JOIN clienti_fornitori cf ON cf.id = o.fornitore_id
      LEFT JOIN rfq_testata r ON r.id = o.rfq_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY o.created_at DESC'
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
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                r.numero AS rfq_numero,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM offerte_testata o
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
        if ($id === 0) {
            $numero = qk_next_numero_acquisto($pdo, 'offerta');
            $st = $pdo->prepare(
                'INSERT INTO offerte_testata
                    (numero, rfq_id, fornitore_id, stato,
                     data_offerta, data_scadenza_offerta,
                     condizioni_pagamento, valuta, riferimento_fornitore,
                     note_fornitore, note_interne, allegato_path,
                     contatto_id, contatto_nome, contatto_telefono, contatto_email,
                     fornitore_telefono, fornitore_email, listino_acquisto_id,
                     metodo_pagamento_id, resa_porto, creato_da, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
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
                ($v['contatto_id'] ?? '') !== '' ? (int)$v['contatto_id'] : null,
                $v['contatto_nome'] ?? null,
                $v['contatto_telefono'] ?? null,
                $v['contatto_email'] ?? null,
                $v['fornitore_telefono'] ?? null,
                $v['fornitore_email'] ?? null,
                ($v['listino_acquisto_id'] ?? '') !== '' ? (int)$v['listino_acquisto_id'] : null,
                ($v['metodo_pagamento_id'] ?? '') !== '' ? (int)$v['metodo_pagamento_id'] : null,
                $v['resa_porto'] ?? null,
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
                ($v['contatto_id'] ?? '') !== '' ? (int)$v['contatto_id'] : null,
                $v['contatto_nome'] ?? null,
                $v['contatto_telefono'] ?? null,
                $v['contatto_email'] ?? null,
                $v['fornitore_telefono'] ?? null,
                $v['fornitore_email'] ?? null,
                ($v['listino_acquisto_id'] ?? '') !== '' ? (int)$v['listino_acquisto_id'] : null,
                ($v['metodo_pagamento_id'] ?? '') !== '' ? (int)$v['metodo_pagamento_id'] : null,
                $v['resa_porto'] ?? null,
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

            // Chiude la RFQ
            $pdo->prepare("UPDATE rfq_testata SET stato = 'chiusa' WHERE id = ?")
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
    if ($row['stato'] !== 'in_valutazione') {
        throw new \RuntimeException("L'offerta è già stata valutata (stato: {$row['stato']}).");
    }
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
        $iva        = (float)($r['iva_perc'] ?? 22);
        $imponibile = round($qta * $prezzo * (1 - $sconto / 100), 2);
        $ivaRiga    = round($imponibile * $iva / 100, 2);

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
        $iva        = (float)($r['iva_perc'] ?? 22);
        $imponibile = round($qta * $prezzo * (1 - $sconto / 100), 2);
        $ivaRiga    = round($imponibile * $iva / 100, 2);

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
