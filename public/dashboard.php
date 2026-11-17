<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';
require __DIR__ . '/../src/order-workflow.php';

startShopSession();
try {
    $db = connect();
    $account = signedInAccount($db);
    if ($account === null || (int) $account['staff'] !== 1) {
        http_response_code(403);
        exit('Das Dashboard ist nur für Mitarbeitende verfügbar.');
    }
    $inventory = new PostgresInventory();
    $reorder = $inventory->reorderList($db);
    $orders = openOrdersForDashboard($db);
    $movements = $inventory->history($db, 6);
} catch (PDOException $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Das Dashboard ist gerade nicht verfügbar.');
}
$berlin = new DateTimeZone('Europe/Berlin');
$time = static fn(string $value): string => (new DateTimeImmutable($value))->setTimezone($berlin)->format('d.m., H:i');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation('dashboard', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero dashboard-hero"><div class="wrap">
    <h1>Mitarbeiter-Dashboard</h1>
    <p>Bestellungen bearbeiten und Engpässe im Lager sehen.</p>
</div></header>
<main class="wrap">
    <section class="reorder-panel" aria-labelledby="reorder-heading">
        <div class="dashboard-section-head">
            <div>
                <h2 id="reorder-heading">Nachbestellliste</h2>
                <p>Röstungen unter ihrem Mindestbestand.</p>
            </div>
            <a href="/inventory.php">Mindestbestände einstellen</a>
        </div>
        <?php if (!$reorder): ?>
            <p class="dashboard-empty">Kein Artikel liegt unter dem Mindestbestand.</p>
        <?php else: ?>
            <ul class="dashboard-list">
                <?php foreach ($reorder as $product): ?>
                    <li class="reorder-row">
                        <div>
                            <h3><?= h($product['name']) ?></h3>
                            <p><?= (int) $product['stock'] ?> auf Lager / Mindestbestand <?= (int) $product['reorder_threshold'] ?></p>
                        </div>
                        <p><strong><?= (int) $product['shortfall'] ?></strong> fehlen bis zum Mindestbestand</p>
                        <a class="primary" href="/inventory.php#restock-<?= (int) $product['id'] ?>">Bestand auffüllen</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="account-grid dashboard-grid">
        <section class="card">
            <div class="dashboard-section-head">
                <h2>Offene Bestellungen</h2><a href="/archive.php">Zum Archiv</a>
            </div>
            <?php if (!$orders): ?><p>Keine offenen Bestellungen.</p><?php endif; ?>
            <ul class="dashboard-list">
                <?php foreach ($orders as $order): ?>
                    <li class="dashboard-row">
                        <div>
                            <a href="/archive.php?order=<?= (int) $order['id'] ?>">Bestellung #<?= (int) $order['id'] ?></a>
                            <p class="muted"><?= h($order['customer_name'] ?? 'Ohne Kundenprofil') ?>, <?= $time($order['created_at']) ?></p>
                        </div>
                        <span class="order-status"><?= h(OrderStatus::from($order['status'])->label()) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="card">
            <div class="dashboard-section-head">
                <h2>Letzte Lagerbewegungen</h2><a href="/inventory.php">Zum Lager</a>
            </div>
            <?php if (!$movements): ?><p>Noch keine Lagerbewegungen.</p><?php endif; ?>
            <ul class="dashboard-list">
                <?php foreach ($movements as $move): ?>
                    <li class="dashboard-row">
                        <div>
                            <strong><?= h($move['product_name']) ?></strong>
                            <p class="muted"><?= match ($move['kind']) {
                                'restock' => 'Nachfüllung', 'cancellation' => 'Storno', default => 'Verkauf',
                            } ?>, <?= $time($move['created_at']) ?></p>
                        </div>
                        <span><?= sprintf('%+d', $move['quantity_change']) ?> Packungen</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</main>
</body>
</html>
