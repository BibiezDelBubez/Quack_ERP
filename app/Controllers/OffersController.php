<?php

declare(strict_types=1);

require_once INCLUDES_DIR . '/offerte.php';

final class OffersController extends BaseController
{
    /* ── Lista ────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $status = get_param('status');

        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.numero_preventivo, o.stato, o.data_offerta, o.data_scadenza,
                    o.imponibile, o.totale, o.note_cliente,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS cliente_nome,
                    cv.nome AS canale_vendita
             FROM preventivi o
             JOIN clienti_fornitori cf ON cf.id = o.cliente_id
             LEFT JOIN canali_vendita cv ON cv.id = o.canale_vendita_id
             WHERE 1=1
               " . ($search ? "AND (o.numero_preventivo LIKE :q OR cf.ragione_sociale LIKE :q OR cf.nome LIKE :q)" : '') . "
               " . ($status ? "AND o.stato = :stato" : '') . "
             ORDER BY o.created_at DESC"
        );
        $params = [];
        if ($search) $params[':q']     = '%' . $search . '%';
        if ($status) $params[':stato'] = $status;
        $stmt->execute($params);

        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total  = count($offers);

        $page_title    = 'Offerte';
        $page_subtitle = 'Preventivi e offerte commerciali';
        $breadcrumbs   = [['label' => 'Offerte']];
        $page_actions  = '<a href="' . url('offers/create') . '" class="btn-qk btn-qk-primary">
            <i class="fa-solid fa-plus"></i> Nuova offerta
        </a>';

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/offers/_list.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Creazione ────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $errors = [];
        $values = $this->defaultValues();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                $errors = qk_offerta_validate($values, $righe);

                if (empty($errors)) {
                    try {
                        $user_id = (int) (current_user()['id'] ?? 0);
                        $id      = qk_insert_offerta($this->pdo, $values, $righe, $user_id);
                        redirect_with('offers', 'success', 'Preventivo ' . e($values['numero_offerta']) . ' salvato correttamente.');
                    } catch (Throwable $e) {
                        $errors['_global'] = 'Errore database: ' . $e->getMessage();
                    }
                }
            }
        }

        $numero_offerta = $values['numero_offerta'] ?: qk_next_numero_offerta($this->pdo);

        $page_title    = 'Nuova offerta';
        $page_subtitle = 'Crea un preventivo commerciale';
        $breadcrumbs   = [
            ['label' => 'Offerte', 'url' => url('offers')],
            ['label' => 'Nuova'],
        ];

        require INCLUDES_DIR . '/header.php';
        require APP_ROOT_DIR . '/app/views/offers/_form.php';
        require INCLUDES_DIR . '/footer.php';
    }

    /* ── Modifica (placeholder) ───────────────────────────────────────────── */

    public function edit(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('offers', 'danger', 'Offerta non specificata.');
        }

        // TODO: implementazione completa in una prossima iterazione
        $page_title    = 'Modifica offerta #' . $id;
        $page_subtitle = 'In sviluppo';
        $breadcrumbs   = [
            ['label' => 'Offerte', 'url' => url('offers')],
            ['label' => '#' . $id],
        ];

        $this->page(APP_ROOT_DIR . '/app/views/shared/_coming_soon.php', [
            'page_title'    => $page_title,
            'page_subtitle' => $page_subtitle,
            'breadcrumbs'   => $breadcrumbs,
            'feature_name'  => 'Modifica offerta',
        ]);
    }

    /* ── API: ricerca clienti (JSON) ──────────────────────────────────────── */

    public function apiClienti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = get_param('q');
        if (strlen($q) < 1) {
            echo '[]';
            exit;
        }
        echo json_encode(qk_search_clienti($this->pdo, $q), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── API: ricerca prodotti (JSON) ─────────────────────────────────────── */

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $q = get_param('q');
        echo json_encode(qk_search_prodotti($this->pdo, $q), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ── API: crea cliente rapido (JSON, POST) ────────────────────────────── */

    public function apiCreaCliente(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF invalido']);
            exit;
        }

        $ts  = post('tipo_soggetto', 'azienda');
        $rs  = post('ragione_sociale');
        $nm  = post('nome');
        $cog = post('cognome');
        $email = post('email');
        $tel   = post('telefono');

        // Validazione minima
        $label = $rs ?: trim("$nm $cog");
        if (trim($label) === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Nome o ragione sociale obbligatori.']);
            exit;
        }

        try {
            $id = qk_crea_cliente_rapido($this->pdo, [
                'tipo_soggetto'  => $ts,
                'ragione_sociale' => $rs,
                'nome'           => $nm,
                'cognome'        => $cog,
                'email'          => $email,
                'telefono'       => $tel,
            ]);
            echo json_encode([
                'success'  => true,
                'id'       => $id,
                'label'    => $label,
                'email'    => $email,
                'telefono' => $tel,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: crea prodotto rapido (JSON, POST) ───────────────────────────── */

    public function apiCreaProdotto(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF invalido']);
            exit;
        }

        $nome = post('nome');
        $sku  = post('sku_interno');
        $um   = post('unita_misura', 'pz');

        if (trim($nome) === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Il nome prodotto è obbligatorio.']);
            exit;
        }

        try {
            $id  = qk_crea_prodotto_rapido($this->pdo, ['nome' => $nome, 'sku_interno' => $sku, 'unita_misura' => $um]);
            $row = $this->pdo->prepare('SELECT sku_interno, unita_misura FROM prodotti WHERE id = :id');
            $row->execute([':id' => $id]);
            $prod = $row->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success'      => true,
                'id'           => $id,
                'label'        => $nome,
                'sku'          => $prod['sku_interno'],
                'unita_misura' => $prod['unita_misura'],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── Helper privati ───────────────────────────────────────────────────── */

    private function defaultValues(): array
    {
        return [
            'numero_offerta'          => '',
            'cliente_id'              => '',
            'cliente_label'           => '',
            'alla_ca'                 => '',
            'data_offerta'            => date('Y-m-d'),
            'data_scadenza'           => date('Y-m-d', strtotime('+6 months')),
            'data_consegna_richiesta' => '',
            'canale_vendita'          => 'email_diretta',
            'stato'                   => 'bozza',
            'condizioni_pagamento'    => '',
            'trasporto'               => 'Franco destino',
            'sconto_globale_perc'     => '0',
            'note_cliente'            => '',
            'note_interne'            => '',
        ];
    }

    private function readPostValues(): array
    {
        return [
            'numero_offerta'          => post('numero_offerta'),
            'cliente_id'              => post('cliente_id'),
            'cliente_label'           => post('cliente_label'),
            'alla_ca'                 => post('alla_ca'),
            'data_offerta'            => post('data_offerta'),
            'data_scadenza'           => post('data_scadenza'),
            'data_consegna_richiesta' => post('data_consegna_richiesta'),
            'canale_vendita'          => post('canale_vendita'),
            'stato'                   => post('stato', 'bozza'),
            'condizioni_pagamento'    => post('condizioni_pagamento'),
            'trasporto'               => post('trasporto'),
            'sconto_globale_perc'     => post('sconto_globale_perc', '0'),
            'note_cliente'            => post('note_cliente'),
            'note_interne'            => post('note_interne'),
        ];
    }

    /** Legge le righe da $_POST['righe'] e le normalizza */
    private function readPostRighe(): array
    {
        $raw   = $_POST['righe'] ?? [];
        $righe = [];
        foreach ($raw as $r) {
            if (empty(trim($r['descrizione'] ?? ''))) continue; // salta righe vuote
            $righe[] = [
                'prodotto_id'    => $r['prodotto_id']    ?: null,
                'descrizione'    => $r['descrizione']    ?? '',
                'quantita'       => $r['quantita']       ?? 0,
                'unita_misura'   => $r['unita_misura']   ?? '',
                'prezzo_unitario' => $r['prezzo_unitario'] ?? 0,
                'sconto_perc'    => $r['sconto_perc']    ?? 0,
                'iva_perc'       => $r['iva_perc']       ?? 22,
                'note_riga'      => $r['note_riga']      ?? '',
            ];
        }
        return $righe;
    }
}
