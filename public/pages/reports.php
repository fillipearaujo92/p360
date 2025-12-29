<?php
// pages/reports.php

// Parâmetros de Filtro
$report_type = $_GET['type'] ?? 'inventory';
$start_date = $_GET['start'] ?? date('Y-01-01');
$end_date = $_GET['end'] ?? date('Y-12-31');

$results = [];
$columns = [];
$total_general = 0;
$total_items = 0;
$total_pages = 1;
$report_title = "";
$subtitle = "";
$chart_labels = [];
$chart_data = [];

try {
    if ($report_type === 'inventory') {
        // --- RELATÓRIO 1: INVENTÁRIO FÍSICO ---
        $subtitle = "Listagem geral de ativos, localização e valores de aquisição";
        $columns = ['Código', 'Ativo', 'Categoria', 'Localização', 'Status', 'Valor Pago'];
        $report_title = "Inventário Patrimonial";
        
        // Contagem para paginação
        $sql = "SELECT a.code, a.name, c.name as category, l.name as location, a.status, a.value 
                FROM assets a 
                LEFT JOIN categories c ON a.category_id = c.id 
                LEFT JOIN locations l ON a.location_id = l.id 
                ORDER BY l.name ASC, a.name ASC";
        $query = $pdo->query($sql); // A query é simples, não precisa de prepare aqui
        
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                $row['code'],
                $row['name'],
                $row['category'] ?? '-',
                $row['location'] ?? 'Não alocado',
                ucfirst($row['status']),
                $row['value']
            ];
            $total_general += $row['value'];
        }

    } elseif ($report_type === 'financial') {
        // --- RELATÓRIO 2: FINANCEIRO (DEPRECIAÇÃO DINÂMICA) ---
        $subtitle = "Cálculo de valor atual baseado na vida útil definida para cada ativo";
        // Adicionada coluna 'Vida Útil'
        $report_title = "Relatório de Ativos Financeiros";
        $columns = ['Ativo', 'Data Aquisição', 'Vida Útil', 'Valor Original', 'Depreciação Acum.', 'Valor Atual', '% Vida Rest.'];

        // Agora buscamos também o lifespan_years e o code
        $sql = "SELECT name, code, acquisition_date, value, lifespan_years FROM assets WHERE value > 0 ORDER BY acquisition_date DESC";
        $query = $pdo->query($sql); // Query simples
        
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $val_original = floatval($row['value']);
            $acq_date = new DateTime($row['acquisition_date']);
            $now = new DateTime();
            
            // Vida útil (Se for nulo ou 0, assume 5 anos como padrão contábil)
            $lifespan_years = !empty($row['lifespan_years']) ? intval($row['lifespan_years']) : 5;
            $total_months_lifespan = $lifespan_years * 12;
            
            // Cálculo de meses passados
            $interval = $acq_date->diff($now);
            $months_passed = ($interval->y * 12) + $interval->m;
            
            // Cálculo Financeiro
            $depreciation_per_month = $val_original / ($total_months_lifespan ?: 1); // Evita divisão por zero
            $total_depreciation = $depreciation_per_month * $months_passed;
            
            // Trava para não depreciar mais que o valor (não fica negativo)
            if ($total_depreciation > $val_original) {
                $total_depreciation = $val_original;
            }
            
            $current_value = $val_original - $total_depreciation;
            
            // Porcentagem Restante
            $pct_remaining = 100 - (($months_passed / $total_months_lifespan) * 100);
            if ($pct_remaining < 0) $pct_remaining = 0;

            $results[] = [
                // Nome com Código pequeno embaixo
                $row['name'] . ' <div class="text-[10px] text-slate-400 font-mono">' . $row['code'] . '</div>',
                $acq_date->format('d/m/Y'),
                $lifespan_years . ' anos',
                $val_original,
                $total_depreciation,
                $current_value,
                // Badge colorida para %
                round($pct_remaining) // Apenas o número, a formatação será no HTML
            ];
            $total_general += $current_value; // Soma o valor ATUAL, não o original
        }
        
        // --- DADOS DO GRÁFICO (Evolução Acumulada) ---
        $sql_chart = "SELECT DATE_FORMAT(acquisition_date, '%Y-%m') as month_year, SUM(value) as monthly_total 
                      FROM assets 
                      WHERE value > 0 AND acquisition_date IS NOT NULL AND acquisition_date <= CURDATE()
                      GROUP BY month_year 
                      ORDER BY month_year ASC";
        $stmt_chart = $pdo->query($sql_chart);
        $cumulative = 0;
        while ($h = $stmt_chart->fetch(PDO::FETCH_ASSOC)) {
            $cumulative += $h['monthly_total'];
            $dt = DateTime::createFromFormat('Y-m', $h['month_year']);
            $chart_labels[] = $dt ? $dt->format('M/Y') : $h['month_year'];
            $chart_data[] = $cumulative;
        }

    } elseif ($report_type === 'maintenance') {
        // --- RELATÓRIO 3: MANUTENÇÃO ---
        $subtitle = "Visão geral do histórico operacional e volume de tickets por ativo";
        $report_title = "Relatório de Manutenção";
        $columns = ['Ativo', 'Total Chamados', 'Em Aberto', 'Concluídos', 'Próxima Preventiva'];
        
        // Adicionado next_maintenance_date na query
        $sql = "SELECT a.name, a.code, a.next_maintenance_date,
                COUNT(t.id) as total,
                SUM(CASE WHEN t.status != 'concluido' AND t.status != 'cancelado' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN t.status = 'concluido' THEN 1 ELSE 0 END) as closed
                FROM assets a
                LEFT JOIN tickets t ON a.id = t.asset_id
                GROUP BY a.id, a.name, a.code, a.next_maintenance_date
                ORDER BY total DESC, a.next_maintenance_date ASC";
        
        $query = $pdo->query($sql); // Query simples
        
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            // Formata data da preventiva
            $next_maint = '-';
            if ($row['next_maintenance_date']) {
                $d = new DateTime($row['next_maintenance_date']);
                $today = new DateTime();
                $color = $d < $today ? 'text-red-600 font-bold' : 'text-slate-600';
                $next_maint = '<span class="'.$color.'">' . $d->format('d/m/Y') . '</span>';
            }

            $results[] = [
                $row['name'] . ' <span class="text-xs text-slate-400">(' . $row['code'] . ')</span>',
                $row['total'],
                $row['open'] > 0 ? '<span class="text-red-600 font-bold">'.$row['open'].'</span>' : '0',
                $row['closed'],
                $next_maint
            ];
            $total_general += $row['total'];
        }
    } elseif ($report_type === 'low_stock') {
        // --- RELATÓRIO 4: ESTOQUE BAIXO DE PERIFÉRICOS ---
        $subtitle = "Itens consumíveis e periféricos que atingiram o nível mínimo de estoque";
        $report_title = "Relatório de Estoque Baixo";
        $columns = ['Item', 'Categoria', 'Local', 'Estoque Atual', 'Estoque Mínimo', 'Diferença'];
        
        $sql = "SELECT p.name, p.category, l.name as location_name, p.quantity, p.min_stock 
                FROM peripherals p 
                LEFT JOIN locations l ON p.location_id = l.id 
                WHERE p.quantity <= p.min_stock AND p.min_stock > 0
                ORDER BY p.name ASC";
        $query = $pdo->query($sql); // Query simples
        
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $difference = $row['min_stock'] - $row['quantity'];
            $results[] = [
                $row['name'],
                $row['category'] ?? '-',
                $row['location_name'] ?? 'Não alocado',
                '<span class="font-bold text-red-600">' . $row['quantity'] . '</span>',
                $row['min_stock'],
                '<span class="font-bold text-red-600">' . $difference . '</span>'
            ];
        }
    }
} catch (Exception $e) {
    $message = "Erro ao gerar relatório: " . $e->getMessage();
}


$total_items = count($results);
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4 no-print">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Relatórios Inteligentes</h1>
        <p class="text-sm text-slate-500">Dados estratégicos para tomada de decisão</p>
    </div>
    <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
        <i data-lucide="printer" class="w-4 h-4"></i> Imprimir / PDF
    </button>
</div>

<div class="bg-white p-1.5 rounded-xl border border-slate-200 flex flex-col md:flex-row gap-1.5 mb-6 no-print shadow-sm">
    <a href="index.php?page=reports&type=inventory" class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all <?php echo $report_type == 'inventory' ? 'bg-blue-50 text-blue-700 shadow-sm ring-1 ring-blue-100' : 'text-slate-500 hover:bg-slate-50'; ?>">
        <i data-lucide="package" class="w-4 h-4"></i> Inventário Físico
    </a>
    <a href="index.php?page=reports&type=financial" class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all <?php echo $report_type == 'financial' ? 'bg-green-50 text-green-700 shadow-sm ring-1 ring-green-100' : 'text-slate-500 hover:bg-slate-50'; ?>">
        <i data-lucide="dollar-sign" class="w-4 h-4"></i> Financeiro & Depreciação
    </a>
    <a href="index.php?page=reports&type=maintenance" class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all <?php echo $report_type == 'maintenance' ? 'bg-orange-50 text-orange-700 shadow-sm ring-1 ring-orange-100' : 'text-slate-500 hover:bg-slate-50'; ?>">
        <i data-lucide="wrench" class="w-4 h-4"></i> Manutenção & Preventivas
    </a>
    <a href="index.php?page=reports&type=low_stock" class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-bold transition-all <?php echo $report_type == 'low_stock' ? 'bg-red-50 text-red-700 shadow-sm ring-1 ring-red-100' : 'text-slate-500 hover:bg-slate-50'; ?>">
        <i data-lucide="archive" class="w-4 h-4"></i> Estoque Baixo
    </a>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden print:border-0 print:shadow-none print:rounded-none">
    
    <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row justify-between items-start md:items-center bg-slate-50/50 print:bg-white gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <h2 class="text-xl font-bold text-slate-900">
                    <?php echo $report_title; ?>
                </h2>
            </div>
            <p class="text-sm text-slate-500"><?php echo $subtitle; ?></p>
        </div>
        <div class="flex items-center gap-4 bg-white p-4 rounded-xl border border-slate-200 shadow-sm print:border-0 print:shadow-none w-full md:w-auto">
            <?php
                $total_label = 'Valor Total';
                $total_icon = 'dollar-sign';
                $total_value = 'R$ ' . number_format($total_general, 2, ',', '.');
                if ($report_type == 'maintenance') { $total_label = 'Total de Chamados'; $total_icon = 'tag'; $total_value = $total_general; }
                if ($report_type == 'low_stock') { $total_label = 'Itens com Estoque Baixo'; $total_icon = 'archive'; $total_value = $total_items; }
                if ($report_type == 'financial') { $total_label = 'Valor Atual Total'; }
            ?>
            <div class="p-3 bg-slate-100 text-slate-600 rounded-lg"><i data-lucide="<?php echo $total_icon; ?>" class="w-6 h-6"></i></div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase"><?php echo $total_label; ?></p>
                <p class="text-2xl font-bold text-slate-800"><?php echo $total_value; ?></p>
            </div>
        </div>
    </div>

    <?php if ($report_type === 'financial' && !empty($chart_data)): ?>
    <div class="p-6 border-b border-slate-100 bg-white print:break-inside-avoid">
        <h3 class="text-sm font-bold text-slate-700 mb-4 flex items-center gap-2">
            <i data-lucide="trending-up" class="w-4 h-4 text-green-600"></i> Evolução do Patrimônio (Valor de Aquisição Acumulado)
        </h3>
        <div class="relative h-72 w-full">
            <canvas id="financialChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <div class="p-4 border-b border-slate-100 no-print">
        <div class="relative">
            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
            <input type="text" id="reportSearch" onkeyup="filterReport()" placeholder="Filtrar resultados..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-xs tracking-wider">
                <tr>
                    <?php foreach($columns as $col): ?>
                        <th class="p-4 whitespace-nowrap"><?php echo $col; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" id="reportTableBody">
                <?php if(empty($results)): ?>
                    <tr>
                        <td colspan="<?php echo count($columns); ?>" class="p-12 text-center text-slate-400 italic">
                            Nenhum registro encontrado neste período.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($results as $row): ?>
                    <tr class="report-row hover:bg-slate-50 transition-colors print:hover:bg-white" data-search="<?php echo htmlspecialchars(strtolower(implode(' ', $row))); ?>">
                        <?php foreach($row as $idx => $cell): ?>
                            <td class="p-4 whitespace-nowrap text-slate-700">
                                <?php 
                                    if ($report_type === 'financial' && $idx === 6) { // Coluna % Vida Rest.
                                        $pct = (int)$cell;
                                        $color_class = $pct < 20 ? 'bg-red-500' : ($pct < 50 ? 'bg-yellow-500' : 'bg-green-500');
                                        echo '<div class="w-24 bg-slate-200 rounded-full h-2.5"><div class="'.$color_class.' h-2.5 rounded-full" style="width: '.$pct.'%"></div></div> <span class="ml-2 font-bold text-xs">'.$pct.'%</span>';
                                    } elseif (is_numeric($cell) && in_array($columns[$idx], ['Valor Pago', 'Valor Original', 'Depreciação Acum.', 'Valor Atual'])) {
                                        echo 'R$ ' . number_format($cell, 2, ',', '.');
                                    } else {
                                        echo $cell;
                                    }
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div id="paginationToolbar" class="flex flex-col md:flex-row justify-between items-center p-4 bg-white border-t border-slate-200 gap-4 no-print">
        <div class="flex items-center gap-2 text-sm text-slate-600">
            <span>Mostrar</span>
            <select id="itemsPerPage" onchange="changeItemsPerPage()" class="border border-slate-300 rounded-lg p-1.5 bg-white outline-none focus:border-blue-500 w-20">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">Todos</option>
            </select>
            <span>por página</span>
        </div>
        <div class="text-sm text-slate-500 font-medium">
            Mostrando <span id="pageInfoStart">0</span> - <span id="pageInfoEnd">0</span> de <span id="pageInfoTotal">0</span> registros
        </div>
        <div class="flex items-center gap-1">
            <button onclick="changePage(-1)" id="btnPrevPage" class="p-2 border bg-white rounded-lg hover:bg-slate-50 disabled:opacity-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></button>
            <span class="px-4 py-2 bg-slate-50 border rounded-lg text-sm font-bold text-slate-700" id="pageIndicator">1</span>
            <button onclick="changePage(1)" id="btnNextPage" class="p-2 border bg-white rounded-lg hover:bg-slate-50 disabled:opacity-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></button>
        </div>
    </div>

    <div class="p-4 border-t border-slate-200 bg-slate-50 text-[10px] text-slate-400 flex justify-between items-center print:bg-white">
        <span>Documento gerado em <?php echo date('d/m/Y \à\s H:i'); ?></span>
        <span>Sistema Patrimônio 360º &copy; <?php echo date('Y'); ?></span>
    </div>
</div>

<script>
    let currentPage = 1;
    let itemsPerPage = parseInt(localStorage.getItem('reportItemsPerPage') || '10');
    let filteredItems = [];

    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('itemsPerPage').value = itemsPerPage;
        filterReport(); // Inicializa a paginação e o filtro
    });
    
    <?php if ($report_type === 'financial' && !empty($chart_data)): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const ctx = document.getElementById('financialChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Valor Acumulado (R$)',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: (ctx) => 'R$ ' + ctx.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2}) } }
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: (val) => 'R$ ' + val.toLocaleString('pt-BR', {notation: 'compact'}) } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } } }
                }
            });
        }
    });
    <?php endif; ?>

    function filterReport() {
        const searchTerm = document.getElementById('reportSearch').value.toLowerCase();
        const allRows = Array.from(document.querySelectorAll('#reportTableBody .report-row'));
        
        filteredItems = allRows.filter(row => {
            const searchData = row.getAttribute('data-search');
            const isVisible = searchData.includes(searchTerm);
            row.style.display = 'none'; // Oculta todas por padrão
            return isVisible;
        });
        currentPage = 1;
        renderPagination();
    }

    function changeItemsPerPage() {
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1;
        renderPagination();
    }

    function changePage(direction) {
        const limit = itemsPerPage === -1 ? filteredItems.length : itemsPerPage;
        const totalPages = Math.ceil(filteredItems.length / limit) || 1;
        const newPage = currentPage + direction;
        if (newPage >= 1 && newPage <= totalPages) {
            currentPage = newPage;
            renderPagination();
        }
    }

    function renderPagination() {
        const totalItems = filteredItems.length;
        const limit = itemsPerPage === -1 ? totalItems : itemsPerPage;
        const totalPages = Math.ceil(totalItems / limit) || 1;
        if (currentPage > totalPages) currentPage = totalPages;

        const startIdx = (currentPage - 1) * limit;
        const endIdx = startIdx + limit;

        // Esconde todas as linhas e mostra apenas as da página atual
        filteredItems.forEach(row => row.style.display = 'none');
        filteredItems.slice(startIdx, endIdx).forEach(item => item.style.display = '');

        document.getElementById('pageInfoStart').innerText = totalItems === 0 ? 0 : startIdx + 1;
        document.getElementById('pageInfoEnd').innerText = Math.min(endIdx, totalItems);
        document.getElementById('pageInfoTotal').innerText = totalItems;
        document.getElementById('pageIndicator').innerText = currentPage;
        document.getElementById('btnPrevPage').disabled = currentPage === 1;
        document.getElementById('btnNextPage').disabled = currentPage === totalPages;

        localStorage.setItem('reportItemsPerPage', itemsPerPage);
    }

    lucide.createIcons();
</script>

<style>
    @media print {
        @page { size: landscape; margin: 1cm; }
        .no-print { display: none !important; }
        body { background: white; -webkit-print-color-adjust: exact; }
        #sidebar, #mobile-overlay { display: none; }
        main { margin: 0; padding: 0; overflow: visible; height: auto; }
        .shadow-sm, .shadow-md, .shadow-lg, .shadow-xl { box-shadow: none !important; }
        .border { border-color: #eee !important; }
        .bg-slate-50 { background-color: #f8fafc !important; } 
    }
</style>