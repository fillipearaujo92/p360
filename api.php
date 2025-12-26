<?php
// api.php
// Endpoint RESTful para consulta de ativos (Integração AledDesk)

// Define o cabeçalho para JSON
header('Content-Type: application/json; charset=utf-8');

// Inclui a conexão com o banco ($pdo) e a configuração ($api_key)
require_once 'config.php';

// 1. Verificação de Segurança (API Key)
// A chave pode ser enviada via Header 'X-API-KEY' ou parâmetro GET 'api_key'
$request_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';

if ($request_key !== $api_key) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => true, 'message' => 'Acesso não autorizado. Chave de API inválida.']);
    exit;
}

// --- LÓGICA PARA CRIAÇÃO DE CHAMADOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lê o corpo da requisição JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    $asset_id = $input['asset_id'] ?? null;
    $description = $input['description'] ?? null;
    $contact_info = $input['contact_info'] ?? null;
    $company_id = $input['company_id'] ?? 1;

    if (!$asset_id || !$description || !$contact_info) {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => 'Dados incompletos (asset_id, description, contact_info).']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO tickets (company_id, asset_id, description, contact_info, status, created_at) VALUES (?, ?, ?, ?, 'aberto', NOW())");
        $stmt->execute([$company_id, $asset_id, $description, $contact_info]);
        echo json_encode(['success' => true, 'message' => 'Chamado criado com sucesso.', 'ticket_id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => 'Erro ao criar chamado: ' . $e->getMessage()]);
    }
    exit;
}

// 2. Validação dos Parâmetros de Entrada
$id = $_GET['id'] ?? null;
$codigo = $_GET['codigo'] ?? null; // Ex: Código de barras ou etiqueta do patrimônio

if (!$id && !$codigo) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => true, 'message' => 'Parâmetro "id" ou "codigo" é obrigatório para a consulta.']);
    exit;
}

try {
    // 3. Consulta ao Banco de Dados
    // IMPORTANTE: Ajuste 'assets' e 'codigo_patrimonio' conforme o nome real da sua tabela e colunas
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $id);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE code = :codigo LIMIT 1");
        $stmt->bindValue(':codigo', $codigo);
    }

    $stmt->execute();
    $ativo = $stmt->fetch();

    if ($ativo) {
        echo json_encode(['success' => true, 'data' => $ativo]);
    } else {
        http_response_code(404); // Not Found
        echo json_encode(['error' => true, 'message' => 'Ativo não encontrado.']);
    }
} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => true, 'message' => 'Erro interno ao consultar dados: ' . $e->getMessage()]);
}