# Project Rules per Quack ERP

Tutte le intelligenze artificiali che lavorano in questo workspace (Quack) devono obbligatoriamente rispettare e imporre le seguenti regole ad ogni step di lavoro, senza alcuna eccezione:

## Regola Aurea: Rispetto Assoluto dei Principi DRY (Don't Repeat Yourself) e MVC (Model-View-Controller)
- **Zero SQL crudo nei Controller:** È severamente vietato scrivere query dirette (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) nei file Controller (`app/Controllers/*`). Tutte le operazioni sul database DEVONO avvenire tramite Helper centralizzati (nella cartella `includes/`) o pattern Model dedicati.
- **Divieto di duplicazione del codice (DRY):** Qualsiasi logica, che sia una singola query o un calcolo, che debba essere usata in due punti diversi del progetto deve essere centralizzata. Se noti SQL crudo preesistente o codice duplicato, modificalo per usare l'helper, estraendolo se non esiste.
- **Controller leggeri:** I controller devono esclusivamente gestire la richiesta HTTP, coordinare le chiamate ai file di logica e restituire la vista o il JSON, mantenendo le responsabilità isolate. Qualsiasi violazione di questa regola individuata nel codice preesistente va sollevata e documentata.

Qualsiasi intervento sul codice sorgente o stesura di nuovi piani tecnici che violi questa Regola Aurea è da considerarsi invalido e difettoso fin dalla progettazione.

## Regola sui Controlli e Divieto di Deduzione Automatica
Quando l'utente richiede un controllo approfondito (o "riga per riga"), questo deve essere eseguito in maniera letterale e rigorosa, senza alcuna scorciatoia. 
È TASSATIVAMENTE VIETATO usare script Python, script Bash o espressioni regolari (Regex) automatiche per dedurre i risultati. 
È TASSATIVAMENTE VIETATO basarsi esclusivamente o ciecamente sullo storico di Git, su assunzioni teoriche o sul lavoro fatto precedentemente da altre AI. Non fidarti MAI ciecamente: verifica sempre!
Le AI devono obbligatoriamente usare gli strumenti nativi di lettura (come il tool `view_file`) per estrarre fisicamente il codice all'interno del proprio contesto di memoria, esplorare TUTTO l'albero delle dipendenze del blocco modificato e leggerlo con i propri "occhi".
**Inoltre, quando ricontrolli la bontà di un codice da te scritto o modificato, NON limitarti al solo controllo sintattico (es. "la sintassi è corretta"). Questo non basta! Devi assicurarti che il codice funzioni davvero a livello logico.**
Particolare attenzione va prestata al download di file binari (PDF, DOCX, ZIP): in PHP, devi SEMPRE garantire che il buffer di output sia pulito (`while (ob_get_level()) ob_end_clean();`) e che l'esecuzione termini (`exit;`) per evitare file corrotti.

## Regola sul Refactoring: Prevenzione di Logic Bomb
Durante il refactoring del codice, specialmente quando si separano le competenze (es. spostando JS inline in file esterni o aggiornando classi CSS/ID), è **obbligatorio** adottare una verifica incrociata rigorosa:
- **Modifiche a Classi/ID HTML:** Se rimpiazzi o rinomini una classe CSS o un ID in un file HTML/PHP, DEVI sempre cercare all'interno dei file JavaScript associati se quella classe/ID era utilizzata come selettore (es. nei `querySelector`, `closest`, o `addEventListener`). Fallire in questo step "scollega" gli eventi JS, distruggendo silenziosamente la logica dell'applicazione pur lasciando la sintassi intatta.
- **Estrazione di Codice (Separation of Concerns):** Quando sposti del codice (JS o CSS) da un blocco inline (es. `<script>` o `<style>`) a un file esterno, DEVI fisicamente e contestualmente cancellare l'intero blocco originario dal file di partenza. Lasciare il codice duplicato genera doppie esecuzioni, memory leak e comportamenti impredicibili.
## Regola sulla Codifica dei File (Encoding UTF-8)
In ambiente Windows, PowerShell utilizza codifiche non standard per default (come UTF-16 LE o Windows-1252), il che corrompe i file contenenti caratteri accentati (es. i file Markdown).
È FATTO DIVIETO di usare l'operatore di reindirizzamento (> o >>) o Out-File senza specificare forzatamente la codifica UTF-8.
Per creare o modificare i file di progetto, **privilegiare SEMPRE i tool nativi** (write_to_file, replace_file_content, multi_replace_file_content) che preservano nativamente l'UTF-8 e non corrompono i caratteri.
Se è assolutamente necessario utilizzare uno script PowerShell per scrivere su disco, si deve obbligatoriamente utilizzare Add-Content o Set-Content con il parametro -Encoding UTF8.
**Inoltre, laddove possibile (specialmente nei commenti tecnici, nei nomi di file o in porzioni di testo non strettamente legate all'interfaccia utente), si invita caldamente a EVITARE l'uso di lettere accentate italiane.** Preferire parole senza accenti o traduzioni inglesi per ridurre alla radice qualsiasi rischio di corruzione UTF-8.

## Regola sull'Autorizzazione ai Piani di Implementazione
È TASSATIVAMENTE VIETATO procedere con l'esecuzione di un piano di implementazione (Planning Mode) basandosi su deduzioni o via libera "impliciti" derivanti da messaggi ambigui o tangenziali dell'utente (ad es. "Ah dimenticavo...", "Ok intanto guarda questo...").
L'esecuzione di un piano deve avvenire ESCLUSIVAMENTE a seguito di un'autorizzazione chiara, esplicita e inequivocabile da parte dell'utente (es. "approvo", "procedi", "esegui il piano", "ok vai"). In caso di dubbio, l'AI deve fermarsi e chiedere conferma esplicita prima di alterare qualsiasi file.

## Linee Guida Architetturali (Fortemente Consigliate)
- **Separazione delle Competenze (Separation of Concerns):** Evitare "minestroni" di codice. Mantenere separati i file PHP (logica/template) dai file JavaScript (comportamento) e CSS (stile). Se trovi blocchi `<script>` o `<style>` inline molto lunghi in una view, valuta di estrarli in file statici e caricarli esternamente.
- **Divieto di File Monolitici:** Evita di creare file monolitici e giganteschi quando non è strettamente necessario. Modula il codice in componenti, funzioni o file più piccoli per favorirne la leggibilità, la manutenibilità e il riutilizzo.

## Linee Guida UI/UX (Fortemente Consigliate)
- **Rispetto degli Standard UI/UX Centralizzati:** Prima di creare o modificare elementi grafici, leggi il file `docs/UI_UX_STANDARDS_v2.md`. Utilizza ESCLUSIVAMENTE i componenti, le classi e i token CSS definiti al suo interno o già disponibili a livello globale. È vietato creare CSS inline o nuove classi per comportamenti già coperti (es. non creare `.my-btn`, usa `.btn-qk`).
- **Ricerca Preventiva nelle Librerie CSS Globali (DRY CSS):** Attenzione: il documento `UI_UX_STANDARDS_v2.md` potrebbe non essere aggiornato con tutte le utility CSS create nel tempo. **Prima di inventare o scrivere nuove classi CSS personalizzate, DEVI SEMPRE cercare all'interno dei file `assets/css/app.css` e `assets/css/theme.css`** per verificare se esiste già una classe globale adatta al tuo scopo (es. layout flex, spaziature, colori, tipografia).
  - Alcuni esempi di classi centralizzate già disponibili:
  - **Bottoni:** `.btn-qk`, `.btn-qk-primary`, `.btn-qk-secondary`, `.btn-qk-danger`, `.btn-qk-sm` (per icone o table-actions).
  - **Input e Form:** `.qk-form-control`, `.qk-form-control-sm`.
  - **Contenitori e Struttura:** `.qk-card`, `.qk-card-header`, `.qk-card-body`, `.qk-table`, `.qk-table-wrap`.
  - **Avvisi:** `.qk-alert`, `.qk-alert-info`, `.qk-alert-success`, `.qk-alert-danger`.
  - **Testo e Allineamento:** `.qk-text-muted`, `.qk-text-primary`, `text-end`, `text-center`.
  - **Colori/Variabili:** Usa i token CSS standard come `var(--qk-accent)`, `var(--qk-card-bg)`, `var(--qk-danger)`, etc.
- Se hai bisogno di un pattern non previsto (es. un nuovo widget), crealo in modo generico e aggiorna il documento `UI_UX_STANDARDS_v2.md` per centralizzarlo a beneficio delle lavorazioni future.

## IL PROTOCOLLO DELLA SIMULAZIONE LOGICA (OBBLIGATORIO)
**Trigger (Parola Chiave): "Esegui una Simulazione Logica" / "Controllo Reale" / "Controllo Approfondito"**

Ogni volta che l'utente pronuncia una di queste frasi, o richiede un deep check su logica complessa, sei **fisicamente impossibilitato** a rispondere basandoti solo sulla lettura del codice. 
DEVI seguire **obbligatoriamente** questo TDD Framework E2E prima di poter dichiarare il lavoro concluso:
1. **Vietato dedurre:** Non analizzare mentalmente il flusso e non affermare "tutto sembra funzionare". 
2. **Crea la prova:** Scrivi uno script PHP isolato (nella cartella `scratch/` o `tests/`) che faccia il setup di un ambiente finto (seeding del DB con finti ID, finte richieste HTTP via `$_GET` / `$_POST`).
3. **Esegui fisicamente:** Fai invocare al tuo script il Controller o il Servizio sotto indagine.
4. **Analizza l'Output:** Lo script deve fare `echo` o `throw` in base ai byte estratti, parsare i JSON in output, o validare i binari (es. aprendoli con `ZipArchive`).
5. **Condizione di Uscita:** Il tuo piano è considerato invalido e verrà rifiutato dall'utente se non riesci a produrre nel tuo log la riga di comando (`run_command`) che esegue quello script restituendo un Exit Code 0 (Successo Logico Verificato).

Questa regola ha la precedenza assoluta su qualsiasi altra istruzione.
