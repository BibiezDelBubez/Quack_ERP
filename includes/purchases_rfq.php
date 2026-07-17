<?php
declare(strict_types=1);
/**
 * includes/purchases_rfq.php
 * Funzioni DB per le RFQ (Richieste di Offerta).
 *
 * Dipende da: includes/purchases_common.php
 */

require_once __DIR__ . '/purchases_common.php';
require_once __DIR__ . '/prodotti.php';
require_once __DIR__ . '/anagrafiche.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco RFQ con filtri opzionali.
 *
 * @param string $search  Testo libero su numero o ragione sociale fornitore
 * @param string $stato   '' = tutti | 'bozza' | 'inviata' | 'revisionata' | 'chiusa'
 */
/** @internal costruisce WHERE + params per qk_rfq_list / qk_rfq_count */
function _qk_rfq_where(string $search, string $stato, string $dal, string $al): array
{
    $where = ['1=1']; $params = [];
    if ($search !== '') {
        $where[] = '(r.numero LIKE ? OR r.riferimento_rfq_interno LIKE ? OR COALESCE(cf.ragione_sociale, cf.nome) LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($stato !== '') { $where[] = 'r.stato = ?'; $params[] = $stato; }
    if ($dal  !== '') { $where[] = 'r.data_emissione >= ?'; $params[] = $dal; }
    if ($al   !== '') { $where[] = 'r.data_emissione <= ?'; $params[] = $al; }
    return [$where, $params];
}

function qk_rfq_count(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = ''): int
{
    [$where, $params] = _qk_rfq_where($search, $stato, $dal, $al);
    $st = $pdo->prepare('SELECT COUNT(*) FROM rfq_testata r LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function qk_rfq_list(PDO $pdo, string $search = '', string $stato = '', string $dal = '', string $al = '', string $sort = 'data', string $dir = 'desc', int $limit = 50, int $offset = 0): array
{
    $sortMap = [
        'data'      => 'r.data_emissione',
        'numero'    => 'r.numero',
        'rif'       => 'r.riferimento_rfq_interno',
        'fornitore' => 'fornitore_nome',
        'stato'     => 'r.stato',
        'scadenza'  => 'r.data_scadenza',
        'totale'    => 'r.totale',
    ];
    $orderCol = $sortMap[$sort] ?? 'r.data_emissione';
    $orderDir = $dir === 'asc' ? 'ASC' : 'DESC';

    [$where, $params] = _qk_rfq_where($search, $stato, $dal, $al);
    $params[] = $limit;
    $params[] = $offset;

    $st = $pdo->prepare(
        'SELECT r.*,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome
           FROM rfq_testata r
      LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY ' . $orderCol . ' ' . $orderDir . ', r.id DESC
          LIMIT ? OFFSET ?'
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
                snap_addr.indirizzo_master_id AS indirizzo_consegna_master_id,
                snap_addr.personalizzato AS indirizzo_consegna_personalizzato,
                snap_addr.hash_originale AS indirizzo_consegna_hash_originale,
                snap_cont.contatto_master_id AS contatto_master_id,
                snap_cont.personalizzato AS contatto_personalizzato,
                snap_cont.hash_originale AS contatto_hash_originale,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome,
                mp.descrizione AS metodo_pagamento_descrizione,
                la.nome_listino AS listino_acquisto_nome
           FROM rfq_testata r
      LEFT JOIN indirizzi_documenti snap_addr ON snap_addr.id = r.indirizzo_consegna_id
      LEFT JOIN contatti_documenti snap_cont ON snap_cont.id = r.contatto_id
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
                     ELSE COALESCE(um.codice, um_comm_prod.codice, um_stock.codice)
                END AS uom,
                um_stock.codice AS uom_stock,
                COALESCE(um_comm_prod.codice, um_stock.codice) AS uom_commercial,
                p.unita_misura_stock_id,
                COALESCE(p.unita_contenuto_id, p.unita_misura_stock_id) AS unita_misura_commercial_id,
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
        // Prepara i payload per gli snapshot dell'indirizzo e del contatto
        $addrPayload = [
            'indirizzo_master_id' => $v['indirizzo_consegna_id'] ?? null,
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

        if ($id === 0) {
            // ==============================================================================
            // [AI WARNING - ARCHITETTURA IBRIDA]
            // Le foreign keys `indirizzo_consegna_id` e `contatto_id` in rfq_testata, 
            // offerte_testata e ordini_testata puntano obbligatoriamente alle tabelle 
            // snapshot (indirizzi_documenti e contatti_documenti) e NON all'anagrafica.
            // Questa funzione esegue lo snapshotting iniziale e restituisce gli ID storici 
            // da inserire nel DB. Il database live è stato allineato a questo schema.
            // ==============================================================================
            $snapAddrId = qk_upsert_document_address($pdo, $addrPayload, null);
            $snapContId = qk_upsert_document_contact($pdo, $contPayload, null);

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
                $snapAddrId,
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
                ($v['incoterm_codice'] ?? '') !== '' ? $v['incoterm_codice'] : null,
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
                $snapContId,
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
            $stCheck = $pdo->prepare('SELECT stato, indirizzo_consegna_id, contatto_id FROM rfq_testata WHERE id = ?');
            $stCheck->execute([$id]);
            $row = $stCheck->fetch(PDO::FETCH_ASSOC);
            // Unico punto di verità sul blocco modifica: solo 'bozza' è liberamente
            // modificabile. Qualunque altro stato (inviata, revisionata, chiusa) è
            // bloccato qui, così la protezione non dipende da controlli duplicati
            // nel controller (che in passato potevano essere aggirati/sovrascritti).
            if ($row && $row['stato'] !== 'bozza') {
                $msgPerStato = [
                    'inviata'     => 'Una RFQ già inviata non è modificabile. Usa "Crea nuova versione".',
                    'revisionata' => 'Questa RFQ è stata sostituita da una versione più recente e non è più modificabile.',
                    'chiusa'      => 'Questa RFQ è chiusa e non è più modificabile.',
                ];
                throw new \RuntimeException($msgPerStato[$row['stato']] ?? 'Questa RFQ non è più modificabile nel suo stato attuale.');
            }

            // Aggiorna gli snapshot esistenti
            $snapAddrId = qk_upsert_document_address($pdo, $addrPayload, $row ? (int)$row['indirizzo_consegna_id'] : null);
            $snapContId = qk_upsert_document_contact($pdo, $contPayload, $row ? (int)$row['contatto_id'] : null);

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
                $snapAddrId,
                $v['data_emissione'] ?? date('Y-m-d'),
                ($v['data_scadenza'] ?? '') !== '' ? $v['data_scadenza'] : null,
                $v['condizioni_pagamento'] ?? '',
                $v['valuta'] ?? 'EUR',
                $v['note_fornitore'] ?? '',
                $v['note_interne'] ?? '',
                $v['riferimento_rfq_interno'] ?? '',
                ($v['azienda_interna_id'] ?? '') !== '' ? (int)$v['azienda_interna_id'] : null,
                $v['uom_mode'] ?? 'commercial',
                ($v['incoterm_codice'] ?? '') !== '' ? $v['incoterm_codice'] : null,
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
    // Chiusura manuale (nessuna offerta accettata) — esito 'annullata', per
    // distinguerla dalla chiusura automatica di qk_offerta_accetta().
    $pdo->prepare("UPDATE rfq_testata SET stato = 'chiusa', esito_chiusura = 'annullata' WHERE id = ?")->execute([$id]);
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

        $nuovaVersione = (int)$rfq['versione'] + 1;
        $baseNumero    = preg_replace('/ v\d+$/', '', $rfq['numero']);
        $nuovoNumero   = $baseNumero . ' v' . $nuovaVersione;

        // Duplica gli snapshot di indirizzo e contatto per isolare la nuova versione
        $nuovoIndirizzoId = null;
        if (!empty($rfq['indirizzo_consegna_id'])) {
            $stAddr = $pdo->prepare('SELECT * FROM indirizzi_documenti WHERE id = ?');
            $stAddr->execute([$rfq['indirizzo_consegna_id']]);
            $addrData = $stAddr->fetch(PDO::FETCH_ASSOC);
            if ($addrData) {
                unset($addrData['id'], $addrData['created_at']);
                $fields = array_keys($addrData);
                $placeholders = array_fill(0, count($fields), '?');
                $stInsAddr = $pdo->prepare('INSERT INTO indirizzi_documenti (' . implode(',', $fields) . ', created_at) VALUES (' . implode(',', $placeholders) . ', CURRENT_TIMESTAMP)');
                $stInsAddr->execute(array_values($addrData));
                $nuovoIndirizzoId = (int)$pdo->lastInsertId();
            }
        }

        $nuovoContattoId = null;
        if (!empty($rfq['contatto_id'])) {
            $stCont = $pdo->prepare('SELECT * FROM contatti_documenti WHERE id = ?');
            $stCont->execute([$rfq['contatto_id']]);
            $contData = $stCont->fetch(PDO::FETCH_ASSOC);
            if ($contData) {
                unset($contData['id'], $contData['created_at']);
                $fields = array_keys($contData);
                $placeholders = array_fill(0, count($fields), '?');
                $stInsCont = $pdo->prepare('INSERT INTO contatti_documenti (' . implode(',', $fields) . ', created_at) VALUES (' . implode(',', $placeholders) . ', CURRENT_TIMESTAMP)');
                $stInsCont->execute(array_values($contData));
                $nuovoContattoId = (int)$pdo->lastInsertId();
            }
        }

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
            $nuovoIndirizzoId,
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
            $nuovoContattoId,
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
             data_consegna_richiesta, e_rifiutato,
             quantita_stock, system_unit_price_stock, unita_misura_stock_id,
             quantita_contenuto_snapshot, num_factor_snapshot, den_factor_snapshot)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($righe as $pos => $r) {
        $qty     = (float)$r['quantita_richiesta'];
        $price   = ($r['prezzo_indicativo'] ?? '') !== '' ? (float)$r['prezzo_indicativo'] : 0.0;
        $ivaPerc = ($r['iva_perc'] ?? '') !== '' ? (float)$r['iva_perc'] : 22.0;
        $ivaRiga = $qty * $price * ($ivaPerc / 100.0);
        $um_id   = ($r['unita_misura_id'] ?? '') !== '' ? (int)$r['unita_misura_id'] : null;

        $uomResult = qk_uom_converti_riga($pdo, !empty($r['prodotto_id']) ? (int)$r['prodotto_id'] : null, $um_id, $qty, $price);

        $st->execute([
            $rfq_id,
            isset($r['posizione_riga']) ? (int)$r['posizione_riga'] : $pos,
            (int)$r['prodotto_id'],
            $r['descrizione'] ?? '',
            $r['sku_fornitore'] ?? '',
            $qty,
            $um_id,
            ($r['prezzo_indicativo'] ?? '') !== '' ? $price : null,
            $r['note'] ?? '',
            $ivaPerc,
            round($ivaRiga, 4),
            !empty($r['data_consegna_richiesta']) ? $r['data_consegna_richiesta'] : null,
            !empty($r['e_rifiutato']) ? (int)$r['e_rifiutato'] : 0,
            $uomResult['quantita_stock'],
            $uomResult['system_unit_price_stock'],
            $uomResult['unita_misura_stock_id'],
            $uomResult['quantita_contenuto_snapshot'],
            $uomResult['num_factor_snapshot'],
            $uomResult['den_factor_snapshot'],
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

/** 
 * Recupera l'indirizzo principale del fornitore formattato.
 */
function qk_rfq_get_fornitore_indirizzo(PDO $pdo, int $fornitore_id, string $separator = '<br>'): string
{
    $ind = qk_soggetto_get_indirizzo_principale($pdo, $fornitore_id);
    if (!$ind) {
        return '';
    }
    return $ind['via'] . ' ' . $ind['civico'] . $separator . $ind['cap'] . ' ' . $ind['citta'] . ' (' . $ind['provincia'] . ')';
}
