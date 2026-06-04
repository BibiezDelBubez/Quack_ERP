# Quack ERP - UI/UX Design System & Coding Standards

This document establishes the UI/UX standards for Quack ERP. **All developers and AI assistants MUST strictly follow these guidelines when creating or modifying views.** Do not invent new styling patterns, colors, or inline styles.

---

## 1. Direct Answers to UI/UX Architecture Questions

### A. Is the CSS as contemporary as possible?
* **Current State**: We use CSS Custom Properties (`var(--qk-*)`) for colors, shadows, and spacing. This is modern. However, we historically relied on full Bootstrap 5 layouts mixed with inline styles.
* **Modern Standard**: To minimize page weight and avoid overrides, we are introducing semantically native variables and utility classes. We use `color-mix()` for dynamic hover overlays based on the active theme, and transition rules on all active elements.
* **Rule**: Avoid inline styling for standard layouts. Always use the built-in custom utilities listed in Section 4.

### B. Is the UX as contemporary as possible?
* **Current State**: We have transitioned to clean, Odoo-style tab structures and action pipelines for complex workflows (like RFQs and Quotes).
* **Modern Standard**: Modern ERPs emphasize:
  1. **Frictionless Navigation**: Table rows/names must always be clickable links to prevent forcing the user to find a small edit button.
  2. **High Information Density with Comfort**: Focus visibility highlights, comfortable padding heights, and responsive columns.
  3. **Visual Hierarchy**: One distinct primary button per layout. Everything else uses secondary styling.

### C. Is it lightweight & usable?
* **Weight**: Yes, extremely lightweight. No JavaScript runtime framework (React/Vue) is required to parse the DOM, meaning immediate page load.
* **Usability**: Enhanced through soft transitions (`transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1)`) and custom dark/light theme-aware scrollbars to prevent jarring browser defaults.

---

## 2. Breadcrumbs & Header Layout
* **Rule**: Every list, edit, or detail view must have a clear breadcrumb navigation at the top, followed by a semantic heading.
* **Structure**:
  ```html
  <!-- Breadcrumb (automatically rendered by header.php if $breadcrumbs is defined in controller) -->
  <nav class="qk-breadcrumb mb-2" aria-label="breadcrumb">
    <ol class="breadcrumb m-0 p-0" style="background:transparent; font-size:.85rem;">
      <li class="breadcrumb-item"><a href="<?= url('') ?>"><i class="fa-solid fa-house"></i></a></li>
      <li class="breadcrumb-item"><a href="<?= url('purchases') ?>">Acquisti</a></li>
      <li class="breadcrumb-item active" aria-current="page">Registra Preventivo</li>
    </ol>
  </nav>

  <!-- Title / Action Bar -->
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 m-0">Preventivo OFF/2026/0001</h1>
  </div>
  ```

---

## 3. Lists & Tables (`.qk-table`)
* **Clickable Primary Identifiers**: The primary text column (Name, SKU, Document Number) MUST be a clickable link pointing to the detail/edit view. Never leave it as static plain text.
  * *Example*:
    ```html
    <td class="fw-semibold">
      <a href="<?= url('customers/edit?id=' . $c['id']) ?>" class="qk-text-accent text-decoration-none">
        <?= e($c['name']) ?>
      </a>
    </td>
    ```
* **Consistent Row Actions**: The last column (`.col-actions`) must contain a secondary styled pencil button for editing.
  * *Example*:
    ```html
    <td class="col-actions">
      <a href="<?= url('products/edit?id=' . $p['id']) ?>" class="btn-qk btn-qk-secondary btn-qk-sm" title="Modifica">
        <i class="fa-solid fa-pencil"></i>
      </a>
    </td>
    ```
* **Row Interactions**: Tables implement `.qk-table tbody tr:hover` transition. They automatically highlight with a 4% overlay of the active theme accent color.

---

## 4. Buttons & Actions Design Token Map
Use the following CSS classes exclusively:

| Action Type | CSS Classes | Visual Appearance | Use Case |
| :--- | :--- | :--- | :--- |
| **Primary/Confirm** | `btn-qk btn-qk-primary` | Solid theme brand color | "Salva", "Invia al Fornitore", "Conferma" |
| **Secondary/Cancel** | `btn-qk btn-qk-secondary` | Outlined (white/card background, border) | "Elenco Preventivi", "Annulla" (in forms) |
| **Danger (Primary)** | `btn-qk btn-qk-danger` | Solid soft red / Red on hover | "Elimina" (irreversible), "Conferma Rifiuto" |
| **Danger (Secondary)** | `btn-qk btn-qk-secondary text-danger` | Outlined, red text and red icon | "Rifiuta" (triggers modal/secondary flow) |
| **Icon-only/Micro** | `btn-qk btn-qk-secondary btn-qk-sm` | Small outlined icon button | Action columns inside tables |

* **Prohibited**: Avoid using `.btn-qk-ghost` inside top action bars. Ghost buttons are reserved for inline link-like secondary elements (like removing split rows).

---

## 5. Built-in Spacing & Flexbox Custom Utilities
To prevent messy inline styling (e.g. `style="display:flex; gap:10px;"`), use these custom utilities:

* **Flexbox & Grid**:
  * `.qk-flex-row`: Display flex, row direction, items aligned center.
  * `.qk-flex-wrap`: Enable flex wrapping.
  * `.qk-justify-between`: Space between columns.
  * `.qk-align-center`: Center items vertically.
  * `.qk-grid-dashboard`: Automatic multi-column grid layout for responsive KPI dashboard cards.
* **Gaps**:
  * `.qk-gap-xs`: `0.25rem`
  * `.qk-gap-sm`: `0.5rem`
  * `.qk-gap-md`: `1rem`
  * `.qk-gap-lg`: `1.5rem`
* **Padding & Margins**:
  * `.qk-p-1` to `.qk-p-4`: Padding sizes from `0.25rem` to `1.5rem`.
  * `.qk-mb-1` to `.qk-mb-4`: Margin bottoms from `0.25rem` to `1.5rem`.

---

## 6. Theme Variable Consistency
Never hardcode hexadecimal or RGB colors in inline HTML styles. Always use theme variables:
* Backgrounds: `var(--qk-body-bg)`, `var(--qk-card-bg)`, `var(--qk-bg-subtle)`
* Borders: `var(--qk-card-border)` or `var(--qk-border)`
* Text Colors: `var(--qk-text-primary)`, `var(--qk-text-secondary)`, `var(--qk-text-muted)`
* Accents: `var(--qk-accent)`, `var(--qk-accent-hover)`
