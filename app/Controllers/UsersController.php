<?php

declare(strict_types=1);

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

        $where  = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (nome||" "||cognome LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }
        if ($ruolo !== '') {
            $where .= ' AND r.codice = :ruolo';
            $params[':ruolo'] = $ruolo;
        }

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.username, u.nome, u.cognome, u.email, r.codice AS ruolo, u.attivo, u.created_at
               FROM utenti u
               JOIN ruoli r ON r.id = u.ruolo_id
             $where
             ORDER BY u.cognome, u.nome"
        );
        $stmt->execute($params);
        $utenti = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total  = count($utenti);

        $page_title    = 'Utenti';
        $page_subtitle = 'Gestione accessi al sistema';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti']];
        $page_actions  = '<a href="' . url('utenti/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo utente
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/utenti/_list.php';
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
                        $this->pdo->prepare(
                            'INSERT INTO utenti (username,nome,cognome,email,password_hash,ruolo_id,attivo)
                             VALUES (:u,:n,:c,:e,:h,(SELECT id FROM ruoli WHERE codice=:r),:a)'
                        )->execute([
                            ':u' => $values['username'],
                            ':n' => $values['nome'],
                            ':c' => $values['cognome'],
                            ':e' => $values['email'],
                            ':h' => password_hash($pwd, PASSWORD_DEFAULT),
                            ':r' => $values['ruolo'],
                            ':a' => (int) $values['attivo'],
                        ]);
                        redirect_with('utenti', 'success', 'Utente creato correttamente.');
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
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti', 'url' => url('utenti')], ['label' => 'Nuovo']];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/utenti/_form.php';
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
            redirect_with('utenti', 'danger', 'Utente non trovato.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.nome, u.cognome, u.email, r.codice AS ruolo, u.attivo
               FROM utenti u
               JOIN ruoli r ON r.id = u.ruolo_id
              WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $utente = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$utente) {
            redirect_with('utenti', 'danger', 'Utente non trovato.');
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
                        $base_params = [
                            ':u'  => $values['username'],
                            ':n'  => $values['nome'],
                            ':c'  => $values['cognome'],
                            ':e'  => $values['email'],
                            ':r'  => $values['ruolo'],
                            ':a'  => (int) $values['attivo'],
                            ':id' => $id,
                        ];
                        if ($pwd !== '') {
                            $this->pdo->prepare(
                                'UPDATE utenti
                                    SET username=:u, nome=:n, cognome=:c, email=:e,
                                        ruolo_id=(SELECT id FROM ruoli WHERE codice=:r),
                                        attivo=:a, password_hash=:h
                                  WHERE id=:id'
                            )->execute($base_params + [':h' => password_hash($pwd, PASSWORD_DEFAULT)]);
                        } else {
                            $this->pdo->prepare(
                                'UPDATE utenti
                                    SET username=:u, nome=:n, cognome=:c, email=:e,
                                        ruolo_id=(SELECT id FROM ruoli WHERE codice=:r),
                                        attivo=:a
                                  WHERE id=:id'
                            )->execute($base_params);
                        }

                        if ($id === (int) ($me['id'] ?? 0)) {
                            $_SESSION['qk_user']['display_name'] = trim($values['nome'] . ' ' . $values['cognome']);
                            $_SESSION['qk_user']['username']     = $values['username'];
                        }

                        redirect_with('utenti', 'success', 'Utente aggiornato correttamente.');
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
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Utenti', 'url' => url('utenti')], ['label' => $display_name]];

        $entity_id = $id;
        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/utenti/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function delete(): void
    {
        if (!can('manage_users')) {
            redirect_with('dashboard', 'danger', 'Accesso riservato agli amministratori.');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('utenti');
        }

        if (!csrf_verify()) {
            redirect_with('utenti', 'danger', 'Token di sicurezza non valido. Ricarica la pagina.');
        }

        $me = current_user();
        $id = (int) post('id');
        $action = post('action'); // disable|enable|delete

        if ($id <= 0) {
            redirect_with('utenti', 'danger', 'Utente non trovato.');
        }
        if ($id === (int) ($me['id'] ?? 0)) {
            redirect_with('utenti', 'danger', 'Non puoi eseguire questa operazione sul tuo stesso account.');
        }

        $stmt = $this->pdo->prepare('SELECT id, nome, cognome FROM utenti WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $utente = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$utente) {
            redirect_with('utenti', 'danger', 'Utente non trovato.');
        }

        $nome_completo = trim($utente['nome'] . ' ' . $utente['cognome']);

        try {
            switch ($action) {
                case 'disable':
                    $this->pdo->prepare('UPDATE utenti SET attivo=0 WHERE id=:id')->execute([':id' => $id]);
                    redirect_with('utenti', 'success', "{$nome_completo} è stato disabilitato.");
                case 'enable':
                    $this->pdo->prepare('UPDATE utenti SET attivo=1 WHERE id=:id')->execute([':id' => $id]);
                    redirect_with('utenti', 'success', "{$nome_completo} è stato riabilitato.");
                case 'delete':
                    $this->pdo->prepare('DELETE FROM utenti WHERE id=:id')->execute([':id' => $id]);
                    redirect_with('utenti', 'success', "Utente {$nome_completo} eliminato definitivamente.");
                default:
                    redirect_with('utenti', 'danger', 'Operazione non riconosciuta.');
            }
        } catch (PDOException) {
            redirect_with('utenti', 'danger', 'Errore durante l\'operazione. Riprova.');
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

