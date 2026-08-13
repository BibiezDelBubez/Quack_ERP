#!/usr/bin/env bash
# tests/manual/http/run_piano11_e2e.sh
#
# PIANO - Architettura UX Preventivo-Ordine-Spedizione.md, §12 (Layer 2).
#
# Test comportamentale end-to-end REALE: avvia il vero dev server PHP sopra
# il codice vero di Quack (stesso meccanismo di
# tools/dev-php-sandbox/run-test-server.sh), semina 8 ordini fixture in un DB
# SQLite temporaneo, fa un login HTTP vero (sessione + CSRF, non simulato),
# scarica con curl le pagine ordine vere, e le verifica con
# assert_piano11_e2e.php.
#
# Deve girare TUTTO in una sola invocazione di shell (seed, avvio server,
# curl, stop server): i processi in background non sopravvivono tra una
# chiamata shell e l'altra in questo sandbox (stesso gotcha #3 documentato in
# tools/dev-php-sandbox/run-test-server.sh).
#
# Uso:
#   bash tests/manual/http/run_piano11_e2e.sh [pre|post] [porta]
#
#   "pre"  (default) — da lanciare COSI' COM'E' ORA, PRIMA di applicare i
#          diff del §11 alla vista: il Gruppo B di assert_piano11_e2e.php è
#          atteso in gran parte rosso (prova che il test individua
#          correttamente il comportamento vecchio/il bug).
#   "post" — da rilanciare DOPO aver applicato i diff del §11 alla vista
#          _detail.php: atteso tutto verde, Gruppo A e Gruppo B.
#
# Uscita: 0 solo se il Gruppo A (invarianti) è tutto verde E (se modo=post)
# anche il Gruppo B lo è. In modo "pre" il Gruppo B rosso non fa fallire lo
# script (è il comportamento atteso), solo il Gruppo A conta.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUACK_ROOT="$(cd "$HERE/../../.." && pwd)"
PHP="$QUACK_ROOT/tools/dev-php-sandbox/runtime/php"
if [ ! -x "$PHP" ]; then
    PHP="/tmp/runtime/php"   # fallback: percorso usato in questa sessione se setup.sh è stato lanciato con prefix diverso
fi
if [ ! -x "$PHP" ]; then
    echo "PHP non pronto in nessuno dei due percorsi noti. Lancia prima: bash tools/dev-php-sandbox/setup.sh" >&2
    exit 1
fi

MODO="${1:-pre}"
PORT="${2:-8199}"
SCRATCH="/tmp/quack_piano11_e2e_$$"
rm -rf "$SCRATCH"
mkdir -p "$SCRATCH/db" "$SCRATCH/uploads" "$SCRATCH/sessions" "$SCRATCH/responses"

echo "[run_piano11_e2e] Scratch: $SCRATCH  Porta: $PORT  Modo: $MODO"

# ── 1. Seed (PRIMA di avviare il server, per evitare una corsa sulla stessa
#       inizializzazione dello schema SQLite — vedi commento nel seed stesso) ──
echo "[run_piano11_e2e] Seed fixture..."
"$PHP" -r "define('DB_DIR', '$SCRATCH/db'); require '$HERE/seed_piano11_e2e.php';" 2>"$SCRATCH/seed.stderr.log"
SEED_EXIT=$?
if [ $SEED_EXIT -ne 0 ]; then
    echo "[run_piano11_e2e] ERRORE: seed fallito (exit $SEED_EXIT). Log:" >&2
    cat "$SCRATCH/seed.stderr.log" >&2
    exit 1
fi
if [ ! -f "$SCRATCH/fixtures.env" ]; then
    echo "[run_piano11_e2e] ERRORE: fixtures.env non scritto dal seed." >&2
    exit 1
fi
source "$SCRATCH/fixtures.env"

# ── 2. Avvio server reale in background, stesso identico meccanismo di
#       tools/dev-php-sandbox/run-test-server.sh (router.php con le define
#       PRIMA di index.php — gotcha #1 di quello script) ──────────────────
cat > "$SCRATCH/router.php" <<EOF
<?php
define('DB_DIR', '$SCRATCH/db');
define('UPLOAD_DIR', '$SCRATCH/uploads');
require \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

"$PHP" -d session.save_path="$SCRATCH/sessions" \
    -S "127.0.0.1:$PORT" -t "$QUACK_ROOT" "$SCRATCH/router.php" \
    > "$SCRATCH/server.log" 2>&1 &
SERVER_PID=$!
echo "[run_piano11_e2e] Server avviato (pid $SERVER_PID), attendo che risponda..."

READY=0
for i in $(seq 1 30); do
    if curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/login" 2>/dev/null | grep -q '^[0-9]'; then
        READY=1
        break
    fi
    sleep 0.3
done

cleanup() {
    kill "$SERVER_PID" 2>/dev/null
    wait "$SERVER_PID" 2>/dev/null
}
trap cleanup EXIT

if [ "$READY" -ne 1 ]; then
    echo "[run_piano11_e2e] ERRORE: il server non ha risposto in tempo. Log server:" >&2
    cat "$SCRATCH/server.log" >&2
    exit 1
fi

# ── 3. Login HTTP vero: GET /login per csrf+cookie, POST credenziali ───────
COOKIEJAR="$SCRATCH/cookies.txt"
LOGIN_PAGE="$SCRATCH/login_get.html"
curl -s -c "$COOKIEJAR" "http://127.0.0.1:$PORT/login" -o "$LOGIN_PAGE"

CSRF_TOKEN=$(grep -o 'name="csrf_token" value="[^"]*"' "$LOGIN_PAGE" | head -1 | sed -E 's/.*value="([^"]*)".*/\1/')
if [ -z "$CSRF_TOKEN" ]; then
    echo "[run_piano11_e2e] ERRORE: impossibile estrarre il csrf_token dalla pagina di login." >&2
    echo "[run_piano11_e2e] Primi 500 caratteri della risposta:" >&2
    head -c 500 "$LOGIN_PAGE" >&2
    exit 1
fi
echo "[run_piano11_e2e] CSRF token ottenuto (${#CSRF_TOKEN} caratteri)."

LOGIN_RESP_HEADERS="$SCRATCH/login_post_headers.txt"
curl -s -b "$COOKIEJAR" -c "$COOKIEJAR" -D "$LOGIN_RESP_HEADERS" -o "$SCRATCH/login_post.html" \
    --data-urlencode "username=$USERNAME" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "_skin=A" \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    "http://127.0.0.1:$PORT/login"

if ! grep -qi '^Location:.*dashboard' "$LOGIN_RESP_HEADERS"; then
    echo "[run_piano11_e2e] ERRORE: login non riuscito (nessun redirect a /dashboard). Header ricevuti:" >&2
    cat "$LOGIN_RESP_HEADERS" >&2
    echo "[run_piano11_e2e] Corpo risposta login:" >&2
    cat "$SCRATCH/login_post.html" >&2
    exit 1
fi
echo "[run_piano11_e2e] Login riuscito (redirect a /dashboard confermato)."

# ── 4. GET reali sulla pagina ordine, per ognuna delle 8 fixture ───────────
declare -A FIXTURE_IDS=(
    [ORDER_IN_LAVORAZIONE]="$ORDER_IN_LAVORAZIONE"
    [ORDER_PARZIALE]="$ORDER_PARZIALE"
    [ORDER_SPEDITO]="$ORDER_SPEDITO"
    [ORDER_COMPLETATO]="$ORDER_COMPLETATO"
    [ORDER_ANNULLATO]="$ORDER_ANNULLATO"
    [ORDER_SUPERATO]="$ORDER_SUPERATO"
    [ORDER_MULTISPED]="$ORDER_MULTISPED"
    [ORDER_FRAZIONARIO]="$ORDER_FRAZIONARIO"
)

for key in "${!FIXTURE_IDS[@]}"; do
    id="${FIXTURE_IDS[$key]}"
    http_code=$(curl -s -b "$COOKIEJAR" -o "$SCRATCH/responses/$key.html" -w '%{http_code}' \
        "http://127.0.0.1:$PORT/sales/orders/edit?id=$id")
    echo "[run_piano11_e2e] GET $key (ordine id $id) -> HTTP $http_code"
done

# ── 5. Verifica con lo script PHP dedicato ──────────────────────────────────
echo
echo "[run_piano11_e2e] === Esecuzione assert_piano11_e2e.php (modo: $MODO) ==="
"$PHP" "$HERE/assert_piano11_e2e.php" "$SCRATCH/responses" "$MODO"
ASSERT_EXIT=$?

echo
echo "[run_piano11_e2e] Scratch conservato in: $SCRATCH (per ispezione manuale, non viene ripulito automaticamente)"
echo "[run_piano11_e2e] Exit finale: $ASSERT_EXIT"
exit $ASSERT_EXIT
