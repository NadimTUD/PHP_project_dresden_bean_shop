<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/staff.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';

startShopSession();
$notice = $_SESSION['team_notice'] ?? null;
unset($_SESSION['team_notice']);
$error = null;
try {
    $db = connect();
    $account = signedInAccount($db);
    if ($account === null) {
        header('Location: /account.php#login', true, 303);
        exit;
    }
    $staff = (int) $account['staff'] === 1;
    $canSetup = !$staff && staffSetupToken() !== null && !shopHasStaff($db);
    if (!$staff && !$canSetup) {
        http_response_code(403);
        exit('Die Teamverwaltung ist nicht verfügbar.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        try {
            if ($staff) {
                grantStaffInShop($db, (int) $account['id'], $_POST['email'] ?? null);
                $_SESSION['team_notice'] = 'Mitarbeiterrolle vergeben.';
            } else {
                activateFirstStaff($db, (int) $account['id'], $_POST['setup_token'] ?? null);
                signIn((int) $account['id']);
                $_SESSION['team_notice'] = 'Mitarbeiterzugang aktiviert.';
            }
            header('Location: /team.php', true, 303);
            exit;
        } catch (AccountRejected $rejection) {
            $error = $rejection->getMessage();
        }
    }
} catch (PDOException $failure) {
    http_response_code(500);
    exit('Die Teamverwaltung ist derzeit nicht verfügbar.');
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Team · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation('team', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero"><div class="wrap">
    <h1><?= $staff ? 'Teamverwaltung' : 'Mitarbeiterzugang einrichten' ?></h1>
    <p><?= $staff ? 'Mitarbeiterrechte an ein bestehendes Shop-Konto vergeben.' : 'Dieser Einrichtungscode funktioniert nur, solange es noch kein Mitarbeiterkonto gibt.' ?></p>
</div></header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="warning" role="alert"><?= h($error) ?></p><?php endif; ?>
    <section class="card">
        <form class="account-form" method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <?php if ($staff): ?>
                <label for="team-email">E-Mail des registrierten Kontos</label>
                <input id="team-email" name="email" type="email" maxlength="254" required>
                <p>Nur vertrauenswürdige Personen hinzufügen. Teammitglieder können Bestellungen, Lager und Kundendaten verwalten und weitere Teammitglieder hinzufügen.</p>
                <button type="submit">Mitarbeiterrolle vergeben</button>
            <?php else: ?>
                <label for="setup-code">Einrichtungscode</label>
                <input id="setup-code" name="setup_token" type="password" autocomplete="off" required>
                <button type="submit">Mitarbeiterzugang aktivieren</button>
            <?php endif; ?>
        </form>
        <?php if ($staff): ?><a class="primary" href="/inventory.php">Zum Lager</a><?php endif; ?>
    </section>
</main>
</body>
</html>
