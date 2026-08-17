<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * SpedizioneRepository — accesso dati per la creazione di una spedizione/DDT.
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 6
 * (23/07/2026): estrae le query dirette prima embedded in
 * qk_elabora_crea_spedizione() (includes/sales_orders.php), stesso pattern
 * già usato da SalesOrderRepository per l'ordine diretto (problema 4.C.1).
 *
 * Nessuna transazione propria: ogni metodo riceve $pdo già dentro la
 * transazione aperta dal Service chiamante
 * (SpedizioneCalculatorService::creaSpedizione()) — stesso principio già
 * applicato a qk_riserva_stock_ordine()/qk_rilascia_stock_ordine().
 *
 * Ogni metodo prepara la propria query ad ogni chiamata (nessuna cache di
 * PDOStatement) — stessa scelta già fatta da SalesOrderRepository per lo
 * stesso motivo: semplicità e coerenza con lo stile del progetto, a fronte
 * di un costo di preparazione trascurabile per i volumi reali di righe per
 * spedizione.
 */
class SpedizioneRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getOrdine(int $orderId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM ordini_clienti WHERE id = ?");
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getIndirizzoMasterDaSnapshot(int $indirizzoDocumentoId): ?int
    {
        $st = $this->pdo->prepare("SELECT indirizzo_master_id FROM indirizzi_documenti WHERE id = ?");
        $st->execute([$indirizzoDocumentoId]);
        $val = $st->fetchColumn();
        return $val ? (int) $val : null;
    }

    public function getIndirizzoDefaultCliente(int $clienteId): ?int
    {
        $st = $this->pdo->prepare("
            SELECT id FROM indirizzi
            WHERE soggetto_id = ? AND attivo = 1
            ORDER BY (tipo = 'spedizione' AND predefinito = 1) DESC,
                     predefinito DESC,
                     id ASC
            LIMIT 1
        ");
        $st->execute([$clienteId]);
        $val = $st->fetchColumn();
        return $val ? (int) $val : null;
    }

    public function insertSpedizione(array $d): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, indirizzo_spedizione_id, magazzino_id, vettore, tracking, stato, creato_da, created_at)
            VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, 'spedita', ?, CURRENT_TIMESTAMP)
        ");
        $st->execute([
            $d['numero_spedizione'], $d['ordine_id'], $d['indirizzo_spedizione_id'], $d['magazzino_id'],
            $d['vettore'], $d['tracking'], $d['creato_da'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertSpedizioneFoto(array $d): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO spedizioni_foto (spedizione_id, file_path, file_name, creato_da, created_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $st->execute([$d['spedizione_id'], $d['file_path'], $d['file_name'], $d['creato_da']]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertDocumentoTrasporto(array $d): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO documenti_trasporto (numero_documento_trasporto, spedizione_id, ordine_id, data, destinatario_id, indirizzo_destinazione_id, causale_trasporto, porto, vettore, aspetto_beni, numero_colli, peso_lordo_kg, stato, mittente_ragione_sociale, mittente_partita_iva, mittente_codice_fiscale, mittente_indirizzo, mittente_telefono, mittente_email, destinatario_ragione_sociale, destinatario_partita_iva, destinatario_codice_fiscale, destinatario_via, destinatario_civico, destinatario_cap, destinatario_citta, destinatario_provincia, destinatario_nazione, destinatario_nome_sede, creato_da, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, 'emesso', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $st->execute([
            $d['numero_documento_trasporto'], $d['spedizione_id'], $d['ordine_id'], $d['destinatario_id'], $d['indirizzo_destinazione_id'],
            $d['causale_trasporto'], $d['porto'], $d['vettore'], $d['aspetto_beni'], $d['numero_colli'], $d['peso_lordo_kg'],
            $d['mittente_ragione_sociale'], $d['mittente_partita_iva'], $d['mittente_codice_fiscale'], $d['mittente_indirizzo'], $d['mittente_telefono'], $d['mittente_email'],
            $d['destinatario_ragione_sociale'], $d['destinatario_partita_iva'], $d['destinatario_codice_fiscale'], $d['destinatario_via'], $d['destinatario_civico'],
            $d['destinatario_cap'], $d['destinatario_citta'], $d['destinatario_provincia'], $d['destinatario_nazione'], $d['destinatario_nome_sede'],
            $d['creato_da'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertSpedizioneRiga(array $d): int
    {
        $st = $this->pdo->prepare("INSERT INTO spedizioni_righe (spedizione_id, ordine_riga_id, prodotto_id, lotto_id, quantita, note) VALUES (?, ?, ?, ?, ?, '')");
        $st->execute([$d['spedizione_id'], $d['ordine_riga_id'], $d['prodotto_id'], $d['lotto_id'], $d['quantita']]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertDocumentoTrasportoRiga(array $d): int
    {
        $st = $this->pdo->prepare("INSERT INTO documenti_trasporto_righe (documento_trasporto_id, prodotto_id, descrizione, quantita, unita_misura_id, lotto_id, codice_lotto, is_adr, numero_onu, classe_adr, packing_group, posizione_riga, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
        $st->execute([
            $d['documento_trasporto_id'], $d['prodotto_id'], $d['descrizione'], $d['quantita'], $d['unita_misura_id'],
            $d['lotto_id'], $d['codice_lotto'], $d['is_adr'], $d['numero_onu'], $d['classe_adr'], $d['packing_group'], $d['posizione_riga'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * UPSERT giacenza di lotto (scarico). Tappa 3 (PIANO - Coerenza
     * Strutturale Giacenze e Lotti), sotto-tappa 3.d: delega a
     * GiacenzaLottoService::applicaDeltaLotto(), che scrive esattamente lo
     * stesso upsert (INSERT...ON CONFLICT/ON DUPLICATE KEY, quantità =
     * quantità + delta) prima duplicato qui — pura deduplicazione, nessun
     * cambio di comportamento (verificato: stesso valore assoluto scritto,
     * stessa condizione di riga assente vs esistente).
     */
    public function aggiornaLottoGiacenza(int $lottoId, int $magazzinoId, float $qta): void
    {
        $this->giacenzaLottoService()->applicaDeltaLotto($lottoId, $magazzinoId, -$qta);
    }

    /**
     * Decrementa la giacenza aggregata di (prodotto, magazzino). Tappa 3,
     * sotto-tappa 3.d: NON delegata a GiacenzaLottoService (a differenza di
     * aggiornaLottoGiacenza() sopra) perché quel servizio fa un upsert (crea
     * la riga se assente), mentre questo metodo fa un UPDATE puro — se la
     * riga giacenze non esiste ancora, questo metodo storicamente non
     * scrive nulla (nessuna riga da decrementare), mentre l'upsert del
     * servizio creerebbe una riga con quantità NEGATIVA. In pratica non
     * dovrebbe mai accadere (si spedisce solo stock già riservato, quindi la
     * riga giacenze esiste già), ma preservare il comportamento esatto
     * pre-esistente in quel caso limite richiede di NON unificare questo
     * punto di scrittura — vedi CLAUDE.md punto 8, "zero cambi di
     * comportamento" resta il vincolo di questa Tappa.
     */
    public function decrementaGiacenzaDisponibile(int $prodottoId, int $magazzinoId, float $qta): void
    {
        $this->pdo->prepare("
            UPDATE giacenze
               SET quantita_disponibile = quantita_disponibile - ?
             WHERE prodotto_id = ? AND magazzino_id = ?
        ")->execute([$qta, $prodottoId, $magazzinoId]);
    }

    private function giacenzaLottoService(): \App\Services\GiacenzaLottoService
    {
        require_once __DIR__ . '/../Services/GiacenzaLottoService.php';

        return new \App\Services\GiacenzaLottoService($this->pdo);
    }

    public function getGiacenzaDisponibile(int $prodottoId, int $magazzinoId): float
    {
        $st = $this->pdo->prepare("SELECT quantita_disponibile FROM giacenze WHERE prodotto_id = ? AND magazzino_id = ?");
        $st->execute([$prodottoId, $magazzinoId]);
        $val = $st->fetchColumn();
        return $val !== false ? (float) $val : 0.0;
    }

    public function aggiornaQuantitaSpedita(int $rigaOrdineId, float $qta): void
    {
        $this->pdo->prepare("UPDATE ordini_clienti_righe SET quantita_spedita = quantita_spedita + ? WHERE id = ?")
            ->execute([$qta, $rigaOrdineId]);
    }

    public function getClassificazioneAdr(int $prodottoId): ?array
    {
        $st = $this->pdo->prepare("SELECT co.codice_onu, co.classe_pericolo, COALESCE(pca.gruppo_imballaggio_override, co.gruppo_imballaggio) AS gruppo_imballaggio FROM prodotto_classificazioni_adr pca JOIN adr_codici_onu co ON co.id = pca.codice_onu_id WHERE pca.prodotto_id = ? AND pca.e_corrente = 1 LIMIT 1");
        $st->execute([$prodottoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLottoInfo(int $lottoId, int $magazzinoId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT l.id, COALESCE(lg.quantita_disponibile, 0) AS quantita_disponibile,
                   l.codice_lotto_interno AS cl
            FROM lotti l
            LEFT JOIN lotti_giacenze lg ON lg.lotto_id = l.id AND lg.magazzino_id = ?
            WHERE l.id = ?
        ");
        $st->execute([$magazzinoId, $lottoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getComponentiKit(int $kitId): array
    {
        $st = $this->pdo->prepare("
            SELECT ck.componente_id, ck.quantita, p.traccia_lotti
            FROM componenti_kit ck
            JOIN prodotti p ON p.id = ck.componente_id
            WHERE ck.kit_id = ?
        ");
        $st->execute([$kitId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Registra un movimento di scarico magazzino per una spedizione/DDT.
     * Tappa 6 (§4.2/4.4.4 del piano): elimina la duplicazione 4x dello
     * stesso INSERT che viveva embedded in qk_elabora_crea_spedizione()
     * (righe 791/835/848/862 della versione pre-refactoring) — unico punto
     * di scrittura per tutti e 4 i rami (standalone lotti/no-lotti, Kit
     * componente lotti/no-lotti).
     *
     * NOTA (osservazione fattuale, non corretta qui — CLAUDE.md punto 8):
     * come nella versione precedente, non viene valorizzato
     * movimenti_magazzino.documento_id (solo documento_tipo='ddt' e
     * riferimento_esterno=numero DDT). Popolarlo migliorerebbe la
     * tracciabilità ma è un cambio di comportamento reale, fuori scope per
     * questa tappa (bit-per-bit equivalente al codice precedente).
     */
    public function insertMovimentoScarico(array $d): int
    {
        $st = $this->pdo->prepare("INSERT INTO movimenti_magazzino (prodotto_id, magazzino_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at) VALUES (?, ?, 'scarico', ?, ?, ?, 'ddt', ?, CURRENT_TIMESTAMP)");
        $st->execute([
            $d['prodotto_id'], $d['magazzino_id'], $d['quantita'], $d['lotto_id'] ?? null, $d['riferimento_esterno'], $d['creato_da'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getRigheOrdinePerStato(int $orderId): array
    {
        $st = $this->pdo->prepare("SELECT quantita_ordinata, quantita_spedita FROM ordini_clienti_righe WHERE ordine_id = ?");
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aggiornaStatoOrdine(int $orderId, string $stato): void
    {
        $this->pdo->prepare("UPDATE ordini_clienti SET stato = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$stato, $orderId]);
    }
}
