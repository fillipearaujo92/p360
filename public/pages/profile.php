<?php
// pages/profile.php

$page_title = "Meu Perfil";
$message = '';
$user_id = $_SESSION['user_id'] ?? 0;

// Auto-setup: Colunas de notificação
try {
    $pdo->query("SELECT notify_movements FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN notify_movements TINYINT(1) DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN notify_tickets TINYINT(1) DEFAULT 1");
}

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    try {
        $name = trim($_POST['name']);
        $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
        $phone = $_POST['phone'];
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $notify_movements = isset($_POST['notify_movements']) ? 1 : 0;
        $notify_tickets = isset($_POST['notify_tickets']) ? 1 : 0;

        if (!$email) throw new Exception("O email fornecido não é válido.");

        // Busca dados atuais para validação
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_user_data = $stmt->fetch();

        // Upload de Foto
        $photo_sql = "";
        $params = [$name, $email, $phone, $notify_movements, $notify_tickets];
        $log_details = ["Perfil atualizado."];

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $new_name = uniqid('user_') . '.' . $ext;
            $target_dir = dirname(__DIR__) . '/uploads/users';
            if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_dir . '/' . $new_name)) {
                $photo_sql = ", photo_url=?";
                $log_details[] = "Foto de perfil alterada.";
                $params[] = 'uploads/users/' . $new_name;
                $_SESSION['user_photo'] = 'uploads/users/' . $new_name; // Atualiza sessão
            }
        }

        // Alteração de Senha
        $pass_sql = "";
        if (!empty($new_password)) {
            if (empty($current_password)) throw new Exception("Para alterar a senha, informe a senha atual.");
            if (!password_verify($current_password, $current_user_data['password_hash'])) throw new Exception("A senha atual está incorreta.");
            if (strlen($new_password) < 6) throw new Exception("A nova senha deve ter pelo menos 6 caracteres.");
            if ($new_password !== $confirm_password) throw new Exception("A confirmação da nova senha não coincide.");
            
            $pass_sql = ", password_hash=?";
            $log_details[] = "Senha alterada.";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $params[] = $user_id;

        $sql = "UPDATE users SET name=?, email=?, phone=?, notify_movements=?, notify_tickets=? $photo_sql $pass_sql WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Atualiza nome na sessão
        $_SESSION['user_name'] = $name;

        $message = "Perfil atualizado com sucesso!";
        log_action('profile_update', implode(' ', $log_details));

    } catch (Exception $e) {
        $message = "error:Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. DADOS
// =================================================================================
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
?>

<!-- FEEDBACK -->
<?php if($message): 
    $is_error = strpos($message, 'error:') === 0;
    $msg_text = $is_error ? substr($message, 6) : $message;
    $msg_class = $is_error ? 'border-red-500 text-red-500' : 'border-blue-500 text-blue-500';
    $icon = $is_error ? 'alert-circle' : 'check-circle';
?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-white border-l-4 <?php echo $msg_class; ?> px-6 py-4 rounded shadow-lg flex items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-3">
            <div class="<?php echo $msg_class; ?>"><i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i></div>
            <div><?php echo htmlspecialchars($msg_text); ?></div>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-slate-400 hover:text-slate-600 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage')?.remove(), 4000);</script>
<?php endif; ?>

<div class="max-w-5xl mx-auto pb-10">
    
    <!-- Banner de Fundo -->
    <div class="relative h-48 rounded-t-2xl bg-gradient-to-r from-blue-600 to-indigo-700 overflow-hidden shadow-sm">
        <div class="absolute inset-0 opacity-10 pattern-grid-lg text-white"></div>
        <div class="absolute bottom-0 left-0 w-full h-20 bg-gradient-to-t from-black/20 to-transparent"></div>
    </div>

    <div class="bg-white rounded-b-2xl shadow-sm border-x border-b border-slate-200 relative z-10 -mt-0">
        <form method="POST" enctype="multipart/form-data" class="px-6 pb-8 md:px-10">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="flex flex-col md:flex-row gap-8">
                <!-- Coluna da Esquerda: Foto e Resumo -->
                <div class="md:w-1/3 flex flex-col items-center -mt-20">
                    <div class="relative group mb-4">
                        <?php if(!empty($user['photo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($user['photo_url']); ?>" class="w-40 h-40 rounded-full object-cover border-4 border-white shadow-lg bg-white">
                        <?php else: ?>
                            <div class="w-40 h-40 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 border-4 border-white shadow-lg">
                                <i data-lucide="user" class="w-20 h-20"></i>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Botão de Upload -->
                        <label class="absolute bottom-2 right-2 bg-blue-600 text-white p-2.5 rounded-full cursor-pointer hover:bg-blue-700 transition-all shadow-md border-2 border-white group-hover:scale-110">
                            <i data-lucide="camera" class="w-5 h-5"></i>
                            <input type="file" name="photo" accept="image/*" class="hidden" onchange="this.form.submit()">
                        </label>
                    </div>
                    
                    <div class="text-center">
                        <h2 class="font-bold text-2xl text-slate-800"><?php echo htmlspecialchars($user['name']); ?></h2>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase bg-blue-50 text-blue-700 mt-2 border border-blue-100">
                            <?php echo htmlspecialchars($user['role']); ?>
                        </span>
                        <?php if(isset($user['created_at'])): ?>
                        <p class="text-sm text-slate-400 mt-3 flex items-center justify-center gap-1">
                            <i data-lucide="calendar" class="w-3 h-3"></i> Membro desde <?php echo date('Y', strtotime($user['created_at'])); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Coluna da Direita: Formulário -->
                <div class="flex-1 space-y-8 mt-6 md:mt-0">
                    
                    <!-- Seção: Informações Pessoais -->
                    <div>
                        <div class="flex items-center gap-2 mb-4 border-b border-slate-100 pb-2">
                            <i data-lucide="user-cog" class="w-5 h-5 text-blue-600"></i>
                            <h3 class="text-lg font-bold text-slate-800">Informações Pessoais</h3>
                        </div>
                        
                        <div class="grid grid-cols-1 gap-5">
                            <div class="relative">
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Nome Completo</label>
                                <div class="relative">
                                    <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div class="relative">
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Email</label>
                                    <div class="relative">
                                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                                    </div>
                                </div>
                                <div class="relative">
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Telefone</label>
                                    <div class="relative">
                                        <i data-lucide="phone" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Seção: Preferências de Notificação -->
                    <div class="bg-slate-50 p-6 rounded-2xl border border-slate-100">
                        <div class="flex items-center gap-2 mb-4">
                            <i data-lucide="bell" class="w-5 h-5 text-blue-600"></i>
                            <h3 class="text-lg font-bold text-slate-800">Preferências de Notificação</h3>
                        </div>
                        
                        <div class="space-y-3">
                            <label class="flex items-start gap-3 cursor-pointer group p-3 bg-white rounded-xl border border-slate-200 hover:border-blue-300 transition-all shadow-sm">
                                <input type="checkbox" name="notify_movements" value="1" <?php echo ($user['notify_movements'] ?? 1) ? 'checked' : ''; ?> class="mt-1 w-5 h-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500 transition-all">
                                <div>
                                    <span class="block text-sm font-bold text-slate-700 group-hover:text-blue-700 transition-colors">Movimentações de Ativos</span>
                                    <span class="block text-xs text-slate-500 mt-0.5">Receber email quando um ativo sob minha responsabilidade for movimentado.</span>
                                </div>
                            </label>
                            
                            <label class="flex items-start gap-3 cursor-pointer group p-3 bg-white rounded-xl border border-slate-200 hover:border-blue-300 transition-all shadow-sm">
                                <input type="checkbox" name="notify_tickets" value="1" <?php echo ($user['notify_tickets'] ?? 1) ? 'checked' : ''; ?> class="mt-1 w-5 h-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500 transition-all">
                                <div>
                                    <span class="block text-sm font-bold text-slate-700 group-hover:text-blue-700 transition-colors">Atualizações de Tickets</span>
                                    <span class="block text-xs text-slate-500 mt-0.5">Receber email sobre mudanças em chamados que abri ou sou responsável.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Seção: Segurança -->
                    <div class="bg-slate-50 p-6 rounded-2xl border border-slate-100">
                        <div class="flex items-center gap-2 mb-4">
                            <i data-lucide="shield-check" class="w-5 h-5 text-blue-600"></i>
                            <h3 class="text-lg font-bold text-slate-800">Alterar Senha</h3>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Senha Atual</label>
                                <div class="relative"><input type="password" name="current_password" id="currPass" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 outline-none bg-white"><button type="button" onclick="togglePass('currPass')" class="absolute right-3 top-2.5 text-slate-400 hover:text-blue-600"><i data-lucide="eye" class="w-4 h-4"></i></button></div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Nova Senha</label>
                                <div class="relative"><input type="password" name="new_password" id="newPass" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 outline-none bg-white"><button type="button" onclick="togglePass('newPass')" class="absolute right-3 top-2.5 text-slate-400 hover:text-blue-600"><i data-lucide="eye" class="w-4 h-4"></i></button></div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Confirmar</label>
                                <div class="relative"><input type="password" name="confirm_password" id="confPass" class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 outline-none bg-white"><button type="button" onclick="togglePass('confPass')" class="absolute right-3 top-2.5 text-slate-400 hover:text-blue-600"><i data-lucide="eye" class="w-4 h-4"></i></button></div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 mt-3 flex items-center gap-1"><i data-lucide="info" class="w-3 h-3"></i> Preencha apenas se desejar alterar sua senha.</p>
                    </div>

                    <!-- Botões de Ação -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <button type="button" onclick="window.location.reload()" class="px-6 py-2.5 border border-slate-300 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-50 transition-colors">
                            Cancelar
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-blue-200 transition-all transform active:scale-95 flex items-center gap-2">
                            <i data-lucide="check-circle" class="w-4 h-4"></i> Salvar Alterações
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function togglePass(id) {
        const input = document.getElementById(id);
        if (input.type === "password") {
            input.type = "text";
        } else {
            input.type = "password";
        }
    }
    lucide.createIcons();
</script>