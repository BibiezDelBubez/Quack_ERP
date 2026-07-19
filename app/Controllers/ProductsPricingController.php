<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsPricingController extends BaseController
{


    public function saveRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $prodotto_id  = (int) post('prodotto_id');
        $rrp_data     = post('rrp_data'); // JSON string

        if ($prodotto_id <= 0 || !$rrp_data) {
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']); exit;
        }

        $rrps = json_decode($rrp_data, true);
        if (!is_array($rrps)) {
            $rrps = [];
        }

        try {
            $this->pdo->beginTransaction();
            
            // Eliminiamo tutti gli rrp esistenti per questo prodotto
            $this->pdo->prepare('DELETE FROM prodotti_rrp WHERE prodotto_id = :pid')
                      ->execute([':pid' => $prodotto_id]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO prodotti_rrp (id, prodotto_id, fornitore_id, rrp_min, rrp_max, valuta, valido_dal, valido_al, note) 
                 VALUES (:id, :pid, :fid, :rmin, :rmax, :valuta, :vdal, :val, :note)'
            );

            foreach ($rrps as $r) {
                $forn_id = !empty($r['fornitore_id']) ? (int)$r['fornitore_id'] : null;
                $stmt->execute([
                    ':id'     => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    ':pid'    => $prodotto_id,
                    ':fid'    => $forn_id,
                    ':rmin'   => isset($r['rrp_min']) ? (float)$r['rrp_min'] : 0,
                    ':rmax'   => isset($r['rrp_max']) ? (float)$r['rrp_max'] : 0,
                    ':valuta' => !empty($r['valuta']) ? $r['valuta'] : 'EUR',
                    ':vdal'   => !empty($r['valido_dal']) ? $r['valido_dal'] : null,
                    ':val'    => !empty($r['valido_al']) ? $r['valido_al'] : null,
                    ':note'   => !empty($r['note']) ? $r['note'] : null
                ]);
            }
            
            $this->pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    public function loadRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'ID prodotto non valido.']);
            exit;
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT * FROM prodotti_rrp 
                WHERE prodotto_id = ? 
                ORDER BY created_at ASC
            ');
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    public function deleteRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = post('id'); // UUID
        $prodottoId = (int) post('prodotto_id');

        if (!$id || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare('DELETE FROM prodotti_rrp WHERE id = :id AND prodotto_id = :pid')
                      ->execute([':id' => $id, ':pid' => $prodottoId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }



    public function savePrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');           // 0 = insert, > 0 = update
        $pfId       = (int) post('prodotto_fornitore_id');
        $prodottoId = (int) post('prodotto_id');  // per verifica ownership

        if ($pfId <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }

        // Verifica che prodotto_fornitore_id appartenga davvero al prodotto (sicurezza)
        $stmtOwn = $this->pdo->prepare(
            'SELECT id, unita_misura_acquisto_id FROM prodotti_fornitori WHERE id = :pfid AND prodotto_id = :pid'
        );
        $stmtOwn->execute([':pfid' => $pfId, ':pid' => $prodottoId]);
        $prodForn = $stmtOwn->fetch();
        if (!$prodForn) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato o accesso non autorizzato.']); exit;
        }

        $n = fn(string $k): mixed => trim((string)(post($k) ?? '')) === '' ? null : post($k);

        $listinoId = $n('listino_acquisto_id') !== null ? (int) $n('listino_acquisto_id') : null;
        $qta       = $n('quantita_minima')     !== null ? (float) $n('quantita_minima')   : 0.0;
        $prezzo    = (float) (post('prezzo') ?? 0);
        $sconto    = $n('sconto_perc')         !== null ? (float) $n('sconto_perc')       : 0.0;
        $validoDal = $n('valido_dal');
        $validoAl  = $n('valido_al');
        $note      = $n('note');
        
        // Mantenimento della UoM Nativa di negoziazione nei listini di acquisto
        // Se non specificato esplicitamente, ereditiamo l'unità di acquisto definita sul fornitore
        $uomIdEsplicito = $n('unita_misura_id') !== null ? (int)$n('unita_misura_id') : null;
        $uomId     = $uomIdEsplicito ?? (int)$prodForn['unita_misura_acquisto_id'];

        // Validazione "terza unità" (14/07/2026, audit a tappeto): stesso principio di saveForn()
        // (D0.4, PIANO §5.6/§7.5) — se il chiamante specifica esplicitamente unita_misura_id, deve
        // essere l'unità di stock o quella commerciale del prodotto, mai un valore arbitrario.
        if ($uomIdEsplicito !== null) {
            $stmtUmProdCheck = $this->pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid');
            $stmtUmProdCheck->execute([':pid' => $prodottoId]);
            $prodUmCheck = $stmtUmProdCheck->fetch(PDO::FETCH_ASSOC);
            $stockIdCheck = $prodUmCheck ? (int)$prodUmCheck['unita_misura_stock_id'] : 0;
            $commIdCheck  = ($prodUmCheck && $prodUmCheck['unita_contenuto_id'] !== null) ? (int)$prodUmCheck['unita_contenuto_id'] : null;
            if ($uomIdEsplicito !== $stockIdCheck && $uomIdEsplicito !== $commIdCheck) {
                echo json_encode(['ok' => false, 'error' => 'Unità di misura non valida: deve essere l\'unità di stock o l\'unità commerciale del prodotto.']);
                exit;
            }
        }

        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo non può essere negativo.']); exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto percentuale deve essere compreso tra 0 e 100.']); exit;
        }

        // D3.2 (PIANO Motore di Pricing Multi-UoM §8 Fase 1/3): questo endpoint scrive sulla stessa
        // tabella di PurchasesController::apiSaveRiga() — senza questo calcolo, le righe salvate qui
        // avrebbero quantita_minima_stock NULL e diventerebbero invisibili a getPurchasePrice()
        // (che dalla Fase 3 filtra su quantita_minima_stock, non più sul valore grezzo). Calcolato
        // PRIMA del controllo duplicati sotto, perché quel controllo ora confronta il valore canonico.
        $qtaIntento = $qta;
        $unitaSogliaId = $uomId ?: null;
        $qtaStock = $qta > 0
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaSogliaId, $qta, 0.0)['quantita_stock']
            : 0.0;

        // [Bug 3] Controllo sovrapposizione scaglioni
        // Corretto il 13/07/2026 (REVISIONE §6.15): "listino_acquisto_id = :listino_id" non trovava mai un
        // duplicato quando lo scaglione non è collegato a un listino (:listino_id NULL, il caso normale per
        // gli "Scaglioni Volume" inseriti nel drawer fornitore) — in SQL "colonna = NULL" non è mai vero,
        // nemmeno quando la colonna contiene davvero NULL. Aggiunta la gestione esplicita del caso NULL.
        // Corretto ULTERIORMENTE il 14/07/2026 (PIANO Motore di Pricing Multi-UoM §7.4/§8): confrontava
        // il valore grezzo (quantita_minima), lasciando passare "doppioni mascherati" (es. 100 KG e
        // 4 FUSTI con fattore 25 = stesso valore canonico) che poi avrebbero fatto fallire l'INSERT/UPDATE
        // contro il nuovo vincolo UNIQUE su quantita_minima_stock (D1.2) con un errore SQL grezzo invece
        // che con questo messaggio comprensibile. Ora confronta quantita_minima_stock.
        $stmtDup = $this->pdo->prepare("
            SELECT id FROM prodotti_fornitori_prezzi
            WHERE prodotto_fornitore_id = :pfid
              AND quantita_minima_stock = :qmin_stock
              AND id != :riga_id
              AND (listino_acquisto_id = :listino_id OR (:listino_id_null = 1 AND listino_acquisto_id IS NULL))
        ");
        $stmtDup->execute([
            ':listino_id'      => $listinoId,
            ':listino_id_null' => $listinoId === null ? 1 : 0,
            ':pfid'            => $pfId,
            ':qmin_stock'      => $qtaStock,
            ':riga_id'         => $id
        ]);
        if ($stmtDup->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).']);
            exit;
        }

        try {
            if ($id > 0) {
                // UPDATE — verifica ownership tramite prodotto_fornitore_id
                // modificato_manualmente_il: aggiornato sempre su un salvataggio manuale da questo drawer,
                // mai da apiImportPrezzi() — vedi REVISIONE §6.12.
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori_prezzi
                        SET listino_acquisto_id = :listino_id,
                            quantita_minima     = :qta,
                            prezzo              = :prezzo,
                            sconto_perc         = :sconto,
                            valido_dal          = :valido_dal,
                            valido_al           = :valido_al,
                            note                = :note,
                            unita_misura_id     = :uom_id,
                            quantita_minima_intento = :qta_intento,
                            unita_misura_soglia_id  = :um_soglia_id,
                            quantita_minima_stock   = :qta_stock,
                            modificato_manualmente_il = CURRENT_TIMESTAMP
                      WHERE id = :id
                        AND prodotto_fornitore_id = :pfid'
                )->execute([
                    ':listino_id' => $listinoId,
                    ':qta'        => $qta,
                    ':prezzo'     => $prezzo,
                    ':sconto'     => $sconto,
                    ':valido_dal' => $validoDal,
                    ':valido_al'  => $validoAl,
                    ':note'       => $note,
                    ':uom_id'     => $uomId,
                    ':qta_intento' => $qtaIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qta_stock'    => $qtaStock,
                    ':id'         => $id,
                    ':pfid'       => $pfId,
                ]);
            } else {
                // INSERT
                $this->pdo->prepare(
                    'INSERT INTO prodotti_fornitori_prezzi
                       (prodotto_fornitore_id, listino_acquisto_id, quantita_minima,
                        prezzo, sconto_perc, valido_dal, valido_al, note, unita_misura_id,
                        quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
                     VALUES
                       (:pfid, :listino_id, :qta, :prezzo, :sconto, :valido_dal, :valido_al, :note, :uom_id,
                        :qta_intento, :um_soglia_id, :qta_stock, CURRENT_TIMESTAMP)'
                )->execute([
                    ':qta_intento' => $qtaIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qta_stock'    => $qtaStock,
                    ':pfid'       => $pfId,
                    ':listino_id' => $listinoId,
                    ':qta'        => $qta,
                    ':prezzo'     => $prezzo,
                    ':sconto'     => $sconto,
                    ':valido_dal' => $validoDal,
                    ':valido_al'  => $validoAl,
                    ':note'       => $note,
                    ':uom_id'     => $uomId,
                ]);
                $id = (int) $this->pdo->lastInsertId();
            }

            // Restituisce la riga salvata (con nome_listino e UoM nativa per il rendering e la visualizzazione)
            $stmtRow = $this->pdo->prepare(
                'SELECT pfp.id, pfp.prodotto_fornitore_id, pfp.listino_acquisto_id,
                        pfp.quantita_minima, pfp.prezzo, pfp.sconto_perc,
                        pfp.valido_dal, pfp.valido_al, pfp.note, pfp.unita_misura_id,
                        la.nome_listino, um.codice AS unita_misura_codice
                   FROM prodotti_fornitori_prezzi pfp
              LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
              LEFT JOIN unita_misura um ON um.id = pfp.unita_misura_id
                  WHERE pfp.id = :id'
            );
            $stmtRow->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'row' => $stmtRow->fetch(PDO::FETCH_ASSOC)]);


        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => qk_prodotti_db_message($e)]);
        }
        exit;
    }



    public function deletePrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');
        $prodottoId = (int) post('prodotto_id'); // per verifica ownership

        if ($id <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            // DELETE con verifica ownership via subquery su prodotti_fornitori
            $this->pdo->prepare(
                'DELETE FROM prodotti_fornitori_prezzi
                  WHERE id = :id
                    AND prodotto_fornitore_id IN (
                        SELECT id FROM prodotti_fornitori WHERE prodotto_id = :pid
                    )'
            )->execute([':id' => $id, ':pid' => $prodottoId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

}
