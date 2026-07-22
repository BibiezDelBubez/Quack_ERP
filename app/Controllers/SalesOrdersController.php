<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/sales_orders.php';
require_once INCLUDES_DIR . '/tabelle_base.php';
require_once INCLUDES_DIR . '/sales_quotes.php';

final class SalesOrdersController extends BaseController
{
    /** Restituisce l'ID del listino marcato is_default=1, oppure null se non esiste. */
    private function getDefaultListinoId(): ?int
    {
        $row = $this->pdo->query("SELECT id FROM listini WHERE is_default = 1 AND attivo = 1 LIMIT 1")->fetch();
        return $row ? (int)$row['id'] : null;
    }

    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');
        $customerId = get_param('customer_id', '');

        $customerName = '';
        if ($customerId) {
            $cStmt = $this->pdo->prepare("SELECT COALESCE(ragione_sociale, TRIM(nome || ' ' || cognome)) FROM clienti_fornitori WHERE id = ?");
            $cStmt->execute([$customerId]);
            $customerName = $cStmt->fetchColumn() ?: '';
        }

        $sortWhitelist = ['numero', 'data', 'stato', 'cliente', 'consegna', 'totale'];
        $pag = qk_pagination_params($sortWhitelist, 'data', 'desc');

        $sortMap = [
            'numero'   => 'o.numero_ordine',
            'data'     => 'o.data_ordine',
            'stato'    => 'o.stato',
            'cliente'  => 'cf.ragione_sociale',
            'consegna' => 'o.data_consegna_prevista',
            'totale'   => '(SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario),0) FROM ordini_clienti_righe r WHERE r.ordine_id = o.id)',
        ];
        $orderCol = $sortMap[$pag['sort']] ?? 'o.data_ordine';

        $where = ['1=1'];
        $params = [];
        if ($search) {
            $where[] = "(o.numero_ordine LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($stato)  { $where[] = "o.stato = :stato"; $params[':stato'] = $stato; }
        if ($dal)    { $where[] = "o.data_ordine >= :dal"; $params[':dal'] = $dal; }
        if ($al)     { $where[] = "o.data_ordine <= :al";  $params[':al']  = $al; }
        if ($customerId) { $where[] = "o.cliente_id = :customer_id"; $params[':customer_id'] = $customerId; }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ordini_clienti o
               JOIN clienti_fornitori cf ON cf.id = o.cliente_id
              WHERE $whereStr"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $this->pdo->prepare(
            "SELECT o.id, o.numero_ordine, o.data_ordine, o.data_consegna_prevista, o.stato,
                    (SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario),0) FROM ordini_clienti_righe r WHERE r.ordine_id = o.id) AS totale,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
               FROM ordini_clienti o
               JOIN clienti_fornitori cf ON cf.id = o.cliente_id
              WHERE $whereStr
              ORDER BY $orderCol {$pag['dir']}
              LIMIT {$pag['limit']} OFFSET {$pag['offset']}"
        );
        $listStmt->execute($params);
        $orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $active_tab    = 'orders';
        $base          = url('sales/orders');
        $page_title    = 'Ordini Clienti';
        $page_subtitle = 'Gestione ordini di vendita attivi';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Ordini']];
        $page_actions  = '<a href="' . url('sales/orders/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo ordine
        </a>';

        $this->page(APP_ROOT_DIR . '/app/views/sales/orders/_list.php', get_defined_vars());
    }

    public function create(): void
    {
        $errors = [];
        $values = $this->defaultOrderValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica e riprova.';
            } else {
                $values = $this->readPostOrderValues();
                $righe  = $this->readPostOrderRighe();
                $errors = qk_ordine_diretto_validate($values, $righe);

                if (empty($errors)) {
                    try {
                        $userId   = (int)(current_user()['id'] ?? 0);
                        $ordineId = qk_insert_ordine_diretto($this->pdo, $values, $righe, $userId);
                        flash_set('success', 'Ordine ' . e($values['numero_ordine']) . ' creato con successo!');
                        redirect(url('sales/orders/edit') . '?id=' . $ordineId);
                    } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore durante la creazione: ' . $e->getMessage();
                    }
                }
            }
        }

        $numero_ordine = $values['numero_ordine'] ?: qk_next_numero_ordine_diretto($this->pdo);
        $stAz    = $this->pdo->query(
            "SELECT ai.id, COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS ragione_sociale
               FROM aziende_interne ai
               JOIN clienti_fornitori cf ON cf.id = ai.soggetto_id
              ORDER BY 2"
        );
        $aziende = $stAz ? $stAz->fetchAll(PDO::FETCH_ASSOC) : [];
        $listini = qk_get_listini_vendita_attivi($this->pdo);

        $active_tab    = 'orders';
        $page_title    = 'Nuovo Ordine Cliente';
        $page_subtitle = 'Inserimento ordine diretto';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Ordini',  'url' => url('sales/orders')],
            ['label' => 'Nuovo'],
        ];

        $extra_css     = ['sales-common.css'];
        $this->page(APP_ROOT_DIR . '/app/views/sales/orders/_create.php', get_defined_vars());
    }

    private function defaultOrderValues(): array
    {
        return [
            'cliente_id'              => '',
            'cliente_label'           => '',
            'azienda_interna_id'      => '',
            'numero_ordine'           => '',
            'data_ordine'             => date('Y-m-d'),
            'data_consegna_prevista'  => '',
            'riferimento_interno'     => '',
            'condizioni_pagamento'    => '',
            'trasporto'               => 'Franco destino',
            'valuta'                  => 'EUR',
            'listino_id'              => $this->getDefaultListinoId() ?? '', // listino marcato is_default=1
            'note'                    => '',
            'indirizzo_spedizione_id' => '',
            'consegna_via'            => '',
            'consegna_civico'         => '',
            'consegna_cap'            => '',
            'consegna_citta'          => '',
            'consegna_provincia'      => '',
            'consegna_nazione'        => 'IT',
            'consegna_presso'         => '',
            'consegna_referente'      => '',
            'consegna_telefono'       => '',
            'consegna_note'           => '',
            'contatto_id'             => '',
            'contatto_nome'           => '',
            'contatto_email'          => '',
            'contatto_telefono'       => '',
            'uom_mode'                => 'commercial',
            'sconto_globale_perc'     => '0',
        ];
    }

    private function readPostOrderValues(): array
    {
        $s = fn(string $k, string $d = '') => trim((string)($_POST[$k] ?? $d));
        return [
            'cliente_id'              => $s('cliente_id'),
            'cliente_label'           => $s('cliente_label'),
            'azienda_interna_id'      => $s('azienda_interna_id'),
            'numero_ordine'           => $s('numero_ordine'),
            'data_ordine'             => $s('data_ordine'),
            'data_consegna_prevista'  => $s('data_consegna_prevista'),
            'riferimento_interno'     => $s('riferimento_interno'),
            'condizioni_pagamento'    => $s('condizioni_pagamento'),
            'trasporto'               => $s('trasporto'),
            'valuta'                  => $s('valuta', 'EUR'),
            'listino_id'              => $s('listino_id', (string)($this->getDefaultListinoId() ?? '')),
            'note'                    => $s('note'),
            'indirizzo_spedizione_id' => $s('indirizzo_spedizione_id'),
            'consegna_via'            => $s('consegna_via'),
            'consegna_civico'         => $s('consegna_civico'),
            'consegna_cap'            => $s('consegna_cap'),
            'consegna_citta'          => $s('consegna_citta'),
            'consegna_provincia'      => $s('consegna_provincia'),
            'consegna_nazione'        => $s('consegna_nazione', 'IT'),
            'consegna_presso'         => $s('consegna_presso'),
            'consegna_referente'      => $s('consegna_referente'),
            'consegna_telefono'       => $s('consegna_telefono'),
            'consegna_note'           => $s('consegna_note'),
            'contatto_id'             => $s('contatto_id'),
            'contatto_nome'           => $s('contatto_nome'),
            'contatto_email'          => $s('contatto_email'),
            'contatto_telefono'       => $s('contatto_telefono'),
            'uom_mode'                => $s('uom_mode', 'commercial'),
            'sconto_globale_perc'     => $s('sconto_globale_perc', '0'),
        ];
    }

    private function readPostOrderRighe(): array
    {
        $raw = $_POST['righe'] ?? [];
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $s = fn($k, $d = '') => trim((string)($row[$k] ?? $d));
            $out[] = [
                'prodotto_id'         => $s('prodotto_id'),
                'descrizione'         => $s('descrizione'),
                'quantita'            => $s('quantita', '0'),
                'unita_misura'        => $s('unita_misura'),
                'uom_stock'           => $s('uom_stock'),
                'uom_stock_id'        => $s('uom_stock_id'),
                'uom_commercial'      => $s('uom_commercial'),
                'uom_commercial_id'   => $s('uom_commercial_id'),
                'quantita_contenuto'  => $s('quantita_contenuto', '1'),
                'prezzo_unitario'     => $s('prezzo_unitario', '0'),
                'sconto_perc'         => $s('sconto_perc', '0'),
                'iva_perc'            => $s('iva_perc', '22'),
                'costo_unitario'      => $s('costo_unitario', '0'),
                'quantita_confezione' => $s('quantita_confezione'),
                'sku'                 => $s('sku'),
                'note_riga'           => $s('note_riga'),
            ];
        }
        return $out;
    }

    public function apiClienti(): void
    {
        header('Content-Type: application/json');
        echo json_encode(qk_search_clienti($this->pdo, get_param('q', '')));
        exit;
    }

    public function apiProdotti(): void
    {
        header('Content-Type: application/json');
        echo json_encode(qk_search_prodotti_orders($this->pdo, get_param('q', '')));
        exit;
    }

    public function apiClienteDettagli(): void
    {
        header('Content-Type: application/json');
        $clienteId = (int)get_param('cliente_id');
        if ($clienteId <= 0) { echo json_encode(['ok' => false]); exit; }

        $stCli = $this->pdo->prepare("
            SELECT cf.*, dc.listino_id
            FROM clienti_fornitori cf
            LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
            WHERE cf.id = ? LIMIT 1
        ");
        $stCli->execute([$clienteId]);
        $cliente = $stCli->fetch(PDO::FETCH_ASSOC);
        if (!$cliente) { echo json_encode(['ok' => false]); exit; }

        $stAddr = $this->pdo->prepare("
            SELECT id, tipo, predefinito, nome_sede, via, civico, cap, citta, provincia, nazione,
                   referente_consegna, telefono_consegna, note_consegna, presso
              FROM indirizzi
             WHERE soggetto_id = ?
               AND tipo IN ('spedizione','magazzino','sede_operativa')
               AND attivo = 1
             ORDER BY predefinito DESC, nome_sede ASC
        ");
        $stAddr->execute([$clienteId]);
        $indirizzi = $stAddr->fetchAll(PDO::FETCH_ASSOC);

        $stCont = $this->pdo->prepare("
            SELECT id,
                   COALESCE(nome || ' ' || COALESCE(cognome, ''), ragione_sociale) AS nome_completo,
                   email, telefono, cellulare
              FROM clienti_fornitori
             WHERE parent_id = ?
               AND attivo = 1
             ORDER BY nome, cognome
        ");
        $stCont->execute([$clienteId]);
        $contatti = $stCont->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'        => true,
            'cliente'   => [
                'condizioni_pagamento' => $cliente['condizioni_pagamento'] ?? '',
                'listino_id'           => $cliente['listino_id'] !== null ? (int)$cliente['listino_id'] : $this->getDefaultListinoId()
            ],
            'indirizzi' => $indirizzi,
            'contatti'  => $contatti,
        ]);
        exit;
    }

    public function apiCreaCliente(): void
    {
        header('Content-Type: application/json');
        if (!csrf_verify()) { echo json_encode(['success' => false, 'error' => 'CSRF']); exit; }

        $tipo     = post('tipo_soggetto', 'azienda');
        $ragione  = trim(post('ragione_sociale', ''));
        $nome     = trim(post('nome', ''));
        $cognome  = trim(post('cognome', ''));
        $email    = trim(post('email', ''));
        $telefono = trim(post('telefono', ''));

        if ($tipo === 'azienda' && $ragione === '') {
            echo json_encode(['success' => false, 'error' => 'Ragione sociale obbligatoria']); exit;
        }
        if ($tipo !== 'azienda' && $nome === '') {
            echo json_encode(['success' => false, 'error' => 'Nome obbligatorio']); exit;
        }

        try {
            $st = $this->pdo->prepare("
                INSERT INTO clienti_fornitori
                    (tipo_soggetto, ragione_sociale, nome, cognome, email, telefono, tipo, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'cliente', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $st->execute([$tipo, $ragione ?: null, $nome ?: null, $cognome ?: null, $email ?: null, $telefono ?: null]);
            $id    = (int)$this->pdo->lastInsertId();
            $label = $tipo === 'azienda' ? $ragione : trim($nome . ' ' . $cognome);
            echo json_encode(['success' => true, 'id' => $id, 'label' => $label, 'email' => $email, 'telefono' => $telefono]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiCreaProdotto(): void
    {
        header('Content-Type: application/json');
        if (!csrf_verify()) { echo json_encode(['success' => false, 'error' => 'CSRF']); exit; }

        $nome  = trim(post('nome', ''));
        $sku   = trim(post('sku_interno', ''));
        $umCod = trim(post('unita_misura', 'pz'));

        if ($nome === '') { echo json_encode(['success' => false, 'error' => 'Nome prodotto obbligatorio']); exit; }

        // Riusa la funzione condivisa qk_crea_prodotto_rapido() (includes/prodotti.php) invece di un
        // INSERT a mano: quello precedente ometteva la colonna NOT NULL "tipo" (falliva sempre) e passava
        // null su "sku_interno" (anch'essa NOT NULL UNIQUE) quando l'utente lasciava lo SKU vuoto.
        // Stesso bug di RfqController::apiCreaProdotto(), vedi Revisione Ciclo Acquisti §3bis B.1.
        try {
            $id = qk_crea_prodotto_rapido($this->pdo, [
                'nome' => $nome,
                'sku_interno' => $sku,
                'unita_misura' => $umCod,
            ]);

            $stInfo = $this->pdo->prepare(
                "SELECT p.sku_interno, um.codice AS unita_misura
                 FROM prodotti p
                 LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
                 WHERE p.id = ?"
            );
            $stInfo->execute([$id]);
            $info = $stInfo->fetch();

            echo json_encode([
                'success' => true,
                'id' => $id,
                'label' => $nome,
                'sku' => $info['sku_interno'] ?? $sku,
                'unita_misura' => $info['unita_misura'] ?? $umCod,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function edit(): void
    {
        $id = (int)get_param('id');
        if ($id <= 0) { redirect(url('sales/orders')); }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                flash_set('error', 'Sessione scaduta. Ricarica la pagina e riprova.');
            } else {
                $note                   = post('note', '');
                $data_consegna_prevista = post('data_consegna_prevista', '');
                try {
                    // Tappa 7 (PIANO - Motore Unificato di Transizioni di Stato):
                    // il controllo "non è già completato/annullato" viveva qui nel
                    // controller (violazione MVC) e veniva verificato PRIMA del CSRF;
                    // ora vive nel model (qk_ordine_cliente_aggiorna_note ->
                    // qk_ordine_cliente_assicura_modificabile) e viene raggiunto solo
                    // dopo il controllo CSRF, coerente con action() qui sotto e con
                    // tutti gli altri controller già migrati.
                    qk_ordine_cliente_aggiorna_note(
                        $this->pdo,
                        $id,
                        $note !== '' ? $note : null,
                        $data_consegna_prevista !== '' ? $data_consegna_prevista : null
                    );
                    flash_set('success', 'Ordine aggiornato correttamente.');
                } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                    flash_set('error', $e->getMessage());
                }
            }
            redirect(url('sales/orders/edit') . '?id=' . $id);
        }

        $st = $this->pdo->prepare("
            SELECT o.*,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                   cf.telefono AS cliente_telefono, cf.email AS cliente_email,
                   p.numero_preventivo AS preventivo_numero,
                   u.username AS creato_da_username,
                   lst.nome AS listino_nome
            FROM ordini_clienti o
            JOIN clienti_fornitori cf ON cf.id = o.cliente_id
            LEFT JOIN preventivi p ON p.id = o.preventivo_id
            LEFT JOIN utenti u ON u.id = o.creato_da
            LEFT JOIN listini lst ON lst.id = o.listino_id
            WHERE o.id = ?
        ");
        $st->execute([$id]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order) { redirect_with('sales/orders', 'error', 'Ordine non trovato.'); }

        $address = null;
        if (!empty($order['indirizzo_spedizione_id'])) {
            $stAddr = $this->pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
            $stAddr->execute([$order['indirizzo_spedizione_id']]);
            $address = $stAddr->fetch(PDO::FETCH_ASSOC);
        }

        $stLines = $this->pdo->prepare("
            SELECT r.*,
                   p.sku_interno AS prodotto_codice, p.nome AS prodotto_nome,
                   p.traccia_lotti, um.codice AS uom_simbolo,
                   (r.prezzo_unitario - COALESCE(r.costo_unitario, 0)) AS margine_unit,
                   CASE WHEN r.prezzo_unitario > 0
                        THEN ((r.prezzo_unitario - COALESCE(r.costo_unitario, 0)) / r.prezzo_unitario) * 100
                        ELSE 0 END AS margine_perc,
                   (r.quantita_ordinata * r.prezzo_unitario) AS imponibile_riga
            FROM ordini_clienti_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.ordine_id = ?
        ");
        $stLines->execute([$id]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

        $stShip = $this->pdo->prepare("
            SELECT s.*, (SELECT COUNT(*) FROM spedizioni_righe WHERE spedizione_id = s.id) AS righe_count
            FROM spedizioni s WHERE s.ordine_id = ? ORDER BY s.created_at DESC
        ");
        $stShip->execute([$id]);
        $shipments = $stShip->fetchAll(PDO::FETCH_ASSOC);

        $stPhotos = $this->pdo->prepare("
            SELECT sf.* FROM spedizioni_foto sf
            JOIN spedizioni s ON s.id = sf.spedizione_id
            WHERE s.ordine_id = ?
        ");
        $stPhotos->execute([$id]);
        $photosList = $stPhotos->fetchAll(PDO::FETCH_ASSOC);
        $shipment_photos = [];
        foreach ($photosList as $ph) {
            $shipment_photos[(int)$ph['spedizione_id']][] = $ph;
        }

        $stDdt = $this->pdo->prepare("SELECT d.* FROM documenti_trasporto d WHERE d.ordine_id = ? ORDER BY d.created_at DESC");
        $stDdt->execute([$id]);
        $ddts = $stDdt->fetchAll(PDO::FETCH_ASSOC);

        $totImponibile = 0.0;
        $totCosto      = 0.0;
        foreach ($lines as $l) {
            $totImponibile += (float)($l['imponibile_riga'] ?? 0);
            $totCosto      += (float)($l['costo_unitario'] ?? 0) * (float)($l['quantita_ordinata'] ?? 0);
        }
        $margineTot     = $totImponibile - $totCosto;
        $margineTotPerc = $totImponibile > 0 ? ($margineTot / $totImponibile) * 100 : 0.0;

        $active_tab  = 'orders';
        $page_title  = 'Ordine ' . $order['numero_ordine'];
        $breadcrumbs = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Ordini',  'url' => url('sales/orders')],
            ['label' => 'Dettaglio Ordine'],
        ];
        $this->page(APP_ROOT_DIR . '/app/views/sales/orders/_detail.php', get_defined_vars());
    }

    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) { redirect(url('sales/orders')); }

        $id     = (int)post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'completa':
                    // Tappa 7 (PIANO - Motore Unificato di Transizioni di Stato):
                    // prima un UPDATE grezzo qui, senza nessun controllo di stato.
                    qk_ordine_cliente_completa($this->pdo, $id);
                    flash_set('success', 'Ordine completato.');
                    break;
                case 'riapri':
                    // Tappa 7: stesso motivo del caso 'completa' sopra.
                    qk_ordine_cliente_riapri($this->pdo, $id);
                    flash_set('success', 'Ordine rimesso in lavorazione.');
                    break;
                case 'annulla':
                    // Tappa 7: stesso motivo del caso 'completa' sopra.
                    qk_ordine_cliente_annulla($this->pdo, $id);
                    flash_set('success', 'Ordine annullato.');
                    break;
                case 'segna_fatturato':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'fatturato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    flash_set('success', 'Ordine segnato come fatturato.');
                    break;
                case 'segna_da_fatturare':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'da_fatturare', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    flash_set('success', 'Ordine ripristinato a "Da Fatturare".');
                    break;
                case 'crea_spedizione':
                    $this->elaboraCreaSpedizione($id);
                    break;
                default:
                    flash_set('error', "Azione '$azione' non riconosciuta.");
            }
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('error', $e->getMessage());
        }

        redirect(url('sales/orders/edit') . '?id=' . $id);
    }

    public function apiLottiDisponibili(): void
    {
        header('Content-Type: application/json');
        $prodottoId = (int)get_param('prodotto_id');
        if ($prodottoId <= 0) { echo json_encode(['error' => 'prodotto_id mancante']); exit; }

        // Disponibilità per magazzino (Piano Multi-Magazzino §3.8): legge da
        // lotti_giacenze scoped al magazzino di spedizione scelto sul form
        // (Revisione Gestione Magazzino e Lotti §3) — con fallback sul magazzino
        // attivo di sessione solo se il chiamante non lo passa esplicitamente.
        $magazzinoId = (int)get_param('magazzino_id', '0');
        if ($magazzinoId <= 0) {
            $magazzinoId = qk_magazzino_attivo_id($this->pdo);
        }

        // Lotti scaduti (Tappa 4.4): esclusi per default — inclusi solo se il
        // chiamante passa esplicitamente includi_scaduti=1 (toggle lato UI).
        $includiScaduti = get_param('includi_scaduti', '0') === '1';
        $condScadenza = $includiScaduti ? '' : "AND (l.data_scadenza IS NULL OR l.data_scadenza >= DATE('now'))";

        $st = $this->pdo->prepare("
            SELECT l.id, l.codice_lotto_interno AS codice,
                   lg.quantita_disponibile, l.data_scadenza, l.data_arrivo, l.created_at,
                   CASE WHEN l.data_scadenza IS NOT NULL AND l.data_scadenza < DATE('now') THEN 1 ELSE 0 END AS scaduto
            FROM lotti l
            JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
            WHERE l.prodotto_id = ? AND lg.quantita_disponibile > 0
              $condScadenza
            ORDER BY CASE WHEN l.data_scadenza IS NULL THEN 1 ELSE 0 END, l.data_scadenza ASC, l.created_at ASC
        ");
        $st->execute([$magazzinoId, $prodottoId]);
        echo json_encode(['lotti' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    private function elaboraCreaSpedizione(int $orderId): void
    {
        $vettore           = post('vettore', '');
        $tracking          = post('tracking', '');
        $causale_trasporto = post('causale_trasporto', 'Vendita');
        $porto             = post('porto', 'franco');
        $aspetto_beni      = post('aspetto_beni', '');
        $numero_colli      = (int)post('numero_colli', '0');
        $peso_lordo_kg     = (float)post('peso_lordo_kg', '0.0');
        $lottiPost           = $_POST['lotti'] ?? [];
        $quantitaNoLottoPost = $_POST['quantita_no_lotto'] ?? [];

        // Magazzino di spedizione: campo esplicito sul form "Nuova Spedizione"
        // (Revisione Gestione Magazzino e Lotti §3), non più risolto silenziosamente
        // dal magazzino attivo di sessione. Viene scritto su spedizioni.magazzino_id e
        // usato per congelare il mittente sul DDT (vedi qk_calcola_mittente_ddt sotto).
        $magazzinoSpedId = (int)post('magazzino_id', '0');

        $this->pdo->beginTransaction();
        try {
            if ($magazzinoSpedId <= 0) {
                throw new \Exception("Seleziona il magazzino di spedizione.");
            }

            $st = $this->pdo->prepare("SELECT * FROM ordini_clienti WHERE id = ?");
            $st->execute([$orderId]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new \Exception("Ordine non trovato.");

            // Tappa 7 (PIANO - Motore Unificato di Transizioni di Stato): PRIMA
            // questo metodo non controllava affatto lo stato dell'ordine — si
            // poteva creare una spedizione (e quindi "far ripartire" lo stato
            // dell'ordine, vedi ricalcolo a fine metodo) anche su un ordine già
            // 'completato' o 'annullato'. Coerente con la stessa condizione già
            // mostrata (ma mai imposta lato server) dal bottone "Nuova Spedizione"
            // in app/views/sales/orders/_detail.php:343.
            qk_ordine_cliente_assicura_modificabile(
                (string) $order['stato'],
                "Impossibile creare la spedizione: l'ordine è già in stato '{$order['stato']}'."
            );

            // Risoluzione indirizzo di spedizione con la stessa logica a cascata
            // usata nelle fasi precedenti del ciclo vendite (preventivo/ordine):
            // 1) indirizzo esplicitamente selezionato sull'ordine
            // 2) master address dietro allo snapshot strutturato (indirizzi_documenti)
            // 3) indirizzo di spedizione predefinito del cliente
            // Se nessuno è risolvibile, si blocca con un errore chiaro invece di
            // forzare NULL su una colonna NOT NULL (che prima causava il crash SQL).
            $indirizzoSpedizioneId = !empty($order['indirizzo_spedizione_id']) ? (int)$order['indirizzo_spedizione_id'] : null;

            if (!$indirizzoSpedizioneId && !empty($order['indirizzo_consegna_id'])) {
                $stSnap = $this->pdo->prepare("SELECT indirizzo_master_id FROM indirizzi_documenti WHERE id = ?");
                $stSnap->execute([$order['indirizzo_consegna_id']]);
                $masterId = $stSnap->fetchColumn();
                if ($masterId) { $indirizzoSpedizioneId = (int)$masterId; }
            }

            if (!$indirizzoSpedizioneId) {
                $stDef = $this->pdo->prepare("
                    SELECT id FROM indirizzi
                    WHERE soggetto_id = ? AND attivo = 1
                    ORDER BY (tipo = 'spedizione' AND predefinito = 1) DESC,
                             predefinito DESC,
                             id ASC
                    LIMIT 1
                ");
                $stDef->execute([$order['cliente_id']]);
                $fallbackId = $stDef->fetchColumn();
                if ($fallbackId) { $indirizzoSpedizioneId = (int)$fallbackId; }
            }

            if (!$indirizzoSpedizioneId) {
                throw new \Exception("Impossibile creare la spedizione: il cliente non ha nessun indirizzo di consegna valido. Seleziona un indirizzo sull'ordine prima di procedere.");
            }

            $stLines = $this->pdo->prepare("
                SELECT r.*, p.traccia_lotti, p.nome AS prodotto_nome, p.sku_interno, p.tipo
                FROM ordini_clienti_righe r
                JOIN prodotti p ON p.id = r.prodotto_id
                WHERE r.ordine_id = ?
            ");
            $stLines->execute([$orderId]);
            $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

            $count   = (int)$this->pdo->query("SELECT COUNT(*) FROM spedizioni")->fetchColumn();
            $numSped = 'SPED/' . date('Y') . '/' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
            $userId  = (int)(current_user()['id'] ?? 0);

            $this->pdo->prepare("
                INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, indirizzo_spedizione_id, magazzino_id, vettore, tracking, stato, creato_da, created_at)
                VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, 'spedita', ?, CURRENT_TIMESTAMP)
            ")->execute([$numSped, $orderId, $indirizzoSpedizioneId, $magazzinoSpedId, $vettore ?: null, $tracking ?: null, $userId]);
            $spedizioneId = (int)$this->pdo->lastInsertId();

            // Gestione Upload Foto Spedizione (Pallet)
            if (isset($_FILES['pallet_foto'])) {
                $files = $_FILES['pallet_foto'];
                $totalUploaded = is_array($files['name']) ? count($files['name']) : 0;
                for ($i = 0; $i < $totalUploaded; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $files['tmp_name'][$i];
                        $name = basename($files['name'][$i]);

                        // Verifica tipo reale del file (MIME sniffato dal contenuto, non
                        // dall'estensione dichiarata dall'utente).
                        $allowedMimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
                            'image/webp' => 'webp',
                        ];
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $realMime = $finfo ? finfo_file($finfo, $tmpName) : false;
                        if ($finfo) {
                            finfo_close($finfo);
                        }
                        $ext = $allowedMimeToExt[$realMime] ?? null;
                        if ($ext !== null && getimagesize($tmpName) !== false) {
                            $subdir = upload_dated_subdir('spedizioni');
                            $upload_dir = upload_ensure_dir($subdir);
                            $new_name = uniqid('sped_photo_', true) . '.' . $ext;
                            $dest = $upload_dir . DIRECTORY_SEPARATOR . $new_name;
                            if (move_uploaded_file($tmpName, $dest)) {
                                $filePath = $subdir . '/' . $new_name;
                                $stPhoto = $this->pdo->prepare("
                                    INSERT INTO spedizioni_foto (spedizione_id, file_path, file_name, creato_da, created_at)
                                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                                ");
                                $stPhoto->execute([$spedizioneId, $filePath, $name, $userId]);
                            }
                        }
                    }
                }
            }

            $countDdt = (int)$this->pdo->query("SELECT COUNT(*) FROM documenti_trasporto")->fetchColumn();
            $numDdt   = 'DDT/' . date('Y') . '/' . str_pad((string)($countDdt + 1), 4, '0', STR_PAD_LEFT);

            // Mittente congelato alla creazione, mai ricalcolato in stampa (Revisione
            // Gestione Magazzino e Lotti §3): stesso principio di
            // qk_resolve_azienda_emittente_stampa() per preventivi/ordini_clienti.
            $mittente = qk_calcola_mittente_ddt($this->pdo, $magazzinoSpedId);

            // Destinatario congelato alla creazione, stesso principio del mittente
            // sopra (Revisione Ciclo Vendite §1.5, PIANO - Congelare Destinatario sul
            // DDT.md). Non serve ricalcolare nulla: $order (righe 610-612) contiene
            // già cliente_ragione_sociale/cliente_partita_iva/cliente_codice_fiscale e
            // consegna_via/civico/cap/citta/provincia/nazione/presso, congelati lì al
            // momento della creazione dell'ordine — li copiamo semplicemente in avanti.
            $destRagioneSociale = $order['cliente_ragione_sociale'] ?? null;
            $destPartitaIva     = $order['cliente_partita_iva'] ?? null;
            $destCodiceFiscale  = $order['cliente_codice_fiscale'] ?? null;
            $destVia            = $order['consegna_via'] ?? null;
            $destCivico         = $order['consegna_civico'] ?? null;
            $destCap            = $order['consegna_cap'] ?? null;
            $destCitta          = $order['consegna_citta'] ?? null;
            $destProvincia      = $order['consegna_provincia'] ?? null;
            $destNazione        = $order['consegna_nazione'] ?? null;
            $destNomeSede       = $order['consegna_presso'] ?? null;

            $this->pdo->prepare("
                INSERT INTO documenti_trasporto (numero_documento_trasporto, spedizione_id, ordine_id, data, destinatario_id, indirizzo_destinazione_id, causale_trasporto, porto, vettore, aspetto_beni, numero_colli, peso_lordo_kg, stato, mittente_ragione_sociale, mittente_partita_iva, mittente_codice_fiscale, mittente_indirizzo, mittente_telefono, mittente_email, destinatario_ragione_sociale, destinatario_partita_iva, destinatario_codice_fiscale, destinatario_via, destinatario_civico, destinatario_cap, destinatario_citta, destinatario_provincia, destinatario_nazione, destinatario_nome_sede, creato_da, created_at, updated_at)
                VALUES (?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, 'emesso', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([
                $numDdt, $spedizioneId, $orderId, $order['cliente_id'], $indirizzoSpedizioneId, $causale_trasporto, $porto, $vettore ?: null, $aspetto_beni ?: null, $numero_colli, $peso_lordo_kg,
                $mittente['ragione_sociale'], $mittente['partita_iva'], $mittente['codice_fiscale'], $mittente['indirizzo'], $mittente['telefono'], $mittente['email'],
                $destRagioneSociale, $destPartitaIva, $destCodiceFiscale, $destVia, $destCivico, $destCap, $destCitta, $destProvincia, $destNazione, $destNomeSede,
                $userId,
            ]);
            $ddtId = (int)$this->pdo->lastInsertId();

            $stInsSpedRiga = $this->pdo->prepare("INSERT INTO spedizioni_righe (spedizione_id, ordine_riga_id, prodotto_id, lotto_id, quantita, note) VALUES (?, ?, ?, ?, ?, '')");
            $stInsDdtRiga  = $this->pdo->prepare("INSERT INTO documenti_trasporto_righe (documento_trasporto_id, prodotto_id, descrizione, quantita, unita_misura_id, lotto_id, codice_lotto, is_adr, numero_onu, classe_adr, packing_group, posizione_riga, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
            // Decremento per-magazzino della disponibilità di lotto (fonte di verità
            // dal Piano Multi-Magazzino §3.8). L'INSERT iniziale con valore negativo
            // gestisce il caso raro in cui la riga lotti_giacenze non esista ancora
            // (es. lotto storico pre-migrazione mai passato da una ricezione scoped).
            $stUpLottoGiac = $this->pdo->prepare("
                INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
                VALUES (?, ?, -?, CURRENT_TIMESTAMP)
                ON CONFLICT(lotto_id, magazzino_id) DO UPDATE
                    SET quantita_disponibile = quantita_disponibile - ?,
                        updated_at = CURRENT_TIMESTAMP
            ");
            // quantita_riservata è "clampata" a 0 (CASE WHEN, portabile SQLite/MySQL,
            // niente GREATEST() che MySQL non ha come scalare né MAX(a,b) che SQLite
            // supporta ma MySQL no) invece di essere semplicemente decrementata: è un
            // contatore di prenotazione, non un vincolo fisico, e può risultare
            // inferiore alla quantità spedita se la prenotazione e la spedizione non
            // sono mai state garantite perfettamente allineate (spedizioni parziali,
            // riserve rilasciate altrove). Non blocchiamo mai la spedizione per
            // questo — è quantita_disponibile, verificata sotto, il vincolo fisico
            // reale — ma evitiamo di scrivere un valore negativo privo di senso.
            $stUpGiac      = $this->pdo->prepare("
                UPDATE giacenze
                   SET quantita_disponibile = quantita_disponibile - ?,
                       quantita_riservata   = CASE WHEN quantita_riservata - ? < 0 THEN 0 ELSE quantita_riservata - ? END
                 WHERE prodotto_id = ? AND magazzino_id = ?
            ");
            // Verifica di disponibilità prima dello scarico per i prodotti NON tracciati
            // a lotto (per i tracciati il controllo equivalente è già fatto sopra su
            // lotti_giacenze). Senza questo controllo lo scarico procedeva comunque,
            // portando quantita_disponibile sotto zero in caso di doppia prenotazione
            // concorrente o di ordine con quantità superiore allo stock reale.
            $stCheckGiac   = $this->pdo->prepare("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");
            $stUpOrdRiga   = $this->pdo->prepare("UPDATE ordini_clienti_righe SET quantita_spedita = quantita_spedita + ? WHERE id = ?");
            $stAdr         = $this->pdo->prepare("SELECT co.codice_onu, co.classe_pericolo, COALESCE(pca.gruppo_imballaggio_override, co.gruppo_imballaggio) AS gruppo_imballaggio FROM prodotto_classificazioni_adr pca JOIN adr_codici_onu co ON co.id = pca.codice_onu_id WHERE pca.prodotto_id = ? AND pca.e_corrente = 1 LIMIT 1");

            $pos = 0; $anyShipped = false;

            foreach ($lines as $line) {
                $lineId = $line['id']; $prodId = $line['prodotto_id'];

                // Tappa 4.3: blocco scarico su un prodotto sotto conteggio in questo
                // magazzino. Per i Kit, il blocco si applica ai singoli componenti
                // (nessuna giacenza fisica propria del Kit), verificati più sotto.
                if (($line['tipo'] ?? '') !== 'kit' && qk_inventario_bloccato($this->pdo, $magazzinoSpedId, (int)$prodId)) {
                    throw new \Exception("Impossibile spedire \"{$line['prodotto_nome']}\": è in corso un inventario fisico su questo prodotto in questo magazzino.");
                }

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
                $numSnap = isset($line['num_factor_snapshot']) ? (int)$line['num_factor_snapshot'] : 1;
                $denSnap = isset($line['den_factor_snapshot']) ? (int)$line['den_factor_snapshot'] : 1;
                // Corretto il 13/07/2026 (PIANO Motore di Pricing Multi-UoM §5.3): la riga precedente
                // faceva "(float)($numSnap / $denSnap)", una divisione float diretta che violava il
                // principio scritto poche righe sopra ("Do NOT use approximate decimal division...").
                // bcmul/bcdiv sulla frazione esatta, come già fa qk_uom_converti_riga() in includes/prodotti.php.
                $convertiInStock = function (float $q) use ($numSnap, $denSnap): float {
                    if ($denSnap <= 0) return $q;
                    return (float) bcdiv(bcmul((string)$q, (string)$numSnap, 6), (string)$denSnap, 3);
                };

                if ((int)$line['traccia_lotti'] === 1) {
                    foreach ($lottiPost[$lineId] ?? [] as $lottoId => $qtaStr) {
                        $qta = (float)$qtaStr;
                        if ($qta <= 0) continue;
                        $anyShipped = true;
                        $qtaStock   = $convertiInStock($qta);

                        // Disponibilità per QUESTO magazzino di spedizione, non l'aggregato
                        // legacy: lo stesso lotto può avere quantità diverse in sedi diverse
                        // (Piano Multi-Magazzino §3.8/§3.9).
                        $stLotInfo = $this->pdo->prepare("
                            SELECT l.id, COALESCE(lg.quantita_disponibile, 0) AS quantita_disponibile,
                                   l.codice_lotto_interno AS cl
                            FROM lotti l
                            LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
                            WHERE l.id = ?
                        ");
                        $stLotInfo->execute([$magazzinoSpedId, $lottoId]);
                        $lot = $stLotInfo->fetch(PDO::FETCH_ASSOC);
                        if (!$lot) throw new \Exception("Lotto {$lottoId} non trovato.");
                        if ($lot['quantita_disponibile'] < $qtaStock) throw new \Exception("Giacenza insufficiente lotto {$lot['cl']} nel magazzino di spedizione.");

                        $stInsSpedRiga->execute([$spedizioneId, $lineId, $prodId, $lottoId, $qta]);
                        $stAdr->execute([$prodId]);
                        $adr = $stAdr->fetch(PDO::FETCH_ASSOC);
                        $isAdr = !empty($adr) ? 1 : 0;
                        $stInsDdtRiga->execute([$ddtId, $prodId, $line['descrizione'], $qta, $line['unita_misura_id'], $lottoId, $lot['cl'], $isAdr, $isAdr ? 'UN '.$adr['codice_onu'] : null, $isAdr ? $adr['classe_pericolo'] : null, $isAdr ? $adr['gruppo_imballaggio'] : null, $pos++]);
                        $stUpLottoGiac->execute([$lottoId, $magazzinoSpedId, $qtaStock, $qtaStock]);
                        $stUpGiac->execute([$qtaStock, $qtaStock, $qtaStock, $prodId, $magazzinoSpedId]);
                        $stUpOrdRiga->execute([$qta, $lineId]);
                        $this->pdo->prepare("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at) VALUES (?, ?, 'scarico', ?, ?, ?, 'ddt', ?, CURRENT_TIMESTAMP)")->execute([$prodId, $magazzinoSpedId, $qtaStock, $lottoId, $numDdt, $userId]);
                    }
                } else {
                    $qta = (float)($quantitaNoLottoPost[$lineId] ?? 0.0);
                    if ($qta > 0) {
                        $anyShipped = true;
                        $qtaStock   = $convertiInStock($qta);
                        $stInsSpedRiga->execute([$spedizioneId, $lineId, $prodId, null, $qta]);
                        $stInsDdtRiga->execute([$ddtId, $prodId, $line['descrizione'], $qta, $line['unita_misura_id'], null, null, 0, null, null, null, $pos++]);
                        
                        if (($line['tipo'] ?? '') === 'kit') {
                            $kitSt = $this->pdo->prepare("SELECT componente_id, quantita FROM componenti_kit WHERE kit_id = ?");
                            $kitSt->execute([$prodId]);
                            $comps = $kitSt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($comps as $c) {
                                if (qk_inventario_bloccato($this->pdo, $magazzinoSpedId, (int)$c['componente_id'])) {
                                    throw new \Exception("Impossibile spedire il Kit \"{$line['prodotto_nome']}\": uno dei suoi componenti è sotto conteggio in un inventario fisico in corso in questo magazzino.");
                                }
                                $cQta = $c['quantita'] * $qtaStock;

                                $stCheckGiac->execute([$c['componente_id'], $magazzinoSpedId]);
                                $dispComp = $stCheckGiac->fetchColumn();
                                $dispComp = $dispComp !== false ? (float)$dispComp : 0.0;
                                if ($dispComp < $cQta) {
                                    throw new \Exception(sprintf(
                                        'Giacenza insufficiente nel magazzino di spedizione per un componente del Kit "%s" (disponibile %.3f, richiesto %.3f).',
                                        $line['prodotto_nome'],
                                        $dispComp,
                                        $cQta
                                    ));
                                }

                                $stUpGiac->execute([$cQta, $cQta, $cQta, $c['componente_id'], $magazzinoSpedId]);
                                $this->pdo->prepare("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at) VALUES (?, ?, 'scarico', ?, null, ?, 'ddt', ?, CURRENT_TIMESTAMP)")->execute([$c['componente_id'], $magazzinoSpedId, $cQta, $numDdt, $userId]);
                            }
                        } else {
                            $stCheckGiac->execute([$prodId, $magazzinoSpedId]);
                            $dispProd = $stCheckGiac->fetchColumn();
                            $dispProd = $dispProd !== false ? (float)$dispProd : 0.0;
                            if ($dispProd < $qtaStock) {
                                throw new \Exception(sprintf(
                                    'Giacenza insufficiente nel magazzino di spedizione per "%s" (disponibile %.3f, richiesto %.3f).',
                                    $line['prodotto_nome'],
                                    $dispProd,
                                    $qtaStock
                                ));
                            }

                            $stUpGiac->execute([$qtaStock, $qtaStock, $qtaStock, $prodId, $magazzinoSpedId]);
                            $this->pdo->prepare("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at) VALUES (?, ?, 'scarico', ?, null, ?, 'ddt', ?, CURRENT_TIMESTAMP)")->execute([$prodId, $magazzinoSpedId, $qtaStock, $numDdt, $userId]);
                        }
                        
                        $stUpOrdRiga->execute([$qta, $lineId]);
                    }
                }
            }

            if (!$anyShipped) throw new \Exception("Nessuna quantita valida per la spedizione.");

            $stAll = $this->pdo->prepare("SELECT quantita_ordinata, quantita_spedita FROM ordini_clienti_righe WHERE ordine_id = ?");
            $stAll->execute([$orderId]);
            $allLines = $stAll->fetchAll(PDO::FETCH_ASSOC);
            $allDone = true; $anyDone = false;
            foreach ($allLines as $al) {
                if ((float)$al['quantita_spedita'] < (float)$al['quantita_ordinata']) $allDone = false;
                if ((float)$al['quantita_spedita'] > 0) $anyDone = true;
            }
            $nuovoStato = $allDone ? 'spedito' : ($anyDone ? 'parzialmente_spedito' : 'in_lavorazione');
            $this->pdo->prepare("UPDATE ordini_clienti SET stato = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$nuovoStato, $orderId]);

            $this->pdo->commit();
            flash_set('success', "Spedizione {$numSped} e DDT {$numDdt} registrati correttamente.");
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
