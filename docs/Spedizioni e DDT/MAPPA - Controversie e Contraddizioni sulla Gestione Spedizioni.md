# MAPPA — Controversie e Contraddizioni sulla Gestione Spedizioni (e dintorni nel Ciclo Vendite)

**Cos'è questo documento**: una rassegna di tutte le incoerenze trovate confrontando, riga per riga, i documenti che oggi descrivono come funziona (o dovrebbe funzionare) la Spedizione in Quack_ERP — in `docs/Spedizioni e DDT/`, `docs/Ciclo Vendite/` e `docs/Gestione Magazzino e Lotti/` — più una verifica fresca contro il codice reale per ogni punto controverso, per capire quale versione è quella vera oggi (11/08/2026). Serve come base per prendere, in futuro, una decisione unica su come deve funzionare la Spedizione — non prende quella decisione da solo (regola di progetto, `CLAUDE.md` punto 8).

**Nessuna modifica al codice è stata fatta scrivendo questo documento.** È solo una mappa.

---

## 0. Come leggere questo documento se non hai memoria di come è nato

Quack_ERP ha accumulato, nel tempo, diversi documenti che descrivono lo stesso argomento — la Spedizione, cioè il momento in cui la merce esce fisicamente dal magazzino verso il cliente e viene emesso il DDT (Documento Di Trasporto) — scritti in momenti diversi, da prospettive diverse (chi guardava il Ciclo Vendite, chi guardava il Ciclo Magazzino), senza che l'uno controllasse sempre cosa diceva l'altro. Il risultato è che oggi esistono affermazioni tra loro incompatibili su domande di fondo come "la Spedizione è già un argomento chiuso o è ancora in discussione?" — e almeno un caso in cui un documento "ufficiale" riporta come vero un bug che in realtà era già stato corretto giorni prima che quel documento venisse scritto.

Questo file elenca ogni caso trovato, con la citazione precisa (file e riga) di ciascuna delle affermazioni in conflitto, più una verifica fatta ora, leggendo il codice reale, per stabilire quale versione riflette la realtà attuale.

I documenti confrontati:

| Documento | Cartella | Data dichiarata | Argomento |
|---|---|---|---|
| `Ciclo Vendite - Flusso Operativo.md` | `docs/Ciclo Vendite/` | 26/07/2026 | Comportamento end-to-end Preventivo→Ordine→Spedizione→DDT→Fatturazione |
| `Ciclo Vendite - Schema Dati e API.md` | `docs/Ciclo Vendite/` | 26/07/2026 | Schema DB e inventario funzioni dello stesso ciclo |
| `Compliance - Restrizioni di Vendita.md` | `docs/Ciclo Vendite/` | 26/07/2026 (estratto), area dichiarata "ancora in studio" | Restrizioni normative di vendita — letto per completezza, non riguarda la Spedizione (nessun riferimento a spedizioni/DDT trovato) |
| `Spedizione - Note di Studio e Decisioni Aperte.md` | `docs/Spedizioni e DDT/` | Nato il 19/07/2026, mai chiuso | Se la creazione spedizione debba avere due fasi (bozza/conferma) invece di una |
| `Ciclo Magazzino - Flusso Operativo.md` | `docs/Gestione Magazzino e Lotti/` | 19/07/2026 | Comportamento end-to-end del ciclo magazzino, con una sezione (§9) sull'interfaccia verso la Spedizione |
| `Backlog Sviluppi Futuri.md`, voci 34/35/36 | `docs/` | Varie (22/07-26/07/2026) | Reso cliente/correzione spedizione, spedizione multi-indirizzo, registro fatturazione |
| `PIANO - Architettura UX Preventivo-Ordine-Spedizione.md` | `docs/Spedizioni e DDT/` | 11/08/2026 (scritto da me, turno precedente di questa stessa sessione) | Proposta UX sui bottoni della pagina Ordine |

---

## 1. LA CONTRADDIZIONE CENTRALE: la Spedizione è un argomento chiuso o ancora aperto?

> **RISOLTO dall'utente l'11/08/2026: la Spedizione è ancora un argomento apertamente in discussione — non chiusa.** Decisione applicata: `Ciclo Vendite - Flusso Operativo.md` §4 ora porta un avviso esplicito in testa alla sezione Spedizione, con link a `Spedizione - Note di Studio e Decisioni Aperte.md` (collegamento che prima non esisteva affatto da questo lato). Nessuna riscrittura del contenuto tecnico di §4/§5 (resta la fotografia corretta di cosa fa il codice oggi) — solo lo *status* è cambiato da "fatto consolidato" a "provvisorio, in discussione".

Questa è la contraddizione più importante trovata, ed è la ragione diretta per cui questo documento esiste.

**Versione A — "è ancora tutto da decidere"**, sostenuta da `Spedizione - Note di Studio e Decisioni Aperte.md` (righe 1-4):

> "**La Spedizione, come ciclo/fase dell'ERP, è ancora in fase di studio e definizione** — non è chiaro se debba essere un ciclo a sé stante o una sotto-fase del Ciclo Vendite. Questo documento raccoglie dubbi, punti chiave, domande aperte e decisioni prese man mano [...] Non è ancora una guida definitiva né una specifica pronta per l'implementazione."

E più esplicitamente ancora, alla fine dello stesso documento (§5, riga 62):

> "Nessuna implementazione di codice per questa funzionalità finché: (a) il dubbio del §3 [...] non è sciolto, e (b) le domande del §4 non hanno almeno una risposta di massima."

Questa posizione è **confermata e ripetuta**, sette giorni dopo la sua nascita, in `Ciclo Magazzino - Flusso Operativo.md` (documento datato 19/07/2026 — lo stesso giorno in cui è nato il dubbio), §9, riga 296:

> "La Spedizione come fase/ciclo dell'ERP è ancora in fase di studio e definizione (non è deciso se sia una sotto-fase del Ciclo Vendite o un ciclo a sé stante) — tutti i dubbi [...] sono raccolti in [Note di Studio e Decisioni Aperte]. Questa sezione descrive solo l'interfaccia verso magazzino/giacenze/lotti [...] non il flusso interno della spedizione stessa."

Nota come il documento sul Magazzino sia scritto con cura per **non pronunciarsi** sul flusso interno della Spedizione proprio perché sa che è ancora in discussione altrove.

**Versione B — "è già tutto definito e verificato"**, sostenuta da `Ciclo Vendite - Flusso Operativo.md` (documento datato 26/07/2026 — **una settimana dopo** che il dubbio era già stato sollevato e messo per iscritto). La sezione §4 ("Fase C — Spedizione") descrive il flusso a singolo passaggio atomico come fatto compiuto, verificato, definitivo:

> "Un ordine può essere evaso in un'unica spedizione completa, oppure in più spedizioni parziali nel tempo [...]" (§4.1, riga 137)

Nessuna riga di questa sezione — né §4.1, né §4.2 "Creazione", né alcuna delle sottosezioni fino a §4.7 — menziona che il flusso descritto è oggetto di un dubbio esplicito e ancora aperto altrove nel progetto. Non c'è nessun rimando a `Spedizione - Note di Studio e Decisioni Aperte.md`, nessuna nota "questo potrebbe cambiare". Il documento intero apre dichiarando (riga 3) di descrivere "il comportamento reale del codice a oggi [...] verificato riga per riga" — un'affermazione tecnicamente vera (il codice fa davvero quello che descrive), ma che **passa sotto silenzio il fatto che quel comportamento è esplicitamente etichettato altrove come provvisorio, in attesa di una decisione di design ancora da prendere.**

**Perché è una contraddizione reale, non solo una questione di enfasi**: chi legge solo `Ciclo Vendite - Flusso Operativo.md` (il documento "principale" del ciclo vendite, quello con il nome più ovvio da cercare) non ha alcun modo di sapere che la sezione Spedizione che sta leggendo come fatto consolidato è, secondo un altro documento scritto nello stesso periodo, tutt'altro che consolidata. Le due fonti non si contraddicono su un dettaglio tecnico — si contraddicono sulla domanda più a monte di tutte: "posso fidarmi di questa descrizione come punto di partenza stabile, o è provvisoria?"

**Verifica fatta ora contro il codice reale (11/08/2026)**: ho verificato che il comportamento a singolo passaggio descritto da `Ciclo Vendite - Flusso Operativo.md` è ancora esattamente quello in produzione oggi — `app/Repositories/SpedizioneRepository.php`, righe 71-72:

```php
INSERT INTO spedizioni (numero_spedizione, ordine_id, data_spedizione, indirizzo_spedizione_id, magazzino_id, vettore, tracking, stato, creato_da, created_at)
VALUES (?, ?, CURRENT_DATE, ?, ?, ?, ?, 'spedita', ?, CURRENT_TIMESTAMP)
```

Lo stato `'spedita'` viene scritto **direttamente e letteralmente** nell'INSERT — lo stato `'preparazione'` (che pure esiste come default di colonna nello schema, `db/init_sqlite.sql`, ed è citato nel CHECK) non viene mai scritto da nessun punto del codice applicativo. Questo conferma, con lettura fresca del codice, che il fatto tecnico di fondo descritto da entrambi i documenti è vero e concorde — **la vera contraddizione non è sul fatto, ma sul suo status**: "fatto acquisito" per Ciclo Vendite, "provvisorio, da rivedere" per Note di Studio/Ciclo Magazzino.

**Non è compito di questo documento risolvere quale versione prevale.** È materiale per una decisione tua: o si conferma esplicitamente che il flusso a singolo passaggio è quello definitivo (e allora `Spedizione - Note di Studio e Decisioni Aperte.md` va aggiornato per dirlo, chiudendo il dubbio), oppure si riapre la discussione sulle due fasi (e allora `Ciclo Vendite - Flusso Operativo.md` §4 andrebbe corretto per riflettere che è provvisorio, non definitivo.

---

## 2. Link rotto tra i due documenti che dovrebbero essere collegati

> **RISOLTO l'11/08/2026.** Link corretto in `Ciclo Magazzino - Flusso Operativo.md` riga 296 (ora punta a `docs/Spedizioni e DDT/...`, non più a `docs/Spedizione/...`). Confermato con l'utente l'assetto attuale della documentazione: tutto ciò che riguarda **solo** la Spedizione vive in `docs/Spedizioni e DDT/`; il Ciclo Vendite (`docs/Ciclo Vendite/`) ne parla "in parte", cioè dentro il proprio flusso end-to-end più ampio (Preventivo→Ordine→Spedizione→DDT→Fatturazione), senza duplicare i dettagli. Coerente con questo, `Ciclo Vendite - Flusso Operativo.md` §4 ora ha anch'esso un link verso `docs/Spedizioni e DDT/Spedizione - Note di Studio e Decisioni Aperte.md` (vedi punto 1 sopra) — prima non esisteva da quel lato, solo dal lato Magazzino (e pure rotto).

`Ciclo Magazzino - Flusso Operativo.md`, riga 296, prova a rimandare esplicitamente a `Spedizione - Note di Studio e Decisioni Aperte.md` con un link relativo:

```markdown
[`docs/Spedizione/Spedizione - Note di Studio e Decisioni Aperte.md`](../Spedizione/Spedizione%20-%20Note%20di%20Studio%20e%20Decisioni%20Aperte.md)
```

Il link punta alla cartella `docs/Spedizione/` (al singolare, senza "e DDT"). **Quella cartella non esiste.** Il file vive davvero in `docs/Spedizioni e DDT/Spedizione - Note di Studio e Decisioni Aperte.md` (verificato: è il file letto e citato nella sezione 1 sopra). La cartella è stata evidentemente rinominata da "Spedizione" a "Spedizioni e DDT" in un momento successivo alla scrittura di questo link, senza aggiornare il link stesso — chi clicca oggi quel collegamento da dentro la documentazione del Ciclo Magazzino trova un errore "file non trovato", non il documento vero.

**Correzione meccanica, quando si deciderà di farla**: aggiornare la riga 296 di `Ciclo Magazzino - Flusso Operativo.md` sostituendo `../Spedizione/Spedizione%20-%20Note%20di%20Studio%20e%20Decisioni%20Aperte.md` con `../Spedizioni%20e%20DDT/Spedizione%20-%20Note%20di%20Studio%20e%20Decisioni%20Aperte.md`. Non è una correzione di contenuto, solo di percorso — segnalata qui e non applicata perché questo documento è solo una mappa, non un intervento (vedi premessa).

---

## 3. "Completa Ordine" solo manuale: non è una lacuna, è una scelta già presa e documentata

> **RISOLTO/RICONFERMATO dall'utente l'11/08/2026: "Confermo scelta manuale per ora."** L'Opzione A del mio PIANO (bottone manuale, condizionato in base allo stato) è quella da seguire; l'Opzione B (chiusura automatica) resta esplicitamente scartata, non solo "da valutare". Aggiornato `PIANO - Architettura UX Preventivo-Ordine-Spedizione.md` §10 punto 1 e §6 Passo 6 per riflettere la decisione chiusa, non più aperta.

Questo punto **corregge/precisa** qualcosa scritto nel mio stesso `PIANO - Architettura UX Preventivo-Ordine-Spedizione.md` (turno precedente di questa sessione), §10, punto 1 — dove avevo presentato "l'ordine si completa da solo in automatico a fine spedizione" (Opzione B) come una possibile evoluzione futura, "più invasiva" ma neutra.

Rileggendo `Ciclo Vendite - Flusso Operativo.md` §7 (riga 230), emerge che questo non è terreno neutro:

> "**`completato` solo manuale**: nessun automatismo passa un ordine `spedito` a `completato` — azione esplicita dell'operatore (§2.2), **mirror del "chiudi" lato Acquisti**."

Questa frase dice due cose che il mio PIANO non aveva: primo, che l'assenza di automatismo è una **scelta deliberata**, non una lacuna dimenticata; secondo, che è scelta apposta per restare **coerente con lo stesso pattern già in uso nel Ciclo Acquisti** (dove un ordine fornitore si "chiude" allo stesso modo, sempre con un click esplicito, mai in automatico).

**Conseguenza pratica**: la mia Opzione B (automazione piena) non è solo "più complessa da costruire" come avevo scritto — è in **contrasto diretto con una decisione di coerenza cross-modulo già presa**. Costruirla richiederebbe non solo nuova logica in Vendite, ma probabilmente riaprire la stessa domanda anche per gli Ordini Fornitore lato Acquisti, per non spezzare la coerenza che questa frase dichiara di voler proteggere. Non è più, quindi, una scelta isolata da valutare sul solo Ciclo Vendite.

**Cosa NON cambia in questa mappa**: il mio PIANO resta valido nella sua Opzione A (bottone manuale, ma condizionato/rinominato in base allo stato — "Segna Ordine Come Completato" solo a spedizione conclusa, "Chiudi Ordine Senza Spedire il Resto" solo se c'è residuo) — quell'opzione è pienamente compatibile con il "solo manuale, mai automatico" che questo documento conferma essere la linea guida dichiarata del progetto. Solo l'Opzione B, nel mio piano precedente, va vista sotto una luce più cauta di quanto scritto lì.

---

## 4. Un'affermazione di bug "non ancora corretto" che in realtà era già stata corretta prima di essere scritta

> **RISOLTO l'11/08/2026, con verifica a runtime (non solo a lettura).** Su richiesta esplicita dell'utente ("controlla cosa fa davvero il codice, quella è l'unica fonte di verità"), ho **eseguito per davvero** `tests/manual/suite_transition_guard_tappa6_preventivi.php` con un interprete PHP reale (`tools/dev-php-sandbox/`) contro un database SQLite temporaneo seminato dallo schema vero — non una lettura statica del codice o dei commenti. Risultato: **27 assert, 0 falliti**, inclusi 6 assert dedicati che chiamano `qk_preventivo_nuova_versione()` da `inviato` e `rifiutato` e confermano un numero sempre diverso dall'originale. Il bottone "Crea Nuova Versione" funziona, con certezza, oggi. Corretti di conseguenza: `Ciclo Vendite - Flusso Operativo.md` §1.7, `Ciclo Vendite - Schema Dati e API.md` (righe 18 e 244) — nessun altro documento del progetto citava questo bug (verificato con grep sul Backlog, nessun risultato).

Questa è la scoperta più concreta di tipo "documento sbagliato, non solo scollegato".

`Ciclo Vendite - Flusso Operativo.md`, §1.7 (riga 63), scrive testualmente:

> "**Bug noto, non ancora corretto**: la funzione copia anche `numero_preventivo` (colonna `UNIQUE`) nel nuovo INSERT prima di rinominarlo — fallisce sempre con `UNIQUE constraint failed`. Il bottone "Crea Nuova Versione" è oggi **non funzionante**."

La stessa affermazione è ripetuta in `Ciclo Vendite - Schema Dati e API.md`, riga 18 (commento sulla colonna `numero_preventivo`: "vedi §1.7 Flusso Operativo: bug noto su nuova versione") e riga 244 (tabella funzioni: "**bug noto**, vedi Flusso Operativo §1.7"). Entrambi i documenti sono datati 26/07/2026 e dichiarano di essere stati "verificati con lettura diretta del codice reale in questa sessione".

**Verifica fatta ora, leggendo il codice reale**: `includes/sales_quotes.php`, funzione `qk_preventivo_nuova_versione()`, righe 862-868, contiene questo commento nel codice stesso:

> "BUG PREESISTENTE corretto qui (segnalato e approvato dall'utente, **22/07/2026**): prima questo INSERT...SELECT copiava TUTTE le colonne (tranne id) dal preventivo originale, incluso `numero_preventivo` — che ha vincolo UNIQUE. L'INSERT falliva SEMPRE con "UNIQUE constraint failed" [...] Ora le colonne che devono avere un valore diverso dall'originale [...] vengono scritte già corrette in un unico INSERT."

E la correzione è confermata da un test automatico reale e passante, `tests/manual/suite_transition_guard_tappa6_preventivi.php`, righe 165-178, che chiama davvero `qk_preventivo_nuova_versione()` da stati `inviato`/`rifiutato` e verifica che il nuovo preventivo venga creato con un numero diverso dall'originale (niente più collisione UNIQUE).

**La cronologia è inequivocabile**: il bug è stato corretto il **22/07/2026**. I due documenti che lo dichiarano "non ancora corretto" sono stati scritti/dichiarati verificati il **26/07/2026** — quattro giorni dopo. L'affermazione era già falsa nel momento stesso in cui è stata scritta. La spiegazione più probabile è che il testo di quella sezione sia stato copiato da una bozza precedente (forse dal documento sorgente superato, "Manuale_Definitivo_Ciclo_Vendite.md", citato come fonte da non usare più nell'intestazione dello stesso Flusso Operativo) senza un ricontrollo fresco su questo punto specifico, nonostante la dichiarazione generale di verifica riga-per-riga in testa al documento.

**Stato reale oggi (11/08/2026), verificato di nuovo in questa sessione**: il bottone "Crea Nuova Versione" sui preventivi **funziona**. Il bug descritto in due documenti come "noto e non corretto" è chiuso da tre settimane.

---

## 5. Riferimenti a documenti-fonte che non esistono più

Sia `Ciclo Vendite - Flusso Operativo.md` (§7, riga 234) sia `Ciclo Vendite - Schema Dati e API.md` (riga 3) sia due voci del Backlog (34 e 35) citano come fonte due documenti — `REVISIONE - Analisi Documentazione Ciclo Vendite.md` e `AUDIT 4 - Controllo Generale Problemi Aperti (25-07-2026).md` — che **non esistono più in nessun punto del repository** (verificato con due metodi di ricerca distinti: pattern esatto e pattern generico su tutta la cartella `docs/`, nessuno dei due li trova).

Non è necessariamente un errore: è plausibile che questi documenti "di lavoro" (audit/revisioni temporanee) siano stati intenzionalmente archiviati o rimossi una volta che il loro contenuto è stato travasato nei documenti definitivi (`Flusso Operativo`, `Schema Dati e API`) — pattern già visto altrove nel progetto per i documenti `PIANO - *.md` che vengono marcati "STATO: ESEGUITO" invece di essere cancellati. Ma qui, a differenza dei `PIANO` eseguiti, i documenti sono **spariti del tutto**, e i riferimenti che li citavano come fonte non sono stati rimossi insieme a loro: restano link a un nulla per chiunque provi a risalire all'origine di un'affermazione (per esempio, il "punto 8" di AUDIT 4 citato come spiegazione del perché è stata fatta la riscrittura documentale, o il "punto 7" citato come prova del test HTTP reale sul caso "lotto non disponibile").

**Segnalato, non corretto qui** — decidere se vale la pena recuperare quei documenti da una versione precedente del repository/history git (se recuperabili) o se i riferimenti vanno semplicemente rimossi dai documenti che li citano.

---

## 6. Backlog voce 34 (reso/correzione spedizione) e Note di Studio si sovrappongono senza saperlo

`Backlog Sviluppi Futuri.md`, voce 34 ("Reso Cliente e Correzione di una Spedizione già Confermata", righe 664-687) descrive il problema "non c'è modo di correggere una spedizione sbagliata dopo che è stata confermata" e propone, come lavoro futuro, una nuova azione dedicata "Registra reso/rettifica".

`Spedizione - Note di Studio e Decisioni Aperte.md` propone, separatamente, uno stato intermedio `preparazione` (bozza, senza alcun impatto su magazzino/DDT) prima della conferma vera e propria — con la conseguenza esplicita, dichiarata al punto 3 di quel documento, che una spedizione ancora in bozza sarebbe "annullabile gratis" perché non ha ancora toccato nulla.

**Il punto di sovrapposizione, mai reso esplicito in nessuno dei due documenti**: se venisse implementato lo stato `preparazione` proposto da Note di Studio, una parte del problema descritto dalla voce 34 del backlog (l'errore scoperto **subito dopo** la creazione, prima che sia davvero definitivo) sparirebbe da sé — non servirebbe più un flusso di "correzione", basterebbe annullare la spedizione ancora in bozza e rifarla. Il problema della voce 34 **non sparirebbe del tutto** (un vero reso del cliente, spedizione già arrivata a destinazione, resta un caso a sé, indipendente dalle due fasi), ma la sua superficie si ridurrebbe. Nessuno dei due documenti lo dice: sono scritti come se il problema dell'altro non esistesse, quando in realtà una parte delle "domande aperte" del punto 4.3 di Note di Studio ("come si annulla una lista di prelievo mai confermata?") e una parte della voce 34 del backlog ("errore operativo scoperto subito dopo la conferma") descrivono, in fondo, situazioni molto vicine.

**Non è una contraddizione di fatti — è una mancata sintesi.** Utile da sapere prima di decidere l'una o l'altra proposta separatamente, per non finire a costruire due soluzioni parzialmente ridondanti in tempi diversi.

---

## 7. Riepilogo — un solo colpo d'occhio

| # | Cosa | Dove | Gravità | Stato (11/08/2026) |
|---|---|---|---|---|
| 1 | Spedizione "chiusa/verificata" (Ciclo Vendite) vs "ancora in studio" (Note di Studio + Ciclo Magazzino) | `Ciclo Vendite - Flusso Operativo.md` §4 vs `Spedizione - Note di Studio...md` + `Ciclo Magazzino - Flusso Operativo.md` §9 | Alta | **RISOLTO** — utente conferma: ancora aperto. Avviso e link aggiunti in Ciclo Vendite §4 |
| 2 | Link rotto verso Note di Studio (cartella rinominata) | `Ciclo Magazzino - Flusso Operativo.md` riga 296 | Bassa | **RISOLTO** — link corretto |
| 3 | "Completa solo manuale" è deliberato, non neutro (precisazione al mio PIANO precedente) | `Ciclo Vendite - Flusso Operativo.md` §7 vs mio `PIANO...md` §10 | Media | **RISOLTO** — utente conferma: manuale, per ora. PIANO aggiornato |
| 4 | Bug "Crea Nuova Versione" dichiarato aperto ma già chiuso da 4 giorni prima della dichiarazione | `Ciclo Vendite - Flusso Operativo.md` §1.7 + `Schema Dati e API.md` righe 18/244 vs `includes/sales_quotes.php` righe 862-868 + test eseguito | Alta | **RISOLTO** — riverificato a runtime (27/27 assert), documenti corretti |
| 5 | Documenti-fonte citati ma non più esistenti nel repo | `Flusso Operativo` §7, `Schema Dati e API` riga 3, Backlog voci 34/35 | Bassa | **APERTO** — non affrontato in questo turno, nessuna decisione richiesta esplicitamente |
| 6 | Backlog voce 34 e Note di Studio si sovrappongono senza saperlo | `Backlog...md` voce 34 vs `Spedizione - Note di Studio...md` §4.3 | Media | **APERTO** — richiede una decisione di merito (vedi §8), non solo una correzione meccanica |

---

## 8. Cosa serve da te per andare avanti — aggiornato l'11/08/2026

I punti 1, 2, 3, 4 sono stati chiusi in questo stesso turno (vedi le note "RISOLTO" nelle sezioni sopra e la tabella §7). Restano aperti solo due punti, nessuno dei due bloccante per il resto del lavoro già fatto:

1. **Il punto 6** (backlog voce 34 e Note di Studio si sovrappongono): ora che è confermato che la Spedizione resta un argomento aperto (punto 1), questa domanda diventa concreta e non più ipotetica — se si deciderà di riaprire la proposta a due fasi di Note di Studio, vale la pena unificarne la progettazione con la voce 34 del backlog (reso/correzione), invece di trattarle come due lavori separati che finirebbero per sovrapporsi? Nessuna decisione presa qui, resta da affrontare quando/se si riprenderà in mano il disegno della Spedizione nel suo complesso.
2. **Il punto 5** (riferimenti a documenti-fonte spariti — `REVISIONE - Analisi Documentazione Ciclo Vendite.md`, `AUDIT 4...md`): correzione meccanica di bassa priorità, non richiede una decisione di merito — solo un via libera a rimuovere quei riferimenti morti (o a recuperare i documenti, se recuperabili) quando ti fa comodo.

Nessun'altra contraddizione aperta tra i documenti di questa mappa e il `PIANO - Architettura UX Preventivo-Ordine-Spedizione.md` — vedi la risposta diretta data in chat per il dettaglio punto per punto.
