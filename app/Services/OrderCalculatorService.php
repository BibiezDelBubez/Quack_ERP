<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repositories\SalesOrderRepository;
use Throwable;

// qk_ordine_cliente_salva() (PIANO - Unificazione Creazione Ordine Cliente,
// eseguito il 26/07/2026) vive in includes/sales_orders.php — richiesta qui
// in testa al file (CLAUDE.md punto 4), non lasciata al caso che il
// chiamante di questa classe (qk_insert_ordine_diretto(), stesso file)
// l'abbia già inclusa per un altro motivo.
require_once __DIR__ . '/../../includes/sales_orders.php';

class OrderCalculatorService
{
    private PDO $pdo;
    private SalesOrderRepository $repository;

    public function __construct(PDO $pdo, SalesOrderRepository $repository)
    {
        $this->pdo = $pdo;
        $this->repository = $repository;
    }

    /**
     * Inserisce un ordine cliente diretto (senza preventivo di origine).
     * Restituisce l'ID del nuovo ordine.
     *
     * @param array $venditaScopertoDettaglio Righe scoperte già rilevate e
     *        confermate esplicitamente dall'utente PRIMA di questa chiamata
     *        (PIANO - Vendita Allo Scoperto Controllata, P4, §3.3/§3.5): il
     *        controllo di disponibilità (qk_verifica_disponibilita_ordine())
     *        vive nel controller, non qui — questo metodo si limita a
     *        scrivere l'audit trail se gli arriva un dettaglio non vuoto.
     *        Vuoto (default): nessuna riga scoperta, nessuna colonna di
     *        audit trail scritta (restano NULL). La riserva stock stessa
     *        (qk_riserva_stock_ordine() più sotto) resta invariata in
     *        entrambi i casi: la vendita allo scoperto è sempre permessa.
     */
    /**
     * @param int $magazzinoId Magazzino di evasione scelto ESPLICITAMENTE
     *        dall'operatore sul form (PIANO - Magazzino Esplicito alla
     *        Conferma Ordine, P4b), gia validato dal chiamante con
     *        qk_valida_magazzino_ordine_esplicito(). Non risolto piu qui
     *        internamente da qk_magazzino_attivo_id() (sessione) - quel
     *        comportamento era esattamente il problema P4b.
     */
    public function insertDirectOrder(array $values, array $righe, int $userId, int $magazzinoId, array $venditaScopertoDettaglio = []): int
    {
        // PIANO - Unificazione Creazione Ordine Cliente (eseguito il
        // 26/07/2026): questo metodo non scrive più direttamente
        // ordini_clienti/ordini_clienti_righe tramite
        // $this->repository->insertOrderHeader()/::insertOrderLine() — le
        // costruisce ancora (righe invariate rispetto a prima), ma la
        // SCRITTURA vera e propria è delegata a qk_ordine_cliente_salva()
        // (includes/sales_orders.php), stesso identico punto usato anche dal
        // percorso "da preventivo" (qk_preventivo_conferma_ordine()).
        //
        // La transazione esterna resta qui (invariata) per includere anche
        // la creazione degli snapshot indirizzo/contatto sotto, che devono
        // restare atomici col resto esattamente come prima di questo piano;
        // qk_ordine_cliente_salva() rileva la transazione già aperta
        // ($pdo->inTransaction()) e si appoggia ad essa invece di aprirne
        // una propria annidata (stesso pattern già usato da
        // qk_next_sequence_number(), verificato con un test dedicato nella
        // suite tests/manual/suite_ordine_cliente_salva_unificata.php §5).
        $this->pdo->beginTransaction();
        try {
            $denorm = qk_denormalize_document_details($this->pdo, $values);

            // ── Creazione snapshot strutturati (indirizzo e contatto) ───────────
            $addrPayload = [
                'indirizzo_master_id' => $values['indirizzo_spedizione_id'] ?? null,
                'via' => $denorm['consegna_via'],
                'civico' => $denorm['consegna_civico'],
                'cap' => $denorm['consegna_cap'],
                'citta' => $denorm['consegna_citta'],
                'provincia' => $denorm['consegna_provincia'],
                'nazione' => $denorm['consegna_nazione'],
                'presso' => $denorm['consegna_presso'],
                'referente_consegna' => $denorm['consegna_referente'],
                'telefono_consegna' => $denorm['consegna_telefono'],
                'note_consegna' => $denorm['consegna_note'],
            ];
            $indirizzoConsegnaId = qk_upsert_document_address($this->pdo, $addrPayload);

            $contPayload = [
                'contatto_master_id' => $values['contatto_id'] ?? null,
                'nome' => $values['contatto_nome'] ?? '',
                'email' => $values['contatto_email'] ?? '',
                'telefono' => $values['contatto_telefono'] ?? '',
            ];
            $contattoSnapshotId = qk_upsert_document_contact($this->pdo, $contPayload);

            // Inserimento testata ordine. Magazzino di evasione: ricevuto
            // esplicito dal chiamante (P4b) - non piu risolto qui da
            // qk_magazzino_attivo_id() (sessione).
            $magazzinoRiservaId = $magazzinoId;

            $orderData = array_merge($values, $denorm);
            $orderData['indirizzo_consegna_id'] = $indirizzoConsegnaId;
            $orderData['contatto_snapshot_id'] = $contattoSnapshotId;
            $orderData['magazzino_id'] = $magazzinoRiservaId;
            // NB: 'creato_da' non entra più in $orderData — qk_ordine_cliente_salva()
            // lo riceve come parametro esplicito $userId (usato SEMPRE per la
            // colonna creato_da, mai letto da $data), non da questo array.

            // ── Righe in forma canonica ──────────────────────────────────────────
            // Conversione UoM centralizzata in qk_calcola_righe_stock_ordine()
            // (includes/sales_orders.php) — stessa funzione già usata dal
            // controller per il controllo di disponibilità PRIMA di questa
            // chiamata (Tappa 4), invece di un loop di conversione duplicato
            // qui dentro. Stesso ordine di $righe in input. Stessa forma già
            // costruita PRIMA di questo piano (solo non più passata alla
            // Repository riga per riga, ma accumulata e passata in blocco a
            // qk_ordine_cliente_salva() sotto).
            $righeStock = qk_calcola_righe_stock_ordine($this->pdo, $righe);

            $righeCanoniche = [];
            foreach ($righe as $idx => $r) {
                $prodottoId = $righeStock[$idx]['prodotto_id'];
                $umId       = $righeStock[$idx]['unita_misura_id'];
                $uomResult  = $righeStock[$idx]['uom'];

                $righeCanoniche[] = [
                    'prodotto_id' => $prodottoId,
                    'descrizione' => $r['descrizione'],
                    'quantita_ordinata' => (float) ($r['quantita'] ?? 0),
                    'unita_misura_id' => $umId,
                    'prezzo_unitario' => (float) ($r['prezzo_unitario'] ?? 0),
                    'sconto_perc' => (float) ($r['sconto_perc'] ?? 0),
                    'iva_perc' => (float) ($r['iva_perc'] ?? 22),
                    'costo_unitario' => (float) ($r['costo_unitario'] ?? 0),
                    'quantita_confezione' => $r['quantita_confezione'] ?? null,
                    'quantita_contenuto_snapshot' => $uomResult['quantita_contenuto_snapshot'],
                    'num_factor_snapshot' => $uomResult['num_factor_snapshot'],
                    'den_factor_snapshot' => $uomResult['den_factor_snapshot'],
                    'quantita_stock' => $uomResult['quantita_stock'],
                    'system_unit_price_stock' => $uomResult['system_unit_price_stock'],
                    'unita_misura_stock_id' => $uomResult['unita_misura_stock_id'],
                    'note' => $r['note_riga'] ?? null,
                ];
            }

            $ordineId = qk_ordine_cliente_salva(
                $this->pdo,
                $orderData,
                $righeCanoniche,
                $userId,
                $magazzinoRiservaId,
                null, // preventivoId: percorso diretto, nessun preventivo di origine.
                $venditaScopertoDettaglio,
                // $dataOrdine ESPLICITO: la data scelta dall'utente sul form
                // (Repository::insertOrderHeader() la leggeva direttamente da
                // $data['data_ordine'], obbligatoria — qk_ordine_diretto_validate()
                // la richiede). Senza passarla qui esplicitamente,
                // qk_ordine_cliente_salva() userebbe il proprio default "oggi",
                // scartando silenziosamente la data scelta dall'utente — bug
                // trovato e corretto in questa stessa sessione PRIMA di
                // eseguire qualunque test sul percorso da preventivo.
                $orderData['data_ordine'] ?? null
            );

            $this->pdo->commit();
            return $ordineId;

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
