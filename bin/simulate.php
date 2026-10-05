<?php
declare(strict_types=1);

// Synthetic conversation only: no database connection and no Meta client.
require_once dirname(__DIR__) . '/src/Config.php';
require_once dirname(__DIR__) . '/src/MiniMaxClient.php';

try {
    $config = Config::load(dirname(__DIR__), ['MINIMAX_API_KEY']);
    $llm = new MiniMaxClient($config);
    $history = [];
    $profile = [];
    $ticketId = null;
    $actions = ['client_id' => null, 'call_id' => null];
    echo "SIMULACIÓN: usa MiniMax; no escribe clientes/tickets ni envía WhatsApp.\n";
    echo "IDs ficticios, inventario vacío. Use datos ficticios. Escriba /salir para terminar, /reiniciar para empezar otra conversación.\n";
    while (true) {
        echo "Cliente> ";
        $line = fgets(STDIN);
        if ($line === false) break;
        $message = trim($line);
        if ($message === '/salir') break;
        if ($message === '') continue;
        if ($message === '/reiniciar') {
            $history = [];
            $profile = [];
            $ticketId = null;
            $actions = ['client_id' => null, 'call_id' => null];
            echo "Conversación reiniciada.\n";
            continue;
        }
        $extracted = $llm->extract($message, $history);
        $profile = array_merge($profile, array_filter($extracted, static fn($v) => $v !== '' && $v !== null && $v !== false));
        if (in_array($extracted['intent'], ['commercial', 'human'], true) || $extracted['wants_call']) {
            $actions['client_id'] ??= 1001;
            $ticketId ??= 2001;
            if ($extracted['wants_call']) $actions['call_id'] ??= 3001;
        }
        $reply = $llm->reply($message, $history, $profile, [], null, $ticketId, $actions + ['call_requested' => $extracted['wants_call']]);
        echo 'Datos extraídos: ' . json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        echo 'Registros SIMULADOS: ' . json_encode($actions + ['ticket_id' => $ticketId]) . "\n";
        echo "Asistente> {$reply}\n";
        $history[] = ['role' => 'user', 'body' => $message];
        $history[] = ['role' => 'assistant', 'body' => $reply];
        $history = array_slice($history, -8);
        if ($extracted['intent'] === 'human') {
            echo "En producción, la IA se pausaría 24 horas tras enviar la respuesta. Reiniciando simulación.\n";
            $history = [];
            $profile = [];
            $ticketId = null;
            $actions = ['client_id' => null, 'call_id' => null];
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Simulación detenida: ' . $e->getMessage() . "\n");
    exit(1);
}
