<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';
require __DIR__ . '/../src/order-workflow.php';

startShopSession();

function positiveQuery(string $name): ?int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return is_int($value) ? $value : null;
}

$orderId = positiveQuery('order');
$page = min(100000, positiveQuery('page') ?? 1);
$notice = $_SESSION['order_notice'] ?? null;
$statusError = $_SESSION['order_error'] ?? null;
unset($_SESSION['order_notice'], $_SESSION['order_error']);

try {
    $db = connect();
    $account = signedInAccount($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($account === null || (int) $account['staff'] !== 1)) {
        http_response_code(403);
        exit('Nur Mitarbeitende können Bestellungen bearbeiten.');
    }
    if ($account === null) {
        header('Location: /account.php', true, 303);
        exit;
    }
    $staff = (int) $account['staff'] === 1;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        $targetId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        $rawStatus = $_POST['status'] ?? null;
        $rawExpected = $_POST['expected'] ?? null;
        $next = is_string($rawStatus) ? OrderStatus::tryFrom($rawStatus) : null;
        $expected = is_string($rawExpected) ? OrderStatus::tryFrom($rawExpected) : null;
        try {
            if (!$targetId || $next === null || $expected === null) {
                throw new OrderStatusRejected('Ungültiger Bestellstatus.');
            }
            moveOrder($db, $targetId, $expected, $next, (int) $account['id'], new PostgresInventory());
            $_SESSION['order_notice'] = $next === OrderStatus::Cancelled
                ? 'Bestellung storniert und Bestand zurückgebucht.' : 'Bestellstatus gespeichert.';
        } catch (OrderStatusRejected | StockRejected $rejection) {
            $_SESSION['order_error'] = $rejection->getMessage();
        }
        header('Location: /archive.php' . ($targetId ? '?order=' . $targetId : ''), true, 303);
        exit;
    }
    $legacy = $staff && ($_GET['customer'] ?? null) === 'legacy';
    $customerId = $staff ? positiveQuery('customer') : (int) $account['id'];
    $customers = $staff ? $db->query('SELECT id, display_name, email FROM accounts ORDER BY display_name, id')
        ->fetchAll(PDO::FETCH_ASSOC) : [];
    $orders = orderHistory($db, $customerId, $orderId, ($page - 1) * 20, $legacy);
    $events = [];
    if ($orderId !== null && $orders !== []) {
        // Only load events after the order passed the account/role filter.
        $log = $db->prepare('SELECT previous_status, next_status, created_at FROM order_status_events
            WHERE order_id = :id ORDER BY id');
        $log->execute(['id' => $orderId]);
        $events = $log->fetchAll(PDO::FETCH_ASSOC);
    }
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
<?php shopNavigation('archive', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero">
    <div class="wrap">
        <h1>Bestellarchiv</h1>
        <p><?= $staff ? 'Mitarbeiteransicht · Alle Bestellungen' : 'Deine Bestellungen mit Datum, Uhrzeit und Artikeln' ?></p>
    </div>
</header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($statusError !== null): ?><p class="warning" role="alert"><?= h($statusError) ?></p><?php endif; ?>
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
    <?php foreach ($orders as $order): $status = OrderStatus::from($order['status']); ?>
        <details class="card archive-order" <?= $orderId !== null ? 'open' : '' ?>>
            <summary>
                <span class="order-line">
                    <strong>Bestellung #<?= $order['id'] ?></strong>
                    <span><?= h($order['customer_name'] ?? 'Ohne Kundenprofil') ?></span>
                    <span><?= (new DateTimeImmutable($order['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s') ?></span>
                    <strong><?= euro($order['total']) ?></strong>
                    <span class="order-status"><?= h($status->label()) ?></span>
                </span>
            </summary>
            <ul>
                <?php foreach ($order['items'] as $item): ?>
                    <li><?= (int) $item['quantity'] ?> × <?= h($item['product_name']) ?> ·
                        <?= euro((int) $item['unit_price_cents']) ?> pro Packung</li>
                <?php endforeach; ?>
            </ul>
            <?php if ($staff && $status->allowedNext() !== []): ?>
                <form method="post" class="filters order-actions">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <input type="hidden" name="expected" value="<?= h($status->value) ?>">
                    <?php foreach ($status->allowedNext() as $next): ?>
                        <button type="submit" name="status" value="<?= h($next->value) ?>"
                                <?= $next === OrderStatus::Cancelled ? 'class="secondary"' : '' ?>>
                            <?= match ($next) {
                                OrderStatus::Processing => 'Bearbeitung starten', OrderStatus::Completed => 'Abschließen',
                                OrderStatus::Cancelled => 'Stornieren und Bestand zurückbuchen',
                                default => h($next->label()),
                            } ?>
                        </button>
                    <?php endforeach; ?>
                </form>
            <?php endif; ?>
            <?php if ($orderId === null && $status !== OrderStatus::Legacy): ?>
                <p><a href="/archive.php?order=<?= (int) $order['id'] ?>">Bestellverlauf ansehen</a></p>
            <?php elseif ($events !== []): ?>
                <h3>Bestellverlauf</h3>
                <ul>
                    <?php foreach ($events as $event): ?>
                        <li><?= h(OrderStatus::from($event['next_status'])->label()) ?>,
                            <?= (new DateTimeImmutable($event['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
