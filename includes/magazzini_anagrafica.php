<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';

// =============================================================================
// magazzini_anagrafica.php
// Anagrafica magazzini: CRUD sulla tabella `magazzini` (Piano Multi-Magazzino
// §3.3, §3.8, §3.10). Estratto da includes/magazzino.php (audit MVC/DRY,
// Revisione Gestione Magazzino e Lotti §6/piano refactor) — quel file resta il
// punto di ingresso per giacenze/lotti/movimenti e per gli helper condivisi
// (qk_magazzino_attivo_id, qk_risolvi_magazzino_filtro, qk_sottoscorta_where/
// having, qk_stock_counters), che restano lì perché usati anche fuori dal
// dominio Magazzino (Vendite/Acquisti/Prodotti/Dashboard). Nessuna dipendenza
// funzionale in nessuna delle due direzioni tra questo file e magazzino.php:
// verificato con grep su tutto il codebase prima dello split.
//
// Stesso stile CRUD di qk_aziende_list/qk_insert_azienda/qk_update_azienda in
// includes/anagrafiche.php, adattato alla tabella `magazzini` (autonoma, non
// appoggiata a clienti_fornitori).
// =============================================================================

/**
 * Lista magazzini per anagrafica e selettori (topbar, form documento).
 *
 * @param bool $soloAttivi         se true (default), esclude i magazzini disattivati
 *                                 (usato nei selettori: un magazzino disattivato non
 *                                 deve poter essere scelto per nuove operazioni, ma
 *                                 resta visibile nell'anagrafica con $soloAttivi=false)
 * @param bool $soloRadiciFisiche  se true, restituisce solo magazzini tipo='fisico'
 *                                 AND parent_id IS NULL (Trasferimenti tra Magazzini
 *                                 come Documento.md, §7.2): usato SOLO dal selettore
 *                                 di sessione in header.php — una sede virtuale/
 *                                 conto_terzi/conto_lavoro non deve mai diventare la
 *                                 sede "attiva" di lavoro, ma deve restare selezionabile
 *                                 come origine/destinazione di un trasferimento
 *                                 (PurchaseOrdersController, WarehouseController::
 *                                 createTransfer/trasferimentoDiretto NON passano questo flag).
 */
function qk_magazzini_list(PDO $pdo, bool $soloAttivi = false, bool $soloRadiciFisiche = false): array
{
    $sql = "SELECT m.id, m.codice, m.nome, m.tipo, m.predefinito, m.attivo, m.note,
                   m.azienda_interna_id, m.indirizzo_id, m.parent_id, m.soggetto_esterno_id,
                   ai.ragione_sociale AS azienda_interna_nome,
                   i.citta AS indirizzo_citta, i.via AS indirizzo_via,
                   pm.nome AS parent_nome,
                   COALESCE(NULLIF(cf.ragione_sociale, ''), TRIM(cf.nome || ' ' || COALESCE(cf.cognome, ''))) AS soggetto_esterno_nome
              FROM magazzini m
              LEFT JOIN aziende_interne az2 ON az2.id = m.azienda_interna_id
              LEFT JOIN clienti_fornitori ai ON ai.id = az2.soggetto_id
              LEFT JOIN indirizzi i ON i.id = m.indirizzo_id
              LEFT JOIN magazzini pm ON pm.id = m.parent_id
              LEFT JOIN clienti_fornitori cf ON cf.id = m.soggetto_esterno_id";
    $where = [];
    if ($soloAttivi) {
        $where[] = 'm.attivo = 1';
    }
    if ($soloRadiciFisiche) {
        $where[] = "m.tipo = 'fisico' AND m.parent_id IS NULL";
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY m.predefinito DESC, m.nome COLLATE NOCASE";

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Radice fisica di un magazzino (Trasferimenti tra Magazzini come Documento.md,
 * §7.1): COALESCE(parent_id, id). Due magazzini con la stessa radice fisica
 * possono usare il trasferimento diretto istantaneo invece del documento con transito.
 */
function qk_magazzini_radice_fisica(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT COALESCE(parent_id, id) FROM magazzini WHERE id = ?');
    $st->execute([$id]);
    $radice = $st->fetchColumn();
    if ($radice === false) {
        throw new \RuntimeException('Magazzino non trovato.');
    }
    return (int) $radice;
}

/**
 * True se due magazzini condividono la stessa radice fisica (§7.2, Regola A):
 * criterio che decide se lo spostamento tra loro può essere un trasferimento
 * diretto istantaneo o richiede il documento di trasferimento con transito.
 */
function qk_magazzino_stessa_radice(PDO $pdo, int $idA, int $idB): bool
{
    return qk_magazzini_radice_fisica($pdo, $idA) === qk_magazzini_radice_fisica($pdo, $idB);
}

/**
 * Carica un singolo magazzino per il form di modifica.
 */
function qk_load_magazzino_form(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT m.*, i.via, i.civico, i.cap, i.citta, i.provincia, i.nazione
           FROM magazzini m
           LEFT JOIN indirizzi i ON i.id = m.indirizzo_id
          WHERE m.id = ?"
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'id'                  => (int) $row['id'],
        'codice'              => (string) $row['codice'],
        'nome'                => (string) $row['nome'],
        'tipo'                => (string) $row['tipo'],
        'azienda_interna_id'  => $row['azienda_interna_id'] !== null ? (int) $row['azienda_interna_id'] : null,
        'indirizzo_id'        => $row['indirizzo_id'] !== null ? (int) $row['indirizzo_id'] : null,
        'parent_id'           => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        'soggetto_esterno_id' => $row['soggetto_esterno_id'] !== null ? (int) $row['soggetto_esterno_id'] : null,
        'predefinito'         => (bool) $row['predefinito'],
        'attivo'              => (bool) $row['attivo'],
        'note'                => (string) ($row['note'] ?? ''),
        'via'                 => (string) ($row['via'] ?? ''),
        'civico'              => (string) ($row['civico'] ?? ''),
        'cap'                 => (string) ($row['cap'] ?? ''),
        'citta'               => (string) ($row['citta'] ?? ''),
        'provincia'           => (string) ($row['provincia'] ?? ''),
        'nazione'             => (string) ($row['nazione'] ?: 'IT'),
    ];
}

/**
 * Trova o crea/aggiorna l'indirizzo (tipo='magazzino') del sito, appoggiato al
 * soggetto dell'azienda interna proprietaria — riusa le funzioni indirizzi
 * consolidate del §3.2 invece di duplicarne la logica INSERT/UPDATE qui.
 * Nessuna azienda interna scelta ⇒ nessun indirizzo gestito (torna null,
 * `magazzini.indirizzo_id` resta invariato/nullo).
 */
function _qk_magazzino_sync_indirizzo(PDO $pdo, ?int $azienda_interna_id, ?int $indirizzo_id_esistente, array $v): ?int
{
    if (!$azienda_interna_id) {
        return null;
    }

    $via = trim((string) ($v['via'] ?? ''));
    $cap = trim((string) ($v['cap'] ?? ''));
    $citta = trim((string) ($v['citta'] ?? ''));
    if ($via === '' && $cap === '' && $citta === '') {
        return $indirizzo_id_esistente; // nessun dato indirizzo inserito, lascia invariato
    }

    $st = $pdo->prepare('SELECT soggetto_id FROM aziende_interne WHERE id = ?');
    $st->execute([$azienda_interna_id]);
    $soggetto_id = (int) $st->fetchColumn();
    if (!$soggetto_id) {
        throw new \RuntimeException('Azienda interna non valida per l\'indirizzo del magazzino.');
    }

    $indirizzoV = [
        'tipo'        => 'magazzino',
        'nome_sede'   => $v['nome'] ?? '',
        'predefinito' => false,
        'via'         => $via !== '' ? $via : '—',
        'civico'      => $v['civico'] ?? '',
        'cap'         => $cap !== '' ? $cap : '00000',
        'citta'       => $citta !== '' ? $citta : '—',
        'provincia'   => $v['provincia'] ?? '',
        'nazione'     => $v['nazione'] ?: 'IT',
    ];

    if ($indirizzo_id_esistente) {
        qk_update_indirizzo($pdo, $indirizzo_id_esistente, $soggetto_id, $indirizzoV);
        return $indirizzo_id_esistente;
    }

    return qk_insert_indirizzo($pdo, $soggetto_id, $indirizzoV);
}

/**
 * Inserisce un nuovo magazzino. Se $v['predefinito'] è true, azzera il flag
 * sugli altri (garantito anche a livello DB dall'indice UNIQUE parziale, ma lo
 * facciamo esplicitamente qui per non affidarci solo al vincolo per dare un
 * errore leggibile invece di una eccezione SQL grezza).
 */
function qk_insert_magazzino(PDO $pdo, array $v): int
{
    $pdo->beginTransaction();
    try {
        if (!empty($v['predefinito'])) {
            $pdo->query("UPDATE magazzini SET predefinito = 0");
        }

        $aziendaInternaId = !empty($v['azienda_interna_id']) ? (int) $v['azienda_interna_id'] : null;
        $parentId = !empty($v['parent_id']) ? (int) $v['parent_id'] : null;
        $soggettoEsternoId = !empty($v['soggetto_esterno_id']) ? (int) $v['soggetto_esterno_id'] : null;
        $indirizzoId = _qk_magazzino_sync_indirizzo($pdo, $aziendaInternaId, null, $v);

        $st = $pdo->prepare(
            "INSERT INTO magazzini (codice, nome, tipo, azienda_interna_id, indirizzo_id, parent_id, soggetto_esterno_id, predefinito, attivo, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $st->execute([
            trim((string) $v['codice']),
            trim((string) $v['nome']),
            $v['tipo'],
            $aziendaInternaId,
            $indirizzoId,
            $parentId,
            $soggettoEsternoId,
            (int) !empty($v['predefinito']),
            (int) ($v['attivo'] ?? true),
            trim((string) ($v['note'] ?? '')) ?: null,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Se è il primo magazzino mai creato (dataset senza seed), forzalo
        // comunque a predefinito: non deve mai esistere un sistema con
        // magazzini ma senza nessuno predefinito (qk_magazzino_attivo_id ne
        // dipende). Solo se è un fisico radice: un virtuale/conto_terzi/
        // conto_lavoro non può mai diventare il predefinito di sistema (§7.2) —
        // in pratica non capita mai come primo record, perché un virtuale
        // richiede un parent_id che punti a un fisico già esistente, ma il
        // guard resta per coerenza con le altre 3 funzioni di fallback.
        $count = (int) $pdo->query("SELECT COUNT(*) FROM magazzini WHERE predefinito = 1")->fetchColumn();
        if ($count === 0 && $v['tipo'] === 'fisico' && $parentId === null) {
            $pdo->prepare("UPDATE magazzini SET predefinito = 1 WHERE id = ?")->execute([$id]);
        }

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_update_magazzino(PDO $pdo, int $id, array $v): void
{
    $pdo->beginTransaction();
    try {
        if (!empty($v['predefinito'])) {
            $pdo->prepare("UPDATE magazzini SET predefinito = 0 WHERE id != ?")->execute([$id]);
        }

        $stCur = $pdo->prepare('SELECT azienda_interna_id, indirizzo_id FROM magazzini WHERE id = ?');
        $stCur->execute([$id]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            throw new \RuntimeException('Magazzino non trovato.');
        }

        $aziendaInternaId = !empty($v['azienda_interna_id']) ? (int) $v['azienda_interna_id'] : null;
        $parentId = !empty($v['parent_id']) ? (int) $v['parent_id'] : null;
        $soggettoEsternoId = !empty($v['soggetto_esterno_id']) ? (int) $v['soggetto_esterno_id'] : null;
        // L'indirizzo esistente è riusabile solo se l'azienda interna non è cambiata
        // (altrimenti apparterrebbe al soggetto sbagliato): in quel caso se ne crea uno nuovo.
        $indirizzoEsistente = ($aziendaInternaId === (int) ($cur['azienda_interna_id'] ?? 0))
            ? ($cur['indirizzo_id'] !== null ? (int) $cur['indirizzo_id'] : null)
            : null;
        $indirizzoId = _qk_magazzino_sync_indirizzo($pdo, $aziendaInternaId, $indirizzoEsistente, $v);

        $st = $pdo->prepare(
            "UPDATE magazzini SET
                codice = ?, nome = ?, tipo = ?, azienda_interna_id = ?, indirizzo_id = ?,
                parent_id = ?, soggetto_esterno_id = ?, predefinito = ?, attivo = ?, note = ?
             WHERE id = ?"
        );
        $st->execute([
            trim((string) $v['codice']),
            trim((string) $v['nome']),
            $v['tipo'],
            $aziendaInternaId,
            $indirizzoId,
            $parentId,
            $soggettoEsternoId,
            (int) !empty($v['predefinito']),
            (int) !empty($v['attivo']),
            trim((string) ($v['note'] ?? '')) ?: null,
            $id,
        ]);

        // Non deve mai restare un sistema senza alcun magazzino predefinito
        // (es. se l'utente toglie la spunta "predefinito" dall'unico che la
        // aveva, senza assegnarla a un altro): riassegna al primo attivo fisico
        // radice (§7.2) — un virtuale/conto_terzi/conto_lavoro non può mai
        // diventare il predefinito di sistema.
        $count = (int) $pdo->query("SELECT COUNT(*) FROM magazzini WHERE predefinito = 1")->fetchColumn();
        if ($count === 0) {
            $fallbackId = $pdo->query("SELECT id FROM magazzini WHERE attivo = 1 AND tipo = 'fisico' AND parent_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
            if ($fallbackId) {
                $pdo->prepare("UPDATE magazzini SET predefinito = 1 WHERE id = ?")->execute([$fallbackId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Imposta un magazzino come predefinito (scorciatoia usata dall'anagrafica
 * per l'azione rapida "rendi predefinito" nella lista, senza aprire il form).
 */
function qk_imposta_magazzino_predefinito(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT attivo, tipo, parent_id FROM magazzini WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Magazzino non trovato.');
        }
        if (!$row['attivo']) {
            throw new \RuntimeException('Impossibile impostare come predefinito un magazzino disattivato.');
        }
        // Solo un fisico radice può essere il predefinito di sistema (§7.2):
        // una sede virtuale/conto_terzi/conto_lavoro non ha senso come sede
        // "attiva" preselezionata per nuove operazioni.
        if ($row['tipo'] !== 'fisico' || $row['parent_id'] !== null) {
            throw new \RuntimeException('Solo un magazzino fisico radice può essere impostato come predefinito.');
        }

        $pdo->query("UPDATE magazzini SET predefinito = 0");
        $pdo->prepare("UPDATE magazzini SET predefinito = 1 WHERE id = ?")->execute([$id]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Disattiva (soft-delete) un magazzino. Mai cancellazione fisica: è
 * referenziato da `giacenze`, `lotti_giacenze`, `movimenti_magazzino`
 * storici (Piano Multi-Magazzino §3.7, stesso principio già applicato a
 * qk_delete_azienda per le aziende interne — qui il guard è più stringente
 * perché un magazzino senza sostituto blocca qk_magazzino_attivo_id()).
 *
 * Guard:
 *  - non si può disattivare l'ultimo magazzino attivo rimasto nel sistema;
 *  - non si può disattivare un magazzino con giacenza residua (Piano §3.7
 *    punto 2, Revisione Gestione Magazzino e Lotti §1: senza questo
 *    controllo la merce restava a sistema ma diventava irraggiungibile dai
 *    normali flussi operativi — FEFO, selettori — senza alcun avviso).
 *    Nessun conteggio aggregato della quantità nel messaggio: i prodotti
 *    coinvolti possono avere unità di misura diverse tra loro, sommarle
 *    sarebbe fuorviante (stesso principio già adottato per il popup di
 *    annullamento ordine parzialmente ricevuto, Ciclo Acquisti REVISIONE
 *    §1.3) — si conta solo quanti prodotti hanno ancora giacenza;
 *  - se il magazzino disattivato era quello predefinito, il predefinito
 *    passa automaticamente al primo altro magazzino attivo rimasto.
 *
 * @throws \RuntimeException se è l'ultimo magazzino attivo o se ha giacenza residua
 */
function qk_disattiva_magazzino(PDO $pdo, int $id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT predefinito, attivo FROM magazzini WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return;
        }
        if (!$row['attivo']) {
            $pdo->rollBack();
            return; // già disattivato, nessuna operazione
        }

        $attiviCount = (int) $pdo->query("SELECT COUNT(*) FROM magazzini WHERE attivo = 1")->fetchColumn();
        if ($attiviCount <= 1) {
            throw new \RuntimeException('Impossibile disattivare l\'unico magazzino attivo: deve sempre esisterne almeno uno.');
        }

        $stGiac = $pdo->prepare("SELECT COUNT(*) FROM giacenze WHERE magazzino_id = ? AND quantita_disponibile > 0.0005");
        $stGiac->execute([$id]);
        $prodottiConGiacenza = (int) $stGiac->fetchColumn();
        if ($prodottiConGiacenza > 0) {
            throw new \RuntimeException(sprintf(
                'ci sono ancora %d prodott%s con giacenza residua su questo magazzino. Trasferisci lo stock su un altro magazzino prima di disattivarlo.',
                $prodottiConGiacenza,
                $prodottiConGiacenza === 1 ? 'o' : 'i'
            ));
        }

        $pdo->prepare("UPDATE magazzini SET attivo = 0, predefinito = 0 WHERE id = ?")->execute([$id]);

        if ($row['predefinito']) {
            $fallbackId = $pdo->query("SELECT id FROM magazzini WHERE attivo = 1 AND tipo = 'fisico' AND parent_id IS NULL ORDER BY id LIMIT 1")->fetchColumn();
            if ($fallbackId) {
                $pdo->prepare("UPDATE magazzini SET predefinito = 1 WHERE id = ?")->execute([$fallbackId]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Riattiva un magazzino precedentemente disattivato.
 */
function qk_riattiva_magazzino(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE magazzini SET attivo = 1 WHERE id = ?")->execute([$id]);
}
