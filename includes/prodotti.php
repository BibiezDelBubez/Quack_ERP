<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* =============================================================================
   HELPERS
============================================================================= */

function qk_prodotti_null(?string $s): ?string
{
    $s = trim((string) $s);
    return $s === '' ? null : $s;
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
                (SELECT pfp.prezzo
                   FROM prodotti_fornitori_prezzi pfp
                  WHERE pfp.prodotto_fornitore_id = pf.id
                    AND pfp.quantita_minima = 0
                  LIMIT 1) AS prezzo_acquisto
           FROM prodotti_fornitori pf
           JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
      LEFT JOIN unita_misura um ON um.id = pf.unita_misura_acquisto_id
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
                (SELECT pfp.prezzo
                   FROM prodotti_fornitori_prezzi pfp
                  WHERE pfp.prodotto_fornitore_id = pf.id
                    AND pfp.quantita_minima = 0
                  LIMIT 1) AS prezzo_acquisto
           FROM prodotti_fornitori pf
           JOIN clienti_fornitori cf ON cf.id = pf.fornitore_id
      LEFT JOIN unita_misura um ON um.id = pf.unita_misura_acquisto_id
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
                CASE WHEN p.tipo = 'kit' THEN (
                    SELECT SUM(ck.quantita * (
                        SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp 
                        JOIN prodotti_fornitori pf2 ON pf2.id = pfp.prodotto_fornitore_id
                        WHERE pf2.prodotto_id = ck.componente_id AND pf2.attivo = 1 AND pfp.quantita_minima = 0
                        ORDER BY pf2.ordine_preferenza_fornitore ASC, pfp.id DESC LIMIT 1
                    ))
                    FROM componenti_kit ck
                    WHERE ck.kit_id = p.id
                ) ELSE (
                    SELECT pfp.prezzo FROM prodotti_fornitori_prezzi pfp 
                    JOIN prodotti_fornitori pf2 ON pf2.id = pfp.prodotto_fornitore_id
                    WHERE pf2.prodotto_id = p.id AND pf2.attivo = 1 AND pfp.quantita_minima = 0
                    ORDER BY pf2.ordine_preferenza_fornitore ASC, pfp.id DESC LIMIT 1
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

        // Calcolo numeratore e denominatore deterministici per potenze di 10
        $rawQty = qk_prodotti_decimal($v['quantita_contenuto']);
        $numFactor = 1;
        $denFactor = 1;
        if ($rawQty !== null && (float)$rawQty > 0.0) {
            $fVal = (float)$rawQty;
            if ($fVal >= 1.0) {
                $numFactor = 1;
                $denFactor = (int)$fVal;
            } else {
                $strVal = (string)$fVal;
                $decimals = 0;
                if (strpos($strVal, '.') !== false) {
                    $decimals = strlen(substr($strVal, strpos($strVal, '.') + 1));
                }
                $multiplier = 10 ** $decimals;
                $numFactor = (int)round($fVal * $multiplier);
                $denFactor = $multiplier;
                
                $gcd = function($a, $b) use (&$gcd) {
                    return $b ? $gcd($b, $a % $b) : $a;
                };
                $g = (int)$gcd($numFactor, $denFactor);
                if ($g > 0) {
                    $numFactor = (int)($numFactor / $g);
                    $denFactor = (int)($denFactor / $g);
                }
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO prodotti
               (sku_interno, sku_ecommerce, nome, descrizione_breve, descrizione_confezione, descrizione_completa,
                tipo, categoria_id, note_interne,
                attivo, traccia_lotti, unita_misura_stock_id, unita_contenuto_id, quantita_contenuto,
                num_factor, den_factor, peso_netto_kg, peso_lordo_kg,
                ean, is_adr, sostanza_chimica_id, created_at)
             VALUES
               (:sku_interno, :sku_ecommerce, :nome, :desc_breve, :desc_confezione, :desc_completa,
                :tipo, :cat_id, :note_int,
                :attivo, :traccia_lotti, :um_id, :unita_contenuto_id, :quantita_contenuto,
                :num_factor, :den_factor, :peso_netto, :peso_lordo,
                :ean, :is_adr, :sostanza_chimica_id, CURRENT_TIMESTAMP)'
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
            $pdo->prepare('INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id) VALUES ((SELECT id FROM listini WHERE is_default = 1 LIMIT 1), ?, 0, ?, ?)')
                ->execute([$id, $prezzo_base, $um_id]);
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

function qk_update_prodotto(PDO $pdo, int $id, array $v): void
{
    $umStmt = $pdo->prepare('SELECT id FROM unita_misura WHERE codice = :c');
    $umStmt->execute([':c' => $v['unita_misura'] ?? 'pz']);
    $um_id = $umStmt->fetchColumn() ?: null;

    // Recupera la Stock UoM attuale per verificare se l'utente la sta cambiando
    $stCurrent = $pdo->prepare('SELECT unita_misura_stock_id FROM prodotti WHERE id = ?');
    $stCurrent->execute([$id]);
    $currentUmId = $stCurrent->fetchColumn();

    if ($currentUmId && (int)$currentUmId !== (int)$um_id) {
        // Verifica se ci sono già stati movimenti fisici a magazzino per questo prodotto
        $stMov = $pdo->prepare('SELECT COUNT(*) FROM movimenti_magazzino WHERE prodotto_id = ?');
        $stMov->execute([$id]);
        $hasMovements = (int)$stMov->fetchColumn() > 0;

        if ($hasMovements) {
            throw new \RuntimeException(
                "Impossibile modificare l'unità di misura di stock: sono già presenti movimenti fisici di magazzino per questo prodotto nel sistema logistico."
            );
        }
    }

    // Calcolo numeratore e denominatore deterministici per potenze di 10
    $rawQty = qk_prodotti_decimal($v['quantita_contenuto']);
    $numFactor = 1;
    $denFactor = 1;
    if ($rawQty !== null && (float)$rawQty > 0.0) {
        $fVal = (float)$rawQty;
        if ($fVal >= 1.0) {
            $numFactor = 1;
            $denFactor = (int)$fVal;
        } else {
            $strVal = (string)$fVal;
            $decimals = 0;
            if (strpos($strVal, '.') !== false) {
                $decimals = strlen(substr($strVal, strpos($strVal, '.') + 1));
            }
            $multiplier = 10 ** $decimals;
            $numFactor = (int)round($fVal * $multiplier);
            $denFactor = $multiplier;
            
            $gcd = function($a, $b) use (&$gcd) {
                return $b ? $gcd($b, $a % $b) : $a;
            };
            $g = (int)$gcd($numFactor, $denFactor);
            if ($g > 0) {
                $numFactor = (int)($numFactor / $g);
                $denFactor = (int)($denFactor / $g);
            }
        }
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
                $pdo->prepare('UPDATE listini_prezzi SET prezzo_unitario = ?, unita_misura_id = ? WHERE id = ?')
                    ->execute([$prezzo_base, $um_id, $ruleId]);
            } else {
                $pdo->prepare('INSERT INTO listini_prezzi (listino_id, prodotto_id, quantita_minima, prezzo_unitario, unita_misura_id) VALUES ((SELECT id FROM listini WHERE is_default = 1 LIMIT 1), ?, 0, ?, ?)')
                    ->execute([$id, $prezzo_base, $um_id]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
