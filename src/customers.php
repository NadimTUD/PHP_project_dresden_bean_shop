<?php
declare(strict_types=1);

function customerProfiles(PDO $db): array
{
    return $db->query('SELECT id, display_name FROM customers ORDER BY display_name')->fetchAll(PDO::FETCH_ASSOC);
}

function createCustomer(PDO $db, mixed $name): bool
{
    // Aliases only: no email, password, address, or payment details.
    if (!is_string($name) || !preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} .\-]{1,59}\z/u', trim($name))) {
        throw new CheckoutRejected('Bitte gib einen Demo-Namen mit 2 bis 60 Zeichen ein.');
    }
    $stmt = $db->prepare(
        'INSERT INTO customers (display_name) VALUES (:name) ON CONFLICT (display_name) DO NOTHING RETURNING id'
    );
    $stmt->execute(['name' => trim($name)]);
    return $stmt->fetchColumn() !== false;
}

function orderHistory(PDO $db, ?int $customerId, ?int $orderId, int $offset = 0): array
{
    // Limit orders before joining their items so an order is never split between pages.
    $filters = [];
    $params = [];
    if ($customerId !== null) {
        $filters[] = 'customer_id = :customer';
        $params['customer'] = $customerId;
    }
    if ($orderId !== null) {
        $filters[] = 'id = :order_id';
        $params['order_id'] = $orderId;
    }
    $where = $filters === [] ? '' : 'WHERE ' . implode(' AND ', $filters);
    $stmt = $db->prepare(
        "WITH recent AS (
            SELECT id, customer_name, created_at FROM orders
            {$where} ORDER BY id DESC LIMIT 21 OFFSET :offset
         )
         SELECT o.id, o.customer_name, o.created_at, i.product_name, i.quantity, i.unit_price_cents
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
            'items' => [], 'total' => 0,
        ];
        $orders[$id]['items'][] = $row;
        $orders[$id]['total'] += (int) $row['quantity'] * (int) $row['unit_price_cents'];
    }
    return array_values($orders);
}
