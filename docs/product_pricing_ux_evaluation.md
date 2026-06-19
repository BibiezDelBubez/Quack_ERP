# Valutazione UX/UI: Prezzi d'Acquisto e Prezzi di Vendita (Anagrafica Prodotto)

Analisi delle sottosezioni relative ai prezzi all'interno della scheda Prodotto, confrontando la versione originale con la versione "Test Lab" (`/products/edit-test`), alla luce delle best practice degli ERP moderni (come SAP, Odoo, Business Central).

---

## 1. Prezzi d'Acquisto & Fornitori

### Versione Originale (Card Impilate)
* **Analisi**: L'interfaccia a blocchi (card) per ogni fornitore risulta dispersiva. Occupa molto spazio verticale e aumenta il carico cognitivo (friction). Se un prodotto ha 5-10 fornitori, diventa difficile confrontarli a colpo d'occhio.
* **Verdetto**: Inadatta per un ERP professionale.

### Versione Test Lab (Master List / Tabella)
* **Analisi**: L'approccio a griglia (tabella) è lo standard di settore per gli ERP. Offre una panoramica immediata su chi fornisce il prodotto, qual è lo SKU del fornitore, il Lead Time e la "Tariffa Base" (il prezzo per quantità 0+ o 1+).
* **Gestione Scaglioni**: Avere un pulsante "Modifica Prezzi" che apre un modale o espande la riga per la gestione degli scaglioni è la scelta ottimale. Mantiene pulita la "Master List" mostrando solo l'essenziale, permettendo il drill-down solo quando serve.
* **Il concetto di "Listino Acquisti"**: È **corretto** non avere un "Listino Acquisti Predefinito" globale in stile vendita. Negli acquisti, il prezzo è strettamente legato all'accordo commerciale con un *Fornitore Specifico*. Il record che lega Prodotto-Fornitore funge esso stesso da listino.

---

## 2. Prezzi di Vendita & Listini

### Versione Test Lab (Matrice dei Prezzi)
* **Analisi**: L'approccio a **Matrice** (righe = Listini, colonne = Scaglioni di quantità) è lo stato dell'arte. Permette all'utente di:
  1. Avere una visione orizzontale (come variano i prezzi in base alle quantità per un listino).
  2. Avere una visione verticale (come varia il prezzo base tra il Listino Pubblico e il Listino B2B).
* **Inline Editing e Sottocosto**: La possibilità di editare i prezzi inline e vedere avvisi in tempo reale ("Prezzo sotto costo!") è una feature "killer" che migliora enormemente l'operatività rispetto a dover aprire schermate secondarie.
* **Listino di Vendita Predefinito**: Avere un listino "Base" o "Principale" è un'ottima analogia con i grandi ERP (es. "Public Pricelist" di Odoo). Funge da ancora di salvataggio: se non c'è un listino specifico per il cliente, si applica quello.

---

## 3. Gestione RRP (Recommended Retail Price)

I fornitori possono imporre un prezzo minimo (RRP Min) e massimo (RRP Max) di rivendita.

### Dove e come registrarli (Backend / Input)
I vincoli RRP sono dettati dal fornitore. Pertanto, **devono risiedere nella tabella di associazione Prodotto-Fornitore**.
Nella tab *Prezzi d'Acquisto & Fornitori*, quando si clicca su "Modifica" o si apre il dettaglio del fornitore, dovrebbero esserci due campi opzionali:
* `Prezzo Min. Rivendita (RRP Min)`
* `Prezzo Max. Rivendita (RRP Max)`

### Dove e come visualizzarli (UX / Output)
Come hai genialmente abbozzato nel Test Lab, i vincoli di vendita devono riflettersi nella tab *Prezzi di Vendita & Listini*.
Ma da quale fornitore prendiamo l'RRP se ne abbiamo 5? **Dal Fornitore Preferito (Stella).**

**Workflow UX proposto:**
1. L'utente imposta "Astra Meccanica" come Fornitore Preferito (stella attiva) e definisce un RRP Min di 10.00€ e Max di 15.00€.
2. Spostandosi nella tab *Prezzi di Vendita & Listini*, il banner azzurro in alto riporta:
   > ℹ️ **Vincoli RRP (Astra Meccanica)**: Min 10.00 € — Max 15.00 €
3. **Validazione Visuale**: Nella griglia matrice, se l'utente digita un prezzo di vendita di `9.50 €`, la cella si colora di arancione o mostra un'icona di avviso (es. ⚠️ *Sotto RRP Minimo*). Simile a come fai per il "Sottocosto", l'RRP funge da guida visiva. In un ERP, solitamente l'RRP è un "Soft Warning" (ti avviso, ma ti lascio salvare se vuoi forzare la mano).

---

## Conclusioni e Proposte

1. **Abbandona le Card per gli Acquisti**: La "Master List" a tabella del Test Lab è vincente. Adottala come versione definitiva.
2. **Promuovi la Matrice per le Vendite**: La vista a matrice con gli scaglioni come colonne dinamicamente aggiungibili è eccellente e altamente professionale.
3. **Consolida il concetto di Fornitore Preferito**: La stella (Preferito) deve essere il "ponte" tra la sezione Acquisti e la sezione Vendite. È dal fornitore preferito che il sistema deve pescare:
   - Il **Costo** (per calcolare il ricarico e mostrare il warning *Sottocosto* nella matrice vendite).
   - I **Vincoli RRP** (per mostrare gli avvertimenti sulla marginalità e sui prezzi imposti).
4. **Allineamento Scaglioni**: Considera se nella matrice vendite la creazione di un nuovo scaglione (es. colonna `[Qtà 10+]`) debba generare automaticamente la colonna per tutti i listini, oppure se ogni listino ha scaglioni a sé. Negli ERP, di solito, la vista a matrice forza gli scaglioni ad essere allineati per tutti i listini visualizzati in quella tabella, semplificando la lettura. Se un listino non usa quello scaglione, si ripropone semplicemente il prezzo dello scaglione precedente.
