<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';

startShopSession();

function positiveQuery(string $name): ?int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return is_int($value) ? $value : null;
}

$orderId = positiveQuery('order');
$page = min(100000, positiveQuery('page') ?? 1);

try {
    $db = connect();
    $account = signedInAccount($db);
    if ($account === null) {
        header('Location: /account.php', true, 303);
        exit;
    }
    $staff = (int) $account['staff'] === 1;
    $legacy = $staff && ($_GET['customer'] ?? null) === 'legacy';
    $customerId = $staff ? positiveQuery('customer') : (int) $account['id'];
    $customers = $staff ? $db->query('SELECT id, display_name, email FROM accounts ORDER BY display_name, id')
        ->fetchAll(PDO::FETCH_ASSOC) : [];
    $orders = orderHistory($db, $customerId, $orderId, ($page - 1) * 20, $legacy);
} catch (PDOException $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Das Bestellarchiv ist gerade nicht verfügbar.');
}

$hasNext = count($orders) > 20;
$orders = array_slice($orders, 0, 20);
$pageUrl = static fn(int $number): string => '/archive.php?' . http_build_query([
    'customer' => $staff ? ($legacy ? 'legacy' : $customerId) : null, 'order' => $orderId, 'page' => $number,
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
        <p><?= $staff ? 'Mitarbeiteransicht · Alle Bestellungen' : 'Deine Bestellungen mit Datum, Uhrzeit und Artikeln' ?></p>
        <nav class="shop-nav" aria-label="Hauptnavigation">
            <a class="nav-button" href="/">Shop</a>
            <a class="nav-button" href="/account.php">Mein Konto</a>
        </nav>
    </div>
</header>
<main class="wrap">
    <form class="card filters" method="get">
        <?php if ($staff): ?>
        <label for="customer">Kundenprofil</label>
        <select id="customer" name="customer">
            <option value="">Alle</option>
            <option value="legacy" <?= $legacy ? 'selected' : '' ?>>Bestellungen ohne Konto</option>
            <?php foreach ($customers as $customer): ?>
                <option value="<?= (int) $customer['id'] ?>" <?= $customerId === (int) $customer['id'] ? 'selected' : '' ?>>
                    <?= h($customer['display_name']) ?> (<?= h($customer['email']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
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
                <?= (new DateTimeImmutable($order['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s') ?> ·
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
