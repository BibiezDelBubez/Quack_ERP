<?php

declare(strict_types=1);

/**
 * FileController — serve i file da UPLOAD_DIR al browser.
 *
 * Route: GET /uploads?path=loghi/img_abc.png
 *
 * Sicurezza:
 *  - Richiede autenticazione (come tutte le route auth=true)
 *  - Blocca path traversal (../)
 *  - Whitelist delle cartelle consentite
 */
class FileController extends BaseController
{
    /** Cartelle consentite dentro UPLOAD_DIR */
    private const ALLOWED_DIRS = ['loghi', 'header_aziende', 'prodotti', 'offerte', 'mpdf_tmp', 'compliance', 'categorie', 'spedizioni', 'sds'];

    public function serve(): void
    {
        $rel = trim(get_param('path', ''), '/\\');

        // Anti path-traversal
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            http_response_code(400);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        // Whitelist: solo cartelle consentite
        $topDir = explode('/', str_replace('\\', '/', $rel))[0];
        if (!in_array($topDir, self::ALLOWED_DIRS, true)) {
            http_response_code(403);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        $abs = upload_abs($rel);

        if (!is_file($abs)) {
            http_response_code(404);
            require APP_ROOT_DIR . '/404.php';
            exit;
        }

        $mime = mime_content_type($abs) ?: 'application/octet-stream';

        // Serve il file
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, max-age=86400'); // 1 giorno, solo per utenti autenticati
        header('X-Content-Type-Options: nosniff');

        readfile($abs);
        exit;
    }
}
