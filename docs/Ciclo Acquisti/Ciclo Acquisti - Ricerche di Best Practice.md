# Ciclo Acquisti — Ricerche di Best Practice

Contenitore unico per tutto il materiale comparativo con i grandi ERP (SAP, Odoo, Microsoft Dynamics 365, Oracle NetSuite, ERPNext) prodotto durante lo sviluppo e la revisione del Ciclo Acquisti di Quack. Non è un documento operativo: descrive **come fanno gli altri** e, dove utile, **perché Quack ha scelto di fare uguale o diverso**. Le decisioni vere e proprie (con lo stato "implementato"/"backlog") sono nel documento A (Flusso Operativo) e nel documento D (Domande Aperte / Backlog); qui restano solo la ricerca e il confronto.

Questo file nasce dalla revisione del 2026-07 (vedi `REVISIONE - Analisi Documentazione Ciclo Acquisti.md`) che ha consolidato materiale prima sparso su tre file diversi (`Architettura ERP - Flusso Acquisto.md`, `Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md`, `ricerca_indirizzo_consegna_preventivi.md`) e su alcune verifiche puntuali fatte confrontando il codice reale con SAP/Odoo/Dynamics/NetSuite. Il documento A vi rimanda con frasi tipo "vedi contenitore best practice, sezione X" invece di duplicare i contenuti: le intestazioni sotto sono pensate per restare stabili nel tempo proprio per questo.

---

## Indirizzo di consegna sui preventivi

**Nota storica.** Il file originale `ricerca_indirizzo_consegna_preventivi.md` (poi ritirato dalla cartella) partiva da una premessa ormai superata: analizzava come SAP S/4HANA, Dynamics 365 Supply Chain e Oracle NetSuite gestiscono l'indirizzo di consegna su un preventivo spontaneo (tutti e tre richiedono la selezione di un'entità anagrafica — Plant/Storage Location in SAP, Site/Warehouse in D365, Location in NetSuite — mai un indirizzo a testo libero) per poi concludere che **Quack non aveva ancora questa funzionalità** e proporne l'aggiunta come "Fase 2 (Evoluzione Future-Proof)": nuove colonne `consegna_via`/`consegna_civico` ecc. su `offerte_testata`.

Nel frattempo la funzionalità è stata **interamente costruita**, con un meccanismo più sofisticato di quanto la ricerca originale immaginasse: `offerte_testata` (così come `rfq_testata` e `ordini_testata`) ha oggi `indirizzo_consegna_id`, e ogni documento fotografa l'indirizzo e il contatto scelti al momento della creazione in tabelle di snapshot dedicate (`indirizzi_documenti`, `contatti_documenti`), con hash per rilevare se l'anagrafica master è cambiata da allora e flag `personalizzato` per gli snapshot modificati a mano. Cambiare l'indirizzo di un fornitore in anagrafica non altera mai un documento già emesso.

Il perché di questa scelta (fotografia invece di riferimento live) è motivato dagli stessi argomenti che la ricerca originale aveva individuato guardando i grandi ERP — impatto su prezzo/Incoterms, territorialità IVA, tracciabilità PO — solo risolti con un pattern più robusto (snapshot con hash) invece delle colonne piatte proposte inizialmente.

**Dettaglio completo, non duplicato qui**: [`Guide Anagrafiche Clienti, Fornitori, Indirizzi/01 - Ciclo Acquisti - Indirizzi e Contatti da RFQ a Ordine.md`](../Guide%20Anagrafiche%20Clienti%2C%20Fornitori%2C%20Indirizzi/01%20-%20Ciclo%20Acquisti%20-%20Indirizzi%20e%20Contatti%20da%20RFQ%20a%20Ordine.md) — badge UI, casi limite su anagrafica che cambia dopo l'emissione, snapshot orfani, asimmetria fra Vendite e Acquisti sul "salva in anagrafica". Quell'argomento resta di competenza della serie "Guide Anagrafiche": questa sezione esiste solo per chiudere la storia del vecchio file di ricerca, non per replicarne il contenuto tecnico.

---

## Tolleranza esubero: gerarchia a più livelli (idea di ricerca, mai implementata)

Il materiale originale (`Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md`, righe 614-630) propone, per il campo tolleranza di esubero in ricezione (evitare di bloccare uno scarico leggermente superiore all'ordinato — frequente con liquidi/polveri sfusi), una **gerarchia a tre livelli**: un default di sistema globale (es. 0% o 2%), sovrascrivibile dall'anagrafica prodotto (es. un solvente sfuso al 5%, un fusto sigillato allo 0%), a sua volta sovrascrivibile dall'anagrafica fornitore (per chi lavora a peso reale del carico).

**Questa gerarchia non è mai stata portata a Quack.** Nello schema reale esiste solo `ordini_righe.tolleranza_esubero_perc`: un valore libero per riga, senza alcun default ereditato da prodotto o fornitore — vedi documento B, sezione "Tolleranza esubero", per il comportamento implementato e per il gap verificato (nessuna interfaccia oggi imposta questo campo, resta sempre a 0). Il principio di *scrittura fisica sulla riga al momento della generazione dell'ordine, non lettura live dall'anagrafica* è invece coerente con quanto già fatto altrove in Quack (snapshot prezzo/UoM/indirizzo) — solo la gerarchia dei default a monte non è stata realizzata.

---

## Multi-fornitore: opzioni A/B

Il materiale originale (`Architettura ERP - Flusso Acquisto.md`, sezione "Problema multi-fornitore") pone la domanda: *ha senso inserire in una singola RFQ prodotti che in anagrafica sono associati a fornitori diversi?* Confronta due modelli:

**Opzione A — Standard Enterprise (SAP / NetSuite), flusso rigido.**
Esiste una fase preliminare, la Purchase Requisition (PR), documento puramente interno che raccoglie i fabbisogni. La PR viene "esplosa" e il sistema genera automaticamente RFQ distinte, inviate solo ai fornitori abilitati per quei prodotti secondo tabelle di corrispondenza rigide (Fornitore × Prodotto × Prezzo × Lotto minimo).
- Pro: controllo totale, automazione su larga scala, zero errori di acquisto da fornitori non autorizzati.
- Contro: rigidità estrema — derogare per urgenza richiede prima di modificare l'anagrafica acquisti del prodotto; mole di configurazione insostenibile per un team piccolo.

**Opzione B — Approccio Flessibile (Odoo), relazione aperta.**
L'anagrafica prodotto supporta un elenco di fornitori alternativi (`product.supplierinfo`). In fase di creazione manuale di una RFQ il sistema non blocca l'inserimento di alcun prodotto: se il fornitore in testata non è tra quelli censiti sul prodotto, lo permette comunque, applicando il prezzo di default o chiedendo l'inserimento manuale.
- Pro: flessibilità operativa immediata, adatta a mercati volatili o con forti vincoli logistici (es. consolidamento di più prodotti su un unico trasporto ADR).
- Contro: rischio di "sporcare" i dati storici se l'operatore seleziona un fornitore sbagliato per distrazione.

**Scelta fatta per Quack: Opzione B**, per il motivo pratico del consolidamento logistico (spedizioni ADR, senso di raggruppare più prodotti anche se il fornitore preferito differisce). Il testo originale la descriveva così: anagrafica con un solo `fornitore_default_id` + costo base; l'RFQ permette di aggiungere qualunque prodotto a catalogo; se il fornitore della riga differisce dal preferito, alert visivo non bloccante (badge "⚠️ Fornitore Abituale: X"); l'operatore può comunque forzare l'acquisto inserendo il prezzo pattuito col nuovo fornitore.

**Nota di allineamento col codice reale (importante, non presente nel testo originale):** il campo realmente usato non è `prodotti.fornitore_default_id` (non esiste), ma `prodotti_fornitori.ordine_preferenza_fornitore` — una relazione N:N con ordinamento di preferenza, non un singolo FK sul prodotto. Questa è di fatto una via di mezzo più vicina a Odoo che al modello a campo singolo descritto nella ricerca originale, e architetturalmente migliore per un caso multi-fornitore reale (permette più di un fornitore alternativo ordinato, non solo "il preferito" e "tutti gli altri"). L'avviso "⚠️ Fornitore Abituale" descritto sopra come proposta è oggi **implementato** con questo meccanismo: quando l'operatore aggiunge a una RFQ una riga il cui fornitore con `ordine_preferenza_fornitore` più basso (fra quelli attivi) è diverso dal fornitore scelto in testata, la riga viene evidenziata con lo stesso badge giallo previsto dalla ricerca originale.

**Caso collaterale verificato durante la revisione — fornitore preferito disattivato.** Cosa succede se il fornitore con preferenza più alta viene disattivato? Nessuno dei tre grandi ERP di riferimento tratta il "fornitore preferito" come un riferimento fisso: SAP (source list / vendor priority), Dynamics 365 (purchase requisition sourcing) e Odoo (vendor pricelist priority) lo trattano tutti come un ordinamento su una lista di fonti valide — quando il primo è bloccato o inattivo, il sistema scorre automaticamente al successivo attivo con priorità più bassa, e se anche i fallback sono esauriti lo segnala esplicitamente in UI (es. badge "nessuna fonte di approvvigionamento valida"), non lascia un campo vuoto indistinguibile da un dato mancante per altri motivi. Quack ha adottato lo stesso principio: le query di proposta fornitore (riordino sotto-scorta, dettaglio RFQ) ordinano per `ordine_preferenza_fornitore ASC` filtrando `attivo = 1`, invece di un filtro fisso `= 1` sulla preferenza; quando nessun fornitore attivo resta collegato, l'interfaccia mostra "Nessun fornitore attivo" invece di una cella vuota.

---

## Prodotto sostitutivo non in anagrafica: opzioni A/B

Argomento distinto dal multi-fornitore sopra, ma con la stessa struttura "opzione A / opzione B" e per questo raccolto qui. Scenario: il fornitore, rispondendo a una RFQ, propone un articolo diverso da quello richiesto e non presente in anagrafica Quack (fuori catalogo, sostituto equivalente, ecc.).

Il materiale di ricerca (`guida_ciclo_acquisti_v2.md`, `Ciclo acquisti SAP-Inspired...md`) propone all'operatore, sulla riga dell'offerta, il checkbox "Proponi Sostituto" con due esiti possibili:

- **Opzione A — Nuova Variante Fornitore.** L'anagrafica prodotto esistente (l'articolo originariamente richiesto) resta l'`ID_Articolo` di riferimento sulla riga dell'offerta; il sistema si limita a inserire una nuova riga in `prodotti_fornitori` che collega quel prodotto al nuovo codice/fornitore proposto. Adatto quando il sostituto è a tutti gli effetti "lo stesso prodotto", solo con un codice fornitore diverso.
- **Opzione B — Nuovo Articolo.** Il sistema esegue un inserimento transazionale: crea il nuovo articolo in `prodotti`, crea il legame in `prodotti_fornitori`, aggiorna la riga dell'offerta per puntare al nuovo prodotto. Adatto quando il sostituto ha caratteristiche realmente diverse (composizione, formato, classificazione chimica) e merita un record indipendente.

Il vantaggio dichiarato nel materiale originale è evitare un workflow asincrono bloccante (proposta → notifica all'ufficio Anagrafiche/Master Data → validazione → approvazione, sul modello "richiesta di nuovo articolo" tipico di alcuni ERP enterprise): l'operatore decide sul momento, in pochi secondi, dentro la riga dell'offerta stessa.

**Attenzione — vedi CLAUDE.md, punto 6 del progetto**: qualunque percorso di creazione "al volo" di un prodotto (quest'opzione B inclusa) produce oggi un prodotto minimale, senza classificazione ADR né tracciabilità lotti impostate. È un compromesso accettato per lo scenario "prodotto sostituto da completare poi in anagrafica", non un pattern da riusare per la creazione ordinaria di un prodotto.

---

## Contratto Quadro / Blanket PO (backlog — mai iniziato)

Argomento tratto per intero da `Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md` (§"Percorso B: Contratto Quadro"), che lo descrive come una delle due strade possibili quando un'offerta fornitore viene aggiudicata:

- **Percorso A — Ordine d'Acquisto Diretto (Spot PO):** la transazione singola, quella realmente implementata in Quack oggi. L'offerta approvata genera un record ordine in stato "Inviato", le righe dell'offerta si chiudono.
- **Percorso B — Contratto Quadro (Blanket Purchase Order):** pensato per un caso molto comune nel settore chimico — concordare con il produttore la fornitura di un grande quantitativo (es. 10.000 kg di Cloro) per un periodo lungo (es. un anno) a un prezzo bloccato e vantaggioso, senza volerlo ricevere tutto subito (mancherebbe lo spazio in magazzino). Si modellerebbe con due nuove tabelle, `T_Contratti_Testata` e `T_Contratti_Righe` (struttura simile agli ordini, ma senza date di consegna rigide). L'offerta fornitore verrebbe convertita in Contratto invece che in Ordine diretto; durante il periodo di validità, il buyer genererebbe **"Chiamate d'Ordine"** (Release Orders) — normali ordini d'acquisto agganciati al contratto — con un controllo di validazione lato backend che verifica che la somma delle chiamate non superi mai il totale pattuito nel contratto (`Σ chiamate_precedenti + chiamata_corrente ≤ Q_contratto`, con messaggio di errore esplicito sul saldo residuo se superato).

**Stato reale: zero implementazione.** Verificato con ricerca mirata sul codice — nessuna tabella contratti, nessuna logica "blanket" o "chiamata d'ordine" in nessun punto dell'applicazione. Non è una contraddizione fra documentazione e codice: sia il materiale di ricerca sia l'utente (vedi `dubbi e domande su rfq.md`, "manca completamente la fase 3") concordano che è semplicemente lavoro non ancora iniziato.

**Decisione presa nella revisione del Ciclo Acquisti**: non si implementa ora. Il documento A (Flusso Operativo) non deve descrivere il Contratto Quadro come se facesse parte del flusso attuale — un rimando esplicito "vedi backlog" è sufficiente per chi si chiede perché non esiste un percorso "Aggiudica come Contratto Quadro" accanto a "Genera Ordine Diretto".

Dettaglio tecnico completo (schema tabelle, validazioni, endpoint proposti) nel backlog: [`Backlog Sviluppi Futuri.md`, voce 15 — Contratto Quadro (Blanket Purchase Order) con Chiamate d'Ordine](../Backlog%20Sviluppi%20Futuri.md#15-contratto-quadro-blanket-purchase-order-con-chiamate-dordine).

---

## Confronto con ERP best-in-class

Raccolta dei confronti puntuali con SAP, Odoo, Dynamics 365, NetSuite ed ERPNext emersi durante lo sviluppo e la revisione del Ciclo Acquisti, non legati a una singola sezione sopra.

### Architettura generale del ciclo
Negli ERP best-in-class (Odoo, SAP, NetSuite, Dynamics) il ciclo acquisti ruota quasi sempre attorno a **quattro entità distinte**: RFQ (richiesta d'offerta), Quotation/Offerta (risposta fornitore), Purchase Order (ordine), Goods Receipt (ricezione merce) — mai un singolo record con un campo stato che le rappresenta tutte. È il modello che Quack usa realmente (`rfq_*`, `offerte_*`, `ordini_*`, `ricezioni_*`), confermato durante la revisione come corretto contro un vecchio documento che descriveva erroneamente un modello a record unico, mai realizzato in produzione.

### Motore prezzi/listini fornitore
**Odoo**: stesso schema di Quack — scaglioni di quantità più validità temporale (tabella `product.supplierinfo`). Non ha però il "suggerimento da ultimo prezzo pagato" out-of-the-box: è un modulo community o va implementato su misura (esattamente il percorso poi seguito da Quack per il proprio terzo livello di fallback, vedi documento B).
**SAP**: scaglioni multipli con validità, più condizioni complesse (sconti per valore totale dell'ordine, non solo per quantità di riga) — giudicato overkill per le esigenze di Quack.
**ERPNext**: concettualmente identico a Odoo; i listini fornitore si chiamano "Supplier Quotation" ma il meccanismo è lo stesso.

### Immutabilità della RFQ inviata e Order Amendment
Best practice enterprise: una RFQ inviata è un documento quasi-legale, mai modificabile una volta uscito verso il fornitore — qualunque scostamento (prezzo, quantità, lotto minimo) va registrato come dato dell'Offerta, mai sovrascrivendo la RFQ originale. Per gli Ordini d'Acquisto già confermati, SAP e Oracle offrono un meccanismo di **PO Amendment/revisione**: invece di annullare e ricreare un ordine quando cambia una condizione dopo l'invio, si crea una nuova versione che preserva la genealogia (versione precedente marcata "superata", non cancellata). Quack implementa lo stesso pattern sia per le RFQ (stato `revisionata`) sia per gli Ordini (stato `superato`, campo `ordine_padre_id`), scartando esplicitamente l'alternativa "annulla e rifai" descritta (erroneamente, come proposta) in un vecchio documento.

Approfondimento specifico emerso durante l'hardening di questo meccanismo (revisione di un ordine con una ricezione ancora in stato bozza collegata): SAP scoraggia esplicitamente la modifica di un PO una volta che esistono documenti a valle (ricezioni merce) — consiglia di creare un nuovo PO invece di alterare quello esistente. Odoo non aggiorna mai automaticamente un PO durante una ricezione, per non rompere tracciabilità/responsabilità. Dynamics 365 richiede una revisione/accettazione esplicita di ogni modifica a un PO già confermato. Tradotto nell'architettura di Quack: bloccare la causa alla radice con guardie esplicite (niente revisione se esiste una ricezione bozza aperta), non solo rilevare il problema più tardi.

### Annullamento di un ordine parzialmente ricevuto
Nessuno dei grandi ERP tratta "annullare un ordine con merce già arrivata in parte" come un'unica operazione bloccante: SAP usa un indicatore "Final Delivery"/consegna completata; Dynamics 365 offre "Cancel remaining quantity"; Odoo e NetSuite chiudono le righe residue. Tutti separano sempre "chiudere il residuo non ricevuto" (sempre permesso) da "stornare merce già ricevuta" (flusso separato, reso/nota di credito, fuori scope). Quack segue lo stesso principio: l'annullamento di un ordine `parzialmente_ricevuto` resta permesso, riformulato come "chiusura del residuo non ancora ricevuto" — la merce già entrata a magazzino non viene mai toccata.

### Righe RFQ senza prodotto a catalogo (free-text)
SAP consente le "Free-Text Requisitions"; ma né SAP, né Dynamics 365, né NetSuite trattano le righe "senza codice articolo" come righe di magazzino generiche — sono sempre righe di **spesa contabile** (account assignment category in SAP, procurement category in D365, riga "Expense" in NetSuite), mai un movimento di magazzino, mai uno SKU, mai un lotto. Per un rivenditore di prodotti chimici spesso in regime ADR, dove SDS/classificazione/lotto sono obbligatori per qualunque cosa finisca fisicamente a magazzino, questo conferma che l'anagrafica-first di Quack (`prodotto_id NOT NULL` su tutte le righe di RFQ/Offerte/Ordini/Ricezioni) è la scelta corretta e non va rilassata. Una funzionalità strutturalmente diversa — righe di spesa non-magazzino, es. trasporto o servizi accessori — resta backlog separato: [`Backlog Sviluppi Futuri.md`, voce 14](../Backlog%20Sviluppi%20Futuri.md#14-righe-di-spesa-non-magazzino-sugli-ordini-dacquisto).

### Unità di misura d'acquisto vincolata
SAP, Dynamics e Odoo risolvono tutti allo stesso modo il caso limite "riga fornitore senza unità di misura d'acquisto specificata": l'unità d'acquisto è sempre vincolata a un piccolo insieme di UoM valide per quel prodotto (mai una scelta libera a testo), con un default automatico alla UoM base quando l'operatore non specifica nulla. Quack replica lo stesso pattern: lato server nessun valore nullo può mai passare (fallback d'ufficio alla Stock UoM del prodotto), lato client il selettore è un toggle a due sole opzioni (Stock/Commerciale), mai un campo libero.

---

*Documento generato durante la revisione 2026-07 della cartella "Ciclo Acquisti". Consolidamento di: la sezione "Come fanno gli ERP best in class" di `Architettura ERP - Flusso Acquisto.md`, l'intero `ricerca_indirizzo_consegna_preventivi.md` (ritirato), le sezioni comparative di `Ciclo acquisti SAP-Inspired da RFQ a Arrivo Merce.md`, e i confronti puntuali con SAP/Odoo/Dynamics/NetSuite emersi durante la verifica incrociata codice-documentazione in `REVISIONE - Analisi Documentazione Ciclo Acquisti.md`.*
