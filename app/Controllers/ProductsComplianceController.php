<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsComplianceController extends BaseController
{


    /**
     * GET /products/compliance/list?prodotto_id=X
     * Restituisce:
     *   - restrizioni attive sul prodotto
     *   - tutti i tipi di restrizione disponibili
     *   - suggerimenti da codice ONU (se il prodotto ha una classificazione ADR)
     */
    public function apiComplianceList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $prodottoId = (int) get_param('prodotto_id');
        if ($prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'prodotto_id mancante.']);
            exit;
        }

        try {
            // Restrizioni già assegnate al prodotto
            $stmtAssigned = $this->pdo->prepare(
                'SELECT pr.restriction_type_id, rt.code, rt.name, rt.regulatory_ref, pr.note, pr.created_at
                 FROM prodotti_restrizioni pr
                 JOIN compliance_restriction_types rt ON rt.id = pr.restriction_type_id
                 WHERE pr.prodotto_id = :pid
                 ORDER BY rt.name'
            );
            $stmtAssigned->execute([':pid' => $prodottoId]);
            $assegnate = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);

            // Tutti i tipi di restrizione attivi
            $stmtAll = $this->pdo->prepare(
                'SELECT id, code, name, regulatory_ref FROM compliance_restriction_types WHERE active = 1 ORDER BY name'
            );
            $stmtAll->execute();
            $tutti = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

            // Suggerimenti da codice ONU del prodotto (se ha classificazione ADR)
            $stmtSuggest = $this->pdo->prepare(
                'SELECT DISTINCT rt.id AS restriction_type_id, rt.code, rt.name, rt.regulatory_ref
                 FROM prodotti p
                 JOIN prodotto_classificazioni_adr pca ON pca.prodotto_id = p.id
                 JOIN compliance_codice_onu_restrizioni ccr ON ccr.un_code_id = pca.codice_onu_id
                 JOIN compliance_restriction_types rt ON rt.id = ccr.restriction_type_id
                 WHERE p.id = :pid AND rt.active = 1'
            );
            $stmtSuggest->execute([':pid' => $prodottoId]);
            $suggerimenti = $stmtSuggest->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok'           => true,
                'assegnate'    => $assegnate,
                'tutti'        => $tutti,
                'suggerimenti' => $suggerimenti,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    /**
     * POST /products/compliance/add
     * Body: prodotto_id, restriction_type_id, note, csrf_token
     */
    public function apiComplianceAdd(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $prodottoId        = (int) post('prodotto_id');
        $restrictionTypeId = (int) post('restriction_type_id');
        $note              = trim(post('note') ?? '');

        if ($prodottoId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi.']);
            exit;
        }

        try {
            $this->pdo->prepare(
                db_insert_ignore_sql('prodotti_restrizioni (prodotto_id, restriction_type_id, note)
                 VALUES (:pid, :rtid, :note)')
            )->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId, ':note' => $note ?: null]);

            // Restituisce la riga con il nome del tipo
            $row = $this->pdo->prepare(
                'SELECT pr.restriction_type_id, rt.code, rt.name, rt.regulatory_ref, pr.note, pr.created_at
                 FROM prodotti_restrizioni pr
                 JOIN compliance_restriction_types rt ON rt.id = pr.restriction_type_id
                 WHERE pr.prodotto_id = :pid AND pr.restriction_type_id = :rtid'
            );
            $row->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId]);

            echo json_encode(['ok' => true, 'restrizione' => $row->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    /**
     * POST /products/compliance/remove
     * Body: prodotto_id, restriction_type_id, csrf_token
     */
    public function apiComplianceRemove(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $prodottoId        = (int) post('prodotto_id');
        $restrictionTypeId = (int) post('restriction_type_id');

        if ($prodottoId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi.']);
            exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM prodotti_restrizioni WHERE prodotto_id = :pid AND restriction_type_id = :rtid'
            )->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    /**
     * POST /products/api/batch-associa-adr
     */
    public function apiBatchAssociaAdr(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']);
            exit;
        }

        $sostanzaId = (int) post('sostanza_id');
        $prodottoIds = (array) ($_POST['prodotto_ids'] ?? []);

        if (empty($prodottoIds)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun prodotto selezionato.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();
            if ($sostanzaId > 0) {
                $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = ?, is_adr = 1 WHERE id = ?');
                foreach ($prodottoIds as $pId) {
                    $stmt->execute([$sostanzaId, (int)$pId]);
                }
            } else {
                // Rimuove l'associazione
                $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = NULL, is_adr = 0 WHERE id = ?');
                foreach ($prodottoIds as $pId) {
                    $stmt->execute([(int)$pId]);
                }
            }
            $this->pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

}
