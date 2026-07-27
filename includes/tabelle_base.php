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
 * Ritorna l'elenco dei canali di vendita attivi
 */
function qk_get_canali_vendita_attivi(PDO $pdo): array
{
    return $pdo->query("SELECT id, codice, nome FROM canali_vendita WHERE attivo = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna l'elenco dei listini di vendita attivi.
 *
 * $q (PIANO - Unificazione Autocomplete, D2, 27/07/2026): filtro opzionale per
 * nome, usato dal nuovo endpoint di ricerca listini (SalesQuotesController::
 * apiListini) che sostituisce il filtro client-side che il campo "listino" in
 * sales-quotes.js faceva su un array già scaricato in memoria — qui invece
 * interroga davvero il server, come tutti gli altri campi di ricerca migrati
 * su QkM2O. Parametro opzionale: tutti i chiamanti esistenti (verificati con
 * grep su tutto il repo: CustomersController, ProductsController,
 * SalesOrdersController, SalesQuotesController, nessuno passa un 3° argomento)
 * continuano a funzionare identici.
 */
function qk_get_listini_vendita_attivi(PDO $pdo, bool $excludeDefault = false, string $q = ''): array
{
    $where  = "WHERE attivo = 1";
    $params = [];
    if ($excludeDefault) {
        $where .= " AND is_default = 0";
    }
    if ($q !== '') {
        $where .= " AND nome LIKE :q";
        $params[':q'] = '%' . $q . '%';
    }
    $stmt = $pdo->prepare("SELECT id, nome, valuta FROM listini $where ORDER BY nome ASC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Restituisce l'ID del listino marcato is_default=1, oppure null se non esiste.
 */
function qk_get_listino_default_id(PDO $pdo): ?int
{
    $row = $pdo->query("SELECT id FROM listini WHERE is_default = 1 AND attivo = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Ritorna l'elenco delle unita di misura
 */
function qk_get_unita_misura_lista(PDO $pdo): array
{
    return $pdo->query("SELECT id, codice, descrizione FROM unita_misura ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ritorna l'elenco completo delle lingue ISO 639-1 di riferimento (tabella
 * iso_lingue), ordinato per nome leggibile — usato per popolare il campo
 * lingua (searchable-select) del form Schede di Sicurezza (SDS), §3.9/§3.16
 * del PIANO Refactoring SDS.
 */
function qk_get_iso_lingue_lista(PDO $pdo): array
{
    return $pdo->query("SELECT codice, nome FROM iso_lingue ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Restituisce il codice del canale di vendita dato l'ID
 */
function qk_get_canale_vendita_codice(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare("SELECT codice FROM canali_vendita WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['codice'] : null;
}

/**
 * Restituisce il codice dell'unità di misura dato l'ID
 */
function qk_get_unita_misura_codice(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare("SELECT codice FROM unita_misura WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['codice'] : null;
}
