<?php
/**
 * functions.php — helper globali dell'app
 * Includi DOPO config.php
 */

/* ── DB portability helpers (sqlite/mysql) ───────────────────────────────────
   Isolano qui le poche differenze di sintassi tra i due motori, così il resto
   del codice non deve mai controllare DB_DRIVER direttamente.
─────────────────────────────────────────────────────────────────────────── */

/**
 * Restituisce i nomi delle colonne di una tabella, indipendentemente dal
 * motore attivo. Sostituisce i vari "PRAGMA table_info($table)" sparsi nel
 * codice (che funzionano solo su SQLite).
 *
 * @return string[] nomi colonna
 */
function db_table_columns(PDO $pdo, string $table): array {
    if (DB_DRIVER === 'mysql') {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
}

/**
 * Restituisce true se la tabella ha la colonna indicata. Comodo per i
 * controlli "esiste questa colonna?" prima di usarla in una query.
 */
function db_table_has_column(PDO $pdo, string $table, string $column): bool {
    return in_array($column, db_table_columns($pdo, $table), true);
}

/**
 * Espressione GROUP_CONCAT compatibile col motore attivo:
 *   - sqlite: "GROUP_CONCAT(col, 'sep')"
 *   - mysql:  "GROUP_CONCAT(col SEPARATOR 'sep')"
 *
 * $expr può essere una colonna o un'espressione SQL (es. "c.categoria_galleria").
 */
function db_group_concat_sql(string $expr, string $separator = ','): string {
    $sep = str_replace("'", "''", $separator);
    return DB_DRIVER === 'mysql'
        ? "GROUP_CONCAT({$expr} SEPARATOR '{$sep}')"
        : "GROUP_CONCAT({$expr}, '{$sep}')";
}

/**
 * Prefisso SQL per un INSERT che deve ignorare i duplicati, nella sintassi
 * corretta per il motore attivo:
 *   - sqlite: "INSERT OR IGNORE INTO tabella (...) VALUES (...)"
 *   - mysql:  "INSERT IGNORE INTO tabella (...) VALUES (...)"
 *
 * Uso: $pdo->prepare(db_insert_ignore_sql('prodotti_tag (prodotto_id, tag_id) VALUES (:pid, :tid)'))
 */
function db_insert_ignore_sql(string $tableColumnsValues): string {
    $prefix = DB_DRIVER === 'mysql' ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ';
    return $prefix . $tableColumnsValues;
}

/* ── Output ───────────────────────────────────────────────────────────────── */

/** Escape HTML per output sicuro */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Genera un URL root-relativo all'interno dell'app */
function url(string $path = ''): string {
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Genera un URL root-relativo a un asset (con cache-busting automatico per CSS/JS) */
function asset(string $path): string {
    $clean = ltrim($path, '/');
    $full  = APP_ROOT_DIR . '/assets/' . $clean;
    $v     = (file_exists($full) && in_array(pathinfo($full, PATHINFO_EXTENSION), ['css', 'js']))
             ? '?v=' . filemtime($full)
             : '';
    return ASSETS_URL . '/' . $clean . $v;
}

/* ── Navigation helpers ───────────────────────────────────────────────────── */

/**
 * Restituisce la classe CSS 'active' se il path corrente contiene $match.
 * Usato per evidenziare il link attivo nella sidebar.
 */
function nav_active(string $match, string $class = 'active', bool $exact = false): string {
    $path = request_path();
    if ($exact || $match === '/purchases') {
        return $path === $match ? $class : '';
    }
    return str_contains($path, $match) ? $class : '';
}

/**
 * Restituisce true se il path corrente inizia con $prefix (relativo a BASE_URL).
 */
function is_current_section(string $prefix): bool {
    $path = request_path();
    return str_contains($path, $prefix);
}

/** Path corrente, normalizzato rispetto a BASE_URL */
function request_path(): string {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = rtrim(BASE_URL, '/');
    if ($basePath !== '' && stripos($uriPath, $basePath) === 0) {
        $uriPath = substr($uriPath, strlen($basePath));
    }
    $path = '/' . ltrim($uriPath ?: '/', '/');
    if ($path !== '/') {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
    }
    return $path;
}

/* ── Flash messages ───────────────────────────────────────────────────────── */

/** Imposta un flash message da visualizzare alla prossima richiesta */
function flash_set(string $type, string $message): void {
    $_SESSION['qk_flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Legge e cancella il flash message corrente.
 * @return array{type:string,message:string}|null
 */
function flash_get(): ?array {
    $flash = $_SESSION['qk_flash'] ?? null;
    unset($_SESSION['qk_flash']);
    return $flash;
}

/* ── Redirect (PRG pattern) ───────────────────────────────────────────────── */

/** Redirect con Location header e uscita immediata */
function redirect(string $path): never {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || (defined('BASE_URL') && BASE_URL !== '' && str_starts_with($path, BASE_URL))) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

/** Redirect con flash message */
function redirect_with(string $path, string $type, string $message): never {
    flash_set($type, $message);
    redirect($path);
}

/* ── Authentication ───────────────────────────────────────────────────────── */

/** Utente corrente autenticato */
function current_user(): ?array {
    $user = $_SESSION['qk_user'] ?? null;
    if (!is_array($user)) return null;

    // Riconvalida dal DB ogni 10 minuti
    $last_check = $_SESSION['qk_user_last_check'] ?? 0;
    if (time() - $last_check > 600) {
        global $pdo;
        if (isset($pdo)) {
            $st = $pdo->prepare('SELECT u.id, r.codice AS role, u.attivo FROM utenti u JOIN ruoli r ON r.id = u.ruolo_id WHERE u.id = ?');
            $st->execute([$user['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !$row['attivo']) {
                session_destroy();
                header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login');
                exit;
            }
            // Aggiorna role in sessione nel caso sia cambiato
            $_SESSION['qk_user']['role'] = $row['role'];
            $_SESSION['qk_user_last_check'] = time();
            $user = $_SESSION['qk_user'];
        }
    }

    return $user;
}

/** True se sessione autenticata */
function is_authenticated(): bool {
    return current_user() !== null;
}

/**
 * Tenta il login cercando l'utente nel DB.
 * Accetta sia username che email: se l'input contiene '@' cerca per email,
 * altrimenti per username.
 */
function login_attempt(PDO $pdo, string $input, string $password): bool {
    try {
        $input = trim($input);
        $field = str_contains($input, '@') ? 'u.email' : 'u.username';

        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.nome, u.cognome, u.email,
                    u.password_hash, r.codice AS ruolo
               FROM utenti u
               JOIN ruoli r ON r.id = u.ruolo_id
              WHERE {$field} = :input AND u.attivo = 1
              LIMIT 1"
        );
        $stmt->execute([':input' => $input]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['qk_user'] = [
                'id'           => $user['id'],
                'username'     => $user['username'],
                'display_name' => trim($user['nome'] . ' ' . $user['cognome']),
                'role'         => $user['ruolo'],
            ];
            session_regenerate_id(true);
            return true;
        }
    } catch (\PDOException) {
        /* fallback sotto */
    }

    return false;
}

/** Logout sessione corrente */
function logout_current_user(): void {
    unset($_SESSION['qk_user']);
    session_regenerate_id(true);
}

/* ── Sicurezza form ───────────────────────────────────────────────────────── */

/** Genera un CSRF token nella sessione */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Stampa un campo hidden con il token CSRF */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verifica il CSRF token su POST */
function csrf_verify(): bool {
    $token = $_POST['csrf_token'] ?? $_POST['_token'] ?? '';
    return !empty($token) && hash_equals(csrf_token(), $token);
}

/**
 * Stato dello schema DB letto dalla tabella schema_migrations, al posto della
 * vecchia costante DB_SCHEMA_VERSION mantenuta a mano (e sempre disallineata).
 * Ritorna ['count' => n, 'last_migration' => ?string, 'last_applied_at' => ?string].
 * Se la tabella non esiste ancora (DB creato prima di questa funzionalità e
 * mai passato da db/migrate.php), ritorna dei valori vuoti invece di rompere
 * la pagina che la chiama.
 */
function qk_schema_status(PDO $pdo): array {
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $last  = $pdo->query("SELECT migration, applied_at FROM schema_migrations ORDER BY id DESC LIMIT 1")
                      ->fetch(PDO::FETCH_ASSOC);
        return [
            'count'           => $count,
            'last_migration'  => $last['migration']   ?? null,
            'last_applied_at' => $last['applied_at']  ?? null,
        ];
    } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . ':' . __LINE__ . '] Exception: ' . $e->getMessage());
        return ['count' => 0, 'last_migration' => null, 'last_applied_at' => null];
    }
}

/** Normalizza un flag booleano/checkbox proveniente da form o DB (string '1'/'0', int 1/0, bool, null) */
function qk_is_true(mixed $value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value))  return $value === 1;
    if (is_string($value)) return $value === '1';
    return false;
}

/* ── Input helpers ────────────────────────────────────────────────────────── */

/**
 * Restituisce un valore POST trimmato, o il default.
 * $default accetta qualunque scalare (i valori di fallback spesso arrivano
 * da una riga DB già tipizzata, es. float/int/bool), ma viene sempre
 * normalizzato a string: il ritorno resta string come da firma, quindi
 * nessun chiamante esistente deve cambiare.
 */
function post(string $key, string|int|float|bool|null $default = ''): string {
    if (!isset($_POST[$key])) {
        return (string) $default;
    }
    if (is_array($_POST[$key])) {
        throw new \InvalidArgumentException(
            "post('{$key}'): il campo è stato inviato come array. Leggilo direttamente da \$_POST['{$key}'], post() gestisce solo valori scalari."
        );
    }
    return trim((string) $_POST[$key]);
}

/** Restituisce un valore GET trimmato, o il default (stesse regole di post(), vedi sopra) */
function get_param(string $key, string|int|float|bool|null $default = ''): string {
    if (!isset($_GET[$key])) {
        return (string) $default;
    }
    if (is_array($_GET[$key])) {
        throw new \InvalidArgumentException(
            "get_param('{$key}'): il parametro è stato inviato come array. Leggilo direttamente da \$_GET['{$key}'], get_param() gestisce solo valori scalari."
        );
    }
    return trim((string) $_GET[$key]);
}

/* ── Formattazione ────────────────────────────────────────────────────────── */

/** Formatta un importo in euro */
function fmt_money(float $amount): string {
    return '€ ' . number_format($amount, 2, ',', '.');
}

/** Formatta una data italiana */
function fmt_date(?string $date): string {
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

/** Formatta datetime italiana */
function fmt_datetime(?string $dt): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : $dt;
}

/**
 * Formatta una quantità numerica eliminando i decimali inutili.
 * Es: 100.000 → "100",  1.500 → "1,5",  0.001 → "0,001"
 */
function fmt_qty(float $n, int $max_dec = 3): string
{
    if ($n == 0.0) return '0';
    $s = number_format(abs($n), $max_dec, ',', '.');
    if (str_contains($s, ',')) {
        $s = rtrim($s, '0');
        $s = rtrim($s, ',');
    }
    return ($n < 0 ? '-' : '') . $s;
}

/** Tronca una stringa a N caratteri aggiungendo … */
function truncate(string $str, int $max = 50): string {
    return mb_strlen($str) > $max ? mb_substr($str, 0, $max) . '…' : $str;
}

/* ── Permessi ─────────────────────────────────────────────────────────────── */

/**
 * Verifica se l'utente corrente ha un determinato permesso.
 *
 * Permessi disponibili (corrispondono alle colonne della tabella `ruoli`):
 *   manage_users      → solo admin: crea/modifica/elimina utenti
 *   view_costs        → può vedere prezzi di acquisto e margini
 *   edit_prices       → può modificare prezzi di vendita
 *   manage_catalog    → può creare/modificare prodotti e categorie
 *   manage_warehouse  → può fare movimenti di magazzino
 *   manage_orders     → può creare/confermare ordini e offerte
 */
function can(string $permission): bool
{
    $role = current_user()['role'] ?? 'readonly';

    $map = [
        'admin' => [
            'manage_users', 'view_costs', 'edit_prices',
            'manage_catalog', 'manage_warehouse', 'manage_orders',
        ],
        'commerciale' => [
            'view_costs', 'edit_prices', 'manage_orders',
        ],
        'magazziniere' => [
            'manage_warehouse',
        ],
        'readonly' => [],
    ];

    return in_array($permission, $map[$role] ?? [], true);
}

/* ── ADR helper ───────────────────────────────────────────────────────────── */

/** Badge HTML per prodotto ADR */
function adr_badge(bool $is_adr): string {
    if (!$is_adr) return '';
    return '<span class="qk-adr-tag"><i class="fa-solid fa-triangle-exclamation"></i> ADR</span>';
}

/* ── Status badge ─────────────────────────────────────────────────────────── */

/** Mappa slug → label IT per gli stati ordine/offerta */
function status_label(string $status): string {
    $map = [
        'draft'     => 'Bozza',
        'pending'   => 'In attesa',
        'confirmed' => 'Confermato',
        'shipped'   => 'Spedito',
        'delivered' => 'Consegnato',
        'cancelled' => 'Annullato',
        'active'    => 'Attivo',
        'inactive'  => 'Inattivo',
        'suspended' => 'Sospeso',
        'in_lavorazione'       => 'In lavorazione',
        'parzialmente_spedito' => 'Parz. spedito',
        'completato'           => 'Completato',
        'annullato'            => 'Annullato',
        'expired'              => 'Scaduto',
        'disattivato'          => 'Disattivato',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/* ── Upload helpers ───────────────────────────────────────────────────────── */

/**
 * Restituisce il percorso assoluto di una sottocartella dentro UPLOAD_DIR,
 * creandola se non esiste.
 *
 * Esempi:
 *   upload_ensure_dir('loghi')               → C:/Quack_ERP_data/loghi
 *   upload_ensure_dir('offerte/2026/06')      → C:/Quack_ERP_data/offerte/2026/06
 */
function upload_ensure_dir(string $subpath): string {
    $full = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim($subpath, '/\\');
    if (!is_dir($full)) {
        mkdir($full, 0755, true);
    }
    return $full;
}

/**
 * Restituisce il percorso assoluto completo di un file negli upload.
 * Il path relativo è quello salvato nel DB (es. "offerte/2026/06/file.pdf").
 */
function upload_abs(string $relative_path): string {
    return rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative_path, '/\\');
}

/**
 * Restituisce la sottocartella con sharding per anno/mese corrente.
 * Usare per file che si accumulano nel tempo (allegati, immagini prodotti, ecc.)
 *
 * Esempio: upload_dated_subdir('offerte') → 'offerte/2026/06'
 */
function upload_dated_subdir(string $base): string {
    return $base . '/' . date('Y') . '/' . date('m');
}

/**
 * Verifica che un file rilevato dal MIME-sniffing come 'application/zip' sia
 * realmente un contenitore DOCX (Word) e non un altro formato basato su ZIP
 * (xlsx, pptx, jar, zip generico) — fileinfo non distingue tra questi sulla
 * sola firma del contenitore ZIP, quindi ispezioniamo il contenuto interno
 * cercando 'word/document.xml', presente solo nei DOCX.
 *
 * Se l'estensione ZIP di PHP non è disponibile sul server, non possiamo
 * verificare: ritorniamo true per non rompere upload legittimi (il rischio
 * residuo in quel caso è solo un'estensione salvata non accurata, non di
 * sicurezza — nessun tipo pericoloso viene ammesso da questo controllo).
 */
function qk_is_docx_zip(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return true;
    }
    $zip = new \ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $isDocx = $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $isDocx;
}

/** Genera il badge HTML per uno stato */
function status_badge(string $status): string {
    $label = e(status_label($status));
    $slug  = e($status);
    return "<span class=\"qk-badge {$slug}\">{$label}</span>";
}

/**
 * Genera il prossimo numero progressivo per un tipo di sequenza centralizzato.
 *
 * @param PDO $pdo
 * @param string $tipo Il tipo di sequenza (es: 'preventivo', 'ordine_cliente', 'rfq', ecc.)
 * @return string
 */
function qk_next_sequence_number(PDO $pdo, string $tipo): string
{
    $anno = (int) date('Y');

    // BEGIN IMMEDIATE acquisisce subito il lock esclusivo su SQLite,
    // rendendo atomica la lettura+incremento e azzerando la race condition.
    //
    // BUG CORRETTO IL 22/07/2026 (scoperto dalla verifica manuale Fase 7, vedi
    // docs/Ciclo Vendite/CHECKLIST - Verifica Manuale Fase 7 Preventivi.md —
    // qualunque "Nuovo Preventivo/Ordine/RFQ/..." era rotto in produzione,
    // mai scoperto dai test automatici perché nessuna suite passava mai da
    // qui a transazione NON già aperta): la transazione veniva aperta con SQL
    // grezzo (exec('BEGIN IMMEDIATE')) ma chiusa con i metodi PDO
    // commit()/rollBack(). PDO tiene un proprio flag interno "sono in
    // transazione" che si accende SOLO con $pdo->beginTransaction(), mai con
    // SQL scritto a mano — quindi $pdo->commit() falliva SEMPRE con
    // "PDOException: There is no active transaction", anche se la
    // transazione era realmente aperta ed esclusiva sul database. Stesso
    // motivo per cui $pdo->inTransaction() qui non è mai affidabile (ritorna
    // false anche a transazione aperta manualmente, verificato). Corretto
    // restando coerenti fino in fondo con SQL grezzo: si apre con
    // exec('BEGIN IMMEDIATE') e si chiude SEMPRE con exec('COMMIT')/
    // exec('ROLLBACK'), mai con i metodi PDO — che controllano un flag
    // diverso da quello che abbiamo davvero acceso. Un solo punto di rollback
    // (nel catch), per evitare un secondo bug collegato: un doppio tentativo
    // di rollback che avrebbe mascherato l'eccezione originale.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->exec('BEGIN IMMEDIATE');

    try {
        $st = $pdo->prepare(
            'SELECT id, prefisso, anno, numero_corrente, padding
               FROM sequenze WHERE tipo = ? LIMIT 1'
        );
        $st->execute([$tipo]);
        $seq = $st->fetch(PDO::FETCH_ASSOC);

        if (!$seq) {
            throw new \RuntimeException("Sequenza '$tipo' non trovata nella tabella sequenze.");
        }

        if ((int) $seq['anno'] !== $anno) {
            $pdo->prepare('UPDATE sequenze SET anno = ?, numero_corrente = 0 WHERE id = ?')
                ->execute([$anno, $seq['id']]);
            $seq['numero_corrente'] = 0;
        }

        $next = (int) $seq['numero_corrente'] + 1;
        $pdo->prepare('UPDATE sequenze SET numero_corrente = ? WHERE id = ?')
            ->execute([$next, $seq['id']]);

        if ($ownTransaction) $pdo->exec('COMMIT');
    } catch (\Throwable $e) {
        if ($ownTransaction) $pdo->exec('ROLLBACK');
        throw $e;
    }

    $pad = (int) $seq['padding'];
    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

/**
 * Elenco chiuso dei tipi di sequenza noti all'applicazione — ogni stringa qui
 * corrisponde a una chiamata reale a qk_next_sequence_number($pdo, '...') in
 * qualche punto del codebase (verificato: rfq/offerta/ordine/ricezione in
 * purchases_common.php e chiamanti; trasferimento in trasferimenti.php;
 * preventivo/ordine_cliente in sales_quotes.php/sales_orders.php; fattura/
 * spedizione/documento_trasporto seminate ma non ancora usate da codice reale
 * al 17/07/2026 — incluse comunque perché già nel seed di baseline).
 *
 * Usato SOLO per popolare l'elenco chiuso di "Ricrea sequenza mancante" nella
 * pagina admin Sequenze Documenti (REVISIONE Ciclo Acquisti, 17/07/2026) — non
 * un'enumerazione a livello di schema (la tabella `sequenze.tipo` resta TEXT
 * libero, come sempre).
 */
const QK_SEQUENZE_TIPI_NOTI = [
    'rfq', 'offerta', 'ordine', 'ricezione', 'trasferimento',
    'preventivo', 'ordine_cliente', 'fattura', 'spedizione', 'documento_trasporto',
];

/**
 * Elenco completo delle righe della tabella `sequenze`, per la pagina admin
 * "Sequenze Documenti" (Impostazioni → Progressivi e Canali).
 *
 * @return array<int, array{id:int, tipo:string, prefisso:string, anno:int, numero_corrente:int, padding:int}>
 */
function qk_sequenze_list(PDO $pdo): array
{
    return $pdo->query('SELECT id, tipo, prefisso, anno, numero_corrente, padding FROM sequenze ORDER BY tipo')
        ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Aggiorna una riga esistente di `sequenze` (prefisso/anno/numero_corrente/padding).
 * Non permette di cambiare `tipo` (identifica la riga, non è un campo editabile —
 * cambiarlo romperebbe silenziosamente ogni chiamante che usa quel tipo).
 *
 * @throws \InvalidArgumentException se i valori non sono validi
 * @throws \RuntimeException se la riga non esiste
 */
function qk_sequenza_aggiorna(PDO $pdo, int $id, array $data): void
{
    $prefisso = trim((string)($data['prefisso'] ?? ''));
    $anno     = (int)($data['anno'] ?? 0);
    $numero   = (int)($data['numero_corrente'] ?? -1);
    $padding  = (int)($data['padding'] ?? 0);

    if ($prefisso === '') {
        throw new \InvalidArgumentException('Il prefisso non può essere vuoto.');
    }
    if ($anno < 2000 || $anno > 2100) {
        throw new \InvalidArgumentException('Anno non valido.');
    }
    if ($numero < 0) {
        throw new \InvalidArgumentException('Il numero corrente non può essere negativo.');
    }
    if ($padding < 1 || $padding > 8) {
        throw new \InvalidArgumentException('Il padding deve essere compreso tra 1 e 8 cifre.');
    }

    $st = $pdo->prepare('SELECT id FROM sequenze WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) {
        throw new \RuntimeException('Sequenza non trovata.');
    }

    $pdo->prepare(
        'UPDATE sequenze SET prefisso = ?, anno = ?, numero_corrente = ?, padding = ? WHERE id = ?'
    )->execute([$prefisso, $anno, $numero, $padding, $id]);
}

/**
 * Ricrea una riga mancante di `sequenze` per un tipo scelto da un elenco chiuso
 * (QK_SEQUENZE_TIPI_NOTI), non da testo libero — evita di creare per errore un
 * tipo mai usato da nessun chiamante reale. Inserisce sempre con
 * numero_corrente = 0 (mai un auto-repair "indovinato": l'operatore sceglie
 * esplicitamente prefisso e padding).
 *
 * @throws \InvalidArgumentException se il tipo non è nell'elenco chiuso, se
 *         esiste già una riga per quel tipo, o se i valori non sono validi
 */
function qk_sequenza_crea_mancante(PDO $pdo, string $tipo, string $prefisso, int $padding, ?int $anno = null): int
{
    if (!in_array($tipo, QK_SEQUENZE_TIPI_NOTI, true)) {
        throw new \InvalidArgumentException("Tipo di sequenza '$tipo' non riconosciuto.");
    }
    $prefisso = trim($prefisso);
    if ($prefisso === '') {
        throw new \InvalidArgumentException('Il prefisso non può essere vuoto.');
    }
    if ($padding < 1 || $padding > 8) {
        throw new \InvalidArgumentException('Il padding deve essere compreso tra 1 e 8 cifre.');
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM sequenze WHERE tipo = ?');
    $st->execute([$tipo]);
    if ((int)$st->fetchColumn() > 0) {
        throw new \InvalidArgumentException("Esiste già una sequenza per il tipo '$tipo'.");
    }

    $anno = $anno ?? (int) date('Y');
    $pdo->prepare(
        'INSERT INTO sequenze (tipo, prefisso, anno, numero_corrente, padding) VALUES (?, ?, ?, 0, ?)'
    )->execute([$tipo, $prefisso, $anno, $padding]);

    return (int) $pdo->lastInsertId();
}

/**
 * Badge di stato per le offerte di vendita.
 * Spostata da quotes/_list.php a functions.php globale.
 */
function prev_stato_badge(string $stato): string {
    $map = [
        'bozza'     => ['draft', 'Bozza'],
        'inviato'   => ['confirmed', 'Inviato'],
        'accettato' => ['active', 'Accettato'],
        'rifiutato' => ['cancelled', 'Rifiutato'],
        'scaduto'   => ['warning', 'Scaduto'],
        'annullato' => ['cancelled', 'Annullato']
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', ucfirst($stato)];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

