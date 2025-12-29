<?php
// config.php

// Define o fuso horário padrão (Brasil)
date_default_timezone_set('America/Sao_Paulo');

// --- CREDENCIAIS DO POSTGRESQL ---
// CORREÇÃO: Use o host público do seu servidor
$host = 'rotapgadmin.aled1.com'; // Host onde o PostgreSQL está rodando
$port = '5432'; // Porta padrão do Postgres
$db_name = 'controle_patrimonial_saas'; 
$username = 'patrimonio_user'; 
$password = '@D1scoverY';

try {
    // String de Conexão (DSN) para PostgreSQL
    $dsn = "pgsql:host=$host;port=$port;dbname=$db_name";
    
    // Cria a conexão PDO
    $pdo = new PDO($dsn, $username, $password);
    
    // Configurações de erro e modo de busca
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Sincroniza o horário (Sintaxe do Postgres)
    $pdo->exec("SET timezone TO '" . date('P') . "'");
    
} catch(PDOException $e) {
    // Tratamento de erro visual
    die("<div style='padding: 20px; background: #fee2e2; color: #991b1b; font-family: sans-serif; border: 1px solid #ef4444; border-radius: 8px;'>
            <strong>Erro de Conexão (PostgreSQL):</strong><br>" . $e->getMessage() . 
            "<br><br><strong>Dicas de troubleshooting:</strong>
            <ul>
                <li>Verifique se a extensão <b>pdo_pgsql</b> está ativada no PHP.ini</li>
                <li>Confirme se o host '$host' está acessível</li>
                <li>Verifique se o firewall permite conexões na porta $port</li>
                <li>Teste a conexão: <code>telnet $host $port</code></li>
            </ul>
         </div>");
}

// Configuração do WhatsApp
$whatsapp_number = '5511999999999'; 

// Chave de API
$api_key = 'aleddesk_secret_key_12345';

// --- FUNÇÃO GLOBAL DE LOG (POSTGRES) ---
function log_action($action, $description) {
    global $pdo;
    try {
        $sqlTable = "CREATE TABLE IF NOT EXISTS system_logs (
            id SERIAL PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(50),
            description TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sqlTable);

        $user_id = $_SESSION['user_id'] ?? null;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $description, $ip_address]);
        
    } catch (Exception $e) {
        // Falha silenciosa no log
        error_log("Log Error: " . $e->getMessage());
    }
}
?>