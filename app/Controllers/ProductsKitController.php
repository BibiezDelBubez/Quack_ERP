<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsKitController extends BaseController
{


    public function listComponentiKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $kit_id = filter_input(INPUT_GET, 'kit_id', FILTER_VALIDATE_INT);
        if (!$kit_id) { echo json_encode([]); exit; }
        echo json_encode(qk_load_kit_componenti($this->pdo, $kit_id));
        exit;
    }



    public function saveComponenteKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $kit_id       = (int) post('kit_id');
        $componente_id = (int) post('componente_id');
        $quantita     = trim((string)(post('quantita') ?? ''));
        $note         = trim((string)(post('note') ?? '')) ?: null;

        if ($kit_id <= 0 || $componente_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }
        if ($kit_id === $componente_id) {
            echo json_encode(['ok' => false, 'error' => 'Un kit non può contenere se stesso.']); exit;
        }

        // FIX Segnalazione #23: Impedisci kit nidificati (Kit dentro Kit) per evitare crash matematici
        $stmtComp = $this->pdo->prepare('SELECT tipo FROM prodotti WHERE id = :id');
        $stmtComp->execute([':id' => $componente_id]);
        if ($stmtComp->fetchColumn() === 'kit') {
            echo json_encode(['ok' => false, 'error' => 'Impossibile inserire un Kit all\'interno di un altro Kit.']); exit;
        }
        $qty = $quantita !== '' ? (float) $quantita : null;
        if ($qty === null || $qty <= 0) {
            echo json_encode(['ok' => false, 'error' => 'La quantità deve essere maggiore di zero.']); exit;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT tipo FROM prodotti WHERE id = :id');
            $stmt->execute([':id' => $kit_id]);
            $prod = $stmt->fetch();
            if (!$prod || $prod['tipo'] !== 'kit') {
                echo json_encode(['ok' => false, 'error' => 'Il prodotto principale non è registrato come Kit nel database. Salva prima le modifiche del prodotto.']); exit;
            }

            // Upsert: elimina riga esistente e reinserisce
            $this->pdo->prepare(
                'DELETE FROM componenti_kit WHERE kit_id = :kit_id AND componente_id = :comp_id'
            )->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);

            $this->pdo->prepare(
                'INSERT INTO componenti_kit (kit_id, componente_id, quantita, note)
                 VALUES (:kit_id, :comp_id, :qty, :note)'
            )->execute([
                ':kit_id'  => $kit_id,
                ':comp_id' => $componente_id,
                ':qty'     => $qty,
                ':note'    => $note,
            ]);

            $row = $this->pdo->prepare(
                'SELECT ck.componente_id, p.nome, p.sku_interno, ck.quantita, ck.note
                   FROM componenti_kit ck
                   JOIN prodotti p ON p.id = ck.componente_id
                  WHERE ck.kit_id = :kit_id AND ck.componente_id = :comp_id'
            );
            $row->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);
            echo json_encode(['ok' => true, 'row' => $row->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }



    public function deleteComponenteKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $kit_id       = (int) post('kit_id');
        $componente_id = (int) post('componente_id');
        if ($kit_id <= 0 || $componente_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM componenti_kit WHERE kit_id = :kit_id AND componente_id = :comp_id'
            )->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

}
