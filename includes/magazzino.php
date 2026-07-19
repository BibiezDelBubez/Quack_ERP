<?php

declare(strict_types=1);

// L'anagrafica magazzini (CRUD tabella `magazzini`) è stata estratta in
// includes/magazzini_anagrafica.php (audit MVC/DRY, Revisione Gestione
// Magazzino e Lotti §6/piano refactor) — questo file resta il punto di
// ingresso primario per i 13 chiamanti esistenti nel progetto (tutti fanno
// require_once di magazzino.php), quindi richiede a cascata il file estratto
// invece di spostare l'onere sui chiamanti. Questo file NON usa più
// direttamente nulla di includes/anagrafiche.php (verificato con grep prima
// dello split: le uniche chiamate a funzioni di quel file erano dentro il
// blocco anagrafica-magazzini, ora spostato) — il require arriva solo
// tramite magazzini_anagrafica.php.
require_once __DIR__ . '/magazzini_anagrafica.php';

// =============================================================================
// magazzino.php
// Funzioni DB per il modulo Magazzino di Quack: giacenze/stock/riordino, lotti,
// movimenti magazzino, più gli helper condivisi (qk_magazzino_attivo_id,
// qk_risolvi_magazzino_filtro, qk_sottoscorta_where/having, qk_stock_counters)
// usati anche fuori dal dominio Magazzino (Vendite/Acquisti/Prodotti/Dashboard
// — qk_magazzino_attivo_id ha 18 chiamanti esterni sparsi nel progetto).
//
// Lotti e movimenti restano insieme in questo file (non separabili senza un
// require incrociato: qk_insert_movimento_manuale chiama qk_insert_lotto_manuale
// per un carico su lotto nuovo). L'anagrafica magazzini è invece in un file a
// parte (vedi sopra) perché isolabile senza dipendenze incrociate.
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
 * True se esiste un inventario fisico in bozza, per QUESTO magazzino, che
 * include il prodotto indicato tra le sue righe (Tappa 4.3, 10/07/2026).
 *
 * Granularità (magazzino, articolo) — non l'intero magazzino: un inventario
 * "a ciclo" (cycle count) può coprire solo una categoria di prodotti, e in tal
 * caso il resto del magazzino deve continuare a operare normalmente. Un
 * inventario "completo" (tutti i prodotti attivi, comportamento di default di
 * qk_inventario_prodotti_scope()) blocca di fatto l'intero magazzino, ma è
 * una conseguenza del suo scope, non una regola a parte.
 *
 * Da richiamare in ogni punto che crea un movimento di magazzino (ricezione,
 * spedizione, movimento manuale) PRIMA di applicare la modifica a giacenze:
 * se true, l'operazione va rifiutata con un errore chiaro invece di lasciare
 * che sporchi il conteggio in corso (vedi audit/scoperte_audit.md, Tappa 4.3).
 */
function qk_inventario_bloccato(PDO $pdo, int $magazzinoId, int $prodottoId): bool
{
    if ($magazzinoId <= 0 || $prodottoId <= 0) {
        return false;
    }
    $st = $pdo->prepare("
        SELECT 1
          FROM inventari i
          JOIN inventari_righe ir ON ir.inventario_id = i.id
         WHERE i.magazzino_id = ?
           AND i.stato = 'bozza'
           AND ir.prodotto_id = ?
         LIMIT 1
    ");
    $st->execute([$magazzinoId, $prodottoId]);
    return (bool) $st->fetchColumn();
}

/**
 * Riserva quantità a magazzino per una riga d'ordine, "consapevole" dei Kit.
 *
 * Un Kit non ha giacenza fisica propria: è una collezione di prodotti singoli,
 * e la sua disponibilità è calcolata dal componente più scarso (vedi le query
 * di lettura in questo stesso file, CASE WHEN p.tipo = 'kit' ...). Per
 * coerenza, anche la RISERVA deve avvenire sui componenti, non sul Kit —
 * altrimenti la riserva scritta qui (sul Kit) non viene mai liberata dallo
 * scarico alla spedizione, che decrementa invece i componenti
 * (SalesOrdersController::confirmShipment, righe ~748-756): le due operazioni
 * finirebbero per scrivere su righe di `giacenze` diverse, lasciando la
 * riserva sul Kit "fantasma" (mai liberata) e i componenti scoperti (la loro
 * `quantita_riservata` può scendere sotto zero allo scarico).
 *
 * Usata sia dalla creazione di un ordine diretto (includes/sales_orders.php)
 * sia dalla conferma preventivo→ordine (includes/sales_quotes.php), che prima
 * duplicavano questa logica in due punti separati, entrambi senza gestione Kit.
 */
function qk_riserva_stock_ordine(PDO $pdo, int $prodottoId, float $qtaStock, int $magazzinoId): void
{
    if ($prodottoId <= 0 || $qtaStock <= 0) {
        return;
    }

    $stTipo = $pdo->prepare("SELECT tipo FROM prodotti WHERE id = ?");
    $stTipo->execute([$prodottoId]);
    $tipo = $stTipo->fetchColumn();

    $righeDaRiservare = [];
    if ($tipo === 'kit') {
        $stComp = $pdo->prepare("SELECT componente_id, quantita FROM componenti_kit WHERE kit_id = ?");
        $stComp->execute([$prodottoId]);
        foreach ($stComp->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $righeDaRiservare[] = [(int)$c['componente_id'], (float)$c['quantita'] * $qtaStock];
        }
    } else {
        $righeDaRiservare[] = [$prodottoId, $qtaStock];
    }

    $stRiserva = $pdo->prepare("
        UPDATE giacenze
           SET quantita_riservata = quantita_riservata + ?
         WHERE prodotto_id = ? AND magazzino_id = ?
    ");
    $stInsert = $pdo->prepare("
        INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
        VALUES (?, ?, 0, ?, 0, 0, CURRENT_TIMESTAMP)
    ");
    foreach ($righeDaRiservare as [$compId, $qta]) {
        $stRiserva->execute([$qta, $compId, $magazzinoId]);
        if ($stRiserva->rowCount() === 0) {
            // Non esiste ancora una riga di giacenza per questo prodotto in
            // questo magazzino (prodotto/componente mai movimentato qui): la
            // creiamo a quantità disponibile 0 e riservata = qta.
            $stInsert->execute([$compId, $magazzinoId, $qta]);
        }
    }
}

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
 * Risolve il parametro `magazzino_id` ricevuto da una schermata con filtro
 * esplicito in pagina (Giacenze/Riordino/Lotti in scadenza — Fix 2/3, §8.3-8.4
 * di "Magazzino Attivo - Analisi e Alternative.md"):
 *  - `''` (non specificato) → magazzino attivo di sessione (default comodo iniziale);
 *  - `'0'` (scelta esplicita) → 0, "Tutti i magazzini" (aggregato);
 *  - un intero → quella sede, se attiva; altrimenti si ricade sul default di sessione.
 *
 * Estratta per eliminare 3 copie quasi identiche dello stesso blocco in
 * `WarehouseController` (`stock()`, `reorder()`, `scadenze()`) — audit MVC/DRY,
 * Revisione Gestione Magazzino e Lotti.
 */
function qk_risolvi_magazzino_filtro(PDO $pdo, string $magazzinoParam): int
{
    if ($magazzinoParam === '') {
        return qk_magazzino_attivo_id($pdo);
    }
    if ($magazzinoParam === '0') {
        return 0;
    }
    $candidato = (int) $magazzinoParam;
    $st = $pdo->prepare('SELECT id FROM magazzini WHERE id = ? AND attivo = 1');
    $st->execute([$candidato]);
    return $st->fetchColumn() ? $candidato : qk_magazzino_attivo_id($pdo);
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
 * Frammento HAVING equivalente a qk_sottoscorta_where(), ma per la modalità
 * aggregata "tutti i magazzini" (Magazzino Attivo - Analisi e Alternative.md,
 * §8.0-8.1): stessa condizione, riscritta su SUM(...) invece che sulla singola
 * riga giacenze — in aggregato ogni prodotto ha più righe (una per sede)
 * raggruppate in una sola con GROUP BY p.id.
 * Condiviso tra qk_stock_list()/qk_stock_count() (Fix 2, §8.3) e la query di
 * WarehouseController::reorder() (Fix 3, §8.4), per non duplicare la formula.
 *
 * @param string $aliasGiacenza  alias SQL della tabella/riga giacenze nella query chiamante (es. 'g')
 */
function qk_sottoscorta_having(string $aliasGiacenza = 'g'): string
{
    return sprintf(
        'SUM(COALESCE(%1$s.scorta_minima,0)) > 0
         AND (SUM(COALESCE(%1$s.quantita_disponibile,0)) - SUM(COALESCE(%1$s.quantita_riservata,0))) < SUM(COALESCE(%1$s.scorta_minima,0))',
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

/**
 * Variante di _qk_stock_where() per gli altri filtri (in_stock/esauriti/
 * in_arrivo) in modalità aggregata "tutti i magazzini" (§8.1). Usata in
 * HAVING dopo un GROUP BY p.id, invece che in WHERE sulla riga singola.
 */
function _qk_stock_having(string $filter): string
{
    return match ($filter) {
        'in_stock'    => 'SUM(COALESCE(g.quantita_disponibile,0)) > 0',
        'sottoscorta' => qk_sottoscorta_having('g'),
        'esauriti'    => 'SUM(COALESCE(g.quantita_disponibile,0)) <= 0',
        'in_arrivo'   => 'SUM(COALESCE(g.quantita_in_arrivo,0)) > 0',
        default       => '1=1',
    };
}

/**
 * Contatori per le pill "Sottoscorta"/"Esauriti" della schermata Giacenze:
 * rispettano lo stesso magazzino scelto in pagina (0 = aggregato su tutte le
 * sedi). Estratta dalla view `stock.php` (audit MVC/DRY, Revisione Gestione
 * Magazzino e Lotti): una view non deve interrogare il database direttamente.
 *
 * @return array{sottoscorta: int, esauriti: int}
 */
function qk_stock_counters(PDO $pdo, int $magazzino_id): array
{
    if ($magazzino_id === 0) {
        $sottoscorta = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT p.id FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id
                 WHERE p.attivo = 1 GROUP BY p.id HAVING " . qk_sottoscorta_having('g') . "
             ) t"
        )->fetchColumn();
        $esauriti = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT p.id FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id
                 WHERE p.attivo = 1 GROUP BY p.id HAVING SUM(COALESCE(g.quantita_disponibile,0)) <= 0
             ) t"
        )->fetchColumn();
        return ['sottoscorta' => $sottoscorta, 'esauriti' => $esauriti];
    }

    $stSc = $pdo->prepare(
        "SELECT COUNT(*) FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
          WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g')
    );
    $stSc->execute([$magazzino_id]);

    $stEs = $pdo->prepare(
        "SELECT COUNT(*) FROM prodotti p LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
          WHERE p.attivo = 1 AND COALESCE(g.quantita_disponibile, 0) <= 0"
    );
    $stEs->execute([$magazzino_id]);

    return ['sottoscorta' => (int) $stSc->fetchColumn(), 'esauriti' => (int) $stEs->fetchColumn()];
}

/**
 * Valore economico corrente del magazzino: SUM(giacenza * costo_acquisto),
 * su TUTTE le sedi (KPI aziendale complessivo, non per singolo magazzino).
 * Da v_costo_acquisto_prodotti (fix "Listino Fantasma",
 * audit/MASTER_AUDIT_REPORT.md — vedi commento esteso in db/init_sqlite.sql
 * §14b). Estratto da WarehouseController::dashboard() (Piano refactor
 * MVC/DRY, Fase 3, microtappa 3.1).
 */
function qk_valore_magazzino(PDO $pdo): float
{
    return (float) $pdo->query("
        SELECT SUM(g.quantita_disponibile * COALESCE(vc.costo_acquisto, 0.0))
        FROM giacenze g
        LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = g.prodotto_id
    ")->fetchColumn();
}

/**
 * Numero di ordini di acquisto ancora da ricevere (in tutto o in parte) —
 * KPI aziendale complessivo, non scoping-ato per magazzino (un ordine può
 * consegnare su sedi diverse). Estratto da WarehouseController::dashboard()
 * (Fase 3, microtappa 3.1).
 */
function qk_ordini_acquisto_in_arrivo_count(PDO $pdo): int
{
    return (int) $pdo->query("
        SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')
    ")->fetchColumn();
}

/**
 * Numero di spedizioni non ancora consegnate — KPI aziendale complessivo,
 * stesso motivo di qk_ordini_acquisto_in_arrivo_count() (Fase 3, microtappa 3.1).
 */
function qk_spedizioni_in_partenza_count(PDO $pdo): int
{
    return (int) $pdo->query("
        SELECT COUNT(*) FROM spedizioni WHERE stato IN ('preparazione', 'spedita')
    ")->fetchColumn();
}

/**
 * Ultimi movimenti di magazzino registrati (tutte le sedi), per la dashboard
 * KPI. Estratto da WarehouseController::dashboard() (Fase 3, microtappa 3.1).
 */
function qk_movimenti_recenti(PDO $pdo, int $limit = 5): array
{
    $stmt = $pdo->prepare("
        SELECT m.created_at, m.tipo_movimento, m.quantita,
               p.nome AS prodotto_nome, u.codice AS uom
        FROM movimenti_magazzino m
        JOIN prodotti p ON p.id = m.prodotto_id
        LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Prodotti più vicini/sotto la soglia di sottoscorta, NEL MAGAZZINO indicato,
 * ordinati dal più critico. Estratto da WarehouseController::dashboard()
 * (Fase 3, microtappa 3.1) — stessa formula di qk_sottoscorta_where(),
 * riusata invece di riscritta inline.
 */
function qk_prodotti_vicino_sottoscorta(PDO $pdo, int $magazzinoId, int $limit = 5): array
{
    $stmt = $pdo->prepare("
        SELECT p.nome, COALESCE(g.quantita_disponibile, 0) AS quantita_disponibile, g.scorta_minima, u.codice AS uom
        FROM prodotti p
        JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
        LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
        WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
        ORDER BY (COALESCE(g.quantita_disponibile, 0) - COALESCE(g.quantita_riservata, 0)) ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $magazzinoId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Soglie di riordino di UN prodotto su TUTTE le sedi (radici fisiche +
 * virtuali/conto_terzi/conto_lavoro — stesso set del form trasferimento: una
 * soglia ha senso anche per una sede virtuale). Estratto da
 * WarehouseController::minStock() (Fase 3, microtappa 3.3).
 */
function qk_min_stock_per_prodotto(PDO $pdo, int $prodottoId): array
{
    $stmt = $pdo->prepare(
        "SELECT m.id AS magazzino_id, m.nome AS magazzino_nome, m.codice AS magazzino_codice,
                p.id AS prodotto_id, p.nome AS prodotto_nome, p.sku_interno,
                u.codice AS uom,
                COALESCE(g.quantita_disponibile, 0) AS qta_disponibile,
                COALESCE(g.scorta_minima, 0) AS scorta_minima
           FROM magazzini m
           LEFT JOIN prodotti p ON p.id = ?
           LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
           LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = m.id
          WHERE m.attivo = 1
          ORDER BY m.predefinito DESC, m.nome COLLATE NOCASE"
    );
    $stmt->execute([$prodottoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Soglie di riordino di TUTTI i prodotti attivi in UN magazzino (vista
 * standard di WarehouseController::minStock(), Fase 3, microtappa 3.3).
 * LEFT JOIN su giacenze: un prodotto mai movimentato in questa sede compare
 * comunque, con soglia 0 modificabile da subito.
 */
function qk_min_stock_per_magazzino(PDO $pdo, int $magazzinoId, string $search = ''): array
{
    $where = ['p.attivo = 1'];
    $params = [$magazzinoId];
    if ($search !== '') {
        $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $stmt = $pdo->prepare(
        "SELECT p.id AS prodotto_id, p.nome AS prodotto_nome, p.sku_interno,
                u.codice AS uom,
                COALESCE(g.quantita_disponibile, 0) AS qta_disponibile,
                COALESCE(g.scorta_minima, 0) AS scorta_minima
           FROM prodotti p
           LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
           LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
          WHERE " . implode(' AND ', $where) . "
          ORDER BY p.nome COLLATE NOCASE"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Upsert della scorta minima per la coppia (prodotto, magazzino) — crea la
 * riga giacenze con giacenza 0 se non esiste ancora (prodotto mai
 * movimentato in questa sede), stesso comportamento del primo movimento
 * manuale (qk_insert_movimento_manuale). Ritorna lo stato aggiornato
 * (qta_disponibile, scorta_minima) per il refresh lato UI. Estratto da
 * WarehouseController::saveMinStock() (Fase 3, microtappa 3.3).
 */
function qk_upsert_scorta_minima(PDO $pdo, int $prodottoId, int $magazzinoId, float $scortaMinima): array
{
    $pdo->prepare(
        "INSERT INTO giacenze (prodotto_id, magazzino_id, scorta_minima, updated_at)
         VALUES (?, ?, ?, CURRENT_TIMESTAMP)
         ON CONFLICT(prodotto_id, magazzino_id)
         DO UPDATE SET scorta_minima = excluded.scorta_minima, updated_at = CURRENT_TIMESTAMP"
    )->execute([$prodottoId, $magazzinoId, $scortaMinima]);

    $stmt = $pdo->prepare(
        "SELECT COALESCE(quantita_disponibile,0) AS qta_disponibile, scorta_minima
           FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?"
    );
    $stmt->execute([$prodottoId, $magazzinoId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Frammento SQL del JOIN "fornitore preferito, primo ATTIVO per ordine di
 * preferenza" (REVISIONE Ciclo Acquisti, sez. Multi-fornitore, fix §1.9): dato
 * un prodotto, seleziona il prodotto_fornitore con priorità più alta tra i
 * fornitori attivi — non il primo in assoluto, che potrebbe essere disattivato
 * e lasciare il campo vuoto invece di scavalcare al successivo attivo.
 * Estratto da WarehouseController::reorder() (Fase 3, microtappa 3.9 — dedup
 * post-audit §6.1: era duplicato identico tra il ramo aggregato e quello
 * per-sede dello stesso metodo).
 *
 * Non unifica le copie analoghe in purchases_rfq.php/RfqController.php (stesso
 * pattern, ma fuori dal dominio Magazzino di questo piano — dedup cross-dominio
 * da valutare a parte).
 *
 * @param string $prodottoAlias alias SQL della tabella prodotti nella query chiamante (es. 'p')
 */
function qk_join_fornitore_preferito_sql(string $prodottoAlias = 'p'): string
{
    return "
                 LEFT JOIN prodotti_fornitori pf ON pf.id = (
                     SELECT pf2.id FROM prodotti_fornitori pf2
                      JOIN clienti_fornitori cf2 ON cf2.id = pf2.fornitore_id AND cf2.attivo = 1
                     WHERE pf2.prodotto_id = {$prodottoAlias}.id AND pf2.attivo = 1
                     ORDER BY pf2.ordine_preferenza_fornitore ASC, pf2.id DESC
                     LIMIT 1
                 )
                 LEFT JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id";
}

function qk_stock_count(PDO $pdo, string $search = '', string $filter = 'all', ?int $magazzino_id = null): int
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    if ($magazzino_id === 0) {
        // "Tutti i magazzini" (§8.0): giacenza aggregata via SUM/GROUP BY, non
        // la riga di una sola sede presa a caso — vedi Magazzino Attivo -
        // Analisi e Alternative.md, §8.3.
        $where = ['p.attivo = 1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like; $params[] = $like;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM (
                SELECT p.id
                  FROM prodotti p
                  LEFT JOIN giacenze g ON g.prodotto_id = p.id
                 WHERE ' . implode(' AND ', $where) . '
                 GROUP BY p.id
                HAVING ' . _qk_stock_having($filter) . '
             ) t'
        );
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

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

/**
 * Ramo aggregato di qk_stock_list() per magazzino_id === 0 ("Tutti i
 * magazzini", §8.0): stessa forma di ritorno del ramo per singola sede, ma
 * ogni quantità è la SUM su tutte le sedi. Punto delicato: la disponibilità
 * dei componenti di un Kit va sommata su tutte le sedi anch'essa (§8.3),
 * quindi la subquery kit non può più fare un semplice JOIN sul magazzino
 * indicato — deve sommare a sua volta con una sub-subquery indipendente.
 *
 * @internal
 */
function _qk_stock_list_aggregato(PDO $pdo, string $search, string $filter, string $sort, string $dir, int $limit, int $offset): array
{
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

    $where = ['p.attivo = 1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(p.nome LIKE ? OR p.sku_interno LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
    $params[] = $limit;
    $params[] = $offset;

    $sql = 'SELECT
                p.id, p.sku_interno, p.nome, p.traccia_lotti,
                u.codice AS uom,
                CASE
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(
                            (SELECT SUM(g3.quantita_disponibile) FROM giacenze g3 WHERE g3.prodotto_id = ck.componente_id)
                        , 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        WHERE ck.kit_id = p.id
                    )
                    ELSE SUM(COALESCE(g.quantita_disponibile, 0))
                END AS qta_disponibile,
                SUM(COALESCE(g.quantita_riservata, 0))                AS qta_riservata,
                SUM(COALESCE(g.quantita_in_arrivo, 0))                AS qta_in_arrivo,
                CASE
                    WHEN p.tipo = \'kit\' THEN (
                        SELECT MIN(CAST(IFNULL(
                            (SELECT SUM(g3.quantita_disponibile) FROM giacenze g3 WHERE g3.prodotto_id = ck.componente_id)
                        , 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        WHERE ck.kit_id = p.id
                    )
                    ELSE SUM(COALESCE(g.quantita_disponibile, 0))
                END - SUM(COALESCE(g.quantita_riservata, 0))          AS qta_libera,
                SUM(COALESCE(g.scorta_minima, 0))                     AS scorta_minima,
                MAX(g.updated_at) AS giacenza_updated_at
            FROM prodotti p
            LEFT JOIN giacenze g ON g.prodotto_id = p.id
            LEFT JOIN unita_misura u ON u.id = p.unita_misura_stock_id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY p.id
            HAVING ' . _qk_stock_having($filter) . '
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

function qk_stock_list(PDO $pdo, string $search = '', string $filter = 'all', string $sort = 'nome', string $dir = 'asc', int $limit = 50, int $offset = 0, ?int $magazzino_id = null): array
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    if ($magazzino_id === 0) {
        return _qk_stock_list_aggregato($pdo, $search, $filter, $sort, $dir, $limit, $offset);
    }

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
 *
 * NOTA (Piano refactor MVC/DRY, Fase 3, microtappa 3.8 — predisposizione non
 * esecutiva): SpedizioniController::nuova() e
 * SalesOrdersController::apiLottiDisponibili() hanno ciascuno una propria
 * query FEFO quasi identica a questa (stessa logica "lotti con giacenza
 * disponibile in questo magazzino, ordinati per scadenza"), invece di
 * riusare questa funzione. Un parametro tipo `includiScaduti` qui potrebbe
 * assorbire entrambe, eliminando 3 copie della stessa query — non fatto ora,
 * rimandato esplicitamente (bassa priorità, pre-esistente a questo piano).
 */
function qk_lotti_by_prodotto(PDO $pdo, int $prodotto_id, bool $solo_disponibili = false, ?int $magazzino_id = null): array
{
    $magazzino_id ??= qk_magazzino_attivo_id($pdo);

    $cond = $solo_disponibili ? 'AND COALESCE(lg.quantita_disponibile, 0) > 0' : '';
    $st   = $pdo->prepare(
        "SELECT l.id,
                l.codice_lotto_interno                            AS codice_lotto_interno,
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
 *   codice_lotto_interno, prodotto_id, quantita_ricevuta, creato_da
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
             (codice_lotto_interno, codice_lotto_fornitore,
              prodotto_id, origine_tipo, data_scadenza, data_arrivo,
              quantita_ricevuta, creato_da)
         VALUES (?, ?, ?, \'rettifica\', ?, ?, ?, ?)'
    )->execute([
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
 * Quando si attiva `traccia_lotti` (0 -> 1) su un prodotto che ha già giacenza in uno
 * o più magazzini, quella giacenza esistente non appartiene a nessun lotto — da quel
 * momento in poi il prodotto lavora "a lotti" (FEFO, selezione lotto in spedizione),
 * ma lo stock già presente resterebbe altrimenti invisibile a quei meccanismi
 * (Revisione Gestione Magazzino e Lotti §4, simmetrico al blocco già esistente per
 * la direzione opposta — disabilitare traccia_lotti con lotti ancora aperti).
 *
 * Crea un lotto "di conversione" per ciascun magazzino con giacenza > 0, che assorbe
 * l'intera quantità esistente in quella sede (origine_tipo = 'rettifica', nessun
 * fornitore/DDT associato: sono dati storici non disponibili). Nessuna azione se il
 * prodotto non ha giacenza da nessuna parte — il cambio resta libero come oggi.
 *
 * @return int numero di lotti di conversione creati (0 = nessuna giacenza da convertire)
 */
function qk_crea_lotti_conversione_iniziale(PDO $pdo, int $prodottoId, int $utente_id): int
{
    $st = $pdo->prepare(
        'SELECT magazzino_id, quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND quantita_disponibile > 0.0005'
    );
    $st->execute([$prodottoId]);
    $righe = $st->fetchAll(PDO::FETCH_ASSOC);
    if (empty($righe)) {
        return 0;
    }

    $pdo->beginTransaction();
    try {
        $data = date('Ymd');
        $stLotto = $pdo->prepare(
            "INSERT INTO lotti (codice_lotto_interno, prodotto_id, origine_tipo, quantita_ricevuta, note, creato_da)
             VALUES (?, ?, 'rettifica', ?, ?, ?)"
        );
        $stGiacLotto = $pdo->prepare(
            'INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
        );

        foreach ($righe as $r) {
            $magazzinoId = (int) $r['magazzino_id'];
            $qta         = (float) $r['quantita_disponibile'];

            $stLotto->execute([
                sprintf('INIZIALE-%d-%s', $magazzinoId, $data),
                $prodottoId,
                $qta,
                'Lotto generato automaticamente per convertire la giacenza già presente al momento dell\'attivazione della tracciabilità lotti su questo prodotto.',
                $utente_id,
            ]);
            $lottoId = (int) $pdo->lastInsertId();

            $stGiacLotto->execute([$lottoId, $magazzinoId, $qta]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($righe);
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
                m.quantita_delta,
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
        // Il segno convenzionale: carico/reso_fornitore/svincolo = positivo; gli altri = negativo.
        // La rettifica può essere sia un aumento che una diminuzione: la direzione
        // non è deducibile dal solo tipo_movimento, quindi usa il segno di
        // quantita_delta (popolata solo per le rettifiche, vedi migrazione
        // 2026_07_10_movimenti_quantita_delta). Per rettifiche storiche precedenti
        // al fix, dove quantita_delta è NULL, si ricade sulla lista statica (Fase 3,
        // microtappa 3.5 — logica allineata a quella già presente, ma inattiva, in
        // app/views/warehouse/movements.php: qui il valore arrivava sempre assente).
        $positivi = ['carico', 'reso_fornitore', 'svincolo'];
        if ($r['tipo_movimento'] === 'rettifica' && $r['quantita_delta'] !== null) {
            $segno = ((float) $r['quantita_delta']) >= 0 ? '+' : '-';
            $quantitaMostrata = abs((float) $r['quantita_delta']);
        } else {
            $segno = in_array($r['tipo_movimento'], $positivi, true) ? '+' : '-';
            $quantitaMostrata = (float) $r['quantita'];
        }
        $qtaClass = $segno === '+' ? 'qk-text-success' : 'qk-text-danger';

        return [
            'id'                    => (int)    $r['id'],
            'tipo_movimento'        => (string) $r['tipo_movimento'],
            'quantita'              => (float)  $r['quantita'],
            'segno'                 => $segno,
            'quantita_mostrata'     => $quantitaMostrata,
            'qta_class'             => $qtaClass,
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

    // Tappa 4.3: blocco movimenti su un prodotto sotto conteggio in questo magazzino.
    if (qk_inventario_bloccato($pdo, $magazzino_id, (int) $v['prodotto_id'])) {
        throw new \RuntimeException('È in corso un inventario fisico su questo prodotto in questo magazzino. Completa o annulla l\'inventario prima di registrare movimenti.');
    }

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

        // Per la rettifica su un prodotto a lotti, l'utente seleziona SEMPRE un
        // lotto specifico (validato in WarehouseController::createMovement()).
        // Il valore inserito nel form è quindi la nuova giacenza assoluta DEL
        // LOTTO in questo magazzino, non del prodotto aggregato: un prodotto può
        // avere più lotti nello stesso magazzino, e sovrascrivere l'aggregato con
        // il valore di un solo lotto cancellerebbe silenziosamente il contributo
        // degli altri lotti. Per questo, in rettifica su lotto, calcoliamo prima
        // il delta a livello di lotto e lo applichiamo come variazione (non come
        // valore assoluto) alla giacenza di prodotto.
        $rettificaSuLotto = ($tipo === 'rettifica' && !empty($v['lotto_id']));
        $deltaLottoRettifica = null;

        if ($rettificaSuLotto) {
            $stLgPre = $pdo->prepare(
                'SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ? AND magazzino_id = ?'
            );
            $stLgPre->execute([$v['lotto_id'], $magazzino_id]);
            $qtaLottoPre = $stLgPre->fetchColumn();
            $qtaLottoPre = $qtaLottoPre !== false ? (float) $qtaLottoPre : 0.0;

            $deltaLottoRettifica = $v['quantita'] - $qtaLottoPre;
            $qtaMovimento = abs($deltaLottoRettifica);

            $qtaNuova = $qtaCorrente + $deltaLottoRettifica;
            if ($qtaNuova < -0.0005) {
                throw new \RuntimeException(
                    sprintf(
                        'La rettifica del lotto porterebbe la giacenza totale del prodotto in questo magazzino sotto zero (attuale %.3f, variazione %.3f). Verifica gli altri lotti dello stesso prodotto.',
                        $qtaCorrente,
                        $deltaLottoRettifica
                    )
                );
            }
        } elseif ($tipo === 'carico') {
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
            // rettifica su prodotto SENZA lotti (traccia_lotti = 0): non esistono
            // altri "contenitori" di giacenza per questo prodotto in questo
            // magazzino, quindi il valore inserito è legittimamente la nuova
            // giacenza assoluta del prodotto.
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
            if ($rettificaSuLotto) {
                // Già letta sopra (qtaLottoPre) per calcolare il delta da applicare
                // all'aggregato di prodotto: qui il valore inserito è la nuova
                // giacenza assoluta DEL LOTTO, coerente con quanto già calcolato.
                $qtaLottoCorrente = $qtaLottoPre;
                $qtaLottoNuova    = $v['quantita'];
            } else {
                $stLg = $pdo->prepare(
                    'SELECT quantita_disponibile FROM lotti_giacenze WHERE lotto_id = ? AND magazzino_id = ?'
                );
                $stLg->execute([$v['lotto_id'], $magazzino_id]);
                $qtaLottoCorrente = $stLg->fetchColumn();
                $qtaLottoCorrente = $qtaLottoCorrente !== false ? (float) $qtaLottoCorrente : 0.0;

                if ($tipo === 'carico') {
                    $qtaLottoNuova = $qtaLottoCorrente + $qtaMovimento;
                } else { // scarico
                    $qtaLottoNuova = $qtaLottoCorrente - $qtaMovimento;
                    if ($qtaLottoNuova < 0) {
                        throw new \RuntimeException('Giacenza del lotto insufficiente in questo magazzino.');
                    }
                }
            }

            $pdo->prepare(
                'INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
                 VALUES (?, ?, ?, datetime(\'now\'))
                 ON CONFLICT(lotto_id, magazzino_id) DO UPDATE SET
                     quantita_disponibile = excluded.quantita_disponibile,
                     updated_at = excluded.updated_at'
            )->execute([$v['lotto_id'], $magazzino_id, $qtaLottoNuova]);

            // Aggiorna anche il totale storico ricevuto (lotti.quantita_ricevuta) quando si
            // carica altra merce su un lotto GIÀ ESISTENTE (es. stesso lotto fisico arrivato
            // in più consegne). Se il lotto è stato appena creato in questa stessa chiamata
            // (step 0, ramo 'nuovo_lotto'), quantita_ricevuta è già stata impostata alla
            // creazione: non va incrementata di nuovo, altrimenti si conta due volte.
            if ($tipo === 'carico' && !isset($v['nuovo_lotto'])) {
                $pdo->prepare(
                    'UPDATE lotti SET quantita_ricevuta = quantita_ricevuta + ? WHERE id = ?'
                )->execute([$qtaMovimento, $v['lotto_id']]);
            }
        }

        // 5. Inserisci il movimento.
        // `quantita` resta SEMPRE una magnitudine positiva (invariante
        // documentato nello schema: "sempre positivo; il tipo_movimento indica
        // la direzione"). Per carico/scarico la direzione è già implicita nel
        // tipo. Per la rettifica invece la direzione NON è ricavabile dal tipo
        // da solo (può essere sia un aumento che una diminuzione): il segno va
        // quindi in `quantita_delta` (nullable, popolata SOLO per rettifica),
        // usata dalla UI storico movimenti per mostrare il verso corretto senza
        // violare l'invariante su `quantita`.
        $deltaRettificaLog = ($tipo === 'rettifica') ? ($qtaNuova - $qtaCorrente) : null;

        $stMov = $pdo->prepare(
            'INSERT INTO movimenti_magazzino
                (prodotto_id, lotto_id, magazzino_id, tipo_movimento, quantita, quantita_delta,
                 documento_tipo, documento_id, canale_origine, riferimento_esterno, note, creato_da, created_at)
             VALUES
                (?, ?, ?, ?, ?, ?,
                 ?, ?, \'manuale\', ?, ?, ?, datetime(\'now\'))'
        );
        $stMov->execute([
            $v['prodotto_id'],
            $v['lotto_id'] ?? null,
            $magazzino_id,
            $tipo,
            $qtaMovimento,
            $deltaRettificaLog,
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

// L'anagrafica magazzini (10 funzioni CRUD sulla tabella `magazzini`) vive ora
// in includes/magazzini_anagrafica.php, richiesto in testa a questo file — vedi
// il commento in cima per il ragionamento sullo split (audit MVC/DRY, Revisione
// Gestione Magazzino e Lotti §6/piano refactor). Nessuno split ulteriore di
// giacenze/lotti/movimenti/cross-cutting sotto: (a) e (b)/(c) hanno una
// dipendenza funzionale reale (qk_insert_movimento_manuale chiama
// qk_insert_lotto_manuale), e gli helper cross-cutting hanno troppi chiamanti
// esterni al dominio Magazzino per essere spostati senza rompere require altrove.
