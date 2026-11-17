<?php
declare(strict_types=1);

/** Render one consistent menu. Authorization stays in the destination pages. */
function shopNavigation(string $page, ?array $account, int $cartCount): void
{
    $staff = $account !== null && (int) $account['staff'] === 1;
    $links = [
        ['shop', '/', 'Shop'],
        ['cart', '/#cart', "Warenkorb ({$cartCount})"],
        ['archive', '/archive.php', $staff ? 'Bestellarchiv · Alle Bestellungen'
            : ($account === null ? 'Bestellarchiv' : 'Meine Bestellungen')],
    ];
    if ($account === null) {
        $links[] = ['login', '/account.php#login', 'Anmelden'];
        $links[] = ['register', '/account.php#register', 'Registrieren'];
    } else {
        $links[] = ['profile', '/account.php#profile', 'Meine Kundendaten'];
    }
    ?>
    <nav class="shop-nav" aria-label="Hauptnavigation">
        <div class="wrap nav-links">
            <?php foreach ($links as [$key, $href, $label]): ?>
                <a class="nav-button" href="<?= h($href) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
            <?php if ($account !== null): ?>
                <form method="post" action="/account.php">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="nav-button" type="submit">Abmelden</button>
                </form>
            <?php endif; ?>
        </div>
    </nav>
    <?php
}
