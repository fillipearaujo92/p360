<?php
// public_ticket.php - Acesso via QR Code com Níveis de Segurança
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php'; // Apenas conexão com o banco

$asset = null;
$message = '';
$view_state = 'loading'; // Estados: loading, form, login, email_auth, error, success
$asset_code = $_GET['code'] ?? '';

// 1. BUSCAR ATIVO PELO CÓDIGO
if ($asset_code) {
    $stmt = $pdo->prepare("
        SELECT a.*, c.name as company_name, l.name as location_name 
        FROM assets a 
        LEFT JOIN companies c ON a.company_id = c.id 
        LEFT JOIN locations l ON a.location_id = l.id 
        WHERE a.code = ?
    ");
    $stmt->execute([$asset_code]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 2. LÓGICA DE ACESSO (DECISÃO DO ESTADO)
if (!$asset) {
    $view_state = 'error';
} else {
    $access_level = $asset['qr_access_level'] ?? 'public';

    // CASO 1: PÚBLICO -> Acesso liberado
    if ($access_level === 'public') {
        $view_state = 'form';
    } 
    // CASO 2: APENAS EMAIL -> Verifica sessão temporária de email
    elseif ($access_level === 'email_only') {
        if (isset($_SESSION['user_id']) || isset($_SESSION['email_auth_valid'])) {
            $view_state = 'form';
        } else {
            $view_state = 'email_auth';
        }
    } 
    // CASO 3: LOGIN REQUERIDO -> Verifica sessão de usuário completa
    else { 
        // 'login' ou 'pre_auth'
        if (isset($_SESSION['user_id'])) {
            $view_state = 'form';
        } else {
            $view_state = 'login';
        }
    }
}

// 3. PROCESSAR LOGIN COMPLETO (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'auth_qr') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_company_id'] = $user['company_id'];
        header("Location: public_ticket.php?code=" . $asset_code);
        exit;
    } else {
        $message = "Email ou senha incorretos.";
        $view_state = 'login';
    }
}

// 4. PROCESSAR VALIDAÇÃO DE EMAIL (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'auth_email_only') {
    $email = trim($_POST['email']);
    
    // Verifica se o email existe na base (opcional, pode remover se quiser aceitar qualquer email)
    // Aqui estamos validando se é um funcionário cadastrado
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['email_auth_valid'] = true;
        $_SESSION['email_auth_user'] = $user['name'];
        header("Location: public_ticket.php?code=" . $asset_code);
        exit;
    } else {
        $message = "Email não autorizado.";
        $view_state = 'email_auth';
    }
}

// 5. PROCESSAR ABERTURA DE CHAMADO (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_ticket' && $view_state == 'form') {
    $description = $_POST['description'];
    // Define quem está abrindo: Usuário Logado > Email Validado > Campo Input
    $contact = $_SESSION['user_name'] ?? ($_SESSION['email_auth_user'] ?? $_POST['contact']);
    
    // Upload Fotos
    $photo_urls = [];
    if (!empty($_FILES['photos']['name'][0])) {
        if (!is_dir('uploads/tickets')) @mkdir('uploads/tickets', 0777, true);
        foreach ($_FILES['photos']['name'] as $key => $name) {
            if ($_FILES['photos']['error'][$key] == 0) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $new_name = uniqid('ticket_') . '.' . $ext;
                if (move_uploaded_file($_FILES['photos']['tmp_name'][$key], 'uploads/tickets/' . $new_name)) {
                    $photo_urls[] = 'uploads/tickets/' . $new_name;
                }
            }
        }
    }

    try {
        $pdo->beginTransaction();
        // Cria o ticket com status 'aberto' (Triagem)
        $stmt = $pdo->prepare("INSERT INTO tickets (company_id, asset_id, description, contact_info, photos_json, status) VALUES (?, ?, ?, ?, ?, 'aberto')");
        $stmt->execute([$asset['company_id'], $asset['id'], $description, $contact, json_encode($photo_urls)]);
        $ticket_id = $pdo->lastInsertId();
        $pdo->commit();
        $view_state = 'success';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Erro ao abrir chamado: " . $e->getMessage();
        $view_state = 'form';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Atendimento - <?php echo $asset ? htmlspecialchars($asset['name']) : 'Erro'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .glass-effect { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4">

    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-200 transform transition-all">

        <!-- ESTADO: ERRO (Ativo não encontrado) -->
        <?php if ($view_state === 'error'): ?>
            <div class="p-10 text-center">
                <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-6 text-red-500 shadow-sm">
                    <i data-lucide="alert-triangle" class="w-10 h-10"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Ativo não encontrado</h1>
                <p class="text-slate-500">O código QR escaneado é inválido.</p>
            </div>

        <!-- ESTADO: LOGIN (Para nível 'login') -->
        <?php elseif ($view_state === 'login'): ?>
            <div class="bg-slate-900 p-8 text-center relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-br from-blue-600/20 to-purple-600/20"></div>
                <div class="relative z-10">
                    <div class="w-16 h-16 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20">
                        <i data-lucide="lock" class="w-8 h-8 text-white"></i>
                    </div>
                    <h2 class="text-xl font-bold text-white">Acesso Restrito</h2>
                    <p class="text-blue-200 text-sm mt-1">Faça login para abrir um chamado.</p>
                </div>
            </div>
            <div class="p-8">
                <?php if($message): ?>
                    <div class="bg-red-50 text-red-600 p-3 rounded-lg text-sm mb-4 flex items-center gap-2 border border-red-100">
                        <i data-lucide="x-circle" class="w-4 h-4"></i> <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="auth_qr">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">Email</label>
                        <div class="relative">
                            <i data-lucide="mail" class="absolute left-3 top-3 w-5 h-5 text-slate-400"></i>
                            <input type="email" name="email" required class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none bg-slate-50 focus:bg-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">Senha</label>
                        <div class="relative">
                            <i data-lucide="key" class="absolute left-3 top-3 w-5 h-5 text-slate-400"></i>
                            <input type="password" name="password" required class="w-full pl-10 pr-4 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none bg-slate-50 focus:bg-white">
                        </div>
                    </div>
                    <button class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-3.5 rounded-xl shadow-lg transition-all active:scale-95">Entrar</button>
                </form>
            </div>

        <!-- ESTADO: EMAIL ONLY (Para nível 'email_only') -->
        <?php elseif ($view_state === 'email_auth'): ?>
            <div class="bg-blue-600 p-8 text-center relative overflow-hidden">
                <div class="relative z-10">
                    <div class="w-16 h-16 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="mail-check" class="w-8 h-8 text-white"></i>
                    </div>
                    <h2 class="text-xl font-bold text-white">Identificação</h2>
                    <p class="text-blue-100 text-sm mt-1">Informe seu email para continuar.</p>
                </div>
            </div>
            <div class="p-8">
                <?php if($message): ?>
                    <div class="bg-red-50 text-red-600 p-3 rounded-lg text-sm mb-4 flex items-center gap-2 border border-red-100">
                        <i data-lucide="x-circle" class="w-4 h-4"></i> <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="auth_email_only">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">Email Corporativo</label>
                        <input type="email" name="email" required class="w-full p-3 border rounded-xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="seu@email.com">
                    </div>
                    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-xl shadow-lg transition-all active:scale-95">Validar e Continuar</button>
                </form>
            </div>

        <!-- ESTADO: SUCESSO -->
        <?php elseif ($view_state === 'success'): ?>
            <div class="p-12 text-center">
                <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 text-green-600 animate-bounce">
                    <i data-lucide="check" class="w-10 h-10"></i>
                </div>
                <h1 class="text-2xl font-bold text-slate-800 mb-2">Chamado Aberto!</h1>
                <p class="text-slate-500 mb-8">Sua solicitação foi enviada com sucesso.<br>Ticket #<?php echo $ticket_id ?? '---'; ?></p>
                <a href="public_ticket.php?code=<?php echo $asset_code; ?>" class="inline-block bg-slate-100 text-slate-600 font-semibold py-2 px-6 rounded-lg hover:bg-slate-200 transition-colors">Voltar</a>
            </div>

        <!-- ESTADO: FORMULÁRIO (Acesso Permitido) -->
        <?php elseif ($view_state === 'form'): ?>
            
            <!-- Header do Ativo -->
            <div class="relative h-48 bg-slate-800 group overflow-hidden">
                <?php if($asset['photo_url']): ?>
                    <img src="<?php echo htmlspecialchars($asset['photo_url']); ?>" class="w-full h-full object-cover opacity-70 transition-transform duration-700 hover:scale-105">
                <?php else: ?>
                    <div class="absolute inset-0 flex items-center justify-center opacity-20"><i data-lucide="box" class="w-24 h-24 text-white"></i></div>
                <?php endif; ?>
                <div class="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-transparent"></div>
                
                <div class="absolute bottom-0 left-0 w-full p-6 text-white">
                    <span class="bg-white/20 backdrop-blur-sm px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wider mb-2 inline-block border border-white/10">
                        <?php echo htmlspecialchars($asset['code']); ?>
                    </span>
                    <h1 class="text-2xl font-bold leading-tight shadow-sm"><?php echo htmlspecialchars($asset['name']); ?></h1>
                </div>
            </div>

            <!-- Info -->
            <div class="flex border-b border-slate-100">
                <div class="flex-1 p-4 text-center border-r border-slate-100">
                    <p class="text-xs text-slate-400 uppercase font-bold">Local</p>
                    <p class="text-sm font-medium text-slate-700 truncate"><?php echo htmlspecialchars($asset['location_name'] ?? '-'); ?></p>
                </div>
                <div class="flex-1 p-4 text-center">
                    <p class="text-xs text-slate-400 uppercase font-bold">Status</p>
                    <p class="text-sm font-medium text-slate-700 capitalize"><?php echo htmlspecialchars($asset['status']); ?></p>
                </div>
            </div>

            <div class="p-6">
                <?php if ($message): ?>
                    <div class="bg-red-50 border border-red-100 text-red-600 p-3 rounded-lg text-sm mb-6 flex items-center gap-2">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i> <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="space-y-5">
                    <input type="hidden" name="action" value="create_ticket">

                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-8 h-8 bg-orange-100 text-orange-600 rounded-full flex items-center justify-center shrink-0"><i data-lucide="life-buoy" class="w-4 h-4"></i></div>
                        <h3 class="font-bold text-slate-800">Abrir Chamado</h3>
                    </div>

                    <?php if(isset($_SESSION['user_name']) || isset($_SESSION['email_auth_user'])): ?>
                        <div class="bg-blue-50 border border-blue-100 text-blue-700 px-4 py-3 rounded-xl text-sm flex items-center gap-2">
                            <i data-lucide="user-check" class="w-4 h-4"></i> Identificado como <b><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['email_auth_user']); ?></b>
                        </div>
                    <?php else: ?>
                        <!-- Campo Nome apenas se for acesso Público e não estiver logado -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">Seu Nome *</label>
                            <input type="text" name="contact" required placeholder="Quem está solicitando?" class="w-full border border-slate-200 rounded-xl p-3 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">O que aconteceu? *</label>
                        <textarea name="description" required rows="4" placeholder="Descreva o problema em detalhes..." class="w-full border border-slate-200 rounded-xl p-3 text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1 ml-1">Foto do Problema</label>
                        <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-slate-300 rounded-xl cursor-pointer hover:bg-slate-50 hover:border-blue-400 transition-all group">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                <i data-lucide="camera" class="w-8 h-8 text-slate-300 group-hover:text-blue-500 mb-2 transition-colors"></i>
                                <p class="text-xs text-slate-400 group-hover:text-slate-600">Toque para adicionar foto</p>
                            </div>
                            <input type="file" name="photos[]" accept="image/*" class="hidden">
                        </label>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 rounded-xl shadow-lg shadow-blue-200 transition-all transform active:scale-[0.98] flex items-center justify-center gap-2">
                        Enviar Solicitação <i data-lucide="send" class="w-4 h-4"></i>
                    </button>
                </form>
            </div>

        <?php endif; ?>
    </div>

    <div class="mt-6 text-center">
        <p class="text-xs text-slate-400 font-medium">Patrimônio 360º &copy; <?php echo date('Y'); ?></p>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>