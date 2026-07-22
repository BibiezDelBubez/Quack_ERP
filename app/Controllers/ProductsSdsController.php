<?php

declare(strict_types=1);

require_once __DIR__ . '/../Services/SchedeSicurezzaService.php';

/**
 * ProductsSdsController — endpoint HTTP per le Schede Dati di Sicurezza (SDS)
 * agganciate a prodotto-fornitore.
 *
 * Contesto: docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS) su
 * Prodotto-Fornitore.md (M3). Questo controller è l'unico punto che tocca
 * $_FILES, UPLOAD_DIR e la transazione DB (§3.12) — tutta la logica di
 * dominio (validazione, overlap, scoping "corrente") vive in
 * App\Services\SchedeSicurezzaService (M2), qui solo richiamata e composta.
 */
final class ProductsSdsController extends BaseController
{
    /**
     * GET /products/sds/list?prodotto_id=X — §5.2 del piano.
     */
    public function apiSdsList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $prodottoId = (int) get_param('prodotto_id');
        if ($prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID prodotto non valido.']);
            exit;
        }

        try {
            $svc = new \App\Services\SchedeSicurezzaService($this->pdo);
            $righe = array_map([$this, 'formatRiga'], $svc->getSchede($prodottoId));
            echo json_encode(['ok' => true, 'righe' => $righe]);
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Errore nel caricamento delle schede.']);
        }
        exit;
    }

    /**
     * POST /products/sds/save — §5.3 del piano.
     *
     * Implementa esattamente l'ordine atomico di §3.12: (1) validazione
     * campi, (2) checkOverlap contro il gruppo di destinazione, (3) mime +
     * scrittura file SOLO se 1-2 superati, (4-7) transazione DB con
     * rollback + unlink in caso di eccezione.
     */
    public function apiSdsSave(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id            = (int) post('id'); // 0 = insert, > 0 = update
        $pfId          = (int) post('prodotto_fornitore_id');
        $prodottoId    = (int) post('prodotto_id'); // per verifica ownership
        $linguaGrezza  = post('lingua');
        $versione      = post('versione') ?: null;
        $dataEmissione = post('data_emissione') ?: null;
        $validoDal     = post('valido_dal') ?: null;
        $validoAl      = post('valido_al') ?: null;
        $note          = post('note') ?: null;

        if ($pfId <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }

        // Ownership: prodotto_fornitore_id deve appartenere davvero al prodotto indicato
        // (mai fidarsi solo del prodotto_fornitore_id ricevuto dal client — §4.4 del piano:
        // è anche l'unico modo per distinguere due righe prodotti_fornitori dello stesso
        // fornitore, dato che non esiste un vincolo UNIQUE(prodotto_id, fornitore_id)).
        $stmtOwn = $this->pdo->prepare('SELECT id FROM prodotti_fornitori WHERE id = :pfid AND prodotto_id = :pid');
        $stmtOwn->execute([':pfid' => $pfId, ':pid' => $prodottoId]);
        if (!$stmtOwn->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato o accesso non autorizzato.']); exit;
        }

        $svc = new \App\Services\SchedeSicurezzaService($this->pdo);

        /* ── Passo 1: validazione di tutti i campi (§3.12) ──────────────── */
        try {
            $lingua = $svc->validaLingua($linguaGrezza);
        } catch (\RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
        }

        if ($validoDal !== null && $validoAl !== null && $validoDal > $validoAl) {
            echo json_encode(['ok' => false, 'error' => 'La data "valido dal" non può essere successiva a "valido al".']); exit;
        }

        $rigaEsistente = null;
        if ($id > 0) {
            $rigaEsistente = $svc->getSchedaOwned($id, $prodottoId);
            if (!$rigaEsistente) {
                echo json_encode(['ok' => false, 'error' => 'Scheda non trovata o accesso non autorizzato.']); exit;
            }
        }

        /* ── Passo 2: checkOverlap contro il gruppo di destinazione ─────── */
        // Su update, $id esclude la riga stessa dal controllo (§3.11: anche
        // in caso di cambio lingua, si controlla sempre il gruppo di ARRIVO).
        $conflitto = $svc->checkOverlap($pfId, $lingua, $validoDal, $validoAl, $id);
        if ($conflitto !== null) {
            echo json_encode([
                'ok' => false,
                'error' => 'overlap',
                'conflitto' => [
                    'id' => (int) $conflitto['id'],
                    'lingua' => $lingua,
                    'valido_dal' => $conflitto['valido_dal'],
                    'valido_al' => $conflitto['valido_al'],
                ],
            ]);
            exit;
        }

        /* ── Passo 3: mime + scrittura file, SOLO se 1-2 superati ───────── */
        $haFile = !empty($_FILES['sds_file']['name']);
        if ($id <= 0 && !$haFile) {
            echo json_encode(['ok' => false, 'error' => 'Devi allegare un file per una nuova scheda.']); exit;
        }

        $nuovoFilePathRel = null;
        $nuovoFileNomeOrig = null;
        $destAbs = null;
        if ($haFile) {
            try {
                $ext = $svc->validaMimeFile($_FILES['sds_file']['tmp_name']);
            } catch (\RuntimeException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
            }
            $nomeFisico = $svc->generaNomeFisico($ext);
            $dirRel = "schede_sicurezza/{$pfId}/{$lingua}";
            upload_ensure_dir($dirRel);
            $nuovoFilePathRel = $dirRel . '/' . $nomeFisico;
            $destAbs = upload_abs($nuovoFilePathRel);
            if (!move_uploaded_file($_FILES['sds_file']['tmp_name'], $destAbs)) {
                echo json_encode(['ok' => false, 'error' => 'Caricamento del file non riuscito.']); exit;
            }
            $nuovoFileNomeOrig = $_FILES['sds_file']['name'];
        }

        /* ── Passi 4-7: transazione (§3.12) ──────────────────────────────
         * insert/update + ricalcolaCorrente insieme, o nessuno dei due:
         * l'atomicità evita che un crash tra le due scritture lasci due
         * righe e_corrente=1 nello stesso gruppo. */
        try {
            $this->pdo->beginTransaction();

            $dati = [
                'prodotto_fornitore_id' => $pfId,
                'lingua'                => $lingua,
                'versione'              => $versione,
                'data_emissione'        => $dataEmissione,
                'valido_dal'            => $validoDal,
                'valido_al'             => $validoAl,
                'note'                  => $note,
            ];
            if ($haFile) {
                $dati['file_path']      = $nuovoFilePathRel;
                $dati['file_nome_orig'] = $nuovoFileNomeOrig;
            }

            if ($id > 0) {
                $svc->aggiornaScheda($id, $dati);
                // §3.11: ricalcola SOLO se la riga era già e_corrente=1, sul
                // gruppo di ARRIVO (nuova lingua, anche se invariata rispetto
                // a prima). Nessuna azione sul gruppo di provenienza — non è
                // un'omissione, è la decisione esplicita del piano.
                if ((int) $rigaEsistente['e_corrente'] === 1) {
                    $svc->ricalcolaCorrente($pfId, $lingua, $id);
                }
            } else {
                $dati['creato_da'] = (int) ($_SESSION['user_id'] ?? 0) ?: null;
                $id = $svc->inserisciScheda($dati);
                // Un nuovo upload è sempre e_corrente=1 (inserisciScheda lo
                // imposta già) — azzera le eventuali righe sorelle del gruppo.
                $svc->ricalcolaCorrente($pfId, $lingua, $id);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($destAbs !== null && is_file($destAbs)) {
                @unlink($destAbs); // evita file orfano se il DB non ha confermato la scrittura
            }
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            $msg = $e instanceof PDOException ? $this->dbMessage($e) : 'Errore nel salvataggio.';
            echo json_encode(['ok' => false, 'error' => $msg]);
            exit;
        }

        $rigaSalvata = $svc->getSchedaOwned($id, $prodottoId);
        echo json_encode(['ok' => true, 'riga' => $this->formatRiga($rigaSalvata)]);
        exit;
    }

    /**
     * POST /products/sds/delete — §5.4 del piano.
     *
     * Elimina riga + file SOLO se la scheda non è mai entrata in vigore
     * (valido_dal futuro, §3.13). Altrimenti errore "in_vigore" che propone
     * l'alternativa "Rimuovi solo il file".
     */
    public function apiSdsDelete(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');
        $prodottoId = (int) post('prodotto_id');
        if ($id <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        $svc = new \App\Services\SchedeSicurezzaService($this->pdo);
        $riga = $svc->getSchedaOwned($id, $prodottoId);
        if (!$riga) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato o accesso non autorizzato.']); exit;
        }

        $errore = $svc->verificaEliminabile($riga);
        if ($errore !== null) {
            echo json_encode($errore);
            exit;
        }

        try {
            $this->pdo->beginTransaction();
            $filePath = $svc->eliminaRiga($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Errore durante l\'eliminazione.']);
            exit;
        }

        if ($filePath) {
            @unlink(upload_abs($filePath));
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * POST /products/sds/rimuovi-file — §5.4bis del piano.
     *
     * Rimuove solo il file fisico (metadati conservati) — sempre permesso,
     * indipendentemente da validità/correntezza (§3.13).
     */
    public function apiSdsRimuoviFile(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');
        $prodottoId = (int) post('prodotto_id');
        if ($id <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        $svc = new \App\Services\SchedeSicurezzaService($this->pdo);
        $riga = $svc->getSchedaOwned($id, $prodottoId);
        if (!$riga) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato o accesso non autorizzato.']); exit;
        }

        try {
            $this->pdo->beginTransaction();
            $vecchioPath = $svc->rimuoviSoloFile($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione del file.']);
            exit;
        }

        if ($vecchioPath) {
            @unlink(upload_abs($vecchioPath));
        }

        $rigaAggiornata = $svc->getSchedaOwned($id, $prodottoId);
        echo json_encode(['ok' => true, 'riga' => $this->formatRiga($rigaAggiornata)]);
        exit;
    }

    /**
     * Formatta una riga per la risposta JSON (§5.2 del piano, con due
     * estensioni necessarie per M5 rispetto all'esempio nel piano:
     * fornitore_attivo per il badge "Inattivo" §4.23, e updated_at).
     * valido_dal_key (colonna generata, §3.15) non viene mai esposta: è un
     * dettaglio puramente interno al database.
     */
    private function formatRiga(array $r): array
    {
        return [
            'id'                    => (int) $r['id'],
            'prodotto_fornitore_id' => (int) $r['prodotto_fornitore_id'],
            'fornitore_id'          => (int) $r['fornitore_id'],
            'nome_fornitore'        => $r['nome_fornitore'],
            'fornitore_attivo'      => (bool) $r['fornitore_attivo'],
            'lingua'                => $r['lingua'],
            'versione'              => $r['versione'],
            'data_emissione'        => $r['data_emissione'],
            'valido_dal'            => $r['valido_dal'],
            'valido_al'             => $r['valido_al'],
            'file_path'             => $r['file_path'],
            'file_nome_orig'        => $r['file_nome_orig'],
            'file_rimosso_il'       => $r['file_rimosso_il'],
            'e_corrente'            => (bool) $r['e_corrente'],
            'created_at'            => $r['created_at'],
            'updated_at'            => $r['updated_at'],
            'note'                  => $r['note'],
        ];
    }
}
