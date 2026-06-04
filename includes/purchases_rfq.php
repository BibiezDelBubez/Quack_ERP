<?php
declare(strict_types=1);
/**
 * includes/purchases_rfq.php
 * Funzioni DB per le RFQ (Richieste di Offerta).
 *
 * Dipende da: includes/purchases_common.php
 */

require_once __DIR__ . '/purchases_common.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco RFQ con filtri opzionali.
 *
 * @param string $search  Testo libero su numero o ragione sociale fornitore
 * @param string $stato   '' = tutti | 'bozza' | 'inviata' | 'revisionata' | 'chiusa'
 */
function qk_rfq_list(PDO $pdo, string $search = '', string $stato = ''): array
{
    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[]  = '(r.numero LIKE ? OR COALESCE(cf.ragione_sociale, cf.nome) LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($stato !== '') {
        $where[]  = 'r.stato = ?';
        $params[] = $stato;
    }

    $st = $pdo->prepare(
        'SELECT r.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome
           FROM rfq_testata r
      LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY r.created_at DESC'
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dettaglio completo di una RFQ: testata + righe + offerte collegate + cronologia versioni.
 *
 * @return array{rfq:array, righe:array, offerte:array, versioni:array}|null
 */
function qk_rfq_detail(PDO $pdo, int $id): ?array
{
    $stRfq = $pdo->prepare(
        'SELECT r.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM rfq_testata r
      LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
      LEFT JOIN metodi_pagamento mp ON mp.id = r.metodo_pagamento_id
      LEFT JOIN listini_acquisto la ON la.id = r.listino_acquisto_id
          WHERE r.id = ?'
    );
    $stRfq->execute([$id]);
    $rfq = $stRfq->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) return null;

    $stRighe = $pdo->prepare(
        'SELECT rr.*, p.nome AS prodotto_nome, p.sku_interno, p.descrizione_confezione AS formato, 
                CASE WHEN r.uom_mode = \'stock\' THEN um_stock.codice
                     ELSE COALESCE(um.codice, um_comm_pf.codice, um_comm_prod.codice, um_stock.codice)
                END AS uom,
                um_stock.codice AS uom_stock,
                COALESCE(um_comm_pf.codice, um_comm_prod.codice, um_stock.codice) AS uom_commercial,
                p.unita_misura_stock_id,
                COALESCE(pf.unita_misura_acquisto_id, p.unita_contenuto_id, p.unita_misura_stock_id) AS unita_misura_commercial_id,
                p.quantita_contenuto
           FROM rfq_righe rr
           JOIN prodotti p ON p.id = rr.prodotto_id
           JOIN rfq_testata r ON r.id = rr.rfq_id
      LEFT JOIN unita_misura um ON um.id = rr.unita_misura_id
      LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
      LEFT JOIN unita_misura um_comm_prod ON um_comm_prod.id = p.unita_contenuto_id
      LEFT JOIN prodotti_fornitori pf ON pf.id = (
          SELECT pf2.id FROM prodotti_fornitori pf2
           WHERE pf2.prodotto_id = p.id
             AND pf2.fornitore_id = r.fornitore_id
           ORDER BY pf2.ordine_preferenza_fornitore ASC, pf2.id DESC
           LIMIT 1
      )
      LEFT JOIN unita_misura um_comm_pf ON um_comm_pf.id = pf.unita_misura_acquisto_id
          WHERE rr.rfq_id = ?
          ORDER BY rr.posizione_riga'
    );
    $stRighe->execute([$id]);

    $stOfferte = $pdo->prepare(
        'SELECT o.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome
           FROM offerte_testata o
      LEFT JOIN clienti_fornitori cf ON cf.id = o.fornitore_id
          WHERE o.rfq_id = ?
          ORDER BY o.created_at DESC'
    );
    $stOfferte->execute([$id]);

    // Tutte le versioni collegate (padre e figli)
    $padreId = $rfq['rfq_padre_id'] ?? null;
    $radice  = $padreId ? (int)$padreId : $id;
    $stVer   = $pdo->prepare(
        'SELECT id, numero, versione, stato, created_at
           FROM rfq_testata
          WHERE id = ? OR rfq_padre_id = ?
          ORDER BY versione'
    );
    $stVer->execute([$radice, $radice]);

    return [
        'rfq'      => $rfq,
        'righe'    => $stRighe->fetchAll(PDO::FETCH_ASSOC),
        'offerte'  => $stOfferte->fetchAll(PDO::FETCH_ASSOC),
        'versioni' => $stVer->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// SCRITTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crea o aggiorna una RFQ con le sue righe (operazione atomica).
 *
 * Vincolo: una RFQ in stato 'inviata' non può essere modificata;
 * usare qk_rfq_nuova_versione() per creare una revisione.
 *
 * @param array $v      Campi testata
 * @param array $righe  Array di righe [['prodotto_id'=>…, 'quantita_richiesta'=>…], …]
 * @param int   $id     0 = nuovo inserimento
 * @return int  ID della RFQ salvata
 */
function qk_rfq_save(PDO $pdo, array $v, array $righe, int $utente_id, int $id = 0): int
{
    $pdo->beginTransaction();
    try {
        if ($id === 0) {
            $numero = qk_next_numero_acquisto($pdo, 'rfq');
             $st = $pdo->prepare(
                'INSERT INTO rfq_testata
                    (numero, versione, rfq_padre_id, stato,
                     fornitore_id, indirizzo_consegna_id,
                     data_emissione, data_scadenza,
                     condizioni_pagamento, valuta,
                     note_fornitore, note_interne,
                     creato_da, riferimento_rfq_interno, azienda_interna_id, uom_mode,
                     incoterm_codice, incoterm_luogo,
                     consegna_via, consegna_civico, consegna_cap, consegna_citta,
                     consegna_provincia, consegna_nazione, consegna_presso,
                     consegna_referente, consegna_telefono, consegna_note,
                     contatto_id, contatto_nome, contatto_telefono, contatto_email,
                     fornitore_telefono, fornitore_email, listino_acquisto_id,
                     metodo_pagamento_id, resa_porto, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
            );
            $st->execute([
                $numero,
                (int)($v['versione'] ?? 1),
                ($v['rfq_padre_id'] ?? '') !== '' ? (int)$v['rfq_padre_id'] : null,
                $v['stato'] ?? 'bozza',
                ($v['fornitore_id'] ?? '') !== '' ? (int)$v['fornitore_id'] : null,
                ($v['indirizzo_consegna_id'] ?? '') !== '' ? (int)$v['indirizzo_consegna_id'] : null,
                $v['data_emissione'] ?? date('Y-m-d'),
                ($v['data_scadenza'] ?? '') !== '' ? $v['data_scadenza'] : null,
                $v['condizioni_pagamento'] ?? '',
                $v['valuta'] ?? 'EUR',
                $v['note_fornitore'] ?? '',
                $v['note_interne'] ?? '',
                $utente_id,
                $v['riferimento_rfq_interno'] ?? '',
                ($v['azienda_interna_id'] ?? '') !== '' ? (int)$v['azienda_interna_id'] : null,
                $v['uom_mode'] ?? 'commercial',
                $v['incoterm_codice'] ?? null,
                $v['incoterm_luogo'] ?? null,
                $v['consegna_via'] ?? null,
                $v['consegna_civico'] ?? null,
                $v['consegna_cap'] ?? null,
                $v['consegna_citta'] ?? null,
                $v['consegna_provincia'] ?? null,
                $v['consegna_nazione'] ?? 'IT',
                $v['consegna_presso'] ?? null,
                $v['consegna_referente'] ?? null,
                $v['consegna_telefono'] ?? null,
                $v['consegna_note'] ?? null,
                ($v['contatto_id'] ?? '') !== '' ? (int)$v['contatto_id'] : null,
                $v['contatto_nome'] ?? null,
                $v['contatto_telefono'] ?? null,
                $v['contatto_email'] ?? null,
                $v['fornitore_telefono'] ?? null,
                $v['fornitore_email'] ?? null,
                ($v['listino_acquisto_id'] ?? '') !== '' ? (int)$v['listino_acquisto_id'] : null,
                ($v['metodo_pagamento_id'] ?? '') !== '' ? (int)$v['metodo_pagamento_id'] : null,
                ($v['resa_porto'] ?? '') !== '' ? $v['resa_porto'] : null,
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $stCheck = $pdo->prepare('SELECT stato FROM rfq_testata WHERE id = ?');
            $stCheck->execute([$id]);
            $row = $stCheck->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['stato'] === 'inviata') {
                throw new \RuntimeException('Una RFQ già inviata non è modificabile. Usa "Crea nuova versione".');
            }

            $pdo->prepare(
                'UPDATE rfq_testata SET
                    stato = ?, fornitore_id = ?,
                    indirizzo_consegna_id = ?, data_emissione = ?, data_scadenza = ?,
                    condizioni_pagamento = ?, valuta = ?,
                    note_fornitore = ?, note_interne = ?,
                    riferimento_rfq_interno = ?, azienda_interna_id = ?, uom_mode = ?,
                    incoterm_codice = ?, incoterm_luogo = ?,
                    consegna_via = ?, consegna_civico = ?, consegna_cap = ?, consegna_citta = ?,
                    consegna_provincia = ?, consegna_nazione = ?, consegna_presso = ?,
                    consegna_referente = ?, consegna_telefono = ?, consegna_note = ?,
                    contatto_id = ?, contatto_nome = ?, contatto_telefono = ?, contatto_email = ?,
                    fornitore_telefono = ?, fornitore_email = ?, listino_acquisto_id = ?,
                    metodo_pagamento_id = ?, resa_porto = ?
                  WHERE id = ?'
            )->execute([
                $v['stato'] ?? 'bozza',
                ($v['fornitore_id'] ?? '') !== '' ? (int)$v['fornitore_id'] : null,
                ($v['indirizzo_consegna_id'] ?? '') !== '' ? (int)$v['indirizzo_consegna_id'] : null,
                $v['data_emissione'] ?? date('Y-m-d'),
                ($v['data_scadenza'] ?? '') !== '' ? $v['data_scadenza'] : null,
                $v['condizioni_pagamento'] ?? '',
                $v['valuta'] ?? 'EUR',
                $v['note_fornitore'] ?? '',
                $v['note_interne'] ?? '',
                $v['riferimento_rfq_interno'] ?? '',
                ($v['azienda_interna_id'] ?? '') !== '' ? (int)$v['azienda_interna_id'] : null,
                $v['uom_mode'] ?? 'commercial',
                $v['incoterm_codice'] ?? null,
                $v['incoterm_luogo'] ?? null,
                $v['consegna_via'] ?? null,
                $v['consegna_civico'] ?? null,
                $v['consegna_cap'] ?? null,
                $v['consegna_citta'] ?? null,
                $v['consegna_provincia'] ?? null,
                $v['consegna_nazione'] ?? 'IT',
                $v['consegna_presso'] ?? null,
                $v['consegna_referente'] ?? null,
                $v['consegna_telefono'] ?? null,
                $v['consegna_note'] ?? null,
                ($v['contatto_id'] ?? '') !== '' ? (int)$v['contatto_id'] : null,
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
            $pdo->prepare('DELETE FROM rfq_righe WHERE rfq_id = ?')->execute([$id]);
        }

        _rfq_insert_righe($pdo, $id, $righe);
        _rfq_ricalcola_totali($pdo, $id);

        $pdo->commit();
        return $id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Elimina una RFQ solo se è in bozza e non ha offerte collegate.
 *
 * @throws RuntimeException se la RFQ non è eliminabile
 */
function qk_rfq_delete(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT stato FROM rfq_testata WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new \RuntimeException('RFQ non trovata.');
    if ($row['stato'] !== 'bozza') throw new \RuntimeException('Si può eliminare solo una RFQ in bozza.');

    $nOfferte = $pdo->prepare('SELECT COUNT(*) FROM offerte_testata WHERE rfq_id = ?');
    $nOfferte->execute([$id]);
    if ((int)$nOfferte->fetchColumn() > 0) {
        throw new \RuntimeException('Impossibile eliminare: esistono offerte collegate a questa RFQ.');
    }

    $pdo->prepare('DELETE FROM rfq_testata WHERE id = ?')->execute([$id]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TRANSIZIONI DI STATO
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Passa una RFQ da bozza → inviata.
 *
 * @throws RuntimeException se la RFQ non è in bozza
 */
function qk_rfq_invia(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $row = _rfq_check_stato($pdo, $id, 'bozza', 'Solo una RFQ in bozza può essere inviata.');
        unset($row);

        $pdo->prepare(
            "UPDATE rfq_testata SET stato = 'inviata', inviata_il = CURRENT_TIMESTAMP WHERE id = ?"
        )->execute([$id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Chiude manualmente una RFQ (bozza o inviata → chiusa).
 */
function qk_rfq_chiudi(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT stato FROM rfq_testata WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('RFQ non trovata.');
    if (!in_array($row['stato'], ['bozza', 'inviata'], true)) {
        throw new \RuntimeException('La RFQ non è in uno stato chiudibile.');
    }
    $pdo->prepare("UPDATE rfq_testata SET stato = 'chiusa' WHERE id = ?")->execute([$id]);
}

/**
 * Crea una nuova versione di una RFQ inviata.
 * La versione corrente passa a "revisionata"; la nuova è in "bozza".
 *
 * @return int  ID della nuova versione
 */
function qk_rfq_nuova_versione(PDO $pdo, int $id, int $utente_id): int
{
    $pdo->beginTransaction();
    try {
        $rfq = _rfq_check_stato($pdo, $id, 'inviata', 'Puoi creare una nuova versione solo da una RFQ inviata.');

        $nuovoNumero   = qk_next_numero_acquisto($pdo, 'rfq');
        $nuovaVersione = (int)$rfq['versione'] + 1;

        $pdo->prepare(
            'INSERT INTO rfq_testata
                (numero, versione, rfq_padre_id, stato,
                 fornitore_id, indirizzo_consegna_id, data_emissione, data_scadenza,
                 condizioni_pagamento, valuta, note_fornitore, note_interne,
                 imponibile, totale_iva, totale,
                 creato_da, riferimento_rfq_interno, azienda_interna_id, uom_mode,
                 incoterm_codice, incoterm_luogo,
                 consegna_via, consegna_civico, consegna_cap, consegna_citta,
                 consegna_provincia, consegna_nazione, consegna_presso,
                 consegna_referente, consegna_telefono, consegna_note,
                 contatto_id, contatto_nome, contatto_telefono, contatto_email,
                 fornitore_telefono, fornitore_email, listino_acquisto_id,
                 metodo_pagamento_id, resa_porto, created_at)
             VALUES (?,?,?,\'bozza\',?,?,DATE(\'now\'),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
        )->execute([
            $nuovoNumero, $nuovaVersione, $id,
            $rfq['fornitore_id'],
            $rfq['indirizzo_consegna_id'],
            $rfq['data_scadenza'],
            $rfq['condizioni_pagamento'],
            $rfq['valuta'],
            $rfq['note_fornitore'],
            $rfq['note_interne'],
            $rfq['imponibile'],
            $rfq['totale_iva'],
            $rfq['totale'],
            $utente_id,
            $rfq['riferimento_rfq_interno'],
            $rfq['azienda_interna_id'],
            $rfq['uom_mode'] ?? 'commercial',
            $rfq['incoterm_codice'],
            $rfq['incoterm_luogo'],
            $rfq['consegna_via'],
            $rfq['consegna_civico'],
            $rfq['consegna_cap'],
            $rfq['consegna_citta'],
            $rfq['consegna_provincia'],
            $rfq['consegna_nazione'],
            $rfq['consegna_presso'],
            $rfq['consegna_referente'],
            $rfq['consegna_telefono'],
            $rfq['consegna_note'],
            $rfq['contatto_id'],
            $rfq['contatto_nome'],
            $rfq['contatto_telefono'],
            $rfq['contatto_email'],
            $rfq['fornitore_telefono'],
            $rfq['fornitore_email'],
            $rfq['listino_acquisto_id'],
            $rfq['metodo_pagamento_id'],
            $rfq['resa_porto'],
        ]);
        $nuovoId = (int) $pdo->lastInsertId();

        // Copia le righe nella nuova versione
        $righe = $pdo->prepare('SELECT * FROM rfq_righe WHERE rfq_id = ? ORDER BY posizione_riga');
        $righe->execute([$id]);
        _rfq_insert_righe($pdo, $nuovoId, $righe->fetchAll(PDO::FETCH_ASSOC));

        // Segna la versione corrente come "revisionata"
        $pdo->prepare("UPDATE rfq_testata SET stato = 'revisionata' WHERE id = ?")->execute([$id]);

        $pdo->commit();
        return $nuovoId;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER PRIVATI
// ─────────────────────────────────────────────────────────────────────────────

/** Verifica lo stato di una RFQ; lancia eccezione se diverso da $statoAtteso. */
function _rfq_check_stato(PDO $pdo, int $id, string $statoAtteso, string $msg): array
{
    $st = $pdo->prepare('SELECT * FROM rfq_testata WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('RFQ non trovata.');
    if ($row['stato'] !== $statoAtteso) throw new \RuntimeException($msg);
    return $row;
}

/** Inserisce le righe di una RFQ (usata sia in insert che in copia versione). */
function _rfq_insert_righe(PDO $pdo, int $rfq_id, array $righe): void
{
    $st = $pdo->prepare(
        'INSERT INTO rfq_righe
            (rfq_id, posizione_riga, prodotto_id, descrizione, sku_fornitore,
             quantita_richiesta, unita_misura_id, prezzo_indicativo, note, iva_perc, iva_riga,
             data_consegna_richiesta, e_rifiutato)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($righe as $pos => $r) {
        $qty = (float)$r['quantita_richiesta'];
        $price = ($r['prezzo_indicativo'] ?? '') !== '' ? (float)$r['prezzo_indicativo'] : 0.0;
        $ivaPerc = ($r['iva_perc'] ?? '') !== '' ? (float)$r['iva_perc'] : 22.0;
        $ivaRiga = $qty * $price * ($ivaPerc / 100.0);

        $st->execute([
            $rfq_id,
            isset($r['posizione_riga']) ? (int)$r['posizione_riga'] : $pos,
            (int)$r['prodotto_id'],
            $r['descrizione'] ?? '',
            $r['sku_fornitore'] ?? '',
            $qty,
            ($r['unita_misura_id'] ?? '') !== '' ? (int)$r['unita_misura_id'] : null,
            ($r['prezzo_indicativo'] ?? '') !== '' ? $price : null,
            $r['note'] ?? '',
            $ivaPerc,
            round($ivaRiga, 4),
            !empty($r['data_consegna_richiesta']) ? $r['data_consegna_richiesta'] : null,
            !empty($r['e_rifiutato']) ? (int)$r['e_rifiutato'] : 0,
        ]);
    }
}

/** Ricalcola e salva imponibile/iva/totale sulla testata RFQ. */
function _rfq_ricalcola_totali(PDO $pdo, int $rfq_id): void
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(quantita_richiesta * COALESCE(prezzo_indicativo,0)),0) AS imponibile,
                COALESCE(SUM(iva_riga),0) AS totale_iva
           FROM rfq_righe WHERE rfq_id = ?'
    );
    $st->execute([$rfq_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $imponibile = (float)$row['imponibile'];
    $totaleIva  = (float)$row['totale_iva'];
    $totale     = $imponibile + $totaleIva;

    $pdo->prepare(
        'UPDATE rfq_testata SET imponibile = ?, totale_iva = ?, totale = ? WHERE id = ?'
    )->execute([round($imponibile, 2), round($totaleIva, 2), round($totale, 2), $rfq_id]);
}
