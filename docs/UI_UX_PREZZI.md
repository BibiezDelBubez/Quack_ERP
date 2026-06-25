# UX/UI dei Prezzi nella Scheda Prodotto

Questo documento definisce l'architettura visuale e le interazioni per la gestione dei prezzi all'interno dell'anagrafica del singolo prodotto, distinguendo nettamente la logica degli acquisti da quella delle vendite. NON STIAMO PARLANDO DELLE MACRO SEZIONI LISTINO VENDITA O LISTINO ACQUISTO.

**Il problema da risolvere (La Tensione Strutturale)**: Nessun ERP risolve questo problema in modo perfetto. Esiste una tensione tra tre esigenze opposte:
- *Immediatezza*: Per il 90% dei prodotti basta un prezzo base, senza attriti.
- *Scalabilità*: Serve gestire una matrice complessa di M listini × N scaglioni.
- *Pulizia Visiva*: Mostrare tutto subito è illeggibile.

*(Nota storica: Analizzando Oracle NetSuite, SAP B1, Microsoft Dynamics e Odoo, si nota come alcuni spingano tutto in sottomenu e altri offrano liste fisse).*
Per Quack specificamente, dove i prodotti chimici ADR hanno spesso pricing a scaglione per quantità fisiche (kg, L, cisterne), l'approccio scelto risolve il bilanciamento tramite il pattern della **Progressive Disclosure**: nascondere la complessità all'utente che inserisce un prodotto semplice, ma renderla immediatamente accessibile inline.

---

## 1. Prezzi d'Acquisto & Fornitori

**Il Principio**: Non esiste un "Listino d'Acquisto Globale" predefinito per il prodotto (in stile vendita). Negli acquisti, il prezzo è strettamente legato all'accordo commerciale con un *Fornitore Specifico*. Il record che lega Prodotto-Fornitore funge esso stesso da listino.

La vecchia versione usava "Card Impilate", inadatta per un ERP professionale. Il "Test Lab" ha introdotto il pattern dello Stato dell'Arte: **Master List + Drawer**. Il trucco UX vincente è nascondere la complessità nel cassetto, mantenendo la griglia pulita.

### Struttura UI (Acquisti)

1. **La Master List (Tabella)**:
   Mostra a colpo d'occhio: SKU Fornitore, Lead Time, e Tariffa Base (il prezzo per quantità 1).

2. **Il Drawer (Cassetto Laterale)**:
   Cliccando su "Modifica", si apre un cassetto che contiene i dettagli avanzati:
   - **Scaglioni Volume**: Una tabellina per definire prezzi diversi a seconda della quantità.
   - **Vincoli RRP**: Campi per registrare i prezzi minimi e massimi di rivendita consigliati (`RRP Min` e `RRP Max`). È assolutamente necessario registrarli all'interno del cassetto del singolo Fornitore. Non puoi spostarli nei Dati Generali.
   - **Selettore Listino Acquisti (Dropdown)**: Il legame tra la "Master List" dei fornitori e i "Listini di Acquisto" globali è gestito tramite un piccolo selettore dentro il Drawer per permettere di legare lo scaglione a un "Accordo/Listino Quadro" del fornitore (es. "Listino Inverno Astra"). Altrimenti, l'accordo rimane una regola di prezzo slegata.
   - **La Regola d'Oro (Sincronizzazione)**: L'Iniezione. Quando l'utente importa 5000 righe CSV in un Listino Globale d'Acquisto, il sistema backend aggiorna i record dentro i Cassetti. Il Risultato Visivo è un badge (es. `[Listino 2026 Astra]`). **Questa riga deve essere bloccata/non modificabile a mano**, per prevenire disallineamenti. Se l'utente vuole un prezzo speciale, deve usare "+ Aggiungi Scaglione" per creare una riga manuale (senza badge).

3. **Il Fornitore Preferito (Stella)**:
   L'utente può marcare un fornitore come "Preferito" (Stella). Questo fornitore diventa cruciale per il **Sottocosto Reale (Prezzo di Acquisto)**, che entra in gioco in fase di vendita e calcolo marginalità.

---

## 2. Prezzi di Vendita & Listini

**Il Principio**: Avere un "Prezzo base" sul prodotto (prezzo_vendita_base) che funge da Listino Pubblico/Predefinito è perfetto. Serve come àncora di salvataggio di default per i clienti senza listini specifici. Copre il 90% dei casi.

Si utilizza il pattern **Matrice Ibrida Lazy-Load**.
La matrice dei prezzi (visione orizzontale × verticale) permette una consultazione rapida incrociata.

### Struttura UI (Vendite)

1. **Prezzo Base nel Tab Principale**:
   Campo singolo `Prezzo Base €`. Sincronizzato con la riga "Listino Base" della matrice.

2. **Sezione "Prezzi di Vendita" (Collassabile)**:
   Chiusa di default. All'interno risiede la Matrice (Righe = Listini, Colonne = Scaglioni Quantità).
   - **Indicatore Visivo (Collapsed)**: Quando l'accordion è chiuso, sotto il Prezzo Base appare un sommario testuale cliccabile: `Prezzo Base: €100,00 · 3 listini configurati · Scaglioni: sì`.
   - **Regole Anti-Cluttering**:
     - Nessuna colonna quantità se tutti i listini non usano scaglioni.
     - Massimo 4 colonne quantità visualizzate di default.
     - I listini non assegnati a quel prodotto non compaiono (no righe vuote). Vanno aggiunti con "+ Aggiungi Riga" (che apre un selector di listini già configurati nel sistema).
     - La sezione si apre automaticamente al caricamento se ci sono 2+ listini o scaglioni.
   - **Allineamento Scaglioni**: La creazione di un nuovo scaglione genera automaticamente la colonna per tutti i listini.
   - **Matrice Ibrida**: Le celle vuote per un listino che non prevede quello scaglione devono limitarsi a **ereditare visivamente** il valore dello scaglione precedente (es. grigio chiaro, disabilitato simulato).
   - **Inline Editing**: Ogni cella è editabile inline con click singolo, salvando con blur o enter.

### Flusso Operativo Tipico
- **Caso 1 (Semplice)**: L'operatore compila solo il Prezzo Base e salva.
- **Caso 2 (Ingrosso)**: Apre "Prezzi di Vendita", "+ Aggiungi Riga" -> "Listino Ingrosso", inserisce prezzo e salva (30s).
- **Caso 3 (ADR a scaglioni)**: Vede la matrice. Edita le celle direttamente.

---

## 3. Gestione RRP e Sottocosto

La possibilità di editare i prezzi inline e vedere avvisi in tempo reale ("Prezzo sotto costo!") è una feature "killer" dell'ERP che migliora enormemente l'operatività rispetto a dover aprire schermate secondarie.

1. **L'Errore: Non usare il Fornitore Preferito per l'RRP**. 
   Il sistema DEVE leggere i vincoli RRP da **TUTTI** i fornitori. Se la Stella è su Astra, e l'utente viola l'RRP di Brenntag, il sistema non deve nasconderglielo. (La Stella si usa SOLO per il costo d'acquisto reale per i blocchi di sottocosto).

2. **Il Banner / Box Collassabile**: 
   Sopra la Matrice, ricorda tutti i vincoli: `ℹ️ Vincoli RRP Fornitori: Astra [10-15€], Brenntag [12-16€]`.

3. **Soft-Warnings (RRP)**:
   Inserendo un prezzo che viola un RRP (es. 11.00€), il sistema non deve bloccarsi o diventare rosso sangue. Mostra una icona ⚠️ gialla o sottolineatura tratteggiata arancione.
   - Il Tooltip di Spiegazione: L'utente passa il mouse sull'icona ⚠️ e legge: *"Attenzione: 11.00€ è sotto l'RRP minimo consigliato da Brenntag (12.00€)."*

4. **Hard-Warnings (Sottocosto)**:
   Se il prezzo scende sotto il vero Costo d'Acquisto (fornito dalla Stella), scatta errore bloccante. Se si compra in USD e si vende in EUR, la UI JS deve effettuare una conversione al volo.

---

## 4. Considerazioni Implementative (SQL & API reali)

Struttura dati per la matrice di vendita basata sul database reale (`listini_prezzi`):
```sql
CREATE TABLE IF NOT EXISTS listini_prezzi (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  listino_id INTEGER NOT NULL,
  prodotto_id INTEGER NOT NULL,
  quantita_minima DECIMAL(10,2) DEFAULT 1.00,
  prezzo DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (listino_id) REFERENCES listini(id),
  FOREIGN KEY (prodotto_id) REFERENCES prodotti(id)
);
```
* **Lazy Loading & Debounce**: L'accordion dei prezzi si relaziona con il backend tramite le rotte reali del controller (es. salvataggio ottimistico via `POST /products/fornitori/prezzi/save`). Le modifiche inline sparano chiamate in background con debounce per prevenire spam al server.

