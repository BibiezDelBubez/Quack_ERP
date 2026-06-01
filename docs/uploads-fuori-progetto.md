# Guida: spostare gli upload fuori dalla cartella di progetto

## Perché farlo

La cartella `htdocs/Quack/` è il codice dell'app. Se la ridistribuisci, aggiorni da git, o fai un backup selettivo del codice, non vuoi che i file caricati dagli utenti (loghi, PDF, immagini) vadano persi o finiscano nel repository.

La best practice è: **codice in un posto, dati in un altro.**

---

## Struttura consigliata su XAMPP (sviluppo)

```
C:\Users\michel.papetti\
│
├── Documents\xampp\htdocs\Quack\   ← codice (Git, deploy)
│
└── quack-uploads\                  ← file utente (fuori da htdocs)
    ├── loghi\
    ├── header_aziende\
    ├── mpdf_tmp\
    └── documenti\
```

La cartella `quack-uploads` non è servita direttamente dal web server — i file vengono sempre letti o scaricati tramite PHP, mai via URL diretta.

---

## Passo 1 — Creare la cartella upload

Su Windows, crea manualmente:

```
C:\Users\michel.papetti\quack-uploads\
```

Con le sottocartelle che ti servono (vedi struttura sopra).

In produzione su Linux il percorso sarà qualcosa tipo `/var/www/quack-uploads/` — fuori da `/var/www/html/`.

---

## Passo 2 — Aggiungere la costante in `config.php`

Apri `includes/config.php` e aggiungi **dopo** la definizione di `APP_ROOT_DIR`:

```php
/* ── Upload directory (fuori dalla cartella di progetto) ─────────────────── */
define('UPLOAD_DIR', 'C:/Users/michel.papetti/quack-uploads');
define('UPLOAD_URL', null); // null = nessun accesso diretto via URL; sempre via PHP
```

> **In produzione** cambierai solo questa riga — il resto del codice non tocchi.
> Esempio produzione Linux: `define('UPLOAD_DIR', '/var/www/quack-uploads');`

---

## Passo 3 — Sostituire i riferimenti a `uploads/` nel codice

Cerca nel codice tutte le occorrenze di `APP_ROOT_DIR . '/uploads'` (o simili) e sostituiscile con `UPLOAD_DIR`.

Esempi pratici:

| Prima | Dopo |
|---|---|
| `APP_ROOT_DIR . '/uploads/loghi'` | `UPLOAD_DIR . '/loghi'` |
| `APP_ROOT_DIR . '/uploads/mpdf_tmp'` | `UPLOAD_DIR . '/mpdf_tmp'` |
| `APP_ROOT_DIR . $azienda['logo_path']` | `UPLOAD_DIR . $azienda['logo_path']` |

Nel database, i campi come `logo_path` e `header_path` salvano percorsi relativi tipo `/loghi/gemina.png`. Restano così — cambia solo il prefisso che gli anteponi in PHP.

---

## Passo 4 — Cosa fa l'app se la cartella non esiste

La best practice è che l'app **non si rompa silenziosamente**, ma segnali il problema in modo chiaro. Aggiungi un controllo in `bootstrap.php`:

```php
/* ── Verifica cartella upload ─────────────────────────────────────────────── */
if (!is_dir(UPLOAD_DIR)) {
    if (APP_ENV === 'development') {
        // In sviluppo: errore visibile subito
        die('<b>ERRORE CONFIGURAZIONE:</b> La cartella upload non esiste: <code>' . UPLOAD_DIR . '</code><br>Creala manualmente o aggiorna UPLOAD_DIR in config.php.');
    } else {
        // In produzione: log silenzioso, l'app continua ma le funzioni upload falliranno con grazia
        error_log('[Quack] UPLOAD_DIR non trovata: ' . UPLOAD_DIR);
    }
}
```

Per le **singole sottocartelle**, il pattern consigliato è crearle on-demand prima di scrivere:

```php
function upload_ensure_dir(string $subpath): string {
    $full = UPLOAD_DIR . '/' . ltrim($subpath, '/');
    if (!is_dir($full)) {
        mkdir($full, 0755, true); // crea ricorsivamente se non esiste
    }
    return $full;
}
```

Uso:

```php
$dest = upload_ensure_dir('loghi') . '/' . $filename;
move_uploaded_file($_FILES['logo']['tmp_name'], $dest);
```

---

## Passo 5 — Servire i file via PHP (non via URL diretta)

Poiché `quack-uploads` è fuori da `htdocs`, il browser non può accederci direttamente. I file vanno serviti tramite un controller PHP, es.:

```php
// GET /quack/?action=file&path=loghi/gemina.png
$allowed = ['loghi', 'header_aziende', 'documenti'];
$rel     = $_GET['path'] ?? '';
$segment = explode('/', $rel)[0];

if (!in_array($segment, $allowed, true) || str_contains($rel, '..')) {
    http_response_code(403); exit;
}

$full = UPLOAD_DIR . '/' . $rel;
if (!file_exists($full)) {
    http_response_code(404); exit;
}

header('Content-Type: ' . mime_content_type($full));
readfile($full);
exit;
```

> Questo approccio ha anche il vantaggio di poter aggiungere controlli di autenticazione sui file.

---

## Riepilogo checklist

- [ ] Creare `C:\Users\michel.papetti\quack-uploads\` con le sottocartelle
- [ ] Aggiungere `define('UPLOAD_DIR', ...)` in `config.php`
- [ ] Sostituire `APP_ROOT_DIR . '/uploads'` con `UPLOAD_DIR` nel codice
- [ ] Aggiungere il controllo di esistenza in `bootstrap.php`
- [ ] Aggiungere la funzione `upload_ensure_dir()` in `functions.php`
- [ ] Creare un controller per servire i file via PHP
- [ ] Spostare i file già caricati dalla vecchia cartella `uploads/` alla nuova
- [ ] Aggiornare `.gitignore` per escludere la vecchia `uploads/` se non già fatto

---

## Note per la produzione

In produzione (Linux + Apache/Nginx) assicurati che:

1. La cartella upload sia di proprietà dell'utente web (`www-data` o `apache`): `chown -R www-data:www-data /var/www/quack-uploads`
2. I permessi siano `755` per le cartelle, `644` per i file
3. La cartella **non sia** dentro il document root di Apache/Nginx
4. Il path in `config.php` sia aggiornato per l'ambiente di produzione (o usa una variabile d'ambiente)
