<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';

final class DashboardController extends BaseController
{
    public function index(): void
    {
        // 1. Operational KPIs

        // KPI 1: Active customer orders (to fulfill)
        $ordini_clienti_evadere = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ordini_clienti WHERE stato IN ('in_lavorazione', 'parzialmente_spedito')"
        )->fetchColumn();

        // KPI 2: Shipments in preparation
        $spedizioni_preparazione = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM spedizioni WHERE stato = 'preparazione'"
        )->fetchColumn();

        // KPI 3: Understock Warning — nel magazzino attivo (soglia per coppia
        // prodotto-magazzino da quando prodotti.scorta_minima è stata rimossa,
        // vedi Piano Multi-Magazzino §3.5).
        $stSottoscorta = $this->pdo->prepare("
            SELECT COUNT(*) FROM prodotti p
            JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
        ");
        $stSottoscorta->execute([qk_magazzino_attivo_id($this->pdo)]);
        $sottoscorta_count = (int) $stSottoscorta->fetchColumn();

        // KPI 4: Active supplier orders (incoming goods)
        $acquisti_in_arrivo = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')"
        )->fetchColumn();

        // KPI 5: Current Month Turnover
        $current_month = date('Y-m');
        $orders_data = $this->pdo->query("SELECT id, data_ordine FROM ordini_clienti WHERE stato != 'annullato'")->fetchAll(PDO::FETCH_ASSOC);
        $monthly_turnover = 0.0;
        foreach ($orders_data as $od) {
            if (str_starts_with($od['data_ordine'], $current_month)) {
                $tot_order = (float)$this->pdo->query("SELECT SUM(quantita_ordinata * prezzo_unitario) FROM ordini_clienti_righe WHERE ordine_id = " . (int)$od['id'])->fetchColumn();
                $monthly_turnover += $tot_order;
            }
        }

        // Combine into KPI array
        $kpi = [
            [
                'icon'  => 'box-open',
                'color' => 'accent',
                'value' => (string) $ordini_clienti_evadere,
                'label' => 'Ordini Clienti da Evadere',
            ],
            [
                'icon'  => 'truck-ramp-box',
                'color' => 'info',
                'value' => (string) $spedizioni_preparazione,
                'label' => 'Spedizioni in Preparazione',
            ],
            [
                'icon'  => 'triangle-exclamation',
                'color' => $sottoscorta_count > 0 ? 'danger' : 'success',
                'value' => (string) $sottoscorta_count,
                'label' => 'Prodotti Sottoscorta',
            ],
            [
                'icon'  => 'dolly',
                'color' => 'warning',
                'value' => (string) $acquisti_in_arrivo,
                'label' => 'Acquisti in Arrivo',
            ],
            [
                'icon'  => 'coins',
                'color' => 'success',
                'value' => '€ ' . number_format($monthly_turnover, 2, ',', '.'),
                'label' => 'Fatturato Mese',
            ],
        ];

        // 2. Recent Sales Orders
        $stmtSO = $this->pdo->query(
            "SELECT o.id, o.numero_ordine, o.stato, o.data_ordine,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                    (SELECT COALESCE(SUM(quantita_ordinata * prezzo_unitario), 0.0) FROM ordini_clienti_righe WHERE ordine_id = o.id) AS totale
             FROM ordini_clienti o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             ORDER BY o.created_at DESC
             LIMIT 5"
        );
        $recent_sales_orders = $stmtSO->fetchAll(PDO::FETCH_ASSOC);

        // 3. Recent Purchase Orders
        $stmtPO = $this->pdo->query(
            "SELECT ot.id, ot.numero, ot.stato, ot.data_ordine, ot.totale,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
             FROM ordini_testata ot
             JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
             ORDER BY ot.created_at DESC
             LIMIT 5"
        );
        $recent_purchase_orders = $stmtPO->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/dashboard/_index.php', [
            'page_title'             => 'Dashboard',
            'page_subtitle'          => 'Panoramica operativa — ' . date('d/m/Y'),
            'kpi'                    => $kpi,
            'recent_sales_orders'    => $recent_sales_orders,
            'recent_purchase_orders' => $recent_purchase_orders,
        ]);
    }
}

