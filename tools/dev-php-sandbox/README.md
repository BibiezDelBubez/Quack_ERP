# dev-php-sandbox

Il sandbox Linux in cui Claude esegue i comandi shell (diverso dal PC reale
dell'utente, dove PHP c'è già via XAMPP) a volte non ha PHP installato di
default, e non ha privilegi di root per un `apt-get install` classico. Questa
cartella contiene tutto il necessario per procurarselo comunque, senza root,
e usarlo per testare davvero il codice di Quack (esecuzione reale, non solo
lettura statica) — anche in una sessione futura molto lontana da quando è
stata scritta questa cartella, perché la cartella del progetto è l'unica
cosa che sopravvive tra una sessione e l'altra del sandbox.

## Uso rapido

```bash
bash tools/dev-php-sandbox/setup.sh
tools/dev-php-sandbox/runtime/php -v
tools/dev-php-sandbox/runtime/php -l qualche/file.php
```

`setup.sh` è idempotente: se il runtime è già pronto (da questa sessione o
da una precedente, se `tools/dev-php-sandbox/runtime/` non è stato ripulito)
non riscarica nulla.

## Cosa include

`php` (wrapper eseguibile, imposta da solo `LD_LIBRARY_PATH`) con le
estensioni che Quack usa davvero: `pdo_sqlite`, `sqlite3`, `mbstring`,
`curl` (necessaria: `includes/error_reporter.php` chiama `curl_init()` per
le notifiche di errore, senza questa estensione qualunque pagina fallisce
con un fatal error, non solo le funzioni che sembrano usare la rete).

## Tre modi di testare, dal più semplice al più completo

1. **Sintassi** (`php -l file.php`): il minimo indispensabile, cattura solo
   errori di battitura grossolani.
2. **Funzioni model isolate, con un vero database**: crea un DB SQLite
   temporaneo seminato da `db/init_sqlite.sql` (lo fa da solo
   `includes/db.php` al primo utilizzo, se manca la tabella `utenti`),
   richiama le funzioni vere (`includes/*.php`) contro quel DB. Vedi
   `tests/manual/suite_*.php` per decine di esempi reali di questo
   pattern — sempre `define('DB_DIR', sys_get_temp_dir() . '/...')` PRIMA di
   richiedere `includes/config.php`.
3. **Ciclo HTTP reale** (login, CSRF, redirect, controller): usa
   `run-test-server.sh` — avvia il built-in dev server di PHP sopra il
   codice reale, con DB/upload/sessioni temporanei (mai quelli di
   produzione). Vedi i commenti dentro quel file per i 4 problemi già
   scoperti e risolti (`auto_prepend_file` inaffidabile col server
   built-in, cartella sessioni mancante, processi in background che non
   sopravvivono tra una chiamata shell e l'altra, estensione curl
   necessaria).

## Sicurezza: non tocca mai il database reale

`includes/config.php` ha `DB_DIR`/`UPLOAD_DIR` hardcoded su un path Windows
di produzione (`C:/Quack_ERP_data/...`). Ogni test in questa cartella
ridefinisce quelle costanti a una cartella temporanea PRIMA che
`includes/config.php` le definisca lui — PHP ignora la seconda `define()`
sullo stesso nome (con un warning innocuo), quindi vince sempre la prima,
purché venga eseguita PRIMA del require di `config.php`. Verificare questo
punto è la prima cosa da fare se un test sembra leggere/scrivere dati che
non ti aspettavi.

## Non versionato

`runtime/` e `.debs-cache/` sono nel `.gitignore` del progetto (binari
scaricati, rigenerabili in pochi secondi con `setup.sh`, non hanno senso in
un repository git). Solo gli script (`setup.sh`, `run-test-server.sh`,
questo `README.md`) sono tracciati.
