#!/usr/bin/env bash
# tools/dev-php-sandbox/run-test-server.sh
#
# Avvia un server web PHP reale (il built-in dev server di PHP) sopra QUESTA
# copia del codice Quack, per fare un vero test end-to-end via HTTP (login,
# CSRF, redirect, controller) — non solo chiamare le funzioni model isolate.
#
# Usa un database e una cartella upload TEMPORANEI, mai quelli reali di
# produzione: DB_DIR/UPLOAD_DIR di includes/config.php vengono sovrascritti
# (vedi router.php generato sotto) PRIMA che config.php li definisca lui.
#
# Gotcha scoperti il 25/07/2026, documentati qui per non riscoprirli ogni
# volta in una sessione futura:
#   1. `-d auto_prepend_file=...` NON viene applicato in modo affidabile dal
#      server built-in di PHP per ogni richiesta (verificato: le define()
#      dentro il prepend file semplicemente non c'erano more). La soluzione
#      è mettere le define() DIRETTAMENTE dentro il router.php passato a
#      `php -S ... router.php`, che è il vero entry point per ogni richiesta.
#   2. session_start() fallisce silenziosamente (warning, non fatale) se
#      session.save_path punta a una cartella che non esiste in questo
#      sandbox (es. /var/lib/php/sessions) — va impostata esplicitamente a
#      una cartella scrivibile qui dentro.
#   3. I processi avviati in background (&) in una chiamata shell NON
#      sopravvivono alla chiamata successiva in questo ambiente — il server
#      va avviato E usato (curl ecc.) E fermato tutto nella STESSA
#      invocazione di shell, non in tre chiamate separate.
#   4. Il codice reale di Quack chiama curl_init() (includes/error_reporter.php,
#      notifiche di errore verso un servizio esterno) — serve l'estensione
#      php-curl anche solo per caricare le pagine, altrimenti fatal error.
#
# Uso:
#   bash tools/dev-php-sandbox/setup.sh          # una tantum (o se manca)
#   bash tools/dev-php-sandbox/run-test-server.sh /tmp/mio_test_scratch 8099
#   # in un'altra parte dello STESSO script/chiamata: curl http://127.0.0.1:8099/...
#
# Questo script da solo AVVIA e basta (in foreground, bloccante) — pensato
# per essere INCLUSO (source) o copiato dentro uno script di test più ampio
# che fa seed+curl+kill nella stessa invocazione, non per girare da solo.
# Vedi il commento in fondo per il pattern d'uso consigliato.

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUACK_ROOT="$(cd "$HERE/../.." && pwd)"
PHP="$HERE/runtime/php"

if [ ! -x "$PHP" ]; then
    echo "PHP non pronto. Lancia prima: bash $HERE/setup.sh" >&2
    exit 1
fi

SCRATCH="${1:-/tmp/quack_dev_test_scratch}"
PORT="${2:-8099}"

mkdir -p "$SCRATCH/db" "$SCRATCH/uploads" "$SCRATCH/sessions"

cat > "$SCRATCH/router.php" <<EOF
<?php
// Vedi gotcha #1 sopra: le define() vanno qui, non in un auto_prepend_file.
define('DB_DIR', '$SCRATCH/db');
define('UPLOAD_DIR', '$SCRATCH/uploads');
require \$_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

echo "[dev-php-sandbox] Avvio server su 127.0.0.1:$PORT (docroot: $QUACK_ROOT, DB scratch: $SCRATCH/db)"
echo "[dev-php-sandbox] Ricorda: questo comando è bloccante in foreground."
echo "[dev-php-sandbox] Per il pattern con seed+curl+kill nella stessa invocazione, non lanciare"
echo "[dev-php-sandbox] questo script da solo: copia il blocco 'exec \"\$PHP\" ...' qui sotto nel"
echo "[dev-php-sandbox] tuo script di test, con '&' e poi 'kill \$SPID' alla fine dello stesso script."
echo

exec "$PHP" -d session.save_path="$SCRATCH/sessions" \
    -S "127.0.0.1:$PORT" -t "$QUACK_ROOT" "$SCRATCH/router.php"
