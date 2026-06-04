<?php

declare(strict_types=1);

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

        $sql .= " ORDER BY l.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $pricelists = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->page(APP_ROOT_DIR . '/app/views/sales/pricelists_list.php', [
            'page_title'    => 'Listini vendita',
            'page_subtitle' => 'Gestione dei listini prezzi di vendita',
            'breadcrumbs'   => [['label' => 'Vendite', 'url' => url('sales')], ['label' => 'Listini vendita']],
            'pricelists'    => $pricelists,
            'search'        => $search,
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
                    $errors['valuta'] = 'Codice valuta non valido.';
                }

                if (empty($errors)) {
                    try {
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
                        $errors['_global'] = 'Errore: ' . $e->getMessage();
                    }
                }
            }
        }

        // Righe prodotto
        $stmtItems = $this->pdo->prepare(
            "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno, um.codice AS unita_misura
             FROM listini_prezzi lp
             JOIN prodotti p ON p.id = lp.prodotto_id
             LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
             WHERE lp.listino_id = :lid
             ORDER BY p.nome ASC, lp.quantita_minima ASC"
        );
        $stmtItems->execute([':lid' => $id]);
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

        try {
            if ($rigaId) {
                // UPDATE
                $this->pdo->prepare(
                    "UPDATE listini_prezzi SET
                        prodotto_id     = :prodotto_id,
                        quantita_minima = :qmin,
                        prezzo_unitario = :prezzo,
                        sconto_perc     = :sconto,
                        note            = :note
                     WHERE id = :id AND listino_id = :listino_id"
                )->execute([
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':note'        => $note ?: null,
                    ':id'          => $rigaId,
                    ':listino_id'  => $listinoId,
                ]);
                $newId = $rigaId;
            } else {
                // INSERT
                $this->pdo->prepare(
                    "INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, sconto_perc, note)
                     VALUES (:listino_id, :prodotto_id, :qmin, :prezzo, :sconto, :note)"
                )->execute([
                    ':listino_id'  => $listinoId,
                    ':prodotto_id' => $prodottoId,
                    ':qmin'        => $qMin,
                    ':prezzo'      => $prezzo,
                    ':sconto'      => $sconto,
                    ':note'        => $note ?: null,
                ]);
                $newId = (int)$this->pdo->lastInsertId();
            }

            // Restituisce la riga aggiornata con nome prodotto
            $stmtRow = $this->pdo->prepare(
                "SELECT lp.*, p.nome AS prodotto_nome, p.sku_interno, um.codice AS unita_misura
                 FROM listini_prezzi lp
                 JOIN prodotti p ON p.id = lp.prodotto_id
                 LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
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
        if (strlen($q) < 2) {
            echo json_encode([]);
            exit;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.nome AS label, p.sku_interno AS sku, um.codice AS unita_misura
             FROM prodotti p
             LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
             WHERE p.attivo = 1
               AND (p.nome LIKE :q OR p.sku_interno LIKE :q OR p.codice_erp LIKE :q)
             ORDER BY p.nome ASC
             LIMIT 20"
        );
        $stmt->execute([':q' => '%' . $q . '%']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    /* ── Metodo di servizio: ottieni prezzo vendita ─────────────────────────── */

    /**
     * Determina il prezzo di vendita per un prodotto dato un listino, una quantità e una data.
     * Strategia (analoga a getPurchasePrice):
     *  1. Cerca nel listino specificato (per scaglione quantità)
     *  2. Se non trova, torna null
     */
    public function getSalePrice(int $prodottoId, int $listinoId, float $quantita = 1.0, ?string $dataRiferimento = null): ?array
    {
        if (!$dataRiferimento) {
            $dataRiferimento = date('Y-m-d');
        }

        // --- LEVEL 1: DEDICATO (Requested listinoId) ---
        if ($listinoId > 0) {
            $stmtL = $this->pdo->prepare(
                "SELECT id, nome, valuta FROM listini
                 WHERE id = :id AND attivo = 1
                   AND (valido_dal IS NULL OR valido_dal <= :data)
                   AND (valido_al  IS NULL OR valido_al  >= :data)"
            );
            $stmtL->execute([':id' => $listinoId, ':data' => $dataRiferimento]);
            $listino = $stmtL->fetch(PDO::FETCH_ASSOC);

            if ($listino) {
                $stmtP = $this->pdo->prepare(
                    "SELECT prezzo_unitario, sconto_perc, note, quantita_minima
                     FROM listini_prezzi
                     WHERE listino_id = :lid
                       AND prodotto_id = :pid
                       AND quantita_minima <= :qty
                     ORDER BY quantita_minima DESC
                     LIMIT 1"
                );
                $stmtP->execute([
                    ':lid' => $listinoId,
                    ':pid' => $prodottoId,
                    ':qty' => $quantita,
                ]);
                $row = $stmtP->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $qMin    = (float)$row['quantita_minima'];
                    $sorgente = 'Listino: ' . $listino['nome'];
                    if ($qMin > 0) {
                        $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
                    }

                    return [
                        'prezzo_unitario' => (float)$row['prezzo_unitario'],
                        'sconto_perc'     => (float)$row['sconto_perc'],
                        'valuta'          => $listino['valuta'],
                        'listino_id'      => $listinoId,
                        'listino_nome'    => $listino['nome'],
                        'prezzo_sorgente' => $sorgente,
                        'origine'         => 'dedicato',
                    ];
                }
            }
        }

        // --- LEVEL 2: STANDARD / PUBBLICO (ID 1) ---
        $fallbackListinoId = 1;
        if ($listinoId !== $fallbackListinoId) {
            $stmtL = $this->pdo->prepare(
                "SELECT id, nome, valuta FROM listini
                 WHERE id = :id AND attivo = 1
                   AND (valido_dal IS NULL OR valido_dal <= :data)
                   AND (valido_al  IS NULL OR valido_al  >= :data)"
            );
            $stmtL->execute([':id' => $fallbackListinoId, ':data' => $dataRiferimento]);
            $listino = $stmtL->fetch(PDO::FETCH_ASSOC);

            if ($listino) {
                $stmtP = $this->pdo->prepare(
                    "SELECT prezzo_unitario, sconto_perc, note, quantita_minima
                     FROM listini_prezzi
                     WHERE listino_id = :lid
                       AND prodotto_id = :pid
                       AND quantita_minima <= :qty
                     ORDER BY quantita_minima DESC
                     LIMIT 1"
                );
                $stmtP->execute([
                    ':lid' => $fallbackListinoId,
                    ':pid' => $prodottoId,
                    ':qty' => $quantita,
                ]);
                $row = $stmtP->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $qMin    = (float)$row['quantita_minima'];
                    $sorgente = 'Listino Standard: ' . $listino['nome'];
                    if ($qMin > 0) {
                        $sorgente .= ' (Scaglione >= ' . number_format($qMin, 0, ',', '.') . ')';
                    }

                    return [
                        'prezzo_unitario' => (float)$row['prezzo_unitario'],
                        'sconto_perc'     => (float)$row['sconto_perc'],
                        'valuta'          => $listino['valuta'],
                        'listino_id'      => $fallbackListinoId,
                        'listino_nome'    => $listino['nome'],
                        'prezzo_sorgente' => $sorgente,
                        'origine'         => 'standard',
                    ];
                }
            }
        }

        // --- LEVEL 3: EMERGENZA RICARICO SU COSTO (+20%) ---
        $costo_unitario = 0.0;
        $stCost = $this->pdo->prepare('
            SELECT MIN(pfp.prezzo) 
            FROM prodotti_fornitori_prezzi pfp
            JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
            WHERE pf.prodotto_id = ? AND pf.attivo = 1
        ');
        $stCost->execute([$prodottoId]);
        $costo_unitario_col = $stCost->fetchColumn();

        if ($costo_unitario_col !== false && $costo_unitario_col !== null) {
            $costPrice = (float)$costo_unitario_col;
            $computedPrice = round($costPrice * 1.20, 2);
            return [
                'prezzo_unitario' => $computedPrice,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'listino_id'      => 0,
                'listino_nome'    => 'Ricarico Emergenza Costo',
                'prezzo_sorgente' => 'Ricarico emergenza su costo (+20%)',
                'origine'         => 'costo',
            ];
        }

        // --- LEVEL 4: MANUALE (prezzo 0.00) ---
        return [
            'prezzo_unitario' => 0.0,
            'sconto_perc'     => 0.0,
            'valuta'          => 'EUR',
            'listino_id'      => 0,
            'listino_nome'    => 'Manuale',
            'prezzo_sorgente' => 'Nessun prezzo configurato',
            'origine'         => 'manuale',
        ];
    }

    /* ── API endpoint: ottieni prezzo vendita ───────────────────────────────── */

    public function apiOttieniPrezzo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $prodottoId = filter_input(INPUT_GET, 'prodotto_id', FILTER_VALIDATE_INT);
        $listinoId  = filter_input(INPUT_GET, 'listino_id',  FILTER_VALIDATE_INT);
        $quantita   = filter_input(INPUT_GET, 'quantita',    FILTER_VALIDATE_FLOAT);
        $data       = get_param('data') ?: null;

        if (!$prodottoId || !$listinoId) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti.']);
            exit;
        }

        $quantita = ($quantita === false || $quantita === null) ? 1.0 : $quantita;

        $priceInfo = $this->getSalePrice($prodottoId, $listinoId, $quantita, $data);

        if ($priceInfo) {
            echo json_encode([
                'ok'              => true,
                'prezzo_unitario' => $priceInfo['prezzo_unitario'],
                'sconto_perc'     => $priceInfo['sconto_perc'],
                'valuta'          => $priceInfo['valuta'],
                'listino_id'      => $priceInfo['listino_id'],
                'prezzo_sorgente' => $priceInfo['prezzo_sorgente'],
                'origine'         => $priceInfo['origine'],
            ]);
        } else {
            echo json_encode([
                'ok'              => false,
                'prezzo_unitario' => 0.0,
                'sconto_perc'     => 0.0,
                'valuta'          => 'EUR',
                'prezzo_sorgente' => 'Nessun prezzo configurato',
                'origine'         => 'manuale',
            ]);
        }
        exit;
    }
}
