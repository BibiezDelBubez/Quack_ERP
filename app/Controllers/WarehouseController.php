<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';
require_once INCLUDES_DIR . '/trasferimenti.php';
require_once INCLUDES_DIR . '/inventario.php';

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
        // KPI della dashboard estratti in includes/magazzino.php (Piano
        // refactor MVC/DRY, Fase 3, microtappa 3.1) — il sottoscorta usa ora
        // qk_stock_counters(), già condivisa con stock()/scadenze(), invece
        // di una formula duplicata inline (stesso $magazzinoAttivoId, stesso
        // risultato: verificato che entrambe le query concordano perché un
        // prodotto senza riga giacenze ha comunque scorta_minima=0, che fallisce
        // sempre la condizione "scorta_minima > 0" di qk_sottoscorta_where(),
        // quindi INNER JOIN vs LEFT JOIN non cambiava il conteggio).
        $magazzinoAttivoId = qk_magazzino_attivo_id($this->pdo);
        $valore_magazzino  = qk_valore_magazzino($this->pdo);
        $sottoscorta_count = qk_stock_counters($this->pdo, $magazzinoAttivoId)['sottoscorta'];
        $in_arrivo_count   = qk_ordini_acquisto_in_arrivo_count($this->pdo);
        $in_partenza_count = qk_spedizioni_in_partenza_count($this->pdo);
        $recent_movements  = qk_movimenti_recenti($this->pdo, 5);
        $understock_items  = qk_prodotti_vicino_sottoscorta($this->pdo, $magazzinoAttivoId, 5);

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
        // Risoluzione condivisa in qk_risolvi_magazzino_filtro() (audit MVC/DRY,
        // Revisione Gestione Magazzino e Lotti — prima duplicata in 3 punti).
        $magazzinoId = qk_risolvi_magazzino_filtro($this->pdo, get_param('magazzino_id', ''));
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

        // Contatori per le pill Sottoscorta/Esauriti — spostati dalla view al
        // service layer (audit MVC/DRY, Revisione Gestione Magazzino e Lotti).
        $contatori    = qk_stock_counters($this->pdo, $magazzinoId);
        $nSottoscorta = $contatori['sottoscorta'];
        $nEsauriti    = $contatori['esauriti'];

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
            'magazzini', 'magazzinoId', 'nSottoscorta', 'nEsauriti'
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
        // Count/lista estratti in includes/inventario.php (Fase 3, microtappa 3.2).
        $total     = qk_inventari_count($this->pdo, $search, $stato);
        $inventari = qk_inventari_list($this->pdo, $search, $stato, $sort, $dir, $limit, $offset);

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
            // categoria scelta se è un cycle count (Tappa 4.3). Estratto in
            // qk_inventario_prodotti_scope() (Piano refactor "God Object",
            // microtappa 2.4).
            $prodotti_inventario = qk_inventario_prodotti_scope($this->pdo, $magazzinoInventarioId, $categoriaFiltroId);

            // Guardia (Tappa 4.3): nessun'altra bozza già aperta su questo
            // magazzino deve coprire uno di questi stessi prodotti. Solo per il
            // redirect immediato lato UI — il controllo che conta davvero contro
            // le race condition è quello ripetuto dentro il lock applicativo, in
            // qk_convalida_inventario() (stessa funzione condivisa, microtappa 2.4).
            $overlap = qk_inventario_verifica_sovrapposizione($this->pdo, $magazzinoInventarioId, $prodotti_inventario);
            if ($overlap) {
                $scopeLabel = $overlap['categoria_nome'] ? ('categoria "' . $overlap['categoria_nome'] . '"') : 'tutti i prodotti';
                redirect_with(
                    'warehouse/inventories',
                    'danger',
                    'Esiste già un inventario in bozza (#' . $overlap['id'] . ', ' . $scopeLabel . ') che copre uno o più di questi prodotti in questo magazzino. Completa o annulla quello prima di aprirne un altro sugli stessi prodotti.'
                );
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
                    // Normalizza le quantità contate (virgola->punto, cast a float)
                    // QUI, prima del confine col service layer — qk_convalida_inventario()
                    // non deve mai ricevere $_POST grezzo (vedi Piano refactor "God
                    // Object", vincolo su $inputRighe).
                    $righeNormalizzate = [];
                    foreach ($inputRighe as $key => $valRiga) {
                        $righeNormalizzate[$key] = (float) str_replace(',', '.', (string) $valRiga);
                    }

                    try {
                        $utente_id = (int)(current_user()['id'] ?? 0);

                        // Facade transazionale (Piano refactor "God Object", microtappa 2.3,
                        // includes/inventario.php): unico punto che apre/chiude la transazione
                        // — lock, guardia ripetuta, CRUD, PASS1, PASS2, cambio stato sono tutti
                        // dentro. Verificata contro tests/manual/suite_create_inventario.php
                        // (tutti gli scenari A-M) prima e dopo l'estrazione.
                        $risultato = qk_convalida_inventario($this->pdo, $id, [
                            'azione'              => $azione,
                            'data_inventario'     => $data_inventario,
                            'note'                => $note,
                            'magazzino_id'        => $magazzinoInventarioId,
                            'categoria_filtro_id' => $categoriaFiltroId,
                            'prodotti_inventario' => $prodotti_inventario,
                            'righe'               => $righeNormalizzate,
                        ], $utente_id);

                        $msg = $azione === 'convalida' ? 'Inventario convalidato e rettifiche applicate con successo.' : 'Bozza inventario salvata con successo.';
                        $flashType = 'success';
                        if ($azione === 'convalida' && !empty($risultato['avvisi_riservata'])) {
                            $msg .= ' ATTENZIONE — sotto-copertura ordini: ' . implode('; ', $risultato['avvisi_riservata']) . '.';
                            $flashType = 'warning';
                        }
                        redirect_with('warehouse/inventories', $flashType, $msg);

                    } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
        $magazzinoId = qk_risolvi_magazzino_filtro($this->pdo, get_param('magazzino_id', ''));
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
                 LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id"
                 . qk_join_fornitore_preferito_sql('p') . "
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
                        -- Fix 'Listino Fantasma' (audit/MASTER_AUDIT_REPORT.md): da
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
                 LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id"
                 . qk_join_fornitore_preferito_sql('p') . "
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
                        -- Fix 'Listino Fantasma' (audit/MASTER_AUDIT_REPORT.md): da
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

        // Quantità consigliata per il riordino: alimenta anche gli URL applicativi
        // (creazione RFQ pre-compilata), non è solo presentazione — spostata qui
        // dalla view (Fase 3, microtappa 3.6). Calcolo puro post-fetch, identico
        // per entrambi i rami (aggregato/per-sede): entrambi espongono
        // scorta_minima/qta_libera con lo stesso significato.
        foreach ($proposals as &$p) {
            $p['qta_consigliata'] = max(0, (float) $p['scorta_minima'] - (float) $p['qta_libera']);
        }
        unset($p);

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

    /* ── Lotti in scadenza ─────────────────────────────────────────────────
     * Revisione Gestione Magazzino e Lotti §5: prima non esisteva alcun report
     * proattivo sulle scadenze imminenti — solo l'esclusione automatica dei lotti
     * già scaduti al momento della spedizione (Tappa 4.4). Report separato, in
     * sola lettura: mostra i lotti già scaduti o in scadenza entro la soglia
     * scelta, con giacenza ancora residua (un lotto esaurito non serve più
     * attenzione). Stesso pattern del selettore magazzino di reorder()/stock()
     * (0 = tutti i magazzini, sempre esplicito in pagina). */
    public function scadenze(): void
    {
        $base   = url('warehouse/scadenze');
        $search = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');

        // Stesso pattern di risoluzione di stock()/reorder() (Fix 2/3, §8.3-8.4),
        // per coerenza tra le tre schermate con filtro magazzino esplicito: '' →
        // magazzino attivo di sessione, '0' → scelta esplicita "Tutti i magazzini".
        $magazzinoId = qk_risolvi_magazzino_filtro($this->pdo, get_param('magazzino_id', ''));
        if ($magazzinoId > 0) {
            $_SESSION['magazzino_attivo_id'] = $magazzinoId; // Opzione A, §9.5
        }
        $magazzini = qk_magazzini_list($this->pdo, true);

        // Soglia di preavviso in giorni: non solo "già scaduto" (che il sistema già
        // gestisce da solo alla spedizione), ma "sta per scadere" — l'informazione
        // che oggi non esiste da nessuna parte.
        $giorniValidi = [7, 15, 30, 60, 90];
        $giorni = (int) get_param('giorni', '30');
        if (!in_array($giorni, $giorniValidi, true)) {
            $giorni = 30;
        }
        // Data soglia calcolata in PHP, mai con funzioni data SQLite-specifiche
        // (JULIANDAY/DATE con modificatori) o con || per costruirla in SQL: qui deve
        // restare portabile SQLite/MySQL (CLAUDE.md, regola sulla concatenazione).
        $sogliaData = date('Y-m-d', strtotime("+{$giorni} days"));

        $where  = ['l.data_scadenza IS NOT NULL', 'lg.quantita_disponibile > 0.0005', 'l.data_scadenza <= ?'];
        $params = [$sogliaData];
        if ($magazzinoId > 0) {
            $where[]  = 'lg.magazzino_id = ?';
            $params[] = $magazzinoId;
        }
        if ($search !== '') {
            $where[]  = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
            $like     = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $sortColMap = [
            'scadenza'  => 'l.data_scadenza',
            'prodotto'  => 'p.nome',
            'magazzino' => 'mg.nome',
            'quantita'  => 'lg.quantita_disponibile',
        ];
        $pag     = qk_pagination_params(array_keys($sortColMap), 'scadenza', 'asc');
        $sortCol = $sortColMap[$pag['sort']];

        $stmtC = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM lotti l
               JOIN lotti_giacenze lg ON lg.lotto_id = l.id
               JOIN prodotti p ON p.id = l.prodotto_id
               JOIN magazzini mg ON mg.id = lg.magazzino_id
              WHERE $whereSql"
        );
        $stmtC->execute($params);
        $total = (int) $stmtC->fetchColumn();

        $stmtQ = $this->pdo->prepare(
            "SELECT l.id AS lotto_id, l.codice_lotto_interno, l.codice_lotto_fornitore, l.data_scadenza,
                    p.id AS prodotto_id, p.nome AS prodotto_nome, p.sku_interno,
                    mg.id AS magazzino_id, mg.nome AS magazzino_nome,
                    lg.quantita_disponibile, u.codice AS uom
               FROM lotti l
               JOIN lotti_giacenze lg ON lg.lotto_id = l.id
               JOIN prodotti p ON p.id = l.prodotto_id
               JOIN magazzini mg ON mg.id = lg.magazzino_id
          LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
              WHERE $whereSql
              ORDER BY $sortCol COLLATE NOCASE {$pag['dir']}
              LIMIT {$pag['limit']} OFFSET {$pag['offset']}"
        );
        $stmtQ->execute($params);
        $lotti = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        // giorni_rimanenti/scaduto: calcolo puro post-fetch, spostato dalla view
        // (Fase 3, microtappa 3.7) — stesso pattern già usato sopra per $sogliaData
        // (data calcolata in PHP, non con funzioni SQLite-specifiche).
        $oggi = date('Y-m-d');
        foreach ($lotti as &$l) {
            $giorniRimanenti = (int) round((strtotime($l['data_scadenza']) - strtotime($oggi)) / 86400);
            $l['giorni_rimanenti'] = $giorniRimanenti;
            $l['scaduto'] = $giorniRimanenti < 0;
        }
        unset($l);

        $page_title    = 'Lotti in scadenza';
        $page_subtitle = 'Lotti già scaduti o in scadenza entro ' . $giorni . ' giorni';
        $breadcrumbs   = [['label' => 'Magazzino', 'url' => url('warehouse')], ['label' => 'Lotti in scadenza']];
        $active_tab    = 'scadenze';
        $extra_css = ['warehouse.css'];

        $this->page(APP_ROOT_DIR . '/app/views/warehouse/scadenze.php', compact(
            'page_title', 'page_subtitle', 'breadcrumbs', 'extra_css', 'active_tab',
            'search', 'lotti', 'total', 'base', 'pag', 'magazzini', 'magazzinoId', 'giorni', 'giorniValidi'
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

        // Query estratte in includes/magazzino.php (Fase 3, microtappa 3.3).
        if ($prodottoFiltro) {
            $righe = qk_min_stock_per_prodotto($this->pdo, $prodottoIdFiltro);
        } else {
            $righe = qk_min_stock_per_magazzino($this->pdo, $magazzinoId, $search);
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
            // Estratto in includes/magazzino.php (Fase 3, microtappa 3.3).
            $row = qk_upsert_scorta_minima($this->pdo, $prodottoId, $magazzinoId, $scortaMinima);

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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['quantita'] = $e->getMessage();
                    } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
    /**
     * Parsing condiviso del form trasferimento (pieno o diretto) — stesso set
     * di campi POST, stessa costruzione di $values/$header/$righe. Estratto
     * da createTransfer()/trasferimentoDiretto() (dedup post-audit §6.2: i
     * due metodi erano quasi identici, ~55 righe duplicate, differendo solo
     * nella funzione di servizio chiamata e nel messaggio di esito).
     *
     * @return array{0: array, 1: array, 2: array} [$values, $header, $righe]
     */
    private function parseTrasferimentoForm(): array
    {
        $values = [
            'magazzino_origine_id'      => post('magazzino_origine_id', ''),
            'magazzino_destinazione_id' => post('magazzino_destinazione_id', ''),
            'data_richiesta'            => post('data_richiesta', date('Y-m-d')),
            'note'                      => post('note', ''),
        ];

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

        $header = [
            'magazzino_origine_id'      => (int) $values['magazzino_origine_id'],
            'magazzino_destinazione_id' => (int) $values['magazzino_destinazione_id'],
            'data_richiesta'            => $values['data_richiesta'],
            'note'                      => $values['note'],
        ];

        return [$values, $header, $righe];
    }

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
                [$values, $header, $righe] = $this->parseTrasferimentoForm();

                try {
                    $id = qk_registra_trasferimento_diretto($this->pdo, $header, $righe, (int) current_user()['id']);
                    // Opzione A (§9.5): stesso effetto collaterale di createTransfer().
                    $_SESSION['magazzino_attivo_id'] = $header['magazzino_origine_id'];
                    redirect_with('warehouse/transfers/view?id=' . $id, 'success', 'Trasferimento diretto registrato: giacenza spostata istantaneamente.');
                } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
                [$values, $header, $righe] = $this->parseTrasferimentoForm();

                try {
                    $id = qk_insert_trasferimento($this->pdo, $header, $righe, (int) current_user()['id']);
                    // Opzione A (§9.5): la scelta esplicita dell'origine aggiorna
                    // la sessione come effetto collaterale — è la sede da cui
                    // l'operatore sta effettivamente lavorando in questo momento.
                    $_SESSION['magazzino_attivo_id'] = $header['magazzino_origine_id'];
                    redirect_with('warehouse/transfers/view?id=' . $id, 'success', 'Trasferimento creato in bozza.');
                } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
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
