<?php
// aleddesk_client.php
// Exemplo de implementação para o AledDesk (Cliente da API)
// Este arquivo simula o sistema externo consultando o AssetManager.

// URL da API do AssetManager (Ajuste para o IP/Domínio real onde o AssetManager está hospedado)
// Se estiver testando localmente, mantenha localhost. Em produção, use o IP do servidor.
$api_endpoint = 'http://localhost/assetmanager/api.php';

// Chave de API configurada no config.php do AssetManager
$api_key = 'aleddesk_secret_key_12345';

$result = null;
$error_msg = '';

// Processa a busca quando o formulário é enviado
if (isset($_GET['search_code']) && !empty($_GET['search_code'])) {
    $code = trim($_GET['search_code']);

    // Inicializa o cURL
    $ch = curl_init();

    // Configura a URL com o parâmetro de busca (codigo)
    // Nota: api.php aceita 'id' ou 'codigo'
    $url = $api_endpoint . "?codigo=" . urlencode($code);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout de 10 segundos
    
    // Configura o Header de Autenticação
    $headers = [
        "X-API-KEY: $api_key",
        "Accept: application/json"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Executa a requisição
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);

    if ($curl_error) {
        $error_msg = "Erro de conexão com o AssetManager: " . $curl_error;
    } else {
        $data = json_decode($response, true);

        if ($http_code === 200 && isset($data['success']) && $data['success']) {
            $result = $data['data'];
        } elseif ($http_code === 404) {
            $error_msg = "Ativo não encontrado.";
        } elseif ($http_code === 401) {
            $error_msg = "Erro de Autenticação: Chave de API inválida.";
        } else {
            $error_msg = $data['message'] ?? "Erro desconhecido (HTTP $http_code)";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integração AledDesk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg bg-white rounded-xl shadow-lg overflow-hidden border border-slate-200">
        
        <!-- Cabeçalho -->
        <div class="bg-indigo-600 p-6 text-white">
            <div class="flex items-center gap-3 mb-2">
                <i data-lucide="monitor-smartphone" class="w-8 h-8"></i>
                <h1 class="text-2xl font-bold">AledDesk</h1>
            </div>
            <p class="text-indigo-200 text-sm">Consulta de Ativos Externos</p>
        </div>

        <!-- Formulário de Busca -->
        <div class="p-6 border-b border-slate-100">
            <form method="GET" class="flex gap-2">
                <div class="relative flex-1">
                    <i data-lucide="search" class="absolute left-3 top-3 w-5 h-5 text-slate-400"></i>
                    <input type="text" name="search_code" 
                           value="<?php echo htmlspecialchars($_GET['search_code'] ?? ''); ?>"
                           placeholder="Digite o código do patrimônio..." 
                           class="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all">
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2 rounded-lg transition-colors">
                    Buscar
                </button>
            </form>
        </div>

        <!-- Resultados -->
        <div class="p-6 bg-slate-50 min-h-[200px]">
            
            <?php if ($error_msg): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5 shrink-0"></i>
                    <span><?php echo htmlspecialchars($error_msg); ?></span>
                </div>
            
            <?php elseif ($result): ?>
                <div class="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
                    
                    <!-- Imagem do Ativo (se houver) -->
                    <?php if (!empty($result['photo_url'])): ?>
                        <div class="h-48 w-full bg-slate-200 relative">
                            <!-- Ajuste o caminho da imagem se necessário, pois a URL salva no banco pode ser relativa -->
                            <img src="<?php echo strpos($result['photo_url'], 'http') === 0 ? $result['photo_url'] : 'http://localhost/assetmanager/' . $result['photo_url']; ?>" 
                                 class="w-full h-full object-cover">
                        </div>
                    <?php endif; ?>

                    <div class="p-5">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <span class="bg-slate-100 text-slate-600 text-xs font-bold px-2 py-1 rounded uppercase tracking-wider border border-slate-200">
                                    <?php echo htmlspecialchars($result['code']); ?>
                                </span>
                                <h2 class="text-xl font-bold text-slate-800 mt-2"><?php echo htmlspecialchars($result['name']); ?></h2>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-bold uppercase 
                                <?php echo ($result['status'] == 'disponivel') ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                <?php echo htmlspecialchars($result['status']); ?>
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-4 text-sm text-slate-600 mb-4">
                            <div>
                                <p class="text-xs text-slate-400 uppercase font-bold">Marca</p>
                                <p><?php echo htmlspecialchars($result['brand'] ?? '-'); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-400 uppercase font-bold">Modelo</p>
                                <p><?php echo htmlspecialchars($result['model'] ?? '-'); ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-400 uppercase font-bold">Número de Série</p>
                                <p class="font-mono"><?php echo htmlspecialchars($result['serial_number'] ?? '-'); ?></p>
                            </div>
                        </div>

                        <?php if (!empty($result['description'])): ?>
                            <div class="border-t border-slate-100 pt-4">
                                <p class="text-xs text-slate-400 uppercase font-bold mb-1">Descrição</p>
                                <p class="text-slate-700 text-sm leading-relaxed">
                                    <?php echo nl2br(htmlspecialchars($result['description'])); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            
            <?php else: ?>
                <div class="flex flex-col items-center justify-center text-slate-400 h-full py-8">
                    <i data-lucide="package-search" class="w-12 h-12 mb-2 opacity-50"></i>
                    <p class="text-sm">Digite um código para consultar os detalhes.</p>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>