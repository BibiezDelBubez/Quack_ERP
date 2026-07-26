# 03 — Ciclo Vendite — Simulazione UX Indirizzi e Contatti (preventivo → ordine)

> **Natura di questo documento, per evitare ambiguità (regola CLAUDE.md punto 11)**: quanto segue è materiale di **design/intento UX**, migrato il 26/07/2026 da `Manuale_Definitivo_Ciclo_Vendite.md` (sezione "Indirizzi e contatti nel ciclo vendite") — **non è stato riverificato caso per caso contro il codice reale**. Il meccanismo realmente implementato lato vendite è più semplice di quanto la simulazione sotto assuma: risoluzione live con fallback manuale/bloccante, non il pattern completo FK+snapshot+override con dirty-check ad hash usato lato Acquisti — vedi [`00 - Anagrafica Unica...md`](00%20-%20Anagrafica%20Unica%2C%20Indirizzi%20e%20Aziende%20Interne%20-%20Guida%20Completa.md) §4 per lo stato di fatto verificato. Alcuni dei casi sotto (in particolare quelli che presuppongono un "override inline con hash" sui campi snapshot) descrivono quindi un'architettura non del tutto corrispondente a quella reale — utile come riferimento di intento UX/casistica, non come descrizione accertata del comportamento attuale. Dove un caso è già noto essere sbagliato o superato, è segnalato esplicitamente sotto.

---

## Simulazione flusso utente: tutti i casi

Questa simulazione segue un operatore che crea un preventivo. Ogni ramo descrive un caso.

### FASE 1 — Selezione del cliente

```
Operatore apre "Nuovo preventivo"
    │
    └── Digita "Ferretti" nel campo cliente
            │
            ├── [CASO 1.A] Trovato un solo cliente "Ferretti S.r.l."
            │       → Il sistema lo seleziona direttamente (o con un click)
            │       → Si va alla FASE 2
            │
            ├── [CASO 1.B] Trovati più clienti con "Ferretti" nel nome
            │       → Dropdown con l'elenco
            │       → Operatore sceglie quello giusto
            │       → Si va alla FASE 2
            │
            └── [CASO 1.C] Nessun risultato
                    → Il pulsante "Nuovo cliente" si illumina o compare un link
                    → Operatore apre il form di creazione cliente (modale o nuova pagina)
                    → Crea il cliente, torna al preventivo con il cliente già selezionato
                    → Si va alla FASE 2 (ma probabilmente senza indirizzi: CASO 2.C)
```

### FASE 2 — Selezione indirizzo di consegna

```
Sistema carica indirizzi del cliente
    │
    ├── [CASO 2.A] Cliente ha UN solo indirizzo di spedizione
    │       → Preselezionato automaticamente
    │       → Operatore vede il preview e può scegliere:
    │           ├── [2.A.i] Va bene → lascia così, passa al referente
    │           ├── [2.A.ii] Vuole modificare un dettaglio (piano, referente diverso)
    │           │       → Clicca [✎] nel preview
    │           │       → Modifica il campo che non va (es. referente consegna)
    │           │       → L'override è solo sul documento
    │           └── [2.A.iii] L'indirizzo è completamente sbagliato (cliente si è trasferito)
    │                   → Clicca "+" per inserire nuovo indirizzo
    │                   → Può scegliere: solo sul documento (una tantum)
    │                                     oppure salva anche in anagrafica
    │
    ├── [CASO 2.B] Cliente ha PIÙ indirizzi di spedizione
    │       → Il predefinito viene preselezionato
    │       → Se il predefinito non è quello giusto:
    │           → Operatore apre il select e sceglie l'altro
    │           → Il preview si aggiorna con i dettagli del nuovo indirizzo
    │
    ├── [CASO 2.C] Cliente NON ha indirizzi di spedizione
    │       → Il select appare vuoto con messaggio "Nessun indirizzo di consegna"
    │       → Operatore inserisce l'indirizzo manualmente ("una tantum" o salvato in anagrafica)
    │
    ├── [CASO 2.D] Cliente ha solo sede legale (nessun indirizzo tipo 'spedizione')
    │       → Il sistema propone "Usa sede legale come indirizzo di consegna"
    │       → Se l'operatore accetta, la sede legale viene precaricata nei campi
    │
    └── [CASO 2.E] La merce va a un TERZO (non al cliente)
            → Caso meno comune ma reale: il cliente ordina ma la merce va
              a un suo cantiere gestito da un'altra impresa
            → L'operatore svuota la selezione e usa "+ Inserisci indirizzo una tantum"
            → Sul PDF compare l'indirizzo del terzo ma il cliente rimane il committente
```

### FASE 3 — Selezione del referente

```
Sistema carica contatti del cliente
    │
    ├── [CASO 3.A] Cliente ha un contatto principale
    │       → Preselezionato automaticamente, campo "alla c.a. di" precompilato
    │
    ├── [CASO 3.B] Cliente ha PIÙ contatti
    │       → Preselezionato il principale, operatore può cambiare il select
    │
    ├── [CASO 3.C] Cliente NON ha contatti censiti
    │       → Il campo "alla c.a. di" è libero: operatore scrive direttamente
    │
    ├── [CASO 3.D] Il referente non è nell'elenco (nuova persona in azienda)
    │       → Operatore scrive il nome nel campo testo (override immediato)
    │
    └── [CASO 3.E] Nessun referente: il documento va all'azienda genericamente
            → Operatore lascia il campo vuoto o scrive "Ufficio acquisti"
```

### FASE 4 — Invio del preventivo (cambio di stato)

```
Operatore clicca "Invia preventivo" (stato bozza → inviato)
    │
    ├── [CASO 4.A] Tutti i dati sono presenti e corretti
    │       → Il preventivo passa a 'inviato', i campi diventano read-only
    │
    ├── [CASO 4.B] Indirizzo di consegna mancante
    │       → Non è un caso bloccante lato vendite: l'indirizzo di consegna in un
    │         preventivo NON è obbligatorio (a differenza di quanto un blocco
    │         generico potrebbe suggerire) — il preventivo si salva/invia comunque
    │         senza indirizzo esplicito, verificato in `00` §4 punto 2.
    │
    ├── [CASO 4.C] Dopo l'invio, il cliente chiede di cambiare l'indirizzo
    │       → Il preventivo inviato non è più modificabile (§1.3 del documento
    │         "Flusso Operativo")
    │       → L'operatore usa "Crea Nuova Versione" (bug noto: oggi non
    │         funzionante per un errore sul numero duplicato, vedi Flusso
    │         Operativo §1.7)
    │
    └── [CASO 4.D] Il preventivo scade senza risposta — **corretto rispetto alla
        stesura originale**: NON esiste alcun automatismo che scriva
        `stato = 'scaduto'` (decisione esplicita del 22/07/2026: niente cron/
        scheduled task esterno alla webapp). Lo stato "vero" è calcolato a
        runtime dalla vista `v_preventivi_stato_effettivo`, mai scritto sulla
        colonna grezza — vedi Flusso Operativo §1.9 per il dettaglio.
```

### FASE 5 — Dal preventivo all'ordine

```
Cliente accetta il preventivo → operatore crea l'ordine
    │
    ├── [CASO 5.A] Consegna alle stesse condizioni del preventivo
    │       → Flusso normale, nessuna scelta aggiuntiva richiesta
    │
    ├── [CASO 5.B] Indirizzo di consegna diverso da quello del preventivo
    │       → Caso tipico: "mandateci la merce al cantiere, non al magazzino"
    │
    ├── [CASO 5.C] Consegna parziale su più indirizzi — **oggi non realizzabile**:
    │       verificato che non esiste alcun campo indirizzo sul form di
    │       spedizione, l'indirizzo è sempre ereditato dall'ordine. Vedi Flusso
    │       Operativo §4.6/§5.5 e Backlog voce 35 per il piano.
    │
    └── [CASO 5.D] L'ordine genera più DDT nel tempo (consegne scaglionate)
            → Ogni DDT congela il proprio destinatario (Flusso Operativo §5.2)
```

---

## Nota per chi legge questo documento in futuro

Le sezioni "Regole di implementazione", "Cosa non fare" e "Checklist implementazione" che accompagnavano questa simulazione nel documento originale sono state **omesse in questa migrazione**, non per svista ma per scelta: descrivevano un'architettura di override/dirty-check che, verificato in `00` §4, non corrisponde al meccanismo reale lato vendite (più semplice) — mantenerle avrebbe presentato come stato di fatto un design mai costruito così. Se in futuro servirà una vera checklist di implementazione per un intervento su questa area, va scritta da zero contro il comportamento reale documentato in `00`, non recuperata da qui.
