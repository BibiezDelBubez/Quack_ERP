<?php
declare(strict_types=1);
/**
 * includes/purchases_pricelists.php
 * Funzioni DB per il modulo Listini Acquisto (PurchasesController).
 *
 * Estratto da PurchasesController.php (A.8, REVISIONE - Analisi Documentazione
 * Ciclo Acquisti.md, sezione 3bis): refactoring puro, nessuna modifica di
 * comportamento — stesse query, stessi parametri, stesso risultato, solo
 * spostate fuori dal controller HTTP (stesso principio già applicato in
 * includes/pricing.php per qk_get_purchase_price()/qk_get_sale_price()).
 *
 * Procede a tappe (Tappa 1 = dashboard, sola lettura, rischio minimo).
 */

require_once __DIR__ . '/prodotti.php';

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 1 — Dashboard (sola lettura)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * KPI numerici della dashboard Acquisti: conteggio RFQ in bozza/inviate,
 * ordini attivi (inviato o parzialmente ricevuto), listini acquisto attivi
 * e validi alla data odierna.
 *
 * @return array{rfq_bozza:int, rfq_inviata:int, ordini_attivi:int, listini_attivi:int}
 */
function qk_purchases_dashboard_kpi(PDO $pdo): array
{
    $rfq_bozza = (int) $pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'bozza'")->fetchColumn();
    $rfq_inviata = (int) $pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'inviata'")->fetchColumn();
    $ordini_attivi = (int) $pdo->query("SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')")->fetchColumn();
    $listini_attivi = (int) $pdo->query("SELECT COUNT(*) FROM listini_acquisto WHERE attivo = 1 AND (valido_dal IS NULL OR valido_dal <= CURRENT_DATE) AND (valido_al IS NULL OR valido_al >= CURRENT_DATE)")->fetchColumn();

    return [
        'rfq_bozza'      => $rfq_bozza,
        'rfq_inviata'    => $rfq_inviata,
        'ordini_attivi'  => $ordini_attivi,
        'listini_attivi' => $listini_attivi,
    ];
}

/** Ultime 5 RFQ (per la dashboard Acquisti), con nome fornitore risolto. */
function qk_purchases_recent_rfqs(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT r.id, r.numero, r.created_at, r.stato, r.totale,
               COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
          FROM rfq_testata r
     LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
      ORDER BY r.id DESC
         LIMIT 5
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Ultimi 5 Ordini di Acquisto (per la dashboard Acquisti), con nome fornitore risolto. */
function qk_purchases_recent_orders(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT ot.id, ot.numero, ot.created_at, ot.stato, ot.totale,
               COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
          FROM ordini_testata ot
     LEFT JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
      ORDER BY ot.id DESC
         LIMIT 5
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 2 — CRUD testata listino
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco listini acquisto attivi, con nome fornitore risolto e stato
 * effettivo (active/expired) calcolato rispetto alla data odierna.
 * Filtro opzionale per nome listino o nome fornitore.
 */
function qk_listini_acquisto_cerca(PDO $pdo, ?string $search): array
{
    $sql = "SELECT la.*,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
            FROM listini_acquisto la
            JOIN clienti_fornitori cf ON cf.id = la.fornitore_id
            WHERE la.attivo = 1";

    $params = [];
    if ($search) {
        $sql .= " AND (la.nome_listino LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q OR cf.cognome LIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY la.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pricelists = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $oggi = date('Y-m-d');
    foreach ($pricelists as &$la) {
        $inRange = (!$la['valido_dal'] || $la['valido_dal'] <= $oggi)
                && (!$la['valido_al']  || $la['valido_al']  >= $oggi);
        $la['stato_effettivo'] = $inRange ? 'active' : 'expired';
    }
    unset($la);

    return $pricelists;
}

/** Crea un nuovo listino acquisto. Ritorna l'id appena creato. */
function qk_listino_acquisto_crea(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO listini_acquisto (fornitore_id, nome_listino, valido_dal, valido_al, note)
         VALUES (:fornitore_id, :nome_listino, :valido_dal, :valido_al, :note)"
    );
    $stmt->execute([
        ':fornitore_id' => $data['fornitore_id'],
        ':nome_listino' => $data['nome_listino'],
        ':valido_dal'   => $data['valido_dal'],
        ':valido_al'    => $data['valido_al'],
        ':note'         => $data['note'],
    ]);
    return (int) $pdo->lastInsertId();
}

/** Recupera un listino acquisto attivo per id, con nome fornitore risolto (fornitore_label). */
function qk_listino_acquisto_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT la.*,
                COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_label
         FROM listini_acquisto la
         JOIN clienti_fornitori cf ON cf.id = la.fornitore_id
         WHERE la.id = :id AND la.attivo = 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Aggiorna i dati di testata di un listino acquisto esistente. */
function qk_listino_acquisto_aggiorna(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        "UPDATE listini_acquisto SET
            fornitore_id = :fornitore_id,
            nome_listino = :nome_listino,
            valido_dal   = :valido_dal,
            valido_al    = :valido_al,
            note         = :note
         WHERE id = :id"
    );
    $stmt->execute([
        ':fornitore_id' => $data['fornitore_id'],
        ':nome_listino' => $data['nome_listino'],
        ':valido_dal'   => $data['valido_dal'],
        ':valido_al'    => $data['valido_al'],
        ':note'         => $data['note'],
        ':id'           => $id,
    ]);
}

/** Disattiva (soft-delete) un listino acquisto: attivo = 0, valido_al = oggi. */
function qk_listino_acquisto_disattiva(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE listini_acquisto SET attivo = 0, valido_al = CURRENT_DATE WHERE id = :id")
        ->execute([':id' => $id]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 3 — Righe listino (editPricelist)
// ─────────────────────────────────────────────────────────────────────────────

/** Conta le righe prezzo di un listino acquisto (per la paginazione). */
function qk_listino_acquisto_righe_count(PDO $pdo, int $listinoId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prodotti_fornitori_prezzi pfp WHERE pfp.listino_acquisto_id = :lid");
    $stmt->execute([':lid' => $listinoId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Righe prezzo di un listino acquisto, paginate e ordinate.
 *
 * @param string $orderByCol colonna SQL già risolta da una whitelist lato chiamante
 *                            (mai da input utente grezzo — invariato dal codice originale)
 */
function qk_listino_acquisto_righe_paginate(PDO $pdo, int $listinoId, string $orderByCol, string $dir, int $limit, int $offset): array
{
    $stmt = $pdo->prepare(
        "SELECT pfp.*,
                p.nome AS prodotto_nome, p.sku_interno,
                pf.sku_fornitore, pf.prodotto_id,
                p.unita_misura_stock_id AS p_um_stock_id,
                p.unita_contenuto_id AS p_um_comm_id,
                p.quantita_contenuto AS p_factor,
                um_stock.codice AS um_stock_code,
                um_comm.codice AS um_comm_code,
                um_native.codice AS um_native_code
         FROM prodotti_fornitori_prezzi pfp
         JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
         JOIN prodotti p ON p.id = pf.prodotto_id
         LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
         LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
         LEFT JOIN unita_misura um_native ON um_native.id = pfp.unita_misura_id
         WHERE pfp.listino_acquisto_id = :lid
         ORDER BY $orderByCol $dir, pfp.quantita_minima ASC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':lid', $listinoId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 4 — Ricerca prodotti (apiProdotti)
// ─────────────────────────────────────────────────────────────────────────────

/** Ricerca prodotti attivi per autocomplete (max 20 risultati). */
function qk_prodotti_cerca_per_listino_acquisto(PDO $pdo, string $q): array
{
    $where = "WHERE p.attivo = 1";
    $params = [];

    if ($q !== '') {
        if (mb_strlen($q) === 1) {
            $where .= " AND (p.nome LIKE :q_start OR p.sku_interno = :q OR p.ean = :q)";
            $params = [':q' => $q, ':q_start' => $q . '%'];
        } else {
            $where .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR p.ean LIKE :q)";
            $params = [':q' => '%' . $q . '%'];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT p.id, p.nome AS label, p.sku_interno AS sku, um.codice AS unita_misura,
                p.unita_misura_stock_id, um_c.codice AS unita_misura_commerciale,
                p.unita_contenuto_id, p.quantita_contenuto
         FROM prodotti p
         LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
         LEFT JOIN unita_misura um_c ON um_c.id = p.unita_contenuto_id
         $where
         ORDER BY p.nome ASC
         LIMIT 20"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 5 — Salvataggio riga prezzo (apiSaveRiga)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Salva (crea o aggiorna) una riga di prezzo di un listino acquisto, dentro
 * un'unica transazione. Comportamento identico all'apiSaveRiga() originale:
 *  - crea/aggiorna il legame prodotti_fornitori (e il suo sku_fornitore)
 *  - risolve l'unità di misura (esplicita, o ereditata da prodotti_fornitori,
 *    o fallback sulla Stock UoM del prodotto)
 *  - valida la "terza unità" (deve essere stock o commerciale del prodotto)
 *  - calcola la soglia canonica (quantita_minima_stock) via qk_uom_converti_riga()
 *  - blocca scaglioni duplicati sulla soglia canonica
 *  - esegue insert o update della riga
 *
 * Lancia \RuntimeException con messaggio user-facing sui casi di validazione
 * fallita (unità non valida, scaglione duplicato) — il chiamante HTTP li
 * traduce nel JSON di errore, esattamente come faceva il controller originale.
 * In caso di eccezione, la transazione viene sempre annullata prima del throw.
 *
 * @return array riga salvata, con dati prodotto e sku_fornitore (stesso shape
 *               di prima: JOIN prodotti_fornitori_prezzi + prodotti_fornitori + prodotti)
 */
function qk_listino_acquisto_riga_salva(PDO $pdo, array $input): array
{
    $listinoId    = (int) $input['listino_acquisto_id'];
    $rigaId       = (int) ($input['riga_id'] ?? 0);
    $prodottoId   = (int) $input['prodotto_id'];
    $qMin         = (float) ($input['quantita_minima'] ?? 0.0);
    $prezzo       = (float) $input['prezzo'];
    $sconto       = (float) ($input['sconto_perc'] ?? 0.0);
    $validoDal    = $input['valido_dal'] ?? null;
    $validoAl     = $input['valido_al'] ?? null;
    $note         = $input['note'] ?? null;
    $skuFornitore = $input['sku_fornitore'] ?? '';
    $umIdPost     = $input['unita_misura_id'] ?? null;

    $pdo->beginTransaction();
    try {
        $stmtL = $pdo->prepare("SELECT fornitore_id FROM listini_acquisto WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        $listino = $stmtL->fetch(PDO::FETCH_ASSOC);
        if (!$listino) {
            $pdo->rollBack();
            throw new \RuntimeException('Listino acquisto non trovato.');
        }
        $fornitoreId = (int) $listino['fornitore_id'];

        // BUGFIX (A.8, scoperto da esecuzione reale il 17/07/2026, non dalla sola lettura statica):
        // prodotti_fornitori.unita_misura_acquisto_id è NOT NULL senza default (db/init_sqlite.sql),
        // ma l'INSERT sotto non lo valorizzava mai — bug preesistente al refactoring A.8 (era già così
        // nel codice originale), si manifestava con un vincolo NOT NULL violato ogni volta che si
        // salvava il primo prezzo per una coppia prodotto+fornitore non ancora collegata. Servono
        // quindi stock/commerciale del prodotto PRIMA di creare l'eventuale nuovo legame, non solo
        // per la validazione "terza unità" più sotto (che restava invariata).
        $stmtUmProdCheck = $pdo->prepare("SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid");
        $stmtUmProdCheck->execute([':pid' => $prodottoId]);
        $prodUmCheck = $stmtUmProdCheck->fetch(PDO::FETCH_ASSOC);
        $stockIdCheck = $prodUmCheck ? (int) $prodUmCheck['unita_misura_stock_id'] : 0;
        $commIdCheck  = ($prodUmCheck && $prodUmCheck['unita_contenuto_id'] !== null) ? (int) $prodUmCheck['unita_contenuto_id'] : null;

        // Validazione "terza unità": comportamento invariato, solo anticipata (prima avveniva dopo
        // la creazione/aggiornamento di prodotti_fornitori, ma essendo nella stessa transazione con
        // rollback in caso di errore, l'esito osservabile non cambia).
        if ($umIdPost && (int) $umIdPost !== $stockIdCheck && (int) $umIdPost !== $commIdCheck) {
            $pdo->rollBack();
            throw new \RuntimeException('Unità di misura non valida: deve essere l\'unità di stock o l\'unità commerciale del prodotto.');
        }

        // Cerca o crea il record in prodotti_fornitori
        $stmtPf = $pdo->prepare("SELECT id, sku_fornitore, unita_misura_acquisto_id FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1");
        $stmtPf->execute([':pid' => $prodottoId, ':fid' => $fornitoreId]);
        $pfRow = $stmtPf->fetch(PDO::FETCH_ASSOC);

        if ($pfRow) {
            $pfId = (int) $pfRow['id'];
            if ($pfRow['sku_fornitore'] !== $skuFornitore) {
                $pdo->prepare("UPDATE prodotti_fornitori SET sku_fornitore = :sku WHERE id = :id")
                    ->execute([':sku' => $skuFornitore ?: null, ':id' => $pfId]);
            }
        } else {
            // Unità di acquisto nativa del nuovo legame fornitore-prodotto: quella esplicitamente
            // indicata in questa riga se presente, altrimenti la Stock UoM del prodotto — stesso
            // fallback già usato per la singola riga di prezzo (vedi risoluzione unitaMisuraId sotto).
            $unitaAcquistoNativa = $umIdPost ? (int) $umIdPost : $stockIdCheck;
            $pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, attivo, ordine_preferenza_fornitore, sku_fornitore, unita_misura_acquisto_id) VALUES (:pid, :fid, 1, 10, :sku, :umid)")
                ->execute([':pid' => $prodottoId, ':fid' => $fornitoreId, ':sku' => $skuFornitore ?: null, ':umid' => $unitaAcquistoNativa]);
            $pfId = (int) $pdo->lastInsertId();
            $pfRow = ['unita_misura_acquisto_id' => $unitaAcquistoNativa];
        }

        if ($umIdPost) {
            $unitaMisuraId = (int) $umIdPost;
        } else {
            $unitaMisuraId = (int) ($pfRow['unita_misura_acquisto_id'] ?? 0);
            if (!$unitaMisuraId) {
                $unitaMisuraId = $stockIdCheck;
            }
        }

        $qMinIntento   = $qMin;
        $unitaSogliaId = $unitaMisuraId ?: null;
        $qMinStock = $qMin > 0
            ? (float) qk_uom_converti_riga($pdo, $prodottoId, $unitaSogliaId, $qMin, 0.0)['quantita_stock']
            : 0.0;

        $stmtDup = $pdo->prepare("
            SELECT id FROM prodotti_fornitori_prezzi
            WHERE prodotto_fornitore_id = :pfid
              AND quantita_minima_stock = :qmin_stock
              AND id != :riga_id
              AND (listino_acquisto_id = :listino_id OR (:listino_id_null = 1 AND listino_acquisto_id IS NULL))
        ");
        $stmtDup->execute([
            ':listino_id'      => $listinoId,
            ':listino_id_null' => $listinoId === null ? 1 : 0,
            ':pfid'            => $pfId,
            ':qmin_stock'      => $qMinStock,
            ':riga_id'         => $rigaId ?: 0,
        ]);
        if ($stmtDup->fetch()) {
            $pdo->rollBack();
            throw new \RuntimeException('Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).');
        }

        if ($rigaId) {
            $pdo->prepare(
                "UPDATE prodotti_fornitori_prezzi SET
                    prodotto_fornitore_id = :pfid,
                    quantita_minima       = :qmin,
                    unita_misura_id       = :umid,
                    prezzo                = :prezzo,
                    sconto_perc           = :sconto,
                    valido_dal            = :valido_dal,
                    valido_al             = :valido_al,
                    note                  = :note,
                    quantita_minima_intento = :qmin_intento,
                    unita_misura_soglia_id  = :um_soglia_id,
                    quantita_minima_stock   = :qmin_stock,
                    modificato_manualmente_il = CURRENT_TIMESTAMP
                 WHERE id = :id AND listino_acquisto_id = :listino_id"
            )->execute([
                ':pfid'         => $pfId,
                ':qmin'         => $qMin,
                ':umid'         => $unitaMisuraId ?: null,
                ':prezzo'       => $prezzo,
                ':sconto'       => $sconto,
                ':valido_dal'   => $validoDal,
                ':valido_al'    => $validoAl,
                ':note'         => $note ?: null,
                ':qmin_intento' => $qMinIntento,
                ':um_soglia_id' => $unitaSogliaId,
                ':qmin_stock'   => $qMinStock,
                ':id'           => $rigaId,
                ':listino_id'   => $listinoId,
            ]);
            $newId = $rigaId;
        } else {
            $pdo->prepare(
                "INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, unita_misura_id, prezzo, sconto_perc, valido_dal, valido_al, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
                 VALUES (:pfid, :listino_id, :qmin, :umid, :prezzo, :sconto, :valido_dal, :valido_al, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)"
            )->execute([
                ':pfid'         => $pfId,
                ':listino_id'   => $listinoId,
                ':qmin'         => $qMin,
                ':umid'         => $unitaMisuraId ?: null,
                ':prezzo'       => $prezzo,
                ':sconto'       => $sconto,
                ':valido_dal'   => $validoDal,
                ':valido_al'    => $validoAl,
                ':note'         => $note ?: null,
                ':qmin_intento' => $qMinIntento,
                ':um_soglia_id' => $unitaSogliaId,
                ':qmin_stock'   => $qMinStock,
            ]);
            $newId = (int) $pdo->lastInsertId();
        }

        $pdo->commit();

        $stmtRow = $pdo->prepare(
            "SELECT pfp.*, p.nome AS prodotto_nome, p.sku_interno, pf.sku_fornitore
             FROM prodotti_fornitori_prezzi pfp
             JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
             JOIN prodotti p ON p.id = pf.prodotto_id
             WHERE pfp.id = :id"
        );
        $stmtRow->execute([':id' => $newId]);
        return $stmtRow->fetch(PDO::FETCH_ASSOC);
    } catch (\RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Elimina una riga di prezzo di un listino acquisto. */
function qk_listino_acquisto_riga_elimina(PDO $pdo, int $rigaId): void
{
    $pdo->prepare("DELETE FROM prodotti_fornitori_prezzi WHERE id = :id")->execute([':id' => $rigaId]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TAPPA 6 — Import massivo prezzi (apiImportPrezzi)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Importa in blocco righe di prezzo per un listino acquisto da un array di
 * righe (tipicamente decodificate da un CSV/Excel caricato lato client).
 * Comportamento identico all'apiImportPrezzi() originale, inclusa la
 * "Regola d'Oro" (REVISIONE §6.12): una riga modificata manualmente dopo
 * l'ultimo import non viene mai sovrascritta silenziosamente da un nuovo
 * import, e le righe importate in passato ma assenti dal nuovo file vengono
 * chiuse (valido_al = oggi), mai cancellate.
 *
 * Lancia \RuntimeException se il listino non esiste. Tutta l'importazione
 * gira in un'unica transazione (rollback su qualunque eccezione).
 *
 * @return array{inserted:int, skipped:int, expired:int, errors:string[]}
 */
function qk_listini_acquisto_importa_prezzi(PDO $pdo, int $listinoId, array $rows): array
{
    $stmtL = $pdo->prepare("SELECT fornitore_id FROM listini_acquisto WHERE id = :id");
    $stmtL->execute([':id' => $listinoId]);
    $listino = $stmtL->fetch(PDO::FETCH_ASSOC);
    if (!$listino) {
        throw new \RuntimeException('Listino acquisto non trovato.');
    }
    $fornitoreId = (int) $listino['fornitore_id'];

    $inserted   = 0;
    $skipped    = 0;
    $rowErrors  = [];
    $touchedIds = [];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $row) {
            $lineNum = $i + 2;

            $sku = trim((string) ($row['sku_interno'] ?? ''));
            if ($sku === '') {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ': SKU interno vuoto — saltata.';
                continue;
            }

            $stmtProd = $pdo->prepare('SELECT id, unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE sku_interno = :s AND attivo = 1');
            $stmtProd->execute([':s' => $sku]);
            $prodRow = $stmtProd->fetch(PDO::FETCH_ASSOC);
            if (!$prodRow) {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ': prodotto con SKU "' . htmlspecialchars($sku) . '" non trovato o non attivo — saltata.';
                continue;
            }
            $prodottoId = (int) $prodRow['id'];
            $uomStockId = (int) $prodRow['unita_misura_stock_id'];
            $uomCommercialId = $prodRow['unita_contenuto_id'] !== null ? (int) $prodRow['unita_contenuto_id'] : null;
            $uomId = $uomStockId;

            $umSogliaCodice = trim((string) ($row['unita_misura_soglia'] ?? ''));
            if ($umSogliaCodice !== '') {
                $stmtUmSoglia = $pdo->prepare('SELECT id FROM unita_misura WHERE UPPER(codice) = UPPER(:c)');
                $stmtUmSoglia->execute([':c' => $umSogliaCodice]);
                $umSogliaId = $stmtUmSoglia->fetchColumn();
                if (!$umSogliaId || ((int) $umSogliaId !== $uomStockId && (int) $umSogliaId !== $uomCommercialId)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): unità soglia "' . htmlspecialchars($umSogliaCodice) . '" non valida per questo prodotto (deve essere l\'unità di stock o l\'unità commerciale) — saltata.';
                    continue;
                }
                $uomId = (int) $umSogliaId;
            }

            $toFloat = function (?string $v): float {
                if ($v === null || trim($v) === '') return 0.0;
                return (float) str_replace(',', '.', trim($v));
            };

            $prezzoVal = trim((string) ($row['prezzo'] ?? ''));
            if ($prezzoVal === '' || !is_numeric(str_replace(',', '.', $prezzoVal))) {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo vuoto o non valido — saltata.';
                continue;
            }
            $prezzo = (float) str_replace(',', '.', $prezzoVal);

            $qMin         = $toFloat($row['quantita_minima'] ?? '0');
            $sconto       = $toFloat($row['sconto_perc'] ?? '0');
            $skuFornitore = trim((string) ($row['sku_fornitore'] ?? ''));
            $validoDal    = !empty($row['valido_dal']) ? trim((string) $row['valido_dal']) : null;
            $validoAl     = !empty($row['valido_al']) ? trim((string) $row['valido_al']) : null;
            $note         = !empty($row['note']) ? trim((string) $row['note']) : null;

            if ($prezzo < 0) {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): il prezzo non può essere negativo — saltata.';
                continue;
            }
            if ($sconto < 0 || $sconto > 100) {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): lo sconto percentuale deve essere tra 0 e 100 — saltata.';
                continue;
            }
            if ($qMin < 0) {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): la quantità minima non può essere negativa — saltata.';
                continue;
            }

            $qMinCanonico = $qMin > 0
                ? (float) qk_uom_converti_riga($pdo, $prodottoId, $uomId, $qMin, 0.0)['quantita_stock']
                : 0.0;

            $stmtPf = $pdo->prepare("SELECT id, sku_fornitore FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1");
            $stmtPf->execute([':pid' => $prodottoId, ':fid' => $fornitoreId]);
            $pfRow = $stmtPf->fetch(PDO::FETCH_ASSOC);

            if ($pfRow) {
                $pfId = (int) $pfRow['id'];
                if ($pfRow['sku_fornitore'] !== $skuFornitore) {
                    $pdo->prepare("UPDATE prodotti_fornitori SET sku_fornitore = :sku WHERE id = :id")
                        ->execute([':sku' => $skuFornitore ?: null, ':id' => $pfId]);
                }
            } else {
                // BUGFIX (A.8, stesso bug preesistente di qk_listino_acquisto_riga_salva()):
                // unita_misura_acquisto_id è NOT NULL senza default — $uomId qui è già risolto
                // (soglia dichiarata dalla riga di import, o fallback su Stock UoM del prodotto),
                // quindi mai null, sempre valido da usare come unità di acquisto nativa del nuovo legame.
                $pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, attivo, ordine_preferenza_fornitore, sku_fornitore, unita_misura_acquisto_id) VALUES (:pid, :fid, 1, 10, :sku, :umid)")
                    ->execute([':pid' => $prodottoId, ':fid' => $fornitoreId, ':sku' => $skuFornitore ?: null, ':umid' => $uomId]);
                $pfId = (int) $pdo->lastInsertId();
            }

            $stmtCheck = $pdo->prepare("
                SELECT id, importato_il, modificato_manualmente_il FROM prodotti_fornitori_prezzi
                WHERE prodotto_fornitore_id = :pfid
                  AND (listino_acquisto_id = :lid OR (:lid_null = 1 AND listino_acquisto_id IS NULL))
                  AND quantita_minima_stock = :qmin_stock
            ");
            $stmtCheck->execute([
                ':pfid'       => $pfId,
                ':lid'        => $listinoId,
                ':lid_null'   => $listinoId === null ? 1 : 0,
                ':qmin_stock' => $qMinCanonico,
            ]);
            $priceRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($priceRow) {
                $rigaId = (int) $priceRow['id'];

                $modificataAMano = !empty($priceRow['modificato_manualmente_il'])
                    && (empty($priceRow['importato_il']) || $priceRow['modificato_manualmente_il'] > $priceRow['importato_il']);

                if ($modificataAMano) {
                    $skipped++;
                    $touchedIds[] = $rigaId;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo modificato manualmente dopo l\'ultimo import — non sovrascritto, verificare a mano.';
                    continue;
                }

                $pdo->prepare("
                    UPDATE prodotti_fornitori_prezzi SET
                        prezzo = :prezzo,
                        sconto_perc = :sconto,
                        valido_dal = :valido_dal,
                        valido_al = :valido_al,
                        note = :note,
                        unita_misura_id = :umid,
                        quantita_minima_intento = :qmin_intento,
                        unita_misura_soglia_id  = :um_soglia_id,
                        quantita_minima_stock   = :qmin_stock,
                        importato_il = CURRENT_TIMESTAMP
                    WHERE id = :id
                ")->execute([
                    ':prezzo'       => $prezzo,
                    ':sconto'       => $sconto,
                    ':valido_dal'   => $validoDal,
                    ':valido_al'    => $validoAl,
                    ':note'         => $note,
                    ':umid'         => $uomId ?: null,
                    ':qmin_intento' => $qMin,
                    ':um_soglia_id' => $uomId ?: null,
                    ':qmin_stock'   => $qMinCanonico,
                    ':id'           => $rigaId,
                ]);
                $touchedIds[] = $rigaId;
            } else {
                $pdo->prepare("
                    INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, unita_misura_id, prezzo, sconto_perc, valido_dal, valido_al, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, importato_il)
                    VALUES (:pfid, :lid, :qmin, :umid, :prezzo, :sconto, :valido_dal, :valido_al, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)
                ")->execute([
                    ':umid'         => $uomId ?: null,
                    ':qmin_intento' => $qMin,
                    ':um_soglia_id' => $uomId ?: null,
                    ':qmin_stock'   => $qMinCanonico,
                    ':pfid'         => $pfId,
                    ':lid'          => $listinoId,
                    ':qmin'         => $qMin,
                    ':prezzo'       => $prezzo,
                    ':sconto'       => $sconto,
                    ':valido_dal'   => $validoDal,
                    ':valido_al'    => $validoAl,
                    ':note'         => $note,
                ]);
                $touchedIds[] = (int) $pdo->lastInsertId();
            }
            $inserted++;
        }

        $expired = 0;
        $stmtOrphanSel = $pdo->prepare("
            SELECT id FROM prodotti_fornitori_prezzi
            WHERE listino_acquisto_id = :lid
              AND importato_il IS NOT NULL
              AND (valido_al IS NULL OR valido_al >= CURRENT_DATE)
        ");
        $stmtOrphanSel->execute([':lid' => $listinoId]);
        $orphanIds = array_diff(array_map('intval', $stmtOrphanSel->fetchAll(PDO::FETCH_COLUMN)), $touchedIds);
        if (!empty($orphanIds)) {
            $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
            $stmtExpire = $pdo->prepare("UPDATE prodotti_fornitori_prezzi SET valido_al = CURRENT_DATE WHERE id IN ($placeholders)");
            $stmtExpire->execute(array_values($orphanIds));
            $expired = count($orphanIds);
        }

        $pdo->commit();

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'expired'  => $expired,
            'errors'   => $rowErrors,
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
