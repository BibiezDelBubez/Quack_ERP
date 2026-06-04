<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/sales_quotes.php';

final class SalesQuotesController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $status = get_param('status');

        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.numero_preventivo, o.stato, o.data_offerta, o.data_scadenza,
                    o.imponibile, o.totale, o.note_cliente,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                    cv.nome AS canale_vendita
             FROM preventivi o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             LEFT JOIN canali_vendita cv ON cv.id = o.canale_vendita_id
             WHERE 1=1
               " . ($search ? "AND (o.numero_preventivo LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q)" : '') . "
               " . ($status ? "AND o.stato = :stato" : '') . "
             ORDER BY o.created_at DESC"
        );
        $params = [];
        if ($search) $params[':q']     = '%' . $search . '%';
        if ($status) $params[':stato'] = $status;
        $stmt->execute($params);

        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total  = count($offers);

        $active_tab    = 'quotes';
        $page_title    = 'Preventivi';
        $page_subtitle = 'Preventivi e offerte commerciali clienti';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Preventivi']];
        $page_actions  = '<a href="' . url('sales/quotes/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo preventivo
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/sales/quotes/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $errors = [];
        $values = $this->defaultValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                $errors = qk_offerta_validate($values, $righe);

                if (empty($errors)) {
                    // Compliance checks
                    require_once INCLUDES_DIR . '/compliance.php';
                    $violations = qk_compliance_check_sale($this->pdo, (int)$values['cliente_id'], $righe);
                    
                    $is_bozza = ($values['stato'] ?? 'bozza') === 'bozza';
                    
                    if (!empty($violations)) {
                        $violation_msgs = [];
                        foreach ($violations as $v) {
                            $violation_msgs[] = $v['message'];
                        }
                        
                        if ($is_bozza) {
                            $_SESSION['flash_warning'] = "Attenzione! Rilevate violazioni di compliance: " . implode(" | ", $violation_msgs);
                        } else {
                            $customer_url = url('customers/edit?id=' . $values['cliente_id']);
                            $errors['_global'] = "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='alert-link' style='color:#721c24;font-weight:bold;text-decoration:underline;'>Gestisci autorizzazioni cliente →</a>";
                        }
                    }

                    if (empty($errors)) {
                        try {
                            $user_id = (int) (current_user()['id'] ?? 0);
                            $id      = qk_insert_offerta($this->pdo, $values, $righe, $user_id);
                            
                            // If order was generated / accepted, we log compliance transactions if any
                            if (($values['stato'] ?? '') === 'accettata') {
                                qk_compliance_log_transactions($this->pdo, $id, $righe);
                            }
                            
                            redirect_with('sales/quotes', 'success', 'Preventivo ' . e($values['numero_offerta']) . ' salvato correttamente.');
                        } catch (Throwable $e) {
                            $errors['_global'] = 'Errore database: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        $numero_offerta = $values['numero_offerta'] ?: qk_next_numero_offerta($this->pdo);

        $aziende = qk_aziende_list($this->pdo);
        $defaultAziendaId = isset($_SESSION['azienda_attiva_id']) ? (string)$_SESSION['azienda_attiva_id'] : '';
        if ($defaultAziendaId === '') {
            foreach ($aziende as $az) {
                if ($az['predefinita'] == 1) {
                    $defaultAziendaId = (string)$az['id'];
                    break;
                }
            }
        }
        if ($defaultAziendaId === '' && !empty($aziende)) {
            $defaultAziendaId = (string)$aziende[0]['id'];
        }
        if (empty($values['azienda_interna_id'])) {
            $values['azienda_interna_id'] = $defaultAziendaId;
        }
        
        $listini = $this->pdo->query("SELECT id, nome, valuta FROM listini WHERE attivo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

        $active_tab    = 'quotes';
        $page_title    = 'Nuovo preventivo';
        $page_subtitle = 'Crea un preventivo commerciale per un cliente';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Preventivi', 'url' => url('sales/quotes')],
            ['label' => 'Nuovo'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/sales/quotes/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/quotes', 'danger', 'Preventivo non specificato.');
        }

        $detail = qk_get_offerta($this->pdo, $id);
        if (!$detail) {
            redirect_with('sales/quotes', 'danger', 'Preventivo non trovato.');
        }

        $errors = [];
        $values = $this->mapDetailToValues($detail['offerta']);
        $preloadedRighe = $detail['righe'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                $errors = qk_offerta_validate($values, $righe);

                if (empty($errors)) {
                    // Compliance checks
                    require_once INCLUDES_DIR . '/compliance.php';
                    $violations = qk_compliance_check_sale($this->pdo, (int)$values['cliente_id'], $righe);
                    
                    $is_bozza = ($values['stato'] ?? 'bozza') === 'bozza';
                    
                    if (!empty($violations)) {
                        $violation_msgs = [];
                        foreach ($violations as $v) {
                            $violation_msgs[] = $v['message'];
                        }
                        
                        if ($is_bozza) {
                            $_SESSION['flash_warning'] = "Attenzione! Rilevate violazioni di compliance: " . implode(" | ", $violation_msgs);
                        } else {
                            $customer_url = url('customers/edit?id=' . $values['cliente_id']);
                            $errors['_global'] = "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='alert-link' style='color:#721c24;font-weight:bold;text-decoration:underline;'>Gestisci autorizzazioni cliente →</a>";
                        }
                    }

                    if (empty($errors)) {
                        try {
                            $user_id = (int) (current_user()['id'] ?? 0);
                            qk_update_offerta($this->pdo, $id, $values, $righe, $user_id);
                            
                            if (($values['stato'] ?? '') === 'accettata') {
                                qk_compliance_log_transactions($this->pdo, $id, $righe);
                            }
                            
                            redirect_with('sales/quotes', 'success', 'Preventivo ' . e($values['numero_offerta']) . ' modificato correttamente.');
                        } catch (Throwable $e) {
                            $errors['_global'] = 'Errore database: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        $numero_offerta = $values['numero_offerta'];
        
        $listini = $this->pdo->query("SELECT id, nome, valuta FROM listini WHERE attivo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

        $aziende = qk_aziende_list($this->pdo);

        $active_tab    = 'quotes';
        $page_title    = 'Modifica preventivo ' . $numero_offerta;
        $page_subtitle = 'Modifica i dettagli e le righe del preventivo';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Preventivi', 'url' => url('sales/quotes')],
            ['label' => '#' . $id],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/sales/quotes/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    private function mapDetailToValues(array $quote): array
    {
        $canale_codice = 'email_diretta';
        if (!empty($quote['canale_vendita_id'])) {
            $stCv = $this->pdo->prepare('SELECT codice FROM canali_vendita WHERE id = ?');
            $stCv->execute([$quote['canale_vendita_id']]);
            $canale_codice = $stCv->fetchColumn() ?: 'email_diretta';
        }

        return [
            'numero_offerta'          => $quote['numero_preventivo'] ?? '',
            'cliente_id'              => $quote['cliente_id'] ?? '',
            'cliente_label'           => $quote['cliente_label'] ?? '',
            'azienda_interna_id'      => $quote['azienda_interna_id'] ?? '',
            'alla_ca'                 => $quote['alla_ca'] ?? '',
            'contatto_id'             => $quote['contatto_id'] ?? '',
            'contatto_nome'           => $quote['contatto_nome'] ?? '',
            'contatto_email'          => $quote['contatto_email'] ?? '',
            'contatto_telefono'       => $quote['contatto_telefono'] ?? '',
            'indirizzo_spedizione_id' => $quote['indirizzo_spedizione_id'] ?? '',
            'data_offerta'            => $quote['data_offerta'] ?? '',
            'data_scadenza'           => $quote['data_scadenza'] ?? '',
            'data_consegna_richiesta' => $quote['data_consegna_richiesta'] ?? '',
            'canale_vendita'          => $canale_codice,
            'stato'                   => $quote['stato'] ?? 'bozza',
            'condizioni_pagamento'    => $quote['condizioni_pagamento'] ?? '',
            'trasporto'               => $quote['trasporto'] ?? 'Franco destino',
            'valuta'                  => $quote['valuta'] ?? 'EUR',
            'sconto_globale_perc'     => $quote['sconto_globale_perc'] ?? '0',
            'note_cliente'            => $quote['note_cliente'] ?? '',
            'note_interne'            => $quote['note_interne'] ?? '',
            'oggetto'                 => $quote['oggetto'] ?? '',
            'riferimento_interno'     => $quote['riferimento_interno'] ?? '',
            'listino_id'              => $quote['listino_id'] ?? '',
        ];
    }

    /* ── API: ricerca clienti (JSON) ──────────────────────────────────────── */

    public function apiClienti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = get_param('q');
        if (strlen($q) < 1) {
            echo '[]';
            exit;
        }
        echo json_encode(qk_search_clienti($this->pdo, $q), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── API: ricerca prodotti (JSON) ─────────────────────────────────────── */

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = get_param('q');
        echo json_encode(qk_search_prodotti_sales($this->pdo, $q), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── API: crea cliente rapido (JSON, POST) ────────────────────────────── */

    public function apiCreaCliente(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF invalido']);
            exit;
        }

        $ts  = post('tipo_soggetto', 'azienda');
        $rs  = post('ragione_sociale');
        $nm  = post('nome');
        $cog = post('cognome');
        $email = post('email');
        $tel   = post('telefono');

        $label = $rs ?: trim("$nm $cog");
        if (trim($label) === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Nome o ragione sociale obbligatori.']);
            exit;
        }

        try {
            $id = qk_crea_cliente_rapido($this->pdo, [
                'tipo_soggetto'  => $ts,
                'ragione_sociale' => $rs,
                'nome'           => $nm,
                'cognome'        => $cog,
                'email'          => $email,
                'telefono'       => $tel,
            ]);
            echo json_encode([
                'success'  => true,
                'id'       => $id,
                'label'    => $label,
                'email'    => $email,
                'telefono' => $tel,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: crea prodotto rapido (JSON, POST) ───────────────────────────── */

    public function apiCreaProdotto(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF invalido']);
            exit;
        }

        $nome = post('nome');
        $sku  = post('sku_interno');
        $um   = post('unita_misura', 'pz');

        if (trim($nome) === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Il nome prodotto è obbligatorio.']);
            exit;
        }

        try {
            $id  = qk_crea_prodotto_rapido($this->pdo, ['nome' => $nome, 'sku_interno' => $sku, 'unita_misura' => $um]);
            $row = $this->pdo->prepare('SELECT sku_interno, unita_misura_stock_id FROM prodotti WHERE id = :id');
            $row->execute([':id' => $id]);
            $prod = $row->fetch(PDO::FETCH_ASSOC);
            
            $stUm = $this->pdo->prepare('SELECT codice FROM unita_misura WHERE id = ?');
            $stUm->execute([$prod['unita_misura_stock_id']]);
            $um_codice = $stUm->fetchColumn() ?: 'pz';

            echo json_encode([
                'success'      => true,
                'id'           => $id,
                'label'        => $nome,
                'sku'          => $prod['sku_interno'],
                'unita_misura' => $um_codice,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiClienteDettagli(): void
    {
        ob_start();
        $cliente_id = (int) get_param('cliente_id', '0');
        
        $cliente = null;
        $contatti = [];
        $indirizzi = [];
        
        if ($cliente_id > 0) {
            $stmtCli = $this->pdo->prepare(
                "SELECT cf.id, cf.email, cf.telefono, cf.cellulare, dc.condizioni_pagamento, dc.listino_id
                   FROM clienti_fornitori cf
              LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
                  WHERE cf.id = ? AND cf.attivo = 1"
            );
            $stmtCli->execute([$cliente_id]);
            $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
            
            if ($cliente) {
                // Fetch contacts
                $stmtContatti = $this->pdo->prepare(
                    "SELECT id, COALESCE(nome || ' ' || COALESCE(cognome, ''), ragione_sociale) AS nome_completo,
                            email, telefono, cellulare
                       FROM clienti_fornitori
                      WHERE parent_id = ? AND attivo = 1
                      ORDER BY nome, cognome"
                );
                $stmtContatti->execute([$cliente_id]);
                $contatti = $stmtContatti->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch addresses
                $stmtAddr = $this->pdo->prepare(
                    "SELECT id, tipo, nome_sede, predefinito, via, civico, cap, citta, provincia, nazione,
                            referente_consegna, telefono_consegna, note_consegna, presso
                       FROM indirizzi
                      WHERE soggetto_id = ? AND attivo = 1
                      ORDER BY CASE tipo WHEN 'spedizione' THEN 1 WHEN 'principale' THEN 2 ELSE 3 END,
                               predefinito DESC, id ASC"
                );
                $stmtAddr->execute([$cliente_id]);
                $indirizzi = $stmtAddr->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'cliente' => $cliente,
            'contatti' => $contatti,
            'indirizzi' => $indirizzi
        ]);
        exit;
    }

    /* ── Helper privati ───────────────────────────────────────────────────── */

    private function defaultValues(): array
    {
        return [
            'numero_offerta'          => '',
            'cliente_id'              => '',
            'cliente_label'           => '',
            'azienda_interna_id'      => '',
            'alla_ca'                 => '',
            'contatto_id'             => '',
            'contatto_nome'           => '',
            'contatto_email'          => '',
            'contatto_telefono'       => '',
            'indirizzo_spedizione_id' => '',
            'data_offerta'            => date('Y-m-d'),
            'data_scadenza'           => date('Y-m-d', strtotime('+6 months')),
            'data_consegna_richiesta' => '',
            'canale_vendita'          => 'email_diretta',
            'stato'                   => 'bozza',
            'condizioni_pagamento'    => '',
            'trasporto'               => 'Franco destino',
            'valuta'                  => 'EUR',
            'sconto_globale_perc'     => '0',
            'note_cliente'            => '',
            'note_interne'            => '',
            'riferimento_interno'     => '',
            'listino_id'              => '',
        ];
    }

    private function readPostValues(): array
    {
        return [
            'numero_offerta'          => post('numero_offerta'),
            'cliente_id'              => post('cliente_id'),
            'cliente_label'           => post('cliente_label'),
            'azienda_interna_id'      => post('azienda_interna_id'),
            'alla_ca'                 => post('alla_ca'),
            'contatto_id'             => post('contatto_id'),
            'contatto_nome'           => post('contatto_nome'),
            'contatto_email'          => post('contatto_email'),
            'contatto_telefono'       => post('contatto_telefono'),
            'indirizzo_spedizione_id' => post('indirizzo_spedizione_id'),
            'data_offerta'            => post('data_offerta'),
            'data_scadenza'           => post('data_scadenza'),
            'data_consegna_richiesta' => post('data_consegna_richiesta'),
            'canale_vendita'          => post('canale_vendita'),
            'stato'                   => post('stato', 'bozza'),
            'condizioni_pagamento'    => post('condizioni_pagamento'),
            'trasporto'               => post('trasporto'),
            'valuta'                  => post('valuta', 'EUR'),
            'sconto_globale_perc'     => post('sconto_globale_perc', '0'),
            'note_cliente'            => post('note_cliente'),
            'note_interne'            => post('note_interne'),
            'riferimento_interno'     => post('riferimento_interno'),
            'listino_id'              => post('listino_id'),
        ];
    }

    private function readPostRighe(): array
    {
        $raw   = $_POST['righe'] ?? [];
        $righe = [];
        foreach ($raw as $r) {
            if (empty(trim($r['descrizione'] ?? ''))) continue;
            $righe[] = [
                'prodotto_id'    => $r['prodotto_id']    ?: null,
                'descrizione'    => $r['descrizione']    ?? '',
                'quantita'       => $r['quantita']       ?? 0,
                'unita_misura'   => $r['unita_misura']   ?? '',
                'prezzo_unitario' => $r['prezzo_unitario'] ?? 0,
                'sconto_perc'    => $r['sconto_perc']    ?? 0,
                'iva_perc'       => $r['iva_perc']       ?? 22,
                'note_riga'      => $r['note_riga']      ?? '',
                'uom_stock'         => $r['uom_stock'] ?? '',
                'uom_stock_id'      => $r['uom_stock_id'] ?? '',
                'uom_commercial'    => $r['uom_commercial'] ?? '',
                'uom_commercial_id' => $r['uom_commercial_id'] ?? '',
                'quantita_contenuto'=> $r['quantita_contenuto'] ?? '1',
                'sku'               => $r['sku'] ?? '',
                'quantita_confezione'=> $r['quantita_confezione'] ?? '',
            ];
        }
        return $righe;
    }

    /** POST /sales/quotes/api/check-compliance */
    public function apiCheckCompliance(): void
    {
        ob_start();
        header('Content-Type: application/json');
        
        $customer_id = (int) post('cliente_id', '0');
        $product_id = (int) post('prodotto_id', '0');
        
        if (!$customer_id || !$product_id) {
            ob_end_clean();
            echo json_encode(['ok' => true]); // No check if missing client/prod
            exit;
        }

        try {
            require_once INCLUDES_DIR . '/compliance.php';
            $violations = qk_compliance_check_sale($this->pdo, $customer_id, [['prodotto_id' => $product_id]]);
            ob_end_clean();
            if (empty($violations)) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'violations' => $violations]);
            }
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
