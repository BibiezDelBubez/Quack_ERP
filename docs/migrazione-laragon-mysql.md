# Guida: spostare Quack ERP da XAMPP/SQLite a Laragon + MySQL

## Panoramica

Situazione attuale:
- Web server: **XAMPP + Apache**
- Database: **SQLite** (un singolo file `db/database.sqlite`)
- Upload: `C:\Quack_ERP_data\`

Situazione finale:
- Web server: **Laragon + Apache** (o Nginx)
- Database: **MySQL** (server separato, accesso via credenziali)
- Upload: `C:\Quack_ERP_data\` (invariato)

---

## Parte 1 — Installare Laragon

1. Scarica Laragon Full da [laragon.org](https://laragon.org/download/)
2. Installalo in `C:\laragon\` (percorso predefinito, lascialo così)
3. Avvia Laragon → clicca **Start All**
4. Verifica che Apache e MySQL siano verdi nel pannello

> Laragon installa automaticamente Apache, PHP e MySQL. Non devi configurare nulla a mano per iniziare.

---

## Parte 2 — Copiare il progetto in Laragon

La cartella web di Laragon è `C:\laragon\www\` (equivalente di `htdocs` in XAMPP).

1. Copia l'intera cartella `Quack` da:
   ```
   C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\
   ```
   a:
   ```
   C:\laragon\www\Quack\
   ```

2. Verifica che l'app risponda su `http://quack.test` (Laragon crea automaticamente i virtual host per ogni cartella in `www\`)

   > Se non si apre, prova prima `http://localhost/Quack/` — poi leggi la sezione "Virtual Host" più avanti.

---

## Parte 3 — MySQL: concetti base (per chi parte da zero)

### Cos'è MySQL rispetto a SQLite

| | SQLite | MySQL |
|---|---|---|
| Cos'è | Un file sul disco | Un server separato sempre in esecuzione |
| Accesso | Diretto, via path | Via rete (localhost:3306) con utente e password |
| Adatto a | Sviluppo, app leggere | Produzione, più utenti simultanei |
| Backup | Copia il file `.sqlite` | Esportazione con `mysqldump` |

### Terminologia

- **Server MySQL**: il processo che gira in background (avviato da Laragon)
- **Database** (o "schema"): contenitore di tabelle — equivale al file `.sqlite`. Ogni progetto di solito ha il suo.
- **Utente**: credenziali per accedere. L'utente `root` ha accesso totale (usalo solo in sviluppo).
- **Host**: dove gira il server. In locale è sempre `127.0.0.1` o `localhost`.
- **Porta**: di default `3306`
- **HeidiSQL / phpMyAdmin**: strumenti grafici per gestire MySQL — Laragon li include entrambi.

---

## Parte 4 — Creare il database MySQL

### Metodo A: da Laragon (più semplice)

1. Nel pannello Laragon, clicca **Database**
2. Si apre HeidiSQL già connesso come `root` (password vuota in Laragon per default)
3. Click destro su "Unnamed" (colonna sinistra) → **Crea nuovo** → **Database**
4. Nome: `quack_erp`, collation: `utf8mb4_unicode_ci` → OK

### Metodo B: da riga di comando

```powershell
# Apri MySQL da Laragon: Menu → MySQL → MySQL Console
# oppure da PowerShell:
C:\laragon\bin\mysql\mysql-8.0\bin\mysql.exe -u root

# Dentro MySQL:
CREATE DATABASE quack_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

---

## Parte 5 — Esportare i dati da SQLite e importarli in MySQL

Questa è la parte più delicata: SQLite e MySQL hanno sintassi SQL leggermente diverse.

### Passo 5.1 — Installa il tool di conversione

```powershell
pip install sqlite3-to-mysql --break-system-packages
```

Oppure scarica [DB Browser for SQLite](https://sqlitebrowser.org/) per esportare manualmente.

### Passo 5.2 — Converti e importa (metodo automatico)

```powershell
sqlite3mysql `
  --sqlite-file "C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\db\database.sqlite" `
  --mysql-database quack_erp `
  --mysql-user root `
  --mysql-password "" `
  --mysql-host 127.0.0.1
```

Questo crea tutte le tabelle e importa tutti i dati in MySQL automaticamente.

### Passo 5.3 — Verifica in HeidiSQL

Apri HeidiSQL, clicca su `quack_erp` — dovresti vedere tutte le tabelle di Quack con i dati.

> **Attenzione**: SQLite usa `INTEGER PRIMARY KEY` come autoincrement, MySQL usa `INT AUTO_INCREMENT`. Il tool di conversione gestisce questa differenza, ma dopo l'import verifica che le tabelle abbiano `AUTO_INCREMENT` sulle PK.

---

## Parte 6 — Aggiornare la connessione al DB in Quack

Apri `includes/db.php` e sostituisci tutto il contenuto con:

```php
<?php
/**
 * db.php — connessione MySQL
 */

$host     = '127.0.0.1';
$dbname   = 'quack_erp';
$username = 'root';
$password = '';          // in produzione: usa una password forte
$charset  = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    if (APP_ENV === 'development') {
        die('<b>Errore database:</b> ' . $e->getMessage());
    } else {
        error_log('[Quack] DB connection failed: ' . $e->getMessage());
        die('Errore interno del server.');
    }
}
```

> Nota: non c'è più la logica di `init.sql` — MySQL non ha bisogno di essere inizializzato dal PHP, perché il database è già stato creato e popolato nel Passo 5.

---

## Parte 7 — Differenze SQL da correggere nel codice

SQLite e MySQL differiscono su alcune cose. Cerca nel codice questi pattern:

| SQLite | MySQL | Dove cercare |
|---|---|---|
| `PRAGMA foreign_keys = ON` | Non serve (si gestisce per tabella) | `db.php` — già rimosso |
| `sqlite_master` | `information_schema.TABLES` | `db.php` — già rimosso |
| `INSERT OR REPLACE` | `REPLACE INTO` oppure `INSERT ... ON DUPLICATE KEY UPDATE` | `includes/*.php` |
| `INSERT OR IGNORE` | `INSERT IGNORE` | `includes/*.php` |
| Funzione `IFNULL()` | Uguale in MySQL ✓ | — |
| `strftime('%Y', ...)` | `YEAR(campo)` o `DATE_FORMAT()` | Query con date |
| `CURRENT_TIMESTAMP` | Uguale ✓ | — |

Fai una ricerca nel codice:
```
grep -rn "INSERT OR\|sqlite_master\|PRAGMA" app/ includes/
```

---

## Parte 8 — Configurare il virtual host (opzionale ma consigliato)

Laragon crea automaticamente `http://quack.test` per la cartella `C:\laragon\www\Quack\`.

Se non funziona:
1. Laragon → menu in alto → **Apache** → **sites-enabled** → `auto.quack.test.conf`
2. Verifica che il file esista. Se no: Laragon → **Menu** → **Apache** → **Reload**
3. Aggiungi al file `C:\Windows\System32\drivers\etc\hosts` (come amministratore):
   ```
   127.0.0.1   quack.test
   ```

Con il virtual host attivo, `BASE_URL` in `config.php` diventa `''` (stringa vuota) invece di `/Quack`.

---

## Parte 9 — Backup MySQL (da fare regolarmente)

A differenza di SQLite dove basta copiare un file, MySQL richiede un'esportazione:

```powershell
# Esporta tutto il database in un file SQL
C:\laragon\bin\mysql\mysql-8.0\bin\mysqldump.exe -u root quack_erp > C:\Quack_ERP_data\backup\quack_erp_backup.sql
```

Per ripristinare:
```powershell
C:\laragon\bin\mysql\mysql-8.0\bin\mysql.exe -u root quack_erp < C:\Quack_ERP_data\backup\quack_erp_backup.sql
```

> Consiglio: crea un file `.bat` con il comando di backup e aggiungilo al Task Scheduler di Windows per eseguirlo ogni notte.

---

## Checklist finale

- [ ] Laragon installato e avviato (Apache + MySQL verdi)
- [ ] Cartella progetto copiata in `C:\laragon\www\Quack\`
- [ ] Database `quack_erp` creato in MySQL
- [ ] Dati esportati da SQLite e importati in MySQL (verificati in HeidiSQL)
- [ ] `includes/db.php` aggiornato per MySQL
- [ ] Nessun `INSERT OR REPLACE` / `sqlite_master` rimasto nel codice
- [ ] `C:\Quack_ERP_data\` accessibile da Laragon (permessi OK)
- [ ] App risponde su `http://quack.test` o `http://localhost/Quack/`
- [ ] Login funzionante, prodotti visibili con immagini
- [ ] PDF e DOCX generati correttamente
