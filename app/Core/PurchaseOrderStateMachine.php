<?php

declare(strict_types=1);

/**
 * PurchaseOrderStateMachine — Gestione degli stati e delle transizioni degli ordini d'acquisto.
 */
final class PurchaseOrderStateMachine
{
    private PDO $pdo;

    private const TRANSIZIONI_VALIDE = [
        'bozza' => ['inviato', 'annullato'],
        'inviato' => ['confermato', 'annullato'],
        'confermato' => ['ricevuto', 'parzialmente_ricevuto', 'annullato'],
        'parzialmente_ricevuto' => ['ricevuto', 'annullato'],
        'ricevuto' => [],
        'annullato' => [],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Esegue la transizione di stato di un ordine d'acquisto.
     * Lancia un'eccezione in caso di errore.
     *
     * @param array  $order          Dati dell'ordine (deve contenere 'id' e 'stato')
     * @param string $requestedState Lo stato di destinazione richiesto
     * @throws Exception
     */
    public function transition(array $order, string $requestedState): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            throw new Exception("Ordine d'acquisto non valido.");
        }

        // Rilegge lo stato e il fornitore corrente dal DB per sicurezza/concorrenza
        $stmt = $this->pdo->prepare('SELECT stato, fornitore_id FROM ordini_acquisto WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentOrder) {
            throw new Exception("Ordine d'acquisto non trovato.");
        }

        $currentState = $currentOrder['stato'] ?? 'bozza';

        // Passo 1 — Controllo strutturale (la mappa delle transizioni)
        $validTargets = self::TRANSIZIONI_VALIDE[$currentState] ?? [];
        if (!in_array($requestedState, $validTargets, true)) {
            $labelCurrent = status_label($currentState);
            $labelTarget  = status_label($requestedState);
            throw new Exception("Transizione di stato non consentita da '{$labelCurrent}' a '{$labelTarget}'.", 422);
        }

        // Passo 2 — Controllo di business (regole specifiche per transizione)
        if ($requestedState === 'confermato') {
            // 1. Il fornitore è valorizzato?
            if (empty($currentOrder['fornitore_id'])) {
                throw new Exception("Seleziona un fornitore.");
            }

            // Carica le righe dell'ordine
            $stmtRows = $this->pdo->prepare(
                'SELECT id, posizione_riga, prezzo_unitario FROM ordini_acquisto_righe WHERE ordine_id = :id ORDER BY posizione_riga ASC'
            );
            $stmtRows->execute([':id' => $orderId]);
            $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

            // 2. Ci sono righe?
            if (empty($rows)) {
                throw new Exception("Aggiungi almeno un prodotto.");
            }

            // 3. Tutte le righe hanno prezzo?
            foreach ($rows as $index => $row) {
                if ($row['prezzo_unitario'] === null || $row['prezzo_unitario'] === '') {
                    $rigaNumero = $index + 1; // 1-indexed per facilitare la lettura all'utente
                    throw new Exception("Prezzo mancante sulla riga {$rigaNumero}.");
                }
            }
        }

        // Esegui la transizione
        $extraSet = '';
        $params = [
            ':stato' => $requestedState,
            ':id'    => $orderId,
        ];

        if ($requestedState === 'inviato') {
            $extraSet = ', inviato_il = CURRENT_TIMESTAMP';
        } elseif ($requestedState === 'confermato') {
            $extraSet = ', confermato_il = CURRENT_TIMESTAMP';
        }

        $sql = "UPDATE ordini_acquisto 
                   SET stato = :stato{$extraSet}, 
                       updated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id";

        $this->pdo->prepare($sql)->execute($params);

        // Scrittura nel log stati ordini
        $logStmt = $this->pdo->prepare(
            "INSERT INTO log_stati_ordini (tipo_documento, documento_id, stato_precedente, stato_nuovo, cambiato_da, cambiato_il)
             VALUES ('ordine_acquisto', :doc_id, :prev, :new, :user_id, CURRENT_TIMESTAMP)"
        );
        $logStmt->execute([
            ':doc_id'  => $orderId,
            ':prev'    => $currentState,
            ':new'     => $requestedState,
            ':user_id' => (int) (current_user()['id'] ?? 0),
        ]);
    }
}
