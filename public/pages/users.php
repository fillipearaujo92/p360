<?php
// pages/users.php

$message = '';

// Auto-setup: Adiciona coluna de responsabilidades/checklist se não existir
try {
    $pdo->query("SELECT responsibilities FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN responsibilities TEXT DEFAULT NULL");
}

// Auto-setup: Tabela de Funções e Permissões
$pdo->exec("CREATE TABLE IF NOT EXISTS roles (
    role_key VARCHAR(50) PRIMARY KEY,
    permissions TEXT
)");

// Garante que as funções padrão existam
$default_roles = ['admin', 'gestor', 'tecnico', 'leitor'];
foreach($default_roles as $r) {
    $stmt = $pdo->prepare("SELECT role_key FROM roles WHERE role_key = ?");
    $stmt->execute([$r]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO roles (role_key, permissions) VALUES (?, '[]')")->execute([$r]);
    }
}

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // --- CRIAR UTILIZADOR ---
        if (isset($_POST['action']) && $_POST['action'] == 'create_user') {
            $name = trim($_POST['name']);
            $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'];
            $confirm_password = $_POST['confirm_password'];
            $role = $_POST['role'];
            $phone = $_POST['phone'];
            $responsibilities = $_POST['responsibilities'] ?? '';
            $company_id = $_SESSION['user_company_id'] ?? 1;

            // Validações
            if (!$email) throw new Exception("O email fornecido não é válido.");
            if (strlen($password) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");
            if ($password !== $confirm_password) throw new Exception("As senhas não coincidem.");

            // Verifica duplicidade
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) throw new Exception("Este email já está registado.");

            // Upload Foto
            $photo_url = '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid('user_') . '.' . $ext;
                $target_dir = dirname(__DIR__) . '../uploads/users';
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_dir . '/' . $new_name)) {
                    $photo_url = '../uploads/users/' . $new_name;
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (company_id, name, email, password_hash, role, phone, photo_url, responsibilities) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $name, $email, $hash, $role, $phone, $photo_url, $responsibilities]);
            
            $message = "Utilizador criado com sucesso!";
            log_action('user_create', "Utilizador '{$name}' (Email: {$email}) criado com a função '{$role}'.");
        }

        // --- EDITAR UTILIZADOR ---
        if (isset($_POST['action']) && $_POST['action'] == 'update_user') {
            $id = $_POST['id'];
            $name = trim($_POST['name']);
            $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
            $role = $_POST['role'];
            $phone = $_POST['phone'];
            $responsibilities = $_POST['responsibilities'] ?? '';
            $password = $_POST['password']; 
            $confirm_password = $_POST['confirm_password'];

            if (!$email) throw new Exception("O email fornecido não é válido.");

            // Upload Foto (Se enviada)
            $photo_sql_part = "";
            $params = [$name, $email, $role, $phone, $responsibilities];
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid('user_') . '.' . $ext;
                $target_dir = dirname(__DIR__) . '../uploads/users';
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_dir . '/' . $new_name)) {
                    $photo_sql_part = ", photo_url=?";
                    $params[] = '../uploads/users/' . $new_name;
                }
            }

            // Senha (Se enviada)
            $pass_sql_part = "";
            if (!empty($password)) {
                if (strlen($password) < 6) throw new Exception("A senha deve ter pelo menos 6 caracteres.");
                if ($password !== $confirm_password) throw new Exception("As senhas não coincidem.");
                $pass_sql_part = ", password_hash=?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $params[] = $id; // ID para o WHERE

            $sql = "UPDATE users SET name=?, email=?, role=?, phone=?, responsibilities=? $photo_sql_part $pass_sql_part WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $message = "Utilizador atualizado.";
            log_action('user_update', "Utilizador '{$name}' (ID: {$id}) atualizado.");
        }

        // --- EXCLUIR UTILIZADOR ---
        if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
            $id_to_delete = $_POST['id'];
            $company_id = $_SESSION['user_company_id'] ?? 1;

            if ($id_to_delete == $_SESSION['user_id']) {
                throw new Exception("Você não pode excluir a sua própria conta.");
            }

            // Verifica se o utilizador a ser excluído é um administrador
            $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
            $stmt->execute([$id_to_delete]);
            $user_to_delete = $stmt->fetch();

            if ($user_to_delete && $user_to_delete['role'] === 'admin') {
                // Se for admin, verifica se é o último da empresa
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND company_id = ?");
                $stmt_count->execute([$company_id]);
                $admin_count = $stmt_count->fetchColumn();

                if ($admin_count <= 1) {
                    throw new Exception("Não é possível excluir o último administrador. Promova outro utilizador a administrador primeiro.");
                }
            }

            // Se todas as verificações passarem, exclui o utilizador
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id_to_delete]);
            log_action('user_delete', "Utilizador '{$user_to_delete['name']}' removido.");
            $message = "Utilizador removido com sucesso.";
        }

        // --- SALVAR PERMISSÕES DA FUNÇÃO ---
        if (isset($_POST['action']) && $_POST['action'] == 'save_permissions') {
            $role_key = $_POST['role_key'];
            $perms = isset($_POST['permissions']) ? json_encode($_POST['permissions']) : '[]';
            $stmt = $pdo->prepare("UPDATE roles SET permissions = ? WHERE role_key = ?");
            $stmt->execute([$perms, $role_key]);
            log_action('permission_update', "Permissões da função '{$role_key}' foram atualizadas.");
            $message = "Permissões de '{$role_key}' atualizadas com sucesso!";
        }

    } catch (Exception $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. DADOS
// =================================================================================
$company_id = $_SESSION['user_company_id'] ?? 1;
$users = $pdo->prepare("SELECT * FROM users WHERE company_id = ? ORDER BY name ASC");
$users->execute([$company_id]);
$all_users = $users->fetchAll();

function getRoleBadge($role) {
    switch($role) {
        case 'superadmin': return '<span class="bg-purple-100 text-purple-700 px-2 py-1 rounded-full text-xs font-bold uppercase">Super Admin</span>';
        case 'admin': return '<span class="bg-blue-100 text-blue-700 px-2 py-1 rounded-full text-xs font-bold uppercase">Admin</span>';
        case 'gestor': return '<span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-bold uppercase">Gestor</span>';
        case 'tecnico': return '<span class="bg-orange-100 text-orange-700 px-2 py-1 rounded-full text-xs font-bold uppercase">Técnico</span>';
        default: return '<span class="bg-gray-100 text-gray-600 px-2 py-1 rounded-full text-xs font-bold uppercase">Leitor</span>';
    }
}

// Lista de Permissões Disponíveis
$available_permissions = [
    'create_asset' => 'Criar Ativos', 'edit_asset' => 'Editar Ativos', 'delete_asset' => 'Excluir Ativos',
    'move_asset' => 'Movimentar Ativos', 'audit' => 'Realizar Auditorias', 'manage_peripherals' => 'Gerenciar Periféricos',
    'manage_licenses' => 'Gerenciar Licenças', 'manage_suppliers' => 'Gerenciar Fornecedores',
    'manage_users' => 'Gerenciar Usuários', 'view_reports' => 'Visualizar Relatórios'
];
?>

<!-- FEEDBACK -->
<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="<?php echo strpos($message, 'Erro') !== false ? 'text-red-500' : 'text-blue-500'; ?>">
            <i data-lucide="<?php echo strpos($message, 'Erro') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5"></i>
        </div>
        <div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => { const el = document.getElementById('alertMessage'); if(el) el.remove(); }, 4000);</script>
<?php endif; ?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Utilizadores</h1>
        <p class="text-sm text-slate-500">Gestão de acesso e permissões da equipa</p>
    </div>
    <div class="flex gap-2">
        <button onclick="openPermissionsModal()" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="shield" class="w-4 h-4"></i> Configurar Funções
        </button>
        <button onclick="openNewUserModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors w-full md:w-auto justify-center">
            <i data-lucide="user-plus" class="w-4 h-4"></i> Novo Utilizador
        </button>
    </div>
</div>

<!-- FILTROS -->
<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 flex flex-col md:flex-row gap-4">
    <div class="relative flex-1">
        <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
        <input type="text" id="searchUser" onkeyup="filterUsers()" placeholder="Buscar por nome ou email..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <select id="filterRole" onchange="filterUsers()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 bg-slate-50 md:w-48">
        <option value="">Todas Funções</option>
        <option value="Admin">Admin</option>
        <option value="Gestor">Gestor</option>
        <option value="Técnico">Técnico</option>
        <option value="Leitor">Leitor</option>
    </select>
</div>

<!-- TABELA -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4 w-16">Foto</th>
                    <th class="p-4">Nome</th>
                    <th class="p-4 hidden sm:table-cell">Email</th>
                    <th class="p-4">Função</th>
                    <th class="p-4 hidden md:table-cell">Telefone</th>
                    <th class="p-4 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach($all_users as $u): ?>
                <tr class="hover:bg-slate-50 transition-colors user-row" data-search="<?php echo strtolower($u['name'] . ' ' . $u['email']); ?>" data-role="<?php echo $u['role']; ?>">
                    <td class="p-4">
                        <?php if(!empty($u['photo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($u['photo_url']); ?>" class="w-10 h-10 rounded-full object-cover border border-slate-200">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs border border-blue-200">
                                <?php echo strtoupper(substr($u['name'], 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="p-4 font-medium text-slate-800">
                        <?php echo htmlspecialchars($u['name']); ?>
                        <div class="text-xs text-slate-400 sm:hidden"><?php echo htmlspecialchars($u['email']); ?></div>
                    </td>
                    <td class="p-4 text-slate-600 hidden sm:table-cell"><?php echo htmlspecialchars($u['email']); ?></td>
                    <td class="p-4"><?php echo getRoleBadge($u['role']); ?></td>
                    <td class="p-4 hidden md:table-cell text-slate-600"><?php echo htmlspecialchars($u['phone'] ?: '-'); ?></td>
                    <td class="p-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick='openEditModal(<?php echo json_encode($u); ?>)' class="p-2 text-slate-400 hover:text-blue-600 rounded-lg transition-colors" title="Editar">
                                <i data-lucide="edit-2" class="w-4 h-4"></i>
                            </button>
                            <?php if($u['id'] != $_SESSION['user_id']): ?>
                                <button onclick="confirmDelete(<?php echo $u['id']; ?>)" class="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Excluir Conta">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL USUÁRIO (ESTRUTURA FIXED + SCROLL) -->
<div id="modalUser" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 sm:p-6">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalUser')"></div>
    
    <!-- Adicionado max-h-[95dvh] e flex-col para garantir que o modal não vaze da tela -->
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[95dvh] overflow-hidden">
        
        <form method="POST" enctype="multipart/form-data" onsubmit="return validatePasswords()" class="flex flex-col h-full min-h-0">
            <input type="hidden" name="action" id="userAction" value="create_user">
            <input type="hidden" name="id" id="userId">
            
            <!-- Header Fixo -->
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white shrink-0">
                <h3 class="text-lg font-bold text-slate-900" id="modalUserTitle">Novo Utilizador</h3>
                <button type="button" onclick="closeModal('modalUser')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <!-- Conteúdo com Scroll (Área Flexível) -->
            <div class="p-4 md:p-6 space-y-5 overflow-y-auto flex-1">
                
                <!-- Foto Centralizada -->
                <div class="flex flex-col items-center mb-2">
                    <div class="relative group">
                        <div class="w-20 h-20 md:w-24 md:h-24 rounded-full bg-slate-100 border-4 border-white shadow-sm flex items-center justify-center overflow-hidden" id="photoPreviewContainer">
                            <i data-lucide="user" class="w-10 h-10 text-slate-300"></i>
                        </div>
                        <label for="userPhoto" class="absolute bottom-0 right-0 bg-blue-600 text-white p-2 rounded-full shadow-md cursor-pointer hover:bg-blue-700 transition-colors border-2 border-white">
                            <i data-lucide="camera" class="w-4 h-4"></i>
                        </label>
                        <input type="file" name="photo" id="userPhoto" accept="image/*" class="hidden" onchange="previewImage(this)">
                    </div>
                </div>

                <!-- Dados (Grid Responsiva) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-1 md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Nome Completo *</label>
                        <input type="text" name="name" id="userName" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Email *</label>
                        <input type="email" name="email" id="userEmail" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Telefone</label>
                        <input type="text" name="phone" id="userPhone" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div class="col-span-1 md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 mb-1">Função *</label>
                        <select name="role" id="userRole" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="leitor">Leitor (Apenas Visualiza)</option>
                            <option value="tecnico">Técnico (Edita Status)</option>
                            <option value="gestor">Gestor (Acesso Completo)</option>
                            <option value="admin">Admin (Configurações)</option>
                        </select>
                    </div>
                </div>

                <!-- Responsabilidades / Checklist -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Checklist / Responsabilidades</label>
                    <textarea name="responsibilities" id="userResponsibilities" rows="4" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Liste aqui as tarefas recorrentes, responsabilidades ou itens de verificação deste utilizador..."></textarea>
                </div>

                <!-- Segurança -->
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <p class="text-sm font-bold text-slate-800 mb-3 flex items-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4 text-blue-500"></i> Segurança
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="relative">
                            <label class="block text-xs font-semibold text-slate-500 uppercase mb-1">Senha</label>
                            <input type="password" name="password" id="userPassword" placeholder="******" class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none pr-10">
                            <button type="button" onclick="togglePassword('userPassword', 'eyeIcon1')" class="absolute right-3 top-7 text-slate-400 hover:text-slate-600">
                                <i data-lucide="eye" id="eyeIcon1" class="w-4 h-4"></i>
                            </button>
                        </div>

                        <div class="relative">
                            <label class="block text-xs font-semibold text-slate-500 uppercase mb-1">Confirmar</label>
                            <input type="password" name="confirm_password" id="userConfirmPassword" placeholder="******" class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none pr-10">
                            <button type="button" onclick="togglePassword('userConfirmPassword', 'eyeIcon2')" class="absolute right-3 top-7 text-slate-400 hover:text-slate-600">
                                <i data-lucide="eye" id="eyeIcon2" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-2 hidden" id="passwordHint">Deixe em branco para manter a senha atual.</p>
                    <p class="text-xs text-red-500 mt-2 hidden font-medium" id="passwordError">As senhas não coincidem.</p>
                </div>
            </div>

            <!-- Footer Fixo -->
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modalUser')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors shadow-sm">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PERMISSÕES -->
<div id="modalPermissions" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalPermissions')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl">
            <h3 class="text-lg font-bold text-slate-900">Configurar Funções</h3>
            <button onclick="closeModal('modalPermissions')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-bold text-slate-700 mb-2">Selecione a Função</label>
                <select id="roleSelector" onchange="loadRolePermissions()" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-slate-50">
                    <option value="admin">Admin</option>
                    <option value="gestor">Gestor</option>
                    <option value="tecnico">Técnico</option>
                    <option value="leitor">Leitor</option>
                </select>
            </div>
            <form method="POST" id="permissionsForm">
                <input type="hidden" name="action" value="save_permissions">
                <input type="hidden" name="role_key" id="permRoleKey">
                <div class="grid grid-cols-2 gap-3 mb-6">
                    <?php foreach($available_permissions as $key => $label): ?>
                        <label class="flex items-center gap-2 p-2 border rounded hover:bg-slate-50 cursor-pointer"><input type="checkbox" name="permissions[]" value="<?php echo $key; ?>" class="rounded text-blue-600 focus:ring-blue-500 perm-checkbox"> <span class="text-sm text-slate-700"><?php echo $label; ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-end gap-2"><button type="button" onclick="closeModal('modalPermissions')" class="px-4 py-2 border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold">Salvar Permissões</button></div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EXCLUIR -->
<div id="modalDelete" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalDelete')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 p-6 text-center transition-all">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 mb-4"><i data-lucide="alert-triangle" class="h-6 w-6 text-red-600"></i></div>
        <h3 class="text-lg font-bold text-slate-900 mb-2">Remover Utilizador?</h3>
        <p class="text-sm text-slate-500 mb-6">Esta ação revogará o acesso deste utilizador imediatamente.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="id" id="deleteUserId">
            <div class="flex gap-2 justify-center">
                <button type="button" onclick="closeModal('modalDelete')" class="px-4 py-2 bg-white border rounded-lg text-sm">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">Confirmar Exclusão</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Funções JS
    function safeClassRemove(sel, cls) { const el = document.querySelector(sel); if(el) el.classList.remove(cls); }
    function safeClassAdd(sel, cls) { const el = document.querySelector(sel); if(el) el.classList.add(cls); }

    function openModal(id) {
        const el = document.getElementById(id);
        if(!el) return;
        el.classList.remove('hidden');
        setTimeout(() => {
            safeClassRemove(`#${id} .modal-backdrop`, 'opacity-0');
            safeClassRemove(`#${id} .modal-panel`, 'opacity-0');
            safeClassRemove(`#${id} .modal-panel`, 'scale-95');
            safeClassAdd(`#${id} .modal-panel`, 'scale-100');
            safeClassAdd(`#${id} .modal-panel`, 'opacity-100');
        }, 10);
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if(!el) return;
        safeClassAdd(`#${id} .modal-backdrop`, 'opacity-0');
        safeClassAdd(`#${id} .modal-panel`, 'opacity-0');
        safeClassAdd(`#${id} .modal-panel`, 'scale-95');
        safeClassRemove(`#${id} .modal-panel`, 'scale-100');
        safeClassRemove(`#${id} .modal-panel`, 'opacity-100');
        setTimeout(() => el.classList.add('hidden'), 300);
    }

    function openNewUserModal() {
        document.getElementById('modalUserTitle').innerText = "Novo Utilizador";
        document.getElementById('userAction').value = "create_user";
        document.getElementById('userId').value = "";
        document.getElementById('userName').value = "";
        document.getElementById('userEmail').value = "";
        document.getElementById('userPhone').value = "";
        document.getElementById('userRole').value = "leitor";
        document.getElementById('userResponsibilities').value = "";
        document.getElementById('userPassword').required = true;
        document.getElementById('userConfirmPassword').required = true;
        document.getElementById('passwordHint').classList.add('hidden');
        
        // Reset Preview
        document.getElementById('photoPreviewContainer').innerHTML = '<i data-lucide="user" class="w-10 h-10 text-slate-300"></i>';
        lucide.createIcons();
        
        openModal('modalUser');
    }

    function openEditModal(data) {
        document.getElementById('modalUserTitle').innerText = "Editar Utilizador";
        document.getElementById('userAction').value = "update_user";
        document.getElementById('userId').value = data.id;
        document.getElementById('userName').value = data.name;
        document.getElementById('userEmail').value = data.email;
        document.getElementById('userPhone').value = data.phone;
        document.getElementById('userRole').value = data.role;
        document.getElementById('userResponsibilities').value = data.responsibilities || "";
        
        const passInput = document.getElementById('userPassword');
        const confirmInput = document.getElementById('userConfirmPassword');
        passInput.required = false;
        confirmInput.required = false;
        passInput.value = "";
        confirmInput.value = "";
        document.getElementById('passwordHint').classList.remove('hidden');
        
        // Preview com Foto existente
        const container = document.getElementById('photoPreviewContainer');
        if(data.photo_url) {
            container.innerHTML = `<img src="${data.photo_url}" class="w-full h-full object-cover">`;
        } else {
            container.innerHTML = '<i data-lucide="user" class="w-10 h-10 text-slate-300"></i>';
            lucide.createIcons();
        }
        
        openModal('modalUser');
    }

    function openPermissionsModal() {
        document.getElementById('roleSelector').value = 'tecnico'; // Default
        loadRolePermissions();
        openModal('modalPermissions');
    }

    async function loadRolePermissions() {
        const role = document.getElementById('roleSelector').value;
        document.getElementById('permRoleKey').value = role;
        
        // Reset checkboxes
        document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = false);

        // Fetch permissions (simulated via PHP injection for simplicity in this context, 
        // ideally would be an AJAX call, but we can dump the data in JS)
        const allRoles = <?php 
            $roles_data = [];
            $stmt = $pdo->query("SELECT role_key, permissions FROM roles");
            while($r = $stmt->fetch()) $roles_data[$r['role_key']] = json_decode($r['permissions']);
            echo json_encode($roles_data); 
        ?>;
        
        if (allRoles[role]) {
            allRoles[role].forEach(perm => {
                const cb = document.querySelector(`input[value="${perm}"]`);
                if(cb) cb.checked = true;
            });
        }
    }

    function confirmDelete(id) {
        document.getElementById('deleteUserId').value = id;
        openModal('modalDelete');
    }

    function filterUsers() {
        const term = document.getElementById('searchUser').value.toLowerCase();
        const role = document.getElementById('filterRole').value.toLowerCase();
        
        document.querySelectorAll('.user-row').forEach(row => {
            const text = row.getAttribute('data-search');
            const rowRole = row.getAttribute('data-role').toLowerCase();
            const matchTerm = text.includes(term);
            const matchRole = role === '' || rowRole === role;
            row.style.display = (matchTerm && matchRole) ? '' : 'none';
        });
    }

    // Lógica de Senha
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (input.type === "password") {
            input.type = "text";
            icon.style.color = "#2563eb"; // azul
        } else {
            input.type = "password";
            icon.style.color = ""; // original
        }
    }

    function validatePasswords() {
        const pass = document.getElementById('userPassword').value;
        const confirm = document.getElementById('userConfirmPassword').value;
        const errorMsg = document.getElementById('passwordError');
        
        if (pass !== confirm) {
            errorMsg.classList.remove('hidden');
            return false; // Impede envio
        }
        errorMsg.classList.add('hidden');
        return true;
    }

    // Preview de Imagem
    function previewImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photoPreviewContainer').innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
</script>