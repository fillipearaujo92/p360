<?php
/**
 * Script de Teste do Sistema de Backup
 * Execute via navegador: http://localhost/assetmanager/public/test_backup.php
 * Ou via CLI: php test_backup.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🧪 Teste do Sistema de Backup</h1>";
echo "<hr>";

// 1. Verificar PHP
echo "<h2>1. Versão do PHP</h2>";
echo "Versão: " . phpversion() . "<br>";
echo (version_compare(phpversion(), '8.2.0', '>=') ? '✅' : '❌') . " PHP 8.2+ requerido<br>";
echo "<hr>";

// 2. Verificar Extensões
echo "<h2>2. Extensões PHP</h2>";
$extensions = ['pdo', 'pdo_pgsql', 'zip'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo ($loaded ? '✅' : '❌') . " {$ext}: " . ($loaded ? 'Instalado' : 'NÃO instalado') . "<br>";
}
echo "<hr>";

// 3. Verificar Diretório de Upload (onde será criado o backup)
echo "<h2>3. Diretório de Upload</h2>";
$upload_dir = __DIR__ . '/../uploads';
$upload_dir_real = realpath($upload_dir);
echo "Caminho: <code>{$upload_dir_real}</code><br>";
echo "Existe: " . (is_dir($upload_dir_real) ? '✅ SIM' : '❌ NÃO') . "<br>";
echo "Pode escrever: " . (is_writable($upload_dir_real) ? '✅ SIM' : '❌ NÃO') . "<br>";

// Teste de escrita
if ($upload_dir_real && is_dir($upload_dir_real)) {
    $test_file = $upload_dir_real . '/test_p360_' . uniqid() . '.txt';
    $write_test = @file_put_contents($test_file, 'teste');
    if ($write_test) {
        echo "✅ Teste de escrita: SUCESSO<br>";
        @unlink($test_file);
    } else {
        echo "❌ Teste de escrita: FALHA<br>";
        echo "<div style='background: #fef3c7; padding: 10px; margin: 10px 0; border-left: 4px solid #f59e0b;'>";
        echo "<strong>⚠️ Solução:</strong> Execute o comando:<br>";
        echo "<code>chmod -R 777 " . dirname(__FILE__) . "/../uploads</code><br>";
        echo "Ou execute o script: <code>./fix_permissions.sh</code>";
        echo "</div>";
    }
} else {
    echo "❌ Diretório não existe<br>";
    echo "<div style='background: #fef3c7; padding: 10px; margin: 10px 0; border-left: 4px solid #f59e0b;'>";
    echo "<strong>⚠️ Solução:</strong> Execute o comando:<br>";
    echo "<code>mkdir -p " . dirname(__FILE__) . "/../uploads && chmod 777 " . dirname(__FILE__) . "/../uploads</code>";
    echo "</div>";
}
echo "<hr>";

// 3b. Verificar Diretório Temporário do Sistema (referência)
echo "<h2>3b. Diretório Temporário do Sistema (Referência)</h2>";
$temp_dir = sys_get_temp_dir();
echo "Caminho: <code>{$temp_dir}</code><br>";
echo "Existe: " . (is_dir($temp_dir) ? '✅ SIM' : '❌ NÃO') . "<br>";
echo "Pode escrever: " . (is_writable($temp_dir) ? '✅ SIM' : '❌ NÃO') . "<br>";
echo "<small><em>Nota: Não usamos este diretório devido a restrições do macOS</em></small><br>";
echo "<hr>";

// 4. Teste de ZipArchive
echo "<h2>4. Teste do ZipArchive</h2>";
if (class_exists('ZipArchive')) {
    echo "✅ Classe ZipArchive existe<br>";
    
    $zip = new ZipArchive();
    $upload_dir_real = realpath(__DIR__ . '/../uploads');
    
    if ($upload_dir_real && is_writable($upload_dir_real)) {
        $zip_path = $upload_dir_real . '/test_p360_' . uniqid() . '.zip';
        
        $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result === TRUE) {
            echo "✅ Criação de ZIP: SUCESSO<br>";
            
            // Adicionar arquivo de teste
            $zip->addFromString('teste.txt', 'Conteúdo de teste');
            $zip->close();
            
            if (file_exists($zip_path)) {
                $size = filesize($zip_path);
                echo "✅ Arquivo ZIP criado ({$size} bytes)<br>";
                @unlink($zip_path);
                echo "✅ Arquivo ZIP removido<br>";
            } else {
                echo "❌ Arquivo ZIP não foi criado<br>";
            }
        } else {
            echo "❌ Falha ao criar ZIP (código: {$result})<br>";
            echo "<div style='background: #fee2e2; padding: 10px; margin: 10px 0; border-left: 4px solid #ef4444;'>";
            echo "<strong>Códigos de erro ZipArchive:</strong><br>";
            echo "5 = ZipArchive::ER_WRITE (Erro de escrita)<br>";
            echo "9 = ZipArchive::ER_NOENT (Arquivo não existe)<br>";
            echo "19 = ZipArchive::ER_TMPOPEN (Erro ao abrir arquivo temporário)<br>";
            echo "<br><strong>Solução:</strong> Verifique permissões da pasta uploads/";
            echo "</div>";
        }
    } else {
        echo "❌ Diretório uploads não tem permissão de escrita<br>";
    }
} else {
    echo "❌ Classe ZipArchive não encontrada<br>";
}
echo "<hr>";

// 5. Teste de Conexão PostgreSQL (opcional)
echo "<h2>5. Teste de Conexão PostgreSQL</h2>";
if (file_exists('config.php')) {
    try {
        require_once 'config.php';
        echo "✅ Arquivo config.php carregado<br>";
        
        if (isset($pdo)) {
            echo "✅ Conexão PDO estabelecida<br>";
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            echo "Driver: {$driver}<br>";
            
            // Testar query simples
            $result = $pdo->query("SELECT version()")->fetchColumn();
            echo "✅ Query executada com sucesso<br>";
            echo "PostgreSQL: " . substr($result, 0, 50) . "...<br>";
        } else {
            echo "❌ Variável \$pdo não existe<br>";
        }
    } catch (Exception $e) {
        echo "❌ Erro de conexão: " . $e->getMessage() . "<br>";
    }
} else {
    echo "⚠️ Arquivo config.php não encontrado (ignore se estiver testando isoladamente)<br>";
}
echo "<hr>";

// 6. Configurações do PHP
echo "<h2>6. Configurações do PHP (php.ini)</h2>";
$configs = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
];

foreach ($configs as $key => $value) {
    echo "{$key}: <strong>{$value}</strong><br>";
}

// Verificar se memory_limit é suficiente
$memory = ini_get('memory_limit');
$memory_bytes = 0;
if (preg_match('/^(\d+)(.)$/', $memory, $matches)) {
    $memory_bytes = $matches[1];
    if ($matches[2] == 'G') $memory_bytes *= 1024 * 1024 * 1024;
    elseif ($matches[2] == 'M') $memory_bytes *= 1024 * 1024;
    elseif ($matches[2] == 'K') $memory_bytes *= 1024;
}

$min_memory = 512 * 1024 * 1024; // 512MB
if ($memory_bytes >= $min_memory || $memory == -1) {
    echo "✅ Memory limit adequado<br>";
} else {
    echo "⚠️ Memory limit pode ser insuficiente (recomendado: 512M)<br>";
}

echo "<hr>";

// 7. Localizar php.ini
echo "<h2>7. Localização do php.ini</h2>";
echo "php.ini ativo: <code>" . php_ini_loaded_file() . "</code><br>";
$additional = php_ini_scanned_files();
if ($additional) {
    echo "Arquivos adicionais: <code>{$additional}</code><br>";
}
echo "<hr>";

// Resultado Final
echo "<h2>🎯 Resultado Final</h2>";
$upload_dir_ok = is_dir(realpath(__DIR__ . '/../uploads')) && is_writable(realpath(__DIR__ . '/../uploads'));
$all_ok = extension_loaded('zip') && 
          extension_loaded('pdo_pgsql') && 
          $upload_dir_ok &&
          class_exists('ZipArchive');

if ($all_ok) {
    echo "<div style='background: #10b981; color: white; padding: 20px; border-radius: 8px;'>";
    echo "<h3>✅ Sistema PRONTO para Backups!</h3>";
    echo "<p>Todas as verificações passaram. Você pode usar o sistema de backup normalmente.</p>";
    echo "</div>";
} else {
    echo "<div style='background: #ef4444; color: white; padding: 20px; border-radius: 8px;'>";
    echo "<h3>❌ Sistema NÃO está pronto</h3>";
    echo "<p>Corrija os erros acima antes de usar o sistema de backup.</p>";
    
    if (!$upload_dir_ok) {
        echo "<hr style='border-color: rgba(255,255,255,0.3); margin: 15px 0;'>";
        echo "<h4>🔧 Solução Rápida:</h4>";
        echo "<p>Execute no terminal:</p>";
        echo "<pre style='background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; overflow-x: auto;'>";
        echo "cd " . dirname(dirname(__FILE__)) . "\n";
        echo "chmod -R 777 uploads/\n";
        echo "# Ou execute: ./fix_permissions.sh";
        echo "</pre>";
    }
    
    echo "<p>Consulte o arquivo <strong>QUICK_FIX.md</strong> para mais soluções.</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Gerado em: " . date('d/m/Y H:i:s') . "</small></p>";
?>