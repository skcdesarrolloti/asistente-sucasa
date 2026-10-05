<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/MiniMaxClient.php';

final class FakeMiniMax extends MiniMaxClient
{
    public array $limits = [];
    public array $requests = [];
    public function __construct(private array $responses) { parent::__construct([]); }
    protected function request(array $messages, int $maxTokens): array
    {
        $this->limits[] = $maxTokens;
        $this->requests[] = $messages;
        if (!$this->responses) throw new RuntimeException('Unexpected extra request');
        return array_shift($this->responses);
    }
}

function response(string $content, string $reason = 'stop'): array
{
    return ['choices' => [['finish_reason' => $reason, 'message' => ['content' => $content]]]];
}
function verify(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "OK: {$label}\n";
}

$json = json_encode(['intent' => 'commercial', 'name' => '', 'email' => '', 'business' => 'arriendo',
    'property_type' => 'apartamento', 'zone' => 'Cartagena, Manga', 'budget' => 4000000,
    'property_code' => '', 'wants_call' => false], JSON_THROW_ON_ERROR);
$client = new FakeMiniMax([response('<think>partial', 'length'), response($json)]);
$data = $client->extract('En Cartagena en Manga tengo 4 millones', []);
verify($data['budget'] === 4000000 && $client->limits === [4096, 8192], 'Truncated response retries with a larger limit');

$client = new FakeMiniMax([response(''), response($json)]);
verify($client->extract('Manga', [])['budget'] === 4000000 && count($client->limits) === 2, 'Empty final output is retried');

$client = new FakeMiniMax([response('{}'), response('```json' . "\n" . $json . "\n```")]);
verify($client->extract('Manga', [])['zone'] === 'Cartagena, Manga', 'Invalid schema is retried; fenced JSON is accepted');

$client = new FakeMiniMax([response('<think>private reasoning</think>' . $json)]);
verify($client->extract('Manga', [])['wants_call'] === false, 'Reasoning is stripped before parsing');

$nullable = json_decode($json, true);
$nullable['name'] = $nullable['email'] = $nullable['property_code'] = null;
$client = new FakeMiniMax([response(json_encode($nullable, JSON_THROW_ON_ERROR))]);
$data = $client->extract('Manga', []);
verify($data['name'] === '' && $data['email'] === '' && $data['budget'] === 4000000, 'Unknown text fields can be null and normalize to empty strings');

$client = new FakeMiniMax([response($json)]);
$client->extract('Manga, 4 millones', [['role' => 'assistant', 'body' => '¿Qué presupuesto tiene?']]);
$messages = $client->requests[0];
$input = json_decode($messages[1]['content'], true);
verify(count($messages) === 2 && $input['history'][0]['role'] === 'assistant'
    && $input['current_message'] === 'Manga, 4 millones', 'Conversation history is input data, not assistant turns during extraction');

foreach ([['no JSON', 'stop'], ['', 'length']] as [$content, $reason]) {
    $client = new FakeMiniMax([response($content, $reason), response($content, $reason)]);
    $rejected = false;
    try { $client->extract('Manga', []); } catch (RuntimeException $e) { $rejected = true; }
    verify($rejected && count($client->limits) === 2, 'Repeated invalid or truncated output fails after bounded retries');
}
