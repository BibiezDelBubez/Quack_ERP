<?php

declare(strict_types=1);

final class SalesController extends BaseController
{
    public function dashboard(): void
    {
        require_once INCLUDES_DIR . '/sales_dashboard.php';

        // 1. KPI Counts
        $quotes_bozza = get_kpi_sales_quotes_bozza($this->pdo);
        $quotes_inviati = get_kpi_sales_quotes_inviati($this->pdo);
        $orders_attivi = get_kpi_sales_orders_attivi($this->pdo);

        // 2. Recent quotes
        $recent_quotes = get_recent_sales_quotes_for_dashboard($this->pdo, 5);

        // 3. Recent orders
        $recent_orders = get_recent_sales_orders_for_dashboard($this->pdo, 5);

        // View vars
        $active_tab    = 'dashboard';
        $page_title    = 'Vendite';
        $page_subtitle = 'Panoramica ciclo vendite e fatturazione';
        $breadcrumbs   = [['label' => 'Vendite']];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/sales/sales_dashboard.php';
        require INCLUDES_DIR . '/footer.php';
    }
}
