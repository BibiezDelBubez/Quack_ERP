<?php

declare(strict_types=1);

final class SalesController extends BaseController
{
    public function dashboard(): void
    {
        // 1. KPI Counts
        $quotes_bozza = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM preventivi WHERE stato = 'bozza'"
        )->fetchColumn();

        $quotes_inviati = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM preventivi WHERE stato = 'inviata'"
        )->fetchColumn();

        $orders_attivi = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ordini_clienti WHERE stato IN ('in_lavorazione', 'parzialmente_spedito', 'spedito')"
        )->fetchColumn();

        // 2. Recent quotes
        $stmtQ = $this->pdo->query(
            "SELECT p.id, p.numero_preventivo, p.stato, p.totale,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
             FROM preventivi p
             JOIN clienti_fornitori cf ON cf.id = p.cliente_id
             ORDER BY p.created_at DESC
             LIMIT 5"
        );
        $recent_quotes = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        // 3. Recent orders
        $stmtO = $this->pdo->query(
            "SELECT o.id, o.numero_ordine, o.stato, o.data_ordine,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome
             FROM ordini_clienti o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             ORDER BY o.created_at DESC
             LIMIT 5"
        );
        $recent_orders = $stmtO->fetchAll(PDO::FETCH_ASSOC);

        // View vars
        $active_tab    = 'dashboard';
        $page_title    = 'Vendite';
        $page_subtitle = 'Panoramica ciclo vendite e fatturazione';
        $breadcrumbs   = [['label' => 'Vendite']];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/sales/sales_dashboard.php';
        require INCLUDES_DIR . '/footer.php';
    }
}
