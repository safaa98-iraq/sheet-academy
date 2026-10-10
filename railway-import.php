<?php

/** Import an explicitly supplied deployment backup only into an empty database. */
$payload = getenv('DEPLOY_DATABASE_DUMP_GZIP_BASE64');
if ($payload === false || $payload === '') {
    exit(0);
}

$encoded = base64_decode($payload, true);
$sql = $encoded === false ? false : gzdecode($encoded, 32 * 1024 * 1024);
$expectedHash = getenv('DEPLOY_DATABASE_DUMP_SHA256');
if ($sql === false || ! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', $sql))) {
    fwrite(STDERR, "Deployment database backup failed integrity verification.\n");
    exit(1);
}

$database = getenv('DB_DATABASE');
if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $database)) {
    fwrite(STDERR, "Invalid deployment database name.\n");
    exit(1);
}

try {
    $pdo = null;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        try {
            $pdo = new PDO(
                'mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '3306').';charset=utf8mb4',
                getenv('DB_USERNAME'),
                getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            break;
        } catch (PDOException $exception) {
            if ($attempt === 29) {
                throw $exception;
            }
            sleep(2);
        }
    }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `'.$database.'`');
    $marker = __DIR__.'/storage/app/private/.railway-database-imported';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if ($tables !== []) {
        if (! is_file($marker) || trim((string) file_get_contents($marker)) !== $expectedHash) {
            throw new RuntimeException('Existing database found; refusing to overwrite it.');
        }
        fwrite(STDOUT, "Deployment database backup already imported; preserving existing data.\n");
        exit(0);
    }

    $pdo->exec($sql);
    file_put_contents($marker, $expectedHash, LOCK_EX);
    chmod($marker, 0600);
    $tableCount = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    fwrite(STDOUT, 'Deployment database backup imported and verified: '.$tableCount." tables.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Deployment database import stopped: '.($exception instanceof RuntimeException && ! ($exception instanceof PDOException) ? $exception->getMessage() : 'database error '.$exception->getCode())."\n");
    exit(1);
}
