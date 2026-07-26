<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/correzioni_ordini.php';

/**
 * CorrezioniOrdiniController — richieste di correzione su un ordine cliente
 * già fatturato (PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7,
 * Tappa 9, 26/07/2026). Mirror di SpedizioniController.php: sotto-flusso
 * dedicato di ordini_clienti, controller separato invece che dentro
 * SalesOrdersController (§7.7.2).
 */
final class CorrezioniOrdiniController extends BaseController
{
    /**
     * GET /sales/orders/correzioni/new?ordine_id=X — form di richiesta
     * correzione per un ordine già fatturato.
     */
    public function nuova(): void
    {
        $ordineId = (int) get_param('ordine_id');
        if ($ordineId <= 0) redirect(url('sales/orders'));

        $ordine = qk_get_ordine_per_spedizione($this->pdo, $ordineId);
        if (!$ordine) {
            flash_set('error', 'Ordine non trovato.');
            redirect(url('sales/orders'));
        }
        if (($ordine['stato_fatturazione'] ?? '') !== 'fatturato') {
            flash_set('error', 'Questo ordine non è fatturato: usa "Crea Nuova Versione" per correggerlo.');
            redirect(url('sales/orders/edit') . '?id=' . $ordineId);
        }

        $righeOrdine = $this->pdo->prepare('SELECT * FROM ordini_clienti_righe WHERE ordine_id = ? ORDER BY id');
        $righeOrdine->execute([$ordineId]);
        $righe = $righeOrdine->fetchAll(PDO::FETCH_ASSOC);

        $page_title  = 'Richiedi Correzione — ' . $ordine['numero_ordine'];
        $breadcrumbs = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Ordini',  'url' => url('sales/orders')],
            ['label' => $ordine['numero_ordine'], 'url' => url('sales/orders/edit') . '?id=' . $ordineId],
            ['label' => 'Richiedi Correzione'],
        ];

        $this->page(APP_ROOT_DIR . '/app/views/sales/orders/_correzione_nuova.php', get_defined_vars());
    }

    /**
     * GET /sales/orders/correzioni?ordine_id=X — elenco richieste per un
     * ordine (mostrato nella scheda ordine stessa in questa v1, non una
     * pagina a sé — vedi vista _detail.php).
     */
    public function index(): void
    {
        redirect(url('sales/orders'));
    }

    /**
     * POST /sales/orders/correzioni/action — crea/approva/rifiuta una
     * richiesta. Stesso stile di SalesOrdersController::action().
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('sales/orders'));
        }

        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'richiedi':
                    $ordineId = (int) post('ordine_id', '0');
                    $motivo   = post('motivo', '');
                    $righe    = [];
                    foreach ((array) ($_POST['righe'] ?? []) as $rigaOrdineId => $dati) {
                        if (!isset($dati['seleziona'])) continue; // riga non toccata dall'operatore
                        $righe[] = [
                            'riga_ordine_id'           => (int) $rigaOrdineId,
                            'quantita_corretta'        => (float) ($dati['quantita_corretta'] ?? 0),
                            'prezzo_unitario_corretta' => (float) ($dati['prezzo_unitario_corretta'] ?? 0),
                        ];
                    }
                    qk_correzione_ordine_richiedi($this->pdo, $ordineId, $motivo !== '' ? $motivo : null, $righe, (int) current_user()['id']);
                    flash_set('success', 'Richiesta di correzione creata. In attesa di approvazione.');
                    redirect(url('sales/orders/edit') . '?id=' . $ordineId);
                    return;

                case 'approva':
                    if (!can('approvare_correzioni')) {
                        flash_set('error', 'Non hai il permesso di approvare correzioni.');
                        redirect(url('sales/orders'));
                        return;
                    }
                    $richiestaId = (int) post('richiesta_id', '0');
                    $ordineId    = (int) post('ordine_id', '0');
                    $riferimento = post('riferimento_doc_esterno', '');
                    qk_correzione_ordine_approva($this->pdo, $richiestaId, $riferimento, (int) current_user()['id']);
                    flash_set('success', 'Richiesta approvata.');
                    redirect(url('sales/orders/edit') . '?id=' . $ordineId);
                    return;

                case 'rifiuta':
                    if (!can('approvare_correzioni')) {
                        flash_set('error', 'Non hai il permesso di gestire correzioni.');
                        redirect(url('sales/orders'));
                        return;
                    }
                    $richiestaId  = (int) post('richiesta_id', '0');
                    $ordineId     = (int) post('ordine_id', '0');
                    $motivoRifiuto = post('motivo_rifiuto', '');
                    qk_correzione_ordine_rifiuta($this->pdo, $richiestaId, $motivoRifiuto, (int) current_user()['id']);
                    flash_set('success', 'Richiesta rifiutata.');
                    redirect(url('sales/orders/edit') . '?id=' . $ordineId);
                    return;

                default:
                    flash_set('error', 'Azione non riconosciuta.');
                    redirect(url('sales/orders'));
                    return;
            }
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('error', $e->getMessage());
            $ordineIdFallback = (int) post('ordine_id', '0');
            redirect(url('sales/orders/edit') . '?id=' . $ordineIdFallback);
        }
    }
}
