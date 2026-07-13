<?php
/**
 * Migration: un fornitore disattivato smette di essere proposto come fonte
 * di costo di riferimento (REVISIONE §6.11, "Unità di misura e prezzi").
 *
 * Problema corretto:
 *   1. `listini_acquisto` non aveva la colonna `attivo` su MySQL (esisteva già
 *      su SQLite): la vista v_prezzo_acquisto_base_fornitore la referenzia da
 *      sempre (la.attivo), quindi su MySQL la CREATE VIEW avrebbe fallito.
 *   2. Nessuna delle due viste (v_prezzo_acquisto_base_fornitore,
 *      v_costo_acquisto_prodotti) controllava mai clienti_fornitori.attivo:
 *      disattivare un fornitore non lo escludeva dal calcolo del costo di
 *      riferimento se il legame prodotti_fornitori restava attivo.
 *
 * Idempotente: controlla lo schema prima di modificarlo, ricrea le viste in
 * ogni caso (CREATE OR REPLACE / DROP+CREATE), sicura da rilanciare.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

function qk_column_exists(PDO $pdo, string $table, string $column): bool
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

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// 1. Colonna listini_acquisto.attivo (mancava solo su MySQL)
if (!qk_column_exists($pdo, 'listini_acquisto', 'attivo')) {
    $pdo->exec("ALTER TABLE listini_acquisto ADD COLUMN attivo BOOLEAN NOT NULL DEFAULT 1");
    echo "  [OK] listini_acquisto.attivo aggiunta\n";
} else {
    echo "  [--] listini_acquisto.attivo già presente, skip\n";
}

// 2. Ricrea le due viste con il filtro su clienti_fornitori.attivo
if ($driver === 'sqlite') {
    $pdo->exec("DROP VIEW IF EXISTS v_costo_acquisto_prodotti");
    $pdo->exec("DROP VIEW IF EXISTS v_prezzo_acquisto_base_fornitore");
    $pdo->exec("
        CREATE VIEW v_prezzo_acquisto_base_fornitore AS
        SELECT prodotto_fornitore_id, prezzo, unita_misura_id
        FROM (
            SELECT
                pfp.prodotto_fornitore_id,
                pfp.prezzo,
                pfp.unita_misura_id,
                ROW_NUMBER() OVER (
                    PARTITION BY pfp.prodotto_fornitore_id
                    ORDER BY COALESCE(pfp.valido_dal, la.valido_dal, '0001-01-01') DESC, pfp.id DESC
                ) AS rn
            FROM prodotti_fornitori_prezzi pfp
            LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
            WHERE pfp.quantita_minima = 0
              AND (pfp.listino_acquisto_id IS NULL OR la.attivo = 1)
              AND (pfp.valido_dal IS NULL OR pfp.valido_dal <= DATE('now'))
              AND (pfp.valido_al  IS NULL OR pfp.valido_al  >= DATE('now'))
              AND (la.valido_dal  IS NULL OR la.valido_dal  <= DATE('now'))
              AND (la.valido_al   IS NULL OR la.valido_al   >= DATE('now'))
        ) ranked
        WHERE rn = 1
    ");
    $pdo->exec("
        CREATE VIEW v_costo_acquisto_prodotti AS
        SELECT prodotto_id, prodotto_fornitore_id, fornitore_id, costo_acquisto, unita_misura_id
        FROM (
            SELECT
                pf.prodotto_id,
                pf.id AS prodotto_fornitore_id,
                pf.fornitore_id,
                vf.prezzo AS costo_acquisto,
                vf.unita_misura_id,
                ROW_NUMBER() OVER (
                    PARTITION BY pf.prodotto_id
                    ORDER BY pf.ordine_preferenza_fornitore ASC, pf.id ASC
                ) AS rn
            FROM prodotti_fornitori pf
            JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
            JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
            WHERE pf.attivo = 1
              AND cf.attivo = 1
        ) ranked
        WHERE rn = 1
    ");
} else {
    $pdo->exec("
        CREATE OR REPLACE VIEW v_prezzo_acquisto_base_fornitore AS
        SELECT prodotto_fornitore_id, prezzo, unita_misura_id
        FROM (
            SELECT
                pfp.prodotto_fornitore_id,
                pfp.prezzo,
                pfp.unita_misura_id,
                ROW_NUMBER() OVER (
                    PARTITION BY pfp.prodotto_fornitore_id
                    ORDER BY COALESCE(pfp.valido_dal, la.valido_dal, '0001-01-01') DESC, pfp.id DESC
                ) AS rn
            FROM prodotti_fornitori_prezzi pfp
            LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
            WHERE pfp.quantita_minima = 0
              AND (pfp.listino_acquisto_id IS NULL OR la.attivo = 1)
              AND (pfp.valido_dal IS NULL OR pfp.valido_dal <= CURDATE())
              AND (pfp.valido_al  IS NULL OR pfp.valido_al  >= CURDATE())
              AND (la.valido_dal  IS NULL OR la.valido_dal  <= CURDATE())
              AND (la.valido_al   IS NULL OR la.valido_al   >= CURDATE())
        ) ranked
        WHERE rn = 1
    ");
    $pdo->exec("
        CREATE OR REPLACE VIEW v_costo_acquisto_prodotti AS
        SELECT prodotto_id, prodotto_fornitore_id, fornitore_id, costo_acquisto, unita_misura_id
        FROM (
            SELECT
                pf.prodotto_id,
                pf.id AS prodotto_fornitore_id,
                pf.fornitore_id,
                vf.prezzo AS costo_acquisto,
                vf.unita_misura_id,
                ROW_NUMBER() OVER (
                    PARTITION BY pf.prodotto_id
                    ORDER BY pf.ordine_preferenza_fornitore ASC, pf.id ASC
                ) AS rn
            FROM prodotti_fornitori pf
            JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
            JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
            WHERE pf.attivo = 1
              AND cf.attivo = 1
        ) ranked
        WHERE rn = 1
    ");
}

echo "  [OK] viste v_prezzo_acquisto_base_fornitore e v_costo_acquisto_prodotti aggiornate (filtro fornitore attivo)\n";
echo "\nMigrazione completata.\n";
