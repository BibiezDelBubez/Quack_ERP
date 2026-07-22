<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

try {
    $pdo->beginTransaction();

    $pdo->exec("DROP TABLE IF EXISTS sostanze_chimiche_restrizioni");
    $pdo->exec("DROP TABLE IF EXISTS compliance_codice_onu_restrizioni");

    $pdo->commit();
    echo "Tabelle legacy eliminate con successo.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Errore durante l'eliminazione: " . $e->getMessage() . "\n";
    exit(1);
}
