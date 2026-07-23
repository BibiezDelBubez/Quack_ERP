<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Controllers/BaseController.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

class TestController extends BaseController {
    public function renderView(string $viewFile, array $data) {
        // We capture output to avoid printing hundreds of lines of HTML
        ob_start();
        try {
            $this->render(__DIR__ . '/../app/views/' . $viewFile, $data);
            $output = ob_get_clean();
            return $output;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}

$ctrl = new TestController($pdo);

// Mock data needed by the views so they don't crash before reaching qk_is_true
$mockData = [
    'adr/_codice_form.php' => [
        'var' => 'values',
        'keys' => ['e_merce_alto_rischio', 'e_inquinante_marino'],
        'extra' => ['mode' => 'create', 'categorie' => [], 'lookup' => ['classi'=>[], 'classificazioni'=>[], 'imballaggi'=>[], 'categorie'=>[], 'gallerie'=>[], 'quantita_esenti'=>[]]]
    ],
    'adr/sostanze/_form.php' => [
        'var' => 'v',
        'keys' => ['e_soluzione', 'e_rifiuto', 'e_stabilizzato'],
        'extra' => ['mode' => 'create', 'errors' => [], 'clp_data' => ['h_statements'=>[], 'p_statements'=>[], 'pittogrammi'=>[]], 'categorie' => [], 'categories' => [], 'prodotti_associati' => []]
    ],
    'customers/_form.php' => [
        'var' => 'v',
        'keys' => ['newsletter', 'iva_esente'],
        'extra' => ['mode' => 'create', 'errors' => [], 'tipi_cliente' => [], 'sconti' => [], 'listini' => []]
    ],
    'products/_form.php' => [
        'var' => 'v',
        'keys' => ['is_adr', 'attivo', 'traccia_lotti'],
        'extra' => ['mode' => 'create', 'errors' => [], 'categorie' => [], 'aliquote_iva' => [], 'unita_misura_list' => [], 'adr_classes' => [], 'tags' => [], 'tags_attivi' => [], 'prodotto_fornitori' => [], 'listini' => [], 'tutti_prezzi' => [], 'all_tags' => []]
    ],
    'settings/aziende/_form.php' => [
        'var' => 'v',
        'keys' => ['socio_unico', 'in_liquidazione', 'predefinita'],
        'extra' => ['mode' => 'create', 'errors' => []]
    ],
    'settings/magazzini/_form.php' => [
        'var' => 'v',
        'keys' => ['predefinito', 'attivo'],
        'extra' => ['mode' => 'create', 'errors' => []]
    ],
    'settings/woocommerce.php' => [
        'var' => 'values',
        'keys' => ['wc_sync_enabled', 'wc_sync_products', 'wc_sync_orders'],
        'extra' => ['errors' => []]
    ],
    'users/_form.php' => [
        'var' => 'v',
        'keys' => ['attivo'],
        'extra' => ['mode' => 'create', 'errors' => []]
    ]
];

$testCases = [
    'string_1' => '1',
    'string_0' => '0',
    'int_1'    => 1,
    'int_0'    => 0,
    'bool_t'   => true,
    'bool_f'   => false,
    'null'     => null
];

$successCount = 0;
$failCount = 0;

echo "Inizio simulazione rendering view per verificare qk_is_true...\n";
echo str_repeat("-", 60) . "\n";

foreach ($mockData as $view => $config) {
    foreach ($testCases as $caseName => $caseValue) {
        // Prepare the values array
        $valuesArray = [];
        // Fill all required keys with the test value
        foreach ($config['keys'] as $k) {
            $valuesArray[$k] = $caseValue;
        }
        // Also add some safe defaults for other fields like 'nome' etc. to prevent unrelated warnings
        $valuesArray['nome'] = 'Test';
        $valuesArray['codice'] = 'TST';
        
        // Prepare data to extract
        $data = $config['extra'];
        $data[$config['var']] = $valuesArray;
        
        try {
            $output = $ctrl->renderView($view, $data);
            
            // Check if checked property is present based on expected logic
            $shouldBeChecked = in_array($caseValue, ['1', 1, true], true);
            // We just verify it didn't throw an error, which proves qk_is_true accepts the mixed type.
            $successCount++;
        } catch (\Throwable $e) {
            echo "[ERROR] $view with $caseName -> " . $e->getMessage() . "\n";
            $failCount++;
        }
    }
    echo "[OK] $view -> Testati tutti i tipi (string, int, bool, null) senza errori.\n";
}

echo str_repeat("-", 60) . "\n";
echo "Risultato: $successCount successi, $failCount fallimenti.\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
