<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';

/**
 * Genera il prossimo numero progressivo per il preventivo cliente.
 */
function qk_next_numero_offerta(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'preventivo');
}

/**
 * Valida i dati del preventivo.
 */
function qk_offerta_validate(array $values, array $righe): array
{
    $errors = [];

    if (empty(trim($values['numero_offerta'] ?? ''))) {
        $errors['numero_offerta'] = 'Il numero preventivo è obbligatorio.';
    }
    if (empty($values['cliente_id'])) {
        $errors['cliente_id'] = 'Selezionare un cliente.';
    }
    if (empty($values['data_offerta'])) {
        $errors['data_offerta'] = 'La data offerta è obbligatoria.';
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
                $errors["righe_{$idx}_prezzo_unitario"] = 'Il prezzo unitario non può essere negativo.';
            }
        }
    }

    return $errors;
}

/**
 * Ricerca clienti per autocomplete.
 */
function qk_search_clienti(PDO $pdo, string $q): array
{
    $stmt = $pdo->prepare("
        SELECT id, 
               COALESCE(ragione_sociale, TRIM(COALESCE(nome, '') || ' ' || COALESCE(cognome, ''))) AS label,
               email, 
               telefono
        FROM clienti_fornitori
        WHERE tipo IN ('cliente', 'entrambi') 
          AND attivo = 1
          AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
          AND (ragione_sociale LIKE :q OR nome LIKE :q OR cognome LIKE :q OR email LIKE :q)
        ORDER BY label
        LIMIT 50
    ");
    $stmt->execute([':q' => '%' . $q . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ricerca prodotti con dettagli arricchiti per preventivi di vendita.
 */
function qk_search_prodotti_sales(PDO $pdo, string $q): array
{
    $stmt = $pdo->prepare("
        SELECT p.id, 
               p.nome AS label, 
               p.sku_interno AS sku, 
               p.categoria_id,
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
        LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id 
            AND pf.attivo = 1 
            AND pf.ordine_preferenza_fornitore = (
                SELECT MIN(pf2.ordine_preferenza_fornitore) 
                FROM prodotti_fornitori pf2 
                WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1
            )
        WHERE p.attivo = 1
          AND (p.nome LIKE :q OR p.sku_interno LIKE :q)
        ORDER BY p.nome
        LIMIT 50
    ");
    $stmt->execute([':q' => '%' . $q . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Creazione rapida cliente.
 */
function qk_crea_cliente_rapido(PDO $pdo, array $data): int
{
    $codice = qk_next_codice($pdo, 'CLI');
    
    $sql = 'INSERT INTO clienti_fornitori (
        codice, tipo, tipo_soggetto, ragione_sociale, nome, cognome, email, telefono, attivo, created_at, updated_at
    ) VALUES (
        :codice, \'cliente\', :tipo_soggetto, :ragione_sociale, :nome, :cognome, :email, :telefono, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':codice'          => $codice,
        ':tipo_soggetto'   => $data['tipo_soggetto'] ?? 'azienda',
        ':ragione_sociale' => qk_null_if_empty($data['ragione_sociale'] ?? ''),
        ':nome'            => qk_null_if_empty($data['nome'] ?? ''),
        ':cognome'         => qk_null_if_empty($data['cognome'] ?? ''),
        ':email'           => qk_null_if_empty($data['email'] ?? ''),
        ':telefono'        => qk_null_if_empty($data['telefono'] ?? ''),
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Creazione rapida prodotto.
 */
function qk_crea_prodotto_rapido(PDO $pdo, array $data): int
{
    $um = $data['unita_misura'] ?? 'pz';
    $stUm = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = ?');
    $stUm->execute([$um]);
    $um_id = $stUm->fetchColumn() ?: null;
    if (!$um_id) {
        $um_id = (int)$pdo->query('SELECT id FROM unita_misura LIMIT 1')->fetchColumn();
    }
    
    $sku = trim($data['sku_interno'] ?? '');
    if ($sku === '') {
        $sku = 'PRD-' . strtoupper(bin2hex(random_bytes(4)));
    }
    
    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO prodotti (
            sku_interno, nome, tipo, unita_misura_stock_id, attivo, created_at, updated_at
        ) VALUES (
            :sku_interno, :nome, 'semplice', :um_id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sku_interno' => $sku,
            ':nome' => trim($data['nome']),
            ':um_id' => $um_id,
        ]);
        $id = (int) $pdo->lastInsertId();
        
        $pdo->prepare(
            'INSERT INTO giacenze (prodotto_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, updated_at)
             VALUES (:id, 0, 0, 0, CURRENT_TIMESTAMP)'
        )->execute([':id' => $id]);
        
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


/**
 * Calcola i valori economici e logistici di una riga preventivo.
 */
function qk_calcola_riga_offerta(PDO $pdo, array $r, int $pos): array
{
    $prodotto_id = $r['prodotto_id'] ? (int)$r['prodotto_id'] : null;
    $qta = (float)($r['quantita'] ?? 0);
    $pu = (float)($r['prezzo_unitario'] ?? 0);
    $sconto = (float)($r['sconto_perc'] ?? 0);
    $iva = (float)($r['iva_perc'] ?? 22);

    $prezzo_netto = round($pu * (1 - $sconto / 100), 4);
    $imponibile_riga = round($prezzo_netto * $qta, 2);
    $iva_riga = round($imponibile_riga * ($iva / 100), 2);
    $totale_riga = round($imponibile_riga + $iva_riga, 2);

    // Ottieni UoM id
    $um_codice = $r['unita_misura'] ?? 'pz';
    $stUm = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = ?');
    $stUm->execute([$um_codice]);
    $um_id = $stUm->fetchColumn() ?: null;

    // Calcola equivalenti in unità stock (centralizzato in qk_uom_converti_riga)
    $uomResult = qk_uom_converti_riga($pdo, $prodotto_id, $um_id ? (int)$um_id : null, $qta, $pu);
    $quantita_stock              = $uomResult['quantita_stock'];
    $system_unit_price_stock     = $uomResult['system_unit_price_stock'];
    $unita_misura_stock_id       = $uomResult['unita_misura_stock_id'];
    $quantita_contenuto_snapshot = $uomResult['quantita_contenuto_snapshot'];
    $num_factor_snapshot         = $uomResult['num_factor_snapshot'];
    $den_factor_snapshot         = $uomResult['den_factor_snapshot'];

    // Calcolo costo d'acquisto stimato
    $costo_unitario = 0.0;
    if ($prodotto_id) {
        $stCost = $pdo->prepare('
            SELECT MIN(pfp.prezzo) 
            FROM prodotti_fornitori_prezzi pfp
            JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
            WHERE pf.prodotto_id = ? AND pf.attivo = 1
        ');
        $stCost->execute([$prodotto_id]);
        $costo_unitario = (float)($stCost->fetchColumn() ?? 0.0);
    }
    $totale_costo_riga = round($costo_unitario * $quantita_stock, 2);
    $margine_riga = round($imponibile_riga - $totale_costo_riga, 2);

    // Verifica classificazione ADR
    $is_adr = 0;
    if ($prodotto_id) {
        $stAdr = $pdo->prepare('
            SELECT COALESCE(is_adr, 0)
            FROM prodotti
            WHERE id = ?
        ');
        $stAdr->execute([$prodotto_id]);
        $is_adr = $stAdr->fetchColumn() > 0 ? 1 : 0;
    }

    return [
        'prodotto_id' => $prodotto_id,
        'descrizione' => trim($r['descrizione']),
        'descrizione_confezione' => isset($r['quantita_confezione']) ? trim($r['quantita_confezione']) : null,
        'quantita' => $qta,
        'unita_misura_id' => $um_id,
        'prezzo_unitario' => $pu,
        'sconto_perc' => $sconto,
        'prezzo_netto' => $prezzo_netto,
        'iva_perc' => $iva,
        'imponibile_riga' => $imponibile_riga,
        'totale_riga' => $totale_riga,
        'costo_unitario' => $costo_unitario,
        'totale_costo_riga' => $totale_costo_riga,
        'margine_riga' => $margine_riga,
        'is_adr' => $is_adr,
        'quantita_stock' => $quantita_stock,
        'system_unit_price_stock' => $system_unit_price_stock,
        'unita_misura_stock_id' => $unita_misura_stock_id,
        'quantita_contenuto_snapshot' => $quantita_contenuto_snapshot,
        'num_factor_snapshot' => $num_factor_snapshot,
        'den_factor_snapshot' => $den_factor_snapshot,
        'posizione_riga' => $pos,
        'note_riga' => trim($r['note_riga'] ?? ''),
        'iva_riga' => $iva_riga
    ];
}

/**
 * Inserimento preventivo e relative righe.
 */
function qk_insert_offerta(PDO $pdo, array $values, array $righe, int $user_id): int
{
    $pdo->beginTransaction();
    try {
        // Recupera canale_vendita_id
        $stCv = $pdo->prepare('SELECT id FROM canali_vendita WHERE codice = ?');
        $stCv->execute([$values['canale_vendita'] ?? 'email_diretta']);
        $canale_vendita_id = $stCv->fetchColumn() ?: null;

        // Inizializza contatori totali
        $raw_imponibile = 0.0;
        $raw_iva = 0.0;
        $totale_costo = 0.0;

        // Prepariamo i dati per ciascuna riga
        $righe_preparate = [];
        foreach ($righe as $pos => $r) {
            $r_calc = qk_calcola_riga_offerta($pdo, $r, $pos);
            $righe_preparate[] = $r_calc;

            $raw_imponibile += $r_calc['imponibile_riga'];
            $raw_iva += $r_calc['iva_riga'];
            $totale_costo += $r_calc['totale_costo_riga'];
        }

        // Applica sconto globale della testata
        $sconto_globale_perc = (float)($values['sconto_globale_perc'] ?? 0.0);
        $imponibile = round($raw_imponibile * (1 - $sconto_globale_perc / 100), 2);
        $totale_iva = round($raw_iva * (1 - $sconto_globale_perc / 100), 2);
        $totale = round($imponibile + $totale_iva, 2);
        $margine_euro = round($imponibile - $totale_costo, 2);
        $margine_perc = $imponibile > 0 ? round(($margine_euro / $imponibile) * 100, 2) : 0.0;

        $denorm = qk_denormalize_document_details($pdo, $values);

        // Creazione snapshot strutturati (indirizzo e contatto)
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

        // Inserimento testata
        $sqlHead = "INSERT INTO preventivi (
            numero_preventivo, riferimento_interno, cliente_id, azienda_interna_id, listino_id, alla_ca, contatto_id, contatto_nome, contatto_email, contatto_telefono, indirizzo_spedizione_id, data_offerta, data_scadenza, data_consegna_richiesta,
            canale_vendita_id, stato, sconto_globale_perc, condizioni_pagamento, trasporto, valuta,
            imponibile, totale_iva, totale, totale_costo, margine_euro, margine_perc,
            note_cliente, note_interne, oggetto, creato_da, created_at, updated_at,
            azienda_ragione_sociale, azienda_partita_iva, azienda_codice_fiscale, azienda_indirizzo, azienda_telefono, azienda_email, azienda_iban,
            cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
            indirizzo_consegna_id, contatto_snapshot_id,
            consegna_via, consegna_civico, consegna_cap, consegna_citta, consegna_provincia, consegna_nazione, consegna_presso, consegna_referente, consegna_telefono, consegna_note
        ) VALUES (
            :numero_preventivo, :riferimento_interno, :cliente_id, :azienda_interna_id, :listino_id, :alla_ca, :contatto_id, :contatto_nome, :contatto_email, :contatto_telefono, :indirizzo_spedizione_id, :data_offerta, :data_scadenza, :data_consegna_richiesta,
            :canale_vendita_id, :stato, :sconto_globale_perc, :condizioni_pagamento, :trasporto, :valuta,
            :imponibile, :totale_iva, :totale, :totale_costo, :margine_euro, :margine_perc,
            :note_cliente, :note_interne, :oggetto, :creato_da, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
            :azienda_ragione_sociale, :azienda_partita_iva, :azienda_codice_fiscale, :azienda_indirizzo, :azienda_telefono, :azienda_email, :azienda_iban,
            :cliente_ragione_sociale, :cliente_partita_iva, :cliente_codice_fiscale, :cliente_indirizzo,
            :indirizzo_consegna_id, :contatto_snapshot_id,
            :consegna_via, :consegna_civico, :consegna_cap, :consegna_citta, :consegna_provincia, :consegna_nazione, :consegna_presso, :consegna_referente, :consegna_telefono, :consegna_note
        )";

        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([
            ':numero_preventivo'       => trim($values['numero_offerta']),
            ':riferimento_interno'     => qk_null_if_empty($values['riferimento_interno'] ?? ''),
            ':cliente_id'              => (int)$values['cliente_id'],
            ':azienda_interna_id'      => !empty($values['azienda_interna_id']) ? (int)$values['azienda_interna_id'] : null,
            ':listino_id'              => !empty($values['listino_id']) ? (int)$values['listino_id'] : null,
            ':alla_ca'                 => qk_null_if_empty($values['alla_ca'] ?? ''),
            ':contatto_id'             => qk_null_if_empty($values['contatto_id'] ?? ''),
            ':contatto_nome'           => qk_null_if_empty($values['contatto_nome'] ?? ''),
            ':contatto_email'          => qk_null_if_empty($values['contatto_email'] ?? ''),
            ':contatto_telefono'       => qk_null_if_empty($values['contatto_telefono'] ?? ''),
            ':indirizzo_spedizione_id' => qk_null_if_empty($values['indirizzo_spedizione_id'] ?? ''),
            ':data_offerta'            => $values['data_offerta'],
            ':data_scadenza'           => qk_null_if_empty($values['data_scadenza'] ?? ''),
            ':data_consegna_richiesta' => qk_null_if_empty($values['data_consegna_richiesta'] ?? ''),
            ':canale_vendita_id'       => $canale_vendita_id,
            ':stato'                   => $values['stato'] ?: 'bozza',
            ':sconto_globale_perc'     => $sconto_globale_perc,
            ':condizioni_pagamento'    => qk_null_if_empty($values['condizioni_pagamento'] ?? ''),
            ':trasporto'               => qk_null_if_empty($values['trasporto'] ?? ''),
            ':valuta'                  => ($values['valuta'] ?? '') ?: 'EUR',
            ':imponibile'              => $imponibile,
            ':totale_iva'              => $totale_iva,
            ':totale'                  => $totale,
            ':totale_costo'            => $totale_costo,
            ':margine_euro'            => $margine_euro,
            ':margine_perc'            => $margine_perc,
            ':note_cliente'            => qk_null_if_empty($values['note_cliente'] ?? ''),
            ':note_interne'            => qk_null_if_empty($values['note_interne'] ?? ''),
            ':oggetto'                 => qk_null_if_empty($values['oggetto'] ?? ''),
            ':creato_da'               => $user_id,
            ':azienda_ragione_sociale' => $denorm['azienda_ragione_sociale'],
            ':azienda_partita_iva'     => $denorm['azienda_partita_iva'],
            ':azienda_codice_fiscale'  => $denorm['azienda_codice_fiscale'],
            ':azienda_indirizzo'       => $denorm['azienda_indirizzo'],
            ':azienda_telefono'        => $denorm['azienda_telefono'],
            ':azienda_email'           => $denorm['azienda_email'],
            ':azienda_iban'            => $denorm['azienda_iban'],
            ':cliente_ragione_sociale' => $denorm['cliente_ragione_sociale'],
            ':cliente_partita_iva'     => $denorm['cliente_partita_iva'],
            ':cliente_codice_fiscale'  => $denorm['cliente_codice_fiscale'],
            ':cliente_indirizzo'       => $denorm['cliente_indirizzo'],
            ':indirizzo_consegna_id'   => $indirizzo_consegna_id,
            ':contatto_snapshot_id'    => $contatto_snapshot_id,
            ':consegna_via'            => $denorm['consegna_via'],
            ':consegna_civico'         => $denorm['consegna_civico'],
            ':consegna_cap'            => $denorm['consegna_cap'],
            ':consegna_citta'          => $denorm['consegna_citta'],
            ':consegna_provincia'      => $denorm['consegna_provincia'],
            ':consegna_nazione'        => $denorm['consegna_nazione'],
            ':consegna_presso'         => $denorm['consegna_presso'],
            ':consegna_referente'      => $denorm['consegna_referente'],
            ':consegna_telefono'       => $denorm['consegna_telefono'],
            ':consegna_note'           => $denorm['consegna_note'],
        ]);

        $preventivo_id = (int)$pdo->lastInsertId();

        // Inserimento righe
        $sqlLine = "INSERT INTO preventivi_righe (
            preventivo_id, prodotto_id, descrizione, descrizione_confezione, quantita, unita_misura_id, prezzo_unitario,
            sconto_perc, prezzo_netto, iva_perc, imponibile_riga, totale_riga, costo_unitario,
            totale_costo_riga, margine_riga, is_adr, quantita_stock, system_unit_price_stock, unita_misura_stock_id, quantita_contenuto_snapshot,
            num_factor_snapshot, den_factor_snapshot, posizione_riga, note_riga
        ) VALUES (
            :preventivo_id, :prodotto_id, :descrizione, :descrizione_confezione, :quantita, :unita_misura_id, :prezzo_unitario,
            :sconto_perc, :prezzo_netto, :iva_perc, :imponibile_riga, :totale_riga, :costo_unitario,
            :totale_costo_riga, :margine_riga, :is_adr, :quantita_stock, :system_unit_price_stock, :unita_misura_stock_id, :quantita_contenuto_snapshot,
            :num_factor_snapshot, :den_factor_snapshot, :posizione_riga, :note_riga
        )";
        $stmtLine = $pdo->prepare($sqlLine);

        foreach ($righe_preparate as $rl) {
            $rl['preventivo_id'] = $preventivo_id;
            $stmtLine->execute([
                ':preventivo_id'     => $rl['preventivo_id'],
                ':prodotto_id'       => $rl['prodotto_id'],
                ':descrizione'       => $rl['descrizione'],
                ':descrizione_confezione' => $rl['descrizione_confezione'],
                ':quantita'          => $rl['quantita'],
                ':unita_misura_id'   => $rl['unita_misura_id'],
                ':prezzo_unitario'   => $rl['prezzo_unitario'],
                ':sconto_perc'       => $rl['sconto_perc'],
                ':prezzo_netto'      => $rl['prezzo_netto'],
                ':iva_perc'          => $rl['iva_perc'],
                ':imponibile_riga'   => $rl['imponibile_riga'],
                ':totale_riga'       => $rl['totale_riga'],
                ':costo_unitario'    => $rl['costo_unitario'],
                ':totale_costo_riga' => $rl['totale_costo_riga'],
                ':margine_riga'      => $rl['margine_riga'],
                ':is_adr'            => $rl['is_adr'],
                ':quantita_stock'    => $rl['quantita_stock'],
                ':system_unit_price_stock' => $rl['system_unit_price_stock'],
                ':unita_misura_stock_id' => $rl['unita_misura_stock_id'],
                ':quantita_contenuto_snapshot' => $rl['quantita_contenuto_snapshot'],
                ':num_factor_snapshot' => $rl['num_factor_snapshot'],
                ':den_factor_snapshot' => $rl['den_factor_snapshot'],
                ':posizione_riga'    => $rl['posizione_riga'],
                ':note_riga'         => $rl['note_riga'],
            ]);
        }

        $pdo->commit();
        return $preventivo_id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recupera un preventivo completo con le sue righe.
 */
function qk_get_offerta(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM preventivi WHERE id = ?');
    $st->execute([$id]);
    $quota = $st->fetch(PDO::FETCH_ASSOC);
    if (!$quota) return null;

    // Get client label
    $stCli = $pdo->prepare("SELECT COALESCE(ragione_sociale, TRIM(COALESCE(nome,'') || ' ' || COALESCE(cognome,''))) AS label FROM clienti_fornitori WHERE id = ?");
    $stCli->execute([(int)$quota['cliente_id']]);
    $quota['cliente_label'] = $stCli->fetchColumn() ?: '';

    // Get lines
    $stLines = $pdo->prepare('
        SELECT pr.*, 
               p.sku_interno AS sku,
               p.quantita_contenuto,
               CASE 
                   WHEN pr.descrizione_confezione IS NOT NULL AND pr.descrizione_confezione != \'\' THEN pr.descrizione_confezione
                   ELSE COALESCE(p.descrizione_confezione, (p.quantita_contenuto || \' \' || uc.codice)) 
               END AS quantita_confezione,
               um.codice AS unita_misura,
               us.codice AS uom_stock,
               uc.codice AS uom_commercial,
               pr.unita_misura_stock_id AS uom_stock_id,
               p.unita_contenuto_id AS uom_commercial_id,
               pf.rrp_min,
               pf.rrp_max,
               pr.costo_unitario AS costo_base
          FROM preventivi_righe pr
     LEFT JOIN prodotti p ON p.id = pr.prodotto_id
     LEFT JOIN unita_misura um ON um.id = pr.unita_misura_id
     LEFT JOIN unita_misura us ON us.id = pr.unita_misura_stock_id
     LEFT JOIN unita_misura uc ON uc.id = p.unita_contenuto_id
     LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = pr.prodotto_id AND pf.attivo = 1
        AND pf.ordine_preferenza_fornitore = (
            SELECT MIN(pf2.ordine_preferenza_fornitore)
            FROM prodotti_fornitori pf2
            WHERE pf2.prodotto_id = pr.prodotto_id AND pf2.attivo = 1
        )
         WHERE pr.preventivo_id = ?
      ORDER BY pr.posizione_riga ASC
    ');
    $stLines->execute([$id]);
    $righe = $stLines->fetchAll(PDO::FETCH_ASSOC);

    return [
        'offerta' => $quota,
        'righe' => $righe
    ];
}

/**
 * Aggiorna un preventivo esistente e le sue righe.
 */
function qk_update_offerta(PDO $pdo, int $id, array $values, array $righe, int $user_id): void
{
    $pdo->beginTransaction();
    try {
        // Enforce domain logic: verify the existing state of the quote before modifying it
        $stState = $pdo->prepare('SELECT stato FROM preventivi WHERE id = ?');
        $stState->execute([$id]);
        $oldState = $stState->fetchColumn();
        if ($oldState !== 'bozza') {
            throw new Exception("Impossibile modificare il preventivo: lo stato attuale è '$oldState'. Le modifiche sono consentite solo in stato 'bozza'.");
        }

        $stCv = $pdo->prepare('SELECT id FROM canali_vendita WHERE codice = ?');
        $stCv->execute([$values['canale_vendita'] ?? 'email_diretta']);
        $canale_vendita_id = $stCv->fetchColumn() ?: null;

        $raw_imponibile = 0.0;
        $raw_iva = 0.0;
        $totale_costo = 0.0;

        $righe_preparate = [];
        foreach ($righe as $pos => $r) {
            $r_calc = qk_calcola_riga_offerta($pdo, $r, $pos);
            $righe_preparate[] = $r_calc;

            $raw_imponibile += $r_calc['imponibile_riga'];
            $raw_iva += $r_calc['iva_riga'];
            $totale_costo += $r_calc['totale_costo_riga'];
        }

        $sconto_globale_perc = (float)($values['sconto_globale_perc'] ?? 0.0);
        $imponibile = round($raw_imponibile * (1 - $sconto_globale_perc / 100), 2);
        $totale_iva = round($raw_iva * (1 - $sconto_globale_perc / 100), 2);
        $totale = round($imponibile + $totale_iva, 2);
        $margine_euro = round($imponibile - $totale_costo, 2);
        $margine_perc = $imponibile > 0 ? round(($margine_euro / $imponibile) * 100, 2) : 0.0;

        $denorm = qk_denormalize_document_details($pdo, $values);

        // Fetch existing snapshot IDs
        $stOldSnap = $pdo->prepare("SELECT indirizzo_consegna_id, contatto_snapshot_id FROM preventivi WHERE id = ?");
        $stOldSnap->execute([$id]);
        $oldSnap = $stOldSnap->fetch(PDO::FETCH_ASSOC) ?: [];
        $existing_indirizzo_consegna_id = $oldSnap['indirizzo_consegna_id'] ?? null;
        $existing_contatto_snapshot_id = $oldSnap['contatto_snapshot_id'] ?? null;

        // Upsert snapshot strutturati
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
        $indirizzo_consegna_id = qk_upsert_document_address($pdo, $addrPayload, $existing_indirizzo_consegna_id);

        $contPayload = [
            'contatto_master_id' => $values['contatto_id'] ?? null,
            'nome' => $values['contatto_nome'] ?? '',
            'email' => $values['contatto_email'] ?? '',
            'telefono' => $values['contatto_telefono'] ?? '',
        ];
        $contatto_snapshot_id = qk_upsert_document_contact($pdo, $contPayload, $existing_contatto_snapshot_id);

        $sqlUpdateHead = "UPDATE preventivi SET
            cliente_id = :cliente_id,
            azienda_interna_id = :azienda_interna_id,
            listino_id = :listino_id,
            alla_ca = :alla_ca,
            contatto_id = :contatto_id,
            contatto_nome = :contatto_nome,
            contatto_email = :contatto_email,
            contatto_telefono = :contatto_telefono,
            indirizzo_spedizione_id = :indirizzo_spedizione_id,
            data_offerta = :data_offerta,
            data_scadenza = :data_scadenza,
            data_consegna_richiesta = :data_consegna_richiesta,
            canale_vendita_id = :canale_vendita_id,
            stato = :stato,
            sconto_globale_perc = :sconto_globale_perc,
            condizioni_pagamento = :condizioni_pagamento,
            trasporto = :trasporto,
            valuta = :valuta,
            imponibile = :imponibile,
            totale_iva = :totale_iva,
            totale = :totale,
            totale_costo = :totale_costo,
            margine_euro = :margine_euro,
            margine_perc = :margine_perc,
            note_cliente = :note_cliente,
            note_interne = :note_interne,
            oggetto = :oggetto,
            riferimento_interno = :riferimento_interno,
            azienda_ragione_sociale = :azienda_ragione_sociale,
            azienda_partita_iva = :azienda_partita_iva,
            azienda_codice_fiscale = :azienda_codice_fiscale,
            azienda_indirizzo = :azienda_indirizzo,
            azienda_telefono = :azienda_telefono,
            azienda_email = :azienda_email,
            azienda_iban = :azienda_iban,
            cliente_ragione_sociale = :cliente_ragione_sociale,
            cliente_partita_iva = :cliente_partita_iva,
            cliente_codice_fiscale = :cliente_codice_fiscale,
            cliente_indirizzo = :cliente_indirizzo,
            indirizzo_consegna_id = :indirizzo_consegna_id,
            contatto_snapshot_id = :contatto_snapshot_id,
            consegna_via = :consegna_via,
            consegna_civico = :consegna_civico,
            consegna_cap = :consegna_cap,
            consegna_citta = :consegna_citta,
            consegna_provincia = :consegna_provincia,
            consegna_nazione = :consegna_nazione,
            consegna_presso = :consegna_presso,
            consegna_referente = :consegna_referente,
            consegna_telefono = :consegna_telefono,
            consegna_note = :consegna_note,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id";

        $stmt = $pdo->prepare($sqlUpdateHead);
        $stmt->execute([
            ':id'                      => $id,
            ':cliente_id'              => (int)$values['cliente_id'],
            ':azienda_interna_id'      => !empty($values['azienda_interna_id']) ? (int)$values['azienda_interna_id'] : null,
            ':listino_id'              => !empty($values['listino_id']) ? (int)$values['listino_id'] : null,
            ':alla_ca'                 => qk_null_if_empty($values['alla_ca'] ?? ''),
            ':contatto_id'             => qk_null_if_empty($values['contatto_id'] ?? ''),
            ':contatto_nome'           => qk_null_if_empty($values['contatto_nome'] ?? ''),
            ':contatto_email'          => qk_null_if_empty($values['contatto_email'] ?? ''),
            ':contatto_telefono'       => qk_null_if_empty($values['contatto_telefono'] ?? ''),
            ':indirizzo_spedizione_id' => qk_null_if_empty($values['indirizzo_spedizione_id'] ?? ''),
            ':data_offerta'            => $values['data_offerta'],
            ':data_scadenza'           => qk_null_if_empty($values['data_scadenza'] ?? ''),
            ':data_consegna_richiesta' => qk_null_if_empty($values['data_consegna_richiesta'] ?? ''),
            ':canale_vendita_id'       => $canale_vendita_id,
            ':stato'                   => $values['stato'] ?: 'bozza',
            ':sconto_globale_perc'     => $sconto_globale_perc,
            ':condizioni_pagamento'    => qk_null_if_empty($values['condizioni_pagamento'] ?? ''),
            ':trasporto'               => qk_null_if_empty($values['trasporto'] ?? ''),
            ':valuta'                  => ($values['valuta'] ?? '') ?: 'EUR',
            ':imponibile'              => $imponibile,
            ':totale_iva'              => $totale_iva,
            ':totale'                  => $totale,
            ':totale_costo'            => $totale_costo,
            ':margine_euro'            => $margine_euro,
            ':margine_perc'            => $margine_perc,
            ':note_cliente'            => qk_null_if_empty($values['note_cliente'] ?? ''),
            ':note_interne'            => qk_null_if_empty($values['note_interne'] ?? ''),
            ':oggetto'                 => qk_null_if_empty($values['oggetto'] ?? ''),
            ':riferimento_interno'     => qk_null_if_empty($values['riferimento_interno'] ?? ''),
            ':azienda_ragione_sociale' => $denorm['azienda_ragione_sociale'],
            ':azienda_partita_iva'     => $denorm['azienda_partita_iva'],
            ':azienda_codice_fiscale'  => $denorm['azienda_codice_fiscale'],
            ':azienda_indirizzo'       => $denorm['azienda_indirizzo'],
            ':azienda_telefono'        => $denorm['azienda_telefono'],
            ':azienda_email'           => $denorm['azienda_email'],
            ':azienda_iban'            => $denorm['azienda_iban'],
            ':cliente_ragione_sociale' => $denorm['cliente_ragione_sociale'],
            ':cliente_partita_iva'     => $denorm['cliente_partita_iva'],
            ':cliente_codice_fiscale'  => $denorm['cliente_codice_fiscale'],
            ':cliente_indirizzo'       => $denorm['cliente_indirizzo'],
            ':indirizzo_consegna_id'   => $indirizzo_consegna_id,
            ':contatto_snapshot_id'    => $contatto_snapshot_id,
            ':consegna_via'            => $denorm['consegna_via'],
            ':consegna_civico'         => $denorm['consegna_civico'],
            ':consegna_cap'            => $denorm['consegna_cap'],
            ':consegna_citta'          => $denorm['consegna_citta'],
            ':consegna_provincia'      => $denorm['consegna_provincia'],
            ':consegna_nazione'        => $denorm['consegna_nazione'],
            ':consegna_presso'         => $denorm['consegna_presso'],
            ':consegna_referente'      => $denorm['consegna_referente'],
            ':consegna_telefono'       => $denorm['consegna_telefono'],
            ':consegna_note'           => $denorm['consegna_note'],
        ]);

        $pdo->prepare('DELETE FROM preventivi_righe WHERE preventivo_id = ?')->execute([$id]);

        $sqlLine = "INSERT INTO preventivi_righe (
            preventivo_id, prodotto_id, descrizione, descrizione_confezione, quantita, unita_misura_id, prezzo_unitario,
            sconto_perc, prezzo_netto, iva_perc, imponibile_riga, totale_riga, costo_unitario,
            totale_costo_riga, margine_riga, is_adr, quantita_stock, system_unit_price_stock, unita_misura_stock_id, quantita_contenuto_snapshot,
            num_factor_snapshot, den_factor_snapshot, posizione_riga, note_riga
        ) VALUES (
            :preventivo_id, :prodotto_id, :descrizione, :descrizione_confezione, :quantita, :unita_misura_id, :prezzo_unitario,
            :sconto_perc, :prezzo_netto, :iva_perc, :imponibile_riga, :totale_riga, :costo_unitario,
            :totale_costo_riga, :margine_riga, :is_adr, :quantita_stock, :system_unit_price_stock, :unita_misura_stock_id, :quantita_contenuto_snapshot,
            :num_factor_snapshot, :den_factor_snapshot, :posizione_riga, :note_riga
        )";

        $stmtLine = $pdo->prepare($sqlLine);
        foreach ($righe_preparate as $rl) {
            $stmtLine->execute([
                ':preventivo_id'     => $id,
                ':prodotto_id'       => $rl['prodotto_id'],
                ':descrizione'       => $rl['descrizione'],
                ':descrizione_confezione' => $rl['descrizione_confezione'],
                ':quantita'          => $rl['quantita'],
                ':unita_misura_id'   => $rl['unita_misura_id'],
                ':prezzo_unitario'   => $rl['prezzo_unitario'],
                ':sconto_perc'       => $rl['sconto_perc'],
                ':prezzo_netto'      => $rl['prezzo_netto'],
                ':iva_perc'          => $rl['iva_perc'],
                ':imponibile_riga'   => $rl['imponibile_riga'],
                ':totale_riga'       => $rl['totale_riga'],
                ':costo_unitario'    => $rl['costo_unitario'],
                ':totale_costo_riga' => $rl['totale_costo_riga'],
                ':margine_riga'      => $rl['margine_riga'],
                ':is_adr'            => $rl['is_adr'],
                ':quantita_stock'    => $rl['quantita_stock'],
                ':system_unit_price_stock' => $rl['system_unit_price_stock'],
                ':unita_misura_stock_id' => $rl['unita_misura_stock_id'],
                ':quantita_contenuto_snapshot' => $rl['quantita_contenuto_snapshot'],
                ':num_factor_snapshot' => $rl['num_factor_snapshot'],
                ':den_factor_snapshot' => $rl['den_factor_snapshot'],
                ':posizione_riga'    => $rl['posizione_riga'],
                ':note_riga'         => $rl['note_riga'],
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Genera il prossimo numero progressivo per l'ordine cliente.
 */
function qk_next_numero_ordine_cliente(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'ordine_cliente');
}

/**
 * Crea una nuova versione (revisione) del preventivo.
 */
function qk_preventivo_nuova_versione(PDO $pdo, int $id, int $userId): int
{
    $pdo->beginTransaction();
    try {
        // 1. Get original quote
        $st = $pdo->prepare("SELECT * FROM preventivi WHERE id = ?");
        $st->execute([$id]);
        $orig = $st->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            throw new Exception("Preventivo originale non trovato.");
        }

        // 2. Mark original quote as storico = 1, stato = 'annullata'
        $stUp = $pdo->prepare("UPDATE preventivi SET storico = 1, stato = 'annullata', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stUp->execute([$id]);

        // 3. Compute new revision number
        $origNum = $orig['numero_preventivo'];
        if (preg_match('/-(\d+)$/', $origNum, $matches)) {
            $rev = (int)$matches[1] + 1;
            $newNum = preg_replace('/-\d+$/', '-' . sprintf('%02d', $rev), $origNum);
        } else {
            $newNum = $origNum . '-02';
        }

        // 4. Create new quote record in Bozza status (storico = 0, stato = 'bozza')
        $columns = array_filter(db_table_columns($pdo, 'preventivi'), fn($c) => $c !== 'id');

        $colList = implode(", ", $columns);
        $insertSql = "INSERT INTO preventivi ($colList) SELECT $colList FROM preventivi WHERE id = :orig_id";
        $pdo->prepare($insertSql)->execute([':orig_id' => $id]);
        $newId = (int)$pdo->lastInsertId();

        // 5. Update new quote specifics
        $stUpNew = $pdo->prepare("
            UPDATE preventivi SET
                numero_preventivo = :newNum,
                stato = 'bozza',
                storico = 0,
                creato_da = :userId,
                created_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :newId
        ");
        $stUpNew->execute([
            ':newNum' => $newNum,
            ':userId' => $userId,
            ':newId'  => $newId
        ]);

        // 6. Copy righe items from original quote
        $righeCols = array_filter(db_table_columns($pdo, 'preventivi_righe'), fn($c) => $c !== 'id');

        $righeColsWithoutPrev = array_filter($righeCols, fn($c) => $c !== 'preventivo_id');
        $colListRighe = implode(", ", $righeColsWithoutPrev);
        
        $insertRigheSql = "
            INSERT INTO preventivi_righe (preventivo_id, $colListRighe)
            SELECT :newId, $colListRighe FROM preventivi_righe WHERE preventivo_id = :orig_id
        ";
        $pdo->prepare($insertRigheSql)->execute([
            ':newId'   => $newId,
            ':orig_id' => $id
        ]);

        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Duplica un preventivo in un nuovo preventivo in stato Bozza.
 */
function qk_preventivo_duplica(PDO $pdo, int $id, int $userId): int
{
    $pdo->beginTransaction();
    try {
        // 1. Get original quote
        $st = $pdo->prepare("SELECT * FROM preventivi WHERE id = ?");
        $st->execute([$id]);
        $orig = $st->fetch(PDO::FETCH_ASSOC);
        if (!$orig) {
            throw new Exception("Preventivo originale non trovato.");
        }

        // 2. Generate new quote number
        $newNum = qk_next_numero_offerta($pdo);

        // 3. Create list of columns
        $columns = array_filter(db_table_columns($pdo, 'preventivi'), fn($c) => $c !== 'id');

        $colList = implode(", ", $columns);
        $insertSql = "INSERT INTO preventivi ($colList) SELECT $colList FROM preventivi WHERE id = :orig_id";
        $pdo->prepare($insertSql)->execute([':orig_id' => $id]);
        $newId = (int)$pdo->lastInsertId();

        // 4. Update new quote specifics
        $stUpNew = $pdo->prepare("
            UPDATE preventivi SET
                numero_preventivo = :newNum,
                stato = 'bozza',
                storico = 0,
                creato_da = :userId,
                created_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :newId
        ");
        $stUpNew->execute([
            ':newNum' => $newNum,
            ':userId' => $userId,
            ':newId'  => $newId
        ]);

        // 5. Copy righe items from original quote
        $righeCols = array_filter(db_table_columns($pdo, 'preventivi_righe'), fn($c) => $c !== 'id');

        $righeColsWithoutPrev = array_filter($righeCols, fn($c) => $c !== 'preventivo_id');
        $colListRighe = implode(", ", $righeColsWithoutPrev);
        
        $insertRigheSql = "
            INSERT INTO preventivi_righe (preventivo_id, $colListRighe)
            SELECT :newId, $colListRighe FROM preventivi_righe WHERE preventivo_id = :orig_id
        ";
        $pdo->prepare($insertRigheSql)->execute([
            ':newId'   => $newId,
            ':orig_id' => $id
        ]);

        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


/**
 * Accetta un preventivo e crea il relativo ordine cliente.
 */
function qk_preventivo_conferma_ordine(PDO $pdo, int $id, int $userId): int
{
    $pdo->beginTransaction();
    try {
        $detail = qk_get_offerta($pdo, $id);
        if (!$detail) {
            throw new Exception("Preventivo non trovato.");
        }
        $quota = $detail['offerta'];
        $righe = $detail['righe'];

        if ($quota['stato'] === 'accettata') {
            throw new Exception("Il preventivo è già stato accettato.");
        }

        // 1. Update preventivo status to 'accettata'
        $stUp = $pdo->prepare("UPDATE preventivi SET stato = 'accettata', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stUp->execute([$id]);

        // 2. Generate ODC number
        $numeroOrdine = qk_next_numero_ordine_cliente($pdo);

        // 2a. Check and generate snapshot IDs if missing (for older quotes)
        $indirizzo_consegna_id = $quota['indirizzo_consegna_id'] ?? null;
        if (!$indirizzo_consegna_id && !empty($quota['consegna_via'])) {
            $addrPayload = [
                'indirizzo_master_id' => $quota['indirizzo_spedizione_id'] ?? null,
                'via' => $quota['consegna_via'],
                'civico' => $quota['consegna_civico'] ?? '',
                'cap' => $quota['consegna_cap'] ?? '',
                'citta' => $quota['consegna_citta'] ?? '',
                'provincia' => $quota['consegna_provincia'] ?? '',
                'nazione' => $quota['consegna_nazione'] ?? 'IT',
                'presso' => $quota['consegna_presso'] ?? '',
                'referente_consegna' => $quota['consegna_referente'] ?? '',
                'telefono_consegna' => $quota['consegna_telefono'] ?? '',
                'note_consegna' => $quota['consegna_note'] ?? '',
            ];
            $indirizzo_consegna_id = qk_upsert_document_address($pdo, $addrPayload);
            // Optionally update the preventivo with this ID to avoid recreating it
            $pdo->prepare("UPDATE preventivi SET indirizzo_consegna_id = ? WHERE id = ?")->execute([$indirizzo_consegna_id, $id]);
        }

        $contatto_snapshot_id = $quota['contatto_snapshot_id'] ?? null;
        if (!$contatto_snapshot_id && !empty($quota['contatto_nome'])) {
            $contPayload = [
                'contatto_master_id' => $quota['contatto_id'] ?? null,
                'nome' => $quota['contatto_nome'] ?? '',
                'email' => $quota['contatto_email'] ?? '',
                'telefono' => $quota['contatto_telefono'] ?? '',
            ];
            $contatto_snapshot_id = qk_upsert_document_contact($pdo, $contPayload);
            $pdo->prepare("UPDATE preventivi SET contatto_snapshot_id = ? WHERE id = ?")->execute([$contatto_snapshot_id, $id]);
        }

        // 3. Create ordini_clienti head
        $sqlHead = "INSERT INTO ordini_clienti (
            numero_ordine, preventivo_id, cliente_id, azienda_interna_id, data_ordine, data_consegna_prevista,
            stato, stato_fatturazione, riferimento_interno, condizioni_pagamento, trasporto, valuta,
            listino_id, note, indirizzo_spedizione_id,
            consegna_via, consegna_civico, consegna_cap, consegna_citta,
            consegna_provincia, consegna_nazione, consegna_presso,
            consegna_referente, consegna_telefono, consegna_note,
            contatto_id, contatto_nome, contatto_email, contatto_telefono,
            indirizzo_consegna_id, contatto_snapshot_id,
            cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
            creato_da, created_at, updated_at
        ) VALUES (
            :numero_ordine, :preventivo_id, :cliente_id, :azienda_interna_id, CURRENT_DATE, :data_consegna_prevista,
            'in_lavorazione', 'da_fatturare', :riferimento_interno, :condizioni_pagamento, :trasporto, :valuta,
            :listino_id, :note, :indirizzo_spedizione_id,
            :consegna_via, :consegna_civico, :consegna_cap, :consegna_citta,
            :consegna_provincia, :consegna_nazione, :consegna_presso,
            :consegna_referente, :consegna_telefono, :consegna_note,
            :contatto_id, :contatto_nome, :contatto_email, :contatto_telefono,
            :indirizzo_consegna_id, :contatto_snapshot_id,
            :cliente_ragione_sociale, :cliente_partita_iva, :cliente_codice_fiscale, :cliente_indirizzo,
            :creato_da, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";
        $stHead = $pdo->prepare($sqlHead);
        $stHead->execute([
            ':numero_ordine'           => $numeroOrdine,
            ':preventivo_id'           => $id,
            ':cliente_id'              => $quota['cliente_id'],
            ':azienda_interna_id'      => $quota['azienda_interna_id'] ?: null,
            ':data_consegna_prevista'  => qk_null_if_empty($quota['data_consegna_richiesta'] ?? ''),
            ':riferimento_interno'     => $quota['riferimento_interno'] ?: null,
            ':condizioni_pagamento'    => $quota['condizioni_pagamento'] ?: null,
            ':trasporto'               => $quota['trasporto'] ?: null,
            ':valuta'                  => $quota['valuta'] ?: 'EUR',
            ':listino_id'              => !empty($quota['listino_id']) ? (int)$quota['listino_id'] : (int)($pdo->query("SELECT id FROM listini WHERE is_default = 1 AND attivo = 1 LIMIT 1")->fetchColumn() ?: 0),
            ':note'                    => qk_null_if_empty($quota['note_cliente'] ?? ''),
            ':indirizzo_spedizione_id' => $quota['indirizzo_spedizione_id'] ?: null,
            ':consegna_via'            => $quota['consegna_via'] ?: null,
            ':consegna_civico'         => $quota['consegna_civico'] ?: null,
            ':consegna_cap'            => $quota['consegna_cap'] ?: null,
            ':consegna_citta'          => $quota['consegna_citta'] ?: null,
            ':consegna_provincia'      => $quota['consegna_provincia'] ?: null,
            ':consegna_nazione'        => $quota['consegna_nazione'] ?: 'IT',
            ':consegna_presso'         => $quota['consegna_presso'] ?: null,
            ':consegna_referente'      => $quota['consegna_referente'] ?: null,
            ':consegna_telefono'       => $quota['consegna_telefono'] ?: null,
            ':consegna_note'           => $quota['consegna_note'] ?: null,
            ':contatto_id'             => $quota['contatto_id'] ?: null,
            ':contatto_nome'           => $quota['contatto_nome'] ?: null,
            ':contatto_email'          => $quota['contatto_email'] ?: null,
            ':contatto_telefono'       => $quota['contatto_telefono'] ?: null,
            ':indirizzo_consegna_id'   => $indirizzo_consegna_id ?: null,
            ':contatto_snapshot_id'    => $contatto_snapshot_id ?: null,
            ':cliente_ragione_sociale' => $quota['cliente_ragione_sociale'] ?: null,
            ':cliente_partita_iva'     => $quota['cliente_partita_iva'] ?: null,
            ':cliente_codice_fiscale'  => $quota['cliente_codice_fiscale'] ?: null,
            ':cliente_indirizzo'       => $quota['cliente_indirizzo'] ?: null,
            ':creato_da'               => $userId
        ]);
        $ordineId = (int)$pdo->lastInsertId();

        // 4. Create ordini_clienti_righe and reserve stock quantities
        $sqlLine = "INSERT INTO ordini_clienti_righe (
            ordine_id, preventivo_riga_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita,
            unita_misura_id, prezzo_unitario, sconto_perc, iva_perc, costo_unitario,
            quantita_stock, unita_misura_stock_id, quantita_contenuto_snapshot,
            num_factor_snapshot, den_factor_snapshot, quantita_confezione, note
        ) VALUES (
            :ordine_id, :preventivo_riga_id, :prodotto_id, :descrizione, :quantita_ordinata, 0,
            :unita_misura_id, :prezzo_unitario, :sconto_perc, :iva_perc, :costo_unitario,
            :quantita_stock, :unita_misura_stock_id, :quantita_contenuto_snapshot,
            :num_factor_snapshot, :den_factor_snapshot, :quantita_confezione, :note
        )";
        $stLine = $pdo->prepare($sqlLine);

        $stRes = $pdo->prepare("
            UPDATE giacenze 
            SET quantita_riservata = COALESCE(quantita_riservata, 0) + ? 
            WHERE prodotto_id = ?
        ");

        foreach ($righe as $r) {
            $stLine->execute([
                ':ordine_id'          => $ordineId,
                ':preventivo_riga_id' => $r['id'],
                ':prodotto_id'        => $r['prodotto_id'] ?: null,
                ':descrizione'        => $r['descrizione'],
                ':quantita_ordinata'  => $r['quantita'],
                ':unita_misura_id'    => $r['unita_misura_id'] ?: null,
                ':prezzo_unitario'    => $r['prezzo_unitario'] ?? 0,
                ':sconto_perc'        => $r['sconto_perc'] ?? 0,
                ':iva_perc'           => $r['iva_perc'] ?? 22,
                ':costo_unitario'     => $r['costo_unitario'] ?? 0,
                ':quantita_stock'     => $r['quantita_stock'],
                ':unita_misura_stock_id' => $r['unita_misura_stock_id'] ?: null,
                ':quantita_contenuto_snapshot' => $r['quantita_contenuto_snapshot'] ?? 1.0,
                ':num_factor_snapshot' => $r['num_factor_snapshot'] ?? 1,
                ':den_factor_snapshot' => $r['den_factor_snapshot'] ?? 1,
                ':quantita_confezione' => $r['quantita_confezione'] ?? null,
                ':note'               => qk_null_if_empty($r['note_riga'] ?? '')
            ]);

            // Reserve stock
            if (!empty($r['prodotto_id']) && (float)$r['quantita_stock'] > 0) {
                // Ensure giacenze row exists
                $stCheckGia = $pdo->prepare("SELECT COUNT(*) FROM giacenze WHERE prodotto_id = ?");
                $stCheckGia->execute([$r['prodotto_id']]);
                if ((int)$stCheckGia->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO giacenze (prodotto_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, updated_at) VALUES (?, 0, 0, 0, CURRENT_TIMESTAMP)")
                        ->execute([$r['prodotto_id']]);
                }
                $stRes->execute([(float)$r['quantita_stock'], (int)$r['prodotto_id']]);
            }
        }

        $pdo->commit();
        return $ordineId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
