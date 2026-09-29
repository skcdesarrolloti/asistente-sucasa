<?php
declare(strict_types=1);

final class Config
{
    public static function load(string $root): array
    {
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) && getenv($key) === false) {
                    putenv($key . '=' . trim($value, " \t\n\r\0\x0B\"'"));
                }
            }
        }
        $required = ['DB_DSN', 'DB_USER', 'META_VERIFY_TOKEN', 'META_APP_SECRET', 'META_ACCESS_TOKEN', 'META_PHONE_NUMBER_ID', 'MINIMAX_API_KEY'];
        foreach ($required as $key) {
            if (trim((string) getenv($key)) === '') throw new RuntimeException("Falta {$key}");
        }
        $zones = json_decode((string) (getenv('ZONE_EMPLOYEES') ?: '{}'), true);
        if (!is_array($zones)) throw new RuntimeException('ZONE_EMPLOYEES debe ser JSON');
        return [
            'db_dsn' => (string) getenv('DB_DSN'),
            'db_user' => (string) getenv('DB_USER'),
            'db_password' => (string) (getenv('DB_PASSWORD') ?: ''),
            'verify_token' => (string) getenv('META_VERIFY_TOKEN'),
            'app_secret' => (string) getenv('META_APP_SECRET'),
            'access_token' => (string) getenv('META_ACCESS_TOKEN'),
            'phone_number_id' => (string) getenv('META_PHONE_NUMBER_ID'),
            'graph_version' => (string) (getenv('META_GRAPH_VERSION') ?: 'v23.0'),
            'minimax_key' => (string) getenv('MINIMAX_API_KEY'),
            'minimax_model' => (string) (getenv('MINIMAX_MODEL') ?: 'MiniMax-M2.7'),
            'minimax_endpoint' => (string) (getenv('MINIMAX_ENDPOINT') ?: 'https://api.minimax.io/v1/chat/completions'),
            'default_employee_id' => (string) (getenv('DEFAULT_EMPLOYEE_ID') ?: ''),
            'zone_employees' => $zones,
        ];
    }

    public static function pdo(array $config): PDO
    {
        return new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
