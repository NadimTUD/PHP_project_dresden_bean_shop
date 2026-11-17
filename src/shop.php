<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';

const MAX_QUANTITY = 20;

final class CheckoutRejected extends RuntimeException {}

function euro(int $cents): string
{
    return intdiv($cents, 100) . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) . ' €';
}

function checkout(PDO $db, array $cart, int $accountId, InventoryForOrders $inventory): array
{
    if ($cart === []) {
        throw new CheckoutRejected('Dein Warenkorb ist leer.');
    }

    // Lock products in a consistent order when multiple checkouts overlap.
    ksort($cart, SORT_NUMERIC);
    $db->beginTransaction();
    try {
        $createOrder = $db->prepare(
            'INSERT INTO orders (account_id, customer_name)
             SELECT id, display_name FROM accounts WHERE id = :id RETURNING id'
        );
        $createOrder->execute(['id' => $accountId]);
        $orderId = $createOrder->fetchColumn();
        if ($orderId === false) {
            throw new CheckoutRejected('Bitte melde dich erneut an.');
        }
        $orderId = (int) $orderId;
        $insert = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price_cents, product_name)
             VALUES (:order_id, :product_id, :quantity, :price, :name)'
        );
        $total = 0;

        foreach ($cart as $id => $quantity) {
            if (!is_int($id) || $id < 1 || !is_int($quantity) || $quantity < 1 || $quantity > MAX_QUANTITY) {
                throw new CheckoutRejected('Ungültige Menge im Warenkorb.');
            }
            try {
                $product = $inventory->deductForOrder($db, $id, $quantity, $orderId);
            } catch (StockRejected) {
                throw new CheckoutRejected('Ein Artikel ist nicht mehr in ausreichender Menge verfügbar. Bitte passe deinen Warenkorb an.');
            }

            $price = (int) $product['price_cents'];
            $insert->execute([
                'order_id' => $orderId, 'product_id' => $id, 'quantity' => $quantity,
                'price' => $price, 'name' => $product['name'],
            ]);
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
