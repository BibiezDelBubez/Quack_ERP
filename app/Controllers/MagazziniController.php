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
        $radici    = qk_magazzini_list($this->pdo, true, true);
        $soggetti  = $this->soggettiEsterniList();

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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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

        $values   = array_merge($this->defaultValues(), $loaded);
        $errors   = [];
        $aziende  = qk_aziende_list($this->pdo);
        $radici   = array_values(array_filter(
            qk_magazzini_list($this->pdo, true, true),
            static fn(array $m): bool => (int) $m['id'] !== $entity_id
        ));
        $soggetti = $this->soggettiEsterniList();

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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
            'codice'              => '',
            'nome'                => '',
            'tipo'                => 'fisico',
            'azienda_interna_id'  => null,
            'indirizzo_id'        => null,
            'parent_id'           => null,
            'soggetto_esterno_id' => null,
            'via'                 => '',
            'civico'              => '',
            'cap'                 => '',
            'citta'               => '',
            'provincia'           => '',
            'nazione'             => 'IT',
            'predefinito'         => false,
            'attivo'              => true,
            'note'                => '',
        ];
    }

    private function readPostValues(array $current): array
    {
        $current = $this->readFormValues(
            $current,
            array_keys($this->defaultValues()),
            ['predefinito', 'attivo'],
            ['azienda_interna_id', 'parent_id', 'soggetto_esterno_id']
        );
        $current['azienda_interna_id']  = post('azienda_interna_id', '') !== '' ? (int) post('azienda_interna_id') : null;
        $current['parent_id']           = post('parent_id', '') !== '' ? (int) post('parent_id') : null;
        $current['soggetto_esterno_id'] = post('soggetto_esterno_id', '') !== '' ? (int) post('soggetto_esterno_id') : null;
        return $current;
    }

    /**
     * Anagrafica soggetti esterni per il selettore "gestito da" dei magazzini
     * conto_terzi/conto_lavoro (§7.1) — riusa clienti_fornitori invece di
     * duplicare nome/indirizzo del terzista in testo libero.
     */
    private function soggettiEsterniList(): array
    {
        return $this->pdo->query(
            "SELECT id, COALESCE(NULLIF(ragione_sociale, ''), TRIM(nome || ' ' || COALESCE(cognome, ''))) AS nome_display
               FROM clienti_fornitori
              WHERE attivo = 1
              ORDER BY nome_display COLLATE NOCASE"
        )->fetchAll(PDO::FETCH_ASSOC);
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

        $tipiValidi = ['fisico', 'virtuale', 'conto_terzi', 'conto_lavoro'];
        if (!in_array($values['tipo'], $tipiValidi, true)) {
            $errors['tipo'] = 'Tipo magazzino non valido.';
        }

        // Gerarchia spaziale a due soli livelli (Trasferimenti tra Magazzini
        // come Documento.md, §7.1): un fisico è sempre radice (parent_id NULL);
        // un virtuale/conto_terzi/conto_lavoro, se ha un genitore, può puntare
        // SOLO a un fisico radice — mai a un altro non-fisico o a un fisico
        // che a sua volta ha un genitore (vieterebbe di fatto un terzo livello).
        $parentId = $values['parent_id'] !== null && $values['parent_id'] !== '' ? (int) $values['parent_id'] : null;
        if ($values['tipo'] === 'fisico') {
            if ($parentId !== null) {
                $errors['parent_id'] = 'Un magazzino fisico non può avere un genitore: è sempre una radice.';
            }
        } elseif ($parentId !== null) {
            if ($excludeId !== null && $parentId === $excludeId) {
                $errors['parent_id'] = 'Un magazzino non può essere genitore di se stesso.';
            } else {
                $st = $this->pdo->prepare('SELECT tipo, parent_id FROM magazzini WHERE id = ?');
                $st->execute([$parentId]);
                $parent = $st->fetch(PDO::FETCH_ASSOC);
                if (!$parent || $parent['tipo'] !== 'fisico' || $parent['parent_id'] !== null) {
                    $errors['parent_id'] = 'Il genitore deve essere un magazzino fisico radice.';
                }
            }
        }

        // Un magazzino che ha già dei figli non può diventare a sua volta
        // non-radice (perderebbe la radice fisica di riferimento per i suoi
        // figli) né cambiare tipo lasciando di essere fisico.
        if ($excludeId !== null) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM magazzini WHERE parent_id = ?');
            $st->execute([$excludeId]);
            if ((int) $st->fetchColumn() > 0) {
                if ($values['tipo'] !== 'fisico') {
                    $errors['tipo'] = 'Questo magazzino è già genitore di altri: non può cambiare tipo da fisico.';
                }
                if ($parentId !== null) {
                    $errors['parent_id'] = 'Questo magazzino è già genitore di altri: non può a sua volta avere un genitore.';
                }
            }
        }

        $soggettoEsternoId = $values['soggetto_esterno_id'] !== null && $values['soggetto_esterno_id'] !== ''
            ? (int) $values['soggetto_esterno_id'] : null;
        if ($soggettoEsternoId !== null) {
            $st = $this->pdo->prepare('SELECT id FROM clienti_fornitori WHERE id = ?');
            $st->execute([$soggettoEsternoId]);
            if (!$st->fetchColumn()) {
                $errors['soggetto_esterno_id'] = 'Soggetto esterno non valido.';
            }
        }

        return $errors;
    }
}
