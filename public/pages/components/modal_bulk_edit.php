<?php
// components/modal_bulk_edit.php

// Garante que as variáveis necessárias estejam disponíveis
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
?>

<!-- MODAL DE EDIÇÃO EM MASSA -->
<div id="modalBulkEdit" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalBulkEdit')"></div>
    <div class="relative w-full max-w-3xl bg-slate-50 rounded-2xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-5 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-100 text-blue-600 flex items-center justify-center rounded-full"><i data-lucide="edit" class="w-5 h-5"></i></div>
                <div>
                    <h3 class="text-lg font-bold text-slate-800">Editar Ativos em Massa</h3>
                    <p class="text-sm text-slate-500">Alterar <span id="bulkEditCount" class="font-bold">0</span> ativos selecionados</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modalBulkEdit')" class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-full p-1"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <form method="POST" id="formBulkEdit" class="flex-1 overflow-y-auto p-6 space-y-6">
            <input type="hidden" name="action" value="bulk_update_assets">
            <input type="hidden" name="ids" id="bulkEditIds">
            <p class="text-sm text-slate-600 bg-yellow-50 p-3 rounded-lg border border-yellow-200 flex items-start gap-3"><i data-lucide="alert-triangle" class="w-4 h-4 text-yellow-600 mt-0.5 shrink-0"></i><span>Marque e preencha **apenas** os campos que deseja alterar. Os campos não marcados permanecerão inalterados.</span></p>
            
            <?php 
            $fields = [
                'Organização' => [
                    'company_id' => ['label' => 'Empresa', 'type' => 'select', 'options' => $companies, 'icon' => 'building', 'onchange' => "filterSectors(null, 'bulk_company_id', 'bulk_location_id'); filterCategories(null, 'bulk_company_id', 'bulk_category_id'); filterStatuses(null, 'bulk_company_id', 'bulk_status');"],
                    'location_id' => ['label' => 'Setor', 'type' => 'select', 'options' => [], 'icon' => 'map-pin'],
                    'category_id' => ['label' => 'Categoria', 'type' => 'select', 'options' => [], 'icon' => 'tag'],
                    'status' => ['label' => 'Status', 'type' => 'select', 'options' => [], 'icon' => 'activity'],
                ],
                'Identificação' => [
                    'brand' => ['label' => 'Marca', 'type' => 'text', 'icon' => 'copyright'],
                    'model' => ['label' => 'Modelo', 'type' => 'text', 'icon' => 'box-select'],
                    'responsible_name' => ['label' => 'Responsável', 'type' => 'text', 'icon' => 'user'],
                ],
                'Financeiro' => [
                    'value' => ['label' => 'Valor (R$)', 'type' => 'text', 'icon' => 'dollar-sign', 'placeholder' => '1.234,56'],
                    'cost_center' => ['label' => 'Centro de Custo', 'type' => 'text', 'icon' => 'landmark'],
                    'acquisition_date' => ['label' => 'Data de Aquisição', 'type' => 'date', 'icon' => 'calendar-plus'],
                    'lifespan_years' => ['label' => 'Vida Útil (anos)', 'type' => 'number', 'icon' => 'hourglass'],
                ],
                'Manutenção' => [
                    'maintenance_freq' => ['label' => 'Frequência (dias)', 'type' => 'number', 'icon' => 'repeat'],
                    'next_maintenance_date' => ['label' => 'Próxima Manutenção', 'type' => 'date', 'icon' => 'calendar-clock'],
                ]
            ];
            ?>

            <div class="space-y-6">
                <?php foreach($fields as $group_name => $group_fields): ?>
                <fieldset>
                    <legend class="text-sm font-bold text-slate-600 mb-3 uppercase tracking-wider"><?php echo $group_name; ?></legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach($group_fields as $key => $f): ?>
                        <label class="bulk-edit-field-wrapper bg-white p-3 rounded-xl border border-slate-200 has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 transition-all flex gap-3 items-start cursor-pointer">
                            <input type="checkbox" name="update_<?php echo $key; ?>" class="mt-1.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500" onchange="toggleBulkField(this, 'bulk_<?php echo $key; ?>')">
                            <div class="flex-1">
                                <span class="text-sm font-medium text-slate-700 mb-1.5 block"><?php echo $f['label']; ?></span>
                                <div class="relative">
                                    <i data-lucide="<?php echo $f['icon']; ?>" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                                    <?php if($f['type'] == 'select'): ?>
                                        <select name="<?php echo $key; ?>" id="bulk_<?php echo $key; ?>" class="w-full pl-9 pr-4 py-2 border-slate-300 rounded-md text-sm disabled:bg-slate-200/60 disabled:cursor-not-allowed" disabled <?php if(isset($f['onchange'])) echo "onchange=\"{$f['onchange']}\""; ?>>
                                            <option value="">Selecione...</option>
                                            <?php 
                                            // Apenas o campo de empresa é pré-populado. Os outros são via JS.
                                            if ($key === 'company_id') {
                                                foreach($f['options'] as $opt) echo "<option value='{$opt['id']}'>{$opt['name']}</option>";
                                            }
                                            ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?php echo $f['type']; ?>" name="<?php echo $key; ?>" id="bulk_<?php echo $key; ?>" placeholder="<?php echo $f['placeholder'] ?? ''; ?>" class="w-full pl-9 pr-4 py-2 border-slate-300 rounded-md text-sm disabled:bg-slate-200/60 disabled:cursor-not-allowed" disabled>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php endforeach; ?>
            </div>
        </form>
        <div class="px-6 py-4 bg-white/50 backdrop-blur-sm border-t border-slate-200 flex justify-end gap-3 rounded-b-2xl">
            <button type="button" onclick="closeModal('modalBulkEdit')" class="px-5 py-2.5 border rounded-lg text-sm font-bold bg-white text-slate-700 hover:bg-slate-100">Cancelar</button>
            <button type="button" onclick="showBulkEditConfirmation()" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-blue-700 shadow-sm shadow-blue-200"><i data-lucide="check-circle" class="w-4 h-4"></i> Salvar Alterações</button>
        </div>
    </div>
</div>

<!-- MODAL CONFIRMAÇÃO EDIÇÃO EM MASSA -->
<div id="modalBulkEditConfirm" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalBulkEditConfirm')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6">
        <div class="text-center">
            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="info" class="w-6 h-6"></i>
            </div>
            <h3 class="text-lg font-bold text-slate-900 mb-2">Confirmar Alterações em Massa</h3>
            <p class="text-sm text-slate-500 mb-4">Você está prestes a aplicar as seguintes alterações em <strong id="confirmBulkCount">0</strong> ativos. Por favor, revise.</p>
        </div>
        <div id="bulkChangesSummary" class="bg-slate-50 border border-slate-200 rounded-lg p-4 max-h-60 overflow-y-auto space-y-2 text-sm">
            <!-- O resumo será injetado aqui pelo JS -->
        </div>
        <div class="flex gap-3 justify-center mt-6">
            <button type="button" onclick="closeModal('modalBulkEditConfirm')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Cancelar</button>
            <button type="button" onclick="document.getElementById('formBulkEdit').submit()" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm">Sim, Aplicar Alterações</button>
        </div>
    </div>
</div>
