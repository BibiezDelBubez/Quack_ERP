<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_rfq.php';
require_once INCLUDES_DIR . '/anagrafiche.php';

/**
 * RfqController — Richieste di Offerta (ciclo acquisti v2).
 *
 * Route   Action
 * ──────────────────────────────────────────────────────────────────────────
 * GET  /purchases/rfq              index()         Elenco RFQ
 * GET  /purchases/rfq/create       create()        Form nuova RFQ
 * POST /purchases/rfq/create       create()        Salva nuova RFQ
 * GET  /purchases/rfq/edit?id=N    edit()          Form modifica RFQ
 * POST /purchases/rfq/edit?id=N    edit()          Salva modifiche
 * POST /purchases/rfq/delete       delete()        Elimina (solo bozza)
 * POST /purchases/rfq/action       action()        Transizioni stato
 * GET  /purchases/rfq/pdf?id=N     pdf()           Stampa PDF (TODO)
 * GET  /purchases/rfq/api/*        api*()          Endpoint AJAX
 */
class RfqController extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // ELENCO
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');

        $pag      = qk_pagination_params(['data','numero','rif','fornitore','stato','scadenza','totale'], 'data', 'desc');
        $total    = qk_rfq_count($this->pdo, $search, $stato, $dal, $al);
        $rfq_list = qk_rfq_list($this->pdo, $search, $stato, $dal, $al, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
        $page_title = 'Richieste di Offerta';
        $breadcrumbs = [
            ['label' => 'Acquisti'],
            ['label' => 'Richieste di Offerta']
        ];
        $page_actions = '<a href="' . url('purchases/rfq/create') . '" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Nuova RFQ</a>';

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_list.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREAZIONE
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): void
    {
        $aziende = qk_aziende_list($this->pdo);
        $defaultAziendaId = isset($_SESSION['azienda_attiva_id']) ? (string)$_SESSION['azienda_attiva_id'] : '';
        if ($defaultAziendaId === '') {
            foreach ($aziende as $az) {
                if ($az['predefinita'] == 1) {
                    $defaultAziendaId = (string)$az['id'];
                    break;
                }
            }
        }
        if ($defaultAziendaId === '' && !empty($aziende)) {
            $defaultAziendaId = (string)$aziende[0]['id'];
        }

        $incoterms = $this->pdo->query("SELECT * FROM incoterms WHERE attivo = 1 ORDER BY codice")->fetchAll(PDO::FETCH_ASSOC);
        $metodi_pagamento = $this->pdo->query("SELECT * FROM metodi_pagamento WHERE attivo = 1 ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);

        $errors = [];
        $values = $this->defaultValues();
        $values['azienda_interna_id'] = $defaultAziendaId;
        $righe  = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                
                // L'indirizzo di consegna viene inviato direttamente in POST per supportare gli override


                $righe  = $this->readPostRighe();
                $errors = $this->validate($values, $righe);

                if (empty($errors)) {
                    try {
                        $id = qk_rfq_save($this->pdo, $values, $righe, (int)current_user()['id']);
                        redirect(url('purchases/rfq/edit') . '?id=' . $id . '&saved=1');
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $anteprima_numero = qk_peek_numero_acquisto($this->pdo, 'rfq');
        $page_title       = 'Nuova RFQ';
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
            ['label' => 'Nuova RFQ']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_form.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODIFICA
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $aziende = qk_aziende_list($this->pdo);
        $incoterms = $this->pdo->query("SELECT * FROM incoterms WHERE attivo = 1 ORDER BY codice")->fetchAll(PDO::FETCH_ASSOC);
        $metodi_pagamento = $this->pdo->query("SELECT * FROM metodi_pagamento WHERE attivo = 1 ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);

        $errors = [];
        $values = $this->mapDetailToValues($detail['rfq']);
        $righe  = $detail['righe'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                
                // Enforce read-only lock for address and contact overrides if the RFQ status is not 'bozza'
                $currentStatus = $detail['rfq']['stato'];
                if ($currentStatus !== 'bozza') {
                    $originalValues = $this->mapDetailToValues($detail['rfq']);
                    foreach ([
                        'indirizzo_consegna_id', 'consegna_via', 'consegna_civico', 'consegna_cap',
                        'consegna_citta', 'consegna_provincia', 'consegna_nazione', 'consegna_presso',
                        'consegna_referente', 'consegna_telefono', 'consegna_note',
                        'contatto_id', 'contatto_nome', 'contatto_telefono', 'contatto_email',
                        'fornitore_telefono', 'fornitore_email'
                    ] as $fld) {
                        $values[$fld] = $originalValues[$fld];
                    }
                }


                $righe  = $this->readPostRighe();
                $errors = $this->validate($values, $righe);

                if (empty($errors)) {
                    try {
                        qk_rfq_save($this->pdo, $values, $righe, (int)current_user()['id'], $id);
                        // Ricarica dopo salvataggio
                        $detail = qk_rfq_detail($this->pdo, $id);
                        $righe  = $detail['righe'];
                        flash_set('success', 'RFQ salvata.');
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $saved      = get_param('saved', '') === '1';
        $page_title = 'RFQ ' . $detail['rfq']['numero'];
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
            ['label' => 'Dettaglio RFQ']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_form.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ELIMINAZIONE
    // ─────────────────────────────────────────────────────────────────────────

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/rfq'));
        }
        $id = (int) post('id', '0');
        try {
            qk_rfq_delete($this->pdo, $id);
            flash_set('success', 'RFQ eliminata.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        redirect(url('purchases/rfq'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSIZIONI DI STATO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gestisce le azioni di cambio stato via POST:
     *   ?azione=invia          bozza → inviata
     *   ?azione=chiudi         inviata → chiusa
     *   ?azione=nuova_versione inviata → nuova bozza + corrente revisionata
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/rfq'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'invia':
                    qk_rfq_invia($this->pdo, $id);
                    flash_set('success', 'RFQ inviata.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
                    break;

                case 'chiudi':
                    qk_rfq_chiudi($this->pdo, $id);
                    flash_set('success', 'RFQ chiusa.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
                    break;

                case 'nuova_versione':
                    $nuovoId = qk_rfq_nuova_versione($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Nuova versione creata.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $nuovoId);
                    break;

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            redirect(url('purchases/rfq/edit') . '?id=' . $id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF (stub — logica PDF mantenuta in PurchasesController per ora)
    // ─────────────────────────────────────────────────────────────────────────

    public function pdf(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $rfq = $detail['rfq'];
        $righe = $detail['righe'];

        // Recupera azienda interna per l'RFQ o quella predefinita
        $azienda = null;
        if (!empty($rfq['azienda_interna_id'])) {
            $stmtAz = $this->pdo->prepare(
                "SELECT cf.*, az.*
                   FROM aziende_interne az
                   JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
                  WHERE az.id = ? LIMIT 1"
            );
            $stmtAz->execute([(int)$rfq['azienda_interna_id']]);
            $azienda = $stmtAz->fetch(PDO::FETCH_ASSOC);
        }
        if (!$azienda) {
            $stmtAz = $this->pdo->query(
                "SELECT cf.*, az.*
                   FROM aziende_interne az
                   JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
                  WHERE az.predefinita = 1 LIMIT 1"
            );
            $azienda = $stmtAz->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Fetch supplier address
        $indirizzoFornitore = '';
        if (!empty($rfq['fornitore_id'])) {
            $stInd = $this->pdo->prepare("
                SELECT * FROM indirizzi 
                WHERE soggetto_id = ? 
                ORDER BY (CASE WHEN tipo='sede_legale' THEN 1 WHEN tipo='sede_operativa' THEN 2 ELSE 3 END), predefinito DESC 
                LIMIT 1
            ");
            $stInd->execute([(int)$rfq['fornitore_id']]);
            $indForn = $stInd->fetch(PDO::FETCH_ASSOC);
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

        $mpdf->SetTitle('RFQ - ' . $rfq['numero']);
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
                        RICHIESTA DI PREVENTIVO (RFQ)
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
                        RICHIESTA DI PREVENTIVO (RFQ)
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
                Pagina {PAGENO} di {nbpg} — RFQ: ' . e($rfq['numero']) . '
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
        $contattoNome = $rfq['contatto_nome'] ?: '';
        $contattoTel = $rfq['contatto_telefono'] ?: $rfq['fornitore_telefono'] ?: '';
        $contattoEmail = $rfq['contatto_email'] ?: $rfq['fornitore_email'] ?: '';

        // BLOCK 1: Info Generali e Recapiti
        $html .= '
        <table style="width:100%; border-collapse:collapse; margin-top:2mm; margin-bottom:5mm;">
            <tr>
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Dettagli Richiesta</strong>
                        <table class="info-table">
                            <tr>
                                <td class="lbl">Rif. Interno:</td>
                                <td><strong>' . e($rfq['riferimento_rfq_interno'] ?: '—') . '</strong></td>
                            </tr>
                            <tr>
                                <td class="lbl">Data Emis.:</td>
                                <td>' . e(fmt_date($rfq['data_emissione'])) . '</td>
                            </tr>
                            <tr>
                                <td class="lbl">Scadenza:</td>
                                <td>' . e(fmt_date($rfq['data_scadenza'])) . '</td>
                            </tr>
                        </table>
                    </div>
                </td>
                
                <td style="width:4%;">&nbsp;</td>
                
                <td style="width:48%; vertical-align:top;">
                    <div class="info-box">
                        <strong style="font-size:11pt; color:#1a5276;">Spett.le Fornitore</strong>
                        <div style="margin-top:1.5mm; font-size:9.5pt; line-height:1.45;">
                            <strong>' . e($rfq['fornitore_nome']) . '</strong><br>
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
        <h2>Articoli in Richiesta</h2>
        <table class="righe-table">
            <thead>
                <tr>
                    <th style="width:15%">SKU Fornitore</th>
                    <th style="width:30%">Descrizione</th>
                    <th style="width:15%">Formato</th>
                    <th style="width:10%; text-align:right;">Q.tà</th>
                    <th style="width:8%; text-align:center;">UdM</th>
                    <th style="width:11%; text-align:right;">Prezzo indicativo</th>
                    <th style="width:11%; text-align:right;">Totale</th>
                </tr>
            </thead>
            <tbody>';

        $fill = false;
        $hasPrices = false;
        $imponibile = 0.0;
        
        foreach ($righe as $r) {
            $zebraClass = $fill ? ' class="zebra"' : '';
            $qty = (float)$r['quantita_richiesta'];
            $um = $r['uom'] ?: 'pz';
            
            $prezzo = (float)($r['prezzo_indicativo'] ?? 0);
            $rigaTotale = $qty * $prezzo;

            $prezzoStr = $prezzo > 0 ? fmt_money($prezzo) : '—';
            $rigaTotaleStr = $rigaTotale > 0 ? fmt_money($rigaTotale) : '—';
            
            $html .= '
                <tr' . $zebraClass . '>
                    <td class="center"><strong>' . e($r['sku_fornitore'] ?: '—') . '</strong></td>
                    <td>' . e($r['descrizione'] ?: $r['prodotto_nome']) . '</td>
                    <td class="center">' . e($r['formato'] ?: '—') . '</td>
                    <td class="num"><strong>' . fmt_qty($qty) . '</strong></td>
                    <td class="center">' . e($um) . '</td>
                    <td class="num">' . $prezzoStr . '</td>
                    <td class="num"><strong>' . $rigaTotaleStr . '</strong></td>
                </tr>';
            
            if ($prezzo > 0) {
                $hasPrices = true;
                $imponibile += $rigaTotale;
            }
            
            $fill = !$fill;
        }

        $html .= '
            </tbody>
        </table>';

        if ($hasPrices) {
            $iva = round($imponibile * 0.22, 2);
            $totale = $imponibile + $iva;
            
            $html .= '
            <table class="totals-table">
                <tr>
                    <td style="width:50%;">&nbsp;</td>
                    <td style="text-align:right; color:#666; white-space:nowrap;">Imponibile Stimato:</td>
                    <td class="val">' . fmt_money($imponibile) . '</td>
                </tr>
                <tr>
                    <td>&nbsp;</td>
                    <td style="text-align:right; color:#666; white-space:nowrap;">I.V.A. Stimata (22%):</td>
                    <td class="val">' . fmt_money($iva) . '</td>
                </tr>
                <tr>
                    <td>&nbsp;</td>
                    <td style="text-align:right; font-weight:bold; color:#1a5276; white-space:nowrap;">Totale Indicativo:</td>
                    <td class="val" style="color:#1a5276; border-top:1px solid #1a5276;">' . fmt_money($totale) . '</td>
                </tr>
            </table>';
        }

        // BLOCK 3: Condizioni di Fornitura
        $consegnaIndirizzo = '—';
        if (!empty($rfq['consegna_via'])) {
            $consegnaIndirizzo = '<strong>' . e($rfq['consegna_presso'] ?: ($azienda['ragione_sociale'] ?? '')) . '</strong> - ' .
                                 e($rfq['consegna_via']) . ' ' . e($rfq['consegna_civico']) . ', ' .
                                 e($rfq['consegna_cap']) . ' ' . e($rfq['consegna_citta']) . ' (' . e($rfq['consegna_provincia']) . ')';
            if (!empty($rfq['consegna_referente'])) {
                $consegnaIndirizzo .= ' (Ref: ' . e($rfq['consegna_referente']) . ')';
            }
            if (!empty($rfq['consegna_telefono'])) {
                $consegnaIndirizzo .= ' - Tel: ' . e($rfq['consegna_telefono']);
            }
        }

        $resaPortoLabel = '—';
        if (($rfq['resa_porto'] ?? '') === 'porto_franco') {
            $resaPortoLabel = 'Porto Franco (A carico venditore)';
        } elseif (($rfq['resa_porto'] ?? '') === 'porto_assegnato') {
            $resaPortoLabel = 'Porto Assegnato (A carico acquirente)';
        }

        $resaCondizioni = '—';
        if (!empty($rfq['incoterm_codice'])) {
            $resaCondizioni = '<strong>' . e($rfq['incoterm_codice']) . '</strong> - ' . e($rfq['incoterm_luogo'] ?: 'Sede di consegna');
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
                <td>' . ($rfq['data_consegna_prevista'] ? e(fmt_date($rfq['data_consegna_prevista'])) : 'Non specificata') . '</td>
            </tr>
            <tr>
                <td class="lbl">Luogo di Consegna:</td>
                <td>' . $consegnaIndirizzo . '</td>
            </tr>
            <tr>
                <td class="lbl">Modalità Pagamento:</td>
                <td>' . e($rfq['metodo_pagamento_descrizione'] ?: $rfq['condizioni_pagamento'] ?: 'Come da accordi') . '</td>
            </tr>
            <tr>
                <td class="lbl">Termini di Resa:</td>
                <td>' . $resaCondizioni . '</td>
            </tr>';

        if (!empty($rfq['note_fornitore'])) {
            $html .= '
            <tr>
                <td class="lbl">Note al Fornitore:</td>
                <td>' . nl2br(e($rfq['note_fornitore'])) . '</td>
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
                        <span style="font-size:7.5pt; color:#777777;">Fornitore (per accettazione condizioni contrattuali)</span>
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

        $mpdf->WriteHTML($html);
        $mpdf->Output('RFQ_' . str_replace('/', '_', $rfq['numero']) . '.pdf', \Mpdf\Output\Destination::INLINE);
        exit;
    }

    public function docx(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $rfq = $detail['rfq'];
        $righe = $detail['righe'];

        // Recupera azienda interna per l'RFQ o quella predefinita
        $azienda = null;
        if (!empty($rfq['azienda_interna_id'])) {
            $stmtAz = $this->pdo->prepare(
                "SELECT cf.*, az.*
                   FROM aziende_interne az
                   JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
                  WHERE az.id = ? LIMIT 1"
            );
            $stmtAz->execute([(int)$rfq['azienda_interna_id']]);
            $azienda = $stmtAz->fetch(PDO::FETCH_ASSOC);
        }
        if (!$azienda) {
            $stmtAz = $this->pdo->query(
                "SELECT cf.*, az.*
                   FROM aziende_interne az
                   JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
                  WHERE az.predefinita = 1 LIMIT 1"
            );
            $azienda = $stmtAz->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Fetch supplier address
        $indirizzoFornitore = '';
        if (!empty($rfq['fornitore_id'])) {
            $stInd = $this->pdo->prepare("
                SELECT * FROM indirizzi 
                WHERE soggetto_id = ? 
                ORDER BY (CASE WHEN tipo='sede_legale' THEN 1 WHEN tipo='sede_operativa' THEN 2 ELSE 3 END), predefinito DESC 
                LIMIT 1
            ");
            $stInd->execute([(int)$rfq['fornitore_id']]);
            $indForn = $stInd->fetch(PDO::FETCH_ASSOC);
            if ($indForn) {
                $indirizzoFornitore = $indForn['via'] . ' ' . $indForn['civico'] . ', ' . $indForn['cap'] . ' ' . $indForn['citta'] . ' (' . $indForn['provincia'] . ')';
            }
        }

        $wordHelper = new \Quack\WordHelper();
        $phpWord = $wordHelper->getPhpWord();
        $section = $wordHelper->createStandardSection();
        $wordHelper->addStandardHeader($section, $azienda, 'RICHIESTA DI PREVENTIVO (RFQ)');
        $wordHelper->addStandardFooter($section, 'RFQ: ' . $rfq['numero']);

        // Styles
        $fontTitle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1A5276'];
        $fontBold = ['name' => 'Arial', 'size' => 9.5, 'bold' => true, 'color' => '333333'];
        $fontNormal = ['name' => 'Arial', 'size' => 9.5, 'color' => '222222'];
        $fontMonospace = ['name' => 'Courier New', 'size' => 8.5];
        $fontTotal = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '1A5276'];

        // Title
        $section->addText('RICHIESTA DI PREVENTIVO (RFQ) - ' . $rfq['numero'], ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '1A5276'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 150]);

        // BLOCK 1: Two Column Layout Table (Dettagli RFQ & Fornitore)
        $block1Table = $section->addTable(['cellMargin' => 100]);
        $block1Table->addRow();
        
        // Left Cell (Dettagli RFQ)
        $leftCell = $block1Table->addCell(5070, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunLeft = $leftCell->addTextRun();
        $textRunLeft->addText('Dettagli Richiesta', $fontTitle);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('RFQ N°: ' . $rfq['numero'] . ' (v' . (int)$rfq['versione'] . ')', $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Rif. Interno: ' . ($rfq['riferimento_rfq_interno'] ?: '—'), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Data: ' . fmt_date($rfq['data_emissione']), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Scadenza: ' . fmt_date($rfq['data_scadenza']), $fontNormal);

        // Spacer Cell
        $block1Table->addCell(400);

        // Right Cell (Spett.le Fornitore)
        $rightCell = $block1Table->addCell(4800, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunRight = $rightCell->addTextRun();
        $textRunRight->addText('Spett.le Fornitore', $fontTitle);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($rfq['fornitore_nome'], ['name' => 'Arial', 'size' => 9.5, 'bold' => true]);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($indirizzoFornitore ?: '—', $fontNormal);
        
        $contattoNome = $rfq['contatto_nome'] ?: '';
        $contattoTel = $rfq['contatto_telefono'] ?: $rfq['fornitore_telefono'] ?: '';
        $contattoEmail = $rfq['contatto_email'] ?: $rfq['fornitore_email'] ?: '';
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
        $section->addText('Articoli in Richiesta', $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        
        $tableGrid = $section->addTable(['cellMargin' => 80]);
        $thStyle = ['bgColor' => '1A5276', 'borderSize' => 6, 'borderColor' => '1A5276'];
        $thText = ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => 'FFFFFF'];

        $tableGrid->addRow();
        $tableGrid->addCell(1600, $thStyle)->addText('SKU Fornitore', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(3240, $thStyle)->addText('Descrizione', $thText);
        $tableGrid->addCell(1600, $thStyle)->addText('Formato', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1000, $thStyle)->addText('Q.tà', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(600, $thStyle)->addText('UdM', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1200, $thStyle)->addText('Prezzo indicativo', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(1300, $thStyle)->addText('Totale', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $fill = false;
        $hasPrices = false;
        $imponibile = 0.0;

        foreach ($righe as $r) {
            $rowBg = $fill ? 'F5F7FA' : 'FFFFFF';
            $tdStyle = ['bgColor' => $rowBg, 'borderBottomSize' => 4, 'borderBottomColor' => 'CCCCCC'];
            
            $qty = (float)$r['quantita_richiesta'];
            $um = $r['uom'] ?: 'pz';
            
            $prezzo = (float)($r['prezzo_indicativo'] ?? 0);
            $rigaTotale = $qty * $prezzo;

            $prezzoStr = $prezzo > 0 ? fmt_money($prezzo) : '—';
            $rigaTotaleStr = $rigaTotale > 0 ? fmt_money($rigaTotale) : '—';

            $tableGrid->addRow();
            $tableGrid->addCell(1600, $tdStyle)->addText($r['sku_fornitore'] ?: '—', $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(3240, $tdStyle)->addText($r['descrizione'] ?: $r['prodotto_nome'], $fontNormal);
            $tableGrid->addCell(1600, $tdStyle)->addText($r['formato'] ?: '—', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1000, $tdStyle)->addText(fmt_qty($qty), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(600, $tdStyle)->addText($um, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1200, $tdStyle)->addText($prezzoStr, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(1300, $tdStyle)->addText($rigaTotaleStr, $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            if ($prezzo > 0) {
                $hasPrices = true;
                $imponibile += $rigaTotale;
            }

            $fill = !$fill;
        }

        if ($hasPrices) {
            $section->addTextBreak(1);
            $iva = round($imponibile * 0.22, 2);
            $totale = $imponibile + $iva;

            $tableTotals = $section->addTable();
            $tableTotals->addRow();
            $tableTotals->addCell(7540);
            $tableTotals->addCell(1800)->addText('Imponibile (Stima):', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableTotals->addCell(1200)->addText(fmt_money($imponibile), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            $tableTotals->addRow();
            $tableTotals->addCell(7540);
            $tableTotals->addCell(1800)->addText('IVA 22% (Stima):', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableTotals->addCell(1200)->addText(fmt_money($iva), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            $tableTotals->addRow();
            $tableTotals->addCell(7540);
            $tableTotals->addCell(1800)->addText('Totale Estimato:', $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableTotals->addCell(1200, ['borderTopSize' => 6, 'borderTopColor' => '1A5276'])->addText(fmt_money($totale), $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        }

        $section->addTextBreak(1);

        // BLOCK 3: Condizioni
        $section->addText('Condizioni di Fornitura', $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        $section->addTable(['width' => 10540])->addRow()->addCell(10540, ['borderTopSize' => 10, 'borderTopColor' => '1A5276']);

        $consegnaIndirizzo = '—';
        if (!empty($rfq['consegna_via'])) {
            $consegnaIndirizzo = ($rfq['consegna_presso'] ?: ($azienda['ragione_sociale'] ?? '')) . ' - ' .
                                 $rfq['consegna_via'] . ' ' . $rfq['consegna_civico'] . ' - ' .
                                 $rfq['consegna_cap'] . ' ' . $rfq['consegna_citta'] . ' (' . $rfq['consegna_provincia'] . ')';
            if ($rfq['consegna_referente']) {
                $consegnaIndirizzo .= ' (Ref: ' . $rfq['consegna_referente'] . ')';
            }
        }

        $resaPortoLabel = '—';
        if (($rfq['resa_porto'] ?? '') === 'porto_franco') {
            $resaPortoLabel = 'Porto Franco (A carico venditore)';
        } elseif (($rfq['resa_porto'] ?? '') === 'porto_assegnato') {
            $resaPortoLabel = 'Porto Assegnato (A carico acquirente)';
        }

        $resaCondizioni = '—';
        if (!empty($rfq['incoterm_codice'])) {
            $resaCondizioni = $rfq['incoterm_codice'] . ' - ' . ($rfq['incoterm_luogo'] ?: 'Sede di consegna');
            if ($resaPortoLabel !== '—') {
                $resaCondizioni .= ' (' . $resaPortoLabel . ')';
            }
        } elseif ($resaPortoLabel !== '—') {
            $resaCondizioni = $resaPortoLabel;
        }

        $tableCond = $section->addTable(['cellMargin' => 80]);
        
        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Consegna Richiesta', $fontBold);
        $tableCond->addCell(8000)->addText($rfq['data_consegna_prevista'] ? fmt_date($rfq['data_consegna_prevista']) : 'Non specificata', $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Luogo di Consegna', $fontBold);
        $tableCond->addCell(8000)->addText($consegnaIndirizzo, $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Modalità Pagamento', $fontBold);
        $tableCond->addCell(8000)->addText($rfq['metodo_pagamento_descrizione'] ?: $rfq['condizioni_pagamento'] ?: 'Come da accordi', $fontNormal);

        $tableCond->addRow();
        $tableCond->addCell(2540)->addText('Termini di Resa', $fontBold);
        $tableCond->addCell(8000)->addText($resaCondizioni, $fontNormal);

        if (!empty($rfq['note_fornitore'])) {
            $tableCond->addRow();
            $tableCond->addCell(2540)->addText('Note al Fornitore', $fontBold);
            $tableCond->addCell(8000)->addText($rfq['note_fornitore'], $fontNormal);
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

        $filename = "RFQ_" . str_replace('/', '_', $rfq['numero']) . ".docx";
        $wordHelper->streamDownload($filename);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API AJAX
    // ─────────────────────────────────────────────────────────────────────────

    /** Ricerca fornitori per autocomplete. */
    public function apiFornitori(): void
    {
        ob_start();
        $q    = get_param('q', '');
        
        $whereSearch = "(ragione_sociale LIKE ? OR nome LIKE ? OR codice LIKE ?)";
        if (mb_strlen($q) === 1) {
            // Cerca solo fornitori che INIZIANO con la lettera cercata, o che hanno codice corrispondente
            $like = $q . '%';
            $codeLike = $q;
        } else {
            // Ricerca parziale (contiene) per query più lunghe
            $like = '%' . $q . '%';
            $codeLike = '%' . $q . '%';
        }

        $st   = $this->pdo->prepare(
            "SELECT id, codice,
                    COALESCE(ragione_sociale, nome || ' ' || COALESCE(cognome,'')) AS nome,
                    partita_iva
               FROM clienti_fornitori
              WHERE (tipo = 'fornitore' OR tipo = 'entrambi')
                AND parent_id IS NULL
                AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
                AND attivo = 1
                AND (ragione_sociale LIKE ? OR nome LIKE ? OR codice LIKE ?)
              ORDER BY ragione_sociale, nome LIMIT 30"
        );
        $st->execute([$like, $like, $codeLike]);
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'fornitori' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** Ricerca prodotti con prezzo fornitore se disponibile. */
    public function apiProdotti(): void
    {
        ob_start();
        require_once INCLUDES_DIR . '/purchases_quotes.php';
        $q           = get_param('q', '');
        $fornitore_id = (int) get_param('fornitore_id', '0');

        // Reusa funzione da acquisti_offerte (stessa logica)
        $prodotti = qk_acquisti_prodotti_search($this->pdo, $q, $fornitore_id);
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'prodotti' => $prodotti]);
    }


    /** Crea un nuovo fornitore al volo. */
    public function apiCreaFornitore(): void
    {
        ob_start();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            return;
        }
        $ragione = trim(post('ragione_sociale', ''));
        $piva    = trim(post('partita_iva', ''));
        if ($ragione === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Ragione sociale obbligatoria.']);
            return;
        }
        try {
            require_once INCLUDES_DIR . '/anagrafiche.php';
            $codice = 'FOR-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ragione), 0, 8)) . '-' . rand(100, 999);
            $this->pdo->prepare(
                "INSERT INTO clienti_fornitori
                    (codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, attivo, created_at)
                 VALUES (?,?,'azienda',?,?,1,CURRENT_TIMESTAMP)"
            )->execute([$codice, 'fornitore', $ragione, $piva ?: null]);
            $id = (int) $this->pdo->lastInsertId();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $ragione, 'codice' => $codice]);
        } catch (\Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Crea un nuovo prodotto al volo. */
    public function apiCreaProdotto(): void
    {
        ob_start();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            return;
        }
        $nome = trim(post('nome', ''));
        if ($nome === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Nome prodotto obbligatorio.']);
            return;
        }
        try {
            $sku = 'NEW-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nome), 0, 8)) . '-' . rand(100, 999);
            $this->pdo->prepare(
                "INSERT INTO prodotti (sku_interno, nome, attivo, created_at) VALUES (?,?,1,CURRENT_TIMESTAMP)"
            )->execute([$sku, $nome]);
            $id = (int) $this->pdo->lastInsertId();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome, 'sku_interno' => $sku]);
        } catch (\Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** API endpoint for loading addresses for a selected internal company */
    public function apiAziendaIndirizzi(): void
    {
        ob_start();
        $azienda_id = (int) get_param('azienda_id', '0');
        
        $addresses = [];
        if ($azienda_id > 0) {
            $stmt = $this->pdo->prepare("SELECT soggetto_id FROM aziende_interne WHERE id = ?");
            $stmt->execute([$azienda_id]);
            $soggetto_id = (int) $stmt->fetchColumn();
            
            if ($soggetto_id > 0) {
                $stmtAddr = $this->pdo->prepare(
                    "SELECT id, tipo, nome_sede, predefinito, via, civico, cap, citta, provincia, nazione,
                            referente_consegna, telefono_consegna, note_consegna, presso
                       FROM indirizzi
                      WHERE soggetto_id = ? AND attivo = 1
                      ORDER BY CASE tipo WHEN 'spedizione' THEN 1 WHEN 'magazzino' THEN 2 ELSE 3 END,
                               predefinito DESC, id ASC"
                );
                $stmtAddr->execute([$soggetto_id]);
                $addresses = $stmtAddr->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'indirizzi' => $addresses]);
        exit;
    }

    /** API endpoint for loading details of a selected supplier (contacts, pricelists, general info) */
    public function apiFornitoreDettagli(): void
    {
        ob_start();
        $fornitore_id = (int) get_param('fornitore_id', '0');
        
        $fornitore = null;
        $contatti = [];
        $listini = [];
        
        if ($fornitore_id > 0) {
            // General info and default details
            $stmtForn = $this->pdo->prepare(
                "SELECT cf.id, cf.email, cf.telefono, cf.cellulare, df.condizioni_pagamento
                   FROM clienti_fornitori cf
              LEFT JOIN dettagli_fornitori df ON df.soggetto_id = cf.id
                  WHERE cf.id = ? AND cf.attivo = 1"
            );
            $stmtForn->execute([$fornitore_id]);
            $fornitore = $stmtForn->fetch(PDO::FETCH_ASSOC);
            
            if ($fornitore) {
                // Fetch contacts (sub-contacts)
                $stmtContatti = $this->pdo->prepare(
                    "SELECT id, COALESCE(nome || ' ' || COALESCE(cognome, ''), ragione_sociale) AS nome_completo,
                            email, telefono, cellulare
                       FROM clienti_fornitori
                      WHERE parent_id = ? AND attivo = 1
                      ORDER BY nome, cognome"
                );
                $stmtContatti->execute([$fornitore_id]);
                $contatti = $stmtContatti->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch active pricelists
                $stmtListini = $this->pdo->prepare(
                    "SELECT id, nome_listino
                       FROM listini_acquisto
                      WHERE fornitore_id = ?
                        AND (valido_dal IS NULL OR valido_dal <= DATE('now'))
                        AND (valido_al IS NULL OR valido_al >= DATE('now'))
                      ORDER BY nome_listino"
                );
                $stmtListini->execute([$fornitore_id]);
                $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'fornitore' => $fornitore,
            'contatti' => $contatti,
            'listini' => $listini
        ]);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER PRIVATI
    // ─────────────────────────────────────────────────────────────────────────

    private function defaultValues(): array
    {
        return [
            'fornitore_id'            => '',
            'indirizzo_consegna_id'   => '',
            'stato'                   => 'bozza',
            'data_emissione'          => date('Y-m-d'),
            'data_scadenza'           => '',
            'condizioni_pagamento'    => '',
            'valuta'                  => 'EUR',
            'note_fornitore'          => '',
            'note_interne'            => '',
            'riferimento_rfq_interno' => '',
            'azienda_interna_id'      => '',
            'incoterm_codice'         => '',
            'incoterm_luogo'          => '',
            'contatto_id'             => '',
            'contatto_nome'           => '',
            'contatto_telefono'       => '',
            'contatto_email'          => '',
            'fornitore_telefono'      => '',
            'fornitore_email'         => '',
            'listino_acquisto_id'     => '',
            'metodo_pagamento_id'     => '',
            'resa_porto'              => '',
            'consegna_via'            => '',
            'consegna_civico'         => '',
            'consegna_cap'            => '',
            'consegna_citta'          => '',
            'consegna_provincia'      => '',
            'consegna_nazione'        => 'IT',
            'consegna_presso'         => '',
            'consegna_referente'      => '',
            'consegna_telefono'       => '',
            'consegna_note'           => '',
        ];
    }

    private function mapDetailToValues(array $rfq): array
    {
        return [
            'fornitore_id'            => (string)($rfq['fornitore_id'] ?? ''),
            'indirizzo_consegna_id'   => (string)($rfq['indirizzo_consegna_id'] ?? ''),
            'stato'                   => $rfq['stato'],
            'data_emissione'          => $rfq['data_emissione'] ?? date('Y-m-d'),
            'data_scadenza'           => $rfq['data_scadenza'] ?? '',
            'condizioni_pagamento'    => $rfq['condizioni_pagamento'] ?? '',
            'valuta'                  => $rfq['valuta'] ?? 'EUR',
            'note_fornitore'          => $rfq['note_fornitore'] ?? '',
            'note_interne'            => $rfq['note_interne'] ?? '',
            'riferimento_rfq_interno' => $rfq['riferimento_rfq_interno'] ?? '',
            'azienda_interna_id'      => (string)($rfq['azienda_interna_id'] ?? ''),
            'incoterm_codice'         => $rfq['incoterm_codice'] ?? '',
            'incoterm_luogo'          => $rfq['incoterm_luogo'] ?? '',
            'contatto_id'             => (string)($rfq['contatto_id'] ?? ''),
            'contatto_nome'           => $rfq['contatto_nome'] ?? '',
            'contatto_telefono'       => $rfq['contatto_telefono'] ?? '',
            'contatto_email'          => $rfq['contatto_email'] ?? '',
            'fornitore_telefono'      => $rfq['fornitore_telefono'] ?? '',
            'fornitore_email'         => $rfq['fornitore_email'] ?? '',
            'listino_acquisto_id'     => (string)($rfq['listino_acquisto_id'] ?? ''),
            'metodo_pagamento_id'     => (string)($rfq['metodo_pagamento_id'] ?? ''),
            'resa_porto'              => $rfq['resa_porto'] ?? '',
            'consegna_via'            => $rfq['consegna_via'] ?? '',
            'consegna_civico'         => $rfq['consegna_civico'] ?? '',
            'consegna_cap'            => $rfq['consegna_cap'] ?? '',
            'consegna_citta'          => $rfq['consegna_citta'] ?? '',
            'consegna_provincia'      => $rfq['consegna_provincia'] ?? '',
            'consegna_nazione'        => $rfq['consegna_nazione'] ?? 'IT',
            'consegna_presso'         => $rfq['consegna_presso'] ?? '',
            'consegna_referente'      => $rfq['consegna_referente'] ?? '',
            'consegna_telefono'       => $rfq['consegna_telefono'] ?? '',
            'consegna_note'           => $rfq['consegna_note'] ?? '',
            'uom_mode'                => $rfq['uom_mode'] ?? 'commercial',
        ];
    }

    private function readPostValues(): array
    {
        return [
            'fornitore_id'            => post('fornitore_id', ''),
            'indirizzo_consegna_id'   => post('indirizzo_consegna_id', ''),
            'stato'                   => post('stato', 'bozza'),
            'data_emissione'          => post('data_emissione', date('Y-m-d')),
            'data_scadenza'           => post('data_scadenza', ''),
            'condizioni_pagamento'    => post('condizioni_pagamento', ''),
            'valuta'                  => post('valuta', 'EUR'),
            'note_fornitore'          => post('note_fornitore', ''),
            'note_interne'            => post('note_interne', ''),
            'riferimento_rfq_interno' => post('riferimento_rfq_interno', ''),
            'azienda_interna_id'      => post('azienda_interna_id', ''),
            'incoterm_codice'         => post('incoterm_codice', ''),
            'incoterm_luogo'          => post('incoterm_luogo', ''),
            'contatto_id'             => post('contatto_id', ''),
            'contatto_nome'           => post('contatto_nome', ''),
            'contatto_telefono'       => post('contatto_telefono', ''),
            'contatto_email'          => post('contatto_email', ''),
            'fornitore_telefono'      => post('fornitore_telefono', ''),
            'fornitore_email'         => post('fornitore_email', ''),
            'listino_acquisto_id'     => post('listino_acquisto_id', ''),
            'metodo_pagamento_id'     => post('metodo_pagamento_id', ''),
            'resa_porto'              => post('resa_porto', ''),
            'consegna_via'            => post('consegna_via', ''),
            'consegna_civico'         => post('consegna_civico', ''),
            'consegna_cap'            => post('consegna_cap', ''),
            'consegna_citta'          => post('consegna_citta', ''),
            'consegna_provincia'      => post('consegna_provincia', ''),
            'consegna_nazione'        => post('consegna_nazione', 'IT'),
            'consegna_presso'         => post('consegna_presso', ''),
            'consegna_referente'      => post('consegna_referente', ''),
            'consegna_telefono'       => post('consegna_telefono', ''),
            'consegna_note'           => post('consegna_note', ''),
            'uom_mode'                => post('uom_mode', 'commercial'),
        ];
    }

    private function readPostRighe(): array
    {
        $righe      = [];
        $prodotti   = $_POST['riga_prodotto_id']   ?? [];
        $quantita   = $_POST['riga_quantita']        ?? [];
        $descrizioni= $_POST['riga_descrizione']     ?? [];
        $sku        = $_POST['riga_sku_fornitore']   ?? [];
        $uom        = $_POST['riga_unita_misura_id'] ?? [];
        $prezzi     = $_POST['riga_prezzo_indicativo'] ?? [];
        $note       = $_POST['riga_note']             ?? [];
        $ivas       = $_POST['riga_iva_perc']         ?? [];
        
        $consegnaRichiesta = $_POST['riga_data_consegna_richiesta'] ?? [];
        $eRifiutato        = $_POST['riga_e_rifiutato'] ?? [];

        foreach ($prodotti as $i => $prod_id) {
            if ((int)$prod_id <= 0) continue;
            $righe[] = [
                'prodotto_id'       => (string)$prod_id,
                'quantita_richiesta'=> (string)($quantita[$i] ?? '1'),
                'descrizione'       => (string)($descrizioni[$i] ?? ''),
                'sku_fornitore'     => (string)($sku[$i] ?? ''),
                'unita_misura_id'   => (string)($uom[$i] ?? ''),
                'prezzo_indicativo' => (string)($prezzi[$i] ?? ''),
                'note'              => (string)($note[$i] ?? ''),
                'iva_perc'          => (string)($ivas[$i] ?? '22'),
                'data_consegna_richiesta' => !empty($consegnaRichiesta[$i]) ? $consegnaRichiesta[$i] : null,
                'e_rifiutato'             => !empty($eRifiutato[$i]) ? (int)$eRifiutato[$i] : 0,
            ];
        }
        return $righe;
    }

    private function validate(array $values, array $righe): array
    {
        $errors = [];
        if (($values['fornitore_id'] ?? '') === '') {
            $errors['fornitore_id'] = 'Il fornitore è obbligatorio.';
        }
        if (empty($righe)) {
            $errors['righe'] = 'Inserisci almeno una riga.';
        }
        foreach ($righe as $i => $r) {
            if ((float)($r['quantita_richiesta'] ?? 0) <= 0) {
                $errors["riga_{$i}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
        }
        return $errors;
    }
}

// ─── Funzione helper per la ricerca prodotti usata anche da RfqController ────

function qk_acquisti_prodotti_search(PDO $pdo, string $q, int $fornitore_id = 0, int $limit = 20): array
{
    ob_start();
    $like   = '%' . $q . '%';
    $params = [$like, $like, $like];

    $joinPrezzo   = '';
    $selectPrezzo = "NULL AS prezzo_unitario, NULL AS sku_fornitore, NULL AS uom_comm, NULL AS uom_comm_id";

    if ($fornitore_id > 0) {
        $joinPrezzo   = "LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.fornitore_id = $fornitore_id";
        $selectPrezzo = "pf.sku_fornitore,
            NULL AS uom_comm,
            NULL AS uom_comm_id,
            (SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp
              WHERE pfp.prodotto_fornitore_id = pf.id
                AND pfp.quantita_minima = 0 LIMIT 1) AS prezzo_unitario";
    }

    $st = $pdo->prepare(
        "SELECT p.id, p.sku_interno, p.nome, um.codice AS uom, p.iva_acquisto_default AS iva_perc,
                p.unita_misura_stock_id AS uom_id,
                um_comm_prod.codice AS uom_comm_prod,
                p.unita_contenuto_id AS uom_comm_prod_id,
                p.quantita_contenuto,
                $selectPrezzo
           FROM prodotti p
      LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
      LEFT JOIN unita_misura um_comm_prod ON um_comm_prod.id = p.unita_contenuto_id
           $joinPrezzo
          WHERE p.attivo = 1
            AND (p.nome LIKE ? OR p.sku_interno LIKE ? OR p.ean LIKE ?)
          ORDER BY p.nome LIMIT ?"
    );
    $params[] = $limit;
    $st->execute($params);
    ob_end_clean();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
