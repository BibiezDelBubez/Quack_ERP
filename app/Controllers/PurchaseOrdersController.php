<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_orders.php';
require_once INCLUDES_DIR . '/word_helper.php';

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

                try {
                    $this->pdo->prepare(
                        'UPDATE ordini_testata SET
                            note_fornitore = ?, note_interne = ?,
                            riferimento_fornitore = ?, altri_riferimenti = ?,
                            data_consegna_prevista = ?
                          WHERE id = ?'
                    )->execute([
                        $note_fornitore, $note_interne,
                        $rif_fornitore, $altri_rif,
                        $data_cons_prevista !== '' ? $data_cons_prevista : null,
                        $id,
                    ]);
                    $detail = qk_ordine_detail($this->pdo, $id);
                    flash_set('success', 'Ordine aggiornato.');
                } catch (\Throwable $e) {
                    $errors['_global'] = $e->getMessage();
                }
            }
        }

        $saved      = get_param('saved', '') === '1';
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
                    $stCheck = $this->pdo->prepare('SELECT COUNT(*) FROM ordini_righe WHERE ordine_id = ? AND (prezzo_unitario <= 0 OR quantita_ordinata <= 0)');
                    $stCheck->execute([$id]);
                    if ((int)$stCheck->fetchColumn() > 0) {
                        throw new \RuntimeException("Non è possibile confermare l'ordine: tutte le righe devono avere una quantità e un prezzo unitario maggiori di zero.");
                    }

                    // Avvia Macro-Transazione (Tutto o Niente)
                    $this->pdo->beginTransaction();
                    try {
                        qk_ordine_invia($this->pdo, $id);

                        // Create dynamic reception
                        require_once INCLUDES_DIR . '/purchases_receipts.php';
                        $stCheckRec = $this->pdo->prepare("SELECT id FROM ricezioni_testata WHERE ordine_id = ? AND stato = 'bozza' LIMIT 1");
                        $stCheckRec->execute([$id]);
                        if (!$stCheckRec->fetch()) {
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

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }

        redirect(url('purchases/orders/edit') . '?id=' . $id);
    }

    public function pdf(): void
    {
        // TODO: generazione PDF ordine di acquisto
        $id = (int) get_param('id', '0');
        flash_set('danger', 'La stampa PDF degli ordini non è ancora disponibile.');
        redirect(url('purchases/orders/edit') . '?id=' . $id);
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
        $azienda = null;
        if (!empty($ordine['azienda_interna_id'])) {
            $stmtAz = $this->pdo->prepare(
                "SELECT cf.*, az.*
                   FROM aziende_interne az
                   JOIN clienti_fornitori cf ON cf.id = az.soggetto_id
                  WHERE az.id = ? LIMIT 1"
            );
            $stmtAz->execute([(int)$ordine['azienda_interna_id']]);
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
        if (!empty($ordine['fornitore_id'])) {
            $stInd = $this->pdo->prepare("
                SELECT * FROM indirizzi 
                WHERE soggetto_id = ? 
                ORDER BY (CASE WHEN tipo='sede_legale' THEN 1 WHEN tipo='sede_operativa' THEN 2 ELSE 3 END), predefinito DESC 
                LIMIT 1
            ");
            $stInd->execute([(int)$ordine['fornitore_id']]);
            $indForn = $stInd->fetch(PDO::FETCH_ASSOC);
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
