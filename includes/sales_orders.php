<?php
declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';
require_once __DIR__ . '/prodotti.php';
require_once __DIR__ . '/document_common.php';
require_once __DIR__ . '/magazzino.php';
require_once __DIR__ . '/transizioni_stati.php';
require_once __DIR__ . '/../app/Services/TransitionGuard.php';

/**
 * Genera il prossimo numero progressivo per l'ordine cliente diretto.
 */
function qk_next_numero_ordine_diretto(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'ordine_cliente');
}

/**
 * Valida i dati dell'ordine diretto.
 */
function qk_ordine_diretto_validate(array $values, array $righe): array
{
    $errors = [];

    if (empty($values['cliente_id'])) {
        $errors['cliente_id'] = 'Selezionare un cliente.';
    }
    if (empty($values['data_ordine'])) {
        $errors['data_ordine'] = 'La data ordine è obbligatoria.';
    }

    if (empty($righe)) {
        $errors['_global'] = 'Inserire almeno una riga prodotto.';
    } else {
        foreach ($righe as $idx => $r) {
            if (empty(trim($r['descrizione'] ?? ''))) {
                $errors["righe_{$idx}_descrizione"] = 'La descrizione è obbligatoria.';
            }
            if ((float)($r['quantita'] ?? 0) <= 0) {
                $errors["righe_{$idx}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
            if ((float)($r['prezzo_unitario'] ?? 0) < 0) {
                $errors["righe_{$idx}_prezzo"] = 'Il prezzo unitario non può essere negativo.';
            }
        }
    }

    return $errors;
}

/**
 * Inserisce un ordine cliente diretto (senza preventivo di origine).
 * Restituisce l'ID del nuovo ordine.
 */
function qk_insert_ordine_diretto(PDO $pdo, array $values, array $righe, int $user_id): int
{
    require_once __DIR__ . '/../app/Repositories/SalesOrderRepository.php';
    require_once __DIR__ . '/../app/Services/OrderCalculatorService.php';

    $repository = new \App\Repositories\SalesOrderRepository($pdo);
    $service = new \App\Services\OrderCalculatorService($pdo, $repository);

    return $service->insertDirectOrder($values, $righe, $user_id);
}

/**
 * Carica la riga di ordini_clienti per id, o lancia se non trovato.
 * Stesso pattern di _ordine_carica() in includes/purchases_orders.php.
 */
function _ordine_cliente_carica(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM ordini_clienti WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new \RuntimeException('Ordine non trovato.');
    }
    return $row;
}

/**
 * Verifica che l'ordine cliente NON sia in uno stato terminale (completato/
 * annullato). Precondizione condivisa da due punti del controller
 * (SalesOrdersController::edit() ed elaboraCreaSpedizione()) che PRIMA la
 * applicavano in modo incoerente: edit() la controllava (ma nel controller,
 * non nel model — violazione MVC), elaboraCreaSpedizione() non la applicava
 * affatto (poteva creare una spedizione, e quindi far "ripartire" lo stato,
 * su un ordine già completato o annullato).
 *
 * Tappa 7 (PIANO - Motore Unificato di Transizioni di Stato): riusa la stessa
 * mappa/istanza di qk_transizioni_ordini_clienti() invece di un secondo
 * in_array() scollegato — uno stato è "ancora attivo" se da lì è raggiungibile
 * 'annullato', esattamente {in_lavorazione, parzialmente_spedito, spedito}
 * nella mappa attuale, mai {completato, annullato}. Corrisponde esattamente
 * alla condizione già mostrata (ma mai imposta lato server) in
 * app/views/sales/orders/_detail.php righe 22 e 343: !$isAnnullato && !$isCompletato.
 */
function qk_ordine_cliente_assicura_modificabile(string $statoAttuale, string $messaggioErrore): void
{
    $guard = new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente');
    if (!$guard->puoTransire($statoAttuale, 'annullato')) {
        throw new \RuntimeException($messaggioErrore);
    }
}

/**
 * Aggiorna note e data di consegna prevista di un ordine cliente.
 * Tappa 7: il controllo "non è già completato/annullato" viveva SOLO dentro
 * SalesOrdersController::edit() (violazione MVC) — spostato qui.
 */
function qk_ordine_cliente_aggiorna_note(PDO $pdo, int $id, ?string $note, ?string $dataConsegnaPrevista): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    qk_ordine_cliente_assicura_modificabile(
        (string) $ordine['stato'],
        'Accesso negato: impossibile modificare un ordine già completato o annullato.'
    );

    $pdo->prepare("
        UPDATE ordini_clienti
        SET note = ?, data_consegna_prevista = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$note, $dataConsegnaPrevista, $id]);
}

/**
 * Segna l'ordine cliente come completato manualmente.
 * Tappa 7: prima un UPDATE grezzo in SalesOrdersController::action(), senza
 * nessun controllo di stato.
 */
function qk_ordine_cliente_completa(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'completato',
            "Impossibile completare: l'ordine non è in uno stato valido per il completamento (stato attuale: {$ordine['stato']})."
        );
    $pdo->prepare("UPDATE ordini_clienti SET stato = 'completato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$id]);
}

/**
 * Riporta l'ordine cliente in lavorazione. Ammesso solo da 'completato' —
 * coerente con app/views/sales/orders/_detail.php:86 (bottone "Riapri"
 * mostrato solo quando isCompletato && !isAnnullato).
 */
function qk_ordine_cliente_riapri(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'in_lavorazione',
            "Impossibile riaprire: l'ordine può essere riaperto solo se è 'completato' (stato attuale: {$ordine['stato']})."
        );
    $pdo->prepare("UPDATE ordini_clienti SET stato = 'in_lavorazione', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$id]);
}

/**
 * Annulla l'ordine cliente.
 * NB: bug O2 preesistente e già noto (l'annullamento non rilascia mai la
 * riserva stock su giacenze.quantita_riservata) resta FUORI SCOPO per questo
 * piano — qui si aggiunge solo la guardia di transizione mancante, non si
 * tocca (né si finge di correggere) la logica di rilascio riserva.
 */
function qk_ordine_cliente_annulla(PDO $pdo, int $id): void
{
    $ordine = _ordine_cliente_carica($pdo, $id);
    (new \App\Services\TransitionGuard(qk_transizioni_ordini_clienti(), 'ordine cliente'))
        ->assicuraConMessaggio(
            (string) $ordine['stato'],
            'annullato',
            "Impossibile annullare: l'ordine è già in stato '{$ordine['stato']}'."
        );
    $pdo->prepare("UPDATE ordini_clienti SET stato = 'annullato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$id]);
}

/**
 * Ricerca prodotti con dettagli arricchiti per ordini di vendita.
 * Riusa la stessa query dei preventivi.
 */
function qk_search_prodotti_orders(PDO $pdo, string $q): array
{
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.nome AS label,
               p.sku_interno AS sku,
               um.codice AS unita_misura,
               p.unita_misura_stock_id,
               p.descrizione_confezione AS quantita_confezione,
               p.unita_contenuto_id,
               um_comm.codice AS unita_misura_commerciale,
               p.quantita_contenuto,
               pf.rrp_min,
               pf.rrp_max,
               pf.fornitore_id AS rrp_fornitore_id,
               -- Costo base: da v_costo_acquisto_prodotti (fix 'Listino Fantasma',
               -- audit/MASTER_AUDIT_REPORT.md) — vedi commento esteso in db/init_sqlite.sql par.14b.
               CASE WHEN p.tipo = 'kit' THEN (
                   SELECT SUM(ck.quantita * vc.costo_acquisto)
                   FROM componenti_kit ck
                   LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = ck.componente_id
                   WHERE ck.kit_id = p.id
               ) ELSE (
                   SELECT vc.costo_acquisto FROM v_costo_acquisto_prodotti vc WHERE vc.prodotto_id = p.id
               ) END AS costo_base
          FROM prodotti p
          LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
          LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
          LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.is_principale = 1
         WHERE p.attivo = 1
           AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR pf.sku_fornitore LIKE :q)
         ORDER BY p.nome
         LIMIT 60
    ");
    $stmt->execute([':q' => '%' . $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalizza campi
    foreach ($rows as &$r) {
        $r['rrp_min']   = isset($r['rrp_min'])   ? (float)$r['rrp_min']   : null;
        $r['rrp_max']   = isset($r['rrp_max'])    ? (float)$r['rrp_max']   : null;
        $r['costo_base'] = isset($r['costo_base']) ? (float)$r['costo_base'] : 0.0;
        $r['quantita_contenuto'] = isset($r['quantita_contenuto']) ? (float)$r['quantita_contenuto'] : 1.0;
    }
    unset($r);
    return $rows;
}
