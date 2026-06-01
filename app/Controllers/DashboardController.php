<?php

declare(strict_types=1);

class DashboardController extends BaseController
{
    public function index(): void
    {
        // TODO: sostituire con query reali al DB
        $kpi = [
            ['icon' => 'box-open',             'color' => 'accent',  'value' => '—', 'label' => 'Ordini aperti',        'trend' => null],
            ['icon' => 'file-invoice',         'color' => 'warning', 'value' => '—', 'label' => 'Offerte in sospeso',   'trend' => null],
            ['icon' => 'users',                'color' => 'info',    'value' => '—', 'label' => 'Clienti attivi',       'trend' => null],
            ['icon' => 'warehouse',            'color' => 'success', 'value' => '—', 'label' => 'Prodotti a magazzino', 'trend' => null],
            ['icon' => 'triangle-exclamation', 'color' => 'danger',  'value' => '—', 'label' => 'Prodotti ADR',         'trend' => null],
            ['icon' => 'cart-shopping',        'color' => 'accent',  'value' => '—', 'label' => 'Fatturato mese',       'trend' => null],
        ];

        $recent_orders = []; // TODO: query DB

        $this->page(APP_ROOT_DIR . '/app/views/dashboard/_index.php', [
            'page_title'    => 'Dashboard',
            'page_subtitle' => 'Panoramica operativa — ' . date('d/m/Y'),
            'kpi'           => $kpi,
            'recent_orders' => $recent_orders,
        ]);
    }
}
