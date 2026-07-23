<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/sales_quotes.php';
require_once INCLUDES_DIR . '/tabelle_base.php';
require_once INCLUDES_DIR . '/compliance.php';
require_once APP_ROOT_DIR . '/app/Services/SalesQuoteDocumentService.php';

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

        // AUDIT 3 §2.1: qk_aziende_list_con_default() (includes/anagrafiche.php) —
        // stessa funzione condivisa ora usata anche da SalesOrdersController::create(),
        // niente più la stessa logica a 3 livelli copiata a mano nei due controller.
        ['aziende' => $aziende, 'default_id' => $defaultAziendaId] = qk_aziende_list_con_default($this->pdo);
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

        $service  = new \App\Services\SalesQuoteDocumentService();
        $pdfBytes = $service->generatePdf($dati);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Preventivo_' . str_replace('/', '_', $dati['quota']['numero_preventivo']) . '.pdf"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
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

        $service  = new \App\Services\SalesQuoteDocumentService();
        $tempPath = $service->generateDocx($dati);
        $filename = "Preventivo_" . str_replace('/', '_', $dati['quota']['numero_preventivo']) . ".docx";

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempPath));
        readfile($tempPath);
        @unlink($tempPath);
        exit;
    }
}

