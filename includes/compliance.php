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
    $violations = [];

    // 1. Fetch customer details
    $c_stmt = $pdo->prepare("SELECT tipo, tipo_soggetto, ragione_sociale, nome, cognome, partita_iva FROM clienti_fornitori WHERE id = :id LIMIT 1");
    $c_stmt->execute([':id' => $customer_id]);
    $customer = $c_stmt->fetch();
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

    $customer_vat = trim($customer['partita_iva'] ?? '');
    $customer_name = trim($customer['ragione_sociale'] ?: ($customer['nome'] . ' ' . $customer['cognome']));

    // Cache restrictions to avoid duplicate queries
    $product_restrictions = [];

    foreach ($items as $item) {
        $p_id = (int) ($item['prodotto_id'] ?? $item['product_id'] ?? 0);
        if ($p_id <= 0) {
            continue;
        }

        // Fetch product details
        $p_stmt = $pdo->prepare("SELECT nome FROM prodotti WHERE id = :id LIMIT 1");
        $p_stmt->execute([':id' => $p_id]);
        $product_name = $p_stmt->fetchColumn() ?: "Prodotto #{$p_id}";

        if (!isset($product_restrictions[$p_id])) {
            // ── Percorso 1: restrizioni dirette sulla sostanza chimica (best practice) ──
            $sql_dirette = "
                SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days,
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

            $stmt = $pdo->prepare($sql_dirette);
            $stmt->execute([':prodotto_id' => $p_id]);
            $dirette = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Percorso 2: fallback su codice ONU (legacy) ──
            // Usato solo se la sostanza NON ha restrizioni dirette configurate
            $fallback = [];
            if (empty($dirette)) {
                $sql_onu = "
                    SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days,
                           cr.min_concentration, cr.max_concentration
                      FROM compliance_restriction_types r
                      JOIN compliance_codice_onu_restrizioni cr ON cr.restriction_type_id = r.id
                      JOIN sostanze_chimiche sa ON sa.codice_onu_id = cr.un_code_id
                      JOIN prodotti p ON p.sostanza_chimica_id = sa.id
                     WHERE p.id = :prodotto_id
                       AND r.active = 1
                       AND (cr.min_concentration IS NULL OR sa.concentrazione_max_pct >= cr.min_concentration)
                       AND (cr.max_concentration IS NULL OR sa.concentrazione_min_pct <= cr.max_concentration)";
                $stmt2 = $pdo->prepare($sql_onu);
                $stmt2->execute([':prodotto_id' => $p_id]);
                $fallback = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            // Merge senza duplicati per restriction_type_id
            $seen = [];
            $merged = [];
            foreach (array_merge($dirette, $fallback) as $r) {
                if (!isset($seen[$r['id']])) {
                    $seen[$r['id']] = true;
                    $merged[] = $r;
                }
            }
            $product_restrictions[$p_id] = $merged;
        }

        $restrictions = $product_restrictions[$p_id];

        foreach ($restrictions as $r) {
            // Check P.IVA if required
            if ((int)$r['requires_vat'] === 1 && $customer_vat === '') {
                $violations[] = [
                    'prodotto_id' => $p_id,
                    'prodotto_nome' => $product_name,
                    'restriction_type_id' => (int) $r['id'],
                    'restriction_code' => $r['code'],
                    'restriction_name' => $r['name'],
                    'regulatory_ref' => $r['regulatory_ref'] ?? '',
                    'error_code' => 'vat_required',
                    'message' => "La vendita di \"{$product_name}\" richiede Partita IVA cliente per la restrizione \"{$r['name']}\" ({$r['regulatory_ref']})."
                ];
                continue;
            }

            // Check client authorization
            $auth_stmt = $pdo->prepare("
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
            $auth_stmt->execute([
                ':customer_id' => $customer_id,
                ':restriction_type_id' => $r['id']
            ]);
            $auth = $auth_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$auth) {
                $violations[] = [
                    'prodotto_id' => $p_id,
                    'prodotto_nome' => $product_name,
                    'restriction_type_id' => (int) $r['id'],
                    'restriction_code' => $r['code'],
                    'restriction_name' => $r['name'],
                    'regulatory_ref' => $r['regulatory_ref'] ?? '',
                    'error_code' => 'auth_missing',
                    'message' => "Il cliente \"{$customer_name}\" non ha un'autorizzazione per \"{$r['name']}\" ({$r['regulatory_ref']})."
                ];
            } else {
                $status = $auth['status'];
                $expiry = $auth['expiry_date'];
                $today = date('Y-m-d');

                if ($status === 'approvata' && $expiry !== null && $expiry < $today) {
                    $status = 'scaduta';
                }

                if ($status !== 'approvata') {
                    $status_labels = [
                        'in_attesa' => 'in attesa di approvazione',
                        'rifiutata' => 'rifiutata',
                        'scaduta'   => "scaduta il " . ($expiry ? date('d/m/Y', strtotime($expiry)) : ''),
                        'sospesa'   => 'sospesa'
                    ];
                    $label = $status_labels[$status] ?? $status;

                    $violations[] = [
                        'prodotto_id' => $p_id,
                        'prodotto_nome' => $product_name,
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

/**
 * Logs transactions for compliance-restricted items of a confirmed sale/order.
 */
function qk_compliance_log_transactions(PDO $pdo, int $sale_id, array $items): void
{
    // Find customer_id from sale
    $stmt = $pdo->prepare("SELECT cliente_id, data_ordine FROM ordini_clienti WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $sale_id]);
    $sale = $stmt->fetch();
    if (!$sale) {
        return;
    }

    $customer_id = (int)$sale['cliente_id'];
    $transaction_date = $sale['data_ordine'] ?? date('Y-m-d');

    // FIX: Svuotiamo il log per questo specifico ordine prima di rigenerarlo. 
    // Così, se l'utente salva l'ordine più volte o ci sono più righe dello stesso prodotto, 
    // le quantità nel registro governativo saranno sempre un'immagine esatta dell'ordine finale, 
    // senza duplicazioni (che falserebbero le somme) o omissioni.
    $del = $pdo->prepare("DELETE FROM compliance_log_transazioni WHERE sale_id = :sale_id");
    $del->execute([':sale_id' => $sale_id]);

    foreach ($items as $item) {
        $p_id = (int) ($item['prodotto_id'] ?? $item['product_id'] ?? 0);
        $qty = (float) ($item['quantita'] ?? $item['quantity'] ?? 0);
        $unit_id = (int) ($item['unita_misura_id'] ?? $item['unit_id'] ?? 0);
        if ($p_id <= 0 || $qty <= 0) {
            continue;
        }

        // Get active restrictions — percorso 1: dirette sulla sostanza
        $sql_d = "SELECT r.id, r.log_transactions
                    FROM compliance_restriction_types r
                    JOIN sostanze_chimiche_restrizioni scr ON scr.restriction_type_id = r.id
                    JOIN sostanze_chimiche sa ON sa.id = scr.sostanza_id
                    JOIN prodotti p ON p.sostanza_chimica_id = sa.id
                   WHERE p.id = :prodotto_id AND r.active = 1";
        $r_stmt = $pdo->prepare($sql_d);
        $r_stmt->execute([':prodotto_id' => $p_id]);
        $restrictions = $r_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: codice ONU (solo se la sostanza non ha restrizioni dirette)
        if (empty($restrictions)) {
            $sql_onu = "SELECT r.id, r.log_transactions
                          FROM compliance_restriction_types r
                          JOIN compliance_codice_onu_restrizioni cr ON cr.restriction_type_id = r.id
                          JOIN sostanze_chimiche sa ON sa.codice_onu_id = cr.un_code_id
                          JOIN prodotti p ON p.sostanza_chimica_id = sa.id
                         WHERE p.id = :prodotto_id
                           AND COALESCE(p.is_adr, 0) = 1
                           AND r.active = 1
                           AND (cr.min_concentration IS NULL OR sa.concentrazione_max_pct >= cr.min_concentration)
                           AND (cr.max_concentration IS NULL OR sa.concentrazione_min_pct <= cr.max_concentration)";
            $r2 = $pdo->prepare($sql_onu);
            $r2->execute([':prodotto_id' => $p_id]);
            $restrictions = $r2->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($restrictions as $r) {
            if ((int)$r['log_transactions'] !== 1) {
                continue;
            }

            // Find the approved authorization for this customer & restriction
            $auth_stmt = $pdo->prepare("
                SELECT id FROM compliance_autorizzazioni
                WHERE customer_id = :customer_id
                  AND restriction_type_id = :restriction_type_id
                  AND status = 'approvata'
                ORDER BY 
                   CASE WHEN expiry_date IS NULL OR expiry_date >= CURRENT_DATE THEN 1 ELSE 2 END ASC,
                   expiry_date DESC, id DESC LIMIT 1
            ");
            $auth_stmt->execute([
                ':customer_id' => $customer_id,
                ':restriction_type_id' => $r['id']
            ]);
            $auth_id = $auth_stmt->fetchColumn();
            if (!$auth_id) {
                // If not found, look for any auth or set to null/insert one (safety fallback)
                $auth_id = null;
            }

            // Rimosso controllo duplicati: la legge impone di tracciare le quantità esatte per ogni transazione, anche in caso di aggiornamenti.
            // Insert transaction log
            // Nome colonna corretto è authorization_id (DDL, inglese come il resto
            // della tabella): il precedente "autorizzazione_id" italianizzato non
            // esiste in db/init_sqlite.sql e faceva fallire ogni INSERT con "no
            // such column" (audit/Schema-vs-codice-vs-live.md §2, 09/07/2026).
            $ins = $pdo->prepare("
                INSERT INTO compliance_log_transazioni
                  (sale_id, authorization_id, restriction_type_id, product_id, quantity, unit_id, transaction_date)
                VALUES
                  (:sale_id, :auth_id, :r_id, :product_id, :qty, :unit_id, :t_date)
            ");
            $ins->execute([
                ':sale_id' => $sale_id,
                ':auth_id' => $auth_id,
                ':r_id' => $r['id'],
                ':product_id' => $p_id,
                ':qty' => $qty,
                ':unit_id' => $unit_id ?: null,
                ':t_date' => $transaction_date
            ]);
        }
    }
}
