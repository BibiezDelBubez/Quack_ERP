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
}
