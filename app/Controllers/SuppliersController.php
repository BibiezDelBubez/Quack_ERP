<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/anagrafiche.php';

/**
 * SuppliersController — gestisce /suppliers, /suppliers/create, /suppliers/edit.
 *
 * Responsabilità: HTTP input → validazione → service layer → vista.
 * Nessun HTML: le viste sono in suppliers/_list.php e suppliers/_form.php.
 */
class SuppliersController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search   = get_param('q', '');
        $adr_only = false;

        $sortWhitelist = ['nome','email','status'];
        $pag    = qk_pagination_params($sortWhitelist, 'nome', 'asc');

        $list      = qk_fornitori_list($this->pdo, $search, $adr_only, $pag['limit'], $pag['offset'], $pag['sort'], $pag['dir']);
        $suppliers = $list['rows'];
        $total     = $list['total'];
        $base      = url('suppliers');

        $page_title    = 'Fornitori';
        $page_subtitle = 'Gestione anagrafiche fornitori';
        $breadcrumbs   = [['label' => 'Anagrafiche'], ['label' => 'Fornitori']];
        $page_actions  = '<a href="' . url('suppliers/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo fornitore
        </a>';

        $this->page(APP_ROOT_DIR . '/app/views/suppliers/_list.php', get_defined_vars());
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $mode           = 'create';
        $entity_id      = null;
        $errors         = [];
        $values         = $this->defaultValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);

                $errors = $this->validate($values);

                if (empty($errors)) {
                    try {
                        $id = qk_insert_fornitore($this->pdo, $values);
                        redirect_with('suppliers/edit?id=' . $id, 'success', 'Fornitore creato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = qk_db_user_message($e);
                    }
                }
            }
        }

        $page_title    = 'Nuovo fornitore';
        $page_subtitle = 'Inserisci i dati anagrafici';
        $breadcrumbs   = [
            ['label' => 'Anagrafiche'],
            ['label' => 'Fornitori', 'url' => url('suppliers')],
            ['label' => 'Nuovo'],
        ];
        $record_meta = null;

        $this->page(APP_ROOT_DIR . '/app/views/suppliers/_form.php', get_defined_vars());
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $mode      = 'edit';
        $entity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$entity_id) {
            redirect_with('suppliers', 'danger', 'Fornitore non specificato.');
        }

        $defaults = $this->defaultValues();

        $loaded = qk_load_fornitore_form($this->pdo, $entity_id);
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
                        qk_update_fornitore($this->pdo, $entity_id, $values);
                        redirect_with('suppliers/edit?id=' . $entity_id, 'success', 'Fornitore aggiornato.');
                    } catch (Throwable $e) {
                        $errors['_global'] = qk_db_user_message($e);
                    }
                }
            }
        }

        $page_title    = $values['company'] ?: 'Modifica fornitore';
        $page_subtitle = 'Modifica anagrafica · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Anagrafiche'],
            ['label' => 'Fornitori', 'url' => url('suppliers')],
            ['label' => $values['company'] ?: 'Modifica'],
        ];

        // Dati pre-caricati per i contatti (PHP → JS via window.QkSupplier)
        $contatti_preload = qk_list_contatti_fornitore($this->pdo, $entity_id);

        // Dati pre-caricati per gli indirizzi (PHP → JS via window.QkSupplierAddr)
        $indirizzi_preload = qk_list_indirizzi($this->pdo, $entity_id);

        // Recupera i listini d'acquisto associati a questo fornitore
        $stmtListini = $this->pdo->prepare("SELECT * FROM listini_acquisto WHERE fornitore_id = ? AND attivo = 1 ORDER BY id DESC");
        $stmtListini->execute([$entity_id]);
        $listini_acquisto = $stmtListini->fetchAll(PDO::FETCH_ASSOC);

        $extra_css = ['suppliers-contacts.css', 'suppliers-addresses.css'];

        $this->page(APP_ROOT_DIR . '/app/views/suppliers/_form.php', get_defined_vars());
    }

    /* ── API: Contatti fornitore ──────────────────────────────────────────── */

    /** GET /suppliers/api/contatti?supplier_id=X */
    public function apiContatti(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $supplier_id = filter_input(INPUT_GET, 'supplier_id', FILTER_VALIDATE_INT);
        if (!$supplier_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'supplier_id mancante']);
            exit;
        }
        $rows = qk_list_contatti_fornitore($this->pdo, $supplier_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'contatti' => $rows]);
        exit;
    }

    /** POST /suppliers/api/contatti/save */
    public function apiContattiSave(): void
    {
        // ob_start garantisce che eventuali notice/warning PHP in dev
        // non contaminino il corpo JSON prima dell'header.
        ob_start();

        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $supplier_id = (int) post('supplier_id', '0');
        $contact_id  = (int) post('contact_id', '0'); // 0 = nuovo

        if (!$supplier_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'supplier_id mancante.']);
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
                qk_update_contatto_fornitore($this->pdo, $contact_id, $supplier_id, $v);
                $id = $contact_id;
            } else {
                $id = qk_insert_contatto_fornitore($this->pdo, $supplier_id, $v);
            }

            // Ricarica dalla DB per restituire i dati definitivi
            $all   = qk_list_contatti_fornitore($this->pdo, $supplier_id);
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

    /** POST /suppliers/api/contatti/delete */
    public function apiContattiDelete(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $supplier_id = (int) post('supplier_id', '0');
        $contact_id  = (int) post('contact_id', '0');

        if (!$supplier_id || !$contact_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            qk_delete_contatto_fornitore($this->pdo, $contact_id, $supplier_id);
            ob_end_clean();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: Indirizzi fornitore ─────────────────────────────────────────── */

    /** GET /suppliers/api/indirizzi?supplier_id=X */
    public function apiIndirizzi(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $supplier_id = filter_input(INPUT_GET, 'supplier_id', FILTER_VALIDATE_INT);
        if (!$supplier_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'supplier_id mancante']);
            exit;
        }
        $rows = qk_list_indirizzi($this->pdo, $supplier_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'indirizzi' => $rows]);
        exit;
    }

    /** POST /suppliers/api/indirizzi/save */
    public function apiIndirizziSave(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $supplier_id = (int) post('supplier_id', '0');
        $address_id  = (int) post('address_id', '0'); // 0 = nuovo

        if (!$supplier_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'supplier_id mancante.']);
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
                qk_update_indirizzo($this->pdo, $address_id, $supplier_id, $v);
                $id = $address_id;
            } else {
                $id = qk_insert_indirizzo($this->pdo, $supplier_id, $v);
            }

            // Ricarica dalla DB per restituire i dati definitivi
            $all   = qk_list_indirizzi($this->pdo, $supplier_id);
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

    /** POST /suppliers/api/indirizzi/delete */
    public function apiIndirizziDelete(): void
    {
        ob_start();
        header('Content-Type: application/json');

        if (!csrf_verify()) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $supplier_id = (int) post('supplier_id', '0');
        $address_id  = (int) post('address_id', '0');

        if (!$supplier_id || !$address_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        try {
            qk_delete_indirizzo($this->pdo, $address_id, $supplier_id);
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
        // Gli indirizzi non sono campi del form principale:
        // sono gestiti dalla tab "Sedi e indirizzi" tramite le API dedicate.
        return [
            'company'           => '',
            'email'             => '',
            'phone'             => '',
            'website'           => '',
            'vat'               => '',
            'tax_code'          => '',
            'pec'               => '',
            'lead_time'         => '',
            'min_order'         => '',
            'payment_terms'     => '',
            'supply_categories' => '',
            'currency'          => 'EUR',
            'our_customer_code' => '',
            'orders_portal_url' => '',
            'status'            => 'active',
            'notes'             => '',
            'notes_intern'      => '',
        ];
    }

    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            $current[$k] = post($k, $current[$k] ?? '');
        }
        return $current;
    }

    /**
     * @return array
     */
    private function validate(array $values): array
    {
        $errors = [];

        if (trim($values['company']) === '') {
            $errors['company'] = 'La ragione sociale è obbligatoria.';
        }
        if (trim($values['vat']) === '') {
            $errors['vat'] = 'La partita IVA è obbligatoria.';
        }
        return $errors;
    }

    /* ── Eliminazione ─────────────────────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('suppliers');
        }

        if (!csrf_verify()) {
            redirect_with('suppliers', 'danger', 'Token CSRF non valido. Ricarica la pagina e riprova.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                qk_delete_soggetto($this->pdo, $id);
                redirect_with('suppliers', 'success', 'Fornitore rimosso con successo.');
            } catch (Throwable $e) {
                redirect_with('suppliers', 'danger', 'Errore durante l\'eliminazione del fornitore: ' . $e->getMessage());
            }
        } else {
            redirect_with('suppliers', 'danger', 'ID fornitore non valido.');
        }
    }
}
