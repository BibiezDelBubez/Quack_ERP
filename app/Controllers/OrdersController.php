<?php

declare(strict_types=1);

final class OrdersController extends BaseController
{
    public function index(): void
    {
        // TODO: query reali DB
        $search = get_param('q');
        $status = get_param('status');

        $orders = [];
        $total  = 0;
        $status_options = ['draft', 'pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

        $page_title    = 'Ordini';
        $page_subtitle = 'Ciclo ordini dropshipping piscina';
        $breadcrumbs   = [['label' => 'Ordini']];
        $page_actions  = '<a href="' . url('orders/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo ordine
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/orders/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function create(): void
    {
        $page_title    = 'Nuovo ordine';
        $page_subtitle = 'In sviluppo';
        $breadcrumbs   = [['label' => 'Ordini', 'url' => url('orders')], ['label' => 'Nuovo']];

        $this->page(APP_ROOT_DIR . '/app/views/shared/_coming_soon.php', [
            'page_title'    => $page_title,
            'page_subtitle' => $page_subtitle,
            'breadcrumbs'   => $breadcrumbs,
            'feature_name'  => 'Creazione ordine',
        ]);
    }
}

