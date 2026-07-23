<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ListinoPrezziRepository;
use PDO;

/**
 * ListinoImportService — logica di business per l'import massivo (da Excel)
 * di righe prezzo in un listino di vendita.
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 8
 * (23/07/2026, problema 4.C.2): estrae il corpo di
 * SalesPricelistsController::apiImportPrezzi() (parsing/validazione riga,
 * conversione UoM, regola "non sovrascrivere le righe modificate a mano",
 * rimozione righe orfane). Il Controller resta responsabile solo di: metodo
 * HTTP, CSRF, parsing di $_POST, verifica esistenza listino, chiamata a
 * questo Service ed emissione della risposta JSON.
 *
 * Nessuna deviazione di comportamento rispetto al codice originale: ogni
 * query, ogni condizione e ogni messaggio di errore sono stati riportati
 * identici, solo spostati dal Controller a Repository/Service.
 */
class ListinoImportService
{
    private PDO $pdo;
    private ListinoPrezziRepository $repository;

    public function __construct(PDO $pdo, ListinoPrezziRepository $repository)
    {
        $this->pdo = $pdo;
        $this->repository = $repository;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{inserted:int, skipped:int, removed:int, errors:string[]}
     */
    public function importaRighe(int $listinoId, array $rows): array
    {
        $inserted   = 0;
        $skipped    = 0;
        $rowErrors  = [];
        $touchedIds = []; // id di listini_prezzi scritte/confermate in questo import — vedi REVISIONE §6.12

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $lineNum = $i + 2;

                $sku = trim((string)($row['sku_interno'] ?? ''));
                if ($sku === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': SKU interno vuoto — saltata.';
                    continue;
                }

                // Cerca il prodotto
                $prodRow = $this->repository->trovaProdottoPerSku($sku);
                if (!$prodRow) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': prodotto con SKU "' . htmlspecialchars($sku) . '" non trovato o non attivo — saltata.';
                    continue;
                }
                $prodottoId = (int)$prodRow['id'];
                $uomStockId = (int)$prodRow['unita_misura_stock_id'];
                $uomCommercialId = $prodRow['unita_contenuto_id'] !== null ? (int)$prodRow['unita_contenuto_id'] : null;
                $uomId = $uomStockId; // default retrocompatibile: assume Stock UoM se la colonna non è valorizzata

                // D4.6 (PIANO Motore di Pricing Multi-UoM §7.7/§8 Fase 4): colonna facoltativa nel
                // tracciato Excel — se valorizzata, dichiara in quale unità è scritta la soglia
                // (quantita_minima) per QUESTA riga, invece di assumere sempre Stock UoM. Se il
                // codice non corrisponde né a Stock né a UoM commerciale del prodotto, si rifiuta
                // la riga (stesso principio di §7.5: mai salvare un dato in un'unità non convertibile).
                $umSogliaCodice = trim((string)($row['unita_misura_soglia'] ?? ''));
                if ($umSogliaCodice !== '') {
                    $umSogliaId = $this->repository->risolviUnitaMisuraPerCodice($umSogliaCodice);
                    if (!$umSogliaId || ($umSogliaId !== $uomStockId && $umSogliaId !== $uomCommercialId)) {
                        $skipped++;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): unità soglia "' . htmlspecialchars($umSogliaCodice) . '" non valida per questo prodotto (deve essere l\'unità di stock o l\'unità commerciale) — saltata.';
                        continue;
                    }
                    $uomId = $umSogliaId;
                }

                $toFloat = function(?string $v): float {
                    if ($v === null || trim($v) === '') return 0.0;
                    return (float) str_replace(',', '.', trim($v));
                };

                $prezzoVal = trim((string)($row['prezzo_unitario'] ?? ''));
                if ($prezzoVal === '' || !is_numeric(str_replace(',', '.', $prezzoVal))) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo unitario vuoto o non valido — saltata.';
                    continue;
                }
                $prezzo = (float) str_replace(',', '.', $prezzoVal);

                $qMin   = $toFloat($row['quantita_minima'] ?? '0');
                $sconto = $toFloat($row['sconto_perc'] ?? '0');
                $note   = !empty($row['note']) ? trim((string)$row['note']) : null;

                // D4.6 (PIANO Motore di Pricing Multi-UoM §7.7): $uomId qui è o la Stock UoM
                // (default retrocompatibile) o l'unità dichiarata dalla colonna facoltativa
                // "unita_misura_soglia" — stesso campo usato sia come unità di quotazione del
                // prezzo sia come unità della soglia, coerente col modello a singola unità per
                // riga già in uso nel resto del sistema (vedi nota in apiSaveRiga).
                $qMinCanonico = $qMin > 0
                    ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $uomId, $qMin, 0.0)['quantita_stock']
                    : 0.0;

                // Cerca se esiste già un prezzo per questo listino/prodotto allo stesso scaglione.
                // Bug corretto il 13/07/2026: la query precedente non filtrava su unita_misura_id.
                // Bug corretto il 14/07/2026 (audit a tappeto, gap simmetrico a quello già noto lato
                // acquisti in apiImportPrezzi di PurchasesController): il confronto restava sul valore
                // grezzo (quantita_minima + unita_misura_id), non sul valore canonico quantita_minima_stock
                // — un import che creasse un doppione mascherato (es. "100 KG" quando esiste già "4 FUSTI"
                // con fattore 25) avrebbe fallito con un errore SQL grezzo sul vincolo UNIQUE invece di
                // essere riconosciuto come lo stesso scaglione e gestito correttamente (update o skip).
                $existingRow = $this->repository->trovaRigaEsistente($listinoId, $prodottoId, $qMinCanonico);

                if ($existingRow) {
                    $existingId = (int)$existingRow['id'];

                    // REVISIONE §6.12 — "Regola d'Oro": non sovrascrivere silenziosamente una riga
                    // modificata a mano dopo l'ultimo import. Resta comunque "vista" (touchedIds),
                    // quindi non verrà mai chiusa come orfana solo perché non aggiornata.
                    $modificataAMano = !empty($existingRow['modificato_manualmente_il'])
                        && (empty($existingRow['importato_il']) || $existingRow['modificato_manualmente_il'] > $existingRow['importato_il']);

                    if ($modificataAMano) {
                        $skipped++;
                        $touchedIds[] = $existingId;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo modificato manualmente dopo l\'ultimo import — non sovrascritto, verificare a mano.';
                        continue;
                    }

                    // UPDATE
                    $this->repository->aggiornaRiga($existingId, [
                        'prezzo_unitario'         => $prezzo,
                        'sconto_perc'             => $sconto,
                        'note'                    => $note,
                        'quantita_minima_intento' => $qMin,
                        'unita_misura_soglia_id'  => $uomId ?: null,
                        'quantita_minima_stock'   => $qMinCanonico,
                    ]);
                    $touchedIds[] = $existingId;
                } else {
                    // INSERT
                    $newId = $this->repository->inserisciRiga([
                        'listino_id'              => $listinoId,
                        'prodotto_id'             => $prodottoId,
                        'quantita_minima'         => $qMin,
                        'prezzo_unitario'         => $prezzo,
                        'sconto_perc'             => $sconto,
                        'unita_misura_id'         => $uomId ?: null,
                        'note'                    => $note,
                        'quantita_minima_intento' => $qMin,
                        'unita_misura_soglia_id'  => $uomId ?: null,
                        'quantita_minima_stock'   => $qMinCanonico,
                    ]);
                    $touchedIds[] = $newId;
                }
                $inserted++;
            }

            // REVISIONE §6.12 — righe che in passato provenivano da un import per QUESTO listino ma non
            // sono più presenti nel file appena caricato. A differenza degli acquisti, `listini_prezzi`
            // non ha validità per riga (scelta di progetto, vedi §6.10/C10: la validità vive solo sulla
            // testata del listino) — quindi qui, coerentemente, le righe orfane vengono rimosse invece
            // di essere "chiuse" con una data, non essendoci alcun campo dove farlo. Le righe modificate
            // manualmente restano comunque escluse, perché sono sempre in touchedIds.
            $orphanIds = $this->repository->trovaRigheOrfane($listinoId, $touchedIds);
            $removed = $this->repository->rimuoviRighe($orphanIds);

            $this->pdo->commit();

            return [
                'inserted' => $inserted,
                'skipped'  => $skipped,
                'removed'  => $removed,
                'errors'   => $rowErrors,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
