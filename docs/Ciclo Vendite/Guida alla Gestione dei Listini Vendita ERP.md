# Guida Tecnica e Funzionale: Gestione Listini Vendita in un ERP Best-in-Class

## 1. Come si comportano i Listini nei Preventivi? (La Logica Funzionale)

Nei migliori ERP, il listino **non è un blocco rigido**, ma una **guida intelligente**. Ecco come si comporta quando crei un preventivo o un ordine di vendita:

### Suggerimento vs Obbligo

- **Comportamento Standard (Suggerimento):** Quando selezioni un cliente nel preventivo, l'ERP carica il suo listino associato. Quando aggiungi una riga prodotto, il sistema calcola il prezzo in base al listino e lo inserisce automaticamente nel campo `Prezzo Unitario`.

- **Flessibilità di Modifica:** L'operatore commerciale può quasi sempre sovrascrivere manualmente il prezzo suggerito (es. per fare una trattativa spot).

- **Controllo (Permissions & Approvals):** Se l'operatore abbassa il prezzo sotto una determinata soglia stabilita dal listino (o sotto il costo del prodotto), l'ERP non blocca l'inserimento, ma:
  
  1. Evidenzia il campo. Con simboli o con colore rosso/arancione.

### Tracciabilità del Prezzo Originale

Un ERP best-in-class memorizza sempre tre informazioni sulla riga del preventivo:

1. `Prezzo di Listino Originale` (es. 10.00 €)

2. `Sconto Applicato` (es. 10%)

3. `Prezzo Finale Venduto` (es. 9.00 €)

Questo serve per la reportistica: devi sapere se i tuoi venditori stanno regalando margine o se stanno rispettando i listini.

## 2. Architettura dei Dati: Listini Generali vs Listini Dedicati

> **La regola d'oro dei Best-in-Class:** Listini generali (per tutti) e listini dedicati (per un solo cliente) **NON sono oggetti diversi a livello di database**. Sono lo stesso identico oggetto, ma con condizioni di applicabilità diverse.

Se crei tabelle separate per "listini generici" e "listini dedicati", il tuo backend diventerà un incubo di `IF/ELSE`.

### Il Modello a "Regole di Pricing" e il "Listino Base" (Best Practice)

Un listino è semplicemente un **contenitore di regole**.

In un sistema enterprise, **l'anagrafica del prodotto non contiene il prezzo di vendita base**. Memorizzare il prezzo di vendita nella tabella prodotti è un errore bloccante per la gestione multivaluta, la storicità e il multi-società.

Invece, esiste un **Listino Standard / Pubblico** (es. ID 1) che contiene il prezzo base "di listino puro" per tutti gli articoli. Tutti gli altri listini speciali (dedicati ai clienti o ai gruppi) lavorano in percentuale di sconto o maggiorazione rispetto a questo Listino Standard, oppure definiscono prezzi fissi sostitutivi.

Le regole vengono valutate in base a una priorità. L'ERP cerca la regola più specifica applicabile a quella vendita. Se non la trova, sale di livello fino al listino generale.

L'assegnazione funziona così:

1. **Listino Standard / Pubblico (Default):** Contiene i prezzi base ufficiali dell'azienda. Si applica a tutti i clienti che non hanno un listino specifico.

2. **Listino di Gruppo (es. "Grossisti", "Distributori"):** Associato a una categoria di clienti.

3. **Listino Specifico (es. "Listino Speciale Cliente ACME"):** Associato a un singolo cliente.

## 3. Schema del Database (SQL)

Ecco un design di database relazionale pulito, scalabile e ottimizzato che disaccoppia i prodotti dai loro prezzi di vendita.

```
-- 1. Tabella Prodotti (SENZA prezzo_base, contiene solo costi e metadati)
CREATE TABLE prodotti (
    id SERIAL PRIMARY KEY,
    codice VARCHAR(50) UNIQUE NOT NULL,
    nome VARCHAR(100) NOT NULL,
    categoria_id INT, -- FK a categorie_prodotti
    costo_medio_ponderato DECIMAL(12,4) DEFAULT 0.0000,
    ultimo_costo_acquisto DECIMAL(12,4) DEFAULT 0.0000,
    attivo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabella dei Listini (La testata del listino)
CREATE TABLE listini (
    id SERIAL PRIMARY KEY,
    nome VARCHAR(100) NOT NULL, -- es. "Listino Base Standard", "Listino Grossisti 2026", "Listino ACME S.p.A."
    valuta VARCHAR(3) DEFAULT 'EUR', -- Gestione multivaluta nativa
    attivo BOOLEAN DEFAULT TRUE,
    data_inizio DATE,
    data_fine DATE,
    is_standard BOOLEAN DEFAULT FALSE, -- Identifica il listino base ufficiale (ID 1)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Tabella di associazione Cliente -> Listino
CREATE TABLE clienti_listini (
    id SERIAL PRIMARY KEY,
    cliente_id INT NOT NULL, -- FK a clienti_fornitori
    listino_id INT NOT NULL, -- FK a listini
    priorita INT DEFAULT 1,  -- Più è alto, più comanda (es. listino promo temporaneo batte quello fisso)
    FOREIGN KEY (listino_id) REFERENCES listini(id)
);

-- 4. Le Regole del Listino (Il motore dei prezzi)
CREATE TABLE listini_regole (
    id SERIAL PRIMARY KEY,
    listino_id INT NOT NULL REFERENCES listini(id) ON DELETE CASCADE,

    -- Ambito di applicazione (Almeno uno deve essere valorizzato, l'ERP valuta dal più specifico al più generico)
    prodotto_id INT REFERENCES prodotti(id) ON DELETE CASCADE, 
    categoria_prodotto_id INT, -- Per applicare sconti/maggiorazioni a intere famiglie chimiche

    -- Condizioni
    quantita_minima DECIMAL(12,4) DEFAULT 0.0000, -- Per scaglioni di quantità (es. prezzo per fusto vs prezzo per cisterna)
    data_inizio DATE,
    data_fine DATE,

    -- Come viene calcolato il prezzo?
    -- Tipi di calcolo: 
    -- 'fixed' (Prezzo fisso, es. 12.50 €/kg. Obbligatorio nel "Listino Standard" per definire il prezzo base)
    -- 'discount' (Sconto % da applicare al prezzo trovato nel "Listino Standard")
    -- 'formula' (Costo del prodotto + % ricarico - utilissimo nel chimico per volatilità materie prime)
    tipo_calcolo VARCHAR(30) NOT NULL CHECK (tipo_calcolo IN ('fixed', 'discount', 'formula')),

    valore DECIMAL(12,4) NOT NULL, -- Può essere il prezzo fisso (es. 15.50), la % di sconto (es. 10.00 per 10%), o la % di ricarico sul costo (es. 20.00 per +20%)

    -- Se tipo_calcolo è 'formula', specifichiamo la base di partenza
    formula_base VARCHAR(30) CHECK (formula_base IN ('costo_medio_ponderato', 'ultimo_costo_acquisto')), 

    priorita INT DEFAULT 10, -- Usato per risolvere i conflitti (es. regola prodotto singolo batte regola categoria)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 4. Backend & Algoritmo di Calcolo (Il "Pricing Engine")

Il backend deve esporre un servizio (o API) chiamato `PricingEngine`. Tutto il calcolo del prezzo deve passare da qui.

### L'Algoritmo di calcolo (Pseudocode / Logic)

Quando l'utente seleziona un prodotto o cambia la quantità in un preventivo, il frontend chiama il backend passando `cliente_id`, `prodotto_id`, `quantita`, `data_documento`.

```
def calcola_prezzo_vendita(cliente_id, prodotto_id, quantita, data_documento):
    # ID del listino standard globale contenente i prezzi base di vendita
    ID_LISTINO_STANDARD = 1 

    # 1. Recupera i dati del prodotto (costi e categoria)
    prodotto = db.query("SELECT costo_medio_ponderato, ultimo_costo_acquisto, categoria_id FROM prodotti WHERE id = ?", prodotto_id)
    if not prodotto:
        raise Exception("Prodotto non trovato")

    # 2. Trova il listino attivo personalizzato per questo cliente
    listino_cliente_id = db.query("""
        SELECT listino_id FROM clienti_listini 
        JOIN listini ON listini.id = clienti_listini.listino_id
        WHERE cliente_id = ? AND listini.attivo = TRUE 
        AND (listini.data_inizio IS NULL OR listini.data_inizio <= ?)
        AND (listini.data_fine IS NULL OR listini.data_fine >= ?)
        ORDER BY priorita DESC LIMIT 1
    """, cliente_id, data_documento, data_documento)

    ha_listino_dedicato = True
    if not listino_cliente_id:
        listino_cliente_id = ID_LISTINO_STANDARD
        ha_listino_dedicato = False

    # 3. Cerca la regola più specifica nel listino assegnato al cliente
    regola = cerca_regola_in_listino(listino_cliente_id, prodotto_id, prodotto.categoria_id, quantita, data_documento)

    # 4. CASO CRITICO: Il prodotto non ha una regola nel listino dedicato del cliente!
    if not regola and ha_listino_dedicato:
        # Fallback 1: Cerca nel Listino Standard/Pubblico di base
        regola_generale = cerca_regola_in_listino(ID_LISTINO_STANDARD, prodotto_id, prodotto.categoria_id, quantita, data_documento)
        if regola_generale:
            prezzo = esegui_calcolo_regola(regola_generale, prodotto, ID_LISTINO_STANDARD)
            return {
                "prezzo": prezzo,
                "origine": "listino_generale",
                "dettaglio": "Prodotto assente nel listino dedicato. Applicata regola da Listino Standard."
            }
        else:
            # Fallback 2: Nessuna regola definita neanche nel listino standard (Manca il prezzo base!)
            # Applichiamo una regola di emergenza: Costo Medio Ponderato + 20% di margine minimo
            prezzo_emergenza = prodotto.costo_medio_ponderato * 1.20
            return {
                "prezzo": prezzo_emergenza,
                "origine": "calcolo_emergenza_costo",
                "dettaglio": "Nessun prezzo di listino trovato. Applicato ricarico di emergenza su costo (+20%)."
            }

    # 5. Se invece abbiamo trovato la regola nel listino principale
    if not regola:
        # Nessuna regola trovata nel listino standard/generico
        prezzo_emergenza = prodotto.costo_medio_ponderato * 1.20
        return {
            "prezzo": prezzo_emergenza,
            "origine": "calcolo_emergenza_costo",
            "dettaglio": "Nessuna regola applicabile nel listino standard. Applicato ricarico di emergenza."
        }

    prezzo_calcolato = esegui_calcolo_regola(regola, prodotto, ID_LISTINO_STANDARD)
    origine_prezzo = "listino_dedicato" if ha_listino_dedicato else "listino_generale"

    return {
        "prezzo": prezzo_calcolato,
        "origine": origine_prezzo,
        "dettaglio": f"Applicata regola ID {regola.id} del listino {listino_cliente_id}."
    }

def cerca_regola_in_listino(listino_id, prodotto_id, categoria_id, quantita, data_documento):
    return db.query("""
        SELECT * FROM listini_regole
        WHERE listino_id = ?
          AND (prodotto_id = ? OR prodotto_id IS NULL)
          AND (categoria_prodotto_id = ? OR categoria_prodotto_id IS NULL)
          AND quantita_minima <= ?
          AND (data_inizio IS NULL OR data_inizio <= ?)
          AND (data_fine IS NULL OR data_fine >= ?)
        ORDER BY 
          (prodotto_id IS NOT NULL) DESC,          -- Priorità 1: Prodotto specifico
          (categoria_prodotto_id IS NOT NULL) DESC, -- Priorità 2: Categoria specifica
          quantita_minima DESC,                    -- Priorità 3: Scaglione di quantità più alto applicabile
          priorita DESC                            -- Priorità 4: Priorità manuale impostata a DB
        LIMIT 1
    """, listino_id, prodotto_id, categoria_id, quantita, data_documento)

def esegui_calcolo_regola(regola, prodotto, id_listino_standard):
    if regola.tipo_calcolo == 'fixed':
        return regola.valore

    elif regola.tipo_calcolo == 'discount':
        # Per calcolare lo sconto percentuale, dobbiamo prima recuperare il prezzo base "fixed" dal Listino Standard
        regola_base = db.query("""
            SELECT valore FROM listini_regole 
            WHERE listino_id = ? AND prodotto_id = ? AND tipo_calcolo = 'fixed' 
            LIMIT 1
        """, id_listino_standard, prodotto.id)

        prezzo_base = regola_base.valore if regola_base else (prodotto.costo_medio_ponderato * 1.20)
        sconto = prezzo_base * (regola.valore / 100)
        return prezzo_base - sconto

    elif regola.tipo_calcolo == 'formula':
        base_costo = prodotto.costo_medio_ponderato if regola.formula_base == 'costo_medio_ponderato' else prodotto.ultimo_costo_acquisto
        return base_costo * (1 + (regola.valore / 100))
```

## 5. Frontend & UX (Interfaccia Utente)

Per rendere l'ERP piacevole e intuitivo, l'interfaccia deve comunicare chiaramente cosa sta succedendo dietro le quinte.

### Nella scheda Cliente

- Deve esserci un campo a discesa chiamato **"Listino Vendite di Default"**.

- Di fianco, un pulsante rapido "Vedi regole listino" per vedere cosa c'è dentro senza dover navigare altrove.

### Nella scheda Listini (Pannello Amministrativo)

Non separare le schermate. Crea un'unica interfaccia di gestione listini dove l'utente può:

1. Creare un listino (es. "Listino Clienti VIP").

2. Associare i clienti a quel listino direttamente da una lista multi-selezione o drag-and-drop nella stessa schermata.

3. Definire le regole tramite una tabella editabile (un "DataGrid") dove ogni riga è una regola (Prodotto / Categoria | Quantità Minima | Tipo (Fisso/%/Formula) | Valore).

### Nella schermata del Preventivo (UX Dinamica)

Quando inserisci le righe d'ordine:

- Se modifichi la quantità da 1 a 1000, l'interfaccia deve fare una chiamata asincrona (debounced) al `PricingEngine` e aggiornare istantaneamente il prezzo unitario se scatta uno scaglione.

- Mostra visivamente l'origine del prezzo. Ad esempio, un'icona a forma di tag di fianco al prezzo:
  
  - Verde se il prezzo arriva da un listino dedicato al cliente.
  
  - Grigio se arriva dal listino generale / standard.
  
  - Giallo/Arancione se è stato sovrascritto manualmente dal commerciale.
  
  - Se passi col mouse (Tooltip) sull'icona, mostra: *"Prezzo calcolato da: Listino speciale ACME - Sconto del 10% sul prezzo base del Listino Standard"*.

## 6. Il Caso Speciale del Settore Chimico (Cruciale per il tuo ERP!)

Visto che stai sviluppando un ERP per **prodotti chimici**, i listini standard degli ERP generici non ti basteranno. Devi implementare queste tre logiche fin da subito per essere "best-in-class":

### A. Gestione Doppia Unità di Misura (UoM)

I prodotti chimici si acquistano e vendono spesso con unità di misura diverse (es. compri in Kg, vendi in fusti da 25 litri o in cisterna).

- Il listino deve supportare la conversione automatica.

- Esempio: Il listino base definisce $1.50\text{ €/Kg}$. Se il commerciale seleziona nel preventivo "Fusto da 200 Litri" (e la densità del prodotto rende un fusto pari a $180\text{ Kg}$), il sistema deve saper calcolare:
  
  $$\text{Prezzo Fusto} = 1.80\text{ €/Kg} \times 180\text{ Kg} = 324.00\text{ €}$$

### B. Aggiornamento Massivo per Volatilità (Costo Chimico + Margine)

I prezzi del settore chimico fluttuano violentemente in base al costo del petrolio e delle materie prime. I tuoi clienti non aggiorneranno mai i listini fissi riga per riga a mano.

- Implementa la formula **"Costo + Margine"** come mostrato nello schema DB.

- Esempio: "Il prezzo del solvente è pari al *Costo Medio Ponderato di questa settimana* + $15\%$". In questo modo, il listino si auto-aggiorna in base alle oscillazioni d'acquisto senza che l'utente debba ricalcolare nulla a mano.

## 7. Cosa succede se un Prodotto NON è nel Listino del Cliente? (La "Cascata di Fallback")

Questo è uno scenario quotidiano: crei un preventivo per il Cliente ACME (che ha il suo listino personalizzato), ma inserisci un prodotto nuovo o non abituale che non ha una regola specifica in quel listino.

Un ERP allo stato dell'arte non restituisce mai un errore bloccante e non lascia il prezzo a zero. Applica invece una **Cascata di Fallback** (Ereditarietà) strutturata in 4 livelli.

```
[Listino Dedicato Cliente] ──(No)──> [Listino Standard / Pubblico] ──(No)──> [Ricarico di Emergenza su Costo] ──(No)──> [Blocco / Richiesta Prezzo]
```

### I 4 Livelli di Fallback

1. **Livello 1: Listino Dedicato al Cliente**
   
   Il sistema cerca nel listino associato al cliente una regola specifica per il Prodotto (o per la Categoria di quel prodotto) valida per la quantità inserita.

2. **Livello 2: Listino Standard / Pubblico (Primo Fallback)**
   
   Se il punto 1 fallisce, il sistema scala sul **Listino Standard** (ID 1). Cerca se esiste una regola di prezzo fissa (`fixed`) per quel prodotto specifico. Trattandosi del listino ufficiale di base, tutti i prodotti attivi dovrebbero teoricamente avere un prezzo definito qui.

3. **Livello 3: Ricarico di Emergenza su Costo (Secondo Fallback)**
   
   Se il prodotto non è stato configurato nemmeno nel Listino Standard per dimenticanza dell'ufficio acquisti/vendite, l'ERP estrae il `Costo Medio Ponderato` (o l'ultimo costo d'acquisto) dalla tabella prodotti e applica una percentuale di margine minimo di sicurezza aziendale pre-configurata (es. `Costo + 20%`).

4. **Livello 4: Blocco / Richiesta Prezzo (Ultima Spiaggia)**
   
   Se manca anche il dato del costo, il prezzo viene impostato a `0.00`, la riga viene evidenziata in rosso bloccando la conferma del preventivo e viene richiesta la valorizzazione manuale da parte di un utente con privilegi di amministratore commerciale.

### UX & Feedback Visivo: Come presentarlo al commerciale?

Se un prodotto non è nel listino dedicato, l'operatore deve saperlo istantaneamente per evitare di fare preventivi con margini sbagliati o prezzi standard a clienti che si aspettano trattamenti di favore.

- **Icona di Warning Soft:** Vicino al prezzo unitario della riga del preventivo, l'ERP mostra un badge giallo o grigio (es. un punto esclamativo o un'icona "link interrotto").

- **Tooltip Esplicativo:** Passando il mouse sull'icona, il sistema mostra:
  
  > *"Attenzione: Questo prodotto non fa parte del 'Listino Personalizzato ACME'. È stato applicato il prezzo di fallback dal 'Listino Standard' (€15.00/Kg)."*

- **Il tasto magico: "Aggiungi al listino del cliente" (Inline Upsert)**
  
  Se l'utente ha i permessi commerciali appropriati, l'ERP mostra un pulsante rapido sulla riga del preventivo: **"Salva questo prezzo nel listino di ACME"**.
  
  Cliccandolo, il commerciale può digitare un prezzo concordato al volo (es. €14.00) e il sistema, in background, crea automaticamente una nuova riga nella tabella `listini_regole` legata al listino di quel cliente. Al prossimo preventivo, il prodotto sarà già scontato senza dover uscire dalla schermata di vendita.

## 8. UX & Data Entry: Come gestire l' "Illusione del Prezzo Base" nel Frontend

Disaccoppiare i prezzi dal prodotto a livello di database è una best practice ingegneristica, ma **può distruggere l'esperienza utente** se costringe a navigare in tre schede diverse solo per impostare il prezzo di un nuovo articolo.

Gli ERP best-in-class superano questo limite strutturando l'interfaccia frontend tramite tre pattern di design:

### A. L' "Illusione Ottica" nella scheda Prodotto (Prezzo Base Diretto)

Quando l'utente si trova sulla scheda di creazione del prodotto (`/prodotti/nuovo` o `/prodotti/modifica`), **deve vedere** un campo di input chiamato **"Prezzo di Vendita Base (€)"**.

- **Come funziona lato client:** L'utente inserisce semplicemente `15.50` nel campo. Per lui, quel prezzo è una proprietà fisica del prodotto (proprio come il nome o il codice).

- **Come funziona lato API/Backend:** Quando l'utente preme "Salva", il frontend invia un payload unificato:
  
  ```
  {
    "codice": "SOLV-01",
    "nome": "Solvente Acetone Puro",
    "prezzo_vendita_base": 15.50,
    "costo_medio_ponderato": 8.00
  }
  ```
  
  Il backend intercetta la richiesta ed esegue una **transazione atomica SQL**:
  
  1. Fa l'INSERT/UPDATE del record nella tabella `prodotti`.
  
  2. Fa un **UPSERT** (Inserimento o Aggiornamento) automatico nella tabella `listini_regole` forzando il `listino_id = 1` (Listino Standard), `tipo_calcolo = 'fixed'`, `valore = 15.50` e associando il `prodotto_id` appena creato.

*L'utente ha l'illusione di salvare il prezzo sul prodotto, ma a DB i dati rimangono normalizzati, puliti e scalabili.*

### B. Il Tab "Prezzi & Listini" (Matrice locale sul Prodotto)

Sempre all'interno della scheda prodotto, di fianco alle informazioni di magazzino o tecniche, i grandi ERP inseriscono un pannello (Tab) denominato **"Vendite & Pricing"**.

In questa sezione viene visualizzata una tabella dinamica (DataGrid) che mostra tutte le regole di prezzo esistenti per *quel* prodotto in tutti i listini del sistema:

| Listino                     | Target                  | Quantità Min. | Tipo Calcolo        | Valore        | Validità      | Azioni     |
| --------------------------- | ----------------------- | ------------- | ------------------- | ------------- | ------------- | ---------- |
| **Listino Standard (Base)** | Tutti i Clienti         | 0 Kg          | Prezzo Fisso        | **15.50 €**   | *Sempre*      | [Modifica] |
| **Listino Grossisti**       | Categoria: Distributori | 1000 Kg       | Sconto %            | **10.00 %**   | *Sempre*      | [Elimina]  |
| **Special ACME**            | Cliente: ACME S.p.A.    | 5000 Kg       | Formula (Costo + %) | **+ 12.00 %** | Fino al 31/12 | [Elimina]  |

- **Vantaggio:** Il product manager non deve andare nella sezione "Configurazione Listini" per decidere quanto costa un prodotto per i vari clienti. Lo fa direttamente mentre compila la scheda tecnica dell'articolo.

### C. La Tabella Excel-Like (Massive Editing)

Se l'azienda commercializza $10.000$ articoli chimici, non è pensabile che l'ufficio prezzi apra ogni singola scheda prodotto per aggiornare i listini di base o speciali.

I migliori ERP espongono una vista tabellare nativa (basata su librerie ad alte prestazioni come *ag-Grid* o *Handsontable*) che replica il comportamento di Microsoft Excel:

- L'utente seleziona un listino (es. "Listino Standard").

- L'ERP mostra una griglia editabile con due colonne principali: **Prodotto** e **Prezzo Fisso**.

- L'utente può usare le frecce della tastiera per muoversi tra le celle, sovrascrivere i valori alla velocità della luce, trascinare i dati per copiare i prezzi su righe adiacenti e salvare $500$ modifiche in un colpo solo tramite una sola chiamata batch alle API.