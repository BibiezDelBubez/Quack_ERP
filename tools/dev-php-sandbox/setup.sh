#!/usr/bin/env bash
# tools/dev-php-sandbox/setup.sh
#
# Questo progetto (Quack) gira su PHP, ma l'ambiente sandbox Linux in cui
# Claude esegue i comandi shell (diverso dal PC reale dell'utente, dove PHP
# c'è già via XAMPP) a volte NON ha PHP installato di default, e non ha
# privilegi di root per un `apt-get install` classico.
#
# Questo script scarica un interprete PHP CLI completo (con le estensioni che
# Quack usa davvero: pdo_sqlite, sqlite3, mbstring, curl, bcmath) come pacchetti .deb
# e li ESTRAE (dpkg -x) in tools/dev-php-sandbox/runtime/, SENZA installarli
# a livello di sistema e senza bisogno di sudo/root. Idempotente: si può
# rilanciare quante volte serve, salta quello che ha già scaricato.
#
# Scoperto/costruito il 25/07/2026 (vedi docs/Ciclo Vendite/PIANO - Revisione
# Ordini Clienti (Nuova Versione).md per il contesto: serviva per testare
# davvero, con esecuzione reale, il codice di quel piano — non solo a
# occhio). Su richiesta esplicita dell'utente, reso permanente qui perché
# sopravviva a questa sessione e sia pronto in qualunque sessione futura,
# anche molto lontana nel tempo, SU QUESTO PC (la cartella del progetto è
# l'unica cosa che persiste tra una sessione e l'altra: il sandbox Linux
# stesso viene ricreato da zero ogni volta).
#
# Uso:
#   bash tools/dev-php-sandbox/setup.sh
#   (poi) tools/dev-php-sandbox/runtime/php  -v
#
# IMPORTANTE — non tocca MAI il database reale di produzione (il percorso
# hardcoded in includes/config.php, DB_DIR = 'C:/Quack_ERP_data/db' su
# Windows, che nel sandbox Linux comunque non esiste). Ogni test va fatto
# ridefinendo DB_DIR/UPLOAD_DIR a una cartella temporanea PRIMA di richiedere
# includes/config.php — vedi run-test-server.sh in questa stessa cartella per
# un esempio funzionante completo (server HTTP reale + DB temporaneo).

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME="$HERE/runtime"
DEBS="$HERE/.debs-cache"
mkdir -p "$RUNTIME" "$DEBS"

# Se già pronto (verifica minima: binario php presente e le estensioni chiave
# nella lista moduli), non rifare nulla.
# AGGIORNAMENTO 07/08/2026 (Bug A, audit/audit-bug-prezzo-preventivi-vendita.md):
# aggiunta bcmath alla verifica — qk_uom_converti_riga() (includes/prodotti.php,
# usata da qk_calcola_riga_offerta() nel ciclo vendite) chiama bcdiv()/bcmul(),
# scoperto mancante testando fisicamente il fix del Bug A. Un runtime scaricato
# PRIMA di questa data ha pdo_sqlite/curl ma non bcmath: la condizione sotto lo
# rileva e riscarica solo il pacchetto mancante, senza ripartire da zero.
if [ -x "$RUNTIME/php" ] && "$RUNTIME/php" -m 2>/dev/null | grep -qi pdo_sqlite && "$RUNTIME/php" -m 2>/dev/null | grep -qi curl && "$RUNTIME/php" -m 2>/dev/null | grep -qi bcmath; then
    echo "[dev-php-sandbox] Già pronto: $RUNTIME/php ($("$RUNTIME/php" -v | head -1))"
    exit 0
fi

echo "[dev-php-sandbox] Preparazione runtime PHP in $RUNTIME ..."

# Pacchetti richiesti (nome apt) e versione "jammy/main" nota funzionante come
# fallback se il mirror di sicurezza (jammy-updates/jammy-security) risulta
# irraggiungibile dal proxy del sandbox (osservato il 25/07/2026: 404 dietro
# 127.0.0.1:3128). Si prova prima SENZA pin (potrebbe funzionare in un
# ambiente/mirror diverso), poi si ripiega sulla versione pinnata.
declare -A FALLBACK_VERSION=(
    [php8.1-cli]="8.1.2-1ubuntu2"
    [php8.1-common]="8.1.2-1ubuntu2"
    [php8.1-sqlite3]="8.1.2-1ubuntu2"
    [php8.1-mbstring]="8.1.2-1ubuntu2"
    [php8.1-curl]="8.1.2-1ubuntu2"
    [php8.1-bcmath]="8.1.2-1ubuntu2"
    [libargon2-1]="0~20171227-0.3"
    [libonig5]="6.9.7.1-2build1"
    [libsodium23]="1.0.18-1ubuntu0.22.04.1"
    [libzip4]="1.7.3-1ubuntu2"
    [libsqlite3-0]="3.37.2-2"
    [libcurl4]="7.81.0-1ubuntu1.25"
)

cd "$DEBS"
for pkg in "${!FALLBACK_VERSION[@]}"; do
    if ls "${pkg}"_*.deb >/dev/null 2>&1; then
        continue # già in cache da un run precedente
    fi
    echo "  scarico $pkg ..."
    if ! apt-get download "$pkg" >/tmp/apt_dl_$$.log 2>&1; then
        ver="${FALLBACK_VERSION[$pkg]}"
        echo "    fallito senza pin, riprovo pinnato a $pkg=$ver"
        apt-get download "${pkg}=${ver}" || {
            echo "ERRORE: impossibile scaricare $pkg (né libero né pinnato $ver). Vedi /tmp/apt_dl_$$.log" >&2
            cat /tmp/apt_dl_$$.log >&2
            exit 1
        }
    fi
    rm -f /tmp/apt_dl_$$.log
done

echo "[dev-php-sandbox] Estraggo i pacchetti (dpkg -x, nessuna installazione di sistema) ..."
for deb in *.deb; do
    dpkg -x "$deb" "$RUNTIME"
done

# php.ini minimale con solo le estensioni che servono davvero a Quack.
cat > "$RUNTIME/php.ini" <<EOF
extension_dir = "$RUNTIME/usr/lib/php/20210902"
extension=pdo.so
extension=pdo_sqlite.so
extension=sqlite3.so
extension=mbstring.so
extension=ctype.so
extension=tokenizer.so
extension=fileinfo.so
extension=curl.so
extension=bcmath.so
display_errors = Off
log_errors = On
EOF

# Wrapper eseguibile che imposta LD_LIBRARY_PATH automaticamente, così non va
# ricordato/ripetuto ogni volta in ogni sessione futura.
PHP_BIN=$(find "$RUNTIME/usr/bin" -maxdepth 1 -iname "php8.*" | head -1)
cat > "$RUNTIME/php" <<EOF
#!/usr/bin/env bash
export LD_LIBRARY_PATH="$RUNTIME/usr/lib/x86_64-linux-gnu:$RUNTIME/lib/x86_64-linux-gnu\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
exec "$PHP_BIN" -c "$RUNTIME/php.ini" "\$@"
EOF
chmod +x "$RUNTIME/php"

echo "[dev-php-sandbox] Fatto. Verifica:"
"$RUNTIME/php" -v
"$RUNTIME/php" -m | grep -iE "pdo_sqlite|sqlite3|mbstring|curl|bcmath"
