<?php
// pages/dashboard.php

$total_assets = 0;
$total_value = 0;
$total_maintenance = 0;
$movements_month = 0;
$status_data = [];
$category_data = [];
$recent_activities = [];
$maintenance_alerts = [];
$warranty_alerts = [];
$peripherals_data = [];
$total_accessories = 0;
$companies_data = [];

try {
    // 1. KPIs (Indicadores)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM assets");
    $total_assets = $stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT SUM(value) as total_value FROM assets");
    $total_value = $stmt->fetch()['total_value'] ?: 0;

    $stmt = $pdo->query("SELECT COUNT(*) as maintenance FROM assets WHERE status = 'manutencao' OR status = 'em_manutencao'");
    $total_maintenance = $stmt->fetch()['maintenance'];

    // ✅ CORREÇÃO: MONTH() e YEAR() para PostgreSQL
    $stmt = $pdo->query("
        SELECT COUNT(*) as movements FROM movements 
        WHERE EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE) 
        AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)
    "); 
    $movements_month = $stmt->fetch()['movements'];

    // 2. Gráficos
    $stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM assets GROUP BY status");
    $status_data = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT c.name, COUNT(a.id) as qtd FROM assets a JOIN categories c ON a.category_id = c.id GROUP BY c.name ORDER BY qtd DESC LIMIT 5");
    $category_data = $stmt->fetchAll();

    // 3. Listas
    $stmt = $pdo->query("
        SELECT m.*, a.name as asset_name, u.name as user_name 
        FROM movements m 
        JOIN assets a ON m.asset_id = a.id 
        LEFT JOIN users u ON m.user_id = u.id 
        ORDER BY m.created_at DESC LIMIT 5
    ");
    $recent_activities = $stmt->fetchAll();

    // ✅ CORREÇÃO: DATEDIFF e INTERVAL para PostgreSQL
    $stmt = $pdo->query("
        SELECT id, name, code, next_maintenance_date, maintenance_freq,
               (next_maintenance_date - CURRENT_DATE) as days_remaining
        FROM assets 
        WHERE next_maintenance_date IS NOT NULL 
          AND status != 'baixado'
          AND next_maintenance_date <= (CURRENT_DATE + INTERVAL '30 days')
        ORDER BY next_maintenance_date ASC
        LIMIT 5
    ");
    $maintenance_alerts = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT name, code, warranty_date FROM assets WHERE warranty_date >= CURRENT_DATE ORDER BY warranty_date ASC LIMIT 5");
    $warranty_alerts = $stmt->fetchAll();

    // 4. Periféricos e Acessórios
    $stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM asset_peripherals GROUP BY status");
    $peripherals_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $stmt = $pdo->query("SELECT SUM(quantity) as total FROM peripherals");
    $total_accessories = $stmt->fetch()['total'] ?: 0;

    // 5. Empresas
    $stmt = $pdo->query("SELECT c.id, c.name, COUNT(a.id) as asset_count, SUM(a.value) as total_value FROM companies c LEFT JOIN assets a ON c.id = a.company_id GROUP BY c.id, c.name ORDER BY c.name ASC");
    $companies_data = $stmt->fetchAll();

} catch (PDOException $e) {
    echo "<div class='bg-red-50 text-red-600 p-4 rounded-lg mb-4 border border-red-200 flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> Erro ao carregar dados do dashboard: " . $e->getMessage() . "</div>";
}

// JSON para Charts
$js_status_labels = json_encode(array_map(function($i) { return ucfirst($i['status']); }, $status_data));
$js_status_values = json_encode(array_map(function($i) { return $i['qtd']; }, $status_data));
$js_cat_labels = json_encode(array_map(function($i) { return $i['name']; }, $category_data));
$js_cat_values = json_encode(array_map(function($i) { return $i['qtd']; }, $category_data));
?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Visão Geral</h1>
        <p class="text-sm text-slate-500">Bem-vindo ao painel de controle do Patrimônio 360º.</p>
    </div>
    <div class="flex items-center gap-3">
        <span class="text-xs font-medium text-slate-500 bg-white border px-3 py-1.5 rounded-full shadow-sm">
            <i data-lucide="calendar" class="w-3 h-3 inline mr-1"></i> <?php echo date('d/m/Y'); ?>
        </span>
        <a href="index.php?page=profile" class="p-2 bg-white border rounded-lg text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-colors shadow-sm" title="Meu Perfil">
            <i data-lucide="user" class="w-4 h-4"></i>
        </a>
        <button onclick="location.reload()" class="p-2 bg-white border rounded-lg text-slate-500 hover:text-blue-600 hover:border-blue-200 transition-colors shadow-sm" title="Atualizar">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
        </button>
    </div>
</div>

<!-- ATALHOS RÁPIDOS -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
    <a href="index.php?page=assets" class="flex items-center justify-center gap-2 bg-white border border-slate-200 p-3 rounded-xl text-sm font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-600 hover:border-blue-200 transition-all shadow-sm group">
        <div class="p-1.5 bg-slate-100 rounded-lg group-hover:bg-blue-100 transition-colors"><i data-lucide="box" class="w-4 h-4"></i></div>
        Gerenciar Ativos
    </a>
    <a href="index.php?page=tickets" class="flex items-center justify-center gap-2 bg-white border border-slate-200 p-3 rounded-xl text-sm font-medium text-slate-600 hover:bg-orange-50 hover:text-orange-600 hover:border-orange-200 transition-all shadow-sm group">
        <div class="p-1.5 bg-slate-100 rounded-lg group-hover:bg-orange-100 transition-colors"><i data-lucide="alert-circle" class="w-4 h-4"></i></div>
        Chamados / OS
    </a>
    <a href="index.php?page=peripherals" class="flex items-center justify-center gap-2 bg-white border border-slate-200 p-3 rounded-xl text-sm font-medium text-slate-600 hover:bg-green-50 hover:text-green-600 hover:border-green-200 transition-all shadow-sm group">
        <div class="p-1.5 bg-slate-100 rounded-lg group-hover:bg-green-100 transition-colors"><i data-lucide="package" class="w-4 h-4"></i></div>
        Estoque
    </a>
    <a href="index.php?page=reports" class="flex items-center justify-center gap-2 bg-white border border-slate-200 p-3 rounded-xl text-sm font-medium text-slate-600 hover:bg-purple-50 hover:text-purple-600 hover:border-purple-200 transition-all shadow-sm group">
        <div class="p-1.5 bg-slate-100 rounded-lg group-hover:bg-purple-100 transition-colors"><i data-lucide="bar-chart-2" class="w-4 h-4"></i></div>
        Relatórios
    </a>
</div>

<!-- SEÇÃO EMPRESAS -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-800">Empresas</h2>
        <?php if(in_array($_SESSION['user_role'] ?? '', ['admin'])): ?>
        <a href="index.php?page=companies" class="text-sm text-blue-600 hover:underline font-medium">Gerenciar</a>
        <?php endif; ?>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <?php foreach($companies_data as $comp): ?>
        <a href="index.php?page=assets&search=<?php echo urlencode($comp['name']); ?>" class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all group relative overflow-hidden">
            <div class="flex justify-between items-start mb-2 relative z-10">
                <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-600 group-hover:text-white transition-colors"><i data-lucide="building-2" class="w-5 h-5"></i></div>
                <span class="text-xs font-bold bg-slate-100 text-slate-600 px-2 py-1 rounded-full border border-slate-200"><?php echo $comp['asset_count']; ?> ativos</span>
            </div>
            <h3 class="font-bold text-slate-800 text-lg truncate relative z-10 group-hover:text-blue-700 transition-colors"><?php echo htmlspecialchars($comp['name']); ?></h3>
            <p class="text-xs text-slate-500 mt-1 relative z-10">Valor: R$ <?php echo number_format($comp['total_value'] ?? 0, 2, ',', '.'); ?></p>
            <i data-lucide="building" class="absolute -right-4 -bottom-4 w-20 h-20 text-slate-50 group-hover:text-blue-50 transition-colors z-0"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- SEÇÃO 1: CARDS DE KPI -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <!-- Card 1 -->
    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] hover:shadow-md transition-shadow relative overflow-hidden">
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-2 bg-blue-50 rounded-lg text-blue-600"><i data-lucide="box" class="w-6 h-6"></i></div>
            <span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded-full border border-green-100">Ativos</span>
        </div>
        <h3 class="text-2xl font-bold text-slate-800 relative z-10"><?php echo $total_assets; ?></h3>
        <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mt-1 relative z-10">Total Registrado</p>
        <i data-lucide="box" class="absolute -right-4 -bottom-4 w-24 h-24 text-slate-50 opacity-50 z-0"></i>
    </div>

    <!-- Card 2 -->
    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] hover:shadow-md transition-shadow relative overflow-hidden">
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-2 bg-emerald-50 rounded-lg text-emerald-600"><i data-lucide="dollar-sign" class="w-6 h-6"></i></div>
        </div>
        <h3 class="text-2xl font-bold text-slate-800 relative z-10">R$ <?php echo number_format($total_value, 2, ',', '.'); ?></h3>
        <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mt-1 relative z-10">Valor Patrimonial</p>
        <i data-lucide="dollar-sign" class="absolute -right-4 -bottom-4 w-24 h-24 text-slate-50 opacity-50 z-0"></i>
    </div>

    <!-- Card 3 -->
    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] hover:shadow-md transition-shadow relative overflow-hidden">
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-2 bg-orange-50 rounded-lg text-orange-600"><i data-lucide="wrench" class="w-6 h-6"></i></div>
            <?php if($total_maintenance > 0): ?>
                <span class="flex h-2 w-2 relative"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-orange-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-orange-500"></span></span>
            <?php endif; ?>
        </div>
        <h3 class="text-2xl font-bold text-slate-800 relative z-10"><?php echo $total_maintenance; ?></h3>
        <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mt-1 relative z-10">Em Manutenção</p>
        <i data-lucide="wrench" class="absolute -right-4 -bottom-4 w-24 h-24 text-slate-50 opacity-50 z-0"></i>
    </div>

    <!-- Card 4 -->
    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.1)] hover:shadow-md transition-shadow relative overflow-hidden">
        <div class="flex justify-between items-start mb-4 relative z-10">
            <div class="p-2 bg-purple-50 rounded-lg text-purple-600"><i data-lucide="arrow-left-right" class="w-6 h-6"></i></div>
            <span class="text-xs text-slate-400">Este mês</span>
        </div>
        <h3 class="text-2xl font-bold text-slate-800 relative z-10"><?php echo $movements_month; ?></h3>
        <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mt-1 relative z-10">Movimentações</p>
        <i data-lucide="activity" class="absolute -right-4 -bottom-4 w-24 h-24 text-slate-50 opacity-50 z-0"></i>
    </div>
</div>

<!-- SEÇÃO PERIFÉRICOS (REESTRUTURADA) -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-8">
    <!-- Acessórios em Estoque (Destaque) -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-700 p-6 rounded-xl shadow-lg text-white flex flex-col justify-between relative overflow-hidden group">
        <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
            <i data-lucide="package" class="w-32 h-32"></i>
        </div>
        <div class="relative z-10">
            <p class="text-blue-100 text-xs font-bold uppercase tracking-wider mb-2">Estoque de Consumíveis</p>
            <h3 class="text-4xl font-bold"><?php echo $total_accessories; ?></h3>
            <p class="text-xs text-blue-200 mt-1">Itens disponíveis</p>
        </div>
        <a href="index.php?page=peripherals" class="mt-6 inline-flex items-center text-xs font-bold text-blue-100 hover:text-white transition-colors relative z-10">
            Gerenciar Estoque <i data-lucide="arrow-right" class="w-3 h-3 ml-1"></i>
        </a>
    </div>

    <!-- Status dos Componentes (Resumo) -->
    <div class="lg:col-span-3 bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-slate-100">
        <div class="flex-1 p-5 flex items-center gap-4">
            <div class="p-3 bg-green-50 rounded-full text-green-600"><i data-lucide="cpu" class="w-6 h-6"></i></div>
            <div>
                <p class="text-xs text-slate-400 uppercase font-bold">Instalados</p>
                <p class="text-2xl font-bold text-slate-700"><?php echo $peripherals_data['Instalado'] ?? 0; ?></p>
            </div>
        </div>
        <div class="flex-1 p-5 flex items-center gap-4">
            <div class="p-3 bg-blue-50 rounded-full text-blue-600"><i data-lucide="layers" class="w-6 h-6"></i></div>
            <div>
                <p class="text-xs text-slate-400 uppercase font-bold">Em Reserva</p>
                <p class="text-2xl font-bold text-slate-700"><?php echo $peripherals_data['Reserva'] ?? 0; ?></p>
            </div>
        </div>
        <div class="flex-1 p-5 flex items-center gap-4">
            <div class="p-3 bg-red-50 rounded-full text-red-600"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
            <div>
                <p class="text-xs text-slate-400 uppercase font-bold">Com Defeito</p>
                <p class="text-2xl font-bold text-slate-700"><?php echo $peripherals_data['Defeito'] ?? 0; ?></p>
            </div>
        </div>
        <div class="p-5 flex items-center justify-center bg-slate-50/50">
             <a href="index.php?page=assets&tab=components" class="text-sm font-medium text-blue-600 hover:text-blue-800 flex items-center gap-2">
                Ver Detalhes <i data-lucide="chevron-right" class="w-4 h-4"></i>
             </a>
        </div>
    </div>
</div>

<!-- SEÇÃO 2: GRÁFICOS -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Gráfico de Status (Rosca) -->
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm lg:col-span-1 flex flex-col">
        <h3 class="text-sm font-bold text-slate-800 mb-6 flex items-center gap-2"><i data-lucide="pie-chart" class="w-4 h-4 text-slate-400"></i> Distribuição por Status</h3>
        <div class="flex-1 relative min-h-[220px]">
            <canvas id="chartStatus"></canvas>
        </div>
    </div>
    
    <!-- Gráfico de Categorias (Barras) -->
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm lg:col-span-2 flex flex-col">
        <h3 class="text-sm font-bold text-slate-800 mb-6 flex items-center gap-2"><i data-lucide="bar-chart-3" class="w-4 h-4 text-slate-400"></i> Top Categorias (Quantidade)</h3>
        <div class="flex-1 relative min-h-[220px]">
            <canvas id="chartCategory"></canvas>
        </div>
    </div>
</div>

<!-- SEÇÃO 3: LISTAS DETALHADAS -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    
    <!-- Movimentações Recentes -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-full">
        <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
            <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2"><i data-lucide="history" class="w-4 h-4 text-blue-500"></i> Atividades Recentes</h3>
            <a href="index.php?page=movements" class="text-xs text-blue-600 hover:underline font-medium">Ver todas</a>
        </div>
        <div class="divide-y divide-slate-50">
            <?php if(empty($recent_activities)): ?>
                <div class="p-8 text-center text-slate-400 text-sm">Nenhuma atividade registrada recentemente.</div>
            <?php else: ?>
                <?php foreach($recent_activities as $act): ?>
                <div class="p-4 flex items-start gap-3 hover:bg-slate-50 transition-colors">
                    <div class="mt-1 shrink-0">
                        <?php if($act['type'] == 'local'): ?>
                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"><i data-lucide="map-pin" class="w-4 h-4"></i></div>
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center text-purple-600"><i data-lucide="refresh-cw" class="w-4 h-4"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-800 truncate">
                            <span class="font-bold"><?php echo htmlspecialchars($act['asset_name']); ?></span>
                        </p>
                        <p class="text-xs text-slate-500 truncate"><?php echo htmlspecialchars($act['description']); ?></p>
                        <p class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                            <i data-lucide="user" class="w-3 h-3"></i> <?php echo htmlspecialchars($act['user_name'] ?? 'Sistema'); ?> 
                            &bull; <?php echo date('d/m H:i', strtotime($act['created_at'])); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alertas de Manutenção e Garantia -->
    <div class="space-y-6">
        <!-- Manutenção -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-indigo-500"></i> Atenção: Manutenções
                </h3>
                <?php if(count($maintenance_alerts) > 0): ?>
                    <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-1 rounded-full animate-pulse">
                        <?php echo count($maintenance_alerts); ?> pendentes
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="divide-y divide-slate-50 flex-1 overflow-y-auto max-h-[250px]">
                <?php if(empty($maintenance_alerts)): ?>
                    <div class="p-8 text-center text-slate-400 text-sm flex flex-col items-center">
                        <div class="bg-green-50 p-3 rounded-full mb-2"><i data-lucide="check-check" class="w-5 h-5 text-green-500"></i></div>
                        Tudo em dia! Nenhuma preventiva próxima.
                    </div>
                <?php else: ?>
                    <?php foreach($maintenance_alerts as $maint): 
                        $days = $maint['days_remaining'];
                        // Lógica de Cores
                        if ($days < 0) {
                            $status_color = "bg-red-50 text-red-700 border-red-100";
                            $status_text = "Vencido há " . abs($days) . " dias";
                            $icon = "alert-triangle";
                        } elseif ($days == 0) {
                            $status_color = "bg-orange-50 text-orange-700 border-orange-100";
                            $status_text = "Vence Hoje!";
                            $icon = "clock";
                        } else {
                            $status_color = "bg-blue-50 text-blue-700 border-blue-100";
                            $status_text = "Em $days dias";
                            $icon = "calendar";
                        }
                    ?>
                    <div class="p-4 hover:bg-slate-50 transition-colors flex items-center justify-between group">
                        <div class="flex items-center gap-3">
                            <div class="flex flex-col items-center justify-center w-10 h-10 rounded-lg bg-slate-100 text-slate-500 font-bold text-[10px] uppercase border border-slate-200">
                                <span><?php echo date('M', strtotime($maint['next_maintenance_date'])); ?></span>
                                <span class="text-sm text-slate-800"><?php echo date('d', strtotime($maint['next_maintenance_date'])); ?></span>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($maint['name']); ?></p>
                                <p class="text-xs text-slate-500 font-mono"><?php echo htmlspecialchars($maint['code']); ?></p>
                            </div>
                        </div>
                        
                        <div class="text-right">
                            <span class="px-2 py-1 rounded text-[10px] font-bold border flex items-center gap-1 w-fit ml-auto <?php echo $status_color; ?>">
                                <i data-lucide="<?php echo $icon; ?>" class="w-3 h-3"></i> <?php echo $status_text; ?>
                            </span>
                            <a href="index.php?page=tickets&action=new&asset_id=<?php echo $maint['id']; ?>&type=preventiva" class="text-[10px] text-indigo-600 hover:underline mt-1 block opacity-0 group-hover:opacity-100 transition-opacity">
                                Abrir Chamado &rarr;
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Garantias -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2"><i data-lucide="shield-alert" class="w-4 h-4 text-orange-500"></i> Garantias Próximas</h3>
            </div>
            <div class="divide-y divide-slate-50 max-h-[200px] overflow-y-auto">
                <?php if(empty($warranty_alerts)): ?>
                    <div class="p-8 text-center text-slate-400 text-sm">Nenhuma garantia próxima do vencimento.</div>
                <?php else: ?>
                    <?php foreach($warranty_alerts as $w): 
                        $days = (new DateTime($w['warranty_date']))->diff(new DateTime())->days;
                        $color = $days < 30 ? 'text-red-600 bg-red-50' : 'text-orange-600 bg-orange-50';
                    ?>
                    <div class="p-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-500 font-bold text-xs">
                                <?php echo strtoupper(substr($w['name'], 0, 1)); ?>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($w['name']); ?></p>
                                <p class="text-xs text-slate-500 font-mono"><?php echo htmlspecialchars($w['code']); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs font-bold px-2 py-1 rounded <?php echo $color; ?>">
                                Vence em <?php echo date('d/m/Y', strtotime($w['warranty_date'])); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
    // Configurações de Cores do Tema
    const themeColors = {
        primary: '#2563eb',   // Blue 600
        success: '#10b981',   // Emerald 500
        warning: '#f59e0b',   // Amber 500
        danger: '#ef4444',    // Red 500
        info: '#3b82f6',      // Blue 500
        slate: '#cbd5e1'      // Slate 300
    };

    // 1. Gráfico de Status (Doughnut)
    const ctxStatus = document.getElementById('chartStatus');
    if (ctxStatus) {
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: <?php echo $js_status_labels; ?>,
                datasets: [{
                    data: <?php echo $js_status_values; ?>,
                    backgroundColor: [themeColors.success, themeColors.warning, themeColors.danger, themeColors.slate, themeColors.info],
                    borderWidth: 0,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 15, font: { size: 11, family: "'Inter', sans-serif" } }
                    }
                }
            }
        });
    }

    // 2. Gráfico de Categorias (Bar)
    const ctxCat = document.getElementById('chartCategory');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'bar',
            data: {
                labels: <?php echo $js_cat_labels; ?>,
                datasets: [{
                    label: 'Quantidade',
                    data: <?php echo $js_cat_values; ?>,
                    backgroundColor: themeColors.primary,
                    borderRadius: 4,
                    barThickness: 24,
                    maxBarThickness: 40
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false, drawBorder: false }, ticks: { font: { size: 10 } } },
                    y: { grid: { display: false, drawBorder: false }, ticks: { font: { size: 11, weight: '500' } } }
                }
            }
        });
    }
    
    if(typeof lucide !== 'undefined') lucide.createIcons();
</script>