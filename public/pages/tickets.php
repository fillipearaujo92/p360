<?php
// pages/tickets.php

$message = '';

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $user_id = $_SESSION['user_id'] ?? 0;
        $company_id = $_SESSION['user_company_id'] ?? 1;
        $user_name = $_SESSION['user_name'] ?? 'Técnico';

        // --- APROVAR TRIAGEM ---
        if (isset($_POST['action']) && $_POST['action'] == 'approve_ticket') {
            $ticket_id = $_POST['id'];
            $asset_id = $_POST['asset_id'];

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE tickets SET status = 'em_execucao' WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $stmt = $pdo->prepare("UPDATE assets SET status = 'manutencao' WHERE id = ?");
            $stmt->execute([$asset_id]);
            $stmt = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description) VALUES (?, ?, ?, 'status', 'Ativo', 'Manutenção', 'OS Iniciada via Triagem')");
            $stmt->execute([$company_id, $asset_id, $user_id]);
            $pdo->commit();
            log_action('ticket_update', "Ticket #{$ticket_id} (Triagem) aprovado para execução.");
            $message = "Ticket aprovado e movido para Execução!";
        }

        // --- CRIAR NOVA OS (DIRETA) ---
        if (isset($_POST['action']) && $_POST['action'] == 'create_ticket') {
            $asset_id = $_POST['asset_id'];
            $title = $_POST['title'];
            $description = $_POST['description'];
            $type = $_POST['type'];
            $priority = $_POST['priority'];
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;

            $photo_urls = [];
            if (!empty($_FILES['photos']['name'][0])) {
                $target_dir = dirname(__DIR__) . '/uploads/tickets';
                if (!is_dir($target_dir)) @mkdir($target_dir, 0777, true);
                foreach ($_FILES['photos']['name'] as $key => $name) {
                    if ($_FILES['photos']['error'][$key] == 0) {
                        $new_name = uniqid('ticket_') . '.' . pathinfo($name, PATHINFO_EXTENSION);
                        if (move_uploaded_file($_FILES['photos']['tmp_name'][$key], $target_dir . '/' . $new_name)) {
                            $photo_urls[] = 'uploads/tickets/' . $new_name;
                        }
                    }
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO tickets (company_id, asset_id, title, description, ticket_type, priority, due_date, contact_info, photos_json, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'em_execucao')");
            $stmt->execute([$company_id, $asset_id, $title, $description, $type, $priority, $due_date, "$user_name (Interno)", json_encode($photo_urls)]);
            
            if ($location_id) {
                $stmt = $pdo->prepare("UPDATE assets SET status = 'manutencao', location_id = ? WHERE id = ?");
                $stmt->execute([$location_id, $asset_id]);
                $stmt = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description) VALUES (?, ?, ?, 'local', 'Origem', 'Destino OS', 'Movimentação por Abertura de OS')");
                $stmt->execute([$company_id, $asset_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE assets SET status = 'manutencao' WHERE id = ?");
                $stmt->execute([$asset_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description) VALUES (?, ?, ?, 'status', 'Ativo', 'Manutenção', 'Abertura de OS #Direta')");
            $stmt->execute([$company_id, $asset_id, $user_id]);

            $pdo->commit();
            log_action('ticket_create', "Ticket para o ativo ID {$asset_id} ('{$title}') criado. ID: " . $pdo->lastInsertId());
            $message = "Ordem de Serviço criada com sucesso!";
        }

        // --- EDITAR OS ---
        if (isset($_POST['action']) && $_POST['action'] == 'update_ticket') {
            $id = $_POST['id'];
            $title = $_POST['title'];
            $description = $_POST['description'];
            $priority = $_POST['priority'];
            $type = $_POST['type'];
            $status = $_POST['status'];
            $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

            $stmt = $pdo->prepare("UPDATE tickets SET title=?, description=?, priority=?, ticket_type=?, status=?, due_date=? WHERE id=?");
            $stmt->execute([$title, $description, $priority, $type, $status, $due_date, $id]);
            log_action('ticket_update', "Ticket #{$id} atualizado. Novo status: {$status}.");
            $message = "OS #$id atualizada.";
        }

        // --- FINALIZAR OS ---
        if (isset($_POST['action']) && $_POST['action'] == 'finish_ticket') {
            $ticket_id = $_POST['id'];
            $asset_id = $_POST['asset_id'];
            $final_status = $_POST['final_asset_status']; 
            $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE tickets SET status = 'concluido' WHERE id = ?");
            $stmt->execute([$ticket_id]);

            if ($location_id) {
                $stmt = $pdo->prepare("UPDATE assets SET status = ?, location_id = ? WHERE id = ?");
                $stmt->execute([$final_status, $location_id, $asset_id]);
                $stmt = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description) VALUES (?, ?, ?, 'local', 'Manutenção', 'Destino Final', 'Movimentação por Conclusão de OS')");
                $stmt->execute([$company_id, $asset_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE assets SET status = ? WHERE id = ?");
                $stmt->execute([$final_status, $asset_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description) VALUES (?, ?, ?, 'status', 'Manutenção', ?, 'Conclusão de OS')");
            $stmt->execute([$company_id, $asset_id, $user_id, ucfirst($final_status)]);
            $pdo->commit();

            log_action('ticket_close', "Ticket #{$ticket_id} finalizado. Status do ativo: {$final_status}.");
            $message = "OS Finalizada com sucesso!";
        }

        // --- EXCLUIR TICKET ---
        if (isset($_POST['action']) && $_POST['action'] == 'delete_ticket') {
            $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            log_action('ticket_delete', "Ticket #{$_POST['id']} excluído.");
            $message = "Ticket excluído.";
        }

    } catch (PDOException $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $message = "Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. DADOS
// =================================================================================

$pre_tickets = $pdo->query("SELECT t.*, a.name as asset_name, a.code as asset_code, l.name as location_name FROM tickets t JOIN assets a ON t.asset_id = a.id LEFT JOIN locations l ON a.location_id = l.id WHERE t.status = 'aberto' ORDER BY t.created_at ASC")->fetchAll();
$active_tickets = $pdo->query("SELECT t.*, a.name as asset_name, a.code as asset_code, a.id as asset_id_real, l.name as location_name FROM tickets t JOIN assets a ON t.asset_id = a.id LEFT JOIN locations l ON a.location_id = l.id WHERE t.status != 'aberto' ORDER BY CASE t.status WHEN 'em_execucao' THEN 1 WHEN 'aguardando' THEN 2 WHEN 'parado' THEN 3 WHEN 'concluido' THEN 4 WHEN 'cancelado' THEN 5 ELSE 6 END, t.created_at DESC")->fetchAll();
$assets_list = $pdo->query("SELECT id, name, code FROM assets ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll();
$asset_statuses = $pdo->query("SELECT * FROM asset_statuses")->fetchAll();

// KPIs
$kpi_executing = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'em_execucao'")->fetchColumn();

// Compatibilidade PostgreSQL/MySQL para datas
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'pgsql') {
    $kpi_finished_month = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'concluido' AND EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM created_at) = EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();
} else {
    $kpi_finished_month = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status = 'concluido' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetchColumn();
}


// JSON para o seletor de ativos
$assets_list_json = json_encode($assets_list);

function getTicketBadge($status) {
    switch($status) {
        case 'aberto': return '<span class="bg-orange-100 text-orange-700 px-2 py-1 rounded text-[10px] font-bold uppercase border border-orange-200">Triagem</span>';
        case 'aguardando': return '<span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-[10px] font-bold uppercase border border-yellow-200">Aguardando</span>';
        case 'em_execucao': return '<span class="bg-blue-100 text-blue-700 px-2 py-1 rounded text-[10px] font-bold uppercase border border-blue-200">Em Execução</span>';
        case 'parado': return '<span class="bg-red-100 text-red-700 px-2 py-1 rounded text-[10px] font-bold uppercase border border-red-200">Parado</span>';
        case 'concluido': return '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[10px] font-bold uppercase border border-green-200">Concluído</span>';
        default: return '<span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-[10px] font-bold uppercase">'.$status.'</span>';
    }
}

function getPriorityBadge($priority) {
    switch($priority) {
        case 'baixa': return '<div class="flex items-center gap-1.5 text-slate-500"><div class="w-2 h-2 rounded-full bg-slate-400"></div><span class="text-xs font-bold">Baixa</span></div>';
        case 'media': return '<div class="flex items-center gap-1.5 text-blue-600"><div class="w-2 h-2 rounded-full bg-blue-500"></div><span class="text-xs font-bold">Média</span></div>';
        case 'alta': return '<div class="flex items-center gap-1.5 text-orange-600"><div class="w-2 h-2 rounded-full bg-orange-500"></div><span class="text-xs font-bold">Alta</span></div>';
        case 'critica': return '<div class="flex items-center gap-1.5 text-red-600"><div class="w-2 h-2 rounded-full bg-red-600"></div><span class="text-xs font-bold">Crítica</span></div>';
        default: return '-';
    }
}
?>

<style>
    .tab-btn.active { color: #2563eb; border-bottom: 2px solid #2563eb; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .modal-scrollable { max-height: calc(90vh - 130px); overflow-y: auto; }
</style>

<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div><div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => { const el = document.getElementById('alertMessage'); if(el) el.remove(); }, 4000);</script>
<?php endif; ?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Central de Serviços</h1>
        <p class="text-sm text-slate-500">Gestão de chamados e ordens de serviço</p>
    </div>
    <button onclick="openNewTicketModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
        <i data-lucide="plus-circle" class="w-4 h-4"></i> Nova OS
    </button>
</div>

<!-- CARDS DE RESUMO -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-orange-50 text-orange-600 rounded-lg"><i data-lucide="inbox" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Aguardando Triagem</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo count($pre_tickets); ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="wrench" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Em Execução</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $kpi_executing; ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-green-50 text-green-600 rounded-lg"><i data-lucide="check-check" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Concluídos no Mês</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $kpi_finished_month; ?></h3>
        </div>
    </div>
</div>

<!-- TABS -->
<div class="bg-white rounded-t-xl border-b border-slate-200 px-6 pt-2 mb-6">
    <div class="flex gap-8">
        <button onclick="window.switchTab('pre-tickets')" id="tab-pre-tickets" class="tab-btn active py-4 text-sm font-medium text-slate-600 hover:text-blue-600 flex items-center gap-2 relative transition-colors">
            Triagem
            <?php if(count($pre_tickets) > 0): ?>
                <span class="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo count($pre_tickets); ?></span>
            <?php endif; ?>
        </button>
        <button onclick="window.switchTab('tickets')" id="tab-tickets" class="tab-btn py-4 text-sm font-medium text-slate-600 hover:text-blue-600 flex items-center gap-2 transition-colors">
            Ordens de Serviço
        </button>
    </div>
</div>

<!-- ABA 1: TRIAGEM -->
<div id="content-pre-tickets" class="tab-content active">
    <?php if(empty($pre_tickets)): ?>
        <div class="bg-white p-12 rounded-xl border border-slate-200 text-center text-slate-400">
            <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3"><i data-lucide="check-circle-2" class="w-8 h-8 text-green-500"></i></div>
            <p class="text-lg font-medium text-slate-600">Tudo limpo!</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach($pre_tickets as $pt): ?>
            <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all flex flex-col md:flex-row gap-4 group relative overflow-hidden">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-orange-400"></div>
                <div class="flex-1 pl-3">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="bg-orange-100 text-orange-700 px-2 py-0.5 rounded text-[10px] font-bold uppercase border border-orange-200">Pendente</span>
                        <span class="text-slate-400 text-xs">#<?php echo $pt['id']; ?> • <?php echo date('d/m H:i', strtotime($pt['created_at'])); ?></span>
                    </div>
                    <h3 class="text-base font-bold text-slate-800"><?php echo htmlspecialchars($pt['description']); ?></h3>
                    <div class="text-sm text-slate-500 mt-1 flex items-center gap-3">
                        <span class="flex items-center gap-1.5 text-xs"><i data-lucide="user" class="w-3 h-3"></i> <?php echo htmlspecialchars($pt['contact_info']); ?></span>
                        <span class="flex items-center gap-1.5 text-xs"><i data-lucide="box" class="w-3 h-3"></i> [<?php echo htmlspecialchars($pt['asset_code']); ?>] <?php echo htmlspecialchars($pt['asset_name']); ?></span>
                    </div>
                </div>
                <div class="flex items-center gap-2 self-end md:self-center">
                    <!-- Botão Visualizar -->
                    <button onclick='openViewModal(<?php echo htmlspecialchars(json_encode($pt), ENT_QUOTES, "UTF-8"); ?>)' class="px-4 py-2 bg-white border border-slate-200 text-slate-600 rounded-lg hover:border-blue-400 hover:text-blue-600 transition-colors text-sm font-medium flex items-center gap-2" title="Ver Detalhes">
                        <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                    
                    <form method="POST" class="inline"><input type="hidden" name="action" value="approve_ticket"><input type="hidden" name="id" value="<?php echo $pt['id']; ?>"><input type="hidden" name="asset_id" value="<?php echo $pt['asset_id']; ?>"><button class="px-4 py-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium flex items-center gap-2" title="Aprovar"><i data-lucide="check" class="w-5 h-5"></i> Aprovar</button></form>
                    <button onclick="confirmDeleteTicket(<?php echo $pt['id']; ?>)" class="p-2.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors" title="Rejeitar"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ABA 2: OS (TICKETS) -->
<div id="content-tickets" class="tab-content">
    <div class="flex flex-wrap gap-3 mb-4">
        <div class="relative flex-1 min-w-[250px]">
            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400 pointer-events-none"></i>
            <input type="text" id="searchTicket" onkeyup="filterTickets()" placeholder="Buscar por descrição ou ativo..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <select id="filterStatus" onchange="filterTickets()" class="border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white text-slate-600">
            <option value="">Todos</option>
            <option value="Aguardando">Aguardando</option>
            <option value="Em Execução">Em Execução</option>
            <option value="Parado">Parado</option>
            <option value="Concluído">Concluído</option>
        </select>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="p-4 w-16">ID</th>
                        <th class="p-4">Título / Ativo</th>
                        <th class="p-4">Tipo</th>
                        <th class="p-4">Prioridade</th>
                        <th class="p-4">Prazo</th>
                        <th class="p-4">Status</th>
                        <th class="p-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($active_tickets as $t): ?>
                    <tr class="hover:bg-slate-50 transition-colors ticket-row" data-status="<?php echo str_replace('_', ' ', ucwords($t['status'])); ?>" data-search="<?php echo strtolower($t['description'].' '.$t['asset_name'].' '.$t['title']); ?>">
                        <td class="p-4 font-mono text-slate-500 text-xs">#<?php echo $t['id']; ?></td>
                        <td class="p-4">
                            <div class="font-medium text-slate-800 truncate max-w-xs"><?php echo htmlspecialchars($t['title'] ?: $t['description']); ?></div>
                            <div class="text-xs text-slate-500 flex items-center gap-1.5 mt-1"><i data-lucide="box" class="w-3 h-3"></i> [<?php echo htmlspecialchars($t['asset_code']); ?>] <?php echo htmlspecialchars($t['asset_name']); ?></div>
                        </td>
                        <td class="p-4 capitalize text-slate-600 text-xs"><?php echo htmlspecialchars($t['ticket_type'] ?? '-'); ?></td>
                        <td class="p-4"><?php echo getPriorityBadge($t['priority'] ?? 'media'); ?></td>
                        <td class="p-4 text-slate-600"><?php echo $t['due_date'] ? date('d/m/y', strtotime($t['due_date'])) : '-'; ?></td>
                        <td class="p-4"><?php echo getTicketBadge($t['status']); ?></td>
                        <td class="p-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <?php if($t['status'] != 'concluido' && $t['status'] != 'cancelado'): ?>
                                    <button onclick="openFinishModal(<?php echo $t['id']; ?>, <?php echo $t['asset_id_real']; ?>)" class="text-green-600 hover:bg-green-50 p-1.5 rounded" title="Concluir"><i data-lucide="check-circle" class="w-4 h-4"></i></button>
                                <?php endif; ?>
                                <button onclick='openEditModal(<?php echo json_encode($t); ?>)' class="text-slate-400 hover:text-blue-600 p-1.5 rounded" title="Editar/Ver"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                                <button onclick="confirmDeleteTicket(<?php echo $t['id']; ?>)" class="text-slate-400 hover:text-red-600 p-1.5 rounded" title="Excluir"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- MODAL VISUALIZAR TRIAGEM -->
<div id="modalViewTicket" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalViewTicket')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl shrink-0">
            <h3 class="text-lg font-bold text-slate-900">Detalhes do Chamado <span id="viewTicketId" class="text-slate-400 font-normal text-sm ml-2"></span></h3>
            <button type="button" onclick="closeModal('modalViewTicket')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>

        <!-- Body -->
        <div class="p-6 space-y-5 overflow-y-auto modal-scrollable">
            <!-- Info Cards -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                    <p class="text-xs font-bold text-slate-400 uppercase mb-1">Solicitante</p>
                    <p class="text-sm font-medium text-slate-700" id="viewContact"></p>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-100">
                    <p class="text-xs font-bold text-slate-400 uppercase mb-1">Data</p>
                    <p class="text-sm font-medium text-slate-700" id="viewDate"></p>
                </div>
            </div>

            <div class="bg-blue-50 p-3 rounded-lg border border-blue-100 flex items-start gap-3">
                <div class="p-2 bg-white rounded-full text-blue-600 shrink-0"><i data-lucide="box" class="w-4 h-4"></i></div>
                <div>
                    <p class="text-xs font-bold text-blue-400 uppercase">Ativo Relacionado</p>
                    <p class="text-sm font-bold text-blue-800" id="viewAsset"></p>
                    <p class="text-xs text-blue-600" id="viewLocation"></p>
                </div>
            </div>

            <div>
                <p class="text-xs font-bold text-slate-400 uppercase mb-2">Descrição do Problema</p>
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100 text-sm text-slate-700 leading-relaxed" id="viewDescription"></div>
            </div>

            <!-- Galeria -->
            <div id="viewPhotosContainer" class="hidden">
                <p class="text-xs font-bold text-slate-400 uppercase mb-2">Fotos Anexadas</p>
                <div class="grid grid-cols-3 gap-2" id="viewPhotosGrid"></div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl shrink-0">
             <form method="POST" class="inline">
                <input type="hidden" name="action" value="delete_ticket">
                <input type="hidden" name="id" id="viewActionDeleteId">
                <button type="submit" class="px-4 py-2 bg-white border border-slate-300 text-slate-600 rounded-lg text-sm font-medium hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors">Rejeitar</button>
            </form>
            <form method="POST" class="inline">
                <input type="hidden" name="action" value="approve_ticket">
                <input type="hidden" name="id" id="viewActionApproveId">
                <input type="hidden" name="asset_id" id="viewActionAssetId">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors shadow-sm">Aprovar OS</button>
            </form>
        </div>
    </div>
</div>

<!-- MODAL NOVO TICKET -->
<div id="modalNewTicket" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalNewTicket')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <form method="POST" enctype="multipart/form-data" class="flex flex-col h-full">
            <input type="hidden" name="action" value="create_ticket">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl shrink-0">
                <h3 class="text-lg font-bold text-slate-900">Nova Ordem de Serviço</h3>
                <button type="button" onclick="closeModal('modalNewTicket')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto modal-scrollable">
                <div class="bg-blue-50 border border-blue-100 p-3 rounded-lg text-xs text-blue-800"><i data-lucide="zap" class="w-3 h-3 inline mr-1"></i> O ativo entrará em status <b>Manutenção</b> automaticamente.</div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Ativo *</label><input type="text" id="assetSearchInput" placeholder="Digite para filtrar..." onkeyup="filterAssets(this.value)" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm mb-2"><select name="asset_id" id="assetSelect" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white" size="3"><?php foreach($assets_list as $a): ?><option value="<?php echo $a['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($a['name'].' '.$a['code'])); ?>"><?php echo htmlspecialchars('[' . $a['code'] . '] ' . $a['name']); ?></option><?php endforeach; ?></select></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Título da O.S.</label><input type="text" name="title" placeholder="Ex: Troca de Peça" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"></div>
                <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-medium text-slate-700 mb-1">Tipo</label><select name="type" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"><option value="corretiva">Corretiva</option><option value="preventiva">Preventiva</option><option value="instalacao">Instalação</option><option value="descarte">Descarte</option><option value="outros">Outros</option></select></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Prioridade</label><select name="priority" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"><option value="media">Média</option><option value="alta">Alta</option><option value="critica">Crítica</option><option value="baixa">Baixa</option></select></div></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Mover para (Opcional)</label><select name="location_id" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"><option value="">Manter local atual</option><?php foreach($locations as $l): ?><option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option><?php endforeach; ?></select></div>
                <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-medium text-slate-700 mb-1">Prazo</label><input type="date" name="due_date" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Fotos</label><input type="file" name="photos[]" multiple accept="image/*" class="w-full text-sm text-slate-500"></div></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Descrição Detalhada *</label><textarea name="description" required rows="3" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm resize-none"></textarea></div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl"><button type="button" onclick="closeModal('modalNewTicket')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm bg-white">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Criar OS</button></div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR TICKET -->
<div id="modalEditTicket" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalEditTicket')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <form method="POST">
            <input type="hidden" name="action" value="update_ticket">
            <input type="hidden" name="id" id="editTicketId">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl shrink-0"><h3 class="text-lg font-bold text-slate-900">Editar Ordem de Serviço</h3><button type="button" onclick="closeModal('modalEditTicket')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button></div>
            <div class="p-6 space-y-4 overflow-y-auto modal-scrollable">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Título</label><input type="text" name="title" id="editTitle" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Status Atual</label><select name="status" id="editStatus" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"><option value="aguardando">Aguardando</option><option value="em_execucao">Em Execução</option><option value="parado">Parado</option><option value="concluido">Concluído</option></select></div>
                <div class="grid grid-cols-2 gap-4"><div><label class="block text-sm font-medium text-slate-700 mb-1">Tipo</label><select name="type" id="editType" class="w-full border rounded-lg p-2.5 text-sm"><option value="corretiva">Corretiva</option><option value="preventiva">Preventiva</option><option value="instalacao">Instalação</option><option value="descarte">Descarte</option><option value="outros">Outros</option></select></div><div><label class="block text-sm font-medium text-slate-700 mb-1">Prioridade</label><select name="priority" id="editPriority" class="w-full border rounded-lg p-2.5 text-sm"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option><option value="critica">Crítica</option></select></div></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Prazo</label><input type="date" name="due_date" id="editDueDate" class="w-full border rounded-lg p-2.5 text-sm"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Descrição</label><textarea name="description" id="editDescription" rows="4" class="w-full border rounded-lg p-2.5 text-sm"></textarea></div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl"><button type="button" onclick="closeModal('modalEditTicket')" class="px-4 py-2 border rounded-lg text-sm bg-white">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm">Salvar</button></div>
        </form>
    </div>
</div>

<!-- MODAL FINALIZAR OS -->
<div id="modalFinishTicket" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalFinishTicket')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl p-6 text-center transform scale-95 opacity-0 transition-all modal-panel">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 mb-4"><i data-lucide="check" class="h-6 w-6 text-green-600"></i></div>
        <h3 class="text-lg font-bold text-slate-900 mb-2">Concluir Ordem de Serviço?</h3>
        <p class="text-sm text-slate-500 mb-4">Isso marcará a OS como concluída.</p>
        <form method="POST">
            <input type="hidden" name="action" value="finish_ticket">
            <input type="hidden" name="id" id="finishTicketId">
            <input type="hidden" name="asset_id" id="finishAssetId">
            <div class="space-y-3 mb-4 text-left">
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Status Final do Ativo</label>
                    <select name="final_asset_status" class="w-full border p-2 rounded text-sm bg-slate-50"><?php foreach($asset_statuses as $st): ?><option value="<?php echo $st['name']; ?>" <?php echo $st['name'] == 'Ativo' ? 'selected' : '' ?>><?php echo $st['name']; ?></option><?php endforeach; ?></select>
                </div>
                <div><label class="block text-xs font-bold text-slate-700 uppercase mb-1">Mover para (Opcional)</label>
                    <select name="location_id" class="w-full border p-2 rounded text-sm bg-slate-50"><option value="">Manter local atual</option><?php foreach($locations as $l): ?><option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="flex gap-2 justify-center"><button type="button" onclick="closeModal('modalFinishTicket')" class="px-4 py-2 bg-white border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 transition-colors">Confirmar Conclusão</button></div>
        </form>
    </div>
</div>

<!-- MODAL EXCLUIR -->
<div id="modalDeleteConfirm" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalDeleteConfirm')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl modal-panel p-6 text-center transform scale-95 opacity-0 transition-all"><div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 mb-4"><i data-lucide="alert-triangle" class="h-6 w-6 text-red-600"></i></div><h3 class="text-lg font-bold text-slate-900 mb-2">Excluir Ticket?</h3><p class="text-sm text-slate-500 mb-6">Esta ação não pode ser desfeita.</p><form method="POST" id="formDelete"><input type="hidden" name="action" value="delete_ticket"><input type="hidden" name="id" id="deleteTicketId"><div class="flex gap-2 justify-center"><button type="button" onclick="closeModal('modalDeleteConfirm')" class="px-4 py-2 bg-white border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm">Excluir</button></div></form></div>
</div>

<script>
    const assetsData = <?php echo $assets_list_json; ?>;

    function filterAssets(val) {
        const select = document.getElementById('assetSelect');
        const term = val.toLowerCase();
        let hasVisible = false;
        for (let i = 0; i < select.options.length; i++) {
            const option = select.options[i];
            const searchData = option.getAttribute('data-search');
            if (searchData.includes(term)) {
                option.style.display = '';
                hasVisible = true;
            } else {
                option.style.display = 'none';
            }
        }
    }

    function openViewModal(data) {
        document.getElementById('viewTicketId').innerText = '#' + data.id;
        document.getElementById('viewContact').innerText = data.contact_info;
        document.getElementById('viewDate').innerText = new Date(data.created_at).toLocaleString('pt-BR');
        document.getElementById('viewAsset').innerText = data.asset_name + ' [' + data.asset_code + ']';
        document.getElementById('viewLocation').innerText = data.location_name || '-';
        document.getElementById('viewDescription').innerText = data.description;
        
        document.getElementById('viewActionDeleteId').value = data.id;
        document.getElementById('viewActionApproveId').value = data.id;
        document.getElementById('viewActionAssetId').value = data.asset_id;

        // Fotos
        const photosContainer = document.getElementById('viewPhotosContainer');
        const photosGrid = document.getElementById('viewPhotosGrid');
        photosGrid.innerHTML = '';
        
        if (data.photos_json) {
            try {
                const photos = JSON.parse(data.photos_json);
                if (photos.length > 0) {
                    photosContainer.classList.remove('hidden');
                    photos.forEach(photo => {
                        photosGrid.innerHTML += `<a href="${photo}" target="_blank" class="block border border-slate-200 rounded-lg overflow-hidden hover:ring-2 ring-blue-400"><img src="${photo}" class="w-full h-24 object-cover"></a>`;
                    });
                } else {
                    photosContainer.classList.add('hidden');
                }
            } catch(e) { photosContainer.classList.add('hidden'); }
        } else {
            photosContainer.classList.add('hidden');
        }

        openModal('modalViewTicket');
    }

    function openNewTicketModal() { filterAssets(''); openModal('modalNewTicket'); }

    window.openEditModal = function(data) {
        document.getElementById('editTicketId').value = data.id;
        document.getElementById('editTitle').value = data.title || '';
        document.getElementById('editDescription').value = data.description || '';
        document.getElementById('editPriority').value = data.priority || 'media';
        document.getElementById('editType').value = data.ticket_type || 'corretiva';
        document.getElementById('editStatus').value = data.status || 'aberto';
        document.getElementById('editDueDate').value = data.due_date || '';
        openModal('modalEditTicket');
    }

    window.openFinishModal = function(ticketId, assetId) {
        document.getElementById('finishTicketId').value = ticketId;
        document.getElementById('finishAssetId').value = assetId;
        openModal('modalFinishTicket');
    }

    window.confirmDeleteTicket = function(id) {
        document.getElementById('deleteTicketId').value = id;
        openModal('modalDeleteConfirm');
    }

    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('active', 'text-blue-600', 'border-blue-600');
            el.classList.add('text-slate-600', 'border-transparent');
        });
        document.getElementById('content-' + tabName).classList.add('active');
        const btn = document.getElementById('tab-' + tabName);
        if(btn) {
            btn.classList.add('active', 'text-blue-600', 'border-blue-600');
            btn.classList.remove('text-slate-600', 'border-transparent');
        }
    };

    function openModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        setTimeout(() => {
            el.querySelector('.modal-backdrop').classList.remove('opacity-0');
            el.querySelector('.modal-panel').classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.querySelector('.modal-backdrop').classList.add('opacity-0');
        el.querySelector('.modal-panel').classList.add('scale-95', 'opacity-0');
        setTimeout(() => el.classList.add('hidden'), 300);
    }

    function filterTickets() {
        const term = document.getElementById('searchTicket').value.toLowerCase();
        const statusFilter = document.getElementById('filterStatus') ? document.getElementById('filterStatus').value : '';
        document.querySelectorAll('.ticket-row').forEach(row => {
            const text = row.getAttribute('data-search');
            const status = row.getAttribute('data-status'); 
            const matchTerm = text.includes(term);
            const matchStatus = statusFilter === '' || status === statusFilter;
            row.style.display = (matchTerm && matchStatus) ? '' : 'none'; 
        });
    };
    
    if (typeof lucide !== 'undefined') lucide.createIcons();
</script>