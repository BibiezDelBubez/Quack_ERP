<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ListinoPrezziRepository — accesso dati per l'import massivo di prezzi
 * listino.
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 8
 * (23/07/2026, problema 4.C.2): estrae le 7 query dirette prima embedded in
 * SalesPricelistsController::apiImportPrezzi(). Nessuna transazione propria:
 * riceve $pdo già dentro la transazione aperta dal Service chiamante
 * (App\Services\ListinoImportService::importaRighe()).
 */
class ListinoPrezziRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function trovaProdottoPerSku(string $sku): ?array
    {
        $st = $this->pdo->prepare('SELECT id, unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE sku_interno = :s AND attivo = 1');
        $st->execute([':s' => $sku]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function risolviUnitaMisuraPerCodice(string $codice): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM unita_misura WHERE codice = :c');
        $st->execute([':c' => $codice]);
        $val = $st->fetchColumn();
        return $val ? (int) $val : null;
    }

    public function trovaRigaEsistente(int $listinoId, int $prodottoId, float $qMinStock): ?array
    {
        $st = $this->pdo->prepare("
            SELECT id, importato_il, modificato_manualmente_il FROM listini_prezzi
            WHERE listino_id = :lid AND prodotto_id = :pid AND quantita_minima_stock = :qmin_stock
        ");
        $st->execute([
            ':lid'        => $listinoId,
            ':pid'        => $prodottoId,
            ':qmin_stock' => $qMinStock,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function aggiornaRiga(int $rigaId, array $d): void
    {
        $this->pdo->prepare("
            UPDATE listini_prezzi SET
                prezzo_unitario = :prezzo,
                sconto_perc = :sconto,
                note = :note,
                quantita_minima_intento = :qmin_intento,
                unita_misura_soglia_id  = :um_soglia_id,
                quantita_minima_stock   = :qmin_stock,
                importato_il = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':prezzo'       => $d['prezzo_unitario'],
            ':sconto'       => $d['sconto_perc'],
            ':note'         => $d['note'],
            ':qmin_intento' => $d['quantita_minima_intento'],
            ':um_soglia_id' => $d['unita_misura_soglia_id'],
            ':qmin_stock'   => $d['quantita_minima_stock'],
            ':id'           => $rigaId,
        ]);
    }

    public function inserisciRiga(array $d): int
    {
        $this->pdo->prepare("
            INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, unita_misura_id, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, importato_il)
            VALUES (:lid, :pid, :qmin, :prezzo, :sconto, :umid, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)
        ")->execute([
            ':lid'          => $d['listino_id'],
            ':pid'          => $d['prodotto_id'],
            ':qmin'         => $d['quantita_minima'],
            ':prezzo'       => $d['prezzo_unitario'],
            ':sconto'       => $d['sconto_perc'],
            ':umid'         => $d['unita_misura_id'],
            ':note'         => $d['note'],
            ':qmin_intento' => $d['quantita_minima_intento'],
            ':um_soglia_id' => $d['unita_misura_soglia_id'],
            ':qmin_stock'   => $d['quantita_minima_stock'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Righe di listini_prezzi che provengono da un import precedente per
     * questo listino (importato_il valorizzato) ma non sono state
     * "toccate" da quello corrente — candidate alla rimozione come orfane.
     */
    public function trovaRigheOrfane(int $listinoId, array $touchedIds): array
    {
        $st = $this->pdo->prepare("SELECT id FROM listini_prezzi WHERE listino_id = :lid AND importato_il IS NOT NULL");
        $st->execute([':lid' => $listinoId]);
        $allImportate = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_diff($allImportate, $touchedIds));
    }

    public function rimuoviRighe(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("DELETE FROM listini_prezzi WHERE id IN ($placeholders)");
        $st->execute(array_values($ids));
        return count($ids);
    }
}
