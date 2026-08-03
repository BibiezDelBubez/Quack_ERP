<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/anagrafiche.php';

/**
 * Conteggio listini per paginazione
 */
function qk_listini_count(PDO $pdo, string $whereStr, array $params): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM listini WHERE $whereStr");
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/**
 * Lista impaginata dei listini
 */
function qk_listini_list(PDO $pdo, string $whereStr, array $params, string $orderCol, string $dir, int $limit, int $offset): array
{
    $sql = "SELECT *
              FROM listini
             WHERE {$whereStr}
             ORDER BY {$orderCol} {$dir}
             LIMIT {$limit} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recupera un listino per ID
 */
function qk_get_listino(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM listini WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Inserisce un nuovo listino
 */
function qk_listino_insert(PDO $pdo, array $data): int
{
    if (!empty($data['is_default'])) {
        $pdo->exec("UPDATE listini SET is_default = 0 WHERE is_default = 1");
    }
    $st = $pdo->prepare("
        INSERT INTO listini (nome, descrizione, valuta, valido_dal, valido_al, is_default, attivo, sconto_default_perc, created_at)
        VALUES (:nome, :descrizione, :valuta, :valido_dal, :valido_al, :is_default, :attivo, :sconto, CURRENT_TIMESTAMP)
    ");
    $st->execute([
        ':nome'        => $data['nome'],
        ':descrizione' => $data['descrizione'] ?? null,
        ':valuta'      => $data['valuta'],
        ':valido_dal'  => $data['valido_dal'] ?? null,
        ':valido_al'   => $data['valido_al'] ?? null,
        ':is_default'  => $data['is_default'] ?? 0,
        ':attivo'      => $data['attivo'] ?? 1,
        ':sconto'      => $data['sconto_default_perc'] ?? 0.0,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Aggiorna un listino esistente
 */
function qk_listino_update(PDO $pdo, int $id, array $data): void
{
    if (!empty($data['is_default'])) {
        $pdo->prepare("UPDATE listini SET is_default = 0 WHERE is_default = 1 AND id != ?")->execute([$id]);
    }
    $st = $pdo->prepare("
        UPDATE listini
           SET nome = :nome, descrizione = :descrizione, valuta = :valuta,
               valido_dal = :valido_dal, valido_al = :valido_al, is_default = :is_default, attivo = :attivo,
               sconto_default_perc = :sconto
         WHERE id = :id
    ");
    $st->execute([
        ':id'          => $id,
        ':nome'        => $data['nome'],
        ':descrizione' => $data['descrizione'] ?? null,
        ':valuta'      => $data['valuta'],
        ':valido_dal'  => $data['valido_dal'] ?? null,
        ':valido_al'   => $data['valido_al'] ?? null,
        ':is_default'  => $data['is_default'] ?? 0,
        ':attivo'      => $data['attivo'] ?? 1,
        ':sconto'      => $data['sconto_default_perc'] ?? 0.0,
    ]);
}

/**
 * Elimina un listino se non referenziato
 */
function qk_listino_delete(PDO $pdo, int $id): bool
{
    $stCheck = $pdo->prepare("
        SELECT (SELECT COUNT(*) FROM preventivi WHERE listino_id = :id)
             + (SELECT COUNT(*) FROM ordini_clienti WHERE listino_id = :id) AS total
    ");
    $stCheck->execute([':id' => $id]);
    if ((int)$stCheck->fetchColumn() > 0) {
        return false;
    }
    $pdo->prepare("DELETE FROM listini WHERE id = :id")->execute([':id' => $id]);
    return true;
}

/**
 * Conteggio righe prezzo per listino
 */
function qk_listino_prezzi_count(PDO $pdo, int $listinoId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM listini_prezzi WHERE listino_id = ?");
    $st->execute([$listinoId]);
    return (int)$st->fetchColumn();
}

/**
 * Recupera i clienti associati ad un listino
 */
function qk_get_clienti_listino(PDO $pdo, int $listinoId): array
{
    $nomeSql = qk_nome_cliente_sql_fragment('cf');
    $st = $pdo->prepare("
        SELECT cl.cliente_id, {$nomeSql} AS nome_cliente, cf.codice AS cliente_codice
          FROM clienti_listini cl
          JOIN clienti_fornitori cf ON cf.id = cl.cliente_id
         WHERE cl.listino_id = ?
         ORDER BY nome_cliente
    ");
    $st->execute([$listinoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Elimina riga prezzo da un listino
 */
function qk_listino_riga_delete(PDO $pdo, int $rigaId): void
{
    $st = $pdo->prepare("DELETE FROM listini_prezzi WHERE id = ?");
    $st->execute([$rigaId]);
}

/**
 * Aggiorna i clienti associati ad un listino (sincronizzazione differenziale:
 * tocca solo le righe che cambiano davvero, mai un cancella-e-reinserisci-
 * tutto — altrimenti priorita/data_inizio/data_fine dei clienti che restano
 * assegnati, scritte altrove, andrebbero perse silenziosamente).
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 7
 * (23/07/2026): questa funzione esisteva già ma non era mai stata richiamata
 * da nessun punto del progetto (verificato con grep esaustivo) — il
 * controller (SalesPricelistsController::apiAssegnaClienti()) duplicava la
 * stessa identica logica inline. NON apre più una propria transazione (a
 * differenza della versione precedente): il chiamante la apre già
 * (apiAssegnaClienti() lo fa per includere anche altre operazioni nella
 * stessa transazione) e PDO/SQLite non supporta transazioni annidate —
 * chiamare questa funzione fuori da una transazione già aperta resta
 * comunque sicuro (le singole DELETE/INSERT restano atomiche per conto
 * proprio via SQLite autocommit).
 */
function qk_update_clienti_listino(PDO $pdo, int $listinoId, array $clientiIds): void
{
    $stOld = $pdo->prepare("SELECT cliente_id FROM clienti_listini WHERE listino_id = ?");
    $stOld->execute([$listinoId]);
    $oldIds = array_map('intval', $stOld->fetchAll(PDO::FETCH_COLUMN));

    $toRemove = array_diff($oldIds, $clientiIds);
    $toAdd    = array_diff($clientiIds, $oldIds);

    if (!empty($toRemove)) {
        $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
        $params = array_merge([$listinoId], array_values($toRemove));
        $stDel = $pdo->prepare("DELETE FROM clienti_listini WHERE listino_id = ? AND cliente_id IN ($placeholders)");
        $stDel->execute($params);
    }

    if (!empty($toAdd)) {
        $stIns = $pdo->prepare("INSERT INTO clienti_listini (cliente_id, listino_id, priorita) VALUES (?, ?, 10)");
        foreach ($toAdd as $cId) {
            $stIns->execute([$cId, $listinoId]);
        }
    }
}

/**
 * Lista listini con conteggio articoli
 */
function qk_listini_list_with_count(PDO $pdo, string $whereStr, array $params, string $orderCol, string $dir): array
{
    $sql = "SELECT l.*,
                   (SELECT COUNT(*) FROM listini_prezzi lp WHERE lp.listino_id = l.id) AS nr_articoli
            FROM listini l
            WHERE {$whereStr}
            ORDER BY {$orderCol} {$dir}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica righe prodotto di un listino con paginazione e ordinamento
 */
function qk_get_listino_items(PDO $pdo, int $listinoId, string $orderByCol, string $dir, int $limit, int $offset): array
{
    $stmtItems = $pdo->prepare(
        "SELECT lp.*, 
                p.nome AS prodotto_nome, p.sku_interno, 
                p.unita_misura_stock_id AS p_um_stock_id, 
                p.unita_contenuto_id AS p_um_comm_id, 
                p.quantita_contenuto AS p_factor,
                um_stock.codice AS um_stock_code,
                um_comm.codice AS um_comm_code,
                um_native.codice AS um_native_code,
                COALESCE(um_native.codice, um_stock.codice) AS unita_misura,
                (SELECT MIN(vf.prezzo)
                 FROM v_prezzo_acquisto_base_fornitore vf
                 JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                 WHERE pf2.prodotto_id = lp.prodotto_id AND pf2.attivo = 1) AS costo
         FROM listini_prezzi lp
         JOIN prodotti p ON p.id = lp.prodotto_id
         LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
         LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
         LEFT JOIN unita_misura um_native ON um_native.id = lp.unita_misura_id
         WHERE lp.listino_id = :lid
         ORDER BY {$orderByCol} {$dir}, lp.quantita_minima ASC
         LIMIT :limit OFFSET :offset"
    );
    $stmtItems->bindValue(':lid', $listinoId, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();
    return $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica clienti assegnati ad un listino default
 */
function qk_get_clienti_assegnati_listino(PDO $pdo, int $listinoId): array
{
    $nomeSql = qk_nome_cliente_sql_fragment('cf');
    $stmtClienti = $pdo->prepare(
        "SELECT cf.id,
                {$nomeSql} AS nome_cliente
         FROM dettagli_clienti dc
         JOIN clienti_fornitori cf ON cf.id = dc.soggetto_id
         WHERE dc.listino_id = :lid
         ORDER BY nome_cliente ASC"
    );
    $stmtClienti->execute([':lid' => $listinoId]);
    return $stmtClienti->fetchAll(PDO::FETCH_ASSOC);
}

/* ── Tappa 7 (PIANO - Eliminazione SQL Grezzo nei Controller, 23/07/2026):  ──
 * funzioni estratte da SalesPricelistsController.php — SELECT esplicite
 * lasciate byte-per-byte identiche all'originale, nessun cambio di
 * comportamento. ──────────────────────────────────────────────────────── */

/**
 * Vero se esiste già una riga prezzo per lo stesso listino/prodotto/soglia
 * canonica (quantita_minima_stock), esclusa la riga stessa in modifica.
 * Estratta da SalesPricelistsController::apiSaveRiga() (§5.2 del piano).
 */
function qk_listino_prezzo_riga_esiste_duplicato(PDO $pdo, ?int $listinoId, int $prodottoId, float $qMinStock, int $escludiRigaId): bool
{
    $listinoCond = $listinoId === null ? "listino_id IS NULL" : "listino_id = :listino_id";
    $st = $pdo->prepare("
        SELECT id FROM listini_prezzi
        WHERE $listinoCond
          AND prodotto_id = :prodotto_id
          AND quantita_minima_stock = :qmin_stock
          AND id != :riga_id
    ");
    $params = [
        ':prodotto_id' => $prodottoId,
        ':qmin_stock'  => $qMinStock,
        ':riga_id'     => $escludiRigaId,
    ];
    if ($listinoId !== null) {
        $params[':listino_id'] = $listinoId;
    }
    $st->execute($params);
    return (bool) $st->fetch();
}

/**
 * Aggiorna una riga prezzo esistente. $data richiede le chiavi:
 * prodotto_id, quantita_minima, prezzo_unitario, sconto_perc,
 * unita_misura_id, note, quantita_minima_intento, unita_misura_soglia_id,
 * quantita_minima_stock. Estratta da SalesPricelistsController::apiSaveRiga().
 */
function qk_listino_prezzo_riga_update(PDO $pdo, int $rigaId, ?int $listinoId, array $data): void
{
    $listinoCond = $listinoId === null ? "listino_id IS NULL" : "listino_id = :listino_id";
    $params = [
        ':prodotto_id'  => $data['prodotto_id'],
        ':qmin'         => $data['quantita_minima'],
        ':prezzo'       => $data['prezzo_unitario'],
        ':sconto'       => $data['sconto_perc'],
        ':unita_misura_id' => $data['unita_misura_id'],
        ':note'         => $data['note'],
        ':qmin_intento' => $data['quantita_minima_intento'],
        ':um_soglia_id' => $data['unita_misura_soglia_id'],
        ':qmin_stock'   => $data['quantita_minima_stock'],
        ':id'           => $rigaId,
    ];
    if ($listinoId !== null) {
        $params[':listino_id'] = $listinoId;
    }

    $pdo->prepare(
        "UPDATE listini_prezzi SET
            prodotto_id     = :prodotto_id,
            quantita_minima = :qmin,
            prezzo_unitario = :prezzo,
            sconto_perc     = :sconto,
            unita_misura_id = :unita_misura_id,
            note            = :note,
            quantita_minima_intento = :qmin_intento,
            unita_misura_soglia_id  = :um_soglia_id,
            quantita_minima_stock   = :qmin_stock,
            modificato_manualmente_il = CURRENT_TIMESTAMP
         WHERE id = :id AND $listinoCond"
    )->execute($params);
}

/**
 * Crea una nuova riga prezzo. $data richiede le stesse chiavi di
 * qk_listino_prezzo_riga_update() più 'listino_id'. Restituisce l'id
 * inserito. Estratta da SalesPricelistsController::apiSaveRiga().
 */
function qk_listino_prezzo_riga_insert(PDO $pdo, array $data): int
{
    $pdo->prepare(
        "INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, unita_misura_id, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
         VALUES (:listino_id, :prodotto_id, :qmin, :prezzo, :sconto, :unita_misura_id, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)"
    )->execute([
        ':listino_id'   => $data['listino_id'],
        ':prodotto_id'  => $data['prodotto_id'],
        ':qmin'         => $data['quantita_minima'],
        ':prezzo'       => $data['prezzo_unitario'],
        ':sconto'       => $data['sconto_perc'],
        ':unita_misura_id' => $data['unita_misura_id'],
        ':note'         => $data['note'],
        ':qmin_intento' => $data['quantita_minima_intento'],
        ':um_soglia_id' => $data['unita_misura_soglia_id'],
        ':qmin_stock'   => $data['quantita_minima_stock'],
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Ricarica una riga listino_prezzi con nome prodotto/UoM/costo, per la
 * risposta JSON dopo un salvataggio (fix "Listino Fantasma",
 * audit/MASTER_AUDIT_REPORT.md: il costo viene da
 * v_prezzo_acquisto_base_fornitore, non da un campo statico sul prodotto).
 * Estratta da SalesPricelistsController::apiSaveRiga().
 */
function qk_listino_prezzo_riga_dettaglio(PDO $pdo, int $rigaId): ?array
{
    $st = $pdo->prepare(
        "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno,
                COALESCE(um_p.codice, um_stock.codice) AS unita_misura,
                p.unita_misura_stock_id AS uom_stock_id,
                um_stock.codice AS uom_stock_code,
                p.unita_contenuto_id AS uom_commercial_id,
                um_comm.codice AS uom_commercial_code,
                (SELECT MIN(vf.prezzo)
                 FROM v_prezzo_acquisto_base_fornitore vf
                 JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                 WHERE pf2.prodotto_id = lp.prodotto_id AND pf2.attivo = 1) AS costo
         FROM listini_prezzi lp
         JOIN prodotti p ON p.id = lp.prodotto_id
         LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
         LEFT JOIN unita_misura um_p ON um_p.id = lp.unita_misura_id
         LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
         WHERE lp.id = :id"
    );
    $st->execute([':id' => $rigaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Autocomplete prodotti per la griglia listino (§5.3 del piano). Verificato
 * (CLAUDE.md punto 1) che NON è la stessa query di qk_search_prodotti_orders()
 * (includes/sales_orders.php): usa una vista di costo diversa
 * (v_prezzo_acquisto_base_fornitore, non v_costo_acquisto_prodotti), non
 * gestisce il costo aggregato dei Kit, limite 20 righe (non 60), cerca anche
 * su 'ean'. Estratta com'è, senza unificarla — unificare cambierebbe il
 * comportamento di uno dei due form. Estratta da
 * SalesPricelistsController::apiProdotti().
 */
function qk_pricelist_search_prodotti(PDO $pdo, string $q): array
{
    $where = "WHERE p.attivo = 1";
    $params = [];

    if ($q !== '') {
        $where .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR p.ean LIKE :q)";
        $params = [':q' => '%' . $q . '%'];
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.nome AS label, p.sku_interno AS sku, um.codice AS unita_misura,
                p.unita_misura_stock_id, um_c.codice AS unita_misura_commerciale,
                p.unita_contenuto_id, p.quantita_contenuto,
                pf.rrp_min, pf.rrp_max,
                (SELECT MIN(vf.prezzo)
                 FROM v_prezzo_acquisto_base_fornitore vf
                 JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                 WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1) AS costo
         FROM prodotti p
         LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
         LEFT JOIN unita_misura um_c ON um_c.id = p.unita_contenuto_id
         LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.attivo = 1
            AND pf.ordine_preferenza_fornitore = (
                SELECT MIN(pf2.ordine_preferenza_fornitore)
                FROM prodotti_fornitori pf2
                WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1
            )
         $where
         ORDER BY p.nome ASC
         LIMIT 20"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * RRP e costo unitario del fornitore preferito di un prodotto, per il
 * suggerimento prezzo di vendita (fix "Listino Fantasma",
 * audit/MASTER_AUDIT_REPORT.md). Estratta da
 * SalesPricelistsController::apiOttieniPrezzo() (§5.4 del piano).
 */
function qk_pricelist_get_rrp_costo(PDO $pdo, int $prodottoId): ?array
{
    $st = $pdo->prepare('
        SELECT pf.rrp_min, pf.rrp_max, pf.rrp_unita_misura_id,
               vf.prezzo AS costo_unitario
        FROM prodotti_fornitori pf
        LEFT JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
        WHERE pf.prodotto_id = ? AND pf.attivo = 1
        ORDER BY pf.ordine_preferenza_fornitore ASC
        LIMIT 1
    ');
    $st->execute([$prodottoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Elenco clienti attivi con lo stato di assegnazione (pivot o default) ad
 * un listino — per il modal "Assegna clienti". Estratta da
 * SalesPricelistsController::apiClientiAssegnazione() (§5.5/§5.7 del
 * piano): usa qk_nome_cliente_sql_fragment() al posto del
 * COALESCE/TRIM scritto a mano (una delle 2 duplicazioni "nome cliente"
 * residue nel Ciclo Vendite dopo AUDIT 3 §4.A.4).
 */
function qk_get_clienti_con_stato_assegnazione(PDO $pdo, int $listinoId): array
{
    $nomeSql = qk_nome_cliente_sql_fragment('cf');
    $stmt = $pdo->prepare("
        SELECT cf.id,
               {$nomeSql} AS nome_cliente,
               cf.partita_iva,
               (SELECT 1 FROM clienti_listini cl WHERE cl.cliente_id = cf.id AND cl.listino_id = :lid) AS assegnato_pivot,
               (SELECT 1 FROM dettagli_clienti dc WHERE dc.soggetto_id = cf.id AND dc.listino_id = :lid) AS assegnato_default
        FROM clienti_fornitori cf
        WHERE cf.tipo IN ('cliente', 'entrambi') AND cf.attivo = 1
        ORDER BY nome_cliente ASC
    ");
    $stmt->execute([':lid' => $listinoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ricarica i clienti assegnati (via pivot clienti_listini O default
 * dettagli_clienti) dopo una sincronizzazione — usata per la risposta JSON
 * di apiAssegnaClienti(). Diversa da qk_get_clienti_assegnati_listino()
 * qui sopra (quella unisce solo dettagli_clienti, senza DISTINCT): non
 * unificate, servono scopi diversi (verificato nei rispettivi punti di
 * chiamata prima di estrarre). Estratta da
 * SalesPricelistsController::apiAssegnaClienti() (§5.7 del piano), usa
 * qk_nome_cliente_sql_fragment() al posto del COALESCE/TRIM manuale
 * (seconda delle 2 duplicazioni "nome cliente" residue).
 */
function qk_get_clienti_assegnati_dopo_sync(PDO $pdo, int $listinoId): array
{
    $nomeSql = qk_nome_cliente_sql_fragment('cf');
    $stmt = $pdo->prepare("
        SELECT DISTINCT cf.id,
               {$nomeSql} AS nome_cliente
        FROM clienti_fornitori cf
        LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
        LEFT JOIN clienti_listini cl ON cl.cliente_id = cf.id
        WHERE (dc.listino_id = :lid OR cl.listino_id = :lid)
          AND cf.attivo = 1
        ORDER BY nome_cliente ASC
    ");
    $stmt->execute([':lid' => $listinoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
