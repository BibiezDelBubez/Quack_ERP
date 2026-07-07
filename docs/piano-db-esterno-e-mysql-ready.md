# Piano: DB fuori dal progetto + preparazione allo switch MySQL

## 0. Stato attuale (analisi del codice)

- Connessione DB creata in un solo punto: `includes/db.php`, riga 12: `$db_path = APP_ROOT_DIR . '/db/database.sqlite'`. Il file sqlite vive **dentro** la cartella di progetto (`db/database.sqlite`), insieme a `db/quack.sqlite` (vuoto, residuo) e agli script `crea_db.php`, `init_sqlite.sql`, `init_mysql.sql`.
- `$pdo` è una variabile globale creata da `db.php` e riusata ovunque (nessun singleton/service container). I controller la ricevono via `BaseController::__construct(PDO $pdo)`; i vecchi file in `includes/*.php` usano `global $pdo` o la variabile già in scope.
- Esiste già lo stesso pattern per gli upload: `UPLOAD_DIR` in `includes/config.php` punta fuori progetto (`C:/Quack_ERP_data`) ed è l'**unica riga da cambiare** tra ambienti (vedi `docs/uploads-fuori-progetto.md`). Il DB va trattato allo stesso modo.
- Esiste già un doc `docs/migrazione-laragon-mysql.md`: è una guida manuale "esporta e riscrivi db.php a mano". Utile come riferimento operativo, ma **non prepara il codice** per rendere lo switch a runtime/config-driven. Questo piano lo sostituisce/completa con un approccio "cambia una variabile".
- Esiste già `db/init_mysql.sql` ma è **fermo alla versione schema v4.2**, mentre `db/init_sqlite.sql` è alla v4.3. Vanno disallineati oggi: qualunque migrazione va prima verificata/aggiornata.
- Punti del codice SQLite-specifici individuati (da correggere per compatibilità MySQL):
  - `PRAGMA foreign_keys = ON` — `db.php`, `anagrafiche.php:1765`, `ProductsController.php:243`
  - `PRAGMA table_info(...)` — `db.php` (indirettamente no), `app/bootstrap.php:54`, `check_db2.php`, `includes/sales_quotes.php` (4 occorrenze), `db/migrations/migrate_ordini_righe_uom.php`
  - `SELECT name FROM sqlite_master WHERE type='table'...` — `db.php:24` (unico punto, usato per l'auto-setup schema)
  - `INSERT OR IGNORE` / `INSERT OR REPLACE` — `prodotti.php:364`, `ProductsController.php:1972`, `SostanzeChimicheController.php` (righe 414, 623, 633, 643)
  - `GROUP_CONCAT(...)` — `SostanzeChimicheController.php:32-33`, `AdrController.php:60` (funziona anche in MySQL con sintassi quasi identica, ma il separatore va verificato)
  - `new PDO('sqlite:...')` diretto — solo `includes/db.php` e lo script di debug `check_db2.php`
  - `AUTOINCREMENT` — solo negli script SQL (`init_sqlite.sql`, doc adr), non nel PHP
- Nessun query builder/ORM: le query sono scritte a mano con PDO preparato (`->prepare()`, `bindValue(..., PDO::PARAM_INT)`), quindi la sintassi SQL string-per-string è il vero rischio di migrazione, non l'API PHP.

---

## Parte 1 — Spostare il DB fuori dalla cartella di progetto

Obiettivo: replicare esattamente il pattern già usato per `UPLOAD_DIR`, così in produzione basta cambiare due righe in `config.php` (una per gli upload, una per il DB) e nient'altro.

### 1.1 — Aggiungere la costante `DB_DIR` in `includes/config.php`

Subito dopo il blocco `UPLOAD_DIR` (circa riga 47):

```php
/* ── Cartella database (fuori dalla cartella di progetto) ─────────────────
   In sviluppo:  C:/Quack_ERP_data/db
   In produzione Linux: es. /var/www/quack-data/db
   Cambia SOLO questo define per adattare l'ambiente.
─────────────────────────────────────────────────────────────────────────── */
define('DB_DIR', 'C:/Quack_ERP_data/db');
```

Nota: si consiglia di mettere `db/` come sottocartella della stessa area dati esterna già usata per gli upload (`C:/Quack_ERP_data`), non una cartella scollegata — un solo albero da backuppare, un solo posto da proteggere con permessi.

### 1.2 — Aggiornare `includes/db.php`

Sostituire riga 12:

```php
// PRIMA
$db_path = APP_ROOT_DIR . '/db/database.sqlite';

// DOPO
$db_path = DB_DIR . '/database.sqlite';
```

L'auto-setup dello schema (`init_sqlite.sql`) resta letto da `APP_ROOT_DIR . '/db/init_sqlite.sql'` — quello è codice/schema versionato in Git, **non** dato utente, quindi resta dentro il progetto.

### 1.3 — Creare la cartella e spostare il file esistente

```powershell
mkdir C:\Quack_ERP_data\db
move C:\...\Quack\db\database.sqlite C:\Quack_ERP_data\db\database.sqlite
```

Verificare permessi di scrittura per l'utente del webserver (in produzione Linux: `chown www-data:www-data`, `chmod 750`).

### 1.4 — Aggiornare i file che referenziano il vecchio path

| File | Riga | Azione |
|---|---|---|
| `includes/modals/info.php` | 28 | `APP_ROOT_DIR . '/db/database.sqlite'` → `DB_DIR . '/database.sqlite'` (mostra path/dimensione DB nel modal "Info sistema") |
| `check_db2.php` | 2 | Script di debug manuale, non incluso da `bootstrap.php`. Aggiornare il path o eliminarlo (verificare prima se ancora usato) |
| `db/crea_db.php` | — | Script di setup MySQL standalone, non tocca `database.sqlite`; nessuna modifica necessaria qui |

### 1.5 — Bootstrap: fail-fast come già fatto per gli upload

In `app/bootstrap.php`, prima del blocco che verifica `UPLOAD_DIR` (riga ~17), aggiungere un controllo equivalente:

```php
if (!is_dir(DB_DIR)) {
    if (APP_ENV === 'development') {
        die('<b>ERRORE CONFIGURAZIONE:</b> La cartella DB non esiste: <code>' . DB_DIR . '</code><br>Creala manualmente o aggiorna DB_DIR in <code>includes/config.php</code>.');
    } else {
        error_log('[Quack] DB_DIR non trovata: ' . DB_DIR);
    }
}
```

Va eseguito **prima** di `require_once __DIR__ . '/../includes/db.php'` (che oggi è alla riga 8, prima del controllo upload alla riga 17): spostare l'ordine o duplicare il controllo dentro `db.php` stesso, così l'errore compare anche se qualcuno include `db.php` isolatamente.

### 1.6 — `.gitignore`

Il file `database.sqlite` oggi non è escluso da Git (rischio: dati reali finiscono nel repository). Una volta spostato fuori progetto il problema sparisce da solo, ma per sicurezza aggiungere comunque, in caso qualcuno lo ricrei per errore dentro `db/`:

```
db/*.sqlite
db/.fuse_hidden*
```

(i file `.fuse_hidden*` visti in `db/` sono residui di lock temporanei del filesystem — sicuri da ignorare/eliminare, non referenziati dal codice)

### 1.7 — Checklist Parte 1

- [ ] `define('DB_DIR', ...)` aggiunto in `config.php`
- [ ] `includes/db.php` aggiornato a `DB_DIR . '/database.sqlite'`
- [ ] Cartella esterna creata, file `.sqlite` spostato
- [ ] `includes/modals/info.php` aggiornato
- [ ] `check_db2.php` aggiornato o rimosso
- [ ] Controllo fail-fast su `DB_DIR` aggiunto in bootstrap
- [ ] `.gitignore` aggiornato
- [ ] App testata: login, lettura prodotti, scrittura (creazione ordine di prova) — verifica che scriva nel nuovo path e non ricrei un file vuoto nel vecchio

---

## Parte 2 — Preparare fin da ora lo switch a MySQL ("cambia una variabile")

Obiettivo dichiarato: che il passaggio futuro consista nel cambiare 1-2 variabili. Per arrivarci davvero (non solo di facciata) servono tre cose indipendenti: **(a)** un connection layer parametrico per driver, **(b)** query scritte in SQL compatibile con entrambi i motori (o isolate dove non lo sono), **(c)** due schemi di init sempre allineati. Oggi manca soprattutto (b) e (c); (a) si costruisce facilmente perché il codice ha già un solo punto di connessione.

### 2.1 — Config-driven connection: nuove costanti in `config.php`

```php
/* ── Driver database — UNICA FONTE DI VERITÀ ──────────────────────────────
   'sqlite' oggi, 'mysql' quando lo schema/logica sarà stabile.
   Lo switch consiste nel cambiare DB_DRIVER (+ le credenziali sotto se mysql).
─────────────────────────────────────────────────────────────────────────── */
define('DB_DRIVER',   'sqlite');   // 'sqlite' | 'mysql'

// Usate solo se DB_DRIVER === 'mysql'
define('DB_MYSQL_HOST',   '127.0.0.1');
define('DB_MYSQL_PORT',   '3306');
define('DB_MYSQL_NAME',   'quack_erp');
define('DB_MYSQL_USER',   'root');
define('DB_MYSQL_PASS',   '');
define('DB_MYSQL_CHARSET','utf8mb4');
```

In produzione, questi valori andrebbero letti da variabili d'ambiente (`getenv('DB_MYSQL_PASS')` ecc.) invece che hardcoded, specialmente la password — coerente con quanto già segnalato in `audit/valori sensibili e segreti_da_spostare.md` per altre credenziali (es. `QK_BREVO_API_KEY`). Non è bloccante per lo switch, ma è il momento giusto per farlo insieme.

### 2.2 — Riscrivere `includes/db.php` come dispatcher a due rami

Stessa interfaccia di uscita (`$pdo`, sempre `PDO::FETCH_ASSOC`), ma la creazione della connessione si dirama su `DB_DRIVER`:

```php
<?php
switch (DB_DRIVER) {

    case 'mysql':
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_MYSQL_HOST, DB_MYSQL_PORT, DB_MYSQL_NAME, DB_MYSQL_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_MYSQL_USER, DB_MYSQL_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $msg = APP_ENV === 'development' ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'Errore interno. Contatta il supporto.';
            die('<b>Errore database:</b> ' . $msg);
        }
        // Nessun auto-setup schema qui: in MySQL il DB va creato/popolato una volta
        // con db/crea_db.php (già esistente) o con una migrazione esplicita.
        break;

    case 'sqlite':
    default:
        // ... contenuto ATTUALE di db.php (path DB_DIR, PRAGMA, auto-setup da init_sqlite.sql) ...
        break;
}
```

Questo è l'unico file che deve "sapere" quale motore è attivo. Tutto il resto del codice continua a usare `$pdo` esattamente come oggi.

### 2.3 — Isolare le query non portabili dietro un helper

Le query che differiscono per sintassi vanno raggruppate in funzioni dedicate invece che scritte inline, così il ramo driver-specifico sta in un solo posto anche lì.

**PRAGMA table_info → colonne di una tabella.** Oggi usato per controlli "esiste questa colonna?" (bootstrap.php, sales_quotes.php, migrate_ordini_righe_uom.php). Sostituire ogni occorrenza con una funzione helper in `includes/functions.php`:

```php
function db_table_columns(PDO $pdo, string $table): array
{
    if (DB_DRIVER === 'mysql') {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $stmt = $pdo->query("PRAGMA table_info(" . $pdo->quote($table) . ")");
    return array_column($stmt->fetchAll(), 'name');
}
```

**`INSERT OR IGNORE` / `INSERT OR REPLACE`.** MySQL equivalenti: `INSERT IGNORE INTO ...` e `INSERT INTO ... ON DUPLICATE KEY UPDATE ...` (quest'ultimo richiede di elencare le colonne da aggiornare, non è un drop-in replacement 1:1 come `IGNORE`). Punti da correggere quando si farà lo switch:
- `includes/prodotti.php:364` — `INSERT OR IGNORE` → `INSERT IGNORE` (compatibile, cambio meccanico)
- `app/Controllers/ProductsController.php:1972` — idem
- `app/Controllers/SostanzeChimicheController.php:623,633,643` — idem (IGNORE)
- `app/Controllers/SostanzeChimicheController.php:414` — `INSERT OR REPLACE` → va riscritta esplicitamente con `ON DUPLICATE KEY UPDATE col=VALUES(col), ...` (richiede leggere la tabella `sostanze_chimiche_restrizioni` e sapere quali colonne aggiornare)

Consiglio: da oggi, per nuovo codice, evitare `INSERT OR IGNORE/REPLACE` e scrivere sempre `SELECT` di controllo + `INSERT`/`UPDATE` espliciti — funziona identico su entrambi i motori e non richiede questa mappatura in futuro.

**`sqlite_master`.** Un solo punto (`db.php:24`), già isolato nel ramo sqlite del dispatcher (2.2): nessun problema, non serve toccare altrove.

**`GROUP_CONCAT`.** Sintassi quasi identica in MySQL (`GROUP_CONCAT(col SEPARATOR ', ')` vs SQLite `GROUP_CONCAT(col, ', ')`). Va corretta la sintassi del separatore nei 3 punti individuati (`SostanzeChimicheController.php:32,33`, `AdrController.php:60`) al momento dello switch — differenza puramente sintattica, comportamento identico.

**`PRAGMA foreign_keys = ON`.** In MySQL le FK sono sempre attive per InnoDB (default engine); questa riga va semplicemente saltata quando `DB_DRIVER === 'mysql'`. Le 3 occorrenze (`db.php`, `anagrafiche.php:1765`, `ProductsController.php:243`) vanno racchiuse in `if (DB_DRIVER === 'sqlite') { $pdo->exec(...); }` oppure spostate dentro un helper `db_enable_fk_checks($pdo)`.

### 2.4 — Tenere `init_mysql.sql` sempre allineato a `init_sqlite.sql`

Oggi è già disallineato (v4.2 vs v4.3 corrente) — è il rischio più concreto per uno switch "veloce e sicuro": se lo schema MySQL è vecchio, la app parte ma con tabelle/colonne mancanti, errori silenziosi in produzione.

Due opzioni, in ordine di robustezza:

1. **Generatore automatico** (consigliato): script PHP/Node che legge `init_sqlite.sql`, applica una tabella di conversione tipo (`INTEGER PRIMARY KEY AUTOINCREMENT` → `INT AUTO_INCREMENT PRIMARY KEY`, `TEXT` → `TEXT`/`VARCHAR` dove serve indice univoco, `BOOLEAN DEFAULT 0/1` → invariato, `DATETIME DEFAULT CURRENT_TIMESTAMP` → invariato) e produce `init_mysql.sql`. Va eseguito **ogni volta che si tocca lo schema sqlite**, come parte del flusso di migrazione (oggi manuale — vedi `db/migrations/migrate_ordini_righe_uom.php` come unico esempio esistente di script di migrazione).
2. **Disciplina manuale minima**: ogni migrazione applicata a `init_sqlite.sql` viene applicata nella stessa PR anche a `init_mysql.sql`, con un test che confronta il set di tabelle/colonne tra i due file (script rapido: parse dei due `CREATE TABLE`, diff dei nomi colonna).

Senza uno dei due, "cambiare una variabile" per passare a MySQL resta falso: il file di bootstrap dello schema sarebbe silenziosamente vecchio.

### 2.5 — Verificare il resto del codice SQL con un audit mirato prima dello switch

Prima di attivare `DB_DRIVER = 'mysql'` in produzione, ripetere questa ricerca sull'intero codice e chiudere ogni risultato:

```
grep -rn "INSERT OR\|sqlite_master\|PRAGMA\|GROUP_CONCAT\|strftime(\|julianday(\|AUTOINCREMENT" app/ includes/ db/
```

Altri punti da controllare manualmente (non trovati come pattern automatico ma tipici in migrazioni SQLite→MySQL):
- Concatenazione stringhe: SQLite usa `||`, MySQL usa `CONCAT()`. Non risultano occorrenze di `||` nelle query attuali (verificato), ma va ricontrollato se si aggiunge nuovo SQL.
- `LIMIT offset, count` vs `LIMIT count OFFSET offset`: entrambe le sintassi sono valide in MySQL, nessuna azione necessaria; SQLite supporta solo `LIMIT count OFFSET offset` — già lo stile usato nel progetto (`prodotti.php` con `:limit`/`:offset` bindati), quindi già compatibile.
- Case-sensitivity dei confronti testo: SQLite è case-insensitive per default su `LIKE` con ASCII, MySQL dipende dalla collation (`utf8mb4_unicode_ci` è case-insensitive, coerente con quanto già usato in `crea_db.php`) — nessuna azione necessaria se si mantiene quella collation ovunque.
- Booleani: SQLite non ha tipo BOOLEAN nativo (è INTEGER 0/1); MySQL idem (`BOOLEAN` è alias di `TINYINT(1)`) — compatibile, nessuna azione.

### 2.6 — Percorso di switch effettivo (quando sarà il momento)

1. Aggiornare `init_mysql.sql` all'ultima versione schema (vedi 2.4)
2. Creare il DB MySQL con `db/crea_db.php` (già esistente e funzionante) oppure a mano
3. Esportare i dati reali da SQLite e importarli in MySQL (stessa procedura già descritta in `docs/migrazione-laragon-mysql.md`, Parte 5 — resta valida)
4. Chiudere ogni voce dell'audit 2.5
5. Cambiare in `config.php`: `DB_DRIVER = 'mysql'` + le 5 costanti `DB_MYSQL_*`
6. Test di non regressione completo (vedi 2.7)
7. Deploy

Punto 5 è l'unico cambiamento previsto nel codice applicativo il giorno dello switch, a patto che 2.1-2.5 siano stati implementati per tempo.

### 2.7 — Test di non regressione da eseguire dopo lo switch (in ambiente di staging, prima della produzione)

- Login utente
- Creazione/modifica prodotto con upload immagine
- Ciclo vendite completo: preventivo → ordine → DDT/spedizione → fattura
- Ciclo acquisti completo: RFQ → richiesta preventivo fornitore → ordine → ricevimento merce
- Movimenti di magazzino e inventario (compresi i lotti)
- Generazione PDF/DOCX (mpdf, phpword) — non dipende dal DB ma va comunque verificato nello stesso giro di test
- Sezione Sostanze Chimiche/ADR (usa `GROUP_CONCAT` e `INSERT OR REPLACE`, i punti più a rischio sintattico)
- Modal "Info sistema" (mostra path e dimensione DB — verificare che il testo si adatti al caso MySQL, dove "dimensione file" non ha senso)

---

## Riepilogo: cosa fare subito vs cosa preparare per dopo

**Subito (Parte 1 — indipendente da MySQL):**
- `DB_DIR` in config, aggiornare `db.php`, spostare il file, aggiornare `info.php`/`check_db2.php`, fail-fast in bootstrap, `.gitignore`

**Da fare ora per rendere lo switch futuro rapido (Parte 2, senza ancora attivare MySQL):**
- Costanti `DB_DRIVER` + `DB_MYSQL_*` in config (con `DB_DRIVER = 'sqlite'` per ora)
- `db.php` riscritto come dispatcher a due rami (ramo mysql pronto ma inattivo)
- Helper `db_table_columns()` e sostituzione delle `PRAGMA table_info` sparse
- Normalizzazione `INSERT OR IGNORE/REPLACE` → forma esplicita o isolata dietro helper
- Correzione sintassi `GROUP_CONCAT` separator, resa condizionale su `DB_DRIVER`
- Processo (script o disciplina) per tenere `init_mysql.sql` allineato a `init_sqlite.sql` — priorità alta, oggi è già disallineato

**Al momento dello switch vero e proprio:**
- Solo Parte 2.6: aggiornare schema mysql, creare/popolare DB, cambiare `DB_DRIVER` + credenziali, test di non regressione (2.7)
