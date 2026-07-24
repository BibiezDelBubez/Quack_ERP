<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/sales_orders.php';
require_once INCLUDES_DIR . '/tabelle_base.php';
require_once INCLUDES_DIR . '/sales_quotes.php';
require_once INCLUDES_DIR . '/compliance.php';
require_once INCLUDES_DIR . '/impostazioni.php';

final class SalesOrdersController extends BaseController
{

    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');
        $customerId = get_param('customer_id', '');

        $customerName = '';
        if ($customerId) {
            $customerName = qk_get_nome_soggetto_by_id($this->pdo, (int)$customerId) ?: '';
        }

        $sortWhitelist = ['numero', 'data', 'stato', 'cliente', 'consegna', 'totale'];
        $pag = qk_pagination_params($sortWhitelist, 'data', 'desc');

        $sortMap = [
            'numero'   => 'o.numero_ordine',
            'data'     => 'o.data_ordine',
            'stato'    => 'o.stato',
            'cliente'  => 'cf.ragione_sociale',
            'consegna' => 'o.data_consegna_prevista',
            'totale'   => "(SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario * (1 - r.sconto_perc/100.0)),0) FROM ordini_clienti_righe r WHERE r.ordine_id = o.id)",
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

        $total  = qk_ordini_clienti_count($this->pdo, $whereStr, $params);
        $orders = qk_ordini_clienti_list($this->pdo, $whereStr, $params, $orderCol, (string)$pag['dir'], (int)$pag['limit'], (int)$pag['offset']);

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
                    // Compliance checks (REVISIONE Ciclo Vendite §1.6/§3bis.O1): un
                    // ordine diretto non passa mai da un preventivo, quindi senza
                    // questo controllo il blocco compliance già applicato in
                    // SalesQuotesController veniva completamente aggirato creando
                    // l'ordine da qui invece che da un preventivo. Un ordine diretto
                    // non ha un concetto di "bozza" (nasce già 'in_lavorazione' e
                    // riserva stock subito): sempre bloccante, mai solo avviso.
                    $violations = qk_compliance_check_sale($this->pdo, (int)$values['cliente_id'], $righe);
                    if (!empty($violations)) {
                        $violation_msgs = [];
                        foreach ($violations as $v) {
                            $violation_msgs[] = $v['message'];
                        }
                        $customer_url = url('customers/edit?id=' . $values['cliente_id']);
                        $errors['_global'] = "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='qk-alert-link'>Gestisci autorizzazioni cliente →</a>";
                    }
                }

                if (empty($errors)) {
                    try {
                        $userId   = (int)(current_user()['id'] ?? 0);
                        // AUDIT 3 §2.2: checkbox già presente nel form, mai letta finora.
                        qk_maybe_create_one_time_address($this->pdo, $values);
                        $ordineId = qk_insert_ordine_diretto($this->pdo, $values, $righe, $userId);
                        qk_compliance_log_transactions($this->pdo, $ordineId, $righe);
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

        // AUDIT 3 §2.1: qk_aziende_list_con_default() (includes/anagrafiche.php) —
        // stessa funzione condivisa ora usata anche da SalesQuotesController::create(),
        // niente più la stessa logica a 3 livelli copiata a mano nei due controller.
        ['aziende' => $aziende, 'default_id' => $defaultAziendaId] = qk_aziende_list_con_default($this->pdo);
        if (empty($values['azienda_interna_id'])) {
            $values['azienda_interna_id'] = $defaultAziendaId;
        }

        $listini = qk_get_listini_vendita_attivi($this->pdo);

        // PIANO - Soglie Margine Configurabili (§1.4): variabili locali,
        // propagate automaticamente a _create.php da get_defined_vars() più
        // sotto — stesso motivo di SalesQuotesController::create()/edit().
        // SalesOrdersController::edit() non le riceve: il box margine esiste
        // solo nel form di creazione (vedi §4.5 del piano).
        $margineSogliaVerde  = (float) get_impostazione($this->pdo, 'margine_soglia_verde', '20.00');
        $margineSogliaGiallo = (float) get_impostazione($this->pdo, 'margine_soglia_giallo', '10.00');

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
            'listino_id'              => qk_get_listino_default_id($this->pdo) ?? '', // listino marcato is_default=1
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
            'listino_id'              => $s('listino_id', (string)(qk_get_listino_default_id($this->pdo) ?? '')),
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
            'salva_in_anagrafica_indirizzo' => $s('salva_in_anagrafica_indirizzo'),
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

        $cliente = qk_get_cliente_dettagli_per_preventivo($this->pdo, $clienteId);
        if (!$cliente) { echo json_encode(['ok' => false]); exit; }

        $indirizzi = qk_get_indirizzi_cliente($this->pdo, $clienteId);
        $contatti  = qk_get_contatti_cliente($this->pdo, $clienteId);

        echo json_encode([
            'ok'        => true,
            'cliente'   => [
                'condizioni_pagamento' => $cliente['condizioni_pagamento'] ?? '',
                'listino_id'           => $cliente['listino_id'] !== null ? (int)$cliente['listino_id'] : qk_get_listino_default_id($this->pdo)
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
            // Corretto (Audit 2, V2 — bug identico a quello già corretto per apiCreaProdotto()
            // poco sotto in questo stesso file): l'INSERT a mano ometteva la colonna NOT NULL
            // UNIQUE "codice" di clienti_fornitori, fallendo sempre. Riusa la funzione condivisa
            // già corretta qk_crea_cliente_rapido() (includes/sales_quotes.php), invece di
            // reinventare un secondo INSERT indipendente per lo stesso identico scopo — la
            // funzione condivisa restituisce solo l'id, quindi il resto della risposta JSON
            // (label/email/telefono) resta assemblato qui, identico a prima.
            $id = qk_crea_cliente_rapido($this->pdo, [
                'tipo_soggetto'   => $tipo,
                'ragione_sociale' => $ragione,
                'nome'            => $nome,
                'cognome'         => $cognome,
                'email'           => $email,
                'telefono'        => $telefono,
            ]);
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

            $prodInfo = qk_get_prodotto_stock_info($this->pdo, $id);
            $um_codice = !empty($prodInfo['unita_misura_stock_id']) ? (qk_get_unita_misura_codice($this->pdo, (int)$prodInfo['unita_misura_stock_id']) ?: $umCod) : $umCod;

            echo json_encode([
                'success' => true,
                'id' => $id,
                'label' => $nome,
                'sku' => $prodInfo['sku_interno'] ?? $sku,
                'unita_misura' => $um_codice,
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

        $order = qk_get_ordine_cliente_dettaglio($this->pdo, $id);
        if (!$order) { redirect_with('sales/orders', 'error', 'Ordine non trovato.'); }

        $address = null;
        if (!empty($order['indirizzo_spedizione_id'])) {
            $address = qk_get_indirizzo($this->pdo, (int)$order['indirizzo_spedizione_id']);
        }

        $lines     = qk_get_ordine_cliente_righe($this->pdo, $id);
        $shipments = qk_get_ordine_cliente_spedizioni($this->pdo, $id);

        $photosList = qk_get_ordine_cliente_foto_spedizioni($this->pdo, $id);
        $shipment_photos = [];
        foreach ($photosList as $ph) {
            $shipment_photos[(int)$ph['spedizione_id']][] = $ph;
        }

        $ddts = qk_get_ordine_cliente_ddt($this->pdo, $id);

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
                    qk_ordine_cliente_segna_fatturato($this->pdo, $id);
                    flash_set('success', 'Ordine segnato come fatturato.');
                    break;
                case 'segna_da_fatturare':
                    qk_ordine_cliente_segna_da_fatturare($this->pdo, $id);
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
        $lotti = qk_get_lotti_disponibili_prodotto($this->pdo, $prodottoId, $magazzinoId, $includiScaduti);
        echo json_encode(['lotti' => $lotti]);
        exit;
    }

    private function elaboraCreaSpedizione(int $orderId): void
    {
        try {
            $userId = (int)(current_user()['id'] ?? 0);
            $res = qk_elabora_crea_spedizione($this->pdo, $orderId, $_POST, $_FILES, $userId);
            flash_set('success', "Spedizione {$res['num_sped']} e DDT {$res['num_ddt']} registrati correttamente.");
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('error', $e->getMessage());
        }
    }
}
