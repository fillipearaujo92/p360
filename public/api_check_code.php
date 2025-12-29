<?php
header('Content-Type: application/json');

if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Configuração do banco de dados não encontrada.']);
    exit;
}

$code = $_GET['code'] ?? '';
$current_id = $_GET['id'] ?? 0;

if (empty($code)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $sql = "SELECT id FROM assets WHERE code = ?";
    $params = [$code];

    if (!empty($current_id) && is_numeric($current_id)) {
        $sql .= " AND id != ?";
        $params[] = $current_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['exists' => $stmt->fetch() !== false]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao consultar o banco de dados.']);
}