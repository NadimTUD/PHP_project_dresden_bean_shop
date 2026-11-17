<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/html.php';

function positiveQuery(string $name): ?int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return is_int($value) ? $value : null;
}

$customerId = positiveQuery('customer');
$orderId = positiveQuery('order');
$page = min(100000, positiveQuery('page') ?? 1);

try {
    $db = connect();
    $customers = customerProfiles($db);
    $orders = orderHistory($db, $customerId, $orderId, ($page - 1) * 20);
} catch (PDOException $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Das Bestellarchiv ist gerade nicht verfügbar.');
}

$hasNext = count($orders) > 20;
$orders = array_slice($orders, 0, 20);
$pageUrl = static fn(int $number): string => '/archive.php?' . http_build_query([
    'customer' => $customerId, 'order' => $orderId, 'page' => $number,
]);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestellarchiv · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<header class="hero">
    <div class="wrap">
        <p class="eyebrow">DRESDEN BEAN SHOP</p>
        <h1>Bestellarchiv</h1>
        <p>Nach Demo-Kundenprofil oder Bestellnummer filtern.</p>
        <a href="/">Zurück zum Shop</a>
    </div>
</header>
<main class="wrap">
    <p class="muted">Lokale Demo: Das Archiv ist ohne Anmeldung sichtbar. Keine echten Kundendaten eingeben.</p>
    <form class="card filters" method="get">
        <label for="customer">Kundenprofil</label>
        <select id="customer" name="customer">
            <option value="">Alle</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?= (int) $customer['id'] ?>" <?= $customerId === (int) $customer['id'] ? 'selected' : '' ?>>
                    <?= h($customer['display_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="order">Bestellnummer</label>
        <input class="wide" id="order" name="order" type="number" min="1" max="2147483647" value="<?= $orderId ?? '' ?>">
        <button>Filtern</button>
        <a href="/archive.php">Zurücksetzen</a>
    </form>
    <?php if ($orders === []): ?>
        <p>Keine passenden Bestellungen.</p>
    <?php endif; ?>
    <?php foreach ($orders as $order): ?>
        <details class="card archive-order" <?= $orderId !== null ? 'open' : '' ?>>
            <summary>
                <strong>Bestellung #<?= $order['id'] ?></strong> ·
                <?= h($order['customer_name'] ?? 'Ohne Kundenprofil') ?> ·
                <?= (new DateTimeImmutable($order['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i') ?> ·
                <strong><?= euro($order['total']) ?></strong>
            </summary>
            <ul>
                <?php foreach ($order['items'] as $item): ?>
                    <li><?= (int) $item['quantity'] ?> × <?= h($item['product_name']) ?> ·
                        <?= euro((int) $item['unit_price_cents']) ?> pro Packung</li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
    <?php if ($page > 1 || $hasNext): ?>
        <nav class="filters" aria-label="Archivseiten">
            <?php if ($page > 1): ?><a href="<?= h($pageUrl($page - 1)) ?>">Vorherige Seite</a><?php endif; ?>
            <span>Seite <?= $page ?></span>
            <?php if ($hasNext && $page < 100000): ?><a href="<?= h($pageUrl($page + 1)) ?>">Nächste Seite</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
