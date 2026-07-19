<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';

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

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM spedizioni s
               JOIN ordini_clienti o  ON o.id = s.ordine_id
               JOIN clienti_fornitori cf ON cf.id = o.cliente_id
              WHERE $whereStr"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $this->pdo->prepare(
            "SELECT s.id, s.numero_spedizione, s.data_spedizione, s.stato, s.vettore, s.tracking,
                    s.ordine_id, o.numero_ordine,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                    d.id AS ddt_id, d.numero_documento_trasporto
               FROM spedizioni s
               JOIN ordini_clienti o  ON o.id = s.ordine_id
               JOIN clienti_fornitori cf ON cf.id = o.cliente_id
          LEFT JOIN documenti_trasporto d ON d.spedizione_id = s.id
              WHERE $whereStr
              ORDER BY $orderCol {$pag['dir']}
              LIMIT {$pag['limit']} OFFSET {$pag['offset']}"
        );
        $listStmt->execute($params);
        $shipments = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        $base          = url('sales/shipments');
        $page_title    = 'Spedizioni';
        $page_subtitle = 'Registro delle spedizioni e DDT emessi';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Spedizioni']];
        $active_tab    = 'shipments';

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_list.php', get_defined_vars());
    }

    /**
     * GET  /sales/shipments/new?order_id=X — pagina pick & pack dedicata
     * POST /sales/shipments/new             — crea spedizione (delega a SalesOrdersController::elaboraCreaSpedizione)
     */
    public function nuova(): void
    {
        $orderId = (int)get_param('order_id');
        if ($orderId <= 0) redirect(url('sales/orders'));

        // Carica ordine
        $stOrd = $this->pdo->prepare("
            SELECT o.*,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                   cf.email AS cliente_email,
                   cf.telefono AS cliente_telefono
            FROM ordini_clienti o
            JOIN clienti_fornitori cf ON cf.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stOrd->execute([$orderId]);
        $order = $stOrd->fetch(PDO::FETCH_ASSOC);

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
        $stLines = $this->pdo->prepare("
            SELECT r.*,
                   p.sku_interno AS prodotto_codice,
                   p.nome        AS prodotto_nome,
                   p.traccia_lotti,
                   um.codice     AS uom_simbolo,
                   (r.quantita_ordinata - r.quantita_spedita) AS qta_residua
            FROM ordini_clienti_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.ordine_id = ?
              AND (r.quantita_ordinata - r.quantita_spedita) > 0.0005
            ORDER BY r.id ASC
        ");
        $stLines->execute([$orderId]);
        $righe = $stLines->fetchAll(PDO::FETCH_ASSOC);

        if (empty($righe)) {
            flash_set('error', 'Tutte le righe di questo ordine sono già state spedite.');
            redirect(url('sales/orders/edit') . '?id=' . $orderId);
        }

        // Magazzino di spedizione: campo esplicito sulla pagina (Revisione Gestione
        // Magazzino e Lotti §3), precompilato col magazzino attivo di sessione ma
        // sempre scelto/confermato dall'operatore — non più un'approssimazione
        // silenziosa (vedi Magazzino Attivo - Analisi e Alternative.md, Fix 1, stesso
        // pattern già applicato a "Nuovo movimento"). Se il parametro passato non
        // corrisponde a un magazzino attivo esistente, si ricade sul default.
        $magazzini = qk_magazzini_list($this->pdo, true);
        $magazzinoParam = get_param('magazzino_id', '');
        if ($magazzinoParam !== '' && in_array((int)$magazzinoParam, array_column($magazzini, 'id'), true)) {
            $magazzinoSpedId = (int) $magazzinoParam;
        } else {
            $magazzinoSpedId = qk_magazzino_attivo_id($this->pdo);
        }

        // Carica lotti disponibili per tutti i prodotti con traccia_lotti in un solo query.
        // Lotti scaduti (Tappa 4.4): esclusi per default dalla pre-allocazione FEFO — non
        // ha senso proporre in automatico un lotto scaduto per una spedizione. L'utente può
        // includerli esplicitamente con il toggle "Includi lotti scaduti" (?includi_scaduti=1),
        // pensato per casi eccezionali (es. smaltimento/reso) — mai come default silenzioso.
        $includiScaduti = get_param('includi_scaduti', '0') === '1';
        $prodIds = array_unique(array_filter(array_column($righe, 'prodotto_id')));
        $lottiPerProdotto = [];
        if (!empty($prodIds)) {
            $in = implode(',', array_map('intval', $prodIds));
            $condScadenza = $includiScaduti ? '' : "AND (l.data_scadenza IS NULL OR l.data_scadenza >= DATE('now'))";
            $stLotti = $this->pdo->prepare("
                SELECT l.prodotto_id,
                       l.id,
                       l.codice_lotto_interno AS codice,
                       lg.quantita_disponibile,
                       l.data_scadenza,
                       CASE WHEN l.data_scadenza IS NOT NULL AND l.data_scadenza < DATE('now') THEN 1 ELSE 0 END AS scaduto
                FROM lotti l
                JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
                WHERE l.prodotto_id IN ($in)
                  AND lg.quantita_disponibile > 0
                  $condScadenza
                ORDER BY l.prodotto_id,
                         CASE WHEN l.data_scadenza IS NULL THEN 1 ELSE 0 END,
                         l.data_scadenza ASC,
                         l.created_at ASC
            ");
            $stLotti->execute([$magazzinoSpedId]);
            foreach ($stLotti->fetchAll(PDO::FETCH_ASSOC) as $lot) {
                $lottiPerProdotto[$lot['prodotto_id']][] = $lot;
            }
        }

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

        $st = $this->pdo->prepare("
            SELECT d.*,
                   s.numero_spedizione, s.vettore AS s_vettore, s.tracking, s.magazzino_id AS spedizione_magazzino_id,
                   o.numero_ordine,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS destinatario_nome,
                   cf.partita_iva AS destinatario_piva,
                   cf.codice_fiscale AS destinatario_cf,
                   a.via, a.civico, a.cap, a.citta, a.provincia, a.nazione, a.nome_sede
            FROM documenti_trasporto d
            JOIN ordini_clienti o ON o.id = d.ordine_id
            JOIN clienti_fornitori cf ON cf.id = d.destinatario_id
            LEFT JOIN indirizzi a ON a.id = d.indirizzo_destinazione_id
            LEFT JOIN spedizioni s ON s.id = d.spedizione_id
            WHERE d.id = ?
        ");
        $st->execute([$id]);
        $ddt = $st->fetch(PDO::FETCH_ASSOC);

        if (!$ddt) {
            flash_set('error', 'DDT non trovato.');
            redirect(url('sales/shipments'));
        }

        // Mittente: letto dallo snapshot congelato alla creazione del DDT
        // (Revisione Gestione Magazzino e Lotti §3), mai ricalcolato dal vivo — fallback
        // dal magazzino di spedizione solo per DDT legacy privi delle colonne mittente_*.
        $mittente = qk_resolve_mittente_ddt_stampa($this->pdo, $ddt, $ddt['spedizione_magazzino_id'] ? (int)$ddt['spedizione_magazzino_id'] : null);

        $stRighe = $this->pdo->prepare("
            SELECT r.*,
                   p.nome AS prodotto_nome, p.sku_interno AS prodotto_codice,
                   um.codice AS uom_codice
            FROM documenti_trasporto_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.documento_trasporto_id = ?
            ORDER BY r.posizione_riga ASC
        ");
        $stRighe->execute([$id]);
        $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);

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
