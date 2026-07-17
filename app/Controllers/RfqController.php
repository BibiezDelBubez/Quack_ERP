<?php
declare(strict_types=1);

require_once INCLUDES_DIR . '/purchases.php';
require_once INCLUDES_DIR . '/tabelle_base.php';
require_once INCLUDES_DIR . '/purchases_rfq.php';
require_once INCLUDES_DIR . '/anagrafiche.php';
require_once APP_ROOT_DIR . '/app/Services/RfqDocumentService.php';

/**
 * RfqController — Richieste di Offerta (ciclo acquisti v2).
 *
 * Route   Action
 * ──────────────────────────────────────────────────────────────────────────
 * GET  /purchases/rfq              index()         Elenco RFQ
 * GET  /purchases/rfq/create       create()        Form nuova RFQ
 * POST /purchases/rfq/create       create()        Salva nuova RFQ
 * GET  /purchases/rfq/edit?id=N    edit()          Form modifica RFQ
 * POST /purchases/rfq/edit?id=N    edit()          Salva modifiche
 * POST /purchases/rfq/delete       delete()        Elimina (solo bozza)
 * POST /purchases/rfq/action       action()        Transizioni stato
 * GET  /purchases/rfq/pdf?id=N     pdf()           Stampa PDF
 * GET  /purchases/rfq/api/*        api*()          Endpoint AJAX
 */
class RfqController extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // ELENCO
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $search = get_param('q', '');
        $stato  = get_param('stato', '');
        $dal    = get_param('dal', '');
        $al     = get_param('al', '');

        $pag      = qk_pagination_params(['data','numero','rif','fornitore','stato','scadenza','totale'], 'data', 'desc');
        $total    = qk_rfq_count($this->pdo, $search, $stato, $dal, $al);
        $rfq_list = qk_rfq_list($this->pdo, $search, $stato, $dal, $al, $pag['sort'], $pag['dir'], $pag['limit'], $pag['offset']);
        $page_title = 'Richieste di Offerta';
        $breadcrumbs = [
            ['label' => 'Acquisti'],
            ['label' => 'Richieste di Offerta']
        ];
        $page_actions = '<a href="' . url('purchases/rfq/create') . '" class="btn-qk btn-qk-primary"><i class="fa-solid fa-plus"></i> Nuova RFQ</a>';

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_list.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREAZIONE
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): void
    {
        $aziende = qk_aziende_list($this->pdo);
        $defaultAziendaId = isset($_SESSION['azienda_attiva_id']) ? (string)$_SESSION['azienda_attiva_id'] : '';
        if ($defaultAziendaId === '') {
            foreach ($aziende as $az) {
                if ($az['predefinita'] == 1) {
                    $defaultAziendaId = (string)$az['id'];
                    break;
                }
            }
        }
        if ($defaultAziendaId === '' && !empty($aziende)) {
            $defaultAziendaId = (string)$aziende[0]['id'];
        }

        $incoterms = qk_get_incoterms_attivi($this->pdo);
        $metodi_pagamento = qk_get_metodi_pagamento_attivi($this->pdo);

        $errors = [];
        $values = $this->defaultValues();
        $values['azienda_interna_id'] = $defaultAziendaId;
        $righe  = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();
                
                // L'indirizzo di consegna viene inviato direttamente in POST per supportare gli override


                $righe  = $this->readPostRighe();
                $errors = $this->validate($values, $righe);

                if (empty($errors)) {
                    try {
                        $id = qk_rfq_save($this->pdo, $values, $righe, (int)current_user()['id']);
                        redirect(url('purchases/rfq/edit') . '?id=' . $id . '&saved=1');
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $anteprima_numero = qk_peek_numero_acquisto($this->pdo, 'rfq');
        $page_title       = 'Nuova RFQ';
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
            ['label' => 'Nuova RFQ']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_form.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODIFICA
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $aziende = qk_aziende_list($this->pdo);
        $incoterms = qk_get_incoterms_attivi($this->pdo);
        $metodi_pagamento = qk_get_metodi_pagamento_attivi($this->pdo);

        $errors = [];
        $values = $this->mapDetailToValues($detail['rfq']);
        $righe  = $detail['righe'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Sessione scaduta. Ricarica la pagina e riprova.';
            } else {
                $values = $this->readPostValues();

                // Nota: il blocco modifica per stato != 'bozza' (inviata, revisionata,
                // chiusa) NON viene più controllato qui — era duplicato rispetto a
                // qk_rfq_save() e quel controllo veniva silenziosamente vanificato
                // dalla riga successiva ($errors = $this->validate(...) sovrascriveva
                // l'errore impostato sopra). Il blocco è ora centralizzato in un unico
                // punto in qk_rfq_save() (includes/purchases_rfq.php), che lancia una
                // RuntimeException catturata qui sotto come qualsiasi altro errore.

                $righe  = $this->readPostRighe();
                $errors = $this->validate($values, $righe);

                if (empty($errors)) {
                    try {
                        qk_rfq_save($this->pdo, $values, $righe, (int)current_user()['id'], $id);
                        // Ricarica dopo salvataggio
                        $detail = qk_rfq_detail($this->pdo, $id);
                        $righe  = $detail['righe'];
                        flash_set('success', 'RFQ salvata.');
                    } catch (\Throwable $e) {
                        $errors['_global'] = $e->getMessage();
                    }
                }
            }
        }

        $saved      = get_param('saved', '') === '1';
        $page_title = 'RFQ ' . $detail['rfq']['numero'];
        $breadcrumbs = [
            ['label' => 'Acquisti', 'url' => url('purchases/rfq')],
            ['label' => 'Dettaglio RFQ']
        ];

        $this->page(APP_ROOT_DIR . '/app/views/purchases/rfq/_form.php', get_defined_vars());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ELIMINAZIONE
    // ─────────────────────────────────────────────────────────────────────────

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/rfq'));
        }
        $id = (int) post('id', '0');
        try {
            qk_rfq_delete($this->pdo, $id);
            flash_set('success', 'RFQ eliminata.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        redirect(url('purchases/rfq'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSIZIONI DI STATO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gestisce le azioni di cambio stato via POST:
     *   ?azione=invia          bozza → inviata
     *   ?azione=chiudi         inviata → chiusa
     *   ?azione=nuova_versione inviata → nuova bozza + corrente revisionata
     */
    public function action(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
            redirect(url('purchases/rfq'));
        }

        $id     = (int) post('id', '0');
        $azione = post('azione', '');

        try {
            switch ($azione) {
                case 'invia':
                    qk_rfq_invia($this->pdo, $id);
                    flash_set('success', 'RFQ inviata.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
                    break;

                case 'chiudi':
                    qk_rfq_chiudi($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'RFQ chiusa.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
                    break;

                case 'nuova_versione':
                    $nuovoId = qk_rfq_nuova_versione($this->pdo, $id, (int)current_user()['id']);
                    flash_set('success', 'Nuova versione creata.');
                    redirect(url('purchases/rfq/edit') . '?id=' . $nuovoId);
                    break;

                default:
                    flash_set('danger', "Azione '$azione' non riconosciuta.");
                    redirect(url('purchases/rfq/edit') . '?id=' . $id);
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            redirect(url('purchases/rfq/edit') . '?id=' . $id);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF e DOCX
    // ─────────────────────────────────────────────────────────────────────────

    public function pdf(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $rfq = $detail['rfq'];
        $righe = $detail['righe'];

        $azienda = qk_resolve_azienda_emittente($this->pdo, !empty($rfq['azienda_interna_id']) ? (int)$rfq['azienda_interna_id'] : null);
        $indirizzoFornitore = qk_rfq_get_fornitore_indirizzo($this->pdo, (int)($rfq['fornitore_id'] ?? 0), '<br>');

        $service = new \App\Services\RfqDocumentService();
        $pdfBytes = $service->generatePdf($rfq, $righe, $azienda, $indirizzoFornitore);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="RFQ_' . str_replace('/', '_', $rfq['numero']) . '.pdf"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
        exit;
    }

    public function docx(): void
    {
        $id = (int) get_param('id', '0');
        if ($id <= 0) {
            redirect(url('purchases/rfq'));
        }

        $detail = qk_rfq_detail($this->pdo, $id);
        if (!$detail) {
            redirect(url('purchases/rfq'));
        }

        $rfq = $detail['rfq'];
        $righe = $detail['righe'];

        $azienda = qk_resolve_azienda_emittente($this->pdo, !empty($rfq['azienda_interna_id']) ? (int)$rfq['azienda_interna_id'] : null);
        $indirizzoFornitore = qk_rfq_get_fornitore_indirizzo($this->pdo, (int)($rfq['fornitore_id'] ?? 0), ', ');

        $service = new \App\Services\RfqDocumentService();
        $tempPath = $service->generateDocx($rfq, $righe, $azienda, $indirizzoFornitore);

        $filename = "RFQ_" . str_replace('/', '_', $rfq['numero']) . ".docx";
        
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempPath));
        readfile($tempPath);
        @unlink($tempPath);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API AJAX
    // ─────────────────────────────────────────────────────────────────────────

    /** Ricerca fornitori per autocomplete. */
    public function apiFornitori(): void
    {
        ob_start();
        $q    = get_param('q', '');
        
        $whereSearch = "(ragione_sociale LIKE ? OR nome LIKE ? OR codice LIKE ?)";
        if (mb_strlen($q) === 1) {
            // Cerca solo fornitori che INIZIANO con la lettera cercata, o che hanno codice corrispondente
            $like = $q . '%';
            $codeLike = $q;
        } else {
            // Ricerca parziale (contiene) per query più lunghe
            $like = '%' . $q . '%';
            $codeLike = '%' . $q . '%';
        }

        $st   = $this->pdo->prepare(
            "SELECT id, codice,
                    COALESCE(ragione_sociale, nome || ' ' || COALESCE(cognome,'')) AS nome,
                    partita_iva
               FROM clienti_fornitori
              WHERE (tipo = 'fornitore' OR tipo = 'entrambi')
                AND parent_id IS NULL
                AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
                AND attivo = 1
                AND (ragione_sociale LIKE ? OR nome LIKE ? OR codice LIKE ?)
              ORDER BY ragione_sociale, nome LIMIT 30"
        );
        $st->execute([$like, $like, $codeLike]);
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'fornitori' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /** Ricerca prodotti con prezzo fornitore se disponibile. */
    public function apiProdotti(): void
    {
        ob_start();
        require_once INCLUDES_DIR . '/purchases_quotes.php';
        $q           = get_param('q', '');
        $fornitore_id = (int) get_param('fornitore_id', '0');

        // Reusa funzione da acquisti_offerte (stessa logica)
        $prodotti = qk_acquisti_prodotti_search($this->pdo, $q, $fornitore_id);
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'prodotti' => $prodotti]);
    }


    /** Crea un nuovo fornitore al volo. */
    public function apiCreaFornitore(): void
    {
        ob_start();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            return;
        }
        if (!csrf_verify()) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido.']);
            return;
        }
        $ragione = trim(post('ragione_sociale', ''));
        $piva    = trim(post('partita_iva', ''));
        if ($ragione === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Ragione sociale obbligatoria.']);
            return;
        }
        try {
            // B.3 (REVISIONE Ciclo Acquisti, sezione B): il codice fornitore era generato con un
            // suffisso rand(100,999) — solo 900 valori possibili su una colonna NOT NULL UNIQUE,
            // senza alcun controllo di unicità. Sostituito con qk_next_codice(), già usata per lo
            // stesso identico scopo (codice sequenziale PREFISSO-00001) da
            // qk_persist_soggetto_clienti_fornitori() e dalla creazione contatti/aziende interne —
            // riuso dell'helper esistente invece di reinventare la generazione codice (DRY).
            $codice = qk_next_codice($this->pdo, 'FOR');
            $this->pdo->prepare(
                "INSERT INTO clienti_fornitori
                    (codice, tipo, tipo_soggetto, ragione_sociale, partita_iva, attivo, created_at)
                 VALUES (?,?,'azienda',?,?,1,CURRENT_TIMESTAMP)"
            )->execute([$codice, 'fornitore', $ragione, $piva ?: null]);
            $id = (int) $this->pdo->lastInsertId();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $ragione, 'codice' => $codice]);
        } catch (\Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Crea un nuovo prodotto al volo. */
    public function apiCreaProdotto(): void
    {
        ob_start();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
            return;
        }
        if (!csrf_verify()) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido.']);
            return;
        }
        $nome = trim(post('nome', ''));
        if ($nome === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Nome prodotto obbligatorio.']);
            return;
        }
        try {
            // B.1 (REVISIONE Ciclo Acquisti §3bis): l'INSERT manuale precedente ometteva le colonne
            // NOT NULL "tipo" e "unita_misura_stock_id" — falliva sempre. Riusa qk_crea_prodotto_rapido()
            // (includes/prodotti.php), già corretta e già in uso da SalesOrdersController::apiCreaProdotto()
            // per lo stesso identico bug (gemello). Contratto JSON di risposta INVARIATO (ok/id/nome/
            // sku_interno) perché il chiamante (app/views/purchases/quotes/_form.php) legge esattamente
            // questi campi — diverso apposta dallo shape di SalesOrdersController, non un'inconsistenza.
            $id = qk_crea_prodotto_rapido($this->pdo, [
                'nome'          => $nome,
                'sku_interno'   => post('sku_interno', ''),
                'unita_misura'  => post('unita_misura', 'pz'),
            ]);
            $stSku = $this->pdo->prepare('SELECT sku_interno FROM prodotti WHERE id = ?');
            $stSku->execute([$id]);
            $sku = $stSku->fetchColumn();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'id' => $id, 'nome' => $nome, 'sku_interno' => $sku]);
        } catch (\Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** API endpoint for loading addresses for a selected internal company */
    public function apiAziendaIndirizzi(): void
    {
        ob_start();
        $azienda_id = (int) get_param('azienda_id', '0');
        $indirizzo_id = (int) get_param('indirizzo_id', '0');
        
        $addresses = [];
        if ($indirizzo_id > 0) {
            $stmtAddr = $this->pdo->prepare(
                "SELECT id, tipo, nome_sede, predefinito, via, civico, cap, citta, provincia, nazione,
                        referente_consegna, telefono_consegna, note_consegna, presso
                   FROM indirizzi
                  WHERE id = ? AND attivo = 1"
            );
            $stmtAddr->execute([$indirizzo_id]);
            $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'indirizzo' => $addr ?: null]);
            exit;
        } elseif ($azienda_id > 0) {
            $stmt = $this->pdo->prepare("SELECT soggetto_id FROM aziende_interne WHERE id = ?");
            $stmt->execute([$azienda_id]);
            $soggetto_id = (int) $stmt->fetchColumn();
            
            if ($soggetto_id > 0) {
                $stmtAddr = $this->pdo->prepare(
                    "SELECT id, tipo, nome_sede, predefinito, via, civico, cap, citta, provincia, nazione,
                            referente_consegna, telefono_consegna, note_consegna, presso
                       FROM indirizzi
                      WHERE soggetto_id = ? AND attivo = 1
                      ORDER BY CASE tipo WHEN 'spedizione' THEN 1 WHEN 'magazzino' THEN 2 ELSE 3 END,
                               predefinito DESC, id ASC"
                );
                $stmtAddr->execute([$soggetto_id]);
                $addresses = $stmtAddr->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'indirizzi' => $addresses]);
        exit;
    }

    /** API endpoint for loading details of a selected supplier (contacts, pricelists, general info) */
    public function apiFornitoreDettagli(): void
    {
        ob_start();
        $fornitore_id = (int) get_param('fornitore_id', '0');
        
        $fornitore = null;
        $contatti = [];
        $listini = [];
        
        if ($fornitore_id > 0) {
            // General info and default details
            $stmtForn = $this->pdo->prepare(
                "SELECT cf.id, cf.email, cf.telefono, cf.cellulare, df.condizioni_pagamento
                   FROM clienti_fornitori cf
              LEFT JOIN dettagli_fornitori df ON df.soggetto_id = cf.id
                  WHERE cf.id = ? AND cf.attivo = 1"
            );
            $stmtForn->execute([$fornitore_id]);
            $fornitore = $stmtForn->fetch(PDO::FETCH_ASSOC);
            
            if ($fornitore) {
                // Fetch contacts (sub-contacts)
                $stmtContatti = $this->pdo->prepare(
                    "SELECT id, COALESCE(nome || ' ' || COALESCE(cognome, ''), ragione_sociale) AS nome_completo,
                            email, telefono, cellulare
                       FROM clienti_fornitori
                      WHERE parent_id = ? AND attivo = 1
                      ORDER BY nome, cognome"
                );
                $stmtContatti->execute([$fornitore_id]);
                $contatti = $stmtContatti->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch active pricelists
                $stmtListini = $this->pdo->prepare(
                    "SELECT id, nome_listino
                       FROM listini_acquisto
                      WHERE fornitore_id = ?
                        AND (valido_dal IS NULL OR valido_dal <= DATE('now'))
                        AND (valido_al IS NULL OR valido_al >= DATE('now'))
                      ORDER BY nome_listino"
                );
                $stmtListini->execute([$fornitore_id]);
                $listini = $stmtListini->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'fornitore' => $fornitore,
            'contatti' => $contatti,
            'listini' => $listini
        ]);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER PRIVATI
    // ─────────────────────────────────────────────────────────────────────────

    private function defaultValues(): array
    {
        return [
            'fornitore_id'            => '',
            'indirizzo_consegna_id'   => '',
            'stato'                   => 'bozza',
            'data_emissione'          => date('Y-m-d'),
            'data_scadenza'           => '',
            'condizioni_pagamento'    => '',
            'valuta'                  => 'EUR',
            'note_fornitore'          => '',
            'note_interne'            => '',
            'riferimento_rfq_interno' => '',
            'azienda_interna_id'      => '',
            'incoterm_codice'         => '',
            'incoterm_luogo'          => '',
            'contatto_id'             => '',
            'contatto_nome'           => '',
            'contatto_telefono'       => '',
            'contatto_email'          => '',
            'fornitore_telefono'      => '',
            'fornitore_email'         => '',
            'listino_acquisto_id'     => '',
            'metodo_pagamento_id'     => '',
            'resa_porto'              => '',
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

    private function mapDetailToValues(array $rfq): array
    {
        return [
            'fornitore_id'            => (string)($rfq['fornitore_id'] ?? ''),
            'indirizzo_consegna_id'   => (string)($rfq['indirizzo_consegna_master_id'] ?? $rfq['indirizzo_consegna_id'] ?? ''),
            'stato'                   => $rfq['stato'],
            'esito_chiusura'          => $rfq['esito_chiusura'] ?? '',
            'data_emissione'          => $rfq['data_emissione'] ?? date('Y-m-d'),
            'data_scadenza'           => $rfq['data_scadenza'] ?? '',
            'condizioni_pagamento'    => $rfq['condizioni_pagamento'] ?? '',
            'valuta'                  => $rfq['valuta'] ?? 'EUR',
            'note_fornitore'          => $rfq['note_fornitore'] ?? '',
            'note_interne'            => $rfq['note_interne'] ?? '',
            'riferimento_rfq_interno' => $rfq['riferimento_rfq_interno'] ?? '',
            'azienda_interna_id'      => (string)($rfq['azienda_interna_id'] ?? ''),
            'incoterm_codice'         => $rfq['incoterm_codice'] ?? '',
            'incoterm_luogo'          => $rfq['incoterm_luogo'] ?? '',
            'contatto_id'             => (string)($rfq['contatto_master_id'] ?? $rfq['contatto_id'] ?? ''),
            'contatto_nome'           => $rfq['contatto_nome'] ?? '',
            'contatto_telefono'       => $rfq['contatto_telefono'] ?? '',
            'contatto_email'          => $rfq['contatto_email'] ?? '',
            'fornitore_telefono'      => $rfq['fornitore_telefono'] ?? '',
            'fornitore_email'         => $rfq['fornitore_email'] ?? '',
            'listino_acquisto_id'     => (string)($rfq['listino_acquisto_id'] ?? ''),
            'metodo_pagamento_id'     => (string)($rfq['metodo_pagamento_id'] ?? ''),
            'resa_porto'              => $rfq['resa_porto'] ?? '',
            'consegna_via'            => $rfq['consegna_via'] ?? '',
            'consegna_civico'         => $rfq['consegna_civico'] ?? '',
            'consegna_cap'            => $rfq['consegna_cap'] ?? '',
            'consegna_citta'          => $rfq['consegna_citta'] ?? '',
            'consegna_provincia'      => $rfq['consegna_provincia'] ?? '',
            'consegna_nazione'        => $rfq['consegna_nazione'] ?? 'IT',
            'consegna_presso'         => $rfq['consegna_presso'] ?? '',
            'consegna_referente'      => $rfq['consegna_referente'] ?? '',
            'consegna_telefono'       => $rfq['consegna_telefono'] ?? '',
            'consegna_note'           => $rfq['consegna_note'] ?? '',
            'uom_mode'                => $rfq['uom_mode'] ?? 'commercial',
        ];
    }

    private function readPostValues(): array
    {
        return [
            'fornitore_id'            => post('fornitore_id', ''),
            'indirizzo_consegna_id'   => post('indirizzo_consegna_id', ''),
            'stato'                   => post('stato', 'bozza'),
            'data_emissione'          => post('data_emissione', date('Y-m-d')),
            'data_scadenza'           => post('data_scadenza', ''),
            'condizioni_pagamento'    => post('condizioni_pagamento', ''),
            'valuta'                  => post('valuta', 'EUR'),
            'note_fornitore'          => post('note_fornitore', ''),
            'note_interne'            => post('note_interne', ''),
            'riferimento_rfq_interno' => post('riferimento_rfq_interno', ''),
            'azienda_interna_id'      => post('azienda_interna_id', ''),
            'incoterm_codice'         => post('incoterm_codice', ''),
            'incoterm_luogo'          => post('incoterm_luogo', ''),
            'contatto_id'             => post('contatto_id', ''),
            'contatto_nome'           => post('contatto_nome', ''),
            'contatto_telefono'       => post('contatto_telefono', ''),
            'contatto_email'          => post('contatto_email', ''),
            'fornitore_telefono'      => post('fornitore_telefono', ''),
            'fornitore_email'         => post('fornitore_email', ''),
            'listino_acquisto_id'     => post('listino_acquisto_id', ''),
            'metodo_pagamento_id'     => post('metodo_pagamento_id', ''),
            'resa_porto'              => post('resa_porto', ''),
            'consegna_via'            => post('consegna_via', ''),
            'consegna_civico'         => post('consegna_civico', ''),
            'consegna_cap'            => post('consegna_cap', ''),
            'consegna_citta'          => post('consegna_citta', ''),
            'consegna_provincia'      => post('consegna_provincia', ''),
            'consegna_nazione'        => post('consegna_nazione', 'IT'),
            'consegna_presso'         => post('consegna_presso', ''),
            'consegna_referente'      => post('consegna_referente', ''),
            'consegna_telefono'       => post('consegna_telefono', ''),
            'consegna_note'           => post('consegna_note', ''),
            'uom_mode'                => post('uom_mode', 'commercial'),
        ];
    }

    private function readPostRighe(): array
    {
        $righe      = [];
        $prodotti   = $_POST['riga_prodotto_id']   ?? [];
        $quantita   = $_POST['riga_quantita']        ?? [];
        $descrizioni= $_POST['riga_descrizione']     ?? [];
        $sku        = $_POST['riga_sku_fornitore']   ?? [];
        $uom        = $_POST['riga_unita_misura_id'] ?? [];
        $prezzi     = $_POST['riga_prezzo_indicativo'] ?? [];
        $note       = $_POST['riga_note']             ?? [];
        $ivas       = $_POST['riga_iva_perc']         ?? [];
        
        $consegnaRichiesta = $_POST['riga_data_consegna_richiesta'] ?? [];
        $eRifiutato        = $_POST['riga_e_rifiutato'] ?? [];

        foreach ($prodotti as $i => $prod_id) {
            if ((int)$prod_id <= 0) continue;
            $righe[] = [
                'prodotto_id'       => (string)$prod_id,
                'quantita_richiesta'=> (string)($quantita[$i] ?? '1'),
                'descrizione'       => (string)($descrizioni[$i] ?? ''),
                'sku_fornitore'     => (string)($sku[$i] ?? ''),
                'unita_misura_id'   => (string)($uom[$i] ?? ''),
                'prezzo_indicativo' => (string)($prezzi[$i] ?? ''),
                'note'              => (string)($note[$i] ?? ''),
                'iva_perc'          => (string)($ivas[$i] ?? '22'),
                'data_consegna_richiesta' => !empty($consegnaRichiesta[$i]) ? $consegnaRichiesta[$i] : null,
                'e_rifiutato'             => !empty($eRifiutato[$i]) ? (int)$eRifiutato[$i] : 0,
            ];
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
            if ((float)($r['quantita_richiesta'] ?? 0) <= 0) {
                $errors["riga_{$i}_quantita"] = 'La quantità deve essere maggiore di zero.';
            }
        }
        return $errors;
    }
}

// ─── Funzione helper per la ricerca prodotti usata anche da RfqController ────

function qk_acquisti_prodotti_search(PDO $pdo, string $q, int $fornitore_id = 0, int $limit = 20): array
{
    ob_start();
    $like   = '%' . $q . '%';
    $params = [$like, $like, $like];

    $joinPrezzo   = '';
    $selectPrezzo = "NULL AS prezzo_unitario, NULL AS sku_fornitore, NULL AS uom_comm, NULL AS uom_comm_id";

    if ($fornitore_id > 0) {
        $joinPrezzo   = "LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.fornitore_id = $fornitore_id";
        // Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md): da
        // v_prezzo_acquisto_base_fornitore invece di LIMIT 1 senza ORDER BY
        // (che sceglieva una riga arbitraria tra più candidati, incluse
        // eventuali righe di listini disattivati).
        $selectPrezzo = "pf.sku_fornitore,
            NULL AS uom_comm,
            NULL AS uom_comm_id,
            (SELECT vf.prezzo FROM v_prezzo_acquisto_base_fornitore vf
              WHERE vf.prodotto_fornitore_id = pf.id) AS prezzo_unitario,
            (SELECT vf.unita_misura_id FROM v_prezzo_acquisto_base_fornitore vf
              WHERE vf.prodotto_fornitore_id = pf.id) AS prezzo_unitario_uom_id";
    }

    // Fornitore abituale del prodotto (REVISIONE Ciclo Acquisti §1.9, 17/07/2026):
    // il fornitore con ordine_preferenza_fornitore più basso tra quelli ATTIVI —
    // stesso identico criterio "ordina e filtra sul primo attivo" già usato in
    // qk_rfq_detail() (purchases_rfq.php riga 128) e deciso per il punto sul
    // fallback fornitore disattivato — non un filtro fisso "= 1". Indipendente
    // da $fornitore_id (quello scelto in testata RFQ): il confronto tra i due
    // avviene lato frontend (rfq-form.js), qui si restituisce solo il dato.
    $joinAbituale = "
      LEFT JOIN prodotti_fornitori pf_ab ON pf_ab.id = (
          SELECT pf_ab2.id FROM prodotti_fornitori pf_ab2
           JOIN clienti_fornitori cf_ab2 ON cf_ab2.id = pf_ab2.fornitore_id AND cf_ab2.attivo = 1
          WHERE pf_ab2.prodotto_id = p.id AND pf_ab2.attivo = 1
          ORDER BY pf_ab2.ordine_preferenza_fornitore ASC, pf_ab2.id DESC
          LIMIT 1
      )
      LEFT JOIN clienti_fornitori cf_ab ON cf_ab.id = pf_ab.fornitore_id";

    $st = $pdo->prepare(
        "SELECT p.id, p.sku_interno, p.nome, um.codice AS uom, p.iva_acquisto_default AS iva_perc,
                p.unita_misura_stock_id AS uom_id,
                um_comm_prod.codice AS uom_comm_prod,
                p.unita_contenuto_id AS uom_comm_prod_id,
                p.quantita_contenuto,
                $selectPrezzo,
                cf_ab.id AS fornitore_abituale_id,
                cf_ab.ragione_sociale AS fornitore_abituale_ragione_sociale,
                cf_ab.nome AS fornitore_abituale_nome_persona,
                cf_ab.cognome AS fornitore_abituale_cognome_persona
           FROM prodotti p
      LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
      LEFT JOIN unita_misura um_comm_prod ON um_comm_prod.id = p.unita_contenuto_id
           $joinPrezzo
           $joinAbituale
          WHERE p.attivo = 1
            AND (p.nome LIKE ? OR p.sku_interno LIKE ? OR p.ean LIKE ?)
          ORDER BY p.nome LIMIT ?"
    );
    $params[] = $limit;
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    ob_end_clean();

    // Calcola il nome visualizzato (ragione sociale prioritaria, stessa funzione
    // condivisa già usata per lo stesso bisogno altrove in questo controller —
    // vedi REVISIONE Ciclo Acquisti §3bis A.5) e rimuove i campi grezzi intermedi
    // dal JSON di risposta.
    foreach ($rows as &$row) {
        $row['fornitore_abituale_nome'] = $row['fornitore_abituale_id'] !== null
            ? qk_nome_completo_soggetto(
                $row['fornitore_abituale_ragione_sociale'],
                $row['fornitore_abituale_nome_persona'],
                $row['fornitore_abituale_cognome_persona']
              )
            : null;
        unset($row['fornitore_abituale_ragione_sociale'], $row['fornitore_abituale_nome_persona'], $row['fornitore_abituale_cognome_persona']);
    }
    unset($row);

    return $rows;
}
