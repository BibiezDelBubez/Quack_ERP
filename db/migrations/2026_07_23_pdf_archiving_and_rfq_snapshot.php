<?php
/**
 * Migration: 2026_07_23_pdf_archiving_and_rfq_snapshot.php
 *
 * Aggiunge le colonne per l'archiviazione immutabile dei PDF (pdf_path, pdf_hash)
 * su preventivi, ordini_clienti, rfq_testata, ordini_testata.
 * Aggiunge le colonne di denormalizzazione del fornitore su rfq_testata (fornitore_ragione_sociale, fornitore_indirizzo_snapshot).
 */

declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Helper per aggiungere una colonna se non esiste
        $addColumn = function (string $table, string $column, string $type) use ($pdo, $driver) {
            if ($driver === 'sqlite') {
                $st = $pdo->query("PRAGMA table_info({$table})");
                $cols = [];
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['name'];
                }
                if (!in_array($column, $cols, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
                }
            } else {
                $st = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
                $st->execute([$column]);
                if (!$st->fetch()) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
                }
            }
        };

        // 1. Colonne PDF Archiving su preventivi, ordini_clienti, rfq_testata, ordini_testata
        $tables = ['preventivi', 'ordini_clienti', 'rfq_testata', 'ordini_testata'];
        foreach ($tables as $t) {
            $addColumn($t, 'pdf_path', 'TEXT');
            $addColumn($t, 'pdf_hash', 'VARCHAR(64)');
        }

        // 2. Colonne Denormalizzazione Fornitore su rfq_testata
        $addColumn('rfq_testata', 'fornitore_ragione_sociale', 'VARCHAR(255)');
        $addColumn('rfq_testata', 'fornitore_indirizzo_snapshot', 'TEXT');
    }

    public function down(PDO $pdo): void
    {
        // Migrazione irreversibile per motivi di preservazione dati storici
    }
};
