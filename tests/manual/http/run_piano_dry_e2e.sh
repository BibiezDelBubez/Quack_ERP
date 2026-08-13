#!/usr/bin/env bash
# tests/manual/http/run_piano_dry_e2e.sh
#
# PIANO - Correzione DRY-MVC Viste Documenti Vendita e Acquisto.md, §5.2.
#
# Golden-master "prima/dopo": avvia il vero dev server PHP sopra il codice
# vero di Quack (stesso meccanismo di tools/dev-php-sandbox/run-test-server.sh
# e di run_piano11_e2e.sh), semina un utente minimo con seed_piano_dry_e2e.php,
# fa un login HTTP vero, scarica con curl le 3 pagine di creazione documento
# (Ordine Cliente, RFQ, Preventivo) e le salva in responses/<modo>/*.html per
# il confronto fatto da diff_piano_dry_e2e.php.
#
# Deve girare TUTTO in una sola invocazione di shell (seed, avvio server,
# curl, stop server) — stesso gotcha già documentato in run_piano11_e2e.sh.
#
# Uso:
#   bash tests/manual/http/run_piano_dry_e2e.sh [pre|post] [porta] [scratch_fisso]
#
#   "pre"  — da lanciare PRIMA di toccare qualunque vista, cattura il
#            riferimento con cui tutto il resto verrà confrontato.
#   "post" — da rilanciare dopo ogni fase del piano, sullo STESSO scratch
#            (terzo argomento) usato per "pre", cosi' diff_piano_dry_e2e.php
#            puo' confrontare le due cartelle responses/pre e responses/post.
#
# Se lo scratch_fisso non è passato, ne viene creato uno nuovo e stampato a
# schermo (da riusare esplicitamente per il/i run "post" successivi).

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUACK_ROOT="$(cd "$HERE/../../.." && pwd)"
PHP="$QUACK_ROOT/tools/dev-php-sandbox/runtime/php"
if [ ! -x "$PHP" ]; then
    PHP="/tmp/runtime/php"
fi
if [ ! -x "$PHP" ]; then
    echo "PHP non pronto in nessuno dei due percorsi noti. Lancia prima: bash tools/dev-php-sandbox/setup.sh" >&2
    exit 1
fi

MODO="${1:-pre}"
PORT="${2:-8299}"
SCRATCH="${3:-/tmp/quack_piano_dry_e2e_$$}"
mkdir -p "$SCRATCH/db" "$SCRATCH/uploads" "$SCRATCH/sessions" "$SCRATCH/responses/$MODO"

echo "[run_piano_dry_e2e] Scratch: $SCRATCH  Porta: $PORT  Modo: $MODO"

# ── 1. Seed. In modo "post" il DB e' gia' seminato dal run "pre" precedente
#       sullo stesso scratch: risemino comunque (idempotente: DELETE+INSERT
#       sull'utente, azienda ricreata solo se assente non e' garantito quindi
#       la si accetta duplicata — non influisce sull'HTML delle pagine di
#       creazione, che non elenca aziende per unicita' di nome) per tenere
#       run "pre" e "post" completamente indipendenti l'uno dall'altro anche
#       se lanciati su scratch diversi. ────────────────────────────────────
if [ ! -f "$SCRATCH/db/quack.sqlite" ] && [ ! -f "$SCRATCH/fixtures.env" ]; then
    echo "[run_piano_dry_e2e] Seed fixture (primo avvio su questo scratch)..."
    "$PHP" -r "define('DB_DIR', '$SCRATCH/db'); require '$HERE/seed_piano_dry_e2e.php';" 2>"$SCRATCH/seed.stderr.log"
    SEED_EXIT=$?
    if [ $SEED_EXIT -ne 0 ]; then
        echo "[run_piano_dry_e2e] ERRORE: seed fallito (exit $SEED_EXIT). Log:" >&2
        cat "$SCRATCH/seed.stderr.log" >&2
        exit 1
    fi
else
    echo "[run_piano_dry_e2e] DB/fixtures gia' presenti su questo scratch, riuso senza riseminare (run 'post' sullo stesso scratch del 'pre')."
fi
if [ ! -f "$SCRATCH/fixtures.env" ]; then
    echo "[run_piano_dry_e2e] ERRORE: fixtures.env non presente." >&2
    exit 1
fi
source "$SCRATCH/fixtures.env"

# ── 2. Avvio server reale in background ─────────────────────────────────────
cat > "$SCRATCH/router.php" <<EOF
<?php
define('DB_DIR', '$SCRATCH/db');
define('UPLOAD_DIR', '$SCRATCH/uploads');
require \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

"$PHP" -d session.save_path="$SCRATCH/sessions" \
    -S "127.0.0.1:$PORT" -t "$QUACK_ROOT" "$SCRATCH/router.php" \
    > "$SCRATCH/server_$MODO.log" 2>&1 &
SERVER_PID=$!
echo "[run_piano_dry_e2e] Server avviato (pid $SERVER_PID), attendo che risponda..."

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
    echo "[run_piano_dry_e2e] ERRORE: il server non ha risposto in tempo. Log server:" >&2
    cat "$SCRATCH/server_$MODO.log" >&2
    exit 1
fi

# ── 3. Login HTTP vero ──────────────────────────────────────────────────────
COOKIEJAR="$SCRATCH/cookies_$MODO.txt"
LOGIN_PAGE="$SCRATCH/login_get_$MODO.html"
curl -s -c "$COOKIEJAR" "http://127.0.0.1:$PORT/login" -o "$LOGIN_PAGE"

CSRF_TOKEN=$(grep -o 'name="csrf_token" value="[^"]*"' "$LOGIN_PAGE" | head -1 | sed -E 's/.*value="([^"]*)".*/\1/')
if [ -z "$CSRF_TOKEN" ]; then
    echo "[run_piano_dry_e2e] ERRORE: impossibile estrarre il csrf_token dalla pagina di login." >&2
    head -c 500 "$LOGIN_PAGE" >&2
    exit 1
fi

LOGIN_RESP_HEADERS="$SCRATCH/login_post_headers_$MODO.txt"
curl -s -b "$COOKIEJAR" -c "$COOKIEJAR" -D "$LOGIN_RESP_HEADERS" -o "$SCRATCH/login_post_$MODO.html" \
    --data-urlencode "username=$USERNAME" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "_skin=A" \
    --data-urlencode "csrf_token=$CSRF_TOKEN" \
    "http://127.0.0.1:$PORT/login"

if ! grep -qi '^Location:.*dashboard' "$LOGIN_RESP_HEADERS"; then
    echo "[run_piano_dry_e2e] ERRORE: login non riuscito (nessun redirect a /dashboard). Header ricevuti:" >&2
    cat "$LOGIN_RESP_HEADERS" >&2
    exit 1
fi
echo "[run_piano_dry_e2e] Login riuscito (redirect a /dashboard confermato)."

# ── 4. GET reali sulle 3 pagine di creazione documento ──────────────────────
declare -A PAGES=(
    [ordine_cliente_create]="/sales/orders/create"
    [rfq_create]="/purchases/rfq/create"
    [preventivo_create]="/sales/quotes/create"
)

for key in "${!PAGES[@]}"; do
    path="${PAGES[$key]}"
    http_code=$(curl -s -b "$COOKIEJAR" -o "$SCRATCH/responses/$MODO/$key.html" -w '%{http_code}' \
        "http://127.0.0.1:$PORT$path")
    echo "[run_piano_dry_e2e] GET $key ($path) -> HTTP $http_code"
    if [ "$http_code" != "200" ]; then
        echo "[run_piano_dry_e2e] ERRORE: $key ha risposto $http_code invece di 200." >&2
        exit 1
    fi
done

echo
echo "[run_piano_dry_e2e] Snapshot '$MODO' salvato in: $SCRATCH/responses/$MODO/"
echo "[run_piano_dry_e2e] Scratch: $SCRATCH (riusalo esplicitamente come terzo argomento per il prossimo run)"
