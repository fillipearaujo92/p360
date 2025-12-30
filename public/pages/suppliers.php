<?php
// pages/suppliers.php

$message = '';

// --- CARREGAR PERMISSÕES ---
$user_role = $_SESSION['user_role'] ?? 'leitor';
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
        if (!hasPermission('manage_suppliers')) throw new Exception("Sem permissão.");
        $company_id = $_SESSION['user_company_id'] ?? 1;

        // --- CRIAR/EDITAR FORNECEDOR ---
        if (isset($_POST['action']) && ($_POST['action'] == 'create_supplier' || $_POST['action'] == 'update_supplier')) {
            $name = $_POST['name'];
            $contact = $_POST['contact_name'];
            $email = $_POST['email'];
            $phone = $_POST['phone'];
            $category = $_POST['category'];

            if ($_POST['action'] == 'create_supplier') {
                $stmt = $pdo->prepare("INSERT INTO suppliers (company_id, name, contact_name, email, phone, category) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $name, $contact, $email, $phone, $category]);
                log_action('supplier_create', "Fornecedor '{$name}' cadastrado. ID: " . $pdo->lastInsertId());
                $message = "Fornecedor cadastrado com sucesso!";
            } else {
                $id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_name=?, email=?, phone=?, category=? WHERE id=?");
                $stmt->execute([$name, $contact, $email, $phone, $category, $id]);
                log_action('supplier_update', "Fornecedor '{$name}' (ID: {$id}) atualizado.");
                $message = "Fornecedor atualizado!";
            }
        }

        // --- EXCLUIR FORNECEDOR ---
        if (isset($_POST['action']) && $_POST['action'] == 'delete_supplier') {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            log_action('supplier_delete', "Fornecedor ID {$_POST['id']} removido.");
            $message = "Fornecedor removido.";
        }

        // --- CRIAR/EDITAR CONTRATO ---
        if (isset($_POST['action']) && ($_POST['action'] == 'create_contract' || $_POST['action'] == 'update_contract')) {
            $supplier_id = $_POST['supplier_id'];
            $title = $_POST['title'];
            $start = $_POST['start_date'];
            $end = $_POST['end_date'];
            $value = str_replace(',', '.', str_replace('.', '', $_POST['value'])); // Formato BRL
            $status = (new DateTime($end) < new DateTime()) ? 'vencido' : 'ativo'; // Auto status

            if ($_POST['action'] == 'create_contract') {
                $stmt = $pdo->prepare("INSERT INTO contracts (company_id, supplier_id, title, start_date, end_date, value, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$company_id, $supplier_id, $title, $start, $end, $value, $status]);
                log_action('contract_create', "Contrato '{$title}' registrado para o fornecedor ID {$supplier_id}. ID: " . $pdo->lastInsertId());
                $message = "Contrato registrado!";
            } else {
                $id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE contracts SET supplier_id=?, title=?, start_date=?, end_date=?, value=?, status=? WHERE id=?");
                $stmt->execute([$supplier_id, $title, $start, $end, $value, $status, $id]);
                log_action('contract_update', "Contrato '{$title}' (ID: {$id}) atualizado.");
                $message = "Contrato atualizado!";
            }
        }

        // --- EXCLUIR CONTRATO ---
        if (isset($_POST['action']) && $_POST['action'] == 'delete_contract') {
            $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            log_action('contract_delete', "Contrato ID {$_POST['id']} removido.");
            $message = "Contrato removido.";
        }

    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. DADOS
// =================================================================================
$company_id = $_SESSION['user_company_id'] ?? 1;

// Fornecedores
$suppliers = $pdo->query("SELECT *, (SELECT COUNT(*) FROM contracts WHERE supplier_id = suppliers.id) as contract_count FROM suppliers WHERE company_id = $company_id ORDER BY name ASC")->fetchAll();

// Contratos
$contracts = $pdo->query("
    SELECT c.*, s.name as supplier_name 
    FROM contracts c 
    JOIN suppliers s ON c.supplier_id = s.id 
    WHERE c.company_id = $company_id 
    ORDER BY c.end_date ASC
")->fetchAll();

// Estatísticas Rápidas
$total_monthly_cost = 0;
$expiring_soon = 0;
foreach($contracts as $c) {
    // Custo aproximado mensal (valor total / 12 - simplificado)
    $total_monthly_cost += ($c['value'] / 12);
    $days_left = (new DateTime($c['end_date']))->diff(new DateTime())->days;
    if ($days_left < 30 && $c['status'] == 'ativo') $expiring_soon++;
}
?>

<style>
    .tab-btn.active { color: #2563eb; border-bottom: 2px solid #2563eb; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
</style>

<!-- FEEDBACK -->
<?php if($message): ?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-3">
            <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div>
            <div><?php echo $message; ?></div>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-slate-400 hover:text-slate-600 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage').remove(), 4000);</script>
<?php endif; ?>

<!-- HEADER -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Fornecedores e Contratos</h1>
        <p class="text-sm text-slate-500">Gerencie parceiros, garantias e renovações</p>
    </div>
    <div class="flex gap-2">
        <?php if(hasPermission('manage_suppliers')): ?>
        <button onclick="openSupplierModal()" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="truck" class="w-4 h-4"></i> Novo Fornecedor
        </button>
        <button onclick="openContractModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-colors">
            <i data-lucide="file-text" class="w-4 h-4"></i> Novo Contrato
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- CARDS DE RESUMO -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-blue-50 text-blue-600 rounded-lg"><i data-lucide="users" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Total Fornecedores</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo count($suppliers); ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-green-50 text-green-600 rounded-lg"><i data-lucide="file-check" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">Contratos Ativos</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo count(array_filter($contracts, fn($c) => $c['status'] == 'ativo')); ?></h3>
        </div>
    </div>
    <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4">
        <div class="p-3 bg-orange-50 text-orange-600 rounded-lg"><i data-lucide="alert-circle" class="w-6 h-6"></i></div>
        <div>
            <p class="text-xs font-bold text-slate-400 uppercase">A Vencer (30 dias)</p>
            <h3 class="text-xl font-bold text-slate-800"><?php echo $expiring_soon; ?></h3>
        </div>
    </div>
</div>

<!-- TABS -->
<div class="bg-white rounded-t-xl border-b border-slate-200 px-6 pt-2 mb-6">
    <div class="flex gap-8">
        <button onclick="switchTab('contracts')" id="tab-contracts" class="tab-btn active py-4 text-sm font-medium text-slate-600 hover:text-blue-600 flex items-center gap-2 relative transition-colors">
            Contratos & Garantias
        </button>
        <button onclick="switchTab('suppliers')" id="tab-suppliers" class="tab-btn py-4 text-sm font-medium text-slate-600 hover:text-blue-600 flex items-center gap-2 transition-colors">
            Lista de Fornecedores
        </button>
    </div>
</div>

<!-- CONTEÚDO: CONTRATOS -->
<div id="content-contracts" class="tab-content active">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="p-4">Título / Fornecedor</th>
                        <th class="p-4">Período</th>
                        <th class="p-4">Valor Total</th>
                        <th class="p-4">Status</th>
                        <th class="p-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if(empty($contracts)): ?>
                        <tr><td colspan="5" class="p-8 text-center text-slate-400">Nenhum contrato registrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($contracts as $c): 
                            $today = new DateTime();
                            $end = new DateTime($c['end_date']);
                            $days = $today->diff($end)->days;
                            $is_late = $end < $today;
                            $status_class = $is_late ? 'bg-red-100 text-red-700' : ($days < 30 ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700');
                            $status_text = $is_late ? 'Vencido' : ($days < 30 ? 'Vence em breve' : 'Ativo');
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="p-4">
                                <div class="font-medium text-slate-800"><?php echo htmlspecialchars($c['title']); ?></div>
                                <div class="text-xs text-slate-500 flex items-center gap-1 mt-1"><i data-lucide="truck" class="w-3 h-3"></i> <?php echo htmlspecialchars($c['supplier_name']); ?></div>
                            </td>
                            <td class="p-4 text-slate-600">
                                <?php echo date('d/m/Y', strtotime($c['start_date'])); ?> a <?php echo date('d/m/Y', strtotime($c['end_date'])); ?>
                            </td>
                            <td class="p-4 font-medium text-slate-700">R$ <?php echo number_format($c['value'], 2, ',', '.'); ?></td>
                            <td class="p-4"><span class="px-2 py-1 rounded text-[10px] font-bold uppercase <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            <td class="p-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if(hasPermission('manage_suppliers')): ?>
                                    <button onclick='openContractModal(<?php echo json_encode($c); ?>)' class="p-2 text-slate-400 hover:text-blue-600 rounded transition-colors"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                                    <form method="POST" onsubmit="return confirm('Excluir contrato?');" class="inline"><input type="hidden" name="action" value="delete_contract"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button class="p-2 text-slate-400 hover:text-red-600 rounded transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form>
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
</div>

<!-- CONTEÚDO: FORNECEDORES -->
<div id="content-suppliers" class="tab-content">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <?php if(empty($suppliers)): ?>
            <div class="col-span-3 p-8 text-center text-slate-400 bg-white rounded-xl border border-slate-200">Nenhum fornecedor cadastrado.</div>
        <?php else: ?>
            <?php foreach($suppliers as $s): ?>
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start mb-3">
                    <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-500 font-bold text-sm border border-slate-200">
                        <?php echo strtoupper(substr($s['name'], 0, 2)); ?>
                    </div>
                    <div class="flex gap-1">
                        <?php if(hasPermission('manage_suppliers')): ?>
                        <button onclick='openSupplierModal(<?php echo json_encode($s); ?>)' class="text-slate-400 hover:text-blue-600"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                        <form method="POST" onsubmit="return confirm('Excluir fornecedor?');" class="inline"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button class="text-slate-400 hover:text-red-600"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form>
                        <?php endif; ?>
                    </div>
                </div>
                <h3 class="font-bold text-slate-800 mb-1 truncate"><?php echo htmlspecialchars($s['name']); ?></h3>
                <p class="text-xs text-slate-500 mb-3 bg-slate-50 inline-block px-2 py-1 rounded"><?php echo htmlspecialchars($s['category'] ?? 'Geral'); ?></p>
                
                <div class="space-y-2 text-sm text-slate-600 border-t border-slate-100 pt-3">
                    <div class="flex items-center gap-2"><i data-lucide="user" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($s['contact_name'] ?: '-'); ?></div>
                    <div class="flex items-center gap-2"><i data-lucide="mail" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($s['email'] ?: '-'); ?></div>
                    <div class="flex items-center gap-2"><i data-lucide="phone" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($s['phone'] ?: '-'); ?></div>
                </div>
                
                <?php if($s['contract_count'] > 0): ?>
                <div class="mt-3 text-xs font-medium text-blue-600 bg-blue-50 p-2 rounded text-center">
                    <?php echo $s['contract_count']; ?> contratos ativos
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL FORNECEDOR -->
<div id="modalSupplier" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalSupplier')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all">
        <form method="POST">
            <input type="hidden" name="action" id="supplierAction" value="create_supplier">
            <input type="hidden" name="id" id="supplierId">
            
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl">
                <h3 class="text-lg font-bold text-slate-900" id="modalSupplierTitle">Novo Fornecedor</h3>
                <button type="button" onclick="closeModal('modalSupplier')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Empresa/Nome *</label><input type="text" name="name" id="suppName" required class="w-full border p-2.5 rounded-lg text-sm"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Contato</label><input type="text" name="contact_name" id="suppContact" class="w-full border p-2.5 rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Categoria</label><input type="text" name="category" id="suppCategory" placeholder="Ex: TI" class="w-full border p-2.5 rounded-lg text-sm"></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Email</label><input type="email" name="email" id="suppEmail" class="w-full border p-2.5 rounded-lg text-sm"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Telefone</label><input type="text" name="phone" id="suppPhone" class="w-full border p-2.5 rounded-lg text-sm"></div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl"><button type="button" onclick="closeModal('modalSupplier')" class="px-4 py-2 border rounded-lg text-sm bg-white">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm">Salvar</button></div>
        </form>
    </div>
</div>

<!-- MODAL CONTRATO -->
<div id="modalContract" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalContract')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl transform scale-95 opacity-0 modal-panel transition-all">
        <form method="POST">
            <input type="hidden" name="action" id="contractAction" value="create_contract">
            <input type="hidden" name="id" id="contractId">
            
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl">
                <h3 class="text-lg font-bold text-slate-900" id="modalContractTitle">Novo Contrato</h3>
                <button type="button" onclick="closeModal('modalContract')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Fornecedor *</label>
                    <select name="supplier_id" id="contSupplier" required class="w-full border p-2.5 rounded-lg text-sm bg-white">
                        <option value="">Selecione...</option>
                        <?php foreach($suppliers as $s) echo "<option value='{$s['id']}'>{$s['name']}</option>"; ?>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Título *</label><input type="text" name="title" id="contTitle" placeholder="Ex: Garantia Notebooks" required class="w-full border p-2.5 rounded-lg text-sm"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Início</label><input type="date" name="start_date" id="contStart" required class="w-full border p-2.5 rounded-lg text-sm"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Fim (Vencimento)</label><input type="date" name="end_date" id="contEnd" required class="w-full border p-2.5 rounded-lg text-sm"></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Valor Total (R$)</label><input type="text" name="value" id="contValue" class="w-full border p-2.5 rounded-lg text-sm"></div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl"><button type="button" onclick="closeModal('modalContract')" class="px-4 py-2 border rounded-lg text-sm bg-white">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm">Salvar</button></div>
        </form>
    </div>
</div>

<script>
    // Funções de UI (Tabs e Modais)
    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => { el.classList.remove('active', 'text-blue-600', 'border-blue-600'); el.classList.add('text-slate-600', 'border-transparent'); });
        document.getElementById('content-' + tabName).classList.add('active');
        const btn = document.getElementById('tab-' + tabName);
        btn.classList.add('active', 'text-blue-600', 'border-blue-600');
        btn.classList.remove('text-slate-600', 'border-transparent');
    }

    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
        setTimeout(() => {
            document.querySelector(`#${id} .modal-backdrop`).classList.remove('opacity-0');
            document.querySelector(`#${id} .modal-panel`).classList.remove('opacity-0', 'scale-95');
            document.querySelector(`#${id} .modal-panel`).classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeModal(id) {
        document.querySelector(`#${id} .modal-backdrop`).classList.add('opacity-0');
        document.querySelector(`#${id} .modal-panel`).classList.add('opacity-0', 'scale-95');
        document.querySelector(`#${id} .modal-panel`).classList.remove('scale-100', 'opacity-100');
        setTimeout(() => document.getElementById(id).classList.add('hidden'), 300);
    }

    function openSupplierModal(data = null) {
        if (data) {
            document.getElementById('modalSupplierTitle').innerText = 'Editar Fornecedor';
            document.getElementById('supplierAction').value = 'update_supplier';
            document.getElementById('supplierId').value = data.id;
            document.getElementById('suppName').value = data.name;
            document.getElementById('suppContact').value = data.contact_name;
            document.getElementById('suppEmail').value = data.email;
            document.getElementById('suppPhone').value = data.phone;
            document.getElementById('suppCategory').value = data.category;
        } else {
            document.getElementById('modalSupplierTitle').innerText = 'Novo Fornecedor';
            document.getElementById('supplierAction').value = 'create_supplier';
            document.getElementById('supplierId').value = '';
            document.getElementById('suppName').value = '';
        }
        openModal('modalSupplier');
    }

    function openContractModal(data = null) {
        if (data) {
            document.getElementById('modalContractTitle').innerText = 'Editar Contrato';
            document.getElementById('contractAction').value = 'update_contract';
            document.getElementById('contractId').value = data.id;
            document.getElementById('contSupplier').value = data.supplier_id;
            document.getElementById('contTitle').value = data.title;
            document.getElementById('contStart').value = data.start_date;
            document.getElementById('contEnd').value = data.end_date;
            document.getElementById('contValue').value = data.value;
        } else {
            document.getElementById('modalContractTitle').innerText = 'Novo Contrato';
            document.getElementById('contractAction').value = 'create_contract';
            document.getElementById('contractId').value = '';
            document.getElementById('contTitle').value = '';
        }
        openModal('modalContract');
    }

    lucide.createIcons();
</script>