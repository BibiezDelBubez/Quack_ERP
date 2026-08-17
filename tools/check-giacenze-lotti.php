<?php
declare(strict_types=1);

/**
 * tools/check-giacenze-lotti.php
 *
 * Tappa 5 del piano:
 * docs/Gestione Magazzino e Lotti/PIANO - Coerenza Strutturale Giacenze e Lotti.md
 *
 * Script di controllo READ-ONLY: non scrive né corregge MAI nulla nel
 * database. Confronta l'aggregato `giacenze.quantita_disponibile` con la
 * somma di `lotti_giacenze.quantita_disponibile` per lo stesso
 * prodotto+magazzino, per i soli prodotti a lotti (traccia_lotti=1) — le due
 * fonti dovrebbero SEMPRE coincidere, per costruzione, da quando
 * App\Services\GiacenzaLottoService (Tappa 3 di questo piano) è diventato
 * l'unico punto che scrive entrambe insieme in un'unica operazione. Se
 * emerge un'anomalia qui, è il segnale che qualche percorso di scrittura non
 * ancora migrato al service condiviso, un intervento manuale sul database, o
 * un'incoerenza dati storica (pre-Tappa 3/4) ha rotto quella simmetria.
 *
 * Rileva anche giacenze negative (aggregate o per lotto): non dovrebbero mai
 * accadere per via delle validazioni applicative (vedi
 * qk_valida_scostamenti_inventario e i controlli equivalenti nei singoli
 * punti di scrittura), ma un controllo diretto sul dato reale è più
 * affidabile che fidarsi che ogni punto di scrittura, presente e futuro,
 * rispetti sempre quel vincolo.
 *
 * USO:
 *   php tools/check-giacenze-lotti.php
 *
 * USCITA: 0 se nessuna anomalia trovata, 1 se ne trova almeno una (elenco
 * stampato su stdout, una riga di intestazione + dettaglio per anomalia).
 * Non è schedulato per l'esecuzione automatica in questa fase — vedi
 * "docs/Gestione Magazzino e Lotti/RUNBOOK - Controllo Coerenza Giacenze e
 * Lotti.md" per chi lo esegue, quando, e cosa fare dei risultati.
 */

/**
 * Esegue tutti i controlli di coerenza e ritorna l'elenco delle anomalie
 * trovate. Pura lettura: nessuna scrittura sul $pdo ricevuto — nessuna query
 * in questa funzione è un INSERT/UPDATE/DELETE. Isolata dal bootstrap CLI
 * sotto apposta per essere riusabile anche da
 * tests/manual/suite_check_giacenze_lotti_tappa5.php, che passa un PDO
 * proprio (in-memory, dati di prova) invece di connettersi al database reale.
 *
 * @return list<array{tipo:string, prodotto_id:int, sku:string, nome_prodotto:string, magazzino_id:int, magazzino_nome:string, dettaglio:string}>
 */
function qk_check_giacenze_lotti(PDO $pdo): array
{
    $anomalie = [];
    $tolleranza = 0.0005; // stessa soglia già in uso altrove nel dominio (vedi includes/inventario.php)

    // 1. Ogni riga `giacenze` di un prodotto a lotti: l'aggregato deve
    //    coincidere con la somma dei lotti di quel prodotto in quel
    //    magazzino (0 se non ne ha nessuno — esattamente il caso che la
    //    Tappa 4 di questo piano ora impedisce di creare via Inventario
    //    Fisico, ma dati storici precedenti o altri percorsi di scrittura
    //    potrebbero comunque averlo prodotto in passato).
    $stmt = $pdo->query("
        SELECT
            p.id AS prodotto_id, p.sku_interno, p.nome AS nome_prodotto,
            g.magazzino_id, m.nome AS magazzino_nome,
            g.quantita_disponibile AS agg,
            COALESCE((
                SELECT SUM(lg.quantita_disponibile)
                FROM lotti_giacenze lg
                JOIN lotti l ON l.id = lg.lotto_id
                WHERE l.prodotto_id = p.id AND lg.magazzino_id = g.magazzino_id
            ), 0) AS lotti_sum
        FROM giacenze g
        JOIN prodotti p ON p.id = g.prodotto_id
        JOIN magazzini m ON m.id = g.magazzino_id
        WHERE p.traccia_lotti = 1
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $agg = (float) $r['agg'];
        $lottiSum = (float) $r['lotti_sum'];
        $diff = $agg - $lottiSum;
        if (abs($diff) > $tolleranza) {
            $anomalie[] = [
                'tipo'           => abs($lottiSum) < $tolleranza ? 'aggregato_senza_lotti' : 'disallineamento_aggregato_lotti',
                'prodotto_id'    => (int) $r['prodotto_id'],
                'sku'            => (string) $r['sku_interno'],
                'nome_prodotto'  => (string) $r['nome_prodotto'],
                'magazzino_id'   => (int) $r['magazzino_id'],
                'magazzino_nome' => (string) $r['magazzino_nome'],
                'dettaglio'      => sprintf(
                    'giacenza aggregata %.3f, somma lotti %.3f, differenza %+.3f',
                    $agg,
                    $lottiSum,
                    $diff
                ),
            ];
        }
    }

    // 2. Caso opposto del punto 1: lotti con giacenza in un magazzino dove
    //    NON esiste nessuna riga `giacenze` per quel prodotto — qui
    //    l'aggregato è "assente" invece che sbagliato, ma il problema di
    //    fondo (dato dei lotti non riflesso nell'aggregato) è lo stesso.
    $stmt2 = $pdo->query("
        SELECT
            p.id AS prodotto_id, p.sku_interno, p.nome AS nome_prodotto,
            lg.magazzino_id, m.nome AS magazzino_nome,
            SUM(lg.quantita_disponibile) AS lotti_sum
        FROM lotti_giacenze lg
        JOIN lotti l ON l.id = lg.lotto_id
        JOIN prodotti p ON p.id = l.prodotto_id
        JOIN magazzini m ON m.id = lg.magazzino_id
        LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = lg.magazzino_id
        WHERE g.id IS NULL
        GROUP BY p.id, lg.magazzino_id
    ");
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lottiSum = (float) $r['lotti_sum'];
        if (abs($lottiSum) > $tolleranza) {
            $anomalie[] = [
                'tipo'           => 'lotti_senza_giacenza_aggregata',
                'prodotto_id'    => (int) $r['prodotto_id'],
                'sku'            => (string) $r['sku_interno'],
                'nome_prodotto'  => (string) $r['nome_prodotto'],
                'magazzino_id'   => (int) $r['magazzino_id'],
                'magazzino_nome' => (string) $r['magazzino_nome'],
                'dettaglio'      => sprintf(
                    'nessuna riga in giacenze per questo prodotto+magazzino, ma i lotti hanno %.3f di giacenza',
                    $lottiSum
                ),
            ];
        }
    }

    // 3. Giacenze aggregate negative (qualunque prodotto, non solo a lotti:
    //    è un vincolo di dominio generale, non specifico alla coerenza
    //    lotti).
    $stmt3 = $pdo->query("
        SELECT p.id AS prodotto_id, p.sku_interno, p.nome AS nome_prodotto,
               g.magazzino_id, m.nome AS magazzino_nome, g.quantita_disponibile AS agg
        FROM giacenze g
        JOIN prodotti p ON p.id = g.prodotto_id
        JOIN magazzini m ON m.id = g.magazzino_id
        WHERE g.quantita_disponibile < 0
    ");
    foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $anomalie[] = [
            'tipo'           => 'giacenza_aggregata_negativa',
            'prodotto_id'    => (int) $r['prodotto_id'],
            'sku'            => (string) $r['sku_interno'],
            'nome_prodotto'  => (string) $r['nome_prodotto'],
            'magazzino_id'   => (int) $r['magazzino_id'],
            'magazzino_nome' => (string) $r['magazzino_nome'],
            'dettaglio'      => sprintf('giacenza aggregata negativa: %.3f', (float) $r['agg']),
        ];
    }

    // 4. Giacenze per lotto negative.
    $stmt4 = $pdo->query("
        SELECT p.id AS prodotto_id, p.sku_interno, p.nome AS nome_prodotto,
               lg.magazzino_id, m.nome AS magazzino_nome,
               l.codice_lotto_interno, lg.quantita_disponibile AS qta
        FROM lotti_giacenze lg
        JOIN lotti l ON l.id = lg.lotto_id
        JOIN prodotti p ON p.id = l.prodotto_id
        JOIN magazzini m ON m.id = lg.magazzino_id
        WHERE lg.quantita_disponibile < 0
    ");
    foreach ($stmt4->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $anomalie[] = [
            'tipo'           => 'giacenza_lotto_negativa',
            'prodotto_id'    => (int) $r['prodotto_id'],
            'sku'            => (string) $r['sku_interno'],
            'nome_prodotto'  => (string) $r['nome_prodotto'],
            'magazzino_id'   => (int) $r['magazzino_id'],
            'magazzino_nome' => (string) $r['magazzino_nome'],
            'dettaglio'      => sprintf('lotto %s: giacenza negativa %.3f', (string) $r['codice_lotto_interno'], (float) $r['qta']),
        ];
    }

    return $anomalie;
}

// =============================================================================
// Entry point CLI — eseguito SOLO quando questo file viene invocato
// direttamente (php tools/check-giacenze-lotti.php), non quando è incluso da
// un chiamante come la suite di test dedicata, che vuole solo la funzione
// sopra con un PDO proprio in-memory e nessuna connessione al DB reale.
// =============================================================================
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';

    /** @var PDO $pdo */
    $anomalie = qk_check_giacenze_lotti($pdo);

    echo "=== Controllo coerenza giacenze/lotti — " . date('Y-m-d H:i:s') . " ===\n";
    echo "(sola lettura — nessuna correzione automatica; vedi il runbook per come intervenire)\n\n";

    if (empty($anomalie)) {
        echo "Nessuna anomalia trovata.\n";
        exit(0);
    }

    foreach ($anomalie as $a) {
        printf(
            "[%s] SKU %s — %s (prodotto #%d, magazzino \"%s\" #%d)\n    %s\n",
            $a['tipo'],
            $a['sku'],
            $a['nome_prodotto'],
            $a['prodotto_id'],
            $a['magazzino_nome'],
            $a['magazzino_id'],
            $a['dettaglio']
        );
    }
    echo "\nTotale anomalie: " . count($anomalie) . "\n";
    exit(1);
}
