<?php
/**
 * Migration: crea la tabella prodotti_valutazione_costi se non esiste.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

if ($driver === 'sqlite') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prodotti_valutazione_costi (
            prodotto_id INTEGER PRIMARY KEY REFERENCES prodotti(id) ON DELETE CASCADE,
            costo_acquisto_base DECIMAL(10,4),
            costo_acquisto_medio DECIMAL(10,4),
            unita_misura_acquisto_id INTEGER REFERENCES unita_misura(id) ON DELETE SET NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");
} elseif ($driver === 'mysql') {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prodotti_valutazione_costi (
            prodotto_id INT PRIMARY KEY,
            costo_acquisto_base DECIMAL(10,4),
            costo_acquisto_medio DECIMAL(10,4),
            unita_misura_acquisto_id INT,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (prodotto_id) REFERENCES prodotti(id) ON DELETE CASCADE,
            FOREIGN KEY (unita_misura_acquisto_id) REFERENCES unita_misura(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}
