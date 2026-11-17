<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/inventory.php';
require __DIR__ . '/../src/navigation.php';

startShopSession();
$notice = $_SESSION['inventory_notice'] ?? null;
unset($_SESSION['inventory_notice']);
$error = null;
try {
    $db = connect();
    $account = signedInAccount($db);
    if ($account === null || (int) $account['staff'] !== 1) {
        http_response_code(403);
        exit('Die Lagerverwaltung ist nur für Mitarbeitende verfügbar.');
    }
    $inventory = new PostgresInventory();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        try {
            $submitted = $_POST['quantities'] ?? null;
            if (!is_array($submitted) || count($submitted) > 100) {
                throw new StockRejected('Ungültige Nachfüllmengen.');
            }
            $quantities = [];
            foreach ($submitted as $id => $value) {
                if ($value === '') {
                    continue;
                }
                $quantity = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => MAX_RESTOCK_QUANTITY],
                ]) : false;
                if ($quantity === false) {
                    throw new StockRejected('Gib für jeden ausgewählten Artikel eine ganze Zahl zwischen 1 und 10.000 ein.');
                }
                $quantities[$id] = $quantity;
            }
            $inventory->restock($db, $quantities, (int) $account['id']);
            $_SESSION['inventory_notice'] = 'Lagerbestand aufgefüllt.';
            header('Location: /inventory.php', true, 303);
            exit;
        } catch (StockRejected $rejection) {
            $error = $rejection->getMessage();
        }
    }
    $products = $inventory->products($db);
    $history = $inventory->history($db);
} catch (PDOException $failure) {
    error_log($failure->getMessage());
    http_response_code(500);
    exit('Die Lagerverwaltung ist gerade nicht verfügbar.');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lager · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation('inventory', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero"><div class="wrap">
    <h1>Lager</h1>
    <p>Bestände nachfüllen und Lagerbewegungen nachvollziehen.</p>
</div></header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="warning" role="alert"><?= h($error) ?></p><?php endif; ?>
    <section class="card">
        <h2>Bestand auffüllen</h2>
        <p>Trage die Anzahl zusätzlicher Packungen ein. Leere Felder bleiben unverändert.</p>
        <?php if (!$products): ?>
            <p>Es sind noch keine Artikel im Lager erfasst.</p>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <ul class="inventory-list">
                <?php foreach ($products as $product): $id = (int) $product['id']; ?>
                    <li>
                        <strong><?= h($product['name']) ?></strong>
                        <span><?= (int) $product['stock'] ?> auf Lager</span>
                        <label for="restock-<?= $id ?>">Nachfüllen <span class="muted">(<?= h($product['name']) ?>)</span></label>
                        <input class="inventory-quantity" id="restock-<?= $id ?>" name="quantities[<?= $id ?>]"
                               type="number" min="1" max="<?= MAX_RESTOCK_QUANTITY ?>" step="1">
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="submit">Bestand auffüllen</button>
        </form>
        <?php endif; ?>
    </section>
    <section class="card inventory-history">
        <h2>Letzte Lagerbewegungen</h2>
        <?php if (!$history): ?><p>Noch keine Bewegungen seit Einführung des Lagerprotokolls.</p><?php endif; ?>
        <ul class="inventory-list">
            <?php foreach ($history as $move): ?>
                <li>
                    <strong><?= h($move['product_name']) ?></strong>
                    <span><?= $move['kind'] === 'restock' ? 'Nachfüllung +' : 'Bestellung ' ?><?= (int) $move['quantity_change'] ?></span>
                    <span><?= (int) $move['stock_after'] ?> danach auf Lager</span>
                    <span><?= (new DateTimeImmutable($move['created_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s') ?></span>
                    <?php if ($move['order_id'] !== null): ?>
                        <a href="/archive.php?order=<?= (int) $move['order_id'] ?>">Bestellung #<?= (int) $move['order_id'] ?></a>
                    <?php else: ?>
                        <span><?= h($move['staff_name'] ?? 'Mitarbeitende') ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</main>
</body>
</html>
