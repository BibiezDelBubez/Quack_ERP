<?php

declare(strict_types=1);

// PIANO - Revisione Ordini Clienti (Nuova Versione), §7.7/Tappa 9 (26/07/2026).
// Richieste di correzione per un ordine GIÀ fatturato (stato_fatturazione =
// 'fatturato'): "Crea Nuova Versione" (qk_ordine_cliente_crea_revisione(),
// includes/sales_orders.php) resta bloccato apposta in quel caso (§4.3) — qui
// si registra una richiesta separata, con approvazione di un secondo utente
// (maker-checker), mai una riscrittura diretta dell'ordine. Dipendenze
// dichiarate in testa al file (CLAUDE.md punto 4), non lasciate al chiamante.
require_once INCLUDES_DIR . '/sales_orders.php';
require_once INCLUDES_DIR . '/impostazioni.php';
require_once INCLUDES_DIR . '/anagrafiche.php';

/**
 * Crea una richiesta di correzione per un ordine già fatturato.
 *
 * @param array $righe Ogni elemento: ['riga_ordine_id' => int, 'quantita_corretta' => float, 'prezzo_unitario_corretta' => float]
 * @return int id della richiesta creata
 */
function qk_correzione_ordine_richiedi(PDO $pdo, int $ordineId, ?string $motivo, array $righe, int $userId): int
{
    $ordine = qk_get_ordine_per_spedizione($pdo, $ordineId);
    if (!$ordine) {
        throw new \RuntimeException('Ordine non trovato.');
    }
    // Guardia esplicita (§4.3 del piano): questa richiesta ha senso solo per
    // un ordine già fatturato — per un ordine non ancora fatturato lo
    // strumento corretto resta "Crea Nuova Versione", non questa richiesta.
    if (($ordine['stato_fatturazione'] ?? '') !== 'fatturato') {
        throw new \RuntimeException('Questo ordine non è ancora fatturato: usa "Crea Nuova Versione" invece di richiedere una correzione.');
    }
    if (empty($righe)) {
        throw new \RuntimeException('Nessuna riga da correggere indicata.');
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stInsReq = $pdo->prepare(
            "INSERT INTO richieste_correzione_ordine (ordine_id, motivo, stato, creato_da)
             VALUES (?, ?, 'in_approvazione', ?)"
        );
        $stInsReq->execute([$ordineId, $motivo !== '' ? $motivo : null, $userId]);
        $richiestaId = (int) $pdo->lastInsertId();

        $stRigaOriginale = $pdo->prepare(
            "SELECT id, prodotto_id, quantita_ordinata, prezzo_unitario FROM ordini_clienti_righe WHERE id = ? AND ordine_id = ?"
        );
        $stInsRiga = $pdo->prepare(
            "INSERT INTO richieste_correzione_righe
                (richiesta_id, riga_ordine_id_originale, prodotto_id, quantita_originale, prezzo_unitario_originale, quantita_corretta, prezzo_unitario_corretta)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $almenoUnaModifica = false;
        foreach ($righe as $riga) {
            $rigaOrdineId = (int) ($riga['riga_ordine_id'] ?? 0);
            $stRigaOriginale->execute([$rigaOrdineId, $ordineId]);
            $originale = $stRigaOriginale->fetch(PDO::FETCH_ASSOC);
            if (!$originale) {
                throw new \RuntimeException("Riga ordine {$rigaOrdineId} non trovata su questo ordine.");
            }

            $qtaCorretta    = (float) ($riga['quantita_corretta'] ?? $originale['quantita_ordinata']);
            $prezzoCorretto = (float) ($riga['prezzo_unitario_corretta'] ?? $originale['prezzo_unitario']);

            // Salta le righe dove l'operatore non ha cambiato nulla (il CHECK
            // a schema rifiuterebbe comunque una riga identica: qui evitiamo
            // l'eccezione a monte, silenziosamente per QUESTA riga soltanto,
            // non per l'intera richiesta).
            if ($qtaCorretta == (float) $originale['quantita_ordinata']
                && $prezzoCorretto == (float) $originale['prezzo_unitario']) {
                continue;
            }

            $stInsRiga->execute([
                $richiestaId,
                $rigaOrdineId,
                $originale['prodotto_id'],
                $originale['quantita_ordinata'],
                $originale['prezzo_unitario'],
                $qtaCorretta,
                $prezzoCorretto,
            ]);
            $almenoUnaModifica = true;
        }

        if (!$almenoUnaModifica) {
            throw new \RuntimeException('Nessuna riga risulta effettivamente modificata rispetto ai valori originali.');
        }

        qk_log_stato_ordine($pdo, 'richiesta_correzione_ordine', $richiestaId, null, 'in_approvazione', $userId, "Richiesta creata per ordine #{$ordineId}");

        if ($ownTransaction) {
            $pdo->commit();
        }
        return $richiestaId;
    } catch (\Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Approva una richiesta di correzione (maker-checker, §7.7.3/§7.7.5).
 * NON riscrive l'ordine: Quack resta solo un archivio della richiesta, la
 * nota di credito vera si emette fuori da Quack (§7.6, Criticità 3) — qui si
 * registra solo il riferimento a quel documento esterno.
 */
function qk_correzione_ordine_approva(PDO $pdo, int $richiestaId, string $riferimentoDocEsterno, int $userId): void
{
    if (trim($riferimentoDocEsterno) === '') {
        throw new \RuntimeException('Il riferimento al documento esterno (es. numero nota di credito) è obbligatorio per approvare.');
    }

    $st = $pdo->prepare("SELECT * FROM richieste_correzione_ordine WHERE id = ?");
    $st->execute([$richiestaId]);
    $richiesta = $st->fetch(PDO::FETCH_ASSOC);
    if (!$richiesta) {
        throw new \RuntimeException('Richiesta di correzione non trovata.');
    }
    if ($richiesta['stato'] !== 'in_approvazione') {
        throw new \RuntimeException('Questa richiesta è già stata chiusa (stato: ' . $richiesta['stato'] . ').');
    }

    // Maker-checker (§7.7.5): di default (Impostazioni non configurate) un
    // singolo utente con il permesso può creare E approvare da solo — utile
    // per una micro-impresa con un solo utente abilitato. Solo se l'admin
    // attiva esplicitamente 'maker_checker_correzioni_attivo', il creatore
    // non può più approvare la propria stessa richiesta, a meno di avere
    // anche 'bypass_maker_checker' (admin, sempre).
    $makerCheckerAttivo = get_impostazione($pdo, 'maker_checker_correzioni_attivo', '0') === '1';
    $puoBypassare = can('bypass_maker_checker');
    if ($makerCheckerAttivo && !$puoBypassare && (int) $richiesta['creato_da'] === $userId) {
        throw new \RuntimeException('Non puoi approvare una richiesta creata da te stesso (doppia approvazione attiva).');
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stUpd = $pdo->prepare(
            "UPDATE richieste_correzione_ordine
                SET stato = 'approvata', riferimento_doc_esterno = ?, chiusa_da = ?, chiusa_il = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $stUpd->execute([$riferimentoDocEsterno, $userId, $richiestaId]);

        $bypassUsato = $makerCheckerAttivo && $puoBypassare && (int) $richiesta['creato_da'] === $userId;
        $nota = 'Richiesta approvata, rif. esterno: ' . $riferimentoDocEsterno . ($bypassUsato ? ' [BYPASS maker-checker usato]' : '');
        qk_log_stato_ordine($pdo, 'richiesta_correzione_ordine', $richiestaId, 'in_approvazione', 'approvata', $userId, $nota);

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Rifiuta una richiesta di correzione.
 */
function qk_correzione_ordine_rifiuta(PDO $pdo, int $richiestaId, string $motivoRifiuto, int $userId): void
{
    if (trim($motivoRifiuto) === '') {
        throw new \RuntimeException('Il motivo del rifiuto è obbligatorio.');
    }

    $st = $pdo->prepare("SELECT stato FROM richieste_correzione_ordine WHERE id = ?");
    $st->execute([$richiestaId]);
    $stato = $st->fetchColumn();
    if ($stato === false) {
        throw new \RuntimeException('Richiesta di correzione non trovata.');
    }
    if ($stato !== 'in_approvazione') {
        throw new \RuntimeException('Questa richiesta è già stata chiusa (stato: ' . $stato . ').');
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stUpd = $pdo->prepare(
            "UPDATE richieste_correzione_ordine
                SET stato = 'rifiutata', motivo_rifiuto = ?, chiusa_da = ?, chiusa_il = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $stUpd->execute([$motivoRifiuto, $userId, $richiestaId]);

        qk_log_stato_ordine($pdo, 'richiesta_correzione_ordine', $richiestaId, 'in_approvazione', 'rifiutata', $userId, 'Rifiutata: ' . $motivoRifiuto);

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Elenco delle richieste di correzione per un ordine (per la vista dettaglio).
 */
function qk_correzioni_ordine_lista(PDO $pdo, int $ordineId): array
{
    // Nomi utente combinati in PHP (qk_nome_completo_persona(), già esistente
    // in includes/anagrafiche.php) invece che con una concatenazione SQL —
    // CLAUDE.md punto 3: mai '||' per portabilità SQLite/MySQL, e qui evitato
    // del tutto usando lo stesso helper già usato altrove nel progetto.
    $st = $pdo->prepare(
        "SELECT r.*, u1.nome AS creato_da_nome, u1.cognome AS creato_da_cognome,
                u2.nome AS chiusa_da_nome, u2.cognome AS chiusa_da_cognome
           FROM richieste_correzione_ordine r
           LEFT JOIN utenti u1 ON u1.id = r.creato_da
           LEFT JOIN utenti u2 ON u2.id = r.chiusa_da
          WHERE r.ordine_id = ?
          ORDER BY r.created_at DESC"
    );
    $st->execute([$ordineId]);
    $righe = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($righe as &$r) {
        $r['creato_da_completo'] = qk_nome_completo_persona($r['creato_da_nome'], $r['creato_da_cognome'], null);
        $r['chiusa_da_completo'] = $r['chiusa_da_nome'] !== null
            ? qk_nome_completo_persona($r['chiusa_da_nome'], $r['chiusa_da_cognome'], null)
            : null;
    }
    unset($r);
    return $righe;
}

/**
 * Righe di dettaglio di una richiesta (per la vista dettaglio/approvazione).
 */
function qk_correzione_ordine_righe(PDO $pdo, int $richiestaId): array
{
    $st = $pdo->prepare(
        "SELECT rr.*, p.nome AS prodotto_nome, p.sku_interno AS prodotto_sku
           FROM richieste_correzione_righe rr
           LEFT JOIN prodotti p ON p.id = rr.prodotto_id
          WHERE rr.richiesta_id = ?"
    );
    $st->execute([$richiestaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Funzione-ponte di logging condivisa (log_stati_ordini, §7.7.4 — primo uso
 * reale di questa tabella in tutto il progetto).
 */
function qk_log_stato_ordine(PDO $pdo, string $tipoDocumento, int $documentoId, ?string $statoPrecedente, string $statoNuovo, int $userId, ?string $nota = null): void
{
    $st = $pdo->prepare(
        "INSERT INTO log_stati_ordini (tipo_documento, documento_id, stato_precedente, stato_nuovo, cambiato_da, note)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $st->execute([$tipoDocumento, $documentoId, $statoPrecedente, $statoNuovo, $userId, $nota]);
}
