<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/word_helper.php';
require_once __DIR__ . '/Core/Router.php';
require_once __DIR__ . '/Core/PurchaseOrderStateMachine.php';

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

