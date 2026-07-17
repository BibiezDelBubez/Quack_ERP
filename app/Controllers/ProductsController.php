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
        $listini = qk_get_listini_vendita_attivi($this->pdo, true);

        $page_title    = 'Nuovo prodotto';
        $page_subtitle = 'Aggiungi un prodotto al catalogo';
        $breadcrumbs   = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => 'Nuovo'],
        ];
        $record_meta = null;
        $extra_css   = ['products-suppliers.css'];

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
                        if (!empty($_FILES['foto_principale']['name'])) {
                            qk_salva_immagine_prodotto($this->pdo, $entity_id, $_FILES['foto_principale']);
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

                        redirect_with('products/edit?id=' . $entity_id, 'success', $msg);
                    } catch (Throwable $e) {
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
        $listini = qk_get_listini_vendita_attivi($this->pdo, true);

        $page_title    = e($values['nome']) ?: 'Modifica prodotto';
        $page_subtitle = 'SKU ' . e($values['sku_interno']) . ' · ID #' . $entity_id;
        $breadcrumbs   = [
            ['label' => 'Prodotti', 'url' => url('products')],
            ['label' => $values['nome'] ?: 'Modifica'],
        ];
        $extra_css = ['products-suppliers.css'];

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

        $this->pdo->beginTransaction();
        try {
            // 1. Cancella configurazioni/anagrafiche associate per superare i vincoli se non usato in transazioni vere
            $this->pdo->prepare("DELETE FROM giacenze WHERE prodotto_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM listini_prezzi WHERE prodotto_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM prodotti_tag WHERE prodotto_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM componenti_kit WHERE kit_id = ?")->execute([$id]);
            
            // Cancella i prezzi dei fornitori di questo prodotto
            $this->pdo->prepare("
                DELETE FROM prodotti_fornitori_prezzi 
                WHERE prodotto_fornitore_id IN (SELECT id FROM prodotti_fornitori WHERE prodotto_id = ?)
            ")->execute([$id]);
            $this->pdo->prepare("DELETE FROM prodotti_fornitori WHERE prodotto_id = ?")->execute([$id]);

            // Forza vincoli FK in SQLite (in MySQL/InnoDB sono sempre attivi)
            if (DB_DRIVER === 'sqlite') {
                $this->pdo->exec("PRAGMA foreign_keys = ON;");
            }

            // Proviamo la cancellazione fisica
            $stmt = $this->pdo->prepare("DELETE FROM prodotti WHERE id = ?");
            $stmt->execute([$id]);
            $this->pdo->commit();
            redirect_with('products', 'success', 'Prodotto eliminato definitivamente.');
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            // Fallback: lo archiviamo (disattiviamo)
            try {
                $this->pdo->prepare("UPDATE prodotti SET attivo = 0 WHERE id = ?")->execute([$id]);
                redirect_with('products', 'warning', 'Impossibile eliminare definitivamente: il prodotto è già collegato a movimenti di magazzino, ordini o preventivi storici. Il record è stato archiviato (disattivato) per preservare l\'integrità dei dati.');
            } catch (Throwable $ex) {
                redirect_with('products', 'danger', 'Errore durante l\'archiviazione del prodotto: ' . $ex->getMessage());
            }
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

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products', 'danger', 'ID prodotto non valido.');
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Carica il prodotto originale
            $stmt = $this->pdo->prepare('SELECT * FROM prodotti WHERE id = ?');
            $stmt->execute([$id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                throw new Exception('Prodotto non trovato.');
            }

            // 2. Genera SKU univoco
            $originalSku = $prod['sku_interno'] ?? 'PROD';
            $newSku = $originalSku . '_COPIA';
            $checkSku = $this->pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sku_interno = ?');
            $checkSku->execute([$newSku]);
            $count = (int)$checkSku->fetchColumn();
            $suffix = 1;
            while ($count > 0) {
                $newSku = $originalSku . '_COPIA_' . $suffix;
                $checkSku->execute([$newSku]);
                $count = (int)$checkSku->fetchColumn();
                $suffix++;
            }

            // 3. Inserimento nuovo prodotto (EAN e SKU WooCommerce vengono resettati per evitare conflitti)
            // scorta_minima non è più un campo di prodotti (multi-magazzino, §3.5):
            // la copia non porta con sé alcuna soglia, la giacenza del nuovo
            // prodotto nel magazzino attivo parte da 0 come per un prodotto nuovo.
            $insertStmt = $this->pdo->prepare('
                INSERT INTO prodotti (
                    sku_interno, sku_ecommerce, nome, descrizione_breve, descrizione_confezione,
                    descrizione_completa, tipo, categoria_id, note_interne, attivo,
                    traccia_lotti, unita_misura_stock_id, unita_contenuto_id, quantita_contenuto,
                    peso_netto_kg, peso_lordo_kg, ean, is_adr,
                    sostanza_chimica_id, created_at
                ) VALUES (
                    :sku_interno, NULL, :nome, :descrizione_breve, :descrizione_confezione,
                    :descrizione_completa, :tipo, :categoria_id, :note_interne, :attivo,
                    :traccia_lotti, :unita_misura_stock_id, :unita_contenuto_id, :quantita_contenuto,
                    :peso_netto_kg, :peso_lordo_kg, NULL, :is_adr,
                    :sostanza_chimica_id, CURRENT_TIMESTAMP
                )
            ');

            $insertStmt->execute([
                ':sku_interno' => $newSku,
                ':nome' => ($prod['nome'] ?? '') . ' - Copia',
                ':descrizione_breve' => $prod['descrizione_breve'] ?? null,
                ':descrizione_confezione' => $prod['descrizione_confezione'] ?? null,
                ':descrizione_completa' => $prod['descrizione_completa'] ?? null,
                ':tipo' => $prod['tipo'] ?? 'semplice',
                ':categoria_id' => $prod['categoria_id'] ?? null,
                ':note_interne' => $prod['note_interne'] ?? null,
                ':attivo' => $prod['attivo'],
                ':traccia_lotti' => $prod['traccia_lotti'],
                ':unita_misura_stock_id' => $prod['unita_misura_stock_id'],
                ':unita_contenuto_id' => $prod['unita_contenuto_id'] ?? null,
                ':quantita_contenuto' => $prod['quantita_contenuto'] ?? null,
                ':peso_netto_kg' => $prod['peso_netto_kg'] ?? null,
                ':peso_lordo_kg' => $prod['peso_lordo_kg'] ?? null,
                ':is_adr' => $prod['is_adr'] ?? 0,
                ':sostanza_chimica_id' => $prod['sostanza_chimica_id'] ?? null,
            ]);

            $newProductId = (int) $this->pdo->lastInsertId();

            // 4. Crea riga giacenze per il nuovo prodotto nel magazzino attivo
            $this->pdo->prepare('
                INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                VALUES (?, ?, 0, 0, 0, 0, CURRENT_TIMESTAMP)
            ')->execute([$newProductId, qk_magazzino_attivo_id($this->pdo)]);

            // 5. Copia tag associati
            qk_copy_prodotto_tags($this->pdo, $id, $newProductId);

            // 6. Copia fornitori e relativi listini acquisto (prezzi)
            $fornStmt = $this->pdo->prepare('SELECT * FROM prodotti_fornitori WHERE prodotto_id = ?');
            $fornStmt->execute([$id]);
            $fornitoriList = $fornStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($fornitoriList)) {
                $insForn = $this->pdo->prepare('
                    INSERT INTO prodotti_fornitori (
                        prodotto_id, fornitore_id, sku_fornitore, nome_prodotto_fornitore,
                        unita_misura_acquisto_id, quantita_minima, iva_acquisto_default,
                        ordine_preferenza_fornitore, lead_time_giorni, attivo, note,
                        rrp_min, rrp_max, rrp_unita_misura_id, rrp_valido_dal, rrp_valido_al, rrp_valuta, created_at
                    ) VALUES (
                        :pid, :fid, :sku, :nome,
                        :uom_id, :qta, :iva,
                        :ord, :lead, :attivo, :note,
                        :rmin, :rmax, :ruom, :rdal, :ral, :rval, CURRENT_TIMESTAMP
                    )
                ');

                $insPrezzo = $this->pdo->prepare('
                    INSERT INTO prodotti_fornitori_prezzi (
                        prodotto_fornitore_id, quantita_minima, prezzo, unita_misura_id,
                        sconto_perc, valido_dal, valido_al, note, listino_acquisto_id,
                        quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock
                    ) VALUES (
                        :pfid, :qta, :prezzo, :uom_id,
                        :sconto, :vdal, :val, :note, :listino_id,
                        :qmin_intento, :um_soglia_id, :qmin_stock
                    )
                ');

                foreach ($fornitoriList as $f) {
                    $insForn->execute([
                        ':pid' => $newProductId,
                        ':fid' => $f['fornitore_id'],
                        ':sku' => $f['sku_fornitore'] ? $f['sku_fornitore'] . '_COPIA' : null,
                        ':nome' => $f['nome_prodotto_fornitore'],
                        ':uom_id' => $f['unita_misura_acquisto_id'],
                        ':qta' => $f['quantita_minima'],
                        ':iva' => $f['iva_acquisto_default'],
                        ':ord' => $f['ordine_preferenza_fornitore'],
                        ':lead' => $f['lead_time_giorni'],
                        ':attivo' => $f['attivo'],
                        ':note' => $f['note'],
                        ':rmin' => $f['rrp_min'],
                        ':rmax' => $f['rrp_max'],
                        ':ruom' => $f['rrp_unita_misura_id'],
                        ':rdal' => $f['rrp_valido_dal'],
                        ':ral' => $f['rrp_valido_al'],
                        ':rval' => $f['rrp_valuta'],
                    ]);

                    $newPfId = (int) $this->pdo->lastInsertId();

                    // Prezzi/scaglioni collegati a questo fornitore
                    $prezziStmt = $this->pdo->prepare('SELECT * FROM prodotti_fornitori_prezzi WHERE prodotto_fornitore_id = ?');
                    $prezziStmt->execute([$f['id']]);
                    $prezzi = $prezziStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($prezzi as $p) {
                        // Le 3 colonne canoniche multi-UoM (D3/D1.1) vengono copiate
                        // direttamente dalla riga sorgente, senza ricalcolo: il fattore
                        // di conversione del prodotto (unita_misura_stock_id/unita_contenuto_id/
                        // quantita_contenuto) è già stato copiato identico al nuovo prodotto
                        // al punto 3, quindi il valore canonico resta valido invariato.
                        $insPrezzo->execute([
                            ':pfid' => $newPfId,
                            ':qta' => $p['quantita_minima'],
                            ':prezzo' => $p['prezzo'],
                            ':uom_id' => $p['unita_misura_id'],
                            ':sconto' => $p['sconto_perc'],
                            ':vdal' => $p['valido_dal'],
                            ':val' => $p['valido_al'],
                            ':note' => $p['note'],
                            ':listino_id' => $p['listino_acquisto_id'],
                            ':qmin_intento' => $p['quantita_minima_intento'] ?? null,
                            ':um_soglia_id' => $p['unita_misura_soglia_id'] ?? null,
                            ':qmin_stock' => $p['quantita_minima_stock'] ?? null,
                        ]);
                    }
                }
            }

            // 7. Copia listini prezzi (regole vendita)
            $listiniStmt = $this->pdo->prepare('SELECT * FROM listini_prezzi WHERE prodotto_id = ?');
            $listiniStmt->execute([$id]);
            $listiniRegole = $listiniStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($listiniRegole)) {
                $insListino = $this->pdo->prepare('
                    INSERT INTO listini_prezzi (
                        listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, sconto_perc, note, created_at,
                        quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock
                    )
                    VALUES (
                        :lid, :pid, :qmin, :prezzo, :uom_id, :sconto, :note, CURRENT_TIMESTAMP,
                        :qmin_intento, :um_soglia_id, :qmin_stock
                    )
                ');
                foreach ($listiniRegole as $lr) {
                    // Stesso principio del blocco fornitori sopra: copia diretta delle
                    // colonne canoniche, valida perché il fattore di conversione del
                    // prodotto è identico su origine e copia.
                    $insListino->execute([
                        ':lid' => $lr['listino_id'],
                        ':pid' => $newProductId,
                        ':qmin' => $lr['quantita_minima'],
                        ':prezzo' => $lr['prezzo_unitario'],
                        ':uom_id' => $lr['unita_misura_id'],
                        ':sconto' => $lr['sconto_perc'],
                        ':note' => $lr['note'],
                        ':qmin_intento' => $lr['quantita_minima_intento'] ?? null,
                        ':um_soglia_id' => $lr['unita_misura_soglia_id'] ?? null,
                        ':qmin_stock' => $lr['quantita_minima_stock'] ?? null,
                    ]);
                }
            }

            // 8. Copia componenti kit (se il prodotto originale è un kit)
            if (($prod['tipo'] ?? '') === 'kit') {
                $kitStmt = $this->pdo->prepare('SELECT * FROM componenti_kit WHERE kit_id = ?');
                $kitStmt->execute([$id]);
                $compList = $kitStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($compList)) {
                    $insComp = $this->pdo->prepare('
                        INSERT INTO componenti_kit (kit_id, componente_id, quantita, note)
                        VALUES (?, ?, ?, ?)
                    ');
                    foreach ($compList as $c) {
                        $insComp->execute([$newProductId, $c['componente_id'], $c['quantita'], $c['note']]);
                    }
                }
            }

            $this->pdo->commit();
            redirect_with('products/edit?id=' . $newProductId, 'success', 'Prodotto duplicato con successo. Stai modificando la copia.');
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            redirect_with('products', 'danger', 'Errore durante la duplicazione del prodotto: ' . $e->getMessage());
        }
    }

    /* ── API: crea categoria inline ──────────────────────────────────────── */

    public function crearCategoria(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']); exit;
        }

        $nome = trim(post('nome') ?? '');
        if ($nome === '') {
            echo json_encode(['ok' => false, 'error' => 'Il nome è obbligatorio.']); exit;
        }
        if (mb_strlen($nome) > 100) {
            echo json_encode(['ok' => false, 'error' => 'Nome troppo lungo (max 100 caratteri).']); exit;
        }

        try {
            $id = qk_crea_categoria($this->pdo, $nome);
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome, 'label' => $nome]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }

    /* ── API: ricerca fornitori (picker) ─────────────────────────────────── */

    public function searchFornitori(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q     = trim(get_param('q'));
        $limit = min(100, max(1, (int) get_param('limit', '50')));
        echo json_encode(qk_search_fornitori($this->pdo, $q, $limit));
        exit;
    }

    /* ── API: ricerca categorie (picker) ─────────────────────────────────── */

    public function searchCategorie(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q     = trim(get_param('q'));
        $limit = min(100, max(1, (int) get_param('limit', '50')));
        echo json_encode(qk_search_categorie($this->pdo, $q, $limit));
        exit;
    }

    /* ── API: crea tag inline ────────────────────────────────────────────── */

    public function crearTag(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }
        $nome = trim(post('nome') ?? '');
        if ($nome === '') {
            echo json_encode(['ok' => false, 'error' => 'Il nome è obbligatorio.']); exit;
        }
        try {
            $id = qk_crea_tag($this->pdo, $nome);
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }

    /* ── API: salva fornitore prodotto (insert o update) ────────────────── */

    public function saveForn(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $prodotto_id  = (int) post('prodotto_id');
        $id           = (int) post('id');
        $fornitore_id = (int) post('fornitore_id');
        if ($prodotto_id <= 0 || $fornitore_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }

        $n = fn(string $k): mixed => trim((string)(post($k) ?? '')) === '' ? null : post($k);

        $prezzoBase    = $n('prezzo_acquisto') !== null ? (float) $n('prezzo_acquisto') : null;
        $ordPref       = max(1, (int) (post('ordine_preferenza_fornitore') ?? 1));
        $rrpMin        = $n('rrp_min') !== null ? (float) $n('rrp_min') : null;
        $rrpMax        = $n('rrp_max') !== null ? (float) $n('rrp_max') : null;
        $leadTime      = $n('lead_time_giorni') !== null ? (int) $n('lead_time_giorni') : null;
        $unitaMisura   = $n('unita_misura_acquisto_id') !== null ? (int) $n('unita_misura_acquisto_id') : null;

        // D0.4 (PIANO Motore di Pricing Multi-UoM §5.6/§7.5, log decisioni punto L): l'unità di
        // acquisto di un fornitore deve SEMPRE coincidere con la Stock UoM o con la UoM commerciale
        // del prodotto — mai una terza unità priva di fattore di conversione noto. Questa regola era
        // rispettata finora solo per comportamento accidentale del client (campo nascosto in
        // products-suppliers.js); qui viene fatta rispettare esplicitamente dal server.
        $stmtUmProdotto = $this->pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid');
        $stmtUmProdotto->execute([':pid' => $prodotto_id]);
        $prodottoUm = $stmtUmProdotto->fetch(PDO::FETCH_ASSOC);
        if (!$prodottoUm) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto non trovato.']); exit;
        }
        $umStockId      = (int) $prodottoUm['unita_misura_stock_id'];
        $umCommercialId = $prodottoUm['unita_contenuto_id'] !== null ? (int) $prodottoUm['unita_contenuto_id'] : null;

        if ($unitaMisura === null) {
            $unitaMisura = $umStockId;
        } elseif ($unitaMisura !== $umStockId && $unitaMisura !== $umCommercialId) {
            echo json_encode(['ok' => false, 'error' => "Unità di acquisto non valida per questo prodotto: deve essere l'unità di stock o l'unità commerciale del prodotto, non una terza unità."]); exit;
        }
        $note          = $n('note');

        try {
            $this->pdo->beginTransaction();

            if ($id <= 0) {
                $checkStmt = $this->pdo->prepare(
                    'SELECT id FROM prodotti_fornitori WHERE prodotto_id = :pid AND fornitore_id = :fid LIMIT 1'
                );
                $checkStmt->execute([':pid' => $prodotto_id, ':fid' => $fornitore_id]);
                $existingId = $checkStmt->fetchColumn();
                if ($existingId) {
                    $id = (int)$existingId;
                }
            }

            if ($id > 0) {
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori SET
                       fornitore_id                  = :fid,
                       sku_fornitore                 = :sku,
                       nome_prodotto_fornitore       = :nome,
                       quantita_minima               = :qta,
                       ordine_preferenza_fornitore   = :ord_pref,
                       attivo                        = :attivo,
                       rrp_min                       = :rrp_min,
                       rrp_max                       = :rrp_max,
                       lead_time_giorni              = :lead_time,
                       unita_misura_acquisto_id      = :uom_id,
                       note                          = :note,
                       updated_at                    = CURRENT_TIMESTAMP
                     WHERE id = :id AND prodotto_id = :pid'
                )->execute([
                    ':fid'      => $fornitore_id,
                    ':sku'      => $n('sku_fornitore'),
                    ':nome'     => $n('nome_prodotto_fornitore'),
                    ':qta'      => $n('quantita_minima') !== null ? (float) $n('quantita_minima') : null,
                    ':ord_pref' => $ordPref,
                    ':attivo'   => post('attivo') === '1' ? 1 : 0,
                    ':rrp_min'  => $rrpMin,
                    ':rrp_max'  => $rrpMax,
                    ':lead_time'=> $leadTime,
                    ':uom_id'   => $unitaMisura,
                    ':note'     => $note,
                    ':id'       => $id,
                    ':pid'      => $prodotto_id,
                ]);
                $pfId = $id;
            } else {
                $this->pdo->prepare(
                    'INSERT INTO prodotti_fornitori
                       (prodotto_id, fornitore_id, sku_fornitore, nome_prodotto_fornitore,
                        quantita_minima, ordine_preferenza_fornitore, attivo,
                        rrp_min, rrp_max, lead_time_giorni, unita_misura_acquisto_id, note, created_at)
                     VALUES
                       (:pid, :fid, :sku, :nome,
                        :qta, :ord_pref, :attivo,
                        :rrp_min, :rrp_max, :lead_time, :uom_id, :note, CURRENT_TIMESTAMP)'
                )->execute([
                    ':pid'      => $prodotto_id,
                    ':fid'      => $fornitore_id,
                    ':sku'      => $n('sku_fornitore'),
                    ':nome'     => $n('nome_prodotto_fornitore'),
                    ':qta'      => $n('quantita_minima') !== null ? (float) $n('quantita_minima') : null,
                    ':ord_pref' => $ordPref,
                    ':attivo'   => post('attivo') === '1' ? 1 : 0,
                    ':rrp_min'  => $rrpMin,
                    ':rrp_max'  => $rrpMax,
                    ':lead_time'=> $leadTime,
                    ':uom_id'   => $unitaMisura,
                    ':note'     => $note,
                ]);
                $pfId = (int) $this->pdo->lastInsertId();
            }

            if ($ordPref === 1) {
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori SET ordine_preferenza_fornitore = 2 WHERE prodotto_id = :pid AND id != :id'
                )->execute([':pid' => $prodotto_id, ':id' => $pfId]);
            }

            // D0.3 (PIANO Motore di Pricing Multi-UoM §5.4/§5.5/§8): questo endpoint conteneva un
            // secondo percorso di scrittura su prodotti_fornitori_prezzi ("Listino Base auto-creato"),
            // vestigiale e mai eseguito in pratica — l'unico chiamante reale (products-suppliers.js)
            // manda sempre no_prezzi=1 e salva i prezzi separatamente tramite savePrezzo(). Rimosso
            // perché duplicava (peggio: senza mai valorizzare unita_misura_id) l'unico percorso corretto.
            // I prezzi vanno sempre e solo tramite savePrezzo() (endpoint /products/fornitori/prezzi/save).
            if (post('no_prezzi') !== '1' && post('prezzi') !== null) {
                echo json_encode(['ok' => false, 'error' => 'Il salvataggio dei prezzi da questo endpoint non è più supportato. Usare /products/fornitori/prezzi/save (savePrezzo).']);
                $this->pdo->rollBack();
                exit;
            }

            $this->pdo->commit();

            // Restituisci la riga completa per l'aggiornamento DOM lato client
            $rowData = qk_load_single_prodotto_fornitore($this->pdo, $pfId);
            echo json_encode(['ok' => true, 'row' => $rowData]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => qk_prodotti_db_message($e)]);
        }
        exit;
    }

    /* ── API: elimina fornitore prodotto ─────────────────────────────────── */

    public function deleteForn(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id          = (int) post('id');
        $prodotto_id = (int) post('prodotto_id');
        if ($id <= 0 || $prodotto_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM prodotti_fornitori WHERE id = :id AND prodotto_id = :pid'
            )->execute([':id' => $id, ':pid' => $prodotto_id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

    public function saveRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $prodotto_id  = (int) post('prodotto_id');
        $rrp_data     = post('rrp_data'); // JSON string

        if ($prodotto_id <= 0 || !$rrp_data) {
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']); exit;
        }

        $rrps = json_decode($rrp_data, true);
        if (!is_array($rrps)) {
            $rrps = [];
        }

        try {
            $this->pdo->beginTransaction();
            
            // Eliminiamo tutti gli rrp esistenti per questo prodotto
            $this->pdo->prepare('DELETE FROM prodotti_rrp WHERE prodotto_id = :pid')
                      ->execute([':pid' => $prodotto_id]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO prodotti_rrp (id, prodotto_id, fornitore_id, rrp_min, rrp_max, valuta, valido_dal, valido_al, note) 
                 VALUES (:id, :pid, :fid, :rmin, :rmax, :valuta, :vdal, :val, :note)'
            );

            foreach ($rrps as $r) {
                $forn_id = !empty($r['fornitore_id']) ? (int)$r['fornitore_id'] : null;
                $stmt->execute([
                    ':id'     => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)),
                    ':pid'    => $prodotto_id,
                    ':fid'    => $forn_id,
                    ':rmin'   => isset($r['rrp_min']) ? (float)$r['rrp_min'] : 0,
                    ':rmax'   => isset($r['rrp_max']) ? (float)$r['rrp_max'] : 0,
                    ':valuta' => !empty($r['valuta']) ? $r['valuta'] : 'EUR',
                    ':vdal'   => !empty($r['valido_dal']) ? $r['valido_dal'] : null,
                    ':val'    => !empty($r['valido_al']) ? $r['valido_al'] : null,
                    ':note'   => !empty($r['note']) ? $r['note'] : null
                ]);
            }
            
            $this->pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function loadRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            echo json_encode(['ok' => false, 'error' => 'ID prodotto non valido.']);
            exit;
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT * FROM prodotti_rrp 
                WHERE prodotto_id = ? 
                ORDER BY created_at ASC
            ');
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function deleteRrp(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = post('id'); // UUID
        $prodottoId = (int) post('prodotto_id');

        if (!$id || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare('DELETE FROM prodotti_rrp WHERE id = :id AND prodotto_id = :pid')
                      ->execute([':id' => $id, ':pid' => $prodottoId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

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
        ];
    }

    private function readPostValues(array $current): array
    {
        foreach (array_keys($current) as $k) {
            $current[$k] = post($k, $current[$k] ?? '');
        }
        $current['attivo']        = isset($_POST['attivo'])        ? '1' : '0';
        $current['traccia_lotti'] = isset($_POST['traccia_lotti']) ? '1' : '0';
        $current['is_adr']        = isset($_POST['is_adr'])        ? '1' : '0';
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

    public function categories(): void
    {
        $errors = [];
        $ok = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $nome = trim(post('nome') ?? '');
                $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_VALIDATE_INT) ?: null;
                $posizione_categoria = (int) post('posizione_categoria', '0');
                $immagine_path = trim(post('immagine_path') ?? '') ?: null;

                if ($nome === '') {
                    $errors['nome'] = 'Il nome della categoria è obbligatorio.';
                } elseif (mb_strlen($nome) > 100) {
                    $errors['nome'] = 'Nome troppo lungo (max 100 caratteri).';
                }

                if ($id && $parent_id && $id === $parent_id) {
                    $errors['parent_id'] = 'Una categoria non può essere genitore di se stessa.';
                }

                // Handle image upload
                if (empty($errors) && isset($_FILES['immagine_file']) && $_FILES['immagine_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp = $_FILES['immagine_file']['tmp_name'];
                    $name = $_FILES['immagine_file']['name'];

                    // Verifica tipo reale del file (MIME sniffato dal contenuto, non
                    // dall'estensione dichiarata dall'utente). SVG escluso: può contenere
                    // script embedded (rischio XSS), coerente con AziendeController.
                    $allowedMimeToExt = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                    ];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $realMime = $finfo ? finfo_file($finfo, $tmp) : false;
                    if ($finfo) {
                        finfo_close($finfo);
                    }
                    $ext = $allowedMimeToExt[$realMime] ?? null;
                    if ($ext === null || getimagesize($tmp) === false) {
                        $errors['immagine_file'] = 'Formato immagine non valido. Consentiti: ' . implode(', ', $allowedMimeToExt);
                    } else {
                        $subDir = 'categorie';
                        $absDir = upload_ensure_dir($subDir);
                        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                        $finalName = $safeName . '_' . time() . '.' . $ext;
                        $destAbs = $absDir . DIRECTORY_SEPARATOR . $finalName;
                        if (move_uploaded_file($tmp, $destAbs)) {
                            $immagine_path = $subDir . '/' . $finalName;
                        } else {
                            $errors['immagine_file'] = 'Impossibile salvare l\'immagine caricata.';
                        }
                    }
                }

                if (empty($errors)) {
                    try {
                        if ($id) {
                            $oldImg = qk_save_category($this->pdo, $id, $nome, $parent_id, $posizione_categoria, $immagine_path);

                            // Se avevamo un'immagine vecchia ed è diversa dalla nuova (cioè abbiamo fatto upload), la eliminiamo
                            if ($oldImg && $oldImg !== $immagine_path) {
                                $absPath = APP_ROOT_DIR . '/public/' . ltrim($oldImg, '/\\');
                                if (is_file($absPath)) {
                                    if (!unlink($absPath)) {
                                        error_log("ProductsController: Impossibile eliminare la vecchia immagine categoria: " . $absPath);
                                    }
                                }
                            }

                            $msg = $id ? 'Categoria aggiornata con successo.' : 'Categoria creata con successo.';
                            redirect_with('products/categories', 'success', $msg);
                        }
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        // Fetch categories list
        $categories = qk_get_categories_list($this->pdo);

        // Fetch flat list for dropdown selection (parent choice)
        $dropdown_categories = qk_load_categorie($this->pdo);

        $this->page(APP_ROOT_DIR . '/app/views/products/_categories.php', [
            'page_title'          => 'Categorie Prodotti',
            'page_subtitle'       => 'Gestione delle categorie merceologiche del catalogo',
            'breadcrumbs'         => [
                ['label' => 'Prodotti', 'url' => url('products')],
                ['label' => 'Categorie'],
            ],
            'categories'          => $categories,
            'dropdown_categories' => $dropdown_categories,
            'errors'              => $errors,
        ]);
    }

    public function deleteCategory(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('products/categories', 'danger', 'Metodo non consentito.');
        }

        if (!csrf_verify()) {
            redirect_with('products/categories', 'danger', 'Token di sicurezza non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products/categories', 'danger', 'Categoria non specificata.');
        }

        $res = qk_delete_category($this->pdo, $id);
        
        if (!$res['ok']) {
            redirect_with('products/categories', 'danger', $res['error']);
        }

        $oldImg = $res['immagine_path'] ?? null;
        if ($oldImg) {
            $absPath = APP_ROOT_DIR . '/public/' . ltrim($oldImg, '/\\');
            if (is_file($absPath)) {
                if (!unlink($absPath)) {
                    error_log("ProductsController: Impossibile eliminare l'immagine categoria in fase di cancellazione: " . $absPath);
                }
            }
        }

        redirect_with('products/categories', 'success', 'Categoria eliminata correttamente.');
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CATEGORIE — Export JSON (GET)
     * ════════════════════════════════════════════════════════════════════════ */

    public function exportCategorie(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $rows = qk_get_categories_for_export($this->pdo);

        echo json_encode([
            'ok'         => true,
            'exported_at' => date('Y-m-d H:i:s'),
            'rows'       => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /* ════════════════════════════════════════════════════════════════════════
     *  CATEGORIE — Import massivo (JSON API, POST)
     * ════════════════════════════════════════════════════════════════════════ */

    public function importCategorie(): void
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

        try {
            $result = qk_import_categories_massively($this->pdo, $rows);
            echo json_encode($result);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $this->dbMessage($e)]);
        }
        exit;
    }

    /* ── Tag Prodotti ──────────────────────────────────────────────────────── */

    public function tags(): void
    {
        $errors = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina.';
            } else {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                $nome = trim(post('nome') ?? '');

                if ($nome === '') {
                    $errors['nome'] = 'Il nome del tag è obbligatorio.';
                } elseif (mb_strlen($nome) > 50) {
                    $errors['nome'] = 'Nome troppo lungo (max 50 caratteri).';
                }

                // Check uniqueness
                if (empty($errors)) {
                    if (qk_tag_exists($this->pdo, $nome, $id)) {
                        $errors['nome'] = 'Questo tag esiste già.';
                    }
                }

                if (empty($errors)) {
                    try {
                        qk_save_tag($this->pdo, $id, $nome);
                        $msg = $id ? 'Tag aggiornato con successo.' : 'Tag creato con successo.';
                        redirect_with('products/tags', 'success', $msg);
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        // Fetch tags with product count
        $tags = qk_get_tags_with_count($this->pdo);

        $this->page(APP_ROOT_DIR . '/app/views/products/_tags.php', [
            'page_title'    => 'Tag Prodotti',
            'page_subtitle' => 'Gestione delle etichette e dei tag associati ai prodotti',
            'breadcrumbs'   => [
                ['label' => 'Prodotti', 'url' => url('products')],
                ['label' => 'Tag'],
            ],
            'tags'          => $tags,
            'errors'        => $errors,
        ]);
    }

    public function deleteTag(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('products/tags', 'danger', 'Metodo non consentito.');
        }

        if (!csrf_verify()) {
            redirect_with('products/tags', 'danger', 'Token di sicurezza non valido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('products/tags', 'danger', 'Tag non specificato.');
        }

        try {
            qk_delete_tag($this->pdo, $id);
            redirect_with('products/tags', 'success', 'Tag eliminato correttamente.');
        } catch (Throwable $e) {
            redirect_with('products/tags', 'danger', 'Errore durante l\'eliminazione del tag.');
        }
    }

    /* ── API: ricerca prodotti (per kit component picker) ─────────────────── */

    public function searchProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q          = trim(get_param('q'));
        $limit      = min(100, max(1, (int) get_param('limit', '50')));
        $exclude_id = filter_input(INPUT_GET, 'exclude', FILTER_VALIDATE_INT) ?: null;
        echo json_encode(qk_search_prodotti($this->pdo, $q, $limit, $exclude_id));
        exit;
    }

    /* ── API: lista componenti kit ────────────────────────────────────────── */

    public function listComponentiKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $kit_id = filter_input(INPUT_GET, 'kit_id', FILTER_VALIDATE_INT);
        if (!$kit_id) { echo json_encode([]); exit; }
        echo json_encode(qk_load_kit_componenti($this->pdo, $kit_id));
        exit;
    }

    /* ── API: salva componente kit (upsert via DELETE + INSERT) ───────────── */

    public function saveComponenteKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $kit_id       = (int) post('kit_id');
        $componente_id = (int) post('componente_id');
        $quantita     = trim((string)(post('quantita') ?? ''));
        $note         = trim((string)(post('note') ?? '')) ?: null;

        if ($kit_id <= 0 || $componente_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }
        if ($kit_id === $componente_id) {
            echo json_encode(['ok' => false, 'error' => 'Un kit non può contenere se stesso.']); exit;
        }

        // FIX Segnalazione #23: Impedisci kit nidificati (Kit dentro Kit) per evitare crash matematici
        $stmtComp = $this->pdo->prepare('SELECT tipo FROM prodotti WHERE id = :id');
        $stmtComp->execute([':id' => $componente_id]);
        if ($stmtComp->fetchColumn() === 'kit') {
            echo json_encode(['ok' => false, 'error' => 'Impossibile inserire un Kit all\'interno di un altro Kit.']); exit;
        }
        $qty = $quantita !== '' ? (float) $quantita : null;
        if ($qty === null || $qty <= 0) {
            echo json_encode(['ok' => false, 'error' => 'La quantità deve essere maggiore di zero.']); exit;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT tipo FROM prodotti WHERE id = :id');
            $stmt->execute([':id' => $kit_id]);
            $prod = $stmt->fetch();
            if (!$prod || $prod['tipo'] !== 'kit') {
                echo json_encode(['ok' => false, 'error' => 'Il prodotto principale non è registrato come Kit nel database. Salva prima le modifiche del prodotto.']); exit;
            }

            // Upsert: elimina riga esistente e reinserisce
            $this->pdo->prepare(
                'DELETE FROM componenti_kit WHERE kit_id = :kit_id AND componente_id = :comp_id'
            )->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);

            $this->pdo->prepare(
                'INSERT INTO componenti_kit (kit_id, componente_id, quantita, note)
                 VALUES (:kit_id, :comp_id, :qty, :note)'
            )->execute([
                ':kit_id'  => $kit_id,
                ':comp_id' => $componente_id,
                ':qty'     => $qty,
                ':note'    => $note,
            ]);

            $row = $this->pdo->prepare(
                'SELECT ck.componente_id, p.nome, p.sku_interno, ck.quantita, ck.note
                   FROM componenti_kit ck
                   JOIN prodotti p ON p.id = ck.componente_id
                  WHERE ck.kit_id = :kit_id AND ck.componente_id = :comp_id'
            );
            $row->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);
            echo json_encode(['ok' => true, 'row' => $row->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore nel salvataggio.']);
        }
        exit;
    }

    /* ── API: elimina componente kit ──────────────────────────────────────── */

    public function deleteComponenteKit(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $kit_id       = (int) post('kit_id');
        $componente_id = (int) post('componente_id');
        if ($kit_id <= 0 || $componente_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM componenti_kit WHERE kit_id = :kit_id AND componente_id = :comp_id'
            )->execute([':kit_id' => $kit_id, ':comp_id' => $componente_id]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

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
                // D3.2 (PIANO Motore di Pricing Multi-UoM §8): quantita_minima_stock deve essere
                // valorizzato (0, coerente con "prezzo base") anche qui — senza, questa riga
                // sarebbe invisibile a getSalePrice() dalla Fase 3 in poi (NULL non è mai <= :qty).
                $prezzoBase = $toFloat($row['prezzo_vendita_base'] ?? null);
                if ($prezzoBase !== null) {
                    $this->pdo->prepare(
                        'INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock)
                         VALUES (1, :pid, 0, :prezzo, :um_id, 0, :um_id2, 0)'
                    )->execute([':pid' => $prodId, ':prezzo' => $prezzoBase, ':um_id' => $umId, ':um_id2' => $umId]);
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

    public function apiFornitoreDettagli(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $pfId = (int) get_param('prodotto_fornitore_id');
        if ($pfId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID fornitore prodotto non valido.']);
            exit;
        }

        try {
            // Ottiene il fornitore_id associato
            $stmtPf = $this->pdo->prepare('SELECT fornitore_id FROM prodotti_fornitori WHERE id = :id');
            $stmtPf->execute([':id' => $pfId]);
            $fornitoreId = (int) ($stmtPf->fetchColumn() ?? 0);

            // Carica tutti i prezzi a scaglione
            $stmtPrezzi = $this->pdo->prepare(
                'SELECT pfp.id, pfp.quantita_minima, pfp.prezzo, pfp.sconto_perc,
                        pfp.valido_dal, pfp.valido_al, pfp.note, pfp.listino_acquisto_id,
                        la.nome_listino
                 FROM prodotti_fornitori_prezzi pfp
                 LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
                 WHERE pfp.prodotto_fornitore_id = :pfid
                 ORDER BY pfp.listino_acquisto_id IS NULL DESC, la.nome_listino ASC, pfp.quantita_minima ASC'
            );
            $stmtPrezzi->execute([':pfid' => $pfId]);
            $prezzi = $stmtPrezzi->fetchAll(PDO::FETCH_ASSOC);

            // Carica tutti i listini per questo fornitore
            $listini = [];
            if ($fornitoreId > 0) {
                $stmtListini = $this->pdo->prepare(
                    'SELECT id, nome_listino 
                     FROM listini_acquisto 
                     WHERE fornitore_id = :fid 
                     ORDER BY nome_listino ASC'
                );
                $stmtListini->execute([':fid' => $fornitoreId]);
                $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['ok' => true, 'prezzi' => $prezzi, 'listini' => $listini]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: salva singola riga prezzo fornitore (insert o update) ──────── */

    public function savePrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');           // 0 = insert, > 0 = update
        $pfId       = (int) post('prodotto_fornitore_id');
        $prodottoId = (int) post('prodotto_id');  // per verifica ownership

        if ($pfId <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dati obbligatori mancanti.']); exit;
        }

        // Verifica che prodotto_fornitore_id appartenga davvero al prodotto (sicurezza)
        $stmtOwn = $this->pdo->prepare(
            'SELECT id, unita_misura_acquisto_id FROM prodotti_fornitori WHERE id = :pfid AND prodotto_id = :pid'
        );
        $stmtOwn->execute([':pfid' => $pfId, ':pid' => $prodottoId]);
        $prodForn = $stmtOwn->fetch();
        if (!$prodForn) {
            echo json_encode(['ok' => false, 'error' => 'Record non trovato o accesso non autorizzato.']); exit;
        }

        $n = fn(string $k): mixed => trim((string)(post($k) ?? '')) === '' ? null : post($k);

        $listinoId = $n('listino_acquisto_id') !== null ? (int) $n('listino_acquisto_id') : null;
        $qta       = $n('quantita_minima')     !== null ? (float) $n('quantita_minima')   : 0.0;
        $prezzo    = (float) (post('prezzo') ?? 0);
        $sconto    = $n('sconto_perc')         !== null ? (float) $n('sconto_perc')       : 0.0;
        $validoDal = $n('valido_dal');
        $validoAl  = $n('valido_al');
        $note      = $n('note');
        
        // Mantenimento della UoM Nativa di negoziazione nei listini di acquisto
        // Se non specificato esplicitamente, ereditiamo l'unità di acquisto definita sul fornitore
        $uomIdEsplicito = $n('unita_misura_id') !== null ? (int)$n('unita_misura_id') : null;
        $uomId     = $uomIdEsplicito ?? (int)$prodForn['unita_misura_acquisto_id'];

        // Validazione "terza unità" (14/07/2026, audit a tappeto): stesso principio di saveForn()
        // (D0.4, PIANO §5.6/§7.5) — se il chiamante specifica esplicitamente unita_misura_id, deve
        // essere l'unità di stock o quella commerciale del prodotto, mai un valore arbitrario.
        if ($uomIdEsplicito !== null) {
            $stmtUmProdCheck = $this->pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = :pid');
            $stmtUmProdCheck->execute([':pid' => $prodottoId]);
            $prodUmCheck = $stmtUmProdCheck->fetch(PDO::FETCH_ASSOC);
            $stockIdCheck = $prodUmCheck ? (int)$prodUmCheck['unita_misura_stock_id'] : 0;
            $commIdCheck  = ($prodUmCheck && $prodUmCheck['unita_contenuto_id'] !== null) ? (int)$prodUmCheck['unita_contenuto_id'] : null;
            if ($uomIdEsplicito !== $stockIdCheck && $uomIdEsplicito !== $commIdCheck) {
                echo json_encode(['ok' => false, 'error' => 'Unità di misura non valida: deve essere l\'unità di stock o l\'unità commerciale del prodotto.']);
                exit;
            }
        }

        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo non può essere negativo.']); exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto percentuale deve essere compreso tra 0 e 100.']); exit;
        }

        // D3.2 (PIANO Motore di Pricing Multi-UoM §8 Fase 1/3): questo endpoint scrive sulla stessa
        // tabella di PurchasesController::apiSaveRiga() — senza questo calcolo, le righe salvate qui
        // avrebbero quantita_minima_stock NULL e diventerebbero invisibili a getPurchasePrice()
        // (che dalla Fase 3 filtra su quantita_minima_stock, non più sul valore grezzo). Calcolato
        // PRIMA del controllo duplicati sotto, perché quel controllo ora confronta il valore canonico.
        $qtaIntento = $qta;
        $unitaSogliaId = $uomId ?: null;
        $qtaStock = $qta > 0
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaSogliaId, $qta, 0.0)['quantita_stock']
            : 0.0;

        // [Bug 3] Controllo sovrapposizione scaglioni
        // Corretto il 13/07/2026 (REVISIONE §6.15): "listino_acquisto_id = :listino_id" non trovava mai un
        // duplicato quando lo scaglione non è collegato a un listino (:listino_id NULL, il caso normale per
        // gli "Scaglioni Volume" inseriti nel drawer fornitore) — in SQL "colonna = NULL" non è mai vero,
        // nemmeno quando la colonna contiene davvero NULL. Aggiunta la gestione esplicita del caso NULL.
        // Corretto ULTERIORMENTE il 14/07/2026 (PIANO Motore di Pricing Multi-UoM §7.4/§8): confrontava
        // il valore grezzo (quantita_minima), lasciando passare "doppioni mascherati" (es. 100 KG e
        // 4 FUSTI con fattore 25 = stesso valore canonico) che poi avrebbero fatto fallire l'INSERT/UPDATE
        // contro il nuovo vincolo UNIQUE su quantita_minima_stock (D1.2) con un errore SQL grezzo invece
        // che con questo messaggio comprensibile. Ora confronta quantita_minima_stock.
        $stmtDup = $this->pdo->prepare("
            SELECT id FROM prodotti_fornitori_prezzi
            WHERE prodotto_fornitore_id = :pfid
              AND quantita_minima_stock = :qmin_stock
              AND id != :riga_id
              AND (listino_acquisto_id = :listino_id OR (:listino_id_null = 1 AND listino_acquisto_id IS NULL))
        ");
        $stmtDup->execute([
            ':listino_id'      => $listinoId,
            ':listino_id_null' => $listinoId === null ? 1 : 0,
            ':pfid'            => $pfId,
            ':qmin_stock'      => $qtaStock,
            ':riga_id'         => $id
        ]);
        if ($stmtDup->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).']);
            exit;
        }

        try {
            if ($id > 0) {
                // UPDATE — verifica ownership tramite prodotto_fornitore_id
                // modificato_manualmente_il: aggiornato sempre su un salvataggio manuale da questo drawer,
                // mai da apiImportPrezzi() — vedi REVISIONE §6.12.
                $this->pdo->prepare(
                    'UPDATE prodotti_fornitori_prezzi
                        SET listino_acquisto_id = :listino_id,
                            quantita_minima     = :qta,
                            prezzo              = :prezzo,
                            sconto_perc         = :sconto,
                            valido_dal          = :valido_dal,
                            valido_al           = :valido_al,
                            note                = :note,
                            unita_misura_id     = :uom_id,
                            quantita_minima_intento = :qta_intento,
                            unita_misura_soglia_id  = :um_soglia_id,
                            quantita_minima_stock   = :qta_stock,
                            modificato_manualmente_il = CURRENT_TIMESTAMP
                      WHERE id = :id
                        AND prodotto_fornitore_id = :pfid'
                )->execute([
                    ':listino_id' => $listinoId,
                    ':qta'        => $qta,
                    ':prezzo'     => $prezzo,
                    ':sconto'     => $sconto,
                    ':valido_dal' => $validoDal,
                    ':valido_al'  => $validoAl,
                    ':note'       => $note,
                    ':uom_id'     => $uomId,
                    ':qta_intento' => $qtaIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qta_stock'    => $qtaStock,
                    ':id'         => $id,
                    ':pfid'       => $pfId,
                ]);
            } else {
                // INSERT
                $this->pdo->prepare(
                    'INSERT INTO prodotti_fornitori_prezzi
                       (prodotto_fornitore_id, listino_acquisto_id, quantita_minima,
                        prezzo, sconto_perc, valido_dal, valido_al, note, unita_misura_id,
                        quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
                     VALUES
                       (:pfid, :listino_id, :qta, :prezzo, :sconto, :valido_dal, :valido_al, :note, :uom_id,
                        :qta_intento, :um_soglia_id, :qta_stock, CURRENT_TIMESTAMP)'
                )->execute([
                    ':qta_intento' => $qtaIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qta_stock'    => $qtaStock,
                    ':pfid'       => $pfId,
                    ':listino_id' => $listinoId,
                    ':qta'        => $qta,
                    ':prezzo'     => $prezzo,
                    ':sconto'     => $sconto,
                    ':valido_dal' => $validoDal,
                    ':valido_al'  => $validoAl,
                    ':note'       => $note,
                    ':uom_id'     => $uomId,
                ]);
                $id = (int) $this->pdo->lastInsertId();
            }

            // Restituisce la riga salvata (con nome_listino e UoM nativa per il rendering e la visualizzazione)
            $stmtRow = $this->pdo->prepare(
                'SELECT pfp.id, pfp.prodotto_fornitore_id, pfp.listino_acquisto_id,
                        pfp.quantita_minima, pfp.prezzo, pfp.sconto_perc,
                        pfp.valido_dal, pfp.valido_al, pfp.note, pfp.unita_misura_id,
                        la.nome_listino, um.codice AS unita_misura_codice
                   FROM prodotti_fornitori_prezzi pfp
              LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
              LEFT JOIN unita_misura um ON um.id = pfp.unita_misura_id
                  WHERE pfp.id = :id'
            );
            $stmtRow->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'row' => $stmtRow->fetch(PDO::FETCH_ASSOC)]);


        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => qk_prodotti_db_message($e)]);
        }
        exit;
    }

    /* ── API: elimina singola riga prezzo fornitore ───────────────────────── */

    public function deletePrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $id         = (int) post('id');
        $prodottoId = (int) post('prodotto_id'); // per verifica ownership

        if ($id <= 0 || $prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Record non specificato.']); exit;
        }

        try {
            // DELETE con verifica ownership via subquery su prodotti_fornitori
            $this->pdo->prepare(
                'DELETE FROM prodotti_fornitori_prezzi
                  WHERE id = :id
                    AND prodotto_fornitore_id IN (
                        SELECT id FROM prodotti_fornitori WHERE prodotto_id = :pid
                    )'
            )->execute([':id' => $id, ':pid' => $prodottoId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Errore durante la rimozione.']);
        }
        exit;
    }

    public function apiFornitoreListini(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $fornitoreId = (int) get_param('fornitore_id');
        if ($fornitoreId <= 0) {
            echo json_encode(['ok' => true, 'listini' => []]);
            exit;
        }

        try {
            $stmtListini = $this->pdo->prepare(
                'SELECT id, nome_listino
                 FROM listini_acquisto
                 WHERE fornitore_id = :fid
                 ORDER BY nome_listino ASC'
            );
            $stmtListini->execute([':fid' => $fornitoreId]);
            $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'listini' => $listini]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── Compliance prodotto ─────────────────────────────────────────────── */

    /**
     * GET /products/compliance/list?prodotto_id=X
     * Restituisce:
     *   - restrizioni attive sul prodotto
     *   - tutti i tipi di restrizione disponibili
     *   - suggerimenti da codice ONU (se il prodotto ha una classificazione ADR)
     */
    public function apiComplianceList(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $prodottoId = (int) get_param('prodotto_id');
        if ($prodottoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'prodotto_id mancante.']);
            exit;
        }

        try {
            // Restrizioni già assegnate al prodotto
            $stmtAssigned = $this->pdo->prepare(
                'SELECT pr.restriction_type_id, rt.code, rt.name, rt.regulatory_ref, pr.note, pr.created_at
                 FROM prodotti_restrizioni pr
                 JOIN compliance_restriction_types rt ON rt.id = pr.restriction_type_id
                 WHERE pr.prodotto_id = :pid
                 ORDER BY rt.name'
            );
            $stmtAssigned->execute([':pid' => $prodottoId]);
            $assegnate = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);

            // Tutti i tipi di restrizione attivi
            $stmtAll = $this->pdo->prepare(
                'SELECT id, code, name, regulatory_ref FROM compliance_restriction_types WHERE active = 1 ORDER BY name'
            );
            $stmtAll->execute();
            $tutti = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

            // Suggerimenti da codice ONU del prodotto (se ha classificazione ADR)
            $stmtSuggest = $this->pdo->prepare(
                'SELECT DISTINCT rt.id AS restriction_type_id, rt.code, rt.name, rt.regulatory_ref
                 FROM prodotti p
                 JOIN prodotto_classificazioni_adr pca ON pca.prodotto_id = p.id
                 JOIN compliance_codice_onu_restrizioni ccr ON ccr.un_code_id = pca.codice_onu_id
                 JOIN compliance_restriction_types rt ON rt.id = ccr.restriction_type_id
                 WHERE p.id = :pid AND rt.active = 1'
            );
            $stmtSuggest->execute([':pid' => $prodottoId]);
            $suggerimenti = $stmtSuggest->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok'           => true,
                'assegnate'    => $assegnate,
                'tutti'        => $tutti,
                'suggerimenti' => $suggerimenti,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /products/compliance/add
     * Body: prodotto_id, restriction_type_id, note, csrf_token
     */
    public function apiComplianceAdd(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $prodottoId        = (int) post('prodotto_id');
        $restrictionTypeId = (int) post('restriction_type_id');
        $note              = trim(post('note') ?? '');

        if ($prodottoId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi.']);
            exit;
        }

        try {
            $this->pdo->prepare(
                db_insert_ignore_sql('prodotti_restrizioni (prodotto_id, restriction_type_id, note)
                 VALUES (:pid, :rtid, :note)')
            )->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId, ':note' => $note ?: null]);

            // Restituisce la riga con il nome del tipo
            $row = $this->pdo->prepare(
                'SELECT pr.restriction_type_id, rt.code, rt.name, rt.regulatory_ref, pr.note, pr.created_at
                 FROM prodotti_restrizioni pr
                 JOIN compliance_restriction_types rt ON rt.id = pr.restriction_type_id
                 WHERE pr.prodotto_id = :pid AND pr.restriction_type_id = :rtid'
            );
            $row->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId]);

            echo json_encode(['ok' => true, 'restrizione' => $row->fetch(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /products/compliance/remove
     * Body: prodotto_id, restriction_type_id, csrf_token
     */
    public function apiComplianceRemove(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token CSRF non valido.']);
            exit;
        }

        $prodottoId        = (int) post('prodotto_id');
        $restrictionTypeId = (int) post('restriction_type_id');

        if ($prodottoId <= 0 || $restrictionTypeId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Parametri non validi.']);
            exit;
        }

        try {
            $this->pdo->prepare(
                'DELETE FROM prodotti_restrizioni WHERE prodotto_id = :pid AND restriction_type_id = :rtid'
            )->execute([':pid' => $prodottoId, ':rtid' => $restrictionTypeId]);

            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * POST /products/api/batch-associa-adr
     */
    public function apiBatchAssociaAdr(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']);
            exit;
        }

        $sostanzaId = (int) post('sostanza_id');
        $prodottoIds = (array) ($_POST['prodotto_ids'] ?? []);

        if (empty($prodottoIds)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun prodotto selezionato.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();
            if ($sostanzaId > 0) {
                $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = ?, is_adr = 1 WHERE id = ?');
                foreach ($prodottoIds as $pId) {
                    $stmt->execute([$sostanzaId, (int)$pId]);
                }
            } else {
                // Rimuove l'associazione
                $stmt = $this->pdo->prepare('UPDATE prodotti SET sostanza_chimica_id = NULL, is_adr = 0 WHERE id = ?');
                foreach ($prodottoIds as $pId) {
                    $stmt->execute([(int)$pId]);
                }
            }
            $this->pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
