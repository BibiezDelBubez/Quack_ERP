<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/prodotti.php';
require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/tabelle_base.php';

/**
 * ProductsController — /products, /products/create, /products/edit
 */
final class ProductsController extends BaseController
{
    public function index(): void
    {
        $search   = get_param('q');
        $category = get_param('cat');
        $adr_only = get_param('adr') === '1';
        $supplierRaw = get_param('fornitore');
        $supplier    = ($supplierRaw !== null && $supplierRaw !== '') ? (int) $supplierRaw : null;
        $pag    = qk_pagination_params(['nome','sku','categoria','giacenza','prezzo','tipo'], 'nome', 'asc');
        $limit  = $pag['limit'];
        $offset = $pag['offset'];
        $page   = $pag['page'];

        $list       = qk_prodotti_list($this->pdo, $search, $category, $adr_only, $supplier, $limit, $offset, $pag['sort'], $pag['dir']);
        $products   = $list['rows'];
        $total      = $list['total'];
        $pages      = (int) ceil($total / $limit);
        $categories = qk_load_categorie($this->pdo);
        $suppliers  = qk_load_fornitori_disponibili($this->pdo);
        $adr_sostanze = qk_load_adr_sostanze($this->pdo);

        $page_title    = 'Prodotti';
        $page_subtitle = 'Catalogo e giacenze magazzino';
        $breadcrumbs   = [['label' => 'Prodotti']];
        $page_actions  = '<a href="' . url('products/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo prodotto
        </a>';

        $this->page(APP_ROOT_DIR . '/app/views/products/_list.php', get_defined_vars());
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $mode      = 'create';
        $entity_id = null;
        $errors    = [];
        $values    = $this->defaultValues();
        $immagini  = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);
                $errors = $this->validate($values);

                if (empty($errors)) {
                    try {
                        $id = qk_insert_prodotto($this->pdo, $values);
                        if (!empty($_FILES['foto_principale']['name'])) {
                            qk_salva_immagine_prodotto($this->pdo, $id, $_FILES['foto_principale']);
                        }
                        redirect_with('products/edit?id=' . $id, 'success', 'Prodotto creato correttamente.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = qk_prodotti_db_message($e);
                    }
                }
            }
        }

        $categorie             = qk_load_categorie($this->pdo);
        $unita_misura_list     = qk_get_unita_misura_lista($this->pdo);
        $adr_sostanze          = qk_load_adr_sostanze($this->pdo);
        $fornitori_disponibili = qk_load_fornitori_disponibili($this->pdo);
        $prodotto_fornitori    = [];
        $tutti_prezzi          = []; // nessun prezzo su un prodotto nuovo
        $prezzi_vendita_regole = [];
        $all_tags              = qk_load_all_tags($this->pdo);
        $prodotto_tag_ids      = [];
        $kit_componenti        = [];
        $listini = qk_get_listini_vendita_attivi($this->pdo, false); // Include base listino for the Matrix
        $iso_lingue = qk_get_iso_lingue_lista($this->pdo); // tab "Scheda di sicurezza" (M5)

        $page_title    = 'Nuovo prodotto';
        $page_subtitle = 'Aggiungi un prodotto al catalogo';
        $breadcrumbs   = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => 'Nuovo'],
        ];
        $record_meta = null;
        $extra_css   = ['products-suppliers.css', 'searchable-select.css'];

        $this->page(APP_ROOT_DIR . '/app/views/products/_form.php', get_defined_vars());
    }

    /* ── Modifica ─────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $mode      = 'edit';
        $entity_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$entity_id) {
            redirect_with('products', 'danger', 'Prodotto non specificato.');
        }

        $loaded = qk_load_prodotto_form($this->pdo, $entity_id);

        if (!$loaded) {
            http_response_code(404);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        $record_meta = [
            'created_at' => $loaded['_created_at'] ?? '',
            'updated_at' => $loaded['_updated_at'] ?? '',
        ];
        unset($loaded['_created_at'], $loaded['_updated_at']);

        // Valore di sola lettura (aggregato calcolato, mai scrivibile dal form):
        // tenuto fuori da $values per non finire mai nel giro di lettura del POST.
        $giacenza_totale_display = (float) ($loaded['quantita_disponibile_totale'] ?? 0);
        unset($loaded['quantita_disponibile_totale']);

        $values = array_merge($this->defaultValues(), $loaded);
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues($values);
                $errors = $this->validate($values, $entity_id);

                // Blocca la disabilitazione di traccia_lotti se esistono lotti con giacenza > 0
                if (empty($errors)
                    && ($loaded['traccia_lotti'] ?? '0') === '1'
                    && $values['traccia_lotti'] === '0'
                ) {
                    // Giacenza lotto aggregata su TUTTI i magazzini (Piano Multi-Magazzino
                    // §3.9): lotti.quantita_disponibile è stata rimossa, la fonte di
                    // verità è lotti_giacenze. Il guard resta a livello prodotto (non ha
                    // senso permettere di disabilitare la tracciatura lotti se il prodotto
                    // ha ancora stock a lotto ovunque si trovi).
                    $stLotti = $this->pdo->prepare(
                        'SELECT COUNT(DISTINCT l.id) AS n, COALESCE(SUM(lg.quantita_disponibile), 0) AS tot
                           FROM lotti l
                           JOIN lotti_giacenze lg ON lg.lotto_id = l.id
                          WHERE l.prodotto_id = ? AND lg.quantita_disponibile > 0'
                    );
                    $stLotti->execute([$entity_id]);
                    $lottiAttivi = $stLotti->fetch(PDO::FETCH_ASSOC);

                    if ((int) $lottiAttivi['n'] > 0) {
                        $errors['traccia_lotti'] = sprintf(
                            'Impossibile disabilitare la tracciatura lotti: %d lott%s %s ancora giacenza disponibile (totale: %s). '
                            . 'Azzera le giacenze di tutti i lotti prima di disabilitare la tracciatura.',
                            (int) $lottiAttivi['n'],
                            (int) $lottiAttivi['n'] === 1 ? 'o ha' : 'i hanno',
                            (int) $lottiAttivi['n'] === 1 ? 'ha' : 'hanno',
                            number_format((float) $lottiAttivi['tot'], 3, ',', '.')
                        );
                    }
                }

                if (empty($errors)) {
                    try {
                        $ricalcoloBozze = qk_update_prodotto($this->pdo, $entity_id, $values);
                        if (!empty($_POST['rimuovi_foto'])) {
                            qk_rimuovi_immagine_prodotto($this->pdo, $entity_id);
                        } elseif (!empty($_FILES['foto_principale']['name'])) {
                            qk_salva_immagine_prodotto($this->pdo, $entity_id, $_FILES['foto_principale']);
                        }

                        // Attivazione traccia_lotti (0 -> 1) su un prodotto con giacenza già
                        // esistente: converte lo stock "orfano" in un lotto per magazzino,
                        // invece di lasciarlo invisibile a FEFO/selezione lotto da questo
                        // momento in poi (Revisione Gestione Magazzino e Lotti §4).
                        $lottiConversioneCreati = 0;
                        if (($loaded['traccia_lotti'] ?? '0') === '0' && $values['traccia_lotti'] === '1') {
                            $lottiConversioneCreati = qk_crea_lotti_conversione_iniziale(
                                $this->pdo,
                                $entity_id,
                                (int) (current_user()['id'] ?? 0)
                            );
                        }
                        // Sync tag
                        $raw_tags = $_POST['tag_ids'] ?? [];
                        $tag_ids  = array_map('intval', (array) $raw_tags);
                        qk_sync_prodotto_tags($this->pdo, $entity_id, $tag_ids);

                        // REVISIONE §6.3 Problema 2, punto 4: mostra il conteggio delle bozze
                        // ricalcolate solo se la Stock UoM è davvero cambiata in questo salvataggio.
                        $totaleRicalcolate = array_sum($ricalcoloBozze);
                        $msg = 'Prodotto aggiornato.';
                        if ($totaleRicalcolate > 0) {
                            $parti = [];
                            if ($ricalcoloBozze['preventivi'] > 0) {
                                $parti[] = $ricalcoloBozze['preventivi'] . ' preventiv' . ($ricalcoloBozze['preventivi'] === 1 ? 'o' : 'i') . ' in bozza';
                            }
                            if ($ricalcoloBozze['rfq'] > 0) {
                                $parti[] = $ricalcoloBozze['rfq'] . ' RFQ in bozza';
                            }
                            if ($ricalcoloBozze['ordini_acquisto'] > 0) {
                                $parti[] = $ricalcoloBozze['ordini_acquisto'] . ' ordin' . ($ricalcoloBozze['ordini_acquisto'] === 1 ? 'e' : 'i') . " d'acquisto in bozza";
                            }
                            if ($ricalcoloBozze['prezzi_vendita'] > 0 || $ricalcoloBozze['prezzi_acquisto'] > 0) {
                                $parti[] = ($ricalcoloBozze['prezzi_vendita'] + $ricalcoloBozze['prezzi_acquisto']) . ' soglie di prezzo';
                            }
                            if (!empty($parti)) {
                                $msg .= ' Ricalcolati per il cambio di unità di stock: ' . implode(', ', $parti) . '.';
                            }
                        }
                        if ($lottiConversioneCreati > 0) {
                            $msg .= sprintf(
                                ' Creat%s %d lott%s di conversione per la giacenza già presente (uno per magazzino con stock).',
                                $lottiConversioneCreati === 1 ? 'o' : 'i',
                                $lottiConversioneCreati,
                                $lottiConversioneCreati === 1 ? 'o' : 'i'
                            );
                        }

                        redirect_with('products/edit?id=' . $entity_id, 'success', $msg);
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = qk_prodotti_db_message($e);
                    }
                }
            }
        }

        $immagini              = qk_load_prodotto_immagini($this->pdo, $entity_id);
        $categorie             = qk_load_categorie($this->pdo);
        $unita_misura_list     = qk_get_unita_misura_lista($this->pdo);
        $adr_sostanze          = qk_load_adr_sostanze($this->pdo);
        $fornitori_disponibili = qk_load_fornitori_disponibili($this->pdo);
        $prodotto_fornitori    = qk_load_prodotto_fornitori($this->pdo, $entity_id);
        // Carica tutti i prezzi/scaglioni di tutti i fornitori per il tab Fornitori
        $tutti_prezzi          = qk_load_prodotto_tutti_prezzi($this->pdo, $entity_id);
        $all_tags              = qk_load_all_tags($this->pdo);
        $prodotto_tag_ids      = qk_load_prodotto_tags($this->pdo, $entity_id);
        $kit_componenti        = ($values['tipo'] === 'kit') ? qk_load_kit_componenti($this->pdo, $entity_id) : [];

        $listini_prezzi_regole = $this->pdo->prepare('
            SELECT lp.*, l.nome AS listino_nome, l.valuta
            FROM listini_prezzi lp
            JOIN listini l ON l.id = lp.listino_id
            WHERE lp.prodotto_id = ?
            ORDER BY l.nome ASC, lp.quantita_minima ASC
        ');
        $listini_prezzi_regole->execute([$entity_id]);
        $prezzi_vendita_regole = $listini_prezzi_regole->fetchAll(PDO::FETCH_ASSOC);
        $listini = qk_get_listini_vendita_attivi($this->pdo, false); // Include base listino for the Matrix
        $iso_lingue = qk_get_iso_lingue_lista($this->pdo); // tab "Scheda di sicurezza" (M5)

        $page_title    = e($values['nome']) ?: 'Modifica prodotto';
        $page_subtitle = 'SKU ' . e($values['sku_interno']) . ' · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => $values['nome'] ?: 'Modifica'],
        ];
        $extra_css = ['products-suppliers.css', 'searchable-select.css'];

        $this->page(APP_ROOT_DIR . '/app/views/products/_form.php', get_defined_vars());
    }

    /* ── Eliminazione prodotto con controllo di integrità ──────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('products');
        }

        if (!csrf_verify()) {
            redirect_with('products', 'danger', 'Token CSRF non valido. Ricarica la pagina e riprova.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products', 'danger', 'ID prodotto non valido.');
        }

        try {
            $success = qk_delete_prodotto($this->pdo, $id);
            if ($success) {
                redirect_with('products', 'success', 'Prodotto eliminato definitivamente.');
            } else {
                redirect_with('products', 'warning', 'Impossibile eliminare definitivamente: il prodotto è già collegato a movimenti di magazzino, ordini o preventivi storici. Il record è stato archiviato (disattivato) per preservare l\'integrità dei dati.');
            }
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            redirect_with('products', 'danger', 'Errore durante l\'eliminazione del prodotto: ' . $e->getMessage());
        }
    }

    /* ── Duplicazione prodotto con copia relazioni e risoluzione SKU ───────── */

    public function duplicate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('products');
        }

        if (!csrf_verify()) {
            redirect_with('products', 'danger', 'Token CSRF non valido. Ricarica la pagina e riprova.');
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            redirect_with('products', 'danger', 'ID prodotto non valido.');
        }

        try {
            $newProductId = qk_duplicate_prodotto($this->pdo, $id);
            redirect_with('products/edit?id=' . $newProductId, 'success', 'Prodotto duplicato con successo. Stai modificando la copia.');
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            redirect_with('products', 'danger', 'Errore durante la duplicazione del prodotto: ' . $e->getMessage());
        }
    }

    /* ── API: crea categoria inline ──────────────────────────────────────── */

    /* ── API: ricerca fornitori (picker) ─────────────────────────────────── */

    /* ── API: ricerca categorie (picker) ─────────────────────────────────── */

    /* ── API: crea tag inline ────────────────────────────────────────────── */

    /* ── API: salva fornitore prodotto (insert o update) ────────────────── */

    /* ── API: elimina fornitore prodotto ─────────────────────────────────── */

    /* ── Helper privati ───────────────────────────────────────────────────── */

    private function defaultValues(): array
    {
        return [
            'sku_interno'          => '',
            'sku_ecommerce'        => '',
            'nome'                 => '',
            'descrizione_breve'    => '',
            'descrizione_confezione'=> '',
            'descrizione_completa' => '',
            'tipo'                 => 'semplice',
            'categoria_id'         => '',
            'note_interne'         => '',
            'attivo'               => '1',
            'traccia_lotti'        => '1',
            'unita_misura'         => 'pz',
            'unita_contenuto_id'   => '',
            'quantita_contenuto'   => '',
            'peso_netto_kg'        => '',
            'peso_lordo_kg'        => '',
            'ean'                  => '',
            'scorta_minima'        => '0',
            'is_adr'               => '0',
            'sostanza_chimica_id'      => '',
            'prezzo_vendita_base'  => '',
            'markup_fallback_perc' => '',
            'costo_acquisto_base'  => '',
            'costo_acquisto_medio' => '',
            'unita_misura_acquisto_id' => '',
        ];
    }

    private function readPostValues(array $current): array
    {
        $current = $this->readFormValues(
            $current,
            array_keys($this->defaultValues()),
            ['attivo', 'traccia_lotti', 'is_adr']
        );
        // Se is_adr è disabilitato, azzera la sostanza
        if ($current['is_adr'] === '0') {
            $current['sostanza_chimica_id'] = '';
        }
        return $current;
    }

    private function validate(array $v, ?int $editId = null): array
    {
        $errors = [];

        if (trim($v['sku_interno']) === '') {
            $errors['sku_interno'] = 'Lo SKU interno è obbligatorio.';
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM prodotti WHERE sku_interno = :sku AND (:id IS NULL OR id != :id)'
            );
            $stmt->execute([':sku' => trim($v['sku_interno']), ':id' => $editId]);
            if ($stmt->fetch()) {
                $errors['sku_interno'] = 'Questo SKU interno è già utilizzato.';
            }
        }

        if (trim($v['nome']) === '') {
            $errors['nome'] = 'Il nome del prodotto è obbligatorio.';
        }

        if (trim($v['unita_misura'] ?? '') === '') {
            $errors['unita_misura'] = 'L\'unità di misura di stock è obbligatoria.';
        } else {
            $umStmt = $this->pdo->prepare('SELECT COUNT(*) FROM unita_misura WHERE codice = :c');
            $umStmt->execute([':c' => $v['unita_misura']]);
            if (!$umStmt->fetchColumn()) {
                $errors['unita_misura'] = 'Unità di misura non valida.';
            } elseif ($editId !== null) {
                // Verifica se la UoM è cambiata e se ci sono movimenti di magazzino
                $currStmt = $this->pdo->prepare('
                    SELECT um.codice 
                    FROM prodotti p
                    LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
                    WHERE p.id = ?
                ');
                $currStmt->execute([$editId]);
                $currentUomCode = $currStmt->fetchColumn();

                if ($currentUomCode !== $v['unita_misura']) {
                    $movStmt = $this->pdo->prepare('SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id = ?');
                    $movStmt->execute([$editId]);
                    $hasMovements = (int)$movStmt->fetchColumn() > 0;
                    if ($hasMovements) {
                        $errors['unita_misura'] = 'Impossibile modificare l\'unità di misura di stock perché il prodotto ha già dei movimenti di magazzino registrati.';
                    }
                }
            }
        }

        // Validazione quantita_contenuto: max 3 decimali, positivo se presente
        $errQta = qk_prodotti_valida_quantita_contenuto($v['quantita_contenuto'] ?? null);
        if ($errQta !== null) {
            $errors['quantita_contenuto'] = $errQta;
        }

        return $errors;
    }

    /* ── Categorie Prodotti ────────────────────────────────────────────────── */

    /* ════════════════════════════════════════════════════════════════════════
     *  CATEGORIE — Export JSON (GET)
     * ════════════════════════════════════════════════════════════════════════ */

    /* ════════════════════════════════════════════════════════════════════════
     *  CATEGORIE — Import massivo (JSON API, POST)
     * ════════════════════════════════════════════════════════════════════════ */

    /* ── Tag Prodotti ──────────────────────────────────────────────────────── */

    /* ── API: ricerca prodotti (per kit component picker) ─────────────────── */

    /* ── API: lista componenti kit ────────────────────────────────────────── */

    /* ── API: salva componente kit (upsert via DELETE + INSERT) ───────────── */

    /* ── API: elimina componente kit ──────────────────────────────────────── */

    /* ════════════════════════════════════════════════════════════════════════
     *  PRODOTTI — importazione massiva (JSON API, POST)
     * ════════════════════════════════════════════════════════════════════════ */

    public function importProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']); exit;
        }

        $rows = json_decode(post('rows') ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        // Carica lookup UoM una volta sola
        $umMap = [];
        foreach ($this->pdo->query('SELECT codice, id FROM unita_misura')->fetchAll() as $r) {
            $umMap[strtolower(trim($r['codice']))] = (int) $r['id'];
        }

        // Carica lookup categorie per nome
        $catMap = [];
        foreach ($this->pdo->query('SELECT id, nome FROM categorie_prodotto')->fetchAll() as $r) {
            $catMap[strtolower(trim($r['nome']))] = (int) $r['id'];
        }

        $inserted  = 0;
        $skipped   = 0;
        $rowErrors = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $lineNum = $i + 2; // +2 perché riga 1 = intestazione Excel

                $sku  = trim((string)($row['sku_interno'] ?? ''));
                $nome = trim((string)($row['nome'] ?? ''));
                $um   = strtolower(trim((string)($row['unita_misura_stock'] ?? $row['unita_misura'] ?? 'pz')));

                if ($sku === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': SKU interno vuoto — saltata.';
                    continue;
                }
                if ($nome === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): nome vuoto — saltata.';
                    continue;
                }

                // Verifica duplicato SKU
                $dupStmt = $this->pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sku_interno = :s');
                $dupStmt->execute([':s' => $sku]);
                if ($dupStmt->fetchColumn() > 0) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': SKU "' . htmlspecialchars($sku) . '" già presente — saltata.';
                    continue;
                }

                // Risolvi UoM
                $umId = $umMap[$um] ?? $umMap['pz'] ?? null;
                if ($umId === null) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': unità di misura "' . htmlspecialchars($um) . '" non trovata — saltata.';
                    continue;
                }

                // Risolvi UoM Commerciale (opzionale)
                $umComm   = strtolower(trim((string)($row['unita_misura_commerciale'] ?? '')));
                $umCommId = ($umComm !== '') ? ($umMap[$umComm] ?? null) : null;

                // Risolvi categoria (opzionale)
                $catNome = strtolower(trim((string)($row['categoria'] ?? '')));
                $catId   = ($catNome !== '') ? ($catMap[$catNome] ?? null) : null;

                $tipo    = in_array(strtolower(trim((string)($row['tipo'] ?? ''))), ['kit']) ? 'kit' : 'semplice';
                $isAdr   = in_array(strtolower(trim((string)($row['is_adr'] ?? ''))), ['1', 'si', 'sì', 'yes', 'true']) ? 1 : 0;
                $attivo  = !in_array(strtolower(trim((string)($row['attivo'] ?? '1'))), ['0', 'no', 'false']) ? 1 : 0;
                $traccia = ($isAdr || in_array(strtolower(trim((string)($row['traccia_lotti'] ?? ''))), ['1', 'si', 'sì', 'yes', 'true'])) ? 1 : 0;

                $toFloat = function(?string $v): ?float {
                    if ($v === null || trim($v) === '') return null;
                    return (float) str_replace(',', '.', trim($v));
                };

                // NOTA: 'scorta_minima' non è più una colonna di prodotti (§3.5,
                // multi-magazzino): se presente nel CSV, la colonna viene
                // ignorata qui. La soglia va impostata per magazzino nel modulo
                // Magazzino dopo l'importazione, non è più un dato di anagrafica
                // prodotto importabile in blocco senza specificare la sede.
                $this->pdo->prepare(
                    'INSERT INTO prodotti
                       (sku_interno, sku_ecommerce, nome, descrizione_breve, descrizione_confezione,
                        tipo, categoria_id, note_interne, attivo, traccia_lotti,
                        unita_misura_stock_id, unita_contenuto_id, quantita_contenuto,
                        peso_netto_kg, peso_lordo_kg, ean, is_adr, created_at)
                     VALUES
                       (:sku, :sku_ec, :nome, :desc_b, :desc_conf,
                        :tipo, :cat_id, :note, :attivo, :traccia,
                        :um_id, :um_comm_id, :qta_cont,
                        :peso_n, :peso_l, :ean, :is_adr, CURRENT_TIMESTAMP)'
                )->execute([
                    ':sku'        => $sku,
                    ':sku_ec'     => ($row['sku_ecommerce'] ?? '') !== '' ? trim($row['sku_ecommerce']) : null,
                    ':nome'       => $nome,
                    ':desc_b'     => ($row['descrizione_breve'] ?? '') !== '' ? trim($row['descrizione_breve']) : null,
                    ':desc_conf'  => ($row['descrizione_confezione'] ?? '') !== '' ? trim($row['descrizione_confezione']) : null,
                    ':tipo'       => $tipo,
                    ':cat_id'     => $catId,
                    ':note'       => ($row['note_interne'] ?? '') !== '' ? trim($row['note_interne']) : null,
                    ':attivo'     => $attivo,
                    ':traccia'    => $traccia,
                    ':um_id'      => $umId,
                    ':um_comm_id' => $umCommId,
                    ':qta_cont'   => $toFloat($row['quantita_contenuto'] ?? null),
                    ':peso_n'     => $toFloat($row['peso_netto_kg'] ?? null),
                    ':peso_l'     => $toFloat($row['peso_lordo_kg'] ?? null),
                    ':ean'        => ($row['ean'] ?? '') !== '' ? trim($row['ean']) : null,
                    ':is_adr'     => $isAdr,
                ]);

                $prodId = (int) $this->pdo->lastInsertId();

                // Crea giacenza nel magazzino attivo. Se il CSV porta una colonna
                // scorta_minima, la usiamo comunque qui (una tantum, in fase di
                // importazione) come soglia iniziale per il magazzino attivo — è
                // l'unico punto in cui ha ancora senso leggerla dal CSV, perché qui
                // sappiamo già per quale sede stiamo creando la riga di giacenza.
                if ($tipo === 'semplice') {
                    $this->pdo->prepare(
                        'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                         VALUES (:id, :magazzino_id, 0, 0, 0, :scorta, CURRENT_TIMESTAMP)'
                    )->execute([
                        ':id'           => $prodId,
                        ':magazzino_id' => qk_magazzino_attivo_id($this->pdo),
                        ':scorta'       => $toFloat($row['scorta_minima'] ?? null) ?? 0.0,
                    ]);
                }

                // Prezzo di vendita base (listino 1)
                // Fix Bug B2 (audit/audit-bug-prezzo-preventivi-vendita.md): questo INSERT scriveva
                // listino_id=NULL invece di listino_id=1, rendendo il prezzo invisibile a
                // qk_get_sale_price() pur restando visibile nelle schermate prodotto (query diversa,
                // meno strette). Ora usa la stessa funzione condivisa di creazione/aggiornamento
                // prodotto singolo, per evitare che i tre percorsi divergano di nuovo.
                $prezzoBase = $toFloat($row['prezzo_vendita_base'] ?? null);
                if ($prezzoBase !== null) {
                    qk_salva_prezzo_base_prodotto($this->pdo, $prodId, $prezzoBase, $umId);
                }

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

    /* ── API: salva singola riga prezzo fornitore (insert o update) ──────── */

    /* ── API: elimina singola riga prezzo fornitore ───────────────────────── */

    /* ── Compliance prodotto ─────────────────────────────────────────────── */

    /**
     * POST /products/api/save-costo-base
     * Salva asincronamente il costo d'acquisto base e l'UoM.
     */
    public function apiSaveCostoBase()
    {
        // Auth + permesso 'manage_catalog' sono già applicati a livello di router
        // (vedi app/routes.php, voce '/products/api/save-costo-base'), come per
        // tutte le altre rotte con 'auth' => true — nessun controllo duplicato qui.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']);
            exit;
        }

        $prodottoId = (int)($_POST['prodotto_id'] ?? 0);
        $costoBase = $_POST['costo_acquisto_base'] ?? '';
        $umAcquistoId = $_POST['unita_misura_acquisto_id'] ?? '';

        if (!$prodottoId) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto ID mancante.']);
            exit;
        }

        $costoNum = ($costoBase === '') ? null : (float)str_replace(',', '.', $costoBase);
        $umIdNum = ($umAcquistoId === '') ? null : (int)$umAcquistoId;

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO prodotti_valutazione_costi (prodotto_id, costo_acquisto_base, costo_acquisto_medio, unita_misura_acquisto_id)
                VALUES (:pid, :costo, 0, :umid)
                ON CONFLICT(prodotto_id) DO UPDATE SET 
                    costo_acquisto_base = excluded.costo_acquisto_base,
                    unita_misura_acquisto_id = excluded.unita_misura_acquisto_id
            ');
            $stmt->execute([
                ':pid' => $prodottoId,
                ':costo' => $costoNum,
                ':umid' => $umIdNum
            ]);

            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

