<?php
/**
 * error_reporter.php — Error reporting via email + log MD
 *
 * Come usare:
 *   1. Configura QK_ERROR_* in includes/config.php (vedi sotto)
 *   2. Includi questo file in app/bootstrap.php DOPO config.php
 *
 * Costanti da definire in config.php:
 *   QK_ERROR_REPORT_ENABLED  bool   — abilita tutto il sistema
 *   QK_ERROR_EMAIL_TO        string — destinatario (es. 'tuo@email.it')
 *   QK_ERROR_EMAIL_FROM      string — mittente
 *   QK_ERROR_LOG_FILE        string — path assoluto del file .md di log
 *   QK_ERROR_MAIL_PROVIDER   string — 'smtp' | 'resend' | 'mailgun'
 *
 * Per SMTP (PHPMailer):
 *   QK_SMTP_HOST, QK_SMTP_PORT, QK_SMTP_USER, QK_SMTP_PASS, QK_SMTP_SECURE
 *
 * Per Resend:
 *   QK_RESEND_API_KEY
 *
 * Per Mailgun:
 *   QK_MAILGUN_API_KEY, QK_MAILGUN_DOMAIN
 *
 * Rate limit: una mail per hash-errore ogni QK_ERROR_THROTTLE_MINUTES (default 30).
 * Il file .md riceve TUTTI gli errori senza throttle.
 */

declare(strict_types=1);

/* ── Valori di default per le costanti opzionali ─────────────────────────── */
if (!defined('QK_ERROR_REPORT_ENABLED'))    define('QK_ERROR_REPORT_ENABLED',    false);
if (!defined('QK_ERROR_EMAIL_TO'))          define('QK_ERROR_EMAIL_TO',          '');
if (!defined('QK_ERROR_EMAIL_FROM'))        define('QK_ERROR_EMAIL_FROM',        'noreply@quack-erp.local');
if (!defined('QK_ERROR_LOG_FILE'))          define('QK_ERROR_LOG_FILE',          APP_ROOT_DIR . '/logs/errors.md');
if (!defined('QK_ERROR_MAIL_PROVIDER'))     define('QK_ERROR_MAIL_PROVIDER',     'smtp');
if (!defined('QK_ERROR_THROTTLE_MINUTES')) define('QK_ERROR_THROTTLE_MINUTES',  30);
if (!defined('QK_ERROR_THROTTLE_FILE'))    define('QK_ERROR_THROTTLE_FILE',     APP_ROOT_DIR . '/logs/.throttle.json');

/* ── Tipi di errore PHP → stringa leggibile ──────────────────────────────── */
function _qk_err_type_label(int $type): string {
    return match($type) {
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        default             => "E_UNKNOWN($type)",
    };
}

/* ── Costruisce il blocco Markdown per un errore ─────────────────────────── */
function _qk_err_build_md(string $level, string $type, string $message, string $file, int $line, string $trace, array $context = []): string
{
    $ts      = date('Y-m-d H:i:s');
    $url     = (isset($_SERVER['REQUEST_URI']) ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] : 'CLI');
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $user    = 'n/a';
    if (isset($_SESSION['user_id'])) {
        $user = ($_SESSION['username'] ?? '') . ' (ID ' . $_SESSION['user_id'] . ')';
    }

    // POST/GET sanitizzati — oscura campi sensibili
    $sensitiveKeys = ['password', 'passwd', 'token', 'secret', 'api_key', 'csrf'];
    $sanitize = function(array $arr) use ($sensitiveKeys): array {
        foreach ($arr as $k => &$v) {
            foreach ($sensitiveKeys as $s) {
                if (stripos((string)$k, $s) !== false) { $v = '***'; break; }
            }
        }
        return $arr;
    };
    $get  = !empty($_GET)  ? json_encode($sanitize($_GET),  JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '{}';
    $post = !empty($_POST) ? json_encode($sanitize($_POST), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '{}';

    // Contesto extra (ultima query SQL se passata)
    $ctxBlock = '';
    if (!empty($context)) {
        $ctxBlock = "\n**Contesto:**\n```\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n";
    }

    $relFile      = str_replace(APP_ROOT_DIR, '', $file);
    $phpSoftware  = $_SERVER['SERVER_SOFTWARE'] ?? 'n/a';

    return <<<MD

---

## [{$ts}] {$level}: {$type}

**Messaggio:** {$message}
**File:** `{$relFile}` riga **{$line}**
**URL:** `{$method} {$url}`
**Utente:** {$user}
**PHP:** {$phpSoftware} | PHP {PHP_VERSION}
{$ctxBlock}
**GET:**
```json
{$get}
```

**POST:**
```json
{$post}
```

**Stack trace:**
```
{$trace}
```

MD;
}

/* ── Rate limiting per le mail ───────────────────────────────────────────── */
function _qk_err_should_mail(string $hash): bool
{
    if (QK_ERROR_THROTTLE_MINUTES <= 0) return true;
    $throttleFile = QK_ERROR_THROTTLE_FILE;
    $data = [];
    if (file_exists($throttleFile)) {
        $data = json_decode(file_get_contents($throttleFile), true) ?? [];
    }
    $now     = time();
    $cutoff  = $now - (QK_ERROR_THROTTLE_MINUTES * 60);
    // Pulisce hash vecchi
    $data = array_filter($data, fn($ts) => $ts > $cutoff);

    if (isset($data[$hash]) && $data[$hash] > $cutoff) {
        return false; // già mandata di recente
    }
    $data[$hash] = $now;
    @file_put_contents($throttleFile, json_encode($data), LOCK_EX);
    return true;
}

/* ── Append sul file MD ──────────────────────────────────────────────────── */
function _qk_err_log_md(string $md): void
{
    $logFile = QK_ERROR_LOG_FILE;
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Header del file se nuovo
    if (!file_exists($logFile)) {
        $header = "# Quack ERP — Error Log\n\nGenerato automaticamente. Non committare questo file.\n";
        @file_put_contents($logFile, $header, LOCK_EX);
    }
    @file_put_contents($logFile, $md, FILE_APPEND | LOCK_EX);
}

/* ── Invio email ─────────────────────────────────────────────────────────── */
function _qk_err_send_mail(string $subject, string $mdBody): void
{
    if (QK_ERROR_EMAIL_TO === '') return;

    $provider = QK_ERROR_MAIL_PROVIDER;

    if ($provider === 'resend') {
        _qk_err_send_resend($subject, $mdBody);
    } elseif ($provider === 'mailgun') {
        _qk_err_send_mailgun($subject, $mdBody);
    } else {
        _qk_err_send_smtp($subject, $mdBody);
    }
}

function _qk_err_send_smtp(string $subject, string $body): void
{
    // Usa PHPMailer se disponibile (composer require phpmailer/phpmailer)
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = defined('QK_SMTP_HOST')   ? QK_SMTP_HOST   : 'localhost';
            $mail->Port       = defined('QK_SMTP_PORT')   ? QK_SMTP_PORT   : 587;
            $mail->SMTPAuth   = defined('QK_SMTP_USER')   && QK_SMTP_USER !== '';
            $mail->Username   = defined('QK_SMTP_USER')   ? QK_SMTP_USER   : '';
            $mail->Password   = defined('QK_SMTP_PASS')   ? QK_SMTP_PASS   : '';
            $mail->SMTPSecure = defined('QK_SMTP_SECURE') ? QK_SMTP_SECURE : 'tls';
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(QK_ERROR_EMAIL_FROM, APP_NAME . ' Error Reporter');
            $mail->addAddress(QK_ERROR_EMAIL_TO);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
        } catch (\Throwable $e) {
            error_log('[Quack ErrorReporter] PHPMailer fail: ' . $e->getMessage());
        }
        return;
    }

    // Fallback senza dipendenze: SMTP via socket PHP puro (funziona su XAMPP)
    _qk_err_send_smtp_raw($subject, $body);
}

function _qk_err_send_smtp_raw(string $subject, string $body): void
{
    $host   = defined('QK_SMTP_HOST') ? QK_SMTP_HOST : 'localhost';
    $port   = defined('QK_SMTP_PORT') ? (int)QK_SMTP_PORT : 587;
    $user   = defined('QK_SMTP_USER') ? QK_SMTP_USER : '';
    $pass   = defined('QK_SMTP_PASS') ? QK_SMTP_PASS : '';
    $from   = QK_ERROR_EMAIL_FROM;
    $to     = QK_ERROR_EMAIL_TO;

    // Costruisce il messaggio MIME minimo
    $boundary = md5(uniqid('', true));
    $headers  = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . APP_NAME . ' Error Reporter <' . $from . '>',
        'To: ' . $to,
        'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
        'Date: ' . date('r'),
    ]);
    $message = $headers . "\r\n\r\n" . $body;

    try {
        // Connessione STARTTLS su porta 587
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]);
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            error_log("[Quack ErrorReporter] SMTP socket fail: $errstr ($errno)");
            return;
        }
        stream_set_timeout($sock, 10);

        $read = fn() => fgets($sock, 512);
        $send = function(string $cmd) use ($sock, $read): string {
            fwrite($sock, $cmd . "\r\n");
            return $read();
        };

        $read(); // 220 banner
        $send("EHLO " . gethostname());
        $read(); $read(); $read(); $read(); $read(); // legge le capability lines (multiplo)
        $send("STARTTLS");
        $read(); // 220 Go ahead
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO " . gethostname());
        $read(); $read(); $read(); $read(); $read();
        $send("AUTH LOGIN");
        $read();
        $send(base64_encode($user));
        $read();
        $send(base64_encode($pass));
        $read(); // 235 Authentication successful
        $send("MAIL FROM:<{$from}>");
        $read();
        $send("RCPT TO:<{$to}>");
        $read();
        $send("DATA");
        $read(); // 354
        fwrite($sock, $message . "\r\n.\r\n");
        $read(); // 250 OK
        $send("QUIT");
        fclose($sock);
    } catch (\Throwable $e) {
        error_log('[Quack ErrorReporter] SMTP raw fail: ' . $e->getMessage());
    }
}

function _qk_err_send_resend(string $subject, string $body): void
{
    if (!defined('QK_RESEND_API_KEY')) return;
    $payload = json_encode([
        'from'    => QK_ERROR_EMAIL_FROM,
        'to'      => [QK_ERROR_EMAIL_TO],
        'subject' => $subject,
        'text'    => $body,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . QK_RESEND_API_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('[Quack ErrorReporter] Resend fail: ' . curl_error($ch));
    }
    curl_close($ch);
}

function _qk_err_send_mailgun(string $subject, string $body): void
{
    if (!defined('QK_MAILGUN_API_KEY') || !defined('QK_MAILGUN_DOMAIN')) return;
    $ch = curl_init('https://api.mailgun.net/v3/' . QK_MAILGUN_DOMAIN . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => 'api:' . QK_MAILGUN_API_KEY,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'from'    => QK_ERROR_EMAIL_FROM,
            'to'      => QK_ERROR_EMAIL_TO,
            'subject' => $subject,
            'text'    => $body,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('[Quack ErrorReporter] Mailgun fail: ' . curl_error($ch));
    }
    curl_close($ch);
}

/* ── Handler principale ──────────────────────────────────────────────────── */
function _qk_handle_error(string $level, string $type, string $message, string $file, int $line, string $trace, array $context = []): void
{
    if (!QK_ERROR_REPORT_ENABLED) return;

    $md   = _qk_err_build_md($level, $type, $message, $file, $line, $trace, $context);
    $hash = md5($type . $file . $line . $message);

    _qk_err_log_md($md);

    if (_qk_err_should_mail($hash)) {
        $relFile = basename($file);
        $subject = '[' . APP_NAME . '] ' . $level . ': ' . $type . ' in ' . $relFile . ':' . $line;
        _qk_err_send_mail($subject, strip_tags($md));
    }
}

/* ── Registra exception handler ──────────────────────────────────────────── */
set_exception_handler(function (\Throwable $e): void {
    _qk_handle_error(
        'EXCEPTION',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    // In development mostra comunque l'errore a schermo
    if (APP_ENV === 'development') {
        echo '<pre style="background:#1a1a1a;color:#f87171;padding:1rem;margin:1rem;border-radius:6px;font-size:.85rem">';
        echo '<b>' . get_class($e) . '</b>: ' . htmlspecialchars($e->getMessage()) . "\n";
        echo 'in ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        http_response_code(500);
        include APP_ROOT_DIR . '/app/views/errors/500.php';
    }
    exit(1);
});

/* ── Registra error handler (warning, notice, ecc.) ─────────────────────── */
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Rispetta l'operatore @ (error suppression)
    if (!(error_reporting() & $errno)) return false;

    // Warning e notice: log MD senza mail (troppo rumorosi)
    $isFatal = in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

    _qk_handle_error(
        $isFatal ? 'ERROR' : 'WARNING',
        _qk_err_type_label($errno),
        $errstr,
        $errfile,
        $errline,
        (new \Exception())->getTraceAsString()
    );

    return false; // lascia proseguire il comportamento PHP di default
});

/* ── shutdown handler per errori fatali non catchabili ───────────────────── */
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    _qk_handle_error(
        'FATAL',
        _qk_err_type_label($err['type']),
        $err['message'],
        $err['file'],
        $err['line'],
        'N/A (errore fatale, stack trace non disponibile)'
    );
});
