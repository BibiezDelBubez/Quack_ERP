<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/AdrRepository.php';

/**
 * AdrController — /adr, /adr/codici/create, /adr/codici/edit, /adr/codici/delete
 */
final class AdrController extends BaseController
{
    private const TABS = ['codici', 'classi', 'imballaggio', 'gallerie', 'categorie', 'classificazioni'];
    private AdrRepository $repo;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->repo = new AdrRepository($pdo);
    }

    public function index(): void
    {
        $tab = get_param('tab', 'codici');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'codici';
        }

        $ok     = null;
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                [$ok, $errors, $tab] = $this->handleLookupPost($tab);
                if ($ok && empty($errors)) {
                    redirect_with('adr?tab=' . $tab, 'success', $ok);
                }
            }
        }

        $search    = get_param('q');
        $page      = max(1, (int) get_param('page', '1'));
        $per_page  = 100;

        [$codici, $codici_total, $codici_pages] = $this->repo->loadCodiciOnu($search ?? '', $page, $per_page);
        $classi     = $this->repo->loadAllClassiList();
        $imballaggi = $this->repo->loadAllImballaggiList();
        $gallerie   = $this->repo->loadAllGallerieList();
        $categorie_trasporto = $this->repo->loadAllCategorieTrasportoList();
        $codici_classificazione = $this->repo->loadAllCodiciClassificazioneList();

        $this->page(APP_ROOT_DIR . '/app/views/adr/_index.php', [
            'page_title'    => 'Anagrafiche ADR',
            'page_subtitle' => 'Merci pericolose — Accordo europeo',
            'breadcrumbs'   => [['label' => 'Anagrafiche ADR']],
            'tab'           => $tab,
            'ok'            => $ok,
            'errors'        => $errors,
            'search'        => $search,
            'page'          => $page,
            'per_page'      => $per_page,
            'codici_total'  => $codici_total,
            'codici_pages'  => $codici_pages,
            'codici'        => $codici,
            'classi'        => $classi,
            'imballaggi'    => $imballaggi,
            'gallerie'      => $gallerie,
            'categorie_trasporto'     => $categorie_trasporto,
            'codici_classificazione'  => $codici_classificazione,
        ]);
    }

    public function createCodice(): void
    {
        $errors = [];
        $values = $this->defaultCodiceValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = $this->readCodicePost();
                $errors = $this->validateCodice($values);
                if (empty($errors)) {
                    try {
                        $this->repo->insertCodice($values);
                        redirect_with('adr?tab=codici', 'success', 'Codice ONU ' . $values['codice_onu'] . ' aggiunto correttamente.');
                    } catch (\Throwable $e) {
                        error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = $e instanceof PDOException ? $this->dbMessage($e) : $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/adr/_codice_form.php', [
            'page_title'    => 'Nuovo codice ONU',
            'page_subtitle' => 'Aggiungi una voce alla Tabella A ADR',
            'breadcrumbs'   => [
                ['label' => 'ADR', 'url' => url('adr?tab=codici')],
                ['label' => 'Nuovo codice ONU'],
            ],
            'mode'      => 'create',
            'entity_id' => null,
            'values'    => $values,
            'errors'    => $errors,
            'lookup'    => $this->repo->loadLookups(),
        ]);
    }

    public function editCodice(): void
    {
        $id = (int) get_param('id');
        if ($id <= 0) {
            redirect_with('adr?tab=codici', 'danger', 'Record non trovato.');
        }

        $record = $this->repo->getCodiceOnu($id);
        if (!$record) {
            redirect_with('adr?tab=codici', 'danger', 'Record non trovato.');
        }

        $values = array_merge($this->defaultCodiceValues(), $record);
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = $this->readCodicePost();
                $errors = $this->validateCodice($values);
                if (empty($errors)) {
                    try {
                        $this->repo->updateCodice($id, $values);
                        redirect_with('adr/codici/edit?id=' . $id, 'success', 'Codice ONU aggiornato.');
                    } catch (\Throwable $e) {
                        error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = $e instanceof PDOException ? $this->dbMessage($e) : $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/adr/_codice_form.php', [
            'page_title'    => 'UN ' . $record['codice_onu'],
            'page_subtitle' => 'Modifica codice ONU · ID #' . $id,
            'breadcrumbs'   => [
                ['label' => 'ADR', 'url' => url('adr?tab=codici')],
                ['label' => 'UN ' . $record['codice_onu']],
            ],
            'mode'      => 'edit',
            'entity_id' => $id,
            'values'    => $values,
            'errors'    => $errors,
            'lookup'    => $this->repo->loadLookups(),
        ]);
    }

    public function deleteCodice(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('adr');
        }
        if (!csrf_verify()) {
            redirect_with('adr?tab=codici', 'danger', 'Token di sicurezza non valido.');
        }

        $id = (int) post('id');
        if ($id <= 0) {
            redirect_with('adr?tab=codici', 'danger', 'Record non trovato.');
        }

        try {
            $this->repo->deleteCodice($id);
            redirect_with('adr?tab=codici', 'success', 'Codice ONU eliminato.');
        } catch (PDOException $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            redirect_with('adr?tab=codici', 'danger', $this->dbMessage($e));
        }
    }

    private function defaultCodiceValues(): array
    {
        return [
            'codice_onu'                  => '',
            'nome_descrizione'            => '',
            'classe_pericolo'             => '',
            'codice_classificazione'      => '',
            'gruppo_imballaggio'          => '',
            'categoria_trasporto'         => '',
            'codice_restrizione_gallerie' => '',
            'quantita_limitata'           => '',
            'codice_quantita_esente'      => '',
            'e_merce_alto_rischio'        => '',
            'e_inquinante_marino'         => '',
            'inizio_validita'             => '',
            'fine_validita'               => '',
            'etichette_str'               => '',
        ];
    }

    private function readCodicePost(): array
    {
        $bool = fn(string $k): string => isset($_POST[$k]) ? '1' : '0';
        return [
            'codice_onu'                  => post('codice_onu'),
            'nome_descrizione'            => post('nome_descrizione'),
            'classe_pericolo'             => post('classe_pericolo'),
            'codice_classificazione'      => post('codice_classificazione'),
            'gruppo_imballaggio'          => post('gruppo_imballaggio'),
            'categoria_trasporto'         => post('categoria_trasporto'),
            'codice_restrizione_gallerie' => post('codice_restrizione_gallerie'),
            'quantita_limitata'           => post('quantita_limitata'),
            'codice_quantita_esente'      => post('codice_quantita_esente'),
            'e_merce_alto_rischio'        => $bool('e_merce_alto_rischio'),
            'e_inquinante_marino'         => $bool('e_inquinante_marino'),
            'inizio_validita'             => post('inizio_validita'),
            'fine_validita'               => post('fine_validita'),
            'etichette_str'               => post('etichette_str'),
        ];
    }

    private function validateCodice(array $v): array
    {
        $errors = [];
        $onu    = trim($v['codice_onu']);
        if ($onu === '') {
            $errors['codice_onu'] = 'Il numero ONU è obbligatorio.';
        } elseif (!ctype_digit($onu) || (int) $onu <= 0) {
            $errors['codice_onu'] = 'Il numero ONU deve essere un intero positivo (es. 1203).';
        }
        return $errors;
    }

    private function handleLookupPost(string $currentTab): array
    {
        $action = post('action');
        $ok     = null;
        $errors = [];
        $tab    = $currentTab;

        try {
            switch ($action) {
                case 'save_classe':
                    $tab    = 'classi';
                    $codice = trim(post('codice'));
                    $denom  = trim(post('denominazione'));
                    $div    = isset($_POST['ha_divisioni']) ? 1 : 0;
                    if ($codice === '' || $denom === '') {
                        $errors['classe'] = 'Codice e denominazione sono obbligatori.';
                        break;
                    }
                    $this->repo->saveClasse($codice, $denom, $div);
                    $ok = "Classe $codice salvata correttamente.";
                    break;

                case 'delete_classe':
                    $tab    = 'classi';
                    $codice = trim(post('codice'));
                    try {
                        $this->repo->deleteClasse($codice);
                        $ok = "Classe $codice eliminata.";
                    } catch (PDOException $e) {
                        error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        if ((string)$e->getCode() === '23000') {
                            $errors['classe'] = "Impossibile eliminare la classe $codice: è attualmente in uso (es. nei Codici ONU).";
                        } else {
                            $errors['classe'] = "Errore durante l'eliminazione: " . $e->getMessage();
                        }
                    }
                    break;

                case 'save_imb':
                    $tab    = 'imballaggio';
                    $codice = trim(post('codice'));
                    $desc   = trim(post('descrizione'));
                    if ($codice === '' || $desc === '') {
                        $errors['imb'] = 'Codice e descrizione sono obbligatori.';
                        break;
                    }
                    $this->repo->saveImballaggio($codice, $desc);
                    $ok = "Gruppo $codice salvato correttamente.";
                    break;

                case 'delete_imb':
                    $tab    = 'imballaggio';
                    $codice = trim(post('codice'));
                    $this->repo->deleteImballaggio($codice);
                    $ok = "Gruppo $codice eliminato.";
                    break;

                case 'save_galleria':
                    $tab      = 'gallerie';
                    $codice   = trim(post('codice'));
                    $desc     = trim(post('descrizione'));
                    $cisterna = trim(post('si_applica_a_cisterna'));
                    if ($codice === '') {
                        $errors['galleria'] = 'Il codice è obbligatorio.';
                        break;
                    }
                    $this->repo->saveGalleria($codice, $cisterna === '' ? null : $cisterna, $desc);
                    $ok = "Codice galleria «$codice» salvato correttamente.";
                    break;

                case 'delete_galleria':
                    $tab    = 'gallerie';
                    $codice = trim(post('codice'));
                    $this->repo->deleteGalleria($codice);
                    $ok = "Codice galleria «$codice» eliminato.";
                    break;

                case 'save_categoria':
                    $tab      = 'categorie';
                    $cat      = post('categoria');
                    $molt     = post('moltiplicatore_punti');
                    $soglia   = post('soglia_esenzione_kg_l');
                    $desc     = trim(post('descrizione'));
                    if ($cat === '' || !is_numeric($cat) || (int)$cat < 0 || (int)$cat > 4) {
                        $errors['categoria'] = 'Categoria non valida (deve essere un intero tra 0 e 4).';
                        break;
                    }
                    $this->repo->saveCategoria((int)$cat, $molt !== '' ? (int)$molt : 0, $soglia !== '' ? (int)$soglia : null, $desc !== '' ? $desc : null);
                    $ok = "Categoria $cat salvata correttamente.";
                    break;

                case 'delete_categoria':
                    $tab = 'categorie';
                    $cat = (int) post('categoria');
                    $this->repo->deleteCategoria($cat);
                    $ok = "Categoria $cat eliminata.";
                    break;

                case 'save_classificazione':
                    $tab    = 'classificazioni';
                    $codice = trim(post('codice'));
                    $classe = trim(post('codice_classe'));
                    $desc   = trim(post('descrizione'));
                    $prim   = trim(post('pericolo_primario'));
                    $sec    = trim(post('pericolo_secondario'));
                    if ($codice === '' || $classe === '') {
                        $errors['classificazione'] = 'Codice e classe sono obbligatori.';
                        break;
                    }
                    $this->repo->saveClassificazione($codice, $classe, $desc !== '' ? $desc : null, $prim !== '' ? $prim : null, $sec !== '' ? $sec : null);
                    $ok = "Codice classificazione «$codice» (Classe $classe) salvato correttamente.";
                    break;

                case 'delete_classificazione':
                    $tab    = 'classificazioni';
                    $codice = trim(post('codice'));
                    $classe = trim(post('codice_classe'));
                    $this->repo->deleteClassificazione($codice, $classe);
                    $ok = "Codice classificazione «$codice» (Classe $classe) eliminato.";
                    break;
            }
        } catch (PDOException $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            $errors['_global'] = $this->dbMessage($e);
        }

        return [$ok, $errors, $tab];
    }

    public function apiLookup(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $map = [
            'classi'          => ['adr_classi_pericolo',              'codice',    'codice',    'denominazione',       'CAST(codice AS REAL)'],
            'classificazioni' => ['adr_codici_classificazione',       'codice',    'codice',    'descrizione',         'codice_classe, codice'],
            'imballaggi'      => ['adr_gruppi_imballaggio',           'codice',    'codice',    'descrizione',         'codice'],
            'categorie'       => ['adr_categorie_trasporto',          'categoria', 'categoria', 'descrizione',         'categoria'],
            'gallerie'        => ['adr_codici_restrizione_gallerie',  'codice',    'codice',    'descrizione',         'codice'],
            'quantita_esenti' => ['adr_quantita_esenti',              'codice',    'codice',    'descrizione',         'codice'],
            'unita_misura'    => ['unita_misura',                     'id',        'codice',    'descrizione',         'descrizione, codice'],
            'codici_onu'      => ['adr_codici_onu',                   'id',        'codice_onu', 'nome_descrizione',    'codice_onu'],
        ];

        $type = get_param('type', '');
        if (!isset($map[$type])) {
            echo json_encode([]);
            exit;
        }

        [$table, $idCol, $labelCol, $subCol, $order] = $map[$type];
        $q = trim(get_param('q', ''));

        echo json_encode($this->repo->lookup($table, $idCol, $labelCol, $subCol, $order, $q), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    public function importCodici(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']);
            exit;
        }

        $rows = json_decode(post('rows') ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']);
            exit;
        }

        $inserted  = 0;
        $skipped   = 0;
        $rowErrors = [];

        $validationMaps = $this->repo->validateClassi();
        $classi = $validationMaps['classi'];
        $gruppi = $validationMaps['gruppi'];
        $catTrasporto = $validationMaps['catTrasporto'];
        $gallerie = $validationMaps['gallerie'];
        $qtaEsente = $validationMaps['qtaEsente'];
        $classificazioni = $validationMaps['classificazioni'];

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $onu = trim((string)($row['codice_onu'] ?? ''));
                if ($onu === '' || !ctype_digit($onu) || (int)$onu <= 0) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': numero ONU non valido ("' . htmlspecialchars($onu) . '") — saltata.';
                    continue;
                }

                $classe = trim((string)($row['classe_pericolo'] ?? ''));
                if ($classe !== '' && !in_array($classe, $classi, true)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': la classe di pericolo "' . htmlspecialchars($classe) . '" non esiste.';
                    continue;
                }
                
                $gruppo = trim((string)($row['gruppo_imballaggio'] ?? ''));
                if ($gruppo !== '' && !in_array($gruppo, $gruppi, true)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': il gruppo imballaggio "' . htmlspecialchars($gruppo) . '" non esiste.';
                    continue;
                }

                $cat = trim((string)($row['categoria_trasporto'] ?? ''));
                if ($cat !== '' && !in_array((int)$cat, $catTrasporto, true)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': la categoria trasporto "' . htmlspecialchars($cat) . '" non esiste.';
                    continue;
                }

                $gall = trim((string)($row['codice_restrizione_gallerie'] ?? ''));
                if ($gall !== '' && !in_array($gall, $gallerie, true)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': il codice restrizione gallerie "' . htmlspecialchars($gall) . '" non esiste.';
                    continue;
                }

                $qe = trim((string)($row['codice_quantita_esente'] ?? ''));
                if ($qe !== '' && !in_array($qe, $qtaEsente, true)) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': il codice quantità esente "' . htmlspecialchars($qe) . '" non esiste.';
                    continue;
                }

                $classif = trim((string)($row['codice_classificazione'] ?? ''));
                if ($classif !== '' && $classe !== '') {
                    $key = strtolower($classif) . '|' . strtolower($classe);
                    if (!isset($classificazioni[$key])) {
                        $skipped++;
                        $rowErrors[] = 'Riga ' . ($i + 1) . ': la combinazione di codice classificazione "' . htmlspecialchars($classif) . '" e classe pericolo "' . htmlspecialchars($classe) . '" non esiste.';
                        continue;
                    }
                }

                $bool = fn(string $k): string => ((string)($row[$k] ?? '') === '1') ? '1' : '0';
                $this->pdo->exec('SAVEPOINT sp_import_row');
                try {
                    $this->repo->insertCodice([
                        'codice_onu'                      => $onu,
                        'nome_descrizione'                => $row['nome_descrizione'] ?? '',
                        'classe_pericolo'                 => $row['classe_pericolo'] ?? '',
                        'codice_classificazione'          => $row['codice_classificazione'] ?? '',
                        'gruppo_imballaggio'              => $row['gruppo_imballaggio'] ?? '',
                        'categoria_trasporto'             => $row['categoria_trasporto'] ?? '',
                        'codice_restrizione_gallerie'     => $row['codice_restrizione_gallerie'] ?? '',
                        'quantita_limitata'               => $row['quantita_limitata'] ?? '',
                        'codice_quantita_esente'          => $row['codice_quantita_esente'] ?? '',
                        'id_unita_misura_qta_limitata'    => $row['id_unita_misura_qta_limitata'] ?? '',
                        'e_merce_alto_rischio'            => $bool('e_merce_alto_rischio'),
                        'e_inquinante_marino'             => $bool('e_inquinante_marino'),
                        'e_pericoloso_ambiente_acquatico' => $bool('e_pericoloso_ambiente_acquatico'),
                        'inizio_validita'                 => $row['inizio_validita'] ?? '',
                        'fine_validita'                   => $row['fine_validita'] ?? '',
                    ]);
                    $this->pdo->exec('RELEASE SAVEPOINT sp_import_row');
                    $inserted++;
                } catch (\Throwable $rowEx) {
                    error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $rowEx->getMessage());
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_import_row');
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': ' . ($rowEx instanceof PDOException ? $this->dbMessage($rowEx) : $rowEx->getMessage()) . ' — saltata.';
                }
            }
            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'errors'   => $rowErrors,
            ]);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e instanceof PDOException ? $this->dbMessage($e) : $e->getMessage()]);
        }
        exit;
    }

    public function importClassificazioni(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']);
            exit;
        }

        $rows = json_decode(post('rows') ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']);
            exit;
        }

        $inserted  = 0;
        $skipped   = 0;
        $rowErrors = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $codice = trim((string)($row['codice'] ?? ''));
                $classe = trim((string)($row['codice_classe'] ?? ''));
                if ($codice === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': codice di classificazione vuoto — saltata.';
                    continue;
                }
                if ($classe === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': classe ADR vuota — saltata.';
                    continue;
                }
                $this->pdo->exec('SAVEPOINT sp_import_row');
                try {
                    $this->repo->saveClassificazione(
                        $codice,
                        $classe,
                        $row['descrizione'] ?? null,
                        $row['pericolo_primario'] ?? null,
                        $row['pericolo_secondario'] ?? null
                    );
                    $this->pdo->exec('RELEASE SAVEPOINT sp_import_row');
                    $inserted++;
                } catch (\Throwable $rowEx) {
                    error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $rowEx->getMessage());
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_import_row');
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': ' . ($rowEx instanceof PDOException ? $this->dbMessage($rowEx) : $rowEx->getMessage()) . ' — saltata.';
                }
            }
            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'errors'   => $rowErrors,
            ]);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e instanceof PDOException ? $this->dbMessage($e) : $e->getMessage()]);
        }
        exit;
    }

    public function esenzione(): void
    {
        $data = $this->repo->loadAllEsenzioneData();
        $categorie = $data['categorie'];
        $sostanze = $data['sostanze'];

        $page_title    = 'Calcola Esenzione ADR';
        $page_subtitle = 'Verifica del limite 1000 punti (ADR 1.1.3.6)';
        $breadcrumbs   = [
            ['label' => 'ADR', 'url' => url('adr')],
            ['label' => 'Calcola Esenzione'],
        ];

        require INCLUDES_DIR . '/header.php';
        include APP_ROOT_DIR . '/app/views/adr/_esenzione.php';
        require INCLUDES_DIR . '/footer.php';
    }

    public function apiProdottiAdr(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q     = trim(get_param('q', ''));
        $limit = min(30, max(1, (int) get_param('limit', '20')));

        echo json_encode($this->repo->searchApiProdotti($q, $limit));
        exit;
    }
}
