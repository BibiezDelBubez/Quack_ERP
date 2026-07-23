# Quack ERP🦆

## Cos'è

Quack ERP è un gestionale web che ho scritto per un'azienda che distribuisce prodotti chimici, con particolare focus sui prodotti per la depurazione dell'acqua.

Colma le lacune dei tradizionali gestionali open source gratuiti, aggiungendo funzionalità come i calcoli ADR per il trasporto di merci pericolose, il controllo dei permessi per la vendita di sostanze regolamentate e il traccia\mento obbligatorio dei lotti.

Quando ho progettato Quack ERP ho pensato alla convivenza di due requisiti che di solito non si trovano nello stesso gestionale

1. **Architettura DB rigida e strutturata con possibilità di uno storico completo** (stile SAP/Oracle): il database non sovrascrive mai i dati passati. Modifichi un RFQ, un preventivo o un ordine? Il sistema salva ogni versione. Puoi sempre vedere cosa era stato concordato all'inizio.
2. **Interfaccia comoda** (stile Odoo/Dynamics/NetSuite): anche se sotto c'è un database rigido, l'uso quotidiano è veloce e pulito, non un labirinto di menu.

#### Funzioni principali

**Anagrafica e sostanze**

- Sostanze chimiche e ADR: classificazione prodotti, calcolo automatico dei pesi per il trasporto di merci pericolose.
- Anagrafiche clienti e fornitori.
- Gestione multi-azienda: più aziende interne sullo stesso ERP.

**Vendite**

- Preventivi, ordini e spedizioni, con storico completo delle revisioni.
- Listini prezzi per cliente, con quantità minime e sconti per fascia.
- Tracciamento spedizioni: stato, vettore, date, ricerca e filtri.

**Acquisti**

- RFQ (richieste d'offerta), preventivi fornitori e ordini d'acquisto.
- Ogni modifica genera una nuova versione: si può sempre vedere cosa era stato concordato all'inizio.
- Ricevimento merci con controllo su quanto ordinato vs quanto arrivato.

**Magazzino e lotti**

- Tracciamento lotti dall'ingresso in magazzino alla spedizione al cliente.
- Doppia unità di misura (es. stock a fusti, vendita a kg), con calcoli ad alta precisione per evitare sballi negli arrotondamenti di prezzi e giacenze.

**Compliance**

- Prima di far uscire una sostanza controllata (precursori di esplosivo, precursori di stupefacenti, etc), il sistema verifica in automatico se il cliente ha un'autorizzazione valida. Se non ce l'ha, blocca l'operazione.

**Sistema**

- Utenti e ruoli, con permessi differenziati (es. gestione utenti riservata agli admin).
- Generazione documenti in PDF (mpdf) e Word (PHPWord) — offerte, ordini, documenti di trasporto.
- Integrazione WooCommerce per la sincronizzazione prodotti/ordini.

## Stack tecnico

- PHP (senza framework, routing custom in `app/Core/Router.php`)
- Database: SQLite in sviluppo, MySQL in produzione (`db/init_sqlite.sql`, `db/init_mysql.sql`)
- PDF: mpdf
- Word: PHPWord
- Dipendenze via Composer

## Struttura cartelle

- `app/` — Controllers, routing, bootstrap
- `includes/` — moduli funzionali (magazzino, acquisti, vendite, anagrafiche, compliance...)
- `db/` — schema e migrazioni
- `docs/` — documentazione di progetto
- `audit/` — audit tecnici e piani di intervento
- `assets/` — file statici

## Come si avvia (locale, XAMPP)

1. Clona il repo dentro `htdocs/`
2. `composer install`
3. Copia/configura `includes/config.php` (ambiente, DB, cartelle upload)
4. `git config core.hooksPath .githooks` — attiva il pre-commit hook che blocca i commit con encoding non-UTF8 o mojibake (vedi `tools/check-utf8.php`). Va rifatto ad ogni nuovo clone/checkout: `core.hooksPath` non è versionato da git, è impostazione locale di ogni copia del repo.
5. Avvia Apache/MySQL da XAMPP
6. Vai su `http://localhost/Quack`

### Controllo encoding manuale

Per controllare a mano l'intero repository (non solo i file staged per il commit):

```
php tools/check-utf8.php $(git ls-files '*.php' '*.sql' '*.js' '*.md' '*.css' '*.html' '*.json' '*.txt' '*.yml' '*.yaml')
```

Rileva sia byte non validi come UTF-8 sia mojibake da doppia codifica (testo UTF-8 corretto ri-salvato dopo essere stato riletto come Windows-1252/Latin-1 — es. "è" diventato "è"): quest'ultimo problema è stato trovato e corretto a tappeto il 23/07/2026 in 4 file del progetto (vedi `CLAUDE.md`).

## Sì, ma perché proprio "Quack"?

In teoria l'acronimo sta per **Q**uoting **U**tility and **A**DR **C**hemical **K**eeper, perché all'inizio era nato solo come una piccola utility per fare i preventivi e calcolare le esenzioni ADR, per poi diventare un vero gestionale.

Ma in realtà: a chi non piacciono le anatre?
