<?php
namespace App\Services;

class SalesQuoteDocumentService
{
    /**
     * Genera il PDF del Preventivo di Vendita.
     * Restituisce il contenuto binario (non invia header HTTP).
     *
     * @param array $dati Array di dati ritornato da SalesQuotesController::preparaDatiStampaOfferta
     * @return string Contenuto binario del PDF
     */
    public function generatePdf(array $dati): string
    {
        $quota               = $dati['quota'];
        $righe               = $dati['righe'];
        $azienda             = $dati['azienda'];
        $imponibile          = $dati['imponibile'];
        $totale_iva          = $dati['totale_iva'];
        $totale              = $dati['totale'];
        $sconto_globale_perc = $dati['sconto_globale_perc'];

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 45,
            'margin_bottom' => 25,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'tempDir'       => upload_ensure_dir('mpdf_tmp'),
        ]);

        $mpdf->SetTitle('Preventivo - ' . $quota['numero_preventivo']);
        $mpdf->SetAuthor('Quack ERP');

        ob_start();
        require \APP_ROOT_DIR . '/app/views/sales/quotes/_pdf_template.php';
        $html = ob_get_clean();

        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Genera il file DOCX del Preventivo di Vendita.
     * Restituisce il percorso assoluto del file temporaneo generato.
     *
     * @param array $dati Array di dati ritornato da SalesQuotesController::preparaDatiStampaOfferta
     * @return string Percorso assoluto del file temporaneo creato
     */
    public function generateDocx(array $dati): string
    {
        $quota               = $dati['quota'];
        $righe               = $dati['righe'];
        $azienda             = $dati['azienda'];
        $imponibile          = $dati['imponibile'];
        $totale_iva          = $dati['totale_iva'];
        $totale              = $dati['totale'];
        $sconto_globale_perc = $dati['sconto_globale_perc'];

        $wordHelper = new \Quack\WordHelper();
        $phpWord = $wordHelper->getPhpWord();
        $section = $wordHelper->createStandardSection();
        $wordHelper->addStandardHeader($section, $azienda, '');
        $wordHelper->addStandardFooter($section, 'Prev. N°: ' . $quota['numero_preventivo']);

        // Styles
        $fontTitle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1A5276'];
        $fontBold = ['name' => 'Arial', 'size' => 9.5, 'bold' => true, 'color' => '333333'];
        $fontNormal = ['name' => 'Arial', 'size' => 9.5, 'color' => '222222'];
        $fontTotal = ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '1A5276'];

        // Document Title
        $section->addText('PREVENTIVO DI VENDITA - N° ' . $quota['numero_preventivo'], ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '1A5276'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceAfter' => 150]);

        // BLOCK 1: Two Column Layout Table
        $block1Table = $section->addTable(['cellMargin' => 100]);
        $block1Table->addRow();
        
        // Left Cell (Dettagli Preventivo)
        $leftCell = $block1Table->addCell(5070, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunLeft = $leftCell->addTextRun();
        $textRunLeft->addText('Dettagli Documento', $fontTitle);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Rif. Interno: ' . ($quota['riferimento_interno'] ?: '—'), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Data Offerta: ' . fmt_date($quota['data_offerta']), $fontNormal);
        $textRunLeft->addTextBreak(1);
        $textRunLeft->addText('Scadenza: ' . fmt_date($quota['data_scadenza']), $fontNormal);

        // Spacer Cell
        $block1Table->addCell(400);

        // Right Cell (Cliente)
        $rightCell = $block1Table->addCell(4800, ['borderSize' => 6, 'borderColor' => 'CCCCCC', 'bgColor' => 'FCFCFC']);
        $textRunRight = $rightCell->addTextRun();
        $textRunRight->addText('Spett.le Cliente', $fontTitle);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($quota['cliente_ragione_sociale'], ['name' => 'Arial', 'size' => 9.5, 'bold' => true]);
        $textRunRight->addTextBreak(1);
        $textRunRight->addText($quota['cliente_indirizzo'] ?: '—', $fontNormal);
        if ($quota['cliente_partita_iva']) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('P.IVA: ' . $quota['cliente_partita_iva'], $fontNormal);
        }
        
        $contattoNome = $quota['contatto_nome'] ?: '';
        $contattoTel = $quota['contatto_telefono'] ?: '';
        $contattoEmail = $quota['contatto_email'] ?: '';
        if ($contattoNome) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Alla c.a.: ' . $contattoNome, $fontBold);
        }
        if ($contattoTel || $contattoEmail) {
            $textRunRight->addTextBreak(1);
            $textRunRight->addText('Tel: ' . $contattoTel . ' | Email: ' . $contattoEmail, $fontNormal);
        }

        $section->addTextBreak(1);

        $titoloRighe = !empty($quota['oggetto']) ? $quota['oggetto'] : 'Articoli Richiesti';

        // BLOCK 2: Righe Articoli
        $section->addText($titoloRighe, $fontTitle, ['spaceBefore' => 100, 'spaceAfter' => 100]);
        
        $tableGrid = $section->addTable(['cellMargin' => 80]);
        $thStyle = ['bgColor' => '1A5276', 'borderSize' => 6, 'borderColor' => '1A5276'];
        $thText = ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => 'FFFFFF'];

        $tableGrid->addRow();
        $tableGrid->addCell(1600, $thStyle)->addText('SKU', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(3240, $thStyle)->addText('Descrizione', $thText);
        $tableGrid->addCell(1600, $thStyle)->addText('Confezione', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1000, $thStyle)->addText('Q.tà', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(600, $thStyle)->addText('UdM', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        $tableGrid->addCell(1200, $thStyle)->addText('Prezzo Unit.', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableGrid->addCell(1300, $thStyle)->addText('Totale', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $fill = false;
        foreach ($righe as $r) {
            $rowBg = $fill ? 'F5F7FA' : 'FFFFFF';
            $tdStyle = ['bgColor' => $rowBg, 'borderBottomSize' => 4, 'borderBottomColor' => 'CCCCCC'];
            
            $qty = (float)$r['quantita'];
            $um = $r['unita_misura'] ?: 'pz';
            $pu = (float)$r['prezzo_unitario'];
            $sconto = (float)$r['sconto_perc'];
            $rigaTotale = (float)$r['imponibile_riga'];

            $prezzoStr = fmt_money($pu);
            if ($sconto > 0) {
                $prezzoStr .= " (Sconto {$sconto}%)";
            }

            $tableGrid->addRow();
            $tableGrid->addCell(1600, $tdStyle)->addText($r['sku'] ?: '—', $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(3240, $tdStyle)->addText($r['descrizione'], $fontNormal);
            $tableGrid->addCell(1600, $tdStyle)->addText($r['quantita_confezione'] ?: '—', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1000, $tdStyle)->addText(fmt_qty($qty), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(600, $tdStyle)->addText($um, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $tableGrid->addCell(1200, $tdStyle)->addText($prezzoStr, $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableGrid->addCell(1300, $tdStyle)->addText(fmt_money($rigaTotale), $fontBold, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

            $fill = !$fill;
        }

        $section->addTextBreak(1);

        $tableTotals = $section->addTable();
        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale Imponibile:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($imponibile), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        if ($sconto_globale_perc > 0) {
            $tableTotals->addRow();
            $tableTotals->addCell(7540);
            $tableTotals->addCell(1800)->addText("Sconto Globale ({$sconto_globale_perc}%):", $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
            $tableTotals->addCell(1200)->addText('Sconto applicato', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        }

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale I.V.A.:', $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($totale_iva), $fontNormal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        $tableTotals->addRow();
        $tableTotals->addCell(7540);
        $tableTotals->addCell(1800)->addText('Totale Documento:', $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $tableTotals->addCell(1200)->addText(fmt_money($totale), $fontTotal, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

        // BLOCK 3: Condizioni
        $section->addText('Condizioni di Vendita', $fontTitle, ['spaceBefore' => 150, 'spaceAfter' => 100]);
        
        $consegnaIndirizzo = '—';
        if (!empty($quota['consegna_via'])) {
            $consegnaIndirizzo = ($quota['consegna_presso'] ?: $quota['cliente_ragione_sociale']) . ' - ' .
                                 $quota['consegna_via'] . ' ' . $quota['consegna_civico'] . ', ' .
                                 $quota['consegna_cap'] . ' ' . $quota['consegna_citta'] . ' (' . $quota['consegna_provincia'] . ')';
        }

        $condTable = $section->addTable(['cellMargin' => 80]);
        
        $condTable->addRow();
        $condTable->addCell(2500)->addText('Modalità Pagamento:', $fontBold);
        $condTable->addCell(8040)->addText($quota['condizioni_pagamento'] ?: 'Come da accordi', $fontNormal);

        $condTable->addRow();
        $condTable->addCell(2500)->addText('Trasporto / Consegna:', $fontBold);
        $condTable->addCell(8040)->addText($quota['trasporto'] ?: '—', $fontNormal);

        $condTable->addRow();
        $condTable->addCell(2500)->addText('Luogo di Destinazione:', $fontBold);
        $condTable->addCell(8040)->addText($consegnaIndirizzo, $fontNormal);

        if (!empty($quota['consegna_note'])) {
            $condTable->addRow();
            $condTable->addCell(2500)->addText('Note Consegna:', $fontBold);
            $condTable->addCell(8040)->addText($quota['consegna_note'], $fontNormal);
        }

        if (!empty($quota['note_cliente'])) {
            $condTable->addRow();
            $condTable->addCell(2500)->addText('Note aggiuntive:', $fontBold);
            $condTable->addCell(8040)->addText($quota['note_cliente'], $fontNormal);
        }

        // BLOCK 4: Riquadri firma/timbro
        $section->addTextBreak(2);
        $firmaTable = $section->addTable(['cellMargin' => 120]);
        
        // Row 1: Labels
        $firmaTable->addRow();
        $cellLabelLeft = $firmaTable->addCell(4770);
        $cellLabelLeft->addText('ACCETTAZIONE PER ORDINE', ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444']);
        $cellLabelLeft->addText('Timbro e Firma leggibile del Cliente', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777']);
        
        $firmaTable->addCell(1000); // spacer
        
        $cellLabelRight = $firmaTable->addCell(4770);
        $aziendaNome = !empty($azienda['ragione_sociale']) ? $azienda['ragione_sociale'] : 'Azienda';
        $cellLabelRight->addText(mb_strtoupper($aziendaNome, 'UTF-8'), ['name' => 'Arial', 'size' => 8.5, 'bold' => true, 'color' => '444444'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
        $cellLabelRight->addText('Firma Direzione Commerciale', ['name' => 'Arial', 'size' => 7.5, 'color' => '777777'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);

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

        $tempDir = upload_ensure_dir('docx_tmp');
        $tempFile = $tempDir . '/preventivo_' . uniqid() . '.docx';

        $xmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $xmlWriter->save($tempFile);

        return $tempFile;
    }
}
