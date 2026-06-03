<?php
declare(strict_types=1);
/**
 * includes/acquisti_ricezioni.php
 * Funzioni DB per le Ricezioni Merce.
 *
 * La conferma di una ricezione aggiorna:
 *   - ordini_righe.quantita_ricevuta
 *   - giacenze.quantita_disponibile e quantita_in_arrivo
 *   - lotti (crea o aggiorna) se il prodotto traccia_lotti = 1
 *   - ordini_righe_pianificazione (avanza o split)
 *   - stato dell'ordine padre
 *
 * Dipende da: includes/acquisti_comune.php, includes/acquisti_ordini.php
 */

require_once __DIR__ . '/acquisti_comune.php';
require_once __DIR__ . '/acquisti_ordini.php';

// ─────────────────────────────────────────────────────────────────────────────
// LETTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Elenco ricezioni, opzionalmente filtrate per ordine o stato.
 */
function qk_ricezioni_list(PDO $pdo, int $ordine_id = 0, string $stato = ''): array
{
    $where  = ['1=1'];
    $params = [];

    if ($ordine_id > 0) {
        $where[]  = 'rt.ordine_id = ?';
        $params[] = $ordine_id;
    }
    if ($stato !== '') {
        $where[]  = 'rt.stato = ?';
        $params[] = $stato;
    }

    $st = $pdo->prepare(
        'SELECT rt.*,
                ot.numero AS ordine_numero,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome
           FROM ricezioni_testata rt
      LEFT JOIN ordini_testata    ot ON ot.id = rt.ordine_id
      LEFT JOIN clienti_fornitori cf ON cf.id = rt.fornitore_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY rt.created_at DESC'
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dettaglio ricezione: testata + righe con info prodotto e lotto.
 *
 * @return array{ricezione:array, righe:array}|null
 */
function qk_ricezione_detail(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT rt.*,
                ot.numero AS ordine_numero,
                COALESCE(cf.ragione_sociale, cf.nome || \' \' || COALESCE(cf.cognome,\'\')) AS fornitore_nome
           FROM ricezioni_testata rt
      LEFT JOIN ordini_testata    ot ON ot.id = rt.ordine_id
      LEFT JOIN clienti_fornitori cf ON cf.id = rt.fornitore_id
          WHERE rt.id = ?'
    );
    $st->execute([$id]);
    $ricezione = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ricezione) return null;

    $stRighe = $pdo->prepare(
        'SELECT rr.*,
                p.nome AS prodotto_nome, p.sku_interno, p.traccia_lotti,
                um.codice AS uom,
                l.codice_lotto_interno AS lotto_codice,
                orr.quantita_ordinata, orr.quantita_ricevuta AS qta_gia_ricevuta,
                orr.tolleranza_esubero_perc
           FROM ricezioni_righe rr
           JOIN prodotti p    ON p.id    = rr.prodotto_id
      LEFT JOIN lotti    l    ON l.id    = rr.lotto_id
      LEFT JOIN ordini_righe orr ON orr.id = rr.ordine_riga_id
      LEFT JOIN unita_misura um  ON um.id  = orr.unita_misura_id
          WHERE rr.ricezione_id = ?'
    );
    $stRighe->execute([$id]);

    return [
        'ricezione' => $ricezione,
        'righe'     => $stRighe->fetchAll(PDO::FETCH_ASSOC),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// APERTURA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Apre una nuova bozza di ricezione per un ordine.
 * Le righe vengono pre-compilate con la quantità ancora da ricevere
 * (considerando la tolleranza esubero).
 *
 * @throws RuntimeException se l'ordine non è nello stato ricevibile
 * @return int  ID della nuova ricezione
 */
function qk_ricezione_apri(PDO $pdo, int $ordine_id, int $utente_id): int
{
    $st = $pdo->prepare(
        "SELECT * FROM ordini_testata WHERE id = ? AND stato IN ('inviato','parzialmente_ricevuto')"
    );
    $st->execute([$ordine_id]);
    $ordine = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ordine) throw new \RuntimeException('Ordine non trovato o non in stato ricevibile.');

    $pdo->beginTransaction();
    try {
        $numero = qk_next_numero_acquisto($pdo, 'ricezione');

        $pdo->prepare(
            "INSERT INTO ricezioni_testata (numero, ordine_id, fornitore_id, stato, creato_da, created_at)
             VALUES (?,?,?,'bozza',?,CURRENT_TIMESTAMP)"
        )->execute([$numero, $ordine_id, $ordine['fornitore_id'], $utente_id]);
        $ricezione_id = (int) $pdo->lastInsertId();

        // Pre-compila righe con le rimanenze
        $righe = $pdo->prepare(
            'SELECT id AS ordine_riga_id, prodotto_id,
                    quantita_ordinata, quantita_ricevuta, tolleranza_esubero_perc
               FROM ordini_righe WHERE ordine_id = ?'
        );
        $righe->execute([$ordine_id]);

        $stRiga = $pdo->prepare(
            'INSERT INTO ricezioni_righe
                (ricezione_id, ordine_riga_id, prodotto_id,
                 quantita_ricevuta, quantita_accettata, quantita_rifiutata)
             VALUES (?,?,?,?,?,0)'
        );
        foreach ($righe->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $massimo   = (float)$r['quantita_ordinata'] * (1 + (float)$r['tolleranza_esubero_perc'] / 100);
            $rimanente = max(0.0, round($massimo - (float)$r['quantita_ricevuta'], 3));
            if ($rimanente <= 0) continue; // riga già completata

            $stRiga->execute([
                $ricezione_id,
                $r['ordine_riga_id'],
                $r['prodotto_id'],
                $rimanente,
                $rimanente,
            ]);
        }

        $pdo->commit();
        return $ricezione_id;

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CONFERMA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Conferma (completa) una ricezione.
 *
 * Per ogni riga:
 *   1. Aggiorna quantita_ricevuta sull'ordine_riga
 *   2. Aggiorna giacenze.quantita_disponibile
 *   3. Crea o aggiorna il lotto (se traccia_lotti = 1)
 *   4. Avanza la pianificazione consegna (split automatico se parziale)
 *   5. Decrementa giacenze.quantita_in_arrivo
 *
 * @param array $righeInput  Mappa [ricezione_riga_id => [
 *                               'quantita_accettata'  => float,
 *                               'quantita_rifiutata'  => float,
 *                               'motivo_rifiuto'      => string,
 *                               'lotto' => [
 *                                   'id'                    => int,    // lotto esistente
 *                                   'codice_lotto_interno'  => string, // nuovo lotto
 *                                   'codice_lotto_fornitore'=> string,
 *                                   'data_scadenza'         => string,
 *                                   'numero_ddt'            => string,
 *                               ]
 *                           ]]
 */
function qk_ricezione_conferma(PDO $pdo, int $ricezione_id, array $righeInput, int $utente_id): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM ricezioni_testata WHERE id = ? AND stato = 'bozza'");
        $st->execute([$ricezione_id]);
        $ricezione = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ricezione) throw new \RuntimeException('Ricezione non trovata o già completata.');

        $ordine_id = (int) $ricezione['ordine_id'];

        // Carica le righe della ricezione
        $stRighe = $pdo->prepare('SELECT * FROM ricezioni_righe WHERE ricezione_id = ?');
        $stRighe->execute([$ricezione_id]);
        $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);

        foreach ($righe as $riga) {
            $rigaId        = (int) $riga['id'];
            $ordineRigaId  = (int) $riga['ordine_riga_id'];
            $prodottoId    = (int) $riga['prodotto_id'];

            $input        = $righeInput[$rigaId] ?? [];
            $qtaAccettata = isset($input['quantita_accettata'])
                ? (float)$input['quantita_accettata']
                : (float)$riga['quantita_accettata'];
            $qtaRifiutata = isset($input['quantita_rifiutata'])
                ? (float)$input['quantita_rifiutata']
                : 0.0;
            $motivoRifiuto = $input['motivo_rifiuto'] ?? '';

            // Convert to stock UoM
            $quantita_stock = $qtaAccettata;
            $unita_misura_stock_id = null;
            
            $stProdUm = $pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id, quantita_contenuto FROM prodotti WHERE id = ?');
            $stProdUm->execute([$prodottoId]);
            $prodUm = $stProdUm->fetch();
            
            $stOrderUm = $pdo->prepare('SELECT unita_misura_id FROM ordini_righe WHERE id = ?');
            $stOrderUm->execute([$ordineRigaId]);
            $um_id = $stOrderUm->fetchColumn();
            
            if ($prodUm && $um_id) {
                $unita_misura_stock_id = (int)$prodUm['unita_misura_stock_id'];
                $content_um_id = $prodUm['unita_contenuto_id'] ? (int)$prodUm['unita_contenuto_id'] : null;
                $factor = $prodUm['quantita_contenuto'] ? (float)$prodUm['quantita_contenuto'] : 1.0;
                
                if ((int)$um_id === $unita_misura_stock_id) {
                    $quantita_stock = $qtaAccettata;
                } elseif ($content_um_id !== null && (int)$um_id === $content_um_id && $factor > 0) {
                    $quantita_stock = round($qtaAccettata / $factor, 3);
                }
            } elseif ($prodUm) {
                $unita_misura_stock_id = (int)$prodUm['unita_misura_stock_id'];
            }

            // Salva valori definitivi sulla riga ricezione
            $pdo->prepare(
                'UPDATE ricezioni_righe
                    SET quantita_accettata = ?, quantita_rifiutata = ?, motivo_rifiuto = ?,
                        quantita_stock = ?, unita_misura_stock_id = ?
                  WHERE id = ?'
            )->execute([$qtaAccettata, $qtaRifiutata, $motivoRifiuto, $quantita_stock, $unita_misura_stock_id, $rigaId]);

            if ($qtaAccettata <= 0) continue;

            // Gestione lotto (usando quantita_stock)
            $lotto_id = _ricezione_gestisci_lotto(
                $pdo,
                $prodottoId,
                (int)$ricezione['fornitore_id'],
                $ordine_id,
                $rigaId,
                $quantita_stock,
                $input['lotto'] ?? [],
                $utente_id
            );
            if ($lotto_id) {
                $pdo->prepare('UPDATE ricezioni_righe SET lotto_id = ? WHERE id = ?')
                   ->execute([$lotto_id, $rigaId]);
            }

            // Aggiorna quantita_ricevuta sull'ordine_riga
            $pdo->prepare(
                'UPDATE ordini_righe SET quantita_ricevuta = quantita_ricevuta + ? WHERE id = ?'
            )->execute([$qtaAccettata, $ordineRigaId]);

            // Aggiorna giacenza disponibile (usando quantita_stock)
            _ricezione_upsert_giacenza($pdo, $prodottoId, $quantita_stock);

            // Decrementa in_arrivo (usando quantita_stock)
            $pdo->prepare(
                'UPDATE giacenze
                    SET quantita_in_arrivo = MAX(0, quantita_in_arrivo - ?)
                  WHERE prodotto_id = ?'
            )->execute([$quantita_stock, $prodottoId]);

            // Avanza pianificazione consegna
            _ricezione_avanza_pianificazione($pdo, $ordineRigaId, $qtaAccettata);
        }

        // Aggiorna stato ordine padre
        qk_ordine_aggiorna_stato_ricezione($pdo, $ordine_id);

        // Chiude la ricezione
        $pdo->prepare(
            "UPDATE ricezioni_testata
                SET stato = 'completata', data_effettiva = CURRENT_TIMESTAMP
              WHERE id = ?"
        )->execute([$ricezione_id]);

        $pdo->commit();

    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER PRIVATI
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Gestisce la logica lotti durante una ricezione.
 * Ritorna l'ID del lotto (esistente o appena creato), oppure null se il prodotto
 * non traccia i lotti.
 */
function _ricezione_gestisci_lotto(
    PDO    $pdo,
    int    $prodotto_id,
    int    $fornitore_id,
    int    $ordine_id,
    int    $ricezione_riga_id,
    float  $qta,
    array  $lottoData,
    int    $utente_id
): ?int {
    $traccia = $pdo->prepare('SELECT traccia_lotti FROM prodotti WHERE id = ?');
    $traccia->execute([$prodotto_id]);
    if (!(bool)$traccia->fetchColumn()) return null;

    if (!empty($lottoData['id'])) {
        // Lotto esistente: aggiorna disponibile e ricevuto
        $lot_id = (int)$lottoData['id'];
        $pdo->prepare(
            'UPDATE lotti
                SET quantita_disponibile = quantita_disponibile + ?,
                    quantita_ricevuta    = quantita_ricevuta    + ?
              WHERE id = ?'
        )->execute([$qta, $qta, $lot_id]);
        return $lot_id;
    }

    if (!empty($lottoData['codice_lotto_interno'])) {
        // Nuovo lotto
        $pdo->prepare(
            "INSERT INTO lotti
                (codice_lotto, codice_lotto_interno, codice_lotto_fornitore,
                 prodotto_id, fornitore_id, ordine_testata_id, ricezione_riga_id,
                 data_scadenza, data_arrivo, numero_ddt_arrivo,
                 quantita_ricevuta, quantita_disponibile,
                 creato_da, created_at)
             VALUES (?,?,?,?,?,?,?,?,DATE('now'),?,?,?,?,CURRENT_TIMESTAMP)"
        )->execute([
            $lottoData['codice_lotto_interno'], // colonna legacy codice_lotto (NOT NULL)
            $lottoData['codice_lotto_interno'],
            $lottoData['codice_lotto_fornitore'] ?? '',
            $prodotto_id,
            $fornitore_id,
            $ordine_id,
            $ricezione_riga_id,
            ($lottoData['data_scadenza'] ?? '') !== '' ? $lottoData['data_scadenza'] : null,
            ($lottoData['numero_ddt'] ?? '') !== '' ? $lottoData['numero_ddt'] : null,
            $qta,
            $qta,
            $utente_id,
        ]);
        return (int) $pdo->lastInsertId();
    }

    return null; // Prodotto traccia lotti ma nessun dato lotto fornito (validazione upstream)
}

/**
 * Inserisce o aggiorna la giacenza disponibile di un prodotto.
 */
function _ricezione_upsert_giacenza(PDO $pdo, int $prodotto_id, float $delta): void
{
    $exists = $pdo->prepare('SELECT id FROM giacenze WHERE prodotto_id = ?');
    $exists->execute([$prodotto_id]);
    if ($exists->fetch()) {
        $pdo->prepare(
            'UPDATE giacenze
                SET quantita_disponibile = quantita_disponibile + ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE prodotto_id = ?'
        )->execute([$delta, $prodotto_id]);
    } else {
        $pdo->prepare(
            'INSERT INTO giacenze (prodotto_id, quantita_disponibile, updated_at)
             VALUES (?,?,CURRENT_TIMESTAMP)'
        )->execute([$prodotto_id, $delta]);
    }
}

/**
 * Avanza la pianificazione consegna per una riga ordine dopo una ricezione.
 * Se la quantità ricevuta supera la tranche corrente, crea uno split automatico
 * per l'eccesso.
 */
function _ricezione_avanza_pianificazione(PDO $pdo, int $ordine_riga_id, float $qtaRicevuta): void
{
    $st = $pdo->prepare(
        "SELECT * FROM ordini_righe_pianificazione
          WHERE ordine_riga_id = ? AND stato != 'completata'
          ORDER BY data_consegna_prevista, id LIMIT 1"
    );
    $st->execute([$ordine_riga_id]);
    $tranche = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tranche) return;

    $trancheId      = (int)$tranche['id'];
    $giàRicevuta    = (float)$tranche['quantita_ricevuta'];
    $pianificata    = (float)$tranche['quantita_pianificata'];
    $totaleRicevuta = $giàRicevuta + $qtaRicevuta;

    if ($totaleRicevuta >= $pianificata) {
        $pdo->prepare(
            "UPDATE ordini_righe_pianificazione
                SET quantita_ricevuta = ?, stato = 'completata'
              WHERE id = ?"
        )->execute([$totaleRicevuta, $trancheId]);

        $eccesso = round($totaleRicevuta - $pianificata, 3);
        if ($eccesso > 0.001) {
            // Split automatico per l'eccesso rispetto alla tranche
            $pdo->prepare(
                "INSERT INTO ordini_righe_pianificazione
                    (ordine_riga_id, riga_padre_id, quantita_pianificata, quantita_ricevuta, stato)
                 VALUES (?,?,?,?,'completata')"
            )->execute([$ordine_riga_id, $trancheId, $eccesso, $eccesso]);
        }
    } else {
        $pdo->prepare(
            "UPDATE ordini_righe_pianificazione
                SET quantita_ricevuta = ?, stato = 'ricevuta_parziale'
              WHERE id = ?"
        )->execute([$totaleRicevuta, $trancheId]);
    }
}
