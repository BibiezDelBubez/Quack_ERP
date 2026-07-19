# Spedizione — Note di Studio e Decisioni Aperte

> Questo file nasce durante la revisione della documentazione "Gestione Magazzino e Lotti" (2026-07-19), quando è emerso che il "picking" (prelievo fisico della merce) manca come fase distinta dalla creazione della spedizione. **La Spedizione, come ciclo/fase dell'ERP, è ancora in fase di studio e definizione** — non è chiaro se debba essere un ciclo a sé stante o una sotto-fase del Ciclo Vendite. Questo documento raccoglie dubbi, punti chiave, domande aperte e decisioni prese man mano, per non disperderli. Non è ancora una guida definitiva né una specifica pronta per l'implementazione.

---

## 1. Il problema di partenza: manca una fase di "picking" separata dalla conferma

Verificato durante la revisione Magazzino e Lotti: oggi in Quack la creazione di una spedizione è **un solo passaggio atomico**. L'operatore apre "Nuova Spedizione" per un ordine cliente, il sistema propone in automatico i lotti da prelevare (FEFO), l'operatore può correggere le quantità, e alla conferma — in un solo click — il sistema:

1. Scarica `giacenze`/`lotti_giacenze` per ogni riga.
2. Genera il DDT (`documenti_trasporto` + righe).
3. Registra i movimenti di magazzino.
4. Segna la spedizione come `spedita`.

Non esiste alcun momento intermedio in cui una "lista di prelievo" viene generata (per essere stampata e portata fisicamente a scaffale) **senza** che questo tocchi già la giacenza — e non esiste alcun momento in cui una persona diversa da chi ha preparato la merce possa rivedere/confermare cosa è stato davvero prelevato prima che il DDT venga emesso.

**Indizio interessante trovato nello schema**: `spedizioni.stato` è già definito con l'enum `CHECK (stato IN ('preparazione', 'spedita', 'consegnata', 'reso'))` (`db/init_sqlite.sql`) — lo stato `'preparazione'` **esiste nello schema ma non viene mai scritto dal codice**: `SalesOrdersController::elaboraCreaSpedizione()` inserisce sempre direttamente `stato = 'spedita'`, saltando `'preparazione'`. Questo suggerisce che un flusso a due fasi fosse già previsto nel disegno originale del database, ma non sia mai stato completato lato applicativo — non è quindi un'idea nuova calata dall'esterno, è probabilmente il completamento di un'intenzione di design preesistente.

---

## 2. Decisione presa (parziale): Opzione 2 — un vero stato intermedio, non solo un bottone "stampa"

Discusse due alternative:

- **Opzione 1 (scartata)**: aggiungere solo un pulsante "Stampa Lista di Prelievo" sulla schermata attuale, senza persistere nulla di nuovo — chi prepara e chi conferma restano nella stessa sessione/schermata.
- **Opzione 2 (scelta)**: la spedizione nasce come bozza in stato `preparazione` quando si genera la lista di prelievo — **nessun movimento di giacenza, nessun DDT** a questo stadio. Resta visibile come "da confermare" finché una persona (eventualmente diversa, eventualmente in un momento diverso) non apre quella spedizione specifica, verifica/corregge le quantità in base a cosa è stato realmente prelevato, e conferma — solo a quel punto scarica la giacenza e genera il DDT.

Motivazione: nel caso d'uso reale che descrivi, prelievo e conferma possono essere fatti da persone diverse, in momenti diversi — l'Opzione 1 non lo permetterebbe.

**Precedente diretto da riusare**: lo stesso schema già validato per i trasferimenti tra magazzini (`trasferimenti_magazzino`: `bozza → in_transito → completato`, con `quantita_richiesta` vs `quantita_arrivata` per gestire le discrepanze tra cosa si intendeva muovere e cosa è realmente arrivato). La spedizione a due fasi è concettualmente lo stesso pattern: `quantita proposta` (lista di prelievo) vs `quantita confermata` (cosa è stato davvero prelevato).

---

## 3. Il dubbio di fondo, non ancora risolto: la Spedizione è un ciclo a sé o parte di Vendite?

Prima di disegnare nel dettaglio le due fasi (lista di prelievo → conferma), va deciso dove "vive" concettualmente la Spedizione:

- **Ipotesi A**: la Spedizione è una sotto-fase del Ciclo Vendite (Preventivo → Ordine → **Spedizione** → Fatturazione), come sembra oggi dal codice (`SalesOrdersController::elaboraCreaSpedizione()`, azione dentro il controller ordini).
- **Ipotesi B**: la Spedizione diventa un ciclo/modulo a sé stante, con un proprio controller/namespace dedicato (`SpedizioniController` esiste già, ma oggi è quasi solo lettura — la scrittura vera è ancora dentro `SalesOrdersController`), più vicino concettualmente al modulo Magazzino (dato che tocca giacenze/lotti/movimenti) che al modulo Vendite.

Questa scelta influenza direttamente il disegno della fase di picking: se la Spedizione resta dentro Vendite, la lista di prelievo è "un'appendice dell'ordine"; se diventa un ciclo a sé, ha più senso una vera coda "Spedizioni da preparare / da confermare" con vita propria, magari con permessi distinti da chi gestisce gli ordini.

**Non ancora deciso.** Da affrontare prima di scrivere qualunque specifica di dettaglio.

---

## 4. Domande aperte, da sciogliere prima di implementare

1. **Permessi**: chi può generare la lista di prelievo e chi può confermarla — stesso permesso (`manage_warehouse`, quello già usato oggi) o due permessi distinti (per separare davvero "chi prepara fisicamente" da "chi autorizza/emette il DDT")?
2. **Riserva di stock durante la fase "in preparazione"**: oggi la riserva (`giacenze.quantita_riservata`) avviene alla conferma dell'ordine cliente, aggregata a livello di prodotto, non per sede/lotto specifico (Piano Multi-Magazzino §4.4, decisione esistente: "l'allocazione concreta a un magazzino/lotto specifico avviene solo alla spedizione"). Con un vero stato intermedio, la lista di prelievo *è* già un'allocazione a lotto/magazzino specifico — ma non ancora confermata. Va deciso se, generando la lista di prelievo, si "blocca" già quel lotto/quantità per impedire che un'altra spedizione lo prenda nel frattempo, o se resta un semplice suggerimento non vincolante fino alla conferma (rischio: due liste di prelievo in preparazione contemporaneamente che contano sullo stesso lotto).
3. **Annullamento di una lista di prelievo mai confermata**: come si annulla una spedizione ferma in `preparazione`? Non ha ancora toccato giacenza, quindi concettualmente è "gratis" — ma va comunque una procedura esplicita (coerente con l'"Annulla bozza" già visto per l'inventario fisico).
4. **Interazione con il blocco-inventario** (`qk_inventario_bloccato()`, già esteso ai trasferimenti in questa stessa sessione): cosa succede se un inventario fisico viene aperto su un prodotto/magazzino **dopo** che la lista di prelievo è già stata generata ma **prima** della conferma? La conferma va bloccata come per movimenti/ricezioni/spedizioni dirette/trasferimenti?
5. **UI**: dove vive l'elenco "spedizioni in preparazione, da confermare"? Una nuova vista dentro Spedizioni, o dentro Vendite/Ordini (dipende dalla risposta al punto 3 sopra)?
6. **Naming italiano** delle due azioni — "Genera Lista di Prelievo" / "Conferma Prelievo" sono nomi di lavoro usati in questa nota, non ancora validati con te.
7. **Cosa succede alle righe non trovate/parzialmente trovate**: se la lista di prelievo proponeva 50 kg dal lotto X e il magazziniere ne trova solo 45 (il resto forse rovinato, o mai davvero disponibile), la conferma registra 45 e la differenza resta un'eccezione da gestire come? (stesso tipo di domanda già risolta per i trasferimenti con `note_discrepanza` — probabile riuso dello stesso pattern, ma da confermare esplicitamente qui).

---

## 5. Cosa NON fare per ora

Nessuna implementazione di codice per questa funzionalità finché: (a) il dubbio del §3 (Spedizione come ciclo a sé o parte di Vendite) non è sciolto, e (b) le domande del §4 non hanno almeno una risposta di massima. Il lavoro di revisione in corso in `docs/Gestione Magazzino e Lotti/` prosegue nel frattempo solo su magazzino/giacenze/lotti, trattando la Spedizione come un consumatore esterno di quei dati (cosa succede quando una spedizione scarica un lotto), non progettandone qui il flusso interno.
