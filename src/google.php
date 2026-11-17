<?php
declare(strict_types=1);

function googleSettings(): ?array
{
    $id = getenv('GOOGLE_CLIENT_ID');
    $secret = getenv('GOOGLE_CLIENT_SECRET');
    $redirect = getenv('GOOGLE_REDIRECT_URI');
    return $id && $secret && $redirect ? ['id' => $id, 'secret' => $secret, 'redirect' => $redirect] : null;
}

function base64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function googleJson(string $url, array $headers, ?array $body = null): array
{
    $options = ['method' => $body === null ? 'GET' : 'POST', 'header' => $headers,
        'timeout' => 8, 'follow_location' => 0];
    if ($body !== null) {
        $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($body);
    }
    // Only fixed Google HTTPS endpoints are used. Never expose token responses or credentials.
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $options]), 0, 65536);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        throw new AccountRejected('Google-Anmeldung derzeit nicht verfügbar.');
    }
    return $data;
}
