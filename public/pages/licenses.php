<?php
// pages/licenses.php

$page_title = "Gestão de Licenças";
$message = '';
$user_role = $_SESSION['user_role'] ?? 'leitor';
$company_id = $_SESSION['user_company_id'] ?? 1;

// --- CARREGAR PERMISSÕES ---
$stmt = $pdo->prepare("SELECT permissions FROM roles WHERE role_key = ?");
$stmt->execute([$user_role]);
$role_perms_json = $stmt->fetchColumn();
$current_permissions = $role_perms_json ? json_decode($role_perms_json, true) : [];

function hasPermission($p) { global $current_permissions, $user_role; return $user_role === 'admin' || in_array($p, $current_permissions); }

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (!hasPermission('manage_licenses')) throw new Exception("Acesso negado.");
        $user_id = $_SESSION['user_id'] ?? 0;
        $action = $_POST['action'] ?? null;

        if ($action == 'create_license' || $action == 'update_license') {
            $software_name = $_POST['software_name'];
            $license_key = $_POST['license_key'] ?? null;
            $supplier_id = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
            $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
            $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
            $total_seats = !empty($_POST['total_seats']) ? (int)$_POST['total_seats'] : 1;
            $notes = $_POST['notes'] ?? null;

            if ($action == 'create_license') {
                $stmt = $pdo->prepare("INSERT INTO licenses (company_id, supplier_id, software_name, license_key, purchase_date, expiration_date, total_seats, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $supplier_id, $software_name, $license_key, $purchase_date, $expiration_date, $total_seats, $notes]);
                log_action('license_create', "Licença '{$software_name}' registrada. ID: " . $pdo->lastInsertId()); 
                $_SESSION['message'] = "Licença registrada com sucesso!";
            } else {
                $id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE licenses SET supplier_id=?, software_name=?, license_key=?, purchase_date=?, expiration_date=?, total_seats=?, notes=? WHERE id=? AND company_id=?");
                $stmt->execute([$supplier_id, $software_name, $license_key, $purchase_date, $expiration_date, $total_seats, $notes, $id, $company_id]);
                log_action('license_update', "Licença '{$software_name}' (ID: {$id}) atualizada."); 
                $_SESSION['message'] = "Licença atualizada com sucesso!";
            }
        }

        if ($action == 'delete_license') {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ? AND company_id = ?");
            $stmt->execute([$id, $company_id]);
            log_action('license_delete', "Licença ID {$id} removida."); 
            $_SESSION['message'] = "Licença removida.";
        }

        // --- ATRIBUIR LICENÇA A ATIVO ---
        if ($action == 'assign_license') {
            $license_id = $_POST['license_id'];
            $asset_id = $_POST['asset_id'];

            // Verifica se ainda há vagas
            $stmt = $pdo->prepare("SELECT total_seats, (SELECT COUNT(*) FROM license_assignments WHERE license_id = ?) as used_seats FROM licenses WHERE id = ?");
            $stmt->execute([$license_id, $license_id]);
            $license_info = $stmt->fetch();

            if ($license_info && $license_info['used_seats'] < $license_info['total_seats']) {
                $stmt = $pdo->prepare("INSERT INTO license_assignments (license_id, asset_id, user_id) VALUES (?, ?, ?)");
                $stmt->execute([$license_id, $asset_id, $user_id]);
                log_action('license_assign', "Licença ID {$license_id} atribuída ao ativo ID {$asset_id}."); 
                $_SESSION['message'] = "Licença atribuída ao ativo com sucesso!";
            } else {
                throw new Exception("Não há postos (seats) disponíveis para esta licença.");
            }
        }

        // --- DESATRIBUIR LICENÇA ---
        if ($action == 'unassign_license') {
            $assignment_id = $_POST['assignment_id'];
            $stmt = $pdo->prepare("DELETE FROM license_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            log_action('license_unassign', "Atribuição de licença ID {$assignment_id} removida."); 
            $_SESSION['message'] = "Atribuição de licença removida.";
        }

    } catch (Exception $e) {
        $_SESSION['message'] = "error:Erro: " . $e->getMessage();
    }
    // Redireciona para limpar o POST
    echo "<script>window.location.href = 'index.php?page=licenses';</script>";
    exit;
}

// =================================================================================
// 2. DADOS PARA A VIEW
// =================================================================================
$view_mode = 'list';
$license_detail = null;
$assigned_assets = [];

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $view_mode = 'detail';
    $license_id = $_GET['id'];

    // Detalhes da licença
    $stmt = $pdo->prepare("SELECT l.*, s.name as supplier_name, (SELECT COUNT(*) FROM license_assignments WHERE license_id = l.id) as used_seats FROM licenses l LEFT JOIN suppliers s ON l.supplier_id = s.id WHERE l.id = ? AND l.company_id = ?");
    $stmt->execute([$license_id, $company_id]);
    $license_detail = $stmt->fetch();

    if ($license_detail) {
        $page_title = "Detalhes: " . htmlspecialchars($license_detail['software_name']);
        // Ativos atribuídos a esta licença
        $stmt = $pdo->prepare("SELECT la.id as assignment_id, a.id as asset_id, a.name, a.code, loc.name as location_name FROM license_assignments la JOIN assets a ON la.asset_id = a.id LEFT JOIN locations loc ON a.location_id = loc.id WHERE la.license_id = ? ORDER BY a.name");
        $stmt->execute([$license_id]);
        $assigned_assets = $stmt->fetchAll();

        // Ativos disponíveis para atribuição (que ainda não têm esta licença)
        $stmt = $pdo->prepare("SELECT id, name, code FROM assets WHERE company_id = ? AND id NOT IN (SELECT asset_id FROM license_assignments WHERE license_id = ?) ORDER BY name");
        $stmt->execute([$company_id, $license_id]);
        $available_assets = $stmt->fetchAll();
    } else {
        $view_mode = 'list';
    }
}

if ($view_mode === 'list') {
    $stmt = $pdo->prepare("
        SELECT l.*, s.name as supplier_name, 
               (SELECT COUNT(*) FROM license_assignments WHERE license_id = l.id) as used_seats
        FROM licenses l 
        LEFT JOIN suppliers s ON l.supplier_id = s.id 
        WHERE l.company_id = ? 
        ORDER BY l.expiration_date ASC
    ");
    $stmt->execute([$company_id]);
    $licenses = $stmt->fetchAll();
}

// Dados para os modais (necessário em ambas as views)
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE company_id = $company_id ORDER BY name ASC")->fetchAll();


function getLicenseStatus($exp_date, $seats_total = 1, $seats_used = 0) {
    if (!$exp_date) {
        return ['text' => 'Perpétua', 'class' => 'bg-blue-100 text-blue-700'];
    }
    $today = new DateTime();
    $expiration = new DateTime($exp_date);
    $diff = $today->diff($expiration);

    if ($expiration < $today) {
        return ['text' => 'Expirada', 'class' => 'bg-red-100 text-red-700'];
    }
    if ($diff->days <= 30) {
        return ['text' => 'Vence em ' . ($diff->days > 0 ? $diff->days : 0) . ' dias', 'class' => 'bg-orange-100 text-orange-700'];
    }
    return ['text' => 'Ativa', 'class' => 'bg-green-100 text-green-700'];
}
?>

<?php if ($view_mode === 'list'): ?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title; ?></h1>
        <p class="text-sm text-slate-500">Controle suas licenças de software, chaves e validades.</p>
    </div>
    <?php if (hasPermission('manage_licenses')): ?>
    <button onclick="openLicenseModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
        <i data-lucide="key-round" class="w-4 h-4"></i> Nova Licença
    </button>
    <?php endif; ?>
</div>

<!-- FEEDBACK -->
<?php if(isset($_SESSION['message'])): 
    $is_error = strpos($_SESSION['message'], 'error:') === 0;
    $message_text = $is_error ? substr($_SESSION['message'], 6) : $_SESSION['message'];
    $message_class = $is_error ? 'border-red-500 text-red-700' : 'border-blue-500 text-blue-500';
    $icon = $is_error ? 'alert-circle' : 'check-circle';
?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-white border-l-4 <?php echo $message_class; ?> px-6 py-4 rounded shadow-lg flex items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-3">
            <div class="<?php echo $message_class; ?>"><i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i></div>
            <div><?php echo htmlspecialchars($message_text); ?></div>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-slate-400 hover:text-slate-600 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage')?.remove(), 4000);</script>
<?php 
    unset($_SESSION['message']);
endif; 
?>

<!-- TABELA -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4">Software</th>
                    <th class="p-4">Fornecedor</th>
                    <th class="p-4 text-center">Uso de Postos (Seats)</th>
                    <th class="p-4">Validade</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($licenses)): ?>
                    <tr><td colspan="6" class="text-center p-10 text-slate-500">Nenhuma licença cadastrada.</td></tr>
                <?php else: ?>
                <?php foreach($licenses as $license): 
                    $status = getLicenseStatus($license['expiration_date'], $license['total_seats'], $license['used_seats']);
                    $usage_pct = $license['total_seats'] > 0 ? ($license['used_seats'] / $license['total_seats']) * 100 : 0;
                ?>
                <tr class="hover:bg-slate-50 transition-colors cursor-pointer" onclick="window.location.href='index.php?page=licenses&id=<?php echo $license['id']; ?>'">
                    <td class="p-4">
                        <div class="font-medium text-slate-800"><?php echo htmlspecialchars($license['software_name']); ?></div>
                        <div class="text-xs text-slate-500 font-mono truncate max-w-xs"><?php echo htmlspecialchars($license['license_key'] ?: 'N/A'); ?></div>
                    </td>
                    <td class="p-4 text-slate-600"><?php echo htmlspecialchars($license['supplier_name'] ?: '-'); ?></td>
                    <td class="p-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <span class="font-bold text-sm text-slate-700"><?php echo $license['used_seats']; ?></span>
                            <span class="text-slate-400">/</span>
                            <span class="text-sm text-slate-500"><?php echo $license['total_seats']; ?></span>
                        </div>
                        <div class="w-20 h-1.5 bg-slate-200 rounded-full mx-auto mt-1 overflow-hidden">
                            <div class="h-full bg-blue-500" style="width: <?php echo $usage_pct; ?>%"></div>
                        </div>
                    </td>
                    <td class="p-4 text-slate-600">
                        <?php echo $license['expiration_date'] ? date('d/m/Y', strtotime($license['expiration_date'])) : 'Perpétua'; ?>
                    </td>
                    <td class="p-4">
                        <span class="px-2 py-1 rounded text-[10px] font-bold uppercase border <?php echo $status['class']; ?> border-current">
                            <?php echo $status['text']; ?>
                        </span>
                    </td>
                    <td class="p-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <?php if (hasPermission('manage_licenses')): ?>
                                <button onclick='event.stopPropagation(); openLicenseModal(<?php echo json_encode($license); ?>)' class="p-2 text-slate-400 hover:text-blue-600 rounded-lg transition-colors" title="Editar"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                                <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir esta licença?');" class="inline">
                                    <input type="hidden" name="action" value="delete_license">
                                    <input type="hidden" name="id" value="<?php echo $license['id']; ?>">
                                    <button type="submit" onclick="event.stopPropagation();" class="p-2 text-red-400 hover:text-red-600 rounded-lg transition-colors" title="Excluir"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: // MODO DE DETALHE ?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div class="flex items-center gap-3">
        <a href="index.php?page=licenses" class="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-slate-800 transition-colors" title="Voltar"><i data-lucide="arrow-left" class="w-5 h-5"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-slate-800"><?php echo $page_title; ?></h1>
            <p class="text-sm text-slate-500">Atribua esta licença a ativos ou utilizadores.</p>
        </div>
    </div>
    <?php if (hasPermission('manage_licenses')): ?>
        <?php if ($license_detail['used_seats'] < $license_detail['total_seats']): ?>
        <button onclick="openAssignModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="plus-circle" class="w-4 h-4"></i> Atribuir a Ativo
        </button>
        <?php else: ?>
        <div class="bg-slate-100 text-slate-500 px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium border border-slate-200">
            <i data-lucide="lock" class="w-4 h-4"></i> Todos os postos ocupados
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="users" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Postos (Seats)</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $license_detail['used_seats']; ?> / <?php echo $license_detail['total_seats']; ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-green-50 text-green-600 rounded-lg"><i data-lucide="calendar" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Data da Compra</p>
            <h3 class="text-lg font-medium text-slate-800"><?php echo $license_detail['purchase_date'] ? date('d/m/Y', strtotime($license_detail['purchase_date'])) : '-'; ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-orange-50 text-orange-600 rounded-lg"><i data-lucide="calendar-clock" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Data de Expiração</p>
            <h3 class="text-lg font-medium text-slate-800"><?php echo $license_detail['expiration_date'] ? date('d/m/Y', strtotime($license_detail['expiration_date'])) : 'Perpétua'; ?></h3>
        </div>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="p-4 bg-slate-50 border-b border-slate-200">
        <h3 class="text-sm font-bold text-slate-700">Ativos com esta Licença</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="text-slate-500 font-semibold">
                <tr><th class="p-4">Ativo</th><th class="p-4">Código</th><th class="p-4">Localização</th><th class="p-4 text-right">Ação</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($assigned_assets)): ?>
                    <tr><td colspan="4" class="text-center p-10 text-slate-400">Nenhum ativo atribuído a esta licença.</td></tr>
                <?php else: ?>
                <?php foreach($assigned_assets as $asset): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="p-4 font-medium text-slate-800"><?php echo htmlspecialchars($asset['name']); ?></td>
                    <td class="p-4 text-slate-500 font-mono"><?php echo htmlspecialchars($asset['code']); ?></td>
                    <td class="p-4 text-slate-600"><?php echo htmlspecialchars($asset['location_name'] ?: '-'); ?></td>
                    <td class="p-4 text-right">
                        <?php if (hasPermission('manage_licenses')): ?>
                            <form method="POST" onsubmit="return confirm('Tem certeza que deseja desatribuir esta licença do ativo?');" class="inline">
                                <input type="hidden" name="action" value="unassign_license">
                                <input type="hidden" name="assignment_id" value="<?php echo $asset['assignment_id']; ?>">
                                <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">Desatribuir</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL ATRIBUIR LICENÇA -->
<div id="modalAssign" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalAssign')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6 modal-panel">
        <h3 class="text-lg font-bold mb-4">Atribuir Licença a um Ativo</h3>
        <form method="POST">
            <input type="hidden" name="action" value="assign_license">
            <input type="hidden" name="license_id" value="<?php echo $license_detail['id']; ?>">
            <div class="mb-4"><label class="block text-sm font-medium mb-1">Selecione o Ativo *</label><select name="asset_id" required class="w-full border p-2 rounded-lg bg-white"><?php foreach($available_assets as $a) echo "<option value='{$a['id']}'>[{$a['code']}] {$a['name']}</option>"; ?></select></div>
            <div class="mt-6 flex justify-end gap-2"><button type="button" onclick="closeModal('modalAssign')" class="px-4 py-2 border rounded-lg text-sm">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">Atribuir</button></div>
        </form>
    </div>
</div>

<?php endif; ?>

<!-- MODAL LICENÇA -->
<div id="modalLicense" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="closeModal('modalLicense')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl p-6 modal-panel">
        <h3 class="text-lg font-bold mb-4" id="modalLicenseTitle">Nova Licença</h3>
        <form method="POST">
            <input type="hidden" name="action" id="licenseAction" value="create_license">
            <input type="hidden" name="id" id="licenseId">
            <div class="space-y-4">
                <div><label class="block text-sm font-medium mb-1">Nome do Software *</label><input type="text" name="software_name" id="softwareName" required class="w-full border p-2.5 rounded-lg"></div>
                <div><label class="block text-sm font-medium mb-1">Chave da Licença (Key)</label><input type="text" name="license_key" id="licenseKey" class="w-full border p-2.5 rounded-lg font-mono"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium mb-1">Fornecedor</label><select name="supplier_id" id="supplierId" class="w-full border p-2.5 rounded-lg bg-white"><option value="">Nenhum</option><?php foreach($suppliers as $s) echo "<option value='{$s['id']}'>".htmlspecialchars($s['name'])."</option>"; ?></select></div>
                    <div><label class="block text-sm font-medium mb-1">Total de Postos (Seats)</label><input type="number" name="total_seats" id="totalSeats" value="1" min="1" class="w-full border p-2.5 rounded-lg"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium mb-1">Data da Compra</label><input type="date" name="purchase_date" id="purchaseDate" class="w-full border p-2.5 rounded-lg"></div>
                    <div><label class="block text-sm font-medium mb-1">Data de Expiração</label><input type="date" name="expiration_date" id="expirationDate" class="w-full border p-2.5 rounded-lg"></div>
                </div>
                <div><label class="block text-sm font-medium mb-1">Notas</label><textarea name="notes" id="notes" rows="2" class="w-full border p-2.5 rounded-lg"></textarea></div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="closeModal('modalLicense')" class="px-4 py-2 border rounded-lg text-sm">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

    function openLicenseModal(data = null) {
        const form = document.getElementById('modalLicense').querySelector('form');
        form.reset();

        if (data) {
            document.getElementById('modalLicenseTitle').innerText = 'Editar Licença';
            document.getElementById('licenseAction').value = 'update_license';
            document.getElementById('licenseId').value = data.id;
            document.getElementById('softwareName').value = data.software_name;
            document.getElementById('licenseKey').value = data.license_key;
            document.getElementById('supplierId').value = data.supplier_id;
            document.getElementById('totalSeats').value = data.total_seats;
            document.getElementById('purchaseDate').value = data.purchase_date;
            document.getElementById('expirationDate').value = data.expiration_date;
            document.getElementById('notes').value = data.notes;
        } else {
            document.getElementById('modalLicenseTitle').innerText = 'Nova Licença';
            document.getElementById('licenseAction').value = 'create_license';
            document.getElementById('licenseId').value = '';
        }
        openModal('modalLicense');
    }

    function openAssignModal() { openModal('modalAssign'); }

    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>