<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/magazzino.php';

/* =============================================================================
   HELPERS
============================================================================= */

function qk_prodotti_null(?string $s): ?string
{
    $s = trim((string) $s);
    return $s === '' ? null : $s;
}

/**
 * Creazione rapida prodotto ("crea al volo" da un form documento: RFQ/Offerta,
 * Preventivo, Ordine Cliente). Funzione condivisa tra Acquisti e Vendite —
 * spostata qui da includes/sales_quotes.php il 15/07/2026 (Revisione Ciclo
 * Acquisti §3bis, punto B.1): non ha nulla di specifico al dominio vendite,
 * viveva in quel file solo per storia, e RfqController ne aveva reinventato
 * una copia incompleta invece di riusarla (mancavano tipo/unita_misura_stock_id,
 * entrambi NOT NULL senza default — l'endpoint falliva sempre).
 */
function qk_crea_prodotto_rapido(PDO $pdo, array $data): int
{
    $um = $data['unita_misura'] ?? 'pz';
    $stUm = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = ?');
    $stUm->execute([$um]);
    $um_id = $stUm->fetchColumn() ?: null;
    if (!$um_id) {
        $um_id = (int)$pdo->query('SELECT id FROM unita_misura LIMIT 1')->fetchColumn();
    }

    $sku = trim($data['sku_interno'] ?? '');
    if ($sku === '') {
        $sku = 'PRD-' . strtoupper(bin2hex(random_bytes(4)));
    }

    $pdo->beginTransaction();
    try {
        $sql = "INSERT INTO prodotti (
            sku_interno, nome, tipo, unita_misura_stock_id, attivo, created_at, updated_at
        ) VALUES (
            :sku_interno, :nome, 'semplice', :um_id, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sku_interno' => $sku,
            ':nome' => trim($data['nome']),
            ':um_id' => $um_id,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
             VALUES (:id, :magazzino_id, 0, 0, 0, 0, CURRENT_TIMESTAMP)'
        )->execute([':id' => $id, ':magazzino_id' => qk_magazzino_attivo_id($pdo)]);

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function qk_prodotti_decimal(?string $s): ?float
{
    $s = trim(str_replace(',', '.', (string) $s));
    return $s === '' ? null : (float) $s;
}

/**
 * Valida quantita_contenuto: deve essere un numero positivo con al massimo 3 decimali.
 * Restituisce null se valido, stringa di errore altrimenti.
 */
function qk_prodotti_valida_quantita_contenuto(?string $s): ?string
{
    if ($s === null || trim($s) === '') return null; // campo facoltativo
    $s = trim(str_replace(',', '.', $s));
    if (!is_numeric($s)) return 'La quantità per confezione deve essere un numero.';
    if ((float)$s <= 0) return 'La quantità per confezione deve essere maggiore di zero.';
    // Controlla max 3 decimali
    if (strpos($s, '.') !== false && strlen(substr($s, strpos($s, '.') + 1)) > 3) {
        return 'La quantità per confezione accetta al massimo 3 cifre decimali (es. 12.500).';
    }
    return null;
}

/* =============================================================================
   LOOKUP
============================================================================= */

/** Crea una nuova categoria prodotto (root) e restituisce l'ID. */
function qk_crea_categoria(PDO $pdo, string $nome, ?int $parent_id = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO categorie_prodotto (nome, parent_id, posizione_categoria) VALUES (:nome, :pid, 0)'
    );
    $stmt->execute([':nome' => $nome, ':pid' => $parent_id]);
    return (int) $pdo->lastInsertId();
}

/** Carica le immagini di un prodotto, aggiunge l'URL assoluto. */
function qk_load_prodotto_immagini(PDO $pdo, int $prodotto_id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, file_path, posizione_immagine
           FROM prodotti_immagini
          WHERE prodotto_id = :id
          ORDER BY posizione_immagine, id'
    );
    $stmt->execute([':id' => $prodotto_id]);
    return array_map(static function (array $r): array {
        return [
            'id'               => (int) $r['id'],
            'file_path'        => $r['file_path'],
            'posizione_immagine' => (int) $r['posizione_immagine'],
            'url'              => BASE_URL . '/uploads?path=' . urlencode(ltrim($r['file_path'], '/')),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Salva l'immagine di copertina di un prodotto.
 * Sostituisce l'eventuale copertina precedente (sort_order = 0).
 */
function qk_salva_immagine_prodotto(PDO $pdo, int $prodotto_id, array $file): ?string
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    $extMap   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }

    // Sharding per prodotto_id (ogni prodotto ha la sua sottocartella)
    $subdir  = 'prodotti/' . $prodotto_id;
    $dir     = upload_ensure_dir($subdir);

    $filename = 'cover_' . bin2hex(random_bytes(6)) . '.' . $extMap[$mime];
    $destPath = $dir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    $relPath = $subdir . '/' . $filename;

    $old = $pdo->prepare(
        'SELECT file_path FROM prodotti_immagini WHERE prodotto_id = :id AND posizione_immagine = 0'
    );
    $old->execute([':id' => $prodotto_id]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldPath) {
        $full = upload_abs($oldPath);
        if (is_file($full)) {
            @unlink($full);
        }
    }
    $pdo->prepare(
        'DELETE FROM prodotti_immagini WHERE prodotto_id = :id AND posizione_immagine = 0'
    )->execute([':id' => $prodotto_id]);

    $pdo->prepare(
        'INSERT INTO prodotti_immagini (prodotto_id, file_path, posizione_immagine) VALUES (:id, :p, 0)'
    )->execute([':id' => $prodotto_id, ':p' => $relPath]);

    return $relPath;
}

/**
 * Rimuove l'immagine di copertina di un prodotto (posizione_immagine = 0) e cancella il file da disco.
 */
function qk_rimuovi_immagine_prodotto(PDO $pdo, int $prodotto_id): void
{
    $old = $pdo->prepare(
        'SELECT file_path FROM prodotti_immagini WHERE prodotto_id = :id AND posizione_immagine = 0'
    );
    $old->execute([':id' => $prodotto_id]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldPath) {
        $full = upload_abs($oldPath);
        if (is_file($full)) {
            @unlink($full);
        }
    }
    $pdo->prepare(
        'DELETE FROM prodotti_immagini WHERE prodotto_id = :id AND posizione_immagine = 0'
    )->execute([':id' => $prodotto_id]);
}

/**
 * Restituisce le categorie come array piatto con indentazione per <select>.
 * @return array<int, array{id:int, nome:string, depth:int, label:string}>
 */
function qk_load_categorie(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, nome, parent_id, posizione_categoria FROM categorie_prodotto ORDER BY posizione_categoria, nome'
    )->fetchAll(PDO::FETCH_ASSOC);

    $byParent = [];
    foreach ($rows as $r) {
        $byParent[$r['parent_id'] ?? 0][] = $r;
    }

    $result = [];
    $walk = function (int $pid, int $depth) use (&$walk, $byParent, &$result): void {
        foreach ($byParent[$pid] ?? [] as $r) {
            $result[] = [
                'id'    => (int) $r['id'],
                'nome'  => $r['nome'],
                'depth' => $depth,
                'label' => str_repeat('— ', $depth) . $r['nome'],
            ];
            $walk((int) $r['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    return $result;
}

/** Restituisce categorie filtrabili per il picker (ricerca testuale). */
function qk_search_categorie(PDO $pdo, string $q = '', int $limit = 50): array
{
    if ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, nome FROM categorie_prodotto WHERE nome LIKE :q ORDER BY nome LIMIT :lim'
        );
        $stmt->execute([':q' => '%' . $q . '%', ':lim' => $limit]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, nome FROM categorie_prodotto ORDER BY nome LIMIT :lim'
        );
        $stmt->execute([':lim' => $limit]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<int, array{id:int, nome:string, codice_onu:string|null, classe_adr:string|null, gruppo_imballaggio:string|null}>
 */
function qk_load_adr_sostanze(PDO $pdo): array
{
    return $pdo->query(
        "SELECT s.id,
                s.nome,
                s.codice_cas,
                s.gruppo_imballaggio,
                c.codice_onu,
                c.classe_pericolo AS classe_adr
           FROM sostanze_chimiche s
      LEFT JOIN adr_codici_onu c ON c.id = s.codice_onu_id
          ORDER BY s.nome COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Carica i fornitori disponibili per il dropdown / picker. */
function qk_load_fornitori_disponibili(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, COALESCE(NULLIF(ragione_sociale, ''), nome || ' ' || cognome) AS ragione_sociale
           FROM clienti_fornitori
          WHERE tipo IN ('fornitore', 'entrambi') AND attivo = 1
            AND parent_id IS NULL
            AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
          ORDER BY ragione_sociale"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Ricerca fornitori per il picker Odoo-style. */
function qk_search_fornitori(PDO $pdo, string $q = '', int $limit = 50): array
{
    if ($q !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, COALESCE(NULLIF(ragione_sociale, ''), nome || ' ' || cognome) AS ragione_sociale, email, telefono
               FROM clienti_fornitori
              WHERE tipo IN ('fornitore', 'entrambi')
                AND attivo = 1
                AND parent_id IS NULL
                AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
                AND (ragione_sociale LIKE :q OR nome LIKE :q OR cognome LIKE :q OR email LIKE :q OR partita_iva LIKE :q)
              ORDER BY ragione_sociale
              LIMIT :lim"
        );
        $stmt->execute([':q' => '%' . $q . '%', ':lim' => $limit]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, COALESCE(NULLIF(ragione_sociale, ''), nome || ' ' || cognome) AS ragione_sociale, email, telefono
               FROM clienti_fornitori
              WHERE tipo IN ('fornitore', 'entrambi') 
                AND attivo = 1
                AND parent_id IS NULL
                AND id NOT IN (SELECT soggetto_id FROM aziende_interne)
              ORDER BY ragione_sociale
              LIMIT :lim"
        );
        $stmt->execute([':lim' => $limit]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Carica una singola riga prodotti_fornitori per ID (usata dopo insert/update AJAX). */
function qk_load_single_prodotto_fornitore(PDO $pdo, int $pf_id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT pf.id, pf.fornitore_id, cf.ragione_sociale AS nome_fornitore_rs,
                pf.sku_fornitore, pf.nome_prodotto_fornitore,
                um.codice AS unita_misura_codice,
                pf.quantita_minima, pf.ordine_preferenza_fornitore, pf.attivo,
                pf.rrp_min, pf.rrp_max, pf.lead_time_giorni, pf.note,
                (pf.ordine_preferenza_fornitore = 1) AS preferenziale,
                vf.prezzo AS prezzo_acquisto
           FROM prodotti_fornitori pf
           JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
      LEFT JOIN unita_misura um ON um.id = pf.unita_misura_acquisto_id
      LEFT JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
          WHERE pf.id = :id"
    );
    $stmt->execute([':id' => $pf_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Carica le righe prodotti_fornitori per un prodotto in modifica. */
function qk_load_prodotto_fornitori(PDO $pdo, int $prodotto_id): array
{
    $stmt = $pdo->prepare(
        "SELECT pf.id, pf.fornitore_id, cf.ragione_sociale AS nome_fornitore_rs,
                pf.sku_fornitore, pf.nome_prodotto_fornitore,
                um.codice AS unita_misura_codice, pf.unita_misura_acquisto_id,
                pf.quantita_minima, pf.ordine_preferenza_fornitore, pf.attivo,
                pf.lead_time_giorni, pf.note,
                (pf.ordine_preferenza_fornitore = 1) AS preferenziale,
                vf.prezzo AS prezzo_acquisto
           FROM prodotti_fornitori pf
           JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
      LEFT JOIN unita_misura um ON um.id = pf.unita_misura_acquisto_id
      LEFT JOIN v_prezzo_acquisto_base_fornitore vf ON vf.prodotto_fornitore_id = pf.id
          WHERE pf.prodotto_id = :id
          ORDER BY pf.ordine_preferenza_fornitore, cf.ragione_sociale"
    );
    $stmt->execute([':id' => $prodotto_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function qk_load_prodotto_rrp(PDO $pdo, int $prodotto_id): array
{
    $stmt = $pdo->prepare(
        "SELECT r.*, cf.ragione_sociale AS nome_fornitore_rs
           FROM prodotti_rrp r
      LEFT JOIN clienti_fornitori cf ON cf.id = r.fornitore_id
          WHERE r.prodotto_id = :id
          ORDER BY r.created_at ASC"
    );
    $stmt->execute([':id' => $prodotto_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Carica TUTTE le righe prodotti_fornitori_prezzi per un prodotto,
 * raggruppabili poi per prodotto_fornitore_id lato PHP o JS.
 *
 * Ordinamento: per ordine preferenza fornitore → pf_id → base/listino
 * (prezzi base IS NULL per listino vengono prima) → nome listino → qtà minima.
 */
function qk_load_prodotto_tutti_prezzi(PDO $pdo, int $prodotto_id): array
{
    $stmt = $pdo->prepare(
        "SELECT pfp.id,
                pfp.prodotto_fornitore_id,
                pfp.quantita_minima,
                pfp.prezzo,
                pfp.sconto_perc,
                pfp.valido_dal,
                pfp.valido_al,
                pfp.note,
                pfp.listino_acquisto_id,
                la.nome_listino
           FROM prodotti_fornitori_prezzi pfp
           JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
      LEFT JOIN listini_acquisto la ON la.id = pfp.listino_acquisto_id
          WHERE pf.prodotto_id = :pid
          ORDER BY pf.ordine_preferenza_fornitore,
                   pfp.prodotto_fornitore_id,
                   pfp.listino_acquisto_id IS NOT NULL,
                   la.nome_listino,
                   pfp.quantita_minima"
    );
    $stmt->execute([':pid' => $prodotto_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================================
   TAG
============================================================================= */

/** Carica tutti i tag disponibili. */
function qk_load_all_tags(PDO $pdo): array
{
    return $pdo->query('SELECT id, nome FROM tag ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
}

/** Carica gli ID dei tag associati a un prodotto. */
function qk_load_prodotto_tags(PDO $pdo, int $prodotto_id): array
{
    $stmt = $pdo->prepare(
        'SELECT tag_id FROM prodotti_tag WHERE prodotto_id = :id'
    );
    $stmt->execute([':id' => $prodotto_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'tag_id');
}

/** Sincronizza i tag di un prodotto (replace-all). */
function qk_sync_prodotto_tags(PDO $pdo, int $prodotto_id, array $tag_ids): void
{
    $pdo->prepare('DELETE FROM prodotti_tag WHERE prodotto_id = :id')
        ->execute([':id' => $prodotto_id]);

    if (!empty($tag_ids)) {
        $ins = $pdo->prepare(
            db_insert_ignore_sql('prodotti_tag (prodotto_id, tag_id) VALUES (:pid, :tid)')
        );
        foreach ($tag_ids as $tid) {
            $ins->execute([':pid' => $prodotto_id, ':tid' => (int) $tid]);
        }
    }
}

/** Crea un nuovo tag e restituisce l'ID. */
function qk_crea_tag(PDO $pdo, string $nome): int
{
    $pdo->prepare('INSERT INTO tag (nome) VALUES (:nome)')->execute([':nome' => $nome]);
    return (int) $pdo->lastInsertId();
}

/* =============================================================================
   LISTA
============================================================================= */

function qk_prodotti_list(PDO $pdo, ?string $search, ?string $cat, bool $adr_only, ?int $supplier_id = null, int $limit = 50, int $offset = 0, string $sort = 'nome', string $dir = 'asc'): array {
    $where  = [];
    $params = [];

    if ($search !== null && $search !== '') {
        $where[]          = "(p.nome LIKE :q OR p.sku_interno LIKE :q OR p.sku_ecommerce LIKE :q)";
        $params[':q']     = '%' . $search . '%';
    }
    if ($cat !== null && $cat !== '') {
        $where[]          = 'p.categoria_id = :cat';
        $params[':cat']   = (int) $cat;
    }
    if ($adr_only) {
        $where[]          = 'COALESCE(p.is_adr, 0) = 1';
    }

    if ($supplier_id !== null) {
        $where[] = 'pf.fornitore_id = :supplier';
        $params[':supplier'] = $supplier_id;
    }
    $cond = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Calculate total count first
    $joinCount = '';
    if ($supplier_id !== null) {
        $joinCount = 'LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.attivo = 1';
    }
    $sqlCount = "SELECT COUNT(DISTINCT p.id) FROM prodotti p $joinCount $cond";
    $stCount = $pdo->prepare($sqlCount);
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    // Unified query with joins to fetch main sales price (listino 1, qty 0)
    $sqlRows = "SELECT p.id, p.sku_interno, p.nome, p.tipo, um.codice AS unita_misura,
                p.attivo, c.nome AS categoria,
                CASE
                    WHEN p.tipo = 'kit' THEN (
                        -- Giacenza kit = MIN, tra i componenti, di (giacenza del componente
                        -- SOMMATA su tutte le sedi) / quantità richiesta — non un semplice
                        -- JOIN su giacenze (che senza SUM per-componente mescolerebbe righe
                        -- di sedi diverse nello stesso MIN, dando un risultato senza senso).
                        -- Stesso pattern già usato in _qk_stock_list_aggregato() di magazzino.php.
                        SELECT MIN(CAST(IFNULL(
                            (SELECT SUM(g3.quantita_disponibile) FROM giacenze g3 WHERE g3.prodotto_id = ck.componente_id)
                        , 0) / ck.quantita AS INT))
                        FROM componenti_kit ck
                        WHERE ck.kit_id = p.id
                    )
                    -- Prodotto semplice: giacenza aggregata su TUTTE le sedi (SUM), non la
                    -- riga di un magazzino preso a caso — bug storico corretto (Best Practice
                    -- UX-UI Multi-Magazzino nei Grandi ERP.md, §5, filo conduttore #2:
                    -- l'aggregato è sempre una somma calcolata al volo, mai un valore singolo).
                    ELSE SUM(g.quantita_disponibile)
                END AS quantita_disponibile,
                COALESCE(p.is_adr, 0) AS is_adr,
                lp.prezzo_unitario AS prezzo_vendita,
                p.descrizione_confezione,
                -- Costo di acquisto base: da v_costo_acquisto_prodotti (fix 'Listino
                -- Fantasma', audit/MASTER_AUDIT_REPORT.md — la vecchia sub-query
                -- sceglieva il prezzo con ORDER BY pfp.id DESC, cioè l'ultimo
                -- inserito, senza controllare se il suo listino fosse ancora
                -- attivo o nella finestra di validità).
                CASE WHEN p.tipo = 'kit' THEN (
                    SELECT SUM(ck.quantita * vc.costo_acquisto)
                    FROM componenti_kit ck
                    LEFT JOIN v_costo_acquisto_prodotti vc ON vc.prodotto_id = ck.componente_id
                    WHERE ck.kit_id = p.id
                ) ELSE (
                    SELECT vc.costo_acquisto FROM v_costo_acquisto_prodotti vc WHERE vc.prodotto_id = p.id
                ) END AS costo_acquisto
         FROM prodotti p
         LEFT JOIN categorie_prodotto c ON c.id = p.categoria_id
         LEFT JOIN giacenze g ON g.prodotto_id = p.id
         LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
         LEFT JOIN prodotti_fornitori pf ON pf.prodotto_id = p.id AND pf.attivo = 1
         LEFT JOIN listini_prezzi lp ON lp.prodotto_id = p.id AND lp.listino_id = (SELECT id FROM listini WHERE is_default = 1 LIMIT 1) AND lp.quantita_minima = 0
         $cond
         GROUP BY p.id
         ORDER BY " . match($sort) {
             'sku'       => 'p.sku_interno COLLATE NOCASE',
             'categoria' => 'c.nome COLLATE NOCASE',
             'giacenza'  => 'quantita_disponibile',
             'prezzo'    => 'prezzo_vendita',
             'tipo'      => 'p.tipo',
             default     => 'p.nome COLLATE NOCASE',
         } . " " . ($dir === 'desc' ? 'DESC' : 'ASC') . "
         LIMIT :limit OFFSET :offset";

    $stRows = $pdo->prepare($sqlRows);
    foreach ($params as $key => $val) {
        $stRows->bindValue($key, $val);
    }
    $stRows->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stRows->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stRows->execute();

    $data  = $stRows->fetchAll(PDO::FETCH_ASSOC);

    return ['rows' => $data, 'total' => $total];
}

/* =============================================================================
   CARICAMENTO FORM (edit)
============================================================================= */

function qk_load_prodotto_form(PDO $pdo, int $id): ?array
{
    // scorta_minima non è più un campo di `prodotti` (era un unico valore
    // azienda-wide, superato dal multi-magazzino, vedi Piano Multi-Magazzino
    // §3.5): qui si legge solo a titolo informativo la soglia impostata per
    // il magazzino attivo, così il form prodotto può mostrarla senza poterla
    // modificare direttamente — la modifica vera avviene nel contesto
    // magazzino (WarehouseController), dove ha senso scegliere la sede.
    $magazzinoAttivoId = qk_magazzino_attivo_id($pdo);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.sku_interno, p.sku_ecommerce, p.nome, p.descrizione_breve,
                p.descrizione_confezione, p.descrizione_completa, p.tipo, p.categoria_id,
                p.note_interne, p.attivo, p.traccia_lotti, um.codice AS unita_misura,
                p.peso_netto_kg, p.peso_lordo_kg, p.ean,
                p.unita_contenuto_id, p.quantita_contenuto,
                COALESCE(g.scorta_minima, 0) AS scorta_minima,
                COALESCE(p.is_adr, 0) AS is_adr, p.sostanza_chimica_id,
                p.markup_fallback_perc,
                p.created_at, p.updated_at
         FROM prodotti p
         LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
         LEFT JOIN giacenze g ON g.prodotto_id = p.id AND g.magazzino_id = :magazzino_id
         WHERE p.id = :id'
     );
     $stmt->execute([':id' => $id, ':magazzino_id' => $magazzinoAttivoId]);
     $row = $stmt->fetch(PDO::FETCH_ASSOC);
     if (!$row) {
         return null;
     }

     $stmtPrice = $pdo->prepare('SELECT prezzo_unitario FROM listini_prezzi WHERE listino_id = (SELECT id FROM listini WHERE is_default = 1 LIMIT 1) AND prodotto_id = ? AND quantita_minima = 0 LIMIT 1');
     $stmtPrice->execute([$id]);
     $prezzo_base = $stmtPrice->fetchColumn();

     // Giacenza totale su tutte le sedi (Best Practice UX-UI Multi-Magazzino
     // nei Grandi ERP.md, §5: "l'aggregato è una somma calcolata al volo").
     // Mostrata sulla scheda prodotto come contatore cliccabile verso il
     // dettaglio per sede — pattern Odoo ("In Stock"), stesso screen già
     // costruito per le soglie di riordino (warehouse/min-stock?prodotto_id=).
     $stmtGiac = $pdo->prepare('SELECT COALESCE(SUM(quantita_disponibile), 0) FROM giacenze WHERE prodotto_id = ?');
     $stmtGiac->execute([$id]);
     $quantita_disponibile_totale = (float) $stmtGiac->fetchColumn();

     return [
         'sku_interno'          => (string) ($row['sku_interno']         ?? ''),
         'sku_ecommerce'        => (string) ($row['sku_ecommerce']        ?? ''),
         'nome'                 => (string) ($row['nome']                 ?? ''),
         'descrizione_breve'    => (string) ($row['descrizione_breve']    ?? ''),
         'descrizione_confezione'=> (string) ($row['descrizione_confezione'] ?? ''),
         'descrizione_completa' => (string) ($row['descrizione_completa'] ?? ''),
         'tipo'                 => (string) ($row['tipo']                 ?? 'semplice'),
         'categoria_id'         => (string) ($row['categoria_id']         ?? ''),
         'note_interne'         => (string) ($row['note_interne']         ?? ''),
         'attivo'               => (string) ($row['attivo']               ?? '1'),
         'traccia_lotti'        => (string) ($row['traccia_lotti']        ?? '0'),
         'unita_misura'         => (string) ($row['unita_misura']         ?? 'pz'),
         'peso_netto_kg'        => (string) ($row['peso_netto_kg']        ?? ''),
         'peso_lordo_kg'        => (string) ($row['peso_lordo_kg']        ?? ''),
         'ean'                  => (string) ($row['ean']                  ?? ''),
         'unita_contenuto_id'   => (string) ($row['unita_contenuto_id']   ?? ''),
         'quantita_contenuto'   => (string) ($row['quantita_contenuto']   ?? ''),
         'scorta_minima'        => (string) ($row['scorta_minima']        ?? '0'),
         'quantita_disponibile_totale' => $quantita_disponibile_totale,
         'is_adr'               => (string) ($row['is_adr']               ?? '0'),
         'sostanza_chimica_id'      => (string) ($row['sostanza_chimica_id']      ?? ''),
         'markup_fallback_perc' => $row['markup_fallback_perc'] !== null ? (string) $row['markup_fallback_perc'] : '',
         'prezzo_vendita_base'  => $prezzo_base !== false ? (string)$prezzo_base : '',
         '_created_at'          => $row['created_at'],
         '_updated_at'          => $row['updated_at'],
     ];
}

/* =============================================================================
   INSERT
   =============================================================================
   DIRETTIVE ARCHITETTURALI MULTI-UoM & PREZZI (MANTENERE ASSOLUTAMENTE INTEGRE):
   1. La Stock UoM (unita_misura_stock_id) definisce l'unità fisica logistica a magazzino.
   2. Il fattore di conversione commerciale/stock deve essere gestito come numeratore/denominatore 
      a database (num_factor e den_factor) per evitare discrepanze float con frazioni periodiche (es. 1/3).
   3. Calcolo frazione: Il decimale inserito a schermo (es. 25 o 0.468) viene convertito in frazione
      deterministicamente per potenze di 10 (es. 0.468 -> 468/1000) per preservare l'esatto input utente.
============================================================================= */

function qk_insert_prodotto(PDO $pdo, array $v): int
{
    $pdo->beginTransaction();
    try {
        $umStmt = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = :c');
        $umStmt->execute([':c' => $v['unita_misura'] ?? 'pz']);
        $um_id = $umStmt->fetchColumn() ?: null;

        // Calcolo numeratore e denominatore deterministici (frazione esatta ridotta ai minimi
        // termini). BUG CORRETTO (14/07/2026, audit a tappeto multi-UoM): il ramo "$fVal >= 1.0"
        // faceva `$denFactor = (int)$fVal`, troncando silenziosamente la parte decimale per
        // qualunque quantita_contenuto non intero >= 1 (es. 12.5 diventava 12).
        //
        // BUG PIÙ GRAVE CORRETTO SUBITO DOPO (14/07/2026, verifica numerica con interprete PHP
        // reale): il fix sopra, unificando i due rami, aveva invertito la CONVENZIONE del
        // rapporto num_factor/den_factor per qualunque valore, non solo per i decimali —
        // rompendo il prezzo convertito (system_unit_price_stock) per la stragrande
        // maggioranza dei prodotti reali (qualunque quantita_contenuto intero, es. 25).
        // qk_uom_converti_riga() usa `inverseRate = den_factor/num_factor` come moltiplicatore
        // del prezzo, e questo DEVE essere uguale a quantita_contenuto stesso (qc) — quindi
        // num_factor/den_factor deve rappresentare 1/qc, non qc. Calcoliamo qc come frazione
        // esatta aVal/bVal (via stringa+GCD, gestisce sia interi che decimali in un colpo solo),
        // poi INVERTIAMO (num_factor=bVal, den_factor=aVal) per ottenere 1/qc.
        // Verificato con un interprete PHP reale (bcmath) su 9 casi (interi, decimali, <1, >1).
        $rawQty = qk_prodotti_decimal($v['quantita_contenuto']);
        $numFactor = 1;
        $denFactor = 1;
        if ($rawQty !== null && (float)$rawQty > 0.0) {
            $fVal = (float)$rawQty;
            $strVal = (string)$fVal;
            $decimals = 0;
            if (strpos($strVal, '.') !== false) {
                $decimals = strlen(substr($strVal, strpos($strVal, '.') + 1));
            }
            $multiplier = 10 ** $decimals;
            $aVal = (int)round($fVal * $multiplier); // fVal = aVal/bVal
            $bVal = $multiplier;

            $gcd = function($a, $b) use (&$gcd) {
                return $b ? $gcd($b, $a % $b) : $a;
            };
            $g = (int)$gcd($aVal, $bVal);
            if ($g > 0) {
                $aVal = (int)($aVal / $g);
                $bVal = (int)($bVal / $g);
            }
            // Inversione: num_factor/den_factor deve essere 1/fVal, non fVal.
            $numFactor = $bVal;
            $denFactor = $aVal;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO prodotti
               (sku_interno, sku_ecommerce, nome, descrizione_breve, descrizione_confezione, descrizione_completa,
                tipo, categoria_id, note_interne,
                attivo, traccia_lotti, unita_misura_stock_id, unita_contenuto_id, quantita_contenuto,
                num_factor, den_factor, peso_netto_kg, peso_lordo_kg,
                ean, is_adr, sostanza_chimica_id, markup_fallback_perc, created_at)
             VALUES
               (:sku_interno, :sku_ecommerce, :nome, :desc_breve, :desc_confezione, :desc_completa,
                :tipo, :cat_id, :note_int,
                :attivo, :traccia_lotti, :um_id, :unita_contenuto_id, :quantita_contenuto,
                :num_factor, :den_factor, :peso_netto, :peso_lordo,
                :ean, :is_adr, :sostanza_chimica_id, :markup_fallback_perc, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':sku_interno'       => trim($v['sku_interno']),
            ':sku_ecommerce'     => qk_prodotti_null($v['sku_ecommerce']),
            ':nome'              => trim($v['nome']),
            ':desc_breve'        => qk_prodotti_null($v['descrizione_breve']),
            ':desc_confezione'   => qk_prodotti_null($v['descrizione_confezione']),
            ':desc_completa'     => qk_prodotti_null($v['descrizione_completa']),
            ':tipo'              => $v['tipo'],
            ':cat_id'            => qk_prodotti_null($v['categoria_id']) !== null ? (int) $v['categoria_id'] : null,
            ':note_int'          => qk_prodotti_null($v['note_interne']),
            ':attivo'            => $v['attivo'] === '1' ? 1 : 0,
            ':traccia_lotti'     => $v['traccia_lotti'] === '1' ? 1 : 0,
            ':um_id'             => $um_id,
            ':unita_contenuto_id'=> qk_prodotti_null($v['unita_contenuto_id']) !== null ? (int) $v['unita_contenuto_id'] : null,
            ':quantita_contenuto'=> $rawQty,
            ':num_factor'        => $numFactor,
            ':den_factor'        => $denFactor,
            ':peso_netto'        => qk_prodotti_decimal($v['peso_netto_kg']),
            ':peso_lordo'        => qk_prodotti_decimal($v['peso_lordo_kg']),
            ':ean'               => qk_prodotti_null($v['ean']),
            ':is_adr'            => ($v['is_adr'] ?? '0') === '1' ? 1 : 0,
            ':sostanza_chimica_id'   => qk_prodotti_null($v['sostanza_chimica_id'] ?? '') !== null ? (int) $v['sostanza_chimica_id'] : null,
            ':markup_fallback_perc'  => qk_prodotti_decimal($v['markup_fallback_perc'] ?? null),
        ]);

        $id = (int) $pdo->lastInsertId();

        // Crea la riga di giacenza per il nuovo prodotto nel magazzino attivo,
        // a quantità zero. La soglia di riordino (scorta_minima) NON si imposta
        // più qui: è per coppia (prodotto, magazzino) e va configurata a parte
        // nel contesto magazzino (WarehouseController), dove ha senso scegliere
        // per quale sede — un prodotto appena creato può non avere ancora
        // bisogno di una soglia in ogni magazzino esistente. Vedi Piano
        // Multi-Magazzino §3.5.
        if ($v['tipo'] === 'semplice') {
            $pdo->prepare(
                'INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
                 VALUES (:id, :magazzino_id, 0, 0, 0, 0, CURRENT_TIMESTAMP)'
            )->execute([
                ':id'           => $id,
                ':magazzino_id' => qk_magazzino_attivo_id($pdo),
            ]);
        }

        $prezzo_base = isset($v['prezzo_vendita_base']) && $v['prezzo_vendita_base'] !== '' ? (float)str_replace(',', '.', (string)$v['prezzo_vendita_base']) : null;
        if ($prezzo_base !== null) {
            // D3.2 (PIANO Motore di Pricing Multi-UoM §8): quantita_minima_stock deve essere
            // sempre valorizzato (0 = prezzo base) — altrimenti invisibile a getSalePrice().
            $pdo->prepare('INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock) VALUES ((SELECT id FROM listini WHERE is_default = 1 LIMIT 1), ?, 0, ?, ?, 0, ?, 0)')
                ->execute([$id, $prezzo_base, $um_id, $um_id]);
        }

        $pdo->commit();
        return $id;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* =============================================================================
   UPDATE
   =============================================================================
   DIRETTIVE ARCHITETTURALI MULTI-UoM & PREZZI (MANTENERE ASSOLUTAMENTE INTEGRE):
   1. La Stock UoM (unita_misura_stock_id) definisce l'unità fisica logistica a magazzino.
   2. SE sono già avvenuti movimenti fisici (presenza in movimenti_magazzino), il cambio della
      Stock UoM è severamente vietato e bloccato in backend per prevenire corruzione dello storico.
   3. Il fattore di conversione commerciale/stock deve essere gestito come numeratore/denominatore 
      a database (num_factor e den_factor) per evitare discrepanze float con frazioni periodiche (es. 1/3).
   4. Calcolo frazione: Il decimale inserito a schermo (es. 25 o 0.468) viene convertito in frazione
      deterministicamente per potenze di 10 (es. 0.468 -> 468/1000) per preservare l'esatto input utente.
============================================================================= */

/**
 * @return array{preventivi: int, rfq: int, ordini_acquisto: int, prezzi_vendita: int, prezzi_acquisto: int}
 *         Conteggio righe ricalcolate per cambio Stock UoM (tutti 0 se la Stock
 *         UoM non è cambiata in questo salvataggio).
 */
function qk_update_prodotto(PDO $pdo, int $id, array $v): array
{
    $umStmt = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = :c');
    $umStmt->execute([':c' => $v['unita_misura'] ?? 'pz']);
    $um_id = $umStmt->fetchColumn() ?: null;

    // Recupera la Stock UoM e la UoM commerciale attuali per verificare se
    // l'utente sta davvero cambiando la Stock UoM (REVISIONE §6.3 Problema 2).
    $stCurrent = $pdo->prepare('SELECT unita_misura_stock_id, unita_contenuto_id FROM prodotti WHERE id = ?');
    $stCurrent->execute([$id]);
    $currentRow = $stCurrent->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentUmId = $currentRow['unita_misura_stock_id'] ?? null;
    $currentContenutoId = $currentRow['unita_contenuto_id'] ?? null;
    $stockUomCambiata = false;

    if ($currentUmId && (int)$currentUmId !== (int)$um_id) {
        $stockUomCambiata = true;

        // Verifica se ci sono già stati movimenti fisici a magazzino per questo prodotto
        $stMov = $pdo->prepare('SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id = ?');
        $stMov->execute([$id]);
        $hasMovements = (int)$stMov->fetchColumn() > 0;

        if ($hasMovements) {
            throw new \RuntimeException(
                "Impossibile modificare l'unità di misura di stock: sono già presenti movimenti fisici di magazzino per questo prodotto nel sistema logistico."
            );
        }

        // Guardia (REVISIONE §6.3 Problema 2, decisione 10/07/2026): se il prodotto
        // ha già una UoM commerciale, il nuovo fattore di conversione è obbligatorio
        // quando cambia la Stock UoM — senza un fattore valido non si potrebbero
        // ricalcolare correttamente le bozze collegate (vedi ricalcolo sotto).
        if ($currentContenutoId) {
            $nuovaQuantitaContenuto = qk_prodotti_decimal($v['quantita_contenuto'] ?? null);
            if ($nuovaQuantitaContenuto === null || (float)$nuovaQuantitaContenuto <= 0.0) {
                throw new \RuntimeException(
                    "Devi specificare il nuovo fattore di conversione (\"Quantità per unità di stock\") " .
                    "perché stai cambiando l'unità di misura di stock di un prodotto che ha già " .
                    "un'unità commerciale configurata. Il vecchio fattore non è valido per la nuova unità di stock."
                );
            }
        }
    }

    // Calcolo numeratore e denominatore deterministici (frazione esatta ridotta ai minimi
    // termini). BUG CORRETTO (14/07/2026, secondo audit a tappeto): questo secondo punto
    // duplicato del calcolo (qk_update_prodotto) era sfuggito al primo fix — il ramo
    // "$fVal >= 1.0" troncava ancora silenziosamente la parte decimale (es. 12.5 -> 12).
    //
    // BUG PIÙ GRAVE CORRETTO SUBITO DOPO (14/07/2026, verifica numerica con interprete PHP
    // reale): unificare i due rami aveva invertito la CONVENZIONE del rapporto
    // num_factor/den_factor per qualunque valore (non solo i decimali), rompendo il prezzo
    // convertito per la maggioranza dei prodotti reali. num_factor/den_factor deve
    // rappresentare 1/quantita_contenuto (non quantita_contenuto stesso), perché
    // qk_uom_converti_riga() usa den_factor/num_factor come moltiplicatore del prezzo.
    // Calcoliamo qc come frazione esatta aVal/bVal, poi INVERTIAMO. Verificato con un
    // interprete PHP reale (bcmath) su 9 casi (interi, decimali, <1, >1).
    $rawQty = qk_prodotti_decimal($v['quantita_contenuto']);
    $numFactor = 1;
    $denFactor = 1;
    if ($rawQty !== null && (float)$rawQty > 0.0) {
        $fVal = (float)$rawQty;
        $strVal = (string)$fVal;
        $decimals = 0;
        if (strpos($strVal, '.') !== false) {
            $decimals = strlen(substr($strVal, strpos($strVal, '.') + 1));
        }
        $multiplier = 10 ** $decimals;
        $aVal = (int)round($fVal * $multiplier); // fVal = aVal/bVal
        $bVal = $multiplier;

        $gcd = function($a, $b) use (&$gcd) {
            return $b ? $gcd($b, $a % $b) : $a;
        };
        $g = (int)$gcd($aVal, $bVal);
        if ($g > 0) {
            $aVal = (int)($aVal / $g);
            $bVal = (int)($bVal / $g);
        }
        // Inversione: num_factor/den_factor deve essere 1/fVal, non fVal.
        $numFactor = $bVal;
        $denFactor = $aVal;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE prodotti SET
               sku_interno            = :sku_interno,
               sku_ecommerce          = :sku_ecommerce,
               nome                   = :nome,
               descrizione_breve      = :desc_breve,
               descrizione_confezione = :desc_confezione,
               descrizione_completa   = :desc_completa,
               tipo                   = :tipo,
               categoria_id           = :cat_id,
               note_interne           = :note_int,
               attivo                 = :attivo,
               traccia_lotti          = :traccia_lotti,
               unita_misura_stock_id  = :um_id,
               unita_contenuto_id     = :unita_contenuto_id,
               quantita_contenuto     = :quantita_contenuto,
               num_factor             = :num_factor,
               den_factor             = :den_factor,
               peso_netto_kg          = :peso_netto,
               peso_lordo_kg          = :peso_lordo,
               ean                    = :ean,
               is_adr                 = :is_adr,
               sostanza_chimica_id        = :sostanza_chimica_id,
               markup_fallback_perc   = :markup_fallback_perc,
               updated_at             = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([
            ':sku_interno'       => trim($v['sku_interno']),
            ':sku_ecommerce'     => qk_prodotti_null($v['sku_ecommerce']),
            ':nome'              => trim($v['nome']),
            ':desc_breve'        => qk_prodotti_null($v['descrizione_breve']),
            ':desc_confezione'   => qk_prodotti_null($v['descrizione_confezione']),
            ':desc_completa'     => qk_prodotti_null($v['descrizione_completa']),
            ':tipo'              => $v['tipo'],
            ':cat_id'            => qk_prodotti_null($v['categoria_id']) !== null ? (int) $v['categoria_id'] : null,
            ':note_int'          => qk_prodotti_null($v['note_interne']),
            ':attivo'            => $v['attivo'] === '1' ? 1 : 0,
            ':traccia_lotti'     => $v['traccia_lotti'] === '1' ? 1 : 0,
            ':um_id'             => $um_id,
            ':unita_contenuto_id'=> qk_prodotti_null($v['unita_contenuto_id']) !== null ? (int) $v['unita_contenuto_id'] : null,
            ':quantita_contenuto'=> $rawQty,
            ':num_factor'        => $numFactor,
            ':den_factor'        => $denFactor,
            ':peso_netto'        => qk_prodotti_decimal($v['peso_netto_kg']),
            ':peso_lordo'        => qk_prodotti_decimal($v['peso_lordo_kg']),
            ':ean'               => qk_prodotti_null($v['ean']),
            ':is_adr'            => ($v['is_adr'] ?? '0') === '1' ? 1 : 0,
            ':sostanza_chimica_id' => qk_prodotti_null($v['sostanza_chimica_id'] ?? '') !== null ? (int) $v['sostanza_chimica_id'] : null,
            ':markup_fallback_perc' => qk_prodotti_decimal($v['markup_fallback_perc'] ?? null),
            ':id'                => $id,
        ]);

        // NOTA: qui c'era una sincronizzazione di scorta_minima verso
        // `giacenze`, rimossa. `giacenze.scorta_minima` è ora per coppia
        // (prodotto, magazzino) e va impostata dall'operatore nel contesto
        // magazzino (WarehouseController), non copiata da un valore unico di
        // prodotto — vedi Piano Multi-Magazzino §3.5. Il form prodotto non
        // scrive più questo campo.

        $prezzo_base = isset($v['prezzo_vendita_base']) && $v['prezzo_vendita_base'] !== '' ? (float)str_replace(',', '.', (string)$v['prezzo_vendita_base']) : null;
        if ($prezzo_base !== null) {
            $stmtCheckRule = $pdo->prepare('SELECT id FROM listini_prezzi WHERE listino_id = (SELECT id FROM listini WHERE is_default = 1 LIMIT 1) AND prodotto_id = ? AND quantita_minima = 0 LIMIT 1');
            $stmtCheckRule->execute([$id]);
            $ruleId = $stmtCheckRule->fetchColumn();
            if ($ruleId) {
                // D3.2: valorizza/ripara anche quantita_minima_stock qui, così eventuali righe
                // create prima di questo fix (con quantita_minima_stock NULL) vengono corrette
                // al primo salvataggio successivo del prodotto.
                $pdo->prepare('UPDATE listini_prezzi SET prezzo_unitario = ?, unita_misura_id = ?, unita_misura_soglia_id = ?, quantita_minima_stock = 0 WHERE id = ?')
                    ->execute([$prezzo_base, $um_id, $um_id, $ruleId]);
            } else {
                $pdo->prepare('INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, quantita_minima_intento, unita_misura_soglia_id, quantita_minima_stock) VALUES ((SELECT id FROM listini WHERE is_default = 1 LIMIT 1), ?, 0, ?, ?, 0, ?, 0)')
                    ->execute([$id, $prezzo_base, $um_id, $um_id]);
            }
        }

        // REVISIONE §6.3 Problema 2 / §6.16 (14/07/2026): se la Stock UoM è
        // davvero cambiata (permesso solo se non ci sono movimenti, verificato
        // sopra), ricalcola tutte le bozze collegate e il valore canonico
        // quantita_minima_stock sui prezzi — nella stessa transazione, così il
        // prodotto non risulta mai aggiornato con le bozze rimaste stale.
        $ricalcoloBozze = ['preventivi' => 0, 'rfq' => 0, 'ordini_acquisto' => 0, 'prezzi_vendita' => 0, 'prezzi_acquisto' => 0];
        if ($stockUomCambiata) {
            $ricalcoloBozze = qk_ricalcola_bozze_per_cambio_stock_uom($pdo, $id);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $ricalcoloBozze;
}

/**
 * Ricalcola lo snapshot UoM/prezzo di tutte le righe in stato "bozza" che
 * referenziano $prodotto_id, dopo un cambio di Stock UoM (o del fattore di
 * conversione) sul prodotto. Va chiamata DOPO che il nuovo
 * unita_misura_stock_id/quantita_contenuto/num_factor/den_factor sono già
 * stati scritti su `prodotti`, nella stessa transazione (vedi qk_update_prodotto()).
 *
 * Copre: preventivi in bozza, RFQ in bozza, ordini d'acquisto in bozza (mai gli
 * ordini cliente, che non hanno un editor di riga in bozza — vedi REVISIONE §6.2),
 * e il valore canonico quantita_minima_stock sui prezzi di vendita/acquisto
 * (PIANO Motore di Pricing Multi-UoM §7.1/§7.2/§8, dipendenza aggiunta il 14/07/2026).
 *
 * @return array{preventivi: int, rfq: int, ordini_acquisto: int, prezzi_vendita: int, prezzi_acquisto: int}
 */
function qk_ricalcola_bozze_per_cambio_stock_uom(PDO $pdo, int $prodotto_id): array
{
    $risultato = ['preventivi' => 0, 'rfq' => 0, 'ordini_acquisto' => 0, 'prezzi_vendita' => 0, 'prezzi_acquisto' => 0];

    // --- Preventivi in bozza ---
    if (!function_exists('qk_calcola_riga_offerta')) {
        require_once __DIR__ . '/sales_quotes.php';
    }
    $st = $pdo->prepare("
        SELECT pr.* FROM preventivi_righe pr
        JOIN preventivi p ON p.id = pr.preventivo_id
        WHERE pr.prodotto_id = ? AND p.stato = 'bozza'
    ");
    $st->execute([$prodotto_id]);
    $umCodiceStmt = $pdo->prepare('SELECT codice FROM unita_misura WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        // Risolvere il codice UoM dalla riga (serve a qk_calcola_riga_offerta come 'unita_misura')
        $umCodiceStmt->execute([$riga['unita_misura_id']]);
        $riga['unita_misura'] = $umCodiceStmt->fetchColumn() ?: 'pz';

        $ricalcolata = qk_calcola_riga_offerta($pdo, $riga, (int)$riga['posizione_riga']);

        $pdo->prepare("
            UPDATE preventivi_righe SET
                quantita_stock = ?, system_unit_price_stock = ?, unita_misura_stock_id = ?,
                quantita_contenuto_snapshot = ?, num_factor_snapshot = ?, den_factor_snapshot = ?,
                totale_costo_riga = ?, margine_riga = ?
            WHERE id = ?
        ")->execute([
            $ricalcolata['quantita_stock'], $ricalcolata['system_unit_price_stock'], $ricalcolata['unita_misura_stock_id'],
            $ricalcolata['quantita_contenuto_snapshot'], $ricalcolata['num_factor_snapshot'], $ricalcolata['den_factor_snapshot'],
            $ricalcolata['totale_costo_riga'], $ricalcolata['margine_riga'],
            $riga['id'],
        ]);
        $risultato['preventivi']++;
    }

    // --- RFQ in bozza (riusa qk_uom_converti_riga(), stessa funzione autorevole) ---
    $st = $pdo->prepare("
        SELECT rr.* FROM rfq_righe rr
        JOIN rfq_testata rt ON rt.id = rr.rfq_id
        WHERE rr.prodotto_id = ? AND rt.stato = 'bozza'
    ");
    $st->execute([$prodotto_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        $qty   = (float)$riga['quantita_richiesta'];
        $price = (float)($riga['prezzo_indicativo'] ?? 0.0);
        $umId  = $riga['unita_misura_id'] ? (int)$riga['unita_misura_id'] : null;

        $uomResult = qk_uom_converti_riga($pdo, $prodotto_id, $umId, $qty, $price);

        $pdo->prepare("
            UPDATE rfq_righe SET
                quantita_stock = ?, system_unit_price_stock = ?, unita_misura_stock_id = ?,
                quantita_contenuto_snapshot = ?, num_factor_snapshot = ?, den_factor_snapshot = ?
            WHERE id = ?
        ")->execute([
            $uomResult['quantita_stock'], $uomResult['system_unit_price_stock'], $uomResult['unita_misura_stock_id'],
            $uomResult['quantita_contenuto_snapshot'], $uomResult['num_factor_snapshot'], $uomResult['den_factor_snapshot'],
            $riga['id'],
        ]);
        $risultato['rfq']++;
    }

    // --- Ordini d'acquisto in bozza (stessa logica; MAI su ordini già inviati:
    //     quelli si toccano solo tramite "nuova revisione", che preserva lo
    //     snapshot originale per principio di immutabilità — vedi REVISIONE §6.2) ---
    $st = $pdo->prepare("
        SELECT orr.* FROM ordini_righe orr
        JOIN ordini_testata ot ON ot.id = orr.ordine_id
        WHERE orr.prodotto_id = ? AND ot.stato = 'bozza'
    ");
    $st->execute([$prodotto_id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        $qty   = (float)$riga['quantita_ordinata'];
        $price = (float)($riga['prezzo_unitario'] ?? 0.0);
        $umId  = $riga['unita_misura_id'] ? (int)$riga['unita_misura_id'] : null;

        $uomResult = qk_uom_converti_riga($pdo, $prodotto_id, $umId, $qty, $price);

        $pdo->prepare("
            UPDATE ordini_righe SET
                quantita_stock = ?, system_unit_price_stock = ?, unita_misura_stock_id = ?,
                quantita_contenuto_snapshot = ?, num_factor_snapshot = ?, den_factor_snapshot = ?
            WHERE id = ?
        ")->execute([
            $uomResult['quantita_stock'], $uomResult['system_unit_price_stock'], $uomResult['unita_misura_stock_id'],
            $uomResult['quantita_contenuto_snapshot'], $uomResult['num_factor_snapshot'], $uomResult['den_factor_snapshot'],
            $riga['id'],
        ]);
        $risultato['ordini_acquisto']++;
    }

    // --- quantita_minima_stock su listini_prezzi (vendita) ---
    // (nota: uso 0.0 come prezzo, qui non ci interessa il prezzo ricalcolato,
    // solo la quantita canonica -- stesso pattern gia usato in
    // ProductsController::savePrezzo() per il calcolo iniziale)
    $st = $pdo->prepare('SELECT id, quantita_minima_intento, unita_misura_soglia_id FROM listini_prezzi WHERE prodotto_id = ?');
    $st->execute([$prodotto_id]);
    $updLp = $pdo->prepare('UPDATE listini_prezzi SET quantita_minima_stock = ? WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        $qtaIntento = (float)($riga['quantita_minima_intento'] ?? 0.0);
        $umSogliaId = $riga['unita_misura_soglia_id'] ? (int)$riga['unita_misura_soglia_id'] : null;
        $qtaStock = $qtaIntento > 0
            ? (float) qk_uom_converti_riga($pdo, $prodotto_id, $umSogliaId, $qtaIntento, 0.0)['quantita_stock']
            : 0.0;
        $updLp->execute([$qtaStock, $riga['id']]);
        $risultato['prezzi_vendita']++;
    }

    // --- quantita_minima_stock su prodotti_fornitori_prezzi (acquisto) ---
    $st = $pdo->prepare('
        SELECT pfp.id, pfp.quantita_minima_intento, pfp.unita_misura_soglia_id
        FROM prodotti_fornitori_prezzi pfp
        JOIN prodotti_fornitori pf ON pf.id = pfp.prodotto_fornitore_id
        WHERE pf.prodotto_id = ?
    ');
    $st->execute([$prodotto_id]);
    $updPfp = $pdo->prepare('UPDATE prodotti_fornitori_prezzi SET quantita_minima_stock = ? WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $riga) {
        $qtaIntento = (float)($riga['quantita_minima_intento'] ?? 0.0);
        $umSogliaId = $riga['unita_misura_soglia_id'] ? (int)$riga['unita_misura_soglia_id'] : null;
        $qtaStock = $qtaIntento > 0
            ? (float) qk_uom_converti_riga($pdo, $prodotto_id, $umSogliaId, $qtaIntento, 0.0)['quantita_stock']
            : 0.0;
        $updPfp->execute([$qtaStock, $riga['id']]);
        $risultato['prezzi_acquisto']++;
    }

    return $risultato;
}

/* =============================================================================
   ERRORI DB → MESSAGGIO UTENTE
============================================================================= */

function qk_prodotti_db_message(Throwable $e): string
{
    $msg = $e->getMessage();
    if (str_contains($msg, 'UNIQUE') && str_contains($msg, 'sku_interno')) {
        return 'SKU interno già esistente. Scegli un codice diverso.';
    }
    if (str_contains($msg, 'UNIQUE') && str_contains($msg, 'sku_ecommerce')) {
        return 'SKU e-commerce già esistente. Scegli un codice diverso.';
    }
    if (str_contains($msg, 'UNIQUE') && str_contains($msg, 'ean')) {
        return 'Codice EAN già associato a un altro prodotto.';
    }
    return 'Errore durante il salvataggio. Verifica i dati e riprova.';
}

/* =============================================================================
   PRODOTTI — Ricerca generica (per kit component picker)
============================================================================= */

/** Ricerca prodotti per nome/SKU (picker kit). Esclude opzionalmente un ID. */
function qk_search_prodotti(PDO $pdo, string $q = '', int $limit = 50, ?int $exclude_id = null): array
{
    $excl = $exclude_id ? 'AND p.id != :exclude_id' : '';
    if ($q !== '') {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.nome, p.sku_interno, p.tipo
               FROM prodotti p
              WHERE p.attivo = 1 {$excl}
                AND (p.nome LIKE :q OR p.sku_interno LIKE :q)
              ORDER BY p.nome
              LIMIT :lim"
        );
        $params = [':q' => '%' . $q . '%', ':lim' => $limit];
    } else {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.nome, p.sku_interno, p.tipo
               FROM prodotti p
              WHERE p.attivo = 1 {$excl}
              ORDER BY p.nome
              LIMIT :lim"
        );
        $params = [':lim' => $limit];
    }
    if ($exclude_id) {
        $params[':exclude_id'] = $exclude_id;
    }
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =============================================================================
   KIT — Componenti
============================================================================= */

/** Carica i componenti di un kit. */
function qk_load_kit_componenti(PDO $pdo, int $kit_id): array
{
    $stmt = $pdo->prepare(
        "SELECT ck.componente_id, p.nome, p.sku_interno, ck.quantita, ck.note,
                p.peso_netto_kg
           FROM componenti_kit ck
           JOIN prodotti p ON p.id = ck.componente_id
          WHERE ck.kit_id = :kit_id
          ORDER BY p.nome"
    );
    $stmt->execute([':kit_id' => $kit_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Restituisce il fattore di conversione quantita_contenuto per un determinato prodotto.
 * Ritorna 1.0 se non impostato, non valido o se il prodotto non esiste.
 */
function qk_prodotto_get_conversion_factor(PDO $pdo, int $prodotto_id): float
{
    $stmt = $pdo->prepare('SELECT quantita_contenuto FROM prodotti WHERE id = ?');
    $stmt->execute([$prodotto_id]);
    $factor = $stmt->fetchColumn();
    return $factor ? (float)$factor : 1.0;
}

/**
 * Restituisce le informazioni sulle unità di misura e i fattori di conversione (frazioni incluse) per un prodotto.
 * Ritorna null se il prodotto non esiste.
 */
function qk_prodotto_get_uom_info(PDO $pdo, int $prodotto_id): ?array
{
    $stmt = $pdo->prepare('
        SELECT unita_misura_stock_id, 
               unita_contenuto_id, 
               quantita_contenuto, 
               num_factor, 
               den_factor 
          FROM prodotti 
         WHERE id = ?
    ');
    $stmt->execute([$prodotto_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$res) {
        return null;
    }
    return [
        'unita_misura_stock_id' => (int)$res['unita_misura_stock_id'],
        'unita_contenuto_id' => $res['unita_contenuto_id'] ? (int)$res['unita_contenuto_id'] : null,
        'quantita_contenuto' => $res['quantita_contenuto'] !== null ? (float)$res['quantita_contenuto'] : 1.0,
        'num_factor' => isset($res['num_factor']) ? (int)$res['num_factor'] : 1,
        'den_factor' => isset($res['den_factor']) ? (int)$res['den_factor'] : 1,
    ];
}

/**
 * Converte quantità e prezzo unitario dalla UoM della riga alla UoM stock del prodotto.
 *
 * Usa BCMath con frazioni intere (num/den) per evitare deriva floating-point.
 * Chiamare da ogni punto in cui si salvano righe transazionali
 * (preventivi, ordini vendita, ordini acquisto, RFQ).
 *
 * @param PDO      $pdo         Connessione DB
 * @param int|null $prodotto_id ID prodotto (null → nessuna conversione)
 * @param int|null $um_id       UoM della riga (stock o commerciale)
 * @param float    $qta         Quantità nella UoM di riga
 * @param float    $pu          Prezzo unitario nella UoM di riga (0 se non disponibile)
 *
 * @return array{
 *   quantita_stock: float,
 *   system_unit_price_stock: float,
 *   unita_misura_stock_id: int|null,
 *   quantita_contenuto_snapshot: float,
 *   num_factor_snapshot: int,
 *   den_factor_snapshot: int,
 * }
 */
function qk_uom_converti_riga(PDO $pdo, ?int $prodotto_id, ?int $um_id, float $qta, float $pu): array
{
    $result = [
        'quantita_stock'              => $qta,
        'system_unit_price_stock'     => $pu,
        'unita_misura_stock_id'       => $um_id,
        'quantita_contenuto_snapshot' => 1.0,
        'num_factor_snapshot'         => 1,
        'den_factor_snapshot'         => 1,
    ];

    if (!$prodotto_id || !$um_id) {
        return $result;
    }

    $prodUm = qk_prodotto_get_uom_info($pdo, $prodotto_id);
    if (!$prodUm) {
        return $result;
    }

    $result['unita_misura_stock_id']       = $prodUm['unita_misura_stock_id'];
    $result['quantita_contenuto_snapshot'] = $prodUm['quantita_contenuto'];
    $result['num_factor_snapshot']         = $prodUm['num_factor'];
    $result['den_factor_snapshot']         = $prodUm['den_factor'];

    $content_um_id = $prodUm['unita_contenuto_id'];
    $qc            = $prodUm['quantita_contenuto'];

    if ((int)$um_id === $prodUm['unita_misura_stock_id']) {
        // Già in UoM stock — nessuna conversione necessaria.
    } elseif ($content_um_id !== null && (int)$um_id === $content_um_id && $qc > 0) {
        // Q_stock = Q_comm / F_conv
        $result['quantita_stock'] = (float)bcdiv((string)$qta, (string)$qc, 3);
        // P_stock = P_comm × (den / num)
        $inverseRate = bcdiv((string)$prodUm['den_factor'], (string)$prodUm['num_factor'], 9);
        $result['system_unit_price_stock'] = (float)bcmul((string)$pu, $inverseRate, 4);
    }
    // Se um_id non corrisponde né a stock né a commerciale: lascia i valori di default
    // (caso anomalo — UoM sconosciuta al sistema).

    return $result;
}

/* =============================================================================
   GESTIONE TAG
============================================================================= */

/** Carica tutti i tag completi di conteggio prodotti. */
function qk_get_tags_with_count(PDO $pdo): array
{
    return $pdo->query(
        'SELECT t.id, t.nome, (SELECT COUNT(*) FROM prodotti_tag pt WHERE pt.tag_id = t.id) AS prodotti_count
           FROM tag t
          ORDER BY t.nome'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Salva (crea o aggiorna) un tag. */
function qk_save_tag(PDO $pdo, ?int $id, string $nome): void
{
    if ($id) {
        $pdo->prepare('UPDATE tag SET nome = :nome WHERE id = :id')
            ->execute([':nome' => $nome, ':id' => $id]);
    } else {
        $pdo->prepare('INSERT INTO tag (nome) VALUES (:nome)')
            ->execute([':nome' => $nome]);
    }
}

/** Verifica se un tag esiste. */
function qk_tag_exists(PDO $pdo, string $nome, ?int $exclude_id = null): bool
{
    $stmt = $pdo->prepare('SELECT id FROM tag WHERE nome = :nome AND (:id IS NULL OR id != :id)');
    $stmt->execute([':nome' => $nome, ':id' => $exclude_id]);
    return (bool) $stmt->fetchColumn();
}

/** Elimina un tag. */
function qk_delete_tag(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM tag WHERE id = :id')->execute([':id' => $id]);
}

/** Copia i tag associati da un prodotto sorgente a un prodotto destinazione. */
function qk_copy_prodotto_tags(PDO $pdo, int $source_id, int $dest_id): void
{
    $stmt = $pdo->prepare('SELECT tag_id FROM prodotti_tag WHERE prodotto_id = ?');
    $stmt->execute([$source_id]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($tags)) {
        $insTag = $pdo->prepare('INSERT INTO prodotti_tag (prodotto_id, tag_id) VALUES (?, ?)');
        foreach ($tags as $tid) {
            $insTag->execute([$dest_id, $tid]);
        }
    }
}

/* =============================================================================
   GESTIONE CATEGORIE
============================================================================= */

/** Carica la lista delle categorie (incluso il nome del parent). */
function qk_get_categories_list(PDO $pdo): array
{
    return $pdo->query(
        'SELECT c.id, c.nome, c.posizione_categoria, c.immagine_path,
                p.nome AS parent_nome, p.id AS parent_id
           FROM categorie_prodotto c
      LEFT JOIN categorie_prodotto p ON p.id = c.parent_id
          ORDER BY c.nome'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Salva (crea o aggiorna) una categoria. Restituisce il path della vecchia immagine in caso di update (per pulizia eventuale). */
function qk_save_category(PDO $pdo, ?int $id, string $nome, ?int $parent_id, int $pos, ?string $img): ?string
{
    $oldImg = null;
    if ($id) {
        $stmtOld = $pdo->prepare('SELECT immagine_path FROM categorie_prodotto WHERE id = :id');
        $stmtOld->execute([':id' => $id]);
        $oldImg = $stmtOld->fetchColumn();

        $pdo->prepare(
            'UPDATE categorie_prodotto 
                SET nome = :nome, parent_id = :parent_id, posizione_categoria = :pos, immagine_path = :img 
              WHERE id = :id'
        )->execute([
            ':nome' => $nome,
            ':parent_id' => $parent_id,
            ':pos' => $pos,
            ':img' => $img,
            ':id' => $id
        ]);
    } else {
        $pdo->prepare(
            'INSERT INTO categorie_prodotto (nome, parent_id, posizione_categoria, immagine_path) 
             VALUES (:nome, :parent_id, :pos, :img)'
        )->execute([
            ':nome' => $nome,
            ':parent_id' => $parent_id,
            ':pos' => $pos,
            ':img' => $img
        ]);
    }
    return $oldImg ?: null;
}

/** Elimina una categoria, previo controllo vincoli. Restituisce un errore se vincolata. Restituisce path img se success. */
function qk_delete_category(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categorie_prodotto WHERE parent_id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'Impossibile eliminare: questa categoria ha delle sotto-categorie.'];
    }

    $stmtImg = $pdo->prepare('SELECT immagine_path FROM categorie_prodotto WHERE id = :id');
    $stmtImg->execute([':id' => $id]);
    $img = $stmtImg->fetchColumn();

    try {
        $pdo->prepare('DELETE FROM categorie_prodotto WHERE id = :id')->execute([':id' => $id]);
        return ['ok' => true, 'immagine_path' => $img];
    } catch (PDOException $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Impossibile eliminare questa categoria poiché è associata ad uno o più prodotti.'];
    }
}

/** Ottiene le categorie per l'esportazione massiva. */
function qk_get_categories_for_export(PDO $pdo): array
{
    return $pdo->query(
        'SELECT c.id, c.nome, c.posizione_categoria, c.immagine_path,
                p.nome AS parent_nome
           FROM categorie_prodotto c
      LEFT JOIN categorie_prodotto p ON p.id = c.parent_id
          ORDER BY c.nome'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Importa massivamente un array di categorie. Restituisce risultati elaborazione. */
function qk_import_categories_massively(PDO $pdo, array $rows): array
{
    $inserted  = 0;
    $updated   = 0;
    $skipped   = 0;
    $rowErrors = [];

    $nameMap = [];
    foreach ($pdo->query('SELECT id, nome FROM categorie_prodotto')->fetchAll() as $r) {
        $nameMap[strtolower(trim($r['nome']))] = (int) $r['id'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $row) {
            $lineNum = $i + 2;
            $nome    = trim((string)($row['nome'] ?? ''));

            if ($nome === '') {
                $skipped++;
                $rowErrors[] = 'Riga ' . $lineNum . ': nome vuoto — saltata.';
                continue;
            }

            $posizione     = (int)($row['posizione_categoria'] ?? 0);
            $immaginePath  = trim((string)($row['immagine_path'] ?? '')) ?: null;

            $parentNome = strtolower(trim((string)($row['parent_nome'] ?? '')));
            $parentId   = ($parentNome !== '') ? ($nameMap[$parentNome] ?? null) : null;

            $nomeKey = strtolower($nome);
            if (isset($nameMap[$nomeKey])) {
                $existingId = $nameMap[$nomeKey];
                $pdo->prepare(
                    'UPDATE categorie_prodotto
                        SET posizione_categoria = :pos,
                            immagine_path       = :img,
                            parent_id           = COALESCE(:pid, parent_id)
                      WHERE id = :id'
                )->execute([
                    ':pos' => $posizione,
                    ':img' => $immaginePath,
                    ':pid' => $parentId,
                    ':id'  => $existingId,
                ]);
                $updated++;
            } else {
                $pdo->prepare(
                    'INSERT INTO categorie_prodotto (nome, parent_id, posizione_categoria, immagine_path)
                     VALUES (:nome, :pid, :pos, :img)'
                )->execute([
                    ':nome' => $nome,
                    ':pid'  => $parentId,
                    ':pos' => $posizione,
                    ':img'  => $immaginePath,
                ]);
                $newId = (int) $pdo->lastInsertId();
                $nameMap[$nomeKey] = $newId;
                $inserted++;
            }
        }
        $pdo->commit();
        
        return [
            'ok'       => true,
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $rowErrors,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


function qk_delete_prodotto(PDO $pdo, int $id): bool {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM giacenze WHERE prodotto_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM listini_prezzi WHERE prodotto_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM prodotti_tag WHERE prodotto_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM componenti_kit WHERE kit_id = ?")->execute([$id]);
        
        $pdo->prepare("
            DELETE FROM prodotti_fornitori_prezzi 
            WHERE prodotto_fornitore_id IN (SELECT id FROM prodotti_fornitori WHERE prodotto_id = ?)
        ")->execute([$id]);
        $pdo->prepare("DELETE FROM prodotti_fornitori WHERE prodotto_id = ?")->execute([$id]);

        if (DB_DRIVER === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = ON;");
        }

        $stmt = $pdo->prepare("DELETE FROM prodotti WHERE id = ?");
        $stmt->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        try {
            $pdo->prepare("UPDATE prodotti SET attivo = 0 WHERE id = ?")->execute([$id]);
            return false;
        } catch (Throwable $ex) {
            throw $ex;
        }
    }
}

function qk_duplicate_prodotto(PDO $pdo, int $id): int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM prodotti WHERE id = ?');
        $stmt->execute([$id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception('Prodotto non trovato.');
        }

        $originalSku = $prod['sku_interno'] ?? 'PROD';
        $newSku = $originalSku . '_COPIA';
        $checkSku = $pdo->prepare('SELECT COUNT(*) FROM prodotti WHERE sku_interno = ?');
        $checkSku->execute([$newSku]);
        $count = (int)$checkSku->fetchColumn();
        $suffix = 1;
        while ($count > 0) {
            $newSku = $originalSku . '_COPIA_' . $suffix;
            $checkSku->execute([$newSku]);
            $count = (int)$checkSku->fetchColumn();
            $suffix++;
        }

        $insertStmt = $pdo->prepare('
            INSERT INTO prodotti (
                sku_interno, sku_ecommerce, nome, descrizione_breve, descrizione_confezione,
                descrizione_completa, tipo, categoria_id, note_interne, attivo,
                traccia_lotti, unita_misura_stock_id, unita_contenuto_id, quantita_contenuto,
                peso_netto_kg, peso_lordo_kg, ean, is_adr,
                sostanza_chimica_id, created_at
            ) VALUES (
                :sku_interno, NULL, :nome, :descrizione_breve, :descrizione_confezione,
                :descrizione_completa, :tipo, :categoria_id, :note_interne, :attivo,
                :traccia_lotti, :unita_misura_stock_id, :unita_contenuto_id, :quantita_contenuto,
                :peso_netto_kg, :peso_lordo_kg, NULL, :is_adr,
                :sostanza_chimica_id, CURRENT_TIMESTAMP
            )
        ');

        $insertStmt->execute([
            ':sku_interno' => $newSku,
            ':nome' => ($prod['nome'] ?? '') . ' - Copia',
            ':descrizione_breve' => $prod['descrizione_breve'] ?? null,
            ':descrizione_confezione' => $prod['descrizione_confezione'] ?? null,
            ':descrizione_completa' => $prod['descrizione_completa'] ?? null,
            ':tipo' => $prod['tipo'] ?? 'semplice',
            ':categoria_id' => $prod['categoria_id'] ?? null,
            ':note_interne' => $prod['note_interne'] ?? null,
            ':attivo' => $prod['attivo'],
            ':traccia_lotti' => $prod['traccia_lotti'],
            ':unita_misura_stock_id' => $prod['unita_misura_stock_id'],
            ':unita_contenuto_id' => $prod['unita_contenuto_id'] ?? null,
            ':quantita_contenuto' => $prod['quantita_contenuto'] ?? null,
            ':peso_netto_kg' => $prod['peso_netto_kg'] ?? null,
            ':peso_lordo_kg' => $prod['peso_lordo_kg'] ?? null,
            ':is_adr' => $prod['is_adr'] ?? 0,
            ':sostanza_chimica_id' => $prod['sostanza_chimica_id'] ?? null,
        ]);

        $newProductId = (int) $pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO giacenze (prodotto_id, magazzino_id, quantita_disponibile, quantita_riservata, quantita_in_arrivo, scorta_minima, updated_at)
            VALUES (?, ?, 0, 0, 0, 0, CURRENT_TIMESTAMP)
        ')->execute([$newProductId, qk_magazzino_attivo_id($pdo)]);

        qk_copy_prodotto_tags($pdo, $id, $newProductId);

        $fornStmt = $pdo->prepare('SELECT * FROM prodotti_fornitori WHERE prodotto_id = ?');
        $fornStmt->execute([$id]);
        $fornitoriList = $fornStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($fornitoriList)) {
            $insForn = $pdo->prepare('
                INSERT INTO prodotti_fornitori (
                    prodotto_id, fornitore_id, sku_fornitore, nome_prodotto_fornitore,
                    unita_misura_acquisto_id, quantita_minima, iva_acquisto_default,
                    ordine_preferenza_fornitore, lead_time_giorni, attivo, note,
                    rrp_min, rrp_max, rrp_unita_misura_id, rrp_valido_dal, rrp_valido_al, rrp_valuta, created_at
                ) VALUES (
                    :pid, :fid, :sku, :nome,
                    :uom_id, :qta, :iva,
                    :ord, :lead, :attivo, :note,
                    :rmin, :rmax, :ruom, :rdal, :ral, :rval, CURRENT_TIMESTAMP
                )
            ');

            $insPrezzo = $pdo->prepare('
                INSERT INTO prodotti_fornitori_prezzi (
                    prodotto_fornitore_id, quantita_minima, prezzo, unita_misura_id,
                    sconto_perc, valido_dal, valido_al, note, listino_acquisto_id
                ) VALUES (
                    :pfid, :qta, :prezzo, :uom_id,
                    :sconto, :vdal, :val, :note, :listino_id
                )
            ');

            foreach ($fornitoriList as $f) {
                $insForn->execute([
                    ':pid' => $newProductId,
                    ':fid' => $f['fornitore_id'],
                    ':sku' => $f['sku_fornitore'] ? $f['sku_fornitore'] . '_COPIA' : null,
                    ':nome' => $f['nome_prodotto_fornitore'],
                    ':uom_id' => $f['unita_misura_acquisto_id'],
                    ':qta' => $f['quantita_minima'],
                    ':iva' => $f['iva_acquisto_default'],
                    ':ord' => $f['ordine_preferenza_fornitore'],
                    ':lead' => $f['lead_time_giorni'],
                    ':attivo' => $f['attivo'],
                    ':note' => $f['note'],
                    ':rmin' => $f['rrp_min'],
                    ':rmax' => $f['rrp_max'],
                    ':ruom' => $f['rrp_unita_misura_id'],
                    ':rdal' => $f['rrp_valido_dal'],
                    ':ral' => $f['rrp_valido_al'],
                    ':rval' => $f['rrp_valuta'],
                ]);

                $newPfId = (int) $pdo->lastInsertId();

                $prezziStmt = $pdo->prepare('SELECT * FROM prodotti_fornitori_prezzi WHERE prodotto_fornitore_id = ?');
                $prezziStmt->execute([$f['id']]);
                $prezzi = $prezziStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($prezzi as $p) {
                    $insPrezzo->execute([
                        ':pfid' => $newPfId,
                        ':qta' => $p['quantita_minima'],
                        ':prezzo' => $p['prezzo'],
                        ':uom_id' => $p['unita_misura_id'],
                        ':sconto' => $p['sconto_perc'],
                        ':vdal' => $p['valido_dal'],
                        ':val' => $p['valido_al'],
                        ':note' => $p['note'],
                        ':listino_id' => $p['listino_acquisto_id']
                    ]);
                }
            }
        }

        $listiniStmt = $pdo->prepare('SELECT * FROM listini_prezzi WHERE prodotto_id = ?');
        $listiniStmt->execute([$id]);
        $listini = $listiniStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($listini)) {
            $insListino = $pdo->prepare('
                INSERT INTO listini_prezzi (
                    listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id, sconto_perc, note, created_at
                ) VALUES (
                    :lid, :pid, :qmin, :prezzo, :uom_id, :sconto, :note, CURRENT_TIMESTAMP
                )
            ');

            foreach ($listini as $l) {
                $insListino->execute([
                    ':lid' => $l['listino_id'],
                    ':pid' => $newProductId,
                    ':qmin' => $l['quantita_minima'],
                    ':prezzo' => $l['prezzo_unitario'],
                    ':uom_id' => $l['unita_misura_id'],
                    ':sconto' => $l['sconto_perc'],
                    ':note' => $l['note'],
                ]);
            }
        }

        if ($prod['tipo'] === 'kit') {
            $kitStmt = $pdo->prepare('SELECT * FROM componenti_kit WHERE kit_id = ?');
            $kitStmt->execute([$id]);
            $compList = $kitStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($compList)) {
                $insComp = $pdo->prepare('
                    INSERT INTO componenti_kit (
                        kit_id, componente_id, quantita, note
                    ) VALUES (
                        :kid, :cid, :qta, :note
                    )
                ');
                foreach ($compList as $c) {
                    $insComp->execute([
                        ':kid' => $newProductId,
                        ':cid' => $c['componente_id'],
                        ':qta' => $c['quantita'],
                        ':note' => $c['note'],
                    ]);
                }
            }
        }

        $pdo->commit();
        return $newProductId;

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Restituisce sku_interno e unita_misura_stock_id di un prodotto dato l'ID
 */
function qk_get_prodotto_stock_info(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT sku_interno, unita_misura_stock_id FROM prodotti WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Funzione trasversale di ricerca autocomplete prodotti.
 *
 * @param PDO    $pdo
 * @param string $term  Stringa di ricerca (nome o sku)
 * @param int    $limit Numero massimo di risultati
 * @return array Mappa di prodotti trovati con informazioni arricchite
 */
function qk_search_prodotti_autocomplete(PDO $pdo, string $term, int $limit = 50): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $st = $pdo->prepare("
        SELECT p.id,
               p.nome AS label,
               p.nome,
               p.sku_interno AS sku,
               p.sku_interno,
               p.categoria_id,
               um.codice AS unita_misura,
               p.unita_misura_stock_id,
               p.descrizione_confezione AS quantita_confezione,
               p.unita_contenuto_id,
               um_comm.codice AS unita_misura_commerciale,
               p.quantita_contenuto
          FROM prodotti p
     LEFT JOIN unita_misura um ON um.id = p.unita_misura_stock_id
     LEFT JOIN unita_misura um_comm ON um_comm.id = p.unita_contenuto_id
         WHERE p.attivo = 1
           AND (p.nome LIKE :q OR p.sku_interno LIKE :q)
      ORDER BY p.nome ASC
         LIMIT " . (int)$limit
    );
    $st->execute([':q' => '%' . $term . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
