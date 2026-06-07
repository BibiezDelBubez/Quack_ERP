<?php

declare(strict_types=1);

/**
 * SostanzeAdrController — /adr/sostanze/*
 *
 * CRUD per l'anagrafica sostanze ADR (tier 2 del modello ONU → Sostanza → Prodotto).
 * Gestisce anche l'upload/download/delete delle Schede di Sicurezza (SDS) per lingua.
 */
final class SostanzeAdrController extends BaseController
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
                   c.codice_onu, c.classe_pericolo,
                   (SELECT COUNT(*) FROM prodotti p WHERE p.sostanza_adr_id = s.id AND p.attivo = 1) AS n_prodotti,
                   (SELECT COUNT(*) FROM sostanze_adr_schede sd WHERE sd.sostanza_id = s.id AND sd.e_corrente = 1) AS n_schede
              FROM sostanze_adr s
         LEFT JOIN adr_codici_onu c ON c.id = s.codice_onu_id
             WHERE 1=1
        ";
        $params = [];
        if ($search) {
            $sql .= " AND (s.nome LIKE :q OR s.nome_tecnico LIKE :q OR c.codice_onu LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY s.nome COLLATE NOCASE";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $sostanze = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $page_title    = 'Schede Sostanze ADR';
        $page_subtitle = 'Anagrafica sostanze pericolose (tier 2: ONU → Sostanza → Prodotto)';
        $breadcrumbs   = [['label' => 'ADR', 'url' => url('adr')], ['label' => 'Schede Sostanze ADR']];

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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $v      = $this->readPost($v);
                $errors = $this->validate($v, null);
                if (empty($errors)) {
                    try {
                        $id = $this->insertSostanza($v);
                        redirect_with('adr/sostanze/edit?id=' . $id, 'success', 'Scheda Sostanza ADR creata.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $codici_onu = $this->loadCodiciOnu();
        $page_title    = 'Nuova Scheda Sostanza ADR';
        $breadcrumbs   = [
            ['label' => 'ADR', 'url' => url('adr')],
            ['label' => 'Schede Sostanze ADR', 'url' => url('adr/sostanze')],
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
                $errors = $this->validate($v, $id);  // passa $id per escludere se stesso dal check CAS
                if (empty($errors)) {
                    try {
                        $this->updateSostanza($id, $v);
                        redirect_with('adr/sostanze/edit?id=' . $id, 'success', 'Scheda Sostanza ADR aggiornata.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $schede             = $this->loadSchede($id);
        $prodotti_associati = $this->loadProdottiAssociati($id);
        $codici_onu         = $this->loadCodiciOnu();
        $page_title         = 'Scheda Sostanza ADR: ' . e($row['nome']);
        $breadcrumbs        = [
            ['label' => 'ADR', 'url' => url('adr')],
            ['label' => 'Schede Sostanze ADR', 'url' => url('adr/sostanze')],
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

        $n = (int) $this->pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sostanza_adr_id = ?')
                              ->execute([$id]) && $this->pdo->query('SELECT COUNT(*) FROM prodotti WHERE sostanza_adr_id = ' . $id)->fetchColumn();

        // Ricontrolla sicuro
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sostanza_adr_id = ?');
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

        $this->pdo->prepare('DELETE FROM sostanze_adr WHERE id = ?')->execute([$id]);
        redirect_with('adr/sostanze', 'success', 'Scheda Sostanza ADR eliminata.');
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

        $nomeFile   = 'SDS_' . $sostanzaId . '_' . $lingua . '_' . date('Ymd_His') . '.' . $ext;
        $destAbs    = $sdsDir . '/' . $nomeFile;
        $filePathRel = 'sds/' . $sostanzaId . '/' . $nomeFile;

        if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Upload fallito.');
        }

        try {
            // Marca tutte le versioni precedenti della stessa lingua come non correnti
            $this->pdo->prepare(
                'UPDATE sostanze_adr_schede SET e_corrente = 0 WHERE sostanza_id = ? AND lingua = ?'
            )->execute([$sostanzaId, $lingua]);

            $this->pdo->prepare(
                'INSERT INTO sostanze_adr_schede
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

        $st = $this->pdo->prepare('SELECT * FROM sostanze_adr_schede WHERE id = ? AND sostanza_id = ?');
        $st->execute([$schedaId, $sostanzaId]);
        $scheda = $st->fetch(PDO::FETCH_ASSOC);

        if (!$scheda) {
            redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'error', 'Scheda non trovata.');
        }

        $path = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim(str_replace('\\', '/', $scheda['file_path']), '/');
        if (is_file($path)) @unlink($path);

        $this->pdo->prepare('DELETE FROM sostanze_adr_schede WHERE id = ?')->execute([$schedaId]);
        redirect_with('adr/sostanze/edit?id=' . $sostanzaId, 'success', 'Scheda SDS eliminata.');
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  DOWNLOAD SCHEDA SDS
     * ════════════════════════════════════════════════════════════════════════ */

    public function downloadScheda(): void
    {
        $schedaId = (int) get_param('id');
        if ($schedaId <= 0) redirect(url('adr/sostanze'));

        $st = $this->pdo->prepare('SELECT * FROM sostanze_adr_schede WHERE id = ?');
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
     *  API: check sostanze duplicate per codice ONU
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiCheckOnu(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $codiceOnuId    = (int) get_param('codice_onu_id');
        $excludeId      = (int) get_param('exclude_id');
        $gruppoRaw      = strtoupper(trim(get_param('gruppo_imballaggio', '')));
        $gruppoImball   = in_array($gruppoRaw, ['I', 'II', 'III'], true) ? $gruppoRaw : null;

        if ($codiceOnuId <= 0) {
            echo json_encode(['sostanze' => [], 'match_type' => null]);
            exit;
        }

        $sql    = 'SELECT s.id, s.nome, s.codice_cas, s.gruppo_imballaggio
                     FROM sostanze_adr s
                    WHERE s.codice_onu_id = ?';
        $params = [$codiceOnuId];

        // Se il GI è specificato, filtra per entrambi (match più preciso)
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

        // match_type comunica al frontend quanto è preciso il match
        $matchType = $gruppoImball !== null ? 'onu+gi' : 'onu';
        echo json_encode(['sostanze' => $sostanze, 'match_type' => $matchType]);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  Helper privati
     * ════════════════════════════════════════════════════════════════════════ */

    private function defaults(): array
    {
        return [
            'nome'                    => '',
            'codice_cas'              => '',
            'codice_onu_id'           => '',
            'nome_tecnico'            => '',
            'gruppo_imballaggio'      => '',
            'concentrazione_min_pct'  => '',
            'concentrazione_max_pct'  => '',
            'punto_infiammabilita_c'  => '',
            'e_soluzione'             => '0',
            'e_rifiuto'               => '0',
            'e_stabilizzato'          => '0',
            'note_interne'            => '',
        ];
    }

    private function readPost(array $v): array
    {
        foreach (array_keys($v) as $k) {
            $v[$k] = post($k, (string)($v[$k] ?? ''));
        }
        $v['e_soluzione']   = isset($_POST['e_soluzione'])   ? '1' : '0';
        $v['e_rifiuto']     = isset($_POST['e_rifiuto'])     ? '1' : '0';
        $v['e_stabilizzato']= isset($_POST['e_stabilizzato']) ? '1' : '0';
        return $v;
    }

    private function validate(array $v, ?int $editId = null): array
    {
        $errors = [];
        if (trim($v['nome']) === '') {
            $errors['nome'] = 'Il nome è obbligatorio.';
        }
        // Unicità CAS
        $cas = trim($v['codice_cas'] ?? '');
        if ($cas !== '') {
            $st = $this->pdo->prepare(
                'SELECT id FROM sostanze_adr WHERE codice_cas = ? AND (:id IS NULL OR id != :id)'
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
            'INSERT INTO sostanze_adr
               (nome, codice_cas, codice_onu_id, nome_tecnico, gruppo_imballaggio,
                concentrazione_min_pct, concentrazione_max_pct, punto_infiammabilita_c,
                e_soluzione, e_rifiuto, e_stabilizzato, note_interne)
             VALUES
               (:nome, :codice_cas, :codice_onu_id, :nome_tecnico, :gruppo_imballaggio,
                :conc_min, :conc_max, :flash,
                :e_sol, :e_rif, :e_stab, :note)'
        )->execute($this->bindParams($v));
        return (int) $this->pdo->lastInsertId();
    }

    private function updateSostanza(int $id, array $v): void
    {
        $params = $this->bindParams($v);
        $params[':id'] = $id;
        $this->pdo->prepare(
            'UPDATE sostanze_adr SET
               nome = :nome, codice_cas = :codice_cas,
               codice_onu_id = :codice_onu_id, nome_tecnico = :nome_tecnico,
               gruppo_imballaggio = :gruppo_imballaggio,
               concentrazione_min_pct = :conc_min, concentrazione_max_pct = :conc_max,
               punto_infiammabilita_c = :flash,
               e_soluzione = :e_sol, e_rifiuto = :e_rif, e_stabilizzato = :e_stab,
               note_interne = :note, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute($params);
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
            ':note'              => $n($v['note_interne'] ?? ''),
        ];
    }

    private function loadSostanza(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM sostanze_adr WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function loadSchede(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM sostanze_adr_schede WHERE sostanza_id = ? ORDER BY lingua, e_corrente DESC, created_at DESC'
        );
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadProdottiAssociati(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.id, p.sku_interno, p.nome, p.attivo
               FROM prodotti p
              WHERE p.sostanza_adr_id = ?
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

    public static function lingue(): array { return self::LINGUE; }
}
