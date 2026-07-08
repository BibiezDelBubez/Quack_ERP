<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';

/**
 * WarehouseController — modulo Magazzino.
 *
 * /warehouse               → panoramica giacenze
 * /warehouse/movements     → storico movimenti
 * /warehouse/movements/create → registra movimento manuale
 *
 * API interne:
 * /warehouse/api/prodotto-info   → GET: info prodotto + giacenza + lotti
 * /warehouse/api/prodotti-search → GET: ricerca prodotti per autocomplete
 */
class WarehouseController extends BaseController
{
    /* ── Dedicated Dashboard ──────────────────────────────────────────────── */

    public function dashboard(): void
    {
        // 1. Calcola il Valore Magazzino: Somma di (giacenze.quantita_disponibile * prezzo_acquisto)
        // Per semplicità, cerchiamo l'ultimo prezzo di acquisto inserito o il prezzo base in prodotti_fornitori_prezzi
        $valore_magazzino = (float) $this->pdo->query("
            SELECT SUM(g.quantita_disponibile * COALESCE(
                (SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp 
                 JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
                 WHERE pf.prodotto_id = g.prodotto_id AND pf.attivo = 1 AND pfp.quantita_minima = 0
                 ORDER BY pfp.id DESC LIMIT 1), 0.0
            )) 
            FROM giacenze g
        ")->fetchColumn();

        // 2. Conta i prodotti Sottoscorta NEL MAGAZZINO ATTIVO (la soglia è per
        //    coppia prodotto-magazzino, non più un valore unico di prodotto —
        //    vedi Piano Multi-Magazzino §3.5). Finché non esiste un selettore
        //    magazzino in UI (§3.3/§3.10), la dashboard generale mostra il
        //    sottoscorta della sede attiva di sessione/predefinita.
        $magazzinoAttivoId = qk_magazzino_attivo_id($this->pdo);
        $stSotto = $this->pdo->prepare("
            SELECT COUNT(*) FROM prodotti p
            JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
        ");
        $stSotto->execute([$magazzinoAttivoId]);
        $sottoscorta_count = (int) $stSotto->fetchColumn();

        // 3. Conta la merce in Arrivo (ordini acquisto pendenti)
        $in_arrivo_count = (int) $this->pdo->query("
            SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')
        ")->fetchColumn();

        // 4. Conta la merce in Partenza (spedizioni non ancora completate/consegnate)
        $in_partenza_count = (int) $this->pdo->query("
            SELECT COUNT(*) FROM spedizioni WHERE stato IN ('preparazione', 'spedita')
        ")->fetchColumn();

        // 5. Ultimi 5 movimenti logistici
        $stmtM = $this->pdo->query("
            SELECT m.created_at, m.tipo_movimento, m.quantita,
                   p.nome AS prodotto_nome, u.codice AS uom
            FROM movimenti_magazzino m
            JOIN prodotti p ON p.id = m.prodotto_id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            ORDER BY m.id DESC LIMIT 5
        ");
        $recent_movements = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // 6. Primi 5 prodotti vicini al sottoscorta nel magazzino attivo
        $stmtU = $this->pdo->prepare("
            SELECT p.nome, COALESCE(g.quantita_disponibile, 0) AS quantita_disponibile, g.scorta_minima, u.codice AS uom
            FROM prodotti p
            JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
            ORDER BY (COALESCE(g.quantita_disponibile, 0) - COALESCE(g.quantita_riservata, 0)) ASC
            LIMIT 5
        ");
        $stmtU->execute([$magazzinoAttivoId]);
        $understock_items = $stmtU->fetchAll(PDO::FETCH_ASSOC);

        $page_title    = 'Magazzino';
        $page_subtitle = 'Panoramica e KPI operativi';
        $breadcrumbs   = [['label' => 'Magazzino']];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/dashboard.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'recent_movements', 'understock_items', 'valore_magazzino',
            'sottoscorta_count', 'in_arrivo_count', 'in_partenza_count'
        ));
    }

    /* ── Stock list ───────────────────────────────────────────────────────── */

    public function stock(): void
    {
        $search = get_param('q');
        $filter = get_param('filter');
        if (!in_array($filter, ['in_stock', 'all', 'sottoscorta', 'esauriti', 'in_arrivo'], true)) {
            $filter = 'in_stock'; // default
        }

        $stock = [];
        $ordini_in_arrivo = [];

        $nInArrivo = (int) $this->pdo->query("SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')")->fetchColumn();

        if ($filter === 'in_arrivo') {
            $sql = "SELECT ot.id, ot.numero, ot.data_ordine, ot.data_consegna_prevista, ot.stato,
                           COALESCE(cf.ragione_sociale, cf.nome || ' ' || COALESCE(cf.cognome, '')) AS fornitore_nome,
                           (SELECT rt.id FROM ricezioni_testata rt WHERE rt.ordine_id = ot.id AND rt.stato = 'bozza' LIMIT 1) AS draft_ricezione_id
                      FROM ordini_testata ot
                 LEFT JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
                     WHERE ot.stato IN ('inviato', 'parzialmente_ricevuto')
                       AND (ot.numero LIKE :q OR COALESCE(cf.ragione_sociale, cf.nome) LIKE :q)
                  ORDER BY ot.data_consegna_prevista ASC, ot.created_at DESC";
            $st = $this->pdo->prepare($sql);
            $st->execute(['q' => '%' . $search . '%']);
            $ordini_in_arrivo = $st->fetchAll(PDO::FETCH_ASSOC);
            $total = count($ordini_in_arrivo);
            $pag   = ['page' => 1, 'limit' => 9999, 'offset' => 0, 'sort' => 'data', 'dir' => 'asc'];
        } else {
            $pag   = qk_pagination_params(['nome','sku','disponibile','libera','riservata','in_arrivo','scorta'], 'nome', 'asc');
            $total = qk_stock_count($this->pdo, $search, $filter);
            $stock = qk_stock_list($this->pdo, $search, $filter, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
        }

        $page_title    = 'Giacenze Magazzino';
        $page_subtitle = 'Situazione disponibilità e giacenze';
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Giacenze']];
        $page_actions  = '<a href="' . url('warehouse/movements/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo movimento
        </a>';
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/stock.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'page_actions', 'extra_css',
            'search', 'filter', 'stock', 'ordini_in_arrivo', 'nInArrivo', 'total', 'pag'
        ));
    }

    /* ── Storico movimenti ────────────────────────────────────────────────── */

    public function movements(): void
    {
        $search      = get_param('q', '');
        $tipo        = get_param('tipo', '');
        $dal         = get_param('dal', '');
        $al          = get_param('al', '');
        $prodotto_id = (int) get_param('prodotto_id', '0');

        $tipiValidi = ['', 'carico', 'scarico', 'rettifica', 'reso_cliente', 'reso_fornitore', 'riserva', 'svincolo'];
        if (!in_array($tipo, $tipiValidi, true)) $tipo = '';

        $sortWhitelist = ['data', 'tipo', 'prodotto', 'qta'];
        $pag = qk_pagination_params($sortWhitelist, 'data', 'desc');

        $result = qk_movements_list($this->pdo, $search, $tipo, $prodotto_id, $pag['limit'], $pag['offset'], $dal, $al, $pag['sort'], $pag['dir']);
        $movements = $result['rows'];
        $total     = $result['total'];
        $base      = url('warehouse/movements');

        $page_title    = 'Movimenti magazzino';
        $page_subtitle = 'Storico movimenti inventario';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Movimenti'],
        ];
        $page_actions  = '<a href="' . url('warehouse/movements/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo movimento
        </a>';
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/movements.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'page_actions', 'extra_css',
            'search', 'tipo', 'dal', 'al', 'prodotto_id', 'movements', 'total', 'base', 'pag'
        ));
    }

    /* ── Physical Inventory Sessions ──────────────────────────────────────── */

    public function inventari(): void
    {
        $base = url('warehouse/inventories');

        // ── Parametri ────────────────────────────────────────────────────────
        $search = trim(filter_input(INPUT_GET, 'q',     FILTER_DEFAULT) ?? '');
        $stato  = trim(filter_input(INPUT_GET, 'stato', FILTER_DEFAULT) ?? '');

        $sortColMap = [
            'id'      => 'i.id',
            'data'    => 'i.data_inventario',
            'stato'   => 'i.stato',
            'creatore'=> 'u.username',
        ];
        $pag     = qk_pagination_params(array_keys($sortColMap), 'id', 'desc');
        $sort    = $pag['sort'];
        $dir     = $pag['dir'];
        $page    = $pag['page'];
        $limit   = $pag['limit'];
        $offset  = $pag['offset'];
        $sortCol = $sortColMap[$sort];

        // ── WHERE dinamico ───────────────────────────────────────────────────
        $where  = [];
        $params = [];
        if ($search !== '') {
            $where[]  = "(u.username LIKE ? OR i.note LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if (in_array($stato, ['bozza', 'convalidato'], true)) {
            $where[]  = "i.stato = ?";
            $params[] = $stato;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtC = $this->pdo->prepare("SELECT COUNT(*) FROM inventari i JOIN utenti u ON u.id = i.creato_da " . $whereSql);
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();

        $stmtQ = $this->pdo->prepare(
            "SELECT i.*, u.username AS creatore_username
             FROM inventari i
             JOIN utenti u ON u.id = i.creato_da
             " . $whereSql . "
             ORDER BY " . $sortCol . " " . $dir . "
             LIMIT " . $limit . " OFFSET " . $offset
        );
        $stmtQ->execute($params);
        $inventari = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        $page_title    = 'Inventario Fisico';
        $page_subtitle = 'Gestione e convalida dei conteggi fisici';
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Inventario fis.']];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/inventories_list.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'search', 'stato', 'inventari', 'total', 'base', 'pag'
        ));
    }

    public function createInventario(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $errors = [];
        $inventario = null;
        $values = [
            'data_inventario' => date('Y-m-d'),
            'note' => '',
            'righe' => []
        ];

        // Un inventario fisico è per un magazzino specifico (giacenza_teorica
        // e le rettifiche generate sono scoping-ate a quella sede — vedi Piano
        // Multi-Magazzino §3.4/§4.9). Finché non esiste un selettore magazzino
        // dedicato al form inventario, un inventario nuovo usa il magazzino
        // attivo; uno esistente mantiene il proprio (letto sotto da $inventario).
        $magazzinoInventarioId = qk_magazzino_attivo_id($this->pdo);

        // Carica dati se stiamo modificando/visualizzando
        if ($id) {
            $stmt = $this->pdo->prepare("SELECT * FROM inventari WHERE id = ?");
            $stmt->execute([$id]);
            $inventario = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inventario) {
                redirect_with('warehouse/inventories', 'error', 'Sessione inventario non trovata.');
            }
            $values['data_inventario'] = $inventario['data_inventario'];
            $values['note'] = $inventario['note'];
            if (!empty($inventario['magazzino_id'])) {
                $magazzinoInventarioId = (int) $inventario['magazzino_id'];
            }

            // Righe esistenti dell'inventario
            $stmtR = $this->pdo->prepare("
                SELECT ir.*, p.nome AS nome_prodotto, p.sku_interno, u.codice AS uom, l.codice_lotto_interno
                FROM inventari_righe ir
                JOIN prodotti p ON p.id = ir.prodotto_id
                LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
                LEFT JOIN lotti l ON l.id = ir.lotto_id
                WHERE ir.inventario_id = ?
            ");
            $stmtR->execute([$id]);
            $prodotti_inventario = $stmtR->fetchAll(PDO::FETCH_ASSOC);

            foreach ($prodotti_inventario as $p) {
                $key = $p['prodotto_id'] . ':' . ($p['lotto_id'] ?? '');
                $values['righe'][$key] = (string)$p['quantita_contata'];
            }
        } else {
            // Nuova sessione: prendiamo tutti i prodotti con le giacenze teoriche
            // NEL MAGAZZINO di questo inventario. Se un prodotto gestisce lotti,
            // splittiamo le giacenze teoriche per lotto (lotti_giacenze, per
            // sede — vedi §3.1), non più sul campo aggregato lotti.quantita_disponibile.
            $sql = "
                SELECT p.id AS prodotto_id, p.nome AS nome_prodotto, p.sku_interno, u.codice AS uom,
                       l.id AS lotto_id, l.codice_lotto_interno,
                       CASE WHEN p.traccia_lotti = 1 THEN COALESCE(lg.quantita_disponibile, 0)
                            ELSE COALESCE(g.quantita_disponibile, 0)
                       END AS giacenza_teorica
                FROM prodotti p
                LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
                LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
                LEFT JOIN lotti l ON l.prodotto_id = p.id AND (p.traccia_lotti = 1)
                LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
                WHERE p.attivo = 1 AND p.tipo != 'kit'
                ORDER BY p.nome COLLATE NOCASE, l.codice_lotto_interno ASC
            ";
            $stmtInv = $this->pdo->prepare($sql);
            $stmtInv->execute([$magazzinoInventarioId, $magazzinoInventarioId]);
            $prodotti_inventario = $stmtInv->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($inventario && $inventario['stato'] === 'convalidato') {
                $errors['_global'] = 'L\'inventario è convalidato e non può più essere modificato.';
            } else if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza scaduto o non valido.';
            } else {
                $azione = post('azione', 'salva'); // 'salva' o 'convalida'
                $data_inventario = post('data_inventario', date('Y-m-d'));
                $note = post('note', '');
                $inputRighe = $_POST['righe'] ?? [];

                if (empty($data_inventario)) {
                    $errors['data_inventario'] = 'La data di rilevamento è obbligatoria.';
                }

                if (empty($errors)) {
                    try {
                        $this->pdo->beginTransaction();
                        $utente_id = (int)(current_user()['id'] ?? 0);

                        if (!$id) {
                            // Inserisci testata (magazzino fissato alla creazione, non più cambiabile dopo)
                            $stmt = $this->pdo->prepare("
                                INSERT INTO inventari (data_inventario, magazzino_id, note, creato_da, stato)
                                VALUES (?, ?, ?, ?, 'bozza')
                            ");
                            $stmt->execute([$data_inventario, $magazzinoInventarioId, $note, $utente_id]);
                            $id = (int)$this->pdo->lastInsertId();
                        } else {
                            // Aggiorna testata
                            $stmt = $this->pdo->prepare("
                                UPDATE inventari SET data_inventario = ?, note = ? WHERE id = ?
                            ");
                            $stmt->execute([$data_inventario, $note, $id]);
                            // Rimuovi vecchie righe temporaneamente
                            $this->pdo->prepare("DELETE FROM inventari_righe WHERE inventario_id = ?")->execute([$id]);
                        }

                        // Reinserisci righe
                        $stmtIns = $this->pdo->prepare("
                            INSERT INTO inventari_righe (inventario_id, prodotto_id, lotto_id, giacenza_teorica, quantita_contata)
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        foreach ($prodotti_inventario as $p) {
                            $key = $p['prodotto_id'] . ':' . ($p['lotto_id'] ?? '');
                            $contata = isset($inputRighe[$key]) ? (float)str_replace(',', '.', $inputRighe[$key]) : (float)$p['giacenza_teorica'];
                            $lottoId = $p['lotto_id'] ? (int)$p['lotto_id'] : null;

                            $stmtIns->execute([$id, $p['prodotto_id'], $lottoId, $p['giacenza_teorica'], $contata]);
                        }

                        // Convalida: genera i movimenti logistici e allinea le giacenze
                        if ($azione === 'convalida') {
                            // Recupera le righe appena salvate
                            $stmtRighe = $this->pdo->prepare("SELECT * FROM inventari_righe WHERE inventario_id = ?");
                            $stmtRighe->execute([$id]);
                            $righeSalvate = $stmtRighe->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($righeSalvate as $riga) {
                                $delta = (float)$riga['quantita_contata'] - (float)$riga['giacenza_teorica'];
                                if (abs($delta) < 0.0001) {
                                    continue; // nessuna rettifica necessaria
                                }

                                $tipoMovimento = $delta > 0 ? 'carico' : 'scarico';
                                $quantitaAssoluta = abs($delta);

                                // 1. Crea il movimento di magazzino, per QUESTO magazzino
                                $stmtMov = $this->pdo->prepare("
                                    INSERT INTO movimenti_magazzino
                                    (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita, documento_tipo, documento_id, canale_origine, note, creato_da)
                                    VALUES (?, ?, ?, 'rettifica', ?, 'inventario', ?, 'manuale', ?, ?)
                                ");
                                $noteMov = sprintf("Rettifica da inventario #%d. Scostamento: %+.3f", $id, $delta);
                                $stmtMov->execute([
                                    $riga['prodotto_id'],
                                    $riga['lotto_id'],
                                    $magazzinoInventarioId,
                                    $quantitaAssoluta,
                                    $id,
                                    $noteMov,
                                    $utente_id
                                ]);
                                $movimentoId = (int)$this->pdo->lastInsertId();

                                // Associa il movimento alla riga
                                $this->pdo->prepare("UPDATE inventari_righe SET movimento_id = ? WHERE id = ?")->execute([$movimentoId, $riga['id']]);

                                // 2. Aggiorna la giacenza di QUESTO magazzino
                                $stGiac = $this->pdo->prepare("SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");
                                $stGiac->execute([$riga['prodotto_id'], $magazzinoInventarioId]);
                                $giac = $stGiac->fetch(PDO::FETCH_ASSOC);

                                if ($giac) {
                                    $nuovaQta = (float)$giac['quantita_disponibile'] + $delta;
                                    $this->pdo->prepare("UPDATE giacenze SET quantita_disponibile = ?, updated_at = datetime('now') WHERE id = ?")
                                              ->execute([$nuovaQta, $giac['id']]);
                                } else {
                                    $this->pdo->prepare("INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                                                         VALUES (?, ?, ?, 0, 0, 0, datetime('now'))")
                                              ->execute([$riga['prodotto_id'], $magazzinoInventarioId, $delta]);
                                }

                                // 3. Aggiorna quantità disponibile del lotto in QUESTO magazzino
                                //    (lotti_giacenze, non più il campo aggregato lotti.quantita_disponibile)
                                if ($riga['lotto_id']) {
                                    $this->pdo->prepare("
                                        INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
                                        VALUES (?, ?, ?, datetime('now'))
                                        ON CONFLICT(lotto_id, magazzino_id) DO UPDATE SET
                                            quantita_disponibile = quantita_disponibile + excluded.quantita_disponibile,
                                            updated_at = excluded.updated_at
                                    ")->execute([$riga['lotto_id'], $magazzinoInventarioId, $delta]);
                                }
                            }

                            // Segna inventario come convalidato
                            $this->pdo->prepare("UPDATE inventari SET stato = 'convalidato' WHERE id = ?")->execute([$id]);
                        }

                        $this->pdo->commit();
                        $msg = $azione === 'convalida' ? 'Inventario convalidato e rettifiche applicate con successo.' : 'Bozza inventario salvata con successo.';
                        redirect_with('warehouse/inventories', 'success', $msg);

                    } catch (\Throwable $e) {
                        $this->pdo->rollBack();
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $isConvalidato = $inventario && $inventario['stato'] === 'convalidato';
        $page_title    = $id ? ($isConvalidato ? 'Dettaglio Inventario' : 'Modifica Inventario') : 'Nuovo Inventario Fisico';
        $page_subtitle = 'Gestione conteggio giacenze fisiche';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Inventario fis.', 'url' => url('warehouse/inventories')],
            ['label' => $id ? '#' . $id : 'Nuovo']
        ];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/inventories_form.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'id', 'errors', 'inventario', 'values', 'prodotti_inventario', 'isConvalidato'
        ));
    }

    /* ── Reorder Proposals ────────────────────────────────────────────────── */

    public function reorder(): void
    {

        $base   = url('warehouse/reorder');
        $search = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');

        // Il riordino è per il magazzino attivo: la soglia (giacenze.scorta_minima)
        // e la giacenza libera sono per coppia (prodotto, magazzino), non più un
        // valore unico di prodotto — vedi Piano Multi-Magazzino §3.5.
        $magazzinoAttivoId = qk_magazzino_attivo_id($this->pdo);

        $sortColMap = [
            'nome'     => 'p.nome',
            'libera'   => '(COALESCE(g.quantita_disponibile,0) - COALESCE(g.quantita_riservata,0))',
            'minima'   => 'g.scorta_minima',
            'arrivo'   => 'COALESCE(g.quantita_in_arrivo,0)',
            'fornitore'=> 'fornitore_nome',
        ];
        $pag     = qk_pagination_params(array_keys($sortColMap), 'nome', 'asc');
        $sort    = $pag['sort'];
        $dir     = $pag['dir'];
        $page    = $pag['page'];
        $limit   = $pag['limit'];
        $offset  = $pag['offset'];
        $sortCol = $sortColMap[$sort];

        $whereExtra = '';
        $params     = [$magazzinoAttivoId];
        if ($search !== '') {
            $whereExtra = 'AND (p.nome LIKE ? OR p.sku_interno LIKE ?)';
            $params[]   = "%$search%";
            $params[]   = "%$search%";
        }

        $baseSql =
            "FROM prodotti p
             JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
             LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
             LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.ordine_preferenza_fornitore = 1 AND pf.attivo = 1
             LEFT JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
             WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
               " . $whereExtra;

        $stmtC = $this->pdo->prepare("SELECT COUNT(*) " . $baseSql);
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();

        $stmtQ = $this->pdo->prepare(
            "SELECT p.id, p.nome, p.sku_interno, u.codice AS uom,
                    COALESCE(g.quantita_disponibile, 0) AS qta_disponibile,
                    COALESCE(g.quantita_riservata, 0) AS qta_riservata,
                    COALESCE(g.quantita_in_arrivo, 0) AS qta_in_arrivo,
                    (COALESCE(g.quantita_disponibile, 0) - COALESCE(g.quantita_riservata, 0)) AS qta_libera,
                    g.scorta_minima,
                    pf.fornitore_id,
                    COALESCE(cf.ragione_sociale, cf.nome || ' ' || COALESCE(cf.cognome, '')) AS fornitore_nome,
                    (SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp
                     WHERE pfp.prodotto_fornitore_id = pf.id AND pfp.quantita_minima = 0
                     ORDER BY pfp.id DESC LIMIT 1) AS ultimo_prezzo
             " . $baseSql . "
             ORDER BY " . $sortCol . " COLLATE NOCASE " . $dir . "
             LIMIT " . $limit . " OFFSET " . $offset
        );
        $stmtQ->execute($params);
        $proposals = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        $pag = compact('page', 'limit', 'sort', 'dir');

        $page_title    = 'Proposte di Riordino';
        $page_subtitle = 'Generazione ordini e RFQ per articoli sottoscorta';
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Riordino']];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/reorder.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'search', 'proposals', 'total', 'base', 'pag'
        ));
    }

    /* ── Crea movimento ───────────────────────────────────────────────────── */

    public function createMovement(): void
    {
        $errors = [];
        $values = [
            'tipo_movimento'               => 'carico',
            'prodotto_id'                  => '',
            'quantita'                     => '',
            'lotto_mode'                   => 'esistente',
            'lotto_id'                     => '',
            'nuovo_lotto_codice_interno'   => '',
            'nuovo_lotto_codice_fornitore' => '',
            'nuovo_lotto_scadenza'         => '',
            'nuovo_lotto_arrivo'           => '',
            'note'                         => '',
            'riferimento_esterno'          => '',
            'documento_tipo'               => '',
            'documento_id'                 => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values['tipo_movimento']      = post('tipo_movimento', 'carico');
                $values['prodotto_id']         = post('prodotto_id', '');
                $values['quantita']            = post('quantita', '');
                $values['lotto_mode']          = post('lotto_mode', 'esistente'); // 'esistente' | 'nuovo'
                $values['lotto_id']            = post('lotto_id', '');
                $values['nuovo_lotto_codice_interno']   = post('nuovo_lotto_codice_interno', '');
                $values['nuovo_lotto_codice_fornitore'] = post('nuovo_lotto_codice_fornitore', '');
                $values['nuovo_lotto_scadenza']         = post('nuovo_lotto_scadenza', '');
                $values['nuovo_lotto_arrivo']           = post('nuovo_lotto_arrivo', '');
                $values['note']                = post('note', '');
                $values['riferimento_esterno'] = post('riferimento_esterno', '');
                $values['documento_tipo']      = post('documento_tipo', '');
                $values['documento_id']        = post('documento_id', '');

                // Validazione
                $tipiValidi = ['carico', 'scarico', 'rettifica'];
                if (!in_array($values['tipo_movimento'], $tipiValidi, true)) {
                    $errors['tipo_movimento'] = 'Tipo movimento non valido.';
                }
                if ($values['prodotto_id'] === '' || (int) $values['prodotto_id'] <= 0) {
                    $errors['prodotto_id'] = 'Seleziona un prodotto.';
                }

                $qtaStr = str_replace(',', '.', $values['quantita']);
                if ($qtaStr === '' || !is_numeric($qtaStr)) {
                    $errors['quantita'] = 'Inserisci una quantità valida.';
                } elseif ($values['tipo_movimento'] !== 'rettifica' && (float) $qtaStr <= 0) {
                    $errors['quantita'] = 'La quantità deve essere maggiore di zero.';
                } elseif ($values['tipo_movimento'] === 'rettifica' && (float) $qtaStr < 0) {
                    $errors['quantita'] = 'La nuova giacenza non può essere negativa.';
                }

                // Validazione lotti per prodotti tracciati
                if (empty($errors['prodotto_id'])) {
                    $info = qk_prodotto_stock_info($this->pdo, (int) $values['prodotto_id']);
                    
                    if ($info && ($info['tipo'] ?? '') === 'kit') {
                        $errors['prodotto_id'] = 'Impossibile movimentare manualmente un Kit. Le giacenze dei Kit sono calcolate automaticamente in base ai loro componenti.';
                    }

                    if ($info && $info['traccia_lotti'] && empty($errors['prodotto_id'])) {
                        if ($values['tipo_movimento'] === 'carico' && $values['lotto_mode'] === 'nuovo') {
                            // Nuovo lotto: codice interno obbligatorio
                            if (trim($values['nuovo_lotto_codice_interno']) === '') {
                                $errors['nuovo_lotto_codice_interno'] = 'Il codice del lotto è obbligatorio.';
                            }
                        } elseif (empty($values['lotto_id'])) {
                            // Lotto esistente (carico su esistente, scarico, rettifica): selezione obbligatoria
                            $errors['lotto_id'] = 'Seleziona il lotto.';
                        }
                    }
                }

                if (empty($errors)) {
                    try {
                        $utente_id = (int) (current_user()['id'] ?? 0);

                        // Costruisce il payload per qk_insert_movimento_manuale.
                        // magazzino_id: finché il form non espone un selettore
                        // dedicato (§3.10), il movimento manuale si registra sempre
                        // sul magazzino attivo di sessione/predefinito.
                        $movData = [
                            'tipo_movimento'      => $values['tipo_movimento'],
                            'prodotto_id'         => (int) $values['prodotto_id'],
                            'magazzino_id'        => qk_magazzino_attivo_id($this->pdo),
                            'quantita'            => (float) $qtaStr,
                            'lotto_id'            => $values['lotto_id'] !== '' ? (int) $values['lotto_id'] : null,
                            'note'                => $values['note'],
                            'riferimento_esterno' => $values['riferimento_esterno'],
                            'documento_tipo'      => $values['documento_tipo'],
                            'documento_id'        => $values['documento_id'],
                        ];

                        // Se carico con nuovo lotto, aggiunge i dati del lotto da creare
                        if ($values['tipo_movimento'] === 'carico' && $values['lotto_mode'] === 'nuovo') {
                            $movData['nuovo_lotto'] = [
                                'codice_interno'   => $values['nuovo_lotto_codice_interno'],
                                'codice_fornitore' => $values['nuovo_lotto_codice_fornitore'],
                                'data_scadenza'    => $values['nuovo_lotto_scadenza'],
                                'data_arrivo'      => $values['nuovo_lotto_arrivo'],
                            ];
                            $movData['lotto_id'] = null; // verrà valorizzato in qk_insert_movimento_manuale
                        }

                        $id = qk_insert_movimento_manuale($this->pdo, $movData, $utente_id);

                        $tipoLabel = [
                            'carico'    => 'Carico',
                            'scarico'   => 'Scarico',
                            'rettifica' => 'Rettifica',
                        ][$values['tipo_movimento']] ?? 'Movimento';

                        redirect_with(
                            'warehouse/movements',
                            'success',
                            $tipoLabel . ' registrato correttamente (movimento #' . $id . ').'
                        );
                    } catch (\RuntimeException $e) {
                        $errors['quantita'] = $e->getMessage();
                    } catch (\Throwable $e) {
                        $errors['_global'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $page_title    = 'Nuovo movimento';
        $page_subtitle = 'Registra carico, scarico o rettifica manuale';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Movimenti', 'url' => url('warehouse/movements')],
            ['label' => 'Nuovo'],
        ];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/_movement_form.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'errors', 'values'
        ));
    }

    /* ── API: info prodotto per JS ────────────────────────────────────────── */

    public function apiProdottoInfo(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'id mancante']);
            exit;
        }
        $info  = qk_prodotto_stock_info($this->pdo, $id);
        // Restituisce TUTTI i lotti (anche esauriti); il JS filtra in base al tipo operazione
        $lotti = $info && $info['traccia_lotti']
            ? qk_lotti_by_prodotto($this->pdo, $id, false)
            : [];
        ob_end_clean();
        echo json_encode(['ok' => true, 'prodotto' => $info, 'lotti' => $lotti]);
        exit;
    }

    public function apiProdottiSearch(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $q = get_param('q');
        if (strlen($q) < 1) {
            ob_end_clean();
            echo json_encode(['ok' => true, 'prodotti' => []]);
            exit;
        }
        $rows = qk_prodotti_search_stock($this->pdo, $q);
        ob_end_clean();
        echo json_encode(['ok' => true, 'prodotti' => $rows]);
        exit;
    }
}
