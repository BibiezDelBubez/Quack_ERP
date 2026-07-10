<?php
/**
 * Migration: crea le viste v_prezzo_acquisto_base_fornitore e
 * v_costo_acquisto_prodotti.
 *
 * Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md, Severità ALTA):
 * eliminare un Listino Acquisto da interfaccia fa soft-delete
 * (listini_acquisto.attivo = 0), quindi ON DELETE CASCADE su
 * prodotti_fornitori_prezzi.listino_acquisto_id non scatta mai — i prezzi
 * del listino disattivato restavano nel database e le query sparse in 6+
 * file per estrarre "il costo di acquisto base" li pescavano comunque,
 * perché usavano `ORDER BY pfp.id DESC LIMIT 1` (l'ID di inserimento non ha
 * alcun legame con la validità commerciale del prezzo) senza filtrare mai
 * sullo stato del listino.
 *
 * Vedi il commento esteso in db/init_sqlite.sql (sezione "14b") per la
 * spiegazione completa della logica delle due viste.
 *
 * Idempotente: CREATE VIEW IF NOT EXISTS (SQLite) / CREATE OR REPLACE VIEW
 * (MySQL) — sicuro da rilanciare.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

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
            WHERE pf.attivo = 1
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
            WHERE pf.attivo = 1
        ) ranked
        WHERE rn = 1
    ");
}

echo "  [OK] viste v_prezzo_acquisto_base_fornitore e v_costo_acquisto_prodotti create/aggiornate\n";
echo "\nMigrazione completata.\n";
