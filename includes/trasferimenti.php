<?php

declare(strict_types=1);

require_once __DIR__ . '/magazzino.php';
require_once __DIR__ . '/functions.php';

// =============================================================================
// trasferimenti.php
// Trasferimenti interni tra magazzini come documento di prima classe (Piano
// Multi-Magazzino §3.4, §4.2). Ciclo di vita:
//   bozza -> in_transito (qk_trasferimento_registra_partenza: scarica l'origine)
//         -> completato  (qk_conferma_arrivo_trasferimento: carica la destinazione,
//                          gestendo eventuali discrepanze come rettifica)
//   bozza | in_transito -> annullato (qk_annulla_trasferimento; da in_transito
//                          restituisce la merce già scaricata all'origine)
// Ogni movimento generato usa tipo_movimento 'carico'/'scarico' (non
// 'trasferimento', pur essendo un valore valido nell'enum) in modo da restare
// coerente con la convenzione di segno già usata da qk_movements_list();
// il collegamento al documento avviene via documento_tipo='trasferimento' +
// documento_id.
// =============================================================================

function qk_next_numero_trasferimento(PDO $pdo): string
{
    return qk_next_sequence_number($pdo, 'trasferimento');
}

function qk_trasferimenti_count(PDO $pdo, string $search = '', string $stato = ''): int
{
    [$where, $params] = _qk_trasferimenti_where($search, $stato);
    $sql = "SELECT COUNT(*) FROM trasferimenti_magazzino t
            JOIN magazzini mo ON mo.id = t.magazzino_origine_id
            JOIN magazzini md ON md.id = t.magazzino_destinazione_id
            WHERE " . implode(' AND ', $where);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/** @internal */
function _qk_trasferimenti_where(string $search, string $stato): array
{
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(t.numero LIKE ? OR mo.nome LIKE ? OR md.nome LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    if ($stato !== '') {
        $where[] = 't.stato = ?';
        $params[] = $stato;
    }
    return [$where, $params];
}

function qk_trasferimenti_list(
    PDO $pdo,
    string $search = '',
    string $stato = '',
    string $sort = 'data',
    string $dir = 'desc',
    int $limit = 50,
    int $offset = 0
): array {
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = 'desc';
    $sortMap = ['data' => 't.created_at', 'numero' => 't.numero', 'stato' => 't.stato'];
    $orderCol = $sortMap[$sort] ?? 't.created_at';

    [$where, $params] = _qk_trasferimenti_where($search, $stato);

    $sql = "SELECT t.id, t.numero, t.stato, t.data_richiesta, t.data_partenza, t.data_arrivo,
                   t.created_at,
                   mo.nome AS magazzino_origine_nome, md.nome AS magazzino_destinazione_nome,
                   (SELECT COUNT(*) FROM trasferimenti_magazzino_righe r WHERE r.trasferimento_id = t.id) AS n_righe
              FROM trasferimenti_magazzino t
              JOIN magazzini mo ON mo.id = t.magazzino_origine_id
              JOIN magazzini md ON md.id = t.magazzino_destinazione_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY $orderCol $dir, t.id $dir
             LIMIT ? OFFSET ?";
    $st = $pdo->prepare($sql);
    $st->execute([...$params, $limit, $offset]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dettaglio completo di un trasferimento: testata + righe con nomi
 * prodotto/lotto/magazzini risolti per la UI.
 */
function qk_trasferimento_detail(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT t.*, mo.nome AS magazzino_origine_nome, md.nome AS magazzino_destinazione_nome,
                ut.username AS creato_da_username
           FROM trasferimenti_magazzino t
           JOIN magazzini mo ON mo.id = t.magazzino_origine_id
           JOIN magazzini md ON md.id = t.magazzino_destinazione_id
      LEFT JOIN utenti ut ON ut.id = t.creato_da
          WHERE t.id = ?"
    );
    $st->execute([$id]);
    $testata = $st->fetch(PDO::FETCH_ASSOC);
    if (!$testata) return null;

    $stRighe = $pdo->prepare(
        "SELECT r.*, p.nome AS prodotto_nome, p.sku_interno, p.traccia_lotti,
                COALESCE(l.codice_lotto_interno, l.codice_lotto) AS codice_lotto,
                u.codice AS uom
           FROM trasferimenti_magazzino_righe r
           JOIN prodotti p ON p.id = r.prodotto_id
      LEFT JOIN lotti l ON l.id = r.lotto_id
      LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
          WHERE r.trasferimento_id = ?
          ORDER BY r.id"
    );
    $stRighe->execute([$id]);

    return [
        'testata' => $testata,
        'righe'   => $stRighe->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Crea un nuovo trasferimento in stato 'bozza'. Nessun movimento di giacenza
 * viene registrato a questo stadio: solo alla partenza (§4.2).
 *
 * @param array $header  ['magazzino_origine_id','magazzino_destinazione_id','data_richiesta','note']
 * @param array $righe   lista di ['prodotto_id','lotto_id'(opzionale),'quantita_richiesta']
 * @throws \RuntimeException se origine == destinazione o righe vuote/non valide
 */
function qk_insert_trasferimento(PDO $pdo, array $header, array $righe, int $utente_id): int
{
    $origineId = (int) ($header['magazzino_origine_id'] ?? 0);
    $destinazioneId = (int) ($header['magazzino_destinazione_id'] ?? 0);

    if (!$origineId || !$destinazioneId) {
        throw new \RuntimeException('Magazzino origine e destinazione sono obbligatori.');
    }
    if ($origineId === $destinazioneId) {
        throw new \RuntimeException('Il magazzino di origine e di destinazione non possono coincidere.');
    }
    $righeValide = array_values(array_filter($righe, static function (array $r): bool {
        return !empty($r['prodotto_id']) && (float) ($r['quantita_richiesta'] ?? 0) > 0;
    }));
    if (empty($righeValide)) {
        throw new \RuntimeException('Inserisci almeno una riga con prodotto e quantità valida.');
    }

    $pdo->beginTransaction();
    try {
        $numero = qk_next_numero_trasferimento($pdo);

        $st = $pdo->prepare(
            "INSERT INTO trasferimenti_magazzino
                (numero, magazzino_origine_id, magazzino_destinazione_id, stato,
                 data_richiesta, note, creato_da, created_at)
             VALUES (?, ?, ?, 'bozza', ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $st->execute([
            $numero,
            $origineId,
            $destinazioneId,
            $header['data_richiesta'] !== '' ? $header['data_richiesta'] : date('Y-m-d'),
            trim((string) ($header['note'] ?? '')) ?: null,
            $utente_id,
        ]);
        $trasferimentoId = (int) $pdo->lastInsertId();

        $stRiga = $pdo->prepare(
            "INSERT INTO trasferimenti_magazzino_righe (trasferimento_id, prodotto_id, lotto_id, quantita_richiesta)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($righeValide as $r) {
            $stRiga->execute([
                $trasferimentoId,
                (int) $r['prodotto_id'],
                !empty($r['lotto_id']) ? (int) $r['lotto_id'] : null,
                (float) $r['quantita_richiesta'],
            ]);
        }

        $pdo->commit();
        return $trasferimentoId;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Registra la partenza (bozza -> in_transito): scarica la giacenza/lotti_giacenze
 * dell'origine per ciascuna riga e genera i movimenti di magazzino corrispondenti.
 * Fallisce interamente (rollback) se anche una sola riga non ha disponibilità
 * sufficiente nell'origine — nessuna partenza parziale.
 */
function qk_trasferimento_registra_partenza(PDO $pdo, int $id, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trasferimenti_magazzino WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new \RuntimeException('Trasferimento non trovato.');
        if ($t['stato'] !== 'bozza') {
            throw new \RuntimeException("Solo un trasferimento in stato 'bozza' può essere avviato.");
        }

        $stRighe = $pdo->prepare('SELECT * FROM trasferimenti_magazzino_righe WHERE trasferimento_id = ?');
        $stRighe->execute([$id]);
        $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);
        if (empty($righe)) {
            throw new \RuntimeException('Il trasferimento non ha righe da spedire.');
        }

        $origineId = (int) $t['magazzino_origine_id'];

        foreach ($righe as $r) {
            $qta = (float) $r['quantita_richiesta'];
            $prodottoId = (int) $r['prodotto_id'];
            $lottoId = $r['lotto_id'] ? (int) $r['lotto_id'] : null;

            _qk_trasferimento_scarica_origine($pdo, $prodottoId, $lottoId, $origineId, $qta);

            $pdo->prepare(
                "INSERT INTO movimenti_magazzino
                    (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita,
                     documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
                 VALUES (?, ?, ?, 'scarico', ?, 'trasferimento', ?, 'manuale', ?, ?, ?, CURRENT_TIMESTAMP)"
            )->execute([
                $prodottoId, $lottoId, $origineId, $qta,
                $id, $t['numero'], 'Partenza trasferimento ' . $t['numero'], $utente_id,
            ]);
        }

        $pdo->prepare("UPDATE trasferimenti_magazzino SET stato = 'in_transito', data_partenza = DATE('now') WHERE id = ?")
            ->execute([$id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @internal Scarica prodotto/lotto dal magazzino di origine, con controllo disponibilità. */
function _qk_trasferimento_scarica_origine(PDO $pdo, int $prodottoId, ?int $lottoId, int $magazzinoId, float $qta): void
{
    // 1. Giacenza aggregata (prodotto, magazzino)
    $stGiac = $pdo->prepare('SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
    $stGiac->execute([$prodottoId, $magazzinoId]);
    $giac = $stGiac->fetch(PDO::FETCH_ASSOC);
    $qtaCorrente = $giac ? (float) $giac['quantita_disponibile'] : 0.0;

    if ($qtaCorrente < $qta) {
        throw new \RuntimeException(sprintf(
            'Giacenza insufficiente nel magazzino di origine per il prodotto #%d: disponibile %.3f, richiesto %.3f.',
            $prodottoId, $qtaCorrente, $qta
        ));
    }

    if ($giac) {
        $pdo->prepare('UPDATE giacenze SET quantita_disponibile = quantita_disponibile - ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$qta, $giac['id']]);
    }

    // 2. Se tracciato a lotto, decrementa anche lotti_giacenze per (lotto, magazzino)
    if ($lottoId) {
        $stLg = $pdo->prepare('SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ? AND magazzino_id = ?');
        $stLg->execute([$lottoId, $magazzinoId]);
        $qtaLotto = $stLg->fetchColumn();
        $qtaLottoCorrente = $qtaLotto !== false ? (float) $qtaLotto : 0.0;

        if ($qtaLottoCorrente < $qta) {
            throw new \RuntimeException(sprintf(
                'Giacenza insufficiente nel lotto selezionato nel magazzino di origine: disponibile %.3f, richiesto %.3f.',
                $qtaLottoCorrente, $qta
            ));
        }

        $pdo->prepare(
            "INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
             VALUES (?, ?, 0, CURRENT_TIMESTAMP)
             ON CONFLICT(lotto_id, magazzino_id) DO UPDATE
                 SET quantita_disponibile = quantita_disponibile - ?,
                     updated_at = CURRENT_TIMESTAMP"
        )->execute([$lottoId, $magazzinoId, $qta]);

        // Il legacy lotti.quantita_disponibile non viene più decrementato qui:
        // dalla migrazione §3.8 la fonte di verità per sede è lotti_giacenze.
    }
}

/** @internal Carica prodotto/lotto nel magazzino di destinazione (upsert). */
function _qk_trasferimento_carica_destinazione(PDO $pdo, int $prodottoId, ?int $lottoId, int $magazzinoId, float $qta): void
{
    if ($qta <= 0) return;

    $stGiac = $pdo->prepare('SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?');
    $stGiac->execute([$prodottoId, $magazzinoId]);
    $giac = $stGiac->fetch(PDO::FETCH_ASSOC);

    if ($giac) {
        $pdo->prepare('UPDATE giacenze SET quantita_disponibile = quantita_disponibile + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$qta, $giac['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
             VALUES (?, ?, ?, 0, 0, 0, CURRENT_TIMESTAMP)"
        )->execute([$prodottoId, $magazzinoId, $qta]);
    }

    if ($lottoId) {
        $pdo->prepare(
            "INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(lotto_id, magazzino_id) DO UPDATE
                 SET quantita_disponibile = quantita_disponibile + ?,
                     updated_at = CURRENT_TIMESTAMP"
        )->execute([$lottoId, $magazzinoId, $qta, $qta]);
    }
}

/**
 * Conferma l'arrivo (in_transito -> completato). Per ogni riga, la quantità
 * arrivata è modificabile rispetto a quella richiesta (rottura, errore di
 * conta, Piano §4.2): la differenza in meno genera una rettifica a
 * destinazione con nota, non un blocco dell'operazione.
 *
 * @param array<int,float>  $quantitaArrivata  riga_id => quantità arrivata (default: quantita_richiesta se assente)
 * @param array<int,string> $noteDiscrepanza   riga_id => nota, usata solo se la quantità differisce
 */
function qk_conferma_arrivo_trasferimento(PDO $pdo, int $id, array $quantitaArrivata, array $noteDiscrepanza, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trasferimenti_magazzino WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new \RuntimeException('Trasferimento non trovato.');
        if ($t['stato'] !== 'in_transito') {
            throw new \RuntimeException("Solo un trasferimento 'in transito' può essere confermato in arrivo.");
        }

        $destinazioneId = (int) $t['magazzino_destinazione_id'];

        $stRighe = $pdo->prepare('SELECT * FROM trasferimenti_magazzino_righe WHERE trasferimento_id = ?');
        $stRighe->execute([$id]);
        $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);

        $stUpdRiga = $pdo->prepare(
            'UPDATE trasferimenti_magazzino_righe SET quantita_arrivata = ?, note_discrepanza = ? WHERE id = ?'
        );

        foreach ($righe as $r) {
            $rigaId = (int) $r['id'];
            $prodottoId = (int) $r['prodotto_id'];
            $lottoId = $r['lotto_id'] ? (int) $r['lotto_id'] : null;
            $richiesta = (float) $r['quantita_richiesta'];
            $arrivata = array_key_exists($rigaId, $quantitaArrivata) ? (float) $quantitaArrivata[$rigaId] : $richiesta;
            if ($arrivata < 0) $arrivata = 0.0;
            $nota = trim((string) ($noteDiscrepanza[$rigaId] ?? ''));

            _qk_trasferimento_carica_destinazione($pdo, $prodottoId, $lottoId, $destinazioneId, $arrivata);

            if ($arrivata > 0) {
                $pdo->prepare(
                    "INSERT INTO movimenti_magazzino
                        (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita,
                         documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
                     VALUES (?, ?, ?, 'carico', ?, 'trasferimento', ?, 'manuale', ?, ?, ?, CURRENT_TIMESTAMP)"
                )->execute([
                    $prodottoId, $lottoId, $destinazioneId, $arrivata,
                    $id, $t['numero'], 'Arrivo trasferimento ' . $t['numero'], $utente_id,
                ]);
            }

            $delta = $richiesta - $arrivata;
            if (abs($delta) > 0.0005) {
                // Discrepanza: la quantità partita dall'origine (già scaricata alla
                // partenza) non coincide con quella arrivata. Si registra come nota
                // sulla riga; la merce mancante non viene "ritrovata" automaticamente
                // né in origine né in destinazione (persa/da verificare fisicamente).
                if ($nota === '') {
                    $nota = sprintf('Discrepanza rilevata: richiesti %.3f, arrivati %.3f.', $richiesta, $arrivata);
                }
            }

            $stUpdRiga->execute([$arrivata, $nota ?: null, $rigaId]);
        }

        $pdo->prepare("UPDATE trasferimenti_magazzino SET stato = 'completato', data_arrivo = DATE('now') WHERE id = ?")
            ->execute([$id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Annulla un trasferimento (§4.2). Da 'bozza' è un'operazione pulita (nessun
 * movimento da revertire). Da 'in_transito', la merce era già stata scaricata
 * dall'origine: l'annullamento genera un carico di ripristino sull'origine
 * stessa per ciascuna riga, così la merce non resta "persa" nel limbo.
 * Vietato da 'completato': per tornare indietro si crea un nuovo trasferimento
 * in senso opposto (mai riscrivere lo storico, stesso principio già seguito
 * altrove in Quack).
 */
function qk_annulla_trasferimento(PDO $pdo, int $id, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM trasferimenti_magazzino WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new \RuntimeException('Trasferimento non trovato.');

        if ($t['stato'] === 'completato') {
            throw new \RuntimeException("Un trasferimento già completato non può essere annullato: crea un nuovo trasferimento in senso opposto.");
        }
        if ($t['stato'] === 'annullato') {
            $pdo->rollBack();
            return; // già annullato, nessuna operazione
        }

        if ($t['stato'] === 'in_transito') {
            $origineId = (int) $t['magazzino_origine_id'];
            $stRighe = $pdo->prepare('SELECT * FROM trasferimenti_magazzino_righe WHERE trasferimento_id = ?');
            $stRighe->execute([$id]);
            foreach ($stRighe->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $qta = (float) $r['quantita_richiesta'];
                $prodottoId = (int) $r['prodotto_id'];
                $lottoId = $r['lotto_id'] ? (int) $r['lotto_id'] : null;

                _qk_trasferimento_carica_destinazione($pdo, $prodottoId, $lottoId, $origineId, $qta);

                $pdo->prepare(
                    "INSERT INTO movimenti_magazzino
                        (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita,
                         documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
                     VALUES (?, ?, ?, 'carico', ?, 'trasferimento', ?, 'manuale', ?, ?, ?, CURRENT_TIMESTAMP)"
                )->execute([
                    $prodottoId, $lottoId, $origineId, $qta,
                    $id, $t['numero'], 'Annullamento trasferimento ' . $t['numero'] . ' (ripristino origine)', $utente_id,
                ]);
            }
        }

        $pdo->prepare("UPDATE trasferimenti_magazzino SET stato = 'annullato' WHERE id = ?")->execute([$id]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
