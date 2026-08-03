<?php

declare(strict_types=1);

// D3.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 3): serve qk_uom_converti_riga() per
// convertire la quantità richiesta in Stock UoM prima di interrogare il motore di prezzo.
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/pricelists.php';
// Tappa 8 (PIANO - Eliminazione SQL Grezzo nei Controller, problema 4.C.2):
// Repository/Service per l'estrazione di apiImportPrezzi().
require_once __DIR__ . '/../Repositories/ListinoPrezziRepository.php';
require_once __DIR__ . '/../Services/ListinoImportService.php';

/**
 * SalesPricelistsController — Gestione Listini Vendita.
 *
 * Tabelle coinvolte:
 *   listini            — testata listino (nome, valuta, attivo, valido_dal/al)
 *   listini_prezzi     — righe (prodotto_id, quantita_minima, prezzo_unitario, sconto_perc)
 *   dettagli_clienti   — listino_id default per cliente (read-only qui)
 */
final class SalesPricelistsController extends BaseController
{
    /* ── Lista ─────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $filtro_attivo = get_param('attivo'); // '' | '1' | '0'

        $where = ["1=1"];
        $params = [];
        if ($search) {
            $where[] = "(l.nome LIKE :q OR l.descrizione LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($filtro_attivo !== '' && $filtro_attivo !== null) {
            $where[] = "l.attivo = :attivo";
            $params[':attivo'] = (int)$filtro_attivo;
        }

        $sortMap = ['nome' => 'l.nome', 'valuta' => 'l.valuta', 'dal' => 'l.valido_dal', 'al' => 'l.valido_al', 'articoli' => 'nr_articoli'];
        $pag = qk_pagination_params(array_keys($sortMap), 'nome', 'asc');
        $orderCol = $sortMap[$pag['sort']] ?? 'l.nome';
        $sqlWhere = implode(' AND ', $where);

        $pricelists = qk_listini_list_with_count($this->pdo, $sqlWhere, $params, $orderCol, $pag['dir'] === 'asc' ? 'ASC' : 'DESC');

        // Calcola lo stato effettivo di ciascun listino nel controller
        $oggi = date('Y-m-d');
        foreach ($pricelists as &$ls) {
            $isAttivo = (bool)$ls['attivo'];
            $inRange  = (!$ls['valido_dal'] || $ls['valido_dal'] <= $oggi)
                     && (!$ls['valido_al']  || $ls['valido_al']  >= $oggi);
            if ($isAttivo && $inRange) {
                $ls['stato_effettivo'] = 'active';
            } elseif (!$isAttivo) {
                $ls['stato_effettivo'] = 'disattivato';
            } else {
                $ls['stato_effettivo'] = 'expired';
            }
        }
        unset($ls);

        $total = count($pricelists);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_list.php', [
            'page_title'    => 'Listini vendita',
            'page_subtitle' => 'Gestione dei listini prezzi di vendita',
            'breadcrumbs'   => [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Listini vendita']],
            'page_actions'  => '<a href="' . url('sales/pricelists/create') . '" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus me-1"></i>Nuovo Listino</a>',
            'pricelists'    => $pricelists,
            'total'         => $total,
            'pag'           => $pag,
            'search'        => $search ?? '',
            'filtro_attivo' => $filtro_attivo ?? '',
        ]);
    }

    /* ── Crea ───────────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $errors = [];
        $values = [
            'nome'       => '',
            'descrizione'=> '',
            'valuta'     => 'EUR',
            'attivo'     => 1,
            'valido_dal' => date('Y-m-d'),
            'valido_al'  => '',
            'sconto_default_perc' => '0.00',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                    'sconto_default_perc' => (float)(str_replace(',', '.', post('sconto_default_perc') ?? '0')),
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Valuta deve essere un codice ISO di 3 lettere (es. EUR, USD).';
                }

                if (empty($errors)) {
                    try {
                        $newId = qk_listino_insert($this->pdo, $values);
                        redirect_with('sales/pricelists/edit?id=' . $newId, 'success', 'Listino creato. Ora puoi aggiungere i prezzi prodotto.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Nuovo listino vendita',
            'page_subtitle' => 'Crea un nuovo listino prezzi di vendita',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => 'Nuovo'],
            ],
            'errors'  => $errors,
            'values'  => $values,
            'isEdit'  => false,
            'items'   => [],
            'clienti_assegnati' => [],
        ]);
    }

    /* ── Modifica ───────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'Nessun listino specificato.');
        }

        $pricelist = qk_get_listino($this->pdo, $id);

        if (!$pricelist) {
            redirect_with('sales/pricelists', 'danger', 'Listino non trovato.');
        }

        $errors = [];
        $values = [
            'nome'        => $pricelist['nome'],
            'descrizione' => $pricelist['descrizione'] ?? '',
            'valuta'      => $pricelist['valuta'],
            'attivo'      => (int)$pricelist['attivo'],
            'valido_dal'  => $pricelist['valido_dal'] ?: '',
            'valido_al'   => $pricelist['valido_al'] ?: '',
            'is_default'  => (int)($pricelist['is_default'] ?? 0),
            'sconto_default_perc' => (float)($pricelist['sconto_default_perc'] ?? 0.0),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                    'is_default'  => (int)($pricelist['is_default'] ?? 0),
                    'sconto_default_perc' => (float)(str_replace(',', '.', post('sconto_default_perc') ?? '0')),
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Codice valuta non valido.';
                }

                if (empty($errors)) {
                    try {
                        if ($id === 1 || (int)($pricelist['is_default'] ?? 0) === 1) {
                            $values['nome'] = 'Prezzo Base (Catalogo)';
                            $values['attivo'] = 1;
                        }
                        qk_listino_update($this->pdo, $id, $values);
                        redirect_with('sales/pricelists/edit?id=' . $id, 'success', 'Listino aggiornato.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore: ' . $e->getMessage();
                    }
                }
            }
        }

        $pag = qk_pagination_params(['nome', 'sku_interno', 'quantita_minima', 'prezzo'], 'nome', 'asc');
        $limit  = $pag['limit'];
        $offset = $pag['offset'];
        $page   = $pag['page'];
        $sort   = $pag['sort'];
        $dir    = $pag['dir'];

        // Mappatura colonne ordinamento
        $sortColMap = [
            'nome'            => 'p.nome',
            'sku_interno'     => 'p.sku_interno',
            'quantita_minima' => 'lp.quantita_minima',
            'prezzo'          => 'lp.prezzo'
        ];
        $orderByCol = $sortColMap[$sort] ?? 'p.nome';

        $total = qk_listino_prezzi_count($this->pdo, $id);
        $pages = (int)ceil($total / $limit);

        $items = qk_get_listino_items($this->pdo, $id, $orderByCol, $dir, $limit, $offset);
        $clienti_assegnati = qk_get_clienti_assegnati_listino($this->pdo, $id);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Modifica listino vendita',
            'page_subtitle' => 'Gestisci il listino e i prezzi prodotto',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => e($pricelist['nome'])],
            ],
            'errors'            => $errors,
            'values'            => $values,
            'isEdit'            => true,
            'id'                => $id,
            'items'             => $items,
            'clienti_assegnati' => $clienti_assegnati,
            'pag'               => $pag,
            'page'              => $page,
            'pages'             => $pages,
            'total'             => $total,
            'sort'              => $sort,
            'dir'               => $dir,
        ]);
    }

    /* ── Elimina ────────────────────────────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('sales/pricelists', 'danger', 'Metodo non consentito.');
        }
        if (!csrf_verify()) {
            redirect_with('sales/pricelists', 'danger', 'Token di sicurezza invalido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'ID non valido.');
        }
        if ($id === 1) {
            redirect_with('sales/pricelists', 'danger', 'Il listino base non può essere eliminato.');
        }

        try {
            if (!qk_listino_delete($this->pdo, $id)) {
                redirect_with('sales/pricelists', 'danger', "Impossibile eliminare: il listino è in uso.");
            }
            redirect_with('sales/pricelists', 'success', 'Listino eliminato.');
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            redirect_with('sales/pricelists', 'danger', 'Impossibile eliminare: ' . $e->getMessage());
        }
    }

    /* ── API: salva riga prezzo ─────────────────────────────────────────────── */

    public function apiSaveRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoIdInput = $_POST['listino_id'] ?? '';
        $listinoId = ($listinoIdInput === '' || $listinoIdInput === 'null') ? null : (int)$listinoIdInput;
        $rigaId      = (int)($_POST['riga_id'] ?? 0);
        $prodottoId  = isset($_POST['prodotto_id']) ? (int)$_POST['prodotto_id'] : null;
        $qMin        = isset($_POST['quantita_minima']) ? (float)$_POST['quantita_minima'] : null;
        $prezzo      = isset($_POST['prezzo_unitario']) ? (float)$_POST['prezzo_unitario'] : null;
        $sconto      = isset($_POST['sconto_perc']) ? (float)$_POST['sconto_perc'] : null;
        $unitaMisuraId = isset($_POST['unita_misura_id']) && $_POST['unita_misura_id'] !== '' ? (int)$_POST['unita_misura_id'] : null;
        $note        = trim(post('note') ?? '');

        if (!$prodottoId || $prezzo === false || $prezzo === null) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti o non validi.']);
            exit;
        }

        // Verifica listino esiste se passato
        if ($listinoId !== null && !qk_get_listino($this->pdo, $listinoId)) {
            echo json_encode(['ok' => false, 'error' => 'Listino non trovato.']);
            exit;
        }

        $qMin   = ($qMin === false || $qMin === null) ? 0.0 : $qMin;
        $sconto = ($sconto === false || $sconto === null) ? 0.0 : $sconto;

        // Logical business validations
        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo unitario non può essere negativo.']);
            exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto deve essere compreso tra 0 e 100%.']);
            exit;
        }
        if ($qMin < 0) {
            echo json_encode(['ok' => false, 'error' => 'La quantità minima dello scaglione non può essere negativa.']);
            exit;
        }

        // D3.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 1/3): a ogni scrittura, calcolare e
        // salvare il valore canonico della soglia (sempre Stock UoM), insieme all'intento originale
        // (valore + unità così come scritti dall'utente) — unita_misura_id qui rappresenta sia
        // l'unità di quotazione del prezzo sia l'unità in cui l'utente ha scritto la soglia
        // (unico campo nell'interfaccia attuale: coerente col modello di input odierno).
        $qMinIntento = $qMin;
        $unitaSogliaId = $unitaMisuraId ?: null;
        $qMinStock = $qMin > 0
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaSogliaId, $qMin, 0.0)['quantita_stock']
            : 0.0;

        // Controllo duplicati esplicito (14/07/2026, PIANO Motore di Pricing Multi-UoM §7.4/§8):
        // questo endpoint non ne aveva mai avuto uno (a differenza degli equivalenti acquisti,
        // REVISIONE §6.15) — si affidava solo al vincolo UNIQUE del database, che ora è su
        // quantita_minima_stock (D1.2): senza questo controllo, un doppione mascherato (es. stesso
        // prodotto/listino con soglie in unità diverse ma stesso valore canonico) produrrebbe un
        // errore SQL grezzo invece di un messaggio comprensibile.
        if (qk_listino_prezzo_riga_esiste_duplicato($this->pdo, $listinoId, $prodottoId, $qMinStock, $rigaId ?: 0)) {
            echo json_encode(['ok' => false, 'error' => 'Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).']);
            exit;
        }

        try {
            $rigaData = [
                'prodotto_id'             => $prodottoId,
                'quantita_minima'         => $qMin,
                'prezzo_unitario'         => $prezzo,
                'sconto_perc'             => $sconto,
                'unita_misura_id'         => $unitaMisuraId ?: null,
                'note'                    => $note ?: null,
                'quantita_minima_intento' => $qMinIntento,
                'unita_misura_soglia_id'  => $unitaSogliaId,
                'quantita_minima_stock'   => $qMinStock,
            ];

            if ($rigaId) {
                // UPDATE
                // modificato_manualmente_il: aggiornato sempre su un salvataggio manuale (questo endpoint,
                // usato sia dalla griglia listino sia dal drawer prodotto), mai da apiImportPrezzi() —
                // vedi REVISIONE §6.12: evita che un import massivo sovrascriva silenziosamente un prezzo
                // corretto/negoziato a mano dopo l'ultimo import.
                qk_listino_prezzo_riga_update($this->pdo, $rigaId, $listinoId, $rigaData);
                $newId = $rigaId;
            } else {
                // INSERT
                $rigaData['listino_id'] = $listinoId;
                $newId = qk_listino_prezzo_riga_insert($this->pdo, $rigaData);
            }

            // Restituisce la riga aggiornata con nome prodotto e UoM
            $riga = qk_listino_prezzo_riga_dettaglio($this->pdo, $newId);

            echo json_encode(['ok' => true, 'riga' => $riga]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: elimina riga prezzo ───────────────────────────────────────────── */

    public function apiDeleteRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $rigaId = filter_input(INPUT_POST, 'riga_id', FILTER_VALIDATE_INT);
        if (!$rigaId) {
            echo json_encode(['ok' => false, 'error' => 'ID riga non valido.']);
            exit;
        }

        try {
            qk_listino_riga_delete($this->pdo, $rigaId);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: ricerca prodotti (per autocomplete righe) ────────────────────── */

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(get_param('q') ?? '');

        // Nessun limite di caratteri per favorire autocomplete istantaneo (dry-run o 1 carattere)
        echo json_encode(qk_pricelist_search_prodotti($this->pdo, $q));
        exit;
    }

    /* ── Metodo di servizio: ottieni prezzo vendita ─────────────────────────── */

    /**
     * Determina il prezzo di vendita per un prodotto dato un listino, una quantità e una data.
     * Strategia (analoga a getPurchasePrice):
     *  1. Cerca nei listini associati del cliente (in ordine di priorità) o nel listino specificato
     *  2. Se non trova, cade sul listino standard o ricarico su costo
     *
     * CONTRATTO (D3.1, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
     * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
     * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
     * attuale, apiOttieniPrezzo(), converte la quantità ricevuta dal client prima di invocarla.
     */
    public function getSalePrice(int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
    {
        // Refactoring 14/07/2026: logica estratta in includes/pricing.php (qk_get_sale_price()),
        // pura (PDO + parametri, nessun riferimento a HTTP), così riutilizzabile da script CLI,
        // cron, o altri moduli senza passare da questo controller. Nessuna modifica di
        // comportamento: stessa logica, stessi parametri, stesso valore di ritorno.
        return qk_get_sale_price($this->pdo, $prodottoId, $listinoId, $quantita, $dataRiferimento, $clienteId);
    }

    /* ── API endpoint: ottieni prezzo vendita ───────────────────────────────── */

    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $prodottoId = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $listinoId  = filter_input(INPUT_GET, 'listino_id',  FILTER_VALIDATE_INT);
        $clienteId  = filter_input(INPUT_GET, 'cliente_id',  FILTER_VALIDATE_INT);
        $quantita   = filter_input(INPUT_GET, 'quantita',    FILTER_VALIDATE_FLOAT);
        // D3.1 (PIANO Motore di Pricing Multi-UoM §5.1/§8 Fase 3): prima di questa modifica, `quantita`
        // arrivava come numero grezzo senza mai dichiarare in quale unità era espresso. Nuovo parametro
        // opzionale `unita_misura_id`: l'unità in cui il chiamante esprime `quantita` (Stock o UoM
        // commerciale del prodotto). Se assente, retrocompatibile: si assume già Stock UoM.
        $unitaRichiestaId = filter_input(INPUT_GET, 'unita_misura_id', FILTER_VALIDATE_INT) ?: null;
        $data       = get_param('data') ?: null;

        if (!$prodottoId) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto ID mancante.']);
            exit;
        }

        $listinoId = $listinoId ?: 0;
        $quantita = ($quantita === false || $quantita === null) ? 1.0 : $quantita;

        // Conversione autorevole (server-side, BCMath) in Stock UoM PRIMA di interrogare il motore
        // di prezzo: da qui in poi getSalePrice() riceve sempre e solo una quantità in Stock UoM,
        // comparabile con quantita_minima_stock indipendentemente da come l'utente l'ha scritta.
        $quantitaStock = $unitaRichiestaId
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaRichiestaId, $quantita, 0.0)['quantita_stock']
            : $quantita;

        $priceInfo = $this->getSalePrice($prodottoId, $listinoId, $quantitaStock, $data, $clienteId);

        $costo_unitario = 0.0;
        $rrp_min = null;
        $rrp_max = null;

        // Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md).
        $rrpData = qk_pricelist_get_rrp_costo($this->pdo, $prodottoId);
        $rrp_unita_misura_id = null;
        if ($rrpData) {
            $costo_unitario = (float)($rrpData['costo_unitario'] ?? 0.0);
            $rrp_min = $rrpData['rrp_min'] !== null ? (float)$rrpData['rrp_min'] : null;
            $rrp_max = $rrpData['rrp_max'] !== null ? (float)$rrpData['rrp_max'] : null;
            // Restituiamo la UoM nativa dell'RRP così il frontend sa se convertire o meno
            $rrp_unita_misura_id = $rrpData['rrp_unita_misura_id'] !== null
                ? (int)$rrpData['rrp_unita_misura_id']
                : null;
        }

        if ($priceInfo) {
            echo json_encode([
                'ok'                 => true,
                'prezzo_unitario'    => $priceInfo['prezzo_unitario'],
                'sconto_perc'        => $priceInfo['sconto_perc'],
                'valuta'             => $priceInfo['valuta'],
                'listino_id'         => $priceInfo['listino_id'],
                'prezzo_sorgente'    => $priceInfo['prezzo_sorgente'],
                'origine'            => $priceInfo['origine'],
                'costo_unitario'     => $costo_unitario,
                'rrp_min'            => $rrp_min,
                'rrp_max'            => $rrp_max,
                'rrp_unita_misura_id'=> $rrp_unita_misura_id,
                'unita_misura_id'    => $priceInfo['unita_misura_id'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo_unitario' => 0.0,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
                'origine'         => 'manuale',
                'unita_misura_id' => null,
            ]);
        }
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        exit;
    }

    /* ── API endpoint: ottieni tutti i clienti con stato assegnazione ───────── */

    public function apiClientiAssegnazione(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $listinoId = filter_input(INPUT_GET, 'listino_id', FILTER_VALIDATE_INT);
        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            // Ottieni tutti i clienti attivi
            $rows = qk_get_clienti_con_stato_assegnazione($this->pdo, $listinoId);

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'id' => (int)$row['id'],
                    'nome_cliente' => $row['nome_cliente'],
                    'partita_iva' => $row['partita_iva'] ?? '',
                    'assegnato' => ($row['assegnato_pivot'] || $row['assegnato_default']) ? true : false,
                ];
            }

            echo json_encode(['ok' => true, 'clienti' => $result]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API endpoint: salva assegnazioni massive ───────────────────────────── */

    public function apiAssegnaClienti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $clienteIds = array_values(array_unique(array_map('intval', $_POST['cliente_ids'] ?? [])));

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Sincronizzazione differenziale (Diff-Sync) — corretto il 13/07/2026 (segnalazione #17
            // / REVISIONE, "Cancellazione distruttiva con perdita di scadenze Listini"). La versione
            // precedente cancellava e reinseriva TUTTE le righe di clienti_listini per questo listino
            // a ogni salvataggio, azzerando priorita/data_inizio/data_fine anche per i clienti che
            // restavano assegnati — quei campi sono scritti altrove (scheda del singolo cliente,
            // includes/anagrafiche.php) e qui venivano silenziosamente persi. Vedi
            // qk_update_clienti_listino() (includes/pricelists.php) per i dettagli: tocca solo le
            // righe che cambiano davvero.
            qk_update_clienti_listino($this->pdo, $listinoId, $clienteIds);

            $this->pdo->commit();

            // Ricarica la lista aggiornata dei clienti assegnati per restituirla al frontend
            $clienti_assegnati = qk_get_clienti_assegnati_dopo_sync($this->pdo, $listinoId);

            echo json_encode(['ok' => true, 'clienti_assegnati' => $clienti_assegnati]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiImportPrezzi(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']); exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $rows      = json_decode(post('rows') ?? '[]', true);

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'ID listino non specificato.']); exit;
        }
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        // Verifica listino
        if (!qk_get_listino($this->pdo, $listinoId)) {
            echo json_encode(['ok' => false, 'error' => 'Listino non trovato.']); exit;
        }

        try {
            $repository = new \App\Repositories\ListinoPrezziRepository($this->pdo);
            $service    = new \App\Services\ListinoImportService($this->pdo, $repository);
            $result     = $service->importaRighe($listinoId, $rows);

            echo json_encode([
                'ok' => true,
                'inserted' => $result['inserted'],
                'skipped' => $result['skipped'],
                'removed' => $result['removed'],
                'errors' => $result['errors'],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
