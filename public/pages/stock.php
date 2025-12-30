<?php
// pages/stock.php

$message = '';
$user_role = $_SESSION['user_role'] ?? 'leitor';

// --- CARREGAR PERMISSÕES ---
$stmt = $pdo->prepare("SELECT permissions FROM roles WHERE role_key = ?");
$stmt->execute([$user_role]);
$role_perms_json = $stmt->fetchColumn();
$current_permissions = $role_perms_json ? json_decode($role_perms_json, true) : [];

function hasPermission($p) { global $current_permissions, $user_role; return $user_role === 'admin' || in_array($p, $current_permissions); }

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && hasPermission('manage_peripherals')) {
    try {
        $action = $_POST['action'] ?? '';

        // --- CRIAR / EDITAR ITEM ---
        if ($action == 'save_item') {
            $name = $_POST['name'];
            $sku = $_POST['sku'] ?? '';
            $location_id = !empty($_POST['location_id']) ? $_POST['location_id'] : null;
            $quantity = (int)$_POST['quantity'];
            
            if (empty($_POST['id'])) {
                // Criar
                $stmt = $pdo->prepare("INSERT INTO peripherals (name, sku, location_id, quantity, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $sku, $location_id, $quantity]);
                $message = "Item adicionado ao estoque!";
            } else {
                // Editar
                $id = $_POST['id'];
                $stmt = $pdo->prepare("UPDATE peripherals SET name = ?, sku = ?, location_id = ?, quantity = ? WHERE id = ?");
                $stmt->execute([$name, $sku, $location_id, $quantity, $id]);
                $message = "Item atualizado!";
            }
        }

        // --- EXCLUIR ITEM ---
        if ($action == 'delete_item') {
            $id = $_POST['id'];
            // Verifica se está em uso (opcional, mas recomendado)
            $check = $pdo->prepare("SELECT COUNT(*) FROM asset_accessories WHERE peripheral_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                $message = "Erro: Este item está vinculado a ativos e não pode ser excluído.";
            } else {
                $pdo->prepare("DELETE FROM peripherals WHERE id = ?")->execute([$id]);
                $message = "Item removido do estoque.";
            }
        }

        // --- AJUSTE RÁPIDO DE ESTOQUE (+/-) ---
        if ($action == 'adjust_stock') {
            $id = $_POST['id'];
            $qty_change = (int)$_POST['qty_change']; // Pode ser positivo ou negativo
            $current = $pdo->query("SELECT quantity FROM peripherals WHERE id = $id")->fetchColumn();
            
            $new_qty = $current + $qty_change;
            if ($new_qty < 0) $new_qty = 0;

            $pdo->prepare("UPDATE peripherals SET quantity = ? WHERE id = ?")->execute([$new_qty, $id]);
            $message = "Estoque atualizado.";
        }

    } catch (PDOException $e) {
        $message = "Erro: " . $e->getMessage();
    }
}

// =================================================================================
// 2. CONSULTAS (GET)
// =================================================================================

// Busca Locais para o Dropdown
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll();

// Busca Itens do Estoque
$search = $_GET['search'] ?? '';
$sql = "SELECT p.*, l.name as location_name 
        FROM peripherals p 
        LEFT JOIN locations l ON p.location_id = l.id 
        WHERE p.name LIKE ? OR p.sku LIKE ? 
        ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$search%", "%$search%"]);
$items = $stmt->fetchAll();

?>

<script>
    function openItemModal(item = null) {
        document.getElementById('modalTitle').innerText = item ? 'Editar Item' : 'Novo Item de Estoque';
        document.getElementById('itemId').value = item ? item.id : '';
        document.getElementById('itemName').value = item ? item.name : '';
        document.getElementById('itemSku').value = item ? item.sku : '';
        document.getElementById('itemQuantity').value = item ? item.quantity : '0';
        document.getElementById('itemLocation').value = item ? (item.location_id || '') : '';
        
        document.getElementById('modalItem').classList.remove('hidden');
    }

    function closeItemModal() {
        document.getElementById('modalItem').classList.add('hidden');
    }

    function confirmDelete(id) {
        if(confirm('Tem certeza que deseja excluir este item do estoque?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<!-- MENSAGEM DE ALERTA -->
<?php if($message): ?>
    <div id="alertMessage" class="fixed bottom-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-300">
        <div class="flex items-center gap-3">
            <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div>
            <div><?php echo $message; ?></div>
        </div>
        <button onclick="this.parentElement.remove()" class="p-1 text-slate-400 hover:text-slate-600 rounded-full -mr-2 -my-2"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage').remove(), 5000);</script>
<?php endif; ?>

<!-- CABEÇALHO -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Estoque de Acessórios</h1>
        <p class="text-sm text-slate-500">Gerencie cabos, mouses, teclados e outros consumíveis.</p>
    </div>
    <?php if(hasPermission('manage_peripherals')): ?>
    <button onclick="openItemModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm hover:bg-blue-700 transition-colors">
        <i data-lucide="plus" class="w-4 h-4"></i> Novo Item
    </button>
    <?php endif; ?>
</div>

<!-- FILTRO -->
<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6">
    <form method="GET" class="flex gap-2">
        <input type="hidden" name="page" value="stock">
        <div class="relative flex-1">
            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar por nome ou SKU..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-lg text-sm font-medium hover:bg-slate-200">Filtrar</button>
    </form>
</div>

<!-- TABELA -->
<div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                <tr>
                    <th class="p-4">Nome do Item</th>
                    <th class="p-4">SKU / Ref</th>
                    <th class="p-4">Localização</th>
                    <th class="p-4 text-center">Quantidade</th>
                    <th class="p-4 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if(empty($items)): ?>
                    <tr><td colspan="5" class="p-8 text-center text-slate-400">Nenhum item encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach($items as $item): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="p-4 font-medium text-slate-800"><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="p-4 font-mono text-slate-500 text-xs"><?php echo htmlspecialchars($item['sku'] ?: '-'); ?></td>
                        <td class="p-4 text-slate-600"><?php echo htmlspecialchars($item['location_name'] ?: 'Não definido'); ?></td>
                        <td class="p-4 text-center">
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold <?php echo $item['quantity'] < 5 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'; ?>">
                                <?php echo $item['quantity']; ?> un
                            </span>
                        </td>
                        <td class="p-4 text-right flex justify-end gap-2">
                            <?php if(hasPermission('manage_peripherals')): ?>
                                <!-- Botões de Ajuste Rápido -->
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="adjust_stock">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <input type="hidden" name="qty_change" value="1">
                                    <button type="submit" class="p-1.5 text-green-600 hover:bg-green-50 rounded" title="Adicionar 1"><i data-lucide="plus-circle" class="w-4 h-4"></i></button>
                                </form>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="adjust_stock">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <input type="hidden" name="qty_change" value="-1">
                                    <button type="submit" class="p-1.5 text-orange-600 hover:bg-orange-50 rounded" title="Remover 1"><i data-lucide="minus-circle" class="w-4 h-4"></i></button>
                                </form>
                                <div class="w-px h-4 bg-slate-300 mx-1 self-center"></div>
                                <button onclick='openItemModal(<?php echo json_encode($item); ?>)' class="p-1.5 text-blue-600 hover:bg-blue-50 rounded"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                                <button onclick="confirmDelete(<?php echo $item['id']; ?>)" class="p-1.5 text-red-600 hover:bg-red-50 rounded"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL CRIAR/EDITAR -->
<div id="modalItem" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeItemModal()"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl p-6 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-slate-900" id="modalTitle">Novo Item</h3>
            <button onclick="closeItemModal()" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="id" id="itemId">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Nome do Item</label>
                    <input type="text" name="name" id="itemName" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Ex: Cabo HDMI 2m">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">SKU (Opcional)</label><input type="text" name="sku" id="itemSku" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Quantidade</label><input type="number" name="quantity" id="itemQuantity" required min="0" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Localização (Setor)</label>
                    <select name="location_id" id="itemLocation" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">Selecione...</option>
                        <?php foreach($locations as $loc) echo "<option value='{$loc['id']}'>{$loc['name']}</option>"; ?>
                    </select>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="closeItemModal()" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Salvar</button>
            </div>
        </form>
    </div>
</div>