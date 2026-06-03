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
    /* ── Stock overview ───────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $filter = get_param('filter');
        if (!in_array($filter, ['in_stock', 'all', 'sottoscorta', 'esauriti', 'in_arrivo'], true)) {
            $filter = 'in_stock'; // default: mostra solo prodotti con giacenza > 0
        }

        $stock = [];
        $ordini_in_arrivo = [];
        
        // Calcola il numero di ordini in arrivo per il contatore della pill
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
        } else {
            $stock = qk_stock_list($this->pdo, $search, $filter);
        }

        $page_title    = 'Magazzino';
        $page_subtitle = 'Panoramica giacenze';
        $breadcrumbs   = [['label' => 'Magazzino']];
        $page_actions  = '<a href="' . url('warehouse/movements/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo movimento
        </a>';
        $extra_css = ['warehouse.css'];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/warehouse/_stock.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Storico movimenti ────────────────────────────────────────────────── */

    public function movements(): void
    {
        $search      = get_param('q');
        $tipo        = get_param('tipo');
        $prodotto_id = (int) get_param('prodotto_id');

        $tipiValidi = ['', 'carico', 'scarico', 'rettifica', 'reso_cliente', 'reso_fornitore', 'riserva', 'svincolo'];
        if (!in_array($tipo, $tipiValidi, true)) $tipo = '';

        $page   = max(1, (int) get_param('page', '1'));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $result = qk_movements_list($this->pdo, $search, $tipo, $prodotto_id, $limit, $offset);
        $movements = $result['rows'];
        $total     = $result['total'];
        $pages     = (int) ceil($total / $limit);

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

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/warehouse/_movements.php';
        require INCLUDES_DIR . '/footer.php';
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
                    if ($info && $info['traccia_lotti']) {
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

                        // Costruisce il payload per qk_insert_movimento_manuale
                        $movData = [
                            'tipo_movimento'      => $values['tipo_movimento'],
                            'prodotto_id'         => (int) $values['prodotto_id'],
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

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/warehouse/_movement_form.php';
        require INCLUDES_DIR . '/footer.php';
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
