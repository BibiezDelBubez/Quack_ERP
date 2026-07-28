#!/usr/bin/env bash
# tests/manual/e2e_errors_500_server.sh
#
# PIANO Punti 1-2-3 Basso Rischio, Punto 1 (27/07/2026) — Livello 3 della
# batteria di test pianificata in §1.7: test end-to-end reale, via un vero
# ciclo HTTP (server built-in di PHP, tools/dev-php-sandbox), degli unici due
# punti che NON sono richiamabili in isolamento da CLI perché sono stato
# globale di processo (set_exception_handler/register_shutdown_function) o
# dipendono da header HTTP realmente trasmessi (headers_list(), verificato
# empiricamente non funzionare sotto SAPI "cli" puro — vedi
# tests/manual/suite_errors_500_view.php per i livelli 1-2 e la nota).
#
# Tre scenari, tramite tre endpoint di test dedicati definiti SOLO nel
# router.php temporaneo di questo script (mai nelle rotte reali dell'app):
#   1. /__test_exception_html — una vera \RuntimeException non gestita →
#      verifica status 500, HTML, messaggio generico, codice di riferimento
#      presente, NESSUN dettaglio tecnico (nome classe, file, stack trace).
#   2. /__test_exception_json — stessa eccezione ma con
#      "Content-Type: application/json" già impostato prima del throw →
#      verifica status 500, corpo JSON valido {ok:false, error, ref}.
#   3. /__test_fatal_error — chiamata a una funzione inesistente (E_ERROR
#      reale, non catchabile da set_exception_handler, solo dallo shutdown
#      handler) → verifica status 500 (Estensione B: PRIMA di questa
#      modifica lo shutdown handler non produceva ALCUNA risposta HTTP per
#      questo caso — il browser riceveva una risposta vuota/troncata).
#
# APP_ENV viene forzata a 'production' SOLO nel processo di test (stesso
# trucco "vince la prima define()" già documentato in
# tools/dev-php-sandbox/README.md per DB_DIR/UPLOAD_DIR) — sul resto
# dell'app, in questa sessione, APP_ENV resta 'development' come da
# includes/config.php:23.
#
# Uso:
#   bash tools/dev-php-sandbox/setup.sh   # una tantum, se non già fatto
#   bash tests/manual/e2e_errors_500_server.sh
#
# Nota tecnica (gotcha #3 di tools/dev-php-sandbox/run-test-server.sh): avvio
# server + curl + verifica + kill devono stare nella STESSA invocazione di
# shell — i processi in background non sopravvivono a chiamate separate in
# questo ambiente. Per questo lo script è autonomo (non sorgenta
# run-test-server.sh, ne replica solo il pattern) e fa tutto in un unico
# processo bash dall'inizio alla fine.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUACK_ROOT="$(cd "$HERE/../.." && pwd)"

# Il runtime PHP puo' vivere in tools/dev-php-sandbox/runtime (se setup.sh e'
# stato lanciato dalla sua posizione originale) oppure altrove se lanciato
# diversamente in questa sessione — prova entrambi, poi arrende con un
# messaggio chiaro invece di un errore criptico.
PHP=""
for candidate in "$QUACK_ROOT/tools/dev-php-sandbox/runtime/php" "/tmp/runtime/php"; do
    if [ -x "$candidate" ]; then PHP="$candidate"; break; fi
done
if [ -z "$PHP" ]; then
    echo "PHP runtime non trovato. Lancia prima: bash tools/dev-php-sandbox/setup.sh" >&2
    exit 1
fi

SCRATCH="/tmp/quack_e2e_500_test_$$"
PORT="${1:-8153}"
mkdir -p "$SCRATCH/db" "$SCRATCH/uploads" "$SCRATCH/sessions"

cat > "$SCRATCH/router.php" <<EOF
<?php
define('DB_DIR', '$SCRATCH/db');
define('UPLOAD_DIR', '$SCRATCH/uploads');
define('APP_ENV', 'production');
define('QK_ERROR_REPORT_ENABLED', true);
define('QK_ERROR_LOG_FILE', '$SCRATCH/errors.md');
define('QK_ERROR_THROTTLE_FILE', '$SCRATCH/.throttle.json');
define('QK_ERROR_QUEUE_FILE', '$SCRATCH/.mail_queue.json');
define('QK_ERROR_EMAIL_TO', ''); // niente mail/curl reali durante il test

\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (\$uri === '/__test_exception_html') {
    require \$_SERVER['DOCUMENT_ROOT'] . '/app/bootstrap.php';
    throw new \RuntimeException('Errore di test E2E - Punto 1 (HTML)');
}
if (\$uri === '/__test_exception_json') {
    require \$_SERVER['DOCUMENT_ROOT'] . '/app/bootstrap.php';
    header('Content-Type: application/json; charset=utf-8');
    throw new \RuntimeException('Errore di test E2E - Punto 1 (JSON)');
}
if (\$uri === '/__test_fatal_error') {
    require \$_SERVER['DOCUMENT_ROOT'] . '/app/bootstrap.php';
    qk_funzione_test_e2e_inesistente_xyz();
}

require \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

# nohup + disown, non un semplice '&': verificato empiricamente in questa
# sessione che un '&' semplice, seguito da 'wait $SPID' dopo il kill, può
# restare bloccato in questo sandbox (il processo in background non si
# stacca completamente dalla shell che lo ha lanciato) — nohup+disown lo
# scollega davvero, e si evita 'wait' dopo il kill per lo stesso motivo.
nohup "$PHP" -d session.save_path="$SCRATCH/sessions" -S "127.0.0.1:$PORT" -t "$QUACK_ROOT" "$SCRATCH/router.php" \
    > "$SCRATCH/server.log" 2>&1 &
SPID=$!
disown
sleep 2

FAIL=0
TOTAL=0

check() {
    local label="$1" cond="$2"
    TOTAL=$((TOTAL+1))
    if [ "$cond" = "1" ]; then
        echo "OK   - $label"
    else
        FAIL=$((FAIL+1))
        echo "FAIL - $label"
    fi
}

# ── Scenario 1: eccezione reale, risposta HTML ──────────────────────────────
STATUS1=$(curl -s -o "$SCRATCH/out1.txt" -w "%{http_code}" "http://127.0.0.1:$PORT/__test_exception_html")
BODY1=$(cat "$SCRATCH/out1.txt")
check "Scenario 1 (eccezione/HTML): status HTTP 500"                              "$([ "$STATUS1" = "500" ] && echo 1 || echo 0)"
check "Scenario 1 (eccezione/HTML): contiene il doctype HTML"                     "$(echo "$BODY1" | grep -q '<!DOCTYPE html>' && echo 1 || echo 0)"
check "Scenario 1 (eccezione/HTML): messaggio generico rassicurante presente"     "$(echo "$BODY1" | grep -q 'Qualcosa si è inceppato' && echo 1 || echo 0)"
check "Scenario 1 (eccezione/HTML): codice di riferimento (hash) presente"        "$(echo "$BODY1" | grep -q 'Codice errore' && echo 1 || echo 0)"
check "Scenario 1 (eccezione/HTML): NESSUN dettaglio tecnico (nome classe/e.g. RuntimeException)" "$(echo "$BODY1" | grep -q 'RuntimeException' && echo 0 || echo 1)"
check "Scenario 1 (eccezione/HTML): NESSUN percorso file (es. .php:)"             "$(echo "$BODY1" | grep -q '\.php:' && echo 0 || echo 1)"

# ── Scenario 2: eccezione reale, risposta JSON (content-negotiation) ────────
STATUS2=$(curl -s -o "$SCRATCH/out2.txt" -w "%{http_code}" "http://127.0.0.1:$PORT/__test_exception_json")
BODY2=$(cat "$SCRATCH/out2.txt")
check "Scenario 2 (eccezione/JSON): status HTTP 500"                              "$([ "$STATUS2" = "500" ] && echo 1 || echo 0)"
check "Scenario 2 (eccezione/JSON): corpo è JSON valido"                          "$("$PHP" -r 'exit(json_decode(file_get_contents($argv[1])) === null ? 1 : 0);' "$SCRATCH/out2.txt" && echo 1 || echo 0)"
check "Scenario 2 (eccezione/JSON): ok:false"                                     "$(echo "$BODY2" | grep -q '"ok":false' && echo 1 || echo 0)"
check "Scenario 2 (eccezione/JSON): campo ref presente (32 caratteri esadecimali)" "$(echo "$BODY2" | grep -qE '"ref":"[0-9a-f]{32}"' && echo 1 || echo 0)"
check "Scenario 2 (eccezione/JSON): NESSUN dettaglio tecnico nel messaggio"        "$(echo "$BODY2" | grep -q 'RuntimeException' && echo 0 || echo 1)"

# ── Scenario 3: errore fatale reale — Estensione B (shutdown handler) ───────
STATUS3=$(curl -s -o "$SCRATCH/out3.txt" -w "%{http_code}" "http://127.0.0.1:$PORT/__test_fatal_error")
BODY3=$(cat "$SCRATCH/out3.txt")
check "Scenario 3 (errore fatale): status HTTP 500 (PRIMA della fix: nessuna risposta HTTP)" "$([ "$STATUS3" = "500" ] && echo 1 || echo 0)"
check "Scenario 3 (errore fatale): pagina 500 completa prodotta (non vuota/troncata)"        "$([ -s "$SCRATCH/out3.txt" ] && echo "$BODY3" | grep -q 'Qualcosa si è inceppato' && echo 1 || echo 0)"
check "Scenario 3 (errore fatale): codice di riferimento presente anche per il fatal"        "$(echo "$BODY3" | grep -q 'Codice errore' && echo 1 || echo 0)"

kill -9 $SPID 2>/dev/null || true
rm -rf "$SCRATCH"

echo
if [ "$FAIL" = "0" ]; then
    echo "TUTTI I TEST OK ($TOTAL/$TOTAL)"
    exit 0
else
    echo "$FAIL/$TOTAL TEST FALLITI"
    exit 1
fi
