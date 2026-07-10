<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/magazzino.php';

final class DashboardController extends BaseController
{
    public function index(): void
    {
        // 1. Operational KPIs

        // KPI 1: Active customer orders (to fulfill)
        $ordini_clienti_evadere = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ordini_clienti WHERE stato IN ('in_lavorazione', 'parzialmente_spedito')"
        )->fetchColumn();

        // KPI 2: Shipments in preparation
        $spedizioni_preparazione = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM spedizioni WHERE stato = 'preparazione'"
        )->fetchColumn();

        // KPI 3: Understock Warning — nel magazzino attivo (soglia per coppia
        // prodotto-magazzino da quando prodotti.scorta_minima è stata rimossa,
        // vedi Piano Multi-Magazzino §3.5).
        $stSottoscorta = $this->pdo->prepare("
            SELECT COUNT(*) FROM prodotti p
            JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = ?
            WHERE p.attivo = 1 AND " . qk_sottoscorta_where('g') . "
        ");
        $magazzinoAttivoId = qk_magazzino_attivo_id($this->pdo);
        $stSottoscorta->execute([$magazzinoAttivoId]);
        $sottoscorta_count = (int) $stSottoscorta->fetchColumn();

        // Fix 4 (§8.5): nome della sede a cui si riferisce il KPI, così non
        // viene letto per errore come dato aziendale aggregato.
        $stNomeMag = $this->pdo->prepare('SELECT nome FROM magazzini WHERE id = ?');
        $stNomeMag->execute([$magazzinoAttivoId]);
        $magazzinoAttivoNome = (string) ($stNomeMag->fetchColumn() ?: '');

        // KPI 4: Active supplier orders (incoming goods)
        $acquisti_in_arrivo = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ordini_testata WHERE stato IN ('inviato', 'parzialmente_ricevuto')"
        )->fetchColumn();

        // KPI 5: Current Month Turnover
        // Nota valute: tutti gli importi vengono sommati indistintamente,
        // senza conversione/isolamento per valuta. Deliberatamente rimandato
        // a una futura versione (vedi docs/Backlog Sviluppi Futuri.md).
        $current_month = date('Y-m');
        $stKpi5 = $this->pdo->prepare("
            SELECT COALESCE(SUM(r.quantita_ordinata * r.prezzo_unitario), 0.0)
            FROM ordini_clienti_righe r
            JOIN ordini_clienti o ON o.id = r.ordine_id
            WHERE o.stato != 'annullato' AND o.data_ordine LIKE ?
        ");
        $stKpi5->execute([$current_month . '%']);
        $monthly_turnover = (float) $stKpi5->fetchColumn();

        // Combine into KPI array
        $kpi = [
            [
                'icon'  => 'box-open',
                'color' => 'accent',
                'value' => (string) $ordini_clienti_evadere,
                'label' => 'Ordini Clienti da Evadere',
            ],
            [
                'icon'  => 'truck-ramp-box',
                'color' => 'info',
                'value' => (string) $spedizioni_preparazione,
                'label' => 'Spedizioni in Preparazione',
            ],
            [
                'icon'  => 'triangle-exclamation',
                'color' => $sottoscorta_count > 0 ? 'danger' : 'success',
                'value' => (string) $sottoscorta_count,
                'label' => 'Prodotti Sottoscorta',
                'sublabel' => 'Magazzino: ' . $magazzinoAttivoNome,
            ],
            [
                'icon'  => 'dolly',
                'color' => 'warning',
                'value' => (string) $acquisti_in_arrivo,
                'label' => 'Acquisti in Arrivo',
            ],
            [
                'icon'  => 'coins',
                'color' => 'success',
                'value' => '€ ' . number_format($monthly_turnover, 2, ',', '.'),
                'label' => 'Fatturato Mese',
            ],
        ];

        // 2. Recent Sales Orders
        $stmtSO = $this->pdo->query(
            "SELECT o.id, o.numero_ordine, o.stato, o.data_ordine,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                    (SELECT COALESCE(SUM(quantita_ordinata * prezzo_unitario), 0.0) FROM ordini_clienti_righe WHERE ordine_id = o.id) AS totale
             FROM ordini_clienti o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             ORDER BY o.created_at DESC
             LIMIT 5"
        );
        $recent_sales_orders = $stmtSO->fetchAll(PDO::FETCH_ASSOC);

        // 3. Recent Purchase Orders
        $stmtPO = $this->pdo->query(
            "SELECT ot.id, ot.numero, ot.stato, ot.data_ordine, ot.totale,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS fornitore_nome
             FROM ordini_testata ot
             JOIN clienti_fornitori cf ON cf.id = ot.fornitore_id
             ORDER BY ot.created_at DESC
             LIMIT 5"
        );
        $recent_purchase_orders = $stmtPO->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/dashboard/_index.php', [
            'page_title'             => 'Dashboard',
            'page_subtitle'          => 'Panoramica operativa — ' . date('d/m/Y'),
            'kpi'                    => $kpi,
            'recent_sales_orders'    => $recent_sales_orders,
            'recent_purchase_orders' => $recent_purchase_orders,
        ]);
    }
}

