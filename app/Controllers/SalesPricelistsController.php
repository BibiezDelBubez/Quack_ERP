<?php

declare(strict_types=1);

/**
 * SalesPricelistsController — Gestione Listini Vendita.
 *
 * Tabelle coinvolte:
 *   listini            — testata listino (nome, valuta, attivo, valido_dal/al)
 *   listini_prezzi     — righe (prodotto_id, quantita_minima, prezzo_unitario, sconto_perc)
 *   dettagli_clienti   — listino_id default per cliente (read-only qui)
 */
final class SalesPricelistsController extends BaseController
{
    /* ── Lista ─────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $filtro_attivo = get_param('attivo'); // '' | '1' | '0'

        $sql = "SELECT l.*,
                       (SELECT COUNT(*) FROM listini_prezzi lp WHERE lp.listino_id = l.id) AS nr_articoli
                FROM listini l
                WHERE 1=1";

        $params = [];
        if ($search) {
            $sql .= " AND (l.nome LIKE :q OR l.descrizione LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($filtro_attivo !== '' && $filtro_attivo !== null) {
            $sql .= " AND l.attivo = :attivo";
            $params[':attivo'] = (int)$filtro_attivo;
        }

        $sortMap = ['nome' => 'l.nome', 'valuta' => 'l.valuta', 'dal' => 'l.valido_dal', 'al' => 'l.valido_al', 'articoli' => 'nr_articoli'];
        $pag = qk_pagination_params(array_keys($sortMap), 'nome', 'asc');
        $orderCol = $sortMap[$pag['sort']] ?? 'l.nome';
        $sql .= " ORDER BY " . $orderCol . " " . ($pag['dir'] === 'asc' ? 'ASC' : 'DESC');

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $pricelists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcola lo stato effettivo di ciascun listino nel controller
        $oggi = date('Y-m-d');
        foreach ($pricelists as &$ls) {
            $isAttivo = (bool)$ls['attivo'];
            $inRange  = (!$ls['valido_dal'] || $ls['valido_dal'] <= $oggi)
                     && (!$ls['valido_al']  || $ls['valido_al']  >= $oggi);
            if ($isAttivo && $inRange) {
                $ls['stato_effettivo'] = 'active';
            } elseif (!$isAttivo) {
                $ls['stato_effettivo'] = 'disattivato';
            } else {
                $ls['stato_effettivo'] = 'expired';
            }
        }
        unset($ls);

        $total = count($pricelists);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_list.php', [
            'page_title'    => 'Listini vendita',
            'page_subtitle' => 'Gestione dei listini prezzi di vendita',
            'breadcrumbs'   => [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Listini vendita']],
            'pricelists'    => $pricelists,
            'total'         => $total,
            'pag'           => $pag,
            'search'        => $search ?? '',
            'filtro_attivo' => $filtro_attivo ?? '',
        ]);
    }

    /* ── Crea ───────────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $errors = [];
        $values = [
            'nome'       => '',
            'descrizione'=> '',
            'valuta'     => 'EUR',
            'attivo'     => 1,
            'valido_dal' => date('Y-m-d'),
            'valido_al'  => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Valuta deve essere un codice ISO di 3 lettere (es. EUR, USD).';
                }

                if (empty($errors)) {
                    try {
                        $stmt = $this->pdo->prepare(
                            "INSERT INTO listini (nome, descrizione, valuta, attivo, valido_dal, valido_al)
                             VALUES (:nome, :descrizione, :valuta, :attivo, :valido_dal, :valido_al)"
                        );
                        $stmt->execute([
                            ':nome'        => $values['nome'],
                            ':descrizione' => $values['descrizione'] ?: null,
                            ':valuta'      => $values['valuta'],
                            ':attivo'      => $values['attivo'],
                            ':valido_dal'  => $values['valido_dal'],
                            ':valido_al'   => $values['valido_al'],
                        ]);
                        $newId = (int)$this->pdo->lastInsertId();
                        redirect_with('sales/pricelists/edit?id=' . $newId, 'success', 'Listino creato. Ora puoi aggiungere i prezzi prodotto.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Nuovo listino vendita',
            'page_subtitle' => 'Crea un nuovo listino prezzi di vendita',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => 'Nuovo'],
            ],
            'errors'  => $errors,
            'values'  => $values,
            'isEdit'  => false,
            'items'   => [],
            'clienti_assegnati' => [],
        ]);
    }

    /* ── Modifica ───────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'Nessun listino specificato.');
        }

        $stmt = $this->pdo->prepare("SELECT * FROM listini WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pricelist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pricelist) {
            redirect_with('sales/pricelists', 'danger', 'Listino non trovato.');
        }

        $errors = [];
        $values = [
            'nome'        => $pricelist['nome'],
            'descrizione' => $pricelist['descrizione'] ?? '',
            'valuta'      => $pricelist['valuta'],
            'attivo'      => (int)$pricelist['attivo'],
            'valido_dal'  => $pricelist['valido_dal'] ?: '',
            'valido_al'   => $pricelist['valido_al'] ?: '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Codice valuta non valido.';
                }

                if (empty($errors)) {
                    try {
                        $this->pdo->prepare(
                            "UPDATE listini SET
                                nome        = :nome,
                                descrizione = :descrizione,
                                valuta      = :valuta,
                                attivo      = :attivo,
                                valido_dal  = :valido_dal,
                                valido_al   = :valido_al
                             WHERE id = :id"
                        )->execute([
                            ':nome'        => $values['nome'],
                            ':descrizione' => $values['descrizione'] ?: null,
                            ':valuta'      => $values['valuta'],
                            ':attivo'      => $values['attivo'],
                            ':valido_dal'  => $values['valido_dal'],
                            ':valido_al'   => $values['valido_al'],
                            ':id'          => $id,
                        ]);
                        redirect_with('sales/pricelists/edit?id=' . $id, 'success', 'Listino aggiornato.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore: ' . $e->getMessage();
                    }
                }
            }
        }

        // Righe prodotto (con UoM nativa del prezzo o fallback UoM stock)
        $stmtItems = $this->pdo->prepare(
            "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno, 
                    COALESCE(um_p.codice, um_stock.codice) AS unita_misura,
                    p.unita_misura_stock_id AS uom_stock_id,
                    um_stock.codice AS uom_stock_code,
                    p.unita_contenuto_id AS uom_commercial_id,
                    um_comm.codice AS uom_commercial_code
             FROM listini_prezzi lp
             JOIN prodotti p ON p.id = lp.prodotto_id
             LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
             LEFT JOIN unita_misura um_p ON um_p.id = lp.unita_misura_id
             LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
             WHERE lp.listino_id = :lid
             ORDER BY p.nome ASC, lp.quantita_minima ASC"
        );
        $stmtItems->execute([':lid' => $id]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Clienti che usano questo listino come default
        $stmtClienti = $this->pdo->prepare(
            "SELECT cf.id,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente
             FROM dettagli_clienti dc
             JOIN clienti_fornitori cf ON cf.id = dc.soggetto_id
             WHERE dc.listino_id = :lid
             ORDER BY nome_cliente ASC"
        );
        $stmtClienti->execute([':lid' => $id]);
        $clienti_assegnati = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Modifica listino vendita',
            'page_subtitle' => 'Gestisci il listino e i prezzi prodotto',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => e($pricelist['nome'])],
            ],
            'errors'            => $errors,
            'values'            => $values,
            'isEdit'            => true,
            'id'                => $id,
            'items'             => $items,
            'clienti_assegnati' => $clienti_assegnati,
        ]);
    }

    /* ── Elimina ────────────────────────────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('sales/pricelists', 'danger', 'Metodo non consentito.');
        }
        if (!csrf_verify()) {
            redirect_with('sales/pricelists', 'danger', 'Token di sicurezza invalido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'ID non valido.');
        }

        // Verifica che non sia usato in preventivi aperti
        $stmtCheck = $this->pdo->prepare(
            "SELECT COUNT(*) FROM preventivi WHERE listino_id = :id AND stato NOT IN ('annullato','rifiutato')"
        );
        $stmtCheck->execute([':id' => $id]);
        $usatoInPreventivi = (int)$stmtCheck->fetchColumn();

        if ($usatoInPreventivi > 0) {
            redirect_with('sales/pricelists', 'danger', "Impossibile eliminare: il listino è usato in $usatoInPreventivi preventivo/i attivi.");
        }

        try {
            $this->pdo->prepare("DELETE FROM listini WHERE id = :id")->execute([':id' => $id]);
            redirect_with('sales/pricelists', 'success', 'Listino eliminato.');
        } catch (Throwable $e) {
            redirect_with('sales/pricelists', 'danger', 'Impossibile eliminare: ' . $e->getMessage());
        }
    }

    /* ── API: salva riga prezzo ─────────────────────────────────────────────── */

    public function apiSaveRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId   = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $rigaId      = filter_input(INPUT_POST, 'riga_id',    FILTER_VALIDATE_INT); // 0 = nuova
        $prodottoId  = filter_input(INPUT_POST, 'prodotto_id', FILTER_VALIDATE_INT);
        $qMin        = filter_input(INPUT_POST, 'quantita_minima', FILTER_VALIDATE_FLOAT);
        $prezzo      = filter_input(INPUT_POST, 'prezzo_unitario', FILTER_VALIDATE_FLOAT);
        $sconto      = filter_input(INPUT_POST, 'sconto_perc', FILTER_VALIDATE_FLOAT);
        $unitaMisuraId = filter_input(INPUT_POST, 'unita_misura_id', FILTER_VALIDATE_INT);
        $note        = trim(post('note') ?? '');

        if (!$listinoId || !$prodottoId || $prezzo === false || $prezzo === null) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti o non validi.']);
            exit;
        }

        // Verifica listino esiste
        $stmtL = $this->pdo->prepare("SELECT id FROM listini WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        if (!$stmtL->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Listino non trovato.']);
            exit;
        }

        $qMin   = ($qMin === false || $qMin === null) ? 0.0 : $qMin;
        $sconto = ($sconto === false || $sconto === null) ? 0.0 : $sconto;

        try {
            if ($rigaId) {
                // UPDATE
                $this->pdo->prepare(
                    "UPDATE listini_prezzi SET
                        prodotto_id     = :prodotto_id,
                        quantita_minima = :qmin,
                        prezzo_unitario = :prezzo,
                        sconto_perc     = :sconto,
                        unita_misura_id = :unita_misura_id,
                        note            = :note
                     WHERE id = :id AND listino_id = :listino_id"
                )->execute([
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':unita_misura_id' => $unitaMisuraId ?: null,
                    ':note'        => $note ?: null,
                    ':id'          => $rigaId,
                    ':listino_id'  => $listinoId,
                ]);
                $newId = $rigaId;
            } else {
                // INSERT
                $this->pdo->prepare(
                    "INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, unita_misura_id, note)
                     VALUES (:listino_id, :prodotto_id, :qmin, :prezzo, :sconto, :unita_misura_id, :note)"
                )->execute([
                    ':listino_id'  => $listinoId,
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':unita_misura_id' => $unitaMisuraId ?: null,
                    ':note'        => $note ?: null,
                ]);
                $newId = (int)$this->pdo->lastInsertId();
            }

            // Restituisce la riga aggiornata con nome prodotto e UoM
            $stmtRow = $this->pdo->prepare(
                "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno, 
                        COALESCE(um_p.codice, um_stock.codice) AS unita_misura,
                        p.unita_misura_stock_id AS uom_stock_id,
                        um_stock.codice AS uom_stock_code,
                        p.unita_contenuto_id AS uom_commercial_id,
                        um_comm.codice AS uom_commercial_code
                 FROM listini_prezzi lp
                 JOIN prodotti p ON p.id = lp.prodotto_id
                 LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
                 LEFT JOIN unita_misura um_p ON um_p.id = lp.unita_misura_id
                 LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
                 WHERE lp.id = :id"
            );
            $stmtRow->execute([':id' => $newId]);
            $riga = $stmtRow->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'riga' => $riga]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: elimina riga prezzo ───────────────────────────────────────────── */

    public function apiDeleteRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $rigaId = filter_input(INPUT_POST, 'riga_id', FILTER_VALIDATE_INT);
        if (!$rigaId) {
            echo json_encode(['ok' => false, 'error' => 'ID riga non valido.']);
            exit;
        }

        try {
            $this->pdo->prepare("DELETE FROM listini_prezzi WHERE id = :id")->execute([':id' => $rigaId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: ricerca prodotti (per autocomplete righe) ────────────────────── */

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(get_param('q') ?? '');
        
        $where = "WHERE p.attivo = 1";
        $params = [];

        if ($q !== '') {
            if (mb_strlen($q) === 1) {
                // Ricerca ottimizzata per singolo carattere (inizia con o SKU esatto)
                $where .= " AND (p.nome LIKE :q_start OR p.sku_interno = :q OR p.codice_erp = :q)";
                $params = [':q' => $q, ':q_start' => $q . '%'];
            } else {
                // Ricerca parziale contiene
                $where .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR p.codice_erp LIKE :q)";
                $params = [':q' => '%' . $q . '%'];
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nome AS label, p.sku_interno AS sku, um.codice AS unita_misura,
                    p.unita_misura_stock_id, um_c.codice AS unita_misura_commerciale,
                    p.unita_contenuto_id, p.quantita_contenuto,
                    pf.rrp_min, pf.rrp_max
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
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    /* ── Metodo di servizio: ottieni prezzo vendita ─────────────────────────── */

    /**
     * Determina il prezzo di vendita per un prodotto dato un listino, una quantità e una data.
     * Strategia (analoga a getPurchasePrice):
     *  1. Cerca nei listini associati del cliente (in ordine di priorità) o nel listino specificato
     *  2. Se non trova, cade sul listino standard o ricarico su costo
     */
    public function getSalePrice(int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
    {
        if (!$dataRiferimento) {
            $dataRiferimento = date('Y-m-d');
        }

        // --- LEVEL 1: DEDICATO (Client pricelists or requested listinoId) ---
        
        // Se c'è un cliente specificato, scorriamo i suoi listini attivi in ordine di priorità decrescente
        if ($clienteId && $clienteId > 0) {
            $stmtClientiListini = $this->pdo->prepare("
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
                $stmtP = $this->pdo->prepare(
                    "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
                     FROM listini_prezzi
                     WHERE listino_id = :lid
                       AND prodotto_id = :pid
                       AND quantita_minima <= :qty
                     ORDER BY quantita_minima DESC
                     LIMIT 1"
                );
                $stmtP->execute([
                    ':lid' => $clientListino['listino_id'],
                    ':pid' => $prodottoId,
                    ':qty' => $quantita,
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
        if ($listinoId > 0 && $listinoId !== 1) {
            $stmtL = $this->pdo->prepare(
                "SELECT id, nome, valuta FROM listini
                 WHERE id = :id AND attivo = 1
                   AND (valido_dal IS NULL OR valido_dal <= :data)
                   AND (valido_al  IS NULL OR valido_al  >= :data)"
            );
            $stmtL->execute([':id' => $listinoId, ':data' => $dataRiferimento]);
            $listino = $stmtL->fetch(PDO::FETCH_ASSOC);

            if ($listino) {
                $stmtP = $this->pdo->prepare(
                    "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
                     FROM listini_prezzi
                     WHERE listino_id = :lid
                       AND prodotto_id = :pid
                       AND quantita_minima <= :qty
                     ORDER BY quantita_minima DESC
                     LIMIT 1"
                );
                $stmtP->execute([
                    ':lid' => $listinoId,
                    ':pid' => $prodottoId,
                    ':qty' => $quantita,
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

        // --- LEVEL 2: STANDARD / PUBBLICO (ID 1) ---
        $fallbackListinoId = 1;
        if ($listinoId !== $fallbackListinoId) {
            $stmtL = $this->pdo->prepare(
                "SELECT id, nome, valuta FROM listini
                 WHERE id = :id AND attivo = 1
                   AND (valido_dal IS NULL OR valido_dal <= :data)
                   AND (valido_al  IS NULL OR valido_al  >= :data)"
            );
            $stmtL->execute([':id' => $fallbackListinoId, ':data' => $dataRiferimento]);
            $listino = $stmtL->fetch(PDO::FETCH_ASSOC);

            if ($listino) {
                $stmtP = $this->pdo->prepare(
                    "SELECT prezzo_unitario, sconto_perc, note, quantita_minima, unita_misura_id
                     FROM listini_prezzi
                     WHERE listino_id = :lid
                       AND prodotto_id = :pid
                       AND quantita_minima <= :qty
                     ORDER BY quantita_minima DESC
                     LIMIT 1"
                );
                $stmtP->execute([
                    ':lid' => $fallbackListinoId,
                    ':pid' => $prodottoId,
                    ':qty' => $quantita,
                ]);
                $row = $stmtP->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $qMin    = (float)$row['quantita_minima'];
                    $sorgente = 'Listino Standard: ' . $listino['nome'];
                    if ($qMin > 0) {
                        $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
                    }

                    return [
                        'prezzo_unitario' => (float)$row['prezzo_unitario'],
                        'sconto_perc'     => (float)$row['sconto_perc'],
                        'valuta'          => $listino['valuta'],
                        'listino_id'      => $fallbackListinoId,
                        'listino_nome'    => $listino['nome'],
                        'prezzo_sorgente' => $sorgente,
                        'origine'         => 'standard',
                        'unita_misura_id' => $row['unita_misura_id'] !== null ? (int)$row['unita_misura_id'] : null,
                    ];
                }
            }
        }

        // --- LEVEL 3: EMERGENZA RICARICO SU COSTO (+20%) ---
        $costo_unitario = 0.0;
        $stCost = $this->pdo->prepare('
            SELECT MIN(pfp.prezzo) 
            FROM prodotti_fornitori_prezzi pfp
            JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
            WHERE pf.prodotto_id = ? AND pf.attivo = 1
        ');
        $stCost->execute([$prodottoId]);
        $costo_unitario_col = $stCost->fetchColumn();

        if ($costo_unitario_col !== false && $costo_unitario_col !== null) {
            $costPrice = (float)$costo_unitario_col;
            $computedPrice = round($costPrice * 1.20, 2);
            return [
                'prezzo_unitario' => $computedPrice,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'listino_id'      => 0,
                'listino_nome'    => 'Ricarico automatico su costo',
                'prezzo_sorgente' => 'Prezzo calcolato: costo d\'acquisto +20% (listino di vendita non configurato)',
                'origine'         => 'costo',
                'unita_misura_id' => null,
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

    /* ── API endpoint: ottieni prezzo vendita ───────────────────────────────── */

    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $prodottoId = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $listinoId  = filter_input(INPUT_GET, 'listino_id',  FILTER_VALIDATE_INT);
        $clienteId  = filter_input(INPUT_GET, 'cliente_id',  FILTER_VALIDATE_INT);
        $quantita   = filter_input(INPUT_GET, 'quantita',    FILTER_VALIDATE_FLOAT);
        $data       = get_param('data') ?: null;

        if (!$prodottoId) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto ID mancante.']);
            exit;
        }

        $listinoId = $listinoId ?: 0;
        $quantita = ($quantita === false || $quantita === null) ? 1.0 : $quantita;

        $priceInfo = $this->getSalePrice($prodottoId, $listinoId, $quantita, $data, $clienteId);

        $costo_unitario = 0.0;
        $rrp_min = null;
        $rrp_max = null;

        $stRrp = $this->pdo->prepare('
            SELECT pf.rrp_min, pf.rrp_max,
                   (SELECT MIN(pfp.prezzo) 
                    FROM prodotti_fornitori_prezzi pfp 
                    WHERE pfp.prodotto_fornitore_id = pf.id 
                      AND pfp.quantita_minima = 0) AS costo_unitario
            FROM prodotti_fornitori pf
            WHERE pf.prodotto_id = ? AND pf.attivo = 1
            ORDER BY pf.ordine_preferenza_fornitore ASC
            LIMIT 1
        ');
        $stRrp->execute([$prodottoId]);
        $rrpData = $stRrp->fetch(PDO::FETCH_ASSOC);
        if ($rrpData) {
            $costo_unitario = (float)($rrpData['costo_unitario'] ?? 0.0);
            $rrp_min = $rrpData['rrp_min'] !== null ? (float)$rrpData['rrp_min'] : null;
            $rrp_max = $rrpData['rrp_max'] !== null ? (float)$rrpData['rrp_max'] : null;
        }

        if ($priceInfo) {
            echo json_encode([
                'ok'              => true,
                'prezzo_unitario' => $priceInfo['prezzo_unitario'],
                'sconto_perc'     => $priceInfo['sconto_perc'],
                'valuta'          => $priceInfo['valuta'],
                'listino_id'      => $priceInfo['listino_id'],
                'prezzo_sorgente' => $priceInfo['prezzo_sorgente'],
                'origine'         => $priceInfo['origine'],
                'costo_unitario'  => $costo_unitario,
                'rrp_min'         => $rrp_min,
                'rrp_max'         => $rrp_max,
                'unita_misura_id' => $priceInfo['unita_misura_id'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo_unitario' => 0.0,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
                'origine'         => 'manuale',
                'unita_misura_id' => null,
            ]);
        }
        exit;
    }

    /* ── API endpoint: ottieni tutti i clienti con stato assegnazione ───────── */

    public function apiClientiAssegnazione(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $listinoId = filter_input(INPUT_GET, 'listino_id', FILTER_VALIDATE_INT);
        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            // Ottieni tutti i clienti attivi
            $stmt = $this->pdo->prepare("
                SELECT cf.id,
                       COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente,
                       cf.partita_iva,
                       (SELECT 1 FROM clienti_listini cl WHERE cl.cliente_id = cf.id AND cl.listino_id = :lid) AS assegnato_pivot,
                       (SELECT 1 FROM dettagli_clienti dc WHERE dc.soggetto_id = cf.id AND dc.listino_id = :lid) AS assegnato_default
                FROM clienti_fornitori cf
                WHERE cf.tipo IN ('cliente', 'entrambi') AND cf.attivo = 1
                ORDER BY nome_cliente ASC
            ");
            $stmt->execute([':lid' => $listinoId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'id' => (int)$row['id'],
                    'nome_cliente' => $row['nome_cliente'],
                    'partita_iva' => $row['partita_iva'] ?? '',
                    'assegnato' => ($row['assegnato_pivot'] || $row['assegnato_default']) ? true : false,
                ];
            }

            echo json_encode(['ok' => true, 'clienti' => $result]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API endpoint: salva assegnazioni massive ───────────────────────────── */

    public function apiAssegnaClienti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $clienteIds = $_POST['cliente_ids'] ?? [];

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Rimuovi assegnazioni esistenti nel pivot
            $stmtDel = $this->pdo->prepare("DELETE FROM clienti_listini WHERE listino_id = ?");
            $stmtDel->execute([$listinoId]);

            // Inserisci le nuove
            if (!empty($clienteIds)) {
                $stmtIns = $this->pdo->prepare("
                    INSERT INTO clienti_listini (cliente_id, listino_id, priorita)
                    VALUES (?, ?, 10)
                ");
                foreach ($clienteIds as $cid) {
                    $stmtIns->execute([(int)$cid, $listinoId]);
                }
            }

            $this->pdo->commit();

            // Ricarica la lista aggiornata dei clienti assegnati per restituirla al frontend
            $stmtClienti = $this->pdo->prepare("
                SELECT DISTINCT cf.id,
                       COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente
                FROM clienti_fornitori cf
                LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
                LEFT JOIN clienti_listini cl ON cl.cliente_id = cf.id
                WHERE (dc.listino_id = :lid OR cl.listino_id = :lid)
                  AND cf.attivo = 1
                ORDER BY nome_cliente ASC
            ");
            $stmtClienti->execute([':lid' => $listinoId]);
            $clienti_assegnati = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'clienti_assegnati' => $clienti_assegnati]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
