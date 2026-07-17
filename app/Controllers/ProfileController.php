<?php

declare(strict_types=1);

class ProfileController extends BaseController
{
    public function index(): void
    {
        $me         = current_user();
        $errors     = [];
        $ok         = null;
        $active_tab = 'dati';

        require_once APP_ROOT_DIR . '/includes/utenti.php';

        /* Carica dati freschi dal DB tramite Helper */
        $utente = get_utente_by_id($this->pdo, $me['id']);

        if (!$utente) { redirect('dashboard'); }

        $values = [
            'nome'    => $utente['nome'],
            'cognome' => $utente['cognome'],
            'email'   => $utente['email'],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {

                $action = post('action');

                /* ── Aggiorna dati personali ─────────────────────────── */
                if ($action === 'dati') {
                    $active_tab        = 'dati';
                    $values['nome']    = trim(post('nome'));
                    $values['cognome'] = trim(post('cognome'));
                    $values['email']   = trim(post('email'));

                    if ($values['nome'] === '')    $errors['nome']    = 'Il nome è obbligatorio.';
                    if ($values['cognome'] === '') $errors['cognome'] = 'Il cognome è obbligatorio.';
                    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL))
                        $errors['email'] = 'Email non valida.';

                    if (empty($errors)) {
                        try {
                            update_dati_utente($this->pdo, $me['id'], [
                                'nome'    => $values['nome'],
                                'cognome' => $values['cognome'],
                                'email'   => $values['email']
                            ]);

                            $_SESSION['qk_user']['display_name'] = trim($values['nome'] . ' ' . $values['cognome']);

                            $ok                 = 'Dati aggiornati correttamente.';
                            $utente['nome']     = $values['nome'];
                            $utente['cognome']  = $values['cognome'];
                            $utente['email']    = $values['email'];
                        } catch (PDOException $ex) {
                            $errors['_global'] = str_contains($ex->getMessage(), 'UNIQUE')
                                ? 'Email già in uso da un altro account.'
                                : 'Errore nel salvataggio. Riprova.';
                        }
                    }
                }

                /* ── Cambio password ─────────────────────────────────── */
                if ($action === 'password') {
                    $active_tab  = 'sicurezza';
                    $pwd_current = post('pwd_current');
                    $pwd_new     = post('pwd_new');
                    $pwd_confirm = post('pwd_confirm');

                    $hash = $utente['password_hash'];

                    if (!password_verify($pwd_current, $hash))
                        $errors['pwd_current'] = 'Password attuale non corretta.';
                    if (strlen($pwd_new) < 8)
                        $errors['pwd_new'] = 'La nuova password deve avere almeno 8 caratteri.';
                    if ($pwd_new !== $pwd_confirm)
                        $errors['pwd_confirm'] = 'Le password non coincidono.';

                    if (empty($errors)) {
                        update_password_utente($this->pdo, $me['id'], password_hash($pwd_new, PASSWORD_DEFAULT));
                        $ok = 'Password aggiornata correttamente.';
                    }
                }
            }
        }

        $display_name = trim($utente['nome'] . ' ' . $utente['cognome']);

        $this->page(APP_ROOT_DIR . '/app/views/profile/_index.php', [
            'page_title'    => 'Profilo',
            'page_subtitle' => $display_name,
            'breadcrumbs'   => [['label' => 'Profilo']],
            'utente'        => $utente,
            'values'        => $values,
            'errors'        => $errors,
            'ok'            => $ok,
            'active_tab'    => $active_tab,
            'display_name'  => $display_name,
        ]);
    }
}
