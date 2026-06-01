<?php

declare(strict_types=1);

final class EcommerceController extends BaseController
{
    public function sync(): void
    {
        $this->page(APP_ROOT_DIR . '/app/views/shared/_coming_soon.php', [
            'page_title'    => 'Sincronizzazione WooCommerce',
            'page_subtitle' => 'In sviluppo',
            'breadcrumbs'   => [['label' => 'E-commerce'], ['label' => 'Sync']],
            'feature_name'  => 'Sincronizzazione WooCommerce',
        ]);
    }
}

