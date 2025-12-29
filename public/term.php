<?php
// term.php - Gerador de Termo de Responsabilidade
require_once 'config.php'; // Conexão com banco

$mov_id = $_GET['id'] ?? 0;

// Busca dados completos da movimentação
$sql = "
    SELECT m.*, m.responsible_name, 
           a.name as asset_name, a.code as asset_code, a.serial_number, a.model, a.brand, a.value,
           u.name as user_name, u.email as user_email,
           c.name as company_name, c.cnpj as company_cnpj, 
           c.hr_whatsapp_phone,
           u_resp.phone as responsible_phone
    FROM movements m
    JOIN assets a ON m.asset_id = a.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN users u_resp ON m.responsible_name = u_resp.name
    LEFT JOIN companies c ON m.company_id = c.id
    WHERE m.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$mov_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) die("Movimentação não encontrada.");

// Prepara link do WhatsApp
$target_phone = $whatsapp_number ?? ''; // 1. Global fallback

// 2. Prioritize company HR number
if (!empty($data['hr_whatsapp_phone'])) {
    // Limpa caracteres não numéricos
    $clean_phone = preg_replace('/[^0-9]/', '', $data['hr_whatsapp_phone']);
    // Se tiver 10 ou 11 dígitos (ex: 11999999999), adiciona o DDI 55 (Brasil) automaticamente
    if (strlen($clean_phone) >= 10 && strlen($clean_phone) <= 11) {
        $clean_phone = '55' . $clean_phone;
    }
    $target_phone = $clean_phone;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$current_url = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$wa_message = "Olá, segue o link do Termo de Responsabilidade #{$mov_id} referente ao ativo {$data['asset_name']}: $current_url";
$wa_link = "https://wa.me/" . $target_phone . "?text=" . urlencode($wa_message);

// Prepara link do WhatsApp (Usuário Responsável)
$wa_link_user = '';
if (!empty($data['responsible_phone'])) {
    $clean_phone_user = preg_replace('/[^0-9]/', '', $data['responsible_phone']);
    if (strlen($clean_phone_user) >= 10 && strlen($clean_phone_user) <= 11) {
        $clean_phone_user = '55' . $clean_phone_user;
    }
    $wa_message_user = "Olá {$data['responsible_name']}, segue o seu Termo de Responsabilidade referente ao ativo {$data['asset_name']}: $current_url";
    $wa_link_user = "https://wa.me/" . $clean_phone_user . "?text=" . urlencode($wa_message_user);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Termo de Responsabilidade - #<?php echo $mov_id; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700&family=Open+Sans:wght@400;600&display=swap');
        
        body { background: #525659; font-family: 'Open Sans', sans-serif; }
        .page {
            background: white; display: block; margin: 0 auto; margin-bottom: 0.5cm;
            box-shadow: 0 0 0.5cm rgba(0,0,0,0.5);
            width: 21cm; height: 29.7cm; /* A4 Size */
            padding: 2cm; box-sizing: border-box; position: relative;
        }
        .legal-text { font-family: 'Merriweather', serif; line-height: 1.8; text-align: justify; font-size: 11pt; margin-top: 2rem; }
        
        @media print {
            body, .page { margin: 0; box-shadow: none; background: white; }
            .no-print { display: none !important; }
        } .print-only { display: none; } @media print { .print-only { display: block !important; } }
    </style>
</head>
<body class="py-10">

    <div class="fixed top-4 right-4 flex gap-2 no-print">
        <a href="<?php echo $wa_link; ?>" target="_blank" class="bg-green-500 text-white px-4 py-2 rounded shadow hover:bg-green-600 font-bold flex items-center gap-2">
            📱 Enviar RH
        </a>
        <?php if($wa_link_user): ?>
        <a href="<?php echo $wa_link_user; ?>" target="_blank" class="bg-teal-500 text-white px-4 py-2 rounded shadow hover:bg-teal-600 font-bold flex items-center gap-2">
            👤 Enviar Usuário
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded shadow hover:bg-blue-700 font-bold flex items-center gap-2">
            🖨️ Imprimir / Salvar PDF
        </button>
        <button onclick="window.close()" class="bg-gray-600 text-white px-4 py-2 rounded shadow hover:bg-gray-700">
            Fechar
        </button>
    </div>

    <div class="page">
        
        <div class="border-b-2 border-gray-800 pb-4 mb-8 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 uppercase tracking-wide">Termo de Responsabilidade</h1>
                <p class="text-sm text-gray-500">Entrega e Guarda de Ativo Patrimonial</p>
            </div>
            <div class="text-right">
                <h2 class="font-bold text-gray-700"><?php echo htmlspecialchars($data['company_name']); ?></h2>
                <p class="text-xs text-gray-500">CNPJ: <?php echo htmlspecialchars($data['company_cnpj'] ?? 'Não informado'); ?></p>
                <p class="text-xs text-gray-500"><?php echo date('d/m/Y'); ?></p>
            </div>
        </div>

        <div class="legal-text text-gray-700">
            <p class="mb-4">
                Pelo presente termo, eu, <strong><?php echo htmlspecialchars($data['responsible_name'] ?? '__________________________'); ?></strong>, 
                declaro ter recebido da empresa <strong><?php echo htmlspecialchars($data['company_name']); ?></strong>, 
                a título de empréstimo para uso exclusivo profissional, o equipamento abaixo descrito:
            </p>

            <div class="my-6 border border-gray-300 rounded p-4 bg-gray-50 font-sans text-sm">
                <table class="w-full">
                    <tr>
                        <td class="font-bold py-1 w-32">Ativo:</td>
                        <td><?php echo htmlspecialchars($data['asset_name']); ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Código Patr.:</td>
                        <td class="font-mono"><?php echo htmlspecialchars($data['asset_code']); ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Marca/Modelo:</td>
                        <td><?php echo htmlspecialchars($data['brand'] . ' / ' . $data['model']); ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Nº Série:</td>
                        <td><?php echo htmlspecialchars($data['serial_number'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td class="font-bold py-1">Valor Ref.:</td>
                        <td>R$ <?php echo number_format($data['value'], 2, ',', '.'); ?></td>
                    </tr>
                </table>
            </div>

            <p class="mb-4">
                Comprometo-me a zelar pela guarda, conservação e integridade do equipamento, bem como a utilizá-lo estritamente para as finalidades a que se destina no exercício de minhas funções.
            </p>
            <p class="mb-4">
                Estou ciente de que, em caso de dano, extravio, furto ou roubo decorrente de dolo ou culpa (negligência, imprudência ou imperícia), poderei ser responsabilizado pelo ressarcimento dos prejuízos causados, autorizando, desde já, o desconto dos valores correspondentes em folha de pagamento, conforme Art. 462 da CLT.
            </p>
            <p>
                Este termo tem validade a partir da data de assinatura e encerra-se mediante a devolução formal do equipamento.
            </p>
        </div>

        <div class="absolute bottom-20 left-0 w-full px-16">
            <div class="grid grid-cols-2 gap-12">
                
                <div class="text-center">
                    <?php if(!empty($data['signature_url'])): ?>
                        <div class="h-24 flex items-end justify-center mb-2">
                            <img src="<?php echo htmlspecialchars($data['signature_url']); ?>" class="max-h-20 max-w-full">
                        </div>
                        <div class="border-t border-gray-400 w-full"></div>
                    <?php else: ?>
                        <form method="POST" action="index.php" id="signatureForm" class="no-print">
                            <input type="hidden" name="action" value="save_signature">
                            <input type="hidden" name="movement_id" value="<?php echo $mov_id; ?>">
                            <input type="hidden" name="term_type" value="responsibility">
                            <input type="hidden" name="signature_data_input" id="signatureDataInput">
                            <div class="bg-gray-50 border-2 border-dashed rounded-lg touch-none relative h-24"><canvas id="signature-pad" class="w-full h-full"></canvas></div>
                            <div class="flex justify-between items-center mt-2"><button type="button" id="clear-signature" class="text-xs text-red-500 hover:underline">Limpar</button><button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded-md text-xs font-bold">Salvar Assinatura</button></div>
                        </form>
                        <div class="print-only h-24 flex items-end justify-center mb-2"><div class="w-full border-b border-gray-400"></div></div>
                    <?php endif; ?>
                    <p class="text-xs font-bold mt-1 uppercase"><?php echo htmlspecialchars($data['responsible_name'] ?? 'Colaborador'); ?></p>
                    <p class="text-[10px] text-gray-500">Colaborador / Recebedor</p>
                </div>

                <div class="text-center">
                    <div class="h-24 flex items-end justify-center mb-2">
                        </div>
                    <div class="border-t border-gray-400 w-full"></div>
                    <p class="text-xs font-bold mt-1 uppercase">Gestão de Patrimônio</p>
                    <p class="text-[10px] text-gray-500"><?php echo htmlspecialchars($data['company_name']); ?></p>
                </div>

            </div>
            
            <p class="text-[10px] text-gray-400 text-center mt-12">
                Gerado digitalmente pelo sistema Patrimônio 360º em <?php echo date('d/m/Y H:i'); ?>. ID Movimentação: #<?php echo $mov_id; ?>
            </p>
        </div>

    </div>

    <script>
        const canvas = document.getElementById('signature-pad');
        if (canvas) {
            const signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(249, 250, 251)' // bg-gray-50
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