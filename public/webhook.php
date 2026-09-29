<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
        if ($mode === '') {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Webhook SuCasa disponible. Esta pagina no confirma la conexion con Meta ni con la base de datos.';
            exit;
        }
        $config = Config::load(dirname(__DIR__), ['META_VERIFY_TOKEN']);
        $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
        if ($mode === 'subscribe' && hash_equals($config['verify_token'], (string) $token)) {
            header('Content-Type: text/plain');
            echo (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
            exit;
        }
        http_response_code(403);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    [$config, , $repo] = app();
    $raw = file_get_contents('php://input') ?: '';
    $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $config['app_secret']);
    if (!hash_equals($expected, $signature)) { http_response_code(403); exit; }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            if ((string) ($value['metadata']['phone_number_id'] ?? '') !== $config['phone_number_id']) continue;
            foreach (($value['messages'] ?? []) as $message) {
                $id = (string) ($message['id'] ?? '');
                $phone = preg_replace('/\D/', '', (string) ($message['from'] ?? '')) ?: '';
                if ($id === '' || strlen($phone) < 8 || strlen($phone) > 15) continue;
                $type = $message['type'] ?? '';
                $body = match ($type) {
                    'text' => (string) ($message['text']['body'] ?? ''),
                    'button' => (string) ($message['button']['text'] ?? ''),
                    'interactive' => (string) ($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? ''),
                    default => '',
                };
                $body = trim($body);
                $timestamp = (int) ($message['timestamp'] ?? 0);
                if ($body !== '' && $timestamp > 0) $repo->enqueue($id, $phone, mb_substr($body, 0, 4000), $timestamp);
            }
        }
    }
    http_response_code(200);
    echo 'EVENT_RECEIVED';
} catch (Throwable $e) {
    error_log('SuCasa webhook: ' . $e->getMessage());
    http_response_code(500);
    echo 'ERROR';
}
