<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';

/**
 * Genera il prossimo numero progressivo per il preventivo cliente.
 */
function qk_next_numero_offerta(PDO $pdo): string
{
    $anno = (int) date('Y');

    $st = $pdo->prepare(
        'SELECT id, prefisso, anno, numero_corrente, padding
           FROM sequenze WHERE tipo = ? LIMIT 1'
    );
    $st->execute(['preventivo']);
    $seq = $st->fetch(PDO::FETCH_ASSOC);

    if (!$seq) {
        throw new \RuntimeException("Sequenza 'preventivo' non trovata nella tabella sequenze.");
    }

    if ((int) $seq['anno'] !== $anno) {
        $pdo->prepare('UPDATE sequenze SET anno = ?, numero_corrente = 0 WHERE id = ?')
            ->execute([$anno, $seq['id']]);
        $seq['numero_corrente'] = 0;
    }

    $next = (int) $seq['numero_corrente'] + 1;
    $pdo->prepare('UPDATE sequenze SET numero_corrente = ? WHERE id = ?')
        ->execute([$next, $seq['id']]);

    $pad = (int) $seq['padding'];
    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
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
 * Recupera e restituisce i dati denormalizzati di azienda, cliente e consegna per l'integrità storica.
 */
function qk_denormalize_offerta_details(PDO $pdo, array $values): array
{
    $azienda = null;
    if (!empty($values['azienda_interna_id'])) {
        $stAz = $pdo->prepare("
            SELECT cf.ragione_sociale, cf.partita_iva, cf.codice_fiscale, cf.email, cf.telefono,
                   ind.via, ind.civico, ind.cap, ind.citta, ind.provincia, ind.nazione,
                   az.iban_default
            FROM aziende_interne az
            JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
            LEFT JOIN indirizzi ind ON ind.soggetto_id = cf.id AND ind.tipo = 'legale'
            WHERE az.id = ?
            LIMIT 1
        ");
        $stAz->execute([(int)$values['azienda_interna_id']]);
        $azienda = $stAz->fetch(PDO::FETCH_ASSOC);
    }

    $cliente = null;
    if (!empty($values['cliente_id'])) {
        $stCli = $pdo->prepare("
            SELECT cf.ragione_sociale, cf.nome, cf.cognome, cf.partita_iva, cf.codice_fiscale,
                   ind.via, ind.civico, ind.cap, ind.citta, ind.provincia, ind.nazione
            FROM clienti_fornitori cf
            LEFT JOIN indirizzi ind ON ind.soggetto_id = cf.id AND ind.tipo = 'legale'
            WHERE cf.id = ?
            LIMIT 1
        ");
        $stCli->execute([(int)$values['cliente_id']]);
        $cliente = $stCli->fetch(PDO::FETCH_ASSOC);
    }

    $consegna = null;
    if (!empty($values['consegna_via'])) {
        $consegna = [
            'via'                => $values['consegna_via'] ?? null,
            'civico'             => $values['consegna_civico'] ?? null,
            'cap'                => $values['consegna_cap'] ?? null,
            'citta'              => $values['consegna_citta'] ?? null,
            'provincia'          => $values['consegna_provincia'] ?? null,
            'nazione'            => $values['consegna_nazione'] ?? null,
            'presso'             => $values['consegna_presso'] ?? null,
            'referente_consegna' => $values['consegna_referente'] ?? null,
            'telefono_consegna'  => $values['consegna_telefono'] ?? null,
            'note_consegna'      => $values['consegna_note'] ?? null,
        ];
    } elseif (!empty($values['indirizzo_spedizione_id']) && is_numeric($values['indirizzo_spedizione_id'])) {
        $stInd = $pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
        $stInd->execute([(int)$values['indirizzo_spedizione_id']]);
        $consegna = $stInd->fetch(PDO::FETCH_ASSOC);
    }

    $azienda_ragione_sociale = $azienda['ragione_sociale'] ?? null;
    $azienda_partita_iva     = $azienda['partita_iva'] ?? null;
    $azienda_codice_fiscale  = $azienda['codice_fiscale'] ?? null;
    $azienda_indirizzo       = $azienda ? trim(($azienda['via'] ?? '') . ' ' . ($azienda['civico'] ?? '') . ', ' . ($azienda['cap'] ?? '') . ' ' . ($azienda['citta'] ?? '') . ' (' . ($azienda['provincia'] ?? '') . ')') : null;
    $azienda_telefono        = $azienda['telefono'] ?? null;
    $azienda_email           = $azienda['email'] ?? null;
    $azienda_iban            = $azienda['iban_default'] ?? null;

    $cliente_label = '';
    if ($cliente) {
        $cliente_label = $cliente['ragione_sociale'] ?: trim(($cliente['nome'] ?? '') . ' ' . ($cliente['cognome'] ?? ''));
    }
    $cliente_ragione_sociale = $cliente_label ?: null;
    $cliente_partita_iva     = $cliente['partita_iva'] ?? null;
    $cliente_codice_fiscale  = $cliente['codice_fiscale'] ?? null;
    $cliente_indirizzo       = $cliente ? trim(($cliente['via'] ?? '') . ' ' . ($cliente['civico'] ?? '') . ', ' . ($cliente['cap'] ?? '') . ' ' . ($cliente['citta'] ?? '') . ' (' . ($cliente['provincia'] ?? '') . ')') : null;

    return [
        'azienda_ragione_sociale' => $azienda_ragione_sociale,
        'azienda_partita_iva'     => $azienda_partita_iva,
        'azienda_codice_fiscale'  => $azienda_codice_fiscale,
        'azienda_indirizzo'       => $azienda_indirizzo,
        'azienda_telefono'        => $azienda_telefono,
        'azienda_email'           => $azienda_email,
        'azienda_iban'            => $azienda_iban,
        'cliente_ragione_sociale' => $cliente_ragione_sociale,
        'cliente_partita_iva'     => $cliente_partita_iva,
        'cliente_codice_fiscale'  => $cliente_codice_fiscale,
        'cliente_indirizzo'       => $cliente_indirizzo,
        'consegna_via'            => $consegna['via'] ?? null,
        'consegna_civico'         => $consegna['civico'] ?? null,
        'consegna_cap'            => $consegna['cap'] ?? null,
        'consegna_citta'          => $consegna['citta'] ?? null,
        'consegna_provincia'      => $consegna['provincia'] ?? null,
        'consegna_nazione'        => $consegna['nazione'] ?? null,
        'consegna_presso'         => $consegna['presso'] ?? null,
        'consegna_referente'      => $consegna['referente_consegna'] ?? null,
        'consegna_telefono'       => $consegna['telefono_consegna'] ?? null,
        'consegna_note'           => $consegna['note_consegna'] ?? null,
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

            // Calcola equivalenti in unità stock
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

            // Calcolo costo d'acquisto stimato (standard/minimo da listini fornitore)
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
                    SELECT COUNT(*) 
                    FROM prodotto_classificazioni_adr 
                    WHERE prodotto_id = ? AND e_corrente = 1
                ');
                $stAdr->execute([$prodotto_id]);
                $is_adr = $stAdr->fetchColumn() > 0 ? 1 : 0;
            }

            $righe_preparate[] = [
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
                'unita_misura_stock_id' => $unita_misura_stock_id,
                'posizione_riga' => $pos,
                'note_riga' => trim($r['note_riga'] ?? ''),
            ];

            $raw_imponibile += $imponibile_riga;
            $raw_iva += $iva_riga;
            $totale_costo += $totale_costo_riga;
        }

        // Applica sconto globale della testata
        $sconto_globale_perc = (float)($values['sconto_globale_perc'] ?? 0.0);
        $imponibile = round($raw_imponibile * (1 - $sconto_globale_perc / 100), 2);
        $totale_iva = round($raw_iva * (1 - $sconto_globale_perc / 100), 2);
        $totale = round($imponibile + $totale_iva, 2);
        $margine_euro = round($imponibile - $totale_costo, 2);
        $margine_perc = $imponibile > 0 ? round(($margine_euro / $imponibile) * 100, 2) : 0.0;

        $denorm = qk_denormalize_offerta_details($pdo, $values);

        // Inserimento testata
        $sqlHead = "INSERT INTO preventivi (
            numero_preventivo, riferimento_interno, cliente_id, azienda_interna_id, listino_id, alla_ca, contatto_id, contatto_nome, contatto_email, contatto_telefono, indirizzo_spedizione_id, data_offerta, data_scadenza, data_consegna_richiesta,
            canale_vendita_id, stato, sconto_globale_perc, condizioni_pagamento, trasporto, valuta,
            imponibile, totale_iva, totale, totale_costo, margine_euro, margine_perc,
            note_cliente, note_interne, oggetto, creato_da, created_at, updated_at,
            azienda_ragione_sociale, azienda_partita_iva, azienda_codice_fiscale, azienda_indirizzo, azienda_telefono, azienda_email, azienda_iban,
            cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
            consegna_via, consegna_civico, consegna_cap, consegna_citta, consegna_provincia, consegna_nazione, consegna_presso, consegna_referente, consegna_telefono, consegna_note
        ) VALUES (
            :numero_preventivo, :riferimento_interno, :cliente_id, :azienda_interna_id, :listino_id, :alla_ca, :contatto_id, :contatto_nome, :contatto_email, :contatto_telefono, :indirizzo_spedizione_id, :data_offerta, :data_scadenza, :data_consegna_richiesta,
            :canale_vendita_id, :stato, :sconto_globale_perc, :condizioni_pagamento, :trasporto, :valuta,
            :imponibile, :totale_iva, :totale, :totale_costo, :margine_euro, :margine_perc,
            :note_cliente, :note_interne, :oggetto, :creato_da, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
            :azienda_ragione_sociale, :azienda_partita_iva, :azienda_codice_fiscale, :azienda_indirizzo, :azienda_telefono, :azienda_email, :azienda_iban,
            :cliente_ragione_sociale, :cliente_partita_iva, :cliente_codice_fiscale, :cliente_indirizzo,
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
            totale_costo_riga, margine_riga, is_adr, quantita_stock, unita_misura_stock_id, posizione_riga, note_riga
        ) VALUES (
            :preventivo_id, :prodotto_id, :descrizione, :descrizione_confezione, :quantita, :unita_misura_id, :prezzo_unitario,
            :sconto_perc, :prezzo_netto, :iva_perc, :imponibile_riga, :totale_riga, :costo_unitario,
            :totale_costo_riga, :margine_riga, :is_adr, :quantita_stock, :unita_misura_stock_id, :posizione_riga, :note_riga
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
                ':unita_misura_stock_id' => $rl['unita_misura_stock_id'],
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
        $stCv = $pdo->prepare('SELECT id FROM canali_vendita WHERE codice = ?');
        $stCv->execute([$values['canale_vendita'] ?? 'email_diretta']);
        $canale_vendita_id = $stCv->fetchColumn() ?: null;

        $raw_imponibile = 0.0;
        $raw_iva = 0.0;
        $totale_costo = 0.0;

        $righe_preparate = [];
        foreach ($righe as $pos => $r) {
            $prodotto_id = $r['prodotto_id'] ? (int)$r['prodotto_id'] : null;
            $qta = (float)($r['quantita'] ?? 0);
            $pu = (float)($r['prezzo_unitario'] ?? 0);
            $sconto = (float)($r['sconto_perc'] ?? 0);
            $iva = (float)($r['iva_perc'] ?? 22);

            $prezzo_netto = round($pu * (1 - $sconto / 100), 4);
            $imponibile_riga = round($prezzo_netto * $qta, 2);
            $iva_riga = round($imponibile_riga * ($iva / 100), 2);
            $totale_riga = round($imponibile_riga + $iva_riga, 2);

            $um_codice = $r['unita_misura'] ?? 'pz';
            $stUm = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = ?');
            $stUm->execute([$um_codice]);
            $um_id = $stUm->fetchColumn() ?: null;

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

            $is_adr = 0;
            if ($prodotto_id) {
                $stAdr = $pdo->prepare('
                    SELECT COUNT(*) 
                    FROM prodotto_classificazioni_adr 
                    WHERE prodotto_id = ? AND e_corrente = 1
                ');
                $stAdr->execute([$prodotto_id]);
                $is_adr = $stAdr->fetchColumn() > 0 ? 1 : 0;
            }

            $righe_preparate[] = [
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
                'unita_misura_stock_id' => $unita_misura_stock_id,
                'posizione_riga' => $pos,
                'note_riga' => trim($r['note_riga'] ?? ''),
            ];

            $raw_imponibile += $imponibile_riga;
            $raw_iva += $iva_riga;
            $totale_costo += $totale_costo_riga;
        }

        $sconto_globale_perc = (float)($values['sconto_globale_perc'] ?? 0.0);
        $imponibile = round($raw_imponibile * (1 - $sconto_globale_perc / 100), 2);
        $totale_iva = round($raw_iva * (1 - $sconto_globale_perc / 100), 2);
        $totale = round($imponibile + $totale_iva, 2);
        $margine_euro = round($imponibile - $totale_costo, 2);
        $margine_perc = $imponibile > 0 ? round(($margine_euro / $imponibile) * 100, 2) : 0.0;

        $denorm = qk_denormalize_offerta_details($pdo, $values);

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
            totale_costo_riga, margine_riga, is_adr, quantita_stock, unita_misura_stock_id, posizione_riga, note_riga
        ) VALUES (
            :preventivo_id, :prodotto_id, :descrizione, :descrizione_confezione, :quantita, :unita_misura_id, :prezzo_unitario,
            :sconto_perc, :prezzo_netto, :iva_perc, :imponibile_riga, :totale_riga, :costo_unitario,
            :totale_costo_riga, :margine_riga, :is_adr, :quantita_stock, :unita_misura_stock_id, :posizione_riga, :note_riga
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
                ':unita_misura_stock_id' => $rl['unita_misura_stock_id'],
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
    $anno = (int) date('Y');

    $st = $pdo->prepare(
        'SELECT id, prefisso, anno, numero_corrente, padding
           FROM sequenze WHERE tipo = ? LIMIT 1'
    );
    $st->execute(['ordine_cliente']);
    $seq = $st->fetch(PDO::FETCH_ASSOC);

    if (!$seq) {
        throw new \RuntimeException("Sequenza 'ordine_cliente' non trovata nella tabella sequenze.");
    }

    if ((int) $seq['anno'] !== $anno) {
        $pdo->prepare('UPDATE sequenze SET anno = ?, numero_corrente = 0 WHERE id = ?')
            ->execute([$anno, $seq['id']]);
        $seq['numero_corrente'] = 0;
    }

    $next = (int) $seq['numero_corrente'] + 1;
    $pdo->prepare('UPDATE sequenze SET numero_corrente = ? WHERE id = ?')
        ->execute([$next, $seq['id']]);

    $pad = (int) $seq['padding'];
    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
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
        $columns = [];
        $stmtCol = $pdo->query("PRAGMA table_info(preventivi)");
        while ($col = $stmtCol->fetch(PDO::FETCH_ASSOC)) {
            if ($col['name'] === 'id') continue;
            $columns[] = $col['name'];
        }

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
        $righeCols = [];
        $stmtRigheCol = $pdo->query("PRAGMA table_info(preventivi_righe)");
        while ($col = $stmtRigheCol->fetch(PDO::FETCH_ASSOC)) {
            if ($col['name'] === 'id') continue;
            $righeCols[] = $col['name'];
        }

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
        $columns = [];
        $stmtCol = $pdo->query("PRAGMA table_info(preventivi)");
        while ($col = $stmtCol->fetch(PDO::FETCH_ASSOC)) {
            if ($col['name'] === 'id') continue;
            $columns[] = $col['name'];
        }

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
        $righeCols = [];
        $stmtRigheCol = $pdo->query("PRAGMA table_info(preventivi_righe)");
        while ($col = $stmtRigheCol->fetch(PDO::FETCH_ASSOC)) {
            if ($col['name'] === 'id') continue;
            $righeCols[] = $col['name'];
        }

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

        // 3. Create ordini_clienti head
        $sqlHead = "INSERT INTO ordini_clienti (
            numero_ordine, preventivo_id, cliente_id, data_ordine, data_consegna_prevista, stato, note, creato_da, created_at, updated_at
        ) VALUES (
            :numero_ordine, :preventivo_id, :cliente_id, CURRENT_DATE, :data_consegna_prevista, 'in_lavorazione', :note, :creato_da, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";
        $stHead = $pdo->prepare($sqlHead);
        $stHead->execute([
            ':numero_ordine'           => $numeroOrdine,
            ':preventivo_id'           => $id,
            ':cliente_id'              => $quota['cliente_id'],
            ':data_consegna_prevista'  => qk_null_if_empty($quota['data_consegna_richiesta'] ?? ''),
            ':note'                    => qk_null_if_empty($quota['note_cliente'] ?? ''),
            ':creato_da'               => $userId
        ]);
        $ordineId = (int)$pdo->lastInsertId();

        // 4. Create ordini_clienti_righe and reserve stock quantities
        $sqlLine = "INSERT INTO ordini_clienti_righe (
            ordine_id, preventivo_riga_id, prodotto_id, descrizione, quantita_ordinata, quantita_spedita, unita_misura_id, prezzo_unitario, costo_unitario, quantita_stock, unita_misura_stock_id, note
        ) VALUES (
            :ordine_id, :preventivo_riga_id, :prodotto_id, :descrizione, :quantita_ordinata, 0, :unita_misura_id, :prezzo_unitario, :costo_unitario, :quantita_stock, :unita_misura_stock_id, :note
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
                ':prezzo_unitario'    => $r['prezzo_unitario'],
                ':costo_unitario'     => $r['costo_unitario'],
                ':quantita_stock'     => $r['quantita_stock'],
                ':unita_misura_stock_id' => $r['unita_misura_stock_id'] ?: null,
                ':note'               => $r['note_riga'] ?? ''
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
