<?php
// pages/idea_stats.php

// Apenas Admin e Gestor podem ver esta página
if (!in_array($_SESSION['user_role'], ['admin', 'gestor'])) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4' role='alert'><p class='font-bold'>Acesso Negado</p><p>Você não tem permissão para visualizar esta página.</p></div>";
    return;
}

// ==========================================================
// CONSULTA DE DADOS
// ==========================================================
$stats = [
    'pendente' => 0,
    'aprovado' => 0,
    'rejeitado' => 0,
    'total' => 0
];


try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM ideas GROUP BY status");
    $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats['pendente'] = $results['pendente'] ?? 0;
    $stats['aprovado'] = $results['aprovado'] ?? 0;
    $stats['rejeitado'] = $results['rejeitado'] ?? 0;
    $stats['total'] = array_sum($stats);

} catch (PDOException $e) {
    // Erro ao buscar dados
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4' role='alert'><p class='font-bold'>Erro de Banco de Dados</p><p>Não foi possível carregar as estatísticas.</p></div>";
}

// Preparar dados para o gráfico
$chart_labels = json_encode(['Aprovadas', 'Rejeitadas', 'Pendentes']);
$chart_data = json_encode([$stats['aprovado'], $stats['rejeitado'], $stats['pendente']]);

?>

<div class="space-y-8">
    <!-- Cabeçalho -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i data-lucide="bar-chart-2" class="w-6 h-6 text-blue-500"></i>
                Estatísticas do Canal de Ideias
            </h1>
            <p class="text-slate-500 text-sm mt-1">Visão geral das sugestões enviadas pelos colaboradores.</p>
        </div>
        <a href="index.php?page=ideas" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Voltar para Ideias
        </a>
    </div>

    <!-- Cards de KPI -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5">
            <div class="p-3 bg-blue-50 text-blue-600 rounded-xl"><i data-lucide="lightbulb" class="w-7 h-7"></i></div>
            <div>
                <p class="text-sm font-bold text-slate-400 uppercase">Total de Ideias</p>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $stats['total']; ?></h3>
            </div>
        </div>
        <!-- Aprovadas -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5">
            <div class="p-3 bg-green-50 text-green-600 rounded-xl"><i data-lucide="check-circle-2" class="w-7 h-7"></i></div>
            <div>
                <p class="text-sm font-bold text-slate-400 uppercase">Aprovadas</p>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $stats['aprovado']; ?></h3>
            </div>
        </div>
        <!-- Rejeitadas -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5">
            <div class="p-3 bg-red-50 text-red-600 rounded-xl"><i data-lucide="x-circle" class="w-7 h-7"></i></div>
            <div>
                <p class="text-sm font-bold text-slate-400 uppercase">Rejeitadas</p>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $stats['rejeitado']; ?></h3>
            </div>
        </div>
        <!-- Pendentes -->
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex items-center gap-5">
            <div class="p-3 bg-yellow-50 text-yellow-600 rounded-xl"><i data-lucide="clock" class="w-7 h-7"></i></div>
            <div>
                <p class="text-sm font-bold text-slate-400 uppercase">Pendentes</p>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $stats['pendente']; ?></h3>
            </div>
        </div>
    </div>

    <!-- Gráfico -->
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <h3 class="text-lg font-bold text-slate-800 mb-6">Distribuição por Status</h3>
        <div class="relative h-80 w-full max-w-2xl mx-auto">
            <canvas id="ideasChart"></canvas>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('ideasChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $chart_labels; ?>,
                datasets: [{
                    label: 'Quantidade de Ideias',
                    data: <?php echo $chart_data; ?>,
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)', // green-500
                        'rgba(239, 68, 68, 0.8)',  // red-500
                        'rgba(245, 158, 11, 0.8)'  // amber-500
                    ],
                    borderColor: [
                        'rgba(16, 185, 129, 1)',
                        'rgba(239, 68, 68, 1)',
                        'rgba(245, 158, 11, 1)'
                    ],
                    borderWidth: 1,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>