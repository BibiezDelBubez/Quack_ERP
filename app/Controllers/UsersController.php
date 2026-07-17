<?php

declare(strict_types=1);

require_once APP_ROOT_DIR . '/includes/utenti.php';

final class UsersController extends BaseController
{
    public function index(): void
    {
        if (!can('manage_users')) {
            redirect_with('dashboard', 'danger', 'Accesso riservato agli amministratori.');
        }

        $me     = current_user();
        $search = get_param('q');
        $ruolo  = get_param('ruolo');

        $utenti = get_all_utenti($this->pdo, $search, $ruolo);
        $total  = count($utenti);

        $page_title    = 'Utenti';
        $page_subtitle = 'Gestione accessi al sistema';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti']];
        $page_actions  = '<a href="' . url('users/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo utente
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/users/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function create(): void
    {
        if (!can('manage_users')) {
            redirect_with('dashboard', 'danger', 'Accesso riservato agli amministratori.');
        }

        $mode   = 'create';
        $errors = [];
        $values = ['username' => '', 'nome' => '', 'cognome' => '', 'email' => '', 'ruolo' => 'commerciale', 'attivo' => '1'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $values['username'] = trim(post('username'));
                $values['nome']    = trim(post('nome'));
                $values['cognome'] = trim(post('cognome'));
                $values['email']   = trim(post('email'));
                $values['ruolo']   = post('ruolo');
                $values['attivo']  = post('attivo', '1');
                $pwd               = post('password');
                $pwd_confirm       = post('password_confirm');

                $errors = $this->validate($values, $pwd, $pwd_confirm, true);

                if (empty($errors)) {
                    try {
                        insert_utente($this->pdo, $values, password_hash($pwd, PASSWORD_DEFAULT));
                        redirect_with('users', 'success', 'Utente creato correttamente.');
                    } catch (PDOException $ex) {
                        $errors['_global'] = str_contains($ex->getMessage(), 'UNIQUE')
                            ? 'Username o email già associati a un altro account.'
                            : 'Errore nel salvataggio. Riprova.';
                    }
                }
            }
        }

        $page_title    = 'Nuovo utente';
        $page_subtitle = 'Crea un nuovo accesso al sistema';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti', 'url' => url('users')], ['label' => 'Nuovo']];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/users/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function edit(): void
    {
        if (!can('manage_users')) {
            redirect_with('dashboard', 'danger', 'Accesso riservato agli amministratori.');
        }

        $mode = 'edit';
        $id   = (int) get_param('id');
        if ($id <= 0) {
            redirect_with('users', 'danger', 'Utente non trovato.');
        }

        $utente = get_utente_by_id($this->pdo, $id);
        if (!$utente) {
            redirect_with('users', 'danger', 'Utente non trovato.');
        }

        $me = current_user();
        $errors = [];
        $values = [
            'username' => $utente['username'],
            'nome'     => $utente['nome'],
            'cognome'  => $utente['cognome'],
            'email'    => $utente['email'],
            'ruolo'    => $utente['ruolo'],
            'attivo'   => (string) $utente['attivo'],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $values['username'] = trim(post('username'));
                $values['nome']    = trim(post('nome'));
                $values['cognome'] = trim(post('cognome'));
                $values['email']   = trim(post('email'));
                $values['ruolo']   = post('ruolo');
                $values['attivo']  = post('attivo', '1');
                $pwd               = post('password');
                $pwd_confirm       = post('password_confirm');

                $errors = $this->validate($values, $pwd, $pwd_confirm, false);

                if (empty($errors)) {
                    try {
                        $hash = ($pwd !== '') ? password_hash($pwd, PASSWORD_DEFAULT) : null;
                        update_utente_admin($this->pdo, $id, $values, $hash);

                        if ($id === (int) ($me['id'] ?? 0)) {
                            $_SESSION['qk_user']['display_name'] = trim($values['nome'] . ' ' . $values['cognome']);
                            $_SESSION['qk_user']['username']     = $values['username'];
                        }

                        redirect_with('users', 'success', 'Utente aggiornato correttamente.');
                    } catch (PDOException $ex) {
                        $errors['_global'] = str_contains($ex->getMessage(), 'UNIQUE')
                            ? 'Username o email già associati a un altro account.'
                            : 'Errore nel salvataggio. Riprova.';
                    }
                }
            }
        }

        $display_name  = trim($utente['nome'] . ' ' . $utente['cognome']);
        $page_title    = 'Modifica utente';
        $page_subtitle = $display_name;
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti', 'url' => url('users')], ['label' => $display_name]];

        $entity_id = $id;
        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/users/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function delete(): void
    {
        if (!can('manage_users')) {
            redirect_with('dashboard', 'danger', 'Accesso riservato agli amministratori.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('users');
        }

        if (!csrf_verify()) {
            redirect_with('users', 'danger', 'Token di sicurezza non valido. Ricarica la pagina.');
        }

        $me = current_user();
        $id = (int) post('id');
        $action = post('action'); // disable|enable|delete

        if ($id <= 0) {
            redirect_with('users', 'danger', 'Utente non trovato.');
        }
        if ($id === (int) ($me['id'] ?? 0)) {
            redirect_with('users', 'danger', 'Non puoi eseguire questa operazione sul tuo stesso account.');
        }

        $utente = get_utente_by_id($this->pdo, $id);
        if (!$utente) {
            redirect_with('users', 'danger', 'Utente non trovato.');
        }

        $nome_completo = trim($utente['nome'] . ' ' . $utente['cognome']);

        try {
            switch ($action) {
                case 'disable':
                    set_stato_utente($this->pdo, $id, 0);
                    redirect_with('users', 'success', "{$nome_completo} è stato disabilitato.");
                case 'enable':
                    set_stato_utente($this->pdo, $id, 1);
                    redirect_with('users', 'success', "{$nome_completo} è stato riabilitato.");
                case 'delete':
                    delete_utente($this->pdo, $id);
                    redirect_with('users', 'success', "Utente {$nome_completo} eliminato definitivamente.");
                default:
                    redirect_with('users', 'danger', 'Operazione non riconosciuta.');
            }
        } catch (PDOException) {
            redirect_with('users', 'danger', 'Errore durante l\'operazione. Riprova.');
        }
    }

    private function validate(array $values, string $pwd, string $pwd_confirm, bool $passwordRequired): array
    {
        $errors = [];

        $username = trim($values['username'] ?? '');
        if ($username === '') {
            $errors['username'] = 'Lo username è obbligatorio.';
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,50}$/i', $username)) {
            $errors['username'] = 'Username non valido (3-50 caratteri: lettere, numeri, . _ -)';
        }
        if (trim($values['nome'] ?? '') === '') {
            $errors['nome'] = 'Il nome è obbligatorio.';
        }
        if (trim($values['cognome'] ?? '') === '') {
            $errors['cognome'] = 'Il cognome è obbligatorio.';
        }
        if (!filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email non valida.';
        }
        if (!in_array($values['ruolo'] ?? '', ['admin', 'commerciale', 'magazziniere', 'readonly'], true)) {
            $errors['ruolo'] = 'Ruolo non valido.';
        }

        if ($passwordRequired || $pwd !== '') {
            if (strlen($pwd) < 8) {
                $errors['password'] = 'La password deve avere almeno 8 caratteri.';
            }
            if ($pwd !== $pwd_confirm) {
                $errors['password_confirm'] = 'Le password non coincidono.';
            }
        }

        return $errors;
    }
}

