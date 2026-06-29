# Architettura e Principi dei Listini (Vendite e Acquisti)

Questo documento illustra i principi di design e l'implementazione del sistema di Pricing dell'ERP Quack, chiarendo la differenza tra Viste Globali (Listini) e Viste Locali (Scheda Prodotto).

## 1. Principi Architetturali: Rigore Enterprise

In un sistema ERP scalabile, il prezzo non è mai un semplice attributo del prodotto (es. una colonna `prezzo_vendita` nella tabella `prodotti`). 
Il prezzo è un'**entità relazionale** che vive all'incrocio tra tre concetti:
1. **Il Prodotto** (Cosa sto vendendo/comprando?)
2. **Lo Scaglione/Unità** (A che quantità e in che formato?)
3. **Il Contesto o Listino** (A chi lo sto vendendo/comprando e quando?)

Per questo motivo, *non esistono prezzi orfani*. Ogni prezzo deve appartenere a un contenitore logico (Listino), che definisce le regole di ingaggio (es. validità temporale, valuta, cliente target).

---

## 2. Le Due Interfacce (Viste)

L'ERP fornisce due modi per interagire con gli stessi dati sottostanti, progettati per due ruoli aziendali diversi.

### A. Le "Vere Sezioni" (Viste Top-Down)
- **URL**: `/sales/pricelists` e `/purchases/pricelists`
- **Scopo**: Gestione massiva, analisi direzionale, creazione di campagne sconti.
- **Flusso Operativo**: Il Direttore Commerciale o il Responsabile Acquisti entra nel "Listino Inverno 2026", e da lì aggiunge e modifica i prezzi di centinaia di prodotti associati a quel listino.
- **Focus**: Il contenitore (Il Listino) al centro, i prodotti sono il dettaglio.

### B. Le "Sotto-Sezioni" (Viste Bottom-Up)
- **URL**: Scheda Prodotto (`/products/edit`) -> Tab "Prezzi di Vendita" / "Costi d'Acquisto"
- **Scopo**: Inserimento rapido "al volo", confronto diretto, operatività quotidiana.
- **Flusso Operativo**: Il Buyer o il Commerciale sta guardando la scheda dell'articolo "Vite M8". Vuole sapere subito a quanto la compriamo oggi e a quanto la vendiamo. Se deve aggiornare il costo perché ha appena chiamato il fornitore, lo fa direttamente da lì.
- **Focus**: Il Prodotto al centro, i listini e i fornitori sono il dettaglio.

---

## 3. Il Raccordo dei Dati

La magia del sistema sta nel fatto che **entrambe le interfacce scrivono e leggono esattamente dalle stesse tabelle**.

### Vendite (Sales)
- **Struttura Dati**: Le righe dei prezzi (`listini_prezzi`) hanno un vincolo forte (`listino_id NOT NULL`). 
- **Comportamento**: Quando un utente aggiunge un prezzo nella scheda prodotto, è obbligato a specificare a quale listino appartiene (o il sistema applica il Listino Base di default, identificato dal campo `is_default = 1` nella tabella `listini`).
- **Risultato**: Sincronizzazione perfetta. Aggiungere un prezzo nel tab prodotto aggiorna istantaneamente la visione del listino globale.

### Acquisti (Purchases)
- **Struttura Dati**: Il prezzo d'acquisto è legato al fornitore (`prodotti_fornitori_prezzi`). Può essere collegato a un listino formale (`listino_acquisto_id`), ma non è strettamente obbligatorio dal DB.
- **Comportamento Idealizzato**: Per mantenere l'integrità del *Pricing Engine*, se un utente inserisce un prezzo d'acquisto "al volo" senza specificare un contratto quadro (Listino), il sistema dovrebbe incanalare questo prezzo in un listino di default, generato in background (es. "Condizioni Base Fornitore X"). 
- **Risultato**: Quando il manager apre la sezione `/purchases/pricelists`, vedrà sempre tutti i prezzi negoziati, anche quelli presi al volo dall'operatore.

---

## 4. Storicizzazione e Motore di Pricing (Pricing Engine)

### La Validità Temporale
Un aspetto cruciale è la gestione delle date (`valido_dal`, `valido_al`).
Quando un prezzo cambia, **la best practice prevede di non cancellare/sovrascrivere il vecchio record**. 
Si "chiude" la validità del vecchio record (`valido_al = ieri`) e si crea un nuovo record (`valido_dal = oggi`). Questo permette di:
- Ricostruire l'andamento dei costi/ricavi (Marginalità Storica).
- Avere preventivi passati che puntano al prezzo corretto del tempo.

### Come sceglie il prezzo il sistema?
Quando si crea un ordine, il Pricing Engine valuta le regole in quest'ordine (dal più specifico al più generico):
1. C'è un listino promozionale temporaneo attivo oggi per questo cliente? -> *Usa quello*
2. Il cliente ha un listino specifico assegnato in anagrafica? -> *Usa quello*
3. Nessuna regola specifica trovata -> *Usa il Listino Base (quello popolato "al volo" nella scheda prodotto).*
