<?php

declare(strict_types=1);

/**
 * AdrController — /adr, /adr/codici/create, /adr/codici/edit, /adr/codici/delete
 *
 * Struttura tab:
 *   ?tab=codici      → lista adr_codici_onu (create/edit su pagine separate)
 *   ?tab=classi      → adr_classi_pericolo  (CRUD inline via modal)
 *   ?tab=imballaggio → adr_gruppi_imballaggio (CRUD inline via modal)
 *   ?tab=gallerie    → adr_codici_restrizione_gallerie (CRUD inline via modal)
 */
final class AdrController extends BaseController
{
    private const TABS = ['codici', 'classi', 'imballaggio', 'gallerie', 'categorie', 'classificazioni'];

    /* ════════════════════════════════════════════════════════════════════════
     *  INDEX — pagina tabbed principale
     * ════════════════════════════════════════════════════════════════════════ */

    public function index(): void
    {
        $tab = get_param('tab', 'codici');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'codici';
        }

        $ok     = null;
        $errors = [];

        /* ── Gestione POST inline (lookup tables) ─────────────────────── */
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

        /* ── Dati per tutti i tab ─────────────────────────────────────── */
        $search = get_param('q');

        $codici     = $this->loadCodiciOnu($search);
        $classi     = $this->pdo->query(
            'SELECT codice, denominazione, ha_divisioni
               FROM adr_classi_pericolo
              ORDER BY CAST(codice AS REAL)'
        )->fetchAll();
        $imballaggi = $this->pdo->query(
            'SELECT codice, descrizione FROM adr_gruppi_imballaggio ORDER BY codice'
        )->fetchAll();
        $gallerie   = $this->pdo->query(
            'SELECT g.codice, g.si_applica_a_cisterna, g.descrizione,
                    GROUP_CONCAT(c.categoria_galleria, ", ") AS categorie
               FROM adr_codici_restrizione_gallerie g
          LEFT JOIN adr_restrizione_gallerie_categorie c ON c.restrizione_codice = g.codice
           GROUP BY g.codice
           ORDER BY g.codice'
        )->fetchAll();
        $categorie_trasporto = $this->pdo->query(
            'SELECT categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione
               FROM adr_categorie_trasporto
              ORDER BY categoria'
        )->fetchAll();
        $codici_classificazione = $this->pdo->query(
            'SELECT c.codice, c.codice_classe, c.descrizione, c.pericolo_primario, c.pericolo_secondario,
                    cl.denominazione AS classe_label
               FROM adr_codici_classificazione c
          LEFT JOIN adr_classi_pericolo cl ON cl.codice = c.codice_classe
              ORDER BY c.codice_classe, c.codice'
        )->fetchAll();

        $this->page(APP_ROOT_DIR . '/app/views/adr/_index.php', [
            'page_title'    => 'Anagrafiche ADR',
            'page_subtitle' => 'Merci pericolose — Accordo europeo',
            'breadcrumbs'   => [['label' => 'Anagrafiche ADR']],
            'tab'           => $tab,
            'ok'            => $ok,
            'errors'        => $errors,
            'search'        => $search,
            'codici'        => $codici,
            'classi'        => $classi,
            'imballaggi'    => $imballaggi,
            'gallerie'      => $gallerie,
            'categorie_trasporto'     => $categorie_trasporto,
            'codici_classificazione'  => $codici_classificazione,
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CODICI ONU — creazione
     * ════════════════════════════════════════════════════════════════════════ */

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
                        $this->insertCodice($values);
                        redirect_with('adr?tab=codici', 'success', 'Codice ONU ' . $values['codice_onu'] . ' aggiunto correttamente.');
                    } catch (PDOException $e) {
                        $errors['_global'] = $this->dbMessage($e);
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
            'lookup'    => $this->loadLookups(),
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CODICI ONU — modifica
     * ════════════════════════════════════════════════════════════════════════ */

    public function editCodice(): void
    {
        $id = (int) get_param('id');
        if ($id <= 0) {
            redirect_with('adr?tab=codici', 'danger', 'Record non trovato.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM adr_codici_onu WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();
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
                        $this->updateCodice($id, $values);
                        redirect_with('adr/codici/edit?id=' . $id, 'success', 'Codice ONU aggiornato.');
                    } catch (PDOException $e) {
                        $errors['_global'] = $this->dbMessage($e);
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
            'lookup'    => $this->loadLookups(),
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CODICI ONU — eliminazione
     * ════════════════════════════════════════════════════════════════════════ */

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
            $this->pdo->prepare('DELETE FROM adr_codici_onu WHERE id = :id')->execute([':id' => $id]);
            redirect_with('adr?tab=codici', 'success', 'Codice ONU eliminato.');
        } catch (PDOException $e) {
            redirect_with('adr?tab=codici', 'danger', $this->dbMessage($e));
        }
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  HELPERS PRIVATI
     * ════════════════════════════════════════════════════════════════════════ */

    private function loadCodiciOnu(?string $search): array
    {
        $search = $search ?? '';
        $where  = '1=1';
        $params = [];
        if ($search !== '') {
            $where    = '(CAST(a.codice_onu AS TEXT) LIKE :q OR a.nome_descrizione LIKE :q OR cl.denominazione LIKE :q)';
            $params   = [':q' => '%' . $search . '%'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.codice_onu, a.nome_descrizione, a.classe_pericolo, a.codice_classificazione,
                    a.gruppo_imballaggio, a.categoria_trasporto,
                    a.codice_restrizione_gallerie, a.quantita_limitata,
                    a.codice_quantita_esente,
                    a.e_merce_alto_rischio, a.e_inquinante_marino,
                    a.inizio_validita, a.fine_validita,
                    cl.denominazione AS classe_label
               FROM adr_codici_onu a
          LEFT JOIN adr_classi_pericolo cl ON cl.codice = a.classe_pericolo
              WHERE $where
           ORDER BY a.codice_onu, a.classe_pericolo"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function loadLookups(): array
    {
        return [
            'classi'       => $this->pdo->query(
                'SELECT codice, denominazione FROM adr_classi_pericolo ORDER BY CAST(codice AS REAL)'
            )->fetchAll(),
            'classificazioni' => $this->pdo->query(
                'SELECT codice, descrizione, codice_classe FROM adr_codici_classificazione ORDER BY codice'
            )->fetchAll(),
            'imballaggi'   => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_gruppi_imballaggio ORDER BY codice'
            )->fetchAll(),
            'categorie'    => $this->pdo->query(
                'SELECT categoria, descrizione, soglia_esenzione_kg_l FROM adr_categorie_trasporto ORDER BY categoria'
            )->fetchAll(),
            'gallerie'     => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_codici_restrizione_gallerie ORDER BY codice'
            )->fetchAll(),
            'quantita_esenti' => $this->pdo->query(
                'SELECT codice, descrizione FROM adr_quantita_esenti ORDER BY codice'
            )->fetchAll(),
            'unita_misura' => $this->pdo->query(
                'SELECT id, codice, descrizione FROM unita_misura ORDER BY descrizione, codice'
            )->fetchAll(),
        ];
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

    private function codiceParams(array $v): array
    {
        $n = fn($val): mixed => (trim((string) $val) === '') ? null : $val;
        return [
            ':codice_onu'                  => (int) $v['codice_onu'],
            ':nome_descrizione'            => $n($v['nome_descrizione']),
            ':classe_pericolo'             => $n($v['classe_pericolo']),
            ':codice_classificazione'      => $n($v['codice_classificazione']),
            ':gruppo_imballaggio'          => $n($v['gruppo_imballaggio']),
            ':categoria_trasporto'         => $n($v['categoria_trasporto']) !== null ? (int) $n($v['categoria_trasporto']) : null,
            ':codice_restrizione_gallerie' => $n($v['codice_restrizione_gallerie']),
            ':quantita_limitata'           => $n($v['quantita_limitata']) !== null ? (int) $n($v['quantita_limitata']) : null,
            ':codice_quantita_esente'      => $n($v['codice_quantita_esente']),
            ':e_merce_alto_rischio'        => ($v['e_merce_alto_rischio'] === '1') ? 1 : null,
            ':e_inquinante_marino'         => ($v['e_inquinante_marino'] === '1') ? 1 : null,
            ':inizio_validita'             => $n($v['inizio_validita']),
            ':fine_validita'               => $n($v['fine_validita']),
        ];
    }

    private function insertCodice(array $v): void
    {
        $this->pdo->prepare(
            'INSERT INTO adr_codici_onu
                (codice_onu, nome_descrizione, classe_pericolo, codice_classificazione,
                 gruppo_imballaggio, categoria_trasporto, codice_restrizione_gallerie,
                 quantita_limitata, codice_quantita_esente,
                 e_merce_alto_rischio, e_inquinante_marino,
                 inizio_validita, fine_validita)
             VALUES
                (:codice_onu, :nome_descrizione, :classe_pericolo, :codice_classificazione,
                 :gruppo_imballaggio, :categoria_trasporto, :codice_restrizione_gallerie,
                 :quantita_limitata, :codice_quantita_esente,
                 :e_merce_alto_rischio, :e_inquinante_marino,
                 :inizio_validita, :fine_validita)'
        )->execute($this->codiceParams($v));
    }

    private function updateCodice(int $id, array $v): void
    {
        $p        = $this->codiceParams($v);
        $p[':id'] = $id;
        $this->pdo->prepare(
            'UPDATE adr_codici_onu SET
                codice_onu=:codice_onu,
                nome_descrizione=:nome_descrizione,
                classe_pericolo=:classe_pericolo,
                codice_classificazione=:codice_classificazione,
                gruppo_imballaggio=:gruppo_imballaggio,
                categoria_trasporto=:categoria_trasporto,
                codice_restrizione_gallerie=:codice_restrizione_gallerie,
                quantita_limitata=:quantita_limitata,
                codice_quantita_esente=:codice_quantita_esente,
                e_merce_alto_rischio=:e_merce_alto_rischio,
                e_inquinante_marino=:e_inquinante_marino,
                inizio_validita=:inizio_validita,
                fine_validita=:fine_validita
             WHERE id=:id'
        )->execute($p);
    }

    /** Gestisce POST inline per le tre lookup table; restituisce [ok, errors, tab]. */
    private function handleLookupPost(string $currentTab): array
    {
        $action = post('action');
        $ok     = null;
        $errors = [];
        $tab    = $currentTab;

        try {
            switch ($action) {

                /* ── Classi pericolo ──────────────────────────────────── */
                case 'save_classe':
                    $tab    = 'classi';
                    $codice = trim(post('codice'));
                    $denom  = trim(post('denominazione'));
                    $div    = isset($_POST['ha_divisioni']) ? 1 : 0;
                    if ($codice === '' || $denom === '') {
                        $errors['classe'] = 'Codice e denominazione sono obbligatori.';
                        break;
                    }
                    $ex = $this->pdo->prepare('SELECT 1 FROM adr_classi_pericolo WHERE codice=:c');
                    $ex->execute([':c' => $codice]);
                    if ($ex->fetchColumn()) {
                        $this->pdo->prepare(
                            'UPDATE adr_classi_pericolo
                                SET denominazione=:d, ha_divisioni=:hd
                              WHERE codice=:c'
                        )->execute([':c'=>$codice,':d'=>$denom,':hd'=>$div]);
                        $ok = "Classe $codice aggiornata.";
                    } else {
                        $this->pdo->prepare(
                            'INSERT INTO adr_classi_pericolo (codice, denominazione, ha_divisioni)
                             VALUES (:c, :d, :hd)'
                        )->execute([':c'=>$codice,':d'=>$denom,':hd'=>$div]);
                        $ok = "Classe $codice aggiunta.";
                    }
                    break;

                case 'delete_classe':
                    $tab    = 'classi';
                    $codice = trim(post('codice'));
                    $this->pdo->prepare('DELETE FROM adr_classi_pericolo WHERE codice=:c')
                        ->execute([':c' => $codice]);
                    $ok = "Classe $codice eliminata.";
                    break;

                /* ── Gruppi imballaggio ───────────────────────────────── */
                case 'save_imb':
                    $tab    = 'imballaggio';
                    $codice = trim(post('codice'));
                    $desc   = trim(post('descrizione'));
                    if ($codice === '' || $desc === '') {
                        $errors['imb'] = 'Codice e descrizione sono obbligatori.';
                        break;
                    }
                    $ex = $this->pdo->prepare('SELECT 1 FROM adr_gruppi_imballaggio WHERE codice=:c');
                    $ex->execute([':c' => $codice]);
                    if ($ex->fetchColumn()) {
                        $this->pdo->prepare('UPDATE adr_gruppi_imballaggio SET descrizione=:d WHERE codice=:c')
                            ->execute([':c'=>$codice,':d'=>$desc]);
                        $ok = "Gruppo $codice aggiornato.";
                    } else {
                        $this->pdo->prepare('INSERT INTO adr_gruppi_imballaggio (codice,descrizione) VALUES (:c,:d)')
                            ->execute([':c'=>$codice,':d'=>$desc]);
                        $ok = "Gruppo $codice aggiunto.";
                    }
                    break;

                case 'delete_imb':
                    $tab    = 'imballaggio';
                    $codice = trim(post('codice'));
                    $this->pdo->prepare('DELETE FROM adr_gruppi_imballaggio WHERE codice=:c')
                        ->execute([':c' => $codice]);
                    $ok = "Gruppo $codice eliminato.";
                    break;

                /* ── Codici galleria ──────────────────────────────────── */
                case 'save_galleria':
                    $tab      = 'gallerie';
                    $codice   = trim(post('codice'));
                    $desc     = trim(post('descrizione'));
                    $cisterna = trim(post('si_applica_a_cisterna'));
                    if ($codice === '') {
                        $errors['galleria'] = 'Il codice è obbligatorio.';
                        break;
                    }
                    $ex = $this->pdo->prepare('SELECT 1 FROM adr_codici_restrizione_gallerie WHERE codice=:c');
                    $ex->execute([':c' => $codice]);
                    if ($ex->fetchColumn()) {
                        $this->pdo->prepare(
                            'UPDATE adr_codici_restrizione_gallerie
                                SET descrizione=:d, si_applica_a_cisterna=:cs
                              WHERE codice=:c'
                        )->execute([':c'=>$codice,':d'=>$desc,':cs'=>($cisterna===''?null:$cisterna)]);
                        $ok = "Codice galleria «$codice» aggiornato.";
                    } else {
                        $this->pdo->prepare(
                            'INSERT INTO adr_codici_restrizione_gallerie (codice,si_applica_a_cisterna,descrizione)
                             VALUES (:c,:cs,:d)'
                        )->execute([':c'=>$codice,':cs'=>($cisterna===''?null:$cisterna),':d'=>$desc]);
                        $ok = "Codice galleria «$codice» aggiunto.";
                    }
                    break;

                case 'delete_galleria':
                    $tab    = 'gallerie';
                    $codice = trim(post('codice'));
                    // ON DELETE CASCADE rimuove anche le categorie vietate
                    $this->pdo->prepare('DELETE FROM adr_codici_restrizione_gallerie WHERE codice=:c')
                        ->execute([':c' => $codice]);
                    $ok = "Codice galleria «$codice» eliminato.";
                    break;

                /* ── Categorie di trasporto ───────────────────────────── */
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
                    $ex = $this->pdo->prepare('SELECT 1 FROM adr_categorie_trasporto WHERE categoria=:c');
                    $ex->execute([':c' => (int)$cat]);
                    if ($ex->fetchColumn()) {
                        $this->pdo->prepare(
                            'UPDATE adr_categorie_trasporto
                                SET moltiplicatore_punti=:m, soglia_esenzione_kg_l=:s, descrizione=:d
                              WHERE categoria=:c'
                        )->execute([
                            ':c' => (int)$cat,
                            ':m' => ($molt !== '' ? (int)$molt : 0),
                            ':s' => ($soglia !== '' ? (int)$soglia : null),
                            ':d' => ($desc !== '' ? $desc : null),
                        ]);
                        $ok = "Categoria $cat aggiornata.";
                    } else {
                        $this->pdo->prepare(
                            'INSERT INTO adr_categorie_trasporto (categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione)
                             VALUES (:c, :m, :s, :d)'
                        )->execute([
                            ':c' => (int)$cat,
                            ':m' => ($molt !== '' ? (int)$molt : 0),
                            ':s' => ($soglia !== '' ? (int)$soglia : null),
                            ':d' => ($desc !== '' ? $desc : null),
                        ]);
                        $ok = "Categoria $cat aggiunta.";
                    }
                    break;

                case 'delete_categoria':
                    $tab = 'categorie';
                    $cat = (int) post('categoria');
                    $this->pdo->prepare('DELETE FROM adr_categorie_trasporto WHERE categoria=:c')
                        ->execute([':c' => $cat]);
                    $ok = "Categoria $cat eliminata.";
                    break;

                /* ── Codici classificazione ───────────────────────────── */
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
                    $ex = $this->pdo->prepare('SELECT 1 FROM adr_codici_classificazione WHERE codice=:c AND codice_classe=:cl');
                    $ex->execute([':c' => $codice, ':cl' => $classe]);
                    if ($ex->fetchColumn()) {
                        $this->pdo->prepare(
                            'UPDATE adr_codici_classificazione
                                SET descrizione=:d, pericolo_primario=:pp, pericolo_secondario=:ps
                              WHERE codice=:c AND codice_classe=:cl'
                        )->execute([
                            ':c'  => $codice,
                            ':cl' => $classe,
                            ':d'  => ($desc !== '' ? $desc : null),
                            ':pp' => ($prim !== '' ? $prim : null),
                            ':ps' => ($sec  !== '' ? $sec  : null),
                        ]);
                        $ok = "Codice classificazione «$codice» (Classe $classe) aggiornato.";
                    } else {
                        $this->pdo->prepare(
                            'INSERT INTO adr_codici_classificazione (codice, codice_classe, descrizione, pericolo_primario, pericolo_secondario)
                             VALUES (:c, :cl, :d, :pp, :ps)'
                        )->execute([
                            ':c'  => $codice,
                            ':cl' => $classe,
                            ':d'  => ($desc !== '' ? $desc : null),
                            ':pp' => ($prim !== '' ? $prim : null),
                            ':ps' => ($sec  !== '' ? $sec  : null),
                        ]);
                        $ok = "Codice classificazione «$codice» (Classe $classe) aggiunto.";
                    }
                    break;

                case 'delete_classificazione':
                    $tab    = 'classificazioni';
                    $codice = trim(post('codice'));
                    $classe = trim(post('codice_classe'));
                    $this->pdo->prepare('DELETE FROM adr_codici_classificazione WHERE codice=:c AND codice_classe=:cl')
                        ->execute([':c' => $codice, ':cl' => $classe]);
                    $ok = "Codice classificazione «$codice» (Classe $classe) eliminato.";
                    break;
            }
        } catch (PDOException $e) {
            $errors['_global'] = $this->dbMessage($e);
        }

        return [$ok, $errors, $tab];
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  LOOKUP — endpoint generico ricerca tabelle ADR (JSON GET)
     *  GET /adr/api/lookup?type=classi&q=...
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiLookup(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        /** Tabelle autorizzate → [table, id_col, label_col, sub_col, order_expr] */
        $map = [
            'classi'          => ['adr_classi_pericolo',              'codice',    'codice',    'denominazione',       'CAST(codice AS REAL)'],
            'classificazioni' => ['adr_codici_classificazione',       'codice',    'codice',    'descrizione',         'codice_classe, codice'],
            'imballaggi'      => ['adr_gruppi_imballaggio',           'codice',    'codice',    'descrizione',         'codice'],
            'categorie'       => ['adr_categorie_trasporto',          'categoria', 'categoria', 'descrizione',         'categoria'],
            'gallerie'        => ['adr_codici_restrizione_gallerie',  'codice',    'codice',    'descrizione',         'codice'],
            'quantita_esenti' => ['adr_quantita_esenti',              'codice',    'codice',    'descrizione',         'codice'],
            'unita_misura'    => ['unita_misura',                     'id',        'codice',    'descrizione',         'descrizione, codice'],
        ];

        $type = get_param('type', '');
        if (!isset($map[$type])) {
            echo json_encode([]);
            exit;
        }

        [$table, $idCol, $labelCol, $subCol, $order] = $map[$type];
        $q = trim(get_param('q', ''));

        $where  = '';
        $params = [];
        if ($q !== '') {
            $where    = "WHERE CAST($idCol AS TEXT) LIKE :q OR $labelCol LIKE :q"
                      . ($subCol ? " OR $subCol LIKE :q" : '');
            $params   = [':q' => '%' . $q . '%'];
        }

        $sql  = "SELECT $idCol AS id, $labelCol AS label"
              . ($subCol ? ", $subCol AS sub" : '')
              . " FROM $table $where ORDER BY $order LIMIT 200";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CODICI ONU — importazione massiva (JSON API, POST)
     * ════════════════════════════════════════════════════════════════════════ */

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

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $onu = trim((string)($row['codice_onu'] ?? ''));
                if ($onu === '' || !ctype_digit($onu) || (int)$onu <= 0) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': numero ONU non valido ("' . htmlspecialchars($onu) . '") — saltata.';
                    continue;
                }
                $bool = fn(string $k): string => ((string)($row[$k] ?? '') === '1') ? '1' : '0';
                $this->insertCodice([
                    'codice_onu'                      => $onu,
                    'nome_descrizione'                => $row['nome_descrizione'] ?? '',
                    'classe_pericolo'                 => $row['classe_pericolo'] ?? '',
                    'codice_classificazione'          => $row['codice_classificazione'] ?? '',
                    'gruppo_imballaggio'              => $row['gruppo_imballaggio'] ?? '',
                    'categoria_trasporto'             => $row['categoria_trasporto'] ?? '',
                    'codice_restrizione_gallerie'     => $row['codice_restrizione_gallerie'] ?? '',
                    'quantita_limitata'               => $row['quantita_limitata'] ?? '',
                    'codice_quantita_esente'          => $row['codice_quantita_esente'] ?? '',
                    'id_unita_misura_qta_limitata'                 => $row['id_unita_misura_qta_limitata'] ?? '',
                    'e_merce_alto_rischio'            => $bool('e_merce_alto_rischio'),
                    'e_inquinante_marino'             => $bool('e_inquinante_marino'),
                    'e_pericoloso_ambiente_acquatico' => $bool('e_pericoloso_ambiente_acquatico'),
                    'inizio_validita'                 => $row['inizio_validita'] ?? '',
                    'fine_validita'                   => $row['fine_validita'] ?? '',
                ]);
                $inserted++;
            }
            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'errors'   => $rowErrors,
            ]);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $this->dbMessage($e)]);
        }
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CODICI CLASSIFICAZIONE — importazione massiva (JSON API, POST)
     * ════════════════════════════════════════════════════════════════════════ */

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
                $this->insertClassificazione([
                    'codice'              => $codice,
                    'codice_classe'       => $classe,
                    'descrizione'         => $row['descrizione'] ?? '',
                    'pericolo_primario'  => $row['pericolo_primario'] ?? '',
                    'pericolo_secondario'=> $row['pericolo_secondario'] ?? '',
                ]);
                $inserted++;
            }
            $this->pdo->commit();
            echo json_encode([
                'ok'       => true,
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'errors'   => $rowErrors,
            ]);
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $this->dbMessage($e)]);
        }
        exit;
    }

    private function insertClassificazione(array $v): void
    {
        $n = fn($val): mixed => (trim((string) $val) === '') ? null : $val;
        $this->pdo->prepare(
            'INSERT INTO adr_codici_classificazione
                (codice, codice_classe, descrizione, pericolo_primario, pericolo_secondario)
             VALUES
                (:codice, :codice_classe, :descrizione, :pericolo_primario, :pericolo_secondario)'
        )->execute([
            ':codice'             => trim($v['codice']),
            ':codice_classe'      => $n($v['codice_classe']),
            ':descrizione'        => $n($v['descrizione']),
            ':pericolo_primario'  => $n($v['pericolo_primario']),
            ':pericolo_secondario'=> $n($v['pericolo_secondario']),
        ]);
    }

    private function dbMessage(PDOException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'FOREIGN KEY')) {
            return 'Impossibile eliminare: il record è referenziato da altri dati.';
        }
        if (str_contains($msg, 'UNIQUE')) {
            return 'Esiste già un record con questo codice.';
        }
        return APP_ENV === 'development' ? 'Errore DB: ' . $msg : 'Errore nel salvataggio. Riprova.';
    }
}
