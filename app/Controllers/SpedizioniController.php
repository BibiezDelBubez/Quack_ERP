<?php

declare(strict_types=1);

final class SpedizioniController extends BaseController
{
    /**
     * GET /sales/shipments — lista globale spedizioni
     */
    public function index(): void
    {
        $search = get_param('q');
        $stato  = get_param('stato');

        $sql = "
            SELECT s.id, s.numero_spedizione, s.data_spedizione, s.stato, s.vettore, s.tracking,
                   s.ordine_id,
                   o.numero_ordine,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                   d.id AS ddt_id,
                   d.numero_documento_trasporto
            FROM spedizioni s
            JOIN ordini_clienti o ON o.id = s.ordine_id
            JOIN clienti_fornitori cf ON cf.id = o.cliente_id
            LEFT JOIN documenti_trasporto d ON d.spedizione_id = s.id
            WHERE 1=1
        ";
        $params = [];
        if ($search) {
            $sql .= " AND (s.numero_spedizione LIKE :q OR o.numero_ordine LIKE :q OR cf.ragione_sociale LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($stato) {
            $sql .= " AND s.stato = :stato";
            $params[':stato'] = $stato;
        }
        $sql .= " ORDER BY s.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $page_title    = 'Spedizioni';
        $page_subtitle = 'Registro delle spedizioni e DDT emessi';
        $breadcrumbs   = [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Spedizioni']];
        $active_tab    = 'shipments';

        require INCLUDES_DIR . '/header.php';
        include APP_ROOT_DIR . '/app/views/sales/_subnav.php';
        ?>
        <div class="qk-card">
          <div class="qk-table-toolbar">
            <div class="qk-table-toolbar-left">
              <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
                <div class="qk-filter-wrap">
                  <i class="fa-solid fa-magnifying-glass"></i>
                  <input type="text" name="q" class="qk-filter-input" placeholder="Cerca spedizione, ordine, cliente…" value="<?= e($search) ?>">
                </div>
                <select name="stato" class="qk-form-control" style="width:auto">
                  <option value="">Tutti gli stati</option>
                  <option value="preparazione" <?= $stato==='preparazione'?'selected':'' ?>>Preparazione</option>
                  <option value="spedita" <?= $stato==='spedita'?'selected':'' ?>>Spedita</option>
                  <option value="consegnata" <?= $stato==='consegnata'?'selected':'' ?>>Consegnata</option>
                  <option value="reso" <?= $stato==='reso'?'selected':'' ?>>Reso</option>
                </select>
                <button type="submit" class="btn-qk btn-qk-secondary btn-qk-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
              </form>
            </div>
            <div class="qk-table-toolbar-right"><small class="qk-text-muted"><?= count($shipments) ?> spedizioni</small></div>
          </div>
          <div class="qk-table-wrap">
            <?php if (empty($shipments)): ?>
              <div class="qk-empty">
                <i class="fa-solid fa-truck"></i>
                <p>Nessuna spedizione trovata.</p>
              </div>
            <?php else: ?>
              <table class="qk-table" style="font-size:0.875rem">
                <thead>
                  <tr>
                    <th>N° Spedizione</th>
                    <th>Data</th>
                    <th>Ordine</th>
                    <th>Cliente</th>
                    <th>Vettore</th>
                    <th>Tracking</th>
                    <th>Stato</th>
                    <th>DDT</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($shipments as $s):
                    $statoClass = match($s['stato']) {
                        'spedita'    => 'shipped',
                        'consegnata' => 'delivered',
                        'reso'       => 'cancelled',
                        default      => 'draft',
                    };
                  ?>
                  <tr>
                    <td class="fw-semibold"><?= e($s['numero_spedizione']) ?></td>
                    <td><?= fmt_date($s['data_spedizione']) ?></td>
                    <td>
                      <a href="<?= url('sales/orders/edit?id=' . $s['ordine_id']) ?>" class="qk-text-accent text-decoration-none fw-semibold">
                        <?= e($s['numero_ordine']) ?>
                      </a>
                    </td>
                    <td><?= e($s['cliente_nome']) ?></td>
                    <td><?= e($s['vettore'] ?: '—') ?></td>
                    <td><?php if ($s['tracking']): ?><code style="font-size:0.78rem"><?= e($s['tracking']) ?></code><?php else: ?>—<?php endif; ?></td>
                    <td><span class="qk-badge <?= $statoClass ?>"><?= e(ucfirst($s['stato'])) ?></span></td>
                    <td>
                      <?php if ($s['numero_documento_trasporto']): ?>
                        <a href="<?= url('sales/shipments/ddt/view?id=' . $s['ddt_id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" target="_blank">
                          <i class="fa-solid fa-file-lines me-1"></i><?= e($s['numero_documento_trasporto']) ?>
                        </a>
                      <?php else: ?>—<?php endif; ?>
                    </td>
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

    /**
     * GET  /sales/shipments/new?order_id=X — pagina pick & pack dedicata
     * POST /sales/shipments/new             — crea spedizione (delega a SalesOrdersController::elaboraCreaSpedizione)
     */
    public function nuova(): void
    {
        $orderId = (int)get_param('order_id');
        if ($orderId <= 0) redirect(url('sales/orders'));

        // Carica ordine
        $stOrd = $this->pdo->prepare("
            SELECT o.*,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                   cf.email AS cliente_email,
                   cf.telefono AS cliente_telefono
            FROM ordini_clienti o
            JOIN clienti_fornitori cf ON cf.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stOrd->execute([$orderId]);
        $order = $stOrd->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $_SESSION['flash_error'] = 'Ordine non trovato.';
            redirect(url('sales/orders'));
        }

        // Blocca solo gli stati terminali
        if (in_array($order['stato'], ['annullato', 'completato'])) {
            $_SESSION['flash_error'] = 'Impossibile creare spedizioni per un ordine ' . $order['stato'] . '.';
            redirect(url('sales/orders/edit') . '?id=' . $orderId);
        }

        // Carica righe con residuo da spedire
        $stLines = $this->pdo->prepare("
            SELECT r.*,
                   p.sku_interno AS prodotto_codice,
                   p.nome        AS prodotto_nome,
                   p.traccia_lotti,
                   um.codice     AS uom_simbolo,
                   (r.quantita_ordinata - r.quantita_spedita) AS qta_residua
            FROM ordini_clienti_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.ordine_id = ?
              AND (r.quantita_ordinata - r.quantita_spedita) > 0.0005
            ORDER BY r.id ASC
        ");
        $stLines->execute([$orderId]);
        $righe = $stLines->fetchAll(PDO::FETCH_ASSOC);

        if (empty($righe)) {
            $_SESSION['flash_error'] = 'Tutte le righe di questo ordine sono già state spedite.';
            redirect(url('sales/orders/edit') . '?id=' . $orderId);
        }

        // Carica lotti disponibili per tutti i prodotti con traccia_lotti in un solo query
        $prodIds = array_unique(array_filter(array_column($righe, 'prodotto_id')));
        $lottiPerProdotto = [];
        if (!empty($prodIds)) {
            $in = implode(',', array_map('intval', $prodIds));
            $stLotti = $this->pdo->query("
                SELECT l.prodotto_id,
                       l.id,
                       COALESCE(l.codice_lotto_interno, l.codice_lotto) AS codice,
                       l.quantita_disponibile,
                       l.data_scadenza
                FROM lotti l
                WHERE l.prodotto_id IN ($in)
                  AND l.quantita_disponibile > 0
                ORDER BY l.prodotto_id,
                         CASE WHEN l.data_scadenza IS NULL THEN 1 ELSE 0 END,
                         l.data_scadenza ASC,
                         l.created_at ASC
            ");
            foreach ($stLotti->fetchAll(PDO::FETCH_ASSOC) as $lot) {
                $lottiPerProdotto[$lot['prodotto_id']][] = $lot;
            }
        }

        $page_title  = 'Nuova Spedizione — ' . $order['numero_ordine'];
        $breadcrumbs = [
            ['label' => 'Vendite',   'url' => url('sales')],
            ['label' => 'Ordini',    'url' => url('sales/orders')],
            ['label' => $order['numero_ordine'], 'url' => url('sales/orders/edit') . '?id=' . $orderId],
            ['label' => 'Nuova Spedizione'],
        ];
        $extra_css = ['sales-shipment-new.css'];
        $extra_js  = ['sales-shipment-new.js'];

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_nuova.php', get_defined_vars());
    }

    /**
     * GET /sales/shipments/ddt/view?id=X — vista/stampa DDT
     * id = documenti_trasporto.id
     */
    public function ddtView(): void
    {
        $id = (int)get_param('id');
        if ($id <= 0) redirect(url('sales/shipments'));

        $st = $this->pdo->prepare("
            SELECT d.*,
                   s.numero_spedizione, s.vettore AS s_vettore, s.tracking,
                   o.numero_ordine,
                   COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS destinatario_nome,
                   cf.partita_iva AS destinatario_piva,
                   cf.codice_fiscale AS destinatario_cf,
                   a.via, a.civico, a.cap, a.citta, a.provincia, a.nazione, a.nome_sede,
                   mit.ragione_sociale AS mittente_nome,
                   mit.partita_iva    AS mittente_piva,
                   mita.via           AS mittente_via,
                   mita.civico        AS mittente_civico,
                   mita.cap           AS mittente_cap,
                   mita.citta         AS mittente_citta,
                   mita.provincia     AS mittente_provincia
            FROM documenti_trasporto d
            JOIN ordini_clienti o ON o.id = d.ordine_id
            JOIN clienti_fornitori cf ON cf.id = d.destinatario_id
            LEFT JOIN indirizzi a ON a.id = d.indirizzo_destinazione_id
            LEFT JOIN spedizioni s ON s.id = d.spedizione_id
            LEFT JOIN aziende_interne az ON az.id = 1
            LEFT JOIN clienti_fornitori mit ON mit.id = az.soggetto_id
            LEFT JOIN indirizzi mita ON mita.soggetto_id = az.soggetto_id AND mita.predefinito = 1
            WHERE d.id = ?
        ");
        $st->execute([$id]);
        $ddt = $st->fetch(PDO::FETCH_ASSOC);

        if (!$ddt) {
            $_SESSION['flash_error'] = 'DDT non trovato.';
            redirect(url('sales/shipments'));
        }

        $stRighe = $this->pdo->prepare("
            SELECT r.*,
                   p.nome AS prodotto_nome, p.sku_interno AS prodotto_codice,
                   um.codice AS uom_codice
            FROM documenti_trasporto_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.documento_trasporto_id = ?
            ORDER BY r.posizione_riga ASC
        ");
        $stRighe->execute([$id]);
        $righe = $stRighe->fetchAll(PDO::FETCH_ASSOC);

        $page_title  = 'DDT ' . $ddt['numero_documento_trasporto'];
        $breadcrumbs = [
            ['label' => 'Vendite', 'url' => url('sales')],
            ['label' => 'Spedizioni', 'url' => url('sales/shipments')],
            ['label' => $ddt['numero_documento_trasporto']],
        ];
        $extra_css = ['sales-ddt.css'];

        $this->page(APP_ROOT_DIR . '/app/views/sales/shipments/_ddt_view.php', get_defined_vars());
    }
}
