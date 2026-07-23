<?php

namespace App\Services;

/**
 * PdfArchiverService — Gestore centralizzato del congelamento PDF su C:\Quack_ERP_data
 *
 * Applica il principio "Freeze-on-Issue":
 * - Se in 'bozza' o file assente -> Generazione dinamica al volo via callback mPDF.
 * - Se in stato formale ('inviato','accettato','completato','inviata') -> Genera una sola volta,
 *   salva il PDF congelato in UPLOAD_DIR ("C:\Quack_ERP_data\documenti\{anno}\{modulo}\"),
 *   calcola l'hash SHA-256 e memorizza pdf_path e pdf_hash nel DB.
 * - Se in stato formale ed il file esiste -> Risponde istantaneamente via file_get_contents() (~2ms).
 * - Se lo spazio libero su disco è < 50MB o se si verifica un errore I/O -> Fallback trasparente in RAM.
 */
class PdfArchiverService
{
    /**
     * Recupera il PDF archiviato o lo renderizza/congela.
     *
     * @param \PDO $pdo Connessione database
     * @param string $module Nome modulo per la sottocartella (es. 'preventivi', 'rfq', 'ordini_acquisto')
     * @param int $docId ID del documento
     * @param string $table Nome tabella del documento (es. 'preventivi', 'rfq_testata', 'ordini_testata')
     * @param string $status Stato del documento (es. 'bozza', 'inviato', 'accettato', 'inviata')
     * @param callable $renderCallback Callback che genera i byte del PDF se necessario
     * @return string Contenuto binario del PDF
     */
    public function getOrRender(
        \PDO $pdo,
        string $module,
        int $docId,
        string $table,
        string $status,
        callable $renderCallback
    ): string {
        $st = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $st->execute([$docId]);
        $doc = $st->fetch(\PDO::FETCH_ASSOC);

        // 1. Se esiste un PDF archiviato valido su disco -> Fast-Serve (~2ms)
        if ($doc && !empty($doc['pdf_path'])) {
            $absPath = \UPLOAD_DIR . '/' . ltrim(str_replace('\\', '/', $doc['pdf_path']), '/');
            if (is_file($absPath)) {
                return file_get_contents($absPath);
            }
        }

        // 2. Genera il PDF dal vivo via Callback
        $pdfBytes = $renderCallback();

        // 3. Se il documento è in uno stato formale -> Prova a congelarlo
        $statiFormali = ['inviato', 'inviata', 'accettato', 'completato', 'chiusa'];
        if (in_array(mb_strtolower($status), $statiFormali, true) && $doc) {
            $this->tryFreeze($pdo, $table, $docId, $module, $doc, $pdfBytes);
        }

        return $pdfBytes;
    }

    /**
     * Tenta il congelamento del PDF su UPLOAD_DIR ("C:\Quack_ERP_data\documenti\...").
     */
    private function tryFreeze(\PDO $pdo, string $table, int $docId, string $module, array $doc, string $pdfBytes): void
    {
        try {
            $uploadDir = \UPLOAD_DIR;
            if (disk_free_space($uploadDir) < 52428800) { // 50 MB
                error_log("[QUACK STORAGE WARNING] Spazio disco insufficiente (<50MB) su {$uploadDir}. Fallback in RAM.");
                return;
            }

            $year = date('Y');
            $relDir = "documenti/{$year}/{$module}";
            $absDir = upload_ensure_dir($relDir);

            $numRaw = $doc['numero'] ?? $doc['numero_preventivo'] ?? (string)$docId;
            $safeNum = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$numRaw);
            $versione = (int)($doc['versione'] ?? 1);
            $shortHash = substr(hash('sha256', $pdfBytes), 0, 12);

            $fileName = "{$safeNum}_v{$versione}_{$shortHash}.pdf";
            $relPath = "{$relDir}/{$fileName}";
            $absPath = "{$absDir}/{$fileName}";

            // Scrittura sicura con lock esclusivo
            file_put_contents($absPath, $pdfBytes, LOCK_EX);

            $sha256 = hash('sha256', $pdfBytes);

            $st = $pdo->prepare("UPDATE {$table} SET pdf_path = ?, pdf_hash = ? WHERE id = ?");
            $st->execute([$relPath, $sha256, $docId]);
        } catch (\Throwable $e) {
            error_log("[QUACK STORAGE ERROR] Impossibile salvare PDF su disco: " . $e->getMessage());
        }
    }
}
