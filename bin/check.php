<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    [$config, $db] = app();
    foreach (['suca_messages', 'suca_leads', 'wp_jet_cct_clientes', 'wp_jet_cct_tickets', 'wp_jet_cct_cct_llamadas', 'wp_jet_cct_funcionarios', 'wp_jet_cct_inmuebles'] as $table) {
        $db->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        echo $table . ": OK\n";
    }
    echo 'Meta phone number ID: ' . $config['phone_number_id'] . "\n";
    echo 'MiniMax model: ' . $config['minimax_model'] . "\n";
    echo "Configuration and database: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
