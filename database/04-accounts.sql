BEGIN;

CREATE TABLE IF NOT EXISTS accounts (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    display_name varchar(60) NOT NULL CHECK (btrim(display_name) <> ''),
    email varchar(254) NOT NULL UNIQUE CHECK (email = lower(email)),
    password_hash text,
    google_subject varchar(255) UNIQUE,
    is_staff boolean NOT NULL DEFAULT false,
    CHECK (password_hash IS NOT NULL OR google_subject IS NOT NULL)
);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS account_id integer REFERENCES accounts(id);
CREATE INDEX IF NOT EXISTS orders_account_history_idx ON orders (account_id, id DESC);

COMMIT;
