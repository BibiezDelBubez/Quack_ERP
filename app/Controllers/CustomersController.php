<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/anagrafiche.php';

/**
 * CustomersController — gestisce le pagine /customers, /customers/create, /customers/edit.
 *
 * Responsabilità:
 *   - leggere l'input HTTP (GET/POST)
 *   - validare i dati
 *   - chiamare il service layer (anagrafiche.php)
 *   - fare redirect o includere la vista
 *
 * NON contiene HTML. Le viste sono in customers/_list.php e customers/_form.php.
 */
class CustomersController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q', '');
        $type   = get_param('type', '');

        $sortWhitelist = ['nome','email','citta','created'];
        $pag    = qk_pagination_params($sortWhitelist, 'nome', 'asc');

        $list      = qk_clienti_list($this->pdo, $search, $type, $pag['limit'], $pag['offset'], $pag['sort'], $pag['dir']);
        $customers = $list['rows'];
        $total     = $list['total'];
        $base      = url('customers');

        $page_title    = 'Clienti';
        $page_subtitle = 'Gestione anagrafiche clienti';
        $breadcrumbs   = [['label' => 'Clienti']];
        $page_actions  = '<a href="' . url('customers/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo cliente
        </a>';

        $this->page(APP_ROOT_DIR . '/app/views/customers/_list.php', get_defined_vars());
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $mode      = 'create';
        $entity_id = null;
        $errors    = [];
        $values    = $this->defaultValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);

                $errors = $this->validate($values);

                if (empty($errors)) {
                    try {
                        $id = qk_insert_cliente($this->pdo, $values);
                        redirect_with('customers/edit?id=' . $id, 'success', 'Cliente creato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = qk_db_user_message($e);
                    }
                }
            }
        }

        $page_title    = 'Nuovo cliente';
        $page_subtitle = 'Inserisci i dati anagrafici';
        $breadcrumbs   = [
            ['label' => 'Anagrafiche'],
            ['label' => 'Clienti', 'url' => url('customers')],
            ['label' => 'Nuovo'],
        ];
        $record_meta = null;

        $listini = $this->pdo->query("SELECT id, nome, valuta FROM listini WHERE attivo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/customers/_form.php', get_defined_vars());
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $mode      = 'edit';
        $entity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$entity_id) {
            redirect_with('customers', 'danger', 'Cliente non specificato.');
        }

        $defaults = $this->defaultValues();
        $loaded   = qk_load_cliente_form($this->pdo, $entity_id);

        if (!$loaded) {
            http_response_code(404);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        $stmtListini = $this->pdo->prepare("
            SELECT cl.listino_id, cl.priorita, cl.data_inizio, cl.data_fine, l.nome AS listino_nome
            FROM clienti_listini cl
            JOIN listini l ON l.id = cl.listino_id
            WHERE cl.cliente_id = ?
            ORDER BY cl.priorita DESC, l.nome ASC
        ");
        $stmtListini->execute([$entity_id]);
        $loaded['listini'] = $stmtListini->fetchAll(PDO::FETCH_ASSOC);

        $record_meta = [
            'codice'     => $loaded['_codice'] ?? '',
            'created_at' => $loaded['_created_at'] ?? '',
            'updated_at' => $loaded['_updated_at'] ?? '',
        ];
        unset($loaded['_codice'], $loaded['_created_at'], $loaded['_updated_at']);

        $values = array_merge($defaults, $loaded);
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);
                $errors = $this->validate($values);

                if (empty($errors)) {
                    try {
                        qk_update_cliente($this->pdo, $entity_id, $values);
                        redirect_with('customers/edit?id=' . $entity_id, 'success', 'Cliente aggiornato.');
                    } catch (Throwable $e) {
                        $errors['_global'] = qk_db_user_message($e);
                    }
                }
            }
        }

        $display_name  = trim(($values['first_name'] . ' ' . $values['last_name']) ?: $values['company']);
        $page_title    = $display_name ?: 'Modifica cliente';
        $page_subtitle = 'Modifica anagrafica · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Anagrafiche'],
            ['label' => 'Clienti', 'url' => url('customers')],
            ['label' => $display_name ?: 'Modifica'],
        ];

        // Dati pre-caricati per gli indirizzi (PHP → JS via window.QkCustomerAddr)
        $indirizzi_preload = qk_list_indirizzi_cliente($this->pdo, $entity_id);

        $extra_css = ['customers-addresses.css', 'customers-compliance.css'];

        $listini = $this->pdo->query("SELECT id, nome, valuta FROM listini WHERE attivo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/customers/_form.php', get_defined_vars());
    }

    /* ── Helper privati ───────────────────────────────────────────────────── */

    /** Valori di default del form cliente */
    private function defaultValues(): array
    {
        return [
            'type'          => 'privato',
            'first_name'    => '',
            'last_name'     => '',
            'company'       => '',
            'referent'      => '',
            'email'         => '',
            'phone'         => '',
            'mobile'        => '',
            'website'       => '',
            'address'       => '',
            'zip'           => '',
            'city'          => '',
            'province'      => '',
            'country'       => 'IT',
            'vat'           => '',
            'tax_code'      => '',
            'sdi_code'      => '',
            'pec'           => '',
            'category'      => '',
            'discount'      => '',
            'payment_terms' => '',
            'status'        => 'active',
            'notes'         => '',
            'newsletter'    => '0',
            'listino_id'    => '',
            'listini'       => [],
        ];
    }

    /** Legge i valori POST sovrascrivendo i defaults */
    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            if ($k === 'listini') {
                $current[$k] = $_POST['listini'] ?? [];
            } else {
                $current[$k] = post($k, $current[$k] ?? '');
            }
        }
        $current['newsletter'] = isset($_POST['newsletter']) ? '1' : '0';
        return $current;
    }

    /** Valida i campi obbligatori e restituisce l'array $errors */
    private function validate(array $values): array
    {
        $errors   = [];
        $tipoSogg = qk_form_tipo_soggetto($values['type']);

        if ($tipoSogg === 'privato') {
            if (trim($values['first_name']) === '') {
                $errors['first_name'] = 'Il nome è obbligatorio.';
            }
            if (trim($values['last_name']) === '') {
                $errors['last_name'] = 'Il cognome è obbligatorio.';
            }
        } else {
            if (trim($values['company']) === '') {
                $errors['company'] = 'La ragione sociale è obbligatoria.';
            }
            if (trim($values['vat']) === '') {
                $errors['vat'] = 'La partita IVA è obbligatoria.';
            }
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Indirizzo email non valido.';
        }

        return $errors;
    }

    /* ── API: Indirizzi cliente ───────────────────────────────────────────── */

    /** GET /customers/api/indirizzi?customer_id=X */
    public function apiIndirizzi(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
        if (!$customer_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id mancante']);
            exit;
        }
        $rows = qk_list_indirizzi_cliente($this->pdo, $customer_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'indirizzi' => $rows]);
        exit;
    }

    /** POST /customers/api/indirizzi/save */
    public function apiIndirizziSave(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $customer_id = (int) post('customer_id', '0');
        $address_id  = (int) post('address_id', '0'); // 0 = nuovo

        if (!$customer_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id mancante.']);
            exit;
        }

        $tipiValidi = ['sede_legale', 'sede_operativa', 'spedizione', 'fatturazione', 'magazzino'];
        $tipo = post('tipo', '');
        if (!in_array($tipo, $tipiValidi, true)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Tipo indirizzo non valido.']);
            exit;
        }

        $v = [
            'tipo'               => $tipo,
            'nome_sede'          => post('nome_sede', ''),
            'predefinito'        => post('predefinito', '0') === '1',
            'referente_consegna' => post('referente_consegna', ''),
            'telefono_consegna'  => post('telefono_consegna', ''),
            'presso'             => post('presso', ''),
            'via'                => post('via', ''),
            'civico'             => post('civico', ''),
            'cap'                => post('cap', ''),
            'citta'              => post('citta', ''),
            'provincia'          => post('provincia', ''),
            'nazione'            => post('nazione', 'IT') ?: 'IT',
            'note_consegna'      => post('note_consegna', ''),
        ];

        if (trim($v['via']) === '') {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Il campo indirizzo (via) è obbligatorio.']);
            exit;
        }
        if (trim($v['cap']) === '') {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Il CAP è obbligatorio.']);
            exit;
        }
        if (trim($v['citta']) === '') {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'La città è obbligatoria.']);
            exit;
        }

        try {
            if ($address_id > 0) {
                qk_update_indirizzo_cliente($this->pdo, $address_id, $customer_id, $v);
                $id = $address_id;
            } else {
                $id = qk_insert_indirizzo_cliente($this->pdo, $customer_id, $v);
            }

            // Ricarica dalla DB per restituire i dati definitivi
            $all   = qk_list_indirizzi_cliente($this->pdo, $customer_id);
            $saved = null;
            foreach ($all as $addr) {
                if ((int) $addr['id'] === (int) $id) { $saved = $addr; break; }
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'address' => $saved, 'all' => $all]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /customers/api/indirizzi/delete */
    public function apiIndirizziDelete(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $customer_id = (int) post('customer_id', '0');
        $address_id  = (int) post('address_id', '0');

        if (!$customer_id || !$address_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            qk_delete_indirizzo_cliente($this->pdo, $address_id, $customer_id);
            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** GET /customers/api/compliance?customer_id=X */
    public function apiCompliance(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
        if (!$customer_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id mancante']);
            exit;
        }

        try {
            // Get all active restriction types
            $types = $this->pdo->query("
                SELECT id, code, name, regulatory_ref, requires_vat, validity_days, log_transactions, report_within_days 
                FROM compliance_restriction_types 
                WHERE active = 1
                ORDER BY name
            ")->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($types as $type) {
                $type_id = (int)$type['id'];

                // Get requested documents for this type
                $req_docs = $this->pdo->prepare("
                    SELECT id, document_code, document_name, mandatory, instructions 
                    FROM compliance_documenti_richiesti 
                    WHERE restriction_type_id = :type_id
                    ORDER BY mandatory DESC, id
                ");
                $req_docs->execute([':type_id' => $type_id]);
                $docs = $req_docs->fetchAll(PDO::FETCH_ASSOC);

                // Get last authorization for this client & type
                $auth_stmt = $this->pdo->prepare("
                    SELECT a.id, a.status, a.issued_date, a.expiry_date, a.internal_notes, a.approved_by, u.username as approver_name
                    FROM compliance_autorizzazioni a
                    LEFT JOIN utenti u ON u.id = a.approved_by
                    WHERE a.customer_id = :customer_id AND a.restriction_type_id = :type_id
                    ORDER BY a.id DESC LIMIT 1
                ");
                $auth_stmt->execute([
                    ':customer_id' => $customer_id,
                    ':type_id' => $type_id
                ]);
                $auth = $auth_stmt->fetch(PDO::FETCH_ASSOC);

                // If auth exists, get uploaded docs
                $uploaded = [];
                if ($auth) {
                    $up_stmt = $this->pdo->prepare("
                        SELECT dc.id, dc.required_doc_id, dc.file_path, dc.original_filename, dc.uploaded_at, 
                               dc.verified, dc.verified_by, dc.verified_at, u.username as verifier_name
                        FROM compliance_documenti_caricati dc
                        LEFT JOIN utenti u ON u.id = dc.verified_by
                        WHERE dc.authorization_id = :auth_id
                    ");
                    $up_stmt->execute([':auth_id' => $auth['id']]);
                    $uploaded = $up_stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                // Map uploaded files to required docs
                $docs_mapped = [];
                foreach ($docs as $doc) {
                    $doc_id = (int)$doc['id'];
                    $file = null;
                    foreach ($uploaded as $up) {
                        if ((int)$up['required_doc_id'] === $doc_id) {
                            $file = [
                                'id' => (int)$up['id'],
                                'file_path' => $up['file_path'],
                                'original_filename' => $up['original_filename'],
                                'uploaded_at' => $up['uploaded_at'],
                                'verified' => (int)$up['verified'] === 1,
                                'verifier_name' => $up['verifier_name'],
                                'verified_at' => $up['verified_at']
                            ];
                            break;
                        }
                    }
                    $docs_mapped[] = array_merge($doc, ['file' => $file]);
                }

                $result[] = [
                    'restriction_type' => $type,
                    'authorization' => $auth ?: null,
                    'documents' => $docs_mapped
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'compliance' => $result]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /customers/api/compliance/save */
    public function apiComplianceSave(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $customer_id = (int) post('customer_id', '0');
        $restriction_type_id = (int) post('restriction_type_id', '0');
        $internal_notes = post('internal_notes', '');

        if (!$customer_id || !$restriction_type_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri obbligatori mancanti.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Mark any existing active or in_attesa auth as status 'scaduta' or 'sospesa'
            $up = $this->pdo->prepare("
                UPDATE compliance_autorizzazioni 
                SET status = 'scaduta' 
                WHERE customer_id = :customer_id 
                  AND restriction_type_id = :restriction_type_id 
                  AND status IN ('approvata', 'in_attesa')
            ");
            $up->execute([
                ':customer_id' => $customer_id,
                ':restriction_type_id' => $restriction_type_id
            ]);

            // Create new auth in 'in_attesa' state
            $ins = $this->pdo->prepare("
                INSERT INTO compliance_autorizzazioni (customer_id, restriction_type_id, status, internal_notes)
                VALUES (:customer_id, :restriction_type_id, 'in_attesa', :internal_notes)
            ");
            $ins->execute([
                ':customer_id' => $customer_id,
                ':restriction_type_id' => $restriction_type_id,
                ':internal_notes' => $internal_notes
            ]);
            $auth_id = $this->pdo->lastInsertId();

            $this->pdo->commit();
            ob_end_clean();
            echo json_encode(['ok' => true, 'authorization_id' => $auth_id]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /customers/api/compliance/upload */
    public function apiComplianceUpload(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $auth_id = (int) post('authorization_id', '0');
        $required_doc_id = (int) post('required_doc_id', '0');

        if (!$auth_id || !$required_doc_id || empty($_FILES['doc_file'])) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Dati o file mancanti.']);
            exit;
        }

        $file = $_FILES['doc_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Errore nel caricamento del file (Codice ' . $file['error'] . ').']);
            exit;
        }

        try {
            // Get clean path for upload
            // The spec says: path format `/uploads/compliance/{autorizzazione_id}/{nome_file}`
            // Upload directory is UPLOAD_DIR (from config.php)
            $upload_base = rtrim(UPLOAD_DIR, '/\\') . '/compliance/' . $auth_id;
            if (!is_dir($upload_base)) {
                mkdir($upload_base, 0777, true);
            }

            $orig_name = basename($file['name']);
            // Clean filename to prevent issues
            $safe_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $orig_name);
            $dest_path = $upload_base . '/' . $safe_name;

            if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
                throw new Exception("Impossibile salvare il file caricato nella destinazione.");
            }

            // Path to save in DB is relative to UPLOAD_DIR
            $db_file_path = 'compliance/' . $auth_id . '/' . $safe_name;

            // Save to DB (delete previous upload for same required_doc if exists)
            $this->pdo->beginTransaction();
            $del = $this->pdo->prepare("
                DELETE FROM compliance_documenti_caricati 
                WHERE authorization_id = :auth_id AND required_doc_id = :req_id
            ");
            $del->execute([
                ':auth_id' => $auth_id,
                ':req_id' => $required_doc_id
            ]);

            $ins = $this->pdo->prepare("
                INSERT INTO compliance_documenti_caricati (authorization_id, required_doc_id, file_path, original_filename)
                VALUES (:auth_id, :req_id, :file_path, :orig_name)
            ");
            $ins->execute([
                ':auth_id' => $auth_id,
                ':req_id' => $required_doc_id,
                ':file_path' => $db_file_path,
                ':orig_name' => $orig_name
            ]);
            $upload_id = $this->pdo->lastInsertId();

            $this->pdo->commit();
            ob_end_clean();
            echo json_encode(['ok' => true, 'upload_id' => $upload_id, 'file_path' => $db_file_path]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /customers/api/compliance/verify */
    public function apiComplianceVerify(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $upload_id = (int) post('upload_id', '0');
        $verified = post('verified', '0') === '1' ? 1 : 0;
        $user_id = $_SESSION['qk_user']['id'] ?? null;

        if (!$upload_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametro upload_id mancante.']);
            exit;
        }

        try {
            $up = $this->pdo->prepare("
                UPDATE compliance_documenti_caricati 
                SET verified = :verified, verified_by = :user_id, verified_at = :now
                WHERE id = :id
            ");
            $up->execute([
                ':verified' => $verified,
                ':user_id' => $verified ? $user_id : null,
                ':now' => $verified ? date('Y-m-d H:i:s') : null,
                ':id' => $upload_id
            ]);

            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /customers/api/compliance/approve */
    public function apiComplianceApprove(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        // Allow approvals only to admin or commercial roles
        $user_role = $_SESSION['qk_user']['role'] ?? '';
        if ($user_role !== 'admin' && $user_role !== 'commerciale') {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Non hai i permessi per approvare autorizzazioni di compliance.']);
            exit;
        }

        $auth_id = (int) post('authorization_id', '0');
        $status = post('status', ''); // 'approvata', 'rifiutata', 'sospesa'
        $internal_notes = post('internal_notes', '');
        $user_id = $_SESSION['qk_user']['id'] ?? null;

        $valid_states = ['approvata', 'rifiutata', 'sospesa', 'in_attesa'];
        if (!$auth_id || !in_array($status, $valid_states, true)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi o mancanti.']);
            exit;
        }

        try {
            // Get details of authorization
            $stmt = $this->pdo->prepare("
                SELECT restriction_type_id, customer_id FROM compliance_autorizzazioni WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $auth_id]);
            $auth = $stmt->fetch();
            if (!$auth) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Autorizzazione non trovata.']);
                exit;
            }

            $type_id = (int)$auth['restriction_type_id'];

            if ($status === 'approvata') {
                // Check if all mandatory documents are uploaded and verified
                $req_stmt = $this->pdo->prepare("
                    SELECT id, document_name 
                    FROM compliance_documenti_richiesti 
                    WHERE restriction_type_id = :type_id AND mandatory = 1
                ");
                $req_stmt->execute([':type_id' => $type_id]);
                $mandatory_docs = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($mandatory_docs as $doc) {
                    $check_stmt = $this->pdo->prepare("
                        SELECT id, verified 
                        FROM compliance_documenti_caricati 
                        WHERE authorization_id = :auth_id AND required_doc_id = :doc_id LIMIT 1
                    ");
                    $check_stmt->execute([
                        ':auth_id' => $auth_id,
                        ':doc_id' => $doc['id']
                    ]);
                    $uploaded = $check_stmt->fetch();
                    if (!$uploaded) {
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'error' => "Il documento obbligatorio '{$doc['document_name']}' non è stato caricato."]);
                        exit;
                    }
                    if ((int)$uploaded['verified'] !== 1) {
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'error' => "Il documento obbligatorio '{$doc['document_name']}' deve essere verificato prima dell'approvazione."]);
                        exit;
                    }
                }

                // Get validity days of the restriction type
                $val_stmt = $this->pdo->prepare("SELECT validity_days FROM compliance_restriction_types WHERE id = :id LIMIT 1");
                $val_stmt->execute([':id' => $type_id]);
                $validity_days = (int) ($val_stmt->fetchColumn() ?: 365);

                $issued_date = post('issued_date', '');
                if ($issued_date === '') {
                    $issued_date = date('Y-m-d');
                }
                
                $expiry_date = post('expiry_date', '');
                if ($expiry_date === '') {
                    $expiry_date = date('Y-m-d', strtotime("+{$validity_days} days"));
                }

                $up = $this->pdo->prepare("
                    UPDATE compliance_autorizzazioni 
                    SET status = :status, issued_date = :issued_date, expiry_date = :expiry_date, 
                        approved_by = :approved_by, internal_notes = :internal_notes, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $up->execute([
                    ':status' => $status,
                    ':issued_date' => $issued_date,
                    ':expiry_date' => $expiry_date,
                    ':approved_by' => $user_id,
                    ':internal_notes' => $internal_notes,
                    ':id' => $auth_id
                ]);
            } else {
                $up = $this->pdo->prepare("
                    UPDATE compliance_autorizzazioni 
                    SET status = :status, approved_by = :approved_by, internal_notes = :internal_notes, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $up->execute([
                    ':status' => $status,
                    ':approved_by' => $user_id,
                    ':internal_notes' => $internal_notes,
                    ':id' => $auth_id
                ]);
            }

            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

