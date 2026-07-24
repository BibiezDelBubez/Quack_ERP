<?php

declare(strict_types=1);

/**
 * Ritorna il numero di preventivi (sales quotes) in stato "bozza".
 */
function get_kpi_sales_quotes_bozza(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM preventivi WHERE stato = 'bozza'"
    )->fetchColumn();
}

/**
 * Ritorna il numero di preventivi (sales quotes) inviati ("inviato") e ancora
 * in attesa di risposta del cliente.
 *
 * PIANO - Vista Stato Effettivo Preventivi (Scadenza P1): conta solo gli
 * 'inviato' NON scaduti (via v_preventivi_stato_effettivo) — un preventivo
 * scaduto non e' piu' "in attesa di risposta", coerente con lo scopo del KPI.
 * Decisione presa in autonomia durante l'esecuzione del piano, segnalata
 * esplicitamente nel documento: se si preferisce continuare a contare anche
 * gli scaduti (magari con un KPI "Scaduti" separato) e' una modifica minima.
 */
function get_kpi_sales_quotes_inviati(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM v_preventivi_stato_effettivo WHERE stato_effettivo = 'inviato'"
    )->fetchColumn();
}

/**
 * Ritorna il numero di ordini clienti (sales orders) in uno stato attivo (in_lavorazione, parzialmente_spedito, spedito).
 */
function get_kpi_sales_orders_attivi(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM ordini_clienti WHERE stato IN ('in_lavorazione', 'parzialmente_spedito', 'spedito')"
    )->fetchColumn();
}

/**
 * Ritorna gli ultimi preventivi recenti per la dashboard vendite.
 */
function get_recent_sales_quotes_for_dashboard(PDO $pdo, int $limit = 5): array
{
    // PIANO - Vista Stato Effettivo Preventivi (Scadenza P1): p.stato_effettivo
    // AS stato, stesso alias-trick di qk_preventivi_list() — preventivo_badge_dash()
    // (app/views/sales/sales_dashboard.php) legge $q['stato'] senza modifiche
    // e gestisce gia' l'etichetta 'scaduto'.
    $stmtQ = $pdo->prepare(
        "SELECT p.id, p.numero_preventivo, p.stato_effettivo AS stato, p.totale,
                COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
         FROM v_preventivi_stato_effettivo p
         JOIN clienti_fornitori cf ON cf.id = p.cliente_id
         ORDER BY p.created_at DESC
         LIMIT ?"
    );
    $stmtQ->bindValue(1, $limit, PDO::PARAM_INT);
    $stmtQ->execute();
    return $stmtQ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna gli ultimi ordini clienti recenti per la dashboard vendite.
 */
function get_recent_sales_orders_for_dashboard(PDO $pdo, int $limit = 5): array
{
    $stmtO = $pdo->prepare(
        "SELECT o.id, o.numero_ordine, o.stato, o.data_ordine,
                COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
         FROM ordini_clienti o
         JOIN clienti_fornitori cf ON cf.id = o.cliente_id
         ORDER BY o.created_at DESC
         LIMIT ?"
    );
    $stmtO->bindValue(1, $limit, PDO::PARAM_INT);
    $stmtO->execute();
    return $stmtO->fetchAll(PDO::FETCH_ASSOC);
}
