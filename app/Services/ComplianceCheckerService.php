<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ComplianceRepository;

class ComplianceCheckerService
{
    private ComplianceRepository $repository;

    public function __construct(ComplianceRepository $repository)
    {
        $this->repository = $repository;
    }

    public function checkSale(int $customerId, array $items): array
    {
        $violations = [];

        $customer = $this->repository->getCustomerDetails($customerId);
        if (!$customer) {
            return [[
                'prodotto_id' => 0,
                'prodotto_nome' => '',
                'restriction_type_id' => 0,
                'restriction_code' => '',
                'restriction_name' => '',
                'regulatory_ref' => '',
                'error_code' => 'customer_not_found',
                'message' => "Cliente non trovato."
            ]];
        }

        $customerVat = trim($customer['partita_iva'] ?? '');
        $customerName = trim($customer['ragione_sociale'] ?: ($customer['nome'] . ' ' . $customer['cognome']));

        $productRestrictions = [];

        foreach ($items as $item) {
            $pId = (int) ($item['prodotto_id'] ?? $item['product_id'] ?? 0);
            if ($pId <= 0) {
                continue;
            }

            $productName = $this->repository->getProductName($pId) ?: "Prodotto #{$pId}";

            if (!isset($productRestrictions[$pId])) {
                $productRestrictions[$pId] = $this->repository->getProductRestrictions($pId, false);
            }

            $restrictions = $productRestrictions[$pId];

            foreach ($restrictions as $r) {
                // Check P.IVA if required
                if ((int)$r['requires_vat'] === 1 && $customerVat === '') {
                    $violations[] = [
                        'prodotto_id' => $pId,
                        'prodotto_nome' => $productName,
                        'restriction_type_id' => (int) $r['id'],
                        'restriction_code' => $r['code'],
                        'restriction_name' => $r['name'],
                        'regulatory_ref' => $r['regulatory_ref'] ?? '',
                        'error_code' => 'vat_required',
                        'message' => "La vendita di \"{$productName}\" richiede Partita IVA cliente per la restrizione \"{$r['name']}\" ({$r['regulatory_ref']})."
                    ];
                    continue;
                }

                // Check client authorization
                $auth = $this->repository->getCustomerAuthorization($customerId, (int)$r['id']);

                if (!$auth) {
                    $violations[] = [
                        'prodotto_id' => $pId,
                        'prodotto_nome' => $productName,
                        'restriction_type_id' => (int) $r['id'],
                        'restriction_code' => $r['code'],
                        'restriction_name' => $r['name'],
                        'regulatory_ref' => $r['regulatory_ref'] ?? '',
                        'error_code' => 'auth_missing',
                        'message' => "Il cliente \"{$customerName}\" non ha un'autorizzazione per \"{$r['name']}\" ({$r['regulatory_ref']})."
                    ];
                } else {
                    $status = $auth['status'];
                    $expiry = $auth['expiry_date'];
                    $today = date('Y-m-d');

                    if ($status === 'approvata' && $expiry !== null && $expiry < $today) {
                        $status = 'scaduta';
                    }

                    if ($status !== 'approvata') {
                        $statusLabels = [
                            'in_attesa' => 'in attesa di approvazione',
                            'rifiutata' => 'rifiutata',
                            'scaduta'   => "scaduta il " . ($expiry ? date('d/m/Y', strtotime($expiry)) : ''),
                            'sospesa'   => 'sospesa'
                        ];
                        $label = $statusLabels[$status] ?? $status;

                        $violations[] = [
                            'prodotto_id' => $pId,
                            'prodotto_nome' => $productName,
                            'restriction_type_id' => (int) $r['id'],
                            'restriction_code' => $r['code'],
                            'restriction_name' => $r['name'],
                            'regulatory_ref' => $r['regulatory_ref'] ?? '',
                            'error_code' => 'auth_' . $status,
                            'message' => "L'autorizzazione del cliente per \"{$r['name']}\" è {$label}. Vendita non consentita."
                        ];
                    }
                }
            }
        }

        return $violations;
    }

    public function logTransactions(int $saleId, array $items): void
    {
        $sale = $this->repository->getSaleDetails($saleId);
        if (!$sale) {
            return;
        }

        $customerId = (int)$sale['cliente_id'];
        $transactionDate = $sale['data_ordine'] ?? date('Y-m-d');

        $this->repository->deleteTransactionLogs($saleId);

        foreach ($items as $item) {
            $pId = (int) ($item['prodotto_id'] ?? $item['product_id'] ?? 0);
            $qty = (float) ($item['quantita'] ?? $item['quantity'] ?? 0);
            $unitId = (int) ($item['unita_misura_id'] ?? $item['unit_id'] ?? 0);
            if ($pId <= 0 || $qty <= 0) {
                continue;
            }

            $restrictions = $this->repository->getProductRestrictions($pId, true);

            foreach ($restrictions as $r) {
                $authId = $this->repository->getApprovedAuthorizationId($customerId, (int)$r['id']);

                $this->repository->insertTransactionLog([
                    ':sale_id' => $saleId,
                    ':auth_id' => $authId,
                    ':r_id' => $r['id'],
                    ':product_id' => $pId,
                    ':qty' => $qty,
                    ':unit_id' => $unitId ?: null,
                    ':t_date' => $transactionDate
                ]);
            }
        }
    }
}
