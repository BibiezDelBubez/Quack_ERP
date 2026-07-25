<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repositories\SalesOrderRepository;
use Throwable;

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
            $orderData['creato_da'] = $userId;
            $orderData['magazzino_id'] = $magazzinoRiservaId;

            // Audit trail vendita allo scoperto (P4, §3.5): valorizzato SOLO
            // se il chiamante ha già rilevato e fatto confermare righe
            // scoperte. Vuoto -> le 3 colonne restano NULL (default della
            // Repository), come per qualunque ordine senza scoperto.
            if (!empty($venditaScopertoDettaglio)) {
                $orderData['vendita_scoperto_confermata_da'] = $userId;
                $orderData['vendita_scoperto_confermata_at'] = date('Y-m-d H:i:s');
                $orderData['vendita_scoperto_dettaglio'] = json_encode($venditaScopertoDettaglio);
            }

            $ordineId = $this->repository->insertOrderHeader($orderData);

            // ── Inserimento righe ────────────────────────────────────────────────
            // Conversione UoM centralizzata in qk_calcola_righe_stock_ordine()
            // (includes/sales_orders.php) — stessa funzione già usata dal
            // controller per il controllo di disponibilità PRIMA di questa
            // chiamata (Tappa 4), invece di un loop di conversione duplicato
            // qui dentro. Stesso ordine di $righe in input.
            $righeStock = qk_calcola_righe_stock_ordine($this->pdo, $righe);

            foreach ($righe as $idx => $r) {
                $prodottoId = $righeStock[$idx]['prodotto_id'];
                $umId       = $righeStock[$idx]['unita_misura_id'];
                $uomResult  = $righeStock[$idx]['uom'];
                $qta = (float) ($r['quantita'] ?? 0);
                $pu  = (float) ($r['prezzo_unitario'] ?? 0);
                $sc  = (float) ($r['sconto_perc'] ?? 0);
                $iva = (float) ($r['iva_perc'] ?? 22);

                $lineData = [
                    'ordine_id' => $ordineId,
                    'prodotto_id' => $prodottoId,
                    'descrizione' => $r['descrizione'],
                    'quantita_ordinata' => $qta,
                    'unita_misura_id' => $umId,
                    'prezzo_unitario' => $pu,
                    'sconto_perc' => $sc,
                    'iva_perc' => $iva,
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

                $rigaOrdineId = $this->repository->insertOrderLine($lineData);

                if ($prodottoId) {
                    // $rigaOrdineId passato per congelare lo snapshot Kit (AUDIT 3 §3.3).
                    qk_riserva_stock_ordine($this->pdo, $prodottoId, $uomResult['quantita_stock'], $magazzinoRiservaId, $rigaOrdineId);
                }
            }

            $this->pdo->commit();
            return $ordineId;

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
