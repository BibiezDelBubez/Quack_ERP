<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases_quotes.php';
// Spostati in testa da un if() e da un case dentro uno switch — vedi Revisione
// Ciclo Acquisti §3bis A.2 (require_once condizionale, anti-pattern).
require_once INCLUDES_DIR . '/purchases_rfq.php';
require_once INCLUDES_DIR . '/purchases_orders.php';

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
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');
        $rfq_id = (int) get_param('rfq_id', '0');

        $pag          = qk_pagination_params(['data','numero','fornitore','stato','scadenza','totale'], 'data', 'desc');
        $total        = qk_offerte_count($this->pdo, $search, $stato, $dal, $al, $rfq_id);
        $offerte_list = qk_offerte_list($this->pdo, $search, $stato, $dal, $al, $rfq_id, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
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
                $detail = qk_rfq_detail($this->pdo, $rfq_id);
                if ($detail) {
                    $values['rfq_id']                  = (string)$rfq_id;
                    $values['rfq_numero']              = (string)($detail['rfq']['numero'] ?? '');
                    $values['rfq_riferimento_interno'] = (string)($detail['rfq']['riferimento_rfq_interno'] ?? '');
                    $values['fornitore_id']            = (string)($detail['rfq']['fornitore_id'] ?? '');
                    $values['fornitore_nome']          = (string)($detail['rfq']['fornitore_name'] ?? $detail['rfq']['fornitore_nome'] ?? '');
                    $values['consegna_via']            = (string)($detail['rfq']['consegna_via'] ?? '');
                    $values['consegna_civico']         = (string)($detail['rfq']['consegna_civico'] ?? '');
                    $values['consegna_cap']            = (string)($detail['rfq']['consegna_cap'] ?? '');
                    $values['consegna_citta']          = (string)($detail['rfq']['consegna_citta'] ?? '');
                    $values['consegna_provincia']      = (string)($detail['rfq']['consegna_provincia'] ?? '');
                    $values['consegna_nazione']        = (string)($detail['rfq']['consegna_nazione'] ?? 'IT');
                    $values['consegna_presso']         = (string)($detail['rfq']['consegna_presso'] ?? '');
                    $values['consegna_referente']      = (string)($detail['rfq']['consegna_referente'] ?? '');
                    $values['consegna_telefono']       = (string)($detail['rfq']['consegna_telefono'] ?? '');
                    $values['consegna_note']           = (string)($detail['rfq']['consegna_note'] ?? '');
                    $values['indirizzo_consegna_master_id'] = (string)($detail['rfq']['indirizzo_consegna_master_id'] ?? '');
                    $values['contatto_id']             = (string)($detail['rfq']['contatto_master_id'] ?? $detail['rfq']['contatto_id'] ?? '');
                    $values['contatto_master_id']      = (string)($detail['rfq']['contatto_master_id'] ?? '');
                    $values['contatto_nome']           = (string)($detail['rfq']['contatto_nome'] ?? '');
                    $values['contatto_telefono']       = (string)($detail['rfq']['contatto_telefono'] ?? '');
                    $values['contatto_email']          = (string)($detail['rfq']['contatto_email'] ?? '');
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
                
                // Enforce read-only lock for contact and address details if the quote status is not 'in_valutazione'
                $currentStatus = $detail['offerta']['stato'];
                if ($currentStatus !== 'in_valutazione') {
                    $originalValues = $this->mapDetailToValues($detail['offerta']);
                    foreach (['contatto_id', 'contatto_nome', 'contatto_telefono', 'contatto_email',
                              'fornitore_telefono', 'fornitore_email',
                              'indirizzo_consegna_id', 'consegna_presso', 'consegna_via', 'consegna_civico',
                              'consegna_cap', 'consegna_citta', 'consegna_provincia', 'consegna_nazione',
                              'consegna_referente', 'consegna_telefono', 'consegna_note'] as $fld) {
                        $values[$fld] = $originalValues[$fld];
                    }
                }
                
                $righe  = $this->readPostRighe();
                
                $oldAllegato = $detail['offerta']['allegato_path'] ?? '';
                
                if (post('rimuovi_allegato', '') === '1') {
                    $values['allegato_path'] = '';
                    if ($oldAllegato) {
                        $abs = upload_abs($oldAllegato);
                        if (is_file($abs)) {
                            if (!unlink($abs)) {
                                error_log("PurchasesQuotesController: Impossibile eliminare allegato: " . $abs);
                            }
                        }
                    }
                }
                
                if (isset($_FILES['allegato_preventivo']) && $_FILES['allegato_preventivo']['error'] === UPLOAD_ERR_OK) {
                    $file_path = $this->handleUpload('allegato_preventivo', $errors);
                    if ($file_path !== null) {
                        $values['allegato_path'] = $file_path;
                        if ($oldAllegato && $oldAllegato !== $file_path) {
                            $abs = upload_abs($oldAllegato);
                            if (is_file($abs)) {
                                if (!unlink($abs)) {
                                    error_log("PurchasesQuotesController: Impossibile eliminare vecchio allegato: " . $abs);
                                }
                            }
                        }
                    }
                }
                
                $errors = array_merge($errors, $this->validate($values, $righe));

                if (empty($errors)) {
                    try {
                        qk_offerta_save($this->pdo, $values, $righe, (int)current_user()['id'], $id);
                        $detail = qk_offerta_detail($this->pdo, $id);
                        $righe  = $detail['righe'];
                        $values = $this->mapDetailToValues($detail['offerta']);
                        flash_set('success', 'Offerta salvata.');
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
                    flash_set('success', 'Offerta accettata.');

                    // Se richiesto, genera subito l'ordine
                    if (post('genera_ordine', '') === '1') {
                        $ordine_id = qk_ordine_da_offerta($this->pdo, $id, (int)current_user()['id']);
                        flash_set('success', 'Offerta accettata e ordine generato.');
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
                    flash_set('success', 'Offerta rifiutata.');
                    redirect(url('purchases/quotes/edit') . '?id=' . $id);
                    break;

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
                    redirect(url('purchases/quotes/edit') . '?id=' . $id);
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
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
            'rfq_numero'             => '',
            'rfq_riferimento_interno'=> '',
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
            'consegna_via'            => '',
            'consegna_civico'         => '',
            'consegna_cap'            => '',
            'consegna_citta'          => '',
            'consegna_provincia'      => '',
            'consegna_nazione'        => 'IT',
            'consegna_presso'         => '',
            'consegna_referente'      => '',
            'consegna_telefono'       => '',
            'consegna_note'           => '',
        ];
    }

    private function mapDetailToValues(array $off): array
    {
        return [
            'rfq_id'                => (string)($off['rfq_id'] ?? ''),
            'rfq_numero'            => (string)($off['rfq_numero'] ?? ''),
            'rfq_riferimento_interno'=> (string)($off['rfq_riferimento_interno'] ?? ''),
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
            'contatto_id'           => (string)($off['contatto_master_id'] ?? $off['contatto_id'] ?? ''),
            'contatto_nome'         => $off['contatto_nome'] ?? '',
            'contatto_telefono'     => $off['contatto_telefono'] ?? '',
            'contatto_email'        => $off['contatto_email'] ?? '',
            'fornitore_telefono'    => $off['fornitore_telefono'] ?? '',
            'fornitore_email'       => $off['fornitore_email'] ?? '',
            'listino_acquisto_id'   => (string)($off['listino_acquisto_id'] ?? ''),
            'metodo_pagamento_id'   => (string)($off['metodo_pagamento_id'] ?? ''),
            'resa_porto'            => $off['resa_porto'] ?? '',
            'indirizzo_consegna_id' => (string)($off['indirizzo_consegna_id'] ?? ''),
            'indirizzo_consegna_master_id' => (string)($off['indirizzo_consegna_master_id'] ?? ''),
            'consegna_via'          => $off['consegna_via'] ?? '',
            'consegna_civico'       => $off['consegna_civico'] ?? '',
            'consegna_cap'          => $off['consegna_cap'] ?? '',
            'consegna_citta'        => $off['consegna_citta'] ?? '',
            'consegna_provincia'    => $off['consegna_provincia'] ?? '',
            'consegna_nazione'      => $off['consegna_nazione'] ?? 'IT',
            'consegna_presso'       => $off['consegna_presso'] ?? '',
            'consegna_referente'    => $off['consegna_referente'] ?? '',
            'consegna_telefono'     => $off['consegna_telefono'] ?? '',
            'consegna_note'         => $off['consegna_note'] ?? '',
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
            'indirizzo_consegna_id' => post('indirizzo_consegna_id', ''),
            'consegna_presso'       => post('consegna_presso', ''),
            'consegna_via'          => post('consegna_via', ''),
            'consegna_civico'       => post('consegna_civico', ''),
            'consegna_cap'          => post('consegna_cap', ''),
            'consegna_citta'        => post('consegna_citta', ''),
            'consegna_provincia'    => post('consegna_provincia', ''),
            'consegna_nazione'      => post('consegna_nazione', 'IT'),
            'consegna_referente'    => post('consegna_referente', ''),
            'consegna_telefono'     => post('consegna_telefono', ''),
            'consegna_note'         => post('consegna_note', ''),
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

        // Verifica tipo reale del file (MIME sniffato dal contenuto, non dichiarato dal client:
        // $_FILES['type'] è il Content-Type inviato dal browser ed è falsificabile). I DOCX sono
        // contenitori ZIP: alcune installazioni di fileinfo li riconoscono come
        // application/vnd.openxmlformats-..., altre solo come application/zip generico — entrambi
        // ammessi e mappati a .docx.
        $allowedMimeToExt = [
            'application/pdf'                                                          => 'pdf',
            'application/msword'                                                       => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
            'application/zip'                                                          => 'docx',
            'image/jpeg'                                                                => 'jpg',
            'image/png'                                                                 => 'png',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($realMime === false || !isset($allowedMimeToExt[$realMime])) {
            $errors[$inputName] = 'Formato file non supportato (ammessi solo PDF, DOC, DOCX, PNG, JPG).';
            return null;
        }

        // application/zip è ambiguo: potrebbe essere un vero DOCX, ma anche
        // un XLSX/PPTX/JAR/zip generico rilevati genericamente allo stesso
        // modo. Ispezioniamo il contenuto per confermare che sia davvero un
        // DOCX prima di salvarlo con quell'estensione.
        if ($realMime === 'application/zip' && !qk_is_docx_zip($file['tmp_name'])) {
            $errors[$inputName] = 'Formato file non supportato (ammessi solo PDF, DOC, DOCX, PNG, JPG).';
            return null;
        }

        $ext = $allowedMimeToExt[$realMime];

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
