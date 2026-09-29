<?php
declare(strict_types=1);

final class MetaClient
{
    public function __construct(private array $config) {}

    public function sendText(string $phone, string $body): void
    {
        $url = 'https://graph.facebook.com/' . rawurlencode($this->config['graph_version']) . '/'
            . rawurlencode($this->config['phone_number_id']) . '/messages';
        $payload = [
            'messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($body, 0, 4000)],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->config['access_token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Meta send failed HTTP ' . $status . ': ' . ($error ?: mb_substr((string) $raw, 0, 500)));
        }
    }
}
