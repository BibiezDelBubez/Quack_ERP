# Architettura ERP Best-in-Class per la Gestione Multi-UoM e Listini Prezzi

## Focus: Industria Chimica (Acquisti, Magazzino, Vendite)

Questo documento analizza come i grandi ERP di fascia Enterprise (**SAP ERP**, **Oracle Cloud SCM** e **Microsoft Dynamics 365 F&O/Business Central**) affrontano la gestione delle unità di misura (UoM) multiple e dei prezzi a esse collegati, proponendo una soluzione tecnica ottimizzata per un'applicazione web sviluppata in PHP (Backend) e JavaScript (Frontend).

## 1. La Filosofia Cardine degli ERP Enterprise

Se si tenta di calcolare dinamicamente le quantità storiche e i prezzi basandosi *unicamente* sull'anagrafica prodotto corrente, l'ERP fallirà inevitabilmente al primo cambio di packaging o di densità.

I sistemi di classe mondiale si basano su tre pilastri architettonici:

### A. Immutabilità Transazionale (Transactional Snapshotting)

Quando un documento (preventivo, ordine di acquisto/vendita, fattura) viene salvato o finalizzato, **tutti i fattori di conversione, i prezzi unitari e le UoM utilizzate in quel momento vengono copiati (snapshottati) direttamente nelle righe del documento**.

*Se l'anagrafica prodotto cambia domani, i documenti passati rimangono matematicamente identici e intatti.*

### B. Il concetto di Base UoM (Stock UoM)

La **Base UoM** (chiamata in SAP *Base Unit of Measure - BUoM*) è l'unità di misura in cui viene tenuto il saldo di magazzino ed è immutabile una volta che sono avvenuti movimenti fisici. Tutte le transazioni in unità alternative (Commercial UoM / Sales UoM / Purchase UoM) vengono convertite e salvate *anche* nella Base UoM per aggiornare i saldi di inventario.

### C. Mantenimento delle UoM Native nei Listini (Native UoM Pricing)

I listini prezzi **non** vengono forzatamente convertiti in Base UoM all'interno del database dei listini. Se un fornitore vende un prodotto a `12.50 €/kg`, il listino memorizza esattamente `prezzo = 12.50`, `valuta = EUR`, `uom = KG`. La conversione in unità di stock avviene solo durante la transazione o la visualizzazione, memorizzando sia il prezzo inserito dall'utente sia il costo/prezzo di stock calcolato.

## 2. Come i Leader di Mercato Gestiscono le UoM

### SAP ERP (ECC & S/4HANA)

- **Tabelle Master (`MARM`)**: Gestisce le unità alternative inserendo coefficienti frazionari rispetto alla Base UoM. Per evitare errori di arrotondamento con numeri periodici (es. $1/3 = 0.33333333$), SAP memorizza un **Numeratore** e un **Denominatore** per ogni conversione.

- **Pricing Unit (`KPEIN`)**: SAP permette di definire i prezzi per "Quantità di Prezzo" (es. `150 € per 100 kg`). Questo risolve le problematiche legate a importi decimali microscopici per unità singola.

### Microsoft Dynamics 365

- **Classi di Conversione (Intra-class & Inter-class)**:
  
  - *Intra-class*: Conversioni standard fisse (es. da `g` a `kg` o da `L` a `mL`).
  
  - *Inter-class*: Conversioni specifiche per prodotto (es. da `L` a `kg` per un acido con densità $1.4 \text{ g/cm}^3$).

- **Catch Weight (Doppia Misura)**: Estremamente comune nel settore chimico/alimentare. Permette di tracciare contemporaneamente l'inventario in "Pezzi" (es. 5 fusti) e il peso reale effettivo (es. 1023 kg totali), fatturando sul peso effettivo rilevato al carico/scarico.

## 3. Matrice dei Casi Limite (Edge Cases) ed Eccezioni

Di seguito sono elencati tutti gli scenari critici indicati e le relative soluzioni adottate dai sistemi Best-in-Class.

| **Caso Limite / Eccezione**                                     | **Cosa succede nel DB / Sistema?**                                                                                                                                  | **Soluzione Best-Practice ERP**                                                                                                                                                                                                                                                                                                                                                                      |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **1. Cambio della UoM di Stock (Base UoM)**                     | Se modificata dopo che sono avvenuti movimenti, sballa tutto lo storico del magazzino e i costi medi ponderati.                                                     | **Divieto Assoluto.** Una volta che un prodotto ha movimenti di magazzino (anche solo 1), la Base UoM viene **bloccata**. Se è strettamente necessario cambiarla, l'utente deve azzerare lo stock del vecchio prodotto, disattivarlo e creare un *Nuovo Codice Prodotto*.                                                                                                                            |
| **2. UoM Commerciale mai inserita**                             | L'utente vuole inserire un preventivo o acquistare in un'unità alternativa, ma non è definita alcuna conversione.                                                   | Il sistema permette transazioni **solo** nella UoM di Stock. Sulla UI del preventivo/ordine, il selettore della UoM mostrerà unicamente l'unità di Stock di default.                                                                                                                                                                                                                                 |
| **3. UoM Commerciale aggiunta in un secondo momento**           | L'anagrafica viene aggiornata introducendo la possibilità di vendere a KG o Litri.                                                                                  | Nessun impatto retroattivo. I nuovi documenti beneficeranno della conversione, mentre quelli vecchi continueranno a fare riferimento alla UoM di Stock originariamente salvata sulle righe storiche.                                                                                                                                                                                                 |
| **4. Cambio del Fattore di Conversione**                        | Un fusto (unità stock) prima conteneva 25 kg di solvente, ora il fornitore lo confeziona da 20 kg.                                                                  | I documenti storici rimangono congelati (usano il fattore $25$ salvato sulle righe). Per l'anagrafica futura, ci sono due approcci:  <br>1) *Semplice*: Si modifica il fattore a $20$ in anagrafica (valido solo per i nuovi documenti).  <br>2) *Enterprise*: Si usa il **Versionamento dei fattori di conversione** con date di validità (es. Fattore = 25 fino al 31/12, Fattore = 20 dal 01/01). |
| **5. Eliminazione di una UoM dal catalogo globale**             | L'utente cancella la UoM "KG" o "LITRI" dall'anagrafica generale delle misure.                                                                                      | **Soft-Delete / Inattivazione.** Non si elimina fisicamente la riga dal DB se è referenziata altrove. Si imposta una colonna `is_active = FALSE`. Le transazioni in corso o storiche continuano a funzionare, ma non è più possibile selezionare quella UoM per nuovi prodotti o documenti.                                                                                                          |
| **6. Discrepanze da Arrotondamento (il problema di `0.33333`)** | Acquistando 1 fusto da 3 kg (unità stock), l'utente inserisce un ordine per 1 kg (0.3333 fusti). Lo stock finale calcola `3 * 0.3333 = 0.9999` fusti invece di `1`. | **Uso dei Coefficienti Frazionari (Numeratore/Denominatore)** invece di un singolo valore float. Nel database si salva che `1 KG = 1/3 di Fusto` (Numeratore: 1, Denominatore: 3). Il calcolo a database viene eseguito usando moltiplicazioni e divisioni intere o ad alta precisione (`DECIMAL(18,6)`).                                                                                            |

## 4. Architettura Database Proposta (MySQL/PostgreSQL)

Ecco lo schema relazionale ottimizzato per la tua applicazione in XAMPP. Nota come le tabelle transazionali (righe d'ordine) isolino completamente i dati storici.

```
-- 1. REGISTRO DELLE UNITÀ DI MISURA GLOBALE
CREATE TABLE uom_registry (
    uom_code VARCHAR(10) PRIMARY KEY, -- es. 'PZ', 'KG', 'L', 'FUSTO'
    uom_name VARCHAR(50) NOT NULL,
    uom_type ENUM('COUNT', 'WEIGHT', 'VOLUME') NOT NULL,
    is_active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. ANAGRAFICA PRODOTTI
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    stock_uom_code VARCHAR(10) NOT NULL, -- BASE UoM (Immutabile se ha movimenti di magazzino)
    is_active TINYINT(1) DEFAULT 1 NOT NULL,
    FOREIGN KEY (stock_uom_code) REFERENCES uom_registry(uom_code)
);

-- 3. FATTORI DI CONVERSIONE SPECIFICI PER PRODOTTO
-- Formula: 1 Unità Alternativa (alt_uom_code) = (num_factor / den_factor) * Unità di Stock
-- Esempio: 1 KG (alt) di Prodotto X = (1 / 25) * FUSTO (stock). Quindi 1 KG = 0.04 Fusti.
CREATE TABLE product_uom_conversions (
    product_id INT NOT NULL,
    alt_uom_code VARCHAR(10) NOT NULL,
    num_factor DECIMAL(18, 9) NOT NULL DEFAULT 1.000000000, -- Numeratore
    den_factor DECIMAL(18, 9) NOT NULL DEFAULT 1.000000000, -- Denominatore
    PRIMARY KEY (product_id, alt_uom_code),
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (alt_uom_code) REFERENCES uom_registry(uom_code)
);

-- 4. LISTINI PREZZI (Acquisto, Vendita, RRP)
-- Conservano la UoM nativa inserita dall'utente commerciale.
CREATE TABLE price_lists (
    price_list_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_type ENUM('PURCHASE', 'SALES', 'RRP') NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    price_value DECIMAL(18, 4) NOT NULL, -- Valore del prezzo
    price_uom_code VARCHAR(10) NOT NULL,  -- UoM inserita (può essere stock o commerciale)
    valid_from DATE NOT NULL,
    valid_to DATE,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (price_uom_code) REFERENCES uom_registry(uom_code)
);

-- 5. TRANSAZIONI: RIGHE ORDINE DI VENDITA (Esempio applicabile anche ad Acquisti/Preventivi)
-- Questa tabella CONGELA tutte le conversioni al momento del salvataggio del documento.
CREATE TABLE sales_order_lines (
    line_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,

    -- Dati inseriti dall'utente (Commerciali)
    ordered_qty DECIMAL(18, 4) NOT NULL,          -- es. 10.0000
    ordered_uom_code VARCHAR(10) NOT NULL,       -- es. 'KG'
    input_unit_price DECIMAL(18, 4) NOT NULL,     -- es. 12.5000 (prezzo al KG)

    -- Snapshot dei fattori di conversione attivi AL MOMENTO della transazione
    num_factor_snapshot DECIMAL(18, 9) NOT NULL,  -- Memorizza il numeratore attivo al salvataggio
    den_factor_snapshot DECIMAL(18, 9) NOT NULL,  -- Memorizza il denominatore attivo al salvataggio

    -- Dati calcolati per il Backend / Logistica (Riferiti sempre all'Unità di Stock)
    stock_qty DECIMAL(18, 4) NOT NULL,            -- Quantità in unità di stock (es. fusti) calcolata
    stock_uom_code_snapshot VARCHAR(10) NOT NULL, -- Unità di stock congelata
    system_unit_price_stock DECIMAL(18, 4) NOT NULL, -- Costo/prezzo unitario convertito in unità di stock

    row_total DECIMAL(18, 4) NOT NULL,            -- Totale riga (evita ricalcoli fluttuanti): ordered_qty * input_unit_price
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);
```

## 5. Logica di Calcolo Matematico (Frazioni vs Floats)

Per garantire la massima precisione numerica ed evitare che `0.33333333` si perda nel database, definiamo la logica matematica di conversione.

### Formule di Conversione

Definiamo la relazione d'anagrafica come:

$$\text{1 Unità Commerciale (Alt)} = \left(\frac{\text{Numeratore}}{\text{Denominatore}}\right) \times \text{Unità di Stock (Base)}$$

#### Esempio:

- **Unità Stock**: `FUSTO`

- **Unità Commerciale**: `KG`

- Un fusto contiene **25 kg** di prodotto.

- Significa che $1 \text{ KG} = \frac{1}{25} \text{ Fusto}$.
  
  - `num_factor` = $1.000000000$  
  
  - `den_factor` = $25.000000000$  

#### Caso A: L'utente ordina $100 \text{ kg}$ (Unità Commerciale)

Vogliamo calcolare la quantità equivalente in **Unità Stock** per movimentare il magazzino:

$$\text{Quantità Stock} = \text{Quantità Commerciale} \times \left(\frac{\text{Numeratore}}{\text{Denominatore}}\right)$$$$\text{Quantità Stock} = 100 \times \left(\frac{1}{25}\right) = 4 \text{ Fusti}$$

#### Caso B: L'utente inserisce un prezzo di listino di $15.00 \text{ €/KG}$

Vogliamo calcolare l'equivalente prezzo per **Unità Stock** (Fusto):

$$\text{Prezzo Stock} = \text{Prezzo Commerciale} \div \left(\frac{\text{Numeratore}}{\text{Denominatore}}\right) = \text{Prezzo Commerciale} \times \left(\frac{\text{Denominatore}}{\text{Numeratore}}\right)$$$$\text{Prezzo Stock} = 15.00 \times \left(\frac{25}{1}\right) = 375.00 \text{ €/Fusto}$$

## 6. Implementazione Software (Backend PHP & Frontend JS)

Per garantire un'esperienza utente (UX) eccellente ed evitare bug di calcolo tra client e server, la conversione deve avvenire sia in JS (reattività UI) che in PHP (validazione e scrittura DB).

### A. Frontend: Logica JavaScript (`uom-converter.js`)

In JavaScript utilizziamo la libreria nativa `BigInt` o una precisione decimale accurata per evitare le fluttuazioni dei numeri a virgola mobile di JS (es. `0.1 + 0.2 = 0.30000000000000004`).

```
class UomConverter {
    /**
     * Converte la quantità commerciale in quantità di stock
     * @param {number|string} commercialQty 
     * @param {number|string} num 
     * @param {number|string} den 
     * @returns {number}
     */
    static toStockQty(commercialQty, num, den) {
        const q = parseFloat(commercialQty);
        const n = parseFloat(num);
        const d = parseFloat(den);
        if (isNaN(q) || isNaN(n) || isNaN(d) || d === 0) return 0;

        // Calcolo ad alta precisione arrotondato a 4 decimali
        return parseFloat((q * (n / d)).toFixed(4));
    }

    /**
     * Converte il prezzo commerciale in prezzo riferito all'unità di stock
     * @param {number|string} commercialPrice 
     * @param {number|string} num 
     * @param {number|string} den 
     * @returns {number}
     */
    static toStockPrice(commercialPrice, num, den) {
        const p = parseFloat(commercialPrice);
        const n = parseFloat(num);
        const d = parseFloat(den);
        if (isNaN(p) || isNaN(n) || isNaN(d) || n === 0) return 0;

        return parseFloat((p * (d / n)).toFixed(4));
    }
}
```

### B. Backend: Validazione e Salvataggio PHP (`OrderProcessor.php`)

Il backend non si fida mai dei calcoli inviati dal frontend. Ricalcola tutto lato server e popola la tabella transazionale con i valori corretti e congelati.

```
<?php

class OrderProcessor {
    private $db;

    public function __construct(PDO $dbConnection) {
        $this->db = $dbConnection;
    }

    public function saveOrderLine($orderId, $productId, $orderedQty, $orderedUom, $inputPrice) {
        // 1. Recupera i dati anagrafici correnti del prodotto e le conversioni
        $stmt = $this->db->prepare("
            SELECT p.stock_uom_code, c.num_factor, c.den_factor
            FROM products p
            LEFT JOIN product_uom_conversions c 
              ON p.product_id = c.product_id AND c.alt_uom_code = :ordered_uom
            WHERE p.product_id = :product_id
        ");
        $stmt->execute([
            ':product_id' => $productId,
            ':ordered_uom' => $orderedUom
        ]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Prodotto non trovato o UoM non configurata.");
        }

        // Se l'utente ordina direttamente in unità di stock, i fattori sono 1/1
        if ($orderedUom === $product['stock_uom_code']) {
            $numFactor = 1.000000000;
            $denFactor = 1.000000000;
        } else {
            if (!$product['num_factor'] || !$product['den_factor']) {
                throw new Exception("Fattore di conversione mancante per la UoM selezionata.");
            }
            $numFactor = (float)$product['num_factor'];
            $denFactor = (float)$product['den_factor'];
        }

        // 2. Esegui i calcoli di conversione (Usando BCMath in PHP per evitare imprecisioni float)
        // Quantità Stock = Qty Ordinata * (Num / Den)
        $conversionRate = bcdiv($numFactor, $denFactor, 9);
        $stockQty = bcmul($orderedQty, $conversionRate, 4);

        // Prezzo Stock = Prezzo Ordinato * (Den / Num)
        $inverseRate = bcdiv($denFactor, $numFactor, 9);
        $stockPrice = bcmul($inputPrice, $inverseRate, 4);

        // Calcolo totale riga
        $rowTotal = bcmul($orderedQty, $inputPrice, 4);

        // 3. Persisti nel DB congelando lo snapshot
        $insertQuery = "
            INSERT INTO sales_order_lines (
                order_id, product_id, ordered_qty, ordered_uom_code, input_unit_price,
                num_factor_snapshot, den_factor_snapshot,
                stock_qty, stock_uom_code_snapshot, system_unit_price_stock, row_total
            ) VALUES (
                :order_id, :product_id, :ordered_qty, :ordered_uom, :input_price,
                :num_factor, :den_factor,
                :stock_qty, :stock_uom, :stock_price, :row_total
            )
        ";

        $insertStmt = $this->db->prepare($insertQuery);
        $insertStmt->execute([
            ':order_id'    => $orderId,
            ':product_id'  => $productId,
            ':ordered_qty' => $orderedQty,
            ':ordered_uom' => $orderedUom,
            ':input_price' => $inputPrice,
            ':num_factor'  => $numFactor,
            ':den_factor'  => $denFactor,
            ':stock_qty'   => $stockQty,
            ':stock_uom'   => $product['stock_uom_code'],
            ':stock_price' => $stockPrice,
            ':row_total'   => $rowTotal
        ]);

        return $this->db->lastInsertId();
    }
}
```

## 7. Flusso di Lavoro Esemplificato (Esempio Pratico)

Analizziamo il ciclo di vita completo del prodotto chimico **"Solvente Chimico Alpha"**.

### Fase 1: Configurazione Anagrafica

- **Prodotto**: Solvente Alpha (ID: 42)

- **UoM di Stock**: `FUSTO` (pezzi fisici in magazzino)

- **UoM Commerciale**: `KG`

- **Fattore di Conversione**: Un fusto contiene esattamente $25\text{ kg}$.
  
  - $1\text{ KG} = 1/25\text{ Fusto}$.
  
  - `num_factor` = $1$, `den_factor` = $25$  

### Fase 2: Configurazione Listini

L'utente inserisce due tipi di prezzi:

1. **Prezzo di Listino Vendita Commerciale**: `10.00 €/KG`

2. **Prezzo Consigliato (RRP) dal Fornitore**: `200.00 €/FUSTO` (in unità di stock)

Il database memorizza esattamente queste informazioni nativamente nella tabella `price_lists`.

### Fase 3: Redazione del Preventivo / Sales Order

Un cliente richiede un preventivo per **100 KG** di Solvente Alpha.

1. **Selezione Prodotto**: L'utente seleziona "Solvente Alpha".

2. **Selezione UoM**: L'interfaccia client (JS) vede che le unità disponibili sono `FUSTO` (Default) e `KG` (da tabella conversioni). L'utente seleziona `KG`.

3. **Determinazione del Prezzo sulla UI**:
   
   - Il sistema cerca il listino. Trova `10.00 €/KG`.
   
   - Poiché la UoM del listino corrisponde alla UoM selezionata (`KG`), propone direttamente `10.00 €/KG`.
   
   - *Se l'utente avesse selezionato `FUSTO`*, il JS avrebbe preso il prezzo del listino in KG (`10.00`), applicato la formula inversa di conversione ($10.00 \times 25 / 1$) mostrando all'utente `250.00 €/FUSTO`.

4. **Salvataggio Documento**:
   
   - Il backend riceve la riga: `Prodotto: 42, Quantità ordinata: 100, UoM: KG, Prezzo: 10.00`.
   
   - Il backend calcola i valori di magazzino:
     
     - Quantità Stock = $100 \times (1 / 25) = 4\text{ Fusti}$.
     
     - Prezzo unitario di Stock = $10.00 \times (25 / 1) = 250.00\text{ €/Fusto}$.
   
   - Scrive lo snapshot nella tabella `sales_order_lines`.

### Fase 4: Spedizione e Logistica

L'addetto al magazzino vede l'ordine di spedizione. Il sistema gli dice di prelevare **4 Fusti** (Stock UoM).

L'addetto effettua il prelievo. Il magazzino viene scaricato di 4 unità fisiche.

## Conclusioni e Raccomandazioni per lo Sviluppo

Seguendo questo schema architetturale:

1. **Il magazzino è al sicuro**: ragiona sempre e solo in un'unica valuta di stock fisicamente immutabile.

2. **I contratti sono storicamente corretti**: se il solvente chimico Alpha in futuro cambierà confezionamento passando a fusti da 20 kg, l'ordine emesso oggi continuerà a riportare che 100 kg equivalgono a 4 fusti da 25 kg, preservando la contabilità aziendale.

3. **L'inserimento dati è flessibile**: i colleghi commerciali possono negoziare in KG, Litri o Fusti senza che questo alteri i controlli fisici dell'inventario.
