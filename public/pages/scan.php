<?php
// pages/scan.php
?>
<div class="max-w-md mx-auto mt-10 p-6 bg-white rounded-xl shadow-lg text-center">
    <div class="mb-6">
        <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <i data-lucide="qr-code" class="w-8 h-8"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">Scanner de Ativos</h1>
        <p class="text-slate-500 text-sm mt-2">Aponte a câmera para a etiqueta antiga ou nova para acessar o ativo.</p>
    </div>

    <div id="reader" class="w-full bg-black rounded-lg overflow-hidden border-2 border-slate-200" style="min-height: 300px;"></div>
    
    <div id="scanResult" class="hidden mt-4 p-4 bg-green-50 text-green-700 rounded-lg text-sm font-medium animate-pulse">
        <i data-lucide="loader" class="w-4 h-4 inline animate-spin mr-2"></i> Processando...
    </div>

    <div class="mt-6 text-xs text-slate-400">
        Se o ativo estiver cadastrado, você será redirecionado automaticamente.
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
    const html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: 250 });
    
    function onScanSuccess(decodedText, decodedResult) {
        // Pausa o scanner
        html5QrcodeScanner.clear();
        document.getElementById('scanResult').classList.remove('hidden');

        // Lógica inteligente para extrair o código (mesma do cadastro)
        let cleanCode = decodedText;
        if (decodedText.includes('http') || decodedText.includes('www')) {
            try {
                const urlObj = new URL(decodedText.startsWith('http') ? decodedText : 'http://' + decodedText);
                const params = new URLSearchParams(urlObj.search);
                if (params.has('code')) cleanCode = params.get('code');
                else if (params.has('id')) cleanCode = params.get('id');
                else {
                    const segments = urlObj.pathname.split('/').filter(s => s !== '');
                    if (segments.length > 0) cleanCode = segments[segments.length - 1];
                }
            } catch(e) {
                const parts = decodedText.split('/');
                cleanCode = parts[parts.length - 1];
            }
        }
        cleanCode = cleanCode.trim();

        // REDIRECIONA PARA A PÁGINA PÚBLICA DE TICKET
        // O sistema vai procurar o ativo pelo código extraído
        window.location.href = `public_ticket.php?code=${encodeURIComponent(cleanCode)}`;
    }

    
    html5QrcodeScanner.render(onScanSuccess);
    lucide.createIcons();
</script>