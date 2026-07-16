<?php
// Preparazione Header Azienda
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

// Preparazione Recapiti Fornitore
$contattoNome = $rfq['contatto_nome'] ?: '';
$contattoTel = $rfq['contatto_telefono'] ?: $rfq['fornitore_telefono'] ?: '';
$contattoEmail = $rfq['contatto_email'] ?: $rfq['fornitore_email'] ?: '';

// Preparazione Condizioni di Fornitura
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
?>

<htmlpageheader name="RfqHeader">
    <div style="width:100%; font-family:sans-serif; margin-bottom:5mm;">
        <?= $headerHtml ?>
    </div>
</htmlpageheader>

<htmlpagefooter name="RfqFooter">
    <div style="border-top:1px solid #999; padding-top:3mm; font-size:8pt; color:#666; text-align:center; font-family:sans-serif;">
        Pagina {PAGENO} di {nbpg} &mdash; RFQ: <?= e($rfq['numero']) ?>
    </div>
</htmlpagefooter>

<sethtmlpageheader name="RfqHeader" value="on" show-this-page="1" />
<sethtmlpagefooter name="RfqFooter" value="on" />

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

<table style="width:100%; border-collapse:collapse; margin-top:2mm; margin-bottom:5mm;">
    <tr>
        <td style="width:48%; vertical-align:top;">
            <div class="info-box">
                <strong style="font-size:11pt; color:#1a5276;">Dettagli Richiesta</strong>
                <table class="info-table">
                    <tr>
                        <td class="lbl">Rif. Interno:</td>
                        <td><strong><?= e($rfq['riferimento_rfq_interno'] ?: '—') ?></strong></td>
                    </tr>
                    <tr>
                        <td class="lbl">Data Emis.:</td>
                        <td><?= e(fmt_date($rfq['data_emissione'])) ?></td>
                    </tr>
                    <tr>
                        <td class="lbl">Scadenza:</td>
                        <td><?= e(fmt_date($rfq['data_scadenza'])) ?></td>
                    </tr>
                </table>
            </div>
        </td>
        
        <td style="width:4%;">&nbsp;</td>
        
        <td style="width:48%; vertical-align:top;">
            <div class="info-box">
                <strong style="font-size:11pt; color:#1a5276;">Spett.le Fornitore</strong>
                <div style="margin-top:1.5mm; font-size:9.5pt; line-height:1.45;">
                    <strong><?= e($rfq['fornitore_nome']) ?></strong><br>
                    <?= $indirizzoFornitore ?: '—' ?><br>
                    <?php if (!empty($contattoNome)): ?>
                        Alla c.a.: <strong><?= e($contattoNome) ?></strong><br>
                    <?php endif; ?>
                    <?php
                    $contactLine = [];
                    if (!empty($contattoTel)) $contactLine[] = 'Tel: ' . e($contattoTel);
                    if (!empty($contattoEmail)) $contactLine[] = 'Email: ' . e($contattoEmail);
                    if (!empty($contactLine)) echo implode(' | ', $contactLine);
                    ?>
                </div>
            </div>
        </td>
    </tr>
</table>

<h2>Articoli in Richiesta</h2>
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
    <tbody>
        <?php
        $fill = false;
        $hasPrices = false;
        $imponibile = 0.0;
        
        foreach ($righe as $r):
            $zebraClass = $fill ? ' class="zebra"' : '';
            $qty = (float)$r['quantita_richiesta'];
            $um = $r['uom'] ?: 'pz';
            
            $prezzo = (float)($r['prezzo_indicativo'] ?? 0);
            $rigaTotale = $qty * $prezzo;

            $prezzoStr = $prezzo > 0 ? fmt_money($prezzo) : '—';
            $rigaTotaleStr = $rigaTotale > 0 ? fmt_money($rigaTotale) : '—';
            
            if ($prezzo > 0) {
                $hasPrices = true;
                $imponibile += $rigaTotale;
            }
        ?>
        <tr<?= $zebraClass ?>>
            <td class="center"><strong><?= e($r['sku_fornitore'] ?: '—') ?></strong></td>
            <td><?= e($r['descrizione'] ?: $r['prodotto_nome']) ?></td>
            <td class="center"><?= e($r['formato'] ?: '—') ?></td>
            <td class="num"><strong><?= fmt_qty($qty) ?></strong></td>
            <td class="center"><?= e($um) ?></td>
            <td class="num"><?= $prezzoStr ?></td>
            <td class="num"><strong><?= $rigaTotaleStr ?></strong></td>
        </tr>
        <?php 
            $fill = !$fill;
        endforeach; 
        ?>
    </tbody>
</table>

<?php if ($hasPrices): 
    $iva = round($imponibile * 0.22, 2);
    $totale = $imponibile + $iva;
?>
<table class="totals-table">
    <tr>
        <td style="width:50%;">&nbsp;</td>
        <td style="text-align:right; color:#666; white-space:nowrap;">Imponibile Stimato:</td>
        <td class="val"><?= fmt_money($imponibile) ?></td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td style="text-align:right; color:#666; white-space:nowrap;">I.V.A. Stimata (22%):</td>
        <td class="val"><?= fmt_money($iva) ?></td>
    </tr>
    <tr>
        <td>&nbsp;</td>
        <td style="text-align:right; font-weight:bold; color:#1a5276; white-space:nowrap;">Totale Indicativo:</td>
        <td class="val" style="color:#1a5276; border-top:1px solid #1a5276;"><?= fmt_money($totale) ?></td>
    </tr>
</table>
<?php endif; ?>

<h2>Condizioni di Fornitura</h2>
<table class="cond-table">
    <tr>
        <td class="lbl">Consegna Richiesta:</td>
        <td><?= !empty($rfq['data_consegna_prevista']) ? e(fmt_date($rfq['data_consegna_prevista'])) : 'Non specificata' ?></td>
    </tr>
    <tr>
        <td class="lbl">Luogo di Consegna:</td>
        <td><?= $consegnaIndirizzo ?></td>
    </tr>
    <tr>
        <td class="lbl">Modalità Pagamento:</td>
        <td><?= e($rfq['metodo_pagamento_descrizione'] ?: $rfq['condizioni_pagamento'] ?: 'Come da accordi') ?></td>
    </tr>
    <tr>
        <td class="lbl">Termini di Resa:</td>
        <td><?= $resaCondizioni ?></td>
    </tr>
    <?php if (!empty($rfq['note_fornitore'])): ?>
    <tr>
        <td class="lbl">Note al Fornitore:</td>
        <td><?= nl2br(e($rfq['note_fornitore'])) ?></td>
    </tr>
    <?php endif; ?>
</table>

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
</div>
