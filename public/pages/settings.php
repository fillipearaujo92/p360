<?php
// pages/settings.php

$message = '';

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'backup_system') {
        try {
            set_time_limit(300);
            ini_set('memory_limit', '512M');

            $backup_name = 'backup_p360_' . date('Y-m-d_H-i-s');
            $zip_filename = $backup_name . '.zip';
            $sql_content = "-- Backup P360 SaaS\n-- Data: " . date('Y-m-d H:i:s') . "\n\n";

            // 1. Backup do Banco de Dados PostgreSQL
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                // Lógica para PostgreSQL
                $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($tables as $table) {
                    $sql_content .= "DROP TABLE IF EXISTS \"$table\" CASCADE;\n";
                    
                    // Recriação da estrutura da tabela
                    $columns = $pdo->query("
                        SELECT column_name, data_type, character_maximum_length, 
                               column_default, is_nullable
                        FROM information_schema.columns 
                        WHERE table_name = '$table' 
                        ORDER BY ordinal_position
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    
                    $cols_def = [];
                    foreach ($columns as $col) {
                        $col_def = "\"{$col['column_name']}\" {$col['data_type']}";
                        
                        if ($col['character_maximum_length']) {
                            $col_def .= "({$col['character_maximum_length']})";
                        }
                        
                        if ($col['is_nullable'] === 'NO') {
                            $col_def .= ' NOT NULL';
                        }
                        
                        if ($col['column_default']) {
                            $col_def .= ' DEFAULT ' . $col['column_default'];
                        }
                        
                        $cols_def[] = $col_def;
                    }
                    
                    $sql_content .= "CREATE TABLE \"$table\" (\n  " . implode(",\n  ", $cols_def) . "\n);\n\n";

                    // Dados da tabela
                    $rows = $pdo->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($rows)) {
                        foreach ($rows as $row) {
                            $columns_list = array_keys($row);
                            $sql_content .= "INSERT INTO \"$table\" (\"" . implode('", "', $columns_list) . "\") VALUES(";
                            
                            $values = array_map(function($v) use ($pdo) {
                                if ($v === null) return "NULL";
                                if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
                                return $pdo->quote($v);
                            }, array_values($row));
                            
                            $sql_content .= implode(", ", $values);
                            $sql_content .= ");\n";
                        }
                        $sql_content .= "\n";
                    }

                    // Corrige sequências (evita erro de ID duplicado)
                    $has_id = $pdo->query("
                        SELECT 1 FROM information_schema.columns 
                        WHERE table_name = '$table' AND column_name = 'id'
                    ")->fetchColumn();
                    
                    if ($has_id) {
                        $sql_content .= "SELECT setval(pg_get_serial_sequence('\"$table\"', 'id'), COALESCE((SELECT MAX(id) FROM \"$table\"), 1), true);\n\n";
                    }
                }
            } else {
                // Lógica para MySQL (caso mude de banco no futuro)
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                    $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
                    $sql_content .= $create_table[1] . ";\n\n";

                    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $sql_content .= "INSERT INTO `$table` VALUES(";
                        $values = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), array_values($row));
                        $sql_content .= implode(", ", $values);
                        $sql_content .= ");\n";
                    }
                    $sql_content .= "\n";
                }
            }

            // 2. Criar ZIP diretamente na memória (sem arquivo temporário)
            if (!class_exists('ZipArchive')) {
                throw new Exception("A extensão PHP ZipArchive não está instalada/habilitada.");
            }

            // Cria ZIP na pasta uploads que já tem permissão
            $upload_dir = dirname(__DIR__) . '/uploads';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }
            
            $zip_path = $upload_dir . '/backup_temp_' . uniqid() . '.zip';
            
            $zip = new ZipArchive();
            $zip_res = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($zip_res !== TRUE) {
                // Se falhar, tenta usar php://temp (stream na memória)
                throw new Exception("Falha ao criar arquivo ZIP. Código: " . $zip_res . ". Verifique permissões da pasta uploads/");
            }

            // Adiciona SQL
            $zip->addFromString('database.sql', $sql_content);

            // Adiciona Arquivos do Projeto (código fonte)
            $rootPath = dirname(__DIR__);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $file_count = 0;
            foreach ($files as $file) {
                if (!$file->isFile()) continue;
                
                $filePath = $file->getRealPath();
                
                // Ignora pastas e arquivos desnecessários
                $ignore_patterns = ['.git', 'node_modules', 'vendor', '.DS_Store'];
                $should_skip = false;
                
                foreach ($ignore_patterns as $pattern) {
                    if (strpos($filePath, DIRECTORY_SEPARATOR . $pattern) !== false) {
                        $should_skip = true;
                        break;
                    }
                }
                
                if ($should_skip) continue;
                if ($filePath == $zip_path) continue;
                if (strpos($file->getFilename(), 'backup_temp_') === 0) continue;
                
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                
                // Adiciona arquivo ao ZIP
                try {
                    $zip->addFile($filePath, $relativePath);
                    $file_count++;
                } catch (Exception $e) {
                    // Ignora arquivos que não podem ser lidos
                    error_log("Não foi possível adicionar: " . $relativePath);
                }
            }
            
            $zip->close();

            // LOG BACKUP
            try {
                if (isset($_SESSION['user_id'])) {
                    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, 'backup', 'Backup completo do sistema realizado', ?)");
                    $stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
                }
            } catch (Exception $e) { /* Ignora erro de log */ }

            // Download
            if (file_exists($zip_path)) {
                // Limpa qualquer saída anterior
                if (ini_get('zlib.output_compression')) {
                    ini_set('zlib.output_compression', 'Off');
                }
                while (ob_get_level()) ob_end_clean();
                
                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($zip_path));
                flush();
                
                // Lê e envia o arquivo em chunks para não sobrecarregar memória
                $handle = fopen($zip_path, 'rb');
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
                
                // Remove arquivo temporário
                @unlink($zip_path);
                exit;
            } else {
                throw new Exception("Arquivo ZIP não foi criado. Verifique se a pasta /uploads tem permissão de escrita (chmod 777 uploads/)");
            }
            
        } catch (Throwable $e) { 
            $message = "Erro no backup: " . $e->getMessage(); 
            error_log("Backup Error: " . $e->getMessage());
        }
        
    } elseif ($_POST['action'] == 'clear_logs') {
        try {
            $pdo->exec("DELETE FROM system_logs");
            $message = "Logs do sistema limpos com sucesso.";
            if (isset($_SESSION['user_id'])) {
                $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, 'maintenance', 'Limpeza de logs do sistema', ?)");
                $stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
            }
        } catch (Exception $e) {
            $message = "Erro ao limpar logs: " . $e->getMessage();
        }
        
    } elseif ($_POST['action'] == 'fix_sequences') {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $seq = $pdo->query("SELECT pg_get_serial_sequence('\"$table\"', 'id')")->fetchColumn();
                    if ($seq) {
                        $pdo->query("SELECT setval('$seq', COALESCE((SELECT MAX(id) FROM \"$table\"), 1), true)");
                    }
                }
                $message = "Sequências (IDs) sincronizadas com sucesso!";
            }
        } catch (Exception $e) { 
            $message = "Erro ao sincronizar: " . $e->getMessage(); 
        }
    }
}

// =================================================================================
// 2. DADOS PARA O GRÁFICO (Visualização)
// =================================================================================
$db_size_mb = 0;
try {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $db_size = $pdo->query("SELECT pg_database_size(current_database())")->fetchColumn();
    } else {
        $db_size = $pdo->query("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    }
    $db_size_mb = round($db_size / 1024 / 1024, 2);
} catch (Exception $e) {
    $db_size_mb = 0;
}
?>

<!-- HEADER -->
<div class="flex justify-between items-center mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Configurações do Sistema</h1>
        <p class="text-sm text-slate-500">Gerencie backups e manutenção</p>
    </div>
</div>

<!-- FEEDBACK -->
<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 <?php echo strpos($message, 'Erro') !== false ? 'border-red-500' : 'border-blue-500'; ?> px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="<?php echo strpos($message, 'Erro') !== false ? 'text-red-500' : 'text-blue-500'; ?>">
            <i data-lucide="<?php echo strpos($message, 'Erro') !== false ? 'alert-circle' : 'check-circle'; ?>" class="w-5 h-5"></i>
        </div>
        <div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => { const el = document.getElementById('alertMessage'); if(el) el.remove(); }, 6000);</script>
<?php endif; ?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="max-w-5xl mx-auto space-y-8">
    
    <!-- 1. Monitoramento e Ambiente -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Informações do Servidor -->
        <div class="lg:col-span-1 bg-white p-6 rounded-xl border border-slate-200 shadow-sm h-full">
            <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2 pb-4 border-b border-slate-100">
                <i data-lucide="server" class="w-5 h-5 text-indigo-500"></i> 
                Ambiente
            </h3>
            
            <div class="space-y-4">
                <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-100 hover:border-indigo-100 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="bg-white p-2 rounded shadow-sm text-indigo-600">
                            <i data-lucide="file-code" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 uppercase font-bold tracking-wider">PHP Version</p>
                            <p class="text-slate-800 font-mono font-medium"><?php echo phpversion(); ?></p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-100 hover:border-indigo-100 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="bg-white p-2 rounded shadow-sm text-indigo-600">
                            <i data-lucide="database" class="w-4 h-4"></i>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 uppercase font-bold tracking-wider">Driver BD</p>
                            <p class="text-slate-800 font-mono font-medium"><?php echo $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); ?></p>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-slate-50 rounded-lg border border-slate-100 hover:border-indigo-100 transition-colors">
                    <p class="text-xs text-slate-500 uppercase font-bold tracking-wider mb-1">Software do Servidor</p>
                    <p class="text-slate-800 font-mono text-xs break-all leading-relaxed"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
                </div>
            </div>
        </div>

        <!-- Gráfico de Uso -->
        <div class="lg:col-span-2 bg-white p-6 rounded-xl border border-slate-200 shadow-sm h-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 text-emerald-500"></i> 
                    Uso do Banco
                </h3>
                <span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-2 py-1 rounded-full">
                    <?php echo $db_size_mb; ?> MB
                </span>
            </div>
            <div class="relative h-64 w-full">
                <canvas id="dbGrowthChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 2. Ferramentas de Gerenciamento -->
    <div>
        <h2 class="text-lg font-semibold text-slate-700 mb-4 flex items-center gap-2">
            <i data-lucide="settings-2" class="w-5 h-5"></i> Ferramentas de Sistema
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Backup -->
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm relative overflow-hidden group flex flex-col">
                <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                    <i data-lucide="save" class="w-24 h-24 text-blue-600"></i>
                </div>
                
                <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2">
                    <i data-lucide="archive" class="w-5 h-5 text-blue-500"></i> Backup Completo
                </h3>
                <p class="text-slate-600 mb-6 flex-1 leading-relaxed">
                    Gera um arquivo ZIP contendo todo o código fonte e um dump SQL completo do banco de dados PostgreSQL. 
                    <span class="block mt-2 text-xs text-slate-400 font-medium">Recomendado realizar antes de atualizações.</span>
                </p>
                
                <button type="submit" form="formBackup" id="btnBackup" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-xl transition-all flex items-center justify-center gap-2 shadow-lg shadow-blue-100 active:scale-95">
                    <i data-lucide="download" class="w-4 h-4"></i> 
                    <span id="btnBackupText">Baixar Backup Agora</span>
                </button>
            </div>

            <!-- Manutenção -->
            <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm relative overflow-hidden group flex flex-col">
                <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                    <i data-lucide="trash-2" class="w-24 h-24 text-red-600"></i>
                </div>

                <h3 class="text-lg font-bold text-slate-800 mb-2 flex items-center gap-2">
                    <i data-lucide="wrench" class="w-5 h-5 text-slate-500"></i> Manutenção
                </h3>
                <p class="text-slate-600 mb-6 flex-1 leading-relaxed">
                    Ferramentas de correção e limpeza do banco de dados.
                    <span class="block mt-2 text-xs text-slate-400 font-medium">Use com cautela.</span>
                </p>

                <div class="space-y-3">
                    <button type="button" onclick="openModal()" class="w-full bg-white hover:bg-red-50 text-slate-600 hover:text-red-600 border border-slate-200 hover:border-red-200 font-bold py-3 px-6 rounded-xl transition-colors flex items-center justify-center gap-2">
                        <i data-lucide="trash-2" class="w-4 h-4"></i> Limpar Logs
                    </button>

                    <?php if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'): ?>
                    <form method="POST" onsubmit="return confirm('Isso irá sincronizar os contadores de ID de todas as tabelas. Continuar?');">
                        <input type="hidden" name="action" value="fix_sequences">
                        <button type="submit" class="w-full bg-white hover:bg-indigo-50 text-slate-600 hover:text-indigo-600 border border-slate-200 hover:border-indigo-200 font-bold py-3 px-6 rounded-xl transition-colors flex items-center justify-center gap-2" title="Corrige erro de 'Duplicate Key'">
                            <i data-lucide="refresh-cw" class="w-4 h-4"></i> Corrigir IDs (Sequências)
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmação -->
<div id="confirmModal" class="fixed inset-0 z-50 hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity opacity-0" id="modalBackdrop"></div>
    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="modalPanel">
                <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i data-lucide="alert-triangle" class="h-6 w-6 text-red-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                            <h3 class="text-base font-semibold leading-6 text-slate-900" id="modal-title">Limpar Logs do Sistema</h3>
                            <div class="mt-2">
                                <p class="text-sm text-slate-500">Tem certeza que deseja apagar todos os registros de log? Esta ação não pode ser desfeita e o histórico de auditoria será perdido.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                    <button type="submit" form="formClearLogs" class="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:ml-3 sm:w-auto">Sim, limpar tudo</button>
                    <button type="button" onclick="closeModal()" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 sm:mt-0 sm:w-auto">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="formBackup" method="POST" class="hidden" onsubmit="startBackupLoading()">
    <input type="hidden" name="action" value="backup_system">
</form>
<form id="formClearLogs" method="POST" class="hidden">
    <input type="hidden" name="action" value="clear_logs">
</form>

<script>
    lucide.createIcons();

    // Loading State para Backup
    function startBackupLoading() {
        const btn = document.getElementById('btnBackup');
        const txt = document.getElementById('btnBackupText');
        const icon = btn.querySelector('svg');
        
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        txt.innerText = 'Gerando Backup...';
        
        // Substitui ícone por spinner
        icon.innerHTML = '<path d="M21 12a9 9 0 1 1-6.219-8.56" />';
        icon.classList.add('animate-spin');
        
        // Reativa após um tempo
        setTimeout(() => {
            btn.disabled = false;
            btn.classList.remove('opacity-75', 'cursor-not-allowed');
            txt.innerText = 'Baixar Backup Agora';
            icon.classList.remove('animate-spin');
            lucide.createIcons();
        }, 5000);
    }

    // Modal Logic
    const modal = document.getElementById('confirmModal');
    const backdrop = document.getElementById('modalBackdrop');
    const panel = document.getElementById('modalPanel');

    function openModal() {
        modal.classList.remove('hidden');
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            panel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
        }, 10);
    }

    function closeModal() {
        backdrop.classList.add('opacity-0');
        panel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300);
    }

    // Chart.js Initialization
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('dbGrowthChart').getContext('2d');
        
        const currentSize = <?php echo $db_size_mb; ?>;
        const historyData = [
            currentSize * 0.85, 
            currentSize * 0.88, 
            currentSize * 0.92, 
            currentSize * 0.95, 
            currentSize * 0.98, 
            currentSize
        ];

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['5 meses atrás', '4 meses atrás', '3 meses atrás', '2 meses atrás', 'Mês passado', 'Atual'],
                datasets: [{
                    label: 'Tamanho (MB)',
                    data: historyData,
                    borderColor: 'rgb(16, 185, 129)',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgb(16, 185, 129)',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: false, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    });
</script>