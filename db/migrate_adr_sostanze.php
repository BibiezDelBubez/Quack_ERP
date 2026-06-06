<?php
/**
 * migrate_adr_sostanze.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Migrazione ADR — Fase 1: introduce sostanze_adr e sostanze_adr_schede,
 * aggiunge is_adr e sostanza_adr_id a prodotti,
 * rimuove prodotto_classificazioni_adr (ridondante).
 *
 * Eseguire da riga di comando:
 *   php db/migrate_adr_sostanze.php
 * oppure aprire nel browser (con APP_ENV=development).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$dsn = 'sqlite:' . APP_ROOT_DIR . '/db/database.sqlite';
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = WAL');

$steps = [];

/* ── 1. Tabella sostanze_adr ─────────────────────────────────────────────── */
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_adr'")->fetchColumn();
if (!$exists) {
    $pdo->exec("
        CREATE TABLE sostanze_adr (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            nome                    VARCHAR(300) NOT NULL,
            codice_onu_id           INTEGER REFERENCES adr_codici_onu(id),
            nome_tecnico            VARCHAR(200),
            gruppo_imballaggio      CHAR(3),
            concentrazione_min_pct  DECIMAL(5,2),
            concentrazione_max_pct  DECIMAL(5,2),
            punto_infiammabilita_c  DECIMAL(6,2),
            e_soluzione             BOOLEAN NOT NULL DEFAULT 0,
            e_rifiuto               BOOLEAN NOT NULL DEFAULT 0,
            e_stabilizzato          BOOLEAN NOT NULL DEFAULT 0,
            note_interne            TEXT,
            creato_da               INTEGER REFERENCES utenti(id),
            created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $steps[] = '✓ Tabella sostanze_adr creata.';
} else {
    $steps[] = '— sostanze_adr già esistente, saltata.';
}

/* ── 2. Tabella sostanze_adr_schede ─────────────────────────────────────── */
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sostanze_adr_schede'")->fetchColumn();
if (!$exists) {
    $pdo->exec("
        CREATE TABLE sostanze_adr_schede (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            sostanza_id     INTEGER NOT NULL REFERENCES sostanze_adr(id) ON DELETE CASCADE,
            lingua          CHAR(5)  NOT NULL,
            versione        VARCHAR(20),
            data_emissione  DATE,
            file_path       TEXT NOT NULL,
            file_nome_orig  VARCHAR(255),
            e_corrente      BOOLEAN NOT NULL DEFAULT 1,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(sostanza_id, lingua, versione)
        )
    ");
    $steps[] = '✓ Tabella sostanze_adr_schede creata.';
} else {
    $steps[] = '— sostanze_adr_schede già esistente, saltata.';
}

/* ── 3. Colonne prodotti.is_adr e prodotti.sostanza_adr_id ─────────────── */
$cols = array_column($pdo->query('PRAGMA table_info(prodotti)')->fetchAll(), 'name');

if (!in_array('is_adr', $cols, true)) {
    $pdo->exec('ALTER TABLE prodotti ADD COLUMN is_adr BOOLEAN NOT NULL DEFAULT 0');
    $steps[] = '✓ Colonna prodotti.is_adr aggiunta.';
} else {
    $steps[] = '— prodotti.is_adr già presente.';
}

if (!in_array('sostanza_adr_id', $cols, true)) {
    $pdo->exec('ALTER TABLE prodotti ADD COLUMN sostanza_adr_id INTEGER REFERENCES sostanze_adr(id)');
    $steps[] = '✓ Colonna prodotti.sostanza_adr_id aggiunta.';
} else {
    $steps[] = '— prodotti.sostanza_adr_id già presente.';
}

/* ── 4. Crea cartella SDS in UPLOAD_DIR ─────────────────────────────────── */
$sdsDir = rtrim(UPLOAD_DIR, '/\\') . '/sds';
if (!is_dir($sdsDir)) {
    if (mkdir($sdsDir, 0755, true)) {
        $steps[] = "✓ Cartella SDS creata: $sdsDir";
    } else {
        $steps[] = "⚠ Impossibile creare cartella SDS: $sdsDir (crearla manualmente)";
    }
} else {
    $steps[] = "— Cartella SDS già esistente: $sdsDir";
}

/* ── 5. Rimuove prodotto_classificazioni_adr (ridondante) ───────────────── */
$exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='prodotto_classificazioni_adr'")->fetchColumn();
if ($exists) {
    // Verifica se ci sono righe da eventualmente loggare
    $count = (int)$pdo->query('SELECT COUNT(*) FROM prodotto_classificazioni_adr')->fetchColumn();
    if ($count > 0) {
        $steps[] = "⚠ prodotto_classificazioni_adr contiene $count righe — NON eliminata automaticamente.";
        $steps[] = "  Migrare manualmente i dati su sostanze_adr, poi eseguire: DROP TABLE prodotto_classificazioni_adr";
    } else {
        $pdo->exec('DROP TABLE prodotto_classificazioni_adr');
        $steps[] = '✓ Tabella prodotto_classificazioni_adr eliminata (era vuota).';
    }
} else {
    $steps[] = '— prodotto_classificazioni_adr non esiste, niente da fare.';
}

$pdo->exec('PRAGMA foreign_keys = ON');

/* ── Output ─────────────────────────────────────────────────────────────── */
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo "=== Migrazione ADR Sostanze ===\n\n";
foreach ($steps as $s) {
    echo $s . "\n";
}
echo "\n=== Fine migrazione ===\n";
