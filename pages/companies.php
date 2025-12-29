<?php
// pages/companies.php

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $action = $_POST['action'] ?? null;

        // --- EMPRESA ---
        if ($action == 'create_company' || $action == 'update_company') {
            $name = $_POST['name'];
            $cnpj = $_POST['cnpj'] ?? null;
            $email = $_POST['email'] ?? null;
            $phone = $_POST['phone'] ?? null;
            $address = $_POST['address'] ?? null;
            $hr_whatsapp_phone = $_POST['hr_whatsapp_phone'] ?? null;
            if ($action == 'create_company') {
                $pdo->prepare("INSERT INTO companies (name, cnpj, email, phone, address, hr_whatsapp_phone) VALUES (?, ?, ?, ?, ?, ?)")->execute([$name, $cnpj, $email, $phone, $address, $hr_whatsapp_phone]);
                $_SESSION['message'] = "Empresa criada com sucesso!";
                log_action('company_create', "Empresa '{$name}' criada.");
            } else {
                $id = $_POST['id'];
                $pdo->prepare("UPDATE companies SET name = ?, cnpj = ?, email = ?, phone = ?, address = ?, hr_whatsapp_phone = ? WHERE id = ?")->execute([$name, $cnpj, $email, $phone, $address, $hr_whatsapp_phone, $id]);
                $_SESSION['message'] = "Empresa atualizada!";
                log_action('company_update', "Empresa '{$name}' (ID: {$id}) atualizada.");
            }
        }
        if ($action == 'delete_company') {
            $id = $_POST['id'];
            
            $pdo->beginTransaction();
            try {
                // 1. Encontrar todos os ativos da empresa para apagar dados relacionados
                $stmt = $pdo->prepare("SELECT id FROM assets WHERE company_id = ?");
                $stmt->execute([$id]);
                $asset_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($asset_ids)) {
                    $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
                    // Apagar ficheiros e movimentações ligados aos ativos
                    $pdo->prepare("DELETE FROM asset_files WHERE asset_id IN ($placeholders)")->execute($asset_ids);
                    $pdo->prepare("DELETE FROM movements WHERE asset_id IN ($placeholders)")->execute($asset_ids);
                }

                // 2. Apagar todos os registos ligados à empresa
                $pdo->prepare("DELETE FROM assets WHERE company_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM locations WHERE company_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM categories WHERE company_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM asset_statuses WHERE company_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM companies WHERE id = ?")->execute([$id]);
                
                $pdo->commit();
                log_action('company_delete', "Empresa '{$_POST['name']}' e todos os seus dados foram removidos.");
                $_SESSION['message'] = "Empresa e todos os seus dados foram removidos permanentemente.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['message_type'] = 'error';
                $_SESSION['message'] = "Erro ao excluir a empresa: " . $e->getMessage();
            }
        }

        // --- SETOR (LOCATION) ---
        if ($action == 'create_sector' || $action == 'update_sector') {
            $company_id = $_POST['company_id'];
            $name = $_POST['name'];
            $manager = $_POST['manager_name'] ?? null;
            $location = $_POST['physical_location'] ?? null;
            $desc = $_POST['description'] ?? null;
            if ($action == 'create_sector') {
                $pdo->prepare("INSERT INTO locations (company_id, name, manager_name, physical_location, description) VALUES (?, ?, ?, ?, ?)")->execute([$company_id, $name, $manager, $location, $desc]);
                $_SESSION['message'] = "Setor criado!";
                log_action('config_create', "Setor '{$name}' criado para a empresa ID {$company_id}.");
            } else {
                $id = $_POST['id'];
                $pdo->prepare("UPDATE locations SET name = ?, manager_name = ?, physical_location = ?, description = ? WHERE id = ?")->execute([$name, $manager, $location, $desc, $id]);
                $_SESSION['message'] = "Setor atualizado!";
                log_action('config_update', "Setor '{$name}' (ID: {$id}) atualizado.");
            }
        }
        if ($action == 'delete_sector') {
            $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?"); $stmt->execute([$_POST['id']]); $name = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM locations WHERE id = ?")->execute([$_POST['id']]);
            $_SESSION['message'] = "Setor removido.";
            log_action('config_delete', "Setor '{$name}' removido.");
        }

        // --- CATEGORIA ---
        if ($action == 'create_category' || $action == 'update_category') {
            $company_id = $_POST['company_id'];
            $name = $_POST['name'];
            if ($action == 'create_category') {
                $pdo->prepare("INSERT INTO categories (company_id, name) VALUES (?, ?)")->execute([$company_id, $name]);
                $_SESSION['message'] = "Categoria criada!";
                log_action('config_create', "Categoria '{$name}' criada para a empresa ID {$company_id}.");
            } else {
                $id = $_POST['id'];
                $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
                $_SESSION['message'] = "Categoria atualizada!";
                log_action('config_update', "Categoria '{$name}' (ID: {$id}) atualizada.");
            }
        }
        if ($action == 'delete_category') {
            $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?"); $stmt->execute([$_POST['id']]); $name = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$_POST['id']]);
            $_SESSION['message'] = "Categoria removida.";
            log_action('config_delete', "Categoria '{$name}' removida.");
        }

        // --- STATUS ---
        if ($action == 'create_status' || $action == 'update_status') {
            $company_id = $_POST['company_id'];
            $name = $_POST['name'];
            $desc = $_POST['description'] ?? null;
            $color = $_POST['color'] ?? 'gray';
            if ($action == 'create_status') {
                $pdo->prepare("INSERT INTO asset_statuses (company_id, name, description, color) VALUES (?, ?, ?, ?)")->execute([$company_id, $name, $desc, $color]);
                $_SESSION['message'] = "Status criado!";
                log_action('config_create', "Status '{$name}' criado para a empresa ID {$company_id}.");
            } else {
                $id = $_POST['id'];
                $pdo->prepare("UPDATE asset_statuses SET name = ?, description = ?, color = ? WHERE id = ?")->execute([$name, $desc, $color, $id]);
                $_SESSION['message'] = "Status atualizado!";
                log_action('config_update', "Status '{$name}' (ID: {$id}) atualizado.");
            }
        }
        if ($action == 'delete_status') {
            $stmt = $pdo->prepare("SELECT name FROM asset_statuses WHERE id = ?"); $stmt->execute([$_POST['id']]); $name = $stmt->fetchColumn();
            $pdo->prepare("DELETE FROM asset_statuses WHERE id = ?")->execute([$_POST['id']]);
            $_SESSION['message'] = "Status removido.";
            log_action('config_delete', "Status '{$name}' removido.");
        }

        // --- IMPORTAÇÃO ---
        if ($action == 'import_all') {
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
                // A lógica de importação será complexa e idealmente usaria uma biblioteca PHP para ler XLSX.
                // Por simplicidade, vamos apenas mostrar uma mensagem de sucesso.
                // A implementação real exigiria uma biblioteca como PhpSpreadsheet.
                $_SESSION['message'] = "Funcionalidade de importação em desenvolvimento. O ficheiro foi recebido.";
            } else {
                throw new Exception("Nenhum ficheiro enviado ou erro no upload.");
            }
        }

        // --- CAMPOS PERSONALIZADOS ---
        if ($action == 'save_category_fields') {
            $category_id = $_POST['category_id'];
            $labels = $_POST['field_label'] ?? [];
            $types = $_POST['field_type'] ?? [];
            $schema = [];

            foreach ($labels as $index => $label) {
                if (!empty($label)) {
                    $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $label)));
                    $schema[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $types[$index] ?? 'text'
                    ];
                }
            }
            $json_schema = json_encode($schema);
            $stmt = $pdo->prepare("UPDATE categories SET custom_schema = ? WHERE id = ?");
            $stmt->execute([$json_schema, $category_id]);
            log_action('config_update', "Campos personalizados da categoria ID {$category_id} foram salvos.");
            $_SESSION['message'] = "Campos personalizados salvos!";
        }


    } catch (PDOException $e) {
        $_SESSION['message'] = "Erro: " . $e->getMessage();
    }
    // Redireciona via JavaScript para evitar erro de "headers already sent"
    echo "<script>window.location.href = 'index.php?page=companies';</script>";
    exit();
}

// BUSCA DADOS
$companies = $pdo->query("SELECT * FROM companies ORDER BY id DESC")->fetchAll();
foreach ($companies as &$comp) {
    $stmt = $pdo->prepare("SELECT *, (SELECT COUNT(*) FROM assets WHERE location_id = locations.id) as asset_count FROM locations WHERE company_id = ?"); $stmt->execute([$comp['id']]); $comp['sectors'] = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT *, (SELECT COUNT(*) FROM assets WHERE category_id = categories.id) as asset_count FROM categories WHERE company_id = ?"); $stmt->execute([$comp['id']]); $comp['categories'] = $stmt->fetchAll();
    try { $stmt = $pdo->prepare("SELECT * FROM asset_statuses WHERE company_id = ?"); $stmt->execute([$comp['id']]); $comp['statuses'] = $stmt->fetchAll(); } catch(Exception $e) { $comp['statuses'] = []; }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE company_id = ?"); $stmt->execute([$comp['id']]); $comp['total_assets'] = $stmt->fetchColumn();
}
unset($comp);
$companiesJson = json_encode($companies);
function getBadgeColor($c) { return ['red'=>'bg-red-100 text-red-700','green'=>'bg-green-100 text-green-700','blue'=>'bg-blue-100 text-blue-700','yellow'=>'bg-yellow-100 text-yellow-800','orange'=>'bg-orange-100 text-orange-700'][$c]??'bg-slate-100'; }
?>
<script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div><h1 class="text-2xl font-bold text-slate-800">Empresas e Configurações</h1><p class="text-sm text-slate-500">Gerencie unidades, setores, categorias e status</p></div>
    <div class="flex items-center gap-2">
        <div class="relative">
            <button onclick="toggleExportMenu()" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
                <i data-lucide="download" class="w-4 h-4 text-slate-500"></i> Exportar
            </button>
            <div id="exportMenu" class="hidden absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-lg shadow-xl z-20 animate-in fade-in zoom-in-95 duration-150">
                <button onclick="exportData('xlsx')" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"><i data-lucide="file-spreadsheet" class="w-4 h-4 text-green-600"></i> Excel (XLSX)</button>
                <button onclick="exportData('csv')" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"><i data-lucide="file-text" class="w-4 h-4 text-blue-600"></i> CSV (Zip)</button>
            </div>
        </div>
        <button onclick="openModal('modalImport')" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all"><i data-lucide="upload" class="w-4 h-4 text-blue-600"></i> Importar</button>
        <button onclick="openCompanyModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm"><i data-lucide="plus" class="w-4 h-4"></i> Nova Empresa</button>
    </div>
</div>

<?php if(isset($_SESSION['message'])): 
    $message_type = $_SESSION['message_type'] ?? 'success';
    $icon = $message_type == 'error' ? 'alert-circle' : 'check-circle';
    $color = $message_type == 'error' ? 'red' : 'green';
?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-<?php echo $color; ?>-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="text-<?php echo $color; ?>-500"><i data-lucide="<?php echo $icon; ?>" class="w-5 h-5"></i></div>
        <div><?php echo $_SESSION['message']; ?></div>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage')?.remove(), 3000);</script>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 relative">
    <i data-lucide="search" class="absolute left-7 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
    <input type="text" id="searchCompany" onkeyup="filterCompanies()" placeholder="Buscar empresa, setor ou categoria..." class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
</div>

<div class="space-y-4" id="companiesList">
<?php foreach ($companies as $comp): 
    $searchTerms = strtolower($comp['name']);
    foreach($comp['sectors'] as $s) $searchTerms .= ' ' . strtolower($s['name']);
    foreach($comp['categories'] as $c) $searchTerms .= ' ' . strtolower($c['name']);
?>
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden company-card transition-all" data-search="<?php echo htmlspecialchars($searchTerms); ?>">
        <div class="p-5 flex justify-between items-center cursor-pointer hover:bg-slate-50" onclick="toggleDetails(this)">
            <div class="flex-1 min-w-0 pr-4">
                <div class="flex items-center gap-3">
                    <h3 class="font-bold text-lg text-slate-800 truncate"><?php echo htmlspecialchars($comp['name']); ?></h3>
                    <div class="flex items-center gap-1 shrink-0">
                        <button onclick="event.stopPropagation(); openCompanyModal(<?php echo htmlspecialchars(json_encode($comp), ENT_QUOTES); ?>)" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-md hover:bg-slate-100"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                        <button onclick="event.stopPropagation(); openDeleteCompanyModal(<?php echo $comp['id']; ?>, '<?php echo htmlspecialchars($comp['name'], ENT_QUOTES); ?>')" class="p-1.5 text-slate-400 hover:text-red-600 rounded-md hover:bg-slate-100">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                        <button class="p-1.5 text-slate-400 hover:text-slate-600 rounded-md hover:bg-slate-100 chevron-icon transition-transform"><i data-lucide="chevron-down" class="w-4 h-4"></i></button>
                    </div>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-500 mt-1.5">
                    <span class="flex items-center gap-1.5"><i data-lucide="box" class="w-3 h-3 text-slate-400"></i> <strong><?php echo $comp['total_assets']; ?></strong> Ativos</span>
                    <span class="flex items-center gap-1.5"><i data-lucide="map-pin" class="w-3 h-3 text-slate-400"></i> <strong><?php echo count($comp['sectors']); ?></strong> Setores</span>
                    <span class="flex items-center gap-1.5"><i data-lucide="tag" class="w-3 h-3 text-slate-400"></i> <strong><?php echo count($comp['categories']); ?></strong> Categorias</span>
                </div>
            </div>
        </div>
        <div class="hidden border-t border-slate-100 bg-slate-50 p-6" data-map-init="false">
            <div class="mb-6 bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-wrap gap-x-6 gap-y-3">
                <?php if(!empty($comp['cnpj'])): ?><div class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="file-digit" class="w-4 h-4 text-slate-400"></i> <span class="font-mono"><?php echo htmlspecialchars($comp['cnpj']); ?></span></div><?php endif; ?>
                <?php if(!empty($comp['email'])): ?><div class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="mail" class="w-4 h-4 text-slate-400"></i> <?php echo htmlspecialchars($comp['email']); ?></div><?php endif; ?>
                <?php if(!empty($comp['phone'])): ?><div class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="phone" class="w-4 h-4 text-slate-400"></i> <?php echo htmlspecialchars($comp['phone']); ?></div><?php endif; ?>
                <?php if(!empty($comp['hr_whatsapp_phone'])): ?><div class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="message-square" class="w-4 h-4 text-green-500"></i> <span class="font-medium"><?php echo htmlspecialchars($comp['hr_whatsapp_phone']); ?></span></div><?php endif; ?>
                <?php if(!empty($comp['address'])): ?><div class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="map-pin" class="w-4 h-4 text-slate-400"></i> <?php echo htmlspecialchars($comp['address']); ?></div><?php endif; ?>
                <?php if(empty($comp['cnpj']) && empty($comp['email']) && empty($comp['phone']) && empty($comp['address']) && empty($comp['hr_whatsapp_phone'])): ?><span class="text-sm text-slate-400 italic">Sem informações adicionais cadastradas.</span><?php endif; ?>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div>
                    <div class="flex justify-between mb-3"><h4 class="font-bold text-slate-700">Setores</h4><button onclick="openModalSector(<?php echo $comp['id']; ?>)" class="text-xs bg-white border px-2 py-1 rounded">Novo</button></div>
                    <div class="space-y-2">
                        <?php if(empty($comp['sectors'])): ?><p class="text-xs text-slate-400 italic text-center py-2 border border-dashed rounded">Nenhum setor cadastrado.</p><?php endif; ?>
                        <?php foreach ($comp['sectors'] as $sector): ?>
                        <div class="bg-white p-3 rounded border flex justify-between items-center shadow-sm">
                            <div>
                                <div class="text-sm font-medium text-slate-800"><?php echo htmlspecialchars($sector['name']); ?></div>
                                <?php if($sector['manager_name']): ?>
                                    <div class="text-xs text-blue-600 flex items-center gap-1 mt-0.5"><i data-lucide="shield" class="w-3 h-3"></i> <?php echo htmlspecialchars($sector['manager_name']); ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-slate-500 flex items-center gap-1 mt-1">
                                    <i data-lucide="box" class="w-3 h-3"></i> 
                                    <?php echo $sector['asset_count']; ?> ativo<?php echo ($sector['asset_count'] != 1) ? 's' : ''; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="openModalSector(<?php echo $comp['id']; ?>, <?php echo htmlspecialchars(json_encode($sector), ENT_QUOTES); ?>)" class="p-1 text-slate-400 hover:text-blue-600 rounded-md hover:bg-slate-100"><i data-lucide="edit-2" class="w-3 h-3"></i></button>
                                <button onclick="openDeleteModal('delete_sector', <?php echo $sector['id']; ?>, 'Excluir Setor?', 'Tem certeza que deseja excluir o setor \'<?php echo htmlspecialchars($sector['name'], ENT_QUOTES); ?>\'?')" class="p-1 text-slate-400 hover:text-red-600 rounded-md hover:bg-slate-100">
                                    <i data-lucide="trash" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between mb-3"><h4 class="font-bold text-slate-700">Categorias</h4><button onclick="openModalCategory(<?php echo $comp['id']; ?>)" class="text-xs bg-white border px-2 py-1 rounded">Nova</button></div>
                    <div class="space-y-2">
                        <?php if(empty($comp['categories'])): ?><p class="text-xs text-slate-400 italic text-center py-2 border border-dashed rounded">Nenhuma categoria cadastrada.</p><?php endif; ?>
                        <?php foreach ($comp['categories'] as $cat): ?>
                        <div class="bg-white p-3 rounded border flex justify-between items-center shadow-sm">
                            <span class="text-sm font-medium"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <div class="flex items-center gap-2">
                                <button onclick="openModalCategory(<?php echo $comp['id']; ?>, <?php echo htmlspecialchars(json_encode($cat), ENT_QUOTES); ?>)" class="p-1 text-slate-400 hover:text-blue-600 rounded-md hover:bg-slate-100"><i data-lucide="edit-2" class="w-3 h-3"></i></button>
                                <button onclick='openFieldsModal(<?php echo $cat['id']; ?>, <?php echo $cat['custom_schema'] ?: "[]"; ?>)' class="p-1 text-slate-400 hover:text-blue-600 rounded-md hover:bg-slate-100"><i data-lucide="settings-2" class="w-3 h-3"></i></button>
                                <button onclick="openDeleteModal('delete_category', <?php echo $cat['id']; ?>, 'Excluir Categoria?', 'Tem certeza que deseja excluir a categoria \'<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>\'?')" class="p-1 text-slate-400 hover:text-red-600 rounded-md hover:bg-slate-100">
                                    <i data-lucide="trash" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <div class="flex justify-between mb-3"><h4 class="font-bold text-slate-700">Status</h4><button onclick="openModalStatus(<?php echo $comp['id']; ?>)" class="text-xs bg-white border px-2 py-1 rounded">Novo</button></div>
                    <div class="space-y-2">
                        <?php if(empty($comp['statuses'])): ?><p class="text-xs text-slate-400 italic text-center py-2 border border-dashed rounded">Nenhum status personalizado.</p><?php endif; ?>
                        <?php foreach ($comp['statuses'] as $st): ?>
                        <div class="bg-white p-3 rounded border flex justify-between items-center shadow-sm">
                            <span class="text-xs px-2 py-0.5 rounded font-bold <?php echo getBadgeColor($st['color']); ?>"><?php echo htmlspecialchars($st['name']); ?></span>
                            <div class="flex items-center gap-2">
                                <button onclick="openModalStatus(<?php echo $comp['id']; ?>, <?php echo htmlspecialchars(json_encode($st), ENT_QUOTES); ?>)" class="p-1 text-slate-400 hover:text-blue-600 rounded-md hover:bg-slate-100"><i data-lucide="edit-2" class="w-3 h-3"></i></button>
                                <button onclick="openDeleteModal('delete_status', <?php echo $st['id']; ?>, 'Excluir Status?', 'Tem certeza que deseja excluir o status \'<?php echo htmlspecialchars($st['name'], ENT_QUOTES); ?>\'?')" class="p-1 text-slate-400 hover:text-red-600 rounded-md hover:bg-slate-100">
                                    <i data-lucide="trash" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div id="modalSector" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('modalSector')"></div>
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="map-pin" class="w-5 h-5"></i></div>
                <h3 class="text-lg font-bold text-slate-900" id="modalSectorTitle">Novo Setor</h3>
            </div>
            <button onclick="closeModal('modalSector')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" class="flex flex-col flex-1 min-h-0">
            <div class="p-6 space-y-4 overflow-y-auto">
                <input type="hidden" name="action" id="sectorAction" value="create_sector">
                <input type="hidden" name="id" id="sectorId">
                <input type="hidden" name="company_id" id="sectorCompanyId">
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nome do Setor</label>
                    <div class="relative">
                        <i data-lucide="type" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="name" id="sectorName" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Financeiro">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Responsável (Gestor)</label>
                    <div class="relative">
                        <i data-lucide="shield" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="manager_name" id="sectorManager" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Maria Silva">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Localização Física</label>
                    <div class="relative">
                        <i data-lucide="map" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="physical_location" id="sectorLocation" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Bloco B, 2º Andar">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Descrição</label>
                    <div class="relative">
                        <i data-lucide="file-text" class="absolute left-3 top-3 w-4 h-4 text-slate-400"></i>
                        <textarea name="description" id="sectorDesc" rows="3" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all resize-none" placeholder="Detalhes opcionais..."></textarea>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modalSector')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm transition-colors flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Empresa -->
<div id="modalCompany" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('modalCompany')"></div>
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="building" class="w-5 h-5"></i></div>
                <h3 class="text-lg font-bold text-slate-900" id="modalCompanyTitle">Nova Empresa</h3>
            </div>
            <button onclick="closeModal('modalCompany')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" class="flex flex-col flex-1">
            <div class="p-6 space-y-4">
                <input type="hidden" name="action" id="companyAction" value="create_company">
                <input type="hidden" name="id" id="companyId">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nome da Empresa</label>
                    <div class="relative">
                        <i data-lucide="building-2" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="name" id="companyName" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Minha Empresa Ltda">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1.5">CNPJ</label>
                        <div class="relative">
                            <i data-lucide="file-digit" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                            <input type="text" name="cnpj" id="companyCnpj" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="00.000.000/0000-00">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1.5">Telefone</label>
                        <div class="relative">
                            <i data-lucide="phone" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                            <input type="text" name="phone" id="companyPhone" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="(00) 0000-0000">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Email Corporativo</label>
                    <div class="relative">
                        <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="email" name="email" id="companyEmail" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="contato@empresa.com">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Endereço</label>
                    <div class="relative">
                        <i data-lucide="map-pin" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="address" id="companyAddress" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Rua, Número, Bairro...">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">WhatsApp (DP/RH)</label>
                    <div class="relative">
                        <i data-lucide="message-square" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="hr_whatsapp_phone" id="companyHrPhone" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="5511999999999">
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Número para receber os termos de responsabilidade/devolução.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modalCompany')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm transition-colors flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Categoria -->
<div id="modalCategory" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('modalCategory')"></div>
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="tag" class="w-5 h-5"></i></div>
                <h3 class="text-lg font-bold text-slate-900" id="modalCategoryTitle">Nova Categoria</h3>
            </div>
            <button onclick="closeModal('modalCategory')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" class="flex flex-col flex-1">
            <div class="p-6 space-y-4">
                <input type="hidden" name="action" id="categoryAction" value="create_category">
                <input type="hidden" name="id" id="categoryId">
                <input type="hidden" name="company_id" id="categoryCompanyId">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nome da Categoria</label>
                    <div class="relative">
                        <i data-lucide="tag" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="name" id="categoryName" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Informática">
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modalCategory')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm transition-colors flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Status -->
<div id="modalStatus" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('modalStatus')"></div>
    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="activity" class="w-5 h-5"></i></div>
                <h3 class="text-lg font-bold text-slate-900" id="modalStatusTitle">Novo Status</h3>
            </div>
            <button onclick="closeModal('modalStatus')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" class="flex flex-col flex-1">
            <div class="p-6 space-y-4">
                <input type="hidden" name="action" id="statusAction" value="create_status">
                <input type="hidden" name="id" id="statusId">
                <input type="hidden" name="company_id" id="statusCompanyId">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nome do Status</label>
                    <div class="relative">
                        <i data-lucide="tag" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="name" id="statusName" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Ex: Em Manutenção">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Descrição</label>
                    <div class="relative">
                        <i data-lucide="file-text" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="description" id="statusDesc" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all" placeholder="Descrição curta...">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Cor Indicativa</label>
                    <div class="relative">
                        <i data-lucide="palette" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <select name="color" id="statusColor" class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all appearance-none">
                            <option value="gray">Cinza (Padrão)</option>
                            <option value="red">Vermelho (Alerta)</option>
                            <option value="yellow">Amarelo (Atenção)</option>
                            <option value="green">Verde (Positivo)</option>
                            <option value="blue">Azul (Informativo)</option>
                            <option value="orange">Laranja (Ação)</option>
                        </select>
                        <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modalStatus')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm transition-colors flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Campos Personalizados -->
<div id="modalFields" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" onclick="closeModal('modalFields')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl transform scale-95 opacity-0 modal-panel transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="sliders" class="w-5 h-5"></i></div>
                <h3 class="text-lg font-bold text-slate-900">Campos Personalizados</h3>
            </div>
            <button onclick="closeModal('modalFields')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" class="flex flex-col flex-1 min-h-0">
            <div class="p-6 overflow-y-auto">
                <input type="hidden" name="action" value="save_category_fields">
                <input type="hidden" name="category_id" id="fieldsCatId">
                <p class="text-sm text-slate-500 mb-4">Defina atributos extras para esta categoria (ex: Processador, Memória).</p>
                
                <div id="fieldsContainer" class="space-y-3 mb-4"></div>
                
                <button type="button" onclick="addFieldRow()" class="w-full py-2.5 border-2 border-dashed border-blue-200 rounded-xl text-sm font-bold text-blue-600 hover:bg-blue-50 hover:border-blue-300 transition-all flex items-center justify-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i> Adicionar Campo
                </button>
            </div>
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('modalFields')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-white transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm transition-colors flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Salvar Campos</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Genérico de Exclusão -->
<div id="modalDelete" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalDelete')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6 text-center">
        <div class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
        <h3 class="text-lg font-bold text-slate-900 mb-2" id="deleteModalTitle">Tem certeza?</h3>
        <p class="text-sm text-slate-500 mb-6" id="deleteModalDesc">Esta ação removerá o registro permanentemente.</p>
        <form method="POST" id="formDeleteGeneral">
            <input type="hidden" name="action" id="deleteAction" value=""><input type="hidden" name="id" id="deleteId" value="">
            <div class="flex gap-3 justify-center"><button type="button" onclick="closeModal('modalDelete')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 shadow-sm">Sim, Excluir</button></div>
        </form>
    </div>
</div>

<!-- MODAL IMPORTAR -->
<div id="modalImport" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalImport')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl"><h3 class="text-lg font-bold text-slate-900">Importar Configurações</h3><button onclick="closeModal('modalImport')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="action" value="import_all">
            <div class="mb-4 bg-blue-50 p-3 rounded-lg border border-blue-100 text-xs text-blue-800"><i data-lucide="info" class="w-3 h-3 inline mr-1"></i> Use o ficheiro XLSX exportado como modelo para garantir a formatação correta.</div>
            <div class="relative border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:bg-slate-50 hover:border-blue-400 transition-colors group cursor-pointer">
                <input type="file" name="import_file" accept=".xlsx" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="document.getElementById('fileNameDisplay').innerText = this.files[0].name; document.getElementById('fileNameDisplay').classList.remove('hidden');">
                <div class="pointer-events-none"><i data-lucide="upload-cloud" class="w-10 h-10 text-slate-400 mx-auto mb-3 group-hover:text-blue-500 transition-colors"></i><p class="text-sm font-medium text-slate-700">Clique ou arraste o arquivo XLSX</p></div>
            </div>
            <p id="fileNameDisplay" class="text-sm text-green-600 font-medium mt-2 text-center hidden"></p>
            <div class="mt-6 flex justify-end gap-2"><button type="button" onclick="closeModal('modalImport')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Processar</button></div>
        </form>
    </div>
</div>

<script>
    function openFieldsModal(catId, currentSchema) { document.getElementById('fieldsCatId').value = catId; const container = document.getElementById('fieldsContainer'); container.innerHTML = ''; if(currentSchema && currentSchema.length > 0) { currentSchema.forEach(field => addFieldRow(field.label, field.type)); } else { addFieldRow(); } openModal('modalFields'); }
    function addFieldRow(label = '', type = 'text') { const div = document.createElement('div'); div.className = 'flex gap-3 items-center animate-in fade-in slide-in-from-left-2 bg-slate-50 p-3 rounded-xl border border-slate-200'; div.innerHTML = `<div class="flex-1"><label class="text-[10px] font-bold text-slate-400 uppercase mb-1 block">Nome do Campo</label><input type="text" name="field_label[]" value="${label}" placeholder="Ex: Memória RAM" class="w-full border border-slate-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" required></div><div class="w-32"><label class="text-[10px] font-bold text-slate-400 uppercase mb-1 block">Tipo</label><select name="field_type[]" class="w-full border border-slate-300 rounded-lg p-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none"><option value="text" ${type=='text'?'selected':''}>Texto</option><option value="number" ${type=='number'?'selected':''}>Número</option><option value="date" ${type=='date'?'selected':''}>Data</option><option value="boolean" ${type=='boolean'?'selected':''}>Sim/Não</option></select></div><button type="button" onclick="this.parentElement.remove()" class="mt-4 p-2 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i></button>`; document.getElementById('fieldsContainer').appendChild(div); lucide.createIcons(); }
</script>

<script>
    lucide.createIcons();

    function toggleExportMenu() { document.getElementById('exportMenu').classList.toggle('hidden'); }
    document.addEventListener('click', function(e) { 
        const menu = document.getElementById('exportMenu'); 
        if (menu && !menu.classList.contains('hidden') && !e.target.closest('.relative')) {
            menu.classList.add('hidden');
        }
    });

    function toggleDetails(el) { const content = el.nextElementSibling; const icon = el.querySelector('.chevron-icon'); content.classList.toggle('hidden'); icon.classList.toggle('rotate-180'); }
    function openModal(id) { 
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden'); 
        setTimeout(() => { 
            el.querySelector('.modal-backdrop')?.classList.remove('opacity-0'); 
            el.querySelector('.modal-panel')?.classList.remove('scale-95', 'opacity-0'); 
            el.querySelector('.modal-panel')?.classList.add('scale-100', 'opacity-100'); 
        }, 10); 
    }
    function closeModal(id) { 
        const modal = document.getElementById(id);
        if (!modal) return;
        const panel = modal.querySelector('.modal-panel');
        if (panel) {
            panel.classList.add('scale-95', 'opacity-0'); 
            panel.classList.remove('scale-100', 'opacity-100'); 
        }
        modal.querySelector('.modal-backdrop')?.classList.add('opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 300); 
    }
    function openCompanyModal(data = null) { if (data) { document.getElementById('modalCompanyTitle').innerText = 'Editar Empresa'; document.getElementById('companyAction').value = 'update_company'; document.getElementById('companyId').value = data.id; document.getElementById('companyName').value = data.name; document.getElementById('companyCnpj').value = data.cnpj; document.getElementById('companyEmail').value = data.email || ''; document.getElementById('companyPhone').value = data.phone || ''; document.getElementById('companyAddress').value = data.address || ''; } else { document.getElementById('modalCompanyTitle').innerText = 'Nova Empresa'; document.getElementById('companyAction').value = 'create_company'; document.getElementById('companyId').value = ''; document.getElementById('companyName').value = ''; document.getElementById('companyCnpj').value = ''; document.getElementById('companyEmail').value = ''; document.getElementById('companyPhone').value = ''; document.getElementById('companyAddress').value = ''; } openModal('modalCompany'); }
    
    function openModalSector(companyId, data = null) {
        document.getElementById('sectorCompanyId').value = companyId;
        if (data) {
            document.getElementById('modalSectorTitle').innerText = 'Editar Setor';
            document.getElementById('sectorAction').value = 'update_sector';
            document.getElementById('sectorId').value = data.id;
            document.getElementById('sectorName').value = data.name;
            document.getElementById('sectorLocation').value = data.physical_location || '';
            document.getElementById('sectorDesc').value = data.description || '';
            document.getElementById('sectorManager').value = data.manager_name || '';
        } else {
            document.getElementById('modalSectorTitle').innerText = 'Novo Setor';
            document.getElementById('sectorAction').value = 'create_sector';
            document.getElementById('sectorId').value = '';
            document.getElementById('sectorName').value = '';
            document.getElementById('sectorLocation').value = '';
            document.getElementById('sectorDesc').value = '';
            document.getElementById('sectorManager').value = '';
        }
        openModal('modalSector');
    }

    function openModalCategory(companyId, data = null) { document.getElementById('categoryCompanyId').value = companyId; if (data) { document.getElementById('modalCategoryTitle').innerText = 'Editar Categoria'; document.getElementById('categoryAction').value = 'update_category'; document.getElementById('categoryId').value = data.id; document.getElementById('categoryName').value = data.name; } else { document.getElementById('modalCategoryTitle').innerText = 'Nova Categoria'; document.getElementById('categoryAction').value = 'create_category'; document.getElementById('categoryId').value = ''; document.getElementById('categoryName').value = ''; } openModal('modalCategory'); }
    function openModalStatus(companyId, data = null) { document.getElementById('statusCompanyId').value = companyId; if (data) { document.getElementById('modalStatusTitle').innerText = 'Editar Status'; document.getElementById('statusAction').value = 'update_status'; document.getElementById('statusId').value = data.id; document.getElementById('statusName').value = data.name; document.getElementById('statusDesc').value = data.description; document.getElementById('statusColor').value = data.color; } else { document.getElementById('modalStatusTitle').innerText = 'Novo Status'; document.getElementById('statusAction').value = 'create_status'; document.getElementById('statusId').value = ''; document.getElementById('statusName').value = ''; document.getElementById('statusDesc').value = ''; document.getElementById('statusColor').value = 'gray'; } openModal('modalStatus'); }

    function openDeleteModal(action, id, title, description) {
        document.getElementById('deleteAction').value = action;
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteModalTitle').innerText = title;
        document.getElementById('deleteModalDesc').innerText = description;
        openModal('modalDelete');
    }

    function openDeleteCompanyModal(companyId, companyName) {
        const title = `Excluir a empresa '${companyName}'?`;
        const description = "Atenção: Esta ação é irreversível. Todos os dados associados a esta empresa (ativos, setores, categorias, status, movimentações, etc.) serão permanentemente apagados.";
        
        // Usa o modal de exclusão genérico com a mensagem de aviso aprimorada
        openDeleteModal('delete_company', companyId, title, description);
    }

    function exportData(format) {
        const companiesData = <?php echo $companiesJson; ?>;
        
        const companiesSheet = companiesData.map(c => ({ 'ID': c.id, 'Nome': c.name, 'CNPJ': c.cnpj }));
        const sectorsSheet = companiesData.flatMap(c => c.sectors.map(s => ({ 'ID': s.id, 'Empresa': c.name, 'Setor': s.name, 'Gestor': s.manager_name, 'Local Físico': s.physical_location, 'Descrição': s.description })));
        const categoriesSheet = companiesData.flatMap(c => c.categories.map(cat => ({ 'ID': cat.id, 'Empresa': c.name, 'Categoria': cat.name })));
        const statusesSheet = companiesData.flatMap(c => c.statuses.map(st => ({ 'ID': st.id, 'Empresa': c.name, 'Status': st.name, 'Cor': st.color, 'Descrição': st.description })));

        if (format === 'xlsx') {
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(companiesSheet), "Empresas");
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(sectorsSheet), "Setores");
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(categoriesSheet), "Categorias");
            XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(statusesSheet), "Status");
            XLSX.writeFile(wb, "export_companies.xlsx");
        } else { // CSV
            const zip = new JSZip();
            zip.file("empresas.csv", XLSX.utils.sheet_to_csv(XLSX.utils.json_to_sheet(companiesSheet)));
            zip.file("setores.csv", XLSX.utils.sheet_to_csv(XLSX.utils.json_to_sheet(sectorsSheet)));
            zip.file("categorias.csv", XLSX.utils.sheet_to_csv(XLSX.utils.json_to_sheet(categoriesSheet)));
            zip.file("status.csv", XLSX.utils.sheet_to_csv(XLSX.utils.json_to_sheet(statusesSheet)));
            
            zip.generateAsync({type:"blob"}).then(function(content) {
                const link = document.createElement("a");
                link.href = URL.createObjectURL(content);
                link.download = "export_companies.zip";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });
        }
        toggleExportMenu();
    }

    function filterCompanies() {
        const term = document.getElementById('searchCompany').value.toLowerCase();
        const cards = document.querySelectorAll('.company-card');
        
        cards.forEach(card => {
            const searchData = card.getAttribute('data-search');
            if (searchData.includes(term)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
</script>