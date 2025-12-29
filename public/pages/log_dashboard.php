<?php
// pages/log_dashboard.php

// Apenas Admin
if ($_SESSION['user_role'] !== 'admin') {
    echo "<div class='p-4 text-red-600 bg-red-50 rounded-lg border border-red-200'>Acesso negado. Apenas administradores podem visualizar o dashboard.</div>";
    return;
}

// =================================================================================
// 1. CONSULTAS DE DADOS
// =================================================================================
$kpi_total_logs = 0;
$kpi_most_active_user = ['name' => '-', 'count' => 0];
$kpi_most_common_action = ['action' => '-', 'count' => 0];
$actions_chart_data = [];
$users_chart_data = [];
$activity_by_hour = array_fill(0, 24, 0); // Inicializa um array com 24 zeros

try {
    // KPIs
    $kpi_total_logs = $pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();

    $stmt_user = $pdo->query("
        SELECT u.name, COUNT(l.id) as count 
        FROM system_logs l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.user_id IS NOT NULL 
        GROUP BY u.name 
        ORDER BY count DESC 
        LIMIT 1
    ");
    if ($stmt_user) $kpi_most_active_user = $stmt_user->fetch() ?: $kpi_most_active_user;

    $stmt_action = $pdo->query("
        SELECT action, COUNT(*) as count 
        FROM system_logs 
        GROUP BY action 
        ORDER BY count DESC 
        LIMIT 1
    ");
    if ($stmt_action) $kpi_most_common_action = $stmt_action->fetch() ?: $kpi_most_common_action;

    // Gráfico de Ações (Doughnut)
    $actions_chart_data = $pdo->query("
        SELECT action, COUNT(*) as count 
        FROM system_logs 
        GROUP BY action 
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Gráfico de Usuários (Bar)
    $users_chart_data = $pdo->query("
        SELECT u.name, COUNT(l.id) as count 
        FROM system_logs l 
        JOIN users u ON l.user_id = u.id 
        WHERE l.user_id IS NOT NULL 
        GROUP BY u.name 
        ORDER BY count DESC 
        LIMIT 7
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Mapa de Calor de Atividades (por hora)
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hour_expr = $driver === 'pgsql' ? "EXTRACT(HOUR FROM created_at)" : "HOUR(created_at)";

    $stmt_activity = $pdo->query("
        SELECT $hour_expr as hour, COUNT(*) as count 
        FROM system_logs 
        GROUP BY $hour_expr
    ");
    while ($row = $stmt_activity->fetch()) {
        $activity_by_hour[(int)$row['hour']] = (int)$row['count'];
    }

} catch (Exception $e) {
    echo "<div class='p-4 text-red-600 bg-red-50 rounded-lg border border-red-200'>Erro ao carregar dados: " . $e->getMessage() . "</div>";
}

// Preparar dados para JS
$js_actions_labels = json_encode(array_keys($actions_chart_data));
$js_actions_values = json_encode(array_values($actions_chart_data));
$js_users_labels = json_encode(array_keys($users_chart_data));
$js_users_values = json_encode(array_values($users_chart_data));
$js_activity_data = json_encode(array_values($activity_by_hour));

?>

<div class="space-y-8">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i data-lucide="bar-chart-big" class="w-6 h-6 text-blue-500"></i>
                Dashboard de Logs
            </h1>
            <p class="text-slate-500 text-sm mt-1">Análise visual das atividades do sistema.</p>
        </div>
        <a href="index.php?page=logs" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
            <i data-lucide="scroll-text" class="w-4 h-4"></i> Ver Logs Detalhados
        </a>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5"><div class="p-3 bg-blue-50 text-blue-600 rounded-xl"><i data-lucide="database" class="w-7 h-7"></i></div><div><p class="text-sm font-bold text-slate-400 uppercase">Total de Registros</p><h3 class="text-3xl font-bold text-slate-800"><?php echo number_format($kpi_total_logs); ?></h3></div></div>
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5"><div class="p-3 bg-green-50 text-green-600 rounded-xl"><i data-lucide="user-check" class="w-7 h-7"></i></div><div><p class="text-sm font-bold text-slate-400 uppercase">Usuário Mais Ativo</p><h3 class="text-2xl font-bold text-slate-800 truncate"><?php echo htmlspecialchars($kpi_most_active_user['name']); ?></h3></div></div>
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5"><div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl"><i data-lucide="activity" class="w-7 h-7"></i></div><div><p class="text-sm font-bold text-slate-400 uppercase">Ação Mais Comum</p><h3 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($kpi_most_common_action['action']); ?></h3></div></div>
    </div>

    <!-- Gráficos -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm lg:col-span-1 flex flex-col"><h3 class="text-sm font-bold text-slate-800 mb-6 flex items-center gap-2"><i data-lucide="pie-chart" class="w-4 h-4 text-slate-400"></i> Ações por Tipo</h3><div class="flex-1 relative min-h-[250px]"><canvas id="actionsChart"></canvas></div></div>
        <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm lg:col-span-2 flex flex-col"><h3 class="text-sm font-bold text-slate-800 mb-6 flex items-center gap-2"><i data-lucide="users" class="w-4 h-4 text-slate-400"></i> Top 7 Usuários por Atividade</h3><div class="flex-1 relative min-h-[250px]"><canvas id="usersChart"></canvas></div></div>
    </div>

    <!-- Mapa de Calor de Atividade -->
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <h3 class="text-sm font-bold text-slate-800 mb-6 flex items-center gap-2"><i data-lucide="clock" class="w-4 h-4 text-slate-400"></i> Atividade por Hora do Dia (UTC)</h3>
        <div class="grid grid-cols-12 md:grid-cols-24 gap-1">
            <?php
                $max_activity = max($activity_by_hour) ?: 1;
                foreach ($activity_by_hour as $hour => $count):
                    $opacity = ($count / $max_activity);
                    $color_class = 'bg-blue-600';
            ?>
            <div class="relative group">
                <div class="w-full h-10 rounded <?php echo $color_class; ?>" style="opacity: <?php echo max(0.1, $opacity); ?>"></div>
                <div class="absolute -top-10 left-1/2 -translate-x-1/2 bg-slate-800 text-white text-xs px-2 py-1 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                    <?php echo str_pad($hour, 2, '0', STR_PAD_LEFT) . 'h: ' . $count . ' logs'; ?>
                    <div class="absolute top-full left-1/2 -translate-x-1/2 w-0 h-0 border-x-4 border-x-transparent border-t-4 border-t-slate-800"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-between text-xs text-slate-400 mt-2"><span>00h</span><span>06h</span><span>12h</span><span>18h</span><span>23h</span></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const actionsCtx = document.getElementById('actionsChart');
    if (actionsCtx) {
        new Chart(actionsCtx, {
            type: 'doughnut',
            data: { labels: <?php echo $js_actions_labels; ?>, datasets: [{ data: <?php echo $js_actions_values; ?>, backgroundColor: ['#3b82f6', '#10b981', '#ef4444', '#f59e0b', '#8b5cf6', '#ec4899', '#64748b'], borderWidth: 0, hoverOffset: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
    const usersCtx = document.getElementById('usersChart');
    if (usersCtx) {
        new Chart(usersCtx, {
            type: 'bar',
            data: { labels: <?php echo $js_users_labels; ?>, datasets: [{ label: 'Nº de Ações', data: <?php echo $js_users_values; ?>, backgroundColor: 'rgba(59, 130, 246, 0.7)', borderColor: 'rgba(59, 130, 246, 1)', borderWidth: 1, borderRadius: 4 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
        });
    }
});
</script>