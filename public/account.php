<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/google.php';
require __DIR__ . '/../src/html.php';
require __DIR__ . '/../src/navigation.php';

startShopSession();
try {
    $db = connect();
    $account = signedInAccount($db);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        try {
            $action = $_POST['action'] ?? null;
            if ($action === 'register' && $account === null) {
                signIn(createAccount($db, $_POST['name'] ?? null, $_POST['email'] ?? null, $_POST['password'] ?? null));
            } elseif ($action === 'login' && $account === null) {
                signIn(passwordAccount($db, $_POST['email'] ?? null, $_POST['password'] ?? null));
            } elseif ($action === 'profile' && $account !== null) {
                updateAccount($db, (int) $account['id'], $_POST['name'] ?? null, $_POST['email'] ?? null);
                $_SESSION['account_notice'] = 'Profil gespeichert.';
            } elseif ($action === 'logout' && $account !== null) {
                // Also clear the cart and OAuth state on a shared machine.
                $_SESSION = [];
                session_regenerate_id(true);
            }
        } catch (AccountRejected $error) {
            $_SESSION['account_error'] = $error->getMessage();
        }
        header('Location: /account.php' . (($action ?? '') === 'profile' ? '#profile' : ''), true, 303);
        exit;
    }
} catch (PDOException $error) {
    http_response_code(500);
    exit('Das Kundenkonto ist gerade nicht verfügbar.');
}
$notice = $_SESSION['account_notice'] ?? null;
$error = $_SESSION['account_error'] ?? null;
unset($_SESSION['account_notice'], $_SESSION['account_error']);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kundenkonto · Dresden Bean Shop</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<?php shopNavigation($account === null ? '' : 'profile', $account, array_sum($_SESSION['cart'] ?? [])); ?>
<header class="hero">
    <div class="wrap">
        <h1>Kundenkonto</h1>
    </div>
</header>
<main class="wrap">
    <?php if ($notice !== null): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="warning" role="alert"><?= h($error) ?></p><?php endif; ?>
    <p class="muted">Lokales Demo-Projekt. Verwende für Passwortkonten Testdaten und kein bereits genutztes Passwort.</p>
    <?php if ($account === null): ?>
    <div class="account-grid">
        <section class="card" id="login">
            <h2>Anmelden</h2>
            <form class="account-form" method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="login">
                <label for="login-email">E-Mail</label>
                <input type="email" id="login-email" name="email" autocomplete="username" maxlength="254" required>
                <label for="login-password">Passwort</label>
                <input type="password" id="login-password" name="password" autocomplete="current-password" required>
                <button>Anmelden</button>
            </form>
            <?php if (googleSettings() !== null): ?>
            <form method="post" action="/google.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <button class="secondary">Mit Google anmelden</button>
            </form>
            <?php endif; ?>
        </section>
        <section class="card" id="register">
            <h2>Konto erstellen</h2>
            <form class="account-form" method="post">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="register">
                <label for="register-name">Anzeigename</label>
                <input id="register-name" name="name" autocomplete="name" minlength="2" maxlength="60" required>
                <label for="register-email">E-Mail</label>
                <input type="email" id="register-email" name="email" autocomplete="email" maxlength="254" required>
                <label for="register-password">Passwort (mindestens 12 Zeichen bei ASCII)</label>
                <input type="password" id="register-password" name="password" autocomplete="new-password" required>
                <button>Konto erstellen</button>
            </form>
        </section>
    </div>
    <?php else: ?>
    <section class="card" id="profile">
        <h2>Mein Profil<?= (int) $account['staff'] === 1 ? ' · Mitarbeitende' : '' ?></h2>
        <p>Hier kannst du deinen Namen und deine E-Mail-Adresse ändern.</p>
        <form class="account-form" method="post">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="profile">
            <label for="profile-name">Anzeigename</label>
            <input id="profile-name" name="name" autocomplete="name" minlength="2" maxlength="60" value="<?= h($account['display_name']) ?>" required>
            <label for="profile-email">E-Mail im Shop</label>
            <input type="email" id="profile-email" name="email" autocomplete="email" maxlength="254" value="<?= h($account['email']) ?>" required>
            <button>Kundendaten speichern</button>
        </form>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
