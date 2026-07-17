<?php

declare(strict_types=1);

/**
 * Recupera un valore dalla tabella Impostazioni.
 * Se la chiave non esiste, ritorna il valore di $default.
 */
function get_impostazione(PDO $pdo, string $chiave, ?string $default = null): ?string
{
    $stmt = $pdo->prepare("SELECT valore FROM Impostazioni WHERE chiave = ?");
    $stmt->execute([$chiave]);
    $valore = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($valore !== false) {
        return (string) $valore;
    }

    return $default;
}

/**
 * Inserisce o aggiorna un valore nella tabella Impostazioni in modo sicuro (UPSERT).
 */
function set_impostazione(PDO $pdo, string $chiave, string $valore): void
{
    // Verifica se è definita la costante globale DB_DRIVER per gestire il dialetto corretto,
    // altrimenti di default assumiamo sqlite.
    $driver = defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';

    if ($driver === 'mysql') {
        $sql = "INSERT INTO Impostazioni (chiave, valore) VALUES (?, ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)";
    } else {
        $sql = "INSERT INTO Impostazioni (chiave, valore) VALUES (?, ?) ON CONFLICT(chiave) DO UPDATE SET valore = excluded.valore";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$chiave, $valore]);
    $stmt->closeCursor();
}
