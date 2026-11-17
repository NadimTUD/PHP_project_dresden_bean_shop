<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';

$rows = connect()->query(file_get_contents(__DIR__ . '/../database/sales.sql'))->fetchAll(PDO::FETCH_ASSOC);
echo "Verkaufsbericht\n";
if ($rows === []) {
    echo "Noch keine Bestellungen.\n";
}
foreach ($rows as $row) {
    printf("%s: %d Stück · %s\n", $row['name'], $row['units'], euro((int) $row['revenue_cents']));
}
