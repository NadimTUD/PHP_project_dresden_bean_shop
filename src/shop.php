<?php
declare(strict_types=1);

const MAX_QUANTITY = 20;

final class CheckoutRejected extends RuntimeException {}

function euro(int $cents): string
{
    return intdiv($cents, 100) . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) . ' €';
}

function checkout(PDO $db, array $cart): array
{
    if ($cart === []) {
        throw new CheckoutRejected('Dein Warenkorb ist leer.');
    }

    // Lock products in a consistent order when multiple checkouts overlap.
    ksort($cart, SORT_NUMERIC);
    $db->beginTransaction();
    try {
        $reserve = $db->prepare(
            'UPDATE products SET stock = stock - :quantity
             WHERE id = :id AND stock >= :minimum RETURNING name, price_cents'
        );
        $insert = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price_cents)
             VALUES (:order_id, :product_id, :quantity, :price)'
        );
        $orderId = (int) $db->query('INSERT INTO orders DEFAULT VALUES RETURNING id')->fetchColumn();
        $total = 0;

        foreach ($cart as $id => $quantity) {
            if (!is_int($id) || $id < 1 || !is_int($quantity) || $quantity < 1 || $quantity > MAX_QUANTITY) {
                throw new CheckoutRejected('Ungültige Menge im Warenkorb.');
            }
            $reserve->execute(['quantity' => $quantity, 'id' => $id, 'minimum' => $quantity]);
            $product = $reserve->fetch(PDO::FETCH_ASSOC);
            $reserve->closeCursor();
            if ($product === false) {
                throw new CheckoutRejected('Ein Artikel ist nicht mehr in ausreichender Menge verfügbar. Bitte passe deinen Warenkorb an.');
            }

            $price = (int) $product['price_cents'];
            $insert->execute(['order_id' => $orderId, 'product_id' => $id, 'quantity' => $quantity, 'price' => $price]);
            $total += $price * $quantity;
        }
        $db->commit();
        return ['id' => $orderId, 'total' => $total];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
