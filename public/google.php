<?php
declare(strict_types=1);

require __DIR__ . '/../src/database.php';
require __DIR__ . '/../src/accounts.php';
require __DIR__ . '/../src/google.php';

startShopSession();
header('Referrer-Policy: no-referrer');
$settings = googleSettings();
if ($settings === null) {
    http_response_code(404);
    exit('Google-Anmeldung ist nicht konfiguriert.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyPostToken();
        $flow = ['state' => base64Url(random_bytes(32)), 'verifier' => base64Url(random_bytes(32)), 'started' => time()];
        $_SESSION['google_flow'] = $flow;
        $query = http_build_query([
            'client_id' => $settings['id'], 'redirect_uri' => $settings['redirect'],
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $flow['state'],
            'code_challenge' => base64Url(hash('sha256', $flow['verifier'], true)), 'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query, true, 303);
        exit;
    }
    $flow = $_SESSION['google_flow'] ?? null;
    unset($_SESSION['google_flow']);
    $state = $_GET['state'] ?? null;
    $code = $_GET['code'] ?? null;
    if (!$flow || !is_string($state) || !hash_equals($flow['state'], $state) || time() - $flow['started'] > 600
        || !is_string($code) || $code === '' || strlen($code) > 4096) {
        throw new AccountRejected('Google-Anmeldung wurde abgebrochen oder ist abgelaufen.');
    }
    $tokens = googleJson('https://oauth2.googleapis.com/token', ['Accept: application/json'], [
        'client_id' => $settings['id'], 'client_secret' => $settings['secret'],
        'redirect_uri' => $settings['redirect'], 'code' => $code, 'code_verifier' => $flow['verifier'],
        'grant_type' => 'authorization_code',
    ]);
    $accessToken = $tokens['access_token'] ?? null;
    if (!is_string($accessToken) || !preg_match('/\A[\x21-\x7E]+\z/', $accessToken)) {
        throw new AccountRejected('Google-Anmeldung konnte nicht abgeschlossen werden.');
    }
    $profile = googleJson('https://openidconnect.googleapis.com/v1/userinfo', [
        'Accept: application/json', 'Authorization: Bearer ' . $accessToken,
    ]);
    signIn(googleAccount(connect(), $profile));
} catch (AccountRejected $error) {
    $_SESSION['account_error'] = $error->getMessage();
} catch (PDOException $error) {
    $_SESSION['account_error'] = 'Das Konto konnte gerade nicht geladen werden.';
}
header('Location: /account.php', true, 303);
