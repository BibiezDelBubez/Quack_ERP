<?php
/**
 * Motore di pricing (vendite e acquisti) — estratto il 14/07/2026 da
 * SalesPricelistsController::getSalePrice() e PurchasesController::getPurchasePrice()
 * (refactoring puro, nessuna modifica di comportamento: stessa logica, stessi parametri,
 * stesso valore di ritorno — solo spostata fuori dai controller HTTP).
 *
 * Motivo del refactoring: prima di oggi questa logica era raggiungibile SOLO tramite i
 * due controller (istanza con $this->pdo), quindi era impossibile calcolare un prezzo
 * da uno script CLI, un cron, un job di ricalcolo massivo, o un altro modulo (dashboard,
 * report marginalità) senza montare uno pseudo-request HTTP. Ora le due funzioni sono
 * pure: prendono PDO + parametri, ritornano un array, nessun riferimento a $_GET/$_POST/
 * sessione/header HTTP.
 *
 * I controller restano thin wrapper (vedi SalesPricelistsController::getSalePrice() e
 * PurchasesController::getPurchasePrice()), così nessun chiamante esistente va toccato.
 */

declare(strict_types=1);

/**
 * Determina il prezzo di vendita per un prodotto dato un listino, una quantità e una data.
 * Strategia (analoga a qk_get_purchase_price):
 *  1. Cerca nei listini associati del cliente (in ordine di priorità) o nel listino specificato
 *  2. Se non trova, cade sul listino standard o ricarico su costo
 *
 * CONTRATTO (D3.1, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
 * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
 * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
 * attuale, SalesPricelistsController::apiOttieniPrezzo(), converte la quantità ricevuta
 * dal client prima di invocarla.
 */
function qk_get_sale_price(PDO $pdo, int $prodottoId, ?int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
{
    if (!$dataRiferimento) {
        $dataRiferimento = date('Y-m-d');
    }

    // --- LEVEL 1: DEDICATO (Client pricelists or requested listinoId) ---

    // Se c'è un cliente specificato, scorriamo i suoi listini attivi in ordine di priorità decrescente
    if ($clienteId && $clienteId > 0) {
        $stmtClientiListini = $pdo->prepare("
            SELECT cl.listino_id, l.nome, l.valuta
            FROM clienti_listini cl
            JOIN listini l ON l.id = cl.listino_id
            WHERE cl.cliente_id = :cliente_id AND l.attivo = 1
              AND (l.valido_dal IS NULL OR l.valido_dal <= :data)
              AND (l.valido_al  IS NULL OR l.valido_al  >= :data)
              AND (cl.data_inizio IS NULL OR cl.data_inizio <= :data)
              AND (cl.data_fine IS NULL OR cl.data_fine >= :data)
            ORDER BY cl.priorita DESC, l.nome ASC
        ");
        $stmtClientiListini->execute([':cliente_id' => $clienteId, ':data' => $dataRiferimento]);
        $clientPricelists = $stmtClientiListini->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clientPricelists as $clientListino) {
            $stmtP = $pdo->prepare(
                "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
                 FROM listini_prezzi
                 WHERE listino_id = :lid
                   AND prodotto_id = :pid
                   AND quantita_minima_stock <= :qty
                 ORDER BY quantita_minima_stock DESC
                 LIMIT 1"
            );
            $stmtP->execute([
                ':lid'  => $clientListino['listino_id'],
                ':pid'  => $prodottoId,
                ':qty'  => $quantita,
            ]);
            $row = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $qMin    = (float)$row['quantita_minima'];
                $sorgente = 'Listino Cliente: ' . $clientListino['nome'];
                if ($qMin > 0) {
                    $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
                }

                return [
                    'prezzo_unitario' => (float)$row['prezzo_unitario'],
                    'sconto_perc'     => (float)$row['sconto_perc'],
                    'valuta'          => $clientListino['valuta'],
                    'listino_id'      => $clientListino['listino_id'],
                    'listino_nome'    => $clientListino['nome'],
                    'prezzo_sorgente' => $sorgente,
                    'origine'         => 'dedicato',
                    'unita_misura_id' => $row['unita_misura_id'] !== null ? (int)$row['unita_misura_id'] : null,
                ];
            }
        }
    }

    // Se non abbiamo trovato nulla o non è definito un cliente, controlliamo il listinoId esplicito fornito
    if ($listinoId > 0) {
        $stmtL = $pdo->prepare(
            "SELECT id, nome, valuta FROM listini
             WHERE id = :id AND attivo = 1
               AND (valido_dal IS NULL OR valido_dal <= :data)
               AND (valido_al  IS NULL OR valido_al  >= :data)"
        );
        $stmtL->execute([':id' => $listinoId, ':data' => $dataRiferimento]);
        $listino = $stmtL->fetch(PDO::FETCH_ASSOC);

        if ($listino) {
            $stmtP = $pdo->prepare(
                "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
                 FROM listini_prezzi
                 WHERE listino_id = :lid
                   AND prodotto_id = :pid
                   AND quantita_minima_stock <= :qty
                 ORDER BY quantita_minima_stock DESC
                 LIMIT 1"
            );
            $stmtP->execute([
                ':lid'  => $listinoId,
                ':pid'  => $prodottoId,
                ':qty'  => $quantita,
            ]);
            $row = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $qMin    = (float)$row['quantita_minima'];
                $sorgente = 'Listino: ' . $listino['nome'];
                if ($qMin > 0) {
                    $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
                }

                return [
                    'prezzo_unitario' => (float)$row['prezzo_unitario'],
                    'sconto_perc'     => (float)$row['sconto_perc'],
                    'valuta'          => $listino['valuta'],
                    'listino_id'      => $listinoId,
                    'listino_nome'    => $listino['nome'],
                    'prezzo_sorgente' => $sorgente,
                    'origine'         => 'dedicato',
                    'unita_misura_id' => $row['unita_misura_id'] !== null ? (int)$row['unita_misura_id'] : null,
                ];
            }
        }
    }

    // --- LEVEL 2: PREZZO BASE (listino_id = 1) ---
    $stmtP = $pdo->prepare(
        "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
         FROM listini_prezzi
         WHERE listino_id = 1
           AND prodotto_id = :pid
           AND quantita_minima_stock <= :qty
         ORDER BY quantita_minima_stock DESC
         LIMIT 1"
    );
    $stmtP->execute([
        ':pid'  => $prodottoId,
        ':qty'  => $quantita,
    ]);
    $row = $stmtP->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $qMin    = (float)$row['quantita_minima'];
        $sorgente = 'Prezzo Base (Catalogo)';
        if ($qMin > 0) {
            $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
        }

        return [
            'prezzo_unitario' => (float)$row['prezzo_unitario'],
            'sconto_perc'     => (float)$row['sconto_perc'],
            'valuta'          => 'EUR', // Il prezzo base vive in valuta di sistema
            'listino_id'      => 1,
            'listino_nome'    => 'Prezzo Base',
            'prezzo_sorgente' => $sorgente,
            'origine'         => 'base',
            'unita_misura_id' => $row['unita_misura_id'] !== null ? (int)$row['unita_misura_id'] : null,
        ];
    }

    // --- LEVEL 3: DYNAMIC FALLBACK MARKUP ON COST ---
    $costo_unitario = 0.0;

    $stProdType = $pdo->prepare("SELECT tipo FROM prodotti WHERE id = ?");
    $stProdType->execute([$prodottoId]);
    $prodTipo = $stProdType->fetchColumn();

    // Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md) — vedi commento
    // esteso in db/init_sqlite.sql §14b.
    if ($prodTipo === 'kit') {
        $stCost = $pdo->prepare('
            SELECT SUM(ck.quantita * vc.costo_acquisto)
            FROM componenti_kit ck
            LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = ck.componente_id
            WHERE ck.kit_id = ?
        ');
    } else {
        $stCost = $pdo->prepare('
            SELECT vc.costo_acquisto FROM v_costo_acquisto_prodotti vc WHERE vc.prodotto_id = ?
        ');
    }
    $stCost->execute([$prodottoId]);
    $costo_unitario_col = $stCost->fetchColumn();

    if ($costo_unitario_col !== false && $costo_unitario_col !== null) {
        $costPrice = (float)$costo_unitario_col;

        // Carica l'override del markup del prodotto e la UoM di stock
        $stProd = $pdo->prepare('SELECT markup_fallback_perc, unita_misura_stock_id FROM prodotti WHERE id = ?');
        $stProd->execute([$prodottoId]);
        $prodRow = $stProd->fetch(PDO::FETCH_ASSOC);

        $markup = null;
        $sorgenteMarkup = '';

        if ($prodRow) {
            if ($prodRow['markup_fallback_perc'] !== null && $prodRow['markup_fallback_perc'] !== '') {
                $markup = (float)$prodRow['markup_fallback_perc'];
                $sorgenteMarkup = "ricarico specifico scheda prodotto (+" . number_format($markup, 2, ',', '.') . "%)";
            }
            $umStockId = $prodRow['unita_misura_stock_id'] !== null ? (int)$prodRow['unita_misura_stock_id'] : null;
        } else {
            $umStockId = null;
        }

        // Se non c'è ricarico specifico, usa il fallback globale di sistema
        if ($markup === null) {
            $stGlobal = $pdo->query("SELECT valore FROM Impostazioni WHERE chiave = 'markup_fallback_default'");
            $globalVal = $stGlobal->fetchColumn();
            if ($globalVal !== false && $globalVal !== null && $globalVal !== '') {
                $markup = (float)$globalVal;
                $sorgenteMarkup = "ricarico di default di sistema (+" . number_format($markup, 2, ',', '.') . "%)";
            } else {
                $markup = 20.0;
                $sorgenteMarkup = "ricarico standard di emergenza (+20,00%)";
            }
        }

        $computedPrice = round($costPrice * (1 + $markup / 100), 2);
        return [
            'prezzo_unitario' => $computedPrice,
            'sconto_perc'     => 0.0,
            'valuta'          => 'EUR',
            'listino_id'      => 0,
            'listino_nome'    => 'Ricarico automatico su costo',
            'prezzo_sorgente' => "Costo d'acquisto minimo (" . number_format($costPrice, 2, ',', '.') . " €) + " . $sorgenteMarkup,
            'origine'         => 'costo',
            'unita_misura_id' => $umStockId,
        ];
    }

    // --- LEVEL 4: MANUALE (prezzo 0.00) ---
    return [
        'prezzo_unitario' => 0.0,
        'sconto_perc'     => 0.0,
        'valuta'          => 'EUR',
        'listino_id'      => 0,
        'listino_nome'    => 'Manuale',
        'prezzo_sorgente' => 'Nessun prezzo configurato',
        'origine'         => 'manuale',
        'unita_misura_id' => null,
    ];
}

/**
 * Trova il prezzo di acquisto corretto per un prodotto, un fornitore e una quantità.
 * Restituisce un array con i dettagli del prezzo e la descrizione della sorgente.
 *
 * CONTRATTO (D3.2, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
 * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
 * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
 * attuale, PurchasesController::apiOttieniPrezzo(), converte la quantità ricevuta dal
 * client prima di invocarla.
 */
function qk_get_purchase_price(PDO $pdo, int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
{
    if (!$dataRiferimento) {
        $dataRiferimento = date('Y-m-d');
    }

    // 1. Controlla se il fornitore ha un listino attivo alla dataRiferimento in listini_acquisto
    $stmtListini = $pdo->prepare("
        SELECT id, nome_listino
        FROM listini_acquisto
        WHERE fornitore_id = :fid
          AND attivo = 1
          AND (valido_dal IS NULL OR valido_dal <= :data_rif)
          AND (valido_al IS NULL OR valido_al >= :data_rif)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtListini->execute([
        ':fid'      => $fornitoreId,
        ':data_rif' => $dataRiferimento
    ]);
    $listinoAttivo = $stmtListini->fetch(PDO::FETCH_ASSOC);

    $listinoId   = $listinoAttivo ? (int)$listinoAttivo['id'] : null;
    $listinoNome = $listinoAttivo ? $listinoAttivo['nome_listino'] : '';

    // Troviamo la riga prodotti_fornitori per recuperare lo SKU del fornitore e l'id di pivot
    $stmtPf = $pdo->prepare("
        SELECT id, sku_fornitore
        FROM prodotti_fornitori
        WHERE prodotto_id = :pid AND fornitore_id = :fid
        LIMIT 1
    ");
    $stmtPf->execute([
        ':pid' => $prodottoId,
        ':fid' => $fornitoreId
    ]);
    $pf = $stmtPf->fetch(PDO::FETCH_ASSOC);
    if (!$pf) {
        return null; // Il prodotto non è venduto da questo fornitore
    }
    $prodottoFornitoreId = (int)$pf['id'];
    $skuFornitore        = $pf['sku_fornitore'] ?: '';

    // Prepariamo la query per i prezzi (14/07/2026: aggiunto codice unità della soglia, per
    // mostrare nel messaggio "Scaglione >= N" anche l'unità nativa, non solo il numero grezzo).
    $sqlPrezzi = "
        SELECT pfp.prezzo, pfp.sconto_perc, pfp.note, pfp.quantita_minima, pfp.listino_acquisto_id,
               um.codice AS um_soglia_codice
        FROM prodotti_fornitori_prezzi pfp
        LEFT JOIN unita_misura um ON um.id = pfp.unita_misura_id
        WHERE pfp.prodotto_fornitore_id = :pfid
          AND (pfp.valido_dal IS NULL OR pfp.valido_dal <= :data_rif)
          AND (pfp.valido_al IS NULL OR pfp.valido_al >= :data_rif)
          AND pfp.quantita_minima_stock <= :qty
    ";

    $params = [
        ':pfid'     => $prodottoFornitoreId,
        ':data_rif' => $dataRiferimento,
        ':qty'      => $quantita
    ];

    if ($listinoId !== null) {
        // Cerchiamo prima all'interno del listino attivo
        $sqlListino = $sqlPrezzi . " AND listino_acquisto_id = :listino_id ORDER BY quantita_minima_stock DESC LIMIT 1";
        $listinoParams = $params;
        $listinoParams[':listino_id'] = $listinoId;

        $stmtPrezzo = $pdo->prepare($sqlListino);
        $stmtPrezzo->execute($listinoParams);
        $prezzoRecord = $stmtPrezzo->fetch(PDO::FETCH_ASSOC);

        if ($prezzoRecord) {
            $qMin = (float)$prezzoRecord['quantita_minima'];
            $sorgente = "Listino: " . $listinoNome;
            if ($qMin > 0) {
                $umLabel = $prezzoRecord['um_soglia_codice'] ? ' ' . $prezzoRecord['um_soglia_codice'] : '';
                $sorgente .= " (Scaglione >= " . number_format($qMin, 0, ',', '.') . $umLabel . ")";
            }
            return [
                'prezzo'              => (float)$prezzoRecord['prezzo'],
                'sconto_perc'         => (float)$prezzoRecord['sconto_perc'],
                'sku_fornitore'       => $skuFornitore,
                'listino_acquisto_id' => (int)$prezzoRecord['listino_acquisto_id'],
                'prezzo_sorgente'     => $sorgente
            ];
        }
    }

    // Se non c'è listino attivo, o se c'è ma non abbiamo trovato prezzi per il prodotto in quel listino,
    // cerchiamo nei prezzi base (listino_acquisto_id IS NULL)
    $sqlBase = $sqlPrezzi . " AND listino_acquisto_id IS NULL ORDER BY quantita_minima_stock DESC LIMIT 1";
    $stmtBase = $pdo->prepare($sqlBase);
    $stmtBase->execute($params);
    $prezzoRecord = $stmtBase->fetch(PDO::FETCH_ASSOC);

    if ($prezzoRecord) {
        $qMin = (float)$prezzoRecord['quantita_minima'];
        $sorgente = "Prezzo base";
        if ($qMin > 0) {
            $umLabel = $prezzoRecord['um_soglia_codice'] ? ' ' . $prezzoRecord['um_soglia_codice'] : '';
            $sorgente .= " (Scaglione >= " . number_format($qMin, 0, ',', '.') . $umLabel . ")";
        }
        return [
            'prezzo'              => (float)$prezzoRecord['prezzo'],
            'sconto_perc'         => (float)$prezzoRecord['sconto_perc'],
            'sku_fornitore'       => $skuFornitore,
            'listino_acquisto_id' => null,
            'prezzo_sorgente'     => $sorgente
        ];
    }

    return null;
}
