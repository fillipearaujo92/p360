<?php
session_start();
header('Content-Type: application/json');

// Tenta incluir a conexão com o banco de dados
// Ajuste o caminho 'config/database.php' se o seu arquivo de conexão estiver em outro local
if (file_exists('config.php')) {
    require_once 'config.php';
} elseif (file_exists('config/database.php')) {
    require_once 'config/database.php';
} elseif (file_exists('../config/database.php')) {
    require_once '../config/database.php';
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Arquivo de configuração do banco de dados não encontrado.']);
    exit;
}

// Verifica se o ID do ativo foi enviado
$asset_id = filter_input(INPUT_GET, 'asset_id', FILTER_VALIDATE_INT);

if (!$asset_id) {
    echo json_encode([]);
    exit;
}

try {
    // Consulta o histórico. 
    // Certifique-se de que a tabela 'asset_logs' existe no seu banco de dados.
    $sql = "SELECT l.created_at, l.description, u.name as user_name 
            FROM asset_logs l 
            LEFT JOIN users u ON l.user_id = u.id 
            WHERE l.asset_id = :id 
            ORDER BY l.created_at DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $asset_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    // Em caso de erro (ex: tabela não existe), retorna array vazio para não quebrar o frontend
    echo json_encode([]); 
}