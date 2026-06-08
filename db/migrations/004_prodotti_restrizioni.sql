-- Migration 004 — Tabella prodotti_restrizioni
-- Collega direttamente un prodotto (SKU) a un tipo di restrizione compliance.
-- Fonte di verità per il check di vendita. Indipendente dall'ADR.
-- =============================================================================

CREATE TABLE IF NOT EXISTS prodotti_restrizioni (
    prodotto_id         INTEGER NOT NULL REFERENCES prodotti(id) ON DELETE CASCADE,
    restriction_type_id INTEGER NOT NULL REFERENCES compliance_restriction_types(id) ON DELETE CASCADE,
    note                TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prodotto_id, restriction_type_id)
);

CREATE INDEX IF NOT EXISTS idx_prodotti_restrizioni_prodotto
    ON prodotti_restrizioni(prodotto_id);

CREATE INDEX IF NOT EXISTS idx_prodotti_restrizioni_tipo
    ON prodotti_restrizioni(restriction_type_id);
