<?php

declare(strict_types=1);

final class PurchasesController extends BaseController
{
    public function dashboard(): void
    {
        // 1. Raccoglie i KPI
        $rfq_bozza = (int) $this->pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'bozza'")->fetchColumn();
        $rfq_inviata = (int) $this->pdo->query("SELECT COUNT(*) FROM rfq_testata WHERE stato = 'inviata'")->fetchColumn();
        $ordini_attivi = (int) $this->pdo->query("SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')")->fetchColumn();
        $listini_attivi = (int) $this->pdo->query("SELECT COUNT(*) FROM listini_acquisto WHERE valido_dal IS NULL OR valido_dal <= CURRENT_DATE AND (valido_al IS NULL OR valido_al >= CURRENT_DATE)")->fetchColumn();

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
                LEFT JOIN clienti_fornitori cf ON cf.id = la.fornitore_id
                WHERE 1=1";

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
             LEFT JOIN clienti_fornitori cf ON cf.id = la.fornitore_id
             WHERE la.id = :id"
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

        $stmtItems = $this->pdo->prepare(
            "SELECT pfp.*, p.nome AS prodotto_nome, p.sku_interno, pf.sku_fornitore, pf.prodotto_id
             FROM prodotti_fornitori_prezzi pfp
             JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
             JOIN prodotti p ON p.id = pf.prodotto_id
             WHERE pfp.listino_acquisto_id = :lid
             ORDER BY p.nome ASC, pfp.quantita_minima ASC"
        );
        $stmtItems->execute([':lid' => $id]);
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
            $this->pdo->prepare("DELETE FROM listini_acquisto WHERE id = :id")->execute([':id' => $id]);
            redirect_with('purchases/pricelists', 'success', 'Listino acquisto eliminato con successo.');
        } catch (Throwable $e) {
            redirect_with('purchases/pricelists', 'danger', "Impossibile eliminare il listino: " . $e->getMessage());
        }
    }

    /**
     * Trova il prezzo di acquisto corretto per un prodotto, un fornitore e una quantità.
     * Restituisce un array con i dettagli del prezzo e la descrizione della sorgente.
     */
    public function getPurchasePrice(int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
    {
        if (!$dataRiferimento) {
            $dataRiferimento = date('Y-m-d');
        }

        // 1. Controlla se il fornitore ha un listino attivo alla dataRiferimento in listini_acquisto
        $stmtListini = $this->pdo->prepare("
            SELECT id, nome_listino
            FROM listini_acquisto
            WHERE fornitore_id = :fid
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
        $stmtPf = $this->pdo->prepare("
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

        // Prepariamo la query per i prezzi
        $sqlPrezzi = "
            SELECT prezzo, sconto_perc, note, quantita_minima, listino_acquisto_id
            FROM prodotti_fornitori_prezzi
            WHERE prodotto_fornitore_id = :pfid
              AND (valido_dal IS NULL OR valido_dal <= :data_rif)
              AND (valido_al IS NULL OR valido_al >= :data_rif)
              AND quantita_minima <= :qty
        ";

        $params = [
            ':pfid'     => $prodottoFornitoreId,
            ':data_rif' => $dataRiferimento,
            ':qty'      => $quantita
        ];

        if ($listinoId !== null) {
            // Cerchiamo prima all'interno del listino attivo
            $sqlListino = $sqlPrezzi . " AND listino_acquisto_id = :listino_id ORDER BY quantita_minima DESC LIMIT 1";
            $listinoParams = $params;
            $listinoParams[':listino_id'] = $listinoId;

            $stmtPrezzo = $this->pdo->prepare($sqlListino);
            $stmtPrezzo->execute($listinoParams);
            $prezzoRecord = $stmtPrezzo->fetch(PDO::FETCH_ASSOC);

            if ($prezzoRecord) {
                $qMin = (float)$prezzoRecord['quantita_minima'];
                $sorgente = "Listino: " . $listinoNome;
                if ($qMin > 0) {
                    $sorgente .= " (Scaglione >= " . number_format($qMin, 0, ',', '.') . ")";
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
        $sqlBase = $sqlPrezzi . " AND listino_acquisto_id IS NULL ORDER BY quantita_minima DESC LIMIT 1";
        $stmtBase = $this->pdo->prepare($sqlBase);
        $stmtBase->execute($params);
        $prezzoRecord = $stmtBase->fetch(PDO::FETCH_ASSOC);

        if ($prezzoRecord) {
            $qMin = (float)$prezzoRecord['quantita_minima'];
            $sorgente = "Prezzo base";
            if ($qMin > 0) {
                $sorgente .= " (Scaglione >= " . number_format($qMin, 0, ',', '.') . ")";
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

    /**
     * Endpoint AJAX per la determinazione dinamica del prezzo.
     */
    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $prodottoId  = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $fornitoreId = filter_input(INPUT_GET, 'fornitore_id', FILTER_VALIDATE_INT);
        $quantita    = filter_input(INPUT_GET, 'quantita', FILTER_VALIDATE_FLOAT);
        $data        = get_param('data') ?: null;

        if ($quantita === false || $quantita === null) {
            $quantita = 1.0;
        }

        if (!$prodottoId || !$fornitoreId) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        $priceInfo = $this->getPurchasePrice($prodottoId, $fornitoreId, $quantita, $data);

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

            if ($rigaId) {
                // Aggiorna riga
                $this->pdo->prepare(
                    "UPDATE prodotti_fornitori_prezzi SET
                        prodotto_fornitore_id = :pfid,
                        quantita_minima       = :qmin,
                        prezzo                = :prezzo,
                        sconto_perc           = :sconto,
                        valido_dal            = :valido_dal,
                        valido_al             = :valido_al,
                        note                  = :note
                     WHERE id = :id AND listino_acquisto_id = :listino_id"
                )->execute([
                    ':pfid'       => $pfId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':valido_dal'  => $validoDal,
                    ':valido_al'   => $validoAl,
                    ':note'        => $note ?: null,
                    ':id'          => $rigaId,
                    ':listino_id'  => $listinoId,
                ]);
                $newId = $rigaId;
            } else {
                // Inserisci riga
                $this->pdo->prepare(
                    "INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, prezzo, sconto_perc, valido_dal, valido_al, note)
                     VALUES (:pfid, :listino_id, :qmin, :prezzo, :sconto, :valido_dal, :valido_al, :note)"
                )->execute([
                    ':pfid'       => $pfId,
                    ':listino_id' => $listinoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':valido_dal'  => $validoDal,
                    ':valido_al'   => $validoAl,
                    ':note'        => $note ?: null,
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

        $inserted  = 0;
        $skipped   = 0;
        $rowErrors = [];

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
                $stmtProd = $this->pdo->prepare('SELECT id FROM prodotti WHERE sku_interno = :s AND attivo = 1');
                $stmtProd->execute([':s' => $sku]);
                $prodRow = $stmtProd->fetch(PDO::FETCH_ASSOC);
                if (!$prodRow) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': prodotto con SKU "' . htmlspecialchars($sku) . '" non trovato o non attivo — saltata.';
                    continue;
                }
                $prodottoId = (int)$prodRow['id'];

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

                // Cerca se esiste già un prezzo per questo listino, prodotto_fornitore e quantita_minima
                $stmtCheck = $this->pdo->prepare("
                    SELECT id FROM prodotti_fornitori_prezzi 
                    WHERE prodotto_fornitore_id = :pfid 
                      AND listino_acquisto_id = :lid 
                      AND quantita_minima = :qmin
                ");
                $stmtCheck->execute([
                    ':pfid' => $pfId,
                    ':lid'  => $listinoId,
                    ':qmin' => $qMin
                ]);
                $priceRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($priceRow) {
                    // Update
                    $this->pdo->prepare("
                        UPDATE prodotti_fornitori_prezzi SET
                            prezzo = :prezzo,
                            sconto_perc = :sconto,
                            valido_dal = :valido_dal,
                            valido_al = :valido_al,
                            note = :note
                        WHERE id = :id
                    ")->execute([
                        ':prezzo'     => $prezzo,
                        ':sconto'     => $sconto,
                        ':valido_dal' => $validoDal,
                        ':valido_al'  => $validoAl,
                        ':note'       => $note,
                        ':id'         => (int)$priceRow['id']
                    ]);
                } else {
                    // Insert
                    $this->pdo->prepare("
                        INSERT INTO prodotti_fornitori_prezzi (prodotto_fornitore_id, listino_acquisto_id, quantita_minima, prezzo, sconto_perc, valido_dal, valido_al, note)
                        VALUES (:pfid, :lid, :qmin, :prezzo, :sconto, :valido_dal, :valido_al, :note)
                    ")->execute([
                        ':pfid'       => $pfId,
                        ':lid'        => $listinoId,
                        ':qmin'        => $qMin,
                        ':prezzo'      => $prezzo,
                        ':sconto'      => $sconto,
                        ':valido_dal'  => $validoDal,
                        ':valido_al'   => $validoAl,
                        ':note'        => $note
                    ]);
                }
                $inserted++;
            }
            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
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
