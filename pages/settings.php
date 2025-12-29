<?php
// pages/settings.php

$message = '';

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'backup_system') {
    try {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $backup_name = 'backup_assetmanager_' . date('Y-m-d_H-i-s');
        $zip_filename = $backup_name . '.zip';
        $sql_content = "-- Backup AssetManager\n-- Data: " . date('Y-m-d H:i:s') . "\n\n";

        // 1. Backup do Banco de Dados
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
            $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql_content .= $create_table[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $sql_content .= "INSERT INTO `$table` VALUES(";
                $values = array_map(function ($value) use ($pdo) {
                    return $value === null ? "NULL" : $pdo->quote($value);
                }, array_values($row));
                $sql_content .= implode(", ", $values);
                $sql_content .= ");\n";
            }
            $sql_content .= "\n";
        }

        // 2. Criar ZIP
        $zip = new ZipArchive();
        $zip_path = dirname(__DIR__) . '/' . $zip_filename;

        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            // Adiciona SQL
            $zip->addFromString('database.sql', $sql_content);

            // Adiciona Arquivos
            $rootPath = dirname(__DIR__);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                // Ignora git, node_modules, e o próprio zip se estiver sendo criado lá
                if (strpos($filePath, '.git') !== false) continue;
                
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
            $zip->close();

            // LOG BACKUP
            try {
                if (isset($_SESSION['user_id'])) {
                    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, 'backup', 'Backup completo do sistema realizado', ?)");
                    $stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
                }
            } catch (Exception $e) { /* Ignora erro de log se tabela não existir */ }

            // Download
            while (ob_get_level()) ob_end_clean();
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($zip_path));
            readfile($zip_path);
            unlink($zip_path);
            exit;
        }
    } catch (Exception $e) { $message = "Erro no backup: " . $e->getMessage(); }
}

?>

<!-- HEADER -->
<div class="flex justify-between items-center mb-8">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Configurações do Sistema</h1>
        <p class="text-sm text-slate-500">Gerencie backups e manutenção</p>
    </div>
</div>

<!-- FEEDBACK -->
<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div>
        <div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => { const el = document.getElementById('alertMessage'); if(el) el.remove(); }, 4000);</script>
<?php endif; ?>

<div class="max-w-2xl mx-auto">
    <!-- Backup -->
    <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
            <i data-lucide="database" class="w-5 h-5 text-slate-400"></i> Backup do Sistema
        </h3>
        <p class="text-sm text-slate-500 mb-6">
            Gere um arquivo ZIP contendo todo o código fonte, uploads e um dump SQL do banco de dados atual.
            <br>Recomendado realizar periodicamente.
        </p>
        <button type="submit" form="formBackup" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-colors flex items-center justify-center gap-2 shadow-lg shadow-blue-200">
            <i data-lucide="download" class="w-4 h-4"></i> Baixar Backup Completo
        </button>
    </div>
</div>

<form id="formBackup" method="POST" class="hidden">
    <input type="hidden" name="action" value="backup_system">
</form>

<script>
    lucide.createIcons();
</script>