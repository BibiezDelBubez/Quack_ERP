<?php

declare(strict_types=1);

/**
 * Ritorna il numero di ordini clienti da evadere.
 */
function get_kpi_ordini_clienti_evadere(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM ordini_clienti WHERE stato IN ('in_lavorazione', 'parzialmente_spedito')"
    )->fetchColumn();
}

/**
 * Ritorna il numero di spedizioni in preparazione.
 */
function get_kpi_spedizioni_preparazione(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM spedizioni WHERE stato = 'preparazione'"
    )->fetchColumn();
}

/**
 * Ritorna il numero di prodotti sottoscorta nel magazzino specificato.
 */
function get_kpi_prodotti_sottoscorta(PDO $pdo, int $magazzinoAttivoId): int
{
    $stSottoscorta = $pdo->prepare("
        SELECT COUNT(*) FROM prodotti p
        JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
        WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
    ");
    $stSottoscorta->execute([$magazzinoAttivoId]);
    $count = (int) $stSottoscorta->fetchColumn();
    $stSottoscorta->closeCursor();
    return $count;
}

/**
 * Ritorna il nome del magazzino.
 */
function get_kpi_nome_magazzino(PDO $pdo, int $magazzinoAttivoId): string
{
    $stNomeMag = $pdo->prepare('SELECT nome FROM magazzini WHERE id = ?');
    $stNomeMag->execute([$magazzinoAttivoId]);
    $nome = (string) ($stNomeMag->fetchColumn() ?: '');
    $stNomeMag->closeCursor();
    return $nome;
}

/**
 * Ritorna il numero di acquisti in arrivo (ordini fornitore).
 */
function get_kpi_acquisti_in_arrivo(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')"
    )->fetchColumn();
}

/**
 * Ritorna il fatturato del mese specificato (formato YYYY-MM).
 */
function get_kpi_fatturato_mese(PDO $pdo, string $mese_yyyy_mm): float
{
    $stKpi5 = $pdo->prepare("
        SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario * (1 - r.sconto_perc/100.0)), 0.0)
        FROM ordini_clienti_righe r
        JOIN ordini_clienti o ON o.id = r.ordine_id
        WHERE o.stato != 'annullato' AND o.data_ordine LIKE ?
    ");
    $stKpi5->execute([$mese_yyyy_mm . '%']);
    $fatturato = (float) $stKpi5->fetchColumn();
    $stKpi5->closeCursor();
    return $fatturato;
}

/**
 * Ritorna gli ordini clienti recenti per la dashboard.
 */
function get_dashboard_recent_sales_orders(PDO $pdo, int $limit = 5): array
{
    $stmtSO = $pdo->prepare(
        "SELECT o.id, o.numero_ordine, o.stato, o.data_ordine,
                COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                (SELECT COALESCE(SUM(quantita_ordinata * prezzo_unitario * (1 - sconto_perc/100.0)), 0.0) FROM ordini_clienti_righe WHERE ordine_id = o.id) AS totale
         FROM ordini_clienti o
         JOIN clienti_fornitori cf ON cf.id = o.cliente_id
         ORDER BY o.created_at DESC
         LIMIT ?"
    );
    // PDO non supporta il bind numerico nei LIMIT se configurato male in emulated prepares,
    // quindi si disabilita l'emulazione o si binda esplicitamente come INT.
    $stmtSO->bindValue(1, $limit, PDO::PARAM_INT);
    $stmtSO->execute();
    return $stmtSO->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna gli acquisti (ordini fornitore) recenti per la dashboard.
 */
function get_dashboard_recent_purchase_orders(PDO $pdo, int $limit = 5): array
{
    $stmtPO = $pdo->prepare(
        "SELECT ot.id, ot.numero, ot.stato, ot.data_ordine, ot.totale,
                COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
         FROM ordini_testata ot
         JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
         ORDER BY ot.created_at DESC
         LIMIT ?"
    );
    $stmtPO->bindValue(1, $limit, PDO::PARAM_INT);
    $stmtPO->execute();
    return $stmtPO->fetchAll(PDO::FETCH_ASSOC);
}
