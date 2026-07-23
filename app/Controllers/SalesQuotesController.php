<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/sales_quotes.php';
require_once INCLUDES_DIR . '/tabelle_base.php';
require_once INCLUDES_DIR . '/compliance.php';

final class SalesQuotesController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q', '');
        $status = get_param('status', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');

        $sortMap = ['data' => 'o.data_offerta', 'numero' => 'o.numero_preventivo',
                    'cliente' => 'cliente_nome', 'stato' => 'o.stato',
                    'scadenza' => 'o.data_scadenza', 'totale' => 'o.totale'];
        $pag      = qk_pagination_params(array_keys($sortMap), 'data', 'desc');
        $orderCol = $sortMap[$pag['sort']] ?? 'o.data_offerta';
        $orderDir = $pag['dir'] === 'asc' ? 'ASC' : 'DESC';

        $where = ['1=1']; $params = [];
        if ($search) {
            $where[] = "(o.numero_preventivo LIKE :q OR o.riferimento_interno LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($status) { $where[] = "o.stato = :stato"; $params[':stato'] = $status; }
        if ($dal)    { $where[] = "o.data_offerta >= :dal"; $params[':dal'] = $dal; }
        if ($al)     { $where[] = "o.data_offerta <= :al";  $params[':al']  = $al; }

        $whereStr = implode(' AND ', $where);

        $total  = qk_preventivi_count($this->pdo, $whereStr, $params);
        $offers = qk_preventivi_list($this->pdo, $whereStr, $params, $orderCol, $orderDir, (int)$pag['limit'], (int)$pag['offset']);

        $active_tab    = 'quotes';
        $page_title    = 'Preventivi';
        $page_subtitle = 'Preventivi e offerte commerciali clienti';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Preventivi']];
        $page_actions  = '<a href="' . url('sales/quotes/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo preventivo
        </a>';

        $this->page(APP_ROOT_DIR . '/app/views/sales/quotes/_list.php', compact(
            'offers', 'total', 'search', 'status', 'dal', 'al', 'pag',
            'active_tab', 'page_title', 'page_subtitle', 'breadcrumbs', 'page_actions'
        ));
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
                    // Compliance checks (Audit 2, V4 — estratto in un metodo condiviso, era
                    // duplicato carattere per carattere anche in edit() poco più sotto in
                    // questo stesso file; vedi checkComplianceOrWarn())
                    $complianceError = $this->checkComplianceOrWarn($values, $righe);
                    if ($complianceError !== null) {
                        $errors['_global'] = $complianceError;
                    }

                    if (empty($errors)) {
                        try {
                            $user_id = (int) (current_user()['id'] ?? 0);

                            // Audit 2, V4 — stesso motivo del compliance check sopra, vedi
                            // maybeCreateOneTimeAddress().
                            $this->maybeCreateOneTimeAddress($values);

                            $id      = qk_insert_offerta($this->pdo, $values, $righe, $user_id);

                            redirect_with('sales/quotes', 'success', 'Preventivo ' . e($values['numero_offerta']) . ' salvato correttamente.');
                        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
        
        $listini = qk_get_listini_vendita_attivi($this->pdo);
        $canali  = qk_get_canali_vendita_attivi($this->pdo);

        $active_tab    = 'quotes';
        $page_title    = 'Nuovo preventivo';
        $page_subtitle = 'Crea un preventivo commerciale per un cliente';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Preventivi', 'url' => url('sales/quotes')],
            ['label' => 'Nuovo'],
        ];

        $this->page(APP_ROOT_DIR . '/app/views/sales/quotes/_form.php', [
            'errors' => $errors,
            'values' => $values,
            'numero_offerta' => $numero_offerta,
            'aziende' => $aziende,
            'listini' => $listini,
            'canali' => $canali,
            'active_tab' => $active_tab,
            'page_title' => $page_title,
            'page_subtitle' => $page_subtitle,
            'breadcrumbs' => $breadcrumbs,
            'extra_css' => ['sales-common.css']
        ]);
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

        // Get linked order if accepted
        $linkedOrder = qk_get_ordine_da_preventivo($this->pdo, $id);

        $errors = [];
        $values = $this->mapDetailToValues($detail['offerta']);
        $preloadedRighe = $detail['righe'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $oldStato = $detail['offerta']['stato'] ?? 'bozza';
            if ($oldStato !== 'bozza') {
                flash_set('danger', 'Impossibile salvare modifiche: questo preventivo non è più in stato bozza ed è bloccato.');
                redirect(url('sales/quotes/edit?id=' . $id));
            }
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                $errors = qk_offerta_validate($values, $righe);

                if (empty($errors)) {
                    // Compliance checks (Audit 2, V4 — vedi checkComplianceOrWarn(), stesso
                    // metodo condiviso usato in create())
                    $complianceError = $this->checkComplianceOrWarn($values, $righe);
                    if ($complianceError !== null) {
                        $errors['_global'] = $complianceError;
                    }

                    if (empty($errors)) {
                        try {
                            $user_id = (int) (current_user()['id'] ?? 0);

                            // Audit 2, V4 — vedi maybeCreateOneTimeAddress()
                            $this->maybeCreateOneTimeAddress($values);

                            qk_update_offerta($this->pdo, $id, $values, $righe, $user_id);

                            redirect_with('sales/quotes', 'success', 'Preventivo ' . e($values['numero_offerta']) . ' modificato correttamente.');
                        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                            $errors['_global'] = 'Errore database: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        $numero_offerta = $values['numero_offerta'];
        
        $listini = qk_get_listini_vendita_attivi($this->pdo);
        $canali  = qk_get_canali_vendita_attivi($this->pdo);

        $aziende = qk_aziende_list($this->pdo);

        $active_tab    = 'quotes';
        $page_title    = 'Modifica preventivo ' . $numero_offerta;
        $page_subtitle = 'Modifica i dettagli e le righe del preventivo';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Preventivi', 'url' => url('sales/quotes')],
            ['label' => '#' . $id],
        ];

        $this->page(APP_ROOT_DIR . '/app/views/sales/quotes/_form.php', [
            'id' => $id,
            'detail' => $detail,
            'linkedOrder' => $linkedOrder,
            'errors' => $errors,
            'values' => $values,
            'preloadedRighe' => $preloadedRighe,
            'numero_offerta' => $numero_offerta,
            'listini' => $listini,
            'canali' => $canali,
            'aziende' => $aziende,
            'active_tab' => $active_tab,
            'page_title' => $page_title,
            'page_subtitle' => $page_subtitle,
            'breadcrumbs' => $breadcrumbs,
            'extra_css' => ['sales-common.css']
        ]);
    }

    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('sales/quotes'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'invia':
                    // Tappa 6 (PIANO - Motore Unificato di Transizioni di Stato):
                    // prima un UPDATE grezzo qui, senza nessun controllo di stato
                    // (violazione MVC + il caso P3 che ha originato il piano).
                    qk_preventivo_invia($this->pdo, $id);
                    flash_set('success', 'Preventivo segnato come Inviato.');
                    redirect(url('sales/quotes/edit') . '?id=' . $id);
                    break;

                case 'conferma_ordine':
                    $nuovoOrderId = qk_preventivo_conferma_ordine($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Preventivo accettato e Ordine di Vendita creato.');
                    redirect(url('sales/quotes/edit') . '?id=' . $id);
                    break;

                case 'nuova_versione':
                    $nuovoId = qk_preventivo_nuova_versione($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Nuova versione creata.');
                    redirect(url('sales/quotes/edit') . '?id=' . $nuovoId);
                    break;

                case 'duplica':
                    $nuovoId = qk_preventivo_duplica($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Preventivo duplicato correttamente in una nuova bozza.');
                    redirect(url('sales/quotes/edit') . '?id=' . $nuovoId);
                    break;

                case 'rifiuta':
                    // Tappa 6 (PIANO - Motore Unificato di Transizioni di Stato):
                    // stesso motivo del caso 'invia' sopra.
                    qk_preventivo_rifiuta($this->pdo, $id);
                    flash_set('success', 'Preventivo segnato come Rifiutato.');
                    redirect(url('sales/quotes/edit') . '?id=' . $id);
                    break;

                default:
                    flash_set('error', "Azione '$azione' non riconosciuta.");
                    redirect(url('sales/quotes/edit') . '?id=' . $id);
            }
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('error', $e->getMessage());
            redirect(url('sales/quotes/edit') . '?id=' . $id);
        }
    }

    /**
     * Esegue il controllo compliance sulle righe di un preventivo. Se ci sono
     * violazioni e il documento è ancora in bozza, mostra solo un avviso
     * (flash warning) e ritorna null (non blocca). Se non è in bozza, ritorna
     * il messaggio di errore da mettere in $errors['_global'] (blocca il
     * salvataggio). Nessuna violazione -> ritorna null.
     *
     * Estratto (Audit 2, V4) da create()/edit(), dove questo identico blocco
     * di 19 righe era duplicato carattere per carattere.
     */
    private function checkComplianceOrWarn(array $values, array $righe): ?string
    {
        $violations = qk_compliance_check_sale($this->pdo, (int)$values['cliente_id'], $righe);
        if (empty($violations)) {
            return null;
        }

        $violation_msgs = [];
        foreach ($violations as $v) {
            $violation_msgs[] = $v['message'];
        }

        $is_bozza = ($values['stato'] ?? 'bozza') === 'bozza';
        if ($is_bozza) {
            flash_set('warning', "Attenzione! Rilevate violazioni di compliance: " . implode(" | ", $violation_msgs));
            return null;
        }

        $customer_url = url('customers/edit?id=' . $values['cliente_id']);
        return "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='qk-alert-link'>Gestisci autorizzazioni cliente →</a>";
    }

    /**
     * Se l'operatore ha spuntato "salva in anagrafica" sull'indirizzo una
     * tantum, crea il nuovo indirizzo e aggiorna $values['indirizzo_spedizione_id']
     * per riferimento. Normalizza anche il caso "custom"/non numerico a
     * stringa vuota.
     *
     * Estratto (Audit 2, V4) da create()/edit(), stesso blocco duplicato.
     */
    private function maybeCreateOneTimeAddress(array &$values): void
    {
        qk_maybe_create_one_time_address($this->pdo, $values);
    }

    private function mapDetailToValues(array $quote): array
    {
        $canale_codice = !empty($quote['canale_vendita_id']) ? (qk_get_canale_vendita_codice($this->pdo, (int)$quote['canale_vendita_id']) ?: 'email_diretta') : 'email_diretta';

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
            'consegna_via'            => $quote['consegna_via'] ?? '',
            'consegna_civico'         => $quote['consegna_civico'] ?? '',
            'consegna_cap'            => $quote['consegna_cap'] ?? '',
            'consegna_citta'          => $quote['consegna_citta'] ?? '',
            'consegna_provincia'      => $quote['consegna_provincia'] ?? '',
            'consegna_nazione'        => $quote['consegna_nazione'] ?? '',
            'consegna_presso'         => $quote['consegna_presso'] ?? '',
            'consegna_referente'      => $quote['consegna_referente'] ?? '',
            'consegna_telefono'       => $quote['consegna_telefono'] ?? '',
            'consegna_note'           => $quote['consegna_note'] ?? '',
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
                'tipo_soggetto'   => $ts,
                'ragione_sociale' => $rs,
                'nome'            => $nm,
                'cognome'         => $cog,
                'email'           => $email,
                'telefono'        => $tel,
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
            $prod = qk_get_prodotto_stock_info($this->pdo, $id);
            $um_codice = !empty($prod['unita_misura_stock_id']) ? (qk_get_unita_misura_codice($this->pdo, (int)$prod['unita_misura_stock_id']) ?: 'pz') : 'pz';

            echo json_encode([
                'success'      => true,
                'id'           => $id,
                'label'        => $nome,
                'sku'          => $prod['sku_interno'],
                'unita_misura' => $um_codice,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => qk_prodotti_db_message($e)]);
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
            $cliente = qk_get_cliente_dettagli_per_preventivo($this->pdo, $cliente_id);
            if ($cliente) {
                $contatti  = qk_get_contatti_cliente($this->pdo, $cliente_id);
                $indirizzi = qk_get_indirizzi_cliente($this->pdo, $cliente_id);
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
            'salva_in_anagrafica_indirizzo' => '',
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
            'consegna_nome_sede'      => post('consegna_nome_sede'),
            'consegna_via'            => post('consegna_via'),
            'consegna_civico'         => post('consegna_civico'),
            'consegna_cap'            => post('consegna_cap'),
            'consegna_citta'          => post('consegna_citta'),
            'consegna_provincia'      => post('consegna_provincia'),
            'consegna_nazione'        => post('consegna_nazione'),
            'consegna_presso'         => post('consegna_presso'),
            'consegna_referente'      => post('consegna_referente'),
            'consegna_telefono'       => post('consegna_telefono'),
            'consegna_note'           => post('consegna_note'),
            'salva_in_anagrafica_indirizzo' => post('salva_in_anagrafica_indirizzo'),
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
            'oggetto'                 => post('oggetto'),
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
        
        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'CSRF token non valido.']);
            exit;
        }

        $customer_id = (int) post('cliente_id', '0');
        $product_id = (int) post('prodotto_id', '0');
        
        if (!$customer_id || !$product_id) {
            ob_end_clean();
            echo json_encode(['ok' => true]); // No check if missing client/prod
            exit;
        }

        try {
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

    /**
     * Audit 2, V7 — dati comuni a pdf() e docx(): prima venivano recuperati e
     * calcolati due volte in modo identico (stesso qk_get_offerta(), stesso
     * qk_resolve_azienda_emittente_stampa(), stessa estrazione dei 4 totali
     * da $quota). Qui viene estratta SOLO questa parte, non il rendering
     * (HTML per mPDF vs chiamate PhpWord), che resta separato perché usa due
     * API completamente diverse e toccarlo è il pezzo a rischio più alto.
     */
    private function preparaDatiStampaOfferta(int $id): ?array
    {
        $detail = qk_get_offerta($this->pdo, $id);
        if (!$detail) {
            return null;
        }

        $quota = $detail['offerta'];
        $righe = $detail['righe'];

        // Recupera azienda interna emittente, rispettando l'integrità storica
        // (Revisione §5.3.3 / doc "00" §3.3, fix 14/07/2026): legge prima le
        // colonne denormalizzate già congelate sul preventivo, invece di
        // rifare sempre una query dal vivo che cambierebbe l'intestazione
        // fiscale di un documento vecchio a ogni ristampa.
        $azienda = qk_resolve_azienda_emittente_stampa($this->pdo, $quota);

        return [
            'quota'               => $quota,
            'righe'               => $righe,
            'azienda'             => $azienda,
            'imponibile'          => (float) $quota['imponibile'],
            'totale_iva'          => (float) $quota['totale_iva'],
            'totale'              => (float) $quota['totale'],
            'sconto_globale_perc' => (float) $quota['sconto_globale_perc'],
        ];
    }

    public function pdf(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('sales/quotes'));
        }

        $dati = $this->preparaDatiStampaOfferta($id);
        if (!$dati) {
            redirect(url('sales/quotes'));
        }
        $quota               = $dati['quota'];
        $righe               = $dati['righe'];
        $azienda             = $dati['azienda'];
        $imponibile          = $dati['imponibile'];
        $totale_iva          = $dati['totale_iva'];
        $totale              = $dati['totale'];
        $sconto_globale_perc = $dati['sconto_globale_perc'];

        // Genera il PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 45,
            'margin_bottom' => 25,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'tempDir'       => upload_ensure_dir('mpdf_tmp'),
        ]);

        $mpdf->SetTitle('Preventivo - ' . $quota['numero_preventivo']);
        $mpdf->SetAuthor('Quack ERP');

        // Header Image (Intestazione stampati)
        $headerHtml = '';
        if (!empty($azienda['header_path']) && file_exists(upload_abs($azienda['header_path']))) {
            $headerHtml = '<img src="' . upload_abs($azienda['header_path']) . '" style="width:100%; max-height:35mm;">';
        } elseif (!empty($azienda['logo_path']) && file_exists(upload_abs($azienda['logo_path']))) {
            $headerHtml = '
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
                <tr>
                    <td style="width:50%;"><img src="' . upload_abs($azienda['logo_path']) . '" style="max-height:22mm;"></td>
                    <td style="width:50%; text-align:right; font-weight:bold; font-size:14pt; color:#1a5276; vertical-align:middle;">
                        PREVENTIVO DI VENDITA
                    </td>
                </tr>
            </table>';
        } else {
            $headerHtml = '
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif; border-bottom:1px solid #ccc; padding-bottom:3mm;">
                <tr>
                    <td style="width:60%;">
                        <strong style="font-size:14pt; color:#1a5276;">' . e($azienda['ragione_sociale'] ?? 'Gemina S.r.l.') . '</strong><br>
                        <span style="font-size:8pt; color:#666;">P.IVA: ' . e($azienda['partita_iva'] ?? '') . ' | Email: ' . e($azienda['email'] ?? '') . ' | Tel: ' . e($azienda['telefono'] ?? '') . '</span>
                    </td>
                    <td style="width:40%; text-align:right; font-weight:bold; font-size:12pt; color:#1a5276; vertical-align:middle;">
                        PREVENTIVO DI VENDITA
                    </td>
                </tr>
            </table>';
        }

        $mpdf->SetHTMLHeader('
            <div style="width:100%; font-family:sans-serif; margin-bottom:5mm;">
                ' . $headerHtml . '
            </div>
        ');

        $mpdf->SetHTMLFooter('
            <div style="border-top:1px solid #999; padding-top:3mm; font-size:8pt; color:#666; text-align:center; font-family:sans-serif;">
                Pagina {PAGENO} di {nbpg} — Preventivo: ' . e($quota['numero_preventivo']) . '
            </div>
        ');

        $html = '
        <style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #222; }
            h2 { font-size: 11pt; color: #1a5276; border-bottom: 1.5pt solid #1a5276; padding-bottom: 1mm; margin-top: 5mm; margin-bottom: 3mm; text-transform: uppercase; }
            .info-table { width: 100%; border-collapse: collapse; margin-top: 1mm; margin-bottom: 0; }
            .info-table td { padding: 0.8mm 0; font-size: 9.5pt; vertical-align: top; }
            .info-table .lbl { font-weight: bold; color: #555; width: 30%; }
            .info-box { padding: 0; }
            
            .righe-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 4mm; }
            .righe-table th { background: #1a5276; color: #fff; padding: 2.2mm 2mm; font-size: 9pt; text-align: center; }
            .righe-table td { padding: 2.2mm 2mm; font-size: 9pt; border-bottom: 0.5pt solid #ccc; }
            .righe-table .num { text-align: right; font-family: monospace; }
            .righe-table .center { text-align: center; }
            .righe-table tr.zebra { background-color: #f8fafc; }
            
            .totals-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 4mm; }
            .totals-table td { padding: 1.5mm 2mm; font-size: 9.5pt; }
            .totals-table .val { text-align: right; font-weight: bold; width: 120px; }
            
            .cond-table { width: 100%; border-collapse: collapse; }
            .cond-table td { padding: 2.5mm 3mm; font-size: 9.5pt; vertical-align: top; border-bottom: 0.3pt solid #eee; }
            .cond-table .lbl { font-weight: bold; color: #444; width: 25%; }
        </style>';

        $contattoNome = $quota['contatto_nome'] ?: '';
        $contattoTel = $quota['contatto_telefono'] ?: '';
        $contattoEmail = $quota['contatto_email'] ?: '';

        // BLOCK 1: Info Generali e Recapiti
        $html .= '
        <table style="width:100%; border-collapse:collapse; margin-top:2mm; margin-bottom:5mm;">
            <tr>
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Dettagli Documento</strong>
                        <table class="info-table">
                            <tr>
                                <td class="lbl">Rif. Interno:</td>
                                <td><strong>' . e($quota['riferimento_interno'] ?: '—') . '</strong></td>
                            </tr>
                            <tr>
                                <td class="lbl">Data Offerta:</td>
                                <td>' . e(fmt_date($quota['data_offerta'])) . '</td>
                            </tr>
                            <tr>
                                <td class="lbl">Scadenza:</td>
                                <td>' . e(fmt_date($quota['data_scadenza'])) . '</td>
                            </tr>
                        </table>
                    </div>
                </td>
                
                <td style="width:4%;">&nbsp;</td>
                
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Spett.le Cliente</strong>
                        <div style="margin-top:1.5mm; font-size:9.5pt; line-height:1.45;">
                            <strong>' . e($quota['cliente_ragione_sociale']) . '</strong><br>
                            ' . e($quota['cliente_indirizzo'] ?: '—') . '<br>';
        if ($quota['cliente_partita_iva']) {
            $html .= 'P.IVA: ' . e($quota['cliente_partita_iva']) . '<br>';
        }
        if (!empty($contattoNome)) {
            $html .= 'Alla c.a.: <strong>' . e($contattoNome) . '</strong><br>';
        }
        if (!empty($contattoTel) || !empty($contattoEmail)) {
            $html .= (!empty($contattoTel) ? 'Tel: ' . e($contattoTel) : '') . 
                     (!empty($contattoEmail) ? ' | Email: ' . e($contattoEmail) : '') . '<br>';
        }
        $html .= '
                        </div>
                    </div>
                </td>
            </tr>
        </table>';

        $titoloRighe = !empty($quota['oggetto']) ? $quota['oggetto'] : 'Articoli Richiesti';

        // BLOCK 2: Righe Articoli
        $html .= '
        <h2>' . e($titoloRighe) . '</h2>
        <table class="righe-table">
            <thead>
                <tr>
                    <th style="width:12%">SKU</th>
                    <th style="width:30%">Descrizione</th>
                    <th style="width:15%">Confezione</th>
                    <th style="width:10%; text-align:right;">Q.tà</th>
                    <th style="width:8%; text-align:center;">UdM</th>
                    <th style="width:12%; text-align:right;">Prezzo Unit.</th>
                    <th style="width:13%; text-align:right;">Totale</th>
                </tr>
            </thead>
            <tbody>';

        $fill = false;
        foreach ($righe as $r) {
            $zebraClass = $fill ? ' class="zebra"' : '';
            $qty = (float)$r['quantita'];
            $um = $r['unita_misura'] ?: 'pz';
            $pu = (float)$r['prezzo_unitario'];
            $sconto = (float)$r['sconto_perc'];
            $rigaTotale = (float)$r['imponibile_riga'];
            
            $prezzoStr = fmt_money($pu);
            if ($sconto > 0) {
                $prezzoStr .= '<br><span style="font-size:7.5pt; color:#666;">Sconto ' . $sconto . '%</span>';
            }
            
            $html .= '
                <tr' . $zebraClass . '>
                    <td class="center"><strong>' . e($r['sku'] ?: '—') . '</strong></td>
                    <td>' . e($r['descrizione']) . '</td>
                    <td class="center">' . e($r['quantita_confezione'] ?: '—') . '</td>
                    <td class="num"><strong>' . fmt_qty($qty) . '</strong></td>
                    <td class="center">' . e($um) . '</td>
                    <td class="num">' . $prezzoStr . '</td>
                    <td class="num"><strong>' . fmt_money($rigaTotale) . '</strong></td>
                </tr>';
            
            $fill = !$fill;
        }

        $html .= '
            </tbody>
        </table>';

        $html .= '
        <table class="totals-table">
            <tr>
                <td style="width:50%;">&nbsp;</td>
                <td style="text-align:right; color:#666; white-space:nowrap;">Totale Imponibile:</td>
                <td class="val">' . fmt_money($imponibile) . '</td>
            </tr>';
        if ($sconto_globale_perc > 0) {
            $html .= '
            <tr>
                <td>&nbsp;</td>
                <td style="text-align:right; color:#666; white-space:nowrap;">Sconto Globale (' . $sconto_globale_perc . '%):</td>
                <td class="val">Sconto applicato</td>
            </tr>';
        }
        $html .= '
            <tr>
                <td>&nbsp;</td>
                <td style="text-align:right; color:#666; white-space:nowrap;">Totale I.V.A.:</td>
                <td class="val">' . fmt_money($totale_iva) . '</td>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td style="text-align:right; font-weight:bold; color:#1a5276; white-space:nowrap;">Totale Documento:</td>
                <td class="val" style="color:#1a5276; border-top:1px solid #1a5276;">' . fmt_money($totale) . '</td>
            </tr>
        </table>';

        // BLOCK 3: Condizioni
        $consegnaIndirizzo = '—';
        if (!empty($quota['consegna_via'])) {
            $consegnaIndirizzo = '<strong>' . e($quota['consegna_presso'] ?: $quota['cliente_ragione_sociale']) . '</strong> - ' .
                                 e($quota['consegna_via']) . ' ' . e($quota['consegna_civico']) . ', ' .
                                 e($quota['consegna_cap']) . ' ' . e($quota['consegna_citta']) . ' (' . e($quota['consegna_provincia']) . ')';
        }

        $html .= '
        <h2>Condizioni di Vendita</h2>
        <table class="cond-table">
            <tr>
                <td class="lbl">Modalità Pagamento:</td>
                <td>' . e($quota['condizioni_pagamento'] ?: 'Come da accordi') . '</td>
            </tr>
            <tr>
                <td class="lbl">Trasporto / Consegna:</td>
                <td>' . e($quota['trasporto'] ?: '—') . '</td>
            </tr>
            <tr>
                <td class="lbl">Luogo di Destinazione:</td>
                <td>' . $consegnaIndirizzo . '</td>
            </tr>';

        if (!empty($quota['consegna_note'])) {
            $html .= '
            <tr>
                <td class="lbl">Note Consegna:</td>
                <td>' . e($quota['consegna_note']) . '</td>
            </tr>';
        }

        if (!empty($quota['note_cliente'])) {
            $html .= '
            <tr>
                <td class="lbl">Note aggiuntive:</td>
                <td>' . nl2br(e($quota['note_cliente'])) . '</td>
            </tr>';
        }

        $html .= '
        </table>';

        $html .= '
        <div style="margin-top:15mm; page-break-inside:avoid;">
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
                <tr>
                    <td style="width:45%; vertical-align:bottom; padding-bottom:6px;">
                        <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;">Accettazione per Ordine</span><br>
                        <span style="font-size:7.5pt; color:#777777;">Timbro e Firma leggibile del Cliente</span>
                    </td>
                    <td style="width:10%;"></td>
                    <td style="width:45%; vertical-align:bottom; padding-bottom:6px; text-align:right;">
                        <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;">' . e(!empty($azienda['ragione_sociale']) ? $azienda['ragione_sociale'] : 'Azienda') . '</span><br>
                        <span style="font-size:7.5pt; color:#777777;">Firma Direzione Commerciale</span>
                    </td>
                </tr>
                <tr>
                    <td style="border:1px dashed #bbbbbb; padding:12px; height:25mm; vertical-align:bottom; background-color:#fafafa;">
                        <div style="border-bottom:1px solid #aaaaaa; width:80%;"></div>
                    </td>
                    <td></td>
                    <td style="border:1px dashed #bbbbbb; padding:12px; height:25mm; vertical-align:bottom; background-color:#fafafa;">
                        <div style="border-bottom:1px solid #aaaaaa; width:80%; margin-left:auto;"></div>
                    </td>
                </tr>
            </table>
        </div>';

        $mpdf->WriteHTML($html);

        while (ob_get_level()) {
            ob_end_clean();
        }
        $mpdf->Output('Preventivo_' . str_replace('/', '_', $quota['numero_preventivo']) . '.pdf', \Mpdf\Output\Destination::INLINE);
        exit;
    }

    public function docx(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('sales/quotes'));
        }

        $dati = $this->preparaDatiStampaOfferta($id);
        if (!$dati) {
            redirect(url('sales/quotes'));
        }
        $quota               = $dati['quota'];
        $righe               = $dati['righe'];
        $azienda             = $dati['azienda'];
        $imponibile          = $dati['imponibile'];
        $totale_iva          = $dati['totale_iva'];
        $totale              = $dati['totale'];
        $sconto_globale_perc = $dati['sconto_globale_perc'];

        $wordHelper = new \Quack\WordHelper();
        $phpWord = $wordHelper->getPhpWord();
        $section = $wordHelper->createStandardSection();
        $wordHelper->addStandardHeader($section, $azienda, 'PREVENTIVO DI VENDITA');
        $wordHelper->addStandardFooter($section, 'Prev. N°: ' . $quota['numero_preventivo']);

        // Styles
        $fontTitle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1A5276'];
        $fontBold = ['name' => 'Arial', 'size' => 9.5, 'bold' => true, 'color' => '333333'];
        $fontNormal = ['name' => 'Arial', 'size' => 9.5, 'color' => '222222'];
        $fontTotal = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '1A5276'];

        // Document Title
        $section->addText('PREVENTIVO DI VENDITA - N° ' . $quota['numero_preventivo'], ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '1A5276'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 150]);

        // BLOCK 1: Two Column Layout Table
        $block1Table = $section->addTable(['cellMargin' => 100]);
        $block1Table->addRow();
        
        // Left Cell (Dettagli Preventivo)
        $leftCell = $block1Table->addCell(5070, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunLeft = $leftCell->addTextRun();
        $textRunLeft->addText('Dettagli Documento', $fontTitle);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Rif. Interno: ' . ($quota['riferimento_interno'] ?: '—'), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Data Offerta: ' . fmt_date($quota['data_offerta']), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Scadenza: ' . fmt_date($quota['data_scadenza']), $fontNormal);

        // Spacer Cell
        $block1Table->addCell(400);

        // Right Cell (Cliente)
        $rightCell = $block1Table->addCell(4800, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunRight = $rightCell->addTextRun();
        $textRunRight->addText('Spett.le Cliente', $fontTitle);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($quota['cliente_ragione_sociale'], ['name' => 'Arial', 'size' => 9.5, 'bold' => true]);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($quota['cliente_indirizzo'] ?: '—', $fontNormal);
        if ($quota['cliente_partita_iva']) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('P.IVA: ' . $quota['cliente_partita_iva'], $fontNormal);
        }
        
        $contattoNome = $quota['contatto_nome'] ?: '';
        $contattoTel = $quota['contatto_telefono'] ?: '';
        $contattoEmail = $quota['contatto_email'] ?: '';
        if ($contattoNome) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Alla c.a.: ' . $contattoNome, $fontBold);
        }
        if ($contattoTel || $contattoEmail) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Tel: ' . $contattoTel . ' | Email: ' . $contattoEmail, $fontNormal);
        }

        $section->addTextBreak(1);

        $titoloRighe = !empty($quota['oggetto']) ? $quota['oggetto'] : 'Articoli Richiesti';

        // BLOCK 2: Righe Articoli
        $section->addText($titoloRighe, $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        
        $tableGrid = $section->addTable(['cellMargin' => 80]);
        $thStyle = ['bgColor' => '1A5276', 'borderSize' => 6, 'borderColor' => '1A5276'];
        $thText = ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => 'FFFFFF'];

        $tableGrid->addRow();
        $tableGrid->addCell(1600, $thStyle)->addText('SKU', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(3240, $thStyle)->addText('Descrizione', $thText);
        $tableGrid->addCell(1600, $thStyle)->addText('Confezione', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1000, $thStyle)->addText('Q.tà', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(600, $thStyle)->addText('UdM', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1200, $thStyle)->addText('Prezzo Unit.', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(1300, $thStyle)->addText('Totale', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $fill = false;
        foreach ($righe as $r) {
            $rowBg = $fill ? 'F5F7FA' : 'FFFFFF';
            $tdStyle = ['bgColor' => $rowBg, 'borderBottomSize' => 4, 'borderBottomColor' => 'CCCCCC'];
            
            $qty = (float)$r['quantita'];
            $um = $r['unita_misura'] ?: 'pz';
            $pu = (float)$r['prezzo_unitario'];
            $sconto = (float)$r['sconto_perc'];
            $rigaTotale = (float)$r['imponibile_riga'];

            $prezzoStr = fmt_money($pu);
            if ($sconto > 0) {
                $prezzoStr .= " (Sconto {$sconto}%)";
            }

            $tableGrid->addRow();
            $tableGrid->addCell(1600, $tdStyle)->addText($r['sku'] ?: '—', $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(3240, $tdStyle)->addText($r['descrizione'], $fontNormal);
            $tableGrid->addCell(1600, $tdStyle)->addText($r['quantita_confezione'] ?: '—', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1000, $tdStyle)->addText(fmt_qty($qty), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(600, $tdStyle)->addText($um, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1200, $tdStyle)->addText($prezzoStr, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(1300, $tdStyle)->addText(fmt_money($rigaTotale), $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            $fill = !$fill;
        }

        $section->addTextBreak(1);

        $tableTotals = $section->addTable();
        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale Imponibile:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($imponibile), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        if ($sconto_globale_perc > 0) {
            $tableTotals->addRow();
            $tableTotals->addCell(7540);
            $tableTotals->addCell(1800)->addText("Sconto Globale ({$sconto_globale_perc}%):", $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableTotals->addCell(1200)->addText('Sconto applicato', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        }

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale I.V.A.:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($totale_iva), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale Documento:', $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($totale), $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        // BLOCK 3: Condizioni
        $section->addText('Condizioni di Vendita', $fontTitle, ['spaceBefore' => 150, 'spaceAfter' => 100]);
        
        $consegnaIndirizzo = '—';
        if (!empty($quota['consegna_via'])) {
            $consegnaIndirizzo = ($quota['consegna_presso'] ?: $quota['cliente_ragione_sociale']) . ' - ' .
                                 $quota['consegna_via'] . ' ' . $quota['consegna_civico'] . ', ' .
                                 $quota['consegna_cap'] . ' ' . $quota['consegna_citta'] . ' (' . $quota['consegna_provincia'] . ')';
        }

        $condTable = $section->addTable(['cellMargin' => 80]);
        
        $condTable->addRow();
        $condTable->addCell(2500)->addText('Modalità Pagamento:', $fontBold);
        $condTable->addCell(8040)->addText($quota['condizioni_pagamento'] ?: 'Come da accordi', $fontNormal);

        $condTable->addRow();
        $condTable->addCell(2500)->addText('Trasporto / Consegna:', $fontBold);
        $condTable->addCell(8040)->addText($quota['trasporto'] ?: '—', $fontNormal);

        $condTable->addRow();
        $condTable->addCell(2500)->addText('Luogo di Destinazione:', $fontBold);
        $condTable->addCell(8040)->addText($consegnaIndirizzo, $fontNormal);

        if (!empty($quota['consegna_note'])) {
            $condTable->addRow();
            $condTable->addCell(2500)->addText('Note Consegna:', $fontBold);
            $condTable->addCell(8040)->addText($quota['consegna_note'], $fontNormal);
        }

        if (!empty($quota['note_cliente'])) {
            $condTable->addRow();
            $condTable->addCell(2500)->addText('Note aggiuntive:', $fontBold);
            $condTable->addCell(8040)->addText($quota['note_cliente'], $fontNormal);
        }

        // BLOCK 4: Riquadri firma/timbro
        $section->addTextBreak(2);
        $firmaTable = $section->addTable(['cellMargin' => 120]);
        
        // Row 1: Labels
        $firmaTable->addRow();
        $cellLabelLeft = $firmaTable->addCell(4770);
        $cellLabelLeft->addText('ACCETTAZIONE PER ORDINE', ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444']);
        $cellLabelLeft->addText('Timbro e Firma leggibile del Cliente', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777']);
        
        $firmaTable->addCell(1000); // spacer
        
        $cellLabelRight = $firmaTable->addCell(4770);
        $aziendaNome = !empty($azienda['ragione_sociale']) ? $azienda['ragione_sociale'] : 'Azienda';
        $cellLabelRight->addText(mb_strtoupper($aziendaNome, 'UTF-8'), ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $cellLabelRight->addText('Firma Direzione Commerciale', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        // Row 2: Stamp/Signature Boxes
        $firmaTable->addRow(1700, ['exactHeight' => true, 'cantSplit' => true]);
        
        $cellStyle = [
            'borderSize' => 6,
            'borderColor' => 'CCCCCC',
            'bgColor' => 'FAFAFA',
            'valign' => 'bottom',
        ];
        
        $cellBoxLeft = $firmaTable->addCell(4770, $cellStyle);
        $cellBoxLeft->addTextBreak(2);
        $cellBoxLeft->addText('____________________________', ['name' => 'Arial', 'size' => 8, 'color' => 'BBBBBB']);
        
        $firmaTable->addCell(1000); // spacer
        
        $cellBoxRight = $firmaTable->addCell(4770, $cellStyle);
        $cellBoxRight->addTextBreak(2);
        $cellBoxRight->addText('____________________________', ['name' => 'Arial', 'size' => 8, 'color' => 'BBBBBB'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $filename = "Preventivo_" . str_replace('/', '_', $quota['numero_preventivo']) . ".docx";
        $wordHelper->streamDownload($filename);
        exit;
    }
}

