# Quack ERP - Guida allo Sviluppo per AI e Sviluppatori

Questo documento definisce le regole architetturali, i pattern e gli standard di codifica per **Quack ERP**. Qualsiasi agente AI o sviluppatore che crea o modifica codice in questo progetto **deve rispettare rigorosamente** queste linee guida per garantire la manutenibilità, l'efficienza e la pulizia del software.

---

## 1. Architettura MVC (Model-View-Controller)

Il progetto segue una struttura MVC rigida. Non sono ammesse violazioni del perimetro delle responsabilità dei singoli componenti.

*   **Controllers (`app/Controllers/`)**:
    *   Devono occuparsi esclusivamente della gestione delle richieste HTTP (GET/POST), del controllo degli accessi, della validazione dei parametri, delle interrogazioni al DB e della preparazione dei dati.
    *   **È vietato** eseguire output HTML diretto o includere file di layout con `require` manuale.
    *   Tutte le viste devono essere renderizzate esclusivamente tramite il metodo ereditato `$this->page($viewPath, $dataArray)`.
    *   Devono passare alle viste variabili pre-calcolate (es. stati attivi/scaduti, badge calcolati, breadcrumbs), lasciando la vista libera da calcoli complessi o logiche di business.
*   **Views (`app/views/`)**:
    *   Devono contenere solo HTML, markup di visualizzazione e cicli/condizioni PHP semplici per scopi di renderizzazione.
    *   **È assolutamente vietato** eseguire query SQL dirette (es. `$this->pdo->prepare` o chiamate dirette al database) all'interno delle viste.
    *   Le viste non devono definire funzioni PHP globali (es. generatori di badge), altrimenti si rischiano errori di ridefinizione fatale a runtime. Tali funzioni vanno collocate in `includes/functions.php`.
*   **Helpers & Logica Funzionale (`includes/`)**:
    *   I file in `includes/` contengono funzioni helper, librerie di calcolo e logiche riutilizzabili (es. [functions.php](file:///c:/Users/michel.papetti/Documents/xampp/htdocs/Quack/includes/functions.php) o [sales_quotes.php](file:///c:/Users/michel.papetti/Documents/xampp/htdocs/Quack/includes/sales_quotes.php)).
    *   Non devono emettere HTML o mescolare markup alla logica di calcolo pura.

---

## 2. Rispetto del Principio DRY (Don't Repeat Yourself)

La duplicazione del codice è considerata un bug bloccante. Prima di implementare una nuova logica, verifica se esiste già una soluzione centralizzata.

*   **Generazione Progressivi**: Utilizzare sempre la funzione centralizzata `qk_next_sequence_number(PDO $pdo, string $tipo)` definita in `includes/functions.php`. Non implementare algoritmi di incremento locali.
*   **Logica Matematica e Calcoli**: I calcoli finanziari (imponibile, IVA, scorpori, conversioni di unità di misura) devono essere centralizzati in funzioni helper riutilizzabili (es. `qk_calcola_riga_offerta()`). Non duplicare mai formule e passaggi BCMath all'interno di inserimenti e aggiornamenti separati.
*   **Fogli di Stile Condivisi**: Non definire blocchi `<style>` inline o all'interno delle singole viste per scopi condivisi. Spostare le classi comuni in [sales-common.css](file:///c:/Users/michel.papetti/Documents/xampp/htdocs/Quack/assets/css/sales-common.css) o nel file principale `assets/css/app.css`.

---

## 3. Esternalizzazione di JavaScript

Le viste non devono contenere script monolitici inline. La logica client-side complessa va organizzata come segue:

1.  **File JS Esterni (`assets/js/modules/` o `assets/js/`)**:
    *   Tutto il codice JavaScript per la gestione di tabelle dinamiche, autocompletamenti, calcoli interattivi e interazioni con le API deve risiedere in file statici `.js` dedicati.
2.  **Passaggio dei Dati Dinamici (PHP $\rightarrow$ JS)**:
    *   Per passare configurazioni, URL di endpoint API o flag di permessi a JavaScript, definire un oggetto di configurazione globale nella pagina prima del caricamento del file JS statico:
    ```html
    <script>
      window.QK_MY_MODULE_CONFIG = {
        apiEndpoint: <?= json_encode(url('my/api/endpoint')) ?>,
        canEdit: <?= $canEdit ? 'true' : 'false' ?>,
        values: <?= json_encode($values) ?>
      };
    </script>
    <script src="<?= asset('js/modules/my-module.js') ?>"></script>
    ```
3.  **Ambito Globale Pulito**: Racchiudere sempre il codice JS in una IIFE (`(function() { ... })()`) ed attivare la modalità rigorosa (`'use strict';`).

---

## 4. Linee Guida per UI/UX e Navigazione

Tutte le interfacce utente devono rispettare la coerenza e il design system aziendale specificato in [UI_UX_STANDARDS.md](file:///c:/Users/michel.papetti/Documents/xampp/htdocs/Quack/docs/UI_UX_STANDARDS.md):

*   **Breadcrumbs**: Ogni vista di elenco, creazione, modifica o dettaglio deve definire e passare l'array `$breadcrumbs` al layout, garantendo la navigabilità gerarchica.
*   **Tabelle (`.qk-table`)**:
    *   La colonna identificativa principale (es. Numero Documento, Ragione Sociale, Codice) deve essere un link cliccabile che porta alla modifica o al dettaglio della riga.
    *   L'ultima colonna deve essere riservata alle azioni rapide (classe `.col-actions`), contenente pulsanti in miniatura (`btn-qk-sm`) uniformi.
*   **Token di Colore**: Non utilizzare colori esadecimali (`#fff`) o RGB inline nei tag HTML. Usare esclusivamente le variabili CSS del sistema (es. `var(--qk-accent)`, `var(--qk-border)`).

---

## 5. Pulizia del Codice e Convenzioni

*   **Eliminazione del Codice Legacy**: Non mantenere commentati vecchi blocchi di codice non più utilizzati né conservare file orfani o di backup (`.bak`, `.tmp`, `scratch_*`). Mantieni la directory pulita.
*   **Lingua dei Commenti**: Tutti i commenti al codice, le docstring e le spiegazioni interne devono essere scritti in **italiano**, in modo chiaro, conciso e professionale.
*   **Sicurezza (CSRF & XSS)**:
    *   Ogni form di tipo POST deve includere il token di sicurezza tramite la funzione `<?= csrf_field() ?>`.
    *   Ogni output dinamico stampato all'interno dell'HTML deve essere protetto contro attacchi XSS tramite la funzione di escaping `<?= e($valore) ?>`.

---

## 6. Verifica Pre-Commit obbligatoria

Prima di concludere qualsiasi modifica o presentare un piano di rilascio, lo sviluppatore o l'agente AI **deve verificare la sintassi** di tutti i file PHP modificati eseguendo da terminale:

```bash
php -l nome_file.php
```

Assicurati che non vi siano errori di parsing, tag non chiusi (`endif;`, `endforeach;`) o riferimenti a variabili inesistenti.
