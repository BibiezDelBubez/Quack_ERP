<?php

declare(strict_types=1);

final class SettingsController extends BaseController
{
    public function index(): void
    {
        $page_title    = 'Impostazioni';
        $page_subtitle = 'Configurazioni e parametri globali del sistema';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Impostazioni']];

        $this->page(APP_ROOT_DIR . '/app/views/settings/index.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs'
        ));
    }
}

