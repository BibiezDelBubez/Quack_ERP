<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_quotes.php';

/**
 * PurchasesQuotesController — Offerte ricevute dai fornitori (ciclo acquisti v2).
 *
 * Route   Action
 * ──────────────────────────────────────────────────────────────────────────
 * GET  /purchases/quotes              index()    Elenco offerte
 * GET  /purchases/quotes/create       create()   Form nuova offerta
 * POST /purchases/quotes/create       create()   Salva
 * GET  /purchases/quotes/edit?id=N    edit()     Form modifica
 * POST /purchases/quotes/edit?id=N    edit()     Salva modifiche
 * POST /purchases/quotes/action       action()   accetta | rifiuta
 */
class PurchasesQuotesController extends BaseController
{
    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $rfq_id = (int) get_param('rfq_id', '0');

        $offerte_list = qk_offerte_list($this->pdo, $search, $stato, $rfq_id);
        $page_title   = 'Preventivi Fornitori';
        $breadcrumbs = [
            ['label' => 'Acquisti'],
            ['label' => 'Preventivi Fornitori']
        ];
        $page_actions = '<a href="' . url('purchases/quotes/create') . '" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Registra Preventivo Spontaneo</a>';

        $this->page(APP_ROOT_DIR . '/app/views/purchases/quotes/_list.php', get_defined_vars());
    }

    public function create(): void
    {
        $errors = [];
        $values = $this->defaultValues();
        $righe  = [];

        // Pre-popola fornitore e rfq_id se arriva da una RFQ
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $rfq_id = (int) get_param('rfq_id', '0');
            if ($rfq_id > 0) {
                require_once INCLUDES_DIR . '/purchases_rfq.php';
                $detail = qk_rfq_detail($this->pdo, $rfq_id);
                if ($detail) {
                    $values['rfq_id']       = (string)$rfq_id;
                    $values['fornitore_id'] = (string)($detail['rfq']['fornitore_id'] ?? '');
                    $values['fornitore_nome'] = (string)($detail['rfq']['fornitore_name'] ?? $detail['rfq']['fornitore_nome'] ?? '');
                    $righe = array_map(function (array $r) {
                        return [
                            'rfq_riga_id'         => (string)$r['id'],
                            'prodotto_id'         => (string)$r['prodotto_id'],
                            'prodotto_nome'       => $r['prodotto_nome'],
                            'sku_interno'         => $r['sku_interno'],
                            'descrizione'         => $r['descrizione'] ?? '',
                            'sku_fornitore'       => $r['sku_fornitore'] ?? '',
                            'quantita_offerta'    => (string)$r['quantita_richiesta'],
                            'unita_misura_id'     => (string)($r['unita_misura_id'] ?? ''),
                            'uom'                 => $r['uom'] ?? '',
                            'prezzo_unitario'     => ($r['prezzo_indicativo'] !== null && $r['prezzo_indicativo'] !== '') ? (string)$r['prezzo_indicativo'] : '',
                            'sconto_perc'         => '0',
                            'iva_perc'            => isset($r['iva_perc']) ? (string)$r['iva_perc'] : '22',
                            'data_consegna_prevista' => $r['data_consegna_richiesta'] ?? '',
                            'note'                => '',
                        ];
                    }, $detail['righe']);
                    $dates = array_filter(array_map(fn($r) => $r['data_consegna_richiesta'] ?? null, $detail['righe']));
                    $values['data_consegna_default'] = !empty($dates) ? min($dates) : '';
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                
                if (isset($_FILES['allegato_preventivo']) && $_FILES['allegato_preventivo']['error'] === UPLOAD_ERR_OK) {
                    $file_path = $this->handleUpload('allegato_preventivo', $errors);
                    if ($file_path !== null) {
                        $values['allegato_path'] = $file_path;
                    }
                }
                
                $errors = array_merge($errors, $this->validate($values, $righe));

                if (empty($errors)) {
                    try {
                        $id = qk_offerta_save($this->pdo, $values, $righe, (int)current_user()['id']);
                        redirect(url('purchases/quotes/edit') . '?id=' . $id . '&saved=1');
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $metodi_pagamento = $this->pdo->query("SELECT * FROM metodi_pagamento WHERE attivo = 1 ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);

        $anteprima_numero = qk_peek_numero_acquisto($this->pdo, 'offerta');
        $page_title       = 'Registra Preventivo';
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/quotes')],
            ['label' => 'Registra Preventivo']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/quotes/_form.php', get_defined_vars());
    }

    public function edit(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) redirect(url('purchases/quotes'));

        $detail = qk_offerta_detail($this->pdo, $id);
        if (!$detail) redirect(url('purchases/quotes'));

        $errors = [];
        $values = $this->mapDetailToValues($detail['offerta']);
        $righe  = $detail['righe'];
        $dates = array_filter(array_map(fn($r) => $r['data_consegna_prevista'] ?? null, $righe));
        $values['data_consegna_default'] = !empty($dates) ? min($dates) : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                $righe  = $this->readPostRighe();
                
                if (post('rimuovi_allegato', '') === '1') {
                    $values['allegato_path'] = '';
                }
                
                if (isset($_FILES['allegato_preventivo']) && $_FILES['allegato_preventivo']['error'] === UPLOAD_ERR_OK) {
                    $file_path = $this->handleUpload('allegato_preventivo', $errors);
                    if ($file_path !== null) {
                        $values['allegato_path'] = $file_path;
                    }
                }
                
                $errors = array_merge($errors, $this->validate($values, $righe));

                if (empty($errors)) {
                    try {
                        qk_offerta_save($this->pdo, $values, $righe, (int)current_user()['id'], $id);
                        $detail = qk_offerta_detail($this->pdo, $id);
                        $righe  = $detail['righe'];
                        $values = $this->mapDetailToValues($detail['offerta']);
                        $_SESSION['flash_success'] = 'Offerta salvata.';
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $metodi_pagamento = $this->pdo->query("SELECT * FROM metodi_pagamento WHERE attivo = 1 ORDER BY descrizione")->fetchAll(PDO::FETCH_ASSOC);

        $saved      = get_param('saved', '') === '1';
        $page_title = 'Preventivo ' . $detail['offerta']['numero'];
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/quotes')],
            ['label' => 'Dettaglio Preventivo']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/quotes/_form.php', get_defined_vars());
    }

    /**
     * POST /purchases/quotes/action
     * Body: id, azione ('accetta' | 'rifiuta'), [genera_ordine=1]
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/quotes'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'accetta':
                    qk_offerta_accetta($this->pdo, $id, (int)current_user()['id']);
                    $_SESSION['flash_success'] = 'Offerta accettata.';

                    // Se richiesto, genera subito l'ordine
                    if (post('genera_ordine', '') === '1') {
                        require_once INCLUDES_DIR . '/purchases_orders.php';
                        $ordine_id = qk_ordine_da_offerta($this->pdo, $id, (int)current_user()['id']);
                        $_SESSION['flash_success'] = 'Offerta accettata e ordine generato.';
                        redirect(url('purchases/orders/edit') . '?id=' . $ordine_id);
                    }
                    redirect(url('purchases/quotes/edit') . '?id=' . $id);
                    break;

                case 'rifiuta':
                    $motivo = post('motivo_rifiuto_select', '');
                    if ($motivo === 'Altro') {
                        $motivo = post('motivo_rifiuto_altro', '');
                    }
                    qk_offerta_rifiuta($this->pdo, $id, (int)current_user()['id'], $motivo);
                    $_SESSION['flash_success'] = 'Offerta rifiutata.';
                    redirect(url('purchases/quotes/edit') . '?id=' . $id);
                    break;

                default:
                    $_SESSION['flash_error'] = "Azione '$azione' non riconosciuta.";
                    redirect(url('purchases/quotes/edit') . '?id=' . $id);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            redirect(url('purchases/quotes/edit') . '?id=' . $id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER PRIVATI
    // ─────────────────────────────────────────────────────────────────────────

    private function defaultValues(): array
    {
        return [
            'rfq_id'                 => '',
            'fornitore_id'           => '',
            'fornitore_nome'         => '',
            'stato'                  => 'in_valutazione',
            'data_offerta'           => date('Y-m-d'),
            'data_scadenza_offerta'  => '',
            'condizioni_pagamento'   => '',
            'valuta'                 => 'EUR',
            'riferimento_fornitore'  => '',
            'note_fornitore'         => '',
            'note_interne'           => '',
            'allegato_path'          => '',
            'contatto_id'             => '',
            'contatto_nome'           => '',
            'contatto_telefono'       => '',
            'contatto_email'          => '',
            'fornitore_telefono'      => '',
            'fornitore_email'         => '',
            'listino_acquisto_id'     => '',
            'metodo_pagamento_id'     => '',
            'resa_porto'              => '',
            'data_consegna_default'   => '',
        ];
    }

    private function mapDetailToValues(array $off): array
    {
        return [
            'rfq_id'                => (string)($off['rfq_id'] ?? ''),
            'fornitore_id'          => (string)($off['fornitore_id'] ?? ''),
            'fornitore_nome'        => $off['fornitore_nome'] ?? '',
            'stato'                 => $off['stato'],
            'data_offerta'          => $off['data_offerta'] ?? date('Y-m-d'),
            'data_scadenza_offerta' => $off['data_scadenza_offerta'] ?? '',
            'condizioni_pagamento'  => $off['condizioni_pagamento'] ?? '',
            'valuta'                => $off['valuta'] ?? 'EUR',
            'riferimento_fornitore' => $off['riferimento_fornitore'] ?? '',
            'note_fornitore'        => $off['note_fornitore'] ?? '',
            'note_interne'          => $off['note_interne'] ?? '',
            'allegato_path'         => $off['allegato_path'] ?? '',
            'contatto_id'           => (string)($off['contatto_id'] ?? ''),
            'contatto_nome'         => $off['contatto_nome'] ?? '',
            'contatto_telefono'     => $off['contatto_telefono'] ?? '',
            'contatto_email'        => $off['contatto_email'] ?? '',
            'fornitore_telefono'    => $off['fornitore_telefono'] ?? '',
            'fornitore_email'       => $off['fornitore_email'] ?? '',
            'listino_acquisto_id'   => (string)($off['listino_acquisto_id'] ?? ''),
            'metodo_pagamento_id'   => (string)($off['metodo_pagamento_id'] ?? ''),
            'resa_porto'            => $off['resa_porto'] ?? '',
            'data_consegna_default' => '',
        ];
    }

    private function readPostValues(): array
    {
        $fornitore_id = post('fornitore_id', '');
        $fornitore_nome = '';
        if ($fornitore_id !== '') {
            $st = $this->pdo->prepare("SELECT COALESCE(ragione_sociale, nome || ' ' || COALESCE(cognome, '')) AS nome FROM clienti_fornitori WHERE id = ?");
            $st->execute([(int)$fornitore_id]);
            $fornitore_nome = $st->fetchColumn() ?: '';
        }
        return [
            'rfq_id'                => post('rfq_id', ''),
            'fornitore_id'          => $fornitore_id,
            'fornitore_nome'        => $fornitore_nome,
            'stato'                 => post('stato', 'in_valutazione'),
            'data_offerta'          => post('data_offerta', date('Y-m-d')),
            'data_scadenza_offerta' => post('data_scadenza_offerta', ''),
            'condizioni_pagamento'  => post('condizioni_pagamento', ''),
            'valuta'                => post('valuta', 'EUR'),
            'riferimento_fornitore' => post('riferimento_fornitore', ''),
            'note_fornitore'        => post('note_fornitore', ''),
            'note_interne'          => post('note_interne', ''),
            'allegato_path'         => post('allegato_path', ''),
            'contatto_id'           => post('contatto_id', ''),
            'contatto_nome'         => post('contatto_nome', ''),
            'contatto_telefono'     => post('contatto_telefono', ''),
            'contatto_email'        => post('contatto_email', ''),
            'fornitore_telefono'    => post('fornitore_telefono', ''),
            'fornitore_email'       => post('fornitore_email', ''),
            'listino_acquisto_id'   => post('listino_acquisto_id', ''),
            'metodo_pagamento_id'   => post('metodo_pagamento_id', ''),
            'resa_porto'            => post('resa_porto', ''),
            'data_consegna_default' => post('data_consegna_default', ''),
        ];
    }

    private function readPostRighe(): array
    {
        $righe = [];
        $prodotti = $_POST['riga_prodotto_id'] ?? [];
        foreach ($prodotti as $i => $prod_id) {
            if ((int)$prod_id <= 0) continue;
            $r = ['prodotto_id' => (string)$prod_id];
            foreach (['rfq_riga_id', 'descrizione', 'sku_fornitore',
                      'quantita_offerta', 'unita_misura_id',
                      'prezzo_unitario', 'sconto_perc', 'iva_perc',
                      'data_consegna_prevista', 'note'] as $k) {
                $r[$k] = (string)($_POST["riga_{$k}"][$i] ?? '');
            }
            $r['temp_id'] = (string)($_POST['riga_temp_id'][$i] ?? '');
            $r['flag_sostituto'] = (int)($_POST['riga_flag_sostituto'][$i] ?? 0);
            $r['offerta_riga_padre_id'] = (string)($_POST['riga_offerta_riga_padre_id'][$i] ?? '');
            $righe[] = $r;
        }
        return $righe;
    }

    private function validate(array $values, array $righe): array
    {
        $errors = [];
        if (($values['fornitore_id'] ?? '') === '') {
            $errors['fornitore_id'] = 'Il fornitore è obbligatorio.';
        }
        if (empty($righe)) {
            $errors['righe'] = 'Inserisci almeno una riga.';
        }
        foreach ($righe as $i => $r) {
            if ((float)($r['quantita_offerta'] ?? 0) <= 0) {
                $errors["riga_{$i}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
            if ((float)($r['prezzo_unitario'] ?? 0) < 0) {
                $errors["riga_{$i}_prezzo"] = 'Il prezzo non può essere negativo.';
            }
        }
        return $errors;
    }

    private function handleUpload(string $inputName, array &$errors): ?string
    {
        $file = $_FILES[$inputName];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        // Verifica tipo e estensione
        $allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png'
        ];
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
        
        if (!in_array($file['type'], $allowedTypes, true) && !in_array($ext, $allowedExts, true)) {
            $errors[$inputName] = 'Formato file non supportato (ammessi solo PDF, DOC, DOCX, PNG, JPG).';
            return null;
        }

        // Directory di upload con sharding per anno/mese
        $subdir = upload_dated_subdir('quotes');
        $upload_dir = upload_ensure_dir($subdir);

        $new_name = uniqid('off_', true) . '.' . $ext;
        $dest = $upload_dir . DIRECTORY_SEPARATOR . $new_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return $subdir . '/' . $new_name;
        }

        $errors[$inputName] = 'Impossibile salvare il file caricato.';
        return null;
    }
}
