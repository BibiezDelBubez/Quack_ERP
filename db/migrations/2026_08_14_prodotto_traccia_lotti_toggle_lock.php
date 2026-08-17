<?php
/**
 * Migration: crea la tabella prodotto_traccia_lotti_toggle_lock.
 *
 * Tappa 2 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Problema che risolve: qk_crea_lotti_conversione_iniziale() (includes/magazzino.php)
 * genera il nome del lotto di conversione a partire da prodotto_id + magazzino_id +
 * data odierna, e per non farlo collidere quando lo stesso prodotto viene attivato
 * più volte nello stesso giorno (caso reale verificato: TEST-BROWSER-A/ODC-2026-0005)
 * conta quanti lotti con quel prefisso esistono già, per decidere il numero
 * progressivo da aggiungere al nome (Tappa 2, stessa funzione). Questo conteggio è
 * un check-then-act: una SELECT (conta i lotti esistenti) seguita, in un momento
 * successivo, dall'INSERT del nuovo lotto con il nome calcolato da quel conteggio.
 * Su SQLite le scritture sono comunque serializzate dal motore (un solo writer alla
 * volta), ma questo progetto supporta anche MySQL/InnoDB (Regola su Schema Doppio,
 * CLAUDE.md punto 7), dove due transazioni concorrenti su righe diverse POSSONO
 * sovrapporsi: senza un vincolo esplicito, due richieste concorrenti sullo stesso
 * prodotto potrebbero entrambe leggere lo stesso conteggio prima che una delle due
 * scriva, generando due lotti con lo stesso nome — lo stesso tipo di race che
 * inventario_creazione_lock (db/migrations/2026_07_10_inventario_creazione_lock.php)
 * risolve già per la creazione di un inventario fisico. Stesso pattern, applicato qui
 * a livello di prodotto_id invece di magazzino_id.
 *
 * La riga di lock viene inserita e rimossa nella STESSA transazione della
 * conversione lotti: non è un lock persistente, serve solo a serializzare l'istante
 * del conteggio + inserimento.
 *
 * prodotto_traccia_lotti_toggle_lock:
 *   - prodotto_id  INTEGER  PRIMARY KEY REFERENCES prodotti(id)
 *
 * Idempotente: controlla l'esistenza della tabella prima di crearla.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_mig_20260814_table_exists(PDO $pdo, string $table): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!qk_mig_20260814_table_exists($pdo, 'prodotto_traccia_lotti_toggle_lock')) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE prodotto_traccia_lotti_toggle_lock (
            prodotto_id INTEGER PRIMARY KEY REFERENCES prodotti(id)
        )");
    } else {
        // Stesso schema di inventario_creazione_lock per MySQL (AUTO_INCREMENT sulla
        // PK ma l'app fornisce sempre il valore esplicito: MySQL rispetta il valore
        // passato invece di generarne uno nuovo quando la colonna non è NULL/omessa,
        // quindi il vincolo di unicità continua a valere sul prodotto_id reale).
        $pdo->exec("CREATE TABLE prodotto_traccia_lotti_toggle_lock (
            prodotto_id INT AUTO_INCREMENT PRIMARY KEY,
            CONSTRAINT fk_prodotto_traccia_lotti_toggle_lock_prodotto_id FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    echo "  [OK] tabella prodotto_traccia_lotti_toggle_lock creata\n";
    echo "\nMigrazione completata.\n";
} else {
    echo "  [--] tabella prodotto_traccia_lotti_toggle_lock già presente, skip\n";
    echo "\nNessuna modifica necessaria. Schema già aggiornato.\n";
}
