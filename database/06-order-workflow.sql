BEGIN;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'legacy'
    CHECK (status IN ('legacy', 'placed', 'processing', 'completed', 'cancelled'));
ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'placed';

CREATE TABLE IF NOT EXISTS order_status_events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id integer NOT NULL REFERENCES orders(id),
    previous_status text NOT NULL,
    next_status text NOT NULL,
    staff_account_id integer NOT NULL REFERENCES accounts(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((previous_status = 'placed' AND next_status IN ('processing', 'cancelled'))
        OR (previous_status = 'processing' AND next_status IN ('completed', 'cancelled')))
);
CREATE INDEX IF NOT EXISTS order_status_events_order_idx ON order_status_events (order_id, id);

ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS inventory_movements_check;
ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_check CHECK (
    (kind = 'sale' AND quantity_change < 0 AND order_id IS NOT NULL AND staff_account_id IS NULL)
    OR (kind = 'restock' AND quantity_change > 0 AND order_id IS NULL AND staff_account_id IS NOT NULL)
    OR (kind = 'cancellation' AND quantity_change > 0 AND order_id IS NOT NULL AND staff_account_id IS NOT NULL)
);
CREATE UNIQUE INDEX IF NOT EXISTS cancellation_once_per_product_idx
    ON inventory_movements (order_id, product_id) WHERE kind = 'cancellation';

COMMIT;
