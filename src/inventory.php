<?php
declare(strict_types=1);

const MAX_RESTOCK_QUANTITY = 10000;

final class StockRejected extends RuntimeException {}

interface InventoryForOrders
{
    /** Runs inside the caller's active transaction. Returns the sale-time product details. */
    public function deductForOrder(PDO $transaction, int $productId, int $quantity, int $orderId): array;
}

interface InventoryForCancellations
{
    public function restoreForCancellation(PDO $transaction, int $productId, int $quantity, int $orderId, int $staffId): void;
}

final class PostgresInventory implements InventoryForOrders, InventoryForCancellations
{
    public function deductForOrder(PDO $transaction, int $productId, int $quantity, int $orderId): array
    {
        if (!$transaction->inTransaction() || $quantity < 1) {
            throw new LogicException('Stock deduction requires an active transaction and a positive quantity.');
        }
        $deduct = $transaction->prepare(
            'UPDATE products SET stock = stock - :quantity
             WHERE id = :id AND stock >= :minimum RETURNING name, price_cents, stock'
        );
        $deduct->execute(['quantity' => $quantity, 'id' => $productId, 'minimum' => $quantity]);
        $product = $deduct->fetch(PDO::FETCH_ASSOC);
        $deduct->closeCursor();
        if ($product === false) {
            throw new StockRejected('Ein Artikel ist nicht in ausreichender Menge verfügbar.');
        }
        $this->record($transaction, $productId, 'sale', -$quantity, (int) $product['stock'], $orderId, null);
        return $product;
    }

    public function restock(PDO $db, array $quantities, int $staffId): void
    {
        if ($quantities === []) {
            throw new StockRejected('Trage mindestens eine Nachfüllmenge ein.');
        }
        // Checkout and restocking lock products in the same order.
        ksort($quantities, SORT_NUMERIC);
        $db->beginTransaction();
        try {
            $staff = $db->prepare('SELECT 1 FROM accounts WHERE id = :id AND is_staff FOR SHARE');
            $staff->execute(['id' => $staffId]);
            if ($staff->fetchColumn() === false) {
                throw new StockRejected('Nur Mitarbeitende können Bestand auffüllen.');
            }
            $increase = $db->prepare(
                'UPDATE products SET stock = stock + :quantity
                 WHERE id = :id AND stock <= :ceiling RETURNING stock'
            );
            foreach ($quantities as $productId => $quantity) {
                if (!is_int($productId) || $productId < 1 || $productId > 2147483647
                    || !is_int($quantity) || $quantity < 1 || $quantity > MAX_RESTOCK_QUANTITY) {
                    throw new StockRejected('Die Nachfüllmenge muss zwischen 1 und 10.000 liegen.');
                }
                $increase->execute(['quantity' => $quantity, 'id' => $productId, 'ceiling' => 2147483647 - $quantity]);
                $after = $increase->fetchColumn();
                $increase->closeCursor();
                if ($after === false) {
                    throw new StockRejected('Ein Artikel fehlt oder der maximale Bestand wurde erreicht.');
                }
                $this->record($db, $productId, 'restock', $quantity, (int) $after, null, $staffId);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public function restoreForCancellation(PDO $transaction, int $productId, int $quantity, int $orderId, int $staffId): void
    {
        if (!$transaction->inTransaction() || $quantity < 1) {
            throw new LogicException('Stock restoration requires an active transaction and a positive quantity.');
        }
        $restore = $transaction->prepare('UPDATE products SET stock = stock + :quantity
            WHERE id = :id AND stock <= :ceiling RETURNING stock');
        $restore->execute(['quantity' => $quantity, 'id' => $productId, 'ceiling' => 2147483647 - $quantity]);
        $after = $restore->fetchColumn();
        if ($after === false) {
            throw new StockRejected('Die Menge kann nicht zurück ins Lager gebucht werden.');
        }
        $this->record($transaction, $productId, 'cancellation', $quantity, (int) $after, $orderId, $staffId);
    }

    public function products(PDO $db): array
    {
        return $db->query('SELECT id, name, stock FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(PDO $db): array
    {
        return $db->query('SELECT m.*, p.name AS product_name, a.display_name AS staff_name
            FROM inventory_movements m JOIN products p ON p.id = m.product_id
            LEFT JOIN accounts a ON a.id = m.staff_account_id
            ORDER BY m.id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function record(PDO $db, int $productId, string $kind, int $change, int $stock, ?int $orderId, ?int $staffId): void
    {
        $log = $db->prepare('INSERT INTO inventory_movements
            (product_id, kind, quantity_change, stock_after, order_id, staff_account_id)
            VALUES (:product, :kind, :change, :stock, :order_id, :staff)');
        $log->execute(['product' => $productId, 'kind' => $kind, 'change' => $change,
            'stock' => $stock, 'order_id' => $orderId, 'staff' => $staffId]);
    }
}
