<?php
// pages/peripherals.php

$page_title = "Periféricos e Consumíveis";
$message = '';

// Auto-setup: Garantir colunas de rastreamento em peripheral_movements
try {
    $pdo->query("SELECT to_asset_id FROM peripheral_movements LIMIT 1");
} catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE peripheral_movements ADD COLUMN to_asset_id INT NULL"); } catch (Exception $e2) {}
}
try {
    $pdo->query("SELECT from_asset_id FROM peripheral_movements LIMIT 1");
} catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE peripheral_movements ADD COLUMN from_asset_id INT NULL"); } catch (Exception $e2) {}
}

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
    try {
        $user_id = $_SESSION['user_id'] ?? 0;
        $company_id = $_SESSION['user_company_id'] ?? 1;

        // --- CRIAR/EDITAR PERIFÉRICO ---
        if (isset($_POST['action']) && in_array($_POST['action'], ['create_peripheral', 'update_peripheral'])) {
            if (!hasPermission('manage_peripherals')) throw new Exception("Sem permissão para gerenciar periféricos.");
            
            $name = $_POST['name'];
            $category = $_POST['category'];
            $sku = $_POST['sku'] ?? null;
            $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;
            $min_stock = !empty($_POST['min_stock']) ? $_POST['min_stock'] : 0;
            $description = $_POST['description'] ?? null;

            if ($min_stock < 0) {
                throw new Exception("O estoque mínimo não pode ser negativo.");
            }

            if ($_POST['action'] == 'create_peripheral') {
                $quantity = !empty($_POST['quantity']) ? $_POST['quantity'] : 0;
                if ($quantity < 0) {
                    throw new Exception("A quantidade inicial não pode ser negativa.");
                }

                $stmt = $pdo->prepare("INSERT INTO peripherals (company_id, name, category, sku, location_id, min_stock, description, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $name, $category, $sku, $location_id, $min_stock, $description, $quantity]);
                log_action('stock_create', "Item de estoque '{$name}' criado com quantidade inicial {$quantity}. ID: " . $pdo->lastInsertId());
                $message = "Periférico cadastrado com sucesso!";
            } else {
                $id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE peripherals SET name=?, category=?, sku=?, location_id=?, min_stock=?, description=? WHERE id=?");
                $stmt->execute([$name, $category, $sku, $location_id, $min_stock, $description, $id]);
                log_action('stock_update', "Item de estoque '{$name}' (ID: {$id}) atualizado.");
                $message = "Periférico atualizado com sucesso!";
            }
        }

        // --- AJUSTAR ESTOQUE ---
        if (isset($_POST['action']) && $_POST['action'] == 'adjust_stock') {
            if (!hasPermission('manage_peripherals')) throw new Exception("Sem permissão para ajustar estoque.");
            
            $id = $_POST['id'];
            $change = (int)$_POST['change_amount'];
            $reason = $_POST['reason'];

            if ($change == 0) {
                throw new Exception("A quantidade do ajuste deve ser diferente de zero.");
            }

            $pdo->beginTransaction();

            // Pega a quantidade atual e garante o bloqueio da linha
            $stmt = $pdo->prepare("SELECT quantity, min_stock, name FROM peripherals WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            $current_quantity = $data['quantity'];
            $min_stock = $data['min_stock'];

            $new_quantity = $current_quantity + $change;

            if ($new_quantity < 0) {
                throw new Exception("O estoque não pode ficar negativo.");
            }

            // Atualiza o estoque
            $stmt = $pdo->prepare("UPDATE peripherals SET quantity = ? WHERE id = ?");
            $stmt->execute([$new_quantity, $id]);

            // Registra o movimento
            $stmt = $pdo->prepare("INSERT INTO peripheral_movements (peripheral_id, user_id, change_amount, new_quantity, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $user_id, $change, $new_quantity, $reason]);

            $pdo->commit();
            log_action('stock_adjust', "Estoque do item '{$data['name']}' (ID: {$id}) ajustado em {$change}. Motivo: {$reason}. Novo total: {$new_quantity}.");
            $message = "Estoque ajustado com sucesso!";

            if ($new_quantity <= $min_stock && $min_stock > 0) {
                $message .= " ⚠️ ALERTA: O estoque de '{$data['name']}' atingiu o nível mínimo!";
            }
        }

        // --- EXCLUIR PERIFÉRICO ---
        if (isset($_POST['action']) && $_POST['action'] == 'delete_peripheral') {
            if (!hasPermission('manage_peripherals')) throw new Exception("Sem permissão para excluir.");
            
            $id = $_POST['id'];
            // Verifica se há movimentações antes de excluir
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM peripheral_movements WHERE peripheral_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Não é possível excluir. O periférico já possui histórico de movimentações.");
            }
            $stmt = $pdo->prepare("DELETE FROM peripherals WHERE id = ?");
            $stmt->execute([$id]);
            log_action('stock_delete', "Item de estoque ID {$id} excluído.");
            $message = "Periférico excluído.";
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Adiciona um prefixo para identificar o tipo de mensagem
        $message = "error:Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. DADOS
// =================================================================================
$view_mode = 'list';
$peripheral_detail = null;
$movements = [];

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $view_mode = 'detail';
    $peripheral_id = $_GET['id'];
    $start_date = filter_input(INPUT_GET, 'start_date');
    $end_date = filter_input(INPUT_GET, 'end_date');
    $page = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
    $items_per_page = 10;
    if (!$page || $page < 1) $page = 1;
    $company_id = $_SESSION['user_company_id'] ?? 1;

    // Busca os detalhes do periférico
    $stmt = $pdo->prepare("SELECT p.*, l.name as location_name FROM peripherals p LEFT JOIN locations l ON p.location_id = l.id WHERE p.id = ? AND p.company_id = ?");
    $stmt->execute([$peripheral_id, $company_id]);
    $peripheral_detail = $stmt->fetch();

    if ($peripheral_detail) {
        $page_title = "Histórico: " . $peripheral_detail['name'];
        
        // Filtros
        $where_clauses = ["pm.peripheral_id = ?"];
        $params = [$peripheral_id];

        if ($start_date) {
            $where_clauses[] = "DATE(pm.created_at) >= ?";
            $params[] = $start_date;
        }
        if ($end_date) {
            $where_clauses[] = "DATE(pm.created_at) <= ?";
            $params[] = $end_date;
        }
        $where_sql = implode(" AND ", $where_clauses);

        // Paginação e Resumo
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM peripheral_movements pm WHERE $where_sql");
        $count_stmt->execute($params);
        $total_items = $count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $items_per_page);
        $offset = ($page - 1) * $items_per_page;

        $summary_stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN change_amount > 0 THEN change_amount ELSE 0 END) as total_entries,
                SUM(CASE WHEN change_amount < 0 THEN change_amount ELSE 0 END) as total_exits
            FROM peripheral_movements pm
            WHERE $where_sql
        ");
        $summary_stmt->execute($params);
        $summary = $summary_stmt->fetch();
        $total_entries = $summary['total_entries'] ?? 0;
        $total_exits = abs($summary['total_exits'] ?? 0);

        // Busca movimentações paginadas
        $stmt = $pdo->prepare("SELECT pm.*, u.name as user_name FROM peripheral_movements pm LEFT JOIN users u ON pm.user_id = u.id WHERE $where_sql ORDER BY pm.created_at DESC LIMIT $items_per_page OFFSET $offset");
        $stmt->execute($params);
        $movements = $stmt->fetchAll();
    } else {
        $view_mode = 'list'; // Volta para a lista se o ID for inválido
    }
}

if ($view_mode === 'list') {
    $company_id = $_SESSION['user_company_id'] ?? 1;

    // --- PAGINAÇÃO E FILTROS ---
    $items_per_page = 20;
    $current_page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $search_term = $_GET['search'] ?? '';
    $filter_category = $_GET['category'] ?? '';

    // Constrói a cláusula WHERE para os filtros usando parâmetros nomeados
    $where_clauses = ['p.company_id = :company_id'];
    $params = [':company_id' => $company_id];

    if (!empty($search_term)) {
        $where_clauses[] = '(p.name LIKE :search_term OR p.sku LIKE :search_term OR l.name LIKE :search_term)';
        $params[':search_term'] = "%{$search_term}%";
    }
    if (!empty($filter_category)) {
        $where_clauses[] = 'p.category = :category';
        $params[':category'] = $filter_category;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);


    // Conta o total de itens para a paginação
    $count_sql = "SELECT COUNT(p.id) FROM peripherals p LEFT JOIN locations l ON p.location_id = l.id {$where_sql}";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params); // A contagem pode usar o mesmo array de parâmetros
    $total_items = $stmt->fetchColumn();
    $total_pages = ceil($total_items / $items_per_page);
    $offset = ($current_page - 1) * $items_per_page;

    // Correção: Vincular LIMIT e OFFSET como inteiros para evitar erro de sintaxe SQL.
    $sql = "SELECT p.*, l.name as location_name FROM peripherals p LEFT JOIN locations l ON p.location_id = l.id {$where_sql} ORDER BY p.name ASC LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);

    // Vincular parâmetros do WHERE e da paginação
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->bindValue(':limit', (int) $items_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $peripherals = $stmt->fetchAll();

    // Estatísticas Rápidas para o Topo da Lista
    $stmtStats = $pdo->prepare("SELECT 
        COUNT(*) as total_items, 
        SUM(quantity) as total_quantity, 
        SUM(CASE WHEN quantity <= min_stock AND min_stock > 0 THEN 1 ELSE 0 END) as low_stock_count 
        FROM peripherals WHERE company_id = ?");
    $stmtStats->execute([$company_id]);
    $list_stats = $stmtStats->fetch();
}

// Busca as categorias da tabela 'categories' em vez de extrair da tabela de periféricos.
// Isso centraliza o gerenciamento de categorias.
$stmt = $pdo->prepare("SELECT name FROM categories WHERE company_id = ? ORDER BY name ASC");
$stmt->execute([$_SESSION['user_company_id'] ?? 1]);
$peripheral_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Busca locais para os modais (necessário em ambas as views)
$stmt = $pdo->prepare("SELECT id, name FROM locations WHERE company_id = ? ORDER BY name ASC");
$stmt->execute([$_SESSION['user_company_id'] ?? 1]);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<style>
    @media print {
        aside, header, nav, .sidebar, .navbar { display: none !important; }
        body, main { margin: 0 !important; padding: 0 !important; background: white !important; }
        .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl { box-shadow: none !important; }
    }
</style>
<?php 
if ($message): 
    $is_error = strpos($message, 'error:') === 0;
    $message_text = $is_error ? substr($message, 6) : $message;
    $message_class = $is_error ? 'border-red-500 text-red-500' : 'border-blue-500 text-blue-500';
    $icon = $is_error ? 'alert-circle' : 'check-circle';
?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-white border-l-4 <?php echo $message_class; ?> px-6 py-4 rounded-xl shadow-lg flex items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-4">
            <div class="<?php echo $message_class; ?>"><i data-lucide="<?php echo $icon; ?>" class="w-6 h-6"></i></div>
            <div class="flex flex-col">
                <span class="font-bold text-slate-700"><?php echo $is_error ? 'Ocorreu um Erro' : 'Sucesso'; ?></span>
                <span class="text-sm text-slate-600"><?php echo htmlspecialchars($message_text); ?></span>
            </div>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-slate-400 hover:text-slate-600 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage')?.remove(), 4000);</script>
<?php endif; ?>

<?php if ($view_mode === 'list'): ?>
<!-- ================================================================================= -->
<!-- ============================ MODO DE LISTA ====================================== -->
<!-- ================================================================================= -->
<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title; ?></h1>
        <p class="text-sm text-slate-500">Gerencie o estoque de itens não rastreados individualmente.</p>
    </div>
    <?php if(hasPermission('manage_peripherals')): ?>
    <button onclick="openPeripheralModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
        <i data-lucide="plus" class="w-4 h-4"></i> Novo Item
    </button>
    <?php endif; ?>
</div>

<!-- CARDS DE RESUMO (KPIs) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="package" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Itens Cadastrados</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $list_stats['total_items'] ?? 0; ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-lg"><i data-lucide="layers" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Estoque Total</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $list_stats['total_quantity'] ?? 0; ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 <?php echo ($list_stats['low_stock_count'] > 0) ? 'bg-red-50 text-red-600' : 'bg-slate-50 text-slate-400'; ?> rounded-lg"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Estoque Baixo</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $list_stats['low_stock_count'] ?? 0; ?></h3>
        </div>
    </div>
</div>

<!-- FILTROS -->
<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 flex flex-col md:flex-row gap-4">
    <div class="relative flex-1">
        <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
        <input type="text" id="searchInput" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Buscar por nome, SKU ou local..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
    </div>
    <select id="filterCategory" class="border border-slate-200 rounded-lg px-3 py-2 text-sm text-slate-600 bg-slate-50 md:w-48">
        <option value="">Todas Categorias</option>
        <?php foreach($peripheral_categories as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filter_category === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- TABELA -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4">Nome do Item</th>
                    <th class="p-4">Categoria</th>
                    <th class="p-4">SKU</th>
                    <th class="p-4">Local</th>
                    <th class="p-4 text-center">Estoque</th>
                    <th class="p-4 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($peripherals ?? [])): ?>
                <tr>
                    <td colspan="6" class="text-center p-10 text-slate-500">
                        <div class="flex flex-col items-center gap-2">
                            <i data-lucide="archive" class="w-10 h-10"></i>
                            <span class="font-medium">Nenhum item encontrado.</span>
                            <p class="text-sm">Comece cadastrando um novo periférico ou consumível.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($peripherals ?? [] as $p): ?>
                <?php $is_low_stock = ($p['quantity'] <= $p['min_stock'] && $p['min_stock'] > 0); ?>
                <tr class="hover:bg-slate-50 transition-colors peripheral-row group <?php echo $is_low_stock ? 'bg-red-50/30' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($p['name'] . ' ' . $p['sku'] . ' ' . $p['category'] . ' ' . $p['location_name'])); ?>">
                    <td class="p-4 font-medium text-slate-800">
                        <?php echo htmlspecialchars($p['name']); ?>
                        <?php if($is_low_stock): ?>
                            <span class="inline-flex items-center ml-2 px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700 border border-red-200" title="Estoque Baixo">Baixo</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-4"><span class="px-2 py-1 rounded-md bg-slate-100 text-slate-600 text-xs font-medium border border-slate-200"><?php echo htmlspecialchars($p['category']); ?></span></td>
                    <td class="p-4 text-slate-500 font-mono text-xs"><?php echo htmlspecialchars($p['sku'] ?: '-'); ?></td>
                    <td class="p-4">
                        <div class="flex items-center gap-1 text-slate-600 text-sm">
                            <i data-lucide="map-pin" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($p['location_name'] ?: 'Geral'); ?>
                        </div>
                    </td>
                    <td class="p-4 text-center">
                        <div class="flex flex-col items-center">
                            <span class="font-bold text-sm <?php echo $is_low_stock ? 'text-red-600' : 'text-slate-700'; ?>">
                                <?php echo $p['quantity']; ?> <span class="text-[10px] font-normal text-slate-400 ml-0.5">unid.</span>
                            </span>
                        </div>
                    </td>
                    <td class="p-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <?php if(hasPermission('manage_peripherals')): ?>
                            <button onclick='openStockModal(<?php echo json_encode($p); ?>)' class="text-blue-600 hover:bg-blue-50 p-1.5 rounded" title="Ajustar Estoque"><i data-lucide="arrow-left-right" class="w-4 h-4"></i></button>
                            <?php endif; ?>
                            <a href="index.php?page=peripherals&id=<?php echo $p['id']; ?>" class="text-slate-400 hover:text-blue-600 p-1.5 rounded" title="Ver Histórico">
                                <i data-lucide="history" class="w-4 h-4"></i>
                            </a>
                            <?php if(hasPermission('manage_peripherals')): ?>
                            <button onclick='openPeripheralModal(<?php echo json_encode($p); ?>)' class="text-slate-400 hover:text-blue-600 p-1.5 rounded" title="Editar"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                            <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir? Esta ação não pode ser desfeita se houver movimentações.')" class="inline">
                                <input type="hidden" name="action" value="delete_peripheral">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <button type="submit" class="text-slate-400 hover:text-red-600 p-1.5 rounded" title="Excluir"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- PAGINAÇÃO -->
<?php if ($total_pages > 1): ?>
<div class="mt-6 flex justify-between items-center text-sm">
    <span class="text-slate-500">
        Página <?php echo $current_page; ?> de <?php echo $total_pages; ?> (Total: <?php echo $total_items; ?> itens)
    </span>
    <div class="flex items-center gap-1">
        <?php
            $base_url = "index.php?page=peripherals&search=" . urlencode($search_term) . "&category=" . urlencode($filter_category);
        ?>
        <!-- Botão Anterior -->
        <a href="<?php echo $base_url . '&p=' . ($current_page - 1); ?>" class="<?php echo ($current_page <= 1) ? 'pointer-events-none text-slate-300' : 'hover:bg-slate-100 text-slate-500'; ?> px-2 py-1.5 rounded-md transition-colors">
            <i data-lucide="chevron-left" class="w-4 h-4"></i>
        </a>

        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="bg-blue-600 text-white font-medium px-3 py-1.5 rounded-md"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="<?php echo $base_url . '&p=' . $i; ?>" class="hover:bg-slate-100 text-slate-500 px-3 py-1.5 rounded-md transition-colors"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <!-- Botão Próximo -->
        <a href="<?php echo $base_url . '&p=' . ($current_page + 1); ?>" class="<?php echo ($current_page >= $total_pages) ? 'pointer-events-none text-slate-300' : 'hover:bg-slate-100 text-slate-500'; ?> px-2 py-1.5 rounded-md transition-colors">
            <i data-lucide="chevron-right" class="w-4 h-4"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<?php endif; // Fim do modo de lista ?>


<?php // Modais são necessários em ambas as views, então ficam fora do IF ?>
<!-- MODAL NOVO/EDITAR PERIFÉRICO -->
<div id="modalPeripheral" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalPeripheral')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl p-6 modal-panel">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-800" id="modalPeripheralTitle">Novo Item</h3>
                <p class="text-sm text-slate-500">Preencha os detalhes do periférico ou consumível.</p>
            </div>
            <button onclick="closeModal('modalPeripheral')" class="p-1 text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="peripheralAction" value="create_peripheral">
            <input type="hidden" name="id" id="peripheralId">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="relative"><label for="peripheralName" class="block text-sm font-medium mb-1">Nome do Item *</label><i data-lucide="tag" class="absolute left-3 top-9 w-4 h-4 text-slate-400"></i><input type="text" name="name" id="peripheralName" required class="w-full border p-2 pl-9 rounded-lg"></div>
                    <div class="relative">
                        <label for="peripheralCategory" class="block text-sm font-medium mb-1">Categoria</label>
                        <i data-lucide="folder" class="absolute left-3 top-9 w-4 h-4 text-slate-400 z-10"></i>
                        <select name="category" id="peripheralCategory" class="w-full border p-2 pl-9 rounded-lg bg-white appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Nenhuma</option>
                            <?php foreach($peripheral_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <i data-lucide="chevron-down" class="absolute right-3 top-9 w-4 h-4 text-slate-400"></i>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="relative"><label for="peripheralSku" class="block text-sm font-medium mb-1">SKU / Part Number</label><i data-lucide="barcode" class="absolute left-3 top-9 w-4 h-4 text-slate-400"></i><input type="text" name="sku" id="peripheralSku" class="w-full border p-2 pl-9 rounded-lg"></div>
                    <div class="relative">
                        <label for="peripheralLocation" class="block text-sm font-medium mb-1">Local de Estoque</label>
                        <i data-lucide="map-pin" class="absolute left-3 top-9 w-4 h-4 text-slate-400 z-10"></i>
                        <select name="location_id" id="peripheralLocation" class="w-full border p-2 pl-9 rounded-lg bg-white appearance-none focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Nenhum</option>
                            <?php foreach($locations as $l): ?><option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option><?php endforeach; ?>
                        </select>
                        <i data-lucide="chevron-down" class="absolute right-3 top-9 w-4 h-4 text-slate-400"></i>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div id="quantityFieldContainer" class="relative">
                        <label for="peripheralQuantity" class="block text-sm font-medium mb-1">Quantidade</label>
                        <i data-lucide="boxes" class="absolute left-3 top-9 w-4 h-4 text-slate-400"></i>
                        <input type="number" name="quantity" id="peripheralQuantity" class="w-full border p-2 pl-9 rounded-lg" value="0" min="0">
                    </div>
                    <div class="relative">
                        <label for="peripheralMinStock" class="block text-sm font-medium mb-1">Estoque Mínimo</label>
                        <i data-lucide="alert-triangle" class="absolute left-3 top-9 w-4 h-4 text-slate-400"></i>
                        <input type="number" name="min_stock" id="peripheralMinStock" class="w-full border p-2 pl-9 rounded-lg" value="0" min="0">
                    </div>
                </div>
                <div class="relative">
                    <label for="peripheralDescription" class="block text-sm font-medium mb-1">Descrição</label>
                    <i data-lucide="file-text" class="absolute left-3 top-9 w-4 h-4 text-slate-400"></i>
                    <textarea name="description" id="peripheralDescription" rows="2" class="w-full border p-2 pl-9 rounded-lg"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="closeModal('modalPeripheral')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL AJUSTAR ESTOQUE -->
<div id="modalStock" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalStock')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6 modal-panel">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-800" id="modalStockTitle">Ajustar Estoque</h3>
                <p class="text-sm text-slate-500 truncate max-w-xs" id="modalStockItemName"></p>
            </div>
            <button onclick="closeModal('modalStock')" class="p-1 text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="id" id="stockId">
            <input type="hidden" id="currentStock" value="0">
            <div class="space-y-4">
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3 text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div><span class="font-medium text-slate-500">Estoque Atual:</span> <strong class="text-slate-800" id="currentStockDisplay">0</strong></div>
                        <div><span class="font-medium text-slate-500">Novo Estoque:</span> <strong class="text-blue-600" id="newStockDisplay">0</strong></div>
                    </div>
                    <div class="mt-2"><span class="font-medium text-slate-500">SKU:</span> <span class="text-slate-700" id="stockSkuDisplay">-</span></div>
                    <div class="mt-1"><span class="font-medium text-slate-500">Local:</span> <span class="text-slate-700" id="stockLocationDisplay">-</span></div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Ajuste de Quantidade</label>
                    <div class="flex items-center">
                        <button type="button" onclick="adjustValue(-1)" class="px-4 py-2 border bg-slate-100 rounded-l-lg hover:bg-slate-200">-</button>
                        <input type="number" name="change_amount" id="changeAmount" oninput="updateNewStockDisplay()" required class="w-full border-t border-b p-2 text-center font-bold text-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="-1">
                        <button type="button" onclick="adjustValue(1)" class="px-4 py-2 border bg-slate-100 rounded-r-lg hover:bg-slate-200">+</button>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Use valores negativos para saídas e positivos para entradas.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Motivo / Destino</label>
                    <input type="text" name="reason" class="w-full border p-2 rounded-lg" placeholder="Ex: Entregue para João Silva" required>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="closeModal('modalStock')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Confirmar Ajuste</button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================================= -->
<!-- ============================ SCRIPTS DA LISTA =================================== -->
<!-- ================================================================================= -->

<script>
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    function openPeripheralModal(data = null) {
        const form = document.getElementById('modalPeripheral').querySelector('form');
        form.reset(); // Limpa o formulário
        const quantityContainer = document.getElementById('quantityFieldContainer');

        if (data) {
            document.getElementById('modalPeripheralTitle').innerText = 'Editar Item';
            document.getElementById('peripheralAction').value = 'update_peripheral';
            document.getElementById('peripheralId').value = data.id;
            document.getElementById('peripheralName').value = data.name;
            document.getElementById('peripheralCategory').value = data.category;
            document.getElementById('peripheralSku').value = data.sku;
            document.getElementById('peripheralMinStock').value = data.min_stock;
            document.getElementById('peripheralDescription').value = data.description;
            document.getElementById('peripheralLocation').value = data.location_id;

            // Na edição, desabilita o campo de quantidade e mostra o valor atual
            const qtyInput = document.getElementById('peripheralQuantity');
            qtyInput.value = data.quantity;
            qtyInput.disabled = true;
            quantityContainer.querySelector('label').innerText = 'Quantidade Atual (não editável)';
        } else {
            document.getElementById('modalPeripheralTitle').innerText = 'Novo Item';
            document.getElementById('peripheralAction').value = 'create_peripheral';
            document.getElementById('peripheralId').value = '';
            document.getElementById('peripheralQuantity').disabled = false;
            quantityContainer.querySelector('label').innerText = 'Quantidade Inicial';
        }
        openModal('modalPeripheral');
    }

    function openStockModal(data) {
        document.getElementById('stockId').value = data.id;
        document.getElementById('modalStockTitle').innerText = `Ajustar Estoque (Atual: ${data.quantity})`;
        document.getElementById('modalStockItemName').innerText = `Item: ${data.name}`;
        document.getElementById('changeAmount').value = -1; // Default para saída
        document.getElementById('currentStock').value = data.quantity;
        document.getElementById('currentStockDisplay').innerText = data.quantity;
        document.getElementById('stockSkuDisplay').innerText = data.sku || '-';
        document.getElementById('stockLocationDisplay').innerText = data.location_name || 'Nenhum';
        updateNewStockDisplay();
        openModal('modalStock');
    }

    function adjustValue(amount) {
        const input = document.getElementById('changeAmount');
        input.value = parseInt(input.value || 0) + amount;
        updateNewStockDisplay();
    }

    function updateNewStockDisplay() {
        const currentStock = parseInt(document.getElementById('currentStock').value || 0);
        const changeAmount = parseInt(document.getElementById('changeAmount').value || 0);
        const newStock = currentStock + changeAmount;
        const newStockDisplay = document.getElementById('newStockDisplay');
        newStockDisplay.innerText = newStock;
        newStockDisplay.classList.toggle('text-red-600', newStock < 0);
        newStockDisplay.classList.toggle('text-blue-600', newStock >= 0);
    }

    // --- Lógica de Filtro e Busca (agora no servidor) ---
    const searchInput = document.getElementById('searchInput');
    const categoryFilter = document.getElementById('filterCategory');
    let searchTimeout;

    function applyFilters() {
        const searchTerm = searchInput.value;
        const category = categoryFilter.value;
        // Redireciona para a página 1 com os novos filtros
        window.location.href = `index.php?page=peripherals&search=${encodeURIComponent(searchTerm)}&category=${encodeURIComponent(category)}&p=1`;
    }

    if (searchInput && categoryFilter) {
        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500); // Espera 500ms após o usuário parar de digitar
        });
        categoryFilter.addEventListener('change', applyFilters);
    }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

<?php if ($view_mode === 'detail'): ?>
<!-- ================================================================================= -->
<!-- ============================ MODO DE DETALHE ==================================== -->
<!-- ================================================================================= -->
<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div class="flex items-center gap-3">
        <a href="index.php?page=peripherals" class="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-slate-800 transition-colors" title="Voltar">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <?php if ($peripheral_detail): ?>
            <div>
                <h1 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($peripheral_detail['name']); ?></h1>
                <div class="text-sm text-slate-500 flex items-center gap-x-3 gap-y-1 flex-wrap">
                    <span>SKU: <span class="font-mono"><?php echo htmlspecialchars($peripheral_detail['sku'] ?: '-'); ?></span></span>
                    <span class="text-slate-300 hidden md:inline">|</span>
                    <span>Categoria: <?php echo htmlspecialchars($peripheral_detail['category'] ?: '-'); ?></span>
                </div>
            </div>
        <?php else: ?>
            <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title; ?></h1>
        <?php endif; ?>
    </div>
    <?php if ($peripheral_detail): ?>
        <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm text-center">
            <p class="text-xs font-bold text-slate-400 uppercase">Estoque Atual</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo $peripheral_detail['quantity']; ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- FILTROS -->
<?php if ($peripheral_detail): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
        <input type="hidden" name="page" value="peripherals">
        <input type="hidden" name="id" value="<?php echo $peripheral_id; ?>">
        
        <div class="w-full sm:w-auto">
            <label class="block text-xs font-medium text-slate-500 mb-1">Data Inicial</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date ?? ''); ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-blue-500">
        </div>
        
        <div class="w-full sm:w-auto">
            <label class="block text-xs font-medium text-slate-500 mb-1">Data Final</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date ?? ''); ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-blue-500">
        </div>
        
        <div class="flex gap-2 w-full sm:w-auto">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2">
                <i data-lucide="filter" class="w-4 h-4"></i> Filtrar
            </button>
            <?php if ($start_date || $end_date): ?>
                <a href="index.php?page=peripherals&id=<?php echo $peripheral_id; ?>" class="px-4 py-2 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors flex items-center gap-2">
                    <i data-lucide="x" class="w-4 h-4"></i> Limpar
                </a>
            <?php endif; ?>
            <button type="button" onclick="window.print()" class="px-4 py-2 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors flex items-center gap-2 print:hidden">
                <i data-lucide="printer" class="w-4 h-4"></i> Imprimir
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- RESUMO -->
<?php if ($peripheral_detail): ?>
<div class="grid grid-cols-2 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl p-4 flex items-center justify-between shadow-sm">
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Total Entradas</p>
            <p class="text-2xl font-bold text-emerald-600">+<?php echo $total_entries; ?></p>
        </div>
        <div class="p-3 bg-emerald-50 rounded-lg text-emerald-600">
            <i data-lucide="arrow-down-left" class="w-6 h-6"></i>
        </div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 flex items-center justify-between shadow-sm">
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Total Saídas</p>
            <p class="text-2xl font-bold text-rose-600">-<?php echo $total_exits; ?></p>
        </div>
        <div class="p-3 bg-red-100 rounded-lg text-red-600">
            <i data-lucide="arrow-up-right" class="w-6 h-6"></i>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CONTEÚDO -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <?php if (!$peripheral_detail): ?>
        <div class="text-center p-10 text-red-500">
            <i data-lucide="alert-triangle" class="w-12 h-12 mx-auto mb-2"></i>
            <p class="font-medium">Periférico não encontrado ou ID inválido.</p>
        </div>
    <?php elseif (empty($movements)): ?>
        <div class="text-center p-10 text-slate-500">
            <i data-lucide="history" class="w-12 h-12 mx-auto mb-2"></i>
            <p class="font-medium">Nenhum histórico de movimentação para este item.</p>
        </div>
    <?php else: ?>
        <div class="relative space-y-8 before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-slate-300 before:to-transparent">
            <?php foreach ($movements as $mov): 
                $is_entry = $mov['change_amount'] > 0;
                $change_class = $is_entry ? 'text-emerald-600 bg-emerald-50 border-emerald-100' : 'text-rose-600 bg-rose-50 border-rose-100';
                $icon = $is_entry ? 'arrow-down-left' : 'arrow-up-right';
                $icon_bg = $is_entry ? 'bg-emerald-500' : 'bg-rose-500';
            ?>
                <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group is-active">
                    <!-- Icone da Timeline -->
                    <div class="flex items-center justify-center w-10 h-10 rounded-full border-4 border-white bg-slate-200 group-[.is-active]:<?php echo $icon_bg; ?> text-white shadow shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2">
                        <i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i>
                    </div>
                    
                    <!-- Card de Conteúdo -->
                    <div class="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                        <div class="flex justify-between items-start mb-2">
                            <span class="font-bold text-sm px-2 py-1 rounded-md border <?php echo $change_class; ?>">
                                <?php echo $is_entry ? '+' : ''; ?><?php echo $mov['change_amount']; ?>
                            </span>
                            <span class="text-xs text-slate-400"><?php echo date('d/m/Y H:i', strtotime($mov['created_at'])); ?></span>
                        </div>
                        <p class="text-sm font-medium text-slate-800 mb-2"><?php echo htmlspecialchars($mov['reason']); ?></p>
                        <div class="flex items-center justify-between pt-2 border-t border-slate-100 mt-2">
                            <div class="flex items-center gap-1.5 text-xs text-slate-500">
                                <i data-lucide="user" class="w-3 h-3"></i> <?php echo htmlspecialchars($mov['user_name'] ?? 'Sistema'); ?>
                            </div>
                            <div class="text-xs font-medium text-slate-600">
                                Saldo: <span class="font-bold text-slate-800"><?php echo $mov['new_quantity']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- PAGINAÇÃO -->
        <?php if ($total_pages > 1): ?>
        <div class="flex justify-center mt-8 border-t border-slate-100 pt-6">
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
                    $query_params['p'] = $i;
                    $active_class = ($i == $page) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50';
                    echo '<a href="?' . http_build_query($query_params) . '" class="px-3 py-1 border rounded text-sm ' . $active_class . '">' . $i . '</a>';
                }

                // Link Próximo
                if ($page < $total_pages) {
                    $query_params['p'] = $page + 1;
                    echo '<a href="?' . http_build_query($query_params) . '" class="px-3 py-1 border border-slate-200 rounded hover:bg-slate-50 text-slate-600 text-sm">Próximo</a>';
                }
                ?>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
</script>
<?php endif; // Fim do modo de detalhe ?>