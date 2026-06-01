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
        $search = get_param('q');
        $type   = get_param('type');
        $page   = (int) get_param('page', '1');
        if ($page < 1) {
            $page = 1;
        }
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $list      = qk_clienti_list($this->pdo, $search, $type, $limit, $offset);
        $customers = $list['rows'];
        $total     = $list['total'];
        $pages     = (int) ceil($total / $limit);

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

        $extra_css = ['customers-addresses.css'];

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
        ];
    }

    /** Legge i valori POST sovrascrivendo i defaults */
    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            $current[$k] = post($k, $current[$k] ?? '');
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
}

