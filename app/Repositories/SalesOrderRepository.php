<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SalesOrderRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getNextSequenceNumber(): string
    {
        return qk_next_sequence_number($this->pdo, 'ordine_cliente');
    }

    public function insertOrderHeader(array $data): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO ordini_clienti (
                numero_ordine, cliente_id, preventivo_id, azienda_interna_id,
                data_ordine, data_consegna_prevista,
                stato, stato_fatturazione,
                riferimento_interno, condizioni_pagamento, trasporto, valuta,
                listino_id, note,
                indirizzo_spedizione_id,
                consegna_via, consegna_civico, consegna_cap, consegna_citta,
                consegna_provincia, consegna_nazione, consegna_presso,
                consegna_referente, consegna_telefono, consegna_note,
                contatto_id, contatto_nome, contatto_email, contatto_telefono,
                indirizzo_consegna_id, contatto_snapshot_id,
                azienda_ragione_sociale, azienda_partita_iva, azienda_codice_fiscale, azienda_indirizzo, azienda_telefono, azienda_email, azienda_iban,
                cliente_ragione_sociale, cliente_partita_iva, cliente_codice_fiscale, cliente_indirizzo,
                creato_da, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $st->execute([
            $data['numero_ordine'],
            $data['cliente_id'],
            $data['preventivo_id'] ?? null,
            $data['azienda_interna_id'] ?? null,
            $data['data_ordine'],
            $data['data_consegna_prevista'] ?? null,
            $data['stato'] ?? 'in_lavorazione',
            $data['stato_fatturazione'] ?? 'da_fatturare',
            $data['riferimento_interno'] ?? null,
            $data['condizioni_pagamento'] ?? null,
            $data['trasporto'] ?? null,
            $data['valuta'] ?? 'EUR',
            $data['listino_id'] ?? 1,
            $data['note'] ?? null,
            $data['indirizzo_spedizione_id'] ?? null,
            $data['consegna_via'] ?? null,
            $data['consegna_civico'] ?? null,
            $data['consegna_cap'] ?? null,
            $data['consegna_citta'] ?? null,
            $data['consegna_provincia'] ?? null,
            $data['consegna_nazione'] ?? 'IT',
            $data['consegna_presso'] ?? null,
            $data['consegna_referente'] ?? null,
            $data['consegna_telefono'] ?? null,
            $data['consegna_note'] ?? null,
            $data['contatto_id'] ?? null,
            $data['contatto_nome'] ?? null,
            $data['contatto_email'] ?? null,
            $data['contatto_telefono'] ?? null,
            $data['indirizzo_consegna_id'] ?? null,
            $data['contatto_snapshot_id'] ?? null,
            $data['azienda_ragione_sociale'] ?? null,
            $data['azienda_partita_iva'] ?? null,
            $data['azienda_codice_fiscale'] ?? null,
            $data['azienda_indirizzo'] ?? null,
            $data['azienda_telefono'] ?? null,
            $data['azienda_email'] ?? null,
            $data['azienda_iban'] ?? null,
            $data['cliente_ragione_sociale'] ?? null,
            $data['cliente_partita_iva'] ?? null,
            $data['cliente_codice_fiscale'] ?? null,
            $data['cliente_indirizzo'] ?? null,
            $data['creato_da']
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertOrderLine(array $data): void
    {
        $stRiga = $this->pdo->prepare("
            INSERT INTO ordini_clienti_righe (
                ordine_id, prodotto_id, descrizione, quantita_ordinata, unita_misura_id,
                prezzo_unitario, sconto_perc, iva_perc, costo_unitario,
                quantita_confezione, quantita_contenuto_snapshot,
                num_factor_snapshot, den_factor_snapshot,
                quantita_stock, system_unit_price_stock, unita_misura_stock_id,
                quantita_spedita, note,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                0, ?,
                CURRENT_TIMESTAMP
            )
        ");

        $stRiga->execute([
            $data['ordine_id'],
            $data['prodotto_id'] ?? null,
            $data['descrizione'],
            $data['quantita_ordinata'],
            $data['unita_misura_id'] ?? null,
            $data['prezzo_unitario'],
            $data['sconto_perc'],
            $data['iva_perc'],
            $data['costo_unitario'],
            $data['quantita_confezione'] ?? null,
            $data['quantita_contenuto_snapshot'] ?? null,
            $data['num_factor_snapshot'] ?? null,
            $data['den_factor_snapshot'] ?? null,
            round((float)$data['quantita_stock'], 3),
            $data['system_unit_price_stock'],
            $data['unita_misura_stock_id'],
            $data['note'] ?? null,
        ]);
    }

    public function findUomIdByCode(string $code): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM unita_misura WHERE codice = ? LIMIT 1");
        $st->execute([$code]);
        $val = $st->fetchColumn();
        return $val !== false ? (int)$val : null;
    }
}
