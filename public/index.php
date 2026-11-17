<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';

function redirect(): never
{
    header('Location: /', true, 303);
    exit;
}

startShopSession();
$cart = $_SESSION['cart'] ?? [];

try {
    $db = connect();
    $account = signedInAccount($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();

        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'checkout') {
                if ($account === null) {
                    throw new CheckoutRejected('Bitte melde dich an, um zu bestellen.');
                }
                $order = checkout($db, $cart, (int) $account['id']);
                $_SESSION['cart'] = [];
                $_SESSION['last_order'] = $order['id'];
                $_SESSION['flash'] = "Bestellung #{$order['id']} erfasst · " . euro($order['total']);
            } elseif ($action === 'quantity') {
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => MAX_QUANTITY],
                ]);
                if (!$id || $quantity === false || $quantity === null) {
                    throw new CheckoutRejected('Bitte gib eine Menge zwischen 0 und 20 ein.');
                }
                if ($quantity === 0) {
                    unset($cart[$id]);
                } else {
                    $check = $db->prepare('SELECT stock FROM products WHERE id = :id');
                    $check->execute(['id' => $id]);
                    if ((int) $check->fetchColumn() < $quantity) {
                        throw new CheckoutRejected('Diese Menge ist gerade nicht verfügbar.');
                    }
                    $cart[$id] = $quantity;
                }
                $_SESSION['cart'] = $cart;
                $_SESSION['flash'] = 'Warenkorb aktualisiert.';
            } else {
                http_response_code(400);
                exit('Unbekannte Aktion.');
            }
        } catch (CheckoutRejected $error) {
            $_SESSION['flash_error'] = $error->getMessage();
        }
        redirect();
    }
    $products = $db->query('SELECT id, name, notes, price_cents, stock FROM products ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $error) {
    error_log($error->getMessage());
    http_response_code(500);
    exit('Die Datenbank ist gerade nicht verfügbar.');
}

$notice = $_SESSION['flash'] ?? null;
$warning = $_SESSION['flash_error'] ?? null;
$lastOrder = $_SESSION['last_order'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_error'], $_SESSION['last_order']);
$total = 0;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation('shop', $account, array_sum($cart)); ?>
<header class="hero shop-hero">
    <div class="wrap">
        <h1>Dresden Bean Shop</h1>
        <p>Drei Röstungen, ein Warenkorb. Ein Demo-Shop mit PHP und PostgreSQL.</p>
        <a class="primary" href="#roasts">Röstungen entdecken</a>
    </div>
</header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($warning !== null): ?><p class="warning" role="alert"><?= h($warning) ?></p><?php endif; ?>
    <?php if ($lastOrder !== null): ?>
        <p><a href="/archive.php?order=<?= (int) $lastOrder ?>">Bestellung im Archiv ansehen</a></p>
    <?php endif; ?>
    <h2 id="roasts">Unsere Röstungen</h2>
    <div class="catalog">
        <?php foreach ($products as $product):
            $id = (int) $product['id'];
            $stock = (int) $product['stock'];
        ?>
        <article class="roast">
            <div>
                <h3><?= h($product['name']) ?></h3>
                <p class="muted"><?= h(str_replace(' · ', ' / ', $product['notes'])) ?></p>
            </div>
            <div>
                <p class="roast-price"><strong><?= euro((int) $product['price_cents']) ?></strong> / 250 g</p>
                <p class="muted"><?= $stock > 0 ? "{$stock} verfügbar" : 'Ausverkauft' ?></p>
            </div>
            <?php if ($stock > 0): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="quantity">
                <input type="hidden" name="id" value="<?= $id ?>">
                <label for="quantity-<?= $id ?>">Menge im Warenkorb</label>
                <div class="controls">
                    <input id="quantity-<?= $id ?>" name="quantity" type="number" min="1"
                           max="<?= min(MAX_QUANTITY, $stock) ?>" value="<?= min($stock, $cart[$id] ?? 1) ?>" required>
                    <button type="submit">Menge speichern</button>
                </div>
            </form>
            <?php else: ?><span></span><?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <section class="cart" id="cart">
        <h2>Warenkorb</h2>
        <?php if ($cart === []): ?>
            <p>Dein Warenkorb ist leer.</p>
        <?php else: ?>
            <ul class="cart-list">
                <?php foreach ($products as $product):
                    $id = (int) $product['id'];
                    if (!isset($cart[$id])) { continue; }
                    $subtotal = $cart[$id] * (int) $product['price_cents'];
                    $total += $subtotal;
                ?>
                <li>
                    <span><?= $cart[$id] ?> × <?= h($product['name']) ?> <strong><?= euro($subtotal) ?></strong></span>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="quantity">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="quantity" value="0">
                        <button class="secondary" aria-label="<?= h($product['name']) ?> entfernen">Entfernen</button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <p><strong>Gesamt: <?= euro($total) ?></strong></p>
            <?php if ($account === null): ?>
                <p><a href="/account.php">Anmelden oder Konto erstellen, um zu bestellen</a></p>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="checkout">
                <p>Bestellung für <strong><?= h($account['display_name']) ?></strong></p>
                <button>Demo-Bestellung abschließen</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
