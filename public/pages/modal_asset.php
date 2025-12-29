<!-- Modal Asset (Criar/Editar) -->
<div id="modalAsset" class="fixed inset-0 z-[80] hidden flex items-center justify-center p-4 sm:p-6">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalAsset')"></div>
    <div class="relative w-full max-w-4xl bg-white rounded-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden modal-panel transform scale-95 opacity-0 transition-all">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white shrink-0 z-10">
            <h3 class="text-lg font-bold text-slate-800" id="modalAssetTitle">Novo Ativo</h3>
            <button onclick="closeModal('modalAsset')" class="text-slate-400 hover:text-slate-600 transition-colors"><i data-lucide="x" class="w-6 h-6"></i></button>
        </div>
        
        <!-- Form -->
        <form method="POST" enctype="multipart/form-data" id="formAsset" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" id="assetAction" value="create_asset">
            <input type="hidden" name="id" id="assetId">
            <input type="hidden" name="current_photo" id="assetCurrentPhoto">

            <div class="flex-1 overflow-y-auto p-6 space-y-6 custom-scrollbar">
                <!-- Company, Location, Category -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Empresa *</label>
                        <select name="company_id" id="assetCompany" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Localização *</label>
                        <select name="location_id" id="assetLocation" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Categoria</label>
                        <select name="category_id" id="assetCategory" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></select>
                    </div>
                </div>

                <!-- Code, Name, Status -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Código *</label>
                        <div class="flex gap-2">
                            <input type="text" name="code" id="assetCode" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <button type="button" onclick="generateCode()" class="p-2.5 bg-slate-100 border border-slate-300 rounded-lg hover:bg-slate-200" title="Gerar Código"><i data-lucide="wand-2" class="w-4 h-4"></i></button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Nome do Ativo *</label>
                        <input type="text" name="name" id="assetName" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">Status</label>
                        <select name="status" id="assetStatus" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"></select>
                    </div>
                </div>

                <!-- Brand, Model, Serial -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Marca</label><input type="text" name="brand" id="assetBrand" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Modelo</label><input type="text" name="model" id="assetModel" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Nº Série</label><input type="text" name="serial_number" id="assetSerial" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                </div>

                <!-- Value, Dates -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Valor (R$)</label><input type="text" name="value" id="assetValue" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Data Aquisição</label><input type="date" name="acquisition_date" id="assetAcquisitionDate" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Fim Garantia</label><input type="date" name="warranty_date" id="assetWarrantyDate" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                </div>

                <!-- Maintenance & Lifespan -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Vida Útil (Anos)</label><input type="number" name="lifespan_years" id="assetLifespan" value="5" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Freq. Manutenção (Dias)</label><input type="number" name="maintenance_freq" id="assetMaintFreq" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Próxima Manutenção</label><input type="date" name="next_maintenance_date" id="assetNextMaint" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                </div>

                <!-- Responsible & Cost Center -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Responsável</label><input type="text" name="responsible_name" id="assetResponsible" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">Centro de Custo</label><input type="text" name="cost_center" id="assetCostCenter" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></div>
                </div>

                <!-- QR Access -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Acesso QR Code</label>
                    <select name="qr_access_level" id="assetQrAccess" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm">
                        <option value="public">Público (Qualquer um pode ler)</option>
                        <option value="private">Privado (Requer login)</option>
                    </select>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Descrição</label>
                    <textarea name="description" id="assetDescription" rows="3" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm"></textarea>
                </div>

                <!-- Photo -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Foto</label>
                    <input type="file" name="photo" accept="image/*" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm">
                </div>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 shrink-0">
                <button type="button" onclick="closeModal('modalAsset')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-white">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAssetModal(asset = null) {
    const form = document.getElementById('formAsset');
    form.reset();
    
    // Populate Selects using global data from assets.php
    const populate = (id, data) => {
        const sel = document.getElementById(id);
        sel.innerHTML = '';
        data.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id || item.name;
            opt.text = item.name;
            sel.appendChild(opt);
        });
    };
    
    populate('assetCompany', assetsCompaniesData);
    populate('assetLocation', assetsLocationsData);
    populate('assetCategory', assetsCategoriesData);
    populate('assetStatus', assetsStatusesData);

    if (asset) {
        document.getElementById('modalAssetTitle').innerText = 'Editar Ativo';
        document.getElementById('assetAction').value = 'update_asset';
        document.getElementById('assetId').value = asset.id;
        document.getElementById('assetCurrentPhoto').value = asset.photo_url || '';
        
        document.getElementById('assetCompany').value = asset.company_id;
        document.getElementById('assetLocation').value = asset.location_id;
        document.getElementById('assetCategory').value = asset.category_id;
        document.getElementById('assetCode').value = asset.code;
        document.getElementById('assetName').value = asset.name;
        document.getElementById('assetStatus').value = asset.status;
        document.getElementById('assetQrAccess').value = asset.qr_access_level || 'public';
        
        document.getElementById('assetBrand').value = asset.brand || '';
        document.getElementById('assetModel').value = asset.model || '';
        document.getElementById('assetSerial').value = asset.serial_number || '';
        document.getElementById('assetValue').value = asset.value || '';
        document.getElementById('assetAcquisitionDate').value = asset.acquisition_date || '';
        document.getElementById('assetWarrantyDate').value = asset.warranty_date || '';
        
        document.getElementById('assetLifespan').value = asset.lifespan_years || 5;
        document.getElementById('assetMaintFreq').value = asset.maintenance_freq || '';
        document.getElementById('assetNextMaint').value = asset.next_maintenance_date || '';
        
        document.getElementById('assetResponsible').value = asset.responsible_name || '';
        document.getElementById('assetCostCenter').value = asset.cost_center || '';
        document.getElementById('assetDescription').value = asset.description || '';
    } else {
        document.getElementById('modalAssetTitle').innerText = 'Novo Ativo';
        document.getElementById('assetAction').value = 'create_asset';
        document.getElementById('assetId').value = '';
        generateCode();
    }
    
    openModal('modalAsset');
}

function cloneAsset(asset) {
    openAssetModal(asset);
    document.getElementById('modalAssetTitle').innerText = 'Clonar Ativo';
    document.getElementById('assetAction').value = 'create_asset';
    document.getElementById('assetId').value = '';
    generateCode();
    document.getElementById('assetName').value = asset.name + ' (Cópia)';
}
</script>