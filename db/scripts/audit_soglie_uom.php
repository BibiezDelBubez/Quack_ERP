<?php
/**
 * D0bis (PIANO Motore di Pricing Multi-UoM §8, Fase 0bis) — script di SOLA LETTURA.
 *
 * Per ogni riga di listini_prezzi (vendite) e prodotti_fornitori_prezzi (acquisti),
 * calcola quale sarebbe il valore canonico di quantita_minima (sempre in Stock UoM,
 * usando la stessa logica di qk_uom_converti_riga()) e riporta:
 *   - quante soglie sono già coerenti (valore grezzo già in Stock UoM, nessun cambiamento in Fase 2)
 *   - quante andranno convertite (valore grezzo espresso in UoM commerciale)
 *   - quante produrrebbero un DOPPIONE MASCHERATO (§7.4): due righe sullo stesso
 *     listino/prodotto che, una volta convertite in Stock UoM, risultano lo stesso
 *     identico valore — da risolvere manualmente PRIMA della migrazione (Fase 2),
 *     perché la migrazione non può decidere da sola quale delle due tenere.
 *
 * Nota (14/07/2026): l'ambiente è di sviluppo, tutti i dati sono di prova — questo
 * script non protegge dati reali, serve a validare che la LOGICA sia corretta prima
 * di riusarla identica in Fase 2 (db/migrations/), quando ci sarà dato reale da migrare.
 *
 * Nessuna scrittura: solo SELECT. Eseguire con: php db/scripts/audit_soglie_uom.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/prodotti.php';

function qk_audit_round(float $v): float
{
    return (float) bcadd((string)$v, '0', 3);
}

function qk_audit_calcola_canonico(PDO $pdo, int $prodottoId, ?int $unitaMisuraId, float $quantitaMinima): float
{
    if ($quantitaMinima <= 0) {
        return 0.0;
    }
    $conv = qk_uom_converti_riga($pdo, $prodottoId, $unitaMisuraId, $quantitaMinima, 0.0);
    return qk_audit_round((float)$conv['quantita_stock']);
}

function qk_audit_tabella(PDO $pdo, string $etichetta, string $sql, string $colGruppo, string $colChiaveExtra = 'prodotto_id'): array
{
    $stmt = $pdo->query($sql);
    $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $coerenti = 0;
    $daConvertire = 0;
    $doppioniMascherati = [];
    $valoriPerGruppo = [];

    foreach ($righe as $r) {
        $prodottoId = (int)$r['prodotto_id'];
        $umId = $r['unita_misura_id'] !== null ? (int)$r['unita_misura_id'] : null;
        $qMin = (float)$r['quantita_minima'];
        $gruppo = $r[$colGruppo] !== null ? (int)$r[$colGruppo] : 0;
        $chiaveExtra = (int)$r[$colChiaveExtra];

        $canonico = qk_audit_calcola_canonico($pdo, $prodottoId, $umId, $qMin);

        if (qk_audit_round($qMin) === $canonico) {
            $coerenti++;
        } else {
            $daConvertire++;
        }

        $chiave = $gruppo . '_' . $chiaveExtra . '_' . $canonico;
        $valoriPerGruppo[$chiave][] = (int)$r['id'];
    }

    foreach ($valoriPerGruppo as $chiave => $ids) {
        if (count($ids) > 1) {
            $doppioniMascherati[] = ['chiave' => $chiave, 'righe_id' => $ids];
        }
    }

    echo "== {$etichetta} ==\n";
    echo "  Righe totali:                 " . count($righe) . "\n";
    echo "  Già coerenti (Stock UoM):     {$coerenti}\n";
    echo "  Da convertire in Fase 2:      {$daConvertire}\n";
    echo "  Doppioni mascherati trovati:  " . count($doppioniMascherati) . "\n";
    if ($doppioniMascherati) {
        echo "  Dettaglio doppioni (gruppo_prodotto_valoreCanonico => id righe coinvolte):\n";
        foreach ($doppioniMascherati as $d) {
            echo "    - {$d['chiave']} => [" . implode(', ', $d['righe_id']) . "]\n";
        }
    }
    echo "\n";

    return [
        'totali' => count($righe),
        'coerenti' => $coerenti,
        'da_convertire' => $daConvertire,
        'doppioni' => $doppioniMascherati,
    ];
}

echo "Audit soglie UoM — D0bis (sola lettura, nessuna scrittura)\n";
echo str_repeat('=', 60) . "\n\n";

$resVendite = qk_audit_tabella(
    $pdo,
    'listini_prezzi (vendite)',
    "SELECT id, listino_id, prodotto_id, unita_misura_id, quantita_minima FROM listini_prezzi",
    'listino_id'
);

$resAcquisti = qk_audit_tabella(
    $pdo,
    'prodotti_fornitori_prezzi (acquisti)',
    "SELECT pfp.id, pf.prodotto_id, pfp.prodotto_fornitore_id, pfp.unita_misura_id, pfp.quantita_minima,
            pfp.listino_acquisto_id AS gruppo_id
     FROM prodotti_fornitori_prezzi pfp
     JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id",
    'gruppo_id',
    'prodotto_fornitore_id'
);

$totDoppioni = count($resVendite['doppioni']) + count($resAcquisti['doppioni']);

echo str_repeat('=', 60) . "\n";
if ($totDoppioni > 0) {
    echo "ATTENZIONE: {$totDoppioni} doppioni mascherati totali trovati.\n";
    echo "Vanno risolti manualmente PRIMA di eseguire la Fase 2 (migrazione dati).\n";
} else {
    echo "Nessun doppione mascherato trovato. La Fase 2 può procedere senza conflitti da risolvere a mano.\n";
}
