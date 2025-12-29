<?php
// pages/audit.php

$company_id = $_SESSION['user_company_id'] ?? 1;
$user_id = $_SESSION['user_id'] ?? 0;
$message = '';

// --- CARREGAR PERMISSÕES ---
$user_role = $_SESSION['user_role'] ?? 'leitor';
$stmt = $pdo->prepare("SELECT permissions FROM roles WHERE role_key = ?");
$stmt->execute([$user_role]);
$role_perms_json = $stmt->fetchColumn();
$current_permissions = $role_perms_json ? json_decode($role_perms_json, true) : [];

function hasPermission($p) { global $current_permissions, $user_role; return $user_role === 'admin' || in_array($p, $current_permissions); }

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!hasPermission('audit')) throw new Exception("Sem permissão para realizar auditorias.");
    
    // --- CRIAR NOVA AUDITORIA ---
    if (isset($_POST['action']) && $_POST['action'] == 'create_audit') {
        $loc_id = $_POST['location_id'];
        
        // Verifica se já existe aberta
        $stmt = $pdo->prepare("SELECT id FROM audits WHERE user_id = ? AND location_id = ? AND status = 'open'");
        $stmt->execute([$user_id, $loc_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $audit_id = $existing['id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO audits (company_id, user_id, location_id, status) VALUES (?, ?, ?, 'open')");
            $stmt->execute([$company_id, $user_id, $loc_id]);
            $audit_id = $pdo->lastInsertId();
            log_action('audit_start', "Auditoria iniciada para o local ID {$loc_id}. Auditoria ID: {$audit_id}.");
        }
        echo "<script>window.location.href = 'index.php?page=audit&id=$audit_id';</script>";
        exit;
    }

    // --- FINALIZAR AUDITORIA ---
    if (isset($_POST['action']) && $_POST['action'] == 'finish_audit') {
        $audit_id = $_POST['audit_id'];
        // Só finaliza se estiver aberta
        $stmt = $pdo->prepare("UPDATE audits SET status = 'completed', completed_at = NOW() WHERE id = ? AND user_id = ? AND status = 'open'");
        $stmt->execute([$audit_id, $user_id]);
        log_action('audit_finish', "Auditoria ID {$audit_id} finalizada.");
        
        // Redireciona para o mesmo ID para ver o resumo (modo leitura)
        echo "<script>window.location.href = 'index.php?page=audit&id=$audit_id';</script>";
        exit;
    }

    // --- EXCLUIR AUDITORIA (Aberta) ---
    if (isset($_POST['action']) && $_POST['action'] == 'delete_audit') {
        $audit_id = $_POST['audit_id'];
        $stmt = $pdo->prepare("DELETE FROM audits WHERE id = ? AND user_id = ? AND status = 'open'");
        $stmt->execute([$audit_id, $user_id]);
        log_action('audit_delete', "Auditoria ID {$audit_id} cancelada.");
        $message = "Auditoria cancelada.";
    }

    // --- ESCANEAR ITEM ---
    if (isset($_POST['action']) && $_POST['action'] == 'scan_item') {
        $audit_id = $_POST['audit_id'];
        $code = trim($_POST['code']);
        $location_target_id = $_POST['location_target_id'];

        // Verifica status da auditoria antes de inserir (segurança)
        $stmtStatus = $pdo->prepare("SELECT status FROM audits WHERE id = ?");
        $stmtStatus->execute([$audit_id]);
        $st = $stmtStatus->fetch();

        if ($st && $st['status'] == 'open') {
            // Verifica duplicidade no scan
            $stmtCheck = $pdo->prepare("SELECT id FROM audit_items WHERE audit_id = ? AND scanned_code = ?");
            $stmtCheck->execute([$audit_id, $code]);
            
            if (!$stmtCheck->fetch()) {
                // Busca ativo
                $stmtAsset = $pdo->prepare("SELECT id, location_id FROM assets WHERE code = ? AND company_id = ?");
                $stmtAsset->execute([$code, $company_id]);
                $asset = $stmtAsset->fetch();

                if ($asset) {
                    $asset_id_db = $asset['id'];
                    $status_audit = ($asset['location_id'] == $location_target_id) ? 'ok' : 'alien';
                    $stmtInsert = $pdo->prepare("INSERT INTO audit_items (audit_id, asset_id, scanned_code, status_audit) VALUES (?, ?, ?, ?)");
                    $stmtInsert->execute([$audit_id, $asset_id_db, $code, $status_audit]);
                    log_action('audit_scan', "Item '{$code}' escaneado na auditoria ID {$audit_id}.");
                } else {
                    // Ativo não encontrado, redireciona para mostrar o modal
                    $redirect_url = "index.php?page=audit&id=$audit_id&unknown_asset=" . urlencode($code);
                    echo "<script>window.location.href = '$redirect_url';</script>";
                    exit;
                }


            }
        }
    }

    // --- AÇÕES DE ITEM (Mover/Remover) ---
    if (isset($_POST['action']) && ($_POST['action'] == 'fix_location' || $_POST['action'] == 'remove_audit_item')) {
        // Verifica se auditoria está aberta
        $audit_item_id = $_POST['audit_item_id'] ?? $_POST['item_id'];
        
        // Join para verificar status da auditoria pai
        $stmtCheck = $pdo->prepare("SELECT a.status FROM audits a JOIN audit_items ai ON ai.audit_id = a.id WHERE ai.id = ?");
        $stmtCheck->execute([$audit_item_id]);
        $auditRow = $stmtCheck->fetch();

        if ($auditRow && $auditRow['status'] == 'open') {
            if ($_POST['action'] == 'fix_location') {
                $asset_id = $_POST['asset_id'];
                $target_loc_id = $_POST['location_target_id'];
                
                $pdo->prepare("UPDATE assets SET location_id = ? WHERE id = ?")->execute([$target_loc_id, $asset_id]);
                $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, to_value, description) VALUES (?, ?, ?, 'local', ?, 'Correção via Auditoria')")->execute([$company_id, $asset_id, $user_id, $target_loc_id]);
                $pdo->prepare("UPDATE audit_items SET status_audit = 'fixed' WHERE id = ?")->execute([$audit_item_id]);
                log_action('audit_fix', "Localização do ativo ID {$asset_id} corrigida para {$target_loc_id} via auditoria ID " . ($_POST['audit_id'] ?? 'N/A') . ".");
                $message = "Ativo regularizado!";
            }
            elseif ($_POST['action'] == 'remove_audit_item') {
                $pdo->prepare("DELETE FROM audit_items WHERE id = ?")->execute([$audit_item_id]);
                // Sem mensagem (ação rápida)
            }
        }
    }
}

// =================================================================================
// 2. DADOS E VIEW
// =================================================================================

$current_audit_id = $_GET['id'] ?? null;
$current_audit = null;
$audit_items = [];

// CARREGAR AUDITORIA ESPECÍFICA (Aberta ou Fechada)
if ($current_audit_id) {
    $stmt = $pdo->prepare("SELECT a.*, l.name as location_name, u.name as auditor_name FROM audits a JOIN locations l ON a.location_id = l.id LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ? AND a.company_id = ?");
    $stmt->execute([$current_audit_id, $company_id]);
    $current_audit = $stmt->fetch();

    if ($current_audit) {
        $stmtItems = $pdo->prepare("
            SELECT ai.*, a.name as asset_name, a.code as asset_code, 
                   l_orig.name as original_location_name
            FROM audit_items ai
            LEFT JOIN assets a ON ai.asset_id = a.id
            LEFT JOIN locations l_orig ON a.location_id = l_orig.id
            WHERE ai.audit_id = ?
            ORDER BY ai.scanned_at DESC
        ");
        $stmtItems->execute([$current_audit_id]);
        $audit_items = $stmtItems->fetchAll();
    }
}

// LOCAIS (Dropdown)
$locations = $pdo->query("SELECT id, name FROM locations WHERE company_id = $company_id ORDER BY name ASC")->fetchAll();

// LISTA: ABERTAS (Apenas do usuário logado)
$open_audits = $pdo->query("
    SELECT a.*, l.name as location_name, 
           (SELECT COUNT(*) FROM audit_items WHERE audit_id = a.id) as total_items,
           (SELECT COUNT(*) FROM audit_items WHERE audit_id = a.id AND status_audit = 'alien') as total_aliens
    FROM audits a 
    JOIN locations l ON a.location_id = l.id 
    WHERE a.user_id = $user_id AND a.status = 'open' 
    ORDER BY a.created_at DESC
")->fetchAll();

// LISTA: HISTÓRICO (Todas da empresa, status completed)
$history_audits = $pdo->query("
    SELECT a.*, l.name as location_name, u.name as user_name,
           (SELECT COUNT(*) FROM audit_items WHERE audit_id = a.id) as total_items,
           (SELECT COUNT(*) FROM audit_items WHERE audit_id = a.id AND status_audit = 'alien') as total_aliens,
           (SELECT COUNT(*) FROM audit_items WHERE audit_id = a.id AND status_audit = 'fixed') as total_fixed
    FROM audits a 
    JOIN locations l ON a.location_id = l.id 
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.company_id = $company_id AND a.status = 'completed' 
    ORDER BY a.completed_at DESC LIMIT 50
")->fetchAll();

?>

<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div><div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage').remove(), 3000);</script>
<?php endif; ?>

<?php if (!$current_audit): ?>
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Auditorias</h1>
            <p class="text-sm text-slate-500">Gestão e histórico de conferências</p>
        </div>
        <?php if(hasPermission('audit')): ?>
        <button onclick="document.getElementById('modalNewAudit').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="plus" class="w-4 h-4"></i> Nova Auditoria
        </button>
        <?php endif; ?>
    </div>

    <?php if(!empty($open_audits)): ?>
        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider mb-3 flex items-center gap-2"><i data-lucide="loader" class="w-4 h-4 text-blue-500 animate-spin"></i> Em Andamento</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
            <?php foreach($open_audits as $audit): ?>
            <div class="bg-white p-5 rounded-xl border border-blue-200 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden group ring-1 ring-blue-100">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg"><?php echo htmlspecialchars($audit['location_name']); ?></h3>
                        <p class="text-xs text-slate-500">Início: <?php echo date('d/m H:i', strtotime($audit['created_at'])); ?></p>
                    </div>
                    <div class="text-right">
                         <div class="text-2xl font-bold text-blue-600"><?php echo $audit['total_items']; ?></div>
                         <div class="text-[10px] text-slate-400 uppercase">Itens</div>
                    </div>
                </div>
                
                <?php if($audit['total_aliens'] > 0): ?>
                <div class="bg-orange-50 text-orange-700 px-2 py-1.5 rounded-md text-xs font-bold flex items-center gap-2 mb-4">
                    <i data-lucide="alert-triangle" class="w-3 h-3"></i> <?php echo $audit['total_aliens']; ?> Divergências
                </div>
                <?php else: ?>
                    <div class="mb-4 h-8"></div> <?php endif; ?>

                <div class="flex items-center gap-2 mt-auto">
                    <a href="index.php?page=audit&id=<?php echo $audit['id']; ?>" class="flex-1 bg-blue-600 hover:bg-blue-700 text-center text-white py-2 rounded-lg text-sm font-medium transition-colors">Continuar</a>
                    <form method="POST" onsubmit="return confirm('Cancelar e excluir esta auditoria?');">
                        <input type="hidden" name="action" value="delete_audit">
                        <input type="hidden" name="audit_id" value="<?php echo $audit['id']; ?>">
                        <button type="submit" class="p-2 border border-slate-200 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider mb-3 flex items-center gap-2"><i data-lucide="archive" class="w-4 h-4 text-slate-500"></i> Histórico Recente</h2>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="p-4">Local</th>
                        <th class="p-4">Realizado por</th>
                        <th class="p-4">Conclusão</th>
                        <th class="p-4 text-center">Itens</th>
                        <th class="p-4 text-center">Divergências</th>
                        <th class="p-4 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if(empty($history_audits)): ?>
                        <tr><td colspan="6" class="p-8 text-center text-slate-400">Nenhum histórico disponível.</td></tr>
                    <?php else: ?>
                        <?php foreach($history_audits as $hist): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="p-4 font-medium text-slate-800"><?php echo htmlspecialchars($hist['location_name']); ?></td>
                            <td class="p-4 text-slate-600"><?php echo htmlspecialchars($hist['user_name'] ?? '-'); ?></td>
                            <td class="p-4 text-slate-500"><?php echo date('d/m/Y H:i', strtotime($hist['completed_at'])); ?></td>
                            <td class="p-4 text-center">
                                <span class="bg-slate-100 text-slate-600 px-2 py-1 rounded text-xs font-bold"><?php echo $hist['total_items']; ?></span>
                            </td>
                            <td class="p-4 text-center">
                                <?php if($hist['total_aliens'] > 0): ?>
                                    <span class="bg-orange-100 text-orange-600 px-2 py-1 rounded text-xs font-bold"><?php echo $hist['total_aliens']; ?></span>
                                    <?php if($hist['total_fixed'] > 0): ?>
                                        <span class="bg-green-100 text-green-600 px-2 py-1 rounded text-xs font-bold ml-1" title="Corrigidos"><?php echo $hist['total_fixed']; ?> <i data-lucide="check" class="w-3 h-3 inline"></i></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-slate-300">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-right">
                                <a href="index.php?page=audit&id=<?php echo $hist['id']; ?>" class="text-blue-600 hover:text-blue-800 font-medium text-xs flex items-center justify-end gap-1">
                                    Ver Detalhes <i data-lucide="arrow-right" class="w-4 h-4"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modalNewAudit" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="document.getElementById('modalNewAudit').classList.add('hidden')"></div>
        <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl p-6 animate-in fade-in zoom-in duration-200">
            <h3 class="text-lg font-bold text-slate-900 mb-4">Iniciar Auditoria</h3>
            <form method="POST">
                <input type="hidden" name="action" value="create_audit">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Qual setor será auditado?</label>
                    <select name="location_id" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm" required>
                        <option value="">Selecione...</option>
                        <?php foreach($locations as $l): ?>
                            <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modalNewAudit').classList.add('hidden')" class="px-4 py-2 border rounded-lg text-sm text-slate-600">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Iniciar</button>
                </div>
            </form>
        </div>
    </div>

<?php else: 
    $is_open = ($current_audit['status'] == 'open');
?>
    
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        <div class="flex items-center gap-3">
            <a href="index.php?page=audit" class="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-colors"><i data-lucide="arrow-left" class="w-5 h-5"></i></a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <?php echo htmlspecialchars($current_audit['location_name']); ?>
                    <?php if($is_open): ?>
                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded border border-blue-200 uppercase tracking-wider">Em Progresso</span>
                    <?php else: ?>
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded border border-green-200 uppercase tracking-wider">Concluída</span>
                    <?php endif; ?>
                </h1>
                <p class="text-sm text-slate-500">
                    ID #<?php echo $current_audit['id']; ?> &bull; 
                    <?php if(!$is_open): ?>
                        Finalizada em <?php echo date('d/m/Y H:i', strtotime($current_audit['completed_at'])); ?> por <?php echo htmlspecialchars($current_audit['auditor_name']??'-'); ?>
                    <?php else: ?>
                        Iniciada em <?php echo date('d/m/Y H:i', strtotime($current_audit['created_at'])); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <?php if($is_open && hasPermission('audit')): ?>
            <button type="button" onclick="openFinishModal(<?php echo $current_audit['id']; ?>)" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
                <i data-lucide="check-square" class="w-4 h-4"></i> Finalizar Auditoria
            </button>
        <?php else: ?>
            <div class="bg-slate-100 text-slate-500 px-3 py-1.5 rounded-lg text-sm font-medium border border-slate-200 flex items-center gap-2">
                <i data-lucide="lock" class="w-4 h-4"></i> Modo Leitura
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        
        <div class="lg:col-span-2 space-y-6">
            <?php if($is_open && hasPermission('audit')): ?>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 sticky top-4 z-10">
                    <form method="POST" id="scanForm" class="relative">
                        <input type="hidden" name="action" value="scan_item">
                        <input type="hidden" name="audit_id" value="<?php echo $current_audit['id']; ?>">
                        <input type="hidden" name="location_target_id" value="<?php echo $current_audit['location_id']; ?>">
                        <div class="relative">
                            <i data-lucide="qr-code" class="absolute left-4 top-3.5 w-6 h-6 text-slate-400"></i>
                            <input type="text" name="code" id="auditInput" placeholder="Bipar código..." class="w-full pl-12 pr-4 py-3.5 border-2 border-slate-300 rounded-xl text-lg font-mono focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 outline-none transition-all" autofocus autocomplete="off">
                        </div>
                    </form>
                    <div class="mt-4 pt-4 border-t border-slate-100 flex justify-center">
                        <!-- Botão de conexão do scanner mobile -->
                        <button id="phone-scanner-toggle" onclick="togglePhoneScannerConnection()" class="text-sm font-medium flex items-center gap-2 px-4 py-2 rounded-lg w-full justify-center transition-all duration-300">
                            <i data-lucide="smartphone" class="w-5 h-5"></i> 
                            <span id="phone-scanner-status-text">Conectar Celular</span>
                        </button>
                        <span id="phone-scanner-indicator" class="hidden text-xs font-bold items-center gap-1.5 text-green-600"><div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>Conectado</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-4">
                <div class="bg-white p-4 rounded-xl border border-slate-200 text-center">
                    <div class="text-3xl font-bold text-slate-800" id="total-items-count"><?php echo count($audit_items); ?></div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Total Itens</div>
                </div>
                <?php $aliens = array_filter($audit_items, fn($i) => $i['status_audit'] == 'alien'); ?>
                <div class="bg-white p-4 rounded-xl border border-slate-200 text-center <?php echo count($aliens) > 0 ? 'border-orange-200 bg-orange-50' : ''; ?>">
                    <div class="text-3xl font-bold <?php echo count($aliens) > 0 ? 'text-orange-600' : 'text-slate-800'; ?>" id="divergences-count"><?php echo count($aliens); ?></div>
                    <div class="text-xs text-slate-500 uppercase font-bold tracking-wider">Divergências</div>
                </div>
            </div>
            
            <?php if(!$is_open): ?>
                <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-xl text-sm text-yellow-800 flex items-start gap-3">
                    <i data-lucide="info" class="w-4 h-4 shrink-0 mt-0.5"></i> 
                    <div>
                        <span class="font-bold">Auditoria Fechada</span><br>
                        Os dados são somente para leitura e não podem ser alterados.
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="lg:col-span-3">
            <div id="audit-items-container" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden min-h-[500px] flex flex-col">
                <div class="p-4 border-b border-slate-100 bg-slate-50 font-bold text-slate-700 flex justify-between">
                    <span>Itens Auditados</span>
                    <span class="text-xs font-normal text-slate-500">Recentes primeiro</span>
                </div>
                <div class="flex-1 overflow-y-auto p-4 space-y-3 max-h-[70vh]">
                    <?php if(empty($audit_items)): ?>
                        <div class="h-full flex flex-col items-center justify-center text-slate-300 opacity-60 py-10">
                            <i data-lucide="scan-line" class="w-16 h-16 mb-4"></i>
                            <p class="text-slate-400">Aguardando o primeiro scan...</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($audit_items as $item): ?>
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg border gap-3 <?php 
                                echo match($item['status_audit']) {
                                    'ok' => 'bg-white border-slate-200',
                                    'fixed' => 'bg-green-50 border-green-200',
                                    'alien' => 'bg-orange-50 border-orange-200',
                                    'unknown' => 'bg-red-50 border-red-200',
                                };
                            ?>">
                                <div class="flex items-center gap-3 flex-1">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center <?php 
                                        echo match($item['status_audit']) {
                                            'ok', 'fixed' => 'bg-green-100 text-green-600',
                                            'alien' => 'bg-orange-100 text-orange-600',
                                            'unknown' => 'bg-red-100 text-red-600',
                                        };
                                    ?>">
                                        <?php if($item['status_audit'] == 'alien'): ?><i data-lucide="alert-triangle" class="w-4 h-4"></i>
                                        <?php elseif($item['status_audit'] == 'unknown'): ?><i data-lucide="help-circle" class="w-4 h-4"></i>
                                        <?php else: ?><i data-lucide="check" class="w-4 h-4"></i><?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($item['asset_name'] ?? 'Desconhecido'); ?></p>
                                        <p class="text-xs font-mono text-slate-500"><?php echo htmlspecialchars($item['scanned_code']); ?></p>
                                        <?php if($item['status_audit'] == 'alien'): ?>
                                            <p class="text-xs text-orange-700 font-bold mt-1">Local original: <?php echo htmlspecialchars($item['original_location_name'] ?? '?'); ?></p>
                                        <?php elseif($item['status_audit'] == 'fixed'): ?>
                                            <p class="text-xs text-green-600 font-bold mt-1">Corrigido</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if($is_open && hasPermission('audit')): ?>
                                    <div class="flex items-center gap-2 self-end sm:self-center">
                                        <?php if($item['status_audit'] == 'alien'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="fix_location">
                                                <input type="hidden" name="audit_item_id" value="<?php echo $item['id']; ?>">
                                                <input type="hidden" name="asset_id" value="<?php echo $item['asset_id']; ?>">
                                                <input type="hidden" name="location_target_id" value="<?php echo $current_audit['location_id']; ?>">
                                                <button type="submit" title="Mover ativo para este local" class="text-xs bg-orange-100 text-orange-700 hover:bg-orange-200 px-3 py-2 rounded-lg font-medium transition-colors flex items-center gap-1.5 border border-orange-200">
                                                    <i data-lucide="corner-down-left" class="w-3 h-3"></i> Regularizar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" title="Remover da lista" onclick="openRemoveModal(<?php echo $item['id']; ?>)" class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if($is_open): ?>
        <!-- Inclui o modal de cadastro de ativo no início do bloco para garantir que as funções JS estejam disponíveis -->
        <?php include_once __DIR__ . '/../components/modal_asset.php'; ?>

        <div id="modalSmartScan" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm transition-opacity" onclick="closeScanner()"></div>
            <div class="relative w-full max-w-sm bg-white rounded-xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200 text-center p-6">
                <h3 class="text-lg font-bold text-slate-900 mb-2">Scanner Mobile</h3>
                <p class="text-sm text-slate-500 mb-4">Escaneie com seu celular.</p>
                <div class="bg-white p-2 border rounded-lg inline-block mb-4 shadow-sm">
                    <img id="sessionQrImage" class="w-48 h-48">
                </div>
                <div id="scanStatus" class="text-xs text-blue-600 animate-pulse mb-4">Aguardando...</div>
                <button onclick="closeScanner()" class="w-full bg-slate-100 p-3 rounded text-sm font-medium">Fechar</button>
            </div>
        </div>

        <div id="modalFinishAudit" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalFinishAudit')"></div>
            <div class="relative w-full max-w-sm bg-white rounded-xl shadow-2xl p-6 text-center transform scale-95 opacity-0 transition-all modal-panel">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 mb-4"><i data-lucide="check-square" class="h-6 w-6 text-green-600"></i></div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">Concluir Auditoria?</h3>
                <p class="text-sm text-slate-500 mb-6">A auditoria será fechada e salva no histórico.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="finish_audit">
                    <input type="hidden" name="audit_id" id="finish_audit_id">
                    <div class="flex gap-3 justify-center"><button type="button" onclick="closeModal('modalFinishAudit')" class="px-4 py-2 border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm">Confirmar</button></div>
                </form>
            </div>
        </div>

        <div id="modalRemoveItem" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalRemoveItem')"></div>
            <div class="relative w-full max-w-sm bg-white rounded-xl shadow-2xl p-6 text-center transform scale-95 opacity-0 transition-all modal-panel">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 mb-4"><i data-lucide="trash-2" class="h-6 w-6 text-red-600"></i></div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">Remover Item?</h3>
                <p class="text-sm text-slate-500 mb-6">Remover da lista de conferência atual?</p>
                <form method="POST">
                    <input type="hidden" name="action" value="remove_audit_item">
                    <input type="hidden" name="item_id" id="remove_item_id">
                    <div class="flex gap-3 justify-center"><button type="button" onclick="closeModal('modalRemoveItem')" class="px-4 py-2 border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm">Remover</button></div>
                </form>
            </div>
        </div>

        <div id="modalUnknownAsset" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalUnknownAsset')"></div>
            <div class="relative w-full max-w-sm bg-white rounded-xl shadow-2xl p-6 text-center transform scale-95 opacity-0 transition-all modal-panel">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-yellow-100 mb-4"><i data-lucide="help-circle" class="h-6 w-6 text-yellow-600"></i></div>
                <h3 class="text-lg font-bold text-slate-900 mb-2">Ativo Não Encontrado</h3>
                <p class="text-sm text-slate-500 mb-6">O código <code id="unknown_asset_code" class="font-mono bg-slate-100 p-1 rounded text-slate-700"></code> não está cadastrado. Deseja cadastrá-lo agora?</p>
                <div class="flex gap-3 justify-center">
                    <button type="button" onclick="closeModal('modalUnknownAsset')" class="px-4 py-2 border rounded-lg text-sm font-medium">Pular</button>
                    <button id="register_asset_link" type="button" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">
                        Cadastrar
                    </button>
                </div>
            </div>
        </div>

        <script>
            function openFinishModal(id) { document.getElementById('finish_audit_id').value = id; openModal('modalFinishAudit'); }
            function openRemoveModal(id) { document.getElementById('remove_item_id').value = id; openModal('modalRemoveItem'); }
            function openModal(id) { const el = document.getElementById(id); el.classList.remove('hidden'); setTimeout(() => { el.querySelector('.modal-backdrop').classList.remove('opacity-0'); el.querySelector('.modal-panel').classList.remove('scale-95', 'opacity-0'); el.querySelector('.modal-panel').classList.add('scale-100', 'opacity-100'); }, 10); }
            function closeModal(id) { const el = document.getElementById(id); el.querySelector('.modal-backdrop').classList.add('opacity-0'); el.querySelector('.modal-panel').classList.add('scale-95', 'opacity-0'); el.querySelector('.modal-panel').classList.remove('scale-100', 'opacity-100'); setTimeout(() => el.classList.add('hidden'), 300); }
            
            // Scanner Logic
            const auditId = <?php echo $current_audit['id']; ?>;
            const sessionId = `audit_session_${auditId}`;
            <?php
                // Reutiliza a mesma lógica robusta de IP
                $my_ip = '127.0.0.1';
                if (function_exists('socket_create')) {
                    try {
                        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                        @socket_connect($sock, "8.8.8.8", 53);
                        socket_getsockname($sock, $name);
                        @socket_close($sock);
                        if ($name && $name != '0.0.0.0') $my_ip = $name;
                    } catch (Exception $e) {}
                } else {
                    $my_ip = gethostbyname(gethostname());
                }
            ?>
            const serverIp = "<?php echo $my_ip; ?>";
            const baseUrl = window.location.origin + window.location.pathname.replace('/index.php', '');
            const storageKey = `phoneScannerActive_${auditId}`;

            function openSmartScan() {
                document.getElementById('modalSmartScan').classList.remove('hidden');
                const formData = new FormData(); formData.append('action', 'create'); formData.append('session_id', sessionId); fetch('api_scan.php', { method: 'POST', body: formData });
                const mobileUrl = `${baseUrl}/mobile_scanner.php?session=${sessionId}`;
                document.getElementById('sessionQrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(mobileUrl)}`;
                
                if(scanPollInterval) clearInterval(scanPollInterval);
                scanPollInterval = setInterval(() => {
                    const checkData = new FormData(); checkData.append('action', 'check'); checkData.append('session_id', sessionId);
                    fetch('api_scan.php', { method: 'POST', body: checkData }).then(res => res.json()).then(data => { if (data.status === 'found') { document.getElementById('auditInput').value = data.code; document.getElementById('scanForm').submit(); closeScanner(); } });
                }, 1500);
            }
            function closeScanner() { document.getElementById('modalSmartScan').classList.add('hidden'); if(scanPollInterval) clearInterval(scanPollInterval); }

            // =================================================================
            // LÓGICA DO SCANNER DE CELULAR PERSISTENTE
            // =================================================================

            const toggleBtn = document.getElementById('phone-scanner-toggle');
            const statusText = document.getElementById('phone-scanner-status-text');
            const indicator = document.getElementById('phone-scanner-indicator');

            function setUIState(isConnected) {
                if (isConnected) {
                    toggleBtn.classList.add('hidden');
                    indicator.classList.remove('hidden');
                    indicator.classList.add('flex');
                } else {
                    toggleBtn.classList.remove('hidden');
                    indicator.classList.add('hidden');
                    indicator.classList.remove('flex');

                    toggleBtn.classList.remove('bg-red-100', 'text-red-700', 'hover:bg-red-200');
                    toggleBtn.classList.add('bg-blue-50', 'text-blue-600', 'hover:bg-blue-100', 'border', 'border-blue-100');
                    statusText.textContent = 'Conectar Celular';
                }
            }

            function startPollingForScans() {
                if (scanPollInterval) clearInterval(scanPollInterval);
                scanPollInterval = setInterval(() => {
                    const checkData = new FormData();
                    checkData.append('action', 'check');
                    checkData.append('session_id', sessionId);
                    fetch('api_scan.php', { method: 'POST', body: checkData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'found' && data.code) {
                                document.getElementById('auditInput').value = data.code;
                                document.getElementById('scanForm').submit();
                                // A página vai recarregar, o initPersistentPhoneScanner vai reiniciar o polling
                            }
                        }).catch(() => { /* falha silenciosa */ });
                }, 1500);
            }

            function connectPhoneScanner() {
                localStorage.setItem(storageKey, 'true');
                document.getElementById('modalSmartScan').classList.remove('hidden');
                
                const mobileUrl = `${baseUrl}/mobile_scanner.php?session=${sessionId}`;
                document.getElementById('sessionQrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(mobileUrl)}`;

                // Polling para verificar se o celular conectou (criou o arquivo de sessão)
                if (scanPollInterval) clearInterval(scanPollInterval);
                scanPollInterval = setInterval(() => {
                    const formData = new FormData();
                    formData.append('action', 'create'); // A ação 'create' no backend agora é idempotente (não cria se já existe)
                    formData.append('session_id', sessionId);
                    fetch('api_scan.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'connected') {
                                clearInterval(scanPollInterval);
                                document.getElementById('modalSmartScan').classList.add('hidden');
                                setUIState(true);
                                startPollingForScans();
                            }
                        });
                }, 2000);
            }

            function disconnectPhoneScanner() {
                localStorage.removeItem(storageKey);
                if (scanPollInterval) clearInterval(scanPollInterval);
                
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('session_id', sessionId);
                fetch('api_scan.php', { method: 'POST', body: formData });
                
                setUIState(false);
            }

            function togglePhoneScannerConnection() {
                const isConnected = localStorage.getItem(storageKey) === 'true';
                isConnected ? disconnectPhoneScanner() : connectPhoneScanner();
            }

            function initPersistentPhoneScanner() {
                if (localStorage.getItem(storageKey) === 'true') {
                    setUIState(true);
                    startPollingForScans();
                } else {
                    setUIState(false);
                }
                window.addEventListener('beforeunload', () => {
                    // Limpa o polling ao sair da página para não deixar processos pendentes
                    if (scanPollInterval) clearInterval(scanPollInterval);
                });
            }

            document.addEventListener('DOMContentLoaded', () => { 
                const inp = document.getElementById('auditInput');
                if(inp) {
                    inp.focus(); 
                    // Refocus no campo de scan após submissão do formulário (melhora a usabilidade)
                    document.getElementById('scanForm').addEventListener('submit', () => {
                        setTimeout(() => inp.focus(), 100);
                    });
                }
                initPersistentPhoneScanner();

                // Verifica se um ativo desconhecido foi escaneado
                const urlParams = new URLSearchParams(window.location.search);
                const unknownAssetCode = urlParams.get('unknown_asset');
                if (unknownAssetCode) {
                    document.getElementById('unknown_asset_code').textContent = unknownAssetCode;
                    const registerBtn = document.getElementById('register_asset_link');
                    registerBtn.onclick = () => {
                        closeModal('modalUnknownAsset');
                        openAssetModal(null, unknownAssetCode);
                    };
                    openModal('modalUnknownAsset');
                    history.replaceState(null, '', `index.php?page=audit&id=${auditId}`);
                }
            });
        </script>
    <?php endif; ?>

<?php endif; ?>


<script>lucide.createIcons();</script>