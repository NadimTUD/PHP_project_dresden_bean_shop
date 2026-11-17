<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';

enum OrderStatus: string
{
    case Legacy = 'legacy';
    case Placed = 'placed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Altbestand', self::Placed => 'Neu',
            self::Processing => 'In Bearbeitung', self::Completed => 'Abgeschlossen',
            self::Cancelled => 'Storniert',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::Placed => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Cancelled],
            default => [],
        };
    }
}

final class OrderStatusRejected extends RuntimeException {}

function openOrdersForDashboard(PDO $db): array
{
    return $db->query("SELECT id, customer_name, status, created_at FROM orders
        WHERE status IN ('placed', 'processing')
        ORDER BY (status = 'placed') DESC, created_at, id LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
}

function moveOrder(PDO $db, int $orderId, OrderStatus $expected, OrderStatus $next, int $staffId, InventoryForCancellations $inventory): void
{
    $db->beginTransaction();
    try {
        $staff = $db->prepare('SELECT 1 FROM accounts WHERE id = :id AND is_staff FOR SHARE');
        $staff->execute(['id' => $staffId]);
        if ($staff->fetchColumn() === false) {
            throw new OrderStatusRejected('Nur Mitarbeitende können Bestellungen bearbeiten.');
        }

        // Serialize status changes and detect stale forms before touching stock.
        $order = $db->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
        $order->execute(['id' => $orderId]);
        $value = $order->fetchColumn();
        if ($value === false) {
            throw new OrderStatusRejected('Bestellung nicht gefunden.');
        }
        $previous = OrderStatus::from($value);
        if ($previous !== $expected || !in_array($next, $previous->allowedNext(), true)) {
            throw new OrderStatusRejected('Der Status kann nicht geändert werden. Lade die Bestellung neu.');
        }

        if ($next === OrderStatus::Cancelled) {
            $items = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :id ORDER BY product_id');
            $items->execute(['id' => $orderId]);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $inventory->restoreForCancellation($db, (int) $item['product_id'], (int) $item['quantity'], $orderId, $staffId);
            }
        }
        $change = $db->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $change->execute(['status' => $next->value, 'id' => $orderId]);
        $log = $db->prepare('INSERT INTO order_status_events (order_id, previous_status, next_status, staff_account_id)
            VALUES (:id, :previous, :next, :staff)');
        $log->execute(['id' => $orderId, 'previous' => $previous->value, 'next' => $next->value, 'staff' => $staffId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
