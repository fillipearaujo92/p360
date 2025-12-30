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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Patrimônio 360º</title>
    <link rel="shortcut icon" href="src/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); 
        body { font-family: 'Inter', sans-serif; }
        
        /* Animação suave para o fundo */
        @keyframes blob {
            0% { transform: translate(0px, 0px) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
            100% { transform: translate(0px, 0px) scale(1); }
        }
        .animate-blob { animation: blob 7s infinite; }
        .animation-delay-2000 { animation-delay: 2s; }
        .animation-delay-4000 { animation-delay: 4s; }
    </style>
</head>
<body class="bg-white h-screen w-full overflow-hidden flex">

    <!-- Lado Esquerdo (Visual / Branding) - Apenas Desktop -->
    <div class="hidden lg:flex lg:w-1/2 relative bg-slate-900 items-center justify-center overflow-hidden">
        <!-- Background Image & Overlay -->
        <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=2070&auto=format&fit=crop')] bg-cover bg-center opacity-20 mix-blend-overlay"></div>
        <div class="absolute inset-0 bg-gradient-to-br from-blue-600/90 to-slate-900/95"></div>
        
        <!-- Elementos Decorativos Animados -->
        <div class="absolute top-0 -left-4 w-72 h-72 bg-purple-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-blob"></div>
        <div class="absolute top-0 -right-4 w-72 h-72 bg-blue-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-blob animation-delay-2000"></div>
        <div class="absolute -bottom-8 left-20 w-72 h-72 bg-indigo-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-blob animation-delay-4000"></div>

        <!-- Conteúdo -->
        <div class="relative z-10 p-12 max-w-xl text-white">
            <div class="w-24 h-24 mb-8">
                <img src="src/Logo P360 Branco.png" alt="Patrimônio 360º Logo" class="w-24 h-24 object-contain">
            </div>
            <h2 class="text-5xl font-bold mb-6 leading-tight tracking-tight">Gestão Patrimonial <br><span class="text-blue-400">Inteligente.</span></h2>
            <p class="text-lg text-blue-100/80 leading-relaxed mb-8">
                Tenha controle total sobre o ciclo de vida dos seus ativos. 
                Rastreamento, auditoria e manutenção em uma única plataforma integrada.
            </p>
            
            <div class="flex items-center gap-4 pt-8 border-t border-white/10">
                <div class="flex -space-x-3">
                    <div class="w-10 h-10 rounded-full border-2 border-slate-900 bg-slate-700 flex items-center justify-center text-xs font-bold">JD</div>
                    <div class="w-10 h-10 rounded-full border-2 border-slate-900 bg-slate-600 flex items-center justify-center text-xs font-bold">AS</div>
                    <div class="w-10 h-10 rounded-full border-2 border-slate-900 bg-slate-500 flex items-center justify-center text-xs font-bold">+5</div>
                </div>
                <div class="text-sm font-medium text-blue-200">
                    <span class="text-white font-bold">Equipa Conectada</span><br>Acesso seguro e auditável.
                </div>
            </div>
        </div>
    </div>

    <!-- Lado Direito (Formulário) -->
    <div class="w-full lg:w-1/2 flex flex-col justify-center items-center p-6 lg:p-12 bg-white relative overflow-y-auto">
        
        <div class="w-full max-w-md space-y-8">
            
            <!-- Header Mobile -->
            <div class="lg:hidden text-center mb-8">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-600 text-white mb-4 shadow-lg shadow-blue-600/30">
                    <i data-lucide="codesandbox" class="w-7 h-7"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-900">Patrimônio 360º</h1>
            </div>

            <!-- Header Desktop -->
            <div class="text-center lg:text-left">
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight">Bem-vindo de volta</h2>
                <p class="mt-2 text-slate-500">Insira as suas credenciais para aceder à sua conta.</p>
            </div>

            <?php if($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-xl text-sm flex items-start gap-3 animate-pulse" role="alert">
                    <i data-lucide="alert-circle" class="h-5 w-5 shrink-0 mt-0.5"></i>
                    <div>
                        <span class="font-bold block mb-1">Falha na autenticação</span>
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" class="space-y-6">
                
                <div class="space-y-2">
                    <label for="email" class="text-sm font-semibold text-slate-700">Email Corporativo</label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i data-lucide="mail" class="h-5 w-5 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        </div>
                        <input type="email" id="email" name="email" required 
                            class="block w-full pl-10 pr-3 py-3 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all bg-slate-50 focus:bg-white" 
                            placeholder="nome@empresa.com">
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label for="password" class="text-sm font-semibold text-slate-700">Senha</label>
                        <a href="#" class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline">Esqueceu a senha?</a>
                    </div>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i data-lucide="lock" class="h-5 w-5 text-slate-400 group-focus-within:text-blue-500 transition-colors"></i>
                        </div>
                        <input type="password" name="password" id="password" required 
                            class="block w-full pl-10 pr-10 py-3 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all bg-slate-50 focus:bg-white" 
                            placeholder="••••••••">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer">
                            <i id="eye-open" data-lucide="eye" class="h-5 w-5"></i>
                            <i id="eye-closed" data-lucide="eye-off" class="h-5 w-5 hidden"></i>
                        </button>
                    </div>
                </div>

                <div class="flex items-center">
                    <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-slate-300 rounded cursor-pointer">
                    <label for="remember-me" class="ml-2 block text-sm text-slate-600 cursor-pointer select-none">Manter sessão iniciada</label>
                </div>

                <button type="submit" id="submitButton" class="w-full flex justify-center items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-4 rounded-xl focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 shadow-lg shadow-blue-600/20 active:scale-[0.98]">
                    <span id="buttonText">Entrar no Sistema</span>
                    <i id="buttonSpinner" data-lucide="loader-2" class="animate-spin h-5 w-5 hidden"></i>
                </button>
            </form>

            <div class="pt-6 text-center space-y-4">
                <p class="text-sm text-slate-500">
                    Não tem uma conta? <a href="#" class="font-medium text-blue-600 hover:text-blue-500 hover:underline">Contacte o suporte</a>
                </p>
                <p class="text-xs text-slate-400">
                    &copy; <?php echo date('Y'); ?> Patrimônio 360º.
                </p>
            </div>
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeOpen = document.querySelector('#eye-open');
        const eyeClosed = document.querySelector('#eye-closed');

        togglePassword.addEventListener('click', function (e) {
            e.preventDefault(); // Prevent focus loss
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
            // Prevent double submission
            if (submitButton.disabled) return;
            
            submitButton.disabled = true;
            submitButton.classList.add('opacity-75', 'cursor-not-allowed');
            buttonText.textContent = 'Autenticando...';
            buttonSpinner.classList.remove('hidden');
        });

        // Initialize Lucide Icons
        lucide.createIcons();
    </script>
</body>
</html>