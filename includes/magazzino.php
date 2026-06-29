<?php

declare(strict_types=1);

// =============================================================================
// magazzino.php
// Funzioni DB per il modulo Magazzino di Quack.
//
// Responsabilità:
//   - Lettura giacenze e storico movimenti
//   - Inserimento movimenti manuali con aggiornamento atomico giacenze
//   - Gestione lotti per prodotti con traccia_lotti = 1
// =============================================================================

/**
 * Restituisce la lista prodotti con giacenza corrente.
 *
 * Prodotti senza riga in `giacenze` compaiono ugualmente con qty = 0
 * (LEFT JOIN), così l'operatore vede anche gli articoli mai movimentati.
 *
 * @return list<array<string, mixed>>
 */
/** @internal */
function _qk_stock_where(string $search, string $filter): array
{
    $where = ['p.attivo = 1']; $params = [];
    if ($search !== '') {
        $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
    if ($filter === 'in_stock') {
        $where[] = 'COALESCE(g.quantita_disponibile, 0) > 0';
    } elseif ($filter === 'sottoscorta') {
        $where[] = 'p.scorta_minima > 0 AND (COALESCE(g.quantita_disponibile,0) - COALESCE(g.quantita_riservata,0)) < p.scorta_minima';
    } elseif ($filter === 'esauriti') {
        $where[] = 'COALESCE(g.quantita_disponibile, 0) <= 0';
    } elseif ($filter === 'in_arrivo') {
        $where[] = 'COALESCE(g.quantita_in_arrivo, 0) > 0';
    }
    return [$where, $params];
}

function qk_stock_count(PDO $pdo, string $search = '', string $filter = 'all'): int
{
    [$where, $params] = _qk_stock_where($search, $filter);
    $st = $pdo->prepare('SELECT COUNT(*) FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function qk_stock_list(PDO $pdo, string $search = '', string $filter = 'all', string $sort = 'nome', string $dir = 'asc', int $limit = 50, int $offset = 0): array
{
    $sortMap = [
        'nome'       => 'p.nome COLLATE NOCASE',
        'sku'        => 'p.sku_interno COLLATE NOCASE',
        'disponibile'=> 'qta_disponibile',
        'libera'     => 'qta_libera',
        'riservata'  => 'qta_riservata',
        'in_arrivo'  => 'qta_in_arrivo',
        'scorta'     => 'p.scorta_minima',
    ];
    $orderCol = $sortMap[$sort] ?? 'p.nome COLLATE NOCASE';
    $orderDir = $dir === 'desc' ? 'DESC' : 'ASC';

    [$where, $params] = _qk_stock_where($search, $filter);
    $params[] = $limit;
    $params[] = $offset;

    $sql = 'SELECT
                p.id, p.sku_interno, p.nome, p.traccia_lotti,
                u.codice AS uom,
                CASE 
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id
                        WHERE ck.kit_id = p.id
                    )
                    ELSE COALESCE(g.quantita_disponibile, 0) 
                END AS qta_disponibile,
                COALESCE(g.quantita_riservata, 0)                     AS qta_riservata,
                COALESCE(g.quantita_in_arrivo, 0)                     AS qta_in_arrivo,
                CASE 
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id
                        WHERE ck.kit_id = p.id
                    )
                    ELSE COALESCE(g.quantita_disponibile, 0) 
                END - COALESCE(g.quantita_riservata, 0)               AS qta_libera,
                COALESCE(p.scorta_minima, 0)                          AS scorta_minima,
                g.updated_at AS giacenza_updated_at
            FROM prodotti p
            LEFT JOIN giacenze g ON g.prodotto_id = p.id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderCol . ' ' . $orderDir . '
            LIMIT ? OFFSET ?';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return array_map(static function (array $r): array {
        return [
            'id'                  => (int)    $r['id'],
            'sku_interno'         => (string) ($r['sku_interno']         ?? ''),
            'nome'                => (string) ($r['nome']                ?? ''),
            'traccia_lotti'       => (bool)   $r['traccia_lotti'],
            'uom'                 => (string) ($r['uom']                 ?? ''),
            'qta_disponibile'     => (float)  $r['qta_disponibile'],
            'qta_riservata'       => (float)  $r['qta_riservata'],
            'qta_in_arrivo'       => (float)  $r['qta_in_arrivo'],
            'qta_libera'          => (float)  $r['qta_libera'],
            'scorta_minima'       => (float)  $r['scorta_minima'],
            'sottoscorta'         => (float)  $r['qta_libera'] < (float) $r['scorta_minima']
                                        && (float) $r['scorta_minima'] > 0,
            'giacenza_updated_at' => (string) ($r['giacenza_updated_at'] ?? ''),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Restituisce i dati essenziali di un prodotto per il form movimento:
 * nome, UoM, traccia_lotti, giacenza corrente.
 */
function qk_prodotto_stock_info(PDO $pdo, int $prodotto_id): ?array
{
    $sql = 'SELECT
                p.id, p.nome, p.sku_interno, p.traccia_lotti, p.tipo,
                u.codice  AS uom, u.id AS uom_id,
                CASE 
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id
                        WHERE ck.kit_id = p.id
                    )
                    ELSE COALESCE(g.quantita_disponibile, 0) 
                END AS qta_disponibile
            FROM prodotti p
            LEFT JOIN giacenze g ON g.prodotto_id = p.id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE p.id = ? AND p.attivo = 1
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$prodotto_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    return [
        'id'              => (int)   $row['id'],
        'nome'            => (string)$row['nome'],
        'sku_interno'     => (string)$row['sku_interno'],
        'tipo'            => (string)$row['tipo'],
        'traccia_lotti'   => (bool)  $row['traccia_lotti'],
        'uom'             => (string)($row['uom'] ?? ''),
        'qta_disponibile' => (float) $row['qta_disponibile'],
    ];
}

/**
 * Restituisce i lotti disponibili (quantita_disponibile > 0) per un prodotto.
 *
 * @return list<array<string, mixed>>
 */
/**
 * @param bool $solo_disponibili  true = solo lotti con qty > 0 (FEFO per scarico);
 *                                false = tutti i lotti del prodotto (per rettifica / carico su esistente)
 * @return list<array<string, mixed>>
 */
function qk_lotti_by_prodotto(PDO $pdo, int $prodotto_id, bool $solo_disponibili = false): array
{
    $cond = $solo_disponibili ? 'AND quantita_disponibile > 0' : '';
    $st   = $pdo->prepare(
        "SELECT id,
                COALESCE(codice_lotto_interno, codice_lotto)  AS codice_lotto_interno,
                COALESCE(codice_lotto_fornitore, '')          AS codice_lotto_fornitore,
                data_scadenza,
                quantita_disponibile
           FROM lotti
          WHERE prodotto_id = ? $cond
          ORDER BY data_scadenza ASC NULLS LAST, created_at ASC"
    );
    $st->execute([$prodotto_id]);

    return array_map(static function (array $r): array {
        return [
            'id'                     => (int)    $r['id'],
            'codice_lotto_interno'   => (string) $r['codice_lotto_interno'],
            'codice_lotto_fornitore' => (string) $r['codice_lotto_fornitore'],
            'data_scadenza'          => (string) ($r['data_scadenza'] ?? ''),
            'quantita_disponibile'   => (float)  $r['quantita_disponibile'],
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Crea un nuovo lotto manualmente (usato nel form movimento quando tipo=carico).
 *
 * Campi obbligatori nel DB (NOT NULL senza default):
 *   codice_lotto, prodotto_id, quantita_ricevuta, quantita_disponibile, creato_da
 *
 * @param array{
 *   codice_lotto_interno: string,
 *   codice_lotto_fornitore: string,
 *   prodotto_id: int,
 *   data_scadenza: ?string,
 *   data_arrivo: ?string,
 *   quantita_ricevuta: float,
 *   quantita_disponibile: float,
 * } $v
 * @return int  id del lotto creato
 */
function qk_insert_lotto_manuale(PDO $pdo, array $v, int $utente_id): int
{
    $codiceInterno   = trim($v['codice_lotto_interno']);
    $codiceFornitore = trim($v['codice_lotto_fornitore']) !== ''
        ? trim($v['codice_lotto_fornitore'])
        : null;

    $pdo->prepare(
        'INSERT INTO lotti
             (codice_lotto, codice_lotto_interno, codice_lotto_fornitore,
              prodotto_id, origine_tipo, data_scadenza, data_arrivo,
              quantita_ricevuta, quantita_disponibile, creato_da)
         VALUES (?, ?, ?, ?, \'rettifica\', ?, ?, ?, ?, ?)'
    )->execute([
        $codiceInterno,      // codice_lotto (campo storico NOT NULL)
        $codiceInterno,      // codice_lotto_interno
        $codiceFornitore,    // codice_lotto_fornitore (nullable)
        $v['prodotto_id'],
        $v['data_scadenza']  ?? null,
        $v['data_arrivo']    ?? null,
        $v['quantita_ricevuta'],
        $v['quantita_disponibile'],
        $utente_id,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Storico movimenti con paginazione e filtri.
 *
 * @return array{rows: list<array<string, mixed>>, total: int}
 */
function qk_movements_list(
    PDO    $pdo,
    string $search      = '',
    string $tipo        = '',
    int    $prodotto_id = 0,
    int    $limit       = 50,
    int    $offset      = 0,
    string $dal         = '',
    string $al          = '',
    string $sort        = 'data',
    string $dir         = 'desc'
): array {
    if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';
    $sortMap = ['data' => 'm.created_at', 'tipo' => 'm.tipo_movimento', 'prodotto' => 'p.nome', 'qta' => 'm.quantita'];
    $orderCol = $sortMap[$sort] ?? 'm.created_at';

    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[]  = '(p.nome LIKE ? OR p.sku_interno LIKE ? OR m.note LIKE ? OR m.riferimento_esterno LIKE ?)';
        $like     = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($tipo !== '') {
        $where[]  = 'm.tipo_movimento = ?';
        $params[] = $tipo;
    }
    if ($prodotto_id > 0) {
        $where[]  = 'm.prodotto_id = ?';
        $params[] = $prodotto_id;
    }
    if ($dal !== '') { $where[] = 'DATE(m.created_at) >= ?'; $params[] = $dal; }
    if ($al  !== '') { $where[] = 'DATE(m.created_at) <= ?'; $params[] = $al; }

    $whereStr = implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) FROM movimenti_magazzino m
                 JOIN prodotti p ON p.id = m.prodotto_id
                 WHERE ' . $whereStr;
    $stCount = $pdo->prepare($countSql);
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    $sql = 'SELECT
                m.id,
                m.tipo_movimento,
                m.quantita,
                m.canale_origine,
                m.documento_tipo,
                m.documento_id,
                m.riferimento_esterno,
                m.note,
                m.created_at,
                p.id   AS prodotto_id,
                p.nome AS prodotto_nome,
                p.sku_interno,
                u.codice  AS uom,
                l.codice_lotto_interno,
                ut.username AS creato_da_username
            FROM movimenti_magazzino m
            JOIN prodotti p ON p.id = m.prodotto_id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            LEFT JOIN lotti l ON l.id = m.lotto_id
            LEFT JOIN utenti ut ON ut.id = m.creato_da
            WHERE ' . $whereStr . '
            ORDER BY ' . $orderCol . ' ' . $dir . ', m.id ' . $dir . '
            LIMIT ? OFFSET ?';

    $stRows = $pdo->prepare($sql);
    $stRows->execute([...$params, $limit, $offset]);

    $rows = array_map(static function (array $r): array {
        // Il segno convenzionale: carico/reso_fornitore/svincolo = positivo; gli altri = negativo
        $positivi = ['carico', 'reso_fornitore', 'svincolo'];
        $segno    = in_array($r['tipo_movimento'], $positivi, true) ? '+' : '-';
        // La rettifica è neutra (mostreremo il delta calcolato)
        if ($r['tipo_movimento'] === 'rettifica') $segno = '±';

        return [
            'id'                    => (int)    $r['id'],
            'tipo_movimento'        => (string) $r['tipo_movimento'],
            'quantita'              => (float)  $r['quantita'],
            'segno'                 => $segno,
            'canale_origine'        => (string) ($r['canale_origine']         ?? ''),
            'documento_tipo'        => (string) ($r['documento_tipo']         ?? ''),
            'documento_id'          => $r['documento_id'] ? (int) $r['documento_id'] : null,
            'riferimento_esterno'   => (string) ($r['riferimento_esterno']    ?? ''),
            'note'                  => (string) ($r['note']                   ?? ''),
            'created_at'            => (string) $r['created_at'],
            'prodotto_id'           => (int)    $r['prodotto_id'],
            'prodotto_nome'         => (string) $r['prodotto_nome'],
            'sku_interno'           => (string) $r['sku_interno'],
            'uom'                   => (string) ($r['uom']                    ?? ''),
            'codice_lotto_interno'  => (string) ($r['codice_lotto_interno']   ?? ''),
            'creato_da_username'    => (string) ($r['creato_da_username']      ?? ''),
        ];
    }, $stRows->fetchAll(PDO::FETCH_ASSOC));

    return ['rows' => $rows, 'total' => $total];
}

/**
 * Inserisce un movimento manuale e aggiorna atomicamente le giacenze.
 *
 * Tipi supportati dal form manuale:
 *   carico    → giacenza += quantita
 *   scarico   → giacenza -= quantita  (errore se risultato < 0 e non rettifica)
 *   rettifica → l'utente fornisce la nuova giacenza assoluta;
 *               il sistema calcola delta = nuova - corrente,
 *               salva il delta assoluto come quantita nel movimento.
 *
 * @param array{
 *   tipo_movimento: string,
 *   prodotto_id: int,
 *   quantita: float,           per rettifica = nuova giacenza assoluta
 *   lotto_id: ?int,
 *   note: string,
 *   riferimento_esterno: string,
 * } $v
 * @param int $utente_id  id dell'utente loggato
 * @return int  id del movimento creato
 * @throws RuntimeException  se la giacenza risultante sarebbe negativa (solo scarico)
 */
function qk_insert_movimento_manuale(PDO $pdo, array $v, int $utente_id): int
{
    $tipiValidi = ['carico', 'scarico', 'rettifica'];
    if (!in_array($v['tipo_movimento'], $tipiValidi, true)) {
        throw new \InvalidArgumentException('Tipo movimento non valido.');
    }

    $pdo->beginTransaction();
    try {
        // 0. Se è un carico con NUOVO lotto, crea prima il lotto nella stessa transazione
        if ($v['tipo_movimento'] === 'carico' && isset($v['nuovo_lotto'])) {
            $nl = $v['nuovo_lotto'];
            $v['lotto_id'] = qk_insert_lotto_manuale($pdo, [
                'codice_lotto_interno'   => $nl['codice_interno'],
                'codice_lotto_fornitore' => $nl['codice_fornitore'] ?? '',
                'prodotto_id'            => $v['prodotto_id'],
                'data_scadenza'          => ($nl['data_scadenza']  ?? '') !== '' ? $nl['data_scadenza']  : null,
                'data_arrivo'            => ($nl['data_arrivo']    ?? '') !== '' ? $nl['data_arrivo']    : null,
                'quantita_ricevuta'      => $v['quantita'],
                'quantita_disponibile'   => $v['quantita'], // verrà aggiornata nel passo 4
            ], $utente_id);
        }

        // 1. Leggi giacenza corrente (lock pessimistico in SQLite via transazione)
        $stGiac = $pdo->prepare(
            'SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? LIMIT 1'
        );
        $stGiac->execute([$v['prodotto_id']]);
        $giac = $stGiac->fetch(PDO::FETCH_ASSOC);

        $qtaCorrente = $giac ? (float) $giac['quantita_disponibile'] : 0.0;

        // 2. Calcola nuova giacenza e quantita da salvare nel movimento
        $tipo = $v['tipo_movimento'];

        if ($tipo === 'carico') {
            $qtaNuova      = $qtaCorrente + $v['quantita'];
            $qtaMovimento  = $v['quantita'];

        } elseif ($tipo === 'scarico') {
            $qtaNuova     = $qtaCorrente - $v['quantita'];
            $qtaMovimento = $v['quantita'];
            if ($qtaNuova < 0) {
                throw new \RuntimeException(
                    sprintf(
                        'Giacenza insufficiente: disponibile %.3f, richiesto %.3f.',
                        $qtaCorrente,
                        $v['quantita']
                    )
                );
            }

        } else {
            // rettifica: l'utente ha inserito la nuova giacenza assoluta
            $qtaNuova     = $v['quantita'];
            $qtaMovimento = abs($qtaNuova - $qtaCorrente);
            // Se la rettifica non cambia nulla, lo consentiamo comunque (potrebbe servire per logging)
        }

        // 3. Upsert giacenze
        if ($giac) {
            $pdo->prepare(
                'UPDATE giacenze SET quantita_disponibile = ?, updated_at = datetime(\'now\') WHERE prodotto_id = ?'
            )->execute([$qtaNuova, $v['prodotto_id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO giacenze (prodotto_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, updated_at)
                 VALUES (?, ?, 0, 0, datetime(\'now\'))'
            )->execute([$v['prodotto_id'], $qtaNuova]);
        }

        // 4. Aggiorna quantita_disponibile del lotto se tracciato
        if (!empty($v['lotto_id'])) {
            if ($tipo === 'carico') {
                $pdo->prepare(
                    'UPDATE lotti SET quantita_disponibile = quantita_disponibile + ? WHERE id = ?'
                )->execute([$qtaMovimento, $v['lotto_id']]);
            } elseif ($tipo === 'scarico') {
                $pdo->prepare(
                    'UPDATE lotti SET quantita_disponibile = quantita_disponibile - ? WHERE id = ?'
                )->execute([$qtaMovimento, $v['lotto_id']]);
            } else {
                // rettifica lotto: imposta la nuova giacenza assoluta del lotto
                $pdo->prepare(
                    'UPDATE lotti SET quantita_disponibile = ? WHERE id = ?'
                )->execute([$qtaNuova, $v['lotto_id']]);
            }
        }

        // 5. Inserisci il movimento
        $stMov = $pdo->prepare(
            'INSERT INTO movimenti_magazzino
                (prodotto_id, lotto_id, tipo_movimento, quantita,
                 documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
             VALUES
                (?, ?, ?, ?,
                 ?, ?, \'manuale\', ?, ?, ?, datetime(\'now\'))'
        );
        $stMov->execute([
            $v['prodotto_id'],
            $v['lotto_id'] ?? null,
            $tipo,
            $qtaMovimento,
            $v['documento_tipo'] !== '' ? $v['documento_tipo'] : null,
            !empty($v['documento_id']) ? (int) $v['documento_id'] : null,
            $v['riferimento_esterno'] !== '' ? $v['riferimento_esterno'] : null,
            $v['note'] !== '' ? $v['note'] : null,
            $utente_id,
        ]);

        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Ricerca prodotti per la select2/autocomplete del form movimento.
 *
 * @return list<array{id: int, sku_interno: string, nome: string, traccia_lotti: bool, uom: string}>
 */
function qk_prodotti_search_stock(PDO $pdo, string $q, int $limit = 20): array
{
    $like = '%' . $q . '%';
    $st   = $pdo->prepare(
        'SELECT p.id, p.sku_interno, p.nome, p.traccia_lotti, u.codice  AS uom
           FROM prodotti p
           LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
          WHERE p.attivo = 1 AND (p.nome LIKE ? OR p.sku_interno LIKE ?)
          ORDER BY p.nome COLLATE NOCASE
          LIMIT ?'
    );
    $st->execute([$like, $like, $limit]);

    return array_map(static function (array $r): array {
        return [
            'id'            => (int)    $r['id'],
            'sku_interno'   => (string) $r['sku_interno'],
            'nome'          => (string) $r['nome'],
            'traccia_lotti' => (bool)   $r['traccia_lotti'],
            'uom'           => (string) ($r['uom'] ?? ''),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}
