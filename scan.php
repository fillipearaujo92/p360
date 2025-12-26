<?php
// scan.php - Scanner Público de Ativos
// Não requer login (session_start ou auth_check não são chamados)
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Scanner de Ativos - Patrimônio 360º</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .scan-area { position: relative; overflow: hidden; border-radius: 1rem; }
        /* Animação da linha de leitura */
        .scan-line {
            position: absolute; width: 100%; height: 2px; background: #ef4444;
            top: 0; left: 0; box-shadow: 0 0 4px #ef4444;
            animation: scan 2s infinite linear; z-index: 10;
        }
        @keyframes scan { 0% {top: 0%} 50% {top: 100%} 100% {top: 0%} }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4">

    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden border border-slate-200">
        
        <div class="bg-slate-900 p-6 text-center">
            <div class="w-16 h-16 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center mx-auto mb-4 border border-white/20">
                <i data-lucide="scan-line" class="w-8 h-8 text-blue-400"></i>
            </div>
            <h1 class="text-xl font-bold text-white">Scanner Universal</h1>
            <p class="text-blue-200 text-sm mt-1">Aponte para a etiqueta do patrimônio</p>
        </div>

        <div class="p-6">
            <div class="scan-area bg-black border-2 border-slate-800 relative" style="min-height: 300px;">
                <div id="reader" class="w-full h-full object-cover"></div>
                <div class="scan-line"></div> </div>

            <div id="scanStatus" class="mt-4 text-center">
                <p class="text-sm text-slate-500 animate-pulse">Aguardando leitura...</p>
            </div>
            
            <div class="mt-6 pt-4 border-t border-slate-100 text-center">
                <p class="text-xs text-slate-400 mb-2">A câmera não abriu?</p>
                <form action="public_ticket.php" method="GET" class="flex gap-2">
                    <input type="text" name="code" placeholder="Digite o código (ex: 1234)" class="flex-1 border border-slate-200 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <button type="submit" class="bg-slate-100 text-slate-600 hover:bg-slate-200 px-4 py-2 rounded-lg font-medium text-sm transition-colors">
                        Ir
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-6 text-center">
        <a href="index.php" class="text-xs text-slate-400 hover:text-blue-600 transition-colors flex items-center gap-1 justify-center">
            <i data-lucide="lock" class="w-3 h-3"></i> Área Administrativa
        </a>
    </div>

    <script>
        lucide.createIcons();

        function onScanSuccess(decodedText, decodedResult) {
            // 1. Feedback visual e sonoro
            // navigator.vibrate(100); // Vibrar se possível
            const statusDiv = document.getElementById('scanStatus');
            statusDiv.innerHTML = `<div class="bg-green-50 text-green-700 p-2 rounded-lg font-bold text-sm flex items-center justify-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i> Código lido! Processando...</div>`;
            lucide.createIcons();

            // 2. Pausa o scanner
            html5QrcodeScanner.clear();

            // 3. Lógica de Limpeza do Código (Remove URLs antigas)
            let cleanCode = decodedText;
            
            // Se for URL, tenta extrair só o código
            if (decodedText.includes('http') || decodedText.includes('www')) {
                try {
                    const urlObj = new URL(decodedText.startsWith('http') ? decodedText : 'http://' + decodedText);
                    const params = new URLSearchParams(urlObj.search);
                    
                    // Tenta achar parâmetros comuns
                    if (params.has('code')) cleanCode = params.get('code');
                    else if (params.has('id')) cleanCode = params.get('id');
                    else {
                        // Pega o último segmento da URL (ex: site.com/ativo/1234 -> 1234)
                        const segments = urlObj.pathname.split('/').filter(s => s !== '');
                        if (segments.length > 0) {
                            cleanCode = segments[segments.length - 1];
                        }
                    }
                } catch(e) {
                    // Fallback: pega tudo depois da última barra
                    const parts = decodedText.split('/');
                    cleanCode = parts[parts.length - 1];
                }
            }
            
            cleanCode = cleanCode.trim();

            // 4. Redireciona para a página de Chamado
            setTimeout(() => {
                window.location.href = `public_ticket.php?code=${encodeURIComponent(cleanCode)}`;
            }, 500);
        }

        function onScanFailure(error) {
            // Não faz nada em falhas de frame vazio para não poluir o console
        }

        // Configuração da Câmera
        const html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", 
            { 
                fps: 10, 
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            },
            /* verbose= */ false
        );
        
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    </script>
</body>
</html>