<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/anagrafiche.php';

final class AziendeController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $companies = qk_aziende_list($this->pdo);
        $total     = count($companies);

        $page_title    = 'Aziende Interne';
        $page_subtitle = 'Gestione delle aziende che utilizzano l\'ERP';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Aziende Interne']];
        $page_actions  = '<a href="' . url('settings/aziende/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuova azienda
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/aziende/_list.php';
        require INCLUDES_DIR . '/footer.php';
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
                        // Gestione upload file
                        $upload_errors = [];
                        $logo = $this->handleUpload('logo_file', $upload_errors);
                        $header = $this->handleUpload('header_file', $upload_errors);

                        if (!empty($upload_errors)) {
                            $errors = array_merge($errors, $upload_errors);
                        } else {
                            if ($logo) $values['logo_path'] = $logo;
                            if ($header) $values['header_path'] = $header;

                            $id = qk_insert_azienda($this->pdo, $values);
                            redirect_with('settings/aziende', 'success', 'Azienda creata correttamente.');
                        }
                    } catch (Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $page_title    = 'Nuova azienda interna';
        $page_subtitle = 'Inserisci le impostazioni della tua azienda';
        $breadcrumbs   = [
            ['label' => 'Sistema'],
            ['label' => 'Aziende Interne', 'url' => url('settings/aziende')],
            ['label' => 'Nuova'],
        ];

        $indirizzi_preload = [];
        $contatti_preload = [];
        $nIndirizzi = 0;
        $nContatti = 0;
        $extra_css = [];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/aziende/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $mode      = 'edit';
        $entity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$entity_id) {
            redirect_with('settings/aziende', 'danger', 'Azienda non specificata.');
        }

        $defaults = $this->defaultValues();
        $loaded   = qk_load_azienda_form($this->pdo, $entity_id);

        if (!$loaded) {
            http_response_code(404);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

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
                        // Gestione upload file
                        $upload_errors = [];
                        $logo = $this->handleUpload('logo_file', $upload_errors);
                        $header = $this->handleUpload('header_file', $upload_errors);

                        if (!empty($upload_errors)) {
                            $errors = array_merge($errors, $upload_errors);
                        } else {
                            if ($logo) {
                                $values['logo_path'] = $logo;
                            } else {
                                $values['logo_path'] = post('existing_logo_path');
                            }
                            if ($header) {
                                $values['header_path'] = $header;
                            } else {
                                $values['header_path'] = post('existing_header_path');
                            }

                            qk_update_azienda($this->pdo, $entity_id, $values);
                            redirect_with('settings/aziende', 'success', 'Azienda aggiornata.');
                        }
                    } catch (Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $page_title    = $values['ragione_sociale'] ?: 'Modifica azienda';
        $page_subtitle = 'Modifica anagrafica e configurazioni · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Sistema'],
            ['label' => 'Aziende Interne', 'url' => url('settings/aziende')],
            ['label' => $values['ragione_sociale'] ?: 'Modifica'],
        ];

        $soggetto_id = (int)($values['soggetto_id'] ?? 0);
        $indirizzi_preload = [];
        $contatti_preload = [];
        $nIndirizzi = 0;
        $nContatti = 0;
        if ($soggetto_id > 0) {
            $indirizzi_preload = qk_list_indirizzi_cliente($this->pdo, $soggetto_id);
            $contatti_preload = qk_list_contatti_cliente($this->pdo, $soggetto_id);
            $nIndirizzi = count($indirizzi_preload);
            $nContatti = count($contatti_preload);
        }
        $extra_css = ['customers-addresses.css', 'suppliers-contacts.css'];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/aziende/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Eliminazione ─────────────────────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('settings/aziende');
        }

        if (!csrf_verify()) {
            redirect_with('settings/aziende', 'danger', 'Token CSRF non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                qk_delete_azienda($this->pdo, $id);
                redirect_with('settings/aziende', 'success', 'Azienda rimossa con successo.');
            } catch (Throwable $e) {
                redirect_with('settings/aziende', 'danger', 'Impossibile eliminare l\'azienda: ' . $e->getMessage());
            }
        } else {
            redirect_with('settings/aziende', 'danger', 'ID azienda non valido.');
        }
    }

    /* ── API: Contatti Azienda ────────────────────────────────────────────── */

    /** GET /settings/aziende/api/contatti?customer_id=X */
    public function apiContatti(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $soggetto_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
        if (!$soggetto_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id (soggetto_id) mancante']);
            exit;
        }
        $rows = qk_list_contatti_cliente($this->pdo, $soggetto_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'contatti' => $rows]);
        exit;
    }

    /** POST /settings/aziende/api/contatti/save */
    public function apiContattiSave(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $soggetto_id = (int) post('customer_id', '0');
        $contact_id  = (int) post('contact_id', '0'); // 0 = nuovo

        if (!$soggetto_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id (soggetto_id) mancante.']);
            exit;
        }

        $v = [
            'nome'      => post('nome', ''),
            'cognome'   => post('cognome', ''),
            'email'     => post('email', ''),
            'telefono'  => post('telefono', ''),
            'cellulare' => post('cellulare', ''),
            'ruolo'     => post('ruolo', ''),
        ];

        if (trim($v['nome']) === '' && trim($v['cognome']) === '') {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Nome o cognome obbligatorio.']);
            exit;
        }
        if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Indirizzo email non valido.']);
            exit;
        }

        try {
            if ($contact_id > 0) {
                qk_update_contatto_cliente($this->pdo, $contact_id, $soggetto_id, $v);
                $id = $contact_id;
            } else {
                $id = qk_insert_contatto_cliente($this->pdo, $soggetto_id, $v);
            }

            // Ricarica per restituire i dati definitivi
            $all   = qk_list_contatti_cliente($this->pdo, $soggetto_id);
            $saved = null;
            foreach ($all as $c) {
                if ((int) $c['id'] === (int) $id) { $saved = $c; break; }
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'contact' => $saved]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /** POST /settings/aziende/api/contatti/delete */
    public function apiContattiDelete(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $soggetto_id = (int) post('customer_id', '0');
        $contact_id  = (int) post('contact_id', '0');

        if (!$soggetto_id || !$contact_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            qk_delete_contatto_cliente($this->pdo, $contact_id, $soggetto_id);
            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: Indirizzi Azienda ───────────────────────────────────────────── */

    /** GET /settings/aziende/api/indirizzi?customer_id=X */
    public function apiIndirizzi(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $soggetto_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
        if (!$soggetto_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id (soggetto_id) mancante']);
            exit;
        }
        $rows = qk_list_indirizzi_cliente($this->pdo, $soggetto_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'indirizzi' => $rows]);
        exit;
    }

    /** POST /settings/aziende/api/indirizzi/save */
    public function apiIndirizziSave(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $soggetto_id = (int) post('customer_id', '0');
        $address_id  = (int) post('address_id', '0'); // 0 = nuovo

        if (!$soggetto_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'customer_id (soggetto_id) mancante.']);
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
                qk_update_indirizzo_cliente($this->pdo, $address_id, $soggetto_id, $v);
                $id = $address_id;
            } else {
                $id = qk_insert_indirizzo_cliente($this->pdo, $soggetto_id, $v);
            }

            // Ricarica dalla DB per restituire i dati definitivi
            $all   = qk_list_indirizzi_cliente($this->pdo, $soggetto_id);
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

    /** POST /settings/aziende/api/indirizzi/delete */
    public function apiIndirizziDelete(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $soggetto_id = (int) post('customer_id', '0');
        $address_id  = (int) post('address_id', '0');

        if (!$soggetto_id || !$address_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            qk_delete_indirizzo_cliente($this->pdo, $address_id, $soggetto_id);
            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }


    /* ── Helper privati ───────────────────────────────────────────────────── */

    private function defaultValues(): array
    {
        return [
            'ragione_sociale'   => '',
            'partita_iva'       => '',
            'codice_fiscale'    => '',
            'email'             => '',
            'telefono'          => '',
            'cellulare'         => '',
            'pec'               => '',
            'sito_web'          => '',
            'address'           => '',
            'civico'            => '',
            'zip'               => '',
            'city'              => '',
            'province'          => '',
            'country'           => 'IT',
            'logo_path'         => '',
            'header_path'       => '',
            'capitale_sociale'  => '',
            'registro_imprese'  => '',
            'socio_unico'       => '0',
            'in_liquidazione'   => '0',
            'banca_default'     => '',
            'iban_default'      => '',
            'bic_swift_default' => '',
            'predefinita'       => '0',
        ];
    }

    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            $val = $current[$k] ?? '';
            if (is_bool($val)) {
                $val = $val ? '1' : '0';
            }
            $current[$k] = post($k, (string) $val);
        }
        $current['socio_unico']      = isset($_POST['socio_unico']) ? '1' : '0';
        $current['in_liquidazione']  = isset($_POST['in_liquidazione']) ? '1' : '0';
        $current['predefinita']     = isset($_POST['predefinita']) ? '1' : '0';
        return $current;
    }

    private function validate(array $values): array
    {
        $errors = [];
        if (trim($values['ragione_sociale']) === '') {
            $errors['ragione_sociale'] = 'La ragione sociale è obbligatoria.';
        }
        if (trim($values['partita_iva']) === '') {
            $errors['partita_iva'] = 'La partita IVA è obbligatoria.';
        }
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Indirizzo email non valido.';
        }
        if ($values['iban_default'] !== '' && strlen(trim($values['iban_default'])) < 15) {
            $errors['iban_default'] = 'IBAN non valido.';
        }
        return $errors;
    }

    private function handleUpload(string $inputName, array &$errors): ?string
    {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES[$inputName];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[$inputName] = 'Errore durante l\'upload del file.';
            return null;
        }

        // Verifica tipo e estensione
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($file['type'], $allowed, true)) {
            $errors[$inputName] = 'Formato file non supportato (ammessi solo JPEG, PNG, GIF, SVG).';
            return null;
        }

        // Directory di upload (loghi e header aziende — senza sharding, pochi file fissi)
        $subdir = ($inputName === 'header_image') ? 'header_aziende' : 'loghi';
        $upload_dir = upload_ensure_dir($subdir);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('img_', true) . '.' . $ext;
        $dest = $upload_dir . DIRECTORY_SEPARATOR . $new_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return $subdir . '/' . $new_name;
        }

        $errors[$inputName] = 'Impossibile salvare il file caricato.';
        return null;
    }
}
