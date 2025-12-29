<?php
session_start();

// --- MODO DEBUG ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1. Incluir Configuração (Onde o $pdo é criado)
// Usamos o caminho absoluto (__DIR__) para evitar erros de pasta
$config_path = __DIR__ . '/config.php';

if (file_exists($config_path)) {
    require_once $config_path;
} else {
    die("Erro Crítico: O ficheiro <code>config.php</code> não foi encontrado na pasta: " . __DIR__);
}

// 2. Verificar se a conexão foi bem sucedida
if (!isset($pdo)) {
    die("Erro Crítico: A variável de conexão <code>\$pdo</code> não existe. Verifique se o seu ficheiro <code>config.php</code> está a criar a conexão corretamente (<code>\$pdo = new PDO(...)</code>).");
}

$error = '';

// 3. Processar Login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // --- CONFIGURAÇÃO DE SEGURANÇA ---
    define('MAX_LOGIN_ATTEMPTS', 5); // Nº máximo de tentativas
    define('LOCKOUT_TIME', 900);     // Tempo de bloqueio em segundos (900s = 15 minutos)

    try {
        // 1. Obter o utilizador e o seu estado de tentativas de login
        $stmt = $pdo->prepare("SELECT id, name, role, password_hash, company_id, failed_login_attempts, last_attempt_time FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // 2. Verificar se a conta está temporariamente bloqueada
            $time_since_last_attempt = $user['last_attempt_time'] ? (time() - strtotime($user['last_attempt_time'])) : (LOCKOUT_TIME + 1);
            if ($user['failed_login_attempts'] >= MAX_LOGIN_ATTEMPTS && $time_since_last_attempt < LOCKOUT_TIME && $user['last_attempt_time'] !== null) {
                $remaining_time = ceil((LOCKOUT_TIME - $time_since_last_attempt) / 60);
                $error = "Demasiadas tentativas falhadas. Por favor, tente novamente dentro de {$remaining_time} minutos.";
            } 
            // 3. Verificar a senha
            elseif (password_verify($password, $user['password_hash'])) {
                // Sucesso: Limpar tentativas falhadas e criar sessão
                if ($user['failed_login_attempts'] > 0) {
                    $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, last_attempt_time = NULL WHERE id = :id");
                    $updateStmt->execute(['id' => $user['id']]);
                }

                // --- LÓGICA "LEMBRAR-ME" ---
                if (isset($_POST['remember-me'])) {
                    // 1. Gerar tokens seguros
                    $selector = bin2hex(random_bytes(16));
                    $validator = bin2hex(random_bytes(32));
                    
                    // 2. Definir data de expiração (ex: 30 dias)
                    $expires = new DateTime('+30 days');

                    // 3. Guardar na base de dados (o validador é hashed)
                    $tokenStmt = $pdo->prepare("INSERT INTO auth_tokens (selector, hashed_validator, user_id, expires) VALUES (:selector, :hashed_validator, :user_id, :expires)");
                    $tokenStmt->execute([
                        'selector' => $selector,
                        'hashed_validator' => hash('sha256', $validator),
                        'user_id' => $user['id'],
                        'expires' => $expires->format('Y-m-d H:i:s')
                    ]);

                    // 4. Guardar no cookie do utilizador (selector:validator)
                    setcookie('remember_me', $selector . ':' . $validator, $expires->getTimestamp(), '/', '', false, true); // Para produção, use 'true' no penúltimo parâmetro (secure)
                }

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_company_id'] = $user['company_id'];
                
                // LOG SUCCESSFUL LOGIN
                log_action('login_success', "Utilizador '{$user['name']}' (Email: {$email}) efetuou login.");

                header("Location: index.php?page=dashboard");
                exit;
            } else {
                // Falha: Incrementar contador de tentativas
                $new_attempts = $user['failed_login_attempts'] + 1;
                $updateStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = :attempts, last_attempt_time = NOW() WHERE id = :id");
                $updateStmt->execute(['attempts' => $new_attempts, 'id' => $user['id']]);

                // LOG FAILED LOGIN
                try {
                    $stmtLog = $pdo->prepare("INSERT INTO system_logs (action, description, ip_address) VALUES ('login_failed', ?, ?)");
                    $stmtLog->execute(["Tentativa de login falhada para: $email", $_SERVER['REMOTE_ADDR']]);
                } catch (Exception $e) { /* Ignora se tabela não existir */ }

                $error = "Email ou senha incorretos.";
            }
        } else {
            // Utilizador não encontrado. Mensagem de erro genérica para não revelar se o email existe.
            $error = "Email ou senha incorretos.";
        }

        // Lógica antiga movida para dentro das novas verificações
        /* if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_company_id'] = $user['company_id'];
            header("Location: index.php?page=dashboard");
            exit;
        } else {
            $error = "Email ou senha incorretos.";
        } */
    } catch (PDOException $e) {
        $error = "Erro no Banco de Dados: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AssetManager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); 
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-white to-slate-100 h-screen flex items-center justify-center p-4">
    <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md border border-slate-200/80">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-blue-100 text-blue-600 mb-4">
                <i data-lucide="codesandbox" class="w-6 h-6"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">AssetManager</h1>
            <p class="text-slate-500 text-sm mt-1">Gestão Patrimonial Inteligente</p>
        </div>

        <?php if($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm flex items-center gap-2">
                <i data-lucide="alert-circle" class="h-5 w-5"></i>
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="mb-4">
                <label class="block text-slate-700 text-sm font-semibold mb-2">Email</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i data-lucide="mail" class="h-5 w-5 text-slate-400"></i>
                    </div>
                    <input type="email" name="email" required placeholder="ex: admin@empresa.com" class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-slate-700 text-sm font-semibold mb-2">Senha</label>
                <div class="relative">
                     <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i data-lucide="lock" class="h-5 w-5 text-slate-400"></i>
                    </div>
                    <input type="password" name="password" id="password" required placeholder="••••••••" class="w-full pl-10 pr-10 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                    <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-sm leading-5">
                        <i id="eye-open" data-lucide="eye" class="h-5 w-5 text-slate-500"></i>
                        <i id="eye-closed" data-lucide="eye-off" class="h-5 w-5 text-slate-500 hidden"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center">
                    <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-slate-300 rounded">
                    <label for="remember-me" class="ml-2 block text-sm text-slate-600">Lembrar-me</label>
                </div>
                <div class="text-sm">
                    <a href="#" class="font-medium text-blue-600 hover:text-blue-500">
                    </a>
                </div>
            </div>

            <button type="submit" id="submitButton" class="w-full flex justify-center items-center gap-2 bg-blue-600 text-white font-bold py-2.5 px-4 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 shadow-lg shadow-blue-500/20">
                <span id="buttonText">Entrar no Sistema</span>
                <i id="buttonSpinner" data-lucide="loader-2" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden"></i>
            </button>
        </form>
        
        <div class="mt-8 pt-6 border-t border-slate-100 text-center">
            <p class="text-xs text-slate-400">
                Ainda não tem acesso? Contacte o administrador.
            </p>
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeOpen = document.querySelector('#eye-open');
        const eyeClosed = document.querySelector('#eye-closed');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            eyeOpen.classList.toggle('hidden');
            eyeClosed.classList.toggle('hidden');
        });

        // Form submission loading state
        const loginForm = document.querySelector('#loginForm');
        const submitButton = document.querySelector('#submitButton');
        const buttonText = document.querySelector('#buttonText');
        const buttonSpinner = document.querySelector('#buttonSpinner');

        loginForm.addEventListener('submit', function() {
            submitButton.disabled = true;
            buttonText.textContent = 'A processar...';
            buttonSpinner.classList.remove('hidden');
        });

        // Initialize Lucide Icons
        lucide.createIcons();
    </script>
</body>
</html>