<?php
// Preparazione Header Azienda Emittente
$headerHtml = '';
if (!empty($azienda['header_path']) && file_exists(upload_abs($azienda['header_path']))) {
    $headerHtml = '<img src="' . upload_abs($azienda['header_path']) . '" style="width:100%; max-height:35mm;">';
} elseif (!empty($azienda['logo_path']) && file_exists(upload_abs($azienda['logo_path']))) {
    $headerHtml = '
    <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
        <tr>
            <td style="width:50%;"><img src="' . upload_abs($azienda['logo_path']) . '" style="max-height:22mm;"></td>
            <td style="width:50%; text-align:right; font-weight:bold; font-size:14pt; color:#1a5276; vertical-align:middle;">
                PREVENTIVO DI VENDITA
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
                PREVENTIVO DI VENDITA
            </td>
        </tr>
    </table>';
}

$contattoNome  = $quota['contatto_nome'] ?: '';
$contattoTel   = $quota['contatto_telefono'] ?: '';
$contattoEmail = $quota['contatto_email'] ?: '';

$consegnaIndirizzo = '—';
if (!empty($quota['consegna_via'])) {
    $consegnaIndirizzo = '<strong>' . e($quota['consegna_presso'] ?: $quota['cliente_ragione_sociale']) . '</strong> - ' .
                         e($quota['consegna_via']) . ' ' . e($quota['consegna_civico']) . ', ' .
                         e($quota['consegna_cap']) . ' ' . e($quota['consegna_citta']) . ' (' . e($quota['consegna_provincia']) . ')';
}

$titoloRighe = !empty($quota['oggetto']) ? $quota['oggetto'] : 'Articoli Richiesti';
?>

<htmlpageheader name="SalesQuoteHeader">
    <div style="width:100%; font-family:sans-serif; margin-bottom:5mm;">
        <?= $headerHtml ?>
    </div>
</htmlpageheader>

<htmlpagefooter name="SalesQuoteFooter">
    <div style="border-top:1px solid #999; padding-top:3mm; font-size:8pt; color:#666; text-align:center; font-family:sans-serif;">
        Pagina {PAGENO} di {nbpg} &mdash; Preventivo: <?= e($quota['numero_preventivo']) ?>
    </div>
</htmlpagefooter>

<sethtmlpageheader name="SalesQuoteHeader" value="on" show-this-page="1" />
<sethtmlpagefooter name="SalesQuoteFooter" value="on" />

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
</style>

<!-- BLOCK 1: Info Generali e Recapiti -->
<table style="width:100%; border-collapse:collapse; margin-top:2mm; margin-bottom:5mm;">
    <tr>
        <td style="width:48%; vertical-align:top;">
            <div class="info-box">
                <strong style="font-size:11pt; color:#1a5276;">Dettagli Documento</strong>
                <table class="info-table">
                    <tr>
                        <td class="lbl">Rif. Interno:</td>
                        <td><strong><?= e($quota['riferimento_interno'] ?: '—') ?></strong></td>
                    </tr>
                    <tr>
                        <td class="lbl">Data Offerta:</td>
                        <td><?= e(fmt_date($quota['data_offerta'])) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Scadenza:</td>
                        <td><?= e(fmt_date($quota['data_scadenza'])) ?></td>
                    </tr>
                </table>
            </div>
        </td>
        
        <td style="width:4%;">&nbsp;</td>
        
        <td style="width:48%; vertical-align:top;">
            <div class="info-box">
                <strong style="font-size:11pt; color:#1a5276;">Spett.le Cliente</strong>
                <div style="margin-top:1.5mm; font-size:9.5pt; line-height:1.45;">
                    <strong><?= e($quota['cliente_ragione_sociale']) ?></strong><br>
                    <?= e($quota['cliente_indirizzo'] ?: '—') ?><br>
                    <?php if (!empty($quota['cliente_partita_iva'])): ?>
                        P.IVA: <?= e($quota['cliente_partita_iva']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($contattoNome)): ?>
                        Alla c.a.: <strong><?= e($contattoNome) ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($contattoTel) || !empty($contattoEmail)): ?>
                        <?= (!empty($contattoTel) ? 'Tel: ' . e($contattoTel) : '') ?>
                        <?= (!empty($contattoEmail) ? ' | Email: ' . e($contattoEmail) : '') ?><br>
                    <?php endif; ?>
                </div>
            </div>
        </td>
    </tr>
</table>

<!-- BLOCK 2: Righe Articoli -->
<h2><?= e($titoloRighe) ?></h2>
<table class="righe-table">
    <thead>
        <tr>
            <th style="width:12%">SKU</th>
            <th style="width:30%">Descrizione</th>
            <th style="width:15%">Confezione</th>
            <th style="width:10%; text-align:right;">Q.tà</th>
            <th style="width:8%; text-align:center;">UdM</th>
            <th style="width:12%; text-align:right;">Prezzo Unit.</th>
            <th style="width:13%; text-align:right;">Totale</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $fill = false;
        foreach ($righe as $r):
            $zebraClass = $fill ? ' class="zebra"' : '';
            $qty = (float)$r['quantita'];
            $um = $r['unita_misura'] ?: 'pz';
            $pu = (float)$r['prezzo_unitario'];
            $sconto = (float)$r['sconto_perc'];
            $rigaTotale = (float)$r['imponibile_riga'];
            
            $prezzoStr = fmt_money($pu);
            if ($sconto > 0) {
                $prezzoStr .= '<br><span style="font-size:7.5pt; color:#666;">Sconto ' . $sconto . '%</span>';
            }
        ?>
            <tr<?= $zebraClass ?>>
                <td class="center"><strong><?= e($r['sku'] ?: '—') ?></strong></td>
                <td><?= e($r['descrizione']) ?></td>
                <td class="center"><?= e($r['quantita_confezione'] ?: '—') ?></td>
                <td class="num"><strong><?= fmt_qty($qty) ?></strong></td>
                <td class="center"><?= e($um) ?></td>
                <td class="num"><?= $prezzoStr ?></td>
                <td class="num"><strong><?= fmt_money($rigaTotale) ?></strong></td>
            </tr>
        <?php
            $fill = !$fill;
        endforeach;
        ?>
    </tbody>
</table>

<!-- Totali -->
<table class="totals-table">
    <tr>
        <td style="width:50%;">&nbsp;</td>
        <td style="text-align:right; color:#666; white-space:nowrap;">Totale Imponibile:</td>
        <td class="val"><?= fmt_money($imponibile) ?></td>
    </tr>
    <?php if ($sconto_globale_perc > 0): ?>
    <tr>
        <td>&nbsp;</td>
        <td style="text-align:right; color:#666; white-space:nowrap;">Sconto Globale (<?= $sconto_globale_perc ?>%):</td>
        <td class="val">Sconto applicato</td>
    </tr>
    <?php endif; ?>
    <tr>
        <td>&nbsp;</td>
        <td style="text-align:right; color:#666; white-space:nowrap;">Totale I.V.A.:</td>
        <td class="val"><?= fmt_money($totale_iva) ?></td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td style="text-align:right; font-weight:bold; color:#1a5276; white-space:nowrap;">Totale Documento:</td>
        <td class="val" style="color:#1a5276; border-top:1px solid #1a5276;"><?= fmt_money($totale) ?></td>
    </tr>
</table>

<!-- BLOCK 3: Condizioni -->
<h2>Condizioni di Vendita</h2>
<table class="cond-table">
    <tr>
        <td class="lbl">Modalità Pagamento:</td>
        <td><?= e($quota['condizioni_pagamento'] ?: 'Come da accordi') ?></td>
    </tr>
    <tr>
        <td class="lbl">Trasporto / Consegna:</td>
        <td><?= e($quota['trasporto'] ?: '—') ?></td>
    </tr>
    <tr>
        <td class="lbl">Luogo di Destinazione:</td>
        <td><?= $consegnaIndirizzo ?></td>
    </tr>
    <?php if (!empty($quota['consegna_note'])): ?>
    <tr>
        <td class="lbl">Note Consegna:</td>
        <td><?= e($quota['consegna_note']) ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($quota['note_cliente'])): ?>
    <tr>
        <td class="lbl">Note aggiuntive:</td>
        <td><?= nl2br(e($quota['note_cliente'])) ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- BLOCK 4: Riquadri Firma -->
<div style="margin-top:15mm; page-break-inside:avoid;">
    <table style="width:100%; border-collapse:collapse; font-family:sans-serif;">
        <tr>
            <td style="width:45%; vertical-align:bottom; padding-bottom:6px;">
                <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;">Accettazione per Ordine</span><br>
                <span style="font-size:7.5pt; color:#777777;">Timbro e Firma leggibile del Cliente</span>
            </td>
            <td style="width:10%;"></td>
            <td style="width:45%; vertical-align:bottom; padding-bottom:6px; text-align:right;">
                <span style="font-size:8.5pt; font-weight:bold; color:#444444; text-transform:uppercase; letter-spacing:0.5px;"><?= e(!empty($azienda['ragione_sociale']) ? $azienda['ragione_sociale'] : 'Azienda') ?></span><br>
                <span style="font-size:7.5pt; color:#777777;">Firma Direzione Commerciale</span>
            </td>
        </tr>
        <tr>
            <td style="border:1px dashed #bbbbbb; padding:12px; height:25mm; vertical-align:bottom; background-color:#fafafa;">
                <div style="border-bottom:1px solid #aaaaaa; width:80%;"></div>
            </td>
            <td></td>
            <td style="border:1px dashed #bbbbbb; padding:12px; height:25mm; vertical-align:bottom; background-color:#fafafa;">
                <div style="border-bottom:1px solid #aaaaaa; width:80%; margin-left:auto;"></div>
            </td>
        </tr>
    </table>
</div>
