<?php

declare(strict_types=1);

// D3.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 3): serve qk_uom_converti_riga() per
// convertire la quantità richiesta in Stock UoM prima di interrogare il motore di prezzo.
require_once __DIR__ . '/../../includes/prodotti.php';
require_once __DIR__ . '/../../includes/pricing.php';

/**
 * SalesPricelistsController — Gestione Listini Vendita.
 *
 * Tabelle coinvolte:
 *   listini            — testata listino (nome, valuta, attivo, valido_dal/al)
 *   listini_prezzi     — righe (prodotto_id, quantita_minima, prezzo_unitario, sconto_perc)
 *   dettagli_clienti   — listino_id default per cliente (read-only qui)
 */
final class SalesPricelistsController extends BaseController
{
    /* ── Lista ─────────────────────────────────────────────────────────────── */

    public function index(): void
    {
        $search = get_param('q');
        $filtro_attivo = get_param('attivo'); // '' | '1' | '0'

        $sql = "SELECT l.*,
                       (SELECT COUNT(*) FROM listini_prezzi lp WHERE lp.listino_id = l.id) AS nr_articoli
                FROM listini l
                WHERE 1=1";

        $params = [];
        if ($search) {
            $sql .= " AND (l.nome LIKE :q OR l.descrizione LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }
        if ($filtro_attivo !== '' && $filtro_attivo !== null) {
            $sql .= " AND l.attivo = :attivo";
            $params[':attivo'] = (int)$filtro_attivo;
        }

        $sortMap = ['nome' => 'l.nome', 'valuta' => 'l.valuta', 'dal' => 'l.valido_dal', 'al' => 'l.valido_al', 'articoli' => 'nr_articoli'];
        $pag = qk_pagination_params(array_keys($sortMap), 'nome', 'asc');
        $orderCol = $sortMap[$pag['sort']] ?? 'l.nome';
        $sql .= " ORDER BY " . $orderCol . " " . ($pag['dir'] === 'asc' ? 'ASC' : 'DESC');

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $pricelists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcola lo stato effettivo di ciascun listino nel controller
        $oggi = date('Y-m-d');
        foreach ($pricelists as &$ls) {
            $isAttivo = (bool)$ls['attivo'];
            $inRange  = (!$ls['valido_dal'] || $ls['valido_dal'] <= $oggi)
                     && (!$ls['valido_al']  || $ls['valido_al']  >= $oggi);
            if ($isAttivo && $inRange) {
                $ls['stato_effettivo'] = 'active';
            } elseif (!$isAttivo) {
                $ls['stato_effettivo'] = 'disattivato';
            } else {
                $ls['stato_effettivo'] = 'expired';
            }
        }
        unset($ls);

        $total = count($pricelists);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_list.php', [
            'page_title'    => 'Listini vendita',
            'page_subtitle' => 'Gestione dei listini prezzi di vendita',
            'breadcrumbs'   => [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Listini vendita']],
            'pricelists'    => $pricelists,
            'total'         => $total,
            'pag'           => $pag,
            'search'        => $search ?? '',
            'filtro_attivo' => $filtro_attivo ?? '',
        ]);
    }

    /* ── Crea ───────────────────────────────────────────────────────────────── */

    public function create(): void
    {
        $errors = [];
        $values = [
            'nome'       => '',
            'descrizione'=> '',
            'valuta'     => 'EUR',
            'attivo'     => 1,
            'valido_dal' => date('Y-m-d'),
            'valido_al'  => '',
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Valuta deve essere un codice ISO di 3 lettere (es. EUR, USD).';
                }

                if (empty($errors)) {
                    try {
                        $stmt = $this->pdo->prepare(
                            "INSERT INTO listini (nome, descrizione, valuta, attivo, valido_dal, valido_al)
                             VALUES (:nome, :descrizione, :valuta, :attivo, :valido_dal, :valido_al)"
                        );
                        $stmt->execute([
                            ':nome'        => $values['nome'],
                            ':descrizione' => $values['descrizione'] ?: null,
                            ':valuta'      => $values['valuta'],
                            ':attivo'      => $values['attivo'],
                            ':valido_dal'  => $values['valido_dal'],
                            ':valido_al'   => $values['valido_al'],
                        ]);
                        $newId = (int)$this->pdo->lastInsertId();
                        redirect_with('sales/pricelists/edit?id=' . $newId, 'success', 'Listino creato. Ora puoi aggiungere i prezzi prodotto.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore nel salvataggio: ' . $e->getMessage();
                    }
                }
            }
        }

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Nuovo listino vendita',
            'page_subtitle' => 'Crea un nuovo listino prezzi di vendita',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => 'Nuovo'],
            ],
            'errors'  => $errors,
            'values'  => $values,
            'isEdit'  => false,
            'items'   => [],
            'clienti_assegnati' => [],
        ]);
    }

    /* ── Modifica ───────────────────────────────────────────────────────────── */

    public function edit(): void
    {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'Nessun listino specificato.');
        }

        $stmt = $this->pdo->prepare("SELECT * FROM listini WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pricelist = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pricelist) {
            redirect_with('sales/pricelists', 'danger', 'Listino non trovato.');
        }

        $errors = [];
        $values = [
            'nome'        => $pricelist['nome'],
            'descrizione' => $pricelist['descrizione'] ?? '',
            'valuta'      => $pricelist['valuta'],
            'attivo'      => (int)$pricelist['attivo'],
            'valido_dal'  => $pricelist['valido_dal'] ?: '',
            'valido_al'   => $pricelist['valido_al'] ?: '',
            'is_default'  => (int)($pricelist['is_default'] ?? 0),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify()) {
                $errors['_global'] = 'Token di sicurezza non valido.';
            } else {
                $values = [
                    'nome'        => trim(post('nome') ?? ''),
                    'descrizione' => trim(post('descrizione') ?? ''),
                    'valuta'      => strtoupper(trim(post('valuta') ?? 'EUR')),
                    'attivo'      => post('attivo') === '1' ? 1 : 0,
                    'valido_dal'  => post('valido_dal') ?: null,
                    'valido_al'   => post('valido_al') ?: null,
                    'is_default'  => (int)($pricelist['is_default'] ?? 0),
                ];

                if (empty($values['nome'])) {
                    $errors['nome'] = 'Nome listino obbligatorio.';
                }
                if (strlen($values['valuta']) !== 3) {
                    $errors['valuta'] = 'Codice valuta non valido.';
                }

                if (empty($errors)) {
                    try {
                        if ((int)($pricelist['is_default'] ?? 0) === 1) {
                            $values['nome'] = 'Listino Base Pubblico (Globale)';
                        }
                        $this->pdo->prepare(
                            "UPDATE listini SET
                                nome        = :nome,
                                descrizione = :descrizione,
                                valuta      = :valuta,
                                attivo      = :attivo,
                                valido_dal  = :valido_dal,
                                valido_al   = :valido_al
                             WHERE id = :id"
                        )->execute([
                            ':nome'        => $values['nome'],
                            ':descrizione' => $values['descrizione'] ?: null,
                            ':valuta'      => $values['valuta'],
                            ':attivo'      => $values['attivo'],
                            ':valido_dal'  => $values['valido_dal'],
                            ':valido_al'   => $values['valido_al'],
                            ':id'          => $id,
                        ]);
                        redirect_with('sales/pricelists/edit?id=' . $id, 'success', 'Listino aggiornato.');
                    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
                        $errors['_global'] = 'Errore: ' . $e->getMessage();
                    }
                }
            }
        }

        $pag = qk_pagination_params(['nome', 'sku_interno', 'quantita_minima', 'prezzo'], 'nome', 'asc');
        $limit  = $pag['limit'];
        $offset = $pag['offset'];
        $page   = $pag['page'];
        $sort   = $pag['sort'];
        $dir    = $pag['dir'];

        // Mappatura colonne ordinamento
        $sortColMap = [
            'nome'            => 'p.nome',
            'sku_interno'     => 'p.sku_interno',
            'quantita_minima' => 'lp.quantita_minima',
            'prezzo'          => 'lp.prezzo'
        ];
        $orderByCol = $sortColMap[$sort] ?? 'p.nome';

        // Count per la paginazione
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM listini_prezzi lp WHERE lp.listino_id = :lid");
        $stmtCount->execute([':lid' => $id]);
        $total = (int)$stmtCount->fetchColumn();
        $pages = (int)ceil($total / $limit);

        // Righe prodotto (con UoM nativa del prezzo o fallback UoM stock)
        $stmtItems = $this->pdo->prepare(
            "SELECT lp.*, 
                    p.nome AS prodotto_nome, p.sku_interno, 
                    p.unita_misura_stock_id AS p_um_stock_id, 
                    p.unita_contenuto_id AS p_um_comm_id, 
                    p.quantita_contenuto AS p_factor,
                    um_stock.codice AS um_stock_code,
                    um_comm.codice AS um_comm_code,
                    um_native.codice AS um_native_code,
                    COALESCE(um_native.codice, um_stock.codice) AS unita_misura,
                    -- Fix 'Listino Fantasma' (audit/MASTER_AUDIT_REPORT.md) —
                    -- vedi commento esteso in db/init_sqlite.sql §14b.
                    (SELECT MIN(vf.prezzo)
                     FROM v_prezzo_acquisto_base_fornitore vf
                     JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                     WHERE pf2.prodotto_id = lp.prodotto_id AND pf2.attivo = 1) AS costo
             FROM listini_prezzi lp
             JOIN prodotti p ON p.id = lp.prodotto_id
             LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
             LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
             LEFT JOIN unita_misura um_native ON um_native.id = lp.unita_misura_id
             WHERE lp.listino_id = :lid
             ORDER BY $orderByCol $dir, lp.quantita_minima ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmtItems->bindValue(':lid', $id, PDO::PARAM_INT);
        $stmtItems->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtItems->execute();
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Clienti che usano questo listino come default
        $stmtClienti = $this->pdo->prepare(
            "SELECT cf.id,
                    COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente
             FROM dettagli_clienti dc
             JOIN clienti_fornitori cf ON cf.id = dc.soggetto_id
             WHERE dc.listino_id = :lid
             ORDER BY nome_cliente ASC"
        );
        $stmtClienti->execute([':lid' => $id]);
        $clienti_assegnati = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_form.php', [
            'page_title'    => 'Modifica listino vendita',
            'page_subtitle' => 'Gestisci il listino e i prezzi prodotto',
            'breadcrumbs'   => [
                ['label' => 'Vendite', 'url' => url('sales')],
                ['label' => 'Listini vendita', 'url' => url('sales/pricelists')],
                ['label' => e($pricelist['nome'])],
            ],
            'errors'            => $errors,
            'values'            => $values,
            'isEdit'            => true,
            'id'                => $id,
            'items'             => $items,
            'clienti_assegnati' => $clienti_assegnati,
            'pag'               => $pag,
            'page'              => $page,
            'pages'             => $pages,
            'total'             => $total,
            'sort'              => $sort,
            'dir'               => $dir,
        ]);
    }

    /* ── Elimina ────────────────────────────────────────────────────────────── */

    public function delete(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect_with('sales/pricelists', 'danger', 'Metodo non consentito.');
        }
        if (!csrf_verify()) {
            redirect_with('sales/pricelists', 'danger', 'Token di sicurezza invalido.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            redirect_with('sales/pricelists', 'danger', 'ID non valido.');
        }

        // Verifica che non sia usato in preventivi aperti
        $stmtCheck = $this->pdo->prepare(
            "SELECT COUNT(*) FROM preventivi WHERE listino_id = :id AND stato NOT IN ('annullato','rifiutato')"
        );
        $stmtCheck->execute([':id' => $id]);
        $usatoInPreventivi = (int)$stmtCheck->fetchColumn();

        if ($usatoInPreventivi > 0) {
            redirect_with('sales/pricelists', 'danger', "Impossibile eliminare: il listino è usato in $usatoInPreventivi preventivo/i attivi.");
        }

        try {
            $this->pdo->prepare("DELETE FROM listini WHERE id = :id")->execute([':id' => $id]);
            redirect_with('sales/pricelists', 'success', 'Listino eliminato.');
        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
            redirect_with('sales/pricelists', 'danger', 'Impossibile eliminare: ' . $e->getMessage());
        }
    }

    /* ── API: salva riga prezzo ─────────────────────────────────────────────── */

    public function apiSaveRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId   = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $rigaId      = filter_input(INPUT_POST, 'riga_id',    FILTER_VALIDATE_INT); // 0 = nuova
        $prodottoId  = filter_input(INPUT_POST, 'prodotto_id', FILTER_VALIDATE_INT);
        $qMin        = filter_input(INPUT_POST, 'quantita_minima', FILTER_VALIDATE_FLOAT);
        $prezzo      = filter_input(INPUT_POST, 'prezzo_unitario', FILTER_VALIDATE_FLOAT);
        $sconto      = filter_input(INPUT_POST, 'sconto_perc', FILTER_VALIDATE_FLOAT);
        $unitaMisuraId = filter_input(INPUT_POST, 'unita_misura_id', FILTER_VALIDATE_INT);
        $note        = trim(post('note') ?? '');

        if (!$listinoId || !$prodottoId || $prezzo === false || $prezzo === null) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti o non validi.']);
            exit;
        }

        // Verifica listino esiste
        $stmtL = $this->pdo->prepare("SELECT id FROM listini WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        if (!$stmtL->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Listino non trovato.']);
            exit;
        }

        $qMin   = ($qMin === false || $qMin === null) ? 0.0 : $qMin;
        $sconto = ($sconto === false || $sconto === null) ? 0.0 : $sconto;

        // Logical business validations
        if ($prezzo < 0) {
            echo json_encode(['ok' => false, 'error' => 'Il prezzo unitario non può essere negativo.']);
            exit;
        }
        if ($sconto < 0 || $sconto > 100) {
            echo json_encode(['ok' => false, 'error' => 'Lo sconto deve essere compreso tra 0 e 100%.']);
            exit;
        }
        if ($qMin < 0) {
            echo json_encode(['ok' => false, 'error' => 'La quantità minima dello scaglione non può essere negativa.']);
            exit;
        }

        // D3.1 (PIANO Motore di Pricing Multi-UoM §8 Fase 1/3): a ogni scrittura, calcolare e
        // salvare il valore canonico della soglia (sempre Stock UoM), insieme all'intento originale
        // (valore + unità così come scritti dall'utente) — unita_misura_id qui rappresenta sia
        // l'unità di quotazione del prezzo sia l'unità in cui l'utente ha scritto la soglia
        // (unico campo nell'interfaccia attuale: coerente col modello di input odierno).
        $qMinIntento = $qMin;
        $unitaSogliaId = $unitaMisuraId ?: null;
        $qMinStock = $qMin > 0
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaSogliaId, $qMin, 0.0)['quantita_stock']
            : 0.0;

        // Controllo duplicati esplicito (14/07/2026, PIANO Motore di Pricing Multi-UoM §7.4/§8):
        // questo endpoint non ne aveva mai avuto uno (a differenza degli equivalenti acquisti,
        // REVISIONE §6.15) — si affidava solo al vincolo UNIQUE del database, che ora è su
        // quantita_minima_stock (D1.2): senza questo controllo, un doppione mascherato (es. stesso
        // prodotto/listino con soglie in unità diverse ma stesso valore canonico) produrrebbe un
        // errore SQL grezzo invece di un messaggio comprensibile.
        $stmtDup = $this->pdo->prepare("
            SELECT id FROM listini_prezzi
            WHERE listino_id = :listino_id
              AND prodotto_id = :prodotto_id
              AND quantita_minima_stock = :qmin_stock
              AND id != :riga_id
        ");
        $stmtDup->execute([
            ':listino_id'  => $listinoId,
            ':prodotto_id' => $prodottoId,
            ':qmin_stock'  => $qMinStock,
            ':riga_id'     => $rigaId ?: 0,
        ]);
        if ($stmtDup->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Attenzione: esiste già un prezzo configurato in questo listino per questo scaglione di quantità (anche se espresso in un\'altra unità di misura, equivale alla stessa soglia).']);
            exit;
        }

        try {
            if ($rigaId) {
                // UPDATE
                // modificato_manualmente_il: aggiornato sempre su un salvataggio manuale (questo endpoint,
                // usato sia dalla griglia listino sia dal drawer prodotto), mai da apiImportPrezzi() —
                // vedi REVISIONE §6.12: evita che un import massivo sovrascriva silenziosamente un prezzo
                // corretto/negoziato a mano dopo l'ultimo import.
                $this->pdo->prepare(
                    "UPDATE listini_prezzi SET
                        prodotto_id     = :prodotto_id,
                        quantita_minima = :qmin,
                        prezzo_unitario = :prezzo,
                        sconto_perc     = :sconto,
                        unita_misura_id = :unita_misura_id,
                        note            = :note,
                        quantita_minima_intento = :qmin_intento,
                        unita_misura_soglia_id  = :um_soglia_id,
                        quantita_minima_stock   = :qmin_stock,
                        modificato_manualmente_il = CURRENT_TIMESTAMP
                     WHERE id = :id AND listino_id = :listino_id"
                )->execute([
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':unita_misura_id' => $unitaMisuraId ?: null,
                    ':note'        => $note ?: null,
                    ':qmin_intento' => $qMinIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qmin_stock'   => $qMinStock,
                    ':id'          => $rigaId,
                    ':listino_id'  => $listinoId,
                ]);
                $newId = $rigaId;
            } else {
                // INSERT
                $this->pdo->prepare(
                    "INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, unita_misura_id, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, modificato_manualmente_il)
                     VALUES (:listino_id, :prodotto_id, :qmin, :prezzo, :sconto, :unita_misura_id, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)"
                )->execute([
                    ':listino_id'  => $listinoId,
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':unita_misura_id' => $unitaMisuraId ?: null,
                    ':note'        => $note ?: null,
                    ':qmin_intento' => $qMinIntento,
                    ':um_soglia_id' => $unitaSogliaId,
                    ':qmin_stock'   => $qMinStock,
                ]);
                $newId = (int)$this->pdo->lastInsertId();
            }



            // Restituisce la riga aggiornata con nome prodotto e UoM
            $stmtRow = $this->pdo->prepare(
                "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno, 
                        COALESCE(um_p.codice, um_stock.codice) AS unita_misura,
                        p.unita_misura_stock_id AS uom_stock_id,
                        um_stock.codice AS uom_stock_code,
                        p.unita_contenuto_id AS uom_commercial_id,
                        um_comm.codice AS uom_commercial_code,
                        -- Fix 'Listino Fantasma' (audit/MASTER_AUDIT_REPORT.md).
                        (SELECT MIN(vf.prezzo)
                         FROM v_prezzo_acquisto_base_fornitore vf
                         JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                         WHERE pf2.prodotto_id = lp.prodotto_id AND pf2.attivo = 1) AS costo
                 FROM listini_prezzi lp
                 JOIN prodotti p ON p.id = lp.prodotto_id
                 LEFT JOIN unita_misura um_stock ON um_stock.id = p.unita_misura_stock_id
                 LEFT JOIN unita_misura um_p ON um_p.id = lp.unita_misura_id
                 LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
                 WHERE lp.id = :id"
            );
            $stmtRow->execute([':id' => $newId]);
            $riga = $stmtRow->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'riga' => $riga]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: elimina riga prezzo ───────────────────────────────────────────── */

    public function apiDeleteRiga(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $rigaId = filter_input(INPUT_POST, 'riga_id', FILTER_VALIDATE_INT);
        if (!$rigaId) {
            echo json_encode(['ok' => false, 'error' => 'ID riga non valido.']);
            exit;
        }

        try {
            $this->pdo->prepare("DELETE FROM listini_prezzi WHERE id = :id")->execute([':id' => $rigaId]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API: ricerca prodotti (per autocomplete righe) ────────────────────── */

    public function apiProdotti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(get_param('q') ?? '');
        
        // Nessun limite di caratteri per favorire autocomplete istantaneo (dry-run o 1 carattere)
        
        $where = "WHERE p.attivo = 1";
        $params = [];

        if ($q !== '') {
            $where .= " AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR p.ean LIKE :q)";
            $params = [':q' => '%' . $q . '%'];
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nome AS label, p.sku_interno AS sku, um.codice AS unita_misura,
                    p.unita_misura_stock_id, um_c.codice AS unita_misura_commerciale,
                    p.unita_contenuto_id, p.quantita_contenuto,
                    pf.rrp_min, pf.rrp_max,
                    -- Fix 'Listino Fantasma' (audit/MASTER_AUDIT_REPORT.md).
                    (SELECT MIN(vf.prezzo)
                     FROM v_prezzo_acquisto_base_fornitore vf
                     JOIN prodotti_fornitori pf2 ON pf2.id = vf.prodotto_fornitore_id
                     WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1) AS costo
             FROM prodotti p
             LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
             LEFT JOIN unita_misura um_c ON um_c.id = p.unita_contenuto_id
             LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.attivo = 1
                AND pf.ordine_preferenza_fornitore = (
                    SELECT MIN(pf2.ordine_preferenza_fornitore)
                    FROM prodotti_fornitori pf2
                    WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1
                )
             $where
             ORDER BY p.nome ASC
             LIMIT 20"
        );
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    /* ── Metodo di servizio: ottieni prezzo vendita ─────────────────────────── */

    /**
     * Determina il prezzo di vendita per un prodotto dato un listino, una quantità e una data.
     * Strategia (analoga a getPurchasePrice):
     *  1. Cerca nei listini associati del cliente (in ordine di priorità) o nel listino specificato
     *  2. Se non trova, cade sul listino standard o ricarico su costo
     *
     * CONTRATTO (D3.1, PIANO Motore di Pricing Multi-UoM §8 Fase 3): $quantita deve essere
     * SEMPRE già espressa in Stock UoM da chi chiama questa funzione — viene confrontata con
     * quantita_minima_stock (valore canonico), non più col valore grezzo. L'unico chiamante
     * attuale, apiOttieniPrezzo(), converte la quantità ricevuta dal client prima di invocarla.
     */
    public function getSalePrice(int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null, ?int $clienteId = null): ?array
    {
        // Refactoring 14/07/2026: logica estratta in includes/pricing.php (qk_get_sale_price()),
        // pura (PDO + parametri, nessun riferimento a HTTP), così riutilizzabile da script CLI,
        // cron, o altri moduli senza passare da questo controller. Nessuna modifica di
        // comportamento: stessa logica, stessi parametri, stesso valore di ritorno.
        return qk_get_sale_price($this->pdo, $prodottoId, $listinoId, $quantita, $dataRiferimento, $clienteId);
    }

    /* ── API endpoint: ottieni prezzo vendita ───────────────────────────────── */

    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $prodottoId = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $listinoId  = filter_input(INPUT_GET, 'listino_id',  FILTER_VALIDATE_INT);
        $clienteId  = filter_input(INPUT_GET, 'cliente_id',  FILTER_VALIDATE_INT);
        $quantita   = filter_input(INPUT_GET, 'quantita',    FILTER_VALIDATE_FLOAT);
        // D3.1 (PIANO Motore di Pricing Multi-UoM §5.1/§8 Fase 3): prima di questa modifica, `quantita`
        // arrivava come numero grezzo senza mai dichiarare in quale unità era espresso. Nuovo parametro
        // opzionale `unita_misura_id`: l'unità in cui il chiamante esprime `quantita` (Stock o UoM
        // commerciale del prodotto). Se assente, retrocompatibile: si assume già Stock UoM.
        $unitaRichiestaId = filter_input(INPUT_GET, 'unita_misura_id', FILTER_VALIDATE_INT) ?: null;
        $data       = get_param('data') ?: null;

        if (!$prodottoId) {
            echo json_encode(['ok' => false, 'error' => 'Prodotto ID mancante.']);
            exit;
        }

        $listinoId = $listinoId ?: 0;
        $quantita = ($quantita === false || $quantita === null) ? 1.0 : $quantita;

        // Conversione autorevole (server-side, BCMath) in Stock UoM PRIMA di interrogare il motore
        // di prezzo: da qui in poi getSalePrice() riceve sempre e solo una quantità in Stock UoM,
        // comparabile con quantita_minima_stock indipendentemente da come l'utente l'ha scritta.
        $quantitaStock = $unitaRichiestaId
            ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $unitaRichiestaId, $quantita, 0.0)['quantita_stock']
            : $quantita;

        $priceInfo = $this->getSalePrice($prodottoId, $listinoId, $quantitaStock, $data, $clienteId);

        $costo_unitario = 0.0;
        $rrp_min = null;
        $rrp_max = null;

        // Fix "Listino Fantasma" (audit/MASTER_AUDIT_REPORT.md).
        $stRrp = $this->pdo->prepare('
            SELECT pf.rrp_min, pf.rrp_max, pf.rrp_unita_misura_id,
                   vf.prezzo AS costo_unitario
            FROM prodotti_fornitori pf
            LEFT JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
            WHERE pf.prodotto_id = ? AND pf.attivo = 1
            ORDER BY pf.ordine_preferenza_fornitore ASC
            LIMIT 1
        ');
        $stRrp->execute([$prodottoId]);
        $rrpData = $stRrp->fetch(PDO::FETCH_ASSOC);
        $rrp_unita_misura_id = null;
        if ($rrpData) {
            $costo_unitario = (float)($rrpData['costo_unitario'] ?? 0.0);
            $rrp_min = $rrpData['rrp_min'] !== null ? (float)$rrpData['rrp_min'] : null;
            $rrp_max = $rrpData['rrp_max'] !== null ? (float)$rrpData['rrp_max'] : null;
            // Restituiamo la UoM nativa dell'RRP così il frontend sa se convertire o meno
            $rrp_unita_misura_id = $rrpData['rrp_unita_misura_id'] !== null
                ? (int)$rrpData['rrp_unita_misura_id']
                : null;
        }

        if ($priceInfo) {
            echo json_encode([
                'ok'                 => true,
                'prezzo_unitario'    => $priceInfo['prezzo_unitario'],
                'sconto_perc'        => $priceInfo['sconto_perc'],
                'valuta'             => $priceInfo['valuta'],
                'listino_id'         => $priceInfo['listino_id'],
                'prezzo_sorgente'    => $priceInfo['prezzo_sorgente'],
                'origine'            => $priceInfo['origine'],
                'costo_unitario'     => $costo_unitario,
                'rrp_min'            => $rrp_min,
                'rrp_max'            => $rrp_max,
                'rrp_unita_misura_id'=> $rrp_unita_misura_id,
                'unita_misura_id'    => $priceInfo['unita_misura_id'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo_unitario' => 0.0,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
                'origine'         => 'manuale',
                'unita_misura_id' => null,
            ]);
        }
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        exit;
    }

    /* ── API endpoint: ottieni tutti i clienti con stato assegnazione ───────── */

    public function apiClientiAssegnazione(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $listinoId = filter_input(INPUT_GET, 'listino_id', FILTER_VALIDATE_INT);
        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            // Ottieni tutti i clienti attivi
            $stmt = $this->pdo->prepare("
                SELECT cf.id,
                       COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente,
                       cf.partita_iva,
                       (SELECT 1 FROM clienti_listini cl WHERE cl.cliente_id = cf.id AND cl.listino_id = :lid) AS assegnato_pivot,
                       (SELECT 1 FROM dettagli_clienti dc WHERE dc.soggetto_id = cf.id AND dc.listino_id = :lid) AS assegnato_default
                FROM clienti_fornitori cf
                WHERE cf.tipo IN ('cliente', 'entrambi') AND cf.attivo = 1
                ORDER BY nome_cliente ASC
            ");
            $stmt->execute([':lid' => $listinoId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'id' => (int)$row['id'],
                    'nome_cliente' => $row['nome_cliente'],
                    'partita_iva' => $row['partita_iva'] ?? '',
                    'assegnato' => ($row['assegnato_pivot'] || $row['assegnato_default']) ? true : false,
                ];
            }

            echo json_encode(['ok' => true, 'clienti' => $result]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* ── API endpoint: salva assegnazioni massive ───────────────────────────── */

    public function apiAssegnaClienti(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token non valido.']);
            exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $clienteIds = array_values(array_unique(array_map('intval', $_POST['cliente_ids'] ?? [])));

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Listino ID mancante.']);
            exit;
        }

        try {
            $this->pdo->beginTransaction();

            // Sincronizzazione differenziale (Diff-Sync) — corretto il 13/07/2026 (segnalazione #17
            // / REVISIONE, "Cancellazione distruttiva con perdita di scadenze Listini"). La versione
            // precedente cancellava e reinseriva TUTTE le righe di clienti_listini per questo listino
            // a ogni salvataggio, azzerando priorita/data_inizio/data_fine anche per i clienti che
            // restavano assegnati — quei campi sono scritti altrove (scheda del singolo cliente,
            // includes/anagrafiche.php) e qui venivano silenziosamente persi. Ora si toccano solo le
            // righe che cambiano davvero: si inseriscono solo i clienti nuovi e si rimuovono solo
            // quelli deselezionati, senza mai toccare chi era già assegnato e resta tale.
            $stmtEsistenti = $this->pdo->prepare("SELECT cliente_id FROM clienti_listini WHERE listino_id = ?");
            $stmtEsistenti->execute([$listinoId]);
            $esistenti = array_map('intval', $stmtEsistenti->fetchAll(PDO::FETCH_COLUMN));

            $daRimuovere = array_diff($esistenti, $clienteIds);
            $daAggiungere = array_diff($clienteIds, $esistenti);

            if (!empty($daRimuovere)) {
                $placeholders = implode(',', array_fill(0, count($daRimuovere), '?'));
                $stmtDel = $this->pdo->prepare("DELETE FROM clienti_listini WHERE listino_id = ? AND cliente_id IN ($placeholders)");
                $stmtDel->execute(array_merge([$listinoId], array_values($daRimuovere)));
            }

            if (!empty($daAggiungere)) {
                $stmtIns = $this->pdo->prepare("
                    INSERT INTO clienti_listini (cliente_id, listino_id, priorita)
                    VALUES (?, ?, 10)
                ");
                foreach ($daAggiungere as $cid) {
                    $stmtIns->execute([$cid, $listinoId]);
                }
            }

            $this->pdo->commit();

            // Ricarica la lista aggiornata dei clienti assegnati per restituirla al frontend
            $stmtClienti = $this->pdo->prepare("
                SELECT DISTINCT cf.id,
                       COALESCE(cf.ragione_sociale, TRIM(COALESCE(cf.nome,'') || ' ' || COALESCE(cf.cognome,''))) AS nome_cliente
                FROM clienti_fornitori cf
                LEFT JOIN dettagli_clienti dc ON dc.soggetto_id = cf.id
                LEFT JOIN clienti_listini cl ON cl.cliente_id = cf.id
                WHERE (dc.listino_id = :lid OR cl.listino_id = :lid)
                  AND cf.attivo = 1
                ORDER BY nome_cliente ASC
            ");
            $stmtClienti->execute([':lid' => $listinoId]);
            $clienti_assegnati = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'clienti_assegnati' => $clienti_assegnati]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    public function apiImportPrezzi(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']); exit;
        }
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'error' => 'Token di sicurezza non valido. Ricarica la pagina.']); exit;
        }

        $listinoId = filter_input(INPUT_POST, 'listino_id', FILTER_VALIDATE_INT);
        $rows      = json_decode(post('rows') ?? '[]', true);

        if (!$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'ID listino non specificato.']); exit;
        }
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['ok' => false, 'error' => 'Nessun dato ricevuto.']); exit;
        }

        // Verifica listino
        $stmtL = $this->pdo->prepare("SELECT id FROM listini WHERE id = :id");
        $stmtL->execute([':id' => $listinoId]);
        if (!$stmtL->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Listino non trovato.']); exit;
        }

        $inserted   = 0;
        $skipped    = 0;
        $rowErrors  = [];
        $touchedIds = []; // id di listini_prezzi scritte/confermate in questo import — vedi REVISIONE §6.12

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $lineNum = $i + 2;

                $sku = trim((string)($row['sku_interno'] ?? ''));
                if ($sku === '') {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': SKU interno vuoto — saltata.';
                    continue;
                }

                // Cerca il prodotto
                $stmtProd = $this->pdo->prepare('SELECT id, unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE sku_interno = :s AND attivo = 1');
                $stmtProd->execute([':s' => $sku]);
                $prodRow = $stmtProd->fetch(PDO::FETCH_ASSOC);
                if (!$prodRow) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ': prodotto con SKU "' . htmlspecialchars($sku) . '" non trovato o non attivo — saltata.';
                    continue;
                }
                $prodottoId = (int)$prodRow['id'];
                $uomStockId = (int)$prodRow['unita_misura_stock_id'];
                $uomCommercialId = $prodRow['unita_contenuto_id'] !== null ? (int)$prodRow['unita_contenuto_id'] : null;
                $uomId = $uomStockId; // default retrocompatibile: assume Stock UoM se la colonna non è valorizzata

                // D4.6 (PIANO Motore di Pricing Multi-UoM §7.7/§8 Fase 4): colonna facoltativa nel
                // tracciato Excel — se valorizzata, dichiara in quale unità è scritta la soglia
                // (quantita_minima) per QUESTA riga, invece di assumere sempre Stock UoM. Se il
                // codice non corrisponde né a Stock né a UoM commerciale del prodotto, si rifiuta
                // la riga (stesso principio di §7.5: mai salvare un dato in un'unità non convertibile).
                $umSogliaCodice = trim((string)($row['unita_misura_soglia'] ?? ''));
                if ($umSogliaCodice !== '') {
                    $stmtUmSoglia = $this->pdo->prepare('SELECT id FROM unita_misura WHERE codice = :c');
                    $stmtUmSoglia->execute([':c' => $umSogliaCodice]);
                    $umSogliaId = $stmtUmSoglia->fetchColumn();
                    if (!$umSogliaId || ((int)$umSogliaId !== $uomStockId && (int)$umSogliaId !== $uomCommercialId)) {
                        $skipped++;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): unità soglia "' . htmlspecialchars($umSogliaCodice) . '" non valida per questo prodotto (deve essere l\'unità di stock o l\'unità commerciale) — saltata.';
                        continue;
                    }
                    $uomId = (int)$umSogliaId;
                }

                $toFloat = function(?string $v): float {
                    if ($v === null || trim($v) === '') return 0.0;
                    return (float) str_replace(',', '.', trim($v));
                };

                $prezzoVal = trim((string)($row['prezzo_unitario'] ?? ''));
                if ($prezzoVal === '' || !is_numeric(str_replace(',', '.', $prezzoVal))) {
                    $skipped++;
                    $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo unitario vuoto o non valido — saltata.';
                    continue;
                }
                $prezzo = (float) str_replace(',', '.', $prezzoVal);

                $qMin   = $toFloat($row['quantita_minima'] ?? '0');
                $sconto = $toFloat($row['sconto_perc'] ?? '0');
                $note   = !empty($row['note']) ? trim((string)$row['note']) : null;

                // D4.6 (PIANO Motore di Pricing Multi-UoM §7.7): $uomId qui è o la Stock UoM
                // (default retrocompatibile) o l'unità dichiarata dalla colonna facoltativa
                // "unita_misura_soglia" — stesso campo usato sia come unità di quotazione del
                // prezzo sia come unità della soglia, coerente col modello a singola unità per
                // riga già in uso nel resto del sistema (vedi nota in apiSaveRiga).
                $qMinCanonico = $qMin > 0
                    ? (float) qk_uom_converti_riga($this->pdo, $prodottoId, $uomId, $qMin, 0.0)['quantita_stock']
                    : 0.0;

                // Cerca se esiste già un prezzo per questo listino/prodotto allo stesso scaglione.
                // Bug corretto il 13/07/2026: la query precedente non filtrava su unita_misura_id.
                // Bug corretto il 14/07/2026 (audit a tappeto, gap simmetrico a quello già noto lato
                // acquisti in apiImportPrezzi di PurchasesController): il confronto restava sul valore
                // grezzo (quantita_minima + unita_misura_id), non sul valore canonico quantita_minima_stock
                // — un import che creasse un doppione mascherato (es. "100 KG" quando esiste già "4 FUSTI"
                // con fattore 25) avrebbe fallito con un errore SQL grezzo sul vincolo UNIQUE invece di
                // essere riconosciuto come lo stesso scaglione e gestito correttamente (update o skip).
                $stmtCheck = $this->pdo->prepare("
                    SELECT id, importato_il, modificato_manualmente_il FROM listini_prezzi
                    WHERE listino_id = :lid AND prodotto_id = :pid AND quantita_minima_stock = :qmin_stock
                ");
                $stmtCheck->execute([
                    ':lid'        => $listinoId,
                    ':pid'        => $prodottoId,
                    ':qmin_stock' => $qMinCanonico,
                ]);
                $existingRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingRow) {
                    $existingId = (int)$existingRow['id'];

                    // REVISIONE §6.12 — "Regola d'Oro": non sovrascrivere silenziosamente una riga
                    // modificata a mano dopo l'ultimo import. Resta comunque "vista" (touchedIds),
                    // quindi non verrà mai chiusa come orfana solo perché non aggiornata.
                    $modificataAMano = !empty($existingRow['modificato_manualmente_il'])
                        && (empty($existingRow['importato_il']) || $existingRow['modificato_manualmente_il'] > $existingRow['importato_il']);

                    if ($modificataAMano) {
                        $skipped++;
                        $touchedIds[] = $existingId;
                        $rowErrors[] = 'Riga ' . $lineNum . ' (SKU: ' . htmlspecialchars($sku) . '): prezzo modificato manualmente dopo l\'ultimo import — non sovrascritto, verificare a mano.';
                        continue;
                    }

                    // UPDATE
                    $this->pdo->prepare("
                        UPDATE listini_prezzi SET
                            prezzo_unitario = :prezzo,
                            sconto_perc = :sconto,
                            note = :note,
                            quantita_minima_intento = :qmin_intento,
                            unita_misura_soglia_id  = :um_soglia_id,
                            quantita_minima_stock   = :qmin_stock,
                            importato_il = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ")->execute([
                        ':prezzo' => $prezzo,
                        ':sconto' => $sconto,
                        ':note'   => $note,
                        ':qmin_intento' => $qMin,
                        ':um_soglia_id' => $uomId ?: null,
                        ':qmin_stock'   => $qMinCanonico,
                        ':id'     => $existingId
                    ]);
                    $touchedIds[] = $existingId;
                } else {
                    // INSERT
                    $this->pdo->prepare("
                        INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, unita_misura_id, note, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock, importato_il)
                        VALUES (:lid, :pid, :qmin, :prezzo, :sconto, :umid, :note, :qmin_intento, :um_soglia_id, :qmin_stock, CURRENT_TIMESTAMP)
                    ")->execute([
                        ':lid'    => $listinoId,
                        ':pid'    => $prodottoId,
                        ':qmin'   => $qMin,
                        ':prezzo' => $prezzo,
                        ':sconto' => $sconto,
                        ':umid'   => $uomId ?: null,
                        ':note'   => $note,
                        ':qmin_intento' => $qMin,
                        ':um_soglia_id' => $uomId ?: null,
                        ':qmin_stock'   => $qMinCanonico,
                    ]);
                    $touchedIds[] = (int)$this->pdo->lastInsertId();
                }
                $inserted++;
            }

            // REVISIONE §6.12 — righe che in passato provenivano da un import per QUESTO listino ma non
            // sono più presenti nel file appena caricato. A differenza degli acquisti, `listini_prezzi`
            // non ha validità per riga (scelta di progetto, vedi §6.10/C10: la validità vive solo sulla
            // testata del listino) — quindi qui, coerentemente, le righe orfane vengono rimosse invece
            // di essere "chiuse" con una data, non essendoci alcun campo dove farlo. Le righe modificate
            // manualmente restano comunque escluse, perché sono sempre in touchedIds.
            $removed = 0;
            $stmtOrphanSel = $this->pdo->prepare("SELECT id FROM listini_prezzi WHERE listino_id = :lid AND importato_il IS NOT NULL");
            $stmtOrphanSel->execute([':lid' => $listinoId]);
            $orphanIds = array_diff(array_map('intval', $stmtOrphanSel->fetchAll(PDO::FETCH_COLUMN)), $touchedIds);
            if (!empty($orphanIds)) {
                $placeholders = implode(',', array_fill(0, count($orphanIds), '?'));
                $stmtRemove = $this->pdo->prepare("DELETE FROM listini_prezzi WHERE id IN ($placeholders)");
                $stmtRemove->execute(array_values($orphanIds));
                $removed = count($orphanIds);
            }

            $this->pdo->commit();
            echo json_encode([
                'ok' => true,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'removed' => $removed,
                'errors' => $rowErrors
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
