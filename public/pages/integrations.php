<?php
// Patrimônio 360º - Página de Integrações com AledDesk
// Esta página vai no sistema Patrimônio 360º

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


$company_id = $_SESSION['user_company_id'];

// Auto-setup: Criar tabela settings se não existir para evitar erro 1146
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `company_id` int(11) NOT NULL,
      `key` varchar(50) NOT NULL,
      `value` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_setting` (`company_id`,`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    error_log("Erro ao criar tabela settings: " . $e->getMessage());
}

// Buscar configurações de integração com AledDesk
try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_token'");
    $stmt->execute([$company_id]);
    $aleddesk_token = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_url'");
    $stmt->execute([$company_id]);
    $aleddesk_url = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_webhook'");
    $stmt->execute([$company_id]);
    $aleddesk_webhook = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_enabled'");
    $stmt->execute([$company_id]);
    $aleddesk_enabled = $stmt->fetchColumn() == '1';
    
} catch (PDOException $e) {
    error_log("Erro ao buscar configurações: " . $e->getMessage());
    $aleddesk_token = $aleddesk_url = $aleddesk_webhook = '';
    $aleddesk_enabled = false;
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'save_integration') {
        try {
            $token = trim($_POST['aleddesk_token'] ?? '');
            $url = trim($_POST['aleddesk_url'] ?? '');
            $webhook = trim($_POST['aleddesk_webhook'] ?? '');
            $enabled = isset($_POST['aleddesk_enabled']) ? '1' : '0';
            
            // Salvar token
            $stmt = $pdo->prepare("
                INSERT INTO settings (company_id, `key`, value) 
                VALUES (?, 'aleddesk_token', ?)
                ON DUPLICATE KEY UPDATE value = ?
            ");
            $stmt->execute([$company_id, $token, $token]);
            
            // Salvar URL
            $stmt = $pdo->prepare("
                INSERT INTO settings (company_id, `key`, value) 
                VALUES (?, 'aleddesk_url', ?)
                ON DUPLICATE KEY UPDATE value = ?
            ");
            $stmt->execute([$company_id, $url, $url]);
            
            // Salvar Webhook
            $stmt = $pdo->prepare("
                INSERT INTO settings (company_id, `key`, value) 
                VALUES (?, 'aleddesk_webhook', ?)
                ON DUPLICATE KEY UPDATE value = ?
            ");
            $stmt->execute([$company_id, $webhook, $webhook]);
            
            // Salvar status
            $stmt = $pdo->prepare("
                INSERT INTO settings (company_id, `key`, value) 
                VALUES (?, 'aleddesk_enabled', ?)
                ON DUPLICATE KEY UPDATE value = ?
            ");
            $stmt->execute([$company_id, $enabled, $enabled]);
            
            $_SESSION['message'] = "Configurações salvas com sucesso!";
            
            // Testar conexão
            if ($enabled && !empty($token) && !empty($url)) {
                $test_result = testAledDeskConnection($url, $token);
                if ($test_result['success']) {
                    $_SESSION['message'] .= " Conexão testada com sucesso!";
                } else {
                    $_SESSION['message'] .= " Aviso: Não foi possível conectar ao AledDesk.";
                }
            }
            
            header("Location: index.php?page=integrations");
            exit;
            
        } catch (PDOException $e) {
            $_SESSION['message'] = "Erro ao salvar configurações: " . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'test_connection') {
        $token = $_POST['aleddesk_token'] ?? '';
        $url = $_POST['aleddesk_url'] ?? '';
        
        $result = testAledDeskConnection($url, $token);
        
        // Limpar qualquer saída HTML anterior (do index.php) para garantir que apenas o JSON seja enviado
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

// Função para testar conexão com AledDesk
function testAledDeskConnection($url, $token) {
    if (empty($url) || empty($token)) {
        return ['success' => false, 'message' => 'URL e Token são obrigatórios'];
    }
    
    $ch = curl_init($url . '/api.php/stats');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        return ['success' => true, 'message' => 'Conexão estabelecida com sucesso!', 'data' => $data];
    } else {
        return ['success' => false, 'message' => 'Erro ao conectar. Código: ' . $http_code];
    }
}

// Recarregar dados após POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_integration') {
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_token'");
        $stmt->execute([$company_id]);
        $aleddesk_token = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_url'");
        $stmt->execute([$company_id]);
        $aleddesk_url = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_webhook'");
        $stmt->execute([$company_id]);
        $aleddesk_webhook = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE company_id = ? AND `key` = 'aleddesk_enabled'");
        $stmt->execute([$company_id]);
        $aleddesk_enabled = $stmt->fetchColumn() == '1';
    } catch (PDOException $e) {
        error_log("Erro ao recarregar configurações: " . $e->getMessage());
    }
}
?>

<!-- Cabeçalho -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-slate-800">Integrações</h1>
    <p class="text-slate-600 mt-1">Gerencie a conexão com sistemas externos.</p>
</div>

<!-- Card Principal da Integração -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
    <div class="p-6">
        <div class="flex items-start gap-4">
            <!-- Ícone -->
            <div class="bg-blue-100 p-4 rounded-xl">
                <i data-lucide="headset" class="w-8 h-8 text-blue-600"></i>
            </div>
            
            <!-- Info -->
            <div class="flex-1">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-xl font-bold text-slate-800">AledDesk - Help Desk</h2>
                    <?php if ($aleddesk_enabled): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                            Ativo
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                            <span class="w-2 h-2 bg-slate-400 rounded-full mr-2"></span>
                            Inativo
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-slate-600">Integração nativa para sincronização de ativos e tickets.</p>
            </div>
        </div>
    </div>
</div>

<!-- Configurações da Integração -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="p-6 border-b border-slate-200">
        <h3 class="text-lg font-semibold text-slate-800">Habilitar Integração</h3>
        <p class="text-sm text-slate-600 mt-1">Permite que o AledDesk acesse dados de ativos via API.</p>
    </div>
    
    <form method="POST" id="integration-form" class="p-6">
        <input type="hidden" name="action" value="save_integration">
        
        <!-- Toggle de Ativação -->
        <div class="flex items-center justify-between p-4 bg-slate-50 rounded-lg mb-6">
            <div>
                <p class="font-medium text-slate-800">Ativar Integração</p>
                <p class="text-sm text-slate-600">Habilite para permitir comunicação entre sistemas</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" name="aleddesk_enabled" class="sr-only peer" <?php echo $aleddesk_enabled ? 'checked' : ''; ?>>
                <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
            </label>
        </div>
        
        <!-- Chave de API (Token) -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-slate-700 mb-2">
                <i data-lucide="key" class="w-4 h-4 inline mr-1"></i>
                Chave de API (Token)
            </label>
            <div class="flex gap-2">
                <input 
                    type="text" 
                    name="aleddesk_token"
                    id="aleddesk-token"
                    value="<?php echo htmlspecialchars($aleddesk_token); ?>"
                    placeholder="Cole aqui o token gerado no AledDesk"
                    class="flex-1 px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent font-mono text-sm"
                >
                <button 
                    type="button"
                    onclick="pasteToken()"
                    class="px-4 py-3 bg-slate-600 hover:bg-slate-700 text-white rounded-lg transition-colors"
                    title="Colar"
                >
                    <i data-lucide="clipboard" class="w-5 h-5"></i>
                </button>
            </div>
            <p class="text-xs text-slate-500 mt-2">Copie esta chave do painel de integrações do AledDesk.</p>
        </div>
        
        <!-- URL do AledDesk -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-slate-700 mb-2">
                <i data-lucide="link" class="w-4 h-4 inline mr-1"></i>
                URL do AledDesk
            </label>
            <input 
                type="url" 
                name="aleddesk_url"
                id="aleddesk-url"
                value="<?php echo htmlspecialchars($aleddesk_url); ?>"
                placeholder="https://seudominio.com/aleddesk"
                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
            <p class="text-xs text-slate-500 mt-2">URL base da instalação do AledDesk.</p>
        </div>
        
        <!-- URL de Webhook (AledDesk) -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-slate-700 mb-2">
                <i data-lucide="webhook" class="w-4 h-4 inline mr-1"></i>
                URL de Webhook (AledDesk)
            </label>
            <div class="flex gap-2">
                <input 
                    type="text" 
                    id="webhook-display"
                    value="<?php echo htmlspecialchars($aleddesk_webhook ?: 'https://aleddesk.com/api/webhook/...'); ?>"
                    readonly
                    class="flex-1 px-4 py-3 bg-slate-50 border border-slate-300 rounded-lg font-mono text-sm"
                >
                <button 
                    type="button"
                    onclick="copyWebhook()"
                    class="px-4 py-3 bg-slate-600 hover:bg-slate-700 text-white rounded-lg transition-colors"
                    title="Copiar"
                >
                    <i data-lucide="copy" class="w-5 h-5"></i>
                </button>
            </div>
            <input type="hidden" name="aleddesk_webhook" value="<?php echo htmlspecialchars($aleddesk_webhook); ?>">
            <p class="text-xs text-slate-500 mt-2">URL para onde o AledDesk enviará notificações de eventos (opcional).</p>
        </div>
        
        <!-- Botões -->
        <div class="flex items-center gap-3">
            <button 
                type="submit"
                class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors font-medium"
            >
                <i data-lucide="save" class="w-4 h-4 inline mr-2"></i>
                Salvar Configurações
            </button>
            <button 
                type="button"
                onclick="testConnection()"
                class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium"
            >
                <i data-lucide="zap" class="w-4 h-4 inline mr-2"></i>
                Testar Conexão
            </button>
        </div>
    </form>
</div>

<script>
    lucide.createIcons();
    
    async function pasteToken() {
        try {
            const text = await navigator.clipboard.readText();
            document.getElementById('aleddesk-token').value = text;
            
            Toastify({
                text: "Token colado com sucesso!",
                duration: 3000,
                close: true,
                gravity: 'top',
                position: 'right',
                style: { background: 'linear-gradient(to right, #22c55e, #15803d)' }
            }).showToast();
        } catch (err) {
            alert('Erro ao colar. Use Ctrl+V manualmente.');
        }
    }
    
    function copyWebhook() {
        const input = document.getElementById('webhook-display');
        input.select();
        document.execCommand('copy');
        
        Toastify({
            text: "URL de Webhook copiada!",
            duration: 3000,
            close: true,
            gravity: 'top',
            position: 'right',
            style: { background: 'linear-gradient(to right, #22c55e, #15803d)' }
        }).showToast();
    }
    
    async function testConnection() {
        const token = document.getElementById('aleddesk-token').value;
        const url = document.getElementById('aleddesk-url').value;
        
        if (!token || !url) {
            Toastify({
                text: "Preencha Token e URL antes de testar!",
                duration: 3000,
                close: true,
                gravity: 'top',
                position: 'right',
                style: { background: 'linear-gradient(to right, #ef4444, #b91c1c)' }
            }).showToast();
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'test_connection');
        formData.append('aleddesk_token', token);
        formData.append('aleddesk_url', url);
        
        try {
            const response = await fetch('index.php?page=integrations', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                Toastify({
                    text: "✅ " + result.message,
                    duration: 5000,
                    close: true,
                    gravity: 'top',
                    position: 'right',
                    style: { background: 'linear-gradient(to right, #22c55e, #15803d)' }
                }).showToast();
            } else {
                Toastify({
                    text: "❌ " + result.message,
                    duration: 5000,
                    close: true,
                    gravity: 'top',
                    position: 'right',
                    style: { background: 'linear-gradient(to right, #ef4444, #b91c1c)' }
                }).showToast();
            }
        } catch (error) {
            Toastify({
                text: "Erro ao testar conexão: " + error.message,
                duration: 5000,
                close: true,
                gravity: 'top',
                position: 'right',
                style: { background: 'linear-gradient(to right, #ef4444, #b91c1c)' }
            }).showToast();
        }
    }
</script>