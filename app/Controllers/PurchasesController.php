<?php

declare(strict_types=1);

// D3.2 (PIANO Motore di Pricing Multi-UoM §8 Fase 3): serve qk_uom_converti_riga() per
// convertire la quantità richiesta in Stock UoM prima di interrogare il motore di prezzo,
// e per calcolare/salvare il valore canonico delle soglie a ogni scrittura.
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/pricing.php';
require_once __DIR__ . '/../../includes/purchases_pricelists.php';

final class PurchasesController extends BaseController
{
    public function dashboard(): void
    {
        $kpi           = qk_purchases_dashboard_kpi($this->pdo);
        $recent_rfqs   = qk_purchases_recent_rfqs($this->pdo);
        $recent_orders = qk_purchases_recent_orders($this->pdo);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/purchases_dashboard.php', [
            'page_title'     => 'Dashboard Acquisti',
            'page_subtitle'  => 'Panoramica operativa acquisti e approvvigionamento',
            'breadcrumbs'    => [['label' => 'Acquisti']],
            'rfq_bozza'      => $kpi['rfq_bozza'],
            'rfq_inviata'    => $kpi['rfq_inviata'],
            'ordini_attivi'  => $kpi['ordini_attivi'],
            'listini_attivi' => $kpi['listini_attivi'],
            'recent_rfqs'    => $recent_rfqs,
            'recent_orders'  => $recent_orders,
        ]);
    }

    /* ── Listini acquisto ────────────────────────────────────────────────── */

    public function indexPricelists(): void
    {
        $search = get_param('q');

        $pricelists = qk_listini_acquisto_cerca($this->pdo, $search);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_list.php', [
            'page_title'    => 'Listini acquisto',
            'page_subtitle' => "Gestione dei listini di acquisto e delle tariffe concordate con i fornitori",
            'breadcrumbs'   => [['label' => 'Acquisti', 'url' => url('purchases/rfq')], ['label' => 'Listini acquisto']],
            'pricelists'    => $pricelists,
            'search'        => $search,
        ]);
    }

    public function createPricelist(): void
    {
        $errors = [];
        $values = [
            'fornitore_id'    => '',
            'fornitore_label' => '',
            'nome_listino'    => '',
            'valido_dal'      => '',
            'valido_al'       => '',
            'note'            => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'fornitore_id'    => post('fornitore_id'),
                    'fornitore_label' => post('fornitore_label'),
                    'nome_listino'    => trim(post('nome_listino') ?? ''),
                    'valido_dal'      => post('valido_dal') ?: null,
                    'valido_al'       => post('valido_al') ?: null,
                    'note'            => post('note'),
                ];

                if (empty($values['fornitore_id'])) {
                    $errors['fornitore_id'] = 'Fornitore obbligatorio.';
                }
                if (empty($values['nome_listino'])) {
                    $errors['nome_listino'] = 'Nome listino obbligatorio.';
                }

                if (empty($errors)) {
                    try {
                        $newId = qk_listino_acquisto_crea($this->pdo, $values);
                        redirect_with('purchases/pricelists/edit?id=' . $newId, 'success', 'Listino acquisto salvato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_form.php', [
            'page_title'    => 'Nuovo listino acquisto',
            'page_subtitle' => 'Crea un nuovo listino acquisto per un fornitore',
            'breadcrumbs'   => [
                ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
                ['label' => 'Listini acquisto', 'url' => url('purchases/pricelists')],
                ['label' => 'Nuovo']
            ],
            'errors'        => $errors,
            'values'        => $values,
            'isEdit'        => false,
            'items'         => [],
        ]);
    }

    public function editPricelist(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('purchases/pricelists', 'danger', 'Nessun listino specificato.');
        }

        $pricelist = qk_listino_acquisto_get($this->pdo, $id);

        if (!$pricelist) {
            redirect_with('purchases/pricelists', 'danger', 'Listino non trovato.');
        }

        $errors = [];
        $values = [
            'fornitore_id'    => $pricelist['fornitore_id'],
            'fornitore_label' => $pricelist['fornitore_label'],
            'nome_listino'    => $pricelist['nome_listino'],
            'valido_dal'      => $pricelist['valido_dal'] ?: '',
            'valido_al'       => $pricelist['valido_al'] ?: '',
            'note'            => $pricelist['note'] ?? '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'fornitore_id'    => post('fornitore_id'),
                    'fornitore_label' => post('fornitore_label'),
                    'nome_listino'    => trim(post('nome_listino') ?? ''),
                    'valido_dal'      => post('valido_dal') ?: null,
                    'valido_al'       => post('valido_al') ?: null,
                    'note'            => post('note'),
                ];

                if (empty($values['fornitore_id'])) {
                    $errors['fornitore_id'] = 'Fornitore obbligatorio.';
                }
                if (empty($values['nome_listino'])) {
                    $errors['nome_listino'] = 'Nome listino obbligatorio.';
                }

                if (empty($errors)) {
                    try {
                        qk_listino_acquisto_aggiorna($this->pdo, $id, $values);
                        redirect_with('purchases/pricelists/edit?id=' . $id, 'success', 'Listino acquisto aggiornato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
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
            'quantita_minima' => 'pfp.quantita_minima',
            'prezzo'          => 'pfp.prezzo'
        ];
        $orderByCol = $sortColMap[$sort] ?? 'p.nome';

        // Count per la paginazione
        $total = qk_listino_acquisto_righe_count($this->pdo, $id);
        $pages = (int)ceil($total / $limit);

        $items = qk_listino_acquisto_righe_paginate($this->pdo, $id, $orderByCol, $dir, $limit, $offset);

        $this->page(APP_ROOT_DIR . '/app/views/purchases/pricelists_form.php', [
            'page_title'    => 'Modifica listino acquisto',
            'page_subtitle' => 'Gestisci i dettagli del listino ed esamina gli articoli associati',
            'breadcrumbs'   => [
                ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
                ['label' => 'Listini acquisto', 'url' => url('purchases/pricelists')],
                ['label' => $pricelist['nome_listino']]
            ],
            'errors'        => $errors,
            'values'        => $values,
            'isEdit'        => true,
            'id'            => $id,
            'items'         => $items,
            'pag'           => $pag,
            'page'          => $page,
            'pages'         => $pages,
            'total'         => $total,
            'sort'          => $sort,
            'dir'           => $dir,
        ]);
    }

    public function deletePricelist(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('purchases/pricelists', 'danger', 'Metodo non consentito.');
        }
        if (!csrf_verify()) {
            redirect_with('purchases/pricelists', 'danger', 'Token di sicurezza invalido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('purchases/pricelists', 'danger', 'Identificativo non valido.');
        }

        try {
            qk_listino_acquisto_disattiva($this->pdo, $id);
            redirect_with('purchases/pricelists', 'success', 'Listino acquisto eliminato con successo.');
        } catch (Throwable $e) {
            redirect_with('purchases/pricelists', 'danger', "Impossibile eliminare il listino: " . $e->getMessage());
        }
    }

    /**
     * Trova il prezzo di acquisto corretto per un prodotto, un fornitore e una quantità.
     * Restituisce un array con i dettagli del prezzo e la descrizione della sorgente.
     */
    /**
     * CONTRATTO (D3.2, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
     * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
     * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
     * attuale, apiOttieniPrezzo(), converte la quantità ricevuta dal client prima di invocarla.
     */
    public function getPurchasePrice(int $prodottoId, int $fornitoreId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
    {
        // Refactoring 14/07/2026: logica estratta in includes/pricing.php (qk_get_purchase_price()),
        // pura (PDO + parametri, nessun riferimento a HTTP), così riutilizzabile da script CLI,
        // cron, o altri moduli senza passare da questo controller. Nessuna modifica di
        // comportamento (a parte l'unità nella label "Scaglione >=", già introdotta oggi anche
        // sul ramo "Prezzo base", coerente col ramo "Listino" già corretto sopra).
        return qk_get_purchase_price($this->pdo, $prodottoId, $fornitoreId, $quantita, $dataRiferimento);
    }

    /**
     * Endpoint AJAX per la determinazione dinamica del prezzo.
     */
    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        
        $prodottoId  = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $fornitoreId = filter_input(INPUT_GET, 'fornitore_id', FILTER_VALIDATE_INT);
        $quantita    = filter_input(INPUT_GET, 'quantita', FILTER_VALIDATE_FLOAT);
        // D3.2 (PIANO Motore di Pricing Multi-UoM §5.1/§8 Fase 3): nuovo parametro opzionale,
        // stesso principio di SalesPricelistsController::apiOttieniPrezzo() — l'unità in cui il
        // chiamante esprime `quantita`. Assente = retrocompatibile, assume già Stock UoM.
        $unitaRichiestaId = filter_input(INPUT_GET, 'unita_misura_id', FILTER_VALIDATE_INT) ?: null;
        $data        = get_param('data') ?: null;

        if ($quantita === false || $quantita === null) {
            $quantita = 1.0;
        }

        if (!$prodottoId || !$fornitoreId) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        $quantitaStock = $unitaRichiestaId
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaRichiestaId, $quantita, 0.0)['quantita_stock']
            : $quantita;

        $priceInfo = $this->getPurchasePrice($prodottoId, $fornitoreId, $quantitaStock, $data);

        if ($priceInfo) {
            echo json_encode([
                'ok'                  => true,
                'prezzo'              => [
                    'prezzo_netto'   => $priceInfo['prezzo'],
                    'sconto_perc'    => $priceInfo['sconto_perc'],
                    'sku_fornitore'  => $priceInfo['sku_fornitore'],
                ],
                'prezzo_valore'       => $priceInfo['prezzo'],
                'sconto_perc'         => $priceInfo['sconto_perc'],
                'sku_fornitore'       => $priceInfo['sku_fornitore'],
                'listino_acquisto_id' => $priceInfo['listino_acquisto_id'],
                'prezzo_sorgente'     => $priceInfo['prezzo_sorgente'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo'          => [
                    'prezzo_netto'   => 0.0,
                    'sconto_perc'    => 0.0,
                    'sku_fornitore'  => '',
                ],
                'prezzo_valore'   => 0.0,
                'sconto_perc'     => 0.0,
                'sku_fornitore'   => '',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
            ]);
        }
        exit;
    }

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(get_param('q') ?? '');

        echo json_encode(qk_prodotti_cerca_per_listino_acquisto($this->pdo, $q));
        exit;
    }

    public function apiSaveRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId     = filter_input(INPUT_POST, 'listino_acquisto_id', FILTER_VALIDATE_INT);
        $rigaId        = filter_input(INPUT_POST, 'riga_id',    FILTER_VALIDATE_INT); // 0 = nuova
        $prodottoId    = filter_input(INPUT_POST, 'prodotto_id', FILTER_VALIDATE_INT);
        $qMin          = filter_input(INPUT_POST, 'quantita_minima', FILTER_VALIDATE_FLOAT);
        $prezzo        = filter_input(INPUT_POST, 'prezzo', FILTER_VALIDATE_FLOAT);
        $sconto        = filter_input(INPUT_POST, 'sconto_perc', FILTER_VALIDATE_FLOAT);
        $validoDal     = post('valido_dal') ?: null;
        $validoAl      = post('valido_al') ?: null;
        $note          = trim(post('note') ?? '');
        $skuFornitore  = trim(post('sku_fornitore') ?? '');

        if (!$listinoId || !$prodottoId || $prezzo === false || $prezzo === null) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti o non validi.']);
            exit;
        }

        $qMin   = ($qMin === false || $qMin === null) ? 0.0 : $qMin;
        $sconto = ($sconto === false || $sconto === null) ? 0.0 : $sconto;

        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo non può essere negativo.']);
            exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto percentuale deve essere compreso tra 0 e 100.']);
            exit;
        }

        $umIdPost = filter_input(INPUT_POST, 'unita_misura_id', FILTER_VALIDATE_INT);

        try {
            $riga = qk_listino_acquisto_riga_salva($this->pdo, [
                'listino_acquisto_id' => $listinoId,
                'riga_id'             => $rigaId,
                'prodotto_id'         => $prodottoId,
                'quantita_minima'     => $qMin,
                'prezzo'              => $prezzo,
                'sconto_perc'         => $sconto,
                'valido_dal'          => $validoDal,
                'valido_al'           => $validoAl,
                'note'                => $note,
                'sku_fornitore'       => $skuFornitore,
                'unita_misura_id'     => $umIdPost ?: null,
            ]);
            echo json_encode(['ok' => true, 'riga' => $riga]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

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
            qk_listino_acquisto_riga_elimina($this->pdo, $rigaId);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
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

        $listinoId = filter_input(INPUT_POST, 'listino_acquisto_id', FILTER_VALIDATE_INT);
        $rows      = json_decode(post('rows') ?? '[]', true);

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'ID listino non specificato.']); exit;
        }
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        try {
            $result = qk_listini_acquisto_importa_prezzi($this->pdo, $listinoId, $rows);
            echo json_encode([
                'ok'       => true,
                'inserted' => $result['inserted'],
                'skipped'  => $result['skipped'],
                'expired'  => $result['expired'],
                'errors'   => $result['errors'],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
