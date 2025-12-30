<?php
// pages/logs.php

// Apenas Admin
if (strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
    echo "<div class='p-4 text-red-600 bg-red-50 rounded-lg border border-red-200'>Acesso negado. Apenas administradores podem visualizar os logs.</div>";
    return;
}

// pages/logs.php

// Função para converter data em tempo relativo (Corrigida para Fuso Brasil)
function time_ago($datetime) {
    try {
        // Define explicitamente o fuso horário do Brasil
        $tz = new DateTimeZone('America/Sao_Paulo');
        
        // Cria a data do log assumindo que ela veio do banco
        $dt = new DateTime($datetime, $tz);
        
        // Cria a data de "Agora" no mesmo fuso
        $now = new DateTime('now', $tz);
        
        // Calcula a diferença real em segundos
        $diff = $now->getTimestamp() - $dt->getTimestamp();
    } catch (Exception $e) {
        return $datetime; // Fallback em caso de erro
    }

    if ($diff < 60) { return 'agora mesmo'; }
    
    $min = round($diff / 60);
    if ($min < 60) { return 'há ' . $min . ' minuto' . ($min > 1 ? 's' : ''); }
    
    $hour = round($diff / 3600);
    if ($hour < 24) { return 'há ' . $hour . ' hora' . ($hour > 1 ? 's' : ''); }
    
    $day = round($diff / 86400);
    if ($day < 7) { return $day == 1 ? 'ontem' : 'há ' . $day . ' dias'; }
    
    // Retorna formatado no padrão brasileiro
    return $dt->format('d/m/Y H:i');
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
            
            log_action('asset_status_change', "Status do ativo '{$asset['name']}' ({$code}) revertido para '{$target_status}' via Painel de Logs.");
            echo "<div id='toast' class='fixed bottom-4 right-4 z-50 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-lg flex items-center justify-between gap-4'><div class='flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5'></i> Status revertido com sucesso!</div><button onclick='this.parentElement.remove()' class='p-1 text-green-700/50 hover:text-green-700 rounded-full -mr-2 -my-2'><i data-lucide='x' class='w-4 h-4'></i></button></div><script>setTimeout(()=>document.getElementById('toast')?.remove(), 4000);</script>";
        } else {
            throw new Exception("Ativo com código '{$code}' não encontrado.");
        }
    } catch (Exception $e) {
        echo "<div id='toast' class='fixed bottom-4 right-4 z-50 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-lg flex items-center justify-between gap-4'><div class='flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> Erro: " . $e->getMessage() . "</div><button onclick='this.parentElement.remove()' class='p-1 text-red-700/50 hover:text-red-700 rounded-full -mr-2 -my-2'><i data-lucide='x' class='w-4 h-4'></i></button></div><script>setTimeout(()=>document.getElementById('toast')?.remove(), 4000);</script>";
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
$date_start = $_GET['date_start'] ?? '';
$date_end = $_GET['date_end'] ?? '';

$where = ["1=1"];
$params = [];

if ($action_filter) {
    $where[] = "action = ?";
    $params[] = $action_filter;
}
if ($user_filter) {
    $where[] = "(u.name LIKE ? OR u.role LIKE ?)";
    $params[] = "%$user_filter%";
    $params[] = "%$user_filter%";
}
if ($date_start) {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $date_start;
}
if ($date_end) {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $date_end;
}

// Paginação
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
if (!in_array($limit, [10, 20, 50, 100])) $limit = 20;
$offset = ($page - 1) * $limit;

// Contagem Total (para paginação)
$count_sql = "SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE " . implode(" AND ", $where);
$stmtCount = $pdo->prepare($count_sql);
$stmtCount->execute($params);
$total_records = $stmtCount->fetchColumn();
$total_pages = ceil($total_records / $limit);

$sql = "SELECT l.*, u.name as user_name, u.role as user_role 
        FROM system_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE " . implode(" AND ", $where) . " 
        ORDER BY l.created_at DESC 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<!-- Header & Actions -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Logs do Sistema</h1>
        <p class="text-sm text-slate-500 mt-1">Histórico de atividades, auditoria e segurança.</p>
    </div>
    <button onclick="location.reload()" class="group p-2.5 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-all shadow-sm hover:shadow-md" title="Atualizar lista">
        <i data-lucide="refresh-cw" class="w-4 h-4 group-hover:animate-spin"></i>
    </button>
</div>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col">
    <!-- Filters Toolbar -->
    <div class="p-5 border-b border-slate-100 bg-slate-50/50">
        <form class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-4 items-end">
            <input type="hidden" name="page" value="logs">
            
            <!-- Filtro de Ação -->
            <div class="lg:col-span-3">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Tipo de Ação</label>
                <div class="relative">
                    <i data-lucide="filter" class="absolute left-3 top-3 w-4 h-4 text-slate-400"></i>
                    <select name="action" onchange="this.form.submit()" class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none appearance-none cursor-pointer hover:border-slate-300 transition-colors shadow-sm">
                        <option value="">Todas as Ações</option>
                        <optgroup label="Segurança">
                            <option value="login_success" <?php echo $action_filter == 'login_success' ? 'selected' : ''; ?>>Login com Sucesso</option>
                            <option value="login_failed" <?php echo $action_filter == 'login_failed' ? 'selected' : ''; ?>>Login Falhou</option>
                            <option value="backup" <?php echo $action_filter == 'backup' ? 'selected' : ''; ?>>Backup do Sistema</option>
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
                            <option value="user_create" <?php echo $action_filter == 'user_create' ? 'selected' : ''; ?>>Criação de Usuário</option>
                            <option value="user_update" <?php echo $action_filter == 'user_update' ? 'selected' : ''; ?>>Edição de Usuário</option>
                            <option value="user_delete" <?php echo $action_filter == 'user_delete' ? 'selected' : ''; ?>>Exclusão de Usuário</option>
                            <option value="profile_update" <?php echo $action_filter == 'profile_update' ? 'selected' : ''; ?>>Edição de Perfil</option>
                        </optgroup>
                        <optgroup label="Auditoria">
                            <option value="audit_start" <?php echo $action_filter == 'audit_start' ? 'selected' : ''; ?>>Início de Auditoria</option>
                            <option value="audit_finish" <?php echo $action_filter == 'audit_finish' ? 'selected' : ''; ?>>Fim de Auditoria</option>
                            <option value="audit_scan" <?php echo $action_filter == 'audit_scan' ? 'selected' : ''; ?>>Scan Realizado</option>
                        </optgroup>
                    </select>
                </div>
            </div>
            
            <!-- Filtro de Usuário -->
            <div class="lg:col-span-3">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Usuário</label>
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-3 w-4 h-4 text-slate-400"></i>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($user_filter); ?>" placeholder="Buscar usuário..." class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none hover:border-slate-300 transition-colors shadow-sm">
                </div>
            </div>
            
            <!-- Filtro de Data -->
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Data Inicial</label>
                <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="w-full px-3 py-2.5 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none hover:border-slate-300 transition-colors shadow-sm text-slate-600">
            </div>
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5 uppercase tracking-wider">Data Final</label>
                <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="w-full px-3 py-2.5 border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none hover:border-slate-300 transition-colors shadow-sm text-slate-600">
            </div>
            
            <!-- Botões e Limite -->
            <div class="lg:col-span-2 flex gap-2">
                <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg text-sm font-bold transition-all shadow-sm hover:shadow active:scale-95 flex items-center justify-center gap-2" title="Aplicar Filtros">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </button>
                
                <?php if($action_filter || $user_filter || $date_start || $date_end): ?>
                <a href="?page=logs" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 px-4 py-2.5 rounded-lg text-sm font-medium transition-all shadow-sm flex items-center justify-center" title="Limpar Filtros">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </a>
                <?php endif; ?>

                <div class="relative w-20">
                    <select name="limit" onchange="this.form.submit()" class="w-full pl-2 pr-6 py-2.5 border border-slate-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none appearance-none cursor-pointer hover:border-slate-300 transition-colors shadow-sm text-center">
                        <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $limit == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                    <i data-lucide="chevron-down" class="absolute right-2 top-3 w-3 h-3 text-slate-400 pointer-events-none"></i>
                </div>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase tracking-wider text-xs sticky top-0 z-10">
                <tr>
                    <th class="px-6 py-4 w-20">ID</th>
                    <th class="px-6 py-4 w-48">Data/Hora</th>
                    <th class="px-6 py-4 w-40">Ação</th>
                    <th class="px-6 py-4 w-48">Usuário</th>
                    <th class="px-6 py-4">Descrição</th>
                    <th class="px-6 py-4 w-32 text-right">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center">
                        <div class="flex flex-col items-center justify-center text-slate-400">
                            <div class="bg-slate-50 p-4 rounded-full mb-3">
                                <i data-lucide="search-x" class="w-8 h-8 text-slate-300"></i>
                            </div>
                            <p class="text-base font-medium text-slate-600">Nenhum registro encontrado</p>
                            <p class="text-sm mt-1">Tente ajustar os filtros de busca.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($logs as $log): 
                    $action_type = explode('_', $log['action'])[0];
                    $badges = [
                        'login' => 'bg-red-50 text-red-700 border-red-100 ring-red-600/10',
                        'backup' => 'bg-blue-50 text-blue-700 border-blue-100 ring-blue-600/10',
                        'permission' => 'bg-amber-50 text-amber-700 border-amber-100 ring-amber-600/10',
                        'asset' => 'bg-blue-50 text-blue-700 border-blue-100 ring-blue-600/10',
                        'user' => 'bg-cyan-50 text-cyan-700 border-cyan-100 ring-cyan-600/10',
                        'profile' => 'bg-cyan-50 text-cyan-700 border-cyan-100 ring-cyan-600/10',
                        'ticket' => 'bg-teal-50 text-teal-700 border-teal-100 ring-teal-600/10',
                        'audit' => 'bg-purple-50 text-purple-700 border-purple-100 ring-purple-600/10',
                        'config' => 'bg-slate-50 text-slate-600 border-slate-100 ring-slate-600/10',
                        'company' => 'bg-slate-50 text-slate-600 border-slate-100 ring-slate-600/10',
                        'license' => 'bg-lime-50 text-lime-700 border-lime-100 ring-lime-600/10',
                        'supplier' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-100 ring-fuchsia-600/10',
                    ];
                    $cls = $badges[$action_type] ?? 'bg-slate-50 text-slate-600 border-slate-100 ring-slate-600/10';
                    if ($log['action'] === 'login_success') {
                        $cls = 'bg-emerald-50 text-emerald-700 border-emerald-100 ring-emerald-600/10';
                    }
                ?>
                <tr class="hover:bg-slate-50/80 transition-colors group">
                    <td class="px-6 py-4 text-slate-400 font-mono text-xs">#<?php echo $log['id']; ?></td>
                    <td class="px-6 py-4 text-slate-600 whitespace-nowrap text-sm" title="<?php echo htmlspecialchars(time_ago($log['created_at'])); ?>">
                        <?php
                            try {
                                $dt = new DateTime($log['created_at']);
                                echo $dt->format('d/m/Y H:i:s');
                            } catch (Exception $e) {
                                echo date('d/m/Y H:i:s', strtotime($log['created_at'])); // Fallback
                            }
                        ?>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase border ring-1 ring-inset <?php echo $cls; ?>">
                            <?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 text-xs font-bold">
                                <?php echo strtoupper(substr($log['user_name'] ?? 'S', 0, 1)); ?>
                            </div>
                            <div>
                                <div class="font-medium text-slate-700 text-sm">
                                    <?php 
                                    $display_name = $log['user_name'] ?? 'Sistema/Visitante';
                                    if (empty($log['user_name']) && !empty($log['user_id'])) {
                                        $display_name = "Usuário #{$log['user_id']} (Removido)";
                                    }
                                    echo htmlspecialchars($display_name); 
                                    ?>
                                </div>
                        <?php if(!empty($log['user_role'])): ?>
                                    <div class="text-[10px] text-slate-400 uppercase tracking-wide"><?php echo htmlspecialchars($log['user_role']); ?></div>
                        <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-slate-600 leading-relaxed">
                        <?php echo htmlspecialchars($log['description']); ?>
                        <?php 
                        // Botão de Reverter (apenas para mudanças de status)
                        if ($log['action'] === 'asset_status_change' && preg_match("/\(([^)]+)\) alterado de '([^']+)' para/", $log['description'], $matches)): 
                            $r_code = $matches[1];
                            $r_old_status = $matches[2];
                        ?>
                            <form method="POST" class="mt-2" onsubmit="return confirm('Reverter status do ativo <?php echo $r_code; ?> para <?php echo $r_old_status; ?>?');">
                                <input type="hidden" name="action" value="revert_status">
                                <input type="hidden" name="asset_code" value="<?php echo htmlspecialchars($r_code); ?>">
                                <input type="hidden" name="target_status" value="<?php echo htmlspecialchars($r_old_status); ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-orange-200 bg-orange-50 text-orange-700 text-xs font-medium hover:bg-orange-100 hover:border-orange-300 transition-all shadow-sm" title="Reverter para <?php echo htmlspecialchars($r_old_status); ?>">
                                    <i data-lucide="rotate-ccw" class="w-3 h-3"></i> 
                                    Reverter Status
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 font-mono text-xs text-slate-400 text-right"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Paginação UI -->
    <?php if ($total_pages > 1): ?>
    <div class="p-4 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
        <div class="text-sm text-slate-500">
            Mostrando <span class="font-medium"><?php echo $offset + 1; ?></span> a <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> de <span class="font-medium"><?php echo $total_records; ?></span> resultados
        </div>
        <div class="flex gap-2">
            <?php 
            $query_params = $_GET;
            // Função auxiliar para gerar URL mantendo filtros
            function get_page_url($p, $params) {
                $params['p'] = $p;
                return '?' . http_build_query($params);
            }
            ?>
            
            <!-- Anterior -->
            <a href="<?php echo get_page_url(max(1, $page - 1), $query_params); ?>" class="px-2 py-1 border border-slate-300 rounded bg-white text-slate-600 hover:bg-slate-50 text-sm font-medium transition-colors <?php echo $page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>" title="Anterior">
                <i data-lucide="chevron-left" class="w-4 h-4"></i>
            </a>

            <?php
            $range = 2;
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                    $activeClass = $i == $page ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50';
                    echo '<a href="' . get_page_url($i, $query_params) . '" class="px-3 py-1 border rounded text-sm font-medium transition-colors ' . $activeClass . '">' . $i . '</a>';
                } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                    echo '<span class="px-2 py-1 text-slate-400">...</span>';
                }
            }
            ?>

            <!-- Próximo -->
            <a href="<?php echo get_page_url(min($total_pages, $page + 1), $query_params); ?>" class="px-2 py-1 border border-slate-300 rounded bg-white text-slate-600 hover:bg-slate-50 text-sm font-medium transition-colors <?php echo $page >= $total_pages ? 'opacity-50 pointer-events-none' : ''; ?>" title="Próximo">
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>lucide.createIcons();</script>