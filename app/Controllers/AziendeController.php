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
