<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';

final class MagazziniController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $magazzini = qk_magazzini_list($this->pdo, false);
        $total     = count($magazzini);

        $page_title    = 'Magazzini';
        $page_subtitle = 'Anagrafica dei siti fisici e virtuali di stoccaggio';
        $breadcrumbs   = [['label' => 'Sistema'], ['label' => 'Magazzini']];
        $page_actions  = '<a href="' . url('settings/magazzini/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo magazzino
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/magazzini/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $mode      = 'create';
        $entity_id = null;
        $errors    = [];
        $values    = $this->defaultValues();
        $aziende   = qk_aziende_list($this->pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);
                $errors = $this->validate($values, null);

                if (empty($errors)) {
                    try {
                        $id = qk_insert_magazzino($this->pdo, $values);
                        redirect_with('settings/magazzini', 'success', 'Magazzino creato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $page_title    = 'Nuovo magazzino';
        $page_subtitle = 'Censisci un nuovo sito di stoccaggio';
        $breadcrumbs   = [
            ['label' => 'Sistema'],
            ['label' => 'Magazzini', 'url' => url('settings/magazzini')],
            ['label' => 'Nuovo'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/magazzini/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $mode      = 'edit';
        $entity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$entity_id) {
            redirect_with('settings/magazzini', 'danger', 'Magazzino non specificato.');
        }

        $loaded = qk_load_magazzino_form($this->pdo, $entity_id);
        if (!$loaded) {
            http_response_code(404);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        $values  = array_merge($this->defaultValues(), $loaded);
        $errors  = [];
        $aziende = qk_aziende_list($this->pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);
                $errors = $this->validate($values, $entity_id);

                if (empty($errors)) {
                    try {
                        qk_update_magazzino($this->pdo, $entity_id, $values);
                        redirect_with('settings/magazzini', 'success', 'Magazzino aggiornato.');
                    } catch (Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $page_title    = $values['nome'] ?: 'Modifica magazzino';
        $page_subtitle = 'Modifica anagrafica · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Sistema'],
            ['label' => 'Magazzini', 'url' => url('settings/magazzini')],
            ['label' => $values['nome'] ?: 'Modifica'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/settings/magazzini/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Disattivazione (soft-delete) ────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('settings/magazzini');
        }

        if (!csrf_verify()) {
            redirect_with('settings/magazzini', 'danger', 'Token CSRF non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                qk_disattiva_magazzino($this->pdo, $id);
                redirect_with('settings/magazzini', 'success', 'Magazzino disattivato.');
            } catch (Throwable $e) {
                redirect_with('settings/magazzini', 'danger', 'Impossibile disattivare il magazzino: ' . $e->getMessage());
            }
        } else {
            redirect_with('settings/magazzini', 'danger', 'ID magazzino non valido.');
        }
    }

    /* ── Riattivazione ────────────────────────────────────────────────────── */

    public function reactivate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('settings/magazzini');
        }

        if (!csrf_verify()) {
            redirect_with('settings/magazzini', 'danger', 'Token CSRF non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            qk_riattiva_magazzino($this->pdo, $id);
            redirect_with('settings/magazzini', 'success', 'Magazzino riattivato.');
        } else {
            redirect_with('settings/magazzini', 'danger', 'ID magazzino non valido.');
        }
    }

    /* ── Imposta come predefinito (azione rapida da lista) ───────────────── */

    public function setDefault(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('settings/magazzini');
        }

        if (!csrf_verify()) {
            redirect_with('settings/magazzini', 'danger', 'Token CSRF non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                qk_imposta_magazzino_predefinito($this->pdo, $id);
                redirect_with('settings/magazzini', 'success', 'Magazzino predefinito aggiornato.');
            } catch (Throwable $e) {
                redirect_with('settings/magazzini', 'danger', $e->getMessage());
            }
        } else {
            redirect_with('settings/magazzini', 'danger', 'ID magazzino non valido.');
        }
    }

    /* ── Helper privati ───────────────────────────────────────────────────── */

    private function defaultValues(): array
    {
        return [
            'codice'             => '',
            'nome'               => '',
            'tipo'               => 'fisico',
            'azienda_interna_id' => null,
            'indirizzo_id'       => null,
            'via'                => '',
            'civico'             => '',
            'cap'                => '',
            'citta'              => '',
            'provincia'          => '',
            'nazione'            => 'IT',
            'predefinito'        => false,
            'attivo'             => true,
            'note'               => '',
        ];
    }

    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            if (in_array($k, ['predefinito', 'attivo'], true)) {
                continue; // gestiti separatamente sotto (checkbox)
            }
            $val = $current[$k] ?? '';
            if (is_bool($val)) {
                $val = $val ? '1' : '0';
            }
            $current[$k] = post($k, (string) $val);
        }
        $current['azienda_interna_id'] = post('azienda_interna_id', '') !== '' ? (int) post('azienda_interna_id') : null;
        $current['predefinito'] = isset($_POST['predefinito']) ? '1' : '0';
        $current['attivo']      = isset($_POST['attivo']) ? '1' : '0';
        return $current;
    }

    private function validate(array $values, ?int $excludeId): array
    {
        $errors = [];
        if (trim((string) $values['codice']) === '') {
            $errors['codice'] = 'Il codice è obbligatorio.';
        } else {
            $st = $this->pdo->prepare('SELECT id FROM magazzini WHERE codice = ?' . ($excludeId ? ' AND id != ?' : ''));
            $params = [trim($values['codice'])];
            if ($excludeId) { $params[] = $excludeId; }
            $st->execute($params);
            if ($st->fetchColumn()) {
                $errors['codice'] = 'Codice già in uso da un altro magazzino.';
            }
        }
        if (trim((string) $values['nome']) === '') {
            $errors['nome'] = 'Il nome è obbligatorio.';
        }
        if (!in_array($values['tipo'], ['fisico', 'virtuale', 'conto_terzi'], true)) {
            $errors['tipo'] = 'Tipo magazzino non valido.';
        }
        return $errors;
    }
}
