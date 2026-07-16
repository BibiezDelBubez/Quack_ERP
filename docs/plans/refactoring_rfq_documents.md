# Piano di Refactoring: Rimozione God Object da RfqController (MVC)

Questo piano descrive i passaggi per risolvere in modo chirurgico la violazione MVC presente in `RfqController.php` legata al rendering dei documenti PDF e DOCX (Segnalazione 4 e Revisione Ciclo Acquisti §3bis-A.4). 
L'obiettivo è separare la logica di recupero dati (Model), la struttura visiva (View) e la logica di controllo (Controller), eliminando le 600 righe di codice procedurale.

## User Review Required

> [!IMPORTANT]
> **Disaccoppiamento dal ciclo HTTP (Background Job Ready):**
> Come evidenziato dal documento di revisione, l'attuale implementazione usa un `exit;` brutale a fine metodo, impedendo di generare PDF in background (es. per l'invio email schedulato). Il nuovo `RfqDocumentService` restituirà i **byte crudi** (o salverà su file) senza mai emettere output diretto o chiamare `exit`. Sarà il Controller a occuparsi di inviare gli header HTTP corretti. Confermi questa astrazione?

## Proposed Changes

---

### Livello Dati (Model / Helpers)
Attualmente il controller esegue query `SELECT * FROM indirizzi WHERE soggetto_id = ? ...` in modo duplicato.

#### [MODIFY] [purchases_rfq.php](file:///C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/includes/purchases_rfq.php)
- Creazione della nuova funzione helper `qk_rfq_get_fornitore_indirizzo(PDO $pdo, int $fornitore_id)` che astrae la logica della query.

---

### Livello Viste (View)
La struttura HTML hardcoded e lo stile CSS inline per il PDF verranno estratti dal controller.

#### [NEW] [app/views/purchases/rfq/_pdf_template.php](file:///C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/views/purchases/rfq/_pdf_template.php)
- Conterrà esclusivamente markup HTML e logica di visualizzazione (cicli foreach sulle `$righe`). Sarà il template nativo per Mpdf.

---

### Livello di Servizio (Service)
Il controller non deve istanziare Mpdf o PhpWord.

#### [NEW] [app/Services/RfqDocumentService.php](file:///C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/Services/RfqDocumentService.php)
- **Metodo `generatePdf(...)`**: Inizializza Mpdf, carica la view `_pdf_template.php`, elabora il buffer e **restituisce il contenuto binario** (`Output('', \Mpdf\Output\Destination::STRING_RETURN)`). Nessun `exit()`.
- **Metodo `generateDocx(...)`**: Inizializza PhpWord, applica la formattazione e salva il file in una directory temporanea restituendone il path assoluto.

---

### Livello di Controllo (Controller)
Pulizia finale del God Object.

#### [MODIFY] [RfqController.php](file:///C:/Users/michel.papetti/Documents/xampp/htdocs/Quack/app/Controllers/RfqController.php)
- Rimozione completa delle ~600 righe di codice per il rendering in `pdf()` e `docx()`.
- Delega al Service. Il controller prenderà il risultato (binario o path) e si occuperà *lui solo* di inviare gli header `Content-Type` corretti e fare l'`exit;`, mantenendo il Service agnostico rispetto al Web.

## Verification Plan

1. **Verifica isolata pre-modifica**: Nessuna modifica al database.
2. **Validazione Sintassi (`php -l`)**: Ogni file estratto verrà sottoposto al linter di PHP.
3. **Controllo manuale del Controller**: Useremo PowerShell per accertarci che `RfqController.php` sia sceso dalle attuali 1292 righe a circa 700 righe.
4. **Endpoint Test**: Simuleremo la generazione da linea di comando (CLI) per provare che il Service funziona perfettamente anche senza una request Web, validando così il disaccoppiamento HTTP descritto al punto A.4.
