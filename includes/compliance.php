<?php
/**
 * includes/compliance.php — Business logic helper functions for the Compliance module.
 */

declare(strict_types=1);

/**
 * Checks if a sale is allowed for a customer and a list of items.
 *
 * Each item in $items should be an array/object with 'prodotto_id' (or 'product_id').
 *
 * Returns an array of violations. If empty, sale is allowed.
 * Each violation: [
 *   'prodotto_id' => int,
 *   'prodotto_nome' => string,
 *   'restriction_type_id' => int,
 *   'restriction_code' => string,
 *   'restriction_name' => string,
 *   'regulatory_ref' => string,
 *   'error_code' => 'vat_required' | 'auth_missing' | 'auth_expired' | 'auth_pending' | 'auth_rejected' | 'auth_suspended',
 *   'message' => string
 * ]
 */
function qk_compliance_check_sale(PDO $pdo, int $customer_id, array $items): array
{
    require_once __DIR__ . '/../app/Repositories/ComplianceRepository.php';
    require_once __DIR__ . '/../app/Services/ComplianceCheckerService.php';

    $repo = new \App\Repositories\ComplianceRepository($pdo);
    $service = new \App\Services\ComplianceCheckerService($repo);

    return $service->checkSale($customer_id, $items);
}

/**
 * Logs transactions for compliance-restricted items of a confirmed sale/order.
 */
function qk_compliance_log_transactions(PDO $pdo, int $sale_id, array $items): void
{
    require_once __DIR__ . '/../app/Repositories/ComplianceRepository.php';
    require_once __DIR__ . '/../app/Services/ComplianceCheckerService.php';

    $repo = new \App\Repositories\ComplianceRepository($pdo);
    $service = new \App\Services\ComplianceCheckerService($repo);

    $service->logTransactions($sale_id, $items);
}
