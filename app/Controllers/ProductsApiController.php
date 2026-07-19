<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsApiController extends BaseController
{


    public function searchProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q          = trim(get_param('q'));
        $limit      = min(100, max(1, (int) get_param('limit', '50')));
        $exclude_id = filter_input(INPUT_GET, 'exclude', FILTER_VALIDATE_INT) ?: null;
        echo json_encode(qk_search_prodotti($this->pdo, $q, $limit, $exclude_id));
        exit;
    }

}
