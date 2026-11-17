<?php
declare(strict_types=1);

function connect(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'bean_shop';

    return new PDO(
        "pgsql:host={$host};port={$port};dbname={$name}",
        getenv('DB_USER') ?: 'bean_shop',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}
