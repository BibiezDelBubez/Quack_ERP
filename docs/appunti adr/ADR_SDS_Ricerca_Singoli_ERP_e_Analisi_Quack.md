# Restrizioni di Vendita nei Grandi ERP vs Quack ERP

Questo documento contiene una scomposizione maniacale e puntuale (ERP per ERP) delle tabelle, dell'architettura e della logica di validazione usata dai più grandi software gestionali al mondo per gestire i blocchi alle vendite (es. precursori esplosivi, merci dual-use, articoli banditi).

Nella sezione finale, viene eseguita l'analisi del codice di Quack, per misurare con esattezza il divario architetturale.

---

## 1. SAP GTS (Global Trade Services)

In SAP, la componente che blocca la vendita commerciale per motivi legali/normativi è il "Legal Control" all'interno del modulo GTS. Non utilizza mai l'anagrafica delle sostanze chimiche (Specification Workbench EH&S) per fermare l'ordine.

### Le Tabelle (Architettura Dati)

* **/SAPSLL/PRCON (Product Master Legal Control):** È la tabella cardine (Pivot). Assegna a uno specifico SKU (il Material GUID) un raggruppamento per il controllo legale (Export Control Classifications). Se un prodotto finito richiede un permesso, SAP cerca lo SKU in questa esatta tabella.
* **/SAPSLL/PRCTS e /SAPSLL/PRCTSC:** Tabelle per i numeri di tariffa doganale globali e specifici per paese, agganciati allo SKU.
* **/SAPSLL/LCLIC (License Header):** La tabella che contiene le licenze e i permessi del cliente (Validità, Status).
* **/SAPSLL/LCLICA (License Attributes) & /SAPSLL/LCLICV (License Values):** Tracciano i valori o le quantità specifiche autorizzate per quella licenza.
* **/SAPSLL/CUIT (Customs Document Item):** Tabella transazionale GTS corrispondente alla riga dell'ordine di vendita (`VBAP` in SAP SD).

### La Logica di Blocco (Hard Block)

Il controllo scatta tramite BAdI o User Exits (`USEREXIT_SAVE_DOCUMENT`) quando si prova a salvare un ordine (transazione `VA01`).
Il motore GTS prende il materiale della riga e interroga `/SAPSLL/PRCON`. Se trova che l'articolo appartiene a una classe sottoposta a restrizione, cerca una licenza valida in `/SAPSLL/LCLIC` per quel cliente. Se non la trova, il sistema blocca l'item in `/SAPSLL/CUIT` con stato bloccato e l'ordine non può proseguire. 
**Conclusione:** SAP restringe sullo SKU, non sulla composizione.

---

## 2. Microsoft Dynamics 365 Supply Chain Management

In D365, il blocco geografico o normativo per la vendita non è gestito dal PLM (Product Lifecycle Management) delle sostanze chimiche, ma da una feature nativa del modulo Inventory Management chiamata **"Restricted products regional lists"**.

### Le Tabelle (Architettura Dati)

* **InventTable:** La tabella core che rappresenta l'anagrafica articoli (SKU).
* **InventRestrictionList:** Tabella di testata che definisce la lista di restrizione (es. "Precursori Esplosivi", "Dual-Use").
* **InventRestrictionListDetail:** Questa è la **Tabella Intermedia (Pivot)**. Contiene il mapping esatto tra l'`Item ID` (lo SKU) e il List ID. 
* **CustTable:** L'anagrafica clienti.

### La Logica di Blocco (Hard Block)

L'amministratore naviga in *Inventory management > Setup > Product compliance > Restricted products regional lists* e mappa fisicamente l'`Item ID` desiderato dentro la lista di restrizione.
Durante l'inserimento di una riga di ordine di vendita (`SalesLine`), il motore valida la riga interrogando `InventRestrictionListDetail`. Se il prodotto è presente, incrocia la regione/nazione del cliente o le eccezioni (`Approved Customer Lists`). Se la validazione fallisce, l'interfaccia blocca fisicamente l'utente con un Infolog (errore in rosso a schermo: *"Item %1 is restricted for sale..."*) ed esegue un rollback dell'inserimento della riga nel DB.
**Conclusione:** D365 restringe sullo SKU, non sulla composizione.

---

## 3. Oracle Global Trade Management (GTM)

Oracle GTM affronta il problema trattandolo come un controllo transazionale sulle esportazioni (Trade Controls), basandosi sul concetto di "Item Classification".

### Le Tabelle (Architettura Dati)

* **EGP_ITEM_CLASSES_B / ITEM:** Tabelle core per gli articoli del modulo SCM.
* **GTM_PROD_CLASS_CODE:** Definisce le classificazioni normative (es. HTS, ECCN, Precursori).
* **GTM_ITEM_CLASSIFICATION:** La **Tabella Intermedia (Pivot)** che mappa un `ITEM_GID` (Global Identifier dello SKU) con la classe di restrizione `GTM_PROD_CLASS_CODE`.
* **GTM_DENIED_PARTY / GTM_PARTY_LICENSE:** Tabelle che contengono i clienti (Parties) bannati o dotati di specifiche licenze.
* **GTM_TR_PROD_CLASSIFICATION:** Tabella transazionale a livello di riga d'ordine (Transaction Line).

### La Logica di Blocco (Hard Block)

La restrizione non esiste in una "tabella delle restrizioni" unica, ma tramite le **Compliance Policy Templates**. Lo *Screening Engine* di Oracle legge l'`ITEM_GID` della riga, interroga `GTM_ITEM_CLASSIFICATION` e, se trova una policy restrittiva, verifica in tempo reale se la transazione o il cliente hanno i permessi in `GTM_PARTY_LICENSE`. Se il permesso manca, lo status della Transaction Line viene cambiato in "Requires License" / "ON HOLD".
**Conclusione:** Oracle restringe sull'Item (SKU), non sulla composizione.

---

## 4. Infor M3

In Infor M3, le restrizioni commerciali vengono gestite combinando lo stato dell'articolo nel magazzino con framework di restrizione prodotto. Non c'è una tabella "sostanze esplosive" usata per fermare le vendite.

### Le Tabelle (Architettura Dati)

* **MITMAS:** Tabella Item Master per le impostazioni globali dell'articolo.
* **MITMAW:** Tabella Item/Warehouse Record. È la tabella cruciale. Lega l'articolo al magazzino.
* **MMS002 (Item. Connect Warehouse):** Programma UI che governa la MITMAW.
* **OIS017 (Customer/Item Agreement) / Assortments:** Strutture usate per restringere attivamente quali articoli possono essere venduti a specifici clienti.

### La Logica di Blocco (Hard Block)

Le restrizioni di vendita (es. per merci pericolose senza licenza) in M3 vengono tipicamente gestite cambiando l'"Acquisition Code" in `MITMAW` o lo status dell'item/warehouse a un livello che non permette la vendita (es. Code 3), oppure creando "Assortment" o regole esclusive in modo che il cliente X non possa inserire l'articolo Y in un ordine.
**Conclusione:** Infor M3 restringe sull'Item (SKU) combinato al magazzino/cliente, non sulla composizione.

---

---

## PARTE 5: L'ANALISI DEL MOTORE DI QUACK (Cosa Fa Oggi)

Basandoci sulla ricerca granitica che dimostra come tutti i grandi ERP applichino il blocco normativo/commerciale agganciandolo all'Anagrafica Articoli (SKU), andiamo ad analizzare riga per riga il codice di Quack, per determinare se è allineato a questo paradigma.

### 5.1 Il Modello Dati (Le Tabelle in `init_sqlite.sql`)

Nel database di Quack convivono **due tabelle antitetiche** per la gestione delle restrizioni:

1. **L'Approccio Enterprise:** `prodotti_restrizioni` (una Tabella Intermedia che accetta `prodotto_id` e `restriction_type_id`). Questa struttura è architetturalmente perfetta e identica a `InventRestrictionListDetail` (D365) o `/SAPSLL/PRCON` (SAP).
2. **L'Approccio Legacy:** `sostanze_chimiche_restrizioni` (una Tabella Intermedia che lega il vincolo direttamente alla Sostanza Chimica).

### 5.2 Il Codice Backend (`ComplianceRepository.php`)

Sebbene il database contenga la tabella corretta per operare come SAP e Oracle, il codice che governa il motore di Quack fa esattamente l'opposto.

Nel metodo `getProductRestrictions(int $productId)` del file `app/Repositories/ComplianceRepository.php`, Quack esegue questa query come via preferenziale (indicata nel codice come "Percorso 1: best practice"):

```sql
SELECT r.id, r.code, r.name, r.regulatory_ref, r.requires_vat, r.validity_days, r.log_transactions,
       scr.concentrazione_min AS min_concentration,
       scr.concentrazione_max AS max_concentration
  FROM compliance_restriction_types r
  JOIN sostanze_chimiche_restrizioni scr ON scr.restriction_type_id = r.id
  JOIN sostanze_chimiche sa ON sa.id = scr.sostanza_id
  JOIN prodotti p ON p.sostanza_chimica_id = sa.id
 WHERE p.id = :prodotto_id
```

**Analisi Funzionale:**
Quack prende lo SKU a carrello (`p.id`), richiede che questo SKU abbia obbligatoriamente una `sostanza_chimica_id` valorizzata, esegue una join con `sostanze_chimiche` e poi va a guardare se **quella sostanza** si trova in `sostanze_chimiche_restrizioni`.

**Il Difetto Architetturale (Gap):**
In nessuna parte del backend (né nel servizio né nel repository), Quack interroga la tabella `prodotti_restrizioni`. 
Questo significa che Quack vincola le vendite in base alla composizione chimica, un approccio totalmente scartato dai grandi ERP.

### 5.3 Prova Fisica (Simulazione Logica)

Per eliminare ogni dubbio, è stato eseguito uno script PHP (nel server di sviluppo `scratch/test_quack_compliance.php`) che bypassa le interfacce grafiche per interrogare direttamente il motore.

1. **Test SKU:** Creato il prodotto "Articolo A" e inserito in `prodotti_restrizioni` (il modo in cui lavora SAP).
2. **Test Sostanza:** Creato il prodotto "Articolo B" e legato a una sostanza che si trova in `sostanze_chimiche_restrizioni` (il modo in cui è programmato Quack).

**Esito:**
Il file `ComplianceCheckerService::checkSale()` ha valutato il carrello. 
L'Articolo A è passato liscio, **ignorando completamente il blocco**.
L'Articolo B è stato **bloccato (Hard Block)** per assenza di licenza.

### 5.4 Il Frontend e la UX di Quack: Modulo Prodotti

Nel frontend, Quack gestisce l'inserimento delle restrizioni tramite la vista `app/views/products/_form.php`. 
Quando un operatore apre la scheda di un prodotto:

1. La UI dispone di un pannello/tab chiamato **"Compliance"** (con un badge di allarme rosso se ci sono anomalie `badge-compliance`).
2. Se il prodotto manca di dati, il sistema mostra un banner giallo (`compliance-suggest-banner`).
3. L'operatore può aggiungere manualmente un vincolo cliccando su "Aggiungi Restrizione" (`modal-compliance-add`). Questo alimenta la tabella `prodotti_restrizioni`.
   **Il paradosso (Gap Frontend/Backend):** L'operatore usa una UI eccellente per legare il permesso allo SKU. Ma come dimostrato dal test backend, tutto il lavoro fatto dall'operatore su questa schermata **è inutile**, perché il motore di cassa (checkout) non leggerà mai queste impostazioni.

### 5.5 Il Frontend e la UX di Quack: Anagrafica Clienti e Permessi

Nella scheda Cliente (`app/views/customers/_form.php`), Quack ha una sezione dedicata (Tab "Compliance").
Qui, il sistema esegue un lavoro eccellente, pienamente allineato agli standard di mercato (es. Oracle GTM Party License):

1. L'interfaccia interroga tramite API (`QkCustomerCompliance.apiGet`) le licenze possedute dal cliente (`compliance_autorizzazioni`).
2. L'operatore può caricare fisicamente i documenti cartacei scansionati o in PDF (`apiUpload`) che vengono salvati nella tabella `compliance_documenti_caricati`.
3. Il file `CustomersController.php` (righe 850-910) implementa un flusso di **Doppia Verifica (Separation of Duties)**:
   - Impone che ogni documento caricato sia prima "Verificato" da un operatore (`apiVerify`).
   - Solo dopo che i documenti obbligatori sono stati verificati, la licenza può essere commutata in stato "approvata" (`apiApprove`), scrivendo chi l'ha approvata (`approved_by`) e calcolandone la scadenza (`expiry_date`).
     Questo flusso è robusto e protegge legalmente l'azienda, a patto che il blocco a monte funzioni.

### 5.6 Il Frontend e la UX di Quack: Il Carrello / Creazione Ordine

Il momento in cui si decide il blocco commerciale avviene durante la creazione di un'offerta/ordine (`app/Controllers/SalesQuotesController.php`).
Il controller di vendita invoca la funzione helper `qk_compliance_check_sale()`.
**Logica UI/UX dell'Hard Block in Quack:**

- **Se l'ordine è in stato "Bozza":** Quack non impedisce il salvataggio. Genera solo un *Flash Warning* giallo in cima alla pagina: *"Attenzione! Rilevate violazioni di compliance: La vendita richiede Partita IVA..."*. Questo permette ai commerciali di lavorare preventivi provvisori senza essere bloccati.
- **Se l'ordine viene salvato come "Confermato":** Il sistema esegue il vero "Hard Block". Se la funzione restituisce una violazione, l'array `$errors['_global']` viene popolato, e il salvataggio nel database viene abortito (`rollback`). 
- **La UX:** In modo estremamente intelligente, l'errore mostrato a video contiene un link HTML cliccabile rosso scuro (`<a href='.../customers/edit?id=...'>Gestisci autorizzazioni cliente -></a>`). Questo reindirizza immediatamente il commerciale alla scheda del cliente per caricare i documenti mancanti, azzerando la frustrazione.

### 5.7 Audit Log (Il Registro delle Transazioni)

Quack possiede anche la tabella `compliance_log_transazioni`. Quando l'ordine viene convertito in uno stato effettivo (es. tramite la funzione `qk_compliance_log_transactions`), Quack scrive una riga indelebile nel registro.
Questa riga "congela" l'evento: id del cliente, id dell'autorizzazione usata, quantità acquistata e data. Questo è esattamente il requisito legale richiesto dal Regolamento UE (tracciabilità assoluta per le ispezioni), un dettaglio che spesso manca nei gestionali non specializzati.

### 5.8 Conclusioni Finali dell'Analisi Quack (Il Gap Reale)

Da questa analisi riga per riga di tutto il perimetro (Database, Backend, Controller e Frontend/UX), emerge un quadro sorprendente:

- **UI/UX e Processo di Licenza:** Quack è formidabile. Il caricamento dei PDF, la doppia verifica dei documenti, l'audit log e il link di risoluzione rapida nel carrello sono funzionalità degne di un software Enterprise da centinaia di migliaia di euro.
- **Il Bug Critico:** Tutto questo ecosistema crolla per via di **una singola query SQL difettosa** nel file `ComplianceRepository.php`. Il motore backend, invece di interrogare lo SKU (Tabella `prodotti_restrizioni`) valorizzato dall'utente nella UI, insiste a guardare la tabella `sostanze_chimiche_restrizioni`. 
  Quack obbliga l'utente a configurare le restrizioni SKU nel frontend, per poi ignorarle totalmente nel backend, rendendo l'intero apparato di compliance inutilizzabile per i prodotti senza distinta base chimica.
