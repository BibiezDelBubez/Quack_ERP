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
    private const LINGUE = ['IT' => 'Italiano', 'EN' => 'English', 'DE' => 'Deutsch', 'FR' => 'Français'];

    /* ════════════════════════════════════════════════════════════════════════
     *  INDEX — lista sostanze
     * ════════════════════════════════════════════════════════════════════════ */

    public function index(): void
    {
        $search = get_param('q');

        $sql = "
            SELECT s.id, s.nome, s.codice_cas, s.nome_tecnico, s.gruppo_imballaggio,
                   s.concentrazione_min_pct, s.concentrazione_max_pct,
                   s.e_soluzione, s.e_rifiuto,
                   s.clp_avvertenza, s.numero_kemler,
                   c.codice_onu, c.classe_pericolo,
                   (SELECT COUNT(*) FROM prodotti p WHERE p.sostanza_chimica_id = s.id AND p.attivo = 1) AS n_prodotti,
                   (SELECT COUNT(*) FROM sostanze_chimiche_schede sd WHERE sd.sostanza_id = s.id AND sd.e_corrente = 1) AS n_schede,
                   (SELECT GROUP_CONCAT(h_codice, ' ') FROM sostanze_h_statements WHERE sostanza_id = s.id) AS h_codes,
                   (SELECT GROUP_CONCAT(pittogramma_cod, ',') FROM sostanze_pittogrammi WHERE sostanza_id = s.id) AS pittogrammi
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
            $_SESSION['flash_error'] = 'Sostanza non trovata.';
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
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $schede             = $this->loadSchede($id);
        $prodotti_associati = $this->loadProdottiAssociati($id);
        $h_selezionati      = $this->loadHSelezionati($id);
        $p_selezionati      = $this->loadPSelezionati($id);
        $pitt_selezionati   = $this->loadPittSelezionati($id);
        $codici_onu         = $this->loadCodiciOnu();
        $clp_data           = $this->loadClpLookup();
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

        // Elimina file SDS fisici
        $schede = $this->loadSchede($id);
        foreach ($schede as $s) {
            $path = UPLOAD_DIR . '/' . ltrim(str_replace('\\', '/', $s['file_path']), '/');
            if (is_file($path)) @unlink($path);
        }

        $this->pdo->prepare('DELETE FROM sostanze_chimiche WHERE id = ?')->execute([$id]);
        redirect_with('adr/sostanze', 'success', 'Sostanza chimica eliminata.');
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  UPLOAD SCHEDA SDS
     * ════════════════════════════════════════════════════════════════════════ */

    public function uploadScheda(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('adr/sostanze'));
        }

        $sostanzaId = (int) post('sostanza_id');
        $lingua     = strtoupper(trim(post('lingua', '')));
        $versione   = trim(post('versione', ''));
        $dataEm     = trim(post('data_emissione', '')) ?: null;

        if ($sostanzaId <= 0 || !array_key_exists($lingua, self::LINGUE)) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Dati non validi.');
        }

        if (empty($_FILES['file_sds']['name'])) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Nessun file selezionato.');
        }

        $file    = $_FILES['file_sds'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx'];
        if (!in_array($ext, $allowed, true)) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Formato non consentito. Usa PDF, DOC o DOCX.');
        }

        $sdsDir  = rtrim(UPLOAD_DIR, '/\\') . '/sds/' . $sostanzaId;
        if (!is_dir($sdsDir) && !mkdir($sdsDir, 0755, true)) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Impossibile creare cartella upload.');
        }

        $nomeFile    = 'SDS_' . $sostanzaId . '_' . $lingua . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs     = $sdsDir . '/' . $nomeFile;
        $filePathRel = 'sds/' . $sostanzaId . '/' . $nomeFile;

        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Upload fallito.');
        }

        try {
            $this->pdo->prepare(
                'UPDATE sostanze_chimiche_schede SET e_corrente = 0 WHERE sostanza_id = ? AND lingua = ?'
            )->execute([$sostanzaId, $lingua]);

            $this->pdo->prepare(
                'INSERT INTO sostanze_chimiche_schede
                   (sostanza_id, lingua, versione, data_emissione, file_path, file_nome_orig, e_corrente)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            )->execute([$sostanzaId, $lingua, $versione ?: null, $dataEm, $filePathRel, $file['name']]);

            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'success', "Scheda SDS ({$lingua}) caricata.");
        } catch (Throwable $e) {
            @unlink($destAbs);
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Errore DB: ' . $e->getMessage());
        }
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  DELETE SCHEDA SDS
     * ════════════════════════════════════════════════════════════════════════ */

    public function deleteScheda(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('adr/sostanze'));
        }

        $schedaId   = (int) post('scheda_id');
        $sostanzaId = (int) post('sostanza_id');

        $st = $this->pdo->prepare('SELECT * FROM sostanze_chimiche_schede WHERE id = ? AND sostanza_id = ?');
        $st->execute([$schedaId, $sostanzaId]);
        $scheda = $st->fetch(PDO::FETCH_ASSOC);

        if (!$scheda) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Scheda non trovata.');
        }

        $path = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim(str_replace('\\', '/', $scheda['file_path']), '/');
        if (is_file($path)) @unlink($path);

        $this->pdo->prepare('DELETE FROM sostanze_chimiche_schede WHERE id = ?')->execute([$schedaId]);
        redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'success', 'Scheda SDS eliminata.');
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  DOWNLOAD SCHEDA SDS
     * ════════════════════════════════════════════════════════════════════════ */

    public function downloadScheda(): void
    {
        $schedaId = (int) get_param('id');
        if ($schedaId <= 0) redirect(url('adr/sostanze'));

        $st = $this->pdo->prepare('SELECT * FROM sostanze_chimiche_schede WHERE id = ?');
        $st->execute([$schedaId]);
        $scheda = $st->fetch(PDO::FETCH_ASSOC);

        if (!$scheda) {
            $_SESSION['flash_error'] = 'File non trovato.';
            redirect(url('adr/sostanze'));
        }

        $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim(str_replace('\\', '/', $scheda['file_path']), '/');
        if (!is_file($abs)) {
            $_SESSION['flash_error'] = 'File fisico non trovato sul server.';
            redirect(url('adr/sostanze/edit?id=' . $scheda['sostanza_id']));
        }

        $ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
            default => 'application/octet-stream',
        };

        $nomeOrig = $scheda['file_nome_orig'] ?: basename($scheda['file_path']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes($nomeOrig) . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=3600');
        readfile($abs);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  API: lista restrizioni compliance per una sostanza
     *  GET /adr/sostanze/api/restrizioni?sostanza_id=X
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiRestrizioniList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $sostanzaId = (int) get_param('sostanza_id');
        if ($sostanzaId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'sostanza_id mancante']);
            exit;
        }

        // Restrizioni dirette sulla sostanza
        $dirette = $this->pdo->prepare("
            SELECT scr.sostanza_id, scr.restriction_type_id,
                   scr.concentrazione_min, scr.concentrazione_max, scr.note,
                   rt.code, rt.name, rt.regulatory_ref, rt.requires_vat,
                   rt.validity_days, rt.active
              FROM sostanze_chimiche_restrizioni scr
              JOIN compliance_restriction_types rt ON rt.id = scr.restriction_type_id
             WHERE scr.sostanza_id = ?
             ORDER BY rt.name
        ");
        $dirette->execute([$sostanzaId]);
        $dati_dirette = $dirette->fetchAll(PDO::FETCH_ASSOC);

        // Restrizioni ereditate dal codice ONU (read-only, informative)
        $ereditate = $this->pdo->prepare("
            SELECT cor.un_code_id, cor.restriction_type_id,
                   cor.min_concentration, cor.max_concentration, cor.notes,
                   rt.code, rt.name, rt.regulatory_ref,
                   c.codice_onu
              FROM sostanze_chimiche sc
              JOIN compliance_codice_onu_restrizioni cor ON cor.un_code_id = sc.codice_onu_id
              JOIN compliance_restriction_types rt ON rt.id = cor.restriction_type_id
              JOIN adr_codici_onu c ON c.id = sc.codice_onu_id
             WHERE sc.id = ?
               AND rt.active = 1
             ORDER BY rt.name
        ");
        $ereditate->execute([$sostanzaId]);
        $dati_ereditate = $ereditate->fetchAll(PDO::FETCH_ASSOC);

        // Tutti i tipi di restrizione attivi (per il select "aggiungi")
        $tutti = $this->pdo->query("
            SELECT id, code, name, regulatory_ref, requires_vat, validity_days
              FROM compliance_restriction_types
             WHERE active = 1
             ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'       => true,
            'dirette'  => $dati_dirette,
            'ereditate'=> $dati_ereditate,
            'tutti'    => $tutti,
        ]);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  API: aggiungi restrizione diretta a una sostanza
     *  POST /adr/sostanze/api/restrizioni/add
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiRestrizioniAdd(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $sostanzaId        = (int) post('sostanza_id');
        $restrictionTypeId = (int) post('restriction_type_id');
        $concMin           = post('concentrazione_min') !== '' ? (float) post('concentrazione_min') : null;
        $concMax           = post('concentrazione_max') !== '' ? (float) post('concentrazione_max') : null;
        $note              = trim(post('note', '')) ?: null;

        if ($sostanzaId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            $this->pdo->prepare("
                INSERT OR REPLACE INTO sostanze_chimiche_restrizioni
                    (sostanza_id, restriction_type_id, concentrazione_min, concentrazione_max, note)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$sostanzaId, $restrictionTypeId, $concMin, $concMax, $note]);

            // Ricarica la riga appena inserita con i dati del tipo
            $row = $this->pdo->prepare("
                SELECT scr.*, rt.code, rt.name, rt.regulatory_ref, rt.requires_vat, rt.validity_days
                  FROM sostanze_chimiche_restrizioni scr
                  JOIN compliance_restriction_types rt ON rt.id = scr.restriction_type_id
                 WHERE scr.sostanza_id = ? AND scr.restriction_type_id = ?
            ");
            $row->execute([$sostanzaId, $restrictionTypeId]);

            echo json_encode(['ok' => true, 'restrizione' => $row->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  API: rimuovi restrizione diretta da una sostanza
     *  POST /adr/sostanze/api/restrizioni/remove
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiRestrizioniRemove(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $sostanzaId        = (int) post('sostanza_id');
        $restrictionTypeId = (int) post('restriction_type_id');

        if ($sostanzaId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            $this->pdo->prepare("
                DELETE FROM sostanze_chimiche_restrizioni
                 WHERE sostanza_id = ? AND restriction_type_id = ?
            ")->execute([$sostanzaId, $restrictionTypeId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

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
        foreach (array_keys($v) as $k) {
            $v[$k] = post($k, (string)($v[$k] ?? ''));
        }
        $v['e_soluzione']    = isset($_POST['e_soluzione'])    ? '1' : '0';
        $v['e_rifiuto']      = isset($_POST['e_rifiuto'])      ? '1' : '0';
        $v['e_stabilizzato'] = isset($_POST['e_stabilizzato']) ? '1' : '0';
        return $v;
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
            $stH = $this->pdo->prepare('INSERT OR IGNORE INTO sostanze_h_statements (sostanza_id, h_codice) VALUES (?,?)');
            foreach ($hCodes as $code) {
                $stH->execute([$id, trim((string)$code)]);
            }
        }

        // P statements
        $this->pdo->prepare('DELETE FROM sostanze_p_statements WHERE sostanza_id = ?')->execute([$id]);
        $pCodes = array_filter((array)($_POST['p_statements'] ?? []));
        if ($pCodes) {
            $stP = $this->pdo->prepare('INSERT OR IGNORE INTO sostanze_p_statements (sostanza_id, p_codice) VALUES (?,?)');
            foreach ($pCodes as $code) {
                $stP->execute([$id, trim((string)$code)]);
            }
        }

        // Pittogrammi
        $this->pdo->prepare('DELETE FROM sostanze_pittogrammi WHERE sostanza_id = ?')->execute([$id]);
        $pittCodes = array_filter((array)($_POST['pittogrammi'] ?? []));
        if ($pittCodes) {
            $stPitt = $this->pdo->prepare('INSERT OR IGNORE INTO sostanze_pittogrammi (sostanza_id, pittogramma_cod) VALUES (?,?)');
            foreach ($pittCodes as $code) {
                $stPitt->execute([$id, trim((string)$code)]);
            }
        }
    }

    private function bindParams(array $v): array
    {
        $n = fn($x) => ($x === '' || $x === null) ? null : trim((string) $x);
        $d = fn($x) => ($x === '' || $x === null) ? null : (float) str_replace(',', '.', (string) $x);
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
        $st = $this->pdo->prepare('SELECT * FROM sostanze_chimiche WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function loadSchede(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM sostanze_chimiche_schede WHERE sostanza_id = ? ORDER BY lingua, e_corrente DESC, created_at DESC'
        );
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadProdottiAssociati(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.id, p.sku_interno, p.nome, p.attivo
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

    public static function lingue(): array { return self::LINGUE; }
}
