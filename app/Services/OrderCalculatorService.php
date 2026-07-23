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
     */
    public function insertDirectOrder(array $values, array $righe, int $userId): int
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

            // ── Inserimento testata ordine ───────────────────────────────────────
            $magazzinoRiservaId = qk_magazzino_attivo_id($this->pdo);

            $orderData = array_merge($values, $denorm);
            $orderData['indirizzo_consegna_id'] = $indirizzoConsegnaId;
            $orderData['contatto_snapshot_id'] = $contattoSnapshotId;
            $orderData['creato_da'] = $userId;
            $orderData['magazzino_id'] = $magazzinoRiservaId;

            $ordineId = $this->repository->insertOrderHeader($orderData);

            // ── Inserimento righe ────────────────────────────────────────────────
            $umCache = [];

            foreach ($righe as $r) {
                $prodottoId = !empty($r['prodotto_id']) ? (int)$r['prodotto_id'] : null;
                $qta = (float) ($r['quantita'] ?? 0);
                $pu  = (float) ($r['prezzo_unitario'] ?? 0);
                $sc  = (float) ($r['sconto_perc'] ?? 0);
                $iva = (float) ($r['iva_perc'] ?? 22);

                $umCodice = $r['unita_misura'] ?? 'pz';
                if (!array_key_exists($umCodice, $umCache)) {
                    $umCache[$umCodice] = $this->repository->findUomIdByCode($umCodice);
                }
                $umId = $umCache[$umCodice];

                $uomResult = qk_uom_converti_riga(
                    $this->pdo, 
                    $prodottoId ? (int)$prodottoId : null, 
                    $umId ? (int)$umId : null, 
                    $qta, 
                    $pu
                );

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

                $this->repository->insertOrderLine($lineData);

                if ($prodottoId) {
                    qk_riserva_stock_ordine($this->pdo, $prodottoId, $uomResult['quantita_stock'], $magazzinoRiservaId);
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
