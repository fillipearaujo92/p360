<?php
// mobile_scanner.php
$session_id = $_GET['session'] ?? '';

if (empty($session_id)) {
    // Se não houver ID de sessão, não podemos continuar.
    die("Erro: ID de sessão inválido. Por favor, gere um novo QR Code no sistema.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scanner Mobile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-slate-900 h-screen flex flex-col items-center justify-center p-4 text-white">

    <div class="w-full max-w-md space-y-6 text-center">
        
        <div class="bg-white/10 p-4 rounded-full w-16 h-16 flex items-center justify-center mx-auto backdrop-blur-md">
            <i data-lucide="qr-code" class="w-8 h-8 text-blue-400"></i>
        </div>

        <h2 class="text-xl font-bold">Conectado ao PC</h2>
        <p class="text-slate-400 text-sm">Aponte a câmera para a etiqueta do ativo.</p>

        <div class="relative overflow-hidden rounded-2xl border-2 border-slate-700 bg-black shadow-2xl">
            <div id="reader" class="w-full" style="min-height: 300px;"></div>
            
            <div class="absolute top-0 left-0 w-full h-1 bg-red-500/80 shadow-[0_0_15px_rgba(239,68,68,0.8)] animate-[scan_2s_infinite]"></div>
        </div>

        <div id="statusMsg" class="text-sm font-mono text-yellow-400">Iniciando câmera...</div>
        
        <div id="httpsWarning" class="hidden bg-red-900/80 border border-red-500 p-4 rounded-lg text-left text-xs">
            <p class="font-bold mb-1">🚫 Erro de Permissão</p>
            <p>O navegador bloqueou a câmera. Isso acontece porque o site não está usando HTTPS.</p>
            <p class="mt-2 text-slate-300">Solução no Android: Acesse <b>chrome://flags</b> e ative "Insecure origins treated as secure".</p>
        </div>
    </div>

    <style>
        @keyframes scan { 0% { top: 0; } 50% { top: 100%; } 100% { top: 0; } }
    </style>

    <script>
        lucide.createIcons();
        const sessionId = "<?php echo $session_id; ?>";
        const statusEl = document.getElementById('statusMsg');

        function onScanSuccess(decodedText, decodedResult) {
            // Toca um bipe (opcional)
            // navigator.vibrate(200); 
            
            statusEl.innerText = "Código lido! Enviando...";
            statusEl.className = "text-sm font-bold text-green-400";

            // Envia para o servidor
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('session_id', sessionId);
            formData.append('code', decodedText);

            fetch('api_scan.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if(data.status === 'saved') {
                        statusEl.innerText = "Sucesso! Pode fechar.";
                        setTimeout(() => {
                            // Opcional: fechar aba ou reiniciar scan
                            statusEl.innerText = "Aguardando próximo...";
                            statusEl.className = "text-sm font-mono text-yellow-400";
                        }, 2000);
                    }
                })
                .catch(err => {
                    statusEl.innerText = "Erro ao enviar.";
                });
        }

    
        function onScanFailure(error) {
            // Ignora erros de frame vazio
        }

        // Configuração do Scanner
        const html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", 
            { fps: 10, qrbox: { width: 250, height: 250 } },
            /* verbose= */ false
        );

        // Tratamento de Erro de Câmera
        html5QrcodeScanner.render(onScanSuccess, onScanFailure)
            .catch(err => {
                console.error("Erro ao iniciar câmera", err);
                statusEl.innerText = "Falha na câmera.";
                document.getElementById('httpsWarning').classList.remove('hidden');
            });
            
    </script>
</body>
</html>