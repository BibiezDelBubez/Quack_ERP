<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/trasferimenti.php';

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
        // 1. Calcola il Valore Magazzino: Somma di (giacenze.quantita_disponibile * costo_acquisto)
        // Da v_costo_acquisto_prodotti (fix "Listino Fantasma", audit/MASTER_AUDIT_REPORT.md)
        // — vedi commento esteso in db/init_sqlite.sql §14b.
        $valore_magazzino = (float) $this->pdo->query("
            SELECT SUM(g.quantita_disponibile * COALESCE(vc.costo_acquisto, 0.0))
            FROM giacenze g
            LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = g.prodotto_id
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

        // Fix 2 (Magazzino Attivo - Analisi e Alternative.md, §8.3): filtro
        // magazzino sempre esplicito in pagina, mai un'aggregazione silenziosa.
        // '' → default comodo sul magazzino attivo; '0' → scelta esplicita
        // "Tutti i magazzini" (aggregato SUM, §8.0); intero → sede specifica,
        // validata attiva altrimenti si ricade sul default.
        $magazzinoParam = get_param('magazzino_id', '');
        if ($magazzinoParam === '') {
            $magazzinoId = qk_magazzino_attivo_id($this->pdo);
        } elseif ($magazzinoParam === '0') {
            $magazzinoId = 0;
        } else {
            $candidato = (int) $magazzinoParam;
            $stChk = $this->pdo->prepare('SELECT id FROM magazzini WHERE id = ? AND attivo = 1');
            $stChk->execute([$candidato]);
            $magazzinoId = $stChk->fetchColumn() ? $candidato : qk_magazzino_attivo_id($this->pdo);
        }
        // Opzione A (§9.5): una scelta esplicita di sede su questa pagina
        // aggiorna la sessione come effetto collaterale, così resta "ricordata"
        // per precompilare le altre schermate — non esiste più un selettore
        // dedicato in topbar per farlo in astratto.
        if ($magazzinoId > 0) {
            $_SESSION['magazzino_attivo_id'] = $magazzinoId;
        }
        $magazzini = qk_magazzini_list($this->pdo, true);

        $stock = [];
        $ordini_in_arrivo = [];

        $nInArrivo = (int) $this->pdo->query("SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')")->fetchColumn();

        if ($filter === 'in_arrivo') {
            // Decisione presa (§8.3, opzione a): questa query resta volutamente
            // NON filtrata per sede — mostra sempre tutti gli ordini d'acquisto
            // pendenti dell'azienda, indipendentemente dal magazzino scelto sopra.
            // Segnalato esplicitamente in UI (stock.php) per non farlo sembrare
            // un'incoerenza silenziosa rispetto agli altri filtri.
            $st = $this->pdo->prepare("
                SELECT ot.id, ot.numero, ot.data_ordine, ot.data_consegna_prevista, ot.stato,
                       COALESCE(cf.ragione_sociale, cf.nome || ' ' || COALESCE(cf.cognome, '')) AS fornitore_nome,
                       (SELECT rt.id FROM ricezioni_testata rt WHERE rt.ordine_id = ot.id AND rt.stato = 'bozza' LIMIT 1) AS draft_ricezione_id
                  FROM ordini_testata ot
             LEFT JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
                 WHERE ot.stato IN ('inviato', 'parzialmente_ricevuto')
                   AND (ot.numero LIKE :q OR COALESCE(cf.ragione_sociale, cf.nome) LIKE :q)
              ORDER BY ot.data_consegna_prevista ASC, ot.created_at DESC
            ");
            $st->execute(['q' => '%' . $search . '%']);
            $ordini_in_arrivo = $st->fetchAll(PDO::FETCH_ASSOC);
            $total = count($ordini_in_arrivo);
            $pag   = ['page' => 1, 'limit' => 9999, 'offset' => 0, 'sort' => 'data', 'dir' => 'asc'];
        } else {
            $pag   = qk_pagination_params(['nome','sku','disponibile','libera','riservata','in_arrivo','scorta'], 'nome', 'asc');
            $total = qk_stock_count($this->pdo, $search, $filter, $magazzinoId);
            $stock = qk_stock_list($this->pdo, $search, $filter, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset'], $magazzinoId);
        }

        $nomeMagazzinoScelto = 'tutti i magazzini';
        if ($magazzinoId !== 0) {
            foreach ($magazzini as $mg) {
                if ((int) $mg['id'] === $magazzinoId) {
                    $nomeMagazzinoScelto = $mg['nome'];
                    break;
                }
            }
        }

        $page_title    = 'Giacenze Magazzino';
        $page_subtitle = 'Situazione disponibilità e giacenze — ' . $nomeMagazzinoScelto;
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Giacenze']];
        $page_actions  = '<a href="' . url('warehouse/movements/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo movimento
        </a>';
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/stock.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'page_actions', 'extra_css',
            'search', 'filter', 'stock', 'ordini_in_arrivo', 'nInArrivo', 'total', 'pag',
            'magazzini', 'magazzinoId'
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

        // Cycle counting (Tappa 4.3): un nuovo inventario può essere ristretto a
        // una categoria invece di coprire sempre l'intero magazzino — best
        // practice per aziende medio-piccole (conteggi a rotazione frequenti +
        // un conteggio completo periodico, invece del solo conteggio completo
        // che ferma tutto ogni volta). Un inventario esistente mantiene lo
        // scope fissato alla creazione (letto sotto da $inventario).
        // Letto sia da GET (reload della select "Ambito conteggio" prima del
        // submit) sia da POST (hidden field nel form, valorizzato dalla view:
        // senza questo la scelta della categoria andrebbe persa al salvataggio,
        // perché il form fa POST sulla stessa action senza query string).
        $categoriaFiltroId = null;
        if (!$id) {
            // 0/assente = "tutti i prodotti" (nessun filtro): usiamo
            // esplicitamente is_int()+>0 invece di ?: per non confondere lo
            // 0 legittimo dell'hidden field con "valore assente".
            $catPost = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT);
            $catGet = filter_input(INPUT_GET, 'categoria_id', FILTER_VALIDATE_INT);
            if (is_int($catPost) && $catPost > 0) {
                $categoriaFiltroId = $catPost;
            } elseif (is_int($catGet) && $catGet > 0) {
                $categoriaFiltroId = $catGet;
            }
        }
        $categorie = $this->pdo->query('SELECT id, nome FROM categorie_prodotto ORDER BY nome COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);

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
            $categoriaFiltroId = !empty($inventario['categoria_filtro_id']) ? (int) $inventario['categoria_filtro_id'] : null;

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
            // Nuova sessione: prendiamo i prodotti con le giacenze teoriche NEL
            // MAGAZZINO di questo inventario — tutti, oppure solo quelli della
            // categoria scelta se è un cycle count (Tappa 4.3). Se un prodotto
            // gestisce lotti, splittiamo le giacenze teoriche per lotto
            // (lotti_giacenze, per sede — vedi §3.1), non più sul campo
            // aggregato lotti.quantita_disponibile.
            $condCategoria = $categoriaFiltroId ? 'AND p.categoria_id = ?' : '';
            $paramsInv = [$magazzinoInventarioId, $magazzinoInventarioId];
            if ($categoriaFiltroId) {
                $paramsInv[] = $categoriaFiltroId;
            }
            $stmtInv = $this->pdo->prepare("
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
                WHERE p.attivo = 1 AND p.tipo != 'kit' $condCategoria
                ORDER BY p.nome COLLATE NOCASE, l.codice_lotto_interno ASC
            ");
            $stmtInv->execute($paramsInv);
            $prodotti_inventario = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

            // Guardia (Tappa 4.3): nessun'altra bozza già aperta su questo
            // magazzino deve coprire uno di questi stessi prodotti — due cycle
            // count su categorie diverse possono coesistere, ma non su prodotti
            // in comune (altrimenti si "pestano i piedi" a vicenda).
            if (!empty($prodotti_inventario)) {
                $prodIds = array_values(array_unique(array_column($prodotti_inventario, 'prodotto_id')));
                $in = implode(',', array_map('intval', $prodIds));
                $stOverlap = $this->pdo->prepare("
                    SELECT i.id, cat.nome AS categoria_nome
                    FROM inventari i
                    JOIN inventari_righe ir ON ir.inventario_id = i.id
                    LEFT JOIN categorie_prodotto cat ON cat.id = i.categoria_filtro_id
                    WHERE i.magazzino_id = ? AND i.stato = 'bozza' AND ir.prodotto_id IN ($in)
                    LIMIT 1
                ");
                $stOverlap->execute([$magazzinoInventarioId]);
                $overlap = $stOverlap->fetch(PDO::FETCH_ASSOC);
                if ($overlap) {
                    $scopeLabel = $overlap['categoria_nome'] ? ('categoria "' . $overlap['categoria_nome'] . '"') : 'tutti i prodotti';
                    redirect_with(
                        'warehouse/inventories',
                        'danger',
                        'Esiste già un inventario in bozza (#' . $overlap['id'] . ', ' . $scopeLabel . ') che copre uno o più di questi prodotti in questo magazzino. Completa o annulla quello prima di aprirne un altro sugli stessi prodotti.'
                    );
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza scaduto o non valido.';
            } elseif (post('azione') === 'annulla') {
                // Annulla bozza (Tappa 4.3): una bozza non ha ancora toccato le
                // giacenze (solo la convalida applica le rettifiche), quindi
                // annullarla è una semplice cancellazione — le righe collegate
                // seguono via ON DELETE CASCADE. Serve come via di sblocco per
                // il controllo qk_inventario_bloccato() sui prodotti coinvolti,
                // se un inventario viene aperto per errore o dimenticato aperto.
                if ($id && $inventario && $inventario['stato'] === 'bozza') {
                    $this->pdo->prepare("DELETE FROM inventari WHERE id = ? AND stato = 'bozza'")->execute([$id]);
                    redirect_with('warehouse/inventories', 'success', 'Inventario annullato.');
                } else {
                    redirect_with('warehouse/inventories', 'danger', 'Impossibile annullare: inventario non trovato o già convalidato.');
                }
            } elseif ($inventario && $inventario['stato'] === 'convalidato') {
                $errors['_global'] = 'L\'inventario è convalidato e non può più essere modificato.';
            } else {
                $azione = post('azione', 'salva'); // 'salva' o 'convalida'
                $data_inventario = post('data_inventario', date('Y-m-d'));
                $note = post('note', '');
                $inputRighe = $_POST['righe'] ?? [];

                if (empty($data_inventario)) {
                    $errors['data_inventario'] = 'La data di rilevamento è obbligatoria.';
                }

                // Una quantità contata negativa non ha senso fisico (non si può contare
                // "meno di zero" a scaffale) ed è l'unico modo realistico per cui la
                // validazione "niente giacenza negativa" del PASS 1 più sotto potrebbe
                // essere aggirata: il controllo lì presuppone contata >= 0 per ogni riga.
                // Il campo ha anche min="0" lato HTML, ma quello si aggira facilmente
                // (JS disabilitato, POST diretto) — questo è il controllo che conta.
                foreach ($inputRighe as $valRiga) {
                    if ((float) str_replace(',', '.', (string) $valRiga) < 0) {
                        // La view (inventories_form.php) mostra solo $errors['_global'],
                        // non chiavi di campo specifiche: usiamo quella, altrimenti
                        // l'errore verrebbe impostato ma mai mostrato all'utente.
                        $errors['_global'] = 'Le quantità contate non possono essere negative.';
                        break;
                    }
                }

                if (empty($errors)) {
                    try {
                        $this->pdo->beginTransaction();
                        $utente_id = (int)(current_user()['id'] ?? 0);
                        $avvisiRiservata = [];

                        if (!$id) {
                            // Lock applicativo (Tappa 4.3): il controllo di sovrapposizione
                            // qui sotto è un check-then-act (SELECT poi, più tardi, INSERT) — una
                            // semplice transazione NON basta a renderlo atomico: due richieste
                            // concorrenti potrebbero entrambe superare la SELECT prima che una
                            // delle due scriva (race "phantom read"). Un vincolo PRIMARY KEY,
                            // invece, viene sempre controllato in modo atomico dal motore
                            // all'INSERT: la seconda richiesta concorrente sullo stesso magazzino
                            // fallisce/si accoda deterministicamente. La riga di lock viene
                            // rilasciata (DELETE) più sotto, nella stessa transazione, subito
                            // dopo l'inserimento della testata — non è un lock persistente.
                            try {
                                $this->pdo->prepare('INSERT INTO inventario_creazione_lock (magazzino_id) VALUES (?)')
                                    ->execute([$magazzinoInventarioId]);
                            } catch (PDOException $eLock) {
                                throw new \RuntimeException('Un\'altra creazione di inventario per questo magazzino è in corso in questo momento. Riprova tra qualche istante.');
                            }

                            // Ripeti la guardia anti-sovrapposizione (già fatta sopra, fuori
                            // transazione, solo per il redirect immediato in caso di GET/preview)
                            // QUI DENTRO alla transazione, ora sotto la copertura del lock appena
                            // preso: nessun'altra richiesta concorrente può essere arrivata fin
                            // qui nel frattempo per lo stesso magazzino.
                            if (!empty($prodotti_inventario)) {
                                $prodIdsChk = array_values(array_unique(array_column($prodotti_inventario, 'prodotto_id')));
                                $inChk = implode(',', array_map('intval', $prodIdsChk));
                                $stOverlapChk = $this->pdo->prepare("
                                    SELECT i.id, cat.nome AS categoria_nome
                                    FROM inventari i
                                    JOIN inventari_righe ir ON ir.inventario_id = i.id
                                    LEFT JOIN categorie_prodotto cat ON cat.id = i.categoria_filtro_id
                                    WHERE i.magazzino_id = ? AND i.stato = 'bozza' AND ir.prodotto_id IN ($inChk)
                                    LIMIT 1
                                ");
                                $stOverlapChk->execute([$magazzinoInventarioId]);
                                $overlapChk = $stOverlapChk->fetch(PDO::FETCH_ASSOC);
                                if ($overlapChk) {
                                    $scopeLabelChk = $overlapChk['categoria_nome'] ? ('categoria "' . $overlapChk['categoria_nome'] . '"') : 'tutti i prodotti';
                                    throw new \RuntimeException(
                                        'Esiste già un inventario in bozza (#' . $overlapChk['id'] . ', ' . $scopeLabelChk . ') che copre uno o più di questi prodotti in questo magazzino. Completa o annulla quello prima di aprirne un altro sugli stessi prodotti.'
                                    );
                                }
                            }

                            // Inserisci testata (magazzino e categoria filtro fissati alla
                            // creazione, non più cambiabili dopo)
                            $stmt = $this->pdo->prepare("
                                INSERT INTO inventari (data_inventario, magazzino_id, categoria_filtro_id, note, creato_da, stato)
                                VALUES (?, ?, ?, ?, ?, 'bozza')
                            ");
                            $stmt->execute([$data_inventario, $magazzinoInventarioId, $categoriaFiltroId, $note, $utente_id]);
                            $id = (int)$this->pdo->lastInsertId();

                            // Rilascia il lock: la testata è scritta (dentro questa stessa
                            // transazione), quindi da qui in poi una nuova creazione concorrente
                            // vedrebbe comunque questa bozza tramite la guardia di sovrapposizione
                            // sopra — non serve più tenere occupato il lock fino al commit finale.
                            $this->pdo->prepare('DELETE FROM inventario_creazione_lock WHERE magazzino_id = ?')
                                ->execute([$magazzinoInventarioId]);
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

                            // PASS 1 (Tappa 4.3): valida TUTTI gli scostamenti prima di
                            // applicare qualsiasi rettifica. Una giacenza che andrebbe
                            // negativa blocca l'intera convalida (segnala un errore di
                            // conteggio o un movimento mancante da indagare, non va
                            // "corretta" automaticamente). Una giacenza che scenderebbe
                            // sotto la quantità già riservata per ordini in corso non
                            // blocca — è un fatto fisico reale, non un errore — ma va
                            // segnalata chiaramente, altrimenti si scopre solo quando la
                            // spedizione fallisce.
                            //
                            // IMPORTANTE: un prodotto a lotti ha UNA riga per lotto in
                            // inventari_righe, ma giacenze.quantita_disponibile è un
                            // aggregato PER PRODOTTO (somma di tutti i lotti). Controllare
                            // ogni riga isolatamente contro lo stesso valore di giacenza
                            // (mai aggiornato in questo PASS, che è di sola lettura) è
                            // sbagliato quando un prodotto ha più lotti in conteggio nella
                            // stessa sessione: due delta negativi indipendenti possono
                            // sembrare entrambi innocui presi da soli, ma sommati insieme
                            // portano la giacenza reale sotto zero — cosa che il PASS 2
                            // (che invece rilegge la giacenza aggiornata a ogni riga)
                            // applicherebbe comunque, senza che PASS 1 l'avesse rilevato.
                            // Per questo qui sommiamo prima i delta PER PRODOTTO, poi
                            // controlliamo il totale cumulato una sola volta a prodotto.
                            $deltaPerProdotto = [];
                            foreach ($righeSalvate as $riga) {
                                $delta = (float)$riga['quantita_contata'] - (float)$riga['giacenza_teorica'];
                                if (abs($delta) < 0.0001) {
                                    continue;
                                }
                                $pid = (int) $riga['prodotto_id'];
                                $deltaPerProdotto[$pid] = ($deltaPerProdotto[$pid] ?? 0.0) + $delta;
                            }

                            $stNomeProdotto = $this->pdo->prepare('SELECT nome FROM prodotti WHERE id = ?');
                            $stGiacChk = $this->pdo->prepare("SELECT quantita_disponibile, quantita_riservata FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");
                            $violazioniNegative = [];
                            foreach ($deltaPerProdotto as $pid => $deltaTotale) {
                                if (abs($deltaTotale) < 0.0001) {
                                    continue;
                                }
                                $stGiacChk->execute([$pid, $magazzinoInventarioId]);
                                $giacChk = $stGiacChk->fetch(PDO::FETCH_ASSOC);
                                $qtaCorrenteChk = $giacChk ? (float)$giacChk['quantita_disponibile'] : 0.0;
                                $riservataChk   = $giacChk ? (float)$giacChk['quantita_riservata']   : 0.0;
                                $nuovaQtaChk    = $qtaCorrenteChk + $deltaTotale;

                                $stNomeProdotto->execute([$pid]);
                                $nomeProdotto = (string) ($stNomeProdotto->fetchColumn() ?: ('#' . $pid));

                                if ($nuovaQtaChk < -0.0001) {
                                    $violazioniNegative[] = sprintf('"%s": giacenza risultante %.3f', $nomeProdotto, $nuovaQtaChk);
                                } elseif ($nuovaQtaChk < $riservataChk - 0.0001) {
                                    $avvisiRiservata[] = sprintf('"%s": disponibile %.3f dopo la rettifica, ma %.3f già riservata per ordini in corso', $nomeProdotto, $nuovaQtaChk, $riservataChk);
                                }
                            }
                            if (!empty($violazioniNegative)) {
                                throw new \RuntimeException(
                                    'Impossibile convalidare: alcune rettifiche porterebbero la giacenza sotto zero — ' .
                                    implode('; ', $violazioniNegative) . '. Verifica il conteggio prima di riprovare.'
                                );
                            }

                            // PASS 2: applica le rettifiche (nessuna violazione trovata sopra)
                            foreach ($righeSalvate as $riga) {
                                $delta = (float)$riga['quantita_contata'] - (float)$riga['giacenza_teorica'];
                                if (abs($delta) < 0.0001) {
                                    continue; // nessuna rettifica necessaria
                                }

                                $quantitaAssoluta = abs($delta);

                                // 1. Crea il movimento di magazzino, per QUESTO magazzino.
                                // `quantita` resta la magnitudine (invariante di schema);
                                // `quantita_delta` porta il segno per la UI storico movimenti
                                // (vedi stesso pattern in qk_insert_movimento_manuale).
                                $stmtMov = $this->pdo->prepare("
                                    INSERT INTO movimenti_magazzino
                                    (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta, documento_tipo, documento_id, canale_origine, note, creato_da)
                                    VALUES (?, ?, ?, 'rettifica', ?, ?, 'inventario', ?, 'manuale', ?, ?)
                                ");
                                $noteMov = sprintf("Rettifica da inventario #%d. Scostamento: %+.3f", $id, $delta);
                                $stmtMov->execute([
                                    $riga['prodotto_id'],
                                    $riga['lotto_id'],
                                    $magazzinoInventarioId,
                                    $quantitaAssoluta,
                                    $delta,
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
                        $flashType = 'success';
                        if ($azione === 'convalida' && !empty($avvisiRiservata)) {
                            $msg .= ' ATTENZIONE — sotto-copertura ordini: ' . implode('; ', $avvisiRiservata) . '.';
                            $flashType = 'warning';
                        }
                        redirect_with('warehouse/inventories', $flashType, $msg);

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
            'id', 'errors', 'inventario', 'values', 'prodotti_inventario', 'isConvalidato',
            'categorie', 'categoriaFiltroId'
        ));
    }

    /* ── Reorder Proposals ────────────────────────────────────────────────── */

    public function reorder(): void
    {

        $base   = url('warehouse/reorder');
        $search = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');

        // Fix 3 (§8.4): stesso pattern di risoluzione del Fix 2 (§8.3) — filtro
        // magazzino sempre esplicito in pagina, mai un'aggregazione silenziosa.
        $magazzinoParam = get_param('magazzino_id', '');
        if ($magazzinoParam === '') {
            $magazzinoId = qk_magazzino_attivo_id($this->pdo);
        } elseif ($magazzinoParam === '0') {
            $magazzinoId = 0;
        } else {
            $candidato = (int) $magazzinoParam;
            $stChk = $this->pdo->prepare('SELECT id FROM magazzini WHERE id = ? AND attivo = 1');
            $stChk->execute([$candidato]);
            $magazzinoId = $stChk->fetchColumn() ? $candidato : qk_magazzino_attivo_id($this->pdo);
        }
        if ($magazzinoId > 0) {
            $_SESSION['magazzino_attivo_id'] = $magazzinoId; // Opzione A, §9.5
        }
        $magazzini = qk_magazzini_list($this->pdo, true);

        $whereExtra = '';
        $paramsSenzaMagazzino = [];
        if ($search !== '') {
            $whereExtra = 'AND (p.nome LIKE ? OR p.sku_interno LIKE ?)';
            $paramsSenzaMagazzino[] = "%$search%";
            $paramsSenzaMagazzino[] = "%$search%";
        }

        if ($magazzinoId === 0) {
            // Ramo aggregato "tutti i magazzini" (§8.0, §8.4): il fabbisogno è
            // la somma su tutte le sedi, non la riga di una singola sede.
            $sortColMapAgg = [
                'nome'      => 'p.nome',
                'libera'    => 'qta_libera',
                'minima'    => 'scorta_minima',
                'arrivo'    => 'qta_in_arrivo',
                'fornitore' => 'fornitore_nome',
            ];
            $pag     = qk_pagination_params(array_keys($sortColMapAgg), 'nome', 'asc');
            $sort    = $pag['sort']; $dir = $pag['dir']; $page = $pag['page'];
            $limit   = $pag['limit']; $offset = $pag['offset'];
            $sortCol = $sortColMapAgg[$sort];

            $baseSql =
                "FROM prodotti p
                 LEFT JOIN giacenze g ON g.prodotto_id = p.id
                 LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
                 LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.ordine_preferenza_fornitore = 1 AND pf.attivo = 1
                 LEFT JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
                 WHERE p.attivo = 1 $whereExtra
                 GROUP BY p.id
                HAVING " . qk_sottoscorta_having('g');

            $stmtC = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT p.id " . $baseSql . ") t");
            $stmtC->execute($paramsSenzaMagazzino);
            $total = (int) $stmtC->fetchColumn();

            $stmtQ = $this->pdo->prepare(
                "SELECT p.id, p.nome, p.sku_interno, u.codice AS uom,
                        SUM(COALESCE(g.quantita_disponibile, 0)) AS qta_disponibile,
                        SUM(COALESCE(g.quantita_riservata, 0)) AS qta_riservata,
                        SUM(COALESCE(g.quantita_in_arrivo, 0)) AS qta_in_arrivo,
                        (SUM(COALESCE(g.quantita_disponibile, 0)) - SUM(COALESCE(g.quantita_riservata, 0))) AS qta_libera,
                        SUM(COALESCE(g.scorta_minima, 0)) AS scorta_minima,
                        MAX(pf.fornitore_id) AS fornitore_id,
                        MAX(COALESCE(cf.ragione_sociale, cf.nome || ' ' || COALESCE(cf.cognome, ''))) AS fornitore_nome,
                        -- Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md): da
                        -- v_prezzo_acquisto_base_fornitore, non più ORDER BY id DESC.
                        MAX((SELECT vf.prezzo FROM v_prezzo_acquisto_base_fornitore vf
                             WHERE vf.prodotto_fornitore_id = pf.id)) AS ultimo_prezzo
                 " . $baseSql . "
                 ORDER BY " . $sortCol . " COLLATE NOCASE " . $dir . "
                 LIMIT $limit OFFSET $offset"
            );
            $stmtQ->execute($paramsSenzaMagazzino);
            $proposals = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
            $pag = compact('page', 'limit', 'sort', 'dir');
        } else {
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

            $params = [$magazzinoId, ...$paramsSenzaMagazzino];

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
                        -- Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md): da
                        -- v_prezzo_acquisto_base_fornitore, non più ORDER BY id DESC.
                        (SELECT vf.prezzo FROM v_prezzo_acquisto_base_fornitore vf
                         WHERE vf.prodotto_fornitore_id = pf.id) AS ultimo_prezzo
                 " . $baseSql . "
                 ORDER BY " . $sortCol . " COLLATE NOCASE " . $dir . "
                 LIMIT " . $limit . " OFFSET " . $offset
            );
            $stmtQ->execute($params);
            $proposals = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

            $pag = compact('page', 'limit', 'sort', 'dir');
        }

        $nomeMagazzinoScelto = 'tutti i magazzini';
        if ($magazzinoId !== 0) {
            foreach ($magazzini as $mg) {
                if ((int) $mg['id'] === $magazzinoId) { $nomeMagazzinoScelto = $mg['nome']; break; }
            }
        }

        $page_title    = 'Proposte di Riordino';
        $page_subtitle = 'Generazione ordini e RFQ per articoli sottoscorta — ' . $nomeMagazzinoScelto;
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Riordino']];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/reorder.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'search', 'proposals', 'total', 'base', 'pag', 'magazzini', 'magazzinoId'
        ));
    }

    /* ── Soglie di riordino (scorta minima per magazzino) ────────────────────
     * Piano Multi-Magazzino Parte 5, §5.2: modello Odoo (griglia editabile
     * inline), unica fonte di verità già su giacenze.scorta_minima. Due
     * modalità della stessa vista: "un magazzino, tutti i prodotti" (default)
     * o, con ?prodotto_id=, "un prodotto, tutte le sedi" (pivot, raggiunto dal
     * link in products/_form.php). Il filtro magazzino non ha mai un default
     * silenzioso "tutti": qui si scrive un dato per sede, non si legge un
     * aggregato (a differenza di Giacenze/Riordino, §9 di Magazzino Attivo -
     * Analisi e Alternative.md), quindi il <select> è sempre obbligatorio e
     * visibile, precompilato solo per comodità con qk_magazzino_attivo_id(). */

    public function minStock(): void
    {
        $prodottoIdFiltro = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT) ?: null;
        $search = trim((string) get_param('q', ''));

        $magazzini = qk_magazzini_list($this->pdo, true);
        $magazzinoId = filter_input(INPUT_GET, 'magazzino_id', FILTER_VALIDATE_INT) ?: null;
        if (!$magazzinoId && !$prodottoIdFiltro) {
            $magazzinoId = qk_magazzino_attivo_id($this->pdo);
        }

        $prodottoFiltro = null;
        if ($prodottoIdFiltro) {
            $stP = $this->pdo->prepare('SELECT id, nome, sku_interno FROM prodotti WHERE id = ?');
            $stP->execute([$prodottoIdFiltro]);
            $prodottoFiltro = $stP->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($prodottoFiltro) {
            // Pivot: un prodotto, tutte le sedi (radici fisiche + virtuali/conto_terzi/conto_lavoro,
            // stesso set usato nel form del trasferimento: una soglia ha senso anche per una sede virtuale).
            $st = $this->pdo->prepare(
                "SELECT m.id AS magazzino_id, m.nome AS magazzino_nome, m.codice AS magazzino_codice,
                        p.id AS prodotto_id, p.nome AS prodotto_nome, p.sku_interno,
                        u.codice AS uom,
                        COALESCE(g.quantita_disponibile, 0) AS qta_disponibile,
                        COALESCE(g.scorta_minima, 0) AS scorta_minima
                   FROM magazzini m
                   LEFT JOIN prodotti p ON p.id = ?
                   LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
                   LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = m.id
                  WHERE m.attivo = 1
                  ORDER BY m.predefinito DESC, m.nome COLLATE NOCASE"
            );
            $st->execute([$prodottoIdFiltro]);
            $righe = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Vista standard: un magazzino, tutti i prodotti attivi (LEFT JOIN
            // su giacenze: un prodotto mai movimentato in questa sede compare
            // comunque, con soglia 0 modificabile da subito).
            $where = ['p.attivo = 1'];
            $params = [$magazzinoId];
            if ($search !== '') {
                $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }
            $st = $this->pdo->prepare(
                "SELECT p.id AS prodotto_id, p.nome AS prodotto_nome, p.sku_interno,
                        u.codice AS uom,
                        COALESCE(g.quantita_disponibile, 0) AS qta_disponibile,
                        COALESCE(g.scorta_minima, 0) AS scorta_minima
                   FROM prodotti p
                   LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
                   LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY p.nome COLLATE NOCASE"
            );
            $st->execute($params);
            $righe = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        $page_title    = 'Soglie di riordino';
        $page_subtitle = $prodottoFiltro
            ? 'Scorta minima di "' . $prodottoFiltro['nome'] . '" per sede'
            : 'Scorta minima per prodotto, per magazzino';
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Soglie di riordino']];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/min_stock.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'magazzini', 'magazzinoId', 'prodottoFiltro', 'search', 'righe'
        ));
    }

    public function saveMinStock(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Non autorizzato.']); exit;
        }

        $prodottoId  = (int) post('prodotto_id');
        $magazzinoId = (int) post('magazzino_id');
        $scortaStr   = str_replace(',', '.', (string) post('scorta_minima', '0'));

        if ($prodottoId <= 0 || $magazzinoId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto o magazzino mancante.']); exit;
        }
        if (!is_numeric($scortaStr) || (float) $scortaStr < 0) {
            echo json_encode(['ok' => false, 'error' => 'Scorta minima non valida.']); exit;
        }
        $scortaMinima = (float) $scortaStr;

        try {
            // Upsert su giacenze: la coppia (prodotto_id, magazzino_id) può non
            // esistere ancora (prodotto mai movimentato in questa sede) — in tal
            // caso la crea con giacenza 0, esattamente come già fa il primo
            // movimento manuale (qk_insert_movimento_manuale).
            $this->pdo->prepare(
                "INSERT INTO giacenze (prodotto_id, magazzino_id, scorta_minima, updated_at)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT(prodotto_id, magazzino_id)
                 DO UPDATE SET scorta_minima = excluded.scorta_minima, updated_at = CURRENT_TIMESTAMP"
            )->execute([$prodottoId, $magazzinoId, $scortaMinima]);

            $st = $this->pdo->prepare(
                "SELECT COALESCE(quantita_disponibile,0) AS qta_disponibile, scorta_minima
                   FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?"
            );
            $st->execute([$prodottoId, $magazzinoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'row' => $row]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $this->dbMessage($e instanceof PDOException ? $e : new PDOException($e->getMessage()))]);
        }
        exit;
    }

    /* ── Crea movimento ───────────────────────────────────────────────────── */

    public function createMovement(): void
    {
        $errors = [];
        $values = [
            'tipo_movimento'               => 'carico',
            'magazzino_id'                 => '',
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

        // Fix 1 (§8.2): il campo magazzino è ora esplicito sul form. Il default
        // comodo si precompila solo al primo caricamento (GET), mai su un
        // resubmit con errori (altrimenti sovrascriverebbe silenziosamente la
        // scelta esplicita già fatta dall'utente prima dell'errore).
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $values['magazzino_id'] = (string) qk_magazzino_attivo_id($this->pdo);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values['tipo_movimento']      = post('tipo_movimento', 'carico');
                $values['magazzino_id']        = post('magazzino_id', $values['magazzino_id']);
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
                $magazzinoIdInt = (int) $values['magazzino_id'];
                if ($magazzinoIdInt <= 0) {
                    $errors['magazzino_id'] = 'Seleziona il magazzino.';
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
                        // magazzino_id: ora sempre quello scelto esplicitamente
                        // sul form (Fix 1, §8.2) — non più risolto silenziosamente
                        // sul magazzino attivo di sessione (il caso "Maria", §3.2
                        // di Magazzino Attivo - Analisi e Alternative.md).
                        $movData = [
                            'tipo_movimento'      => $values['tipo_movimento'],
                            'prodotto_id'         => (int) $values['prodotto_id'],
                            'magazzino_id'        => $magazzinoIdInt,
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

                        // Opzione A (§9.5): la scelta esplicita di sede su questo
                        // form aggiorna la sessione come effetto collaterale.
                        $_SESSION['magazzino_attivo_id'] = $magazzinoIdInt;

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

        $magazzini = qk_magazzini_list($this->pdo, true);

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
            'errors', 'values', 'magazzini'
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
        // Fix 1 (§8.2): deve rispettare il magazzino scelto esplicitamente nel
        // form "Nuovo movimento", non sempre quello attivo di sessione —
        // altrimenti il campo select esisterebbe ma "Giacenza attuale"/lotti
        // continuerebbero a mostrare la sede sbagliata quando se ne sceglie
        // una diversa da quella attiva. null → fallback interno invariato
        // (usato anche dal drawer lotti di stock.php, che non passa magazzino_id).
        $magazzinoId = filter_input(INPUT_GET, 'magazzino_id', FILTER_VALIDATE_INT) ?: null;
        $info  = qk_prodotto_stock_info($this->pdo, $id, $magazzinoId);
        // Restituisce TUTTI i lotti (anche esauriti); il JS filtra in base al tipo operazione
        $lotti = $info && $info['traccia_lotti']
            ? qk_lotti_by_prodotto($this->pdo, $id, false, $magazzinoId)
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

    /* ── Trasferimenti tra magazzini (Piano Multi-Magazzino §3.4, §4.2) ──────── */

    public function transfers(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $creazione = get_param('creazione', '');

        $statiValidi = ['', 'bozza', 'in_transito', 'completato', 'annullato'];
        if (!in_array($stato, $statiValidi, true)) $stato = '';
        $creazioneValide = ['', 'trasferimento', 'diretto'];
        if (!in_array($creazione, $creazioneValide, true)) $creazione = '';

        $pag = qk_pagination_params(['data', 'numero', 'stato'], 'data', 'desc');

        $total = qk_trasferimenti_count($this->pdo, $search, $stato, $creazione);
        $trasferimenti = qk_trasferimenti_list($this->pdo, $search, $stato, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset'], $creazione);

        $page_title    = 'Trasferimenti tra Magazzini';
        $page_subtitle = 'Movimentazioni interne tra sedi';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Trasferimenti'],
        ];
        $page_actions  = '<a href="' . url('warehouse/transfers/diretto') . '" class="btn-qk btn-qk-secondary">
            <i class="fa-solid fa-bolt"></i> Trasferimento diretto
        </a>
        <a href="' . url('warehouse/transfers/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo trasferimento
        </a>';
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/transfers_list.php', compact(
            'search', 'stato', 'creazione', 'total', 'trasferimenti', 'pag',
            'page_title', 'page_subtitle', 'breadcrumbs', 'page_actions', 'extra_css'
        ));
    }

    /**
     * Trasferimento diretto: form semplificato per lo spostamento istantaneo
     * tra due magazzini con la stessa radice fisica (Trasferimenti tra
     * Magazzini come Documento.md, §7.2, Regola A — nome allineato al lessico
     * SAP/Dynamics 365, vedi qk_registra_trasferimento_diretto()). Riusa
     * qk_magazzini_list($pdo, true) senza il filtro $soloRadiciFisiche: un
     * virtuale/conto_terzi/conto_lavoro deve poter comparire come origine o
     * destinazione qui, esattamente come nel form del trasferimento pieno.
     */
    public function trasferimentoDiretto(): void
    {
        $errors = [];
        $magazzini = qk_magazzini_list($this->pdo, true);

        $values = [
            'magazzino_origine_id'      => '',
            'magazzino_destinazione_id' => '',
            'data_richiesta'            => date('Y-m-d'),
            'note'                      => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values['magazzino_origine_id']      = post('magazzino_origine_id', '');
                $values['magazzino_destinazione_id'] = post('magazzino_destinazione_id', '');
                $values['data_richiesta']            = post('data_richiesta', date('Y-m-d'));
                $values['note']                      = post('note', '');

                $prodottiPost = $_POST['riga_prodotto_id'] ?? [];
                $lottiPost    = $_POST['riga_lotto_id'] ?? [];
                $qtaPost      = $_POST['riga_quantita'] ?? [];

                $righe = [];
                foreach ($prodottiPost as $i => $pid) {
                    if (empty($pid)) continue;
                    $righe[] = [
                        'prodotto_id'         => (int) $pid,
                        'lotto_id'            => !empty($lottiPost[$i]) ? (int) $lottiPost[$i] : null,
                        'quantita_richiesta'  => (float) ($qtaPost[$i] ?? 0),
                    ];
                }

                try {
                    $header = [
                        'magazzino_origine_id'      => (int) $values['magazzino_origine_id'],
                        'magazzino_destinazione_id' => (int) $values['magazzino_destinazione_id'],
                        'data_richiesta'            => $values['data_richiesta'],
                        'note'                      => $values['note'],
                    ];
                    $id = qk_registra_trasferimento_diretto($this->pdo, $header, $righe, (int) current_user()['id']);
                    // Opzione A (§9.5): stesso effetto collaterale di createTransfer().
                    $_SESSION['magazzino_attivo_id'] = $header['magazzino_origine_id'];
                    redirect_with('warehouse/transfers/view?id=' . $id, 'success', 'Trasferimento diretto registrato: giacenza spostata istantaneamente.');
                } catch (\Throwable $e) {
                    $errors['_global'] = $e->getMessage();
                }
            }
        }

        $page_title    = 'Trasferimento diretto';
        $page_subtitle = 'Spostamento istantaneo tra sedi della stessa radice fisica';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Trasferimenti', 'url' => url('warehouse/transfers')],
            ['label' => 'Trasferimento diretto'],
        ];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/transfers_diretto.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'errors', 'values', 'magazzini'
        ));
    }

    public function createTransfer(): void
    {
        $errors = [];
        $magazzini = qk_magazzini_list($this->pdo, true);

        $values = [
            'magazzino_origine_id'      => '',
            'magazzino_destinazione_id' => '',
            'data_richiesta'            => date('Y-m-d'),
            'note'                      => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values['magazzino_origine_id']      = post('magazzino_origine_id', '');
                $values['magazzino_destinazione_id'] = post('magazzino_destinazione_id', '');
                $values['data_richiesta']            = post('data_richiesta', date('Y-m-d'));
                $values['note']                      = post('note', '');

                $prodottiPost = $_POST['riga_prodotto_id'] ?? [];
                $lottiPost    = $_POST['riga_lotto_id'] ?? [];
                $qtaPost      = $_POST['riga_quantita'] ?? [];

                $righe = [];
                foreach ($prodottiPost as $i => $pid) {
                    if (empty($pid)) continue;
                    $righe[] = [
                        'prodotto_id'         => (int) $pid,
                        'lotto_id'            => !empty($lottiPost[$i]) ? (int) $lottiPost[$i] : null,
                        'quantita_richiesta'  => (float) ($qtaPost[$i] ?? 0),
                    ];
                }

                try {
                    $header = [
                        'magazzino_origine_id'      => (int) $values['magazzino_origine_id'],
                        'magazzino_destinazione_id' => (int) $values['magazzino_destinazione_id'],
                        'data_richiesta'            => $values['data_richiesta'],
                        'note'                      => $values['note'],
                    ];
                    $id = qk_insert_trasferimento($this->pdo, $header, $righe, (int) current_user()['id']);
                    // Opzione A (§9.5): la scelta esplicita dell'origine aggiorna
                    // la sessione come effetto collaterale — è la sede da cui
                    // l'operatore sta effettivamente lavorando in questo momento.
                    $_SESSION['magazzino_attivo_id'] = $header['magazzino_origine_id'];
                    redirect_with('warehouse/transfers/view?id=' . $id, 'success', 'Trasferimento creato in bozza.');
                } catch (\Throwable $e) {
                    $errors['_global'] = $e->getMessage();
                }
            }
        }

        $page_title    = 'Nuovo trasferimento';
        $page_subtitle = 'Sposta merce da un magazzino a un altro';
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Trasferimenti', 'url' => url('warehouse/transfers')],
            ['label' => 'Nuovo'],
        ];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/transfers_form.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css',
            'errors', 'values', 'magazzini'
        ));
    }

    public function viewTransfer(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) redirect(url('warehouse/transfers'));

        $detail = qk_trasferimento_detail($this->pdo, $id);
        if (!$detail) redirect(url('warehouse/transfers'));

        $page_title    = 'Trasferimento ' . $detail['testata']['numero'];
        $page_subtitle = $detail['testata']['magazzino_origine_nome'] . ' → ' . $detail['testata']['magazzino_destinazione_nome'];
        $breadcrumbs   = [
            ['label' => 'Magazzino', 'url' => url('warehouse')],
            ['label' => 'Trasferimenti', 'url' => url('warehouse/transfers')],
            ['label' => $detail['testata']['numero']],
        ];
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/transfers_detail.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css', 'detail'
        ));
    }

    /**
     * POST /warehouse/transfers/action
     * Body: id, azione ('partenza' | 'conferma_arrivo' | 'annulla')
     */
    public function transferAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('warehouse/transfers'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');
        $utente_id = (int) current_user()['id'];

        try {
            switch ($azione) {
                case 'partenza':
                    qk_trasferimento_registra_partenza($this->pdo, $id, $utente_id);
                    flash_set('success', 'Partenza registrata: giacenza scaricata dal magazzino di origine.');
                    break;

                case 'conferma_arrivo':
                    $rigaIds  = $_POST['riga_id'] ?? [];
                    $qtaPost  = $_POST['quantita_arrivata'] ?? [];
                    $notePost = $_POST['nota_discrepanza'] ?? [];
                    $quantitaArrivata = [];
                    $noteDiscrepanza  = [];
                    foreach ($rigaIds as $i => $rid) {
                        $quantitaArrivata[(int) $rid] = (float) ($qtaPost[$i] ?? 0);
                        $noteDiscrepanza[(int) $rid]  = (string) ($notePost[$i] ?? '');
                    }
                    qk_conferma_arrivo_trasferimento($this->pdo, $id, $quantitaArrivata, $noteDiscrepanza, $utente_id);
                    flash_set('success', 'Arrivo confermato: giacenza caricata nel magazzino di destinazione.');
                    break;

                case 'annulla':
                    qk_annulla_trasferimento($this->pdo, $id, $utente_id);
                    flash_set('success', 'Trasferimento annullato.');
                    break;

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }

        redirect(url('warehouse/transfers/view') . '?id=' . $id);
    }

    /**
     * GET /warehouse/api/lotti-per-magazzino?prodotto_id=X&magazzino_id=Y
     * Lotti disponibili per un prodotto in UN magazzino specifico (usato dal
     * form di creazione trasferimento per popolare la select lotto in base
     * al magazzino di origine scelto — a differenza di apiProdottoInfo, che
     * restituisce tutti i lotti aggregati indipendentemente dalla sede).
     */
    public function apiLottiPerMagazzino(): void
    {
        ob_start();
        header('Content-Type: application/json');
        $prodotto_id  = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $magazzino_id = filter_input(INPUT_GET, 'magazzino_id', FILTER_VALIDATE_INT);
        if (!$prodotto_id || !$magazzino_id) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'prodotto_id e magazzino_id sono obbligatori']);
            exit;
        }
        $lotti = qk_lotti_by_prodotto($this->pdo, $prodotto_id, true, $magazzino_id);
        ob_end_clean();
        echo json_encode(['ok' => true, 'lotti' => $lotti]);
        exit;
    }
}
