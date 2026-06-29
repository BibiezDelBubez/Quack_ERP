# Database Schema Rules

## Prodotti / Giacenze schema
- **`scorta_minima` must remain in `prodotti`**: Do not move `scorta_minima` to `giacenze` or normalize it out of the `prodotti` table.
- The `DashboardController` and `ProductsController` explicitly rely on `p.scorta_minima`.
- It is duplicated in `giacenze` for caching/convenience reasons. Do not attempt to fix this duplication or refactor the DB schema.
