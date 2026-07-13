<?php

declare(strict_types=1);

// D3.2 (PIANO Motore di Pricing Multi-UoM §8 Fase 3): serve qk_uom_converti_riga() per
// convertire la quantità richiesta in Stock UoM prima di interrogare il motore di prezzo,
// e per calcolare/salvare il valore canonico delle soglie a ogni scrittura.
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/pricing.php';

final class PurchasesController extends BaseController
{
    public function dashboard(): void
    {
        // 1. Raccoglie i KPI
        $rfq_bozza = (int) $this->pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'bozza'")->fetchColumn();
        $rfq_inviata = (int) $this->pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'inviata'")->fetchColumn();
        $ordini_attivi = (int) $this->pdo->query("SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')")->fetchColumn();
        $listini_attivi = (int) $this->pdo->query("SELECT COUNT(*) FROM listini_acquisto WHERE attivo = 1 AND (valido_dal IS NULL OR valido_dal <= CURRENT_DATE) AND (valido_al IS NULL OR valido_al >= CURRENT_DATE)")->fetchColumn();

        // 2. Ultime RFQ (max 5)
        $st = $this->pdo->query("
            SELECT r.id, r.numero, r.created_at, r.stato, r.totale,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
              FROM rfq_testata r
         LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
          ORDER BY r.id DESC
             LIMIT 5
        ");
        $recent_rfqs = $st->fetchAll(PDO::FETCH_ASSOC);

        // 3. Ultimi Ordini d'Acquisto (max 5)
        $st2 = $this->pdo->query("
            SELECT ot.id, ot.numero, ot.created_at, ot.stato, ot.totale,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
              FROM ordini_testata ot
         LEFT JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
          ORDER BY ot.id DESC
             LIMIT 5
        ");
        $recent_orders = $st2->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/purchases_dashboard.php', [
            'page_title'     => 'Dashboard Acquisti',
            'page_subtitle'  => 'Panoramica operativa acquisti e approvvigionamento',
            'breadcrumbs'    => [['label' => 'Acquisti']],
            'rfq_bozza'      => $rfq_bozza,
            'rfq_inviata'    => $rfq_inviata,
            'ordini_attivi'  => $ordini_attivi,
            'listini_attivi' => $listini_attivi,
            'recent_rfqs'    => $recent_rfqs,
            'recent_orders'  => $recent_orders,
        ]);
    }

    /* ── Listini acquisto ────────────────────────────────────────────────── */

    public function indexPricelists(): void
    {
        $search = get_param('q');

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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $pricelists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcola lo stato effettivo nel controller
        $oggi = date('Y-m-d');
        foreach ($pricelists as &$la) {
            $inRange  = (!$la['valido_dal'] || $la['valido_dal'] <= $oggi)
                     && (!$la['valido_al']  || $la['valido_al']  >= $oggi);
            $la['stato_effettivo'] = $inRange ? 'active' : 'expired';
        }
        unset($la);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_list.php', [
            'page_title'    => 'Listini acquisto',
            'page_subtitle' => "Gestione dei listini di acquisto e delle tariffe concordate con i fornitori",
            'breadcrumbs'   => [['label' => 'Acquisti', 'url' => url('purchases/rfq')], ['label' => 'Listini acquisto']],
            'pricelists'    => $pricelists,
            'search'        => $search,
        ]);
    }

    public function createPricelist(): void
    {
        $errors = [];
        $values = [
            'fornitore_id'    => '',
            'fornitore_label' => '',
            'nome_listino'    => '',
            'valido_dal'      => '',
            'valido_al'       => '',
            'note'            => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'fornitore_id'    => post('fornitore_id'),
                    'fornitore_label' => post('fornitore_label'),
                    'nome_listino'    => trim(post('nome_listino') ?? ''),
                    'valido_dal'      => post('valido_dal') ?: null,
                    'valido_al'       => post('valido_al') ?: null,
                    'note'            => post('note'),
                ];

                if (empty($values['fornitore_id'])) {
                    $errors['fornitore_id'] = 'Fornitore obbligatorio.';
                }
                if (empty($values['nome_listino'])) {
                    $errors['nome_listino'] = 'Nome listino obbligatorio.';
                }

                if (empty($errors)) {
                    try {
                        $stmt = $this->pdo->prepare(
                            "INSERT INTO listini_acquisto (fornitore_id, nome_listino, valido_dal, valido_al, note)
                             VALUES (:fornitore_id, :nome_listino, :valido_dal, :valido_al, :note)"
                        );
                        $stmt->execute([
                            ':fornitore_id' => $values['fornitore_id'],
                            ':nome_listino' => $values['nome_listino'],
                            ':valido_dal'   => $values['valido_dal'],
                            ':valido_al'    => $values['valido_al'],
                            ':note'         => $values['note'],
                        ]);
                        $newId = (int)$this->pdo->lastInsertId();
                        redirect_with('purchases/pricelists/edit?id=' . $newId, 'success', 'Listino acquisto salvato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_form.php', [
            'page_title'    => 'Nuovo listino acquisto',
            'page_subtitle' => 'Crea un nuovo listino acquisto per un fornitore',
            'breadcrumbs'   => [
                ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
                ['label' => 'Listini acquisto', 'url' => url('purchases/pricelists')],
                ['label' => 'Nuovo']
            ],
            'errors'        => $errors,
            'values'        => $values,
            'isEdit'        => false,
            'items'         => [],
        ]);
    }

    public function editPricelist(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('purchases/pricelists', 'danger', 'Nessun listino specificato.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT la.*, 
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_label
             FROM listini_acquisto la
             JOIN clienti_fornitori cf ON cf.id = la.fornitore_id
             WHERE la.id = :id AND la.attivo = 1"
        );
        $stmt->execute([':id' => $id]);
        $pricelist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pricelist) {
            redirect_with('purchases/pricelists', 'danger', 'Listino non trovato.');
        }

        $errors = [];
        $values = [
            'fornitore_id'    => $pricelist['fornitore_id'],
            'fornitore_label' => $pricelist['fornitore_label'],
            'nome_listino'    => $pricelist['nome_listino'],
            'valido_dal'      => $pricelist['valido_dal'] ?: '',
            'valido_al'       => $pricelist['valido_al'] ?: '',
            'note'            => $pricelist['note'] ?? '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'fornitore_id'    => post('fornitore_id'),
                    'fornitore_label' => post('fornitore_label'),
                    'nome_listino'    => trim(post('nome_listino') ?? ''),
                    'valido_dal'      => post('valido_dal') ?: null,
                    'valido_al'       => post('valido_al') ?: null,
                    'note'            => post('note'),
                ];

                if (empty($values['fornitore_id'])) {
                    $errors['fornitore_id'] = 'Fornitore obbligatorio.';
                }
                if (empty($values['nome_listino'])) {
                    $errors['nome_listino'] = 'Nome listino obbligatorio.';
                }

                if (empty($errors)) {
                    try {
                        $stmtUpdate = $this->pdo->prepare(
                            "UPDATE listini_acquisto SET
                                fornitore_id = :fornitore_id,
                                nome_listino = :nome_listino,
                                valido_dal   = :valido_dal,
                                valido_al    = :valido_al,
                                note         = :note
                             WHERE id = :id"
                        );
                        $stmtUpdate->execute([
                            ':fornitore_id' => $values['fornitore_id'],
                            ':nome_listino' => $values['nome_listino'],
                            ':valido_dal'   => $values['valido_dal'],
                            ':valido_al'    => $values['valido_al'],
                            ':note'         => $values['note'],
                            ':id'           => $id,
                        ]);
                        redirect_with('purchases/pricelists/edit?id=' . $id, 'success', 'Listino acquisto aggiornato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $pag = qk_pagination_params(['nome', 'sku_interno', 'quantita_minima', 'prezzo'], 'nome', 'asc');
        $limit  = $pag['limit'];
        $offset = $pag['offset'];
        $page   = $pag['page'];
        $sort   = $pag['sort'];
        $dir    = $pag['dir'];

        // Mappatura colonne ordinamento
        $sortColMap = [
            'nome'            => 'p.nome',
            'sku_interno'     => 'p.sku_interno',
            'quantita_minima' => 'pfp.quantita_minima',
            'prezzo'          => 'pfp.prezzo'
        ];
        $orderByCol = $sortColMap[$sort] ?? 'p.nome';

        // Count per la paginazione
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM prodotti_fornitori_prezzi pfp WHERE pfp.listino_acquisto_id = :lid");
        $stmtCount->execute([':lid' => $id]);
        $total = (int)$stmtCount->fetchColumn();
        $pages = (int)ceil($total / $limit);

        $stmtItems = $this->pdo->prepare(
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
        $stmtItems->bindValue(':lid', $id, PDO::PARAM_INT);
        $stmtItems->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtItems->execute();
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_form.php', [
            'page_title'    => 'Modifica listino acquisto',
            'page_subtitle' => 'Gestisci i dettagli del listino ed esamina gli articoli associati',
            'breadcrumbs'   => [
                ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
                ['label' => 'Listini acquisto', 'url' => url('purchases/pricelists')],
                ['label' => $pricelist['nome_listino']]
            ],
            'errors'        => $errors,
            'values'        => $values,
            'isEdit'        => true,
            'id'            => $id,
            'items'         => $items,
            'pag'           => $pag,
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
            'sort'          => $sort,
            'dir'           => $dir,
        ]);
    }

    public function deletePricelist(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('purchases/pricelists', 'danger', 'Metodo non consentito.');
        }
        if (!csrf_verify()) {
            redirect_with('purchases/pricelists', 'danger', 'Token di sicurezza invalido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('purchases/pricelists', 'danger', 'Identificativo non valido.');
        }

        try {
            $this->pdo->prepare("UPDATE listini_acquisto SET attivo = 0, valido_al = CURRENT_DATE WHERE id = :id")->execute([':id' => $id]);
            redirect_with('purchases/pricelists', 'success', 'Listino acquisto eliminato con successo.');
        } catch (Throwable $e) {
            redirect_with('purchases/pricelists', 'danger', "Impossibile eliminare il listino: " . $e->getMessage());
        }
    }

    /**
     * Trova il prezzo di acquisto corretto per un prodotto, un fornitore e una quantità.
     * Restituisce un array con i dettagli del prezzo e la descrizione della sorgente.
     */
    /**
     * CONTRATTO (D3.2, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
     * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
     * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
     * attuale, apiOttieniPrezzo(), converte la quantità ricevuta dal client prima di invocarla.
     */
    public function getPurchasePrice(int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
    {
        // Refactoring 14/07/2026: logica estratta in includes/pricing.php (qk_get_purchase_price()),
        // pura (PDO + parametri, nessun riferimento a HTTP), così riutilizzabile da script CLI,
        // cron, o altri moduli senza passare da questo controller. Nessuna modifica di
        // comportamento (a parte l'unità nella label "Scaglione >=", già introdotta oggi anche
        // sul ramo "Prezzo base", coerente col ramo "Listino" già corretto sopra).
        return qk_get_purchase_price($this->pdo, $prodottoId, $fornitoreId, $quantita, $dataRiferimento);
    }

    /**
     * Endpoint AJAX per la determinazione dinamica del prezzo.
     */
    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $prodottoId  = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $fornitoreId = filter_input(INPUT_GET, 'fornitore_id', FILTER_VALIDATE_INT);
        $quantita    = filter_input(INPUT_GET, 'quantita', FILTER_VALIDATE_FLOAT);
        // D3.2 (PIANO Motore di Pricing Multi-UoM §5.1/§8 Fase 3): nuovo parametro opzionale,
        // stesso principio di SalesPricelistsController::apiOttieniPrezzo() — l'unità in cui il
        // chiamante esprime `quantita`. Assente = retrocompatibile, assume già Stock UoM.
        $unitaRichiestaId = filter_input(INPUT_GET, 'unita_misura_id', FILTER_VALIDATE_INT) ?: null;
        $data        = get_param('data') ?: null;

        if ($quantita === false || $quantita === null) {
            $quantita = 1.0;
        }

        if (!$prodottoId || !$fornitoreId) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        $quantitaStock = $unitaRichiestaId
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaRichiestaId, $quantita, 0.0)['quantita_stock']
            : $quantita;

        $priceInfo = $this->getPurchasePrice($prodottoId, $fornitoreId, $quantitaStock, $data);

        if ($priceInfo) {
            echo json_encode([
                'ok'                  => true,
                'prezzo'              => [
                    'prezzo_netto'   => $priceInfo['prezzo'],
                    'sconto_perc'    => $priceInfo['sconto_perc'],
                    'sku_fornitore'  => $priceInfo['sku_fornitore'],
                ],
                'prezzo_valore'       => $priceInfo['prezzo'],
                'sconto_perc'         => $priceInfo['sconto_perc'],
                'sku_fornitore'       => $priceInfo['sku_fornitore'],
                'listino_acquisto_id' => $priceInfo['listino_acquisto_id'],
                'prezzo_sorgente'     => $priceInfo['prezzo_sorgente'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo'          => [
                    'prezzo_netto'   => 0.0,
                    'sconto_perc'    => 0.0,
                    'sku_fornitore'  => '',
                ],
                'prezzo_valore'   => 0.0,
                'sconto_perc'     => 0.0,
                'sku_fornitore'   => '',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
            ]);
        }
        exit;
    }

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(get_param('q') ?? '');
        
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

        $stmt = $this->pdo->prepare(
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
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    public function apiSaveRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId     = filter_input(INPUT_POST, 'listino_acquisto_id', FILTER_VALIDATE_INT);
        $rigaId        = filter_input(INPUT_POST, 'riga_id',    FILTER_VALIDATE_INT); // 0 = nuova
        $prodottoId    = filter_input(INPUT_POST, 'prodotto_id', FILTER_VALIDATE_INT);
        $qMin          = filter_input(INPUT_POST, 'quantita_minima', FILTER_VALIDATE_FLOAT);
        $prezzo        = filter_input(INPUT_POST, 'prezzo', FILTER_VALIDATE_FLOAT);
        $sconto        = filter_input(INPUT_POST, 'sconto_perc', FILTER_VALIDATE_FLOAT);
        $validoDal     = post('valido_dal') ?: null;
        $validoAl      = post('valido_al') ?: null;
        $note          = trim(post('note') ?? '');
        $skuFornitore  = trim(post('sku_fornitore') ?? '');

        if (!$listinoId || !$prodottoId || $prezzo === false || $prezzo === null) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti o non validi.']);
            exit;
        }

        // Recupera fornitore_id del listino
        $stmtL = $this->pdo->prepare("SELECT fornitore_id FROM listini_acquisto WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        $listino = $stmtL->fetch(PDO::FETCH_ASSOC);
        if (!$listino) {
            echo json_encode(['ok' => false, 'error' => 'Listino acquisto non trovato.']);
            exit;
        }
        $fornitoreId = (int)$listino['fornitore_id'];

        $qMin   = ($qMin === false || $qMin === null) ? 0.0 : $qMin;
        $sconto = ($sconto === false || $sconto === null) ? 0.0 : $sconto;

        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo non può essere negativo.']);
            exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto percentuale deve essere compreso tra 0 e 100.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Cerca o crea il record in prodotti_fornitori
            $stmtPf = $this->pdo->prepare("SELECT id, sku_fornitore FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1");
            $stmtPf->execute([':pid' => $prodottoId, ':fid' => $fornitoreId]);
            $pfRow = $stmtPf->fetch(PDO::FETCH_ASSOC);

            if ($pfRow) {
                $pfId = (int)$pfRow['id'];
                if ($pfRow['sku_fornitore'] !== $skuFornitore) {
                    $this->pdo->prepare("UPDATE prodotti_fornitori SET sku_fornitore = :sku WHERE id = :id")
                             ->execute([':sku' => $skuFornitore ?: null, ':id' => $pfId]);
                }
            } else {
                $this->pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, attivo, ordine_preferenza_fornitore, sku_fornitore) VALUES (:pid, :fid, 1, 10, :sku)")
                         ->execute([':pid' => $prodottoId, ':fid' => $fornitoreId, ':sku' => $skuFornitore ?: null]);
                $pfId = (int)$this->pdo->lastInsertId();
            }

            // D0.2 (PIANO Motore di Pricing Multi-UoM §5.4/§8): questo endpoint non valorizzava mai
            // unita_misura_id, a differenza di ProductsController::savePrezzo() che lo fa sempre.
            // Stesso principio di ereditarietà: se non esplicitamente inviato dal client, si eredita
            // l'unità di acquisto nativa definita sul legame prodotto-fornitore (prodotti_fornitori.unita_misura_acquisto_id).
            $umIdPost = filter_input(INPUT_POST, 'unita_misura_id', FILTER_VALIDATE_INT);
            if ($umIdPost) {
                $unitaMisuraId = $umIdPost;
            } else {
                $stmtUmForn = $this->pdo->prepare("SELECT unita_misura_acquisto_id FROM prodotti_fornitori WHERE id = :id");
                $stmtUmForn->execute([':id' => $pfId]);
                $unitaMisuraId = (int)$stmtUmForn->fetchColumn();
                if (!$unitaMisuraId) {
                    // Fallback finale: Stock UoM del prodotto, se il legame fornitore non ha ancora un'unità di acquisto propria.
                    $stmtUmProd = $this->pdo->prepare("SELECT unita_misura_stock_id FROM prodotti WHERE id = :pid");
                    $stmtUmProd->execute([':pid' => $prodottoId]);
                    $unitaMisuraId = (int)$stmtUmProd->fetchColumn();
                }
            }

            // Validazione "terza unità" (14/07/2026, audit a tappeto): stesso principio già applicato
            // in ProductsController::saveForn() (D0.4, PIANO §5.6/§7.5) — unita_misura_id sulla riga di
            // prezzo deve essere sempre o l'unità di stock o l'unità commerciale del prodotto, mai un
            // valore arbitrario. Qui il gap era solo latente (nessun chiamante JS invia oggi un valore
            // diverso), ma un chiamante futuro non veniva bloccato dal server.
            if ($umIdPost) {
                $stmtUmProdCheck = $this->pdo->prepare("SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid");
                $stmtUmProdCheck->execute([':pid' => $prodottoId]);
                $prodUmCheck = $stmtUmProdCheck->fetch(PDO::FETCH_ASSOC);
                $stockIdCheck = $prodUmCheck ? (int)$prodUmCheck['unita_misura_stock_id'] : 0;
                $commIdCheck  = ($prodUmCheck && $prodUmCheck['unita_contenuto_id'] !== null) ? (int)$prodUmCheck['unita_contenuto_id'] : null;
                if ($umIdPost !== $stockIdCheck && $umIdPost !== $commIdCheck) {
                    $this->pdo->rollBack();
                    echo json_encode(['ok' => false, 'error' => 'Unità di misura non valida: deve essere l\'unità di stock o l\'unità commerciale del prodotto.']);
                    exit;
                }
            }

            // D3.2 (PIANO Motore di Pricing Multi-UoM §8 Fase 1/3): a ogni scrittura, calcolare e
            // salvare il valore canonico della soglia (sempre Stock UoM) insieme all'intento originale.
            $qMinIntento = $qMin;
            $unitaSogliaId = $unitaMisuraId ?: null;
            $qMinStock = $qMin > 0
                ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaSogliaId, $qMin, 0.0)['quantita_stock']
                : 0.0;

            // [Bug 3] Controllo sovrapposizione scaglioni
            // Query blindata anche qui il 13/07/2026 (REVISIONE §6.15) per coerenza con ProductsController::savePrezzo():
            // in questo endpoint $listinoId è sempre valorizzato (validato più sopra), quindi il bug "colonna = NULL"
            // non si presenta oggi — ma la query resta corretta anche se in futuro questo vincolo dovesse cambiare.
            // Corretto ULTERIORMENTE il 14/07/2026 (PIANO Motore di Pricing Multi-UoM §7.4/§8): confronta ora
            // il valore canonico (quantita_minima_stock), non più il valore grezzo — vedi nota gemella in
            // ProductsController::savePrezzo() per il motivo (doppioni mascherati altrimenti rilevati solo
            // dal vincolo UNIQUE del database, con un errore SQL grezzo invece di questo messaggio).
            $stmtDup = $this->pdo->prepare("
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
                ':riga_id'         => $rigaId ?: 0
            ]);
            if ($stmtDup->fetch()) {
                $this->pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).']);
                exit;
            }

            if ($rigaId) {
                // Aggiorna riga
                // modificato_manualmente_il: aggiornato sempre su un salvataggio manuale (questo endpoint),
                // mai da apiImportPrezzi() — vedi REVISIONE §6.12: serve a evitare che un import massivo
                // sovrascriva silenziosamente un prezzo negoziato/corretto a mano dopo l'ultimo import.
                $this->pdo->prepare(
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
                    ':pfid'       => $pfId,
                    ':qmin'        => $qMin,
                    ':umid'        => $unitaMisuraId ?: null,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':valido_dal'  => $validoDal,
                    ':valido_al'   => $validoAl,
                    ':note'        => $note ?: null,
                    ':qmin_intento' => $qMinIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qmin_stock'   => $qMinStock,
                    ':id'          => $rigaId,
                    ':listino_id'  => $listinoId,
                ]);
                $newId = $rigaId;
            } else {
                // Inserisci riga
                $this->pdo->prepare(
                    "INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, unita_misura_id, prezzo, sconto_perc, valido_dal, valido_al, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
                     VALUES (:pfid, :listino_id, :qmin, :umid, :prezzo, :sconto, :valido_dal, :valido_al, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)"
                )->execute([
                    ':pfid'       => $pfId,
                    ':listino_id' => $listinoId,
                    ':qmin'        => $qMin,
                    ':umid'        => $unitaMisuraId ?: null,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':valido_dal'  => $validoDal,
                    ':valido_al'   => $validoAl,
                    ':note'        => $note ?: null,
                    ':qmin_intento' => $qMinIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qmin_stock'   => $qMinStock,
                ]);
                $newId = (int)$this->pdo->lastInsertId();
            }

            $this->pdo->commit();

            // Restituisce la riga inserita/aggiornata con dati prodotto e sku_fornitore
            $stmtRow = $this->pdo->prepare(
                "SELECT pfp.*, p.nome AS prodotto_nome, p.sku_interno, pf.sku_fornitore
                 FROM prodotti_fornitori_prezzi pfp
                 JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
                 JOIN prodotti p ON p.id = pf.prodotto_id
                 WHERE pfp.id = :id"
            );
            $stmtRow->execute([':id' => $newId]);
            $riga = $stmtRow->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'riga' => $riga]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

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
            $this->pdo->prepare("DELETE FROM prodotti_fornitori_prezzi WHERE id = :id")->execute([':id' => $rigaId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiImportPrezzi(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']); exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_acquisto_id', FILTER_VALIDATE_INT);
        $rows      = json_decode(post('rows') ?? '[]', true);

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'ID listino non specificato.']); exit;
        }
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        // Recupera fornitore_id del listino
        $stmtL = $this->pdo->prepare("SELECT fornitore_id FROM listini_acquisto WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        $listino = $stmtL->fetch(PDO::FETCH_ASSOC);
        if (!$listino) {
            echo json_encode(['ok' => false, 'error' => 'Listino acquisto non trovato.']); exit;
        }
        $fornitoreId = (int)$listino['fornitore_id'];

        $inserted   = 0;
        $skipped    = 0;
        $rowErrors  = [];
        $touchedIds = []; // id di prodotti_fornitori_prezzi scritte/confermate in questo import — usato per (a) non toccare due volte la stessa riga, (b) individuare a fine ciclo le righe importate in passato ma assenti da questo file (REVISIONE §6.12)

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $lineNum = $i + 2; // +2 perché riga 1 = intestazione Excel

                $sku = trim((string)($row['sku_interno'] ?? ''));
                if ($sku === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': SKU interno vuoto — saltata.';
                    continue;
                }

                // Cerca il prodotto
                // BUG CORRETTO (14/07/2026, secondo audit a tappeto): mancava unita_contenuto_id,
                // quindi $uomCommercialId sotto era sempre null e la validazione "unità soglia deve
                // essere stock o commerciale" (poco più sotto) rifiutava sempre un import CSV che
                // dichiarasse legittimamente l'unità commerciale (es. "FUSTO") in unita_misura_soglia.
                $stmtProd = $this->pdo->prepare('SELECT id, unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE sku_interno = :s AND attivo = 1');
                $stmtProd->execute([':s' => $sku]);
                $prodRow = $stmtProd->fetch(PDO::FETCH_ASSOC);
                if (!$prodRow) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': prodotto con SKU "' . htmlspecialchars($sku) . '" non trovato o non attivo — saltata.';
                    continue;
                }
                $prodottoId = (int)$prodRow['id'];
                $uomStockId = (int)$prodRow['unita_misura_stock_id'];
                $uomCommercialId = $prodRow['unita_contenuto_id'] !== null ? (int)$prodRow['unita_contenuto_id'] : null;
                $uomId = $uomStockId; // default retrocompatibile: assume Stock UoM se la colonna non è valorizzata

                // D4.6 (PIANO Motore di Pricing Multi-UoM §7.7/§8 Fase 4): colonna facoltativa —
                // se valorizzata, dichiara l'unità di quotazione/soglia di QUESTA riga (stesso
                // principio applicato lato vendite in SalesPricelistsController::apiImportPrezzi()).
                $umSogliaCodice = trim((string)($row['unita_misura_soglia'] ?? ''));
                if ($umSogliaCodice !== '') {
                    // Confronto case-insensitive (14/07/2026, audit a tappeto): un import CSV è
                    // compilato a mano da persone diverse, "fusto"/"Fusto"/"FUSTO" devono risolvere
                    // tutti allo stesso codice invece di essere rifiutati come "unità non valida".
                    $stmtUmSoglia = $this->pdo->prepare('SELECT id FROM unita_misura WHERE UPPER(codice) = UPPER(:c)');
                    $stmtUmSoglia->execute([':c' => $umSogliaCodice]);
                    $umSogliaId = $stmtUmSoglia->fetchColumn();
                    if (!$umSogliaId || ((int)$umSogliaId !== $uomStockId && (int)$umSogliaId !== $uomCommercialId)) {
                        $skipped++;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): unità soglia "' . htmlspecialchars($umSogliaCodice) . '" non valida per questo prodotto (deve essere l\'unità di stock o l\'unità commerciale) — saltata.';
                        continue;
                    }
                    $uomId = (int)$umSogliaId;
                }

                // Risolvi i campi numerici e date
                $toFloat = function(?string $v): float {
                    if ($v === null || trim($v) === '') return 0.0;
                    return (float) str_replace(',', '.', trim($v));
                };

                $prezzoVal = trim((string)($row['prezzo'] ?? ''));
                if ($prezzoVal === '' || !is_numeric(str_replace(',', '.', $prezzoVal))) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo vuoto o non valido — saltata.';
                    continue;
                }
                $prezzo = (float) str_replace(',', '.', $prezzoVal);

                $qMin         = $toFloat($row['quantita_minima'] ?? '0');
                $sconto       = $toFloat($row['sconto_perc'] ?? '0');
                $skuFornitore = trim((string)($row['sku_fornitore'] ?? ''));
                $validoDal    = !empty($row['valido_dal']) ? trim((string)$row['valido_dal']) : null;
                $validoAl     = !empty($row['valido_al']) ? trim((string)$row['valido_al']) : null;
                $note         = !empty($row['note']) ? trim((string)$row['note']) : null;

                // Stessa validazione di apiSaveRiga(): prezzo/sconto/quantita_minima non validi vengono saltati, non salvati ciecamente.
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
                    ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $uomId, $qMin, 0.0)['quantita_stock']
                    : 0.0;

                // Cerca o crea il record in prodotti_fornitori
                $stmtPf = $this->pdo->prepare("SELECT id, sku_fornitore FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1");
                $stmtPf->execute([':pid' => $prodottoId, ':fid' => $fornitoreId]);
                $pfRow = $stmtPf->fetch(PDO::FETCH_ASSOC);

                if ($pfRow) {
                    $pfId = (int)$pfRow['id'];
                    if ($pfRow['sku_fornitore'] !== $skuFornitore) {
                        $this->pdo->prepare("UPDATE prodotti_fornitori SET sku_fornitore = :sku WHERE id = :id")
                                 ->execute([':sku' => $skuFornitore ?: null, ':id' => $pfId]);
                    }
                } else {
                    $this->pdo->prepare("INSERT INTO prodotti_fornitori (prodotto_id, fornitore_id, attivo, ordine_preferenza_fornitore, sku_fornitore) VALUES (:pid, :fid, 1, 10, :sku)")
                             ->execute([':pid' => $prodottoId, ':fid' => $fornitoreId, ':sku' => $skuFornitore ?: null]);
                    $pfId = (int)$this->pdo->lastInsertId();
                }

                // Cerca se esiste già un prezzo per questo listino, prodotto_fornitore e scaglione.
                // Corretto il 14/07/2026 (audit a tappeto):
                //  1) confronto ora su quantita_minima_stock (canonico), non sul valore grezzo —
                //     stesso gap già noto e ora chiuso anche lato vendite (SalesPricelistsController).
                //  2) confronto NULL-safe su listino_acquisto_id (prezzo base, nessun listino): prima
                //     "listino_acquisto_id = :lid" con :lid NULL non trova mai nulla in SQL standard,
                //     stesso pattern già corretto in apiSaveRiga() di questo stesso controller.
                $stmtCheck = $this->pdo->prepare("
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
                    $rigaId = (int)$priceRow['id'];

                    // REVISIONE §6.12 — "Regola d'Oro": se qualcuno ha modificato questa riga a mano
                    // dopo l'ultimo import (o non è mai stata toccata da un import), non sovrascriverla
                    // silenziosamente. La riga resta comunque "vista" da questo import (touchedIds), quindi
                    // non verrà mai considerata orfana solo perché non aggiornata.
                    $modificataAMano = !empty($priceRow['modificato_manualmente_il'])
                        && (empty($priceRow['importato_il']) || $priceRow['modificato_manualmente_il'] > $priceRow['importato_il']);

                    if ($modificataAMano) {
                        $skipped++;
                        $touchedIds[] = $rigaId;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo modificato manualmente dopo l\'ultimo import — non sovrascritto, verificare a mano.';
                        continue;
                    }

                    // Update
                    // D4.6: unita_misura_id (unità di quotazione del prezzo) mancava del tutto in
                    // questo import — aggiunta qui insieme alle colonne canoniche.
                    $this->pdo->prepare("
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
                        ':prezzo'     => $prezzo,
                        ':sconto'     => $sconto,
                        ':valido_dal' => $validoDal,
                        ':valido_al'  => $validoAl,
                        ':note'       => $note,
                        ':umid'        => $uomId ?: null,
                        ':qmin_intento' => $qMin,
                        ':um_soglia_id' => $uomId ?: null,
                        ':qmin_stock'   => $qMinCanonico,
                        ':id'         => $rigaId
                    ]);
                    $touchedIds[] = $rigaId;
                } else {
                    // Insert
                    $this->pdo->prepare("
                        INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, unita_misura_id, prezzo, sconto_perc, valido_dal, valido_al, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, importato_il)
                        VALUES (:pfid, :lid, :qmin, :umid, :prezzo, :sconto, :valido_dal, :valido_al, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)
                    ")->execute([
                        ':umid'        => $uomId ?: null,
                        ':qmin_intento' => $qMin,
                        ':um_soglia_id' => $uomId ?: null,
                        ':qmin_stock'   => $qMinCanonico,
                        ':pfid'       => $pfId,
                        ':lid'        => $listinoId,
                        ':qmin'        => $qMin,
                        ':prezzo'      => $prezzo,
                        ':sconto'      => $sconto,
                        ':valido_dal'  => $validoDal,
                        ':valido_al'   => $validoAl,
                        ':note'        => $note
                    ]);
                    $touchedIds[] = (int)$this->pdo->lastInsertId();
                }
                $inserted++;
            }

            // REVISIONE §6.12 — righe che in passato provenivano da un import per QUESTO listino
            // ma non sono più presenti nel file appena caricato: il fornitore le ha tolte dal suo
            // listino. Non le cancelliamo (perderemmo lo storico prezzi); le chiudiamo impostando
            // valido_al = oggi, stesso principio di storicizzazione già usato altrove nel progetto.
            // Le righe modificate manualmente (mai toccate da importato_il, o già chiuse) non rientrano.
            $expired = 0;
            $stmtOrphanSel = $this->pdo->prepare("
                SELECT id FROM prodotti_fornitori_prezzi
                WHERE listino_acquisto_id = :lid
                  AND importato_il IS NOT NULL
                  AND (valido_al IS NULL OR valido_al >= CURRENT_DATE)
            ");
            $stmtOrphanSel->execute([':lid' => $listinoId]);
            $orphanIds = array_diff(array_map('intval', $stmtOrphanSel->fetchAll(PDO::FETCH_COLUMN)), $touchedIds);
            if (!empty($orphanIds)) {
                $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
                $stmtExpire = $this->pdo->prepare("UPDATE prodotti_fornitori_prezzi SET valido_al = CURRENT_DATE WHERE id IN ($placeholders)");
                $stmtExpire->execute(array_values($orphanIds));
                $expired = count($orphanIds);
            }

            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'expired'  => $expired,
                'errors'   => $rowErrors
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
