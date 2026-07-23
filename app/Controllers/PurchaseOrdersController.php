<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_orders.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once INCLUDES_DIR . '/word_helper.php';
require_once INCLUDES_DIR . '/magazzino.php';
// Spostato in testa dal case 'invia' del metodo action() — vedi Revisione Ciclo
// Acquisti §3bis A.2 (require_once condizionale dentro uno switch).
require_once INCLUDES_DIR . '/purchases_receipts.php';

/**
 * PurchaseOrdersController — Ordini di Acquisto (ciclo acquisti v2).
 *
 * Route   Action
 * ──────────────────────────────────────────────────────────────────────────
 * GET  /purchases/orders              index()    Elenco ordini
 * GET  /purchases/orders/create       create()   Genera ordine da offerta (redirect)
 * GET  /purchases/orders/edit?id=N    edit()     Dettaglio / form modifica
 * POST /purchases/orders/edit?id=N    edit()     Salva modifiche note/riferimenti
 * POST /purchases/orders/action       action()   approva | invia | annulla
 * GET  /purchases/orders/pdf?id=N     pdf()      Stampa PDF
 */
class PurchaseOrdersController extends BaseController
{
    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');

        $pag         = qk_pagination_params(['data','numero','fornitore','stato','consegna','totale'], 'data', 'desc');
        $total       = qk_ordini_count($this->pdo, $search, $stato, $dal, $al);
        $ordini_list = qk_ordini_list($this->pdo, $search, $stato, $dal, $al, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
        $page_title  = 'Ordini di Acquisto';
        $breadcrumbs = [
            ['label' => 'Acquisti'],
            ['label' => 'Ordini di Acquisto']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/orders/_list.php', get_defined_vars());
    }

    /**
     * GET /purchases/orders/create?offerta_id=N
     * Genera un ordine da offerta accettata e reindirizza al dettaglio.
     * Non ha form proprio: l'ordine viene creato da PurchasesQuotesController::action(accetta+genera).
     * Questo metodo serve come fallback diretto.
     */
    public function create(): void
    {
        $offerta_id = (int) get_param('offerta_id', '0');
        if ($offerta_id <= 0) {
            redirect(url('purchases/orders'));
        }
        try {
            $id = qk_ordine_da_offerta($this->pdo, $offerta_id, (int)current_user()['id']);
            redirect(url('purchases/orders/edit') . '?id=' . $id . '&saved=1');
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('danger', $e->getMessage());
            redirect(url('purchases/quotes/edit') . '?id=' . $offerta_id);
        }
    }

    /**
     * Dettaglio ordine. POST: aggiorna solo i campi testuali (note, riferimenti).
     * Quantità e prezzi non sono modificabili dopo la generazione.
     */
    public function edit(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) redirect(url('purchases/orders'));

        $detail = qk_ordine_detail($this->pdo, $id);
        if (!$detail) redirect(url('purchases/orders'));

        $errors = [];

        $isTerminalState = in_array($detail['ordine']['stato'], ['completato', 'annullato'], true);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($isTerminalState) {
                $errors['_global'] = 'Accesso Negato: Impossibile modificare un documento chiuso e archiviato.';
            } elseif (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $note_fornitore     = post('note_fornitore', '');
                $note_interne       = post('note_interne', '');
                $rif_fornitore      = post('riferimento_fornitore', '');
                $altri_rif          = post('altri_riferimenti', '');
                $data_cons_prevista = post('data_consegna_prevista', '');
                // Magazzino destinazione (Piano Multi-Magazzino §2.7/§3.4): modificabile
                // finché l'ordine non è in stato terminale, come gli altri campi qui.
                $magazzino_destinazione_id = post('magazzino_destinazione_id', '') !== ''
                    ? (int) post('magazzino_destinazione_id')
                    : null;

                try {
                    // SQL spostato in qk_ordine_aggiorna_note() (includes/purchases_orders.php)
                    // — era scritto a mano qui, vedi Revisione Ciclo Acquisti §3bis A.1.
                    qk_ordine_aggiorna_note($this->pdo, $id, [
                        'note_fornitore' => $note_fornitore,
                        'note_interne' => $note_interne,
                        'riferimento_fornitore' => $rif_fornitore,
                        'altri_riferimenti' => $altri_rif,
                        'data_consegna_prevista' => $data_cons_prevista !== '' ? $data_cons_prevista : null,
                        'magazzino_destinazione_id' => $magazzino_destinazione_id,
                    ]);
                    $detail = qk_ordine_detail($this->pdo, $id);
                    flash_set('success', 'Ordine aggiornato.');
                } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                    $errors['_global'] = $e->getMessage();
                }
            }
        }

        $saved      = get_param('saved', '') === '1';
        $magazzini_options = qk_magazzini_list($this->pdo, true);
        $page_title = 'Ordine ' . $detail['ordine']['numero'];
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/orders')],
            ['label' => 'Dettaglio Ordine']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/orders/_detail.php', get_defined_vars());
    }

    /**
     * POST /purchases/orders/action
     * Body: id, azione ('approva' | 'invia' | 'annulla')
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/orders'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'approva':
                    qk_ordine_approva($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Ordine approvato.');
                    break;

                case 'invia':
                    // Verification
                    if (!qk_ordine_check_righe_valide($this->pdo, $id)) {
                        throw new \RuntimeException("Non è possibile confermare l'ordine: tutte le righe devono avere una quantità e un prezzo unitario maggiori di zero.");
                    }

                    // Avvia Macro-Transazione (Tutto o Niente)
                    $this->pdo->beginTransaction();
                    try {
                        qk_ordine_invia($this->pdo, $id);

                        // Create dynamic reception (require spostato in testa al file)
                        if (!qk_ordine_has_ricezione_bozza($this->pdo, $id)) {
                            qk_ricezione_apri($this->pdo, $id, (int)current_user()['id']);
                        }

                        $this->pdo->commit();
                        flash_set('success', 'Ordine confermato e inviato al fornitore. Ricezione in magazzino predisposta.');
                    } catch (\Throwable $e) {
                        $this->pdo->rollBack();
                        throw $e;
                    }
                    break;

                case 'forza_chiusura':
                    qk_ordine_forza_chiusura($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Ordine chiuso forzatamente e residui annullati.');
                    break;

                case 'annulla':
                    qk_ordine_annulla($this->pdo, $id);
                    flash_set('success', 'Ordine annullato.');
                    break;

                // Tracciabilità manuale "fatturato/da fatturare" (REVISIONE Ciclo
                // Acquisti, 17/07/2026) — mirror esatto di SalesOrdersController
                // (righe 526-533): semplice UPDATE, nessuna verifica di importi/
                // quantità collegata, non è un three-way match automatico.
                case 'segna_fatturato':
                    qk_ordine_segna_fatturato($this->pdo, $id);
                    flash_set('success', 'Ordine segnato come fatturato.');
                    break;

                case 'segna_da_fatturare':
                    qk_ordine_segna_da_fatturare($this->pdo, $id);
                    flash_set('success', 'Ordine ripristinato a "Da Fatturare".');
                    break;

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
            }
        } catch (\Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            flash_set('danger', $e->getMessage());
        }

        redirect(url('purchases/orders/edit') . '?id=' . $id);
    }
    public function pdf(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/orders'));
        }

        $detail = qk_ordine_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/orders'));
        }

        $ordine = $detail['ordine'];
        $righe = $detail['righe'];

        // Recupera azienda interna o quella predefinita
        // (Revisione Incrociata §7.1.1/§5.3.1: risoluzione centralizzata in
        // qk_resolve_azienda_emittente(), prima duplicata qui x2 e in
        // RfqController.php x2)
        $azienda = qk_resolve_azienda_emittente($this->pdo, !empty($ordine['azienda_interna_id']) ? (int)$ordine['azienda_interna_id'] : null);

        // Fetch supplier address
        $indirizzoFornitore = '';
        if (!empty($ordine['fornitore_id'])) {
            $indForn = qk_soggetto_get_indirizzo_principale($this->pdo, (int)$ordine['fornitore_id']);
            if ($indForn) {
                $indirizzoFornitore = $indForn['via'] . ' ' . $indForn['civico'] . '<br>' . $indForn['cap'] . ' ' . $indForn['citta'] . ' (' . $indForn['provincia'] . ')';
            }
        }

        // Genera il PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 45,
            'margin_bottom' => 25,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'tempDir'       => upload_ensure_dir('mpdf_tmp'),
        ]);

        $mpdf->SetTitle('Ordine Acquisto - ' . $ordine['numero']);
        $mpdf->SetAuthor('Quack ERP');

        // Header Image (Intestazione stampati)
        $headerHtml = '';
        if (!empty($azienda['header_path']) && file_exists(upload_abs($azienda['header_path']))) {
            $headerHtml = '<img src="' . upload_abs($azienda['header_path']) . '" style="width:100%; max-height:35mm;">';
        } elseif (!empty($azienda['logo_path']) && file_exists(upload_abs($azienda['logo_path']))) {
            $headerHtml = '
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
                <tr>
                    <td style="width:50%;"><img src="' . upload_abs($azienda['logo_path']) . '" style="max-height:22mm;"></td>
                    <td style="width:50%; text-align:right; font-weight:bold; font-size:14pt; color:#1a5276; vertical-align:middle;">
                        ORDINE DI ACQUISTO A FORNITORE
                    </td>
                </tr>
            </table>';
        } else {
            $headerHtml = '
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif; border-bottom:1px solid #ccc; padding-bottom:3mm;">
                <tr>
                    <td style="width:60%;">
                        <strong style="font-size:14pt; color:#1a5276;">' . e($azienda['ragione_sociale'] ?? 'Gemina S.r.l.') . '</strong><br>
                        <span style="font-size:8pt; color:#666;">P.IVA: ' . e($azienda['partita_iva'] ?? '') . ' | Email: ' . e($azienda['email'] ?? '') . ' | Tel: ' . e($azienda['telefono'] ?? '') . '</span>
                    </td>
                    <td style="width:40%; text-align:right; font-weight:bold; font-size:12pt; color:#1a5276; vertical-align:middle;">
                        ORDINE DI ACQUISTO A FORNITORE
                    </td>
                </tr>
            </table>';
        }

        $mpdf->SetHTMLHeader('
            <div style="width:100%; font-family:sans-serif; margin-bottom:5mm;">
                ' . $headerHtml . '
            </div>
        ');

        $mpdf->SetHTMLFooter('
            <div style="border-top:1px solid #999; padding-top:3mm; font-size:8pt; color:#666; text-align:center; font-family:sans-serif;">
                Pagina {PAGENO} di {nbpg} — Ordine N°: ' . e($ordine['numero']) . '
            </div>
        ');

        $html = '
        <style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #222; }
            h2 { font-size: 11pt; color: #1a5276; border-bottom: 1.5pt solid #1a5276; padding-bottom: 1mm; margin-top: 5mm; margin-bottom: 3mm; text-transform: uppercase; }
            .info-table { width: 100%; border-collapse: collapse; margin-top: 1mm; margin-bottom: 0; }
            .info-table td { padding: 0.8mm 0; font-size: 9.5pt; vertical-align: top; }
            .info-table .lbl { font-weight: bold; color: #555; width: 30%; }
            .info-box { padding: 0; }
            
            .righe-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 4mm; }
            .righe-table th { background: #1a5276; color: #fff; padding: 2.2mm 2mm; font-size: 9pt; text-align: center; }
            .righe-table td { padding: 2.2mm 2mm; font-size: 9pt; border-bottom: 0.5pt solid #ccc; }
            .righe-table .num { text-align: right; font-family: monospace; }
            .righe-table .center { text-align: center; }
            .righe-table tr.zebra { background-color: #f8fafc; }
            
            .totals-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 4mm; }
            .totals-table td { padding: 1.5mm 2mm; font-size: 9.5pt; }
            .totals-table .val { text-align: right; font-weight: bold; width: 120px; }
            
            .cond-table { width: 100%; border-collapse: collapse; }
            .cond-table td { padding: 2.5mm 3mm; font-size: 9.5pt; vertical-align: top; border-bottom: 0.3pt solid #eee; }
            .cond-table .lbl { font-weight: bold; color: #444; width: 25%; }
        </style>';

        // Contact details
        $contattoNome = $ordine['contatto_nome'] ?: '';
        $contattoTel = $ordine['contatto_telefono'] ?: $ordine['fornitore_telefono'] ?: '';
        $contattoEmail = $ordine['contatto_email'] ?: $ordine['fornitore_email'] ?: '';

        // BLOCK 1: Info Generali e Recapiti
        $html .= '
        <table style="width:100%; border-collapse:collapse; margin-top:2mm; margin-bottom:5mm;">
            <tr>
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Dettagli Ordine</strong>
                        <table class="info-table">
                            <tr>
                                <td class="lbl">Rif. Interno:</td>
                                <td><strong>' . e($ordine['riferimento_interno'] ?: '—') . '</strong></td>
                            </tr>
                            <tr>
                                <td class="lbl">Rif. RFQ:</td>
                                <td>' . e($ordine['rfq_numero'] ?: $ordine['riferimento_rfq_interno'] ?: '—') . '</td>
                            </tr>
                            <tr>
                                <td class="lbl">Rif. Prev. Forn.:</td>
                                <td>' . e($ordine['riferimento_fornitore'] ?: '—') . '</td>
                            </tr>
                            <tr>
                                <td class="lbl">Data Ordine:</td>
                                <td>' . e(fmt_date($ordine['data_ordine'])) . '</td>
                            </tr>
                        </table>
                    </div>
                </td>
                
                <td style="width:4%;">&nbsp;</td>
                
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Spett.le Fornitore</strong>
                        <div style="margin-top:1.5mm; font-size:9.5pt; line-height:1.45;">
                            <strong>' . e($ordine['fornitore_nome']) . '</strong><br>
                            ' . ($indirizzoFornitore ?: '—') . '<br>
                            ' . (!empty($contattoNome) ? 'Alla c.a.: <strong>' . e($contattoNome) . '</strong><br>' : '') . '
                            ' . (!empty($contattoTel) ? 'Tel: ' . e($contattoTel) : '') . 
                            (!empty($contattoEmail) ? ' | Email: ' . e($contattoEmail) : '') . '
                        </div>
                    </div>
                </td>
            </tr>
        </table>';

        // BLOCK 2: Righe Articoli
        $html .= '
        <h2>Prodotti Ordinati</h2>
        <table class="righe-table">
            <thead>
                <tr>
                    <th style="width:15%">SKU Fornitore</th>
                    <th style="width:30%">Descrizione</th>
                    <th style="width:15%">Formato</th>
                    <th style="width:10%; text-align:right;">Q.tà</th>
                    <th style="width:8%; text-align:center;">UdM</th>
                    <th style="width:11%; text-align:right;">Prezzo Unitario</th>
                    <th style="width:11%; text-align:right;">Totale</th>
                </tr>
            </thead>
            <tbody>';

        $fill = false;
        foreach ($righe as $r) {
            $zebraClass = $fill ? ' class="zebra"' : '';
            $qty = (float)$r['quantita_ordinata'];
            $um = $r['uom'] ?: 'pz';
            
            $prezzo = (float)($r['prezzo_unitario'] ?? 0);
            $rigaTotale = $qty * $prezzo; // Potrebbe anche essere letto dal database 'imponibile_riga' o 'totale_riga'
            // check db field for line total
            $totaleRiga = isset($r['imponibile_riga']) ? (float)$r['imponibile_riga'] : $rigaTotale;

            $html .= '
                <tr' . $zebraClass . '>
                    <td class="center"><strong>' . e($r['sku_fornitore'] ?: '—') . '</strong></td>
                    <td>' . e($r['descrizione'] ?: $r['prodotto_nome']) . '</td>
                    <td class="center">' . e($r['formato'] ?: '—') . '</td>
                    <td class="num"><strong>' . fmt_qty($qty) . '</strong></td>
                    <td class="center">' . e($um) . '</td>
                    <td class="num">' . fmt_money($prezzo) . '</td>
                    <td class="num"><strong>' . fmt_money($totaleRiga) . '</strong></td>
                </tr>';
            
            $fill = !$fill;
        }

        $html .= '
            </tbody>
        </table>';

        // BLOCK 3: Totali
        $imponibile = (float)$ordine['imponibile'];
        $iva = (float)$ordine['totale_iva'];
        $totale = (float)$ordine['totale'];
        
        $html .= '
        <table class="totals-table">
            <tr>
                <td style="width:50%;">&nbsp;</td>
                <td style="text-align:right; color:#666; white-space:nowrap;">Totale Imponibile:</td>
                <td class="val">' . fmt_money($imponibile) . '</td>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td style="text-align:right; color:#666; white-space:nowrap;">Totale I.V.A.:</td>
                <td class="val">' . fmt_money($iva) . '</td>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td style="text-align:right; font-weight:bold; color:#1a5276; white-space:nowrap;">Totale Documento:</td>
                <td class="val" style="color:#1a5276; border-top:1px solid #1a5276;">' . fmt_money($totale) . '</td>
            </tr>
        </table>';

        // BLOCK 4: Condizioni di Fornitura
        $consegnaIndirizzo = '—';
        if (!empty($ordine['consegna_via'])) {
            $consegnaIndirizzo = '<strong>' . e($ordine['consegna_presso'] ?: ($azienda['ragione_sociale'] ?? '')) . '</strong> - ' .
                                 e($ordine['consegna_via']) . ' ' . e($ordine['consegna_civico']) . ', ' .
                                 e($ordine['consegna_cap']) . ' ' . e($ordine['consegna_citta']) . ' (' . e($ordine['consegna_provincia']) . ')';
            if (!empty($ordine['consegna_referente'])) {
                $consegnaIndirizzo .= ' (Ref: ' . e($ordine['consegna_referente']) . ')';
            }
            if (!empty($ordine['consegna_telefono'])) {
                $consegnaIndirizzo .= ' - Tel: ' . e($ordine['consegna_telefono']);
            }
        }

        $resaPortoLabel = '—';
        if (($ordine['resa_porto'] ?? '') === 'porto_franco') {
            $resaPortoLabel = 'Porto Franco (A carico venditore)';
        } elseif (($ordine['resa_porto'] ?? '') === 'porto_assegnato') {
            $resaPortoLabel = 'Porto Assegnato (A carico acquirente)';
        }

        $resaCondizioni = '—';
        if (!empty($ordine['incoterm_codice'])) {
            $resaCondizioni = '<strong>' . e($ordine['incoterm_codice']) . '</strong> - ' . e($ordine['incoterm_luogo'] ?: 'Sede di consegna');
            if ($resaPortoLabel !== '—') {
                $resaCondizioni .= ' (' . $resaPortoLabel . ')';
            }
        } elseif ($resaPortoLabel !== '—') {
            $resaCondizioni = $resaPortoLabel;
        }

        $html .= '
        <h2>Condizioni di Fornitura</h2>
        <table class="cond-table">
            <tr>
                <td class="lbl">Consegna Richiesta:</td>
                <td>' . (!empty($ordine['data_consegna_prevista']) ? e(fmt_date($ordine['data_consegna_prevista'])) : 'Non specificata') . '</td>
            </tr>
            <tr>
                <td class="lbl">Luogo di Consegna:</td>
                <td>' . $consegnaIndirizzo . '</td>
            </tr>
            <tr>
                <td class="lbl">Modalità Pagamento:</td>
                <td>' . e($ordine['metodo_pagamento_descrizione'] ?: $ordine['condizioni_pagamento'] ?: 'Come da accordi') . '</td>
            </tr>
            <tr>
                <td class="lbl">Termini di Resa:</td>
                <td>' . $resaCondizioni . '</td>
            </tr>';

        if (!empty($ordine['note_fornitore'])) {
            $html .= '
            <tr>
                <td class="lbl">Note al Fornitore:</td>
                <td>' . nl2br(e($ordine['note_fornitore'])) . '</td>
            </tr>';
        }

        $html .= '
        </table>';

        $html .= '
        <div style="margin-top:15mm; page-break-inside:avoid;">
            <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
                <tr>
                    <td style="width:45%; vertical-align:bottom; padding-bottom:6px;">
                        <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;">Timbro e Firma per Accettazione</span><br>
                        <span style="font-size:7.5pt; color:#777777;">Fornitore (per accettazione ordine e condizioni)</span>
                    </td>
                    <td style="width:10%;"></td>
                    <td style="width:45%; vertical-align:bottom; padding-bottom:6px; text-align:right;">
                        <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;">Firma Autorizzata</span><br>
                        <span style="font-size:7.5pt; color:#777777;">Il responsabile acquisti</span>
                    </td>
                </tr>
                <tr>
                    <td style="border:1px dashed #bbbbbb; padding:12px; height:30mm; vertical-align:bottom; background-color:#fafafa;">
                        <div style="border-bottom:1px solid #aaaaaa; width:80%;"></div>
                    </td>
                    <td></td>
                    <td style="border:1px dashed #bbbbbb; padding:12px; height:30mm; vertical-align:bottom; background-color:#fafafa;">
                        <div style="border-bottom:1px solid #aaaaaa; width:80%; margin-left:auto;"></div>
                    </td>
                </tr>
            </table>
        </div>';

        $archiver = new \App\Services\PdfArchiverService();
        $pdfBytes = $archiver->getOrRender(
            $this->pdo,
            'ordini_acquisto',
            $id,
            'ordini_testata',
            (string)($ordine['stato'] ?? 'bozza'),
            function() use ($mpdf, $html) {
                $mpdf->WriteHTML($html);
                return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
            }
        );

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="OrdineAcquisto_' . clean_filename($ordine['numero']) . '.pdf"');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
        exit;
    }

    public function docx(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/orders'));
        }

        $detail = qk_ordine_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/orders'));
        }

        $ordine = $detail['ordine'];
        $righe = $detail['righe'];

        // Recupera azienda interna o quella predefinita
        // (Revisione Incrociata §7.1.1/§5.3.1: risoluzione centralizzata in
        // qk_resolve_azienda_emittente(), prima duplicata qui x2 e in
        // RfqController.php x2)
        $azienda = qk_resolve_azienda_emittente($this->pdo, !empty($ordine['azienda_interna_id']) ? (int)$ordine['azienda_interna_id'] : null);

        // Fetch supplier address
        $indirizzoFornitore = '';
        if (!empty($ordine['fornitore_id'])) {
            $indForn = qk_soggetto_get_indirizzo_principale($this->pdo, (int)$ordine['fornitore_id']);
            if ($indForn) {
                $indirizzoFornitore = $indForn['via'] . ' ' . $indForn['civico'] . ', ' . $indForn['cap'] . ' ' . $indForn['citta'] . ' (' . $indForn['provincia'] . ')';
            }
        }

        $wordHelper = new \Quack\WordHelper();
        $phpWord = $wordHelper->getPhpWord();
        $section = $wordHelper->createStandardSection();
        $wordHelper->addStandardHeader($section, $azienda, 'ORDINE DI ACQUISTO A FORNITORE');
        $wordHelper->addStandardFooter($section, 'Ordine: ' . $ordine['numero']);

        // Styles
        $fontTitle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1A5276'];
        $fontBold = ['name' => 'Arial', 'size' => 9.5, 'bold' => true, 'color' => '333333'];
        $fontNormal = ['name' => 'Arial', 'size' => 9.5, 'color' => '222222'];
        $fontMonospace = ['name' => 'Courier New', 'size' => 8.5];
        $fontTotal = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '1A5276'];

        // Title
        $section->addText('ORDINE DI ACQUISTO - ' . $ordine['numero'], ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '1A5276'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 150]);

        // BLOCK 1: Two Column Layout Table (Dettagli Ordine & Fornitore)
        $block1Table = $section->addTable(['cellMargin' => 100]);
        $block1Table->addRow();
        
        // Left Cell (Dettagli Ordine)
        $leftCell = $block1Table->addCell(5070, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunLeft = $leftCell->addTextRun();
        $textRunLeft->addText('Dettagli Ordine', $fontTitle);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Ordine N°: ' . $ordine['numero'], $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Riferimento RFQ: ' . ($ordine['rfq_numero'] ?: $ordine['riferimento_rfq_interno'] ?: '—'), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Rif. Preventivo Fornitore: ' . ($ordine['riferimento_fornitore'] ?: '—'), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Data: ' . date('d/m/Y', strtotime($ordine['data_ordine'])), $fontNormal);

        // Spacer Cell
        $block1Table->addCell(400);

        // Right Cell (Spett.le Fornitore)
        $rightCell = $block1Table->addCell(4800, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunRight = $rightCell->addTextRun();
        $textRunRight->addText('Spett.le Fornitore', $fontTitle);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($ordine['fornitore_nome'], ['name' => 'Arial', 'size' => 9.5, 'bold' => true]);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($indirizzoFornitore ?: '—', $fontNormal);
        
        $contattoNome = $ordine['contatto_nome'] ?: '';
        $contattoTel = $ordine['contatto_telefono'] ?: $ordine['fornitore_telefono'] ?: '';
        $contattoEmail = $ordine['contatto_email'] ?: $ordine['fornitore_email'] ?: '';
        if ($contattoNome) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Alla c.a.: ' . $contattoNome, $fontBold);
        }
        if ($contattoTel || $contattoEmail) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Tel: ' . $contattoTel . ' | Email: ' . $contattoEmail, $fontNormal);
        }

        $section->addTextBreak(1);

        // BLOCK 2: Articoli
        $section->addText('Articoli Ordinati', $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        
        $tableGrid = $section->addTable(['cellMargin' => 80]);
        $thStyle = ['bgColor' => '1A5276', 'borderSize' => 6, 'borderColor' => '1A5276'];
        $thText = ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => 'FFFFFF'];

        $tableGrid->addRow();
        $tableGrid->addCell(1600, $thStyle)->addText('SKU Fornitore', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(3040, $thStyle)->addText('Descrizione', $thText);
        $tableGrid->addCell(1000, $thStyle)->addText('Q.tà Ord.', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(1000, $thStyle)->addText('Q.tà Rice.', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(600, $thStyle)->addText('UdM', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1000, $thStyle)->addText('Prezzo Unit.', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(1000, $thStyle)->addText('Sconto', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1300, $thStyle)->addText('Totale', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $fill = false;
        $imponibile = 0.0;

        foreach ($righe as $r) {
            $rowBg = $fill ? 'F5F7FA' : 'FFFFFF';
            $tdStyle = ['bgColor' => $rowBg, 'borderBottomSize' => 4, 'borderBottomColor' => 'CCCCCC'];
            
            $qtyOrd = (float)$r['quantita_ordinata'];
            $qtyRic = (float)$r['quantita_ricevuta'];
            $um = $r['uom'] ?: 'pz';
            
            $prezzo = (float)$r['prezzo_unitario'];
            $sconto = (float)$r['sconto_perc'];
            $rigaTotale = (float)$r['imponibile_riga'];

            $prezzoStr = '€ ' . number_format($prezzo, 2, ',', '.');
            $scontoStr = $sconto > 0 ? (float)$sconto . '%' : '—';
            $rigaTotaleStr = '€ ' . number_format($rigaTotale, 2, ',', '.');

            $tableGrid->addRow();
            $tableGrid->addCell(1600, $tdStyle)->addText($r['sku_fornitore'] ?: '—', $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(3040, $tdStyle)->addText(($r['descrizione'] ?: $r['prodotto_nome']), $fontNormal);
            $tableGrid->addCell(1000, $tdStyle)->addText(number_format($qtyOrd, 0, ',', '.'), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(1000, $tdStyle)->addText(number_format($qtyRic, 0, ',', '.'), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(600, $tdStyle)->addText($um, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1000, $tdStyle)->addText($prezzoStr, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(1000, $tdStyle)->addText($scontoStr, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1300, $tdStyle)->addText($rigaTotaleStr, $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            $imponibile += $rigaTotale;
            $fill = !$fill;
        }

        $section->addTextBreak(1);
        $iva = (float)$ordine['totale_iva'];
        $totale = (float)$ordine['totale'];

        $tableTotals = $section->addTable();
        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Imponibile:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText('€ ' . number_format($imponibile, 2, ',', '.'), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('IVA:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText('€ ' . number_format($iva, 2, ',', '.'), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale:', $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200, ['borderTopSize' => 6, 'borderTopColor' => '1A5276'])->addText('€ ' . number_format($totale, 2, ',', '.'), $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $section->addTextBreak(1);

        // BLOCK 3: Condizioni
        $section->addText('Condizioni di Fornitura', $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        $section->addTable(['width' => 10540])->addRow()->addCell(10540, ['borderTopSize' => 10, 'borderTopColor' => '1A5276']);

        $consegnaIndirizzo = '—';
        if (!empty($ordine['consegna_via'])) {
            $consegnaIndirizzo = ($ordine['consegna_presso'] ?: ($azienda['ragione_sociale'] ?? '')) . ' - ' .
                                 $ordine['consegna_via'] . ' ' . $ordine['consegna_civico'] . ' - ' .
                                 $ordine['consegna_cap'] . ' ' . $ordine['consegna_citta'] . ' (' . $ordine['consegna_provincia'] . ')';
            if ($ordine['consegna_referente']) {
                $consegnaIndirizzo .= ' (Ref: ' . $ordine['consegna_referente'] . ')';
            }
        }

        $resaPortoLabel = '—';
        if (($ordine['resa_porto'] ?? '') === 'porto_franco') {
            $resaPortoLabel = 'Porto Franco (A carico venditore)';
        } elseif (($ordine['resa_porto'] ?? '') === 'porto_assegnato') {
            $resaPortoLabel = 'Porto Assegnato (A carico acquirente)';
        }

        $resaCondizioni = '—';
        if (!empty($ordine['incoterm_codice'])) {
            $resaCondizioni = $ordine['incoterm_codice'] . ' - ' . ($ordine['incoterm_luogo'] ?: 'Sede di consegna');
            if ($resaPortoLabel !== '—') {
                $resaCondizioni .= ' (' . $resaPortoLabel . ')';
            }
        } elseif ($resaPortoLabel !== '—') {
            $resaCondizioni = $resaPortoLabel;
        }

        $tableCond = $section->addTable(['cellMargin' => 80]);
        
        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Consegna Richiesta', $fontBold);
        $tableCond->addCell(8000)->addText($ordine['data_consegna_prevista'] ? date('d/m/Y', strtotime($ordine['data_consegna_prevista'])) : 'Non specificata', $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Luogo di Consegna', $fontBold);
        $tableCond->addCell(8000)->addText($consegnaIndirizzo, $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Modalità Pagamento', $fontBold);
        $tableCond->addCell(8000)->addText($ordine['metodo_pagamento_descrizione'] ?: $ordine['condizioni_pagamento'] ?: 'Come da accordi', $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Termini di Resa', $fontBold);
        $tableCond->addCell(8000)->addText($resaCondizioni, $fontNormal);

        if (!empty($ordine['note_fornitore'])) {
            $tableCond->addRow();
            $tableCond->addCell(2540)->addText('Note al Fornitore', $fontBold);
            $tableCond->addCell(8000)->addText($ordine['note_fornitore'], $fontNormal);
        }

        // BLOCK 4: Riquadri firma/timbro
        $section->addTextBreak(2);
        $firmaTable = $section->addTable(['cellMargin' => 120]);
        
        // Row 1: Labels
        $firmaTable->addRow();
        $cellLabelLeft = $firmaTable->addCell(4770);
        $cellLabelLeft->addText('TIMBRO E FIRMA PER ACCETTAZIONE', ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444']);
        $cellLabelLeft->addText('Fornitore (per accettazione condizioni contrattuali)', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777']);
        
        $firmaTable->addCell(1000); // spacer
        
        $cellLabelRight = $firmaTable->addCell(4770);
        $cellLabelRight->addText('FIRMA AUTORIZZATA', ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $cellLabelRight->addText('Il responsabile acquisti', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        // Row 2: Stamp/Signature Boxes
        $firmaTable->addRow(1700, ['exactHeight' => true, 'cantSplit' => true]);
        
        $cellStyle = [
            'borderSize' => 6,
            'borderColor' => 'CCCCCC',
            'bgColor' => 'FAFAFA',
            'valign' => 'bottom',
        ];
        
        $cellBoxLeft = $firmaTable->addCell(4770, $cellStyle);
        $cellBoxLeft->addTextBreak(2);
        $cellBoxLeft->addText('____________________________', ['name' => 'Arial', 'size' => 8, 'color' => 'BBBBBB']);
        
        $firmaTable->addCell(1000); // spacer
        
        $cellBoxRight = $firmaTable->addCell(4770, $cellStyle);
        $cellBoxRight->addTextBreak(2);
        $cellBoxRight->addText('____________________________', ['name' => 'Arial', 'size' => 8, 'color' => 'BBBBBB'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $filename = "Ordine_" . str_replace('/', '_', $ordine['numero']) . ".docx";
        $wordHelper->streamDownload($filename);
    }
}
