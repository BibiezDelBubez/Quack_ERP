<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';

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

        // ── Snapshot cliente ────────────────────────────────────────────────
        $stCli = $pdo->prepare(
            "SELECT cf.ragione_sociale, cf.nome, cf.cognome, cf.partita_iva, cf.codice_fiscale,
                    ia.via, ia.civico, ia.cap, ia.citta, ia.provincia, ia.nazione
               FROM clienti_fornitori cf
               LEFT JOIN indirizzi ia ON ia.soggetto_id = cf.id
                                     AND ia.tipo IN ('sede_legale','fatturazione')
                                     AND ia.predefinito = 1
              WHERE cf.id = ?
              LIMIT 1"
        );
        $stCli->execute([$values['cliente_id']]);
        $cli = $stCli->fetch(PDO::FETCH_ASSOC) ?: [];

        $ragione_sociale = $cli['ragione_sociale'] ?? trim(($cli['nome'] ?? '') . ' ' . ($cli['cognome'] ?? ''));
        $cliente_indirizzo = '';
        if (!empty($cli['via'])) {
            $cliente_indirizzo = trim(
                ($cli['via'] ?? '') . ' ' . ($cli['civico'] ?? '') . ', ' .
                ($cli['cap'] ?? '') . ' ' . ($cli['citta'] ?? '') .
                (!empty($cli['provincia']) ? ' (' . $cli['provincia'] . ')' : '')
            );
        }

        // ── Snapshot indirizzo spedizione (se selezionato da anagrafica) ────
        $indirizzo_spedizione_id = !empty($values['indirizzo_spedizione_id']) && is_numeric($values['indirizzo_spedizione_id'])
            ? (int)$values['indirizzo_spedizione_id']
            : null;

        if ($indirizzo_spedizione_id) {
            $stAddr = $pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
            $stAddr->execute([$indirizzo_spedizione_id]);
            $addr = $stAddr->fetch(PDO::FETCH_ASSOC);
            if ($addr) {
                // Override solo se i campi POST non hanno già sovrascrittura
                foreach (['via','civico','cap','citta','provincia','nazione','presso','note_consegna'] as $field) {
                    if (empty($values['consegna_' . ($field === 'note_consegna' ? 'note' : $field)])) {
                        $mapKey = $field === 'note_consegna' ? 'consegna_note' : 'consegna_' . $field;
                        $values[$mapKey] = $addr[$field] ?? '';
                    }
                }
                if (empty($values['consegna_referente'])) {
                    $values['consegna_referente'] = $addr['referente_consegna'] ?? '';
                }
                if (empty($values['consegna_telefono'])) {
                    $values['consegna_telefono'] = $addr['telefono_consegna'] ?? '';
                }
            }
        }

        // ── Inserimento testata ordine ───────────────────────────────────────
        $st = $pdo->prepare("
            INSERT INTO ordini_clienti (
                numero_ordine, cliente_id, preventivo_id, azienda_interna_id,
                data_ordine, data_consegna_prevista,
                stato, stato_fatturazione,
                riferimento_interno, condizioni_pagamento, trasporto, valuta,
                note,
                indirizzo_spedizione_id,
                consegna_via, consegna_civico, consegna_cap, consegna_citta,
                consegna_provincia, consegna_nazione, consegna_presso,
                consegna_referente, consegna_telefono, consegna_note,
                contatto_id, contatto_nome, contatto_email, contatto_telefono,
                cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
                creato_da, created_at, updated_at
            ) VALUES (
                ?, ?, NULL, ?,
                ?, ?,
                'in_lavorazione', 'da_fatturare',
                ?, ?, ?, ?,
                ?,
                ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
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
            $values['note'] ?: null,
            $indirizzo_spedizione_id,
            $values['consegna_via'] ?: null,
            $values['consegna_civico'] ?: null,
            $values['consegna_cap'] ?: null,
            $values['consegna_citta'] ?: null,
            $values['consegna_provincia'] ?: null,
            $values['consegna_nazione'] ?: 'IT',
            $values['consegna_presso'] ?: null,
            $values['consegna_referente'] ?: null,
            $values['consegna_telefono'] ?: null,
            $values['consegna_note'] ?: null,
            $values['contatto_id'] ?: null,
            $values['contatto_nome'] ?: null,
            $values['contatto_email'] ?: null,
            $values['contatto_telefono'] ?: null,
            $ragione_sociale ?: null,
            $cli['partita_iva'] ?? null,
            $cli['codice_fiscale'] ?? null,
            $cliente_indirizzo ?: null,
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
                quantita_stock, unita_misura_stock_id,
                quantita_spedita, note,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
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
            $um_id = !empty($r['uom_stock_id']) ? (int)$r['uom_stock_id']
                   : (!empty($r['unita_misura']) ? $getUomId($pdo, $r['unita_misura']) : null);
            $um_stock_id = $um_id;

            // Snapshot factor e quantità stock
            $factor = (float) ($r['quantita_contenuto'] ?? 1);
            if ($factor <= 0) $factor = 1;

            /*
             * =========================================================================
             * ARCHITECTURAL RULE / PRINCIPLE - MULTI-UOM & PRICING PRESERVATION:
             * To prevent precision drift and guarantee contract safety:
             * 1. Conversion factors between stock UoM and commercial UoM must be stored
             *    as exact, simplified integer fractions (num_factor / den_factor) in the database.
             * 2. Every transactional line snapshot (Offers, Sales/Purchase Orders, Receipts)
             *    must copy these factors at creation time into `num_factor_snapshot` and
             *    `den_factor_snapshot` to avoid historical data changes when products are edited.
             * 3. Do NOT use approximate decimal division or float conversion factors directly
             *    in transactions. Always reference the exact snapshot fraction.
             * =========================================================================
             */
            $num_factor_snapshot = 1;
            $den_factor_snapshot = 1;
            if ($prodotto_id) {
                $prodUm = qk_prodotto_get_uom_info($pdo, $prodotto_id);
                if ($prodUm) {
                    $num_factor_snapshot = $prodUm['num_factor'];
                    $den_factor_snapshot = $prodUm['den_factor'];
                }
            }

            // Determina se la riga è in UoM commerciale o stock
            $uom_comm = $r['uom_commercial'] ?? '';
            $uom_stock = $r['uom_stock'] ?? '';
            $qtaStock = ($uom_comm && $uom_comm !== $uom_stock && $factor > 1)
                ? $qta / $factor
                : $qta;

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
               (SELECT MIN(pfp.prezzo)
                  FROM prodotti_fornitori_prezzi pfp
                 WHERE pfp.prodotto_fornitore_id = pf.id
                   AND pfp.quantita_minima = 0) AS costo_base
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
