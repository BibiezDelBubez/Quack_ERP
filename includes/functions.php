<?php
/**
 * functions.php — helper globali dell'app
 * Includi DOPO config.php
 */

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
    return is_array($user) ? $user : null;
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
function login_attempt(string $input, string $password): bool {
    global $pdo;

    if (isset($pdo)) {
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

/* ── Input helpers ────────────────────────────────────────────────────────── */

/** Restituisce un valore POST escaped, o il default */
function post(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/** Restituisce un valore GET escaped, o il default */
function get_param(string $key, string $default = ''): string {
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
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

    $pad = (int) $seq['padding'];
    return $seq['prefisso'] . $anno . '/' . str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
}

/**
 * Badge di stato per le offerte di vendita.
 * Spostata da quotes/_list.php a functions.php globale.
 */
function prev_stato_badge(string $stato): string {
    $map = [
        'bozza'     => ['draft', 'Bozza'],
        'inviata'   => ['confirmed', 'Inviata'],
        'accettata' => ['active', 'Accettata'],
        'rifiutata' => ['cancelled', 'Rifiutata'],
        'scaduta'   => ['warning', 'Scaduta'],
        'annullata' => ['cancelled', 'Annullata']
    ];
    [$cls, $lbl] = $map[$stato] ?? ['draft', ucfirst($stato)];
    return '<span class="qk-badge ' . $cls . '">' . e($lbl) . '</span>';
}

