# PIANO — Motore Aliquote IVA e Classificazione Fiscale (allineamento a SAP/NetSuite/Dynamics 365)

**Stato: BACKLOG — non pianificato per l'esecuzione.** Scritto su richiesta esplicita dell'utente (12/08/2026) come piano di riferimento super dettagliato, da riprendere solo quando (e se) emergerà un caso reale che lo giustifichi. Nessuna riga di codice toccata da questo documento. Va letto insieme a `PIANO - Aliquota IVA su Prodotti e Cliente Esente (Livello Intermedio).md` (quello sì eseguibile a breve — sostituisce un precedente piano "soluzione minima" del 12/08/2026, assorbito interamente lì), di cui questo piano è l'evoluzione architetturale, non un'alternativa concorrente.

## 0. Come leggere questo documento

Questo non è un piano "pronto a esecuzione immediata" come la soluzione minima: contiene decisioni di prodotto ancora aperte (§5) che vanno prese con l'utente prima di scrivere qualunque riga di codice, coerente con la Regola 8 di `CLAUDE.md` di questo progetto ("non decidere al posto dell'utente"). Serve a due cose: (a) lasciare traccia dettagliata di cosa fanno davvero i grandi ERP su questo tema, così da non doverlo ri-cercare da capo in futuro; (b) dare una stima concreta di sforzo/impatto per decidere se e quando vale la pena farlo.

## 1. Perché questo piano esiste, in breve

Il modello attuale di Quack (anche dopo il wiring della soluzione minima) resta una **percentuale IVA grezza salvata sul prodotto**. Confrontato con SAP, NetSuite e Dynamics 365 Business Central (ricerca dedicata, 12/08/2026 — vedi §2), nessuno dei tre sistemi fa così: tutti risolvono l'aliquota da applicare tramite una **combinazione tra una classificazione fiscale del prodotto e una classificazione fiscale della controparte** (cliente o fornitore), non da un numero fisso attaccato al solo prodotto. Il limite pratico concreto per Quack: oggi non esiste un modo strutturato per dire "questo prodotto è normalmente al 22%, ma per QUESTO cliente (export extra-UE, dichiarazione d'intento, reverse charge intra-UE) l'aliquota reale è 0%" — è esattamente lo scenario che la "regola d'oro" di Quack (mai prezzi lordi a DB, vedi `Ciclo Acquisti - Schema Dati e API.md` §2.5) cita come motivazione, ma per cui oggi manca il meccanismo.

## 2. Come funzionano davvero SAP, NetSuite, Dynamics 365 (ricerca dedicata, 12/08/2026)

### 2.1 SAP

Il materiale ha una **classificazione fiscale** in anagrafica (vista "Sales Org. 1" del materiale) — non un'aliquota, un codice (es. "1" = pieno, "0" = esente). Il cliente ha la propria classificazione fiscale (scheda "Fatturazione" a livello area di vendita). La combinazione delle due viene risolta tramite la tecnica delle condizioni (la stessa usata per i prezzi): un tipo condizione (tipicamente `MWST`) e un record di condizione mantenuto in transazione `VK11`, che lega la combinazione classificazione-cliente × classificazione-materiale a un tax code, il quale porta con sé l'aliquota effettiva. Il tax code stesso è mantenuto in `FTXP`, con le sue date di validità.

### 2.2 NetSuite

L'item porta un **Tax Code** o, con le tasse avanzate abilitate, un **Tax Schedule** — mai una percentuale libera. Il Tax Code/Tax Schedule è un'entità a sé, con la propria aliquota e le proprie regole; la determinazione finale dipende anche dal nexus/tax group della controparte e dalla giurisdizione. I Tax Schedule sono descritti esplicitamente come necessari solo quando un item richiede un trattamento diverso dal default del nexus — cioè il default vive a livello di giurisdizione/controparte, il prodotto lo sovrascrive solo quando serve davvero.

### 2.3 Dynamics 365 Business Central

**VAT Product Posting Group** (sul prodotto) × **VAT Business Posting Group** (sul cliente/fornitore) → tabella **VAT Posting Setup**, che per ciascuna combinazione delle due dimensioni porta una % specifica. L'IVA si calcola solo per le combinazioni effettivamente presenti in quella tabella — se una combinazione non è configurata, il sistema segnala l'anomalia invece di assumere un default silenzioso.

### 2.4 Pattern comune, in una frase

Prodotto × Controparte → tabella di determinazione → codice/aliquota con validità temporale. Mai un numero grezzo memorizzato una volta sola su un solo lato della transazione.

## 3. Vincoli di progetto noti (da rispettare, non negoziabili in questo piano)

1. **Fatturazione elettronica reale esclusa per sempre.** Decisione dell'utente, 26/07/2026, registrata in `docs/Backlog Sviluppi Futuri.md` voce 12: "Quack non emetterà mai fatture elettroniche reali (nessun XML FatturaPA, nessun invio SDI, nessun `FatturaController`)". **Conseguenza diretta e importante per questo piano**: qualunque codice di "natura IVA" introdotto qui serve solo a uso interno (correttezza del documento mostrato/stampato, promemoria per chi poi emette la fattura vera altrove) — non ha senso replicare la granularità completa dei codici natura N1-N7.9 richiesti dallo SDI, che qui non verrebbero mai consumati da un generatore XML. Il set di codici va scelto per la sua utilità interna, non per conformità SDI.
2. **Prezzi sempre netti a DB.** Regola d'oro già in vigore (`Ciclo Acquisti - Schema Dati e API.md` §2.5) — invariata, questo piano non la tocca.
3. **Punto di partenza già esistente, non ripartire da zero.** `dettagli_clienti.iva_esente BOOLEAN NOT NULL DEFAULT 0` e `dettagli_clienti.codice_esenzione_iva VARCHAR(20)` esistono già (`db/init_sqlite.sql:441-442`). Oggi `codice_esenzione_iva` è testo libero, mai validato, e non risulta incrociato con l'IVA di prodotto in nessun documento o punto di codice (verificato).
4. **Nessun campo di plafond/dichiarazione d'intento esiste oggi.** Verificato: zero occorrenze di "plafond", "dichiarazione d'intento", "esportatore abituale", "VIES" in tutto il codebase.
5. **Doppio schema SQLite/MySQL.** Qualunque modifica strutturale va valutata su entrambi `db/init_sqlite.sql` e `db/init_mysql.sql` (Regola 7 di `CLAUDE.md`), anche se lo sviluppo attivo resta su SQLite.

## 4. Stato attuale — riassunto (dettaglio completo nel piano di livello intermedio collegato)

Anche dopo l'esecuzione della soluzione minima, il modello resterebbe: `prodotti.iva_vendita_default`/`iva_acquisto_default` e `prodotti_fornitori.iva_acquisto_default` come `DECIMAL(5,2)` grezzi, suggeriti in automatico, sempre sovrascrivibili a mano riga per riga, mai incrociati con lo stato di esenzione del cliente. Il commento già presente in `db/init_sqlite.sql:511` ("iva che viene suggerita nelle offerte. forse in futuro sarà sostituita da una fk a tabella aliquote") anticipava esattamente la direzione di questo piano.

## 5. Decisioni da prendere PRIMA di scrivere qualunque codice (nessuna presa qui)

Come per le altre voci di backlog di questo progetto con domande aperte (vedi ad es. voce 37, "Bozza persistente per Ordini diretti"), sono decisioni di prodotto, non solo tecniche:

1. **Quanto è reale il bisogno?** Non è noto (non verificato in questa sessione, dato non presente nel codebase) quanti clienti/fornitori di Quack siano esteri (UE o extra-UE) oggi o a breve, quanti abbiano dichiarazione d'intento attiva, e se il catalogo prodotti ha davvero aliquote diverse dal 22% oltre a poche eccezioni. Senza questo dato, costruire il modello completo sarebbe complessità anticipata senza un requisito reale confermato — stesso principio già applicato altrove in questo backlog (voce 11, validazione indirizzi esteri; voce 17, multi-valuta).
2. **Fino a che punto normalizzare la classificazione cliente?** Un'opzione leggera (sostituire `codice_esenzione_iva` testo libero con un `<select>` a valori fissi: nessuno / export extra-UE / intra-UE reverse charge / dichiarazione d'intento) copre già la maggior parte dei casi reali per un'azienda italiana B2B, senza costruire un motore di condizioni generico multi-dimensionale come SAP (che per la scala di Quack sarebbe over-engineering).
3. **Si vuole gestire il plafond delle dichiarazioni d'intento?** È la parte più delicata e con più valore reale se il caso esiste: un esportatore abituale è esente solo fino a un tetto annuo, non un booleano stabile — serve tracciare protocollo, plafond totale, plafond utilizzato, e bloccare/avvisare al superamento. Se il caso non esiste nella base clienti attuale, questa sotto-parte può restare esclusa anche quando il resto del piano venisse ripreso.
4. **Il catalogo ha davvero bisogno di più di due/tre aliquote?** Se in pratica quasi tutto il catalogo è al 22% con pochissime eccezioni, il beneficio di una tabella `aliquote_iva` dedicata (invece del semplice `DECIMAL` già wired dal piano di livello intermedio) è soprattutto la storicizzazione nel tempo (cambio aliquota di legge) più che la varietà di valori.
5. **Vendite a privati (B2C)?** Non risulta, da quanto verificato in questo codebase, che Quack venda a consumatori finali — se così fosse anche in futuro, andrebbe valutato il tema OSS (One Stop Shop, aliquota del paese di destinazione per vendite UE a privati) — oggi fuori scope per mancanza di caso d'uso.
6. **Il log override (§6.5) vale la pena costruirlo da solo, prima del resto?** A differenza dei punti 1-5, non dipende da `aliquote_iva` né dal plafond — si appoggia solo a `qk_risolvi_aliquota_iva_riga()`, già esistente dal piano di livello intermedio. Ha senso deciderlo separatamente: se in futuro emerge un bisogno concreto di tracciare le eccezioni IVA (es. richiesta di un commercialista, o un controllo), questa singola sotto-parte (Fase 6bis) potrebbe essere autorizzata ed eseguita prima ed indipendentemente dal resto di questo piano.

## 6. Modello dati proposto (da confermare solo dopo le risposte al §5)

### 6.1 Tabella `aliquote_iva`

```sql
CREATE TABLE IF NOT EXISTS aliquote_iva (
    codice        VARCHAR(10)   PRIMARY KEY,           -- es. '22', '10', '4', 'ESENTE'
    percentuale   DECIMAL(5,2)  NOT NULL,               -- 22.00, 10.00, 4.00, 0.00
    descrizione   VARCHAR(100)  NOT NULL,               -- "Aliquota ordinaria", "Esente — export extra-UE"
    motivo_esenzione VARCHAR(50),                       -- SOLO uso interno/promemoria (vedi vincolo §3.1) — NON un codice natura SDI
    valido_dal    DATE,
    valido_al     DATE,
    attivo        BOOLEAN       NOT NULL DEFAULT 1
);
```

`prodotti.iva_vendita_default`, `prodotti.iva_acquisto_default`, `prodotti_fornitori.iva_acquisto_default` diventano `VARCHAR(10) REFERENCES aliquote_iva(codice)` invece di `DECIMAL(5,2)` grezzo — coerente con l'annotazione già presente nello schema (`db/init_sqlite.sql:511`).

### 6.2 Classificazione fiscale cliente (versione leggera, non una VAT Business Posting Group generica)

Sostituire `dettagli_clienti.codice_esenzione_iva VARCHAR(20)` (oggi testo libero) con un campo a valori fissi, es.:

```sql
motivo_esenzione_iva VARCHAR(20) CHECK (motivo_esenzione_iva IN (
    'nessuno', 'export_extra_ue', 'intra_ue_reverse_charge', 'dichiarazione_intento'
)) DEFAULT 'nessuno'
```

`iva_esente` (booleano già esistente) può restare come colonna calcolata/ridondante per compatibilità con l'eventuale codice esistente che già lo legge, oppure essere derivato da `motivo_esenzione_iva != 'nessuno'` — da decidere in fase di esecuzione, non qui.

### 6.3 Dichiarazioni d'intento e plafond (solo se il §5 punto 3 viene confermato come necessario)

```sql
CREATE TABLE IF NOT EXISTS dichiarazioni_intento (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id          INTEGER NOT NULL REFERENCES clienti_fornitori(id),
    numero_protocollo   VARCHAR(50) NOT NULL,
    plafond_totale      DECIMAL(12,2) NOT NULL,
    plafond_utilizzato  DECIMAL(12,2) NOT NULL DEFAULT 0,
    valido_dal          DATE NOT NULL,
    valido_al           DATE NOT NULL,
    attivo              BOOLEAN NOT NULL DEFAULT 1
);
```

Ogni riga venduta a un cliente con dichiarazione d'intento attiva e plafond residuo sufficiente andrebbe a scalare `plafond_utilizzato`; al superamento, l'aliquota tornerebbe quella ordinaria (o l'operazione andrebbe bloccata con avviso esplicito) — dettaglio del motore di determinazione, §6.4.

### 6.4 Motore di determinazione (funzione unica, sostituisce i `COALESCE` sparsi)

```
qk_risolvi_aliquota_iva(prodotto_id, controparte_id, direzione: 'vendita'|'acquisto'): { codice, percentuale, motivo }
```

Ordine di risoluzione proposto:
1. Controparte con `motivo_esenzione_iva` bloccante (`export_extra_ue`, `intra_ue_reverse_charge`) → aliquota `ESENTE` (0%), motivo riportato.
2. Controparte con dichiarazione d'intento attiva e plafond residuo sufficiente per la riga → aliquota `ESENTE`, scala il plafond.
3. (Solo direzione acquisto) Override fornitore: `prodotti_fornitori.iva_acquisto_default`.
4. Default di prodotto: `prodotti.iva_vendita_default`/`iva_acquisto_default`.
5. Fallback di sistema (stessa costante centralizzata introdotta dal piano di livello intermedio, §6 Fase 4 di quel piano).

Questa funzione sostituirebbe, in ogni punto attualmente wired dal piano di livello intermedio, la logica `COALESCE(pf..., p...)` con una chiamata unica — un solo punto da mantenere invece di N query duplicate.

### 6.5 Override controllato dell'aliquota su riga — la risposta "enterprise" a "modificabile o bloccato?"

Nato da una domanda diretta dell'utente (13/08/2026) sul piano di livello intermedio: quando un cliente è esente, il campo IVA sulla riga deve restare modificabile o va bloccato? Il piano di livello intermedio ha scelto **modificabile con avviso** (badge giallo, nessun blocco, nessuna traccia di chi cambia cosa) — scelta pragmatica adatta a un sistema senza plafond né classificazione fiscale strutturata, perché lì il sistema non ha comunque abbastanza informazione per sapere se un'eccezione è legittima o è un errore, quindi bloccare sarebbe solo friction senza un vero controllo dietro.

**Un vero motore enterprise non sceglie tra "modificabile" e "bloccato" come due opzioni alternative — ne aggiunge una terza.** Il valore proposto da `qk_risolvi_aliquota_iva()` (§6.4) è bloccato per la modifica diretta (l'operatore non può scrivere un numero a mano nel campo), ma esiste un **percorso di eccezione esplicito**: un'azione "Forza aliquota diversa" che apre un piccolo modulo con due campi obbligatori — l'aliquota che si vuole applicare invece di quella proposta, e il motivo (testo libero, obbligatorio, non un semplice checkbox). Il salvataggio della riga con un'aliquota forzata scrive automaticamente una riga in un log di override (proposto sotto), che resta consultabile per sempre — nessuna modifica "silenziosa".

Perché questa terza via è meglio delle due sole ipotizzate nel piano intermedio:

- **Blocco rigido puro** (l'altra opzione considerata nel piano intermedio) impedisce fisicamente le eccezioni legittime (es. plafond appena esaurito a metà anno, una riga di servizio non coperta dall'esenzione) — l'operatore resterebbe bloccato senza via d'uscita pulita.
- **Modificabile senza traccia** (la scelta fatta nel piano intermedio) risolve il blocco ma non lascia nessuna evidenza di chi ha deciso di derogare alla regola e perché — un problema vero se un giorno un commercialista o un controllo fiscale chiede conto di una riga con IVA diversa da quella che il sistema avrebbe calcolato da solo.
- **Override con motivo obbligatorio e log permanente** copre entrambi i bisogni: l'eccezione resta possibile (nessun blocco rigido), ma non è più invisibile — ogni deroga ha un nome, una data e un perché, consultabile in un secondo momento.

Questo è, in sostanza, lo stesso principio che SAP/NetSuite/Dynamics applicano ai valori "calcolati dal sistema ma correggibili" in generale (non solo per l'IVA): il valore di default vive in un campo protetto, la correzione manuale passa da un'azione esplicita e tracciata, mai da una semplice modifica diretta del campo.

**Tabella proposta**, sullo stesso stile già in uso in questo progetto per `log_stati_ordini` (`db/init_sqlite.sql:1161-1170` — stessa forma `cambiato_da`/`cambiato_il`/nota motivo, non un pattern nuovo inventato qui):

```sql
CREATE TABLE IF NOT EXISTS log_override_iva_riga (
    id                  INTEGER      PRIMARY KEY AUTOINCREMENT,
    tipo_documento      VARCHAR(50)  NOT NULL,   -- es. preventivo, ordine_cliente, rfq
    documento_riga_id   INTEGER      NOT NULL,   -- riga specifica su cui è stato forzato il valore
    aliquota_proposta   DECIMAL(5,2) NOT NULL,   -- quella che qk_risolvi_aliquota_iva() aveva calcolato
    aliquota_applicata  DECIMAL(5,2) NOT NULL,   -- quella scritta a mano dall'operatore
    motivo              TEXT         NOT NULL,   -- obbligatorio, testo libero
    cambiato_da         INTEGER      NOT NULL REFERENCES utenti(id),
    cambiato_il         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

**Perché non è nel piano di livello intermedio.** Richiede due cose che quel piano ha deliberatamente escluso: un'azione UI distinta dal semplice campo di input (più lavoro di frontend di un `<input>` scrivibile) e una nuova tabella con relativa scrittura ad ogni deroga (più lavoro di backend di un `if` in JS). Per un solo campo, oggi, è complessità sproporzionata al problema reale verificato — ma è la parte di questo piano enterprise più facile da isolare e costruire per prima, se in futuro il bisogno di tracciabilità (es. per un controllo fiscale, o per capire quante eccezioni reali si verificano davvero) diventasse concreto, anche prima di costruire il resto (tabella `aliquote_iva`, plafond) — non dipende da quelle parti, si appoggia solo a `qk_risolvi_aliquota_iva_riga()` già esistente dal piano intermedio.

## 7. Fasi di implementazione (solo quando/se il piano viene autorizzato)

### Fase 0 — Audit dati (sola lettura, nessuna modifica)

Verificare quanti prodotti reali hanno oggi un `iva_vendita_default`/`iva_acquisto_default` valorizzato (presumibilmente pochi o nessuno, dato quanto verificato nel piano di livello intermedio collegato) e quanti clienti hanno `iva_esente = 1` con che tipo di `codice_esenzione_iva` — per calibrare la migrazione e confermare/smentire le stime del §5.

### Fase 1 — Schema

Nuova tabella `aliquote_iva`, popolata almeno con `22`, `10`, `4`, `ESENTE`. Migrazione dedicata su `db/init_sqlite.sql` e `db/init_mysql.sql` (mantenuti allineati, Regola 7 `CLAUDE.md`). Modifica di `prodotti.iva_vendita_default`/`iva_acquisto_default` e `prodotti_fornitori.iva_acquisto_default` da `DECIMAL` a `VARCHAR(10)` FK — **operazione distruttiva sul tipo colonna**, richiede backup e verificato path di migrazione dati (non un semplice `ALTER TYPE`, va scritto uno script che mappa i decimali esistenti al codice corrispondente).

### Fase 2 — Migrazione dati esistenti

Mappare ogni valore `DECIMAL` esistente (presumibilmente quasi tutti `NULL`, dato l'audit di Fase 0) al codice `aliquote_iva` corrispondente. Caso limite da gestire esplicitamente: valori "anomali" non corrispondenti a nessun codice noto (es. un refuso storico `21.5`) — decidere se arrotondare, segnalare per revisione manuale, o bloccare la migrazione finché non risolto a mano.

### Fase 3 — Classificazione fiscale cliente

`dettagli_clienti.codice_esenzione_iva` → nuovo campo a valori fissi (§6.2). Migrazione dei valori testo-libero esistenti (verificare quanti e quali valori reali sono già stati inseriti a mano, se presenti).

### Fase 4 — Motore di determinazione

Implementare `qk_risolvi_aliquota_iva()` (§6.4) come funzione condivisa in `includes/`, con test unitari dedicati per ciascun ramo dell'ordine di risoluzione.

### Fase 5 — Sostituire i punti di lettura wired dal piano di livello intermedio

Tutti i punti elencati nel piano di livello intermedio (§6 Fase 1 e Fase 2 di quel documento) che oggi leggerebbero direttamente `p.iva_acquisto_default`/`pf.iva_acquisto_default`/`p.iva_vendita_default` vanno fatti passare dalla nuova funzione unica invece che da SELECT dirette — evita di dover ripetere la logica di determinazione in ogni punto.

### Fase 6 — Frontend

I campi numerici liberi introdotti dal piano di livello intermedio (scheda prodotto) diventano `<select>` popolati da `aliquote_iva` invece di input numerici liberi. Il `<select>` a valori fissi per il motivo di esenzione, già introdotto sulla scheda cliente dal piano di livello intermedio, resta com'è o si estende con ulteriori valori se il §5 lo richiede. Il campo IVA sulla riga documento diventa sola lettura, con un'azione distinta "Forza aliquota diversa" che apre il modulo motivo+valore descritto al §6.5 — sostituisce l'`<input>` liberamente scrivibile del piano intermedio.

### Fase 6bis — Log override (indipendente, costruibile anche da sola prima del resto)

Nuova tabella `log_override_iva_riga` (§6.5), scrittura ad ogni deroga confermata dall'azione "Forza aliquota diversa", nuova vista di consultazione (filtrabile per documento/utente/periodo) per chi deve rendere conto delle eccezioni — utile già con il solo motore del piano intermedio, non richiede `aliquote_iva` né le dichiarazioni d'intento per avere valore.

### Fase 7 — Dichiarazioni d'intento e plafond (sotto-fase separata, solo se confermata al §5.3)

Nuova sezione nella scheda cliente per registrare/consultare le dichiarazioni d'intento, con calcolo del plafond residuo in tempo reale e blocco/avviso quando una vendita lo supererebbe.

### Fase 8 — Verifica end-to-end

Test a due livelli, stesso schema già in uso nel progetto per altri piani (vedi `PIANO - Refactoring Schede di Sicurezza (SDS)`, §7): Livello 1 (PHP reale, DB temporaneo, test di `qk_risolvi_aliquota_iva()` su tutti i rami dell'ordine di risoluzione), Livello 2 (browser reale, verifica che un cliente esente veda davvero 0% su un nuovo preventivo, che il plafond si scali correttamente, che un prodotto senza classificazione non rompa nulla).

## 8. Rischi ed eccezioni da non dimenticare

1. **Migrazione dati con valori anomali** (§Fase 2) — vedi sopra, va deciso esplicitamente, non lasciato a un default silenzioso.
2. **Doppio schema MySQL/SQLite disallineato** — rischio già noto e ricorrente in questo progetto (vedi Regola 7 `CLAUDE.md` e la voce "Schema doppio" già segnalata più volte nelle REVISIONI esistenti); qualunque migrazione va scritta e verificata su entrambi fin dal primo commit, non "poi allineo anche MySQL".
3. **Form prodotto già molto denso.** `app/views/products/_form.php` supera oggi le 1600 righe — aggiungere ulteriori `<select>` e sezioni (classificazione fiscale, eventualmente plafond) richiede attenzione a non peggiorare ulteriormente la leggibilità/manutenibilità del file, possibile occasione per valutare uno split in partial view più piccoli (fuori scope di questo piano, ma da segnalare).
4. **Codice natura IVA — tentazione di allinearsi comunque a SDI "per il futuro".** Da resistere esplicitamente: il vincolo §3.1 è una decisione permanente, non provvisoria. Un set di motivi di esenzione a 4 valori (§6.2) è sufficiente per l'uso interno; espanderlo a 20+ codici natura in stile FatturaPA sarebbe complessità importata da un requisito che questo progetto ha esplicitamente escluso.
5. **Prestazioni**: la funzione di determinazione (§6.4) verrebbe chiamata per ogni riga aggiunta a un documento — va scritta per restare una query semplice (indici su `aliquote_iva.codice`, su `dettagli_clienti.motivo_esenzione_iva`), non un giro di query multiple non necessarie.

## 9. Cosa NON è in scope di questo piano (per chiarezza, non per dimenticanza)

- Fatturazione elettronica reale, XML FatturaPA, invio SDI — esclusi per sempre (§3.1).
- Un motore di condizioni generico multi-dimensionale come SAP (`VK11` supporta N dimensioni combinabili) — per la scala di Quack, due dimensioni (prodotto × controparte) bastano.
- Vendite B2C / OSS — nessun caso d'uso noto oggi (§5.5).
- Multi-valuta e conversione — tema distinto, già in backlog a sé (voce 17 di `Backlog Sviluppi Futuri.md`).
- Reverse charge nazionale settoriale (edilizia, rottami ex art. 17 c.6 DPR 633/72) — non risulta rilevante per un rivenditore di prodotti chimici, non trattato.

## 10. Perché resta in backlog e non si fa ora

Tre motivi concreti, non solo "manca tempo":
1. **Il valore reale dipende da un dato che non abbiamo ancora** — quanti clienti esteri/con dichiarazione d'intento ha davvero Quack oggi (§5.1). Costruire la tabella aliquote + classificazione cliente + eventuale plafond senza saperlo sarebbe complessità anticipata senza un requisito confermato, esattamente il tipo di scelta che questo stesso backlog evita sistematicamente altrove (vedi voci 11, 17, 7).
2. **È un cambio di modello dati non banale**, non un'estensione additiva: cambia il tipo di tre colonne esistenti (da `DECIMAL` a FK), tocca entrambi gli schemi gemelli, richiede una migrazione dati con casi limite da decidere a mano.
3. **Il piano di livello intermedio (piano sorella) copre già i problemi più concreti verificati oggi** — il suggerimento IVA che non funziona mai, e un cliente segnato esente che comunque non ottiene IVA 0. Questo piano risolve un limite strutturale più profondo (nessuna storicizzazione delle aliquote, nessun plafond per le dichiarazioni d'intento), reale ma non ancora quantificato come urgente.

Da riprendere quando l'utente avrà risposto alle domande del §5 — a quel punto questo documento va aggiornato con le decisioni prese e trasformato in un piano eseguibile con lo stesso livello di dettaglio (checklist, fasi a microtappe) del piano di livello intermedio.
