<?php
declare(strict_types=1);

function staffSetupToken(): ?string
{
    $token = getenv('STAFF_SETUP_TOKEN');
    return is_string($token) && strlen($token) >= 32 ? $token : null;
}

function shopHasStaff(PDO $db): bool
{
    return $db->query('SELECT 1 FROM accounts WHERE is_staff LIMIT 1')->fetchColumn() !== false;
}

function activateFirstStaff(PDO $db, int $accountId, mixed $submittedToken): void
{
    $secret = staffSetupToken();
    if ($secret === null || !is_string($submittedToken) || !hash_equals($secret, $submittedToken)) {
        throw new AccountRejected('Der Einrichtungscode stimmt nicht.');
    }
    $db->beginTransaction();
    try {
        // Concurrent setup submissions must not both see an empty team.
        $db->query('SELECT pg_advisory_xact_lock(846201, 1)');
        if (shopHasStaff($db)) {
            throw new AccountRejected('Das erste Mitarbeiterkonto wurde bereits eingerichtet.');
        }
        $update = $db->prepare('UPDATE accounts SET is_staff = true WHERE id = :id RETURNING id');
        $update->execute(['id' => $accountId]);
        if ($update->fetchColumn() === false) {
            throw new AccountRejected('Konto nicht gefunden.');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function grantStaffInShop(PDO $db, int $staffId, mixed $email): void
{
    $update = $db->prepare('UPDATE accounts SET is_staff = true WHERE email = :email AND NOT is_staff
        AND EXISTS (SELECT 1 FROM accounts WHERE id = :staff AND is_staff) RETURNING id');
    $update->execute(['email' => accountEmail($email), 'staff' => $staffId]);
    if ($update->fetchColumn() === false) {
        throw new AccountRejected('Kein passendes Kundenkonto gefunden oder bereits im Team.');
    }
}
