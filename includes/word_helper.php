<?php
/**
 * word_helper.php — Helper utility for generating standardized DOCX files in Quack ERP.
 */

namespace Quack;

class WordHelper
{
    /** @var \PhpOffice\PhpWord\PhpWord */
    private $phpWord;

    /** @var string */
    public const MAX_PRINT_WIDTH = 10540; // Full width in dxa for A4 with 12mm (680 dxa) margins

    public function __construct()
    {
        $this->phpWord = new \PhpOffice\PhpWord\PhpWord();
    }

    /**
     * Get the underlying PhpWord instance.
     * 
     * @return \PhpOffice\PhpWord\PhpWord
     */
    public function getPhpWord(): \PhpOffice\PhpWord\PhpWord
    {
        return $this->phpWord;
    }

    /**
     * Create a standard A4 page section with balanced margins.
     * 
     * @param array $options Custom margin overrides
     * @return \PhpOffice\PhpWord\Element\Section
     */
    public function createStandardSection(array $options = []): \PhpOffice\PhpWord\Element\Section
    {
        $sectionStyle = array_merge([
            'paperSize'    => 'A4',
            'marginLeft'   => 680,  // ~12mm
            'marginRight'  => 680,  // ~12mm
            'marginTop'    => 1800, // ~31mm
            'marginBottom' => 1000, // ~17mm
        ], $options);

        return $this->phpWord->addSection($sectionStyle);
    }

    /**
     * Add the standard corporate header to a section.
     * 
     * @param \PhpOffice\PhpWord\Element\Section $section
     * @param array|null $azienda Company associative array containing logo_path or header_path
     * @param string $documentTitle Document type title (e.g. 'RICHIESTA DI PREVENTIVO (RFQ)')
     */
    public function addStandardHeader(\PhpOffice\PhpWord\Element\Section $section, ?array $azienda, string $documentTitle): void
    {
        $header = $section->addHeader();
        
        if (!empty($azienda['header_path']) && file_exists(upload_abs($azienda['header_path']))) {
            $header->addImage(upload_abs($azienda['header_path']), [
                'width'     => 500,
                'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER
            ]);
        } elseif (!empty($azienda['logo_path']) && file_exists(upload_abs($azienda['logo_path']))) {
            $headerTable = $header->addTable(['width' => self::MAX_PRINT_WIDTH]);
            $headerTable->addRow();
            $headerTable->addCell(5270)->addImage(upload_abs($azienda['logo_path']), ['height' => 45]);
            $headerTable->addCell(5270, ['valign' => 'center'])->addText(
                $documentTitle, 
                ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '1A5276'], 
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::RIGHT]
            );
        } else {
            $header->addText(
                $documentTitle, 
                ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => '1A5276'], 
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );
        }
    }

    /**
     * Add standard footer with page numbering and document reference.
     * 
     * @param \PhpOffice\PhpWord\Element\Section $section
     * @param string $reference Document reference number (e.g. RFQ number)
     */
    public function addStandardFooter(\PhpOffice\PhpWord\Element\Section $section, string $reference): void
    {
        $footer = $section->addFooter();
        $footerTable = $footer->addTable(['width' => self::MAX_PRINT_WIDTH]);
        $footerTable->addRow();
        $footerTable->addCell(self::MAX_PRINT_WIDTH, ['borderTopSize' => 6, 'borderTopColor' => '999999']);
        $footer->addPreserveText(
            'Pagina {PAGE} di {NUMPAGES} — Rif: ' . $reference, 
            ['name' => 'Arial', 'size' => 8, 'color' => '666666'], 
            ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
        );
    }

    /**
     * Output the DOCX file to the browser with headers and clean output buffers.
     * 
     * @param string $filename Download filename
     */
    public function streamDownload(string $filename): void
    {
        // Clear all previous output buffers to avoid output zip file corruption
        while (ob_get_level()) {
            ob_end_clean();
        }

        header("Content-Description: File Transfer");
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        $xmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($this->phpWord, 'Word2007');
        $xmlWriter->save("php://output");
        exit;
    }
}
