<?php
// config.php

// Define o fuso horário padrão (Brasil) para funções de data do PHP
date_default_timezone_set('America/Sao_Paulo');

$host = 'localhost';
// Certifique-se que este nome é EXATAMENTE o do seu banco no phpMyAdmin
$db_name = 'controle_patrimonial_saas'; 
$username = 'root';
$password = ''; // Senha padrão do XAMPP geralmente é vazia

try {
    // Cria a conexão PDO e atribui à variável $pdo (essencial para o resto do sistema)
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    
    // Configurações de segurança e erros
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Sincroniza o horário do MySQL com o PHP para garantir que NOW() e CURRENT_TIMESTAMP estejam corretos
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    
} catch(PDOException $e) {
    // Mostra erro amigável se falhar
    die("<div style='padding: 20px; background: #fee2e2; color: #991b1b; font-family: sans-serif; border: 1px solid #ef4444; border-radius: 8px;'>
            <strong>Erro Crítico de Banco de Dados:</strong><br>" . $e->getMessage() . 
            "<br><br>Verifique se o banco de dados <u>'$db_name'</u> foi criado no phpMyAdmin.
         </div>");
}

// Configuração do WhatsApp (DDI + DDD + Número, apenas números)
$whatsapp_number = '5511999999999'; 

// Chave de API para integração com AledDesk (Altere para uma senha forte)
$api_key = 'aleddesk_secret_key_12345';

// --- FUNÇÃO GLOBAL DE LOG ---
function log_action($action, $description) {
    global $pdo;
    try {
        // Garante que a tabela exista
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(50),
            description TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $user_id = $_SESSION['user_id'] ?? null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $description, $ip_address]);
    } catch (Exception $e) { error_log("Falha ao registrar log: " . $e->getMessage()); }
}
?>