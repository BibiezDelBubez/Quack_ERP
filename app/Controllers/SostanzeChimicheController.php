<?php

declare(strict_types=1);

/**
 * SostanzeChimicheController — /adr/sostanze/*
 *
 * CRUD per l'anagrafica sostanze chimiche (tier 2: ONU → Sostanza → Prodotto).
 * Gestisce SDS multi-lingua, classificazione CLP/GHS (H/P statements, pittogrammi)
 * e i prodotti SKU associati.
 */
final class SostanzeChimicheController extends BaseController
{
    /* ════════════════════════════════════════════════════════════════════════
     *  INDEX — lista sostanze
     * ════════════════════════════════════════════════════════════════════════ */

    public function index(): void
    {
        $search = get_param('q');

        $hCodesConcat    = db_group_concat_sql('h_codice', ' ');
        $pittogrammiConcat = db_group_concat_sql('pittogramma_cod', ',');

        $sql = "
            SELECT s.id, s.nome, s.codice_cas, s.nome_tecnico, s.gruppo_imballaggio,
                   s.concentrazione_min_pct, s.concentrazione_max_pct,
                   s.e_soluzione, s.e_rifiuto,
                   s.clp_avvertenza, s.numero_kemler,
                   c.codice_onu, c.classe_pericolo,
                   (SELECT COUNT(*) FROM prodotti p WHERE p.sostanza_chimica_id = s.id AND p.attivo = 1) AS n_prodotti,
                   (SELECT {$hCodesConcat} FROM sostanze_h_statements WHERE sostanza_id = s.id) AS h_codes,
                   (SELECT {$pittogrammiConcat} FROM sostanze_pittogrammi WHERE sostanza_id = s.id) AS pittogrammi
              FROM sostanze_chimiche s
         LEFT JOIN adr_codici_onu c ON c.id = s.codice_onu_id
             WHERE 1=1
        ";
        $params = [];
        if ($search) {
            $sql .= " AND (s.nome LIKE :q OR s.nome_tecnico LIKE :q OR CAST(c.codice_onu AS TEXT) LIKE :q OR s.codice_cas LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY s.nome COLLATE NOCASE";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $sostanze = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $page_title    = 'Sostanze Chimiche';
        $page_subtitle = 'Schede delle sostanze chimiche — codici ONU, SDS, CLP e restrizioni di vendita';
        $breadcrumbs   = [['label' => 'ADR', 'url' => url('adr')], ['label' => 'Sostanze Chimiche']];

        require INCLUDES_DIR . '/header.php';
        include APP_ROOT_DIR . '/app/views/adr/sostanze/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CREATE
     * ════════════════════════════════════════════════════════════════════════ */

    public function create(): void
    {
        $mode   = 'create';
        $errors = [];
        $v      = $this->defaults();
        $schede = [];
        $prodotti_associati = [];
        $h_selezionati = [];
        $p_selezionati = [];
        $pitt_selezionati = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $v      = $this->readPost($v);
                $errors = $this->validate($v, null);
                if (empty($errors)) {
                    try {
                        $id = $this->insertSostanza($v);
                        redirect_with('adr/sostanze/edit?id=' . $id, 'success', 'Sostanza chimica creata.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $codici_onu   = $this->loadCodiciOnu();
        $clp_data     = $this->loadClpLookup();
        $page_title   = 'Nuova Sostanza Chimica';
        $breadcrumbs  = [
            ['label' => 'ADR', 'url' => url('adr')],
            ['label' => 'Sostanze Chimiche', 'url' => url('adr/sostanze')],
            ['label' => 'Nuova'],
        ];

        require INCLUDES_DIR . '/header.php';
        include APP_ROOT_DIR . '/app/views/adr/sostanze/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  EDIT
     * ════════════════════════════════════════════════════════════════════════ */

    public function edit(): void
    {
        $id = (int) get_param('id');
        if ($id <= 0) redirect(url('adr/sostanze'));

        $mode   = 'edit';
        $errors = [];

        $row = $this->loadSostanza($id);
        if (!$row) {
            flash_set('error', 'Sostanza non trovata.');
            redirect(url('adr/sostanze'));
        }

        $v = $row;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $v      = $this->readPost($v);
                $errors = $this->validate($v, $id);
                if (empty($errors)) {
                    try {
                        $this->updateSostanza($id, $v);
                        redirect_with('adr/sostanze/edit?id=' . $id, 'success', 'Sostanza chimica aggiornata.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        require_once APP_ROOT_DIR . '/includes/prodotti.php';
        $prodotti_associati = $this->loadProdottiAssociati($id);
        $h_selezionati      = $this->loadHSelezionati($id);
        $p_selezionati      = $this->loadPSelezionati($id);
        $pitt_selezionati   = $this->loadPittSelezionati($id);
        $codici_onu         = $this->loadCodiciOnu();
        $clp_data           = $this->loadClpLookup();
        $categories         = qk_load_categorie($this->pdo);
        $page_title         = 'Sostanza: ' . e($row['nome']);
        $breadcrumbs        = [
            ['label' => 'ADR', 'url' => url('adr')],
            ['label' => 'Sostanze Chimiche', 'url' => url('adr/sostanze')],
            ['label' => e($row['nome'])],
        ];

        require INCLUDES_DIR . '/header.php';
        include APP_ROOT_DIR . '/app/views/adr/sostanze/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  DELETE
     * ════════════════════════════════════════════════════════════════════════ */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('adr/sostanze'));
        }

        $id = (int) post('id');
        if ($id <= 0) redirect(url('adr/sostanze'));

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sostanza_chimica_id = ?');
        $st->execute([$id]);
        $nProd = (int) $st->fetchColumn();

        if ($nProd > 0) {
            redirect_with('adr/sostanze', 'error', "Impossibile eliminare: {$nProd} prodott" . ($nProd === 1 ? 'o usa' : 'i usano') . " questa sostanza.");
        }

        $this->pdo->prepare('DELETE FROM sostanze_chimiche WHERE id = ?')->execute([$id]);
        redirect_with('adr/sostanze', 'success', 'Sostanza chimica eliminata.');
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  API: lista restrizioni compliance per una sostanza


    /* ════════════════════════════════════════════════════════════════════════
     *  API: check sostanze duplicate per codice ONU
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiCheckOnu(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $codiceOnuId  = (int) get_param('codice_onu_id');
        $excludeId    = (int) get_param('exclude_id');
        $gruppoRaw    = strtoupper(trim(get_param('gruppo_imballaggio', '')));
        $gruppoImball = in_array($gruppoRaw, ['I', 'II', 'III'], true) ? $gruppoRaw : null;

        if ($codiceOnuId <= 0) {
            echo json_encode(['sostanze' => [], 'match_type' => null]);
            exit;
        }

        $sql    = 'SELECT s.id, s.nome, s.codice_cas, s.gruppo_imballaggio
                     FROM sostanze_chimiche s
                    WHERE s.codice_onu_id = ?';
        $params = [$codiceOnuId];

        if ($gruppoImball !== null) {
            $sql .= ' AND s.gruppo_imballaggio = ?';
            $params[] = $gruppoImball;
        }
        if ($excludeId > 0) {
            $sql .= ' AND s.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY s.nome COLLATE NOCASE LIMIT 10';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $sostanze = $st->fetchAll(PDO::FETCH_ASSOC);

        $matchType = $gruppoImball !== null ? 'onu+gi' : 'onu';
        echo json_encode(['sostanze' => $sostanze, 'match_type' => $matchType]);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  Helper privati — dati
     * ════════════════════════════════════════════════════════════════════════ */

    private function defaults(): array
    {
        return [
            'nome'                   => '',
            'codice_cas'             => '',
            'codice_onu_id'          => '',
            'nome_tecnico'           => '',
            'gruppo_imballaggio'     => '',
            'concentrazione_min_pct' => '',
            'concentrazione_max_pct' => '',
            'punto_infiammabilita_c' => '',
            'e_soluzione'            => '0',
            'e_rifiuto'              => '0',
            'e_stabilizzato'         => '0',
            // nuovi
            'codice_ufi'             => '',
            'codice_einecs'          => '',
            'codice_reach'           => '',
            'numero_kemler'          => '',
            'stato_fisico'           => '',
            'clp_avvertenza'         => '',
            'note_interne'           => '',
        ];
    }

    private function readPost(array $v): array
    {
        return $this->readFormValues(
            $v,
            array_keys($this->defaults()),
            ['e_soluzione', 'e_rifiuto', 'e_stabilizzato']
        );
    }

    private function validate(array $v, ?int $editId = null): array
    {
        $errors = [];
        if (trim($v['nome']) === '') {
            $errors['nome'] = 'Il nome è obbligatorio.';
        }
        $cas = trim($v['codice_cas'] ?? '');
        if ($cas !== '') {
            $st = $this->pdo->prepare(
                'SELECT id FROM sostanze_chimiche WHERE codice_cas = ? AND (:id IS NULL OR id != :id)'
            );
            $st->execute([$cas, $editId, $editId]);
            if ($st->fetch()) {
                $errors['codice_cas'] = 'Questo codice CAS è già registrato per un\'altra sostanza.';
            }
        }
        return $errors;
    }

    private function insertSostanza(array $v): int
    {
        $this->pdo->prepare(
            'INSERT INTO sostanze_chimiche
               (nome, codice_cas, codice_onu_id, nome_tecnico, gruppo_imballaggio,
                concentrazione_min_pct, concentrazione_max_pct, punto_infiammabilita_c,
                e_soluzione, e_rifiuto, e_stabilizzato,
                codice_ufi, codice_einecs, codice_reach, numero_kemler,
                stato_fisico, clp_avvertenza, note_interne)
             VALUES
               (:nome, :codice_cas, :codice_onu_id, :nome_tecnico, :gruppo_imballaggio,
                :conc_min, :conc_max, :flash,
                :e_sol, :e_rif, :e_stab,
                :ufi, :einecs, :reach, :kemler,
                :stato_fisico, :clp_avvertenza, :note)'
        )->execute($this->bindParams($v));
        $id = (int) $this->pdo->lastInsertId();
        $this->saveClpPivot($id, $v);
        return $id;
    }

    private function updateSostanza(int $id, array $v): void
    {
        $params = $this->bindParams($v);
        $params[':id'] = $id;
        $this->pdo->prepare(
            'UPDATE sostanze_chimiche SET
               nome = :nome, codice_cas = :codice_cas,
               codice_onu_id = :codice_onu_id, nome_tecnico = :nome_tecnico,
               gruppo_imballaggio = :gruppo_imballaggio,
               concentrazione_min_pct = :conc_min, concentrazione_max_pct = :conc_max,
               punto_infiammabilita_c = :flash,
               e_soluzione = :e_sol, e_rifiuto = :e_rif, e_stabilizzato = :e_stab,
               codice_ufi = :ufi, codice_einecs = :einecs, codice_reach = :reach,
               numero_kemler = :kemler, stato_fisico = :stato_fisico,
               clp_avvertenza = :clp_avvertenza, note_interne = :note,
               updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute($params);
        $this->saveClpPivot($id, $v);
    }

    /**
     * Salva H statements, P statements e pittogrammi per una sostanza.
     * Legge dal POST: h_statements[] p_statements[] pittogrammi[]
     */
    private function saveClpPivot(int $id, array $v): void
    {
        // H statements
        $this->pdo->prepare('DELETE FROM sostanze_h_statements WHERE sostanza_id = ?')->execute([$id]);
        $hCodes = array_filter((array)($_POST['h_statements'] ?? []));
        if ($hCodes) {
            $stH = $this->pdo->prepare(db_insert_ignore_sql('sostanze_h_statements (sostanza_id, h_codice) VALUES (?,?)'));
            foreach ($hCodes as $code) {
                $stH->execute([$id, trim((string)$code)]);
            }
        }

        // P statements
        $this->pdo->prepare('DELETE FROM sostanze_p_statements WHERE sostanza_id = ?')->execute([$id]);
        $pCodes = array_filter((array)($_POST['p_statements'] ?? []));
        if ($pCodes) {
            $stP = $this->pdo->prepare(db_insert_ignore_sql('sostanze_p_statements (sostanza_id, p_codice) VALUES (?,?)'));
            foreach ($pCodes as $code) {
                $stP->execute([$id, trim((string)$code)]);
            }
        }

        // Pittogrammi
        $this->pdo->prepare('DELETE FROM sostanze_pittogrammi WHERE sostanza_id = ?')->execute([$id]);
        $pittCodes = array_filter((array)($_POST['pittogrammi'] ?? []));
        if ($pittCodes) {
            $stPitt = $this->pdo->prepare(db_insert_ignore_sql('sostanze_pittogrammi (sostanza_id, pittogramma_cod) VALUES (?,?)'));
            foreach ($pittCodes as $code) {
                $stPitt->execute([$id, trim((string)$code)]);
            }
        }
    }

    private function bindParams(array $v): array
    {
        $n = fn($x) => ($x === '' || $x === null) ? null : trim((string) $x);
        $d = fn($x) => ($x === '' || $x === null) ? null : str_replace(',', '.', (string) $x);
        return [
            ':nome'              => trim($v['nome']),
            ':codice_cas'        => $n($v['codice_cas'] ?? ''),
            ':codice_onu_id'     => $n($v['codice_onu_id']) !== null ? (int) $v['codice_onu_id'] : null,
            ':nome_tecnico'      => $n($v['nome_tecnico']),
            ':gruppo_imballaggio'=> $n($v['gruppo_imballaggio']),
            ':conc_min'          => $d($v['concentrazione_min_pct']),
            ':conc_max'          => $d($v['concentrazione_max_pct']),
            ':flash'             => $d($v['punto_infiammabilita_c']),
            ':e_sol'             => $v['e_soluzione']    === '1' ? 1 : 0,
            ':e_rif'             => $v['e_rifiuto']      === '1' ? 1 : 0,
            ':e_stab'            => $v['e_stabilizzato'] === '1' ? 1 : 0,
            ':ufi'               => $n($v['codice_ufi'] ?? ''),
            ':einecs'            => $n($v['codice_einecs'] ?? ''),
            ':reach'             => $n($v['codice_reach'] ?? ''),
            ':kemler'            => $n($v['numero_kemler'] ?? ''),
            ':stato_fisico'      => $n($v['stato_fisico'] ?? ''),
            ':clp_avvertenza'    => $n($v['clp_avvertenza'] ?? ''),
            ':note'              => $n($v['note_interne'] ?? ''),
        ];
    }

    private function loadSostanza(int $id): ?array
    {
        $st = $this->pdo->prepare('
            SELECT s.*, c.codice_onu, c.nome_descrizione AS codice_onu_desc, c.classe_pericolo AS codice_onu_classe
              FROM sostanze_chimiche s
         LEFT JOIN adr_codici_onu c ON c.id = s.codice_onu_id
             WHERE s.id = ?
        ');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function loadProdottiAssociati(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.id, p.sku_interno, p.nome, p.attivo, p.categoria_id
               FROM prodotti p
              WHERE p.sostanza_chimica_id = ?
              ORDER BY p.nome COLLATE NOCASE'
        );
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadCodiciOnu(): array
    {
        return $this->pdo->query(
            'SELECT id, codice_onu, nome_descrizione, classe_pericolo
               FROM adr_codici_onu
              ORDER BY codice_onu'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Carica tutti i dati CLP per il form: h_statements, p_statements, pittogrammi */
    private function loadClpLookup(): array
    {
        return [
            'h_statements' => $this->pdo->query(
                "SELECT codice, testo, tipo FROM clp_h_statements ORDER BY tipo, codice"
            )->fetchAll(PDO::FETCH_ASSOC),
            'p_statements' => $this->pdo->query(
                "SELECT codice, testo, tipo FROM clp_p_statements ORDER BY tipo, codice"
            )->fetchAll(PDO::FETCH_ASSOC),
            'pittogrammi' => $this->pdo->query(
                "SELECT codice, nome, descrizione FROM clp_pittogrammi ORDER BY codice"
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function loadHSelezionati(int $id): array
    {
        $st = $this->pdo->prepare('SELECT h_codice FROM sostanze_h_statements WHERE sostanza_id = ?');
        $st->execute([$id]);
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'h_codice');
    }

    private function loadPSelezionati(int $id): array
    {
        $st = $this->pdo->prepare('SELECT p_codice FROM sostanze_p_statements WHERE sostanza_id = ?');
        $st->execute([$id]);
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'p_codice');
    }

    private function loadPittSelezionati(int $id): array
    {
        $st = $this->pdo->prepare('SELECT pittogramma_cod FROM sostanze_pittogrammi WHERE sostanza_id = ?');
        $st->execute([$id]);
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'pittogramma_cod');
    }

    public function apiAssociaProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']);
            exit;
        }

        $sostanzaId = (int) post('sostanza_id');
        $prodottoIds = (array) ($_POST['prodotto_ids'] ?? []);

        if ($sostanzaId <= 0 || empty($prodottoIds)) {
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = ?, is_adr = 1 WHERE id = ?');
            foreach ($prodottoIds as $pId) {
                $stmt->execute([$sostanzaId, (int)$pId]);
            }
            $this->pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiDisassociaProdotto(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']);
            exit;
        }

        $prodottoId = (int) post('prodotto_id');
        $prodottoIds = (array) ($_POST['prodotto_ids'] ?? []);

        if ($prodottoId > 0) {
            $prodottoIds[] = $prodottoId;
        }

        if (empty($prodottoIds)) {
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = NULL WHERE id = ?');
            foreach ($prodottoIds as $pId) {
                $stmt->execute([(int)$pId]);
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
