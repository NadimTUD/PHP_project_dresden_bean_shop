ALTER TABLE products ADD COLUMN IF NOT EXISTS reorder_threshold integer NOT NULL DEFAULT 5
    CHECK (reorder_threshold BETWEEN 0 AND 10000);
