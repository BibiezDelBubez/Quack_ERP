<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/document_common.php';
require_once INCLUDES_DIR . '/sales_orders.php';

final class SpedizioniController extends BaseController
{
    /**
     * GET /sales/shipments — lista globale spedizioni
     */
    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');

        $sortWhitelist = ['numero', 'data', 'stato', 'cliente', 'vettore'];
        $pag = qk_pagination_params($sortWhitelist, 'data', 'desc');

        $sortMap = [
            'numero'  => 's.numero_spedizione',
            'data'    => 's.data_spedizione',
            'stato'   => 's.stato',
            'cliente' => 'cf.ragione_sociale',
            'vettore' => 's.vettore',
        ];
        $orderCol = $sortMap[$pag['sort']] ?? 's.data_spedizione';

        $where = ['1=1'];
        $params = [];
        if ($search) {
            $where[] = "(s.numero_spedizione LIKE :q OR o.numero_ordine LIKE :q OR cf.ragione_sociale LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($stato) { $where[] = "s.stato = :stato";             $params[':stato'] = $stato; }
        if ($dal)   { $where[] = "s.data_spedizione >= :dal";    $params[':dal']   = $dal; }
        if ($al)    { $where[] = "s.data_spedizione <= :al";     $params[':al']    = $al; }
        $whereStr = implode(' AND ', $where);

        $total     = qk_spedizioni_count($this->pdo, $whereStr, $params);
        $shipments = qk_spedizioni_list($this->pdo, $whereStr, $params, $orderCol, (string)$pag['dir'], (int)$pag['limit'], (int)$pag['offset']);

        $base          = url('sales/shipments');
        $page_title    = 'Spedizioni';
        $page_subtitle = 'Registro delle spedizioni e DDT emessi';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Spedizioni']];
        $active_tab    = 'shipments';

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_list.php', get_defined_vars());
    }

    /**
     * GET /sales/shipments/new?order_id=X — pagina pick & pack dedicata
     * Nota: il form di invio fa POST a sales/orders/action (gestito da SalesOrdersController::action)
     */
    public function nuova(): void
    {
        $orderId = (int)get_param('order_id');
        if ($orderId <= 0) redirect(url('sales/orders'));

        // Carica ordine
        $order = qk_get_ordine_per_spedizione($this->pdo, $orderId);

        if (!$order) {
            flash_set('error', 'Ordine non trovato.');
            redirect(url('sales/orders'));
        }

        // Blocca solo gli stati terminali
        if (in_array($order['stato'], ['annullato', 'completato'])) {
            flash_set('error', 'Impossibile creare spedizioni per un ordine ' . $order['stato'] . '.');
            redirect(url('sales/orders/edit') . '?id=' . $orderId);
        }

        // Carica righe con residuo da spedire
        $righe = qk_get_righe_ordine_da_spedire($this->pdo, $orderId);

        if (empty($righe)) {
            flash_set('error', 'Tutte le righe di questo ordine sono già state spedite.');
            redirect(url('sales/orders/edit') . '?id=' . $orderId);
        }

        // Magazzino di spedizione: campo esplicito sulla pagina (Revisione Gestione
        // Magazzino e Lotti §3), sempre scelto/confermato dall'operatore — non più
        // un'approssimazione silenziosa. AUDIT 3 §3.1: il default NON deve più essere
        // il magazzino attivo di sessione (che può essere cambiato tra la creazione
        // dell'ordine e la spedizione, lasciando la riserva "fantasma" nel magazzino
        // originale) — deve essere il magazzino congelato sull'ordine
        // (ordini_clienti.magazzino_id), lo stesso dove la riserva è stata scritta.
        // Se l'operatore lo cambia comunque (merce non più lì), elaboraCreaSpedizione()
        // gestisce il rilascio riserva sul magazzino originale separatamente dallo
        // scarico fisico sul magazzino scelto qui (vedi fix in quel metodo).
        $magazzini = qk_magazzini_list($this->pdo, true);
        $magazzinoParam = get_param('magazzino_id', '');
        if ($magazzinoParam !== '' && in_array((int)$magazzinoParam, array_column($magazzini, 'id'), true)) {
            $magazzinoSpedId = (int) $magazzinoParam;
        } elseif (!empty($order['magazzino_id']) && in_array((int)$order['magazzino_id'], array_column($magazzini, 'id'), true)) {
            $magazzinoSpedId = (int) $order['magazzino_id'];
        } else {
            // Ordine legacy senza magazzino_id congelato (creato prima della Tappa
            // che ha introdotto la colonna): unico caso in cui si ricade ancora sul
            // magazzino attivo di sessione.
            $magazzinoSpedId = qk_magazzino_attivo_id($this->pdo);
        }

        // Carica lotti disponibili per tutti i prodotti con traccia_lotti in un solo query.
        // Lotti scaduti (Tappa 4.4): esclusi per default dalla pre-allocazione FEFO — non
        // ha senso proporre in automatico un lotto scaduto per una spedizione. L'utente può
        // includerli esplicitamente con il toggle "Includi lotti scaduti" (?includi_scaduti=1),
        // pensato per casi eccezionali (es. smaltimento/reso) — mai come default silenzioso.
        $includiScaduti = get_param('includi_scaduti', '0') === '1';
        $prodIds = array_unique(array_filter(array_column($righe, 'prodotto_id')));
        $lottiPerProdotto = qk_get_lotti_disponibili_per_prodotti($this->pdo, $prodIds, $magazzinoSpedId, $includiScaduti);

        $page_title  = 'Nuova Spedizione — ' . $order['numero_ordine'];
        $breadcrumbs = [
            ['label' => 'Vendite',   'url' => url('sales')],
            ['label' => 'Ordini',    'url' => url('sales/orders')],
            ['label' => $order['numero_ordine'], 'url' => url('sales/orders/edit') . '?id=' . $orderId],
            ['label' => 'Nuova Spedizione'],
        ];
        $extra_css = ['sales-shipment-new.css'];
        $extra_js  = ['sales-shipment-new.js'];

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_nuova.php', get_defined_vars());
    }

    /**
     * GET /sales/shipments/ddt/view?id=X — vista/stampa DDT
     * id = documenti_trasporto.id
     */
    public function ddtView(): void
    {
        $id = (int)get_param('id');
        if ($id <= 0) redirect(url('sales/shipments'));

        $ddt = qk_get_ddt_dettaglio($this->pdo, $id);

        if (!$ddt) {
            flash_set('error', 'DDT non trovato.');
            redirect(url('sales/shipments'));
        }

        // Mittente: letto dallo snapshot congelato alla creazione del DDT
        // (Revisione Gestione Magazzino e Lotti §3), mai ricalcolato dal vivo — fallback
        // dal magazzino di spedizione solo per DDT legacy privi delle colonne mittente_*.
        $mittente = qk_resolve_mittente_ddt_stampa($this->pdo, $ddt, $ddt['spedizione_magazzino_id'] ? (int)$ddt['spedizione_magazzino_id'] : null);

        // Destinatario: stesso principio del mittente sopra (Revisione Ciclo Vendite
        // §1.5, PIANO - Congelare Destinatario sul DDT.md) — legge lo snapshot
        // congelato alla creazione, mai ricalcolato dal vivo, fallback dal vivo solo
        // per DDT legacy privi delle colonne destinatario_*.
        $destinatario = qk_resolve_destinatario_ddt_stampa($this->pdo, $ddt);

        $righe = qk_get_ddt_righe($this->pdo, $id);

        $page_title  = 'DDT ' . $ddt['numero_documento_trasporto'];
        $breadcrumbs = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Spedizioni', 'url' => url('sales/shipments')],
            ['label' => $ddt['numero_documento_trasporto']],
        ];
        $extra_css = ['sales-ddt.css'];

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_ddt_view.php', get_defined_vars());
    }
}
