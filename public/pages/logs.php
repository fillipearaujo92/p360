<?php
// pages/logs.php

// Apenas Admin
if ($_SESSION['user_role'] !== 'admin') {
    echo "<div class='p-4 text-red-600 bg-red-50 rounded-lg border border-red-200'>Acesso negado. Apenas administradores podem visualizar os logs.</div>";
    return;
}

// --- LÓGICA DE REVERSÃO DE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_status') {
    try {
        $code = $_POST['asset_code'];
        $target_status = $_POST['target_status'];
        
        // Busca o ativo pelo código
        $stmt = $pdo->prepare("SELECT id, name FROM assets WHERE code = ?");
        $stmt->execute([$code]);
        $asset = $stmt->fetch();
        
        if ($asset) {
            $stmtUpd = $pdo->prepare("UPDATE assets SET status = ? WHERE id = ?");
            $stmtUpd->execute([$target_status, $asset['id']]);
            
            log_action('asset_update', "Status do ativo '{$asset['name']}' ({$code}) revertido para '{$target_status}' via Painel de Logs.");
            echo "<div id='toast' class='fixed top-4 right-4 z-50 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-lg flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5'></i> Status revertido com sucesso!</div><script>setTimeout(()=>document.getElementById('toast').remove(), 4000);</script>";
        } else {
            throw new Exception("Ativo com código '{$code}' não encontrado.");
        }
    } catch (Exception $e) {
        echo "<div id='toast' class='fixed top-4 right-4 z-50 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-lg flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> Erro: " . $e->getMessage() . "</div><script>setTimeout(()=>document.getElementById('toast').remove(), 4000);</script>";
    }
}

// Auto-setup: Tabela de Logs
try {
    $pdo->query("SELECT 1 FROM system_logs LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(50),
        description TEXT,
        ip_address VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

// Filtros
$action_filter = $_GET['action'] ?? '';
$user_filter = $_GET['user'] ?? '';

$where = ["1=1"];
$params = [];

if ($action_filter) {
    $where[] = "action = ?";
    $params[] = $action_filter;
}
if ($user_filter) {
    $where[] = "u.name LIKE ?";
    $params[] = "%$user_filter%";
}

$sql = "SELECT l.*, u.name as user_name, u.role as user_role 
        FROM system_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE " . implode(" AND ", $where) . " 
        ORDER BY l.created_at DESC 
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Logs do Sistema</h1>
        <p class="text-sm text-slate-500">Histórico de atividades, erros e segurança</p>
    </div>
    <button onclick="location.reload()" class="p-2 bg-white border rounded-lg text-slate-500 hover:text-blue-600 transition-colors shadow-sm">
        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
    </button>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="p-4 border-b border-slate-200 bg-slate-50">
        <form class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="page" value="logs">
            <select name="action" onchange="this.form.submit()" class="border border-slate-300 rounded-lg text-sm p-2.5 bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                <option value="">Todas Ações</option>
                <optgroup label="Segurança">
                    <option value="login_success" <?php echo $action_filter == 'login_success' ? 'selected' : ''; ?>>Login com Sucesso</option>
                    <option value="login_failed" <?php echo $action_filter == 'login_failed' ? 'selected' : ''; ?>>Login Falhou</option>
                    <option value="backup" <?php echo $action_filter == 'backup' ? 'selected' : ''; ?>>Backup</option>
                    <option value="permission_update" <?php echo $action_filter == 'permission_update' ? 'selected' : ''; ?>>Permissões</option>
                </optgroup>
                <optgroup label="Ativos">
                    <option value="asset_create" <?php echo $action_filter == 'asset_create' ? 'selected' : ''; ?>>Criação de Ativo</option>
                    <option value="asset_update" <?php echo $action_filter == 'asset_update' ? 'selected' : ''; ?>>Edição de Ativo</option>
                    <option value="asset_delete" <?php echo $action_filter == 'asset_delete' ? 'selected' : ''; ?>>Exclusão de Ativo</option>
                    <option value="asset_move" <?php echo $action_filter == 'asset_move' ? 'selected' : ''; ?>>Movimentação</option>
                    <option value="asset_status_change" <?php echo $action_filter == 'asset_status_change' ? 'selected' : ''; ?>>Mudança de Status</option>
                    <option value="asset_transfer" <?php echo $action_filter == 'asset_transfer' ? 'selected' : ''; ?>>Transferência</option>
                </optgroup>
                <optgroup label="Usuários">
                    <option value="user_create" <?php echo $action_filter == 'user_create' ? 'selected' : ''; ?>>Criação</option>
                    <option value="user_update" <?php echo $action_filter == 'user_update' ? 'selected' : ''; ?>>Edição</option>
                    <option value="user_delete" <?php echo $action_filter == 'user_delete' ? 'selected' : ''; ?>>Exclusão</option>
                    <option value="profile_update" <?php echo $action_filter == 'profile_update' ? 'selected' : ''; ?>>Edição de Perfil</option>
                </optgroup>
                <optgroup label="Auditoria">
                    <option value="audit_start" <?php echo $action_filter == 'audit_start' ? 'selected' : ''; ?>>Início</option>
                    <option value="audit_finish" <?php echo $action_filter == 'audit_finish' ? 'selected' : ''; ?>>Fim</option>
                    <option value="audit_scan" <?php echo $action_filter == 'audit_scan' ? 'selected' : ''; ?>>Scan</option>
                </optgroup>
            </select>
            <input type="text" name="user" value="<?php echo htmlspecialchars($user_filter); ?>" placeholder="Buscar usuário..." class="border border-slate-300 rounded-lg text-sm p-2.5 focus:ring-2 focus:ring-blue-500 outline-none flex-1">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2"><i data-lucide="filter" class="w-4 h-4"></i> Filtrar</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4 w-20">ID</th>
                    <th class="p-4 w-40">Data/Hora</th>
                    <th class="p-4 w-32">Ação</th>
                    <th class="p-4 w-48">Usuário</th>
                    <th class="p-4">Descrição</th>
                    <th class="p-4 w-32">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach($logs as $log): 
                    $action_type = explode('_', $log['action'])[0];
                    $badges = [
                        'login' => 'bg-red-100 text-red-700 border-red-200',
                        'backup' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                        'permission' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                        'asset' => 'bg-blue-100 text-blue-700 border-blue-200',
                        'user' => 'bg-cyan-100 text-cyan-700 border-cyan-200',
                        'profile' => 'bg-cyan-100 text-cyan-700 border-cyan-200',
                        'ticket' => 'bg-teal-100 text-teal-700 border-teal-200',
                        'audit' => 'bg-purple-100 text-purple-700 border-purple-200',
                        'config' => 'bg-slate-100 text-slate-600 border-slate-200',
                        'company' => 'bg-slate-100 text-slate-600 border-slate-200',
                        'license' => 'bg-lime-100 text-lime-700 border-lime-200',
                        'supplier' => 'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200',
                    ];
                    $cls = $badges[$action_type] ?? 'bg-gray-100 text-gray-600 border-gray-200';
                    if ($log['action'] === 'login_success') {
                        $cls = 'bg-green-100 text-green-700 border-green-200';
                    }
                ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="p-4 text-slate-400 font-mono text-xs">#<?php echo $log['id']; ?></td>
                    <td class="p-4 text-slate-500 whitespace-nowrap"><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                    <td class="p-4"><span class="px-2 py-1 rounded text-[10px] font-bold uppercase border <?php echo $cls; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                    <td class="p-4">
                        <div class="font-medium text-slate-700"><?php echo htmlspecialchars($log['user_name'] ?? 'Sistema/Visitante'); ?></div>
                        <?php if(!empty($log['user_role'])): ?>
                            <div class="text-xs text-slate-400 capitalize"><?php echo htmlspecialchars($log['user_role']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="p-4 text-slate-600">
                        <?php echo htmlspecialchars($log['description']); ?>
                        <?php 
                        // Botão de Reverter (apenas para mudanças de status)
                        if ($log['action'] === 'asset_status_change' && preg_match("/\(([^)]+)\) alterado de '([^']+)' para/", $log['description'], $matches)): 
                            $r_code = $matches[1];
                            $r_old_status = $matches[2];
                        ?>
                            <form method="POST" class="inline-block ml-2" onsubmit="return confirm('Reverter status do ativo <?php echo $r_code; ?> para <?php echo $r_old_status; ?>?');">
                                <input type="hidden" name="action" value="revert_status">
                                <input type="hidden" name="asset_code" value="<?php echo htmlspecialchars($r_code); ?>">
                                <input type="hidden" name="target_status" value="<?php echo htmlspecialchars($r_old_status); ?>">
                                <button type="submit" class="inline-flex items-center gap-1 px-2 py-0.5 rounded border border-orange-200 bg-orange-50 text-orange-700 text-[10px] font-bold hover:bg-orange-100 transition-colors" title="Reverter para <?php echo htmlspecialchars($r_old_status); ?>"><i data-lucide="rotate-ccw" class="w-3 h-3"></i> Reverter</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="p-4 font-mono text-xs text-slate-400"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>lucide.createIcons();</script>