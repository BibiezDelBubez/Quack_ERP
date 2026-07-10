<?php
/**
 * Migration: aggiunge un vincolo UNIQUE(prodotto_id, codice_lotto_interno) su lotti.
 *
 * Problema che risolve: codice_lotto_interno è digitato a mano dall'operatore
 * (qk_insert_lotto_manuale) o generato in fase di ricezione, senza nessun controllo
 * applicativo o di schema che impedisca a due lotti DIVERSI dello stesso prodotto di
 * avere lo stesso codice. Per un'azienda ADR/chimica questo è un rischio di
 * tracciabilità (REACH/CLP, richiami prodotto): un codice duplicato rende ambigua
 * la query "quali clienti hanno ricevuto il lotto X".
 *
 * Il vincolo è volutamente PER PRODOTTO e non globale: prodotti diversi possono
 * legittimamente condividere lo stesso codice (es. numerazione del fornitore riusata
 * su materie prime diverse). Arrivi multipli dello stesso lotto fisico non violano
 * il vincolo perché vengono sommati sulla stessa riga esistente (vedi
 * qk_insert_movimento_manuale / _ricezione_gestisci_lotto), non creano un duplicato.
 *
 * Sicurezza: se sul DB esistono già duplicati (prodotto_id, codice_lotto_interno),
 * la migrazione NON li tocca/rinomina automaticamente (sono dati potenzialmente
 * critici per la tracciabilità) — si ferma, li elenca e chiede una correzione manuale
 * prima di poter applicare il vincolo.
 *
 * Idempotente: controlla l'esistenza dell'indice prima di agire.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php'; // crea/apre $pdo sul DB reale dell'app

function qk_mig_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA index_list({$table})");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            if ($idx['name'] === $indexName) return true;
        }
        return false;
    }
    // MySQL / MariaDB
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$table, $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

$indexName = 'idx_lotti_prodotto_codice_unique';

if (qk_mig_index_exists($pdo, 'lotti', $indexName)) {
    echo "  [--] {$indexName} già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
    exit(0);
}

// Cerca duplicati (prodotto_id, codice_lotto_interno) prima di creare l'indice unique.
$stmt = $pdo->query(
    "SELECT prodotto_id, codice_lotto_interno, COUNT(*) AS n
       FROM lotti
      GROUP BY prodotto_id, codice_lotto_interno
     HAVING COUNT(*) > 1"
);
$duplicati = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($duplicati)) {
    echo "  [ERRORE] Trovati " . count($duplicati) . " codici lotto duplicati per lo stesso prodotto:\n";
    foreach ($duplicati as $d) {
        echo "    - prodotto_id={$d['prodotto_id']}  codice='{$d['codice_lotto_interno']}'  occorrenze={$d['n']}\n";
    }
    echo "\n  Correggi manualmente questi lotti (rinomina il codice di uno dei due, o unisci le\n";
    echo "  quantità sullo stesso lotto se sono davvero la stessa partita fisica) e rilancia\n";
    echo "  questa migrazione: non vengono modificati automaticamente perché sono dati di\n";
    echo "  tracciabilità potenzialmente critici.\n";
    exit(1);
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$indexName} ON lotti(prodotto_id, codice_lotto_interno)");
} else {
    $pdo->exec("ALTER TABLE lotti ADD UNIQUE INDEX {$indexName} (prodotto_id, codice_lotto_interno)");
}

echo "  [OK] {$indexName} creato su lotti(prodotto_id, codice_lotto_interno)\n";
echo "\nMigrazione completata.\n";
