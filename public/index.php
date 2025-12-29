<?php
// --- MODO DEBUG: ATIVADO ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

// ==========================================================
// ORDEM DE CARREGAMENTO CRÍTICA
// ==========================================================

// 1. Configuração do Banco
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die("Erro Crítico: O ficheiro 'config.php' não foi encontrado.");
}

// 2. Autenticação
if (file_exists('auth_check.php')) {
    require_once 'auth_check.php';
} else {
    die("Erro Crítico: O ficheiro 'auth_check.php' não foi encontrado.");
}

// 3. BUSCAR DADOS EXTRAS DO USUÁRIO LOGADO (FOTO)
$user_photo = '';
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT photo_url FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userData = $stmt->fetch();
        if ($userData && !empty($userData['photo_url'])) {
            $user_photo = $userData['photo_url'];
        }
    } catch (Exception $e) {
        // Falha silenciosa para não quebrar o layout se houver erro na query
    }
}

// 4. BUSCAR CONTAGEM PARA NOTIFICAÇÕES (BADGES)
$triage_count = 0;
if (isset($_SESSION['user_id'])) {
    try {
        // Conta quantos tickets estão com o status 'aberto' (Triagem)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE status = 'aberto'");
        $stmt->execute();
        $triage_count = $stmt->fetchColumn();
    } catch (Exception $e) {
        // Falha silenciosa
    }
}
// ==========================================================
// PROCESSAMENTO DE FORMULÁRIOS (POST)
// ==========================================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    try {
        // --- AÇÕES DA PÁGINA DE MOVIMENTAÇÕES ---
        if ($_POST['action'] == 'confirm_receipt') {
            $mov_id = $_POST['mov_id'];
            $stmt = $pdo->prepare("UPDATE movements SET manager_confirmed = 1, manager_confirmed_at = NOW() WHERE id = ?");
            $stmt->execute([$mov_id]);
            $_SESSION['message'] = "Recebimento confirmado pelo gestor!";
            header("Location: index.php?page=movements"); exit;
        }
        if ($_POST['action'] == 'reject_receipt') {
            $mov_id = $_POST['mov_id'];
            $stmt = $pdo->prepare("UPDATE movements SET manager_confirmed = 2, manager_confirmed_at = NOW(), description = CONCAT(description, ' (Recusado pelo gestor.)') WHERE id = ?");
            $stmt->execute([$mov_id]);
            $_SESSION['message'] = "Recebimento recusado pelo gestor!";
            header("Location: index.php?page=movements"); exit;
        }

        // --- AÇÃO PARA SALVAR ASSINATURA (DOS TERMOS) ---
        if ($_POST['action'] == 'save_signature') {
            $mov_id = $_POST['movement_id'];
            $signature_base64 = $_POST['signature_data_input'];
            $term_type = $_POST['term_type'] ?? 'responsibility'; // 'responsibility' or 'return'

            if (!empty($mov_id) && !empty($signature_base64)) {
                $signature_url = null;
                if (preg_match('/^data:image\/(\w+);base64,/', $signature_base64, $type)) {
                    $data = substr($signature_base64, strpos($signature_base64, ',') + 1);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $sigName = 'sig_' . uniqid() . '.png';
                        $target_dir = __DIR__ . 'uploads/signatures/';
                        if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                        if(file_put_contents($target_dir . $sigName, $data)) {
                            $signature_url = 'uploads/signatures/' . $sigName;
                        }
                    }
                }

                if ($signature_url) {
                    $stmt = $pdo->prepare("UPDATE movements SET signature_url = ? WHERE id = ?");
                    $stmt->execute([$signature_url, $mov_id]);
                    $_SESSION['message'] = "Assinatura salva com sucesso!";
                }
            }
            $redirect_page = ($term_type === 'return') ? 'term_return.php' : 'term.php';
            header("Location: " . $redirect_page . "?id=" . $mov_id);
            exit;
        }
    } catch (PDOException $e) { $_SESSION['message'] = "Erro: " . $e->getMessage(); }
}

// ==========================================================
// PREPARAÇÃO DE NOTIFICAÇÕES (TOAST)
// ==========================================================
$notification_js = '';
if (isset($_SESSION['message'])) {
    // Escapa a mensagem para ser usada de forma segura em JavaScript
    $message_text = addslashes($_SESSION['message']);
    
    // Determina a cor do toast com base no conteúdo da mensagem (sucesso vs. erro)
    $is_error = stripos($message_text, 'erro') !== false || stripos($message_text, 'falha') !== false;
    $background_color = $is_error 
        ? "'linear-gradient(to right, #ef4444, #b91c1c)'" // Vermelho para erros
        : "'linear-gradient(to right, #22c55e, #15803d)'"; // Verde para sucesso

    // Monta o script do Toastify
    $notification_js = "
        Toastify({
            text: \"{$message_text}\",
            duration: 5000,
            close: true,
            gravity: 'top',
            position: 'right',
            style: { background: {$background_color} }
        }).showToast();
    ";
    unset($_SESSION['message']); // Limpa a mensagem para não ser exibida novamente
}
// ==========================================================
// ROTEAMENTO
// ==========================================================
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'assets', 'movements', 'users', 'settings', 'companies', 'tickets', 'reports', 'suppliers', 'audit', 'scan', 'peripherals', 'licenses', 'integrations', 'ideas', 'idea_stats', 'profile', 'logs', 'log_dashboard']; 
$page_file = "pages/{$page}.php";

if (in_array($page, $allowed_pages) && file_exists($page_file)) {
    $file_to_load = $page_file;
} else {
    $file_to_load = "pages/dashboard.php";
}

$user_name = $_SESSION['user_name'] ?? 'Utilizador';
$user_role = $_SESSION['user_role'] ?? 'Visitante';

// --- INTERCEPTAÇÃO DE AJAX (JSON) ---
if ($page == 'assets' && isset($_POST['action']) && $_POST['action'] == 'toggle_favorite') {
    include $page_file;
    exit;
}
?>
<?php
// ==========================================================
// FUNÇÃO AUXILIAR PARA GERAR LINKS DA SIDEBAR
// ==========================================================
function generate_nav_link($link_page, $icon, $label, $current_page, $badge_count = 0) {
    $is_active = ($link_page == $current_page);

    $link_classes = 'nav-link group flex items-center justify-between gap-3 px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 ';
    $link_classes .= $is_active ? 'bg-white/20 text-white' : 'text-blue-100 hover:bg-blue-700 hover:text-white';

    $icon_classes = 'w-5 h-5 ';
    $icon_classes .= $is_active ? 'text-white' : 'text-blue-300 group-hover:text-white';

    $html = "<a href='index.php?page={$link_page}' class='{$link_classes}'>";
    $html .= "<div class='flex items-center gap-3'>";
    $html .= "<i data-lucide='{$icon}' class='{$icon_classes}'></i>";
    $html .= "<span class='nav-text'>{$label}</span>";
    $html .= "</div>";

    if ($badge_count > 0) {
        $html .= "<span class='bg-red-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full nav-text'>{$badge_count}</span>";
    }
    $html .= "</a>";
    return $html;
}

function generate_nav_dropdown($label, $icon, $children, $current_page) {
    // Verifica se alguma página filha está ativa
    $is_active_parent = false;
    foreach ($children as $child) {
        if ($child['page'] == $current_page) {
            $is_active_parent = true;
            break;
        }
    }

    $button_classes = 'nav-link group w-full flex items-center justify-between gap-3 px-4 py-3 text-sm font-medium rounded-lg transition-all duration-200 ';
    $button_classes .= $is_active_parent ? 'bg-white/20 text-white' : 'text-blue-100 hover:bg-blue-700 hover:text-white';

    $icon_classes = 'w-5 h-5 ';
    $icon_classes .= $is_active_parent ? 'text-white' : 'text-blue-300 group-hover:text-white';

    $chevron_classes = 'w-4 h-4 transition-transform duration-200 ';
    $chevron_classes .= $is_active_parent ? 'rotate-180' : '';

    $submenu_classes = 'pl-6 pt-1 pb-2 space-y-1 nav-text ';
    $submenu_classes .= $is_active_parent ? 'max-h-screen' : 'max-h-0 hidden'; // Use max-h for smooth transition

    $html = "<div>";
    $html .= "<button onclick='toggleSubmenu(this)' class='{$button_classes}'>";
    $html .= "<div class='flex items-center gap-3'>";
    $html .= "<i data-lucide='{$icon}' class='{$icon_classes}'></i>";
    $html .= "<span class='nav-text'>{$label}</span>";
    $html .= "</div>";
    $html .= "<i data-lucide='chevron-down' class='{$chevron_classes}'></i>";
    $html .= "</button>";
    $html .= "<div class='{$submenu_classes}'>";
    foreach ($children as $child) {
        $html .= generate_nav_link($child['page'], $child['icon'], $child['label'], $current_page);
    }
    $html .= "</div>";
    $html .= "</div>";
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Patrimônio 360º - <?php echo ucfirst($page); ?></title>
    <link rel="shortcut icon" href="src/favicon.ico">
    <!-- Bibliotecas -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
    <!-- Biblioteca de Notificações Toastify -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'); 
        body { font-family: 'Inter', sans-serif; }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Scrollbar da Sidebar (para combinar com o tema azul) */
        #sidebar nav::-webkit-scrollbar { width: 6px; }
        #sidebar nav::-webkit-scrollbar-track { background: #1e40af; /* Cor de fundo (blue-800) */ }
        #sidebar nav::-webkit-scrollbar-thumb { background: #60a5fa; border-radius: 3px; /* Cor da alça (blue-400) */ }
        #sidebar nav::-webkit-scrollbar-thumb:hover { background: #93c5fd; /* Cor da alça no hover (blue-300) */ }

        /* Ajustes Mobile */
        .sidebar-open { transform: translateX(0) !important; }
        .overlay-open { display: block !important; opacity: 1 !important; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased h-screen flex overflow-hidden">

    <!-- OVERLAY MOBILE (Fundo escuro quando menu abre) -->
    <div id="mobile-overlay" onclick="toggleSidebar()" class="fixed inset-0 bg-slate-900/50 z-40 hidden transition-opacity opacity-0 backdrop-blur-sm md:hidden"></div>

    <!-- SIDEBAR -->
    <!-- 
       A lógica aqui é:
       - Mobile: fixed, z-50, começa fora da tela (-translate-x-full)
       - Desktop (md): static, z-20, sempre visível (translate-x-0)
    -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-blue-600 text-white flex flex-col transition-all duration-300 transform -translate-x-full md:translate-x-0 md:relative shadow-xl md:shadow-lg md:w-64">
        
        <!-- Logo -->
        <div class="p-6 border-b border-blue-700 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="bg-white/20 text-white p-2 rounded-lg">
                    <i data-lucide="codesandbox" class="w-6 h-6"></i>
                </div>
                <span class="font-bold text-xl text-white tracking-tight nav-text">Patrimônio 360º</span>
            </div>
            <!-- Botão fechar (Apenas Mobile) -->
            <button onclick="toggleSidebar()" class="md:hidden text-blue-200 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        
        <!-- Navegação -->
        <nav class="flex-1 p-4 space-y-1 overflow-y-auto">

            <?php echo generate_nav_link('scan', 'scan', 'Scanner Universal', $page); ?>

            <div class="border-t border-blue-700 my-2 mx-4 pb-4 nav-text"></div>

            <p class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-2 nav-text">Principal</p>
            
            <?php echo generate_nav_link('dashboard', 'layout-dashboard', 'Dashboard', $page); ?>
            <?php echo generate_nav_link('assets', 'package', 'Ativos', $page); ?>
            <?php echo generate_nav_link('peripherals', 'keyboard', 'Estoque / Consumíveis', $page); ?>
            <?php echo generate_nav_link('movements', 'arrow-left-right', 'Movimentações', $page); ?>
            <?php echo generate_nav_link('tickets', 'tag', 'Tickets', $page, $triage_count); ?>
            <?php echo generate_nav_link('profile', 'user', 'Meu Perfil', $page); ?>
            <?php
                $ideas_children = [
                    ['page' => 'ideas', 'icon' => 'message-circle', 'label' => 'Ver Ideias'],
                    ['page' => 'idea_stats', 'icon' => 'bar-chart-2', 'label' => 'Estatísticas']
                ];
                echo generate_nav_dropdown('Canal de Ideias', 'lightbulb', $ideas_children, $page);
            ?>
            <?php echo generate_nav_link('audit', 'list-checks', 'Auditoria', $page); ?>
            <?php echo generate_nav_link('reports', 'file-spreadsheet', 'Relatórios', $page); ?>

            <div class="border-t border-blue-700 my-2 mx-4 pb-4 nav-text"></div>

            <?php
                // Itens do submenu de Administração
                $admin_children = [];
                if (in_array($user_role, ['admin'])) {
                    $admin_children[] = ['page' => 'companies', 'icon' => 'building-2', 'label' => 'Empresas'];
                    $admin_children[] = ['page' => 'users', 'icon' => 'users', 'label' => 'Usuários'];
                    $admin_children[] = ['page' => 'settings', 'icon' => 'settings', 'label' => 'Configurações'];
                    $admin_children[] = ['page' => 'logs', 'icon' => 'scroll-text', 'label' => 'Logs do Sistema'];
                    $admin_children[] = ['page' => 'log_dashboard', 'icon' => 'bar-chart-big', 'label' => 'Dashboard de Logs'];
                    //$admin_children[] = ['page' => 'integrations', 'icon' => 'network', 'label' => 'Integrações'];
                }
                if (in_array($user_role, ['admin', 'gestor'])) {
                    $admin_children[] = ['page' => 'licenses', 'icon' => 'key-round', 'label' => 'Licenças'];
                }

                if (!empty($admin_children)) {
                    echo '<p class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mt-6 mb-2 nav-text">Administração</p>';
                    echo generate_nav_dropdown('Gestão', 'shield', $admin_children, $page);
                }
            ?>
        </nav>

        <!-- Footer Sidebar -->
        <div class="p-4 border-t border-blue-700">
            <div class="flex items-center gap-3 p-2 rounded-lg bg-blue-700/50">
                
                <!-- FOTO DO USUÁRIO -->
                <?php if($user_photo): ?>
                    <img src="<?php echo htmlspecialchars($user_photo); ?>" class="w-10 h-10 rounded-full object-cover border-2 border-blue-400 shrink-0">
                <?php else: ?>
                    <div class="bg-blue-500 p-2 rounded-full text-blue-100 shrink-0">
                        <i data-lucide="user" class="w-5 h-5"></i>
                    </div>
                <?php endif; ?>

                <div class="flex-1 min-w-0 user-info">
                    <p class="text-sm font-medium text-white truncate" title="<?php echo htmlspecialchars($user_name); ?>">
                        <?php echo htmlspecialchars($user_name); ?>
                    </p>
                    <p class="text-xs text-blue-200 truncate uppercase">
                        <?php echo htmlspecialchars($user_role); ?>
                    </p>
                </div>
                <a href="logout.php" title="Sair" class="text-blue-200 hover:text-white transition-colors p-1 rounded user-info">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- CONTEÚDO PRINCIPAL -->
    <main id="main-content" class="flex-1 flex flex-col h-full overflow-hidden bg-slate-50 relative w-full transition-all duration-300">

        <!-- Header Mobile (Só aparece em telas pequenas) -->
        <div class="md:hidden flex items-center justify-between p-4 bg-white border-b border-slate-200 shadow-sm z-30 sticky top-0">
            <div class="flex items-center gap-2">
                 <div class="bg-blue-600 text-white p-1.5 rounded-lg">
                    <i data-lucide="codesandbox" class="w-5 h-5"></i>
                </div>
                <span class="font-bold text-lg text-slate-800">Patrimônio 360º</span>
            </div>
            <button onclick="toggleSidebar()" class="text-slate-500 hover:text-blue-600 p-1 rounded-md active:bg-slate-100">
                <i data-lucide="menu" class="w-7 h-7"></i>
            </button>
        </div>

        <!-- Área de Scroll da Página -->
        <div class="flex-1 overflow-y-auto p-4 md:p-8 scroll-smooth">
            <?php 
                if (file_exists($file_to_load)) {
                    include $file_to_load; 
                } else {
                    echo "
                    <div class='flex flex-col items-center justify-center h-full text-center text-slate-400'>
                        <i data-lucide='file-warning' class='w-12 h-12 mb-4 opacity-50'></i>
                        <h2 class='text-xl font-bold text-slate-600 mb-2'>Página não encontrada</h2>
                        <p class='text-sm'>O ficheiro <code>$file_to_load</code> não existe.</p>
                    </div>";
                }
            ?>
        </div>
    </main>

    <!-- SCRIPTS -->
    <script>
        // Inicializa ícones
        lucide.createIcons();

        // Lógica do Menu Mobile
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobile-overlay');
            
            // Alterna classes para abrir/fechar
            sidebar.classList.toggle('sidebar-open');
            
            if (overlay.classList.contains('hidden')) {
                // Abrir
                overlay.classList.remove('hidden');
                setTimeout(() => overlay.classList.add('overlay-open'), 10); // Delay para animação
            } else {
                // Fechar
                overlay.classList.remove('overlay-open');
                setTimeout(() => overlay.classList.add('hidden'), 300); // Espera animação
            }
        }

        // A função toggleSidebar agora é apenas para o menu mobile.
        // Renomeei para maior clareza.
        const toggleSidebar = toggleMobileSidebar;

        // Exibe a notificação (toast) se houver alguma
        <?php if (!empty($notification_js)): ?>
            <?php echo $notification_js; ?>
        <?php endif; ?>

        // Lógica do Submenu
        function toggleSubmenu(button) {
            const submenu = button.nextElementSibling;
            const chevron = button.querySelector('i[data-lucide="chevron-down"]');

            const isExpanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', !isExpanded);

            if (submenu) {
                if (isExpanded) {
                    submenu.classList.add('max-h-0', 'hidden'); // Add hidden after transition
                    submenu.classList.remove('max-h-screen');
                } else {
                    submenu.classList.remove('hidden', 'max-h-0'); // Remove hidden before transition
                    submenu.classList.add('max-h-screen');
                }
            }

            if (chevron) {
                if (isExpanded) {
                    chevron.classList.remove('rotate-180');
                } else {
                    chevron.classList.add('rotate-180');
                }
            }
        }
    </script>
</body>
</html>
