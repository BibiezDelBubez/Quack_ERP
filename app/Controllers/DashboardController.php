<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/dashboard.php';

final class DashboardController extends BaseController
{
    public function index(): void
    {
        // 1. Operational KPIs

        // KPI 1: Active customer orders (to fulfill)
        $ordini_clienti_evadere = get_kpi_ordini_clienti_evadere($this->pdo);

        // KPI 2: Shipments in preparation
        $spedizioni_preparazione = get_kpi_spedizioni_preparazione($this->pdo);

        // KPI 3: Understock Warning — nel magazzino attivo (soglia per coppia
        // prodotto-magazzino da quando prodotti.scorta_minima è stata rimossa,
        // vedi Piano Multi-Magazzino §3.5).
        $magazzinoAttivoId = qk_magazzino_attivo_id($this->pdo);
        $sottoscorta_count = get_kpi_prodotti_sottoscorta($this->pdo, $magazzinoAttivoId);

        // Fix 4 (§8.5): nome della sede a cui si riferisce il KPI, così non
        // viene letto per errore come dato aziendale aggregato.
        $magazzinoAttivoNome = get_kpi_nome_magazzino($this->pdo, $magazzinoAttivoId);

        // KPI 4: Active supplier orders (incoming goods)
        $acquisti_in_arrivo = get_kpi_acquisti_in_arrivo($this->pdo);

        // KPI 5: Current Month Turnover
        // Nota valute: tutti gli importi vengono sommati indistintamente,
        // senza conversione/isolamento per valuta. Deliberatamente rimandato
        // a una futura versione (vedi docs/Backlog Sviluppi Futuri.md).
        $current_month = date('Y-m');
        $monthly_turnover = get_kpi_fatturato_mese($this->pdo, $current_month);

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
                'sublabel' => 'Magazzino: ' . $magazzinoAttivoNome,
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
        $recent_sales_orders = get_dashboard_recent_sales_orders($this->pdo, 5);

        // 3. Recent Purchase Orders
        $recent_purchase_orders = get_dashboard_recent_purchase_orders($this->pdo, 5);

        $this->page(APP_ROOT_DIR . '/app/views/dashboard/_index.php', [
            'page_title'             => 'Dashboard',
            'page_subtitle'          => 'Panoramica operativa — ' . date('d/m/Y'),
            'kpi'                    => $kpi,
            'recent_sales_orders'    => $recent_sales_orders,
            'recent_purchase_orders' => $recent_purchase_orders,
        ]);
    }
}

