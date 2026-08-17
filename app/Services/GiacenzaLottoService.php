<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * GiacenzaLottoService — punto di scrittura unico per l'aggiornamento della
 * giacenza aggregata (prodotto+magazzino, tabella `giacenze`) e, quando
 * pertinente, della giacenza di dettaglio per lotto (lotto+magazzino,
 * tabella `lotti_giacenze`).
 *
 * PIANO - Coerenza Strutturale Giacenze e Lotti, Tappa 3 (14/08/2026):
 * prima di questa classe lo stesso upsert (SELECT poi UPDATE-o-INSERT su
 * `giacenze`; INSERT...ON CONFLICT/ON DUPLICATE KEY su `lotti_giacenze`) era
 * duplicato in modo indipendente in 5 punti di scrittura distinti:
 *   - qk_insert_movimento_manuale()        (includes/magazzino.php)
 *   - qk_applica_rettifiche_inventario()   (includes/inventario.php)
 *   - qk_ricezione_conferma(), via
 *     _ricezione_upsert_giacenza()/_ricezione_gestisci_lotto()
 *                                           (includes/purchases_receipts.php)
 *   - SpedizioneRepository::aggiornaLottoGiacenza() (lotti_giacenze)
 *                                           (app/Repositories/SpedizioneRepository.php)
 *   - qk_crea_lotti_conversione_iniziale() (includes/magazzino.php, Tappa 1/2
 *     dello stesso piano)
 * Cinque copie indipendenti dello stesso pattern, con differenze non
 * intenzionali tra loro (`datetime('now')` vs `CURRENT_TIMESTAMP`, valore
 * assoluto calcolato in PHP vs incremento SQL diretto) — nessuna di queste
 * differenze produce oggi un comportamento osservabile diverso (l'aritmetica
 * finale coincide sempre, dentro la stessa transazione), ma la duplicazione
 * stessa è il rischio strutturale che questa Tappa elimina: una riga corretta
 * in un punto e non negli altri 4 diverge silenziosamente (§3 del piano).
 *
 * Nessuna transazione propria (stesso principio già in uso in
 * SpedizioneRepository/SalesOrderRepository, vedi i rispettivi commenti di
 * classe): ogni chiamante deve invocare questo servizio dentro una
 * transazione già aperta da lui.
 *
 * NON gestisce le validazioni di business (es. "non far scendere la giacenza
 * sotto zero"): quelle restano nel chiamante, eseguite PRIMA di invocare
 * applicaDelta(), perché variano da un punto di scrittura all'altro (soglie
 * diverse — es. -0.0005 di tolleranza in rettifica-su-lotto vs 0 esatto in
 * scarico —, messaggi d'errore diversi). Consolidarle qui sarebbe un cambio
 * di comportamento, non una pura estrazione DRY: fuori scope per questa
 * Tappa (CLAUDE.md, punto 8 — non decidere/ampliare lo scope senza permesso
 * esplicito).
 */
class GiacenzaLottoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Applica un delta con segno alla giacenza aggregata di (prodotto,
     * magazzino) e, se $lottoId è specificato, un delta alla giacenza di
     * quel lotto in quel magazzino — di norma lo stesso $deltaAggregato
     * (caso comune a tutti i 5 punti di scrittura originali tranne uno),
     * salvo quando esplicitamente diverso via $deltaLotto (necessario per
     * qk_crea_lotti_conversione_iniziale: converte giacenza aggregata già
     * esistente in un nuovo lotto, quindi $deltaAggregato è 0 — l'aggregato
     * non cambia — ma $deltaLotto è la quantità piena del nuovo lotto).
     *
     * Esegue sempre un upsert su entrambe le tabelle: se la riga non esiste
     * ancora, viene creata con il delta come valore iniziale assoluto —
     * corretto per definizione, perché l'assenza della riga implica un saldo
     * precedente pari a zero.
     */
    public function applicaDelta(
        int $prodottoId,
        int $magazzinoId,
        float $deltaAggregato,
        ?int $lottoId = null,
        ?float $deltaLotto = null
    ): void {
        $this->applicaDeltaAggregato($prodottoId, $magazzinoId, $deltaAggregato);

        if ($lottoId !== null) {
            $this->applicaDeltaLotto($lottoId, $magazzinoId, $deltaLotto ?? $deltaAggregato);
        }
    }

    /**
     * Applica il delta SOLO all'aggregato (prodotto, magazzino), senza
     * toccare alcun lotto. Pubblico (non solo tramite applicaDelta()) per
     * SpedizioneRepository::decrementaGiacenzaDisponibile() — vedi il
     * commento in quella classe sul perché quel punto di scrittura resta un
     * UPDATE puro (nessun upsert) invece di usare questo metodo, a
     * differenza di aggiornaLottoGiacenza() che invece lo usa (Tappa 3,
     * sotto-tappa 3.d).
     */
    public function applicaDeltaAggregato(int $prodottoId, int $magazzinoId, float $delta): void
    {
        $st = $this->pdo->prepare(
            'UPDATE giacenze
                SET quantita_disponibile = quantita_disponibile + ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE prodotto_id = ? AND magazzino_id = ?'
        );
        $st->execute([$delta, $prodottoId, $magazzinoId]);

        if ($st->rowCount() === 0) {
            $this->pdo->prepare(
                'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                 VALUES (?, ?, ?, 0, 0, 0, CURRENT_TIMESTAMP)'
            )->execute([$prodottoId, $magazzinoId, $delta]);
        }
    }

    /**
     * Applica il delta SOLO al lotto (lotto, magazzino), senza toccare
     * l'aggregato. Pubblico per SpedizioneRepository::aggiornaLottoGiacenza()
     * (Tappa 3, sotto-tappa 3.d): quel metodo scriveva già un upsert
     * byte-per-byte equivalente a questo (stesso INSERT...ON CONFLICT DO
     * UPDATE SET quantita_disponibile = quantita_disponibile + delta),
     * quindi la migrazione qui è una pura deduplicazione, senza le
     * complicazioni di applicaDeltaAggregato() (vedi sopra).
     */
    public function applicaDeltaLotto(int $lottoId, int $magazzinoId, float $delta): void
    {
        // Upsert atomico in una singola istruzione (a differenza dell'upsert
        // su `giacenze` sopra, qui la sintassi diverge davvero tra i due
        // driver: non solo il nome della funzione data/ora — vedi CLAUDE.md
        // punto 7, schema doppio SQLite/MySQL).
        $sql = (DB_DRIVER === 'mysql')
            ? 'INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
               VALUES (?, ?, ?, CURRENT_TIMESTAMP)
               ON DUPLICATE KEY UPDATE
                   quantita_disponibile = quantita_disponibile + VALUES(quantita_disponibile),
                   updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO lotti_giacenze (lotto_id, magazzino_id, quantita_disponibile, updated_at)
               VALUES (?, ?, ?, CURRENT_TIMESTAMP)
               ON CONFLICT(lotto_id, magazzino_id) DO UPDATE SET
                   quantita_disponibile = quantita_disponibile + excluded.quantita_disponibile,
                   updated_at = excluded.updated_at';

        $this->pdo->prepare($sql)->execute([$lottoId, $magazzinoId, $delta]);
    }
}
