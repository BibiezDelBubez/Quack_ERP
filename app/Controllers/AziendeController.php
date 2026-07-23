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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
                            $oldLogo = $loaded['logo_path'] ?? '';
                            $oldHeader = $loaded['header_path'] ?? '';

                            if ($logo) {
                                $values['logo_path'] = $logo;
                                if ($oldLogo && $oldLogo !== $logo) {
                                    $abs = APP_ROOT_DIR . '/public/' . ltrim($oldLogo, '/\\');
                                    if (is_file($abs)) {
                                        if (!unlink($abs)) {
                                            error_log("AziendeController: Impossibile eliminare il vecchio logo: " . $abs);
                                        }
                                    }
                                }
                            } else {
                                $values['logo_path'] = post('existing_logo_path');
                            }
                            
                            if ($header) {
                                $values['header_path'] = $header;
                                if ($oldHeader && $oldHeader !== $header) {
                                    $abs = APP_ROOT_DIR . '/public/' . ltrim($oldHeader, '/\\');
                                    if (is_file($abs)) {
                                        if (!unlink($abs)) {
                                            error_log("AziendeController: Impossibile eliminare il vecchio header: " . $abs);
                                        }
                                    }
                                }
                            } else {
                                $values['header_path'] = post('existing_header_path');
                            }

                            qk_update_azienda($this->pdo, $entity_id, $values);
                            redirect_with('settings/aziende', 'success', 'Azienda aggiornata.');
                        }
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
            $indirizzi_preload = qk_list_indirizzi($this->pdo, $soggetto_id);
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
                $esito = qk_delete_azienda($this->pdo, $id);
                $msg = qk_esito_cancellazione_messaggio($esito, 'azienda', 'la');
                redirect_with('settings/aziende', $msg['type'], $msg['text']);
            } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
        $rows = qk_list_indirizzi($this->pdo, $soggetto_id);
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

        $validazione = qk_build_e_valida_indirizzo();
        if (!$validazione['ok']) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $validazione['error']]);
            exit;
        }
        $v = $validazione['v'];

        try {
            if ($address_id > 0) {
                qk_update_indirizzo($this->pdo, $address_id, $soggetto_id, $v);
                $id = $address_id;
            } else {
                $id = qk_insert_indirizzo($this->pdo, $soggetto_id, $v);
            }

            // Ricarica dalla DB per restituire i dati definitivi
            $all   = qk_list_indirizzi($this->pdo, $soggetto_id);
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
            qk_delete_indirizzo($this->pdo, $address_id, $soggetto_id);
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
        return $this->readFormValues(
            $current,
            array_keys($this->defaultValues()),
            ['socio_unico', 'in_liquidazione', 'predefinita']
        );
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

        // Verifica tipo reale del file (MIME sniffato dal contenuto, non dichiarato dal client:
        // $_FILES['type'] è il Content-Type inviato dal browser ed è falsificabile — un file .php
        // rinominato .jpg lo passerebbe indisturbato). Formati ammessi: intersezione tra quelli
        // realmente supportati da mpdf (generazione PDF, vedi Image/ImageTypeGuesser.php) e da
        // PHPWord (generazione DOCX, vedi Element/Image.php::setFunctions()) — entrambi usano
        // questo stesso logo/header. SVG e WebP sono supportati solo da mpdf e non da PHPWord
        // (in più l'SVG può contenere script, quindi va escluso anche per sicurezza); TIFF è
        // supportato solo da PHPWord e non da mpdf.
        $allowedMimeToExt = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/bmp'     => 'bmp',
            'image/x-ms-bmp' => 'bmp',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($realMime === false || !isset($allowedMimeToExt[$realMime])) {
            $errors[$inputName] = 'Formato file non supportato (ammessi solo JPEG, PNG, GIF, BMP).';
            return null;
        }

        // Doppio controllo: il file deve essere un'immagine raster valida e decodificabile,
        // non solo avere i "magic bytes" giusti in testa.
        if (getimagesize($file['tmp_name']) === false) {
            $errors[$inputName] = 'Il file non è un\'immagine valida.';
            return null;
        }

        // Directory di upload (loghi e header aziende — senza sharding, pochi file fissi)
        $subdir = ($inputName === 'header_file') ? 'header_aziende' : 'loghi';
        $upload_dir = upload_ensure_dir($subdir);

        // Estensione derivata dal MIME reale, non dal nome file fornito dall'utente.
        $ext = $allowedMimeToExt[$realMime];
        $new_name = uniqid('img_', true) . '.' . $ext;
        $dest = $upload_dir . DIRECTORY_SEPARATOR . $new_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return $subdir . '/' . $new_name;
        }

        $errors[$inputName] = 'Impossibile salvare il file caricato.';
        return null;
    }
}
