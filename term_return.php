<?php
require_once 'config.php';

// Verifica autenticação (opcional, dependendo se o termo é público ou não)
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Faça login para visualizar este documento.");
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) die("ID inválido");

// Busca dados da movimentação
$query = "
    SELECT m.*, 
           a.name as asset_name, 
           a.code as asset_code, 
           a.description as asset_desc,
           l.name as location_name,
           c.name as company_name, 
           c.hr_whatsapp_phone,
           u_giver.phone as giver_phone,
           m.signature_url
    FROM movements m
    JOIN assets a ON m.asset_id = a.id
    LEFT JOIN locations l ON m.to_value = l.id
    LEFT JOIN companies c ON m.company_id = c.id
    LEFT JOIN users u_giver ON m.giver_name = u_giver.name
    WHERE m.id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$mov = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mov) die("Movimentação não encontrada.");

// Prepara link do WhatsApp
$target_phone = $whatsapp_number ?? ''; // 1. Global fallback

// 2. Prioritize company HR number
if (!empty($mov['hr_whatsapp_phone'])) {
    // Limpa caracteres não numéricos
    $clean_phone = preg_replace('/[^0-9]/', '', $mov['hr_whatsapp_phone']);
    // Se tiver 10 ou 11 dígitos (ex: 11999999999), adiciona o DDI 55 (Brasil) automaticamente
    if (strlen($clean_phone) >= 10 && strlen($clean_phone) <= 11) {
        $clean_phone = '55' . $clean_phone;
    }
    $target_phone = $clean_phone;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$current_url = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$wa_message = "Olá, segue o link do Termo de Devolução #{$id} referente ao ativo {$mov['asset_name']}: $current_url";
$wa_link = "https://wa.me/" . $target_phone . "?text=" . urlencode($wa_message);

// Prepara link do WhatsApp (Usuário Devolvente)
$wa_link_user = '';
if (!empty($mov['giver_phone'])) {
    $clean_phone_user = preg_replace('/[^0-9]/', '', $mov['giver_phone']);
    if (strlen($clean_phone_user) >= 10 && strlen($clean_phone_user) <= 11) {
        $clean_phone_user = '55' . $clean_phone_user;
    }
    $wa_message_user = "Olá, segue o seu Termo de Devolução referente ao ativo {$mov['asset_name']}: $current_url";
    $wa_link_user = "https://wa.me/" . $clean_phone_user . "?text=" . urlencode($wa_message_user);
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Termo de Devolução #<?php echo $id; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { margin: 2cm; }
            body { -webkit-print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        .print-only { display: none; } @media print { .print-only { display: block !important; } }
        body { font-family: 'Times New Roman', serif; line-height: 1.6; }
    </style>
</head>
<body class="bg-gray-100 p-8 print:bg-white print:p-0">

    <div class="max-w-[21cm] mx-auto bg-white p-12 shadow-lg print:shadow-none print:max-w-none">
        
        <!-- Cabeçalho -->
        <div class="text-center border-b-2 border-gray-800 pb-6 mb-8">
            <div class="flex justify-center mb-4">
                <!-- Substitua 'src/logo.png' pelo caminho real do seu arquivo de imagem -->
                <img src="src/logo.png" alt="Logotipo" class="h-16 object-contain">
            </div>
            <h1 class="text-2xl font-bold uppercase tracking-wide">Termo de Devolução de Ativo</h1>
            <p class="text-sm text-gray-500 mt-1">Comprovante de Retorno e Baixa de Responsabilidade</p>
            <?php if (!empty($mov['company_name'])): ?>
                <p class="text-xs font-bold text-gray-600 mt-2 uppercase"><?php echo htmlspecialchars($mov['company_name']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Texto Legal -->
        <div class="mb-8 text-justify text-lg">
            <p class="mb-4">
                Pelo presente termo, certifica-se a <strong>DEVOLUÇÃO</strong> do ativo abaixo descrito.
            </p>
            <p class="mb-4">
                O equipamento foi devolvido por <strong><?php echo htmlspecialchars($mov['giver_name'] ?? 'Não informado'); ?></strong> 
                e recebido por <strong><?php echo htmlspecialchars($mov['responsible_name']); ?></strong> 
                em <strong><?php echo date('d/m/Y', strtotime($mov['created_at'])); ?></strong> às <?php echo date('H:i', strtotime($mov['created_at'])); ?>.
            </p>
            <p>
                A partir desta data, cessa a responsabilidade de guarda e uso do devolvente sobre o referido bem, 
                passando este a estar sob custódia do setor/local <strong><?php echo htmlspecialchars($mov['location_name'] ?? 'Estoque/Manutenção'); ?></strong>.
            </p>
        </div>

        <!-- Detalhes do Ativo -->
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 mb-8 print:border-gray-300">
            <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4 border-b pb-2">Dados do Equipamento</h3>
            <div class="grid grid-cols-2 gap-y-4 gap-x-8">
                <div>
                    <span class="block text-xs text-gray-500 uppercase">Nome do Ativo</span>
                    <span class="block font-bold text-lg"><?php echo htmlspecialchars($mov['asset_name']); ?></span>
                </div>
                <div>
                    <span class="block text-xs text-gray-500 uppercase">Código de Patrimônio</span>
                    <span class="block font-mono font-bold text-lg"><?php echo htmlspecialchars($mov['asset_code']); ?></span>
                </div>
                <div class="col-span-2">
                    <span class="block text-xs text-gray-500 uppercase">Motivo / Observações</span>
                    <span class="block italic text-gray-700 bg-white p-2 border border-gray-100 rounded mt-1">
                        <?php echo !empty($mov['description']) ? nl2br(htmlspecialchars($mov['description'])) : 'Nenhuma observação registrada.'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Assinaturas -->
        <div class="mt-16 grid grid-cols-2 gap-12">
            <!-- Assinatura Devolvente -->
            <div class="text-center">
                <div class="h-24 flex items-end justify-center mb-2">
                    <div class="w-full border-b border-gray-400"></div>
                </div>
                <p class="font-bold text-sm uppercase"><?php echo htmlspecialchars($mov['giver_name'] ?? 'Devolvente'); ?></p>
                <p class="text-xs text-gray-500">Devolvente (Entregue por)</p>
            </div>

            <!-- Assinatura Recebedor -->
            <div class="text-center">
                <?php if (!empty($mov['signature_url'])): ?>
                    <div class="h-24 flex items-end justify-center mb-2 relative">
                        <img src="<?php echo htmlspecialchars($mov['signature_url']); ?>" class="absolute bottom-0 max-h-24 max-w-full mx-auto" alt="Assinatura Digital">
                        <div class="w-full border-b border-gray-400"></div>
                    </div>
                <?php else: ?>
                    <form method="POST" action="index.php" id="signatureForm" class="no-print">
                        <input type="hidden" name="action" value="save_signature">
                        <input type="hidden" name="movement_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="term_type" value="return">
                        <input type="hidden" name="signature_data_input" id="signatureDataInput">
                        <div class="bg-slate-50 border-2 border-dashed rounded-lg touch-none relative h-32">
                            <canvas id="signature-pad" class="w-full h-full"></canvas>
                        </div>
                        <div class="flex justify-between items-center mt-2">
                            <button type="button" id="clear-signature" class="text-xs text-red-500 hover:underline">Limpar</button>
                            <button type="submit" id="save-signature" class="bg-blue-600 text-white px-3 py-1 rounded-md text-xs font-bold">Salvar Assinatura</button>
                        </div>
                    </form>
                    <div class="print-only h-24 flex items-end justify-center mb-2"><div class="w-full border-b border-gray-400"></div></div>
                <?php endif; ?>
                <p class="font-bold text-sm uppercase"><?php echo htmlspecialchars($mov['responsible_name']); ?></p>
                <p class="text-xs text-gray-500">Recebedor (Responsável)</p>
            </div>
        </div>

        <!-- Rodapé -->
        <div class="mt-16 pt-4 border-t border-gray-200 text-center text-xs text-gray-400">
            <p>Documento gerado digitalmente pelo sistema Patrimônio 360º em <?php echo date('d/m/Y H:i:s'); ?></p>
            <p>ID da Movimentação: <?php echo $mov['id']; ?> | Hash de Segurança: <?php echo md5($mov['id'] . $mov['created_at']); ?></p>
        </div>

    </div>

    <!-- Botão Flutuante de Impressão -->
    <div class="fixed bottom-8 right-8 no-print flex flex-col gap-3">
        <a href="<?php echo $wa_link; ?>" target="_blank" class="bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-6 rounded-full shadow-lg flex items-center gap-2 transition-all transform hover:scale-105 justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            Enviar RH
        </a>
        <?php if($wa_link_user): ?>
        <a href="<?php echo $wa_link_user; ?>" target="_blank" class="bg-teal-500 hover:bg-teal-600 text-white font-bold py-3 px-6 rounded-full shadow-lg flex items-center gap-2 transition-all transform hover:scale-105 justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            Enviar Usuário
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-full shadow-lg flex items-center gap-2 transition-all transform hover:scale-105">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Imprimir Termo
        </button>
    </div>

    <script>
        const canvas = document.getElementById('signature-pad');
        if (canvas) {
            const signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(248, 250, 252)' // bg-slate-50
            });

            function resizeCanvas() {
                const ratio =  Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                canvas.getContext("2d").scale(ratio, ratio);
                signaturePad.clear();
            }
            window.addEventListener("resize", resizeCanvas);
            resizeCanvas();

            document.getElementById('clear-signature').addEventListener('click', () => signaturePad.clear());
            document.getElementById('signatureForm').addEventListener('submit', function(e) {
                if (signaturePad.isEmpty()) {
                    alert("Por favor, forneça uma assinatura.");
                    e.preventDefault();
                } else { document.getElementById('signatureDataInput').value = signaturePad.toDataURL(); }
            });
        }
    </script>
</body>
</html>