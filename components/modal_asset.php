<?php
// components/modal_asset.php

// Carrega dados necessários se não estiverem definidos
if (!isset($locations)) {
    try { $locations = $pdo->query("SELECT id, name, company_id, manager_name FROM locations ORDER BY name ASC")->fetchAll(); } catch(Exception $e) { $locations = []; }
}
if (!isset($companies)) {
    $companies = $pdo->query("SELECT id, name FROM companies")->fetchAll();
}
if (!isset($categories)) {
    try { $categories = $pdo->query("SELECT id, name, custom_schema, company_id FROM categories")->fetchAll(); } catch(Exception $e) { $categories = []; }
}
if (!isset($statuses)) {
    try { $statuses = $pdo->query("SELECT id, name, company_id FROM asset_statuses ORDER BY name ASC")->fetchAll(); } catch(Exception $e) { $statuses = []; }
}
if (!isset($locationsJson)) {
    $locationsJson = json_encode($locations);
}
if (!isset($qr_base_url)) {
    // Lógica simplificada para obter a URL base
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $base_path = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
    $qr_base_url = $protocol . $domain . trim($base_path);
}
?>

<div id="modalAsset" class="fixed inset-0 z-[80] hidden items-center justify-center p-4 sm:p-6 flex">
    <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-md transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalAsset')"></div>
    
    <div class="relative w-full max-w-4xl bg-white rounded-3xl shadow-2xl flex flex-col max-h-[90vh] transition-all transform scale-95 opacity-0 modal-panel overflow-hidden">
        
        <form method="POST" enctype="multipart/form-data" id="formAsset" class="flex flex-col overflow-hidden">
            <input type="hidden" name="action" id="assetAction" value="create_asset">
            <input type="hidden" name="id" id="assetId">
            <input type="hidden" name="current_photo" id="assetCurrentPhoto">
            
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white shrink-0 z-20">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center shadow-lg shadow-blue-200">
                        <i data-lucide="box" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 leading-tight" id="modalAssetTitle">Novo Ativo</h3>
                        <p class="text-sm text-slate-500 font-medium">Dados do equipamento</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modalAsset')" class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-full transition-all">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <div class="flex-1 overflow-y-auto min-h-0 bg-slate-50/50 p-6 sm:p-8 space-y-8 custom-scrollbar overscroll-contain">
                
                <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative group hover:border-blue-300 transition-colors">
                    <div class="absolute top-0 right-0 p-6 opacity-5 pointer-events-none text-blue-600">
                        <i data-lucide="scan-barcode" class="w-24 h-24"></i>
                    </div>
                    
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span> Identificação
                    </h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                        <div class="col-span-1 md:col-span-2 relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Empresa</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="building-2" class="w-5 h-5"></i></div>
                                <select name="company_id" id="assetCompany" onchange="filterSectors(); filterCategories(); filterStatuses()" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none appearance-none font-medium text-slate-700 h-12">
                                    <?php 
                                    $current_company = $_SESSION['user_company_id'] ?? 1;
                                    foreach($companies as $c) echo "<option value='{$c['id']}' " . ($c['id'] == $current_company ? 'selected' : '') . ">{$c['name']}</option>"; 
                                    ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div class="col-span-1 md:col-span-2 relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Nome do Ativo *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="type" class="w-5 h-5"></i></div>
                                <input type="text" name="name" id="assetName" required 
                                       class="w-full pl-12 pr-4 py-3.5 border border-slate-300 rounded-xl text-sm font-medium focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all placeholder:text-slate-400 h-12" 
                                       placeholder="Ex: Notebook Dell Latitude 3520">
                            </div>
                        </div>

                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Código Patrimônio *</label>
                            <div class="flex gap-2 relative">
                                <div class="relative flex-1">
                                    <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="barcode" class="w-5 h-5"></i></div>
                                    <input type="text" name="code" id="assetCode" required 
                                           class="w-full pl-12 pr-4 py-3.5 border border-slate-300 rounded-xl text-sm font-mono font-bold text-slate-700 focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all h-12" 
                                           placeholder="PAT-0000">
                                    <div id="codeWarning" class="hidden absolute -bottom-5 left-0 text-xs text-red-600 font-medium flex items-center gap-1">
                                        <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                        <span>Código já em uso.</span>
                                    </div>
                                </div>
                                
                                <button type="button" onclick="toggleScanMenu(event)" class="px-3.5 bg-white border border-slate-300 text-slate-600 rounded-xl hover:bg-slate-50 hover:border-blue-400 hover:text-blue-600 transition-all shadow-sm" title="Escanear">
                                    <i data-lucide="scan-line" class="w-5 h-5"></i>
                                </button>
                                <button type="button" onclick="generateCode()" class="px-3.5 bg-white border border-slate-300 text-slate-600 rounded-xl hover:bg-slate-50 hover:border-blue-400 hover:text-blue-600 transition-all shadow-sm" title="Gerar Aleatório">
                                    <i data-lucide="wand-2" class="w-5 h-5"></i>
                                </button>
                                <button type="button" onclick="printAssetLabel()" class="px-3.5 bg-white border border-slate-300 text-slate-600 rounded-xl hover:bg-slate-50 hover:border-blue-400 hover:text-blue-600 transition-all shadow-sm" title="Imprimir Etiqueta">
                                    <i data-lucide="printer" class="w-5 h-5"></i>
                                </button>

                                <div id="scanMenu" class="hidden absolute right-0 top-full mt-2 w-56 bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden flex flex-col animate-in fade-in zoom-in duration-200">
                                    <button type="button" onclick="startLocalScan(); toggleScanMenu()" class="text-left px-4 py-3.5 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3 border-b border-slate-50 font-medium">
                                        <i data-lucide="camera" class="w-4 h-4 text-blue-600"></i> Câmera do PC
                                    </button>
                                    <button type="button" onclick="startRemoteScan(); toggleScanMenu()" class="text-left px-4 py-3.5 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-3 font-medium">
                                        <i data-lucide="smartphone" class="w-4 h-4 text-purple-600"></i> Conectar Celular
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Categoria</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="tag" class="w-5 h-5"></i></div>
                                <select name="category_id" id="assetCategory" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none appearance-none font-medium text-slate-700 h-12">
                                    <option value="">Selecione...</option>
                                    <?php foreach($categories as $cat) echo "<option value='{$cat['id']}'>{$cat['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-orange-500"></span> Localização & Estado
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Setor / Local *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-orange-500 transition-colors"><i data-lucide="map-pin" class="w-5 h-5"></i></div>
                                <select name="location_id" id="assetLocation" onchange="updateManagerInfoAsset()" required class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-orange-100 focus:border-orange-500 outline-none appearance-none font-medium text-slate-700 h-12">
                                    </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Status Atual</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-orange-500 transition-colors"><i data-lucide="activity" class="w-5 h-5"></i></div>
                                <select name="status" id="assetStatus" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-orange-100 focus:border-orange-500 outline-none appearance-none font-medium text-slate-700 h-12">
                                    <?php foreach($statuses as $st) echo "<option value='{$st['name']}'>{$st['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <div id="managerInfoCardAsset" class="hidden mt-4 bg-blue-50 border border-blue-200 p-4 rounded-xl flex items-center justify-between animate-in fade-in slide-in-from-top-2">
                        <div class="flex items-center gap-3">
                            <div class="bg-white p-2 rounded-full text-blue-600 shadow-sm"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
                            <div>
                                <p class="text-xs text-blue-500 font-bold uppercase">Gestor do Setor</p>
                                <p class="text-sm font-bold text-blue-900" id="managerNameDisplayAsset">-</p>
                                <input type="hidden" name="location_manager_name" id="managerNameInputAsset">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer bg-white px-3 py-2 rounded-lg border border-blue-100 hover:border-blue-300 transition-colors shadow-sm">
                            <input type="checkbox" name="manager_confirmed_check" class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                            <span class="text-xs font-bold text-slate-600">Confirmar Agora</span>
                        </label>
                    </div>
                </section>

                <div id="customFieldsArea" class="hidden bg-blue-50 p-6 rounded-3xl border border-blue-100 dashed-border">
                    <h5 class="text-sm font-bold text-blue-800 mb-4 flex items-center gap-2">
                        <i data-lucide="sliders" class="w-4 h-4"></i> Especificações Técnicas
                    </h5>
                    <div id="customFieldsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-5"></div>
                </div>

                <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-purple-500"></span> Detalhes
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Marca</label>
                            <input type="text" name="brand" id="assetBrand" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none h-12">
                        </div>
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Modelo</label>
                            <input type="text" name="model" id="assetModel" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none h-12">
                        </div>
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Nº Série</label>
                            <input type="text" name="serial_number" id="assetSerial" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none h-12">
                        </div>
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Responsável</label>
                            <input type="text" name="responsible_name" id="assetResponsible" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none h-12" placeholder="Ex: João da Silva">
                        </div>
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Centro de Custo</label>
                            <input type="text" name="cost_center" id="assetCost" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none h-12">
                        </div>
                        <div class="md:col-span-2 group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Descrição / Observações</label>
                            <textarea name="description" id="assetDescription" class="w-full p-4 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-purple-100 focus:border-purple-500 outline-none resize-none" rows="3"></textarea>
                        </div>
                    </div>
                </section>

                <section class="bg-gradient-to-br from-indigo-50 to-white p-6 rounded-3xl border border-indigo-100 shadow-sm relative overflow-hidden">
                    <div class="absolute -right-6 -top-6 w-32 h-32 bg-indigo-100 rounded-full opacity-50 blur-2xl"></div>
                    <h4 class="text-xs font-bold text-indigo-400 uppercase tracking-widest mb-6 flex items-center gap-2 relative z-10">
                        <i data-lucide="calendar-clock" class="w-4 h-4 text-indigo-600"></i> Plano de Manutenção
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Periodicidade (dias)</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-indigo-600 transition-colors"><i data-lucide="repeat" class="w-5 h-5"></i></div>
                                <input type="number" name="maintenance_freq" id="assetFreq" class="w-full pl-12 pr-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none transition-all h-12" placeholder="Ex: 90">
                            </div>
                        </div>
                        <div class="group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Próxima Execução</label>
                            <input type="date" name="next_maintenance_date" id="assetNextMaint" onchange="validateMaintenanceDate()" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-indigo-100 focus:border-indigo-500 outline-none text-slate-600 h-12">
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm h-full">
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span> Financeiro
                        </h4>
                        <div class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="group/input">
                                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Aquisição</label>
                                    <input type="date" name="acquisition_date" id="assetDate" onchange="validateDates()" class="w-full px-3 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-green-100 focus:border-green-500 outline-none text-slate-600 h-12">
                                </div>
                                <div class="group/input">
                                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Garantia</label>
                                    <input type="date" name="warranty_date" id="assetWarranty" onchange="validateDates()" class="w-full px-3 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-green-100 focus:border-green-500 outline-none text-slate-600 h-12">
                                </div>
                                <div class="group/input">
                                    <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Vida Útil (anos)</label>
                                    <input type="number" name="lifespan_years" id="assetLifespan" class="w-full px-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-green-100 focus:border-green-500 outline-none h-12" placeholder="5">
                                </div>
                            </div>
                            <div class="relative group/input">
                                <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Valor Original</label>
                                <div class="relative">
                                    <div class="absolute left-4 top-3.5 text-slate-400 font-bold group-focus-within/input:text-green-600">R$</div>
                                    <input type="text" name="value" id="assetValue" oninput="formatCurrency(this)" class="w-full pl-12 pr-4 py-3.5 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-green-100 focus:border-green-500 outline-none h-12" placeholder="0,00">
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm h-full flex flex-col">
                        <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-500"></span> Imagem
                        </h4>
                        <div class="flex-1 flex gap-4">
                            <div id="photoPreview" class="w-24 h-24 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-center overflow-hidden hidden shrink-0 shadow-sm">
                                <img src="" class="w-full h-full object-cover">
                            </div>
                            <label class="flex-1 flex flex-col items-center justify-center h-full border-2 border-dashed border-slate-300 rounded-2xl cursor-pointer hover:bg-slate-50 hover:border-blue-400 transition-all group min-h-[120px]">
                                <div class="flex flex-col items-center justify-center p-4 text-center">
                                    <div class="p-3 bg-slate-100 rounded-full mb-2 group-hover:bg-blue-100 text-slate-400 group-hover:text-blue-600 transition-colors">
                                        <i data-lucide="cloud-upload" class="w-6 h-6"></i>
                                    </div>
                                    <p class="text-xs font-bold text-slate-500 group-hover:text-slate-700">Clique para enviar</p>
                                    <p class="text-[10px] text-slate-400 mt-1">JPG, PNG (Max 5MB)</p>
                                </div>
                                <input type="file" name="photo" class="hidden" onchange="previewImage(this)">
                            </label>
                        </div>
                    </section>
                </div>

                <section id="assetHistorySection" class="hidden bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <i data-lucide="history" class="w-4 h-4"></i> Histórico de Movimentações
                    </h4>
                    <div class="relative ml-2 space-y-0" id="assetHistoryList">
                    </div>
                </section>
                
                <div class="h-4"></div>
            </div>
            
            <div class="px-8 py-5 bg-white border-t border-slate-100 flex justify-end gap-4 rounded-b-3xl shrink-0 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)] z-20">
                <button type="button" onclick="closeModal('modalAsset')" class="px-6 py-3.5 border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:bg-slate-50 hover:text-slate-800 transition-all">
                    Cancelar
                </button>
                <button type="submit" id="btnSaveAsset" class="px-8 py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold shadow-lg shadow-blue-200 transition-all flex items-center gap-2 transform active:scale-[0.98]">
                    <i data-lucide="check" class="w-5 h-5"></i> Salvar Ativo
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Garante que as variáveis PHP/JSON estejam disponíveis globalmente ou sejam passadas
    var allLocations = <?php echo $locationsJson ?? '[]'; ?>;
    var categoriesData = <?php echo json_encode($categories ?? []); ?>;
    var statusesData = <?php echo json_encode($statuses ?? []); ?>;
    const qrBaseUrl = "<?php echo trim($qr_base_url ?? ''); ?>";

    // --- SCANNER LOGIC ---
    function toggleScanMenu(event) {
        if (event) {
            event.stopPropagation();
        }
        const menu = document.getElementById('scanMenu');
        menu.classList.toggle('hidden');
    }

    let scanPollInterval; let localHtml5QrcodeScanner;
    
    function startLocalScan() {
        const modal = document.getElementById('modalSmartScan');
        modal.classList.remove('hidden');
        document.getElementById('scanTitle').innerText = "Escanear Etiqueta"; 
        document.getElementById('desktopConnectView').classList.add('hidden'); 
        document.getElementById('mobileCameraView').classList.remove('hidden');
        if(!localHtml5QrcodeScanner) { 
            localHtml5QrcodeScanner = new Html5QrcodeScanner("localReader", { fps: 10, qrbox: 250 }); 
            localHtml5QrcodeScanner.render((decodedText) => { handleScannedCode(decodedText); closeScanner(); }); 
        }
    }

    function startRemoteScan() {
        const modal = document.getElementById('modalSmartScan');
        modal.classList.remove('hidden');
        document.getElementById('scanTitle').innerText = "Usar Celular como Leitor"; 
        document.getElementById('mobileCameraView').classList.add('hidden'); 
        document.getElementById('desktopConnectView').classList.remove('hidden');
        
        const sessionId = 'sess_' + Math.random().toString(36).substr(2, 9);
        const formData = new FormData(); formData.append('action', 'create'); formData.append('session_id', sessionId); 
        fetch('api_scan.php', { method: 'POST', body: formData });
        
        const mobileUrl = `${qrBaseUrl}/mobile_scanner.php?session=${sessionId}`; 
        document.getElementById('sessionQrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=${encodeURIComponent(mobileUrl)}`;
        
        if(scanPollInterval) clearInterval(scanPollInterval);
        scanPollInterval = setInterval(() => { 
            const checkData = new FormData(); checkData.append('action', 'check'); checkData.append('session_id', sessionId); 
            fetch('api_scan.php', { method: 'POST', body: checkData }).then(res => res.json()).then(data => { if (data.status === 'found') { handleScannedCode(data.code); closeScanner(); } }); 
        }, 2000);
    }

    function closeScanner() { document.getElementById('modalSmartScan').classList.add('hidden'); if(scanPollInterval) clearInterval(scanPollInterval); if(localHtml5QrcodeScanner) { try { localHtml5QrcodeScanner.clear(); localHtml5QrcodeScanner = null; } catch(e){} } }
    
    function handleScannedCode(code) {
        let cleanCode = code; 
        if (code.includes('http') || code.includes('www')) {
            try {
                const urlObj = new URL(code.startsWith('http') ? code : 'http://' + code);
                const params = new URLSearchParams(urlObj.search);
                if (params.has('code')) cleanCode = params.get('code');
                else if (params.has('id')) cleanCode = params.get('id');
                else if (params.has('asset')) cleanCode = params.get('asset');
                else {
                    const segments = urlObj.pathname.split('/').filter(s => s !== '');
                    if (segments.length > 0) cleanCode = segments[segments.length - 1];
                }
            } catch(e) {
                const parts = code.split('/');
                cleanCode = parts[parts.length - 1];
            }
        }
        cleanCode = cleanCode.trim();
        document.getElementById('assetCode').value = cleanCode;
        const input = document.getElementById('assetCode'); 
        input.classList.add('bg-green-50', 'border-green-500', 'text-green-700'); 
        setTimeout(() => { input.classList.remove('bg-green-50', 'border-green-500', 'text-green-700'); }, 1500);
    }

    // --- CRUD ---
    function openAssetModal(data = null, code = null) {
        const form = document.getElementById('formAsset');
        removeCodeWarning();
        if (data) {
            document.getElementById('modalAssetTitle').innerText = 'Editar Ativo'; document.getElementById('assetAction').value = 'update_asset'; document.getElementById('assetId').value = data.id;
            document.getElementById('assetName').value = data.name; document.getElementById('assetCode').value = data.code;
            document.getElementById('assetStatus').value = data.status || 'Ativo'; if(document.getElementById('assetQrLevel')) document.getElementById('assetQrLevel').value = data.qr_access_level || 'public';
            document.getElementById('assetValue').value = data.value ? parseFloat(data.value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''; document.getElementById('assetBrand').value = data.brand || ''; document.getElementById('assetModel').value = data.model || '';
            document.getElementById('assetSerial').value = data.serial_number || ''; document.getElementById('assetDate').value = data.acquisition_date || ''; document.getElementById('assetWarranty').value = data.warranty_date || ''; document.getElementById('assetDescription').value = data.description || '';
            document.getElementById('assetCost').value = data.cost_center || ''; document.getElementById('assetCompany').value = data.company_id; document.getElementById('assetCurrentPhoto').value = data.photo_url || '';
            document.getElementById('assetResponsible').value = data.responsible_name || '';
            
            document.getElementById('assetFreq').value = data.maintenance_freq || '';
            document.getElementById('assetNextMaint').value = data.next_maintenance_date || '';
            document.getElementById('assetLifespan').value = data.lifespan_years || 5;

            const preview = document.getElementById('photoPreview'); if(data.photo_url) { preview.classList.remove('hidden'); preview.querySelector('img').src = data.photo_url; } else { preview.classList.add('hidden'); }
            filterSectors(data.location_id); filterCategories(data.category_id); filterStatuses(data.status); let currentCustomData = {}; if (data.custom_attributes) { try { currentCustomData = JSON.parse(data.custom_attributes); } catch(e) {} } renderCustomFields(data.category_id, currentCustomData);
            loadAssetHistory(data.id);
        } else {
            document.getElementById('modalAssetTitle').innerText = 'Novo Ativo'; document.getElementById('assetAction').value = 'create_asset'; document.getElementById('assetId').value = '';
            document.getElementById('assetCurrentPhoto').value = ''; document.getElementById('photoPreview').classList.add('hidden'); 
            form.reset(); 
            document.getElementById('assetDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('assetLifespan').value = 5;
            if (code) {
                document.getElementById('assetCode').value = code;
            }
            document.getElementById('assetHistorySection').classList.add('hidden');
            filterSectors(); filterCategories(); filterStatuses('Ativo'); renderCustomFields(null);
        }
        updateManagerInfoAsset();
        openModal('modalAsset');
    }
    const catSelect = document.getElementById('assetCategory'); if(catSelect) catSelect.addEventListener('change', function() { renderCustomFields(this.value); });
    function renderCustomFields(catId, existingValues = null) {
        const container = document.getElementById('customFieldsArea'); const grid = document.getElementById('customFieldsGrid'); if(!container || !grid) return; grid.innerHTML = '';
        const category = categoriesData.find(c => c.id == catId); if (!category || !category.custom_schema) { container.classList.add('hidden'); return; }
        let schema = []; try { schema = JSON.parse(category.custom_schema); } catch(e) {} if (schema.length === 0) { container.classList.add('hidden'); return; }
        container.classList.remove('hidden');
        schema.forEach(field => {
            const value = existingValues ? (existingValues[field.key] || '') : '';
            let inputHtml = (field.type === 'boolean') ? `<select name="custom_fields[${field.key}]" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white"><option value="Não" ${value=='Não'?'selected':''}>Não</option><option value="Sim" ${value=='Sim'?'selected':''}>Sim</option></select>` : `<input type="${field.type}" name="custom_fields[${field.key}]" value="${value}" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm">`;
            const div = document.createElement('div'); div.innerHTML = `<label class="block text-sm font-medium text-slate-700 mb-1">${field.label}</label>${inputHtml}`; grid.appendChild(div);
        });
    }
    function filterSectors(selectedLocationId = null, companySelectId = 'assetCompany', locationSelectId = 'assetLocation') {
        const companySelect = document.getElementById(companySelectId); 
        const locationSelect = document.getElementById(locationSelectId); 
        if(!locationSelect) return;
        
        locationSelect.innerHTML = '<option value="">Selecione...</option>'; 
        const companyId = companySelect ? companySelect.value : null;
        if (!companyId) return;
        
        const filtered = allLocations.filter(loc => loc.company_id == companyId);
        filtered.forEach(loc => { const option = document.createElement('option'); option.value = loc.id; option.text = loc.name; if (selectedLocationId && loc.id == selectedLocationId) option.selected = true; locationSelect.appendChild(option); });
    }

    function filterCategories(selectedCategoryId = null, companySelectId = 'assetCompany', categorySelectId = 'assetCategory') {
        const companySelect = document.getElementById(companySelectId); 
        const categorySelect = document.getElementById(categorySelectId); 
        if(!categorySelect) return;
        
        categorySelect.innerHTML = '<option value="">Selecione...</option>'; 
        const companyId = companySelect ? companySelect.value : null;
        if (!companyId) return;
        
        const filtered = categoriesData.filter(cat => cat.company_id == companyId);
        filtered.forEach(cat => { const option = document.createElement('option'); option.value = cat.id; option.text = cat.name; if (selectedCategoryId && cat.id == selectedCategoryId) option.selected = true; categorySelect.appendChild(option); });
    }

    function filterStatuses(selectedStatus = null, companySelectId = 'assetCompany', statusSelectId = 'assetStatus') {
        const companySelect = document.getElementById(companySelectId); 
        const statusSelect = document.getElementById(statusSelectId); 
        if(!statusSelect) return;
        
        statusSelect.innerHTML = ''; 
        const companyId = companySelect ? companySelect.value : null;
        if (!companyId) return;
        
        const filtered = statusesData.filter(st => st.company_id == companyId);
        const uniqueNames = new Set();
        filtered.forEach(st => { if(!uniqueNames.has(st.name)) { uniqueNames.add(st.name); const option = document.createElement('option'); option.value = st.name; option.text = st.name; if (selectedStatus && st.name == selectedStatus) option.selected = true; statusSelect.appendChild(option); } });
    }

    function updateManagerInfoAsset() {
        const select = document.getElementById('assetLocation');
        const card = document.getElementById('managerInfoCardAsset');
        const display = document.getElementById('managerNameDisplayAsset');
        const input = document.getElementById('managerNameInputAsset');
        const locId = select.value;
        const location = allLocations.find(l => l.id == locId);
        
        if (location && location.manager_name) {
            display.innerText = location.manager_name;
            input.value = location.manager_name;
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
            input.value = '';
        }
    }

    function previewImage(input) {
        const preview = document.getElementById('photoPreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.querySelector('img').src = e.target.result;
                preview.classList.remove('hidden');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function generateCode() { document.getElementById('assetCode').value = `PAT-${new Date().getFullYear()}-${Math.floor(1000 + Math.random() * 9000)}`; }
    
    function cloneAsset(data) {
        const form = document.getElementById('formAsset');
        form.reset();
        document.getElementById('modalAssetTitle').innerText = 'Clonar Ativo';
        document.getElementById('assetAction').value = 'create_asset';
        document.getElementById('assetId').value = '';
        document.getElementById('assetName').value = data.name + ' (Cópia)';
        document.getElementById('assetStatus').value = data.status || 'Ativo';
        if(document.getElementById('assetQrLevel')) document.getElementById('assetQrLevel').value = data.qr_access_level || 'public';
        document.getElementById('assetValue').value = data.value ? parseFloat(data.value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
        document.getElementById('assetBrand').value = data.brand || '';
        document.getElementById('assetModel').value = data.model || '';
        document.getElementById('assetCost').value = data.cost_center || '';
        document.getElementById('assetResponsible').value = data.responsible_name || '';
        document.getElementById('assetDescription').value = data.description || '';
        document.getElementById('assetLifespan').value = data.lifespan_years || 5;
        document.getElementById('assetFreq').value = data.maintenance_freq || '';
        document.getElementById('assetCurrentPhoto').value = data.photo_url || '';
        const preview = document.getElementById('photoPreview');
        if(data.photo_url) { preview.classList.remove('hidden'); preview.querySelector('img').src = data.photo_url; } else { preview.classList.add('hidden'); }
        document.getElementById('assetDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('assetWarranty').value = '';
        generateCode();
        document.getElementById('assetSerial').value = '';
        removeCodeWarning();
        document.getElementById('assetCompany').value = data.company_id;
        filterSectors(data.location_id);
        filterCategories(data.category_id);
        filterStatuses(data.status);
        let currentCustomData = {}; 
        if (data.custom_attributes) { try { currentCustomData = JSON.parse(data.custom_attributes); } catch(e) {} }
        renderCustomFields(data.category_id, currentCustomData);
        document.getElementById('assetHistorySection').classList.add('hidden');
        openModal('modalAsset');
    }

    const assetCodeInput = document.getElementById('assetCode');
    if(assetCodeInput) {
        assetCodeInput.addEventListener('blur', checkAssetCode);
    }

    async function checkAssetCode() {
        const input = document.getElementById('assetCode');
        const code = input.value.trim();
        const assetId = document.getElementById('assetId').value;
        const btnSave = document.getElementById('btnSaveAsset');

        if (code === '') {
            removeCodeWarning();
            return;
        }

        const response = await fetch(`api_check_code.php?code=${encodeURIComponent(code)}&id=${assetId}`);
        const data = await response.json();

        if (data.exists) {
            input.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-100');
            document.getElementById('codeWarning').classList.remove('hidden');
            if(btnSave) {
                btnSave.disabled = true;
                btnSave.classList.add('opacity-50', 'cursor-not-allowed');
            }
        } else {
            removeCodeWarning();
        }
    }

    function validateDates() {
        const acq = document.getElementById('assetDate').value;
        const war = document.getElementById('assetWarranty').value;
        if (acq && war && war < acq) {
            alert('A data de garantia não pode ser anterior à data de aquisição.');
            document.getElementById('assetWarranty').value = '';
        }
    }

    function validateMaintenanceDate() {
        const nextMaint = document.getElementById('assetNextMaint').value;
        if (!nextMaint) return;

        const date = new Date();
        const today = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        
        if (nextMaint < today) {
            alert('A data da próxima manutenção não pode ser anterior à data atual.');
            document.getElementById('assetNextMaint').value = '';
        }
    }

    function formatCurrency(input) {
        let value = input.value.replace(/\D/g, '');
        if (value === '') {
            input.value = '';
            return;
        }
        value = (parseInt(value) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        input.value = value;
    }

    function removeCodeWarning() {
        const input = document.getElementById('assetCode');
        const btnSave = document.getElementById('btnSaveAsset');
        input.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-100');
        document.getElementById('codeWarning').classList.add('hidden');
        if(btnSave) {
            btnSave.disabled = false;
            btnSave.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }

    function printAssetLabel() {
        const code = document.getElementById('assetCode').value;
        const name = document.getElementById('assetName').value || 'Ativo';
        
        if (!code) {
            alert('Informe ou gere um Código de Patrimônio para imprimir a etiqueta.');
            return;
        }

        const win = window.open('', '_blank', 'width=400,height=400');
        win.document.write(`
            <html>
            <head>
                <title>Etiqueta ${code}</title>
                <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}.tag{border:2px solid #000;padding:10px;text-align:center;border-radius:8px;width:200px}.name{font-size:12px;font-weight:bold;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.qr{width:100px;height:100px}.code{font-family:monospace;font-size:14px;font-weight:bold;margin-top:5px}</style>
            </head>
            <body>
                <div class="tag">
                    <div class="name">${name}</div>
                    <img class="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(code)}" onload="window.print();" />
                    <div class="code">${code}</div>
                </div>
            </body></html>
        `);
        win.document.close();
    }

    async function loadAssetHistory(assetId) {
        const section = document.getElementById('assetHistorySection');
        const list = document.getElementById('assetHistoryList');
        
        if (!assetId) {
            section.classList.add('hidden');
            return;
        }
        
        section.classList.remove('hidden');
        list.innerHTML = '<div class="pl-8 text-sm text-slate-400 flex items-center gap-2 py-4"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Carregando histórico...</div>';
        lucide.createIcons();

        try {
            const response = await fetch(`api_asset_history.php?asset_id=${assetId}`);
            const logs = await response.json();
            list.innerHTML = '';

            if (!Array.isArray(logs) || logs.length === 0) {
                list.innerHTML = '<div class="pl-8 text-sm text-slate-400 italic py-2">Nenhum registro encontrado.</div>';
                return;
            }

            logs.forEach(log => {
                const item = document.createElement('div');
                item.className = 'relative pl-8 pb-8 last:pb-0 border-l border-slate-200 last:border-0';
                const date = new Date(log.created_at);
                
                item.innerHTML = `
                    <div class="absolute -left-2 top-0 w-4 h-4 rounded-full bg-blue-100 border-2 border-white ring-1 ring-blue-500"></div>
                    <div class="flex flex-col gap-1 -mt-1">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-bold text-slate-700">${log.description || 'Ação registrada'}</p>
                            <span class="text-[10px] font-medium text-slate-400 whitespace-nowrap">${date.toLocaleDateString('pt-BR')} ${date.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'})}</span>
                        </div>
                        <p class="text-xs text-slate-500">Por: <span class="font-medium text-slate-600">${log.user_name || 'Sistema'}</span></p>
                    </div>
                `;
                list.appendChild(item);
            });
        } catch (error) {
            console.error(error);
            list.innerHTML = '<div class="pl-8 text-sm text-red-400 py-2">Não foi possível carregar o histórico.</div>';
        }
    }
</script>