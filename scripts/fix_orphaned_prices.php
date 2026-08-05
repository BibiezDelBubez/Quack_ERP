<?php
/**
 * Questo script isolato corregge le righe orfane in listini_prezzi generate
 * dal bug del Frontend che inviava listino_id="" per il listino base.
 */
require_once __DIR__ . '/../app/bootstrap.php';

global $pdo;

try {
    $pdo->beginTransaction();
    echo "Inizio sanitizzazione database...\n";

    // Trova tutte le righe con listino_id = NULL
    $stmt = $pdo->query("SELECT id, prodotto_id, quantita_minima_stock FROM listini_prezzi WHERE listino_id IS NULL");
    $orphans = $stmt->fetchAll();

    $fixed = 0;
    $deleted = 0;

    foreach ($orphans as $orphan) {
        $id = $orphan['id'];
        $prodottoId = $orphan['prodotto_id'];
        $qMinStock = $orphan['quantita_minima_stock'];

        // Controlla se esiste già una riga valida (listino_id = 1) per questo prodotto e quantità
        $checkStmt = $pdo->prepare("SELECT id FROM listini_prezzi WHERE listino_id = 1 AND prodotto_id = ? AND quantita_minima_stock = ?");
        $checkStmt->execute([$prodottoId, $qMinStock]);
        $existing = $checkStmt->fetchColumn();

        if ($existing) {
            // Se esiste già una riga valida, l'orfana è un duplicato sporco: eliminiamola
            $pdo->exec("DELETE FROM listini_prezzi WHERE id = $id");
            echo "Eliminata riga orfana duplicata ID $id per il prodotto $prodottoId\n";
            $deleted++;
        } else {
            // Altrimenti, questa riga era intesa per il listino base ma salvata male: correggiamola
            $pdo->exec("UPDATE listini_prezzi SET listino_id = 1 WHERE id = $id");
            echo "Corretta riga orfana ID $id per il prodotto $prodottoId (listino_id = 1)\n";
            $fixed++;
        }
    }

    $pdo->commit();
    echo "\nSanitizzazione completata con successo.\n";
    echo "Righe corrette: $fixed\n";
    echo "Righe duplicate eliminate: $deleted\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERRORE durante la sanitizzazione: " . $e->getMessage() . "\n";
}
