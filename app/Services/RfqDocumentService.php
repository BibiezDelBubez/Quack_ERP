<?php
namespace App\Services;

class RfqDocumentService
{
    /**
     * Genera il PDF della Richiesta di Preventivo.
     * Restituisce il contenuto binario (non invia header HTTP).
     *
     * @param array $rfq
     * @param array $righe
     * @param array $azienda
     * @param string $indirizzoFornitore
     * @return string Contenuto binario del PDF
     */
    public function generatePdf(array $rfq, array $righe, array $azienda, string $indirizzoFornitore = ''): string
    {
        // Genera il PDF (A4 standard)
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
        $mpdf->SetAuthor('Gemina ERP');

        // Estrai il contenuto HTML usando il template
        ob_start();
        require \APP_ROOT_DIR . '/app/views/purchases/rfq/_pdf_template.php';
        $html = ob_get_clean();

        // Scrivi l'HTML e ritorna il binario (STRING_RETURN)
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Genera il DOCX della Richiesta di Preventivo.
     * Restituisce il path temporaneo locale del file generato.
     *
     * @param array $rfq
     * @param array $righe
     * @param array $azienda
     * @param string $indirizzoFornitore
     * @return string Percorso assoluto del file temporaneo creato
     */
    public function generateDocx(array $rfq, array $righe, array $azienda, string $indirizzoFornitore = ''): string
    {
        $wordHelper = new \Quack\WordHelper();
        $phpWord = $wordHelper->getPhpWord();
        $section = $wordHelper->createStandardSection();
        $wordHelper->addStandardHeader($section, $azienda, '');
        $wordHelper->addStandardFooter($section, 'RFQ: ' . $rfq['numero']);

        // Styles
        $fontTitle = ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => '1A5276'];
        $fontBold = ['name' => 'Arial', 'size' => 9.5, 'bold' => true, 'color' => '333333'];
        $fontNormal = ['name' => 'Arial', 'size' => 9.5, 'color' => '222222'];
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
        $tableGrid->addCell(1200, $thStyle)->addText('Prezzo Unitario', $thText, ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]);
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
        $tableCond->addCell(8000)->addText(!empty($rfq['data_consegna_prevista']) ? fmt_date($rfq['data_consegna_prevista']) : 'Non specificata', $fontNormal);

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

        // Salva il documento in locale invece di inviarlo al browser
        $tempPath = upload_ensure_dir('docx_tmp') . '/' . uniqid('rfq_', true) . '.docx';
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return $tempPath;
    }
}
