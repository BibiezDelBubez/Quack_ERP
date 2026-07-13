<?php
/**
 * Migration: D2 (PIANO Motore di Pricing Multi-UoM §8, Fase 2).
 *
 * Popola, per ogni riga esistente di listini_prezzi e prodotti_fornitori_prezzi,
 * le tre colonne aggiunte da D1.1:
 *   - quantita_minima_intento = quantita_minima esistente (il valore così come
 *     l'utente l'aveva scritto)
 *   - unita_misura_soglia_id  = unita_misura_id esistente (l'unità con cui è
 *     stata scritta la soglia — NULL se non specificata, assunta Stock UoM)
 *   - quantita_minima_stock   = valore canonico calcolato con la stessa logica
 *     autorevole di qk_uom_converti_riga() (BCMath sulla frazione esatta)
 *
 * Precondizione (verificata da questa stessa migrazione, non solo dichiarata):
 * si rifiuta di procedere se trova "doppioni mascherati" (§7.4) — due righe sullo
 * stesso listino/prodotto che, una volta convertite in Stock UoM, risultano lo
 * stesso identico valore. In quel caso la migrazione si ferma senza scrivere
 * nulla e riporta quali righe vanno risolte manualmente prima di rilanciarla
 * (non decide da sola quale delle due tenere — vedi db/scripts/audit_soglie_uom.php
 * per lo stesso controllo in sola lettura, eseguibile prima per orientarsi).
 *
 * Idempotente: le righe già popolate (quantita_minima_stock NOT NULL) vengono
 * ricalcolate comunque (per sicurezza, nel caso lo schema del prodotto sia
 * cambiato tra un run e l'altro in ambiente di sviluppo) ma non causano errori.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/prodotti.php';

function qk_migrazione_round_qmin(float $v): float
{
    return (float) bcadd((string)$v, '0', 3);
}

function qk_migrazione_canonico(PDO $pdo, int $prodottoId, ?int $unitaMisuraId, float $quantitaMinima): float
{
    if ($quantitaMinima <= 0) {
        return 0.0;
    }
    $conv = qk_uom_converti_riga($pdo, $prodottoId, $unitaMisuraId, $quantitaMinima, 0.0);
    return qk_migrazione_round_qmin((float)$conv['quantita_stock']);
}

/**
 * @param string $colChiaveExtra colonna aggiuntiva che, insieme a $colGruppo, identifica
 *        univocamente il vincolo reale della tabella (per listini_prezzi è prodotto_id;
 *        per prodotti_fornitori_prezzi è prodotto_fornitore_id, NON prodotto_id — un
 *        prodotto con più fornitori ha più righe legittimamente indipendenti).
 * @return array{doppioni: array, righe: array} righe pronte da scrivere + eventuali doppioni trovati
 */
function qk_migrazione_prepara(PDO $pdo, string $sql, string $colGruppo, string $colChiaveExtra = 'prodotto_id'): array
{
    $stmt = $pdo->query($sql);
    $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $daScrivere = [];
    $valoriPerGruppo = [];

    foreach ($righe as $r) {
        $prodottoId = (int)$r['prodotto_id'];
        $umId = $r['unita_misura_id'] !== null ? (int)$r['unita_misura_id'] : null;
        $qMin = (float)$r['quantita_minima'];
        $gruppo = $r[$colGruppo] !== null ? (int)$r[$colGruppo] : 0;
        $chiaveExtra = (int)$r[$colChiaveExtra];

        $canonico = qk_migrazione_canonico($pdo, $prodottoId, $umId, $qMin);

        $daScrivere[] = [
            'id' => (int)$r['id'],
            'intento' => $qMin,
            'unita_soglia' => $umId,
            'canonico' => $canonico,
        ];

        $chiave = $gruppo . '_' . $chiaveExtra . '_' . $canonico;
        $valoriPerGruppo[$chiave][] = (int)$r['id'];
    }

    $doppioni = [];
    foreach ($valoriPerGruppo as $chiave => $ids) {
        if (count($ids) > 1) {
            $doppioni[] = ['chiave' => $chiave, 'righe_id' => $ids];
        }
    }

    return ['doppioni' => $doppioni, 'righe' => $daScrivere];
}

echo "Migrazione D2 — popolamento quantita_minima_stock\n";
echo str_repeat('=', 60) . "\n\n";

$prepVendite = qk_migrazione_prepara(
    $pdo,
    "SELECT id, listino_id, prodotto_id, unita_misura_id, quantita_minima FROM listini_prezzi",
    'listino_id'
);

$prepAcquisti = qk_migrazione_prepara(
    $pdo,
    "SELECT pfp.id, pf.prodotto_id, pfp.prodotto_fornitore_id, pfp.unita_misura_id, pfp.quantita_minima,
            pfp.listino_acquisto_id AS gruppo_id
     FROM prodotti_fornitori_prezzi pfp
     JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id",
    'gruppo_id',
    'prodotto_fornitore_id'
);

$totDoppioni = count($prepVendite['doppioni']) + count($prepAcquisti['doppioni']);

if ($totDoppioni > 0) {
    echo "MIGRAZIONE FERMATA: {$totDoppioni} doppioni mascherati trovati.\n";
    echo "Nessuna scrittura effettuata. Risolvere manualmente le righe elencate sotto,\n";
    echo "poi rilanciare questa migrazione.\n\n";
    if ($prepVendite['doppioni']) {
        echo "-- listini_prezzi (vendite) --\n";
        foreach ($prepVendite['doppioni'] as $d) {
            echo "  {$d['chiave']} => id righe [" . implode(', ', $d['righe_id']) . "]\n";
        }
    }
    if ($prepAcquisti['doppioni']) {
        echo "-- prodotti_fornitori_prezzi (acquisti) --\n";
        foreach ($prepAcquisti['doppioni'] as $d) {
            echo "  {$d['chiave']} => id righe [" . implode(', ', $d['righe_id']) . "]\n";
        }
    }
    exit(1);
}

$pdo->beginTransaction();
try {
    $updV = $pdo->prepare(
        "UPDATE listini_prezzi SET quantita_minima_intento = :intento, unita_misura_soglia_id = :umid, quantita_minima_stock = :canonico WHERE id = :id"
    );
    foreach ($prepVendite['righe'] as $r) {
        $updV->execute([
            ':intento' => $r['intento'],
            ':umid' => $r['unita_soglia'],
            ':canonico' => $r['canonico'],
            ':id' => $r['id'],
        ]);
    }

    $updA = $pdo->prepare(
        "UPDATE prodotti_fornitori_prezzi SET quantita_minima_intento = :intento, unita_misura_soglia_id = :umid, quantita_minima_stock = :canonico WHERE id = :id"
    );
    foreach ($prepAcquisti['righe'] as $r) {
        $updA->execute([
            ':intento' => $r['intento'],
            ':umid' => $r['unita_soglia'],
            ':canonico' => $r['canonico'],
            ':id' => $r['id'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERRORE durante la scrittura: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Completata. " . count($prepVendite['righe']) . " righe listini_prezzi e "
    . count($prepAcquisti['righe']) . " righe prodotti_fornitori_prezzi aggiornate.\n";
echo "\nProssimo passo: D1.2 (aggiornare il vincolo UNIQUE su quantita_minima_stock),\n";
echo "ora possibile perché tutte le righe hanno un valore canonico popolato.\n";
