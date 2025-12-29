<!-- Modal Bulk Edit -->
<div id="modalBulkEdit" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalBulkEdit')"></div>
    <div class="relative w-full max-w-3xl bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl">
            <div>
                <h3 class="text-lg font-bold text-slate-900">Editar em Massa</h3>
                <p class="text-sm text-slate-500">Editando <span id="bulkEditCount" class="font-bold text-blue-600">0</span> ativos selecionados</p>
            </div>
            <button onclick="closeModal('modalBulkEdit')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        
        <form id="formBulkEdit" method="POST" class="flex flex-col flex-1 overflow-hidden">
            <input type="hidden" name="action" value="bulk_update_assets">
            <input type="hidden" name="ids" id="bulkEditIds">
            
            <div class="p-6 overflow-y-auto space-y-4 bg-slate-50/50 flex-1">
                <div class="bg-blue-50 p-3 rounded-lg text-sm text-blue-700 mb-4 flex gap-2">
                    <i data-lucide="info" class="w-5 h-5 shrink-0"></i>
                    <p>Marque a caixa ao lado do campo que deseja alterar. Apenas os campos marcados serão atualizados em todos os ativos selecionados.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Company -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_company_id" onchange="toggleBulkField(this, 'bulk_company_id')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Empresa</span>
                            <select name="company_id" id="bulk_company_id" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                                <option value="">Selecione...</option>
                                <?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_location_id" onchange="toggleBulkField(this, 'bulk_location_id')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Localização</span>
                            <select name="location_id" id="bulk_location_id" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                                <option value="">Selecione...</option>
                                <?php foreach($locations as $l): ?><option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Category -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_category_id" onchange="toggleBulkField(this, 'bulk_category_id')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Categoria</span>
                            <select name="category_id" id="bulk_category_id" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                                <option value="">Selecione...</option>
                                <?php foreach($categories as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_status" onchange="toggleBulkField(this, 'bulk_status')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Status</span>
                            <select name="status" id="bulk_status" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                                <option value="">Selecione...</option>
                                <?php foreach($statuses as $s): ?><option value="<?php echo $s['name']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Responsible -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_responsible_name" onchange="toggleBulkField(this, 'bulk_responsible_name')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Responsável</span>
                            <input type="text" name="responsible_name" id="bulk_responsible_name" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                        </div>
                    </div>

                    <!-- Cost Center -->
                    <div class="bulk-edit-field-wrapper flex items-center gap-3 bg-white p-3 rounded border border-slate-200">
                        <input type="checkbox" name="update_cost_center" onchange="toggleBulkField(this, 'bulk_cost_center')" class="w-4 h-4 rounded text-blue-600">
                        <div class="flex-1">
                            <span class="text-sm font-medium text-slate-700 block mb-1">Centro de Custo</span>
                            <input type="text" name="cost_center" id="bulk_cost_center" disabled class="w-full border border-slate-300 rounded p-1.5 text-sm disabled:bg-slate-100">
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 bg-white border-t border-slate-100 flex justify-end gap-3 rounded-b-xl">
                <button type="button" onclick="closeModal('modalBulkEdit')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button>
                <button type="button" onclick="showBulkEditConfirmation()" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm">Aplicar Alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bulk Edit Confirm -->
<div id="modalBulkEditConfirm" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalBulkEditConfirm')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6">
        <div class="w-12 h-12 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center mx-auto mb-4"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
        <h3 class="text-lg font-bold text-slate-900 mb-2 text-center">Confirmar Alterações em Massa</h3>
        <p class="text-sm text-slate-500 mb-4 text-center">Você está prestes a atualizar <span id="confirmBulkCount" class="font-bold text-slate-800">0</span> ativos com os seguintes valores:</p>
        
        <div id="bulkChangesSummary" class="bg-slate-50 p-4 rounded-lg border border-slate-200 text-sm space-y-2 mb-6 max-h-48 overflow-y-auto"></div>
        
        <div class="flex gap-3 justify-center">
            <button type="button" onclick="closeModal('modalBulkEditConfirm')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Voltar</button>
            <button type="button" onclick="document.getElementById('formBulkEdit').submit()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm">Confirmar e Salvar</button>
        </div>
    </div>
</div>
