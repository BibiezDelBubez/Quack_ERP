<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';

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
               p.quantita_contenuto
        FROM prodotti p
        LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
        LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
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
            $totale_costo_riga = round($costo_unitario * $qta, 2);
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

            // Ottieni UoM id
            $um_codice = $r['unita_misura'] ?? 'pz';
            $stUm = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = ?');
            $stUm->execute([$um_codice]);
            $um_id = $stUm->fetchColumn() ?: null;

            // Calcola equivalenti in unità stock
            $quantita_stock = $qta;
            $unita_misura_stock_id = $um_id;
            if ($prodotto_id && $um_id) {
                $stProdUm = $pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id, quantita_contenuto FROM prodotti WHERE id = ?');
                $stProdUm->execute([$prodotto_id]);
                $prodUm = $stProdUm->fetch();
                if ($prodUm) {
                    $unita_misura_stock_id = (int)$prodUm['unita_misura_stock_id'];
                    $content_um_id = $prodUm['unita_contenuto_id'] ? (int)$prodUm['unita_contenuto_id'] : null;
                    $factor = $prodUm['quantita_contenuto'] ? (float)$prodUm['quantita_contenuto'] : 1.0;
                    if ((int)$um_id === $unita_misura_stock_id) {
                        $quantita_stock = $qta;
                    } elseif ($content_um_id !== null && (int)$um_id === $content_um_id && $factor > 0) {
                        $quantita_stock = round($qta / $factor, 3);
                    }
                }
            }

            $righe_preparate[] = [
                'prodotto_id' => $prodotto_id,
                'descrizione' => trim($r['descrizione']),
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

        // Inserimento testata
        $sqlHead = "INSERT INTO preventivi (
            numero_preventivo, cliente_id, alla_ca, data_offerta, data_scadenza, data_consegna_richiesta,
            canale_vendita_id, stato, sconto_globale_perc, condizioni_pagamento, trasporto, valuta,
            imponibile, totale_iva, totale, totale_costo, margine_euro, margine_perc,
            note_cliente, note_interne, creato_da, created_at, updated_at
        ) VALUES (
            :numero_preventivo, :cliente_id, :alla_ca, :data_offerta, :data_scadenza, :data_consegna_richiesta,
            :canale_vendita_id, :stato, :sconto_globale_perc, :condizioni_pagamento, :trasporto, 'EUR',
            :imponibile, :totale_iva, :totale, :totale_costo, :margine_euro, :margine_perc,
            :note_cliente, :note_interne, :creato_da, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";

        $stmtHead = $pdo->prepare($sqlHead);
        $stmtHead->execute([
            ':numero_preventivo'       => trim($values['numero_offerta']),
            ':cliente_id'              => (int)$values['cliente_id'],
            ':alla_ca'                 => qk_null_if_empty($values['alla_ca'] ?? ''),
            ':data_offerta'            => $values['data_offerta'],
            ':data_scadenza'           => qk_null_if_empty($values['data_scadenza'] ?? ''),
            ':data_consegna_richiesta' => qk_null_if_empty($values['data_consegna_richiesta'] ?? ''),
            ':canale_vendita_id'       => $canale_vendita_id,
            ':stato'                   => $values['stato'] ?: 'bozza',
            ':sconto_globale_perc'     => $sconto_globale_perc,
            ':condizioni_pagamento'    => qk_null_if_empty($values['condizioni_pagamento'] ?? ''),
            ':trasporto'               => qk_null_if_empty($values['trasporto'] ?? ''),
            ':imponibile'              => $imponibile,
            ':totale_iva'              => $totale_iva,
            ':totale'                  => $totale,
            ':totale_costo'            => $totale_costo,
            ':margine_euro'            => $margine_euro,
            ':margine_perc'            => $margine_perc,
            ':note_cliente'            => qk_null_if_empty($values['note_cliente'] ?? ''),
            ':note_interne'            => qk_null_if_empty($values['note_interne'] ?? ''),
            ':creato_da'               => $user_id,
        ]);

        $preventivo_id = (int)$pdo->lastInsertId();

        // Inserimento righe
        $sqlLine = "INSERT INTO preventivi_righe (
            preventivo_id, prodotto_id, descrizione, quantita, unita_misura_id, prezzo_unitario,
            sconto_perc, prezzo_netto, iva_perc, imponibile_riga, totale_riga, costo_unitario,
            totale_costo_riga, margine_riga, is_adr, quantita_stock, unita_misura_stock_id, posizione_riga, note_riga
        ) VALUES (
            :preventivo_id, :prodotto_id, :descrizione, :quantita, :unita_misura_id, :prezzo_unitario,
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
