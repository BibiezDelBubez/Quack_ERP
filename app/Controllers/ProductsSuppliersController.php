<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

final class ProductsSuppliersController extends BaseController
{


    public function searchFornitori(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q     = trim(get_param('q'));
        $limit = min(100, max(1, (int) get_param('limit', '50')));
        echo json_encode(qk_search_fornitori($this->pdo, $q, $limit));
        exit;
    }



    public function saveForn(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $prodotto_id  = (int) post('prodotto_id');
        $id           = (int) post('id');
        $fornitore_id = (int) post('fornitore_id');
        if ($prodotto_id <= 0 || $fornitore_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }

        $n = fn(string $k): mixed => trim((string)(post($k) ?? '')) === '' ? null : post($k);

        $prezzoBase    = $n('prezzo_acquisto') !== null ? (float) $n('prezzo_acquisto') : null;
        $ordPref       = max(1, (int) (post('ordine_preferenza_fornitore') ?? 1));
        $rrpMin        = $n('rrp_min') !== null ? (float) $n('rrp_min') : null;
        $rrpMax        = $n('rrp_max') !== null ? (float) $n('rrp_max') : null;
        $leadTime      = $n('lead_time_giorni') !== null ? (int) $n('lead_time_giorni') : null;
        $unitaMisura   = $n('unita_misura_acquisto_id') !== null ? (int) $n('unita_misura_acquisto_id') : null;

        // D0.4 (PIANO Motore di Pricing Multi-UoM §5.6/§7.5, log decisioni punto L): l'unità di
        // acquisto di un fornitore deve SEMPRE coincidere con la Stock UoM o con la UoM commerciale
        // del prodotto — mai una terza unità priva di fattore di conversione noto. Questa regola era
        // rispettata finora solo per comportamento accidentale del client (campo nascosto in
        // products-suppliers.js); qui viene fatta rispettare esplicitamente dal server.
        $stmtUmProdotto = $this->pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid');
        $stmtUmProdotto->execute([':pid' => $prodotto_id]);
        $prodottoUm = $stmtUmProdotto->fetch(PDO::FETCH_ASSOC);
        if (!$prodottoUm) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto non trovato.']); exit;
        }
        $umStockId      = (int) $prodottoUm['unita_misura_stock_id'];
        $umCommercialId = $prodottoUm['unita_contenuto_id'] !== null ? (int) $prodottoUm['unita_contenuto_id'] : null;

        if ($unitaMisura === null) {
            $unitaMisura = $umStockId;
        } elseif ($unitaMisura !== $umStockId && $unitaMisura !== $umCommercialId) {
            echo json_encode(['ok' => false, 'error' => "Unità di acquisto non valida per questo prodotto: deve essere l'unità di stock o l'unità commerciale del prodotto, non una terza unità."]); exit;
        }
        $note          = $n('note');

        try {
            $this->pdo->beginTransaction();

            if ($id <= 0) {
                $checkStmt = $this->pdo->prepare(
                    'SELECT id FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1'
                );
                $checkStmt->execute([':pid' => $prodotto_id, ':fid' => $fornitore_id]);
                $existingId = $checkStmt->fetchColumn();
                if ($existingId) {
                    $id = (int)$existingId;
                }
            }

            if ($id > 0) {
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori SET
                       fornitore_id                  = :fid,
                       sku_fornitore                 = :sku,
                       nome_prodotto_fornitore       = :nome,
                       quantita_minima               = :qta,
                       ordine_preferenza_fornitore   = :ord_pref,
                       attivo                        = :attivo,
                       rrp_min                       = :rrp_min,
                       rrp_max                       = :rrp_max,
                       lead_time_giorni              = :lead_time,
                       unita_misura_acquisto_id      = :uom_id,
                       note                          = :note,
                       updated_at                    = CURRENT_TIMESTAMP
                     WHERE id = :id AND prodotto_id = :pid'
                )->execute([
                    ':fid'      => $fornitore_id,
                    ':sku'      => $n('sku_fornitore'),
                    ':nome'     => $n('nome_prodotto_fornitore'),
                    ':qta'      => $n('quantita_minima') !== null ? (float) $n('quantita_minima') : null,
                    ':ord_pref' => $ordPref,
                    ':attivo'   => post('attivo') === '1' ? 1 : 0,
                    ':rrp_min'  => $rrpMin,
                    ':rrp_max'  => $rrpMax,
                    ':lead_time'=> $leadTime,
                    ':uom_id'   => $unitaMisura,
                    ':note'     => $note,
                    ':id'       => $id,
                    ':pid'      => $prodotto_id,
                ]);
                $pfId = $id;
            } else {
                $this->pdo->prepare(
                    'INSERT INTO prodotti_fornitori
                       (prodotto_id, fornitore_id, sku_fornitore, nome_prodotto_fornitore,
                        quantita_minima, ordine_preferenza_fornitore, attivo,
                        rrp_min, rrp_max, lead_time_giorni, unita_misura_acquisto_id, note, created_at)
                     VALUES
                       (:pid, :fid, :sku, :nome,
                        :qta, :ord_pref, :attivo,
                        :rrp_min, :rrp_max, :lead_time, :uom_id, :note, CURRENT_TIMESTAMP)'
                )->execute([
                    ':pid'      => $prodotto_id,
                    ':fid'      => $fornitore_id,
                    ':sku'      => $n('sku_fornitore'),
                    ':nome'     => $n('nome_prodotto_fornitore'),
                    ':qta'      => $n('quantita_minima') !== null ? (float) $n('quantita_minima') : null,
                    ':ord_pref' => $ordPref,
                    ':attivo'   => post('attivo') === '1' ? 1 : 0,
                    ':rrp_min'  => $rrpMin,
                    ':rrp_max'  => $rrpMax,
                    ':lead_time'=> $leadTime,
                    ':uom_id'   => $unitaMisura,
                    ':note'     => $note,
                ]);
                $pfId = (int) $this->pdo->lastInsertId();
            }

            if ($ordPref === 1) {
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori SET ordine_preferenza_fornitore = 2 WHERE prodotto_id = :pid AND id != :id'
                )->execute([':pid' => $prodotto_id, ':id' => $pfId]);
            }

            // D0.3 (PIANO Motore di Pricing Multi-UoM §5.4/§5.5/§8): questo endpoint conteneva un
            // secondo percorso di scrittura su prodotti_fornitori_prezzi ("Listino Base auto-creato"),
            // vestigiale e mai eseguito in pratica — l'unico chiamante reale (products-suppliers.js)
            // manda sempre no_prezzi=1 e salva i prezzi separatamente tramite savePrezzo(). Rimosso
            // perché duplicava (peggio: senza mai valorizzare unita_misura_id) l'unico percorso corretto.
            // I prezzi vanno sempre e solo tramite savePrezzo() (endpoint /products/fornitori/prezzi/save).
            if (post('no_prezzi') !== '1' && post('prezzi') !== null) {
                echo json_encode(['ok' => false, 'error' => 'Il salvataggio dei prezzi da questo endpoint non è più supportato. Usare /products/fornitori/prezzi/save (savePrezzo).']);
                $this->pdo->rollBack();
                exit;
            }

            $this->pdo->commit();

            // Restituisci la riga completa per l'aggiornamento DOM lato client
            $rowData = qk_load_single_prodotto_fornitore($this->pdo, $pfId);
            echo json_encode(['ok' => true, 'row' => $rowData]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => qk_prodotti_db_message($e)]);
        }
        exit;
    }



    public function deleteForn(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id          = (int) post('id');
        $prodotto_id = (int) post('prodotto_id');
        if ($id <= 0 || $prodotto_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM prodotti_fornitori WHERE id = :id AND prodotto_id = :pid'
            )->execute([':id' => $id, ':pid' => $prodotto_id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }



    public function apiFornitoreDettagli(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $pfId = (int) get_param('prodotto_fornitore_id');
        if ($pfId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID fornitore prodotto non valido.']);
            exit;
        }

        try {
            // Ottiene il fornitore_id associato
            $stmtPf = $this->pdo->prepare('SELECT fornitore_id FROM prodotti_fornitori WHERE id = :id');
            $stmtPf->execute([':id' => $pfId]);
            $fornitoreId = (int) ($stmtPf->fetchColumn() ?? 0);

            // Carica tutti i prezzi a scaglione
            $stmtPrezzi = $this->pdo->prepare(
                'SELECT pfp.id, pfp.quantita_minima, pfp.prezzo, pfp.sconto_perc,
                        pfp.valido_dal, pfp.valido_al, pfp.note, pfp.listino_acquisto_id,
                        la.nome_listino
                 FROM prodotti_fornitori_prezzi pfp
                 LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
                 WHERE pfp.prodotto_fornitore_id = :pfid
                 ORDER BY pfp.listino_acquisto_id IS NULL DESC, la.nome_listino ASC, pfp.quantita_minima ASC'
            );
            $stmtPrezzi->execute([':pfid' => $pfId]);
            $prezzi = $stmtPrezzi->fetchAll(PDO::FETCH_ASSOC);

            // Carica tutti i listini per questo fornitore
            $listini = [];
            if ($fornitoreId > 0) {
                $stmtListini = $this->pdo->prepare(
                    'SELECT id, nome_listino 
                     FROM listini_acquisto 
                     WHERE fornitore_id = :fid 
                     ORDER BY nome_listino ASC'
                );
                $stmtListini->execute([':fid' => $fornitoreId]);
                $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['ok' => true, 'prezzi' => $prezzi, 'listini' => $listini]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }



    public function apiFornitoreListini(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $fornitoreId = (int) get_param('fornitore_id');
        if ($fornitoreId <= 0) {
            echo json_encode(['ok' => true, 'listini' => []]);
            exit;
        }

        try {
            $stmtListini = $this->pdo->prepare(
                'SELECT id, nome_listino
                 FROM listini_acquisto
                 WHERE fornitore_id = :fid
                 ORDER BY nome_listino ASC'
            );
            $stmtListini->execute([':fid' => $fornitoreId]);
            $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'listini' => $listini]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

}
