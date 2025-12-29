<?php
// api_stats.php
// Endpoint dedicado para fornecer estatísticas em tempo real para dashboards externos.

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *"); // Permite acesso de qualquer origem (ajuste para produção)
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: X-API-KEY");

// Trata pre-flight request do CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 1. Carregar Configuração do Banco de Dados
// Tenta localizar o config.php na raiz do projeto ou na pasta atual
$config_paths = [
    __DIR__ . '/../config.php',
    __DIR__ . '/config.php'
];

$loaded = false;
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro de configuração: Banco de dados não conectado.']);
    exit;
}

// 2. Autenticação
$api_key_header = $_SERVER['HTTP_X_API_KEY'] ?? '';
$api_key_param = $_GET['api_key'] ?? '';
$provided_key = $api_key_header ?: $api_key_param;

// Usa a constante do config ou fallback para a chave usada no aleddesk_client.php
$valid_key = defined('API_SECRET_KEY') ? API_SECRET_KEY : 'aleddesk_secret_key_12345';

if ($provided_key !== $valid_key) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
    exit;
}

// 3. Coleta de Métricas
try {
    $response = [
        'success' => true,
        'timestamp' => date('c'),
        'metrics' => []
    ];

    // Total de Ativos
    $stmt = $pdo->query("SELECT COUNT(*) FROM assets");
    $response['metrics']['total_assets'] = (int)$stmt->fetchColumn();

    // Distribuição por Status
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM assets GROUP BY status");
    $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $response['metrics']['assets_by_status'] = array_change_key_case($statuses, CASE_LOWER);

    // Tamanho do Banco de Dados (MB)
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $size = 0;
    
    if ($driver === 'pgsql') {
        $size = $pdo->query("SELECT pg_database_size(current_database())")->fetchColumn();
    } else {
        // Fallback para MySQL
        $size = $pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    }
    $response['metrics']['db_size_mb'] = round($size / 1024 / 1024, 2);

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()]);
}