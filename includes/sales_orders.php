<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';
require_once __DIR__ . '/document_common.php';

/**
 * Genera il prossimo numero progressivo per l'ordine cliente diretto.
 */
function qk_next_numero_ordine_diretto(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'ordine_cliente');
}

/**
 * Valida i dati dell'ordine diretto.
 */
function qk_ordine_diretto_validate(array $values, array $righe): array
{
    $errors = [];

    if (empty($values['cliente_id'])) {
        $errors['cliente_id'] = 'Selezionare un cliente.';
    }
    if (empty($values['data_ordine'])) {
        $errors['data_ordine'] = 'La data ordine è obbligatoria.';
    }

    if (empty($righe)) {
        $errors['_global'] = 'Inserire almeno una riga prodotto.';
    } else {
        foreach ($righe as $idx => $r) {
            if (empty(trim($r['descrizione'] ?? ''))) {
                $errors["righe_{$idx}_descrizione"] = 'La descrizione è obbligatoria.';
            }
            if ((float)($r['quantita'] ?? 0) <= 0) {
                $errors["righe_{$idx}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
            if ((float)($r['prezzo_unitario'] ?? 0) < 0) {
                $errors["righe_{$idx}_prezzo"] = 'Il prezzo unitario non può essere negativo.';
            }
        }
    }

    return $errors;
}

/**
 * Inserisce un ordine cliente diretto (senza preventivo di origine).
 * Restituisce l'ID del nuovo ordine.
 */
function qk_insert_ordine_diretto(PDO $pdo, array $values, array $righe, int $user_id): int
{
    $pdo->beginTransaction();
    try {
        $numero = $values['numero_ordine'];

        $denorm = qk_denormalize_document_details($pdo, $values);

        // ── Creazione snapshot strutturati (indirizzo e contatto) ───────────
        $addrPayload = [
            'indirizzo_master_id' => $values['indirizzo_spedizione_id'] ?? null,
            'via' => $denorm['consegna_via'],
            'civico' => $denorm['consegna_civico'],
            'cap' => $denorm['consegna_cap'],
            'citta' => $denorm['consegna_citta'],
            'provincia' => $denorm['consegna_provincia'],
            'nazione' => $denorm['consegna_nazione'],
            'presso' => $denorm['consegna_presso'],
            'referente_consegna' => $denorm['consegna_referente'],
            'telefono_consegna' => $denorm['consegna_telefono'],
            'note_consegna' => $denorm['consegna_note'],
        ];
        $indirizzo_consegna_id = qk_upsert_document_address($pdo, $addrPayload);

        $contPayload = [
            'contatto_master_id' => $values['contatto_id'] ?? null,
            'nome' => $values['contatto_nome'] ?? '',
            'email' => $values['contatto_email'] ?? '',
            'telefono' => $values['contatto_telefono'] ?? '',
        ];
        $contatto_snapshot_id = qk_upsert_document_contact($pdo, $contPayload);

        // ── Inserimento testata ordine ───────────────────────────────────────
        $st = $pdo->prepare("
            INSERT INTO ordini_clienti (
                numero_ordine, cliente_id, preventivo_id, azienda_interna_id,
                data_ordine, data_consegna_prevista,
                stato, stato_fatturazione,
                riferimento_interno, condizioni_pagamento, trasporto, valuta,
                listino_id, note,
                indirizzo_spedizione_id,
                consegna_via, consegna_civico, consegna_cap, consegna_citta,
                consegna_provincia, consegna_nazione, consegna_presso,
                consegna_referente, consegna_telefono, consegna_note,
                contatto_id, contatto_nome, contatto_email, contatto_telefono,
                indirizzo_consegna_id, contatto_snapshot_id,
                cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
                creato_da, created_at, updated_at
            ) VALUES (
                ?, ?, NULL, ?,
                ?, ?,
                'in_lavorazione', 'da_fatturare',
                ?, ?, ?, ?,
                ?, ?,
                ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");
        $st->execute([
            $numero,
            (int) $values['cliente_id'],
            $values['azienda_interna_id'] ?: null,
            $values['data_ordine'],
            $values['data_consegna_prevista'] ?: null,
            $values['riferimento_interno'] ?: null,
            $values['condizioni_pagamento'] ?: null,
            $values['trasporto'] ?: null,
            $values['valuta'] ?: 'EUR',
            !empty($values['listino_id']) ? (int)$values['listino_id'] : 1,
            $values['note'] ?: null,
            $values['indirizzo_spedizione_id'] ?: null,
            $denorm['consegna_via'] ?: null,
            $denorm['consegna_civico'] ?: null,
            $denorm['consegna_cap'] ?: null,
            $denorm['consegna_citta'] ?: null,
            $denorm['consegna_provincia'] ?: null,
            $denorm['consegna_nazione'] ?: 'IT',
            $denorm['consegna_presso'] ?: null,
            $denorm['consegna_referente'] ?: null,
            $denorm['consegna_telefono'] ?: null,
            $denorm['consegna_note'] ?: null,
            $values['contatto_id'] ?: null,
            $values['contatto_nome'] ?: null,
            $values['contatto_email'] ?: null,
            $values['contatto_telefono'] ?: null,
            $indirizzo_consegna_id,
            $contatto_snapshot_id,
            $denorm['cliente_ragione_sociale'] ?: null,
            $denorm['cliente_partita_iva'] ?: null,
            $denorm['cliente_codice_fiscale'] ?: null,
            $denorm['cliente_indirizzo'] ?: null,
            $user_id,
        ]);
        $ordine_id = (int) $pdo->lastInsertId();

        // ── Inserimento righe ────────────────────────────────────────────────
        $stRiga = $pdo->prepare("
            INSERT INTO ordini_clienti_righe (
                ordine_id, prodotto_id, descrizione, quantita_ordinata, unita_misura_id,
                prezzo_unitario, sconto_perc, iva_perc, costo_unitario,
                quantita_confezione, quantita_contenuto_snapshot,
                num_factor_snapshot, den_factor_snapshot,
                quantita_stock, system_unit_price_stock, unita_misura_stock_id,
                quantita_spedita, note,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                0, ?,
                CURRENT_TIMESTAMP
            )
        ");

        // Preload UoM IDs se non ci sono già
        $umCache = [];
        $getUomId = function (PDO $pdo, string $codice) use (&$umCache): ?int {
            if (!$codice) return null;
            if (!isset($umCache[$codice])) {
                $st = $pdo->prepare("SELECT id FROM unita_misura WHERE codice = ? LIMIT 1");
                $st->execute([$codice]);
                $umCache[$codice] = $st->fetchColumn() ?: null;
            }
            return $umCache[$codice];
        };

        foreach ($righe as $r) {
            $prodotto_id = !empty($r['prodotto_id']) ? (int)$r['prodotto_id'] : null;
            $qta = (float) ($r['quantita'] ?? 0);
            $pu  = (float) ($r['prezzo_unitario'] ?? 0);
            $sc  = (float) ($r['sconto_perc'] ?? 0);
            $iva = (float) ($r['iva_perc'] ?? 22);

            // UoM ID
            $um_codice = $r['unita_misura'] ?? 'pz';
            $um_id = $getUomId($pdo, $um_codice);

            // Calcola equivalenti in unità stock (centralizzato in qk_uom_converti_riga)
            $uomResult = qk_uom_converti_riga($pdo, $prodotto_id ? (int)$prodotto_id : null, $um_id ? (int)$um_id : null, $qta, (float)($r['prezzo_unitario'] ?? 0));
            $qtaStock            = $uomResult['quantita_stock'];
            $um_stock_id         = $uomResult['unita_misura_stock_id'];
            $factor              = $uomResult['quantita_contenuto_snapshot'];
            $num_factor_snapshot = $uomResult['num_factor_snapshot'];
            $den_factor_snapshot = $uomResult['den_factor_snapshot'];

            // Costo unitario
            $costo = (float) ($r['costo_unitario'] ?? 0);

            $stRiga->execute([
                $ordine_id,
                $prodotto_id,
                $r['descrizione'],
                $qta,
                $um_id,
                $pu,
                $sc,
                $iva,
                $costo,
                $r['quantita_confezione'] ?: null,
                $factor,
                $num_factor_snapshot,
                $den_factor_snapshot,
                round($qtaStock, 3),
                $uomResult['system_unit_price_stock'],
                $um_stock_id,
                $r['note_riga'] ?: null,
            ]);

            // Riserva quantità a magazzino
            if ($prodotto_id) {
                $pdo->prepare("
                    UPDATE giacenze
                       SET quantita_riservata = quantita_riservata + ?
                     WHERE prodotto_id = ?
                ")->execute([$qtaStock, $prodotto_id]);
            }
        }

        $pdo->commit();
        return $ordine_id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Ricerca prodotti con dettagli arricchiti per ordini di vendita.
 * Riusa la stessa query dei preventivi.
 */
function qk_search_prodotti_orders(PDO $pdo, string $q): array
{
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.nome AS label,
               p.sku_interno AS sku,
               um.codice AS unita_misura,
               p.unita_misura_stock_id,
               p.descrizione_confezione AS quantita_confezione,
               p.unita_contenuto_id,
               um_comm.codice AS unita_misura_commerciale,
               p.quantita_contenuto,
               pf.rrp_min,
               pf.rrp_max,
               pf.fornitore_id AS rrp_fornitore_id,
               CASE WHEN p.tipo = 'kit' THEN (
                   SELECT SUM(ck.quantita * (
                       SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp 
                       JOIN prodotti_fornitori pf2 ON pf2.id = pfp.prodotto_fornitore_id
                       WHERE pf2.prodotto_id = ck.componente_id AND pf2.attivo = 1 AND pfp.quantita_minima = 0
                       ORDER BY pf2.ordine_preferenza_fornitore ASC, pfp.id DESC LIMIT 1
                   ))
                   FROM componenti_kit ck
                   WHERE ck.kit_id = p.id
               ) ELSE (
                   SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp 
                   JOIN prodotti_fornitori pf2 ON pf2.id = pfp.prodotto_fornitore_id
                   WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1 AND pfp.quantita_minima = 0
                   ORDER BY pf2.ordine_preferenza_fornitore ASC, pfp.id DESC LIMIT 1
               ) END AS costo_base
          FROM prodotti p
          LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
          LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
          LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.is_principale = 1
         WHERE p.attivo = 1
           AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR pf.sku_fornitore LIKE :q)
         ORDER BY p.nome
         LIMIT 60
    ");
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalizza campi
    foreach ($rows as &$r) {
        $r['rrp_min']   = isset($r['rrp_min'])   ? (float)$r['rrp_min']   : null;
        $r['rrp_max']   = isset($r['rrp_max'])    ? (float)$r['rrp_max']   : null;
        $r['costo_base'] = isset($r['costo_base']) ? (float)$r['costo_base'] : 0.0;
        $r['quantita_contenuto'] = isset($r['quantita_contenuto']) ? (float)$r['quantita_contenuto'] : 1.0;
    }
    unset($r);
    return $rows;
}
