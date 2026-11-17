<?php
declare(strict_types=1);

function orderHistory(PDO $db, ?int $accountId, ?int $orderId, int $offset = 0, bool $legacyOnly = false): array
{
    // Limit orders before joining their items so an order is never split between pages.
    $filters = [];
    $params = [];
    if ($accountId !== null) {
        $filters[] = 'account_id = :account';
        $params['account'] = $accountId;
    }
    if ($legacyOnly) {
        $filters[] = 'account_id IS NULL';
    }
    if ($orderId !== null) {
        $filters[] = 'id = :order_id';
        $params['order_id'] = $orderId;
    }
    $where = $filters === [] ? '' : 'WHERE ' . implode(' AND ', $filters);
    $stmt = $db->prepare(
        "WITH recent AS (
            SELECT id, customer_name, created_at, status FROM orders
            {$where} ORDER BY id DESC LIMIT 21 OFFSET :offset
         )
         SELECT o.id, o.customer_name, o.created_at, o.status, i.product_name, i.quantity, i.unit_price_cents
         FROM recent AS o
         JOIN order_items AS i ON i.order_id = o.id
         ORDER BY o.id DESC, i.product_id"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $orders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $orders[$id] ??= [
            'id' => $id, 'customer_name' => $row['customer_name'], 'created_at' => $row['created_at'],
            'status' => $row['status'], 'items' => [], 'total' => 0,
        ];
        $orders[$id]['items'][] = $row;
        $orders[$id]['total'] += (int) $row['quantity'] * (int) $row['unit_price_cents'];
    }
    return array_values($orders);
}
