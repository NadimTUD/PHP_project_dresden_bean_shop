<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Aufruf: php bin/grant-staff.php EMAIL\n");
    exit(1);
}
try {
    $stmt = connect()->prepare('UPDATE accounts SET is_staff = true WHERE email = :email RETURNING id');
    $stmt->execute(['email' => accountEmail($argv[1])]);
    if ($stmt->fetchColumn() === false) {
        throw new AccountRejected('Kein passendes Konto gefunden. Bitte zuerst registrieren.');
    }
    echo "Mitarbeiterrolle vergeben. Öffne das Bestellarchiv neu.\n";
} catch (AccountRejected $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
