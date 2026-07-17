<?php
/**
 * Migration: aggiunge ordini_testata.stato_fatturazione.
 *
 * Problema che risolve (REVISIONE Ciclo Acquisti, 17/07/2026): lato Vendite
 * esiste già ordini_clienti.stato_fatturazione (TEXT/VARCHAR NOT NULL DEFAULT
 * 'da_fatturare', valori 'da_fatturare'/'fatturato'), con azioni di toggle in
 * SalesOrdersController e badge in app/views/sales/orders/_detail.php. Lato
 * Acquisti lo stesso bisogno — sapere se l'amministrazione ha già fatturato
 * un ordine — non esisteva affatto. Non è un three-way match automatico
 * (nessuna verifica di importi/quantità), solo una spunta manuale.
 *
 * Mirror esatto del pattern Vendite: stesso nome colonna, stessi due valori,
 * nessuna colonna data separata.
 *
 * Idempotente: controlla PRAGMA table_info / INFORMATION_SCHEMA prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (!column_exists($pdo, 'ordini_testata', 'stato_fatturazione')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? "ALTER TABLE ordini_testata ADD COLUMN stato_fatturazione TEXT NOT NULL DEFAULT 'da_fatturare'"
        : "ALTER TABLE ordini_testata ADD COLUMN stato_fatturazione VARCHAR(255) NOT NULL DEFAULT 'da_fatturare'";
    $pdo->exec($sql);
    echo "  [OK] ordini_testata.stato_fatturazione aggiunta (default 'da_fatturare')\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] ordini_testata.stato_fatturazione già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
