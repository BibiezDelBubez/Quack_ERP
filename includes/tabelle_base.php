<?php
declare(strict_types=1);

/**
 * Ritorna l'elenco dei metodi di pagamento attivi
 */
function qk_get_metodi_pagamento_attivi(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM metodi_pagamento WHERE attivo = 1 ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna l'elenco degli incoterms attivi
 */
function qk_get_incoterms_attivi(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM incoterms WHERE attivo = 1 ORDER BY codice")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna l'elenco dei listini di vendita attivi
 */
function qk_get_listini_vendita_attivi(PDO $pdo, bool $excludeDefault = false): array
{
    $where = "WHERE attivo = 1";
    if ($excludeDefault) {
        $where .= " AND is_default = 0";
    }
    return $pdo->query("SELECT id, nome, valuta FROM listini $where ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna l'elenco delle unita di misura
 */
function qk_get_unita_misura_lista(PDO $pdo): array
{
    return $pdo->query("SELECT id, codice, descrizione FROM unita_misura ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);
}
