# 01 — Ciclo Acquisti — Indirizzi e Contatti da RFQ a Ordine

> Questo documento sostituisce integralmente i tre file storici `Proposta Architettura Ibrida...Versione Integrata ERP.md` (F3, la versione più accurata e già quasi definitiva), `Proposta Architettura Ibrida...md` (F4, bozza precedente con nomi di campo generici/inglesi, mai realmente implementata così) e `proposta_gestione_indirizzi_contatti_ciclo_acquisti.md` (F5, la bozza più antica, "Snapshot & Override con Stato"). Il contenuto sotto è basato su F3, con l'architettura reale spiegata subito nel testo principale — non in blocchi di correzione a parte — e con ogni caso limite marcato fin dall'inizio come implementato o non implementato, verificato al 14/07/2026. I file originali restano disponibili, non eliminati, in `_originali_pre_riorganizzazione/`.
>
> Per un confronto con l'ispirazione di alto livello: questo pattern (fotografia immutabile dei dati anagrafici al momento della creazione di un documento, con rilevamento delle modifiche tramite hash) è lo stesso usato da SAP e Dynamics per il proprio "document snapshot" — l'unica citazione di best-practice esterna rilevante nei documenti originali, qui condensata in questa singola nota invece di un capitolo a parte.

---

## 1. L'architettura reale: Snapshot & Override con hash

Il ciclo acquisti (RFQ → Offerta → Ordine) **non** legge mai in tempo reale l'indirizzo o il contatto del fornitore da `clienti_fornitori`/`indirizzi` al momento della stampa. Al contrario, ogni volta che un documento viene creato o modificato, l'indirizzo e il contatto scelti vengono **fotografati** (copiati) in due tabelle dedicate:

- `indirizzi_documenti` — copia di un indirizzo al momento in cui è stato scelto per un documento.
- `contatti_documenti` — copia di un contatto (referente) al momento in cui è stato scelto per un documento.

Ogni riga fotografata porta con sé:

- `master_id` (o `contatto_master_id` per i contatti): l'id del record originale in anagrafica da cui la fotografia è stata presa — `NULL` se l'utente ha inserito un indirizzo/contatto "una tantum" mai esistito in anagrafica.
- `personalizzato`: flag che indica se, al momento del salvataggio, i dati fotografati coincidevano con l'anagrafica master (`0`) o erano stati modificati manualmente prima di salvare (`1`).
- `hash_originale`: hash SHA-256 dei campi rilevanti del master al momento della fotografia, usato per rilevare in seguito se l'anagrafica è cambiata rispetto a quando il documento è stato creato.

Le tabelle documento (`rfq_testata`, `offerte_testata`, `ordini_testata`, e sul lato vendite `preventivi`/`ordini_clienti`) non puntano mai direttamente a `clienti_fornitori`/`indirizzi` per i dati di indirizzo/contatto: puntano sempre a una riga di `indirizzi_documenti`/`contatti_documenti`. Cambiare l'indirizzo di un fornitore in anagrafica oggi non altera in alcun modo un documento già emesso in passato.

### 1.1 Come si decide se una fotografia è "personalizzata"

La logica di confronto (rilevamento dirty-check) è condivisa tra indirizzi e contatti tramite la funzione `qk_snapshot_stato_personalizzazione()` in `document_common.php` (introdotta il 14/07/2026 per eliminare una duplicazione quasi identica tra `qk_upsert_document_address()` e `qk_upsert_document_contact()`). Il comportamento, identico per entrambi i tipi:

1. Se non viene passato nessun `master_id` (indirizzo/contatto "una tantum"): `personalizzato = 1`, `hash_originale = null`.
2. Se viene passato un `master_id` ma il record master non viene trovato in anagrafica (es. eliminato nel frattempo): `personalizzato = 0`, `hash_originale = null` — comportamento preservato intenzionalmente identico all'originale pre-refactor.
3. Se il master viene trovato: si confronta l'hash dei campi correnti del master con l'hash dei dati che si sta per salvare; se coincidono, `personalizzato = 0`; se differiscono, `personalizzato = 1`.

### 1.2 Badge mostrati all'utente

L'interfaccia mostra tre stati distinti accanto a ogni indirizzo/contatto in un documento:

- `✓ Sincronizzato con anagrafica` — lo snapshot coincide con il master attuale.
- `Modificato localmente (override)` — lo snapshot è stato personalizzato rispetto al master.
- `Recapito manuale / una tantum` — non esiste alcun master collegato, il dato è stato inserito solo per questo documento.

---

## 2. Il ciclo di vita, fase per fase

### 2.1 Bozza RFQ

Alla creazione di una RFQ (`qk_rfq_save()`), l'indirizzo di consegna e il contatto del fornitore scelti vengono fotografati nella stessa transazione della riga RFQ — non esistono bozze "a metà" che lasciano tracce di snapshot prima ancora di essere salvate.

**Nota di comportamento reale, assente in F1/F2**: non esiste alcun suggerimento automatico dell'indirizzo di consegna in fase di RFQ (verificato al 10/07/2026) — la scelta è sempre esplicita da parte dell'utente.

### 2.2 RFQ Inviata

Una volta inviata, la RFQ è considerata "congelata": il suo snapshot di indirizzo/contatto non cambia più autonomamente.

### 2.3 Offerta da RFQ (Registra Preventivo)

Quando un fornitore risponde a una RFQ con un'offerta, ogni offerta ottiene una **propria copia indipendente** dello snapshot di indirizzo, non un riferimento condiviso a quello della RFQ.

**⚠️ Nota di verifica — F3 descriveva un'ereditarietà dinamica via `LEFT JOIN` che non esiste nel codice reale.** `qk_upsert_offerta()`, quando crea una nuova offerta collegata a una RFQ senza un indirizzo ad-hoc, legge lo snapshot già presente sulla RFQ e chiama `qk_upsert_document_address($pdo, $addrRow, null)` — passando `null` come id esistente, il che forza un `INSERT` nuovo, non un riuso. Il codice contiene un commento esplicito a difesa di questo pattern: *"[AI WARNING - ARCHITETTURA IBRIDA] Le FK puntano agli snapshot storici. Il DB live è stato allineato. NON toccare."* Il comportamento reale è più robusto di quanto documentato in origine: ogni offerta di ogni fornitore su una stessa RFQ è immune per costruzione a modifiche future allo snapshot della RFQ o di altre offerte concorrenti. Il contatto fornitore, invece, è per sua natura specifico dell'offerta fin dall'inizio (ogni fornitore ha il proprio referente), quindi non c'è ambiguità da chiarire su quel fronte.

### 2.4 Offerta Spontanea (senza RFQ)

Un'offerta può essere registrata anche senza una RFQ di partenza; in quel caso lo snapshot viene fotografato direttamente dall'anagrafica del fornitore al momento della creazione, con la stessa logica del punto 1.1.

### 2.5 Ordine (da offerta accettata)

Quando un'offerta viene accettata, l'ordine d'acquisto nasce con il proprio snapshot, fotografato a partire da quello dell'offerta di origine.

### 2.6 Order Amendment (nuova revisione dell'ordine)

Quando si crea una nuova versione di un ordine già esistente (es. cambia l'indirizzo di consegna dopo l'emissione), `qk_ordine_crea_revisione()` clona esplicitamente lo snapshot: legge la riga di indirizzo/contatto esistente, rimuove l'`id`, e fa un nuovo `INSERT` — una riga fisicamente distinta, non una modifica di quella esistente. Il commento di intestazione della funzione lo dichiara: *"Clona l'ordine incrementando la colonna versione, clona le righe, duplica gli snapshot degli indirizzi/contatti, e imposta lo stato dell'ordine precedente a 'superato'."* Il vecchio ordine mantiene inalterato il proprio riferimento allo snapshot originale, mai toccato — l'unica modifica è il cambio di stato a `'superato'`. Comportamento già corretto, coerente con come i grandi ERP gestiscono le revisioni: ogni versione preserva intatta quella precedente per finalità di audit.

**Nota terminologica**: gli ordini d'acquisto usano `superato` per lo stato del documento sostituito da una revisione più recente; le RFQ, per lo stesso concetto, usano `revisionata`. Entrambi partono da `versione = 1`. La differenza di terminologia tra i due flussi è preesistente e non è stata unificata in questa revisione.

---

## 3. Casi limite: cosa è implementato e cosa no

### 3.1 Caso Limite 1 — l'anagrafica master cambia dopo che una bozza è stata creata

❌ **Non implementato.** Se il fornitore aggiorna il proprio indirizzo in anagrafica mentre esiste già una RFQ in bozza che lo referenzia, la RFQ non viene notificata né aggiornata automaticamente: lo snapshot resta quello fotografato al momento della creazione, e l'hash memorizzato permetterebbe di rilevare la divergenza solo se qualcuno confrontasse esplicitamente `hash_originale` con un nuovo hash calcolato al volo — nessun punto del codice lo fa oggi in automatico per mostrare un avviso "l'anagrafica è cambiata da quando hai creato questa bozza".

### 3.2 Caso Limite 2 — il fornitore cambia a documento già in corso

✅ **Implementato, e in modo più corretto di quanto la documentazione originale suggerisse.** Se l'utente cambia il fornitore selezionato su una RFQ in corso, il codice resetta il contatto fotografato (dato che il nuovo fornitore ha referenti diversi), ma **non** resetta l'indirizzo di consegna — correttamente, perché l'indirizzo di consegna in una RFQ d'acquisto è un indirizzo nostro (dove vogliamo ricevere la merce), non del fornitore, quindi cambiare fornitore non lo rende obsoleto.

### 3.3 Caso Limite 3 — pulizia periodica degli snapshot orfani

❌ **Non implementato**, ma con la causa concreta individuata (approfondimento richiesto esplicitamente il 14/07/2026).

**Definizione di "orfano"**: una riga di `indirizzi_documenti`/`contatti_documenti` il cui `id` non è più referenziato da nessuna delle cinque tabelle che possono puntarci (`preventivi`, `ordini_clienti` lato vendite; `rfq_testata`, `offerte_testata`, `ordini_testata` lato acquisti).

**Fonte concreta verificata**: l'unica funzione nel codice che elimina fisicamente un documento già salvato è `qk_rfq_delete()`, che permette di eliminare una RFQ ancora in stato `bozza` senza offerte collegate. Questa funzione cancella la riga `rfq_testata` ma non tocca mai `indirizzi_documenti`/`contatti_documenti` — ogni volta che un utente elimina una RFQ bozza creata per errore (azione normale e prevista), il suo snapshot resta orfano nel database per sempre. Non esiste una funzione equivalente per preventivi, ordini clienti o ordini d'acquisto, quindi `qk_rfq_delete()` è oggi l'unica fonte concreta verificata di orfani.

**Caso collegato ma distinto — offerte rifiutate**: quando un'offerta viene marcata `rifiutata` (manualmente, o in automatico quando un'altra offerta sulla stessa RFQ viene accettata), il suo snapshot non viene mai toccato: resta referenziato dall'offerta rifiutata, quindi non è "orfano" in senso stretto, ma è comunque un dato che non servirà più a nulla — probabilmente la fetta più consistente di dati da ripulire in un ciclo RFQ multi-fornitore (per ogni RFQ evasa esistono tipicamente N−1 offerte scartate).

**Soluzione pianificata, in due livelli** (da implementare a breve, non rimandata in backlog):

1. **Prevenzione alla fonte**: correggere `qk_rfq_delete()` perché elimini, nella stessa transazione, anche gli snapshot che la RFQ referenziava — sicuro perché il controllo esistente (nessuna offerta collegata) garantisce che nessun'altra tabella possa già puntarci.
2. **Pulizia periodica come rete di sicurezza** (per gli orfani già accumulati e per fonti future non ancora identificate): richiede prima una migrazione che aggiunga `created_at` a entrambe le tabelle di snapshot (assente oggi, necessaria per applicare un margine di sicurezza temporale), poi uno script schedulato con criteri distinti: eliminazione diretta per i veri orfani, eliminazione con soglia di retention per le offerte rifiutate (per non compromettere eventuali contestazioni).

---

## 4. Asimmetria vendite/acquisti sul "Salva in Anagrafica"

Il ciclo vendite (preventivi) offre un'opzione per salvare in anagrafica un indirizzo inserito "una tantum" durante la creazione di un documento, così da poterlo riutilizzare in futuro. **Questa opzione non ha un equivalente sul lato acquisti**: un indirizzo/contatto una tantum inserito su una RFQ o un'offerta resta legato solo a quel documento, senza un percorso UI per promuoverlo ad anagrafica permanente del fornitore. Non è un bug — è un'asimmetria di funzionalità tra i due lati del ciclo, utile da avere presente per non aspettarsi lo stesso comportamento in entrambe le direzioni.

Per il riferimento residuo di alcune FK dirette (non-snapshot) ancora presenti lato vendite, vedi il documento [`02 - Dettaglio Tecnico`](02%20-%20Dettaglio%20Tecnico%20-%20Schema%20DB%20e%20Codice%20(Anagrafiche).md), §3.7.

---

## 5. Dove vedere il comportamento reale dell'indirizzo lato vendite

Il ciclo vendite (preventivi/ordini clienti) **non** usa lo stesso schema di snapshot con hash descritto sopra per la risoluzione dell'indirizzo di fatturazione/spedizione in fase di creazione — usa un algoritmo di risoluzione più semplice, descritto in [`00 - Anagrafica Unica, Indirizzi e Aziende Interne`](00%20-%20Anagrafica%20Unica%2C%20Indirizzi%20e%20Aziende%20Interne%20-%20Guida%20Completa.md), §4. Le due logiche non vanno confuse: quella descritta in questo documento (snapshot con hash) si applica al ciclo acquisti dalla RFQ all'ordine; quella del documento `00` si applica alla scelta dell'indirizzo su un preventivo/ordine cliente.
