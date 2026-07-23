<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SpedizioneRepository;
use PDO;
use Throwable;

/**
 * SpedizioneCalculatorService — crea una spedizione + DDT per un ordine cliente.
 *
 * PIANO - Eliminazione SQL Grezzo nei Controller (Regola Aurea), Tappa 6
 * (23/07/2026): estrae la logica di dominio prima embedded in
 * qk_elabora_crea_spedizione() (includes/sales_orders.php, problema 4.C.1),
 * stesso pattern Service+Repository già usato da
 * OrderCalculatorService/SalesOrderRepository per l'ordine diretto.
 * qk_elabora_crea_spedizione() resta come wrapper sottile che istanzia
 * questa classe (stesso schema di qk_insert_ordine_diretto()).
 *
 * Due differenze volute rispetto alla versione precedente (bug scoperti
 * durante il test end-to-end richiesto da questa stessa tappa, corretti con
 * autorizzazione esplicita — non "silenziosamente" durante il refactoring):
 *
 * 1. Le 4 INSERT identiche su movimenti_magazzino sono ora un'unica chiamata
 *    a SpedizioneRepository::insertMovimentoScarico() (fix DRY interno,
 *    §4.2 del piano — prima non segnalato dall'audit originale).
 *
 * 2. Il rilevamento "questa riga è un Kit?" non si fida più di un campo
 *    $line['tipo'] che qk_get_righe_ordine_da_spedire() (includes/
 *    sales_orders.php) non ha MAI selezionato — rendendo il ramo Kit qui
 *    sotto irraggiungibile in produzione (verificato con esecuzione reale:
 *    spedire un Kit falliva sempre con "Giacenza insufficiente", perché il
 *    codice cercava la giacenza del Kit stesso invece di espandersi sui
 *    componenti). Ora usa qk_prodotto_e_kit($pdo, $prodottoId)
 *    (includes/magazzino.php), la stessa funzione condivisa già estratta
 *    dall'identico pattern in qk_riserva_stock_ordine()/
 *    qk_rilascia_stock_ordine() — quelle due non avevano il bug perché già
 *    verificavano il tipo con una query propria invece di fidarsi di un
 *    campo arrivato da una query esterna.
 *
 * Prerequisiti bug 3.1 (riserva/rilascio su magazzino mai confrontato) e 3.2
 * (Kit senza tracciabilità lotti — la logica del ramo Kit era corretta ma,
 * per il bug del punto 2 qui sopra, non veniva mai eseguita) già presenti
 * nella versione precedente di questa funzione (AUDIT 3 §3.1/§3.2) — qui
 * riportati invariati nella logica, solo ora davvero raggiungibili.
 */
class SpedizioneCalculatorService
{
    private PDO $pdo;
    private SpedizioneRepository $repository;

    public function __construct(PDO $pdo, SpedizioneRepository $repository)
    {
        $this->pdo = $pdo;
        $this->repository = $repository;
    }

    public function creaSpedizione(int $orderId, array $postData, array $filesData, int $userId): array
    {
        $vettore             = trim($postData['vettore'] ?? '');
        $tracking            = trim($postData['tracking'] ?? '');
        $causale_trasporto   = trim($postData['causale_trasporto'] ?? 'Vendita');
        $porto               = strtolower(trim($postData['porto'] ?? 'franco'));
        $aspetto_beni        = trim($postData['aspetto_beni'] ?? '');
        $numero_colli        = (int) ($postData['numero_colli'] ?? 1);
        $peso_lordo_kg       = (float) ($postData['peso_lordo_kg'] ?? 0.0);
        $lottiPost           = $postData['lotti'] ?? [];
        $quantitaNoLottoPost = $postData['quantita_no_lotto'] ?? [];
        $magazzinoSpedId     = (int) ($postData['magazzino_id'] ?? 0);

        $this->pdo->beginTransaction();
        try {
            if ($magazzinoSpedId <= 0) {
                throw new \Exception("Seleziona il magazzino di spedizione.");
            }

            $order = $this->repository->getOrdine($orderId);
            if (!$order) {
                throw new \Exception("Ordine non trovato.");
            }

            $magazzinoRiservaId = !empty($order['magazzino_id']) ? (int) $order['magazzino_id'] : null;

            qk_ordine_cliente_assicura_modificabile(
                (string) $order['stato'],
                "Impossibile creare la spedizione: l'ordine è già in stato '{$order['stato']}'."
            );

            $indirizzoSpedizioneId = !empty($order['indirizzo_spedizione_id']) ? (int) $order['indirizzo_spedizione_id'] : null;

            if (!$indirizzoSpedizioneId && !empty($order['indirizzo_consegna_id'])) {
                $masterId = $this->repository->getIndirizzoMasterDaSnapshot((int) $order['indirizzo_consegna_id']);
                if ($masterId) {
                    $indirizzoSpedizioneId = $masterId;
                }
            }

            if (!$indirizzoSpedizioneId) {
                $fallbackId = $this->repository->getIndirizzoDefaultCliente((int) $order['cliente_id']);
                if ($fallbackId) {
                    $indirizzoSpedizioneId = $fallbackId;
                }
            }

            if (!$indirizzoSpedizioneId) {
                throw new \Exception("Impossibile creare la spedizione: il cliente non ha nessun indirizzo di consegna valido.");
            }

            $lines = qk_get_righe_ordine_da_spedire($this->pdo, $orderId);

            $confermaSuperamento = ($postData['conferma_superamento'] ?? '') === '1';
            if (!$confermaSuperamento) {
                $superamenti = [];
                foreach ($lines as $line) {
                    $lineId = $line['id'];
                    $residuo = (float) $line['quantita_ordinata'] - (float) $line['quantita_spedita'];
                    if ((int) $line['traccia_lotti'] === 1) {
                        $totRiga = 0.0;
                        foreach ($lottiPost[$lineId] ?? [] as $qtaStr) {
                            $totRiga += (float) $qtaStr;
                        }
                    } else {
                        $totRiga = (float) ($quantitaNoLottoPost[$lineId] ?? 0.0);
                    }
                    if ($totRiga > $residuo + 0.0005) {
                        $superamenti[] = "\"{$line['prodotto_nome']}\": " . number_format($totRiga, 3) . " contro un residuo di " . number_format($residuo, 3);
                    }
                }
                if (!empty($superamenti)) {
                    throw new \Exception("Spedizione bloccata: alcune righe superano la quantità ancora ordinata — " . implode('; ', $superamenti));
                }
            }

            $numSped = qk_next_sequence_number($this->pdo, 'spedizione');

            $spedizioneId = $this->repository->insertSpedizione([
                'numero_spedizione'       => $numSped,
                'ordine_id'               => $orderId,
                'indirizzo_spedizione_id' => $indirizzoSpedizioneId,
                'magazzino_id'            => $magazzinoSpedId,
                'vettore'                 => $vettore ?: null,
                'tracking'                => $tracking ?: null,
                'creato_da'               => $userId,
            ]);

            if (isset($filesData['pallet_foto'])) {
                $files = $filesData['pallet_foto'];
                $totalUploaded = is_array($files['name']) ? count($files['name']) : 0;
                for ($i = 0; $i < $totalUploaded; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $files['tmp_name'][$i];
                        $name = basename($files['name'][$i]);

                        $allowedMimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/gif'  => 'gif',
                            'image/webp' => 'webp',
                        ];
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $realMime = $finfo ? finfo_file($finfo, $tmpName) : false;
                        if ($finfo) {
                            finfo_close($finfo);
                        }
                        $ext = $allowedMimeToExt[$realMime] ?? null;
                        if ($ext !== null && getimagesize($tmpName) !== false) {
                            $subdir = upload_dated_subdir('spedizioni');
                            $upload_dir = upload_ensure_dir($subdir);
                            $new_name = uniqid('sped_photo_', true) . '.' . $ext;
                            $dest = $upload_dir . DIRECTORY_SEPARATOR . $new_name;
                            if (move_uploaded_file($tmpName, $dest)) {
                                $filePath = $subdir . '/' . $new_name;
                                $this->repository->insertSpedizioneFoto([
                                    'spedizione_id' => $spedizioneId,
                                    'file_path'     => $filePath,
                                    'file_name'     => $name,
                                    'creato_da'     => $userId,
                                ]);
                            }
                        }
                    }
                }
            }

            $numDdt = qk_next_sequence_number($this->pdo, 'documento_trasporto');
            $mittente = qk_calcola_mittente_ddt($this->pdo, $magazzinoSpedId);

            $ddtId = $this->repository->insertDocumentoTrasporto([
                'numero_documento_trasporto'   => $numDdt,
                'spedizione_id'                => $spedizioneId,
                'ordine_id'                    => $orderId,
                'destinatario_id'              => $order['cliente_id'],
                'indirizzo_destinazione_id'    => $indirizzoSpedizioneId,
                'causale_trasporto'            => $causale_trasporto,
                'porto'                        => $porto,
                'vettore'                      => $vettore ?: null,
                'aspetto_beni'                 => $aspetto_beni ?: null,
                'numero_colli'                 => $numero_colli,
                'peso_lordo_kg'                => $peso_lordo_kg,
                'mittente_ragione_sociale'     => $mittente['ragione_sociale'],
                'mittente_partita_iva'         => $mittente['partita_iva'],
                'mittente_codice_fiscale'      => $mittente['codice_fiscale'],
                'mittente_indirizzo'           => $mittente['indirizzo'],
                'mittente_telefono'            => $mittente['telefono'],
                'mittente_email'               => $mittente['email'],
                'destinatario_ragione_sociale' => $order['cliente_ragione_sociale'] ?? null,
                'destinatario_partita_iva'     => $order['cliente_partita_iva'] ?? null,
                'destinatario_codice_fiscale'  => $order['cliente_codice_fiscale'] ?? null,
                'destinatario_via'             => $order['consegna_via'] ?? null,
                'destinatario_civico'          => $order['consegna_civico'] ?? null,
                'destinatario_cap'             => $order['consegna_cap'] ?? null,
                'destinatario_citta'           => $order['consegna_citta'] ?? null,
                'destinatario_provincia'       => $order['consegna_provincia'] ?? null,
                'destinatario_nazione'         => $order['consegna_nazione'] ?? null,
                'destinatario_nome_sede'       => $order['consegna_presso'] ?? null,
                'creato_da'                    => $userId,
            ]);

            $pos = 0;
            $anyShipped = false;

            foreach ($lines as $line) {
                $lineId = $line['id'];
                $prodId = $line['prodotto_id'];

                if (!qk_prodotto_e_kit($this->pdo, (int) $prodId) && qk_inventario_bloccato($this->pdo, $magazzinoSpedId, (int) $prodId)) {
                    throw new \Exception("Impossibile spedire \"{$line['prodotto_nome']}\": è in corso un inventario fisico in questo magazzino.");
                }

                $numSnap = isset($line['num_factor_snapshot']) ? (int) $line['num_factor_snapshot'] : 1;
                $denSnap = isset($line['den_factor_snapshot']) ? (int) $line['den_factor_snapshot'] : 1;
                $convertiInStock = function (float $q) use ($numSnap, $denSnap): float {
                    if ($denSnap <= 0) {
                        return $q;
                    }
                    return (float) bcdiv(bcmul((string) $q, (string) $numSnap, 6), (string) $denSnap, 3);
                };

                if ((int) $line['traccia_lotti'] === 1) {
                    foreach ($lottiPost[$lineId] ?? [] as $lottoId => $qtaStr) {
                        $qta = (float) $qtaStr;
                        if ($qta <= 0) {
                            continue;
                        }
                        $anyShipped = true;
                        $qtaStock = $convertiInStock($qta);

                        $lot = $this->repository->getLottoInfo((int) $lottoId, $magazzinoSpedId);
                        if (!$lot) {
                            throw new \Exception("Lotto {$lottoId} non trovato.");
                        }
                        if ($lot['quantita_disponibile'] < $qtaStock) {
                            throw new \Exception("Giacenza insufficiente lotto {$lot['cl']}.");
                        }

                        $this->repository->insertSpedizioneRiga([
                            'spedizione_id'  => $spedizioneId,
                            'ordine_riga_id' => $lineId,
                            'prodotto_id'    => $prodId,
                            'lotto_id'       => $lottoId,
                            'quantita'       => $qta,
                        ]);
                        $adr = $this->repository->getClassificazioneAdr((int) $prodId);
                        $isAdr = !empty($adr) ? 1 : 0;
                        $this->repository->insertDocumentoTrasportoRiga([
                            'documento_trasporto_id' => $ddtId,
                            'prodotto_id'            => $prodId,
                            'descrizione'            => $line['descrizione'],
                            'quantita'               => $qta,
                            'unita_misura_id'        => $line['unita_misura_id'],
                            'lotto_id'               => $lottoId,
                            'codice_lotto'           => $lot['cl'],
                            'is_adr'                 => $isAdr,
                            'numero_onu'             => $isAdr ? 'UN ' . $adr['codice_onu'] : null,
                            'classe_adr'             => $isAdr ? $adr['classe_pericolo'] : null,
                            'packing_group'          => $isAdr ? $adr['gruppo_imballaggio'] : null,
                            'posizione_riga'         => $pos++,
                        ]);
                        $this->repository->aggiornaLottoGiacenza((int) $lottoId, $magazzinoSpedId, $qtaStock);
                        $this->repository->decrementaGiacenzaDisponibile((int) $prodId, $magazzinoSpedId, $qtaStock);
                        if ($magazzinoRiservaId !== null) {
                            qk_rilascia_stock_ordine($this->pdo, (int) $prodId, $qtaStock, $magazzinoRiservaId, (int) $lineId);
                        }
                        $this->repository->aggiornaQuantitaSpedita((int) $lineId, $qta);
                        $this->repository->insertMovimentoScarico([
                            'prodotto_id'         => $prodId,
                            'magazzino_id'        => $magazzinoSpedId,
                            'quantita'            => $qtaStock,
                            'lotto_id'            => $lottoId,
                            'riferimento_esterno' => $numDdt,
                            'creato_da'           => $userId,
                        ]);
                    }
                } else {
                    $qta = (float) ($quantitaNoLottoPost[$lineId] ?? 0.0);
                    if ($qta > 0) {
                        $anyShipped = true;
                        $qtaStock = $convertiInStock($qta);
                        $this->repository->insertSpedizioneRiga([
                            'spedizione_id'  => $spedizioneId,
                            'ordine_riga_id' => $lineId,
                            'prodotto_id'    => $prodId,
                            'lotto_id'       => null,
                            'quantita'       => $qta,
                        ]);
                        $adr = $this->repository->getClassificazioneAdr((int) $prodId);
                        $isAdr = !empty($adr) ? 1 : 0;
                        $this->repository->insertDocumentoTrasportoRiga([
                            'documento_trasporto_id' => $ddtId,
                            'prodotto_id'            => $prodId,
                            'descrizione'            => $line['descrizione'],
                            'quantita'               => $qta,
                            'unita_misura_id'        => $line['unita_misura_id'],
                            'lotto_id'               => null,
                            'codice_lotto'           => null,
                            'is_adr'                 => $isAdr,
                            'numero_onu'             => $isAdr ? 'UN ' . $adr['codice_onu'] : null,
                            'classe_adr'             => $isAdr ? $adr['classe_pericolo'] : null,
                            'packing_group'          => $isAdr ? $adr['gruppo_imballaggio'] : null,
                            'posizione_riga'         => $pos++,
                        ]);

                        if (qk_prodotto_e_kit($this->pdo, (int) $prodId)) {
                            if ($magazzinoRiservaId !== null) {
                                qk_rilascia_stock_ordine($this->pdo, (int) $prodId, $qtaStock, $magazzinoRiservaId, (int) $lineId);
                            }

                            $comps = $this->repository->getComponentiKit((int) $prodId);
                            foreach ($comps as $c) {
                                if (qk_inventario_bloccato($this->pdo, $magazzinoSpedId, (int) $c['componente_id'])) {
                                    throw new \Exception("Impossibile spedire il Kit \"{$line['prodotto_nome']}\": un componente è in inventario.");
                                }
                                $cQta = $c['quantita'] * $qtaStock;

                                if ((int) $c['traccia_lotti'] === 1) {
                                    $allocazioni = qk_seleziona_lotti_fefo($this->pdo, (int) $c['componente_id'], $magazzinoSpedId, $cQta);
                                    $totAllocato = array_sum(array_column($allocazioni, 'quantita'));
                                    if ($totAllocato < $cQta - 0.0005) {
                                        throw new \Exception(sprintf('Giacenza lotti insufficiente per componente Kit "%s".', $line['prodotto_nome']));
                                    }
                                    foreach ($allocazioni as $alloc) {
                                        $this->repository->aggiornaLottoGiacenza((int) $alloc['lotto_id'], $magazzinoSpedId, (float) $alloc['quantita']);
                                        $this->repository->decrementaGiacenzaDisponibile((int) $c['componente_id'], $magazzinoSpedId, (float) $alloc['quantita']);
                                        $this->repository->insertMovimentoScarico([
                                            'prodotto_id'         => $c['componente_id'],
                                            'magazzino_id'        => $magazzinoSpedId,
                                            'quantita'            => $alloc['quantita'],
                                            'lotto_id'            => $alloc['lotto_id'],
                                            'riferimento_esterno' => $numDdt,
                                            'creato_da'           => $userId,
                                        ]);
                                    }
                                    continue;
                                }

                                $dispComp = $this->repository->getGiacenzaDisponibile((int) $c['componente_id'], $magazzinoSpedId);
                                if ($dispComp < $cQta) {
                                    throw new \Exception(sprintf('Giacenza insufficiente per componente Kit "%s".', $line['prodotto_nome']));
                                }

                                $this->repository->decrementaGiacenzaDisponibile((int) $c['componente_id'], $magazzinoSpedId, $cQta);
                                $this->repository->insertMovimentoScarico([
                                    'prodotto_id'         => $c['componente_id'],
                                    'magazzino_id'        => $magazzinoSpedId,
                                    'quantita'            => $cQta,
                                    'lotto_id'            => null,
                                    'riferimento_esterno' => $numDdt,
                                    'creato_da'           => $userId,
                                ]);
                            }
                        } else {
                            $dispProd = $this->repository->getGiacenzaDisponibile((int) $prodId, $magazzinoSpedId);
                            if ($dispProd < $qtaStock) {
                                throw new \Exception(sprintf('Giacenza insufficiente per "%s".', $line['prodotto_nome']));
                            }

                            $this->repository->decrementaGiacenzaDisponibile((int) $prodId, $magazzinoSpedId, $qtaStock);
                            if ($magazzinoRiservaId !== null) {
                                qk_rilascia_stock_ordine($this->pdo, (int) $prodId, $qtaStock, $magazzinoRiservaId, (int) $lineId);
                            }
                            $this->repository->insertMovimentoScarico([
                                'prodotto_id'         => $prodId,
                                'magazzino_id'        => $magazzinoSpedId,
                                'quantita'            => $qtaStock,
                                'lotto_id'            => null,
                                'riferimento_esterno' => $numDdt,
                                'creato_da'           => $userId,
                            ]);
                        }

                        $this->repository->aggiornaQuantitaSpedita((int) $lineId, $qta);
                    }
                }
            }

            if (!$anyShipped) {
                throw new \Exception("Nessuna quantita valida per la spedizione.");
            }

            $allLines = $this->repository->getRigheOrdinePerStato($orderId);
            $allDone = true;
            $anyDone = false;
            foreach ($allLines as $al) {
                if ((float) $al['quantita_spedita'] < (float) $al['quantita_ordinata']) {
                    $allDone = false;
                }
                if ((float) $al['quantita_spedita'] > 0) {
                    $anyDone = true;
                }
            }
            $nuovoStato = $allDone ? 'spedito' : ($anyDone ? 'parzialmente_spedito' : 'in_lavorazione');
            $this->repository->aggiornaStatoOrdine($orderId, $nuovoStato);

            $this->pdo->commit();
            return [
                'num_sped'      => $numSped,
                'num_ddt'       => $numDdt,
                'spedizione_id' => $spedizioneId,
                'ddt_id'        => $ddtId,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
