<?php
declare(strict_types=1);

final class AccountRejected extends RuntimeException {}

function accountName(mixed $value): string
{
    if (!is_string($value) || !preg_match('/\A[^\p{C}]{2,60}\z/u', trim($value))) {
        throw new AccountRejected('Der Name muss zwischen 2 und 60 Zeichen enthalten.');
    }
    return trim($value);
}

function accountEmail(mixed $value): string
{
    if (!is_string($value) || strlen(trim($value)) > 254 || !filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
        throw new AccountRejected('Bitte gib eine gültige E-Mail-Adresse ein.');
    }
    return strtolower(trim($value));
}

function createAccount(PDO $db, mixed $name, mixed $email, mixed $password): int
{
    $name = accountName($name);
    $email = accountEmail($email);
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new AccountRejected('Das Passwort muss 12 bis 72 Bytes lang sein.');
    }
    $stmt = $db->prepare('INSERT INTO accounts (display_name, email, password_hash)
        VALUES (:name, :email, :hash) ON CONFLICT (email) DO NOTHING RETURNING id');
    $stmt->execute(['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new AccountRejected('Ein Konto mit dieser E-Mail-Adresse existiert bereits.');
    }
    return (int) $id;
}

function passwordAccount(PDO $db, mixed $email, mixed $password): int
{
    $stmt = $db->prepare('SELECT id, password_hash FROM accounts WHERE email = :email');
    $stmt->execute(['email' => accountEmail($email)]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account || !$account['password_hash'] || !is_string($password) || strlen($password) > 72
        || !password_verify($password, $account['password_hash'])) {
        throw new AccountRejected('E-Mail oder Passwort stimmt nicht.');
    }
    return (int) $account['id'];
}

function updateAccount(PDO $db, int $id, mixed $name, mixed $email, bool $customerOnly = false): void
{
    $sql = 'UPDATE accounts SET display_name = :name, email = :email WHERE id = :id';
    $stmt = $db->prepare($sql . ($customerOnly ? ' AND NOT is_staff' : '') . ' RETURNING id');
    try {
        $stmt->execute(['id' => $id, 'name' => accountName($name), 'email' => accountEmail($email)]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23505') {
            throw new AccountRejected('Diese E-Mail-Adresse wird bereits verwendet.');
        }
        throw $error;
    }
    if ($stmt->fetchColumn() === false) {
        throw new AccountRejected('Konto nicht gefunden oder nicht bearbeitbar.');
    }
}

function googleAccount(PDO $db, array $profile): int
{
    $sub = $profile['sub'] ?? null;
    if (!is_string($sub) || !preg_match('/\A[\x21-\x7E]{1,255}\z/', $sub)
        || ($profile['email_verified'] ?? false) !== true) {
        throw new AccountRejected('Google hat keine verifizierte Identität geliefert.');
    }
    $find = $db->prepare('SELECT id FROM accounts WHERE google_subject = :subject');
    $find->execute(['subject' => $sub]);
    $existing = $find->fetchColumn();
    if ($existing !== false) {
        return (int) $existing; // Do not undo edits to the shop profile.
    }
    try {
        $name = accountName($profile['name'] ?? '');
    } catch (AccountRejected) {
        $name = 'Google-Kunde';
    }
    $email = accountEmail($profile['email'] ?? null);
    $stmt = $db->prepare('INSERT INTO accounts (display_name, email, google_subject)
        VALUES (:name, :email, :subject) ON CONFLICT DO NOTHING RETURNING id');
    $stmt->execute(['name' => $name, 'email' => $email, 'subject' => $sub]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        $find->execute(['subject' => $sub]);
        $id = $find->fetchColumn();
    }
    if ($id === false) {
        // A matching email is not permission to take over a local account.
        throw new AccountRejected('Diese E-Mail gehört bereits zu einem anderen Shop-Konto. Bitte nutze dessen ursprüngliche Anmeldemethode.');
    }
    return (int) $id;
}

function startShopSession(): void
{
    session_start([
        'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true,
        'cookie_secure' => ($_SERVER['HTTPS'] ?? 'off') === 'on',
    ]);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    header('Cache-Control: no-store');
}

function signedInAccount(PDO $db): ?array
{
    if (!isset($_SESSION['account_id'])) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, display_name, email, CASE WHEN is_staff THEN 1 ELSE 0 END AS staff
        FROM accounts WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['account_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function signIn(int $id): void
{
    session_regenerate_id(true);
    unset($_SESSION['google_flow']);
    $_SESSION['account_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function verifyPostToken(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Ungültige Anfrage. Bitte lade die Seite neu.');
    }
}
