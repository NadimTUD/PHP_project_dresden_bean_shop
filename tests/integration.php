<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/order-workflow.php';

function expect(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        throw new LogicException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
    echo "PASS: {$label}\n";
}

$db = connect();
// Session-local copies of the real schema; demo data stays untouched.
$schema = file_get_contents(__DIR__ . '/../database/01-schema.sql');
$db->exec(str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $schema));
$db->exec(file_get_contents(__DIR__ . '/../database/02-seed.sql'));
$db->exec('INSERT INTO orders DEFAULT VALUES');
$db->exec('INSERT INTO order_items (order_id, product_id, quantity, unit_price_cents) VALUES (1, 3, 1, 1190)');
$migration = str_replace('CREATE TABLE IF NOT EXISTS customers', 'CREATE TEMP TABLE IF NOT EXISTS customers',
    file_get_contents(__DIR__ . '/../database/03-customers.sql'));
$db->exec($migration);
$db->exec($migration);
$accountsMigration = str_replace('CREATE TABLE IF NOT EXISTS accounts', 'CREATE TEMP TABLE IF NOT EXISTS accounts',
    file_get_contents(__DIR__ . '/../database/04-accounts.sql'));
$db->exec($accountsMigration);
$db->exec($accountsMigration);
$inventoryMigration = str_replace('CREATE TABLE IF NOT EXISTS inventory_movements',
    'CREATE TEMP TABLE IF NOT EXISTS inventory_movements', file_get_contents(__DIR__ . '/../database/05-inventory.sql'));
$db->exec($inventoryMigration);
$db->exec($inventoryMigration);
$workflowMigration = str_replace('CREATE TABLE IF NOT EXISTS order_status_events',
    'CREATE TEMP TABLE IF NOT EXISTS order_status_events', file_get_contents(__DIR__ . '/../database/06-order-workflow.sql'));
$db->exec($workflowMigration);
$db->exec($workflowMigration);
$reorderMigration = file_get_contents(__DIR__ . '/../database/07-reorder-threshold.sql');
$db->exec($reorderMigration);
$db->exec($reorderMigration);
$inventory = new PostgresInventory();

$stock = static fn(int $id): int => (int) $db->query("SELECT stock FROM products WHERE id = {$id}")->fetchColumn();
$count = static fn(): int => (int) $db->query('SELECT count(*) FROM orders')->fetchColumn();

expect(euro(1290), '12,90 €', 'Euro formatting');
$legacy = orderHistory($db, null, 1)[0];
expect($legacy['status'], 'legacy', 'Migration does not invent a workflow status for old orders');
expect($legacy['customer_name'], null, 'Migration preserves old orders without assigning a customer');
expect($legacy['items'][0]['product_name'], 'Plauen Blend', 'Migration backfills old item labels');
expect((int) $db->query('SELECT count(*) FROM customers')->fetchColumn(), 2, 'Migration retains legacy profiles');
$customerId = createAccount($db, 'Demo Test', 'TEST@example.test', 'demo-password-2026');
expect(passwordAccount($db, 'test@example.test', 'demo-password-2026'), $customerId, 'Signs in with normalized email');
$otherId = createAccount($db, 'Second Customer', 'other@example.test', 'demo-password-2026');
$google = ['sub' => 'test-subject-1', 'email_verified' => true, 'email' => 'google@example.test', 'name' => 'Google Test'];
$googleId = googleAccount($db, $google);
expect(googleAccount($db, $google), $googleId, 'Google identity is reused by subject');
try {
    changeAccountPassword($db, $googleId, 'test-only-password', 'another-test-password', 'another-test-password');
    throw new LogicException('A Google-only account must not gain a password through this screen.');
} catch (AccountRejected) {
    echo "PASS: Google-only accounts cannot change a nonexistent shop password\n";
}
try {
    googleAccount($db, array_replace($google, ['sub' => 'another-subject', 'email' => 'test@example.test']));
    throw new LogicException('Google login must not take over a password account.');
} catch (AccountRejected) {
    echo "PASS: Google identity is not automatically linked by email\n";
}
$order = checkout($db, [1 => 2, 2 => 1], $customerId, $inventory);
expect($order['total'], 4070, 'Accurate checkout total in cents');
expect($stock(1), 10, 'First product stock reduced');
expect($stock(2), 7, 'Second product stock reduced');
expect($count(), 2, 'One new order recorded');
expect((int) openOrdersForDashboard($db)[0]['id'], $order['id'], 'Dashboard shows the new order');

try {
    checkout($db, [1 => 1, 2 => 20], $customerId, $inventory);
    throw new LogicException('Checkout with insufficient stock should have failed.');
} catch (CheckoutRejected) {
    expect($stock(1), 10, 'Rollback restores previously reduced stock');
    expect($stock(2), 7, 'Unavailable product stock unchanged');
    expect($count(), 2, 'Rollback removes incomplete order');
    expect(count($inventory->history($db)), 2, 'Rollback leaves no movement from the failed sale');
}

try {
    checkout($db, [], $customerId, $inventory);
    throw new LogicException('Empty checkout should have failed.');
} catch (CheckoutRejected) {
    expect($count(), 2, 'Empty checkout does not create an order');
}

try {
    checkout($db, [1 => 1], 0, $inventory);
    throw new LogicException('Checkout without a valid customer should have failed.');
} catch (CheckoutRejected) {
    expect($stock(1), 10, 'Invalid customer does not reduce stock');
    expect($count(), 2, 'Invalid customer does not create an order');
}

$db->exec('UPDATE products SET price_cents = 1990 WHERE id = 1');
try {
    $inventory->restock($db, [1 => 5], $customerId);
    throw new LogicException('A customer must not be allowed to restock.');
} catch (StockRejected) {
    expect($stock(1), 10, 'Non-staff restock does not change stock');
}
$db->exec("UPDATE accounts SET is_staff = true WHERE id = {$otherId}");
try {
    $inventory->setReorderThreshold($db, 1, 12, $customerId);
    throw new LogicException('A customer must not edit the reorder threshold.');
} catch (StockRejected) {
    expect($inventory->reorderList($db), [], 'Unauthorized threshold change is blocked');
}
$inventory->setReorderThreshold($db, 1, 17, $otherId);
try {
    $inventory->restock($db, [1 => 5, 999999 => 1], $otherId);
    throw new LogicException('An unknown product must roll back all restocks.');
} catch (StockRejected) {
    expect($stock(1), 10, 'Failed batch rolls back earlier stock increments');
    expect(count($inventory->history($db)), 2, 'Failed batch leaves no movement');
}
$inventory->restock($db, [1 => 5, 2 => 2], $otherId);
expect($stock(1), 15, 'Restock adds to current stock');
expect($stock(2), 9, 'Restock can update several products at once');
expect($count(), 2, 'Restock does not create orders');
$moves = $inventory->history($db);
expect(count($moves), 4, 'Sales and restocks are recorded separately');
expect($moves[0]['kind'], 'restock', 'Latest movement is a restock');
expect((int) $moves[0]['staff_account_id'], $otherId, 'Restock is attributed to staff');
expect((int) $moves[2]['order_id'], $order['id'], 'Sale movement links to order');
$rows = $db->query(file_get_contents(__DIR__ . '/../database/sales.sql'))->fetchAll(PDO::FETCH_ASSOC);
expect($rows[0]['name'], 'Elbe Espresso', 'Report sorts by revenue');
expect((int) $rows[0]['units'], 2, 'Report sums quantities');
expect((int) $rows[0]['revenue_cents'], 2580, 'Report uses historical sale price');
$db->exec("UPDATE products SET name = 'New catalog name' WHERE id = 1");
updateAccount($db, $customerId, 'New demo alias', 'updated@example.test');
expect(passwordAccount($db, 'updated@example.test', 'demo-password-2026'), $customerId, 'Profile email can be updated');
$history = orderHistory($db, $customerId, null);
expect(count($history), 1, 'Customer filter only returns matching orders');
expect($history[0]['id'], $order['id'], 'Archive finds the new order by customer');
expect($history[0]['customer_name'], 'Demo Test', 'Archive keeps the name at checkout');
expect($history[0]['items'][0]['product_name'], 'Elbe Espresso', 'Archive keeps the item name at checkout');
expect($history[0]['total'], 4070, 'Archive calculates the historical order total');
expect(orderHistory($db, $customerId, 1), [], 'Combined filters exclude the older guest order');
expect(orderHistory($db, $otherId, $order['id']), [], 'Another account cannot select the order');
expect(count(orderHistory($db, null, null, 0, true)), 1, 'Staff legacy filter finds orders without an account');

try {
    moveOrder($db, $order['id'], OrderStatus::Placed, OrderStatus::Processing, $customerId, $inventory);
    throw new LogicException('A customer must not change order status.');
} catch (OrderStatusRejected) {
    expect(orderHistory($db, $customerId, $order['id'])[0]['status'], 'placed', 'Non-staff status change rejected');
}
moveOrder($db, $order['id'], OrderStatus::Placed, OrderStatus::Processing, $otherId, $inventory);
expect(openOrdersForDashboard($db)[0]['status'], 'processing', 'Processing orders remain in the dashboard queue');
expect((int) $inventory->reorderList($db)[0]['shortfall'], 2, 'Dashboard reports stock shortage before cancellation');
moveOrder($db, $order['id'], OrderStatus::Processing, OrderStatus::Cancelled, $otherId, $inventory);
expect($stock(1), 17, 'Cancellation restores both units of the first product');
expect($stock(2), 10, 'Cancellation restores the second product');
expect(orderHistory($db, $customerId, $order['id'])[0]['status'], 'cancelled', 'Customer sees the cancelled status');
expect(openOrdersForDashboard($db), [], 'Cancelled order drops out of the dashboard queue');
expect($inventory->reorderList($db), [], 'Returning stock to the threshold clears the reorder warning');
expect(count($inventory->history($db, 2)), 2, 'Short movement list respects the dashboard limit');
try {
    moveOrder($db, $order['id'], OrderStatus::Processing, OrderStatus::Cancelled, $otherId, $inventory);
    throw new LogicException('Repeated cancellation must not restore stock twice.');
} catch (OrderStatusRejected) {
    expect($stock(1), 17, 'Repeated cancellation leaves stock unchanged');
    expect(count($inventory->history($db)), 6, 'Repeated cancellation adds no inventory movements');
}
expect((int) $db->query('SELECT count(*) FROM order_status_events')->fetchColumn(), 2, 'Both valid changes are audited');
$remainingSales = $db->query(file_get_contents(__DIR__ . '/../database/sales.sql'))->fetchAll(PDO::FETCH_ASSOC);
expect(count($remainingSales), 1, 'Cancelled sales are omitted from reporting');
