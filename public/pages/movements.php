<?php
// pages/movements.php

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'create_movement') {
        try {
            $asset_id = $_POST['asset_id'];
            $location_id = $_POST['location_id'];
            $status = $_POST['status'] ?? null;
            $giver_name = $_POST['giver_name'] ?? null;
            $responsible_name = $_POST['responsible_name'] ?? null;
            $description = $_POST['description'] ?? '';
            $movement_type = $_POST['movement_type'] ?? 'transfer';
            $location_manager_name = $_POST['location_manager_name'] ?? null;
            $manager_confirmed = isset($_POST['manager_confirmed_check']) ? 1 : 0;
            
            $user_id = $_SESSION['user_id'];
            $company_id = $_SESSION['user_company_id'] ?? 1;

            // Handle Signature
            $signature_url = null;
            if (!empty($_POST['signature_data'])) {
                $data = $_POST['signature_data'];
                if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                    $data = substr($data, strpos($data, ',') + 1);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $sigName = 'sig_' . uniqid() . '.png';
                        $target_dir = dirname(__DIR__) . '/uploads/signatures/';
                        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                        if(file_put_contents($target_dir . $sigName, $data)) {
                            $signature_url = 'uploads/signatures/' . $sigName;
                        }
                    }
                }
            }

            if ($movement_type === 'return') {
                $description = "[DEVOLUÇÃO] " . $description;
            }

            $stmtAsset = $pdo->prepare("SELECT location_id, status FROM assets WHERE id = ?");
            $stmtAsset->execute([$asset_id]);
            $currentAsset = $stmtAsset->fetch();

            // Validação para não movimentar ativo baixado
            if ($currentAsset && (strtolower($currentAsset['status']) === 'baixado' || strtolower($currentAsset['status']) === 'descartado')) {
                throw new Exception("Não é possível movimentar um ativo com status 'Baixado' ou 'Descartado'.");
            }

            if (!$status && $currentAsset) {
                $status = $currentAsset['status'];
            }

            $from_value = $currentAsset['location_id'] ?? null;

            $stmtUpdate = $pdo->prepare("UPDATE assets SET location_id = ?, status = ?, responsible_name = ? WHERE id = ?");
            $stmtUpdate->execute([$location_id, $status, $responsible_name, $asset_id]);

            $manager_confirmed_at = $manager_confirmed ? date('Y-m-d H:i:s') : null;
            
            $stmtInsert = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description, signature_url, responsible_name, giver_name, location_manager_name, manager_confirmed, manager_confirmed_at, created_at) VALUES (?, ?, ?, 'local', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmtInsert->execute([$company_id, $asset_id, $user_id, $from_value, $location_id, $description, $signature_url, $responsible_name, $giver_name, $location_manager_name, $manager_confirmed, $manager_confirmed_at]);

            $_SESSION['message'] = "Movimentação registrada com sucesso!";
            echo "<script>window.location.href = 'index.php?page=movements';</script>";
            exit;
        } catch (Exception $e) { $_SESSION['message'] = "Erro ao salvar movimentação: " . $e->getMessage(); }
    }
}

// =================================================================================
// 2. BUSCA DE DADOS (FILTRADA)
// =================================================================================

$search = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$type_filter = $_GET['type'] ?? '';
$page = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
$limit_param = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
$items_per_page = ($limit_param && $limit_param > 0) ? $limit_param : 20;

if (!$page || $page < 1) $page = 1;

$where_clauses = ["m.type IN ('local', 'status')"];
$params = [];

if ($search) {
    $where_clauses[] = "(a.name LIKE ? OR a.code LIKE ? OR u.name LIKE ? OR m.description LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($start_date) { $where_clauses[] = "DATE(m.created_at) >= ?"; $params[] = $start_date; }
if ($end_date) { $where_clauses[] = "DATE(m.created_at) <= ?"; $params[] = $end_date; }
if ($type_filter) {
    if ($type_filter === 'return') {
        $where_clauses[] = "m.description LIKE ?";
        $params[] = '%devolução%';
    } else {
        $where_clauses[] = "m.type = ?";
        $params[] = $type_filter;
    }
}

$where_sql = implode(' AND ', $where_clauses);

// Contagem total para paginação
$count_query = "
    SELECT COUNT(*)
    FROM movements m
    JOIN assets a ON m.asset_id = a.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE $where_sql
";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);
$offset = ($page - 1) * $items_per_page;

$query = "
    SELECT m.*, 
           a.name as asset_name, 
           a.code as asset_code, 
           u.name as user_name,
           l_new.name as location_new_name,
           l_old.name as location_old_name
    FROM movements m
    JOIN assets a ON m.asset_id = a.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN locations l_new ON CAST(m.to_value AS INTEGER) = l_new.id
    LEFT JOIN locations l_old ON CAST(m.from_value AS INTEGER) = l_old.id
    WHERE $where_sql 
    ORDER BY m.created_at DESC
    LIMIT $items_per_page OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$assets = $pdo->query("SELECT id, name, code FROM assets WHERE status NOT IN ('baixado', 'descartado') ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, manager_name FROM locations ORDER BY name ASC")->fetchAll();
$locationsJson = json_encode($locations);
$statuses = $pdo->query("SELECT * FROM asset_statuses")->fetchAll();
?>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 3px; }
</style>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Movimentações</h1>
        <p class="text-sm text-slate-500">Fluxo físico, trocas de responsabilidade e auditoria</p>
    </div>
    <div class="flex gap-2">
        <button onclick="openModal('modalNewMovement', 'return')" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="rotate-ccw" class="w-4 h-4"></i> Devolução
        </button>
        <button onclick="openModal('modalNewMovement', 'transfer')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="arrow-right-left" class="w-4 h-4"></i> Nova Movimentação
        </button>
    </div>
</div>

<?php if(isset($_SESSION['message'])): ?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg flex items-center justify-between gap-4 shadow-sm animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-2">
            <i data-lucide="check-circle" class="w-5 h-5"></i> <?php echo $_SESSION['message']; ?>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-green-700/50 hover:text-green-700 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage')?.remove(), 4000);</script>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<!-- FILTROS -->
<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6">
    <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
        <input type="hidden" name="page" value="movements">
        
        <div class="flex-1 w-full">
            <label class="block text-xs font-bold text-slate-500 mb-1">Buscar</label>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Ativo, código, usuário..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <div class="w-full md:w-40">
            <label class="block text-xs font-bold text-slate-500 mb-1">Tipo</label>
            <select name="type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                <option value="">Todos</option>
                <option value="local" <?php echo $type_filter == 'local' ? 'selected' : ''; ?>>Localização</option>
                <option value="status" <?php echo $type_filter == 'status' ? 'selected' : ''; ?>>Status</option>
                <option value="return" <?php echo $type_filter == 'return' ? 'selected' : ''; ?>>Devolução</option>
            </select>
        </div>

        <div class="w-full md:w-36"><label class="block text-xs font-bold text-slate-500 mb-1">Data Inicial</label><input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500"></div>
        <div class="w-full md:w-36"><label class="block text-xs font-bold text-slate-500 mb-1">Data Final</label><input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500"></div>

        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors flex items-center gap-2"><i data-lucide="filter" class="w-4 h-4"></i> Filtrar</button>
        <?php if($search || $start_date || $end_date || $type_filter): ?>
            <a href="index.php?page=movements" class="bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition-colors flex items-center gap-2"><i data-lucide="x" class="w-4 h-4"></i> Limpar</a>
        <?php endif; ?>
    </form>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4 w-16 text-center">Tipo</th>
                    <th class="p-4 w-32">Data/Hora</th>
                    <th class="p-4">Ativo</th>
                    <th class="p-4">De &rarr; Para</th>
                    <th class="p-4">Responsáveis (Fluxo)</th>
                    <th class="p-4 text-center">Gestor do Setor</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if(empty($movements)): ?>
                    <tr><td colspan="6" class="p-8 text-center text-slate-400 italic">Nenhuma movimentação registrada no histórico.</td></tr>
                <?php else: ?>
                    <?php foreach($movements as $mov): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="p-4 text-center">
                            <?php 
                            // Verifica se é devolução procurando a palavra na descrição
                            $is_return = stripos($mov['description'] ?? '', 'devolução') !== false;
                            
                            if ($is_return): ?>
                                <div class="w-8 h-8 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center mx-auto border border-orange-200" title="Devolução">
                                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                </div>
                            <?php elseif ($mov['type'] == 'status'): ?>
                                <div class="w-8 h-8 rounded-lg bg-purple-100 text-purple-600 flex items-center justify-center mx-auto border border-purple-200" title="Mudança de Status">
                                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                </div>
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center mx-auto border border-blue-200" title="Transferência">
                                    <i data-lucide="arrow-right-left" class="w-4 h-4"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 text-slate-600">
                            <div class="font-medium"><?php echo $mov['created_at'] ? date('d/m/Y', strtotime($mov['created_at'])) : '-'; ?></div>
                            <div class="text-xs text-slate-400"><?php echo $mov['created_at'] ? date('H:i', strtotime($mov['created_at'])) : ''; ?></div>
                        </td>
                        <td class="p-4">
                            <div class="font-medium text-slate-800"><?php echo htmlspecialchars($mov['asset_name'] ?? 'Desconhecido'); ?></div>
                            <div class="text-xs text-slate-500 font-mono"><?php echo htmlspecialchars($mov['asset_code'] ?? ''); ?></div>
                        </td>
                        <td class="p-4">
                            <?php if($mov['type'] == 'local'): ?>
                                <div class="flex items-center gap-2 flex-wrap text-xs">
                                    <span class="bg-slate-100 px-2 py-1 rounded text-slate-500 border border-slate-200">
                                        <?php echo htmlspecialchars($mov['location_old_name'] ?? 'Origem'); ?>
                                    </span>
                                    <i data-lucide="arrow-right" class="w-3 h-3 text-slate-300"></i>
                                    <span class="bg-blue-50 px-2 py-1 rounded text-blue-700 border border-blue-100 font-bold">
                                        <?php echo htmlspecialchars($mov['location_new_name'] ?? 'Destino'); ?>
                                    </span>
                                </div>
                                <?php if(!empty($mov['description'])): ?>
                                    <div class="text-[10px] text-slate-400 mt-1 italic max-w-xs truncate">"<?php echo htmlspecialchars($mov['description']); ?>"</div>
                                <?php endif; ?>
                            <?php elseif($mov['type'] == 'status'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded bg-purple-50 text-purple-700 text-xs font-bold border border-purple-100">
                                    <i data-lucide="refresh-cw" class="w-3 h-3"></i> Status: <?php echo htmlspecialchars($mov['to_value'] ?? '-'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4">
                            <div class="flex flex-col gap-1.5 text-xs">
                                <?php if(!empty($mov['giver_name'])): ?>
                                    <div class="flex items-center gap-2 text-orange-700 bg-orange-50 px-2 py-1 rounded w-fit border border-orange-100">
                                        <i data-lucide="log-out" class="w-3 h-3"></i> 
                                        <span>De: <b><?php echo htmlspecialchars($mov['giver_name']); ?></b></span>
                                    </div>
                                <?php endif; ?>

                                <?php if(!empty($mov['responsible_name'])): ?>
                                    <div class="flex items-center justify-between gap-2 text-green-700 bg-green-50 px-2 py-1 rounded w-fit border border-green-100">
                                        <div class="flex items-center gap-1">
                                            <i data-lucide="log-in" class="w-3 h-3"></i> 
                                            <span>Para: <b><?php echo htmlspecialchars($mov['responsible_name']); ?></b></span>
                                        </div>
                                        <a href="<?php echo $is_return ? 'term_return.php' : 'term.php'; ?>?id=<?php echo $mov['id']; ?>" target="_blank" class="ml-2 <?php echo $is_return ? 'text-orange-600 hover:text-orange-900 border-orange-200' : 'text-green-600 hover:text-green-900 border-green-200'; ?> border-l pl-2" title="<?php echo $is_return ? 'Gerar Termo de Devolução' : 'Gerar Termo de Responsabilidade'; ?>">
                                            <i data-lucide="<?php echo $is_return ? 'file-minus' : 'file-signature'; ?>" class="w-3 h-3"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <div class="text-[9px] text-slate-300 mt-0.5 pl-1">
                                    Reg: <?php echo htmlspecialchars($mov['user_name'] ?? 'Sistema'); ?>
                                </div>
                            </div>
                        </td>
                        <td class="p-4 text-center">
                            <?php if(!empty($mov['location_manager_name'])): ?>
                                <div class="flex flex-col items-center gap-1">
                                    <span class="text-xs font-bold text-slate-700 flex items-center gap-1">
                                        <i data-lucide="shield" class="w-3 h-3 text-blue-400"></i>
                                        <?php echo htmlspecialchars($mov['location_manager_name']); ?>
                                    </span>
                                    <?php if($mov['manager_confirmed']): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-[10px] font-bold border border-green-200 flex items-center gap-1" title="Confirmado em <?php echo date('d/m H:i', strtotime($mov['manager_confirmed_at'])); ?>">
                                            <i data-lucide="check-circle" class="w-3 h-3"></i> Confirmado
                                        </span>
                                    <?php elseif($mov['manager_confirmed'] == 2): ?>
                                        <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-[10px] font-bold border border-red-200 flex items-center gap-1" title="Recusado em <?php echo date('d/m H:i', strtotime($mov['manager_confirmed_at'])); ?>">
                                            <i data-lucide="x-circle" class="w-3 h-3"></i> Recusado
                                        </span>
                                    <?php else: ?>
                                        <button type="button" onclick="openConfirmModal('<?php echo $mov['id']; ?>', '<?php echo htmlspecialchars($mov['location_manager_name']); ?>')" class="bg-yellow-100 text-yellow-700 hover:bg-yellow-200 px-2 py-1 rounded text-[10px] font-bold border border-yellow-200 shadow-sm animate-pulse flex items-center gap-1 transition-colors">
                                            <i data-lucide="clock" class="w-3 h-3"></i> Pendente
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-slate-300 text-xs">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PAGINAÇÃO -->
<div class="flex flex-col md:flex-row justify-between items-center mt-6 gap-4">
    <!-- Seletor de Itens por Página -->
    <div class="flex items-center gap-2 text-sm text-slate-600">
        <span>Mostrar</span>
        <select onchange="window.location.href=this.value" class="border border-slate-200 rounded-lg p-1.5 bg-white outline-none focus:border-blue-500 text-sm">
            <?php
            $limits = [10, 20, 50, 100];
            foreach($limits as $l) {
                $selected = $l == $items_per_page ? 'selected' : '';
                $params = $_GET;
                $params['limit'] = $l;
                $params['p'] = 1; // Reseta para a primeira página ao mudar o limite
                $url = '?' . http_build_query($params);
                echo "<option value='{$url}' {$selected}>{$l}</option>";
            }
            ?>
        </select>
        <span>por página</span>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="flex gap-1">
        <?php
        $query_params = $_GET;
        // Link Anterior
        if ($page > 1) {
            $query_params['p'] = $page - 1;
            echo '<a href="?' . http_build_query($query_params) . '" class="px-3 py-1 border border-slate-200 rounded hover:bg-slate-50 text-slate-600 text-sm">Anterior</a>';
        }
        
        // Números das páginas
        for ($i = 1; $i <= $total_pages; $i++) {
             if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)) {
                $query_params['p'] = $i;
                $active_class = ($i == $page) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
                echo '<a href="?' . http_build_query($query_params) . '" class="px-3 py-1 border rounded text-sm ' . $active_class . '">' . $i . '</a>';
             } elseif ($i == $page - 3 || $i == $page + 3) {
                 echo '<span class="px-2 py-1 text-slate-400">...</span>';
             }
        }

        // Link Próximo
        if ($page < $total_pages) {
            $query_params['p'] = $page + 1;
            echo '<a href="?' . http_build_query($query_params) . '" class="px-3 py-1 border border-slate-200 rounded hover:bg-slate-50 text-slate-600 text-sm">Próximo</a>';
        }
        ?>
    </nav>
    <?php endif; ?>
</div>

<!-- Modal Confirmação/Recusa do Gestor -->
<div id="modalConfirmReceipt" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalConfirmReceipt')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6 text-center">
        <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="shield-question" class="w-6 h-6"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-900 mb-2">Confirmação do Gestor</h3>
        <p class="text-sm text-slate-500 mb-6">
            <span id="managerNameToConfirm" class="font-bold"></span>, você confirma o recebimento do ativo neste setor?
        </p>
        <div class="flex gap-3 justify-center">
            <form method="POST">
                <input type="hidden" name="action" value="reject_receipt">
                <input type="hidden" name="mov_id" id="rejectMovId">
                <button type="submit" class="px-4 py-2 bg-red-100 text-red-700 rounded-lg text-sm font-medium hover:bg-red-200 border border-red-200">Recusar</button>
            </form>
            <form method="POST">
                <input type="hidden" name="action" value="confirm_receipt">
                <input type="hidden" name="mov_id" id="confirmMovId">
                <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 shadow-sm">Sim, Confirmar</button>
            </form>
        </div>
    </div>
</div>

<div id="modalNewMovement" class="fixed inset-0 z-50 hidden items-center justify-center p-4 sm:p-6 flex">
    <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-md transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalNewMovement')"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl flex flex-col max-h-[85vh] overflow-hidden modal-panel transition-all transform scale-95 opacity-0">
        
        <form method="POST" id="formNewMovement" class="flex flex-col h-full overflow-hidden">
            <input type="hidden" name="action" value="create_movement">
            <input type="hidden" name="movement_type" id="movementTypeInput">
            
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white shrink-0 z-20">
                <div class="flex items-center gap-4">
                    <div id="modalIconContainer" class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center border border-blue-100 shadow-sm shadow-blue-100 shrink-0">
                        <i data-lucide="arrow-right-left" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 id="modalTitle" class="text-lg font-bold text-slate-900 leading-tight truncate">Nova Movimentação</h3>
                        <p id="modalSubtitle" class="text-xs text-slate-500 font-medium truncate">Transferência ou mudança de status</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modalNewMovement')" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-full transition-all shrink-0">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto min-h-0 bg-slate-50/50 p-6 sm:p-8 space-y-6 custom-scrollbar overscroll-contain">
                
                <section class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm relative group focus-within:border-blue-300 transition-colors">
                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-widest mb-3 ml-1">O que será movido?</label>
                    <div class="relative">
                        <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within:text-blue-600 transition-colors">
                            <i data-lucide="search" class="w-5 h-5"></i>
                        </div>
                        <input type="text" id="assetSearchInput" placeholder="Busca por nome ou código..." 
                               class="w-full pl-12 pr-4 py-3.5 border border-slate-300 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all shadow-sm h-12"
                               autocomplete="off" onkeyup="filterAssets()" onfocus="showAssetList()">
                        <input type="hidden" name="asset_id" id="selectedAssetId" required>
                        
                        <div id="assetDropdownList" class="hidden absolute z-50 w-full bg-white border border-slate-200 rounded-xl shadow-xl mt-2 max-h-60 overflow-y-auto custom-scrollbar left-0">
                            <?php foreach($assets as $a): ?>
                                <div class="asset-option p-3 hover:bg-blue-50 cursor-pointer border-b border-slate-50 last:border-0 transition-colors flex items-center justify-between group/item" 
                                     onclick="selectAsset('<?php echo $a['id']; ?>', '<?php echo htmlspecialchars($a['name'] . ' (' . $a['code'] . ')', ENT_QUOTES); ?>')">
                                    <div>
                                        <p class="text-sm font-bold text-slate-700 group-hover/item:text-blue-700"><?php echo htmlspecialchars($a['name']); ?></p>
                                        <p class="text-xs text-slate-400 font-mono"><?php echo htmlspecialchars($a['code']); ?></p>
                                    </div>
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-300 opacity-0 group-hover/item:opacity-100 transition-opacity"></i>
                                    <span class="hidden"><?php echo strtolower($a['name'] . ' ' . $a['code']); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div id="noAssetFound" class="hidden p-4 text-sm text-slate-400 text-center italic">Nenhum ativo encontrado.</div>
                        </div>
                    </div>
                </section>

                <section>
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4 ml-1">Destino</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="relative group">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Novo Destino *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within:text-blue-600 transition-colors"><i data-lucide="map-pin" class="w-5 h-5"></i></div>
                                <select name="location_id" id="movLocationSelect" onchange="updateManagerInfoMov()" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all appearance-none font-medium text-slate-700 shadow-sm h-12" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach($locations as $l) echo "<option value='{$l['id']}'>{$l['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                        <div class="relative group">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Status</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within:text-blue-600 transition-colors"><i data-lucide="activity" class="w-5 h-5"></i></div>
                                <select name="status" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all appearance-none font-medium text-slate-700 shadow-sm h-12">
                                    
                                    <?php foreach($statuses as $st) echo "<option value='{$st['name']}'>{$st['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <div id="managerInfoCardMov" class="hidden mt-4 bg-blue-50 border border-blue-200 p-4 rounded-xl flex items-center justify-between animate-in fade-in slide-in-from-top-2">
                        <div class="flex items-center gap-3">
                            <div class="bg-white p-2 rounded-full text-blue-600 shadow-sm"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
                            <div>
                                <p class="text-xs text-blue-500 font-bold uppercase">Gestor do Setor</p>
                                <p class="text-sm font-bold text-blue-900" id="managerNameDisplayMov">-</p>
                                <input type="hidden" name="location_manager_name" id="managerNameInputMov">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer bg-white px-3 py-2 rounded-lg border border-blue-100 hover:border-blue-300 transition-colors shadow-sm">
                            <input type="checkbox" name="manager_confirmed_check" class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                            <span class="text-xs font-bold text-slate-600">Confirmar Agora</span>
                        </label>
                    </div>
                </section>

                <hr class="border-slate-200">

                <section>
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4 ml-1">Responsabilidade Direta</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 p-5 bg-white rounded-2xl border border-slate-200 shadow-sm">
                        <div class="relative group">
                            <label id="labelGiver" class="block text-xs font-bold text-orange-600 mb-2 ml-1 uppercase">Quem Repassou?</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3 text-orange-300 group-focus-within:text-orange-500 transition-colors"><i data-lucide="user-minus" class="w-5 h-5"></i></div>
                                <input type="text" name="giver_name" 
                                       class="w-full pl-12 pr-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-4 focus:ring-orange-50 focus:border-orange-400 outline-none transition-all placeholder:text-slate-300 font-medium"
                                       placeholder="Ex: TI / Gestor Atual">
                            </div>
                        </div>
                        <div class="relative group">
                            <label id="labelReceiver" class="block text-xs font-bold text-green-600 mb-2 ml-1 uppercase">Quem Recebeu? *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3 text-green-300 group-focus-within:text-green-500 transition-colors"><i data-lucide="user-plus" class="w-5 h-5"></i></div>
                                <input type="text" name="responsible_name" required
                                       class="w-full pl-12 pr-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-4 focus:ring-green-50 focus:border-green-400 outline-none transition-all placeholder:text-slate-300 font-medium"
                                       placeholder="Ex: Novo Funcionário">
                            </div>
                        </div>
                    </div>
                </section>

                <div class="mb-5">
                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Motivo / Observação</label>
                    <textarea name="description" rows="2" 
                              class="w-full p-4 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all shadow-sm resize-none placeholder:text-slate-400" 
                              placeholder="Justifique a movimentação..."></textarea>
                </div>

                <div>
                    <div class="flex justify-between items-end mb-2 ml-1">
                        <label class="block text-sm font-bold text-slate-700">Assinatura Digital</label>
                        <button type="button" id="clear-signature" class="text-[10px] uppercase font-bold text-red-500 hover:text-red-700 hover:bg-red-50 px-2 py-1 rounded transition-colors">
                            Limpar
                        </button>
                    </div>
                    <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-white touch-none relative group hover:border-blue-400 transition-colors overflow-hidden">
                        <canvas id="signature-pad" class="w-full h-40 cursor-crosshair block" style="touch-action: none;"></canvas>
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-20">
                            <span class="text-slate-400 text-xl font-bold uppercase tracking-widest">Assine Aqui</span>
                        </div>
                    </div>
                    <input type="hidden" name="signature_data" id="signatureData">
                </div>
                <div class="h-4"></div>
            </div>

            <div class="px-6 py-4 bg-white border-t border-slate-100 flex flex-col sm:flex-row justify-end gap-3 rounded-b-3xl shrink-0 z-20 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)]">
                <button type="button" onclick="closeModal('modalNewMovement')" class="w-full sm:w-auto px-6 py-3.5 border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:bg-slate-50 hover:text-slate-800 transition-all">
                    Cancelar
                </button>
                <button type="submit" class="w-full sm:w-auto px-8 py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold shadow-lg shadow-blue-200 transition-all flex items-center justify-center gap-2 transform active:scale-[0.98]">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> Confirmar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const allLocationsDataMov = <?php echo $locationsJson; ?>;

    function openConfirmModal(movId, managerName) {
        document.getElementById('confirmMovId').value = movId;
        document.getElementById('rejectMovId').value = movId;
        document.getElementById('managerNameToConfirm').innerText = managerName;
        openModal('modalConfirmReceipt');
    }

    function updateManagerInfoMov() {
        const select = document.getElementById('movLocationSelect');
        const card = document.getElementById('managerInfoCardMov');
        const display = document.getElementById('managerNameDisplayMov');
        const input = document.getElementById('managerNameInputMov');
        const locId = select.value;
        const location = allLocationsDataMov.find(l => l.id == locId);
        
        if (location && location.manager_name) {
            display.innerText = location.manager_name;
            input.value = location.manager_name;
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
            input.value = '';
        }
    }

    // JS: MODAL
    function openModal(id, type = null) {
        const el = document.getElementById(id);
        if (id === 'modalNewMovement' && type) {
            setupMovementModal(type);
        }
        el.classList.remove('hidden');
        setTimeout(() => {
            el.querySelector('.modal-backdrop').classList.remove('opacity-0');
            el.querySelector('.modal-panel').classList.remove('scale-95', 'opacity-0');
            el.querySelector('.modal-panel').classList.add('scale-100', 'opacity-100');
            if(typeof resizeCanvas === 'function') resizeCanvas();
        }, 10);
    }

    function setupMovementModal(type) {
        const title = document.getElementById('modalTitle');
        const subtitle = document.getElementById('modalSubtitle');
        const iconContainer = document.getElementById('modalIconContainer');
        const labelGiver = document.getElementById('labelGiver');
        const labelReceiver = document.getElementById('labelReceiver');
        const inputGiver = document.querySelector('input[name="giver_name"]');
        const inputType = document.getElementById('movementTypeInput');
        const inputDesc = document.querySelector('textarea[name="description"]');
        const selectStatus = document.querySelector('select[name="status"]');

        if (type === 'return') {
            title.innerText = 'Devolução de Ativo';
            subtitle.innerText = 'Retorno ao estoque ou manutenção';
            labelGiver.innerText = 'QUEM DEVOLVEU? *';
            labelReceiver.innerText = 'QUEM RECEBEU? *';
            iconContainer.innerHTML = '<i data-lucide="rotate-ccw" class="w-5 h-5"></i>';
            if(inputGiver) inputGiver.required = true;
            if(inputType) inputType.value = 'return';
            if(inputDesc) inputDesc.placeholder = "Motivo da devolução (ex: Defeito, Fim de contrato)...";
            if(selectStatus) {
                selectStatus.disabled = true;
                selectStatus.classList.add('bg-slate-100', 'text-slate-400');
            }
        } else {
            title.innerText = 'Nova Movimentação';
            subtitle.innerText = 'Transferência ou mudança de status';
            labelGiver.innerText = 'QUEM REPASSOU?';
            labelReceiver.innerText = 'QUEM RECEBEU? *';
            iconContainer.innerHTML = '<i data-lucide="arrow-right-left" class="w-5 h-5"></i>';
            if(inputGiver) inputGiver.required = false;
            if(inputType) inputType.value = 'transfer';
            if(inputDesc) inputDesc.placeholder = "Justifique a movimentação...";
            if(selectStatus) {
                selectStatus.disabled = false;
                selectStatus.classList.remove('bg-slate-100', 'text-slate-400');
            }
        }
        lucide.createIcons();
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        el.querySelector('.modal-backdrop').classList.add('opacity-0');
        el.querySelector('.modal-panel').classList.add('scale-95', 'opacity-0');
        el.querySelector('.modal-panel').classList.remove('scale-100', 'opacity-100');
        setTimeout(() => el.classList.add('hidden'), 300);
    }

    // JS: ASSET SEARCH
    function showAssetList() { document.getElementById('assetDropdownList').classList.remove('hidden'); }
    
    function filterAssets() {
        const input = document.getElementById('assetSearchInput');
        const filter = input.value.toLowerCase();
        const list = document.getElementById('assetDropdownList');
        const items = list.getElementsByClassName('asset-option');
        list.classList.remove('hidden');
        let hasVisible = false;
        for (let i = 0; i < items.length; i++) {
            const span = items[i].querySelector("span.hidden");
            if (span && span.textContent.indexOf(filter) > -1) { items[i].style.display = ""; hasVisible = true; } else { items[i].style.display = "none"; }
        }
        const noResult = document.getElementById('noAssetFound');
        if (!hasVisible) noResult.classList.remove('hidden'); else noResult.classList.add('hidden');
    }

    function selectAsset(id, name) {
        document.getElementById('assetSearchInput').value = name;
        document.getElementById('selectedAssetId').value = id;
        document.getElementById('assetDropdownList').classList.add('hidden');
    }

    document.addEventListener('click', function(e) {
        const input = document.getElementById('assetSearchInput');
        const list = document.getElementById('assetDropdownList');
        if (input && list && !input.contains(e.target) && !list.contains(e.target)) {
            list.classList.add('hidden');
        }
    });

    // JS: INIT
    var signaturePad;
    document.addEventListener("DOMContentLoaded", function() {
        var canvas = document.getElementById('signature-pad');
        if(canvas) {
            signaturePad = new SignaturePad(canvas, { backgroundColor: 'rgba(255,255,255,0)', penColor: 'rgb(0,0,0)' });
            window.resizeCanvas = function() {
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext("2d").scale(ratio, ratio);
                signaturePad.clear();
            }
            window.addEventListener("resize", resizeCanvas);
            document.getElementById('clear-signature').addEventListener('click', function () { signaturePad.clear(); });
            document.getElementById('formNewMovement').addEventListener('submit', function(e) {
                if (!signaturePad.isEmpty()) {
                    document.getElementById('signatureData').value = signaturePad.toDataURL();
                }
            });
        }
        lucide.createIcons();
    });
</script>