<?php
// api_scan.php

// 1. Configuração de CORS (Permitir acesso do Celular/Ngrok)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

// Se for apenas uma verificação de permissão (OPTIONS), para aqui
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php'; // Certifique-se que este arquivo conecta ao banco $pdo

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$session_id = $_POST['session_id'] ?? $_GET['session_id'] ?? '';

if (!$session_id) { 
    echo json_encode(['status' => 'error', 'message' => 'Sem Session ID']); 
    exit; 
}

try {
    // 1. COMPUTADOR: Verifica se o celular enviou algum código
    if ($action == 'check') {
        $stmt = $pdo->prepare("SELECT scanned_code FROM scan_sessions WHERE session_id = ? AND scanned_code IS NOT NULL");
        $stmt->execute([$session_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['scanned_code'])) {
            // Se achou, retorna o código e limpa o campo para a próxima leitura, sem deletar a sessão.
            $pdo->prepare("UPDATE scan_sessions SET scanned_code = NULL WHERE session_id = ?")->execute([$session_id]);
            echo json_encode(['status' => 'found', 'code' => $result['scanned_code']]);
        } else {
            echo json_encode(['status' => 'waiting']);
        }
    }

    // 2. COMPUTADOR: Cria a sessão quando abre o Modal
    if ($action == 'create') {
        // Limpa sessões antigas (> 2 horas) para não acumular lixo
        $pdo->query("DELETE FROM scan_sessions WHERE created_at < (NOW() - INTERVAL 2 HOUR) AND scanned_code IS NULL");
    
        // Usa INSERT IGNORE para evitar erro se a sessão já existir.
        // Ou ON DUPLICATE KEY UPDATE para resetar o código, se necessário.
        // Aqui, vamos apenas garantir que a sessão exista.
        $stmt = $pdo->prepare("INSERT IGNORE INTO scan_sessions (session_id) VALUES (?)");
        $stmt->execute([$session_id]);
        echo json_encode(['status' => 'created']);
    }

    // 3. CELULAR: Salva o código lido no banco
    if ($action == 'save') {
        $code = $_POST['code'] ?? '';
        
        // Verifica se a sessão existe
        $stmtCheck = $pdo->prepare("SELECT session_id FROM scan_sessions WHERE session_id = ?");
        $stmtCheck->execute([$session_id]);
        
        if ($stmtCheck->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE scan_sessions SET scanned_code = ? WHERE session_id = ?");
            $stmt->execute([$code, $session_id]);
            echo json_encode(['status' => 'saved']);
        } else {
            // Se a sessão caiu ou não existe, cria ela e salva (Recuperação de falha)
            $stmt = $pdo->prepare("INSERT INTO scan_sessions (session_id, scanned_code) VALUES (?, ?)");
            $stmt->execute([$session_id, $code]);
            echo json_encode(['status' => 'saved', 'note' => 'session_restored']);
        }
    }

    // 4. COMPUTADOR: Deleta a sessão ao desconectar
    if ($action == 'delete') {
        $pdo->prepare("DELETE FROM scan_sessions WHERE session_id = ?")->execute([$session_id]);
        echo json_encode(['status' => 'deleted']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>