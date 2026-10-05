<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

[$config, $db, $repo] = app();
if ((int) $db->query("SELECT GET_LOCK('suca_worker', 0)")->fetchColumn() !== 1) {
    echo "Worker already running\n";
    exit;
}
$llm = new MiniMaxClient($config);
$meta = new MetaClient($config);
$count = 0;

while ($count < 20 && ($job = $repo->claim())) {
    ++$count;
    $id = (int) $job['id'];
    $phone = (string) $job['phone'];
    try {
        // Free-form Cloud API replies must remain within the customer service window.
        if ((int) $job['user_timestamp'] < time() - 23 * 3600) {
            $repo->finish($id);
            continue;
        }
        if ($repo->blocked($phone)) { $repo->finish($id); continue; }
        if (!empty($job['reply_body'])) {
            $meta->sendText($phone, (string) $job['reply_body']);
            $repo->finish($id, (string) $job['reply_body']);
            $savedLead = $repo->lead($phone);
            if (($savedLead['profile']['intent'] ?? '') === 'human') $repo->pauseHuman($phone);
            continue;
        }
        $lead = $repo->lead($phone);
        if (!empty($lead['human_paused_until']) && strtotime($lead['human_paused_until']) > time()) {
            $repo->finish($id); continue;
        }
        $history = $repo->history($phone);
        $extracted = $llm->extract($job['body'], $history);
        $profile = array_merge($lead['profile'], array_filter($extracted, static fn($v) => $v !== '' && $v !== null && $v !== false));
        $property = $repo->propertyByCode((string) ($profile['property_code'] ?? ''));
        $properties = $property ? [$property] : $repo->searchProperties($profile);
        $commercial = in_array($extracted['intent'], ['commercial', 'human'], true) || $extracted['wants_call'] || $property || !empty($lead['ticket_id']);
        $ticketId = $lead['ticket_id'] ? (int) $lead['ticket_id'] : null;
        $actions = ['client_id' => $lead['client_id'], 'call_id' => $lead['call_id']];
        if ($commercial) {
            $saved = $repo->saveProspect($phone, $job['body'], $profile, $property, $extracted['wants_call'], (string) $job['wa_message_id']);
            $profile = $saved['profile'];
            $ticketId = $saved['ticket_id'];
            $actions = ['client_id' => $saved['client_id'], 'call_id' => $saved['call_id'], 'call_requested' => $extracted['wants_call']];
        } else {
            $repo->rememberProfile($phone, $profile);
        }
        $safeProperty = static function (array $row): array {
            unset($row['_ID'], $row['id_funcionario']);
            return $row;
        };
        $reply = $llm->reply($job['body'], $history, $profile, array_map($safeProperty, $properties),
            $property ? $safeProperty($property) : null, $ticketId, $actions);
        $repo->storeReply($id, $reply);
        $meta->sendText($phone, $reply);
        $repo->finish($id, $reply);
        if ($extracted['intent'] === 'human') $repo->pauseHuman($phone);
    } catch (Throwable $e) {
        $repo->fail($id, (int) $job['attempts'] + 1, $e->getMessage());
        error_log('SuCasa worker job ' . $id . ': ' . $e->getMessage());
    }
}

echo "Processed {$count}\n";
