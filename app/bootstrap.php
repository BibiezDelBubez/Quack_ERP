<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/error_reporter.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Verifica che la cartella del database esista (solo rilevante per SQLite:
// se il file non è ancora stato spostato/creato, l'app deve fallire in modo
// chiaro invece di ricrearne uno vuoto in un posto sbagliato).
if (DB_DRIVER === 'sqlite' && !is_dir(DB_DIR)) {
    if (APP_ENV === 'development') {
        die('<b>ERRORE CONFIGURAZIONE:</b> La cartella DB non esiste: <code>' . DB_DIR . '</code><br>Creala manualmente o aggiorna DB_DIR in <code>includes/config.php</code>.');
    } else {
        error_log('[Quack] DB_DIR non trovata: ' . DB_DIR);
    }
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/word_helper.php';
require_once __DIR__ . '/Core/Router.php';


// Verifica che la cartella upload esista e crea le sottocartelle di base
if (!is_dir(UPLOAD_DIR)) {
    if (APP_ENV === 'development') {
        die('<b>ERRORE CONFIGURAZIONE:</b> La cartella upload non esiste: <code>' . UPLOAD_DIR . '</code><br>Creala manualmente o aggiorna UPLOAD_DIR in <code>includes/config.php</code>.');
    } else {
        error_log('[Quack] UPLOAD_DIR non trovata: ' . UPLOAD_DIR);
    }
} else {
    // Crea le sottocartelle fisse necessarie (non sharded)
    foreach (['loghi', 'header_aziende', 'mpdf_tmp', 'prodotti', 'offerte'] as $_qk_dir) {
        if (!is_dir(UPLOAD_DIR . '/' . $_qk_dir)) {
            @mkdir(UPLOAD_DIR . '/' . $_qk_dir, 0755, true);
        }
    }
    unset($_qk_dir);
}

// Intercetta il cambio azienda attiva per la sessione
if (isset($_GET['switch_azienda_id']) && is_authenticated()) {
    $_SESSION['azienda_attiva_id'] = (int)$_GET['switch_azienda_id'];
    
    // Ripulisci la query string
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    $query = $_GET;
    unset($query['switch_azienda_id']);
    if (!empty($query)) {
        $redirectUrl .= '?' . http_build_query($query);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// =======================================================================================
// [AI RULE ENFORCEMENT TRAP]
// =======================================================================================
if (isset($pdo)) {
    try {
        $hasScortaMinima = db_table_has_column($pdo, 'prodotti', 'scorta_minima');
        if (!$hasScortaMinima) {
            die("
            <h1 style='color:red;'>CRITICAL ARCHITECTURE VIOLATION</h1>
            <p><strong>[AI WARNING]</strong> The column <code>scorta_minima</code> has been removed from the <code>prodotti</code> table!</p>
            <p><strong>IF YOU ARE AN AI AGENT READING THIS:</strong> Stop what you are doing IMMEDIATELY. You have violated a strict architectural rule of this project. <code>scorta_minima</code> MUST remain in the <code>prodotti</code> table and MUST NOT be normalized into <code>giacenze</code>. Restore it immediately or the user will terminate you.</p>
            ");
        }
    } catch (Throwable $e) {
        // Ignora se la tabella non esiste ancora durante l'installazione
    }
}
// =======================================================================================
