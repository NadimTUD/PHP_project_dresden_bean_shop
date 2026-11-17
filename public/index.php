<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/shop.php';
require __DIR__ . '/../src/customers.php';
require __DIR__ . '/../src/html.php';

function redirect(): never
{
    header('Location: /', true, 303);
    exit;
}

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$cart = $_SESSION['cart'] ?? [];

try {
    $db = connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(403);
            exit('Ungültige Anfrage. Bitte lade die Seite neu.');
        }

        try {
            $action = $_POST['action'] ?? '';
            if ($action === 'checkout') {
                $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 2147483647],
                ]);
                if (!$customerId) {
                    throw new CheckoutRejected('Bitte wähle ein Demo-Kundenprofil.');
                }
                $order = checkout($db, $cart, $customerId);
                $_SESSION['cart'] = [];
                $_SESSION['last_order'] = $order['id'];
                $_SESSION['flash'] = "Bestellung #{$order['id']} erfasst · " . euro($order['total']);
            } elseif ($action === 'customer') {
                $_SESSION['flash'] = createCustomer($db, $_POST['display_name'] ?? null)
                    ? 'Demo-Kundenprofil erstellt.' : 'Dieses Demo-Kundenprofil existiert bereits.';
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
    $customers = customerProfiles($db);
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
<header class="hero">
    <div class="wrap">
        <p class="eyebrow">DRESDEN / E-COMMERCE-DEMO</p>
        <h1>Dresden Bean Shop</h1>
        <p>Drei Röstungen, ein Warenkorb. Ein Demo-Shop mit PHP und PostgreSQL.</p>
        <a href="#cart">Zum Warenkorb (<?= array_sum($cart) ?>)</a>
        <a class="archive-link" href="/archive.php">Bestellarchiv</a>
    </div>
</header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($warning !== null): ?><p class="warning" role="alert"><?= h($warning) ?></p><?php endif; ?>
    <?php if ($lastOrder !== null): ?>
        <p><a href="/archive.php?order=<?= (int) $lastOrder ?>">Bestellung im Archiv ansehen</a></p>
    <?php endif; ?>
    <h2>Unsere Röstungen</h2>
    <div class="catalog">
        <?php foreach ($products as $product):
            $id = (int) $product['id'];
            $stock = (int) $product['stock'];
        ?>
        <article class="card">
            <h3><?= h($product['name']) ?></h3>
            <p><?= h($product['notes']) ?></p>
            <p><strong><?= euro((int) $product['price_cents']) ?></strong> / 250 g</p>
            <p><?= $stock > 0 ? "{$stock} verfügbar" : 'Ausverkauft' ?></p>
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
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <section class="card cart" id="cart">
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
                    <span><?= $cart[$id] ?> × <?= h($product['name']) ?> · <?= euro($subtotal) ?></span>
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
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="checkout">
                <label for="customer">Demo-Kundenprofil</label>
                <select id="customer" name="customer_id" required>
                    <option value="">Bitte auswählen</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['id'] ?>"><?= h($customer['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button>Demo-Bestellung abschließen</button>
            </form>
        <?php endif; ?>
    </section>
    <section class="card cart" id="customers">
        <h2>Demo-Kundenprofil anlegen</h2>
        <p class="muted">Nur fiktive Namen verwenden. Jede Person am Demo-Shop kann das Archiv öffnen.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="customer">
            <label for="display-name">Demo-Name</label>
            <input class="wide" id="display-name" name="display_name" minlength="2" maxlength="60" required>
            <button>Profil speichern</button>
        </form>
    </section>
</main>
</body>
</html>
