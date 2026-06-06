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
                   um.codice AS uom_simbolo
            FROM ordini_clienti_righe r
            LEFT JOIN prodotti p ON p.id = r.prodotto_id
            LEFT JOIN unita_misura um ON um.id = r.unita_misura_id
            WHERE r.ordine_id = ?
        ");
        $stLines->execute([$id]);
        $lines = $stLines->fetchAll(PDO::FETCH_ASSOC);

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

                default:
                    $_SESSION['flash_error'] = "Azione '$azione' non riconosciuta.";
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        redirect(url('sales/orders/edit') . '?id=' . $id);
    }
}
