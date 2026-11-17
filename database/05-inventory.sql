BEGIN;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id integer NOT NULL REFERENCES products(id),
    kind text NOT NULL,
    quantity_change integer NOT NULL,
    stock_after integer NOT NULL CHECK (stock_after >= 0),
    order_id integer REFERENCES orders(id),
    staff_account_id integer REFERENCES accounts(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT inventory_movements_check CHECK (
        (kind = 'sale' AND quantity_change < 0 AND order_id IS NOT NULL AND staff_account_id IS NULL)
        OR
        (kind = 'restock' AND quantity_change > 0 AND order_id IS NULL AND staff_account_id IS NOT NULL)
    )
);

COMMIT;
