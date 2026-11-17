BEGIN;

CREATE TABLE IF NOT EXISTS customers (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    display_name varchar(60) NOT NULL UNIQUE CHECK (btrim(display_name) <> '')
);

INSERT INTO customers (display_name) VALUES ('Demo-Kunde Elbe'), ('Demo-Kundin Neustadt')
ON CONFLICT (display_name) DO NOTHING;

-- Old orders were placed without customer profiles; NULL preserves that fact.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_id integer REFERENCES customers(id);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name varchar(60);
ALTER TABLE order_items ADD COLUMN IF NOT EXISTS product_name text;

UPDATE order_items AS i
SET product_name = p.name
FROM products AS p
WHERE i.product_id = p.id AND i.product_name IS NULL;

ALTER TABLE order_items ALTER COLUMN product_name SET NOT NULL;
CREATE INDEX IF NOT EXISTS orders_customer_history_idx ON orders (customer_id, id DESC);

COMMIT;
