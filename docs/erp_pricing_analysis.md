# Analisi Completa UX/UI e Architettura: Prezzi d'Acquisto e Vendita (Anagrafica Prodotto)

Questo documento raccoglie in un'unica visione d'insieme **tutte** le valutazioni svolte sul flusso di gestione prezzi del Prodotto, confrontando le interfacce originali con quelle del Test Lab (`/products/edit-test`) e analizzando a fondo le logiche di backend rispetto agli standard degli ERP "Best in Class" (come Odoo, SAP, Dynamics).

---

## 1. Prezzi d'Acquisto & Fornitori

### 1.1 Confronto UX: Originale vs Test Lab
* **Versione Originale (Card Impilate)**: Utilizza grossi blocchi (card) per ogni fornitore. Risulta troppo dispersiva, genera un eccessivo scrolling verticale e aumenta il carico cognitivo (friction). Diventa ingestibile se un prodotto ha 5-10 fornitori. **Verdetto: Inadatta per un ERP professionale.**
* **Versione Test Lab (Master List / Tabella)**: Utilizza una griglia compatta. Offre una panoramica immediata dei dati essenziali (Fornitore, SKU, Lead Time, Tariffa Base). È lo standard di settore per gli ERP. Mantiene pulita la schermata nascondendo gli scaglioni all'interno di un "cassetto" o di un modale, permettendo il drill-down solo quando serve. **Verdetto: Soluzione eccellente, da adottare definitivamente.**

### 1.2 UX per Fornitori e Condizioni Multiple
* **Il Problema di UX**: Dal punto di vista dell'utente, permettere l'inserimento dello stesso fornitore più volte rischia di creare una Master List visivamente caotica (es. tre righe identiche chiamate "Astra Meccanica").
* **Come gestiscono la UX i grandi ERP (Odoo)**: In Odoo, l'elenco fornitori sul prodotto non è concepito come una semplice rubrica contatti, ma come una lista di **"Regole di Listino"**. Se "Astra" compare tre volte, è perché l'utente visivamente distingue subito *perché* c'è una ripetizione. 
* **Soluzione UX allo stato dell'arte**: La tua interfaccia Master List deve giustificare visivamente la presenza multipla dello stesso fornitore. Se un utente aggiunge "Astra" una seconda volta, la tabella deve mostrare chiaramente la discriminante, ad esempio:
  - Lo SKU Fornitore (una riga è `SKU-A`, l'altra è `SKU-B`).
  - Il Lead Time (spedizione standard vs express).
  Se le righe sono visivamente identiche nella Master List, la UX fallisce. L'interfaccia non dovrebbe bloccare tecnicamente l'utente, ma dovrebbe aiutarlo visivamente raggruppando o evidenziando le differenze chiave direttamente nella griglia, evitando che debba aprire il cassetto di ogni riga solo per capire quale "Astra" sta guardando.
* **Il Problema**: La Master List mostra una colonna "Tariffa Base", ma il fornitore può avere uno storico di prezzi o scaglioni per quantità.
* **Soluzione**: La logica di Backend/JS per popolare quella colonna deve filtrare esplicitamente per `quantita_minima = 0` (o il lotto minimo di base) e assicurarsi che la data odierna rientri nel range `valido_dal` - `valido_al`. Se non ci sono prezzi correntemente attivi, la UX deve mostrare "Nessun prezzo attivo", invitando l'utente ad aggiornare il cassetto.

### 1.4 Il Listino Acquisti
A differenza delle vendite, **è corretto non avere un "Listino Acquisti Predefinito" globale**. Negli acquisti, il prezzo non esiste in astratto: è sempre strettamente legato all'accordo commerciale con uno specifico fornitore (la Vendor Pricelist).

---

## 2. Prezzi di Vendita & Listini

### 2.1 Confronto UX: Originale vs Test Lab
* **Versione Originale**: Troppo rudimentale e ad alto attrito per il data entry.
* **Versione Test Lab (Matrice dei Prezzi)**: L'approccio a Matrice (righe = Listini, colonne = Scaglioni) è un enorme passo avanti. Odoo nativamente si limita a liste piatte molto tediose da consultare. Una matrice offre una visione sia orizzontale (variazioni di volume) che verticale (variazioni tra target B2B/B2C).
* **Funzionalità "Killer"**: L'editing inline diretto nelle celle della matrice è lo stato dell'arte per l'operatività quotidiana.

### 2.2 Il Listino Base di Vendita
Avere un "Prezzo di vendita base" (Listino 1 / Pubblico) salvato direttamente sul prodotto è perfettamente in linea con i grandi ERP (es. il campo `list_price` di Odoo). È la fallback universale: se il cliente non ha listini speciali, si applica questo.

### 2.3 Gestione Scaglioni nella Matrice (Il Disallineamento)
* **Il Problema**: Se il Listino A prevede scaglioni a `[10, 50]` e il Listino B a `[12, 100]`, generare una matrice unica creerà le colonne `[10, 12, 50, 100]`. Per il Listino A, le celle 12 e 100 non esistono nativamente, generando "buchi" visivi.
* **Soluzione UX (Matrice Ibrida)**: Per non forzare tutti i listini ad avere gli stessi scaglioni, la matrice deve gestire "l'Ereditarietà Visiva". Se la cella per la colonna "Qtà 12" nel Listino A è vuota a livello di database, la UX nel frontend non deve mostrare un input vuoto, ma deve riproporre (in grigio sbiadito o corsivo) il prezzo dello scaglione precedente (quello della Qtà 10). Questo rende la lettura fluida. Se l'utente clicca sulla cella sbiadita per modificarla, crea l'override e genera il record per la Qtà 12.

---

## 3. Dinamiche di Incrocio: RRP, Costi e Sottocosto

### 3.1 Costo di Riferimento e "Fornitore Preferito" (Stella)
* **Il Problema**: Per validare il prezzo di vendita e mostrare il warning "Sottocosto", il sistema deve sapere quanto ci costa il prodotto. Avendo fornitori multipli, quale usiamo?
* **Soluzione**: L'approccio attuale con la **Stella (Fornitore Preferito)** è eccellente e deve fungere da arbitro. Il costo base del fornitore con la Stella diventa il "Costo di Riferimento" per l'intera anagrafica prodotto. I warning di sottocosto nella matrice vendite si calcoleranno matematicamente rispetto a questo valore. (Assicurandosi sempre di normalizzare le valute se si compra in USD e si vende in EUR).

### 3.2 Gestione RRP (Prezzi Consigliati Multipli)
* **Dove registrarli**: Poiché nella tua realtà commerciale ogni fornitore può suggerire un diverso prezzo minimo (RRP Min) e massimo (RRP Max) per lo stesso identico articolo, **i dati RRP devono rimanere sulla riga del fornitore** (tabella `prodotti_fornitori`). Spostarli a livello di "Prodotto Base" distruggerebbe l'informazione se Astra e Brenntag impongono politiche diverse.
* **Come visualizzarli nella Matrice di Vendita**: 
  A differenza del "Costo" puro (dove la Stella elegge l'unico vincitore matematico), l'RRP è un vincolo commerciale che l'utente ha bisogno di conoscere nella sua interezza. Nascondere l'RRP di Brenntag solo perché Astra ha la Stella è un errore di UX.
  **La soluzione UX Best-in-Class prevede "Trasparenza Contestuale":**
  1. **Il Box Riassuntivo**: Sopra la matrice di vendita, un banner o un box collassabile (come abbozzato nel Test Lab) elenca in modo chiaro tutti gli RRP forniti dai vari distributori.
  2. **Soft Warning sulle Celle**: Se un utente inserisce un prezzo di vendita di `9.50€`, e Astra impone un RRP minimo di `10.00€`, la cella non deve "rompersi" bloccando il salvataggio. Deve mostrare un avviso leggero (es. iconina `ℹ️` gialla o bordino tratteggiato). 
  3. **Tooltip Esplicito**: Passando con il mouse sull'icona di warning della cella, un tooltip deve spiegare esattamente chi stiamo violando: *"Attenzione: il prezzo viola il RRP Minimo di Astra Meccanica (10.00€)"*.
  In questo modo l'utente mantiene il controllo totale: vede subito il rischio "sottocosto reale" (tramite il confronto con il fornitore Stellato), e parallelamente viene avvisato se sta violando i patti commerciali con uno o più fornitori della Master List.
