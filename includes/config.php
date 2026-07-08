<?php
/**
 * config.php — configurazione centrale dell'app Quack ERP
 *
 * Include questo file come primo include in ogni pagina.
 * Rileva automaticamente il BASE_URL dall'ubicazione fisica del file,
 * senza mai usare porte o hostname fissi.
 */

/* ── Session ─────────────────────────────────────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => defined('APP_ENV') && APP_ENV === 'production',
    ]);
    session_start();
}

/* ── App info ─────────────────────────────────────────────────────────────── */
define('APP_NAME',          'Quack ERP');
define('APP_VERSION',       '0.1.0');
define('APP_ENV',           'development'); // 'production' in prod
define('DB_SCHEMA_VERSION', '4.2');         // aggiornare ad ogni migrazione

/* ── Path resolution ──────────────────────────────────────────────────────── */
// includes/config.php è in <app_root>/includes/
// app root = dirname di questo file salito di 1 livello
define('APP_ROOT_DIR', dirname(__DIR__));

// Base URL root-relativa (es. "/quack") calcolata confrontando il percorso
// fisico dell'app con il document root del server.
$_docroot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
$_approot = rtrim(str_replace('\\', '/', realpath(APP_ROOT_DIR)), '/');
$_base    = str_replace($_docroot, '', $_approot);
define('BASE_URL', $_base === '' ? '' : $_base); // es. "/quack"
unset($_docroot, $_approot, $_base);

define('ASSETS_URL',   BASE_URL . '/assets');
define('INCLUDES_DIR', APP_ROOT_DIR . '/includes');

/* ── Upload directory (fuori dalla cartella di progetto) ──────────────────
   In sviluppo: C:/Quack_ERP_data
   In produzione Linux: es. /var/www/quack-data
   Cambia SOLO questo define per adattare l'ambiente.
─────────────────────────────────────────────────────────────────────────── */
define('UPLOAD_DIR', 'C:/Quack_ERP_data');

/* ── Cartella database (fuori dalla cartella di progetto) ─────────────────
   In sviluppo:  C:/Quack_ERP_data/db
   In produzione Linux: es. /var/www/quack-data/db
   Cambia SOLO questo define per adattare l'ambiente.
─────────────────────────────────────────────────────────────────────────── */
define('DB_DIR', 'C:/Quack_ERP_data/db');

/* ── Driver database — UNICA FONTE DI VERITÀ ──────────────────────────────
   'sqlite' oggi, 'mysql' quando lo schema/logica sarà stabile.
   Lo switch consiste nel cambiare DB_DRIVER (+ le credenziali sotto se mysql).
─────────────────────────────────────────────────────────────────────────── */
define('DB_DRIVER',   'sqlite');   // 'sqlite' | 'mysql'

// Usate solo se DB_DRIVER === 'mysql'
define('DB_MYSQL_HOST',    getenv('QK_DB_HOST')   ?: '127.0.0.1');
define('DB_MYSQL_PORT',    getenv('QK_DB_PORT')   ?: '3306');
define('DB_MYSQL_NAME',    getenv('QK_DB_NAME')   ?: 'quack_erp');
define('DB_MYSQL_USER',    getenv('QK_DB_USER')   ?: 'root');
define('DB_MYSQL_PASS',    getenv('QK_DB_PASS')   ?: '');
define('DB_MYSQL_CHARSET', getenv('QK_DB_CHARSET') ?: 'utf8mb4');

/* ── Registro temi — UNICA FONTE DI VERITÀ ────────────────────────────────
   Aggiungere o rimuovere un tema: modificare SOLO questo array.
   Ogni voce: slug (CSS data-theme), label (UI), swatch (style inline CSS).
─────────────────────────────────────────────────────────────────────────── */
define('QK_THEMES', [
    ['slug' => 'mallard',    'label' => 'Germano Reale',      'swatch' => 'linear-gradient(90deg,#064e3b 50%,#fbbf24 50%)'],
    ['slug' => 'moretta',    'label' => 'Moretta',             'swatch' => 'linear-gradient(90deg,#141414 50%,#6b8cbf 50%)'],
    ['slug' => 'orco-marino','label' => 'Orco Marino',         'swatch' => 'linear-gradient(90deg,#111827 50%,#facc15 50%)'],
    ['slug' => 'svedese-blu','label' => 'Svedese Blu',         'swatch' => 'linear-gradient(90deg,#334155 50%,#1e40af 50%)'],
    ['slug' => 'codone',     'label' => 'Alzavola',            'swatch' => 'linear-gradient(90deg,#6b5848 50%,#2d7a6e 50%)'],
    ['slug' => 'edredone',   'label' => 'Quattrocchi Comune',  'swatch' => 'linear-gradient(90deg,#0f172a 50%,#0d9488 50%)'],
    ['slug' => 'mandarino',  'label' => 'Anatra Mandarina',    'swatch' => 'linear-gradient(90deg,#143642 50%,#b48234 50%)'],
    ['slug' => 'pesciaiola', 'label' => 'Vaporiera',           'swatch' => 'linear-gradient(90deg,#f9fafb 50%,#2563eb 50%)', 'swatch_border' => '1px solid #d1d5db'],
    ['slug' => 'fischione',  'label' => 'Fischione',           'swatch' => 'linear-gradient(90deg,#45403E 50%,#D97706 50%)'],
    ['slug' => 'fagiano',    'label' => 'Fagiano Remo',        'swatch' => 'linear-gradient(90deg,#0e1c30 50%,#f8a800 50%)'],
    ['slug' => 'cayun',      'label' => 'Cayun',               'swatch' => 'linear-gradient(90deg,#141a1c 50%,#00f5a0 50%)'],
    ['slug' => 'picchio-deberardinis', 'label' => 'Picchio Deberardinis', 'swatch' => 'linear-gradient(90deg,#1e1f22 50%,#a63326 50%)'],
]);

/* ── Migrazione slug obsoleti — UNICA FONTE DI VERITÀ ────────────────────────
   Usata da: config.php, ThemeController (via costante), app.js (via header.php).
   Per aggiungere un alias: aggiungere qui, non nei file sopra citati.
 ─────────────────────────────────────────────────────────────────────────── */
/* ── Tema attivo ─────────────────────────────────────────────────────────── */
$_qk_theme_raw = $_SESSION['qk_theme'] ?? 'svedese-blu';
if (!in_array($_qk_theme_raw, array_column(QK_THEMES, 'slug'), true)) {
    $_SESSION['qk_theme'] = 'svedese-blu';
    $_qk_theme_raw        = 'svedese-blu';
}
define('ACTIVE_THEME', $_qk_theme_raw);
unset($_qk_theme_raw);

/* ── PHP error display ────────────────────────────────────────────────────── */
// In development gli errori PHP vengono stampati a schermo.
// In production vengono silenziati (ci pensa l'error reporter sotto).
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/* ── Error Reporter ───────────────────────────────────────────────────────────
 *
 * Quando qualcosa si rompe, il sistema fa due cose automaticamente:
 *   1. Scrive l'errore in logs/errors.md con URL, utente, stack trace e
 *      dati della richiesta — così hai uno storico leggibile.
 *   2. Manda una mail a QK_ERROR_EMAIL_TO — così lo sai subito.
 *      (Stessa mail non viene mandata più di una volta ogni 30 min.)
 *
 * Per attivare: metti QK_ERROR_REPORT_ENABLED = true qui sotto.
 * Le credenziali SMTP Brevo sono già configurate e pronte.
 *
─────────────────────────────────────────────────────────────────────────── */

// true = attivo, false = disattivato completamente
define('QK_ERROR_REPORT_ENABLED',   true);

// Destinatario delle mail di errore
define('QK_ERROR_EMAIL_TO',         'michel.papetti@campioni.com');

// Mittente — deve essere un sender verificato su Brevo
define('QK_ERROR_EMAIL_FROM',       'michel.papetti@campioni.com');

// Path del file di log (non serve cambiare)
define('QK_ERROR_LOG_FILE',         APP_ROOT_DIR . '/logs/errors.md');

// Provider: 'brevo' (API key) | 'smtp' | 'resend' | 'mailgun'
define('QK_ERROR_MAIL_PROVIDER',    'brevo');

// Minuti di pausa tra una mail e l'altra per lo stesso identico errore
define('QK_ERROR_THROTTLE_MINUTES', 30);

// ── Brevo API key ─────────────────────────────────────────────────────────
define('QK_BREVO_API_KEY', '***BREVO_API_KEY_RIMOSSA***');

/* ── Charset ──────────────────────────────────────────────────────────────── */
header('Content-Type: text/html; charset=UTF-8');
