<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/accounts.php';

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

$stock = static fn(int $id): int => (int) $db->query("SELECT stock FROM products WHERE id = {$id}")->fetchColumn();
$count = static fn(): int => (int) $db->query('SELECT count(*) FROM orders')->fetchColumn();

expect(euro(1290), '12,90 €', 'Euro formatting');
$legacy = orderHistory($db, null, 1)[0];
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
    googleAccount($db, array_replace($google, ['sub' => 'another-subject', 'email' => 'test@example.test']));
    throw new LogicException('Google login must not take over a password account.');
} catch (AccountRejected) {
    echo "PASS: Google identity is not automatically linked by email\n";
}
$order = checkout($db, [1 => 2, 2 => 1], $customerId);
expect($order['total'], 4070, 'Accurate checkout total in cents');
expect($stock(1), 10, 'First product stock reduced');
expect($stock(2), 7, 'Second product stock reduced');
expect($count(), 2, 'One new order recorded');

try {
    checkout($db, [1 => 1, 2 => 20], $customerId);
    throw new LogicException('Checkout with insufficient stock should have failed.');
} catch (CheckoutRejected) {
    expect($stock(1), 10, 'Rollback restores previously reduced stock');
    expect($stock(2), 7, 'Unavailable product stock unchanged');
    expect($count(), 2, 'Rollback removes incomplete order');
}

try {
    checkout($db, [], $customerId);
    throw new LogicException('Empty checkout should have failed.');
} catch (CheckoutRejected) {
    expect($count(), 2, 'Empty checkout does not create an order');
}

try {
    checkout($db, [1 => 1], 0);
    throw new LogicException('Checkout without a valid customer should have failed.');
} catch (CheckoutRejected) {
    expect($stock(1), 10, 'Invalid customer does not reduce stock');
    expect($count(), 2, 'Invalid customer does not create an order');
}

$db->exec('UPDATE products SET price_cents = 1990 WHERE id = 1');
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
