<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_receipts.php';

/**
 * ReceiptsController — Ricezioni Merce (ciclo acquisti v2).
 *
 * Route   Action
 * ──────────────────────────────────────────────────────────────────────────
 * GET  /purchases/receipts              index()    Elenco ricezioni
 * GET  /purchases/receipts/create       create()   Apre nuova ricezione da ordine
 * GET  /purchases/receipts/edit?id=N    edit()     Form di conferma ricezione
 * POST /purchases/receipts/conferma     conferma() Conferma ed esegue gli aggiornamenti
 */
class ReceiptsController extends BaseController
{
    public function index(): void
    {
        $search    = get_param('q', '');
        $stato     = get_param('stato', '');
        $dal       = get_param('dal', '');
        $al        = get_param('al', '');
        $ordine_id = (int) get_param('ordine_id', '0');

        $pag            = qk_pagination_params(['data','numero','fornitore','ordine','stato'], 'data', 'desc');
        $total          = qk_ricezioni_count($this->pdo, $search, $stato, $dal, $al, $ordine_id);
        $ricezioni_list = qk_ricezioni_list($this->pdo, $search, $stato, $dal, $al, $ordine_id, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
        $page_title     = 'Ricezioni Merce';
        $breadcrumbs = [
            ['label' => 'Acquisti'],
            ['label' => 'Ricezioni Merce']
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/purchases/receipts/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /**
     * GET /purchases/receipts/create?ordine_id=N
     * Apre una nuova bozza di ricezione pre-compilata e reindirizza al form.
     */
    public function create(): void
    {
        $ordine_id = (int) get_param('ordine_id', '0');
        if ($ordine_id <= 0) redirect(url('purchases/receipts'));

        try {
            $id = qk_ricezione_apri($this->pdo, $ordine_id, (int)current_user()['id']);
            redirect(url('purchases/receipts/edit') . '?id=' . $id);
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            redirect(url('purchases/orders/edit') . '?id=' . $ordine_id);
        }
    }

    /**
     * Form di conferma ricezione. L'utente verifica/corregge le quantità e i lotti.
     */
    public function edit(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) redirect(url('purchases/receipts'));

        $detail = qk_ricezione_detail($this->pdo, $id);
        if (!$detail) redirect(url('purchases/receipts'));

        // Pre-query lotti for each product to comply with MVC pattern (no SQL in views)
        if (!empty($detail['righe'])) {
            $stLotti = $this->pdo->prepare('SELECT id, codice_lotto_interno, data_scadenza FROM lotti WHERE prodotto_id = ?');
            foreach ($detail['righe'] as &$r) {
                if ($r['traccia_lotti'] == 1) {
                    $stLotti->execute([(int)$r['prodotto_id']]);
                    $r['lotti'] = $stLotti->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $r['lotti'] = [];
                }
            }
            unset($r);
        }

        $page_title = 'Ricezione ' . $detail['ricezione']['numero'];
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/receipts')],
            ['label' => 'Dettaglio Ricezione']
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/purchases/receipts/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /**
     * POST /purchases/receipts/action
     * Body: id, azione ('annulla')
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/receipts'));
        }

        $id = (int) post('id', '0');

        $st = $this->pdo->prepare('SELECT ordine_id FROM ricezioni_testata WHERE id = ?');
        $st->execute([$id]);
        $ordine_id = (int) ($st->fetchColumn() ?: 0);

        try {
            switch (post('azione', '')) {
                case 'annulla':
                    qk_ricezione_annulla($this->pdo, $id);
                    flash_set('success', 'Ricezione annullata.');
                    break;

                default:
                    flash_set('danger', "Azione '" . post('azione', '') . "' non riconosciuta.");
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }

        redirect($ordine_id > 0 ? url('purchases/orders/edit') . '?id=' . $ordine_id : url('purchases/receipts'));
    }

    /**
     * POST /purchases/receipts/conferma
     * Conferma la ricezione e aggiorna giacenze/lotti/ordine.
     */
    public function conferma(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/receipts'));
        }

        $id = (int) post('ricezione_id', '0');
        if ($id <= 0) redirect(url('purchases/receipts'));

        // Costruisce il mapping righe_input da $_POST
        $righeInput = $this->readPostRighe();

        try {
            qk_ricezione_conferma($this->pdo, $id, $righeInput, (int)current_user()['id']);
            flash_set('success', 'Ricezione confermata. Giacenze aggiornate.');

            // Recupera l'ordine padre per reindirizzare
            $st = $this->pdo->prepare('SELECT ordine_id FROM ricezioni_testata WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            redirect(url('purchases/orders/edit') . '?id=' . ($row['ordine_id'] ?? 0));

        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            redirect(url('purchases/receipts/edit') . '?id=' . $id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER PRIVATI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Costruisce la mappa righe_input dal POST.
     *
     * Il form invia:
     *   riga_id[]              = ID della riga ricezione
     *   riga_qta_accettata[]   = quantità accettata
     *   riga_qta_rifiutata[]   = quantità rifiutata
     *   riga_motivo_rifiuto[]  = motivo rifiuto
     *   riga_lotto_id[]        = ID lotto esistente (vuoto = nuovo lotto)
     *   lotto_codice_interno[] = codice nuovo lotto
     *   lotto_codice_fornitore[] = codice lotto fornitore
     *   lotto_data_scadenza[]  = data scadenza nuovo lotto
     *   lotto_numero_ddt[]     = numero DDT
     *
     * Ritorna: [riga_id => ['quantita_accettata'=>…, 'lotto'=>[…]], …]
     */
    private function readPostRighe(): array
    {
        $righeInput = [];

        $rigaIds       = $_POST['riga_id']              ?? [];
        $qtaAcc        = $_POST['riga_qta_accettata']    ?? [];
        $qtaRif        = $_POST['riga_qta_rifiutata']    ?? [];
        $motivi        = $_POST['riga_motivo_rifiuto']   ?? [];
        $lottoIds      = $_POST['riga_lotto_id']         ?? [];
        $lottoCodInt   = $_POST['lotto_codice_interno']  ?? [];
        $lottoCodFor   = $_POST['lotto_codice_fornitore'] ?? [];
        $lottoScad     = $_POST['lotto_data_scadenza']   ?? [];
        $lottoDdt      = $_POST['lotto_numero_ddt']      ?? [];

        foreach ($rigaIds as $i => $riga_id) {
            $riga_id = (int)$riga_id;
            if ($riga_id <= 0) continue;

            $entry = [
                'quantita_accettata' => (string)($qtaAcc[$i] ?? '0'),
                'quantita_rifiutata' => (string)($qtaRif[$i] ?? '0'),
                'motivo_rifiuto'     => (string)($motivi[$i] ?? ''),
                'lotto'              => [],
            ];

            $lottoId = (int)($lottoIds[$i] ?? 0);
            if ($lottoId > 0) {
                $entry['lotto'] = ['id' => $lottoId];
            } elseif (!empty($lottoCodInt[$i])) {
                $entry['lotto'] = [
                    'codice_lotto_interno'   => (string)$lottoCodInt[$i],
                    'codice_lotto_fornitore' => (string)($lottoCodFor[$i] ?? ''),
                    'data_scadenza'          => (string)($lottoScad[$i] ?? ''),
                    'numero_ddt'             => (string)($lottoDdt[$i] ?? ''),
                ];
            }

            $righeInput[$riga_id] = $entry;
        }

        return $righeInput;
    }
}
