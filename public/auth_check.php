<?php
// auth_check.php
session_start();

// Verifica se a variável de sessão 'user_id' existe
if (!isset($_SESSION['user_id'])) {
    // Se não estiver logado, redireciona para o login
    header("Location: login.php");
    exit;
}

// Opcional: Recuperar dados do usuário da sessão para exibir na tela
$user_name = $_SESSION['user_name'] ?? 'Usuário';
$user_role = $_SESSION['user_role'] ?? 'leitor';
?>