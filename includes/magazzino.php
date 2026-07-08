<?php

declare(strict_types=1);

require_once __DIR__ . '/anagrafiche.php';

// =============================================================================
// magazzino.php
// Funzioni DB per il modulo Magazzino di Quack.
//
// Responsabilità:
//   - Lettura giacenze e storico movimenti
//   - Inserimento movimenti manuali con aggiornamento atomico giacenze
//   - Gestione lotti per prodotti con traccia_lotti = 1
//
// MULTI-MAGAZZINO (vedi docs/Gestione Magazzino e Lotti/Piano di
// Implementazione Multi-Magazzino.md): da qui in avanti `giacenze` è per
// coppia (prodotto_id, magazzino_id), non più per solo prodotto. Ogni
// funzione che legge/scrive giacenza richiede quindi un magazzino_id — le
// funzioni "di lista" (stock, dashboard) lo risolvono implicitamente sul
// magazzino attivo tramite qk_magazzino_attivo_id() finché non esiste ancora
// un selettore in UI (§3.3/§3.10, non ancora implementato): il comportamento
// visibile all'utente resta identico a prima (un solo magazzino "invisibile"),
// ma i dati sono già scoping-ati correttamente sotto al cofano.
// =============================================================================

/**
 * Risolve l'id del magazzino "attivo" da usare quando non ne viene passato uno
 * esplicito: prima $_SESSION['magazzino_attivo_id'] (impostata dal selettore
 * in topbar, §3.3/§3.10, stesso pattern di azienda_attiva_id in
 * includes/header.php), poi il magazzino marcato `predefinito = 1`, infine il
 * primo magazzino attivo per id. Cache statica per richiesta: chiamata più
 * volte nella stessa pagina non ripete la query.
 *
 * @throws \RuntimeException se non esiste alcun magazzino attivo/predefinito
 *         (dataset non ancora inizializzato con il seed di magazzini)
 */
function qk_magazzino_attivo_id(PDO $pdo): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (isset($_SESSION['magazzino_attivo_id'])) {
        $id = (int) $_SESSION['magazzino_attivo_id'];
        $st = $pdo->prepare('SELECT id FROM magazzini WHERE id = ? AND attivo = 1');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            $cache = $id;
            return $cache;
        }
    }

    $st = $pdo->query("SELECT id FROM magazzini WHERE predefinito = 1 AND attivo = 1 LIMIT 1");
    $id = $st->fetchColumn();
    if ($id === false) {
        // Fallback: nessun magazzino marcato predefinito (dataset corrotto/non
        // seedato) — prende il primo magazzino attivo per non bloccare l'app.
        $st = $pdo->query('SELECT id FROM magazzini WHERE attivo = 1 ORDER BY id LIMIT 1');
        $id = $st->fetchColumn();
    }
    if ($id === false) {
        throw new \RuntimeException(
            'Nessun magazzino attivo trovato. Verificare che esista almeno un record in `magazzini` (attivo=1).'
        );
    }

    $cache = (int) $id;
    return $cache;
}

/**
 * Frammento SQL condiviso per il criterio di sottoscorta: giacenza libera
 * (disponibile - riservata) inferiore alla soglia impostata per QUESTA coppia
 * (prodotto, magazzino) su `giacenze.scorta_minima` — unica fonte di verità
 * da quando la soglia è stata rimossa da `prodotti` (era duplicata e mai letta
 * fino ad allora, vedi Piano Multi-Magazzino §2.8/§3.5). Centralizzato qui
 * invece di essere riscritto a mano in ogni query (_qk_stock_where, dashboard,
 * WarehouseController) per evitare le 4 copie leggermente diverse che c'erano
 * prima con `prodotti.scorta_minima`.
 *
 * @param string $aliasGiacenza  alias SQL della tabella/riga giacenze nella query chiamante (es. 'g')
 */
function qk_sottoscorta_where(string $aliasGiacenza = 'g'): string
{
    return sprintf(
        '%1$s.scorta_minima > 0 AND (COALESCE(%1$s.quantita_disponibile,0) - COALESCE(%1$s.quantita_riservata,0)) < %1$s.scorta_minima',
        $aliasGiacenza
    );
}

/**
 * Restituisce la lista prodotti con giacenza corrente NEL MAGAZZINO indicato
 * (default: magazzino attivo).
 *
 * Prodotti senza riga in `giacenze` per quel magazzino compaiono ugualmente
 * con qty = 0 (LEFT JOIN), così l'operatore vede anche gli articoli mai
 * movimentati in quella sede.
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
        $where[] = qk_sottoscorta_where('g');
    } elseif ($filter === 'esauriti') {
        $where[] = 'COALESCE(g.quantita_disponibile, 0) <= 0';
    } elseif ($filter === 'in_arrivo') {
        $where[] = 'COALESCE(g.quantita_in_arrivo, 0) > 0';
    }
    return [$where, $params];
}

function qk_stock_count(PDO $pdo, string $search = '', string $filter = 'all', ?int $magazzino_id = null): int
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);
    [$where, $params] = _qk_stock_where($search, $filter);
    array_unshift($params, $magazzino_id);
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM prodotti p
         LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
         WHERE ' . implode(' AND ', $where)
    );
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function qk_stock_list(PDO $pdo, string $search = '', string $filter = 'all', string $sort = 'nome', string $dir = 'asc', int $limit = 50, int $offset = 0, ?int $magazzino_id = null): array
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    $sortMap = [
        'nome'       => 'p.nome COLLATE NOCASE',
        'sku'        => 'p.sku_interno COLLATE NOCASE',
        'disponibile'=> 'qta_disponibile',
        'libera'     => 'qta_libera',
        'riservata'  => 'qta_riservata',
        'in_arrivo'  => 'qta_in_arrivo',
        'scorta'     => 'scorta_minima',
    ];
    $orderCol = $sortMap[$sort] ?? 'p.nome COLLATE NOCASE';
    $orderDir = $dir === 'desc' ? 'DESC' : 'ASC';

    [$where, $params] = _qk_stock_where($search, $filter);
    // Il magazzino compare 3 volte nella SELECT (join giacenze principale + 2 subquery kit)
    array_unshift($params, $magazzino_id);
    $params[] = $limit;
    $params[] = $offset;

    $sql = 'SELECT
                p.id, p.sku_interno, p.nome, p.traccia_lotti,
                u.codice AS uom,
                CASE
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id AND g2.magazzino_id = ?
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
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id AND g2.magazzino_id = ?
                        WHERE ck.kit_id = p.id
                    )
                    ELSE COALESCE(g.quantita_disponibile, 0)
                END - COALESCE(g.quantita_riservata, 0)               AS qta_libera,
                COALESCE(g.scorta_minima, 0)                          AS scorta_minima,
                g.updated_at AS giacenza_updated_at
            FROM prodotti p
            LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderCol . ' ' . $orderDir . '
            LIMIT ? OFFSET ?';
    // NOTA: il magazzino_id compare 3 volte nell'SQL (2 subquery kit + join
    // principale), ma i placeholder "?" nella subquery kit vengono PRIMA di
    // quello del join principale nell'ordine di lettura SQL — quindi va
    // duplicato nei $params nell'ordine giusto. Vedi sotto.
    $paramsOrdered = [$magazzino_id, $magazzino_id, $magazzino_id, ...array_slice($params, 1)];

    $st = $pdo->prepare($sql);
    $st->execute($paramsOrdered);

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
 * nome, UoM, traccia_lotti, giacenza corrente NEL MAGAZZINO indicato
 * (default: magazzino attivo).
 */
function qk_prodotto_stock_info(PDO $pdo, int $prodotto_id, ?int $magazzino_id = null): ?array
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    $sql = 'SELECT
                p.id, p.nome, p.sku_interno, p.traccia_lotti, p.tipo,
                u.codice  AS uom, u.id AS uom_id,
                CASE
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(g2.quantita_disponibile, 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        LEFT JOIN giacenze g2 ON g2.prodotto_id = ck.componente_id AND g2.magazzino_id = ?
                        WHERE ck.kit_id = p.id
                    )
                    ELSE COALESCE(g.quantita_disponibile, 0)
                END AS qta_disponibile
            FROM prodotti p
            LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE p.id = ? AND p.attivo = 1
            LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$magazzino_id, $magazzino_id, $prodotto_id]);
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
 * Restituisce i lotti di un prodotto DISPONIBILI NEL MAGAZZINO indicato
 * (default: magazzino attivo), con la relativa quantita_disponibile IN QUELLA
 * SEDE (letta da `lotti_giacenze`, non più dal campo aggregato
 * `lotti.quantita_disponibile` — lo stesso lotto può avere quantità diverse
 * in sedi diverse, vedi Piano Multi-Magazzino §3.1).
 *
 * @param bool $solo_disponibili  true = solo lotti con qty > 0 in QUESTO magazzino (FEFO per scarico);
 *                                false = tutti i lotti del prodotto, incluse le sedi con 0 in questo
 *                                magazzino (per rettifica / carico su lotto esistente non ancora presente qui)
 * @return list<array<string, mixed>>
 */
function qk_lotti_by_prodotto(PDO $pdo, int $prodotto_id, bool $solo_disponibili = false, ?int $magazzino_id = null): array
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    $cond = $solo_disponibili ? 'AND COALESCE(lg.quantita_disponibile, 0) > 0' : '';
    $st   = $pdo->prepare(
        "SELECT l.id,
                COALESCE(l.codice_lotto_interno, l.codice_lotto)  AS codice_lotto_interno,
                COALESCE(l.codice_lotto_fornitore, '')            AS codice_lotto_fornitore,
                l.data_scadenza,
                COALESCE(lg.quantita_disponibile, 0)              AS quantita_disponibile
           FROM lotti l
           LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
          WHERE l.prodotto_id = ? $cond
          ORDER BY l.data_scadenza ASC NULLS LAST, l.created_at ASC"
    );
    $st->execute([$magazzino_id, $prodotto_id]);

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
 *   codice_lotto, prodotto_id, quantita_ricevuta, creato_da
 *
 * NOTA (Piano Multi-Magazzino §3.9): `lotti.quantita_disponibile` è stata
 * rimossa — la fonte di verità della disponibilità per lotto è ora
 * `lotti_giacenze`, per (lotto, magazzino). Il chiamante deve creare/aggiornare
 * la riga `lotti_giacenze` corrispondente subito dopo (vedi
 * qk_insert_movimento_manuale, che lo fa già).
 *
 * @param array{
 *   codice_lotto_interno: string,
 *   codice_lotto_fornitore: string,
 *   prodotto_id: int,
 *   data_scadenza: ?string,
 *   data_arrivo: ?string,
 *   quantita_ricevuta: float,
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
              quantita_ricevuta, creato_da)
         VALUES (?, ?, ?, ?, \'rettifica\', ?, ?, ?, ?)'
    )->execute([
        $codiceInterno,      // codice_lotto (campo storico NOT NULL)
        $codiceInterno,      // codice_lotto_interno
        $codiceFornitore,    // codice_lotto_fornitore (nullable)
        $v['prodotto_id'],
        $v['data_scadenza']  ?? null,
        $v['data_arrivo']    ?? null,
        $v['quantita_ricevuta'],
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
 * Inserisce un movimento manuale e aggiorna atomicamente le giacenze DEL
 * MAGAZZINO INDICATO in $v['magazzino_id'] (obbligatorio; i chiamanti che non
 * espongono ancora un selettore magazzino in UI devono passare esplicitamente
 * qk_magazzino_attivo_id($pdo)).
 *
 * Tipi supportati dal form manuale:
 *   carico    → giacenza += quantita  (in questo magazzino)
 *   scarico   → giacenza -= quantita  (errore se risultato < 0 e non rettifica)
 *   rettifica → l'utente fornisce la nuova giacenza assoluta PER QUESTO MAGAZZINO;
 *               il sistema calcola delta = nuova - corrente,
 *               salva il delta assoluto come quantita nel movimento.
 *
 * Se il prodotto traccia lotti, la quantità del lotto viene aggiornata su
 * `lotti_giacenze` (per la coppia lotto+magazzino), non più sul campo
 * aggregato `lotti.quantita_disponibile` — vedi Piano Multi-Magazzino §3.1/§3.9.
 *
 * Lock pessimistico: la lettura della giacenza corrente avviene dentro la
 * stessa transazione (SQLite: la prima scrittura promuove la transazione a
 * IMMEDIATE/EXCLUSIVE secondo necessità), per evitare lost update in caso di
 * due movimenti concorrenti sullo stesso (prodotto, magazzino) — vedi Piano
 * Multi-Magazzino §4.1.
 *
 * @param array{
 *   tipo_movimento: string,
 *   prodotto_id: int,
 *   magazzino_id: int,
 *   quantita: float,           per rettifica = nuova giacenza assoluta
 *   lotto_id: ?int,
 *   note: string,
 *   riferimento_esterno: string,
 * } $v
 * @param int $utente_id  id dell'utente loggato
 * @return int  id del movimento creato
 * @throws \InvalidArgumentException se magazzino_id manca o il tipo movimento non è valido
 * @throws \RuntimeException  se la giacenza risultante sarebbe negativa (solo scarico)
 */
function qk_insert_movimento_manuale(PDO $pdo, array $v, int $utente_id): int
{
    $tipiValidi = ['carico', 'scarico', 'rettifica'];
    if (!in_array($v['tipo_movimento'], $tipiValidi, true)) {
        throw new \InvalidArgumentException('Tipo movimento non valido.');
    }
    if (empty($v['magazzino_id'])) {
        throw new \InvalidArgumentException('magazzino_id obbligatorio per registrare un movimento.');
    }
    $magazzino_id = (int) $v['magazzino_id'];

    $pdo->beginTransaction();
    try {
        // 0. Se è un carico con NUOVO lotto, crea prima il lotto (anagrafica) nella stessa transazione
        if ($v['tipo_movimento'] === 'carico' && isset($v['nuovo_lotto'])) {
            $nl = $v['nuovo_lotto'];
            $v['lotto_id'] = qk_insert_lotto_manuale($pdo, [
                'codice_lotto_interno'   => $nl['codice_interno'],
                'codice_lotto_fornitore' => $nl['codice_fornitore'] ?? '',
                'prodotto_id'            => $v['prodotto_id'],
                'data_scadenza'          => ($nl['data_scadenza']  ?? '') !== '' ? $nl['data_scadenza']  : null,
                'data_arrivo'            => ($nl['data_arrivo']    ?? '') !== '' ? $nl['data_arrivo']    : null,
                'quantita_ricevuta'      => $v['quantita'],
            ], $utente_id);
        }

        // 1. Leggi giacenza corrente per (prodotto, magazzino) — lock pessimistico
        //    in SQLite via transazione (vedi §4.1 del piano).
        $stGiac = $pdo->prepare(
            'SELECT id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ? LIMIT 1'
        );
        $stGiac->execute([$v['prodotto_id'], $magazzino_id]);
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
                        'Giacenza insufficiente in questo magazzino: disponibile %.3f, richiesto %.3f.',
                        $qtaCorrente,
                        $v['quantita']
                    )
                );
            }

        } else {
            // rettifica: l'utente ha inserito la nuova giacenza assoluta per questo magazzino
            $qtaNuova     = $v['quantita'];
            $qtaMovimento = abs($qtaNuova - $qtaCorrente);
            // Se la rettifica non cambia nulla, lo consentiamo comunque (potrebbe servire per logging)
        }

        // 3. Upsert giacenze per (prodotto, magazzino). scorta_minima non viene
        //    toccata qui: è impostata a parte dall'operatore per la coppia
        //    (prodotto, magazzino), vedi §3.5.
        if ($giac) {
            $pdo->prepare(
                'UPDATE giacenze SET quantita_disponibile = ?, updated_at = datetime(\'now\') WHERE prodotto_id = ? AND magazzino_id = ?'
            )->execute([$qtaNuova, $v['prodotto_id'], $magazzino_id]);
        } else {
            $pdo->prepare(
                'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                 VALUES (?, ?, ?, 0, 0, 0, datetime(\'now\'))'
            )->execute([$v['prodotto_id'], $magazzino_id, $qtaNuova]);
        }

        // 4. Aggiorna la quantità del lotto IN QUESTO MAGAZZINO su lotti_giacenze
        //    (non più sul campo aggregato lotti.quantita_disponibile, legacy).
        if (!empty($v['lotto_id'])) {
            $stLg = $pdo->prepare(
                'SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ? AND magazzino_id = ?'
            );
            $stLg->execute([$v['lotto_id'], $magazzino_id]);
            $qtaLottoCorrente = $stLg->fetchColumn();
            $qtaLottoCorrente = $qtaLottoCorrente !== false ? (float) $qtaLottoCorrente : 0.0;

            if ($tipo === 'carico') {
                $qtaLottoNuova = $qtaLottoCorrente + $qtaMovimento;
            } elseif ($tipo === 'scarico') {
                $qtaLottoNuova = $qtaLottoCorrente - $qtaMovimento;
                if ($qtaLottoNuova < 0) {
                    throw new \RuntimeException('Giacenza del lotto insufficiente in questo magazzino.');
                }
            } else {
                // rettifica: la nuova giacenza assoluta del lotto in questo magazzino
                // coincide con la nuova giacenza di prodotto solo se non ci sono altri
                // lotti dello stesso prodotto in questo magazzino; qui semplicemente
                // applichiamo la stessa quantita_disponibile passata per il prodotto,
                // coerente con l'uso attuale del form (rettifica su prodotto senza lotti multipli).
                $qtaLottoNuova = $v['quantita'];
            }

            $pdo->prepare(
                'INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(lotto_id, magazzino_id) DO UPDATE SET
                     quantita_disponibile = excluded.quantita_disponibile,
                     updated_at = excluded.updated_at'
            )->execute([$v['lotto_id'], $magazzino_id, $qtaLottoNuova]);
        }

        // 5. Inserisci il movimento
        $stMov = $pdo->prepare(
            'INSERT INTO movimenti_magazzino
                (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita,
                 documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
             VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, \'manuale\', ?, ?, ?, datetime(\'now\'))'
        );
        $stMov->execute([
            $v['prodotto_id'],
            $v['lotto_id'] ?? null,
            $magazzino_id,
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

// =============================================================================
// ANAGRAFICA MAGAZZINI (Piano Multi-Magazzino §3.3, §3.8, §3.10)
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
 *  - se il magazzino disattivato era quello predefinito, il predefinito
 *    passa automaticamente al primo altro magazzino attivo rimasto.
 *
 * @throws \RuntimeException se è l'ultimo magazzino attivo
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
