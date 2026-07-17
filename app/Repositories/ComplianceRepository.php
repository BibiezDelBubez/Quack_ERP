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
        // ── Percorso 1: restrizioni dirette sulla sostanza chimica (best practice) ──
        $sqlDirette = "
            SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days, r.log_transactions,
                   scr.concentrazione_min AS min_concentration,
                   scr.concentrazione_max AS max_concentration
              FROM compliance_restriction_types r
              JOIN sostanze_chimiche_restrizioni scr ON scr.restriction_type_id = r.id
              JOIN sostanze_chimiche sa ON sa.id = scr.sostanza_id
              JOIN prodotti p ON p.sostanza_chimica_id = sa.id
             WHERE p.id = :prodotto_id
               AND r.active = 1
               AND (scr.concentrazione_min IS NULL OR sa.concentrazione_max_pct >= scr.concentrazione_min)
               AND (scr.concentrazione_max IS NULL OR sa.concentrazione_min_pct <= scr.concentrazione_max)";
        
        if ($includeOnlyLogTransactions) {
            $sqlDirette = str_replace("AND r.active = 1", "AND r.active = 1 AND r.log_transactions = 1", $sqlDirette);
        }

        $stmt = $this->pdo->prepare($sqlDirette);
        $stmt->execute([':prodotto_id' => $productId]);
        $dirette = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Percorso 2: fallback su codice ONU (legacy) ──
        $fallback = [];
        if (empty($dirette)) {
            $sqlOnu = "
                SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days, r.log_transactions,
                       cr.min_concentration, cr.max_concentration
                  FROM compliance_restriction_types r
                  JOIN compliance_codice_onu_restrizioni cr ON cr.restriction_type_id = r.id
                  JOIN sostanze_chimiche sa ON sa.codice_onu_id = cr.un_code_id
                  JOIN prodotti p ON p.sostanza_chimica_id = sa.id
                 WHERE p.id = :prodotto_id
                   AND r.active = 1
                   AND (cr.min_concentration IS NULL OR sa.concentrazione_max_pct >= cr.min_concentration)
                   AND (cr.max_concentration IS NULL OR sa.concentrazione_min_pct <= cr.max_concentration)";
            
            if ($includeOnlyLogTransactions) {
                // Notice the extra ADR check from the original logic for logging via ONU
                $sqlOnu = str_replace("AND r.active = 1", "AND COALESCE(p.is_adr, 0) = 1 AND r.active = 1 AND r.log_transactions = 1", $sqlOnu);
            }

            $stmt2 = $this->pdo->prepare($sqlOnu);
            $stmt2->execute([':prodotto_id' => $productId]);
            $fallback = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        // Merge senza duplicati
        $seen = [];
        $merged = [];
        foreach (array_merge($dirette, $fallback) as $r) {
            if (!isset($seen[$r['id']])) {
                $seen[$r['id']] = true;
                $merged[] = $r;
            }
        }
        return $merged;
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
