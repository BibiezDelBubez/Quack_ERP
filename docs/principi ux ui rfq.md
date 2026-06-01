# Implementation Plan - RFQ UI/UX Modernization

Redesign the RFQ creation/editing layout to bring it to state-of-the-art 2026 ERP standards.

## Proposed Changes

### Styling & CSS Enhancements

#### [MODIFY] [app.css](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/assets/css/app.css)
- Hide number inputs' spin buttons (spinners) globally or for form grids.
- Implement a premium flat-grid table style for the RFQ lines:
  - Inputs inside cells will have no border or background by default, behaving like flat text.
  - Hovering over a row or focusing an input highlights the boundaries and background.
  - Text inputs will align perfectly with table text, and numeric inputs (Quantity, Price, VAT) will align to the right.
  - Add drag handles and a grabbing cursor styling.

### Row & View Layout Enhancements

#### [MODIFY] [_form_riga.php](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/app/views/purchases/rfq/_form_riga.php)
- Add a drag handle cell (`col-drag`) at the beginning of each row using a grip icon (`fa-grip-vertical`).
- Position the Clear (`x`) button for autocomplete absolutely inside the search wrapper to prevent layout wrapping.
- Align numbers to the right and clean up margins.

#### [MODIFY] [_form.php](file:///c:/Users/miche/OneDrive/Documenti/xampp/htdocs/Quack/app/views/purchases/rfq/_form.php)
- Update `<thead>` to include the drag handle column header.
- Update `<template id="tpl-riga">` to match the new `_form_riga.php` structure.
- Implement native HTML5 Drag and Drop reordering in JavaScript:
  - Add event listeners (`dragstart`, `dragover`, `dragleave`, `drop`, `dragend`) to rows to allow smooth reordering.
  - Update `rigaIdx` and re-index logic if necessary, though DOM order is naturally preserved on POST submit.
- Enhance focus styles and spreadsheet-like micro-animations.

## Verification Plan

### Manual Verification
- Verify the form visually: number inputs should be clean, autocomplete clear buttons should not wrap, and table lines should look like a clean grid.
- Test drag-and-drop: drag rows to reorder them and verify the total is computed properly, and saving the document stores the rows in the new order.
