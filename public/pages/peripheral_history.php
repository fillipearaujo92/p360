<?php
// pages/peripheral_history.php

$page_title = "Histórico de Movimentações";
$peripheral = null;
$movements = [];

// =================================================================================
// 1. VALIDAÇÃO E BUSCA DE DADOS
// =================================================================================

$peripheral_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$start_date = filter_input(INPUT_GET, 'start_date');
$end_date = filter_input(INPUT_GET, 'end_date');
$page = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
$items_per_page = 10;

if (!$page || $page < 1) $page = 1;

if ($peripheral_id) {
    $company_id = $_SESSION['user_company_id'] ?? 1;

    // Busca os detalhes do periférico
    $stmt = $pdo->prepare("
        SELECT p.*, l.name as location_name, a.name as asset_name
        FROM peripherals p 
        LEFT JOIN locations l ON p.location_id = l.id 
        LEFT JOIN assets a ON p.asset_id = a.id
        WHERE p.id = ? AND p.company_id = ?
    ");
    $stmt->execute([$peripheral_id, $company_id]);
    $peripheral = $stmt->fetch();

    // Se o periférico for encontrado, busca suas movimentações
    if ($peripheral) {
        // Filtros básicos
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

        // Contagem total para paginação
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM peripheral_movements pm WHERE $where_sql");
        $count_stmt->execute($params);
        $total_items = $count_stmt->fetchColumn();
        $total_pages = ceil($total_items / $items_per_page);
        $offset = ($page - 1) * $items_per_page;

        // Resumo de Entradas e Saídas
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

        // Busca paginada
        $query = "
            SELECT pm.*, u.name as user_name,
                   a_to.name as to_asset_name, a_to.code as to_asset_code,
                   a_from.name as from_asset_name, a_from.code as from_asset_code
            FROM peripheral_movements pm
            LEFT JOIN users u ON pm.user_id = u.id
            LEFT JOIN assets a_to ON pm.to_asset_id = a_to.id
            LEFT JOIN assets a_from ON pm.from_asset_id = a_from.id
            WHERE $where_sql
            ORDER BY pm.created_at DESC
            LIMIT $items_per_page OFFSET $offset
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $movements = $stmt->fetchAll();
    }
}

?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div class="flex items-center gap-3">
        <a href="index.php?page=peripherals" class="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-slate-800 transition-colors" title="Voltar">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <?php if ($peripheral): ?>
            <div>
                <h1 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($peripheral['name']); ?></h1>
                <div class="text-sm text-slate-500 flex items-center gap-x-3 gap-y-1 flex-wrap">
                    <span>SKU: <span class="font-mono"><?php echo htmlspecialchars($peripheral['sku'] ?: '-'); ?></span></span>
                    <span class="text-slate-300 hidden md:inline">|</span>
                    <span>Categoria: <?php echo htmlspecialchars($peripheral['category'] ?: '-'); ?></span>
                    <span class="text-slate-300 hidden md:inline">|</span>
                    <span>Designado a: 
                        <?php if (!empty($peripheral['asset_name'])): ?>
                            <a href="index.php?page=asset_details&id=<?php echo $peripheral['asset_id']; ?>" class="font-medium text-blue-600 hover:underline">
                                <?php echo htmlspecialchars($peripheral['asset_name']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-slate-400 italic">Não atribuído</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php else: ?>
            <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title; ?></h1>
        <?php endif; ?>
    </div>
    <?php if ($peripheral): ?>
        <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm text-center">
            <p class="text-xs font-bold text-slate-400 uppercase">Estoque Atual</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo $peripheral['quantity']; ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- FILTROS -->
<?php if ($peripheral): ?>
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 mb-6">
    <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
        <input type="hidden" name="page" value="peripheral_history">
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
                <a href="index.php?page=peripheral_history&id=<?php echo $peripheral_id; ?>" class="px-4 py-2 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors flex items-center gap-2">
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
<?php if ($peripheral): ?>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-green-600 uppercase">Total Entradas</p>
            <p class="text-2xl font-bold text-green-700">+<?php echo $total_entries; ?></p>
        </div>
        <div class="p-3 bg-green-100 rounded-lg text-green-600">
            <i data-lucide="arrow-down-left" class="w-6 h-6"></i>
        </div>
    </div>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center justify-between">
        <div>
            <p class="text-xs font-bold text-red-600 uppercase">Total Saídas</p>
            <p class="text-2xl font-bold text-red-700">-<?php echo $total_exits; ?></p>
        </div>
        <div class="p-3 bg-red-100 rounded-lg text-red-600">
            <i data-lucide="arrow-up-right" class="w-6 h-6"></i>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CONTEÚDO -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6">
    <?php if (!$peripheral): ?>
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
        <div class="space-y-8 border-l-2 border-slate-200 ml-4 pl-8">
            <?php foreach ($movements as $mov): 
                $is_entry = $mov['change_amount'] > 0;
                $change_class = $is_entry ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50';
                $icon = $is_entry ? 'plus' : 'minus';
            ?>
                <div class="relative">
                    <div class="absolute -left-[41px] top-1 w-6 h-6 bg-white border-2 border-blue-500 rounded-full flex items-center justify-center">
                        <i data-lucide="arrow-right-left" class="w-3 h-3 text-blue-500"></i>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div class="text-sm font-medium text-slate-800">
                            <?php echo htmlspecialchars($mov['reason']); ?>
                            <?php if(!empty($mov['to_asset_code'])): ?>
                                <span class="text-xs text-slate-500 ml-1 block sm:inline"> &rarr; <?php echo htmlspecialchars($mov['to_asset_name']); ?> (<?php echo htmlspecialchars($mov['to_asset_code']); ?>)</span>
                            <?php endif; ?>
                            <?php if(!empty($mov['from_asset_code'])): ?>
                                <span class="text-xs text-slate-500 ml-1 block sm:inline"> &larr; <?php echo htmlspecialchars($mov['from_asset_name']); ?> (<?php echo htmlspecialchars($mov['from_asset_code']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <span class="font-bold text-lg <?php echo $change_class; ?> px-2 py-1 rounded-md text-xs flex items-center gap-1 w-fit">
                            <i data-lucide="<?php echo $icon; ?>" class="w-3 h-3"></i> <?php echo abs($mov['change_amount']); ?>
                        </span>
                    </div>
                    <div class="text-xs text-slate-500 mt-1 flex items-center gap-x-3 gap-y-1 flex-wrap">
                        <span><i data-lucide="calendar" class="w-3 h-3 inline mr-1"></i><?php echo date('d/m/Y H:i', strtotime($mov['created_at'])); ?></span>
                        <span><i data-lucide="user" class="w-3 h-3 inline mr-1"></i><?php echo htmlspecialchars($mov['user_name'] ?? 'Sistema'); ?></span>
                        <span class="font-medium">Estoque Final: <span class="font-bold text-slate-700"><?php echo $mov['new_quantity']; ?></span></span>
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
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>