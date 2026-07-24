<?php
/**
 * Migration: crea la vista v_preventivi_stato_effettivo.
 *
 * Problema che risolve (REVISIONE Ciclo Vendite, §3bis P1 / PIANO - Vista
 * Stato Effettivo Preventivi (Scadenza P1)): un preventivo 'inviato' con
 * data_scadenza superata non veniva mai segnalato come scaduto — nessun
 * automatismo lo confrontava con la data odierna. Decisione: niente cron
 * esterno alla webapp (locale, invisibile a chi subentra); il calcolo avviene
 * sempre a runtime tramite questa vista, mai scritto su preventivi.stato.
 *
 * Idempotente: verifica l'esistenza della vista prima di crearla/sostituirla.
 * Su SQLite CREATE VIEW IF NOT EXISTS è nativamente idempotente; su MySQL si
 * usa CREATE OR REPLACE VIEW (una vista non contiene dati, ricrearla è sempre
 * sicuro).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_migration_view_exists(PDO $pdo, string $viewName): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name = ?");
        $stmt->execute([$viewName]);
        return (int)$stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.VIEWS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$viewName]);
    return (int)$stmt->fetchColumn() > 0;
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    if (!qk_migration_view_exists($pdo, 'v_preventivi_stato_effettivo')) {
        $pdo->exec("
            CREATE VIEW IF NOT EXISTS v_preventivi_stato_effettivo AS
            SELECT p.*,
                   CASE
                       WHEN p.stato = 'inviato'
                            AND p.data_scadenza IS NOT NULL
                            AND p.data_scadenza < DATE('now')
                       THEN 'scaduto'
                       ELSE p.stato
                   END AS stato_effettivo
            FROM preventivi p
        ");
        echo "  [OK] vista v_preventivi_stato_effettivo creata (SQLite)\n";
    } else {
        echo "  [--] vista v_preventivi_stato_effettivo già presente, skip\n";
    }
} else {
    // CREATE OR REPLACE VIEW è idempotente per costruzione: nessuna verifica
    // preventiva necessaria, ma la manteniamo per un log coerente con l'altro ramo.
    $existeva = qk_migration_view_exists($pdo, 'v_preventivi_stato_effettivo');
    $pdo->exec("
        CREATE OR REPLACE VIEW v_preventivi_stato_effettivo AS
        SELECT p.*,
               CASE
                   WHEN p.stato = 'inviato'
                        AND p.data_scadenza IS NOT NULL
                        AND p.data_scadenza < CURDATE()
                   THEN 'scaduto'
                   ELSE p.stato
               END AS stato_effettivo
        FROM preventivi p
    ");
    echo $existeva
        ? "  [OK] vista v_preventivi_stato_effettivo ricreata (MySQL, CREATE OR REPLACE)\n"
        : "  [OK] vista v_preventivi_stato_effettivo creata (MySQL)\n";
}

echo "\nMigrazione completata.\n";
