<?php
declare(strict_types=1);

/**
 * Script di migrazione per la gestione Multi-UoM ed i Listini Prezzi dell'ERP Quack.
 * Aggiunge colonne per tracciare la UoM nativa dei listini/RRP e lo snapshot dei fattori.
 */

define('APP_ROOT_DIR', dirname(__DIR__));
$db_path = APP_ROOT_DIR . '/db/database.sqlite';

if (!file_exists($db_path)) {
    echo "Database SQLite non trovato in: $db_path\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = OFF;"); // Temporaneamente off per modifiche strutturali complesse

    echo "Inizio migrazione schema per allineamento Multi-UoM...\n";

    // Funzione helper per aggiungere colonne ignorando errori se esistono già
    $addColumn = function (PDO $pdo, string $table, string $columnDef) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$columnDef}");
            echo "Aggiunta colonna a {$table}: {$columnDef}\n";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), "duplicate column") || str_contains($e->getMessage(), "already exists")) {
                echo "La colonna in {$table} esiste già. Saltata.\n";
            } else {
                throw $e;
            }
        }
    };

    // 1. Aggiunta colonne per listini prezzi e prezzi fornitore (UoM Nativa)
    $addColumn($pdo, 'listini_prezzi', 'unita_misura_id INTEGER REFERENCES unita_misura(id)');
    $addColumn($pdo, 'prodotti_fornitori_prezzi', 'unita_misura_id INTEGER REFERENCES unita_misura(id)');
    $addColumn($pdo, 'prodotti_fornitori', 'rrp_unita_misura_id INTEGER REFERENCES unita_misura(id)');

    // 2. Aggiunta colonne snapshot del fattore di conversione per righe transazionali
    $addColumn($pdo, 'preventivi_righe', 'quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0');
    $addColumn($pdo, 'ordini_clienti_righe', 'quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0');
    $addColumn($pdo, 'ordini_acquisto_righe', 'quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0');
    $addColumn($pdo, 'ricevimento_merce_righe', 'quantita_contenuto_snapshot DECIMAL(10,3) DEFAULT 1.0');

    echo "Colonne aggiunte con successo. Inizio migrazione e allineamento dati esistenti...\n";

    // 3. Popolamento UoM nativa nei listini esistenti con la Stock UoM del prodotto
    $pdo->exec("
        UPDATE listini_prezzi 
        SET unita_misura_id = (
            SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = listini_prezzi.prodotto_id
        )
        WHERE unita_misura_id IS NULL
    ");
    echo "Allineate UoM su listini_prezzi.\n";

    $pdo->exec("
        UPDATE prodotti_fornitori_prezzi 
        SET unita_misura_id = (
            SELECT p.unita_misura_stock_id 
            FROM prodotti p 
            JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id 
            WHERE pf.id = prodotti_fornitori_prezzi.prodotto_fornitore_id
        )
        WHERE unita_misura_id IS NULL
    ");
    echo "Allineate UoM su prodotti_fornitori_prezzi.\n";

    $pdo->exec("
        UPDATE prodotti_fornitori 
        SET rrp_unita_misura_id = (
            SELECT unita_misura_stock_id FROM prodotti WHERE prodotti.id = prodotti_fornitori.prodotto_id
        )
        WHERE rrp_unita_misura_id IS NULL
    ");
    echo "Allineate UoM su prodotti_fornitori.\n";

    // 4. Popolamento snapshot dei fattori di conversione storici
    $pdo->exec("
        UPDATE preventivi_righe 
        SET quantita_contenuto_snapshot = COALESCE(
            (SELECT quantita_contenuto FROM prodotti WHERE prodotti.id = preventivi_righe.prodotto_id), 
            1.0
        )
    ");
    $pdo->exec("
        UPDATE ordini_clienti_righe 
        SET quantita_contenuto_snapshot = COALESCE(
            (SELECT quantita_contenuto FROM prodotti WHERE prodotti.id = ordini_clienti_righe.prodotto_id), 
            1.0
        )
    ");
    $pdo->exec("
        UPDATE ordini_acquisto_righe 
        SET quantita_contenuto_snapshot = COALESCE(
            (SELECT quantita_contenuto FROM prodotti WHERE prodotti.id = ordini_acquisto_righe.prodotto_id), 
            1.0
        )
    ");
    $pdo->exec("
        UPDATE ricevimento_merce_righe 
        SET quantita_contenuto_snapshot = COALESCE(
            (SELECT quantita_contenuto FROM prodotti WHERE prodotti.id = ricevimento_merce_righe.prodotto_id), 
            1.0
        )
    ");
    echo "Allineati snapshot fattori di conversione storici sulle righe transazionali.\n";

    $pdo->exec("PRAGMA foreign_keys = ON;");
    echo "Migrazione completata con successo!\n";

} catch (\Exception $e) {
    echo "Errore durante la migrazione: " . $e->getMessage() . "\n";
    exit(1);
}
