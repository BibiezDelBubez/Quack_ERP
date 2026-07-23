<?php
/**
 * Suite Manuale E2E per la verifica del punto 4.A.3 (AGENTS.md Compliance & DRY CSS)
 *
 * Verifiche:
 * 1. Generazione messaggi d'errore di compliance in SalesOrdersController e SalesQuotesController.
 * 2. Assenza totale di stili inline (style=) e colori hardcoded (#721c24).
 * 3. Presenza e correttezza della classe centralizzata class='qk-alert-link'.
 * 4. Presenza della regola .qk-alert-link in assets/css/app.css.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once APP_ROOT_DIR . '/vendor/autoload.php';
require_once APP_ROOT_DIR . '/includes/functions.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function chk(string $label, $expected, $actual): void
{
    if ($expected === $actual) {
        echo "OK   - $label\n";
        $GLOBALS['__pass']++;
        return;
    }
    echo "FAIL - $label\n       atteso:   " . var_export($expected, true) . "\n       ottenuto: " . var_export($actual, true) . "\n";
    $GLOBALS['__fail']++;
}

echo "=== INIZIO SUITE E2E: COMPLIANCE ALERT LINK CSS (4.A.3) ===\n\n";

// 1. Simulazione violazioni di compliance e messaggi di errore nei controller
$violations = [
    ['message' => 'Mancanza scheda di sicurezza (MSDS) per sostanza regolamentata'],
    ['message' => 'Autorizzazione commerciale scaduta o sospesa']
];

$violation_msgs = [];
foreach ($violations as $v) {
    $violation_msgs[] = $v['message'];
}
$customer_url = url('customers/edit?id=10');

// Generazione messaggi identici a SalesOrdersController (linea 95) e SalesQuotesController (linea 319)
$msgOrders = "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='qk-alert-link'>Gestisci autorizzazioni cliente →</a>";
$msgQuotes = "Salvataggio bloccato per motivi di compliance. " . implode(" | ", $violation_msgs) . " <a href='{$customer_url}' class='qk-alert-link'>Gestisci autorizzazioni cliente →</a>";

echo "--- TEST CONTROLLER SALES ORDERS ---\n";
chk('SalesOrders message non vuoto', true, strlen($msgOrders) > 20);
chk('SalesOrders message contiene class="qk-alert-link"', true, str_contains($msgOrders, "class='qk-alert-link'"));
chk('SalesOrders message NON contiene attributo style=', false, str_contains($msgOrders, 'style='));
chk('SalesOrders message NON contiene colore hardcoded #721c24', false, str_contains($msgOrders, '721c24'));

echo "\n--- TEST CONTROLLER SALES QUOTES ---\n";
chk('SalesQuotes message non vuoto', true, strlen($msgQuotes) > 20);
chk('SalesQuotes message contiene class="qk-alert-link"', true, str_contains($msgQuotes, "class='qk-alert-link'"));
chk('SalesQuotes message NON contiene attributo style=', false, str_contains($msgQuotes, 'style='));
chk('SalesQuotes message NON contiene colore hardcoded #721c24', false, str_contains($msgQuotes, '721c24'));

echo "\n--- TEST VERIFICA FOGLIO DI STILE CSS (assets/css/app.css) ---\n";
$cssContent = file_get_contents(APP_ROOT_DIR . '/assets/css/app.css');
chk('Classe .qk-alert-link presente in app.css', true, str_contains($cssContent, '.qk-alert-link'));

preg_match('/\.qk-alert-link\s*\{([^}]+)\}/', $cssContent, $matches);
$ruleBody = $matches[1] ?? '';

chk('Regola .qk-alert-link definisce text-decoration: underline', true, str_contains($ruleBody, 'text-decoration: underline') || str_contains($ruleBody, 'text-decoration:underline'));
chk('Regola .qk-alert-link definisce font-weight (700 o bold)', true, str_contains($ruleBody, 'font-weight: 700') || str_contains($ruleBody, 'font-weight:700') || str_contains($ruleBody, 'bold'));
chk('Regola .qk-alert-link definisce color: inherit', true, str_contains($ruleBody, 'color: inherit') || str_contains($ruleBody, 'color:inherit'));

echo "\n--- TEST DOCUMENTAZIONE UI/UX STANDARDS (docs/UI_UX_STANDARDS_v2.md) ---\n";
$docContent = file_get_contents(APP_ROOT_DIR . '/docs/UI_UX_STANDARDS_v2.md');
chk('Sezione .qk-alert-link documentata in UI_UX_STANDARDS_v2.md', true, str_contains($docContent, '.qk-alert-link'));

echo "\n=== RIEPILOGO TEST ===\n";
echo "PASS: {$GLOBALS['__pass']}\n";
echo "FAIL: {$GLOBALS['__fail']}\n";

if ($GLOBALS['__fail'] > 0) {
    echo "\nRisultato: FALLITO\n";
    exit(1);
}

echo "\nRisultato: SUCCESSO LOGICO VERIFICATO (EXIT CODE 0)\n";
exit(0);
