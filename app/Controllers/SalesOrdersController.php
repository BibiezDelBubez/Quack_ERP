<?php

declare(strict_types=1);

final class SalesOrdersController extends BaseController
{
    public function index(): void
    {
        // For now, client orders list can query ordini_clienti
        $search = get_param('q');
        $status = get_param('status');

        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.numero_ordine, o.data_ordine, o.stato,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
             FROM ordini_clienti o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             WHERE 1=1
               " . ($search ? "AND (o.numero_ordine LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q)" : '') . "
               " . ($status ? "AND o.stato = :stato" : '') . "
             ORDER BY o.created_at DESC"
        );
        $params = [];
        if ($search) $params[':q']     = '%' . $search . '%';
        if ($status) $params[':stato'] = $status;
        $stmt->execute($params);

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total  = count($orders);

        $active_tab    = 'orders';
        $page_title    = 'Ordini Clienti';
        $page_subtitle = 'Gestione ordini di vendita attivi';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Ordini']];
        $page_actions  = '<a href="' . url('sales/orders/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuovo ordine
        </a>';

        require INCLUDES_DIR . '/header.php';
        // Render simple order list placeholder
        ?>
        <?php include APP_ROOT_DIR . '/app/views/sales/_subnav.php'; ?>
        <div class="qk-card">
          <div class="qk-table-toolbar">
            <div class="qk-table-toolbar-left">
              <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                <div class="qk-filter-wrap">
                  <i class="fa-solid fa-magnifying-glass"></i>
                  <input type="text" name="q" class="qk-filter-input" placeholder="Cerca ordine, cliente…" value="<?= e($search) ?>">
                </div>
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
              </form>
            </div>
            <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= $total ?> ordini</small></div>
          </div>
          <div class="qk-table-wrap">
            <?php if (empty($orders)): ?>
            <div class="qk-empty">
              <i class="fa-solid fa-file-invoice-dollar"></i>
              <p>Nessun ordine trovato.</p>
            </div>
            <?php else: ?>
            <table class="qk-table">
              <thead><tr><th>N° Ordine</th><th>Cliente</th><th>Stato</th><th>Data Ordine</th></tr></thead>
              <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                  <td class="fw-semibold">
                    <a href="<?= url('sales/orders/edit?id=' . $o['id']) ?>" class="qk-text-accent fw-bold text-decoration-none">
                      <?= e($o['numero_ordine'] ?? '') ?>
                    </a>
                  </td>
                  <td><?= e($o['cliente_nome'] ?? '') ?></td>
                  <td><span class="qk-badge"><?= e(ucfirst(str_replace('_', ' ', $o['stato'] ?? ''))) ?></span></td>
                  <td class="qk-text-muted"><?= fmt_date($o['data_ordine'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        </div>
        <?php
        require INCLUDES_DIR . '/footer.php';
    }

    public function create(): void
    {
        $page_title    = 'Nuovo ordine di vendita';
        $page_subtitle = 'In sviluppo';
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Ordini', 'url' => url('sales/orders')],
            ['label' => 'Nuovo']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/shared/_coming_soon.php', [
            'page_title'    => $page_title,
            'page_subtitle' => $page_subtitle,
            'breadcrumbs'   => $breadcrumbs,
            'feature_name'  => 'Creazione ordine di vendita',
        ]);
    }

    public function edit(): void
    {
        $id = (int)get_param('id');
        if ($id <= 0) {
            redirect(url('sales/orders'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $_SESSION['flash_error'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $note = post('note', '');
                $data_consegna_prevista = post('data_consegna_prevista', '');

                try {
                    $stUp = $this->pdo->prepare("
                        UPDATE ordini_clienti 
                        SET note = ?, 
                            data_consegna_prevista = ?, 
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = ?
                    ");
                    $stUp->execute([
                        $note !== '' ? $note : null,
                        $data_consegna_prevista !== '' ? $data_consegna_prevista : null,
                        $id
                    ]);
                    $_SESSION['flash_success'] = 'Ordine aggiornato correttamente.';
                } catch (\Throwable $e) {
                    $_SESSION['flash_error'] = 'Errore durante il salvataggio: ' . $e->getMessage();
                }
            }
            redirect(url('sales/orders/edit') . '?id=' . $id);
        }

        $st = $this->pdo->prepare("
            SELECT o.*,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                   cf.telefono AS cliente_telefono,
                   cf.email AS cliente_email,
                   p.numero_preventivo AS preventivo_numero,
                   u.username AS creato_da_username
            FROM ordini_clienti o
            JOIN clienti_fornitori cf ON cf.id = o.cliente_id
            LEFT JOIN preventivi p ON p.id = o.preventivo_id
            LEFT JOIN utenti u ON u.id = o.creato_da
            WHERE o.id = ?
        ");
        $st->execute([$id]);
        $order = $st->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $_SESSION['flash_error'] = 'Ordine non trovato.';
            redirect(url('sales/orders'));
        }

        $address = null;
        if (!empty($order['indirizzo_spedizione_id'])) {
            $stAddr = $this->pdo->prepare("SELECT * FROM indirizzi WHERE id = ?");
            $stAddr->execute([$order['indirizzo_spedizione_id']]);
            $address = $stAddr->fetch(PDO::FETCH_ASSOC);
        }

        $stLines = $this->pdo->prepare("
            SELECT r.*,
                   p.sku_interno AS prodotto_codice,
                   p.nome AS prodotto_nome,
                   p.traccia_lotti,
                   um.codice AS uom_simbolo
            FROM ordini_clienti_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.ordine_id = ?
        ");
        $stLines->execute([$id]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

        // Fetch linked shipments
        $stShip = $this->pdo->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM spedizioni_righe WHERE spedizione_id = s.id) AS righe_count
            FROM spedizioni s
            WHERE s.ordine_id = ?
            ORDER BY s.created_at DESC
        ");
        $stShip->execute([$id]);
        $shipments = $stShip->fetchAll(PDO::FETCH_ASSOC);

        // Fetch linked DDTs
        $stDdt = $this->pdo->prepare("
            SELECT d.*
            FROM documenti_trasporto d
            WHERE d.ordine_id = ?
            ORDER BY d.created_at DESC
        ");
        $stDdt->execute([$id]);
        $ddts = $stDdt->fetchAll(PDO::FETCH_ASSOC);

        $active_tab    = 'orders';
        $page_title    = 'Ordine ' . $order['numero_ordine'];
        $breadcrumbs   = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Ordini', 'url' => url('sales/orders')],
            ['label' => 'Dettaglio Ordine']
        ];
        $this->page(APP_ROOT_DIR . '/app/views/sales/orders/_detail.php', get_defined_vars());
    }

    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('sales/orders'));
        }

        $id     = (int)post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'completa':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato = 'completato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $_SESSION['flash_success'] = 'Ordine completato.';
                    break;

                case 'riapri':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato = 'in_lavorazione', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $_SESSION['flash_success'] = 'Ordine rimesso in lavorazione.';
                    break;

                case 'annulla':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato = 'annullato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $_SESSION['flash_success'] = 'Ordine annullato.';
                    break;

                case 'segna_fatturato':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'fatturato', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $_SESSION['flash_success'] = 'Ordine segnato come fatturato.';
                    break;

                case 'segna_da_fatturare':
                    $this->pdo->prepare("UPDATE ordini_clienti SET stato_fatturazione = 'da_fatturare', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
                    $_SESSION['flash_success'] = 'Ordine ripristinato a "Da Fatturare".';
                    break;

                case 'crea_spedizione':
                    $this->elaboraCreaSpedizione($id);
                    break;

                default:
                    $_SESSION['flash_error'] = "Azione '$azione' non riconosciuta.";
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        redirect(url('sales/orders/edit') . '?id=' . $id);
    }

    /**
     * API: GET /sales/orders/api/lotti-disponibili?prodotto_id=X
     * Returns available lots for a product ordered FEFO then FIFO.
     */
    public function apiLottiDisponibili(): void
    {
        header('Content-Type: application/json');
        $prodottoId = (int)get_param('prodotto_id');
        if ($prodottoId <= 0) {
            echo json_encode(['error' => 'prodotto_id mancante']);
            exit;
        }

        $st = $this->pdo->prepare("
            SELECT l.id,
                   COALESCE(l.codice_lotto_interno, l.codice_lotto) AS codice,
                   l.quantita_disponibile,
                   l.data_scadenza,
                   l.data_arrivo,
                   l.created_at
            FROM lotti l
            WHERE l.prodotto_id = ?
              AND l.quantita_disponibile > 0
            ORDER BY
                CASE WHEN l.data_scadenza IS NULL THEN 1 ELSE 0 END,
                l.data_scadenza ASC,
                l.created_at ASC
        ");
        $st->execute([$prodottoId]);
        $lotti = $st->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['lotti' => $lotti]);
        exit;
    }

    private function elaboraCreaSpedizione(int $orderId): void
    {
        $vettore = post('vettore', '');
        $tracking = post('tracking', '');
        $causale_trasporto = post('causale_trasporto', 'Vendita');
        $porto = post('porto', 'franco');
        $aspetto_beni = post('aspetto_beni', '');
        $numero_colli = (int)post('numero_colli', '0');
        $peso_lordo_kg = (float)post('peso_lordo_kg', '0.0');

        $lottiPost = $_POST['lotti'] ?? []; // format: [ordine_riga_id => [lotto_id => qta]]
        $quantitaNoLottoPost = $_POST['quantita_no_lotto'] ?? []; // format: [ordine_riga_id => qta]

        $this->pdo->beginTransaction();
        try {
            // Get order details
            $st = $this->pdo->prepare("SELECT * FROM ordini_clienti WHERE id = ?");
            $st->execute([$orderId]);
            $order = $st->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new \Exception("Ordine non trovato.");
            }

            // Fetch order lines to validate and process
            $stLines = $this->pdo->prepare("
                SELECT r.*, p.traccia_lotti, p.nome AS prodotto_nome, p.sku_interno
                FROM ordini_clienti_righe r
                JOIN prodotti p ON p.id = r.prodotto_id
                WHERE r.ordine_id = ?
            ");
            $stLines->execute([$orderId]);
            $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

            // Generate shipment number
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM spedizioni")->fetchColumn();
            $numSped = 'SPED/' . date('Y') . '/' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);

            // Insert shipment
            $stInsSped = $this->pdo->prepare("
                INSERT INTO spedizioni (
                    numero_spedizione, ordine_id, data_spedizione, indirizzo_spedizione_id, vettore, tracking, stato, creato_da, created_at
                ) VALUES (?, ?, CURRENT_DATE, ?, ?, ?, 'spedita', ?, CURRENT_TIMESTAMP)
            ");
            $userId = (int)(current_user()['id'] ?? 0);
            $stInsSped->execute([
                $numSped,
                $orderId,
                $order['indirizzo_spedizione_id'] ?: null,
                $vettore !== '' ? $vettore : null,
                $tracking !== '' ? $tracking : null,
                $userId
            ]);
            $spedizioneId = (int)$this->pdo->lastInsertId();

            // Generate DDT number
            $countDdt = (int)$this->pdo->query("SELECT COUNT(*) FROM documenti_trasporto")->fetchColumn();
            $numDdt = 'DDT/' . date('Y') . '/' . str_pad((string)($countDdt + 1), 4, '0', STR_PAD_LEFT);

            // Insert DDT
            $stInsDdt = $this->pdo->prepare("
                INSERT INTO documenti_trasporto (
                    numero_documento_trasporto, spedizione_id, ordine_id, data, destinatario_id, indirizzo_destinazione_id, causale_trasporto, porto, vettore, aspetto_beni, numero_colli, peso_lordo_kg, stato, creato_da, created_at, updated_at
                ) VALUES (?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, 'emesso', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stInsDdt->execute([
                $numDdt,
                $spedizioneId,
                $orderId,
                $order['cliente_id'],
                $order['indirizzo_spedizione_id'] ?: null,
                $causale_trasporto,
                $porto,
                $vettore !== '' ? $vettore : null,
                $aspetto_beni !== '' ? $aspetto_beni : null,
                $numero_colli,
                $peso_lordo_kg,
                $userId
            ]);
            $ddtId = (int)$this->pdo->lastInsertId();

            $stInsSpedRiga = $this->pdo->prepare("
                INSERT INTO spedizioni_righe (spedizione_id, ordine_riga_id, prodotto_id, lotto_id, quantita, note)
                VALUES (?, ?, ?, ?, ?, '')
            ");

            $stInsDdtRiga = $this->pdo->prepare("
                INSERT INTO documenti_trasporto_righe (
                    documento_trasporto_id, prodotto_id, descrizione, quantita, unita_misura_id, lotto_id, codice_lotto, is_adr, numero_onu, classe_adr, packing_group, posizione_riga, note
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')
            ");

            $stUpLotto = $this->pdo->prepare("UPDATE lotti SET quantita_disponibile = quantita_disponibile - ? WHERE id = ?");
            $stUpGiac = $this->pdo->prepare("UPDATE giacenze SET quantita_disponibile = quantita_disponibile - ?, quantita_riservata = quantita_riservata - ? WHERE prodotto_id = ?");
            $stUpOrdRiga = $this->pdo->prepare("UPDATE ordini_clienti_righe SET quantita_spedita = quantita_spedita + ? WHERE id = ?");

            // Select ADR classification helper
            $stAdr = $this->pdo->prepare("
                SELECT co.codice_onu, co.classe_pericolo, 
                       COALESCE(pca.gruppo_imballaggio_override, co.gruppo_imballaggio) AS gruppo_imballaggio
                FROM prodotto_classificazioni_adr pca
                JOIN adr_codici_onu co ON co.id = pca.codice_onu_id
                WHERE pca.prodotto_id = ? AND pca.e_corrente = 1
                LIMIT 1
            ");

            $pos = 0;
            $anyShipped = false;

            foreach ($lines as $line) {
                $lineId = $line['id'];
                $prodId = $line['prodotto_id'];
                $tracciaLotti = (int)$line['traccia_lotti'];
                $snap = (float)($line['quantita_contenuto_snapshot'] ?? 1.0);

                if ($tracciaLotti === 1) {
                    $lottiForLine = $lottiPost[$lineId] ?? [];
                    foreach ($lottiForLine as $lottoId => $qtaStr) {
                        $qta = (float)$qtaStr;
                        if ($qta <= 0) continue;
                        
                        $anyShipped = true;

                        // Check lot availability
                        $stLotInfo = $this->pdo->prepare("SELECT id, quantita_disponibile, COALESCE(codice_lotto_interno, codice_lotto) AS codice_lotto_interno FROM lotti WHERE id = ?");
                        $stLotInfo->execute([$lottoId]);
                        $lot = $stLotInfo->fetch(PDO::FETCH_ASSOC);
                        if (!$lot) {
                            throw new \Exception("Lotto ID {$lottoId} non trovato per il prodotto {$line['prodotto_nome']}.");
                        }
                        
                        // Quantities are entered in the order UoM.
                        // We must convert to stock UoM for lot/stock updates.
                        $qtaStock = $qta * $snap;

                        if ($lot['quantita_disponibile'] < $qtaStock) {
                            throw new \Exception("Giacenza insufficiente per il lotto {$lot['codice_lotto_interno']} di {$line['prodotto_nome']}. Disponibile: {$lot['quantita_disponibile']}, Richiesto: {$qtaStock}.");
                        }

                        // Insert Shipment Line
                        $stInsSpedRiga->execute([$spedizioneId, $lineId, $prodId, $lottoId, $qta]);

                        // Fetch ADR info
                        $stAdr->execute([$prodId]);
                        $adr = $stAdr->fetch(PDO::FETCH_ASSOC);
                        $isAdr = !empty($adr) ? 1 : 0;
                        $onu = !empty($adr) ? 'UN ' . $adr['codice_onu'] : null;
                        $classe = !empty($adr) ? $adr['classe_pericolo'] : null;
                        $pg = !empty($adr) ? $adr['gruppo_imballaggio'] : null;

                        // Insert DDT Line
                        $stInsDdtRiga->execute([
                            $ddtId, $prodId, $line['descrizione'], $qta, $line['unita_misura_id'], 
                            $lottoId, $lot['codice_lotto_interno'], $isAdr, $onu, $classe, $pg, $pos++
                        ]);

                        // Update lot stock
                        $stUpLotto->execute([$qtaStock, $lottoId]);

                        // Update product giacenze
                        $stUpGiac->execute([$qtaStock, $qtaStock, $prodId]);

                        // Update order line quantita_spedita
                        $stUpOrdRiga->execute([$qta, $lineId]);

                        // Record log movement
                        $stMov = $this->pdo->prepare("
                            INSERT INTO movimenti_magazzino (
                                prodotto_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at
                            ) VALUES (?, 'scarico', ?, ?, ?, 'ddt', ?, CURRENT_TIMESTAMP)
                        ");
                        $stMov->execute([$prodId, $qtaStock, $lottoId, $numDdt, $userId]);
                    }
                } else {
                    $qta = (float)($quantitaNoLottoPost[$lineId] ?? 0.0);
                    if ($qta > 0) {
                        $anyShipped = true;
                        $qtaStock = $qta * $snap;

                        // Insert Shipment Line
                        $stInsSpedRiga->execute([$spedizioneId, $lineId, $prodId, null, $qta]);

                        // Insert DDT Line
                        $stInsDdtRiga->execute([
                            $ddtId, $prodId, $line['descrizione'], $qta, $line['unita_misura_id'], 
                            null, null, 0, null, null, null, $pos++
                        ]);

                        // Update product giacenze
                        $stUpGiac->execute([$qtaStock, $qtaStock, $prodId]);

                        // Update order line quantita_spedita
                        $stUpOrdRiga->execute([$qta, $lineId]);

                        // Record log movement
                        $stMov = $this->pdo->prepare("
                            INSERT INTO movimenti_magazzino (
                                prodotto_id, tipo_movimento, quantita, lotto_id, riferimento_esterno, documento_tipo, creato_da, created_at
                            ) VALUES (?, 'scarico', ?, null, ?, 'ddt', ?, CURRENT_TIMESTAMP)
                        ");
                        $stMov->execute([$prodId, $qtaStock, $numDdt, $userId]);
                    }
                }
            }

            if (!$anyShipped) {
                throw new \Exception("Nessuna quantità valida specificata per la spedizione.");
            }

            // Recalculate order status: check if all lines are fully shipped
            $stAll = $this->pdo->prepare("SELECT id, quantita_ordinata, quantita_spedita FROM ordini_clienti_righe WHERE ordine_id = ?");
            $stAll->execute([$orderId]);
            $allLines = $stAll->fetchAll(PDO::FETCH_ASSOC);

            $allCompleted = true;
            $anyCompleted = false;
            foreach ($allLines as $al) {
                $ord = (float)$al['quantita_ordinata'];
                $sped = (float)$al['quantita_spedita'];
                if ($sped < $ord) {
                    $allCompleted = false;
                }
                if ($sped > 0) {
                    $anyCompleted = true;
                }
            }

            $nuovoStato = 'in_lavorazione';
            if ($allCompleted) {
                $nuovoStato = 'spedito';
            } elseif ($anyCompleted) {
                $nuovoStato = 'parzialmente_spedito';
            }

            $this->pdo->prepare("UPDATE ordini_clienti SET stato = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                      ->execute([$nuovoStato, $orderId]);

            $this->pdo->commit();
            $_SESSION['flash_success'] = "Spedizione {$numSped} e Documento di Trasporto {$numDdt} registrati correttamente.";
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
