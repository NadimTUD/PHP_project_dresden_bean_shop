CREATE TABLE products (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL UNIQUE,
    notes text NOT NULL,
    price_cents integer NOT NULL CHECK (price_cents > 0),
    stock integer NOT NULL CHECK (stock >= 0)
);

CREATE TABLE orders (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE order_items (
    order_id integer NOT NULL REFERENCES orders(id),
    product_id integer NOT NULL REFERENCES products(id),
    quantity integer NOT NULL CHECK (quantity BETWEEN 1 AND 20),
    unit_price_cents integer NOT NULL CHECK (unit_price_cents > 0),
    PRIMARY KEY (order_id, product_id)
);
