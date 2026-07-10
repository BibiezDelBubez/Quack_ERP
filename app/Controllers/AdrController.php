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
        $search    = get_param('q');
        $page      = max(1, (int) get_param('page', '1'));
        $per_page  = 100;

        [$codici, $codici_total, $codici_pages] = $this->loadCodiciOnu($search, $page, $per_page);
        $classi     = $this->pdo->query(
            'SELECT codice, denominazione, ha_divisioni
               FROM adr_classi_pericolo
              ORDER BY CAST(codice AS REAL)'
        )->fetchAll();
        $imballaggi = $this->pdo->query(
            'SELECT codice, descrizione FROM adr_gruppi_imballaggio ORDER BY codice'
        )->fetchAll();
        $categorieConcat = db_group_concat_sql('c.categoria_galleria', ', ');
        $gallerie   = $this->pdo->query(
            "SELECT g.codice, g.si_applica_a_cisterna, g.descrizione,
                    {$categorieConcat} AS categorie
               FROM adr_codici_restrizione_gallerie g
          LEFT JOIN adr_restrizione_gallerie_categorie c ON c.restrizione_codice = g.codice
           GROUP BY g.codice
           ORDER BY g.codice"
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
                    } catch (\Throwable $e) {
                        // \Throwable e non solo PDOException: insertCodice() ora può
                        // propagare anche errori non-SQL (vedi il suo catch interno) —
                        // vogliamo comunque mostrare un errore leggibile invece di un
                        // fatal error non gestito.
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
        $stmtLabels = $this->pdo->prepare('SELECT etichetta_codice FROM adr_codici_onu_etichette WHERE codice_onu_id = ? ORDER BY ordine ASC');
        $stmtLabels->execute([$id]);
        $record['etichette_str'] = implode(', ', $stmtLabels->fetchAll(PDO::FETCH_COLUMN));

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
                    } catch (\Throwable $e) {
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
            $this->pdo->beginTransaction();
            // Elimina prima le etichette associate (emula ON DELETE CASCADE)
            $this->pdo->prepare('DELETE FROM adr_codici_onu_etichette WHERE codice_onu_id = :id')->execute([':id' => $id]);
            // Poi elimina il codice ONU
            $this->pdo->prepare('DELETE FROM adr_codici_onu WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
            redirect_with('adr?tab=codici', 'success', 'Codice ONU eliminato.');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            redirect_with('adr?tab=codici', 'danger', $this->dbMessage($e));
        }
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  HELPERS PRIVATI
     * ════════════════════════════════════════════════════════════════════════ */

    private function loadCodiciOnu(?string $search, int $page = 1, int $perPage = 100): array
    {
        $search = $search ?? '';
        $where  = '1=1';
        $params = [];
        if ($search !== '') {
            $where  = '(CAST(a.codice_onu AS TEXT) LIKE :q OR a.nome_descrizione LIKE :q OR cl.denominazione LIKE :q)';
            $params = [':q' => '%' . $search . '%'];
        }

        // Totale
        $stmtCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM adr_codici_onu a
             LEFT JOIN adr_classi_pericolo cl ON cl.codice = a.classe_pericolo
             WHERE $where"
        );
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page  = max(1, min($page, $pages));

        $offset = ($page - 1) * $perPage;
        $params[':limit']  = $perPage;
        $params[':offset'] = $offset;

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.codice_onu, a.nome_descrizione, a.classe_pericolo, a.codice_classificazione,
                    a.gruppo_imballaggio, a.categoria_trasporto,
                    a.codice_restrizione_gallerie, a.quantita_limitata,
                    a.codice_quantita_esente,
                    a.e_merce_alto_rischio, a.e_inquinante_marino,
                    a.inizio_validita, a.fine_validita,
                    cl.denominazione AS classe_label,
                    (SELECT GROUP_CONCAT(etichetta_codice) 
                     FROM adr_codici_onu_etichette 
                     WHERE codice_onu_id = a.id) AS etichette_str
               FROM adr_codici_onu a
          LEFT JOIN adr_classi_pericolo cl ON cl.codice = a.classe_pericolo
              WHERE $where
           ORDER BY a.codice_onu, a.classe_pericolo
           LIMIT :limit OFFSET :offset"
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), $total, $pages];
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

    private function insertCodice(array $v): int
    {
        // Transazione unica: INSERT del codice ONU + saveEtichette() devono
        // essere atomici. Prima erano due autocommit separati e saveEtichette()
        // ingoiava silenziosamente ogni errore sulle etichette (vedi audit
        // 10/07/2026): un'etichetta di pericolo scritta male spariva senza
        // nessun avviso, con "salvato correttamente" mostrato comunque —
        // rischioso su un dato di compliance ADR. Ora, se una singola etichetta
        // fallisce (FK verso adr_etichette inesistente), l'intero salvataggio
        // va in rollback e l'errore risale a create()/edit() via dbMessage().
        //
        // PDO non supporta transazioni annidate: importCodici()/importClassificazioni()
        // chiamano già insertCodice() dentro una LORO transazione aperta sull'intero
        // batch (beginTransaction() qui sopra fallirebbe con "there is already an
        // active transaction"). Apriamo/chiudiamo la transazione solo se non ce n'è
        // già una in corso — nel caso annidato ci limitiamo a propagare l'eccezione
        // e lasciamo che sia il chiamante a fare il rollback dell'intero batch.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
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
            $id = (int) $this->pdo->lastInsertId();
            $this->saveEtichette($id, $v['etichette_str'] ?? '');
            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $e) {
            // \Throwable e non solo PDOException: un errore non-SQL (es. un
            // TypeError su un parametro malformato, plausibile con
            // strict_types=1) lasciava altrimenti la transazione aperta senza
            // rollback, perché non veniva intercettato dal catch.
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Sostituisce le etichette di pericolo associate a un codice ONU.
     * Deve sempre girare dentro la transazione aperta da insertCodice()/
     * updateCodice(): nessun try/catch locale che ingoi errori — un
     * fallimento (duplicato, o codice etichetta inesistente in adr_etichette)
     * deve propagarsi e far fallire l'intero salvataggio, non sparire in
     * silenzio lasciando l'utente convinto che sia andato tutto bene.
     */
    private function saveEtichette(int $id, string $etichetteStr): void
    {
        $this->pdo->prepare('DELETE FROM adr_codici_onu_etichette WHERE codice_onu_id = ?')->execute([$id]);

        // trim + scarta solo le stringhe vuote (non "0", improbabile per un
        // codice etichetta ma comunque non va perso da un array_filter senza
        // callback esplicito) + dedup case-insensitive: "8a" e "8A" sono la
        // stessa etichetta per l'operatore, ma NON forziamo una maiuscola
        // arbitraria in scrittura — non sappiamo con certezza con che
        // grafia siano salvati i codici in adr_etichette (nessun seed/CRUD
        // nel codice per verificarlo). Dedup sul confronto case-insensitive
        // mantenendo la prima grafia incontrata per ciascun gruppo.
        $raw = array_values(array_filter(
            array_map('trim', explode(',', $etichetteStr)),
            static fn(string $v): bool => $v !== ''
        ));
        $etichette = [];
        $visti = [];
        foreach ($raw as $v) {
            $chiave = mb_strtolower($v);
            if (isset($visti[$chiave])) {
                continue;
            }
            $visti[$chiave] = true;
            $etichette[] = $v;
        }

        if (empty($etichette)) {
            return;
        }

        // Per ogni etichetta deduplicata, risolviamo la grafia ESATTA già
        // presente in adr_etichette con un confronto case-insensitive
        // (LOWER(), portabile SQLite/MySQL) e usiamo quella per l'INSERT —
        // così il risultato rispetta sempre la FK indipendentemente da come
        // l'operatore l'ha digitata. Se non troviamo alcuna corrispondenza,
        // lasciamo il valore originale: l'INSERT fallirà sulla FK esattamente
        // come prima (nessun cambiamento di comportamento per codici davvero
        // inesistenti).
        $stFind = $this->pdo->prepare('SELECT codice FROM adr_etichette WHERE LOWER(codice) = LOWER(?)');
        $stIns = $this->pdo->prepare(
            'INSERT INTO adr_codici_onu_etichette (codice_onu_id, etichetta_codice, tipo, ordine) VALUES (?, ?, ?, ?)'
        );
        foreach ($etichette as $idx => $et) {
            $stFind->execute([$et]);
            $resolved = $stFind->fetchColumn();
            $etReale = $resolved !== false ? $resolved : $et;
            $stIns->execute([$id, $etReale, $idx === 0 ? 'primaria' : 'sussidiaria', $idx + 1]);
        }
    }

    private function updateCodice(int $id, array $v): void
    {
        // Stessa ragione della transazione unica in insertCodice(): saveEtichette()
        // qui CANCELLA prima tutte le etichette esistenti, quindi un fallimento
        // a metà del re-insert senza transazione lascerebbe il record con meno
        // etichette di quelle salvate in precedenza, senza nessun errore visibile.
        // Stesso guard anti-nidificazione di insertCodice() (vedi commento lì).
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
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
            $this->saveEtichette($id, $v['etichette_str'] ?? '');
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            // \Throwable, stessa ragione di insertCodice(): un errore non-SQL
            // non deve lasciare la transazione aperta senza rollback.
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
                    try {
                        $this->pdo->prepare('DELETE FROM adr_classi_pericolo WHERE codice=:c')
                            ->execute([':c' => $codice]);
                        $ok = "Classe $codice eliminata.";
                    } catch (PDOException $e) {
                        if ((string)$e->getCode() === '23000') {
                            $errors['classe'] = "Impossibile eliminare la classe $codice: è attualmente in uso (es. nei Codici ONU).";
                        } else {
                            $errors['classe'] = "Errore durante l'eliminazione: " . $e->getMessage();
                        }
                    }
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
            'codici_onu'      => ['adr_codici_onu',                   'id',        'codice_onu', 'nome_descrizione',    'codice_onu'],
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
            if (mb_strlen($q) === 1) {
                // Ricerca ottimizzata per caratteri singoli (codice esatto o inizia con per la descrizione)
                $where  = "WHERE CAST($idCol AS TEXT) = :q OR $labelCol LIKE :q_start"
                        . ($subCol ? " OR $subCol LIKE :q_start" : '');
                $params = [':q' => $q, ':q_start' => $q . '%'];
            } else {
                // Ricerca parziale standard (contiene) per query più lunghe
                $where    = "WHERE CAST($idCol AS TEXT) LIKE :q OR $labelCol LIKE :q"
                          . ($subCol ? " OR $subCol LIKE :q" : '');
                $params   = [':q' => '%' . $q . '%'];
            }
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

        // Pre-carica tabelle di lookup per validazione
        $classi = array_column($this->pdo->query('SELECT codice FROM adr_classi_pericolo')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $gruppi = array_column($this->pdo->query('SELECT codice FROM adr_gruppi_imballaggio')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $catTrasporto = array_column($this->pdo->query('SELECT categoria FROM adr_categorie_trasporto')->fetchAll(PDO::FETCH_ASSOC), 'categoria');
        $gallerie = array_column($this->pdo->query('SELECT codice FROM adr_codici_restrizione_gallerie')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        $qtaEsente = array_column($this->pdo->query('SELECT codice FROM adr_quantita_esenti')->fetchAll(PDO::FETCH_ASSOC), 'codice');
        
        $classificazioni = [];
        foreach ($this->pdo->query('SELECT codice, codice_classe FROM adr_codici_classificazione')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $classificazioni[strtolower($r['codice']) . '|' . strtolower($r['codice_classe'])] = true;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $onu = trim((string)($row['codice_onu'] ?? ''));
                if ($onu === '' || !ctype_digit($onu) || (int)$onu <= 0) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . ($i + 1) . ': numero ONU non valido ("' . htmlspecialchars($onu) . '") — saltata.';
                    continue;
                }

                // Validazione relazioni/foreign keys
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

                // SAVEPOINT per riga: se insertCodice() fallisce su QUESTA riga
                // (es. codice_onu duplicato — non pre-validato sopra come le FK)
                // vogliamo scartare solo questa riga, non l'intero batch già
                // importato correttamente fin qui. insertCodice() gira con
                // $ownTransaction=false (siamo già dentro la transazione aperta
                // sotto) quindi non tenta un rollback proprio: propaga soltanto
                // l'eccezione, che qui catturiamo per-riga.
                $bool = fn(string $k): string => ((string)($row[$k] ?? '') === '1') ? '1' : '0';
                $this->pdo->exec('SAVEPOINT sp_import_row');
                try {
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
            // \Throwable e non solo PDOException: qualcosa fuori dal blocco
            // per-riga (es. le query di pre-caricamento lookup sopra, o un
            // errore non-SQL) non deve lasciare la transazione di batch
            // aperta senza rollback.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e instanceof PDOException ? $this->dbMessage($e) : $e->getMessage()]);
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
                // SAVEPOINT per riga: stessa ragione di importCodici() — un
                // fallimento su questa riga (es. UNIQUE su codice+codice_classe)
                // non deve scartare le righe già importate correttamente prima.
                $this->pdo->exec('SAVEPOINT sp_import_row');
                try {
                    $this->insertClassificazione([
                        'codice'              => $codice,
                        'codice_classe'       => $classe,
                        'descrizione'         => $row['descrizione'] ?? '',
                        'pericolo_primario'  => $row['pericolo_primario'] ?? '',
                        'pericolo_secondario'=> $row['pericolo_secondario'] ?? '',
                    ]);
                    $this->pdo->exec('RELEASE SAVEPOINT sp_import_row');
                    $inserted++;
                } catch (\Throwable $rowEx) {
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

    /* ════════════════════════════════════════════════════════════════════════
     *  ESENZIONE — calcolatore 1000 punti (ADR 1.1.3.6)
     * ════════════════════════════════════════════════════════════════════════ */

    public function esenzione(): void
    {
        // Categorie di trasporto con moltiplicatore
        $categorie = $this->pdo->query(
            'SELECT categoria, moltiplicatore_punti, soglia_esenzione_kg_l, descrizione
               FROM adr_categorie_trasporto
              ORDER BY categoria'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Sostanze ADR con i dati necessari al calcolo
        $sostanze = $this->pdo->query(
            "SELECT s.id, s.nome, s.codice_cas, s.gruppo_imballaggio,
                    c.codice_onu, c.classe_pericolo, c.categoria_trasporto,
                    c.quantita_limitata, c.codice_quantita_esente,
                    ct.moltiplicatore_punti, ct.soglia_esenzione_kg_l
               FROM sostanze_chimiche s
          LEFT JOIN adr_codici_onu c  ON c.id = s.codice_onu_id
          LEFT JOIN adr_categorie_trasporto ct ON ct.categoria = c.categoria_trasporto
              ORDER BY s.nome COLLATE NOCASE"
        )->fetchAll(PDO::FETCH_ASSOC);

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

    /* ════════════════════════════════════════════════════════════════════════
     *  API: ricerca prodotti ADR per il calcolatore esenzione
     * ════════════════════════════════════════════════════════════════════════ */

    public function apiProdottiAdr(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q     = trim(get_param('q', ''));
        $limit = min(30, max(1, (int) get_param('limit', '20')));

        $sql = "SELECT p.id, p.nome, p.sku_interno,
                       s.id AS sostanza_id, s.nome AS sostanza_nome, s.codice_cas,
                       s.gruppo_imballaggio,
                       c.codice_onu, c.classe_pericolo, c.categoria_trasporto,
                       ct.moltiplicatore_punti, ct.soglia_esenzione_kg_l
                  FROM prodotti p
                  JOIN sostanze_chimiche s        ON s.id = p.sostanza_chimica_id
             LEFT JOIN adr_codici_onu c          ON c.id = s.codice_onu_id
             LEFT JOIN adr_categorie_trasporto ct ON ct.categoria = c.categoria_trasporto
                 WHERE p.attivo = 1
                   AND COALESCE(p.is_adr, 0) = 1";
        $params = [];

        if ($q !== '') {
            $sql .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR s.nome LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $sql .= " ORDER BY p.nome COLLATE NOCASE LIMIT :lim";
        $params[':lim'] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
}
