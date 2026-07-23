# Quack ERP - UI/UX Design System & Coding Standards (v2)

Questo documento stabilisce gli standard architetturali e di UI/UX per Quack ERP. **All developers and AI assistants MUST strictly follow these guidelines when creating or modifying views.** Do not inventare nuovi pattern, colori o stili inline.

---

## 1. Visione Generale: Il Modello "Operative Shell" e Navigazione a Strati

L'ERP non deve essere inteso come una serie di pagine web slegate, ma come un **ambiente di lavoro coerente**. Anche se tecnicamente l'app è una Lightweight MPA (Multi-Page Application) sviluppata in PHP (e non una SPA con "Zero carichi di pagina"), l'esperienza utente deve mimare un "guscio" (Shell) fisso e responsivo, arricchito da Vanilla JS, che mantiene alte le performance senza dipendenze pesanti (React/Vue). Non stiamo scegliendo un menu, stiamo costruendo un sistema di navigazione a strati (combinando la facilità di Odoo, la potenza di Slack/Linear).

La navigazione è divisa per **intenzioni dell'utente** (I 3 Pilastri):

### A. La Sidebar "Slim" (Navigazione Strutturale / Il "Dove sono?")
* **Posizione**: Lato sinistro, fissa ma collassabile.
* **Stato Default**: Solo icone (Slim) per massimizzare lo spazio orizzontale.
* **Funzione**: Rappresenta i "Reparti" dell'azienda (es. Vendite, Acquisti, Magazzino, HR). Serve all'utente per capire "dove si trova" nel macro-sistema. Definisce il perimetro operativo.
* **Metafora per il team**: La Sidebar è il tuo **ufficio** (le porte dei reparti dove vai sempre).

### B. L'App Launcher (Navigazione Visuale / Il "Cosa posso fare?")
* **Posizione**: Primo tasto in alto della Sidebar o schermata di Home.
* **Funzione**: Una griglia di icone stile smartphone (stile Odoo). Contiene tutte le Utility e i Moduli Specifici, inclusi quelli che non usi ogni giorno.
* **Metafora per il team**: L'App Launcher è l'**archivio/magazzino** dell'intero edificio (dove trovi tutto, delegando la complessità per tenere pulita la Sidebar).
* **Cosa trovi qui e NON nella sidebar**:
  - Configurazione Tasse (Ci entri una volta all'anno)
  - Reportistica Avanzata (Analisi trimestrale)
  - Archivio Storico (Consultazione dati vecchi)
  - Integrazioni API (Settaggi tecnici)
  - Audit Log (Controllo accessi)

### C. La Command Bar (Navigazione Operativa / "Il Dispatcher")
* **Posizione**: Centro della Top Bar (stile Spotlight/Google).
* **Accesso**: Shortcut universale `Cmd+K` o `Ctrl+K`.
* **Funzione**: È il cuore pulsante dell'ERP. Deve gestire tre tipi di input: Navigazione ("Vai a Fatture"), Ricerca Dati ("Rossi"), Azioni Rapide ("Crea nuovo ordine").
* **Sinergia**: È il metodo primario per gli utenti esperti (Power Users) per saltare tra i dati senza usare il mouse.
* **Metafora per il team**: La Command Bar è l'**assistente** a cui chiedi "Portami la pratica Rossi" e lui te la trova ovunque essa sia.
* **Funzionamento Tecnico (Dispatcher di Azioni)**: Non è un semplice input di ricerca, utilizza tre livelli di consapevolezza (Registri):
  - *Il Registro Globale*: Elenco statico di comandi disponibili.
  - *Il Provider di Contesto (Current Route)*: Comandi specifici della pagina (es. in Fatture attiva "> Invia via email").
  - *Il Contextual Data (ID in Focus)*: Se sei nella scheda "Bianchi", propone "Crea Fattura per Bianchi".

### Sintesi della Gerarchia d'Uso (User Flow)
| Componente | Quando si usa? | Esempio |
| :--- | :--- | :--- |
| **App Launcher** | All'inizio della giornata o per esplorazione. | "Vediamo che moduli abbiamo a disposizione." |
| **Sidebar** | Per cambiare reparto operativo. | "Ora smetto di fatturare e vado in Magazzino." |
| **Command Bar** | Per l'80% delle azioni quotidiane. | "Trova il contratto di Bianchi", "Crea fattura". |

---

## 2. Visualizzazione Dati: Il "Canvas" e Principi di Ergonomia

* **Densità Variabile**: L'utente deve poter scegliere tra una vista "Compact" (molte righe, poco spazio, stile Excel) e una vista "Comfort" (più aria, icone più grandi).
* **Filtri "Sticky"**: I filtri nelle tabelle devono essere sempre raggiungibili e non devono scomparire durante lo scroll.
* **Keyboard First**: Ogni azione principale deve avere uno shortcut. L'utente non dovrebbe mai essere obbligato a usare il mouse per le operazioni ripetitive.
* **Performance**: La Command Bar deve usare algoritmi di Fuzzy Search (es. fuse.js) e rispondere in **meno di 100ms**. La percezione di velocità è fondamentale.
* **Mobile Adaptive**: La Sidebar scompare e diventa un menu "hamburger" in basso, mentre la Command Bar diventa un tasto di ricerca flottante.

---

## 3. Direct Answers to UI/UX Architecture Questions

### A. Is the CSS as contemporary as possible?
* **Current State**: We use CSS Custom Properties (`var(--qk-*)`) for colors, shadows, and spacing.
* **Modern Standard**: We use `color-mix()` for dynamic hover overlays based on the active theme, and transition rules on all active elements (`transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1)`). Avoid inline styling. Use custom utilities (Section 7).

### B. Is the UX as contemporary as possible?
1. **Frictionless Navigation**: Table rows/names must always be clickable links.
2. **High Information Density with Comfort**: Focus visibility highlights, comfortable padding heights, and responsive columns.
3. **Visual Hierarchy**: **One distinct primary button per layout.** Everything else uses secondary styling.

### C. Is it lightweight & usable?
* **Weight**: Extremely lightweight. No JS runtime framework required for DOM parsing.
* **Usability**: Enhanced through soft transitions and custom dark/light theme-aware scrollbars to prevent jarring browser defaults.

---

## 4. Breadcrumbs & Header Layout
* **Regola**: Ogni list, edit, o detail view must have a clear breadcrumb navigation at the top (sempre visibile in alto a sinistra), seguito da un titolo semantico. Serve per dare orientamento immediato dopo un salto effettuato tramite Command Bar (es. *Vendite > Ordini > #2024-001*).
* **Structure**:
  ```html
  <nav class="qk-breadcrumb mb-2" aria-label="breadcrumb">
    <ol class="breadcrumb m-0 p-0" style="background:transparent; font-size:.85rem;">
      <li class="breadcrumb-item"><a href="<?= url('') ?>"><i class="fa-solid fa-house"></i></a></li>
      <li class="breadcrumb-item"><a href="<?= url('purchases') ?>">Acquisti</a></li>
      <li class="breadcrumb-item active" aria-current="page">Registra Preventivo</li>
    </ol>
  </nav>

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 m-0">Preventivo OFF/2026/0001</h1>
  </div>
  ```

---

## 5. Lists & Tables

### A. Tabelle Elenco (`.qk-table`)
* **Clickable Primary Identifiers**: The primary text column (Name, SKU, Document Number) MUST be a clickable link pointing to the detail/edit view. Never leave it as static plain text.
* **Consistent Row Actions**: The last column (`.col-actions`) must contain a secondary styled pencil button for editing.

### B. Tabelle Transazionali / Form di Riga (Es. RFQ, Preventivi)
* **Premium flat-grid table style**: 
  - Inputs inside cells will have no border or background by default, behaving like flat text.
  - Hovering over a row or focusing an input highlights the boundaries and background.
  - Text inputs will align perfectly with table text, and numeric inputs (Quantity, Price, VAT) will align to the right.
  - Hide number inputs' spin buttons (spinners).
  - Clean up margins.
* **Row & View Layout Enhancements**:
  - Add a drag handle cell (`col-drag`) at the beginning of each row using a grip icon (`fa-grip-vertical`) and a grabbing cursor styling (`cursor: grab/grabbing`).
  - Position the Clear (`x`) button for autocomplete absolutely inside the search wrapper to prevent layout wrapping.
  - Update `<thead>` to include the drag handle column header.
  - Update `<template id="tpl-riga">` to match the `_form_riga.php` structure.
  - Implement native HTML5 Drag and Drop reordering in JavaScript: Add event listeners (`dragstart`, `dragover`, `dragleave`, `drop`, `dragend`) to rows to allow smooth reordering, verify totals are computed properly, and ensure saving the document stores the rows in the new order (DOM order is preserved on POST submit).

---

## 6. Buttons & Actions Design Token Map

| Action Type | CSS Classes | Visual Appearance | Use Case |
| :--- | :--- | :--- | :--- |
| **Primary/Confirm** | `btn-qk btn-qk-primary` | Solid theme brand color | "Salva", "Conferma" |
| **Secondary/Cancel** | `btn-qk btn-qk-secondary` | Outlined | "Elenco", "Annulla" |
| **Danger (Primary)** | `btn-qk btn-qk-danger` | Solid soft red | "Elimina" |
| **Danger (Secondary)** | `btn-qk btn-qk-secondary text-danger` | Outlined, red text | "Rifiuta" |
| **Icon-only/Micro** | `btn-qk btn-qk-secondary btn-qk-sm` | Small outlined icon | Action columns |
| **Ghost** | `btn-qk btn-qk-ghost` | Senza bordi, solo hover | Rimuovi riga in form |

* **Prohibited**: Avoid using `.btn-qk-ghost` inside top action bars. Ghost buttons are reserved for inline link-like secondary elements.

### Transactional Forms & Sticky Action Bar Layout
For document forms, use the sticky header `.qk-sticky-action-bar` to keep operations accessible:
* **Left Container (`.qk-sticky-action-bar-left`)**: Holds positive/saving actions. The most important primary confirmation action goes first on the left.
* **Right Container (`.qk-sticky-action-bar-right`)**: Holds negative, destructive, fallback, or navigation actions. 
* **Workflow Pipeline (`.qk-status-path`)**: Must be placed in the right container to display the document's progress.

---

## 7. Advanced ERP Widgets & Custom Utilities

### A. KPI Cards (`.qk-kpi-card`)
A standard grid container widget for dashboards.
```html
<div class="qk-kpi-card">
  <div class="qk-kpi-icon-wrap" style="background: var(--qk-accent-light); color: var(--qk-accent);">
    <i class="fa-solid fa-coins"></i>
  </div>
  <div>
    <div class="qk-kpi-val">€ 12.450,00</div>
    <div class="qk-kpi-label">Fatturato Mese</div>
    <div class="qk-kpi-trend up"><i class="fa-solid fa-arrow-trend-up"></i> +12%</div>
  </div>
</div>
```

### B. Activity Timeline / History Feed (`.qk-timeline`)
```html
<ul class="qk-timeline">
  <li class="qk-timeline-item">
    <div class="qk-timeline-badge success"></div>
    <div class="qk-timeline-title">Ordine Approvato</div>
    <div class="qk-timeline-time">Oggi, 14:32 - Mario Rossi</div>
    <div class="qk-timeline-desc">L'ordine è stato approvato.</div>
  </li>
</ul>
```

### C. File Attachments Card Grid (`.qk-attachment-grid`)
```html
<div class="qk-attachment-grid">
  <div class="qk-attachment-card">
    <i class="fa-solid fa-file-pdf qk-attachment-icon text-danger"></i>
    <a href="/uploads/doc.pdf" target="_blank" class="qk-attachment-name">Preventivo_Firmato.pdf</a>
    <span class="qk-attachment-size">1.2 MB</span>
  </div>
</div>
```

### D. Spacing & Flexbox Custom Utilities
* `.qk-flex-row`, `.qk-flex-wrap`, `.qk-justify-between`, `.qk-align-center`, `.qk-grid-dashboard`
* `.qk-gap-xs` to `.qk-gap-lg`
* `.qk-p-1` to `.qk-p-4`, `.qk-mb-1` to `.qk-mb-4`
* **Theme Variables Consistency**: `var(--qk-body-bg)`, `var(--qk-card-bg)`, `var(--qk-bg-subtle)`, `var(--qk-border)`, `var(--qk-text-primary)`, `var(--qk-text-muted)`, `var(--qk-accent)`.

### E. Alert Messages & Link Styling (`.qk-alert`, `.qk-alert-link`)
* **Standard Component**: Use `.qk-alert` with variant classes (`.qk-alert-danger`, `.qk-alert-warning`, `.qk-alert-success`, `.qk-alert-info`).
* **Links Inside Alerts**: Any actionable link (`<a>`) generated inside an alert message (e.g. from controllers or flash messages) MUST use the class `.qk-alert-link`.
* **Prohibited**: **NEVER write inline styles** (`style="color:...; font-weight:..."`) in PHP controllers or views for alert text/links. Using `.qk-alert-link` ensures `color: inherit`, keeping the link in bold (`font-weight: 700`), underlined, and visually aligned with the container alert type.

