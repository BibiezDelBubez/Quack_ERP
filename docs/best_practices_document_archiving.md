# Archiviazione Immutabile dei Documenti: Deep Dive Tecnico

A seguito dell'analisi sulle best practices adottate dai sistemi ERP "best in class", analizziamo in dettaglio le 3 modalità tecniche principali per risolvere il problema del "rendering dinamico" in Quack ERP. Vedremo cosa implicano a livello di architettura, database e logica applicativa.

---

## 1. File Storage (Salvataggio dello Snapshot Fisico)
Questa è la soluzione più pragmatica e diffusa in ERP agili come Odoo. L'idea è trattare il PDF come un "prodotto finito" e salvarlo fisicamente nel momento esatto in cui diventa ufficiale (es. quando viene inviato via email al cliente).

### Cosa implica tecnicamente:
Invece di avere una singola rotta API (es. `/api/quote/{id}/pdf`) che legge dal DB e "sputa" un PDF in stream, il backend viene modificato per eseguire un'azione di salvataggio.

1. **Storage Layer:** Serve un file system condiviso o, preferibilmente, un Object Storage (es. AWS S3, MinIO, Azure Blob Storage). Salvare file binari direttamente dentro il database (come BLOB) è sconsigliato perché appesantisce i backup e degrada le performance.
2. **Database:** Si crea una nuova tabella per tracciare gli allegati generati, collegata in relazione 1-a-N con il preventivo.

**Esempio di Schema DB:**
```sql
CREATE TABLE quote_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL, -- es. 's3://bucket/quotes/2026/quote_123_v1.pdf'
    file_name VARCHAR(100) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT, -- ID dell'utente che l'ha generato
    is_sent BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (quote_id) REFERENCES quotes(id)
);
```

### Flusso Logico (Backend):
1. L'utente clicca "Invia Preventivo".
2. Il server raccoglie i dati correnti dal DB.
3. Il motore di templating (es. wkhtmltopdf, Puppeteer) genera il PDF in memoria o in una cartella `/tmp`.
4. Il server fa l'upload del file su S3.
5. Il server inserisce un record in `quote_documents` con il link di S3.
6. L'email al cliente parte allegando il file scaricato da S3 (o includendo un link sicuro).
7. Se il cliente o un operatore vuole rivedere quel documento, l'interfaccia non chiama la funzione di generazione, ma interroga la tabella `quote_documents` e fornisce l'URL di download diretto.

---

## 2. Versioning del Documento (Gestione delle Revisioni)
Questo è l'approccio usato da Salesforce. Non solo salva il file, ma introduce un workflow rigoroso in cui un documento, una volta emesso, è "bloccato" a livello applicativo. Se serve modificarlo, si crea una nuova "Revisione".

### Cosa implica tecnicamente:
Richiede modifiche strutturali profonde alla macchina a stati (State Machine) del preventivo.

1. **Stato Lockato:** Un preventivo inviato passa allo stato `SENT` (Inviato). In questo stato, i campi del preventivo (righe, sconti, intestazioni) **non sono più modificabili** dall'interfaccia (i campi diventano *readonly*).
2. **Azione di Revisione:** L'utente non può modificare il preventivo 100, ma deve cliccare "Crea Revisione". Il sistema clona il preventivo 100 creando il preventivo 100-RevA.

**Esempio di Schema DB:**
```sql
-- La tabella base dei preventivi viene modificata
ALTER TABLE quotes ADD COLUMN parent_quote_id INT DEFAULT NULL;
ALTER TABLE quotes ADD COLUMN revision_number INT DEFAULT 0;
ALTER TABLE quotes ADD COLUMN status ENUM('DRAFT', 'SENT', 'SUPERSEDED', 'ACCEPTED') DEFAULT 'DRAFT';
```

### Flusso Logico (Backend):
1. **Preventivo 100 (DRAFT):** L'utente lo modifica liberamente. Se scarica il PDF, è un PDF temporaneo ("Bozza") non salvato.
2. **Invio Preventivo 100:** Lo stato passa a `SENT`. Il PDF ufficiale viene generato e salvato (vedi Modalità 1). I campi nel DB vengono bloccati.
3. **Modifica (Nuova Revisione):** Il cliente chiede uno sconto. L'utente clicca "Revisione".
4. Il backend duplica i record di `quotes` e `quote_lines`. Nasce il Preventivo 101, ma con lo stesso numero visivo (es. "PREV-100/A"). Il vecchio Preventivo 100 passa allo stato `SUPERSEDED` (Superato).
5. L'utente modifica il PREV-100/A e lo invia. Viene generato un *nuovo* PDF.
6. **Storico:** Il sistema ha l'esatta fotografia tabellare e documentale di ogni negoziazione.

> [!TIP]
> **Vantaggio:** È la soluzione più robusta legalmente, perché anche il dato strutturato nel database è congelato e storicizzato, non solo il PDF.

---

## 3. Data Freezing / Snapshotting Dati (Senza PDF Storage)
In questa modalità non si salva fisicamente il file PDF, ma si salva una "fotografia relazionale" di tutti i dati esatti nel momento in cui è stato generato il documento. Il PDF si ricalcola al volo, ma attingendo a questa "fotografia" statica anziché ai dati "vivi".

### Cosa implica tecnicamente:
Questa è la soluzione architettonicamente più complessa, perché i sistemi ERP hanno dati altamente normalizzati (i nomi clienti, gli indirizzi, i nomi prodotti sono in altre tabelle e possono cambiare nel tempo).

1. **Denormalizzazione degli Storici:** Serve creare un set di tabelle parallele ("Shadow Tables") che copiano fisicamente i valori di anagrafiche e listini.

**Esempio di Schema DB:**
```sql
-- Tabella Shadow del Preventivo
CREATE TABLE quote_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    snapshot_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    customer_name_at_the_time VARCHAR(255), -- Denormalizzato!
    customer_address_at_the_time TEXT,      -- Denormalizzato!
    total_amount DECIMAL(10,2)
);

-- Tabella Shadow delle Righe
CREATE TABLE quote_line_history (
    history_line_id INT AUTO_INCREMENT PRIMARY KEY,
    history_id INT NOT NULL,
    product_code_at_the_time VARCHAR(50),
    product_description_at_the_time TEXT, -- Se oggi la descrizione prodotto cambia, qui resta quella vecchia
    price_at_the_time DECIMAL(10,2)
);
```

### Flusso Logico (Backend):
1. L'utente invia il preventivo.
2. Il backend legge i dati *attuali* (anagrafica cliente aggiornata, descrizioni articoli attuali) e li inserisce "piatti" (denormalizzati) nelle tabelle `quote_history` e `quote_line_history`.
3. Anni dopo, l'utente chiede il PDF di quel vecchio preventivo.
4. Il backend legge i dati dalla tabella `quote_history`, ignora le anagrafiche vive attuali (dove magari il cliente ha cambiato ragione sociale), e genera un PDF al volo.
5. Il PDF restituito è identico a quello originale, perché usa gli stessi identici dati (congelati) di allora.

> [!WARNING]
> **Rischio del Data Freezing:** 
> Questa tecnica garantisce l'integrità dei dati testuali, ma è molto prona a bug strutturali. Ad esempio: se cambi il template visivo del PDF (il logo dell'azienda, il font, la disposizione delle colonne), quando rigeneri il PDF del 2023 con il codice del 2026, il documento *sembrerà* diverso, anche se i dati sono corretti. Il cliente potrebbe contestare la validità del file perché non ha lo stesso identico aspetto di quello che ha firmato.

## Conclusione: Quale Scegliere?
*   Per un sistema moderno e sicuro senza stravolgere la base dati: **Modalità 1 (File Storage su S3)** è la via più veloce e garantisce la prova visiva esatta.
*   Per un sistema aziendale rigoroso (approccio Enterprise): **Modalità 2 (Versioning + File Storage)**. Stravolge un po' i flussi di lavoro degli utenti (che non possono più sovrascrivere i preventivi), ma elimina ogni errore umano.
*   **Modalità 3 (Data Freezing)** è sconsigliata per i PDF proprio a causa dei cambiamenti nel layout/motore di rendering nel corso degli anni, ma è utile solo a livello di Audit Log interno.
