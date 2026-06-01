# Guida per permettere a php\xampp di creare file Word (DOCX) su Windows

## 1️⃣ Prerequisiti

- **XAMPP** (Apache + PHP) installato e avviato.
- **PHP 8.0+** (il progetto usa PHP 8.2). Verifica con `php -v`.
- **Composer** installato (gestore pacchetti PHP).

## 2️⃣ Abilitare le estensioni PHP

1. Apri `C:\xampp\php\php.ini`.
2. Rimuovi il punto e virgola (`;`) davanti a queste righe (aggiungile se non esistono):
   
   ```ini
   extension=zip    ; necessario per PHPWord (crea .docx)
   extension=gd2    ; consigliata per mPDF se usi immagini
   ```
3. Salva il file e **riavvia Apache** dal pannello di controllo XAMPP (Stop → Start).

## 3️⃣ Installare le librerie con Composer

Apri PowerShell, spostati nella cartella del progetto e lancia i comandi:

```powershell
cd C:\Users\michel.papetti\Documents\xampp\htdocs\Quack
composer require mpdf/mpdf
composer require phpoffice/phpword
```

Composer scaricherà le dipendenze nella cartella `vendor/`.

## 4️⃣ Permessi di scrittura nella cartella `docs`

```powershell
mkdir C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\docs
icacls "C:\Users\michel.papetti\Documents\xampp\htdocs\Quack\docs" /grant *S-1-1-0:(OI)(CI)F
```

> *Nota:* per produzione usa permessi più restrittivi.

## 5️⃣ Aggiornare `.htaccess` (solo se usi script di test nella root)

Apri il file `.htaccess` nella cartella `Quack` e **prima** della regola finale aggiungi:

```
RewriteCond %{REQUEST_URI} !/test_mpdf\.php$ [NC]
RewriteCond %{REQUEST_URI} !/test_docx\.php$ [NC]
RewriteRule ^ index.php [L,QSA]
```

Salva il file.

## 6️⃣ Script di esempio per generare un PDF (test_mpdf.php)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML('<h1>Ciao PDF</h1>');
$mpdf->Output('test.pdf', \Mpdf\Output\Destination::DOWNLOAD);
?>
```

Visita `http://localhost:8080/Quack/test_mpdf.php` → scarica il PDF.

## 7️⃣ Script di esempio per generare un DOCX (test_docx.php)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
$section->addText('Ciao DOCX');
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save(__DIR__ . '/docs/test.docx');
?>
```

Visita `http://localhost:8080/Quack/test_docx.php` → scarica il DOCX.

## 8️⃣ Verifica finale

1. Apri i due URL sopra e controlla che i download avvengano senza errori.
2. Controlla che il file `docs/test.docx` sia presente e apribile con Microsoft Word.

## 9️⃣ Riepilogo librerie

| Libreria      | Scopo                                 | Installazione                        |
| ------------- | ------------------------------------- | ------------------------------------ |
| **mPDF**      | Genera PDF da HTML/CSS                | `composer require mpdf/mpdf`         |
| **PHPWord**   | Crea file `.docx`                     | `composer require phpoffice/phpword` |
| **zip (PHP)** | Necessaria per compressione DOCX      | `extension=zip` in `php.ini`         |
| **gd2 (PHP)** | Gestisce immagini nei PDF (opzionale) | `extension=gd2` in `php.ini`         |

---

> **Fine della guida** – Salvala nella cartella `docs/` come `setup_guide.md`. Ora la tua web‑app è pronta a produrre PDF e Word senza ulteriori problemi.
