<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ComplianceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getCustomerDetails(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT tipo, tipo_soggetto, ragione_sociale, nome, cognome, partita_iva FROM clienti_fornitori WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getProductName(int $productId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT nome FROM prodotti WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $productId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string)$name : null;
    }

    public function getProductRestrictions(int $productId, bool $includeOnlyLogTransactions = false): array
    {
        $sql = "
            SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days, r.log_transactions
              FROM compliance_restriction_types r
              JOIN prodotti_restrizioni pr ON pr.restriction_type_id = r.id
             WHERE pr.prodotto_id = :prodotto_id
               AND r.active = 1";
        
        if ($includeOnlyLogTransactions) {
            $sql .= " AND r.log_transactions = 1";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':prodotto_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCustomerAuthorization(int $customerId, int $restrictionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *, 
                   CASE 
                       WHEN status = 'approvata' AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE) THEN 1
                       WHEN status = 'in_attesa' THEN 2
                       WHEN status = 'approvata' AND expiry_date < CURRENT_DATE THEN 3
                       ELSE 4
                   END as prio
            FROM compliance_autorizzazioni 
            WHERE customer_id = :customer_id 
              AND restriction_type_id = :restriction_type_id 
            ORDER BY prio ASC, expiry_date DESC, id DESC LIMIT 1
        ");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':restriction_type_id' => $restrictionId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getApprovedAuthorizationId(int $customerId, int $restrictionId): ?int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM compliance_autorizzazioni
            WHERE customer_id = :customer_id
              AND restriction_type_id = :restriction_type_id
              AND status = 'approvata'
            ORDER BY 
               CASE WHEN expiry_date IS NULL OR expiry_date >= CURRENT_DATE THEN 1 ELSE 2 END ASC,
               expiry_date DESC, id DESC LIMIT 1
        ");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':restriction_type_id' => $restrictionId
        ]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public function getSaleDetails(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT cliente_id, data_ordine FROM ordini_clienti WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $saleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteTransactionLogs(int $saleId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM compliance_log_transazioni WHERE sale_id = :sale_id");
        $stmt->execute([':sale_id' => $saleId]);
    }

    public function insertTransactionLog(array $data): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO compliance_log_transazioni
              (sale_id, authorization_id, restriction_type_id, product_id, quantity, unit_id, transaction_date)
            VALUES
              (:sale_id, :auth_id, :r_id, :product_id, :qty, :unit_id, :t_date)
        ");
        $stmt->execute($data);
    }
}
