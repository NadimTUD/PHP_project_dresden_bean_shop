<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';

startShopSession();
$error = null;
$notice = $_SESSION['customer_notice'] ?? null;
unset($_SESSION['customer_notice']);
$idOptions = ['options' => ['min_range' => 1, 'max_range' => 2147483647]];
$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, $idOptions);

try {
    $db = connect();
    $account = signedInAccount($db);
    if ($account === null || (int) $account['staff'] !== 1) {
        http_response_code(403);
        exit('Die Kundenverwaltung ist nur für Mitarbeitende verfügbar.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        $selectedId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, $idOptions);
        try {
            if (!$selectedId) {
                throw new AccountRejected('Bitte wähle ein Kundenkonto.');
            }
            updateAccount($db, $selectedId, $_POST['name'] ?? null, $_POST['email'] ?? null, true);
            $_SESSION['customer_notice'] = 'Kundendaten gespeichert.';
            header('Location: /customers.php?id=' . $selectedId, true, 303);
            exit;
        } catch (AccountRejected $rejection) {
            $error = $rejection->getMessage();
        }
    }
    $customers = $db->query('SELECT id, display_name, email FROM accounts
        WHERE NOT is_staff ORDER BY display_name, id')->fetchAll(PDO::FETCH_ASSOC);
    $selected = null;
    foreach ($customers as $customer) {
        if ((int) $customer['id'] === $selectedId) {
            $selected = $customer;
            break;
        }
    }
    if ($selectedId && $selected === null && $error === null) {
        http_response_code(404);
        $error = 'Dieses Kundenkonto wurde nicht gefunden.';
    }
} catch (PDOException $error) {
    http_response_code(500);
    exit('Die Kundenverwaltung ist derzeit nicht verfügbar.');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kundenverwaltung · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation('customers', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero"><div class="wrap">
    <h1>Kundenverwaltung</h1>
    <p>Kontaktdaten bearbeiten und Bestellungen ansehen.</p>
</div></header>
<main class="wrap account-grid">
    <section class="card">
        <h2>Kundenkonten</h2>
        <?php if (!$customers): ?><p>Es gibt noch keine Kundenkonten.</p><?php endif; ?>
        <ul class="customer-list">
            <?php foreach ($customers as $customer): ?>
                <li><a href="/customers.php?id=<?= (int) $customer['id'] ?>"
                    <?= $selectedId === (int) $customer['id'] ? 'aria-current="page"' : '' ?>><?= h($customer['display_name']) ?></a>
                    <span class="muted"><?= h($customer['email']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="card">
        <h2>Kundendaten bearbeiten</h2>
        <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
        <?php if ($error !== null): ?><p class="warning" role="alert"><?= h($error) ?></p><?php endif; ?>
        <?php if ($selected !== null): ?>
            <form class="account-form" method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                <label for="customer-name">Name</label>
                <input id="customer-name" name="name" minlength="2" maxlength="60" value="<?= h($selected['display_name']) ?>" required>
                <label for="customer-email">E-Mail im Shop</label>
                <input id="customer-email" type="email" name="email" maxlength="254" value="<?= h($selected['email']) ?>" required>
                <button type="submit">Kundendaten speichern</button>
            </form>
            <a href="/archive.php?customer=<?= (int) $selected['id'] ?>">Bestellungen dieses Kunden</a>
        <?php else: ?>
            <p>Wähle ein Konto aus der Liste.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
