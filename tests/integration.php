<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';

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

$stock = static fn(int $id): int => (int) $db->query("SELECT stock FROM products WHERE id = {$id}")->fetchColumn();
$count = static fn(): int => (int) $db->query('SELECT count(*) FROM orders')->fetchColumn();

expect(euro(1290), '12,90 €', 'Euro formatting');
$order = checkout($db, [1 => 2, 2 => 1]);
expect($order['total'], 4070, 'Accurate checkout total in cents');
expect($stock(1), 10, 'First product stock reduced');
expect($stock(2), 7, 'Second product stock reduced');
expect($count(), 1, 'One order recorded');

try {
    checkout($db, [1 => 1, 2 => 20]);
    throw new LogicException('Checkout with insufficient stock should have failed.');
} catch (CheckoutRejected) {
    expect($stock(1), 10, 'Rollback restores previously reduced stock');
    expect($stock(2), 7, 'Unavailable product stock unchanged');
    expect($count(), 1, 'Rollback removes incomplete order');
}

try {
    checkout($db, []);
    throw new LogicException('Empty checkout should have failed.');
} catch (CheckoutRejected) {
    expect($count(), 1, 'Empty checkout does not create an order');
}

$db->exec('UPDATE products SET price_cents = 1990 WHERE id = 1');
$rows = $db->query(file_get_contents(__DIR__ . '/../database/sales.sql'))->fetchAll(PDO::FETCH_ASSOC);
expect($rows[0]['name'], 'Elbe Espresso', 'Report sorts by revenue');
expect((int) $rows[0]['units'], 2, 'Report sums quantities');
expect((int) $rows[0]['revenue_cents'], 2580, 'Report uses historical sale price');
