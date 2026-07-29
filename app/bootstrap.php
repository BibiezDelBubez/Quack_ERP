<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/error_reporter.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Autoloader PSR-4 per il namespace App\
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

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
require_once __DIR__ . '/../includes/transizioni_stati.php';
// TransitionGuard è centralizzato qui (a differenza degli altri servizi in
// app/Services/, che ogni controller richiede da sé) perché, a differenza di
// ComplianceCheckerService/SchedeSicurezzaService (usati da un controller
// specifico), è pensato per essere usato trasversalmente da praticamente ogni
// modulo con un ciclo di vita a stati (vedi docs/plans/PIANO - Motore
// Unificato di Transizioni di Stato.md) — stessa categoria di functions.php,
// non un servizio di dominio singolo. Non prendere questo come il nuovo
// pattern di default per ogni futuro servizio: resta un'eccezione motivata.
require_once __DIR__ . '/Services/TransitionGuard.php';
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
            if (!mkdir(UPLOAD_DIR . '/' . $_qk_dir, 0755, true)) {
                error_log('[Quack] Impossibile creare la sottocartella upload: ' . $_qk_dir);
            }
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

// Gestore switch_magazzino_id RIMOSSO (Opzione A, Magazzino Attivo - Analisi
// e Alternative.md §9.3/§9.5): non esiste più un selettore astratto in topbar
// da cui arrivava questo parametro. $_SESSION['magazzino_attivo_id'] si
// aggiorna ora come effetto collaterale delle scelte esplicite fatte nelle
// pagine che lo richiedono (Fix 1-3, §8.2-§8.4: WarehouseController::
// createMovement()/stock()/reorder(), e createTransfer()/trasferimentoDiretto()).


