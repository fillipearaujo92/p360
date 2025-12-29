<?php
// pages/assets.php

// --- DEPENDÊNCIAS DE DIRETÓRIOS ---
$dirs = ['uploads/assets', 'uploads/signatures', 'uploads/files'];
$user_role = $_SESSION['user_role'] ?? 'leitor';
foreach($dirs as $d) {
    $path = dirname(__DIR__) . '/' . $d;
    if (!is_dir($path)) @mkdir($path, 0777, true);
}

// --- CARREGAR PERMISSÕES ---
$stmt = $pdo->prepare("SELECT permissions FROM roles WHERE role_key = ?");
$stmt->execute([$user_role]);
$role_perms_json = $stmt->fetchColumn();
$current_permissions = $role_perms_json ? json_decode($role_perms_json, true) : [];

function hasPermission($p) { 
    global $current_permissions, $user_role; 
    // Admin tem acesso total por padrão, ou verifica na lista
    return $user_role === 'admin' || in_array($p, $current_permissions); 
}

$message = '';

// =================================================================================
// 0. CONFIGURAÇÃO DE URL BASE (MOVIDO PARA CIMA)
// =================================================================================
// CONFIGURAÇÃO DO NGROK (URL BASE)
$ngrok_url = ' https://5c9fb739de08.ngrok-free.app'; // <--- COLE SEU LINK AQUI
$ngrok_url = trim(preg_replace('/\s+/', '', $ngrok_url)); 
$ngrok_url = rtrim($ngrok_url, '/');
if (!empty($ngrok_url) && strpos($ngrok_url, 'http') !== 0) $ngrok_url = 'https://' . $ngrok_url;
$base_path = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']);
$base_path = trim($base_path);

if (!empty($ngrok_url)) {
    $qr_base_url = $ngrok_url . $base_path;
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $qr_base_url = $protocol . $domain . $base_path;
}

// Verifica se a tabela de favoritos existe para evitar erros fatais
$favorites_enabled = false;
try {
    $pdo->query("SELECT 1 FROM user_asset_favorites LIMIT 1");
    $favorites_enabled = true;
} catch (Exception $e) {
    // Tabela não existe, funcionalidade será desativada
}

// =================================================================================
// 1. PROCESSAMENTO (POST)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // --- TOGGLE FAVORITE (AJAX) ---
        if (isset($_POST['action']) && $_POST['action'] == 'toggle_favorite') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            if (!$favorites_enabled) {
                echo json_encode(['success' => false, 'message' => 'Funcionalidade indisponível: tabela não criada.']);
                exit;
            }

            $asset_id = $_POST['asset_id'] ?? null;
            $user_id = $_SESSION['user_id'] ?? null;

            if (!$asset_id || !$user_id) {
                echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
                exit;
            }

            // Check if it's already a favorite
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_asset_favorites WHERE user_id = ? AND asset_id = ?");
            $stmt->execute([$user_id, $asset_id]);
            $is_favorite = $stmt->fetchColumn() > 0;

            if ($is_favorite) {
                $stmt = $pdo->prepare("DELETE FROM user_asset_favorites WHERE user_id = ? AND asset_id = ?");
                $stmt->execute([$user_id, $asset_id]);
                echo json_encode(['success' => true, 'status' => 'removed']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO user_asset_favorites (user_id, asset_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $asset_id]);
                echo json_encode(['success' => true, 'status' => 'added']);
            }
            exit;
        }
        // Proteção de Ações
        $action = $_POST['action'] ?? '';

        $user_id = $_SESSION['user_id'] ?? 0;
        $company_id = $_SESSION['user_company_id'] ?? 1;

        // --- UPLOAD DE ANEXO ---
        if (isset($_POST['action']) && $_POST['action'] == 'upload_attachment') {
            $asset_id = $_POST['asset_id'];
            if (!empty($_FILES['attachment']['name'][0])) {
                foreach ($_FILES['attachment']['name'] as $key => $name) {
                    if ($_FILES['attachment']['error'][$key] == 0) {
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $new_name = uniqid('file_') . '.' . $ext;
                        $target = dirname(__DIR__) . '/uploads/files/' . $new_name;
                        if (move_uploaded_file($_FILES['attachment']['tmp_name'][$key], $target)) {
                            $stmt = $pdo->prepare("INSERT INTO asset_files (asset_id, file_path, file_name, file_type, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$asset_id, 'uploads/files/' . $new_name, $name, $ext, $user_id]);
                        }
                    }
                }
                echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id&tab=photos';</script>"; exit;
            }
        }

        // --- EXCLUIR ANEXO ---
        if ($action == 'delete_attachment' && hasPermission('edit_asset')) {
            $file_id = $_POST['file_id'];
            $stmt = $pdo->prepare("SELECT file_path, asset_id FROM asset_files WHERE id = ?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetch();
            if ($file) {
                if (file_exists(dirname(__DIR__) . '/' . $file['file_path'])) @unlink(dirname(__DIR__) . '/' . $file['file_path']);
                $pdo->prepare("DELETE FROM asset_files WHERE id = ?")->execute([$file_id]);
                log_action('asset_update', "Anexo '{$file['file_name']}' removido do ativo ID {$file['asset_id']}.");
                echo "<script>window.location.href = 'index.php?page=assets&id={$file['asset_id']}&tab=photos';</script>"; exit;
            }
        }

        // --- EXCLUIR ATIVO ---
        if ($action == 'delete_asset' && hasPermission('delete_asset')) {
            $id = $_POST['id'];

            // VERIFICA VÍNCULOS ANTES DE EXCLUIR
            $stmtCheckLinks = $pdo->prepare("SELECT COUNT(*) FROM asset_links WHERE asset_id_1 = ? OR asset_id_2 = ?");
            $stmtCheckLinks->execute([$id, $id]);
            if ($stmtCheckLinks->fetchColumn() > 0) {
                throw new Exception("Não é possível excluir: este ativo está relacionado a outros. Desvincule-os primeiro.");
            }

            $stmtCheckAccessories = $pdo->prepare("SELECT COUNT(*) FROM asset_accessories WHERE asset_id = ?");
            $stmtCheckAccessories->execute([$id]);
            if ($stmtCheckAccessories->fetchColumn() > 0) {
                throw new Exception("Não é possível excluir: este ativo possui acessórios vinculados. Devolva-os ao estoque primeiro.");
            }

            $stmtCheckComponents = $pdo->prepare("SELECT COUNT(*) FROM asset_peripherals WHERE asset_id = ?");
            $stmtCheckComponents->execute([$id]);
            if ($stmtCheckComponents->fetchColumn() > 0) {
                throw new Exception("Não é possível excluir: este ativo possui componentes registrados. Remova-os primeiro.");
            }

            $stmt = $pdo->prepare("SELECT name, code, photo_url FROM assets WHERE id = ?"); $stmt->execute([$id]); $row = $stmt->fetch();
            if ($row && $row['photo_url'] && file_exists(__DIR__ . '/../' . $row['photo_url'])) @unlink(__DIR__ . '/../' . $row['photo_url']);
            $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?"); $stmt->execute([$id]);
            log_action('asset_delete', "Ativo '{$row['name']}' ({$row['code']}) removido.");
            if(isset($_GET['view']) || isset($_GET['id'])) { echo "<script>window.location.href = 'index.php?page=assets';</script>"; exit; }
            $message = "Ativo removido.";
        }
        // --- EXCLUIR EM MASSA ---
        if ($action == 'bulk_delete_assets' && hasPermission('delete_asset')) {
            $ids = explode(',', $_POST['ids']); $ids = array_filter($ids, 'is_numeric'); 
            $count = 0; $skipped_count = 0;
            if(!empty($ids)) {
                $stmtCheckLinks = $pdo->prepare("SELECT COUNT(*) FROM asset_links WHERE asset_id_1 = ? OR asset_id_2 = ?");
                $stmtCheckAccessories = $pdo->prepare("SELECT COUNT(*) FROM asset_accessories WHERE asset_id = ?");
                $stmtCheckComponents = $pdo->prepare("SELECT COUNT(*) FROM asset_peripherals WHERE asset_id = ?");
                foreach ($ids as $id) {
                    $stmtCheckLinks->execute([$id, $id]); $stmtCheckAccessories->execute([$id]); $stmtCheckComponents->execute([$id]);
                    if ($stmtCheckLinks->fetchColumn() > 0 || $stmtCheckAccessories->fetchColumn() > 0 || $stmtCheckComponents->fetchColumn() > 0) {
                        $skipped_count++; continue;
                    }
                    $stmt = $pdo->prepare("SELECT photo_url FROM assets WHERE id = ?"); $stmt->execute([$id]); $row = $stmt->fetch();
                    if ($row && $row['photo_url'] && file_exists(__DIR__ . '/../' . $row['photo_url'])) @unlink(__DIR__ . '/../' . $row['photo_url']);
                    $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?"); $stmt->execute([$id]); $count++;
                }
                $message = "$count ativos removidos. $skipped_count não foram removidos por possuírem vínculos.";
                if ($count > 0) log_action('asset_delete', "$count ativos removidos em massa. IDs: " . implode(', ', $ids));
            }
        }

        // --- ATUALIZAÇÃO EM MASSA (BULK UPDATE) ---
        if ($action == 'bulk_update_assets' && hasPermission('edit_asset')) {
            $ids = explode(',', $_POST['ids']);
            $ids = array_filter($ids, 'is_numeric');
            $count = 0;
        
            if (!empty($ids)) {
                $updates = [];
                $params = [];
        
                // Mapeia os campos do formulário para os campos do banco
                $field_map = [
                    'company_id' => 'int', 'category_id' => 'int', 'location_id' => 'int', 'status' => 'string',
                    'brand' => 'string', 'model' => 'string', 'maintenance_freq' => 'int',
                    'next_maintenance_date' => 'string', 'lifespan_years' => 'int', 
                    'responsible_name' => 'string',
                    'cost_center' => 'string', 'acquisition_date' => 'string', 'value' => 'float'
                ];
        
                // Lógica para campos personalizados
                if (isset($_POST['update_custom_fields']) && is_array($_POST['update_custom_fields'])) {
                    foreach ($_POST['update_custom_fields'] as $key => $value) {
                        // JSON_SET irá adicionar ou atualizar a chave no JSON
                        // A chave é escapada para o caso de conter caracteres especiais.
                        $updates[] = "custom_attributes = JSON_SET(COALESCE(custom_attributes, '{}'), ?, ?)";
                        $params[] = '$.' . $key; // Caminho JSON
                        $params[] = $value;      // Valor
                    }
                }

                foreach ($field_map as $field => $type) {
                    // Verifica se o checkbox para atualizar o campo foi marcado
                    if (isset($_POST['update_' . $field])) {
                        // Permite campos vazios para limpar valores, exceto para o valor que será 0
                        $value_to_set = $_POST[$field] ?? null;
        
                        // Tratamento especial para o campo 'value' (monetário)
                        if ($field === 'value') {
                            $valInput = $_POST['value'] ?? '0';
                            $value_to_set = (strpos($valInput, ',') !== false) ? str_replace(',', '.', str_replace('.', '', $valInput)) : $valInput;
                            $value_to_set = is_numeric($value_to_set) ? $value_to_set : 0;
                        }
        
                        $updates[] = "$field = ?";
                        $params[] = $value_to_set;
                    }
                }
        
                if (!empty($updates)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "UPDATE assets SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge($params, $ids));
                    $count = $stmt->rowCount();
                }
                if ($count > 0) log_action('asset_update', "$count ativos atualizados em massa. IDs: " . implode(', ', $ids));
                $message = "$count ativos foram atualizados.";
            }
        }

        // --- TRANSFERÊNCIA DE EMPRESA EM MASSA ---
        if ($action == 'bulk_transfer_assets' && hasPermission('edit_asset')) {
            $ids = explode(',', $_POST['ids']);
            $ids = array_filter($ids, 'is_numeric');
            $target_company_id = $_POST['target_company_id'];
            $target_location_id = !empty($_POST['target_location_id']) ? $_POST['target_location_id'] : null;
            $target_status = !empty($_POST['target_status']) ? $_POST['target_status'] : null;

            if (!empty($ids) && !empty($target_company_id)) {
                // Busca nome da empresa destino para o log
                $stmtTgt = $pdo->prepare("SELECT name, email FROM companies WHERE id = ?");
                $stmtTgt->execute([$target_company_id]);
                $target_company_data = $stmtTgt->fetch();
                $target_company_name = $target_company_data['name'];
                $target_company_email = $target_company_data['email'];

                $count = 0;
                $transferred_assets_list = [];
                foreach ($ids as $id) {
                    // Busca dados atuais do ativo e nome da empresa de origem
                    $stmt = $pdo->prepare("SELECT a.company_id, a.category_id, a.status, a.name, a.code, c.name as company_name FROM assets a LEFT JOIN companies c ON a.company_id = c.id WHERE a.id = ?");
                    $stmt->execute([$id]);
                    $asset = $stmt->fetch();

                    if ($asset) {
                        $new_category_id = null;
                        $current_status = $target_status ? $target_status : $asset['status'];
                        $source_company_id = $asset['company_id'];
                        $source_company_name = $asset['company_name'];
                        $transferred_assets_list[] = "{$asset['name']} ({$asset['code']})";

                        // Lógica para criar o Status na empresa de destino se não existir
                        if ($current_status) {
                            try {
                                $stmtCheckS = $pdo->prepare("SELECT id FROM asset_statuses WHERE company_id = ? AND name = ?");
                                $stmtCheckS->execute([$target_company_id, $current_status]);
                                if (!$stmtCheckS->fetchColumn()) {
                                    // Tenta copiar o status completo da empresa de origem (incluindo cor, etc)
                                    $stmtSourceS = $pdo->prepare("SELECT * FROM asset_statuses WHERE company_id = ? AND name = ?");
                                    $stmtSourceS->execute([$source_company_id, $current_status]);
                                    $sourceStatus = $stmtSourceS->fetch(PDO::FETCH_ASSOC);

                                    if ($sourceStatus) {
                                        unset($sourceStatus['id']); // Remove ID original
                                        $sourceStatus['company_id'] = $target_company_id; // Define nova empresa
                                        
                                        $cols = array_keys($sourceStatus);
                                        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                                        $colNames = implode(', ', $cols);
                                        
                                        $pdo->prepare("INSERT INTO asset_statuses ($colNames) VALUES ($placeholders)")
                                            ->execute(array_values($sourceStatus));
                                    } else {
                                        // Fallback: cria apenas com o nome
                                        $pdo->prepare("INSERT INTO asset_statuses (company_id, name) VALUES (?, ?)")
                                            ->execute([$target_company_id, $current_status]);
                                    }
                                }
                            } catch (Exception $e) {
                                // Silencia erro caso a tabela não tenha company_id (status globais)
                            }
                        }

                        // Lógica para preservar/migrar categoria
                        if ($asset['category_id']) {
                            $stmtCat = $pdo->prepare("SELECT name, custom_schema FROM categories WHERE id = ?");
                            $stmtCat->execute([$asset['category_id']]);
                            $cat = $stmtCat->fetch();

                            if ($cat) {
                                // Verifica se existe categoria com mesmo nome na empresa destino
                                $stmtCheck = $pdo->prepare("SELECT id FROM categories WHERE company_id = ? AND name = ?");
                                $stmtCheck->execute([$target_company_id, $cat['name']]);
                                $new_category_id = $stmtCheck->fetchColumn();

                                // Se não existir, cria
                                if (!$new_category_id) {
                                    try {
                                        $stmtIns = $pdo->prepare("INSERT INTO categories (company_id, name, custom_schema) VALUES (?, ?, ?)");
                                        $stmtIns->execute([$target_company_id, $cat['name'], $cat['custom_schema']]);
                                        $new_category_id = $pdo->lastInsertId();
                                    } catch (Exception $e) { /* Falha silenciosa, categoria ficará nula */ }
                                }
                            }
                        }

                        // Atualiza o ativo, mantendo o status original e migrando a categoria
                        $pdo->prepare("UPDATE assets SET company_id = ?, category_id = ?, location_id = ?, status = ? WHERE id = ?")
                            ->execute([$target_company_id, $new_category_id, $target_location_id, $current_status, $id]);
                        
                        // Log de movimentação detalhado
                        $description = "Transferido da empresa '{$source_company_name}' para '{$target_company_name}'";
                        $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description, created_at) VALUES (?, ?, ?, 'transfer', ?, ?, ?, NOW())")
                            ->execute([$target_company_id, $id, $user_id, $source_company_name, $target_company_name, $description]);
                        
                    log_action('asset_transfer', "Ativo '{$asset['name']}' ({$asset['code']}) transferido da empresa '{$source_company_name}' para '{$target_company_name}'.");
                        $count++;
                    }
                }

                // Envia notificação por e-mail para o gestor da empresa de destino
                if ($count > 0 && !empty($target_company_email)) {
                    $link = $qr_base_url . '/index.php?page=assets&search=' . urlencode($target_company_name);
                    $subject = "Recebimento de Ativos - " . $target_company_name;
                    $body = "Olá,\n\nOs seguintes ativos foram transferidos para a gestão da sua empresa ($target_company_name):\n\n";
                    foreach ($transferred_assets_list as $item) {
                        $body .= "- $item\n";
                    }
                    $body .= "\n\nPara visualizar os ativos no sistema, acesse o link abaixo:\n";
                    $body .= $link . "\n";
                    $body .= "\n\nAtenciosamente,\nSistema Patrimônio 360º";
                    $headers = "From: no-reply@patrimonio360.com\r\nContent-Type: text/plain; charset=UTF-8";
                    @mail($target_company_email, $subject, $body, $headers);
                }

                $message = "$count ativos foram transferidos para a nova empresa.";
            }
        }

        // --- MOVIMENTAR ATIVO ---
        if ($action == 'move_asset' && hasPermission('move_asset')) {
            $id = $_POST['id'];
            $new_location = $_POST['location_id'];
            $new_status = $_POST['status'];
            $reason = $_POST['description'];
            
            if (empty(trim($reason))) {
                throw new Exception("O motivo da movimentação é obrigatório.");
            }
            
            $receiver = $_POST['responsible_name'] ?? null;
            $giver = $_POST['giver_name'] ?? null;

            $signature_url = null;
            if (!empty($_POST['signature_data'])) {
                $data = $_POST['signature_data'];
                if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                    $data = substr($data, strpos($data, ',') + 1);
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $sigName = 'sig_' . uniqid() . '.png';
                        $sigPath = dirname(__DIR__) . '/uploads/signatures/' . $sigName;
                        if(file_put_contents($sigPath, $data)) $signature_url = 'uploads/signatures/' . $sigName;
                    }
                }
            }

            $stmt = $pdo->prepare("SELECT name, code, location_id, status FROM assets WHERE id = ?"); $stmt->execute([$id]); $old = $stmt->fetch();
            
            if ($old && (strtolower($old['status']) === 'baixado' || strtolower($old['status']) === 'descartado')) {
                throw new Exception("Não é possível movimentar um ativo com status 'Baixado' ou 'Descartado'.");
            }

            $stmt = $pdo->prepare("SELECT name FROM locations WHERE id = ?"); $stmt->execute([$new_location]); $new_loc_name = $stmt->fetchColumn();
            
            // Busca o nome do gestor do novo setor
            $stmt_loc = $pdo->prepare("SELECT manager_name FROM locations WHERE id = ?");
            $stmt_loc->execute([$new_location]);
            $loc_manager = $stmt_loc->fetchColumn();

            // Verifica se o gestor confirmou no ato
            $manager_confirmed = isset($_POST['manager_confirmed_check']) ? 1 : 0;
            if (empty($loc_manager)) $manager_confirmed = 0; // Não pode confirmar se não há gestor
            $confirmed_at = $manager_confirmed ? date('Y-m-d H:i:s') : null;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE assets SET location_id = ?, status = ?, responsible_name = ? WHERE id = ?");
            $stmt->execute([$new_location, $new_status, $receiver, $id]);

            $log_desc = "Movido para '{$new_loc_name}'. Motivo: $reason";
            // Adicionado location_manager_name
            $sql = "INSERT INTO movements (company_id, asset_id, user_id, type, from_value, to_value, description, signature_url, responsible_name, giver_name, location_manager_name, manager_confirmed, manager_confirmed_at, created_at) VALUES (?, ?, ?, 'local', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$company_id, $id, $user_id, $old['location_id'] ?? 0, $new_location, $log_desc, $signature_url, $receiver, $giver, $loc_manager, $manager_confirmed, $confirmed_at]);

            $pdo->commit();
            log_action('asset_move', "Ativo '{$old['name']}' ({$old['code']}) movido para '{$new_loc_name}' por '{$receiver}'. Motivo: {$reason}.");

            // Log específico para mudança de status na movimentação
            if ($old['status'] !== $new_status) {
                log_action('asset_status_change', "Status do ativo '{$old['name']}' ({$old['code']}) alterado de '{$old['status']}' para '{$new_status}' durante movimentação.");
            }

            echo "<script>window.location.href = 'index.php?page=assets&id=$id&tab=history';</script>"; exit;
        }

        // --- CRIAR/EDITAR ATIVO ---
        if (in_array($action, ['create_asset', 'update_asset']) && (hasPermission('create_asset') || hasPermission('edit_asset'))) {
            $company_id = $_POST['company_id'];
            $location_id = $_POST['location_id'];
            $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
            $code = $_POST['code'];
            $name = $_POST['name'];
            $brand = $_POST['brand'] ?? '';
            $model = $_POST['model'] ?? '';
            $serial = $_POST['serial_number'] ?? '';
            $cost_center = $_POST['cost_center'] ?? '';
            $status = $_POST['status'] ?? 'Ativo';
            $qr_access = $_POST['qr_access_level'] ?? 'public';
            $acquisition_date = !empty($_POST['acquisition_date']) ? $_POST['acquisition_date'] : date('Y-m-d');
            $warranty_date = !empty($_POST['warranty_date']) ? $_POST['warranty_date'] : null;
            $description = $_POST['description'] ?? '';
            
            $valInput = $_POST['value'];
            $value = (strpos($valInput, ',') !== false) ? str_replace(',', '.', str_replace('.', '', $valInput)) : $valInput;
            $value = is_numeric($value) ? $value : 0;

            // CAMPOS NOVOS
            $maint_freq = !empty($_POST['maintenance_freq']) ? $_POST['maintenance_freq'] : null;
            $next_maint = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : null;
            if ($maint_freq && empty($next_maint)) {
                $next_maint = date('Y-m-d', strtotime("+$maint_freq days"));
            }
            $lifespan = !empty($_POST['lifespan_years']) ? $_POST['lifespan_years'] : 5;
            $responsible_name = $_POST['responsible_name'] ?? null;

            $custom_attributes = (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) ? json_encode($_POST['custom_fields']) : null;

            $photo_url = $_POST['current_photo'] ?? '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid('asset_') . '.' . $ext;
                $target_dir = dirname(__DIR__) . '/uploads/assets';
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_dir . '/' . $new_name)) {
                    $photo_url = 'uploads/assets/' . $new_name;
                }
            }

            if ($_POST['action'] == 'create_asset') {
                if (!hasPermission('create_asset')) throw new Exception("Sem permissão para criar ativos.");
                $sql = "INSERT INTO assets (
                            company_id, location_id, category_id, code, name, 
                            brand, model, serial_number, cost_center, status, 
                            qr_access_level, value, acquisition_date, warranty_date, description, photo_url, 
                            custom_attributes, maintenance_freq, next_maintenance_date, lifespan_years, responsible_name
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $company_id, $location_id, $category_id, $code, $name,
                    $brand, $model, $serial, $cost_center, $status,
                    $qr_access, $value, $acquisition_date, $warranty_date, $description, 
                    $photo_url, $custom_attributes, $maint_freq, $next_maint, $lifespan, $responsible_name
                ]);
                $new_id = $pdo->lastInsertId();

                // Adiciona confirmação do gestor ao criar
                $loc_manager = $_POST['location_manager_name'] ?? null;
                $manager_confirmed = isset($_POST['manager_confirmed_check']) ? 1 : 0;
                $confirmed_at = $manager_confirmed ? date('Y-m-d H:i:s') : null;

                $pdo->prepare("INSERT INTO movements (company_id, asset_id, user_id, type, description, location_manager_name, manager_confirmed, manager_confirmed_at) VALUES (?, ?, ?, 'creation', 'Ativo cadastrado no sistema', ?, ?, ?)")->execute([$company_id, $new_id, $user_id, $loc_manager, $manager_confirmed, $confirmed_at]);
                $message = "Ativo criado com sucesso!";
                log_action('asset_create', "Ativo '{$name}' (Cód: {$code}) criado. ID: {$new_id}");

            } else {
                if (!hasPermission('edit_asset')) throw new Exception("Sem permissão para editar ativos.");
                $id = $_POST['id'];

                // Busca o status antigo para log específico
                $stmtOld = $pdo->prepare("SELECT status FROM assets WHERE id = ?");
                $stmtOld->execute([$id]);
                $old_status = $stmtOld->fetchColumn();

                $sql = "UPDATE assets SET 
                            company_id=?, location_id=?, category_id=?, code=?, name=?, 
                            brand=?, model=?, serial_number=?, cost_center=?, status=?, 
                            qr_access_level=?, value=?, acquisition_date=?, warranty_date=?, description=?, photo_url=?, 
                            custom_attributes=?, maintenance_freq=?, next_maintenance_date=?, lifespan_years=?, responsible_name=? 
                        WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $company_id, $location_id, $category_id, $code, $name,
                    $brand, $model, $serial, $cost_center, $status,
                    $qr_access, $value, $acquisition_date, $warranty_date, $description, 
                    $photo_url, $custom_attributes, $maint_freq, $next_maint, $lifespan, $responsible_name,
                    $id
                ]);
                $message = "Ativo atualizado com sucesso!";
                log_action('asset_update', "Ativo '{$name}' ({$code}) atualizado.");

                // Log específico para mudança de status
                if ($old_status !== $status) {
                    log_action('asset_status_change', "Status do ativo '{$name}' ({$code}) alterado de '{$old_status}' para '{$status}'.");
                }
            }
        }
        
        // --- IMPORTAÇÃO ---
        if ($action == 'import_assets' && hasPermission('create_asset')) {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
                $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
                
                // Detecta e remove BOM (Byte Order Mark) se existir
                $bom = fread($file, 3);
                if ($bom !== "\xEF\xBB\xBF") rewind($file);
                
                fgetcsv($file); // Pula header
                $imported = 0; $updated = 0; $errors = 0; $last_error = '';

                // Prepara mapas para buscar IDs pelos Nomes (Case Insensitive)
                $catMap = [];
                $stmtCat = $pdo->prepare("SELECT id, name FROM categories WHERE company_id = ?");
                $stmtCat->execute([$company_id]);
                while($c = $stmtCat->fetch()) $catMap[mb_strtolower(trim($c['name']))] = $c['id'];

                $locMap = [];
                $stmtLoc = $pdo->prepare("SELECT id, name FROM locations WHERE company_id = ?");
                $stmtLoc->execute([$company_id]);
                while($l = $stmtLoc->fetch()) $locMap[mb_strtolower(trim($l['name']))] = $l['id'];

                // Verifica se existe para decidir entre update ou insert
                $stmtCheck = $pdo->prepare("SELECT id FROM assets WHERE code = ? AND company_id = ?");
                
                $stmtInsert = $pdo->prepare("
                    INSERT INTO assets (
                        company_id, code, name, responsible_name, category_id, location_id, status, value, 
                        brand, model, serial_number, acquisition_date, warranty_date, cost_center, 
                        lifespan_years, maintenance_freq, next_maintenance_date, description, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmtUpdate = $pdo->prepare("
                    UPDATE assets SET 
                        name=?, responsible_name=?, category_id=?, location_id=?, status=?, value=?, 
                        brand=?, model=?, serial_number=?, acquisition_date=?, warranty_date=?, cost_center=?, 
                        lifespan_years=?, maintenance_freq=?, next_maintenance_date=?, description=? 
                    WHERE id=?
                ");

                while (($row = fgetcsv($file, 2000, ",")) !== FALSE) {
                    if (count($row) < 2) continue; // Pula linhas vazias
                    try {
                        // Mapeamento Padronizado (0-16)
                        $code = trim($row[0] ?? '');
                        if (empty($code)) $code = 'IMP-' . uniqid();
                        
                        $name = trim($row[1] ?? 'Sem Nome');
                        $responsible = trim($row[2] ?? '');
                        
                        // Busca ID pelo Nome (ou usa null se não encontrar)
                        $catName = mb_strtolower(trim($row[3] ?? ''));
                        $cat_id = $catMap[$catName] ?? null;
                        
                        $locName = mb_strtolower(trim($row[4] ?? ''));
                        $loc_id = $locMap[$locName] ?? null;
                        
                        $status = trim($row[5] ?? 'Ativo');
                        
                        // Tratamento de Valor (R$)
                        $valInput = $row[6] ?? '0';
                        $valInput = preg_replace('/[^\d,.-]/', '', $valInput); // Remove R$ e espaços
                        if (strpos($valInput, ',') !== false && strpos($valInput, '.') !== false) { 
                            $valInput = str_replace('.', '', $valInput); $valInput = str_replace(',', '.', $valInput); 
                        } elseif (strpos($valInput, ',') !== false) { 
                            $valInput = str_replace(',', '.', $valInput); 
                        }
                        $value = is_numeric($valInput) ? (float)$valInput : 0;

                        $brand = trim($row[7] ?? '');
                        $model = trim($row[8] ?? '');
                        $serial = trim($row[9] ?? '');

                        // Helper para Datas (aceita d/m/Y ou Y-m-d)
                        $parseDate = function($d) {
                            if (empty($d)) return null;
                            $d = trim($d);
                            $dt = DateTime::createFromFormat('d/m/Y', $d);
                            if ($dt) return $dt->format('Y-m-d');
                            $dt = DateTime::createFromFormat('Y-m-d', $d);
                            if ($dt) return $dt->format('Y-m-d');
                            return null;
                        };

                        $acqDate = $parseDate($row[10] ?? '') ?? date('Y-m-d');
                        $warDate = $parseDate($row[11] ?? '');
                        
                        $costCenter = trim($row[12] ?? '');
                        $lifespan = (int)($row[13] ?? 5);
                        $maintFreq = !empty($row[14]) ? (int)$row[14] : null;
                        $nextMaint = $parseDate($row[15] ?? '');
                        $description = trim($row[16] ?? '');

                        // Lógica: Se existe, atualiza. Se não, cria.
                        $stmtCheck->execute([$code, $company_id]);
                        $existing_id = $stmtCheck->fetchColumn();

                        if ($existing_id) {
                            $stmtUpdate->execute([
                                $name, $responsible, $cat_id, $loc_id, $status, $value,
                                $brand, $model, $serial, $acqDate, $warDate, $costCenter,
                                $lifespan, $maintFreq, $nextMaint, $description,
                                $existing_id
                            ]);
                            $updated++;
                        } else {
                            $stmtInsert->execute([
                                $company_id, $code, $name, $responsible, $cat_id, $loc_id, $status, $value,
                                $brand, $model, $serial, $acqDate, $warDate, $costCenter,
                                $lifespan, $maintFreq, $nextMaint, $description
                            ]);
                            $imported++;
                        }
                    } catch (Exception $e) { $errors++; $last_error = $e->getMessage(); }
                }
                fclose($file);
                log_action('asset_import', "Importação de ativos: $imported criados, $updated atualizados, $errors erros.");
                $message = "Importação: $imported criados, $updated atualizados, $errors erros." . ($errors > 0 ? " (Último erro: $last_error)" : "");
            }
        }

        // --- GERENCIAR PERIFÉRICOS ---
        if ($action == 'add_peripheral' && hasPermission('manage_peripherals')) {
            $asset_id = $_POST['asset_id'];
            $name = $_POST['name'];
            $serial = $_POST['serial_number'] ?? '';
            $status = $_POST['status'] ?? 'Instalado';
            $stmt = $pdo->prepare("INSERT INTO asset_peripherals (asset_id, name, serial_number, status, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$asset_id, $name, $serial, $status]);
            $new_p_id = $pdo->lastInsertId();
            // Log Histórico (Criação/Instalação)
            try { $pdo->prepare("INSERT INTO peripheral_movements (peripheral_id, to_asset_id, user_id, created_at) VALUES (?, ?, ?, NOW())")->execute([$new_p_id, $asset_id, $user_id]); } catch(Exception $e) {}
            $stmtName = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtName->execute([$asset_id]); $asset_data = $stmtName->fetch();
            log_action('asset_update', "Componente '{$name}' adicionado ao ativo '{$asset_data['name']}' ({$asset_data['code']}).");
            echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id&tab=peripherals';</script>"; exit;
        }

        if ($action == 'delete_peripheral' && hasPermission('manage_peripherals')) {
            $p_id = $_POST['peripheral_id'];
            $asset_id = $_POST['asset_id'];
            $pdo->prepare("DELETE FROM asset_peripherals WHERE id = ? AND asset_id = ?")->execute([$p_id, $asset_id]);
            $stmtP = $pdo->prepare("SELECT name FROM asset_peripherals WHERE id = ?"); $stmtP->execute([$p_id]); $p_name = $stmtP->fetchColumn();
            $stmtA = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA->execute([$asset_id]); $a_data = $stmtA->fetch();
            log_action('asset_update', "Componente '{$p_name}' removido do ativo '{$a_data['name']}' ({$a_data['code']}).");
            echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id&tab=peripherals';</script>"; exit;
        }

        if ($action == 'move_peripheral' && hasPermission('manage_peripherals')) {
            $p_id = $_POST['peripheral_id'];
            $current_asset_id = $_POST['current_asset_id'];
            $new_asset_id = $_POST['new_asset_id'];
            $pdo->prepare("UPDATE asset_peripherals SET asset_id = ? WHERE id = ?")->execute([$new_asset_id, $p_id]);
            // Log Histórico (Movimentação)
            $stmtP = $pdo->prepare("SELECT name FROM asset_peripherals WHERE id = ?"); $stmtP->execute([$p_id]); $p_name = $stmtP->fetchColumn();
            $stmtA1 = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA1->execute([$current_asset_id]); $a1 = $stmtA1->fetch();
            $stmtA2 = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA2->execute([$new_asset_id]); $a2 = $stmtA2->fetch();
            log_action('asset_update', "Componente '{$p_name}' movido do ativo '{$a1['name']}' ({$a1['code']}) para '{$a2['name']}' ({$a2['code']}).");
            try { $pdo->prepare("INSERT INTO peripheral_movements (peripheral_id, from_asset_id, to_asset_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW())")->execute([$p_id, $current_asset_id, $new_asset_id, $user_id]); } catch(Exception $e) {}
            echo "<script>window.location.href = 'index.php?page=assets&id=$current_asset_id&tab=peripherals';</script>"; exit;
        }

        // --- GERENCIAR ACESSÓRIOS (VINCULAR ITENS DO ESTOQUE) ---
        if ($action == 'assign_accessory' && hasPermission('manage_peripherals')) {
            $asset_id = $_POST['asset_id'];
            $peripheral_id = $_POST['peripheral_id'];
            $quantity = (int)($_POST['quantity'] ?? 1);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT quantity FROM peripherals WHERE id = ? FOR UPDATE");
            $stmt->execute([$peripheral_id]);
            $stock = $stmt->fetchColumn();

            if ($stock !== false && $stock >= $quantity) {
                $pdo->prepare("UPDATE peripherals SET quantity = quantity - ? WHERE id = ?")->execute([$quantity, $peripheral_id]);
                $pdo->prepare("INSERT INTO asset_accessories (asset_id, peripheral_id, quantity_assigned, assigned_by, assigned_at) VALUES (?, ?, ?, ?, NOW())")->execute([$asset_id, $peripheral_id, $quantity, $user_id]);
                
                // Log Histórico no Periférico
                $stmtName = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtName->execute([$asset_id]); $asset_data = $stmtName->fetch();
                $reason = "Vinculado ao ativo: " . ($asset_data['name'] ?: "ID $asset_id");
                $new_qty = $stock - $quantity;
                $pdo->prepare("INSERT INTO peripheral_movements (peripheral_id, user_id, change_amount, new_quantity, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$peripheral_id, $user_id, -$quantity, $new_qty, $reason]);

                $stmtPName = $pdo->prepare("SELECT name FROM peripherals WHERE id = ?"); $stmtPName->execute([$peripheral_id]); $p_name = $stmtPName->fetchColumn();
                log_action('asset_update', "Acessório '{$p_name}' (Qtd: {$quantity}) vinculado ao ativo '{$asset_data['name']}' ({$asset_data['code']}).");
                $pdo->commit();
            } else { $pdo->rollBack(); }
            echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id&tab=accessories';</script>"; exit;
        }

        if ($action == 'unassign_accessory' && hasPermission('manage_peripherals')) {
            $assignment_id = $_POST['assignment_id'];
            $asset_id = $_POST['asset_id'];

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT peripheral_id, quantity_assigned FROM asset_accessories WHERE id = ?");
            $stmt->execute([$assignment_id]);
            if ($assignment = $stmt->fetch()) {
                // Pega estoque atual para log
                $stmtStock = $pdo->prepare("SELECT quantity FROM peripherals WHERE id = ?"); $stmtStock->execute([$assignment['peripheral_id']]); $curr_stock = $stmtStock->fetchColumn();

                $pdo->prepare("UPDATE peripherals SET quantity = quantity + ? WHERE id = ?")->execute([$assignment['quantity_assigned'], $assignment['peripheral_id']]);
                $pdo->prepare("DELETE FROM asset_accessories WHERE id = ?")->execute([$assignment_id]);

                // Log Histórico no Periférico
                $stmtName = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtName->execute([$asset_id]); $asset_data = $stmtName->fetch();
                $reason = "Devolvido do ativo: " . ($asset_data['name'] ?: "ID $asset_id");
                $new_qty = $curr_stock + $assignment['quantity_assigned'];
                $pdo->prepare("INSERT INTO peripheral_movements (peripheral_id, user_id, change_amount, new_quantity, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())")->execute([$assignment['peripheral_id'], $user_id, $assignment['quantity_assigned'], $new_qty, $reason]);

                $stmtPName = $pdo->prepare("SELECT name FROM peripherals WHERE id = ?"); $stmtPName->execute([$assignment['peripheral_id']]); $p_name = $stmtPName->fetchColumn();
                log_action('asset_update', "Acessório '{$p_name}' desvinculado do ativo '{$asset_data['name']}' ({$asset_data['code']}).");
                $pdo->commit();
            } else { $pdo->rollBack(); }
            echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id&tab=accessories';</script>"; exit;
        }

        // --- GERENCIAR VÍNCULO ENTRE ATIVOS (RELACIONADOS) ---
        if ($action == 'link_asset' && hasPermission('edit_asset')) {
            $asset_id_1 = $_POST['asset_id_1'];
            $asset_id_2 = $_POST['asset_id_2'];
            $type = $_POST['relationship_type'] ?? 'Relacionado';

            if ($asset_id_1 != $asset_id_2) {
                // Verifica se já existe vínculo (em qualquer direção)
                $stmt = $pdo->prepare("SELECT id FROM asset_links WHERE (asset_id_1 = ? AND asset_id_2 = ?) OR (asset_id_1 = ? AND asset_id_2 = ?)");
                $stmt->execute([$asset_id_1, $asset_id_2, $asset_id_2, $asset_id_1]);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO asset_links (asset_id_1, asset_id_2, relationship_type) VALUES (?, ?, ?)")->execute([$asset_id_1, $asset_id_2, $type]);
                    $stmtA1 = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA1->execute([$asset_id_1]); $a1 = $stmtA1->fetch();
                    $stmtA2 = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA2->execute([$asset_id_2]); $a2 = $stmtA2->fetch();
                    log_action('asset_update', "Ativo '{$a1['name']}' ({$a1['code']}) vinculado ao ativo '{$a2['name']}' ({$a2['code']}).");
                }
            }
            echo "<script>window.location.href = 'index.php?page=assets&id=$asset_id_1&tab=related';</script>"; exit;
        }

        if ($action == 'unlink_asset' && hasPermission('edit_asset')) {
            $link_id = $_POST['link_id'];
            $current_asset_id = $_POST['current_asset_id'];
            $pdo->prepare("DELETE FROM asset_links WHERE id = ?")->execute([$link_id]);
            $stmtA = $pdo->prepare("SELECT name, code FROM assets WHERE id = ?"); $stmtA->execute([$current_asset_id]); $a = $stmtA->fetch();
            log_action('asset_update', "Vínculo removido do ativo '{$a['name']}' ({$a['code']}).");
            echo "<script>window.location.href = 'index.php?page=assets&id=$current_asset_id&tab=related';</script>"; exit;
        }

    } catch (Exception $e) { $message = "Erro: " . $e->getMessage(); }
}

// =================================================================================
// 2. VIEW & URL
// =================================================================================

$view_mode = 'list';
$asset_detail = null;
$movements_history = [];
$asset_files = [];
$assigned_licenses = []; // Nova variável para licenças

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $view_mode = 'detail';
    $stmt = $pdo->prepare("SELECT a.*, c.name as company_name, l.name as location_name, cat.name as category_name, cat.custom_schema FROM assets a LEFT JOIN companies c ON a.company_id = c.id LEFT JOIN locations l ON a.location_id = l.id LEFT JOIN categories cat ON a.category_id = cat.id WHERE a.id = ?");
    $stmt->execute([$_GET['id']]);
    $asset_detail = $stmt->fetch();
    
    if($asset_detail) {
        // CÁLCULO DE DEPRECIAÇÃO
        $val_original = floatval($asset_detail['value']);
        $anos_vida = intval($asset_detail['lifespan_years'] ?: 5);
        $data_compra = new DateTime($asset_detail['acquisition_date']);
        $hoje = new DateTime();
        $intervalo = $data_compra->diff($hoje);
        $meses_uso = ($intervalo->y * 12) + $intervalo->m;
        $total_meses_vida = $anos_vida * 12;
        $depreciacao_mensal = $val_original / ($total_meses_vida ?: 1); // Evita div por zero
        $depreciacao_acumulada = $depreciacao_mensal * $meses_uso;
        $val_atual = max(0, $val_original - $depreciacao_acumulada);
        $vida_restante_pct = max(0, 100 - (($meses_uso / ($total_meses_vida?:1)) * 100));
        $bar_color = $vida_restante_pct > 50 ? 'bg-green-500' : ($vida_restante_pct > 20 ? 'bg-yellow-500' : 'bg-red-500');

        $stmtMov = $pdo->prepare("SELECT m.*, u.name as user_name FROM movements m LEFT JOIN users u ON m.user_id = u.id WHERE m.asset_id = ? ORDER BY m.created_at DESC");
        $stmtMov->execute([$_GET['id']]);
        $movements_history = $stmtMov->fetchAll();

        // Busca histórico de responsáveis
        $stmtResp = $pdo->prepare("
            SELECT m.responsible_name, m.created_at, u.name as assigned_by
            FROM movements m 
            LEFT JOIN users u ON m.user_id = u.id 
            WHERE m.asset_id = ? AND m.responsible_name IS NOT NULL AND m.responsible_name != ''
            ORDER BY m.created_at DESC
        ");
        $stmtResp->execute([$_GET['id']]);
        $responsible_history = $stmtResp->fetchAll();

        // Busca as licenças associadas a este ativo
        $stmtLic = $pdo->prepare("SELECT l.id, l.software_name, l.expiration_date FROM license_assignments la JOIN licenses l ON la.license_id = l.id WHERE la.asset_id = ? ORDER BY l.software_name");
        $stmtLic->execute([$_GET['id']]);
        $assigned_licenses = $stmtLic->fetchAll();

        // Busca Ativos Relacionados
        $linked_assets = [];
        try {
            $sql = "SELECT al.id as link_id, al.relationship_type, a.id, a.name, a.code, a.status, a.photo_url 
                    FROM asset_links al 
                    JOIN assets a ON (CASE WHEN al.asset_id_1 = ? THEN al.asset_id_2 ELSE al.asset_id_1 END) = a.id 
                    WHERE al.asset_id_1 = ? OR al.asset_id_2 = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_GET['id'], $_GET['id'], $_GET['id']]);
            $linked_assets = $stmt->fetchAll();
        } catch (Exception $e) { /* Tabela pode não existir */ }

        // Cria um array de IDs dos ativos já vinculados para facilitar a filtragem no formulário
        $linked_asset_ids = array_column($linked_assets, 'id');

        $peripherals = []; try { $stmtPer = $pdo->prepare("SELECT * FROM asset_peripherals WHERE asset_id = ? ORDER BY id DESC"); $stmtPer->execute([$_GET['id']]); $peripherals = $stmtPer->fetchAll(); } catch(Exception $e) { /* Tabela pode não existir */ }
        
        // Busca histórico dos periféricos listados
        $peripherals_history = [];
        if (!empty($peripherals)) {
            try {
                $p_ids = array_column($peripherals, 'id');
                $in  = str_repeat('?,', count($p_ids) - 1) . '?';
                $sql = "SELECT pm.*, u.name as user_name, a1.name as from_asset_name, a1.code as from_asset_code, a2.name as to_asset_name, a2.code as to_asset_code FROM peripheral_movements pm LEFT JOIN users u ON pm.user_id = u.id LEFT JOIN assets a1 ON pm.from_asset_id = a1.id LEFT JOIN assets a2 ON pm.to_asset_id = a2.id WHERE pm.peripheral_id IN ($in) ORDER BY pm.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p_ids);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $peripherals_history[$row['peripheral_id']][] = $row; }
            } catch (Exception $e) {}
        }

        // Busca ACESSÓRIOS associados (itens de estoque)
        $assigned_accessories = []; try { $stmtAcc = $pdo->prepare("SELECT aa.id, aa.quantity_assigned, p.name, p.sku FROM asset_accessories aa JOIN peripherals p ON aa.peripheral_id = p.id WHERE aa.asset_id = ? ORDER BY p.name"); $stmtAcc->execute([$_GET['id']]); $assigned_accessories = $stmtAcc->fetchAll(); } catch(Exception $e) {}
        // Busca periféricos de estoque disponíveis para o modal
        $available_peripherals = []; try { $available_peripherals = $pdo->query("SELECT p.id, p.name, p.quantity, p.sku, l.name as location_name FROM peripherals p LEFT JOIN locations l ON p.location_id = l.id WHERE p.quantity > 0 ORDER BY p.name")->fetchAll(); } catch(Exception $e) {}

        try { $stmtFiles = $pdo->prepare("SELECT * FROM asset_files WHERE asset_id = ? ORDER BY created_at DESC"); $stmtFiles->execute([$_GET['id']]); $asset_files = $stmtFiles->fetchAll(); } catch(Exception $e) { $asset_files = []; }
    } else { $view_mode = 'list'; }
}

$locations = $pdo->query("SELECT id, name, company_id, manager_name FROM locations")->fetchAll();
$companies = $pdo->query("SELECT id, name FROM companies")->fetchAll();
try { $categories = $pdo->query("SELECT id, name, custom_schema, company_id FROM categories")->fetchAll(); } catch(Exception $e) { $categories = []; }
try { $statuses = $pdo->query("SELECT * FROM asset_statuses")->fetchAll(); } catch(Exception $e) { $statuses = []; }
$locationsJson = json_encode($locations);
$companiesJson = json_encode($companies);
$categoriesJson = json_encode($categories);
$statusesJson = json_encode($statuses);
$user_id_for_query = $_SESSION['user_id'] ?? 0;
/*
NOTA: A funcionalidade de favoritos requer uma nova tabela no banco de dados:
CREATE TABLE `user_asset_favorites` (
  `user_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`asset_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `user_asset_favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_asset_favorites_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/
$all_assets_query = $pdo->prepare("
    -- ✅ QUERY COMPLETA CORRIGIDA PARA POSTGRESQL (Linhas 896-910)

SELECT a.*, 
       c.name as company_name, 
       l.name as location_name, 
       cat.name as category_name, 
       (SELECT COUNT(*) 
        FROM asset_peripherals ap 
        WHERE ap.asset_id = a.id AND ap.status = 'Defeito') as defective_peripherals_count,
       (SELECT COUNT(*) 
        FROM asset_links al 
        WHERE al.asset_id_1 = a.id OR al.asset_id_2 = a.id) as linked_assets_count,
       (SELECT STRING_AGG(name, ', ') 
        FROM asset_peripherals 
        WHERE asset_id = a.id) as components_list,
       (SELECT STRING_AGG(p.name || ' (' || aa.quantity_assigned || ')', ', ') 
        FROM asset_accessories aa 
        JOIN peripherals p ON aa.peripheral_id = p.id 
        WHERE aa.asset_id = a.id) as accessories_list,
       -- Favoritos (se habilitado)
       CASE 
           WHEN :favorites_enabled THEN 
               (SELECT COUNT(*) FROM user_asset_favorites WHERE asset_id = a.id AND user_id = :user_id)
           ELSE 0 
       END as is_favorite
FROM assets a 
LEFT JOIN companies c ON a.company_id = c.id 
LEFT JOIN locations l ON a.location_id = l.id 
LEFT JOIN categories cat ON a.category_id = cat.id 
GROUP BY a.id, c.name, l.name, cat.name
ORDER BY a.id DESC
");
$query_params = [
    'favorites_enabled' => $favorites_enabled ? 1 : 0,
    'user_id' => $user_id_for_query
];
$all_assets_query->execute($query_params);
$all_assets = $all_assets_query->fetchAll(PDO::FETCH_ASSOC);
$all_assets_json = json_encode($all_assets); 

function getStatusColor($statusName) {
    $s = strtolower(trim($statusName));
    if(strpos($s, 'ativo') !== false || strpos($s, 'dispon') !== false) return 'bg-green-100 text-green-700 border border-green-200';
    if(strpos($s, 'manuten') !== false || strpos($s, 'reparo') !== false) return 'bg-orange-100 text-orange-700 border border-orange-200';
    if(strpos($s, 'uso') !== false || strpos($s, 'alocado') !== false) return 'bg-blue-100 text-blue-700 border border-blue-200';
    if(strpos($s, 'baixado') !== false || strpos($s, 'roubado') !== false) return 'bg-red-100 text-red-700 border border-red-200';
    return 'bg-slate-100 text-slate-600 border border-slate-200';
}
?>

<!-- Inclui o modal de cadastro de ativo no início para garantir que as funções JS estejam disponíveis -->
<?php include_once __DIR__ . '/components/modal_asset.php'; ?>
<?php include_once __DIR__ . '/components/modal_bulk_edit.php'; ?>

<script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

<style>
    .asset-checkbox:checked { background-color: #2563eb; border-color: #2563eb; }
    .tab-btn.active { color: #2563eb; border-bottom: 2px solid #2563eb; }
    .modal-content { max-height: 80vh; overflow-y: auto; }
    .user-hidden { display: none !important; }
</style>

<script>
    // --- MODAL HELPER FUNCTIONS ---
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        modal.classList.remove('hidden');
        const backdrop = modal.querySelector('.modal-backdrop');
        const panel = modal.querySelector('.modal-panel');
        if (backdrop) backdrop.style.opacity = 1;
        if (panel) {
            panel.style.opacity = 1;
            panel.style.transform = 'scale(1)';
        }
    }

    function closeModal(modalId) { const modal = document.getElementById(modalId); if (modal) { modal.classList.add('hidden'); const backdrop = modal.querySelector('.modal-backdrop'); const panel = modal.querySelector('.modal-panel'); if(backdrop) backdrop.style.opacity = 0; if(panel) { panel.style.opacity = 0; panel.style.transform = 'scale(0.95)'; } } }
</script>

<?php if($message): ?>
    <div id="alertMessage" class="fixed top-4 right-4 z-[100] bg-white border-l-4 border-blue-500 px-6 py-4 rounded shadow-lg flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
        <div class="text-blue-500"><i data-lucide="check-circle" class="w-5 h-5"></i></div><div><?php echo $message; ?></div>
    </div>
    <script>setTimeout(() => document.getElementById('alertMessage').remove(), 5000);</script>
<?php endif; ?>

<?php if ($view_mode === 'list'): ?>
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        <div><h1 class="text-2xl font-bold text-slate-800">Ativos</h1><p class="text-sm text-slate-500"><?php echo count($all_assets); ?> ativos registrados</p></div>
        <div class="flex flex-wrap gap-2 w-full md:w-auto items-center">
            <div class="bg-slate-100 p-1 rounded-lg flex border border-slate-200 mr-2">
                <button onclick="switchView('list')" id="btnList" class="p-1.5 rounded transition-all text-slate-500 hover:text-slate-700"><i data-lucide="list" class="w-4 h-4"></i></button>
                <button onclick="switchView('grid')" id="btnGrid" class="p-1.5 rounded transition-all text-slate-500 hover:text-slate-700"><i data-lucide="layout-grid" class="w-4 h-4"></i></button>
            </div>
            <div class="relative">
                <button onclick="toggleExportMenu()" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
                    <i data-lucide="download-cloud" class="w-4 h-4 text-slate-500"></i> Exportar
                </button>
                <div id="exportMenu" class="hidden absolute right-0 mt-2 w-40 bg-white border border-slate-200 rounded-lg shadow-xl z-20 animate-in fade-in zoom-in-95 duration-150">
                    <button onclick="exportAssets('xlsx')" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"><i data-lucide="file-spreadsheet" class="w-4 h-4 text-green-600"></i> Excel</button>
                    <button onclick="exportAssets('csv')" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"><i data-lucide="file-text" class="w-4 h-4 text-blue-600"></i> CSV</button>
                    <button onclick="exportAssets('pdf')" class="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"><i data-lucide="file-text" class="w-4 h-4 text-red-600"></i> PDF</button>
                </div>
            </div>
            <div class="relative">
                <button onclick="toggleColumnMenu()" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all ml-2">
                    <i data-lucide="columns" class="w-4 h-4 text-slate-500"></i> Colunas
                </button>
                <div id="columnMenu" class="hidden absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-lg shadow-xl z-20 p-2 space-y-1 animate-in fade-in zoom-in-95 duration-150">
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('code')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Código</span>
                    </label>
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('company')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Empresa</span>
                    </label>
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('responsible')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Responsável</span>
                    </label>
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('category')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Categoria</span>
                    </label>
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('location')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Local</span>
                    </label>
                    <label class="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50 rounded cursor-pointer select-none">
                        <input type="checkbox" checked onchange="toggleColumn('status')" class="rounded text-blue-600 focus:ring-blue-500"> <span class="text-sm text-slate-700">Status</span>
                    </label>
                </div>
            </div>
            <?php if(hasPermission('create_asset')): ?>
                <button onclick="openModal('modalImport')" class="bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm transition-all"><i data-lucide="upload-cloud" class="w-4 h-4 text-blue-600"></i> Importar</button>
                <button onclick="openAssetModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center gap-2 text-sm font-medium shadow-sm hover:bg-blue-700 transition-colors"><i data-lucide="plus" class="w-4 h-4"></i> Novo Ativo</button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- BARRA DE BUSCA E BOTÃO DE FILTROS (COMPACTO) -->
    <div class="flex flex-col md:flex-row gap-3 mb-6">
        <div class="relative flex-1 w-full">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
            <input type="text" id="searchInput" onkeyup="filterContent()" placeholder="Buscar por nome, código, responsável..." class="w-full pl-9 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none bg-white shadow-sm transition-all">
        </div>
        
        <button onclick="toggleFilters()" id="btnToggleFilters" class="bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:border-slate-300 px-4 py-2.5 rounded-xl flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
            <i data-lucide="filter" class="w-4 h-4"></i> 
            <span>Filtros</span>
            <span id="activeFiltersCount" class="hidden bg-blue-100 text-blue-600 text-[10px] font-bold px-1.5 py-0.5 rounded-full ml-1">0</span>
            <i data-lucide="chevron-down" class="w-3 h-3 ml-1 transition-transform duration-200" id="filterChevron"></i>
        </button>

        <?php if($favorites_enabled): ?>
        <button id="filterFavorites" onclick="toggleFavoriteFilter(this)" class="bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:border-slate-300 px-4 py-2.5 rounded-xl flex items-center gap-2 text-sm font-medium shadow-sm transition-all">
            <i data-lucide="star" class="w-4 h-4 text-slate-400 transition-colors"></i>
            <span class="hidden md:inline">Favoritos</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- PAINEL DE FILTROS (COLLAPSIBLE) -->
    <div id="filterPanel" class="hidden bg-white p-5 rounded-xl border border-slate-200 shadow-sm mb-6 animate-in fade-in slide-in-from-top-2 duration-200">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Empresa</label>
                <div class="relative">
                    <select id="filterCompany" onchange="updateCompanyFilters()" class="w-full pl-3 pr-8 py-2 border border-slate-200 rounded-lg text-sm text-slate-700 focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none appearance-none bg-slate-50 hover:bg-white transition-colors"><option value="">Todas</option><?php foreach($companies as $c) echo "<option value='{$c['name']}'>{$c['name']}</option>"; ?></select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Localização</label>
                <div class="relative">
                    <select id="filterLocation" onchange="filterContent()" class="w-full pl-3 pr-8 py-2 border border-slate-200 rounded-lg text-sm text-slate-700 focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none appearance-none bg-slate-50 hover:bg-white transition-colors"><option value="">Todos</option><?php foreach($locations as $l) echo "<option value='{$l['name']}'>{$l['name']}</option>"; ?></select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Categoria</label>
                <div class="relative">
                    <select id="filterCategory" onchange="filterContent()" class="w-full pl-3 pr-8 py-2 border border-slate-200 rounded-lg text-sm text-slate-700 focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none appearance-none bg-slate-50 hover:bg-white transition-colors"><option value="">Todas</option><?php foreach($categories as $cat) echo "<option value='{$cat['name']}'>{$cat['name']}</option>"; ?></select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Status</label>
                <div class="relative">
                    <select id="filterStatus" onchange="filterContent()" class="w-full pl-3 pr-8 py-2 border border-slate-200 rounded-lg text-sm text-slate-700 focus:ring-2 focus:ring-blue-100 focus:border-blue-300 outline-none appearance-none bg-slate-50 hover:bg-white transition-colors"><option value="">Todos</option><?php foreach($statuses as $st) echo "<option value='{$st['name']}'>{$st['name']}</option>"; ?></select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none"></i>
                </div>
            </div>
        </div>
        <div class="mt-4 pt-4 border-t border-slate-100 flex justify-end">
            <button onclick="clearFilters()" class="text-sm text-red-500 hover:text-red-700 font-medium flex items-center gap-1">
                <i data-lucide="x" class="w-3 h-3"></i> Limpar Filtros
            </button>
        </div>
    </div>

    <?php if(hasPermission('edit_asset') || hasPermission('delete_asset')): ?>
    <div id="selectionToolbar" class="hidden flex-col md:flex-row items-start md:items-center justify-between bg-blue-50 px-4 py-3 rounded-xl border border-blue-100 mb-4 transition-all gap-3">
        <div class="flex items-center gap-3 w-full md:w-auto">
            <div class="flex items-center gap-3">
                <span class="flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full" id="selectedCount">0</span>
                <span class="text-sm font-medium text-blue-900">selecionados</span>
            </div>
            <button onclick="clearSelection()" class="ml-auto md:ml-2 text-xs font-bold text-blue-600 hover:underline">Limpar</button>
        </div>
        <div class="flex flex-col md:flex-row gap-2 w-full md:w-auto">
            <?php if(hasPermission('edit_asset')): ?>
                <button id="bulkEditBtn" onclick="openBulkEditModal()" class="hidden w-full md:w-auto justify-center text-blue-700 bg-white border border-blue-200 hover:bg-blue-100 px-3 py-1.5 rounded-lg text-sm font-medium items-center gap-2 transition-colors"><i data-lucide="edit" class="w-4 h-4"></i> Editar em Massa</button>
                <button id="transferCompanyBtn" onclick="openTransferModal()" class="w-full md:w-auto justify-center text-purple-700 bg-white border border-purple-200 hover:bg-purple-100 px-3 py-1.5 rounded-lg text-sm font-medium flex items-center gap-2 transition-colors"><i data-lucide="building-2" class="w-4 h-4"></i> Transferir</button>
            <?php endif; ?>
            <?php if(hasPermission('delete_asset')): ?>
                <button id="bulkDeleteBtn" onclick="confirmBulkDelete()" class="hidden w-full md:w-auto justify-center text-red-600 bg-white border border-red-200 hover:bg-red-100 px-3 py-1.5 rounded-lg text-sm font-medium items-center gap-2 transition-colors"><i data-lucide="trash-2" class="w-4 h-4"></i> Excluir</button>
            <?php endif; ?>
            <button id="printLabelsBtn" onclick="openPrintConfig()" class="w-full md:w-auto justify-center text-blue-700 bg-white border border-blue-200 hover:bg-blue-100 px-3 py-1.5 rounded-lg text-sm font-medium flex items-center gap-2 transition-colors"><i data-lucide="printer" class="w-4 h-4"></i> Etiquetas</button>
        </div>
        <form id="formBulkDelete" method="POST" class="hidden"><input type="hidden" name="action" value="bulk_delete_assets"><input type="hidden" name="ids" id="bulkDeleteIds"></form>
    </div>
    <?php endif; ?>

    <div id="viewGrid" class="hidden grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
        <?php foreach($all_assets as $asset): $searchString = strtolower(implode(' ', [$asset['name'], $asset['code'], $asset['status'], $asset['company_name']??'', $asset['responsible_name']??'', $asset['category_name']??'', $asset['location_name']??'', $asset['brand']??'', $asset['model']??'', $asset['components_list']??'', $asset['accessories_list']??''])); ?>
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm hover:shadow-lg hover:border-blue-200 transition-all duration-300 overflow-hidden group asset-card relative flex flex-col" data-search="<?php echo htmlspecialchars($searchString); ?>" data-favorite="<?php echo $asset['is_favorite'] ? '1' : '0'; ?>" data-asset-id="<?php echo $asset['id']; ?>">
            <?php if(hasPermission('edit_asset') || hasPermission('delete_asset')): ?><div class="absolute top-3 left-3 z-10"><input type="checkbox" name="selected_assets[]" value="<?php echo $asset['id']; ?>" class="asset-checkbox w-5 h-5 rounded border-gray-300 text-blue-600 cursor-pointer" onchange="updateSelection()"></div><?php endif; ?>
            <div class="relative h-48 bg-slate-50 flex items-center justify-center border-b border-slate-100 overflow-hidden cursor-pointer" onclick='openQuickView(<?php echo htmlspecialchars(json_encode($asset), ENT_QUOTES, "UTF-8"); ?>)'>
                <?php if($asset['photo_url']): ?><img src="<?php echo htmlspecialchars($asset['photo_url']); ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"><?php else: ?><i data-lucide="box" class="w-12 h-12 text-slate-300 group-hover:scale-110 transition-transform duration-500"></i><?php endif; ?>
                <div class="absolute top-3 right-3"><span class="px-2 py-1 rounded text-[10px] font-bold uppercase bg-white/90 backdrop-blur border shadow-sm <?php echo getStatusColor($asset['status']); ?>"><?php echo $asset['status']; ?></span></div>
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/5 transition-colors flex items-center justify-center">
                    <span class="opacity-0 group-hover:opacity-100 bg-white/90 backdrop-blur px-3 py-1.5 rounded-full text-xs font-bold text-slate-700 shadow-sm transform translate-y-2 group-hover:translate-y-0 transition-all duration-300 flex items-center gap-2"><i data-lucide="eye" class="w-3 h-3"></i> Visualizar</span>
                </div>
            </div>
            <div class="p-4 flex-1 flex flex-col">
                <div class="mb-2">
                    <h3 class="font-bold text-slate-800 text-sm flex items-center gap-1">
                        <span class="truncate"><?php echo htmlspecialchars($asset['name']); ?></span>
                        <?php if(($asset['defective_peripherals_count'] ?? 0) > 0): ?><span class="text-red-500 shrink-0" title="Periférico com defeito"><i data-lucide="alert-triangle" class="w-3 h-3"></i></span><?php endif; ?>
                    </h3>
                    <?php if(!empty($asset['components_list'])): ?>
                        <p class="text-xs text-slate-500 mt-1 truncate" title="Componentes: <?php echo htmlspecialchars($asset['components_list']); ?>"><i data-lucide="cpu" class="w-3 h-3 inline mr-1"></i><?php echo htmlspecialchars($asset['components_list']); ?></p>
                    <?php endif; ?>
                    <?php if(!empty($asset['accessories_list'])): ?>
                        <p class="text-xs text-slate-500 mt-1 truncate" title="Acessórios: <?php echo htmlspecialchars($asset['accessories_list']); ?>"><i data-lucide="plug" class="w-3 h-3 inline mr-1"></i><?php echo htmlspecialchars($asset['accessories_list']); ?></p>
                    <?php endif; ?>
                    <?php if($asset['linked_assets_count'] > 0): ?>
                        <p class="text-xs text-blue-500 mt-1 truncate" title="<?php echo $asset['linked_assets_count']; ?> ativo(s) relacionado(s)"><i data-lucide="link-2" class="w-3 h-3 inline mr-1"></i>Relacionado</p>
                    <?php endif; ?>
                    <p class="text-xs text-slate-500 font-mono mt-1"><?php echo htmlspecialchars($asset['code']); ?></p>
                </div>
                <div class="flex justify-between items-center border-t border-slate-100 pt-3 mt-auto">
                    <span class="text-sm font-semibold text-slate-700">R$ <?php echo number_format($asset['value'], 2, ',', '.'); ?></span>
                    <div class="flex gap-1 items-center">
                        <?php if($favorites_enabled): ?>
                        <button onclick="toggleFavorite(this, <?php echo $asset['id']; ?>)" class="p-1.5 text-slate-400 hover:text-yellow-500 rounded favorite-btn" title="Favoritar">
                            <i data-lucide="star" class="w-4 h-4 transition-colors <?php if($asset['is_favorite']) echo 'fill-current text-yellow-400'; ?>"></i>
                        </button>
                        <?php endif; ?>
                        <button onclick="showQrCode('<?php echo $asset['code']; ?>', '<?php echo htmlspecialchars($asset['name'], ENT_QUOTES); ?>')" class="p-1.5 text-slate-400 hover:text-purple-600 rounded"><i data-lucide="qr-code" class="w-4 h-4"></i></button>
                        <a href="index.php?page=assets&id=<?php echo $asset['id']; ?>" class="p-1.5 text-slate-400 hover:text-blue-600 rounded"><i data-lucide="eye" class="w-4 h-4"></i></a>
                        <?php if(hasPermission('create_asset')): ?>
                            <button onclick='cloneAsset(<?php echo htmlspecialchars(json_encode($asset), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-green-600 rounded" title="Clonar"><i data-lucide="copy" class="w-4 h-4"></i></button>
                        <?php endif; ?>
                        <?php if(hasPermission('edit_asset')): ?>
                            <button onclick='openAssetModal(<?php echo htmlspecialchars(json_encode($asset), ENT_QUOTES, "UTF-8"); ?>)' class="p-1.5 text-slate-400 hover:text-blue-600 rounded"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                        <?php endif; ?>
                        <?php if(hasPermission('delete_asset')): ?>
                            <button onclick="confirmDelete(<?php echo $asset['id']; ?>)" class="p-1.5 text-red-400 hover:text-red-600 rounded"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div id="noResultsState" class="hidden flex flex-col items-center justify-center py-16 text-center bg-white rounded-xl border border-slate-200 border-dashed mb-6 animate-in fade-in zoom-in-95 duration-300">
        <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center mb-4 shadow-inner">
            <i data-lucide="search-x" class="w-8 h-8"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-700">Nenhum ativo encontrado</h3>
        <p class="text-slate-500 max-w-xs mx-auto mt-1 text-sm">Não encontramos registros com os filtros atuais.</p>
        <button onclick="clearFilters()" class="mt-4 text-blue-600 font-bold text-sm hover:text-blue-700 hover:underline transition-colors">Limpar Filtros</button>
    </div>

    <div id="viewList" class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden flex flex-col mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left" id="assetsTable">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200 sticky top-0 z-10 shadow-sm">
                    <tr>
                        <?php if(hasPermission('edit_asset') || hasPermission('delete_asset')): ?><th class="p-4 w-10"><input type="checkbox" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-blue-600 cursor-pointer"></th><?php endif; ?>
                        <th class="p-4 col-code cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('code')"><div class="flex items-center gap-1">Código<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="code"></i></div></th>
                        <th class="p-4 cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('name')"><div class="flex items-center gap-1">Nome<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="name"></i></div></th>
                        <th class="p-4 hidden md:table-cell col-company cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('company_name')"><div class="flex items-center gap-1">Empresa<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="company_name"></i></div></th>
                        <th class="p-4 hidden md:table-cell col-responsible cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('responsible_name')"><div class="flex items-center gap-1">Responsável<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="responsible_name"></i></div></th>
                        <th class="p-4 hidden md:table-cell col-category cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('category_name')"><div class="flex items-center gap-1">Categoria<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="category_name"></i></div></th>
                        <th class="p-4 hidden md:table-cell col-location cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('location_name')"><div class="flex items-center gap-1">Local<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="location_name"></i></div></th>
                        <th class="p-4 col-status cursor-pointer hover:bg-slate-100 transition-colors" onclick="applySort('status')"><div class="flex items-center gap-1">Status<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="status"></i></div></th>
                        <th class="p-4 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($all_assets as $asset): $searchString = strtolower(implode(' ', [$asset['name'], $asset['code'], $asset['status'], $asset['company_name']??'', $asset['responsible_name']??'', $asset['category_name']??'', $asset['location_name']??'', $asset['brand']??'', $asset['model']??'', $asset['components_list']??'', $asset['accessories_list']??'', ($asset['linked_assets_count'] > 0 ? 'relacionado' : '')])); ?>
                    <tr class="hover:bg-slate-50 transition-colors group asset-row" data-search="<?php echo htmlspecialchars($searchString); ?>" data-favorite="<?php echo $asset['is_favorite'] ? '1' : '0'; ?>" data-asset-id="<?php echo $asset['id']; ?>">
                        <?php if(hasPermission('edit_asset') || hasPermission('delete_asset')): ?><td class="p-4"><input type="checkbox" name="selected_assets[]" value="<?php echo $asset['id']; ?>" class="asset-checkbox rounded border-gray-300 text-blue-600 cursor-pointer" onchange="updateSelection()"></td><?php endif; ?>
                        <td class="p-4 font-mono text-slate-500 col-code"><?php echo htmlspecialchars($asset['code']); ?></td>
                        <td class="p-4 font-medium text-slate-800">
                            <div><?php echo htmlspecialchars($asset['name']); ?></div>
                            <?php if(($asset['defective_peripherals_count'] ?? 0) > 0): ?><span class="inline-flex items-center justify-center w-5 h-5 bg-red-100 text-red-600 rounded-full ml-2" title="Periférico com defeito"><i data-lucide="alert-triangle" class="w-3 h-3"></i></span><?php endif; ?>
                            <?php if(!empty($asset['components_list'])): ?>
                                <div class="text-xs text-slate-500 mt-1 flex items-start gap-1" title="Componentes"><i data-lucide="cpu" class="w-3 h-3 mt-0.5 shrink-0"></i><span class="truncate max-w-xs"><?php echo htmlspecialchars($asset['components_list']); ?></span></div>
                            <?php endif; ?>
                            <?php if(!empty($asset['accessories_list'])): ?>
                                <div class="text-xs text-slate-500 mt-1 flex items-start gap-1" title="Acessórios"><i data-lucide="plug" class="w-3 h-3 mt-0.5 shrink-0"></i><span class="truncate max-w-xs"><?php echo htmlspecialchars($asset['accessories_list']); ?></span></div>
                            <?php endif; ?>
                            <?php if($asset['linked_assets_count'] > 0): ?>
                                <div class="text-xs text-blue-500 mt-1 flex items-start gap-1" title="Possui ativos relacionados"><i data-lucide="link-2" class="w-3 h-3 mt-0.5 shrink-0"></i><span><?php echo $asset['linked_assets_count']; ?> relacionado(s)</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 hidden md:table-cell text-slate-600 col-company"><?php echo htmlspecialchars($asset['company_name'] ?? '-'); ?></td>
                        <td class="p-4 hidden md:table-cell text-slate-600 col-responsible"><?php echo htmlspecialchars($asset['responsible_name'] ?? '-'); ?></td>
                        <td class="p-4 hidden md:table-cell text-slate-600 col-category"><?php echo htmlspecialchars($asset['category_name'] ?? '-'); ?></td>
                        <td class="p-4 hidden md:table-cell text-slate-600 col-location"><?php echo htmlspecialchars($asset['location_name'] ?? '-'); ?></td>
                        <td class="p-4 col-status"><span class="px-2 py-1 rounded text-[10px] font-bold uppercase border <?php echo getStatusColor($asset['status']); ?>"><?php echo $asset['status']; ?></span></td>
                        <td class="p-4 text-right">
                            <div class="flex justify-end gap-2 items-center">
                                <?php if($favorites_enabled): ?>
                                <button onclick="toggleFavorite(this, <?php echo $asset['id']; ?>)" class="text-slate-400 hover:text-yellow-500 favorite-btn" title="Favoritar">
                                    <i data-lucide="star" class="w-4 h-4 transition-colors <?php if($asset['is_favorite']) echo 'fill-current text-yellow-400'; ?>"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="showQrCode('<?php echo $asset['code']; ?>', '<?php echo htmlspecialchars($asset['name'], ENT_QUOTES); ?>')" class="text-slate-400 hover:text-purple-600"><i data-lucide="qr-code" class="w-4 h-4"></i></button>
                                <a href="index.php?page=assets&id=<?php echo $asset['id']; ?>" class="text-slate-400 hover:text-blue-600"><i data-lucide="eye" class="w-4 h-4"></i></a>
                                <?php if(hasPermission('create_asset')): ?>
                                    <button onclick='cloneAsset(<?php echo htmlspecialchars(json_encode($asset), ENT_QUOTES, "UTF-8"); ?>)' class="text-slate-400 hover:text-green-600" title="Clonar"><i data-lucide="copy" class="w-4 h-4"></i></button>
                                <?php endif; ?>
                                <?php if(hasPermission('edit_asset')): ?>
                                    <button onclick='openAssetModal(<?php echo htmlspecialchars(json_encode($asset), ENT_QUOTES, "UTF-8"); ?>)' class="text-slate-400 hover:text-blue-600"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                                <?php endif; ?>
                                <?php if(hasPermission('delete_asset')): ?>
                                    <button onclick="confirmDelete(<?php echo $asset['id']; ?>)" class="text-red-400 hover:text-red-600"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div> <div id="paginationToolbar" class="flex flex-col md:flex-row justify-between items-center p-4 bg-white border border-slate-200 rounded-xl shadow-sm gap-4 mb-8">
        <div class="flex items-center gap-2 text-sm text-slate-600">
            <span>Mostrar</span>
            <select id="itemsPerPage" onchange="changeItemsPerPage()" class="border border-slate-300 rounded-lg p-1.5 bg-white outline-none focus:border-blue-500">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">Todos</option>
            </select>
            <span>por página</span>
        </div>
        <div class="text-sm text-slate-500 font-medium">
            Mostrando <span id="pageInfoStart">0</span> - <span id="pageInfoEnd">0</span> de <span id="pageInfoTotal">0</span> registros
        </div>
        <div class="flex items-center gap-2">
            <button onclick="changePage(-1)" id="btnPrevPage" class="p-2 border bg-white rounded-lg hover:bg-slate-50 disabled:opacity-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></button>
            <span class="px-4 py-2 bg-slate-50 border rounded-lg text-sm font-bold text-slate-700" id="pageIndicator">1</span>
            <button onclick="changePage(1)" id="btnNextPage" class="p-2 border bg-white rounded-lg hover:bg-slate-50 disabled:opacity-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></button>
        </div>
    </div>

<?php else: ?>
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <div class="flex items-center gap-4"><a href="index.php?page=assets" class="p-2 bg-white border border-slate-200 rounded-lg text-slate-500 hover:text-slate-800"><i data-lucide="arrow-left" class="w-5 h-5"></i></a><div><div class="flex items-center gap-3"><h1 class="text-2xl font-bold text-slate-800"><?php echo htmlspecialchars($asset_detail['name']); ?></h1><span class="px-2 py-0.5 rounded text-sm font-semibold capitalize border <?php echo getStatusColor($asset_detail['status']); ?>"><?php echo $asset_detail['status']; ?></span></div><p class="text-slate-500 font-mono text-sm mt-1"><?php echo htmlspecialchars($asset_detail['code']); ?></p></div></div>
            <div class="flex gap-2">
                <?php if(hasPermission('move_asset')): ?>
                    <button onclick='openMoveModal(<?php echo htmlspecialchars(json_encode($asset_detail), ENT_QUOTES, "UTF-8"); ?>)' class="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-lg font-medium flex gap-2"><i data-lucide="arrow-right-left" class="w-4"></i> Movimentar</button>
                <?php endif; ?>
                <?php if(hasPermission('edit_asset')): ?>
                    <button onclick='openAssetModal(<?php echo htmlspecialchars(json_encode($asset_detail), ENT_QUOTES, "UTF-8"); ?>)' class="px-4 py-2 bg-blue-600 text-white rounded-lg font-medium flex gap-2"><i data-lucide="edit-2" class="w-4"></i> Editar</button>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
             <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5"><div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><i data-lucide="map-pin" class="w-5 h-5"></i></div><div><p class="text-xs text-slate-500 uppercase font-semibold">Localização</p><p class="font-medium text-slate-800 truncate" title="<?php echo htmlspecialchars($asset_detail['location_name'] ?? '-'); ?>"><?php echo htmlspecialchars($asset_detail['location_name'] ?? '-'); ?></p></div></div>
             <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5"><div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0"><i data-lucide="user" class="w-5 h-5"></i></div><div><p class="text-xs text-slate-500 uppercase font-semibold">Responsável</p><p class="font-medium text-slate-800 truncate" title="<?php echo htmlspecialchars($asset_detail['responsible_name'] ?? 'Não designado'); ?>"><?php echo htmlspecialchars($asset_detail['responsible_name'] ?? 'Não designado'); ?></p></div></div>
             
             <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-col justify-center relative overflow-hidden group hover:shadow-md transition-all duration-200 hover:-translate-y-0.5">
                 <div class="flex justify-between items-center z-10 relative">
                     <div>
                         <p class="text-xs text-slate-500 uppercase font-semibold">Valor Atual</p>
                         <p class="font-bold text-lg text-slate-800">R$ <?php echo number_format($val_atual, 2, ',', '.'); ?></p>
                     </div>
                     <div class="text-right">
                         <span class="text-xs font-bold <?php echo $vida_restante_pct < 20 ? 'text-red-600' : 'text-green-600'; ?>">
                             <?php echo round($vida_restante_pct); ?>% vida
                         </span>
                     </div>
                 </div>
                 <div class="w-full bg-slate-100 h-1.5 mt-2 rounded-full overflow-hidden z-10 relative">
                     <div class="h-full <?php echo $bar_color; ?>" style="width: <?php echo $vida_restante_pct; ?>%"></div>
                 </div>
                 <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-95 transition-opacity flex items-center justify-center text-xs text-slate-600 font-medium z-20 shadow-sm">
                     Pago: R$ <?php echo number_format($val_original, 2, ',', '.'); ?>
                 </div>
             </div>

             <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5"><div class="w-10 h-10 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center shrink-0"><i data-lucide="tag" class="w-5 h-5"></i></div><div><p class="text-xs text-slate-500 uppercase font-semibold">Categoria</p><p class="font-medium text-slate-800 truncate" title="<?php echo htmlspecialchars($asset_detail['category_name'] ?? '-'); ?>"><?php echo htmlspecialchars($asset_detail['category_name'] ?? '-'); ?></p></div></div>
             <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex items-center gap-4 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5"><div class="w-10 h-10 rounded-lg bg-orange-50 text-orange-600 flex items-center justify-center shrink-0"><i data-lucide="calendar" class="w-5 h-5"></i></div><div><p class="text-xs text-slate-500 uppercase font-semibold">Aquisição</p><p class="font-medium text-slate-800"><?php echo date('d/m/Y', strtotime($asset_detail['acquisition_date'])); ?></p></div></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden min-h-[500px]">
                    <div class="flex border-b border-slate-100 px-6 overflow-x-auto">
                        <button onclick="switchTab('details')" id="tab-details" class="tab-btn active py-4 px-4 text-sm font-medium border-b-2 border-blue-600 text-blue-600 whitespace-nowrap">Detalhes</button>
                        <button onclick="switchTab('history')" id="tab-history" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Histórico</button>
                        <button onclick="switchTab('responsible')" id="tab-responsible" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Responsáveis</button>
                        <button onclick="switchTab('components')" id="tab-components" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Componentes</button>
                        <button onclick="switchTab('accessories')" id="tab-accessories" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Acessórios</button>
                        <button onclick="switchTab('related')" id="tab-related" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Relacionados</button>
                        <button onclick="switchTab('photos')" id="tab-photos" class="tab-btn py-4 px-4 text-sm font-medium text-slate-500 whitespace-nowrap">Fotos e Anexos</button>
                    </div>

                    <div id="content-details" class="tab-content active p-6">
                        <div class="grid grid-cols-2 gap-6 mb-6">
                            <div><label class="text-xs text-slate-400 uppercase block mb-1">Marca</label><p class="text-slate-800 font-medium"><?php echo htmlspecialchars($asset_detail['brand']?:'-'); ?></p></div>
                            <div><label class="text-xs text-slate-400 uppercase block mb-1">Modelo</label><p class="text-slate-800 font-medium"><?php echo htmlspecialchars($asset_detail['model']?:'-'); ?></p></div>
                            <div><label class="text-xs text-slate-400 uppercase block mb-1">Série</label><p class="text-slate-800 font-medium font-mono"><?php echo htmlspecialchars($asset_detail['serial_number']?:'-'); ?></p></div>
                            <div><label class="text-xs text-slate-400 uppercase block mb-1">Centro de Custo</label><p class="text-slate-800 font-medium"><?php echo htmlspecialchars($asset_detail['cost_center']?:'-'); ?></p></div>
                        </div>
                        <?php if(!empty($asset_detail['custom_attributes'])): $custom = json_decode($asset_detail['custom_attributes'], true); if(is_array($custom)): ?>
                            <div class="mb-6 p-4 bg-blue-50/50 rounded-lg border border-blue-100"><h4 class="text-xs font-bold text-blue-800 uppercase mb-3 flex items-center gap-2"><i data-lucide="sliders" class="w-3 h-3"></i> Especificações</h4><div class="grid grid-cols-2 gap-4"><?php foreach($custom as $key => $val): ?><div><label class="text-xs text-slate-400 uppercase block mb-1"><?php echo ucfirst(str_replace('_',' ',$key)); ?></label><p class="text-slate-800 font-medium"><?php echo htmlspecialchars($val); ?></p></div><?php endforeach; ?></div></div>
                        <?php endif; endif; ?>
                        
                        <?php if($asset_detail['next_maintenance_date']): ?>
                            <div class="mb-6 p-4 bg-blue-50/50 rounded-lg border border-blue-100"><h4 class="text-xs font-bold text-blue-800 uppercase mb-3 flex items-center gap-2"><i data-lucide="calendar-clock" class="w-3 h-3"></i> Manutenção Preventiva</h4><div class="grid grid-cols-2 gap-4"><div><label class="text-xs text-slate-400 uppercase block mb-1">Próxima Data</label><p class="text-slate-800 font-medium"><?php echo date('d/m/Y', strtotime($asset_detail['next_maintenance_date'])); ?></p></div><div><label class="text-xs text-slate-400 uppercase block mb-1">Frequência</label><p class="text-slate-800 font-medium"><?php echo $asset_detail['maintenance_freq'] ? $asset_detail['maintenance_freq'].' dias' : '-'; ?></p></div></div></div>
                        <?php endif; ?>

                        <div class="mb-6"><label class="text-xs text-slate-400 uppercase block mb-1">Descrição</label><p class="text-sm text-slate-600 bg-slate-50 p-4 rounded-lg leading-relaxed border border-slate-100"><?php echo nl2br(htmlspecialchars($asset_detail['description'] ?: 'Sem descrição.')); ?></p></div>
                    </div>

                    <div id="content-history" class="tab-content hidden p-6">
                         <?php if(empty($movements_history)): ?><div class="text-center py-10 text-slate-400"><p>Nenhum histórico.</p></div><?php else: ?>
                            <div class="space-y-6 border-l-2 border-slate-200 ml-4 pl-6">
                            <?php foreach($movements_history as $mov): ?>
                                <div class="relative">
                                    <div class="absolute -left-[31px] top-0 w-4 h-4 bg-white border-2 border-blue-500 rounded-full"></div>
                                    <p class="text-xs text-slate-400 mb-1"><?php echo date('d/m/Y H:i', strtotime($mov['created_at'])); ?></p>
                                    <p class="text-sm text-slate-800 font-medium">
                                        <?php 
                                            $description = htmlspecialchars($mov['description']);
                                            $parts = explode('Motivo:', $description, 2);
                                            if (count($parts) === 2) {
                                                echo $parts[0] . 'Motivo: <strong class="text-slate-900">' . trim($parts[1]) . '</strong>';
                                            } else { echo $description; }
                                        ?>
                                    </p>
                                    <p class="text-xs text-slate-500 mt-1">Por: <?php echo htmlspecialchars($mov['user_name']??'Sistema'); ?></p>
                                </div>
                            <?php endforeach; ?>
                            </div>
                         <?php endif; ?>
                    </div>

                    <div id="content-responsible" class="tab-content hidden p-6">
                         <?php if(empty($responsible_history)): ?><div class="text-center py-10 text-slate-400"><p>Nenhum histórico de responsáveis.</p></div><?php else: ?>
                            <div class="space-y-6 border-l-2 border-slate-200 ml-4 pl-6">
                            <?php foreach($responsible_history as $idx => $resp): 
                                $is_current = ($idx === 0);
                                $start_date = date('d/m/Y', strtotime($resp['created_at']));
                                $end_date = ($idx === 0) ? 'Atual' : date('d/m/Y', strtotime($responsible_history[$idx-1]['created_at']));
                            ?>
                                <div class="relative">
                                    <div class="absolute -left-[31px] top-1 w-4 h-4 rounded-full border-2 <?php echo $is_current ? 'bg-green-500 border-green-100' : 'bg-slate-300 border-white'; ?>"></div>
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-2">
                                        <div>
                                            <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($resp['responsible_name']); ?></p>
                                            <p class="text-xs text-slate-500 mt-0.5">Atribuído por: <?php echo htmlspecialchars($resp['assigned_by'] ?? 'Sistema'); ?></p>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs font-mono text-slate-500 bg-slate-100 px-2 py-1 rounded border border-slate-200 whitespace-nowrap">
                                                <?php echo $start_date; ?> <i data-lucide="arrow-right" class="w-3 h-3 inline mx-1"></i> <?php echo $end_date; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                         <?php endif; ?>
                    </div>

                    <div id="content-photos" class="tab-content hidden p-6">
                         <?php if(hasPermission('edit_asset')): ?>
                         <form method="POST" enctype="multipart/form-data" class="mb-6 p-4 border-2 border-dashed border-slate-300 rounded-lg text-center cursor-pointer">
                            <input type="hidden" name="action" value="upload_attachment"><input type="hidden" name="asset_id" value="<?php echo $asset_detail['id']; ?>">
                            <input type="file" name="attachment[]" multiple class="hidden" id="fileUpload" onchange="this.form.submit()">
                            <label for="fileUpload" class="cursor-pointer text-blue-600 font-medium">Clique para enviar arquivos</label>
                         </form>
                         <?php endif; ?>
                         <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php foreach($asset_files as $file): $isImg = in_array(strtolower($file['file_type']), ['jpg','jpeg','png']); ?>
                                <div class="border rounded p-2 text-center group relative">
                                    <?php if($isImg): ?><img src="<?php echo $file['file_path']; ?>" class="h-24 mx-auto object-contain"><?php else: ?><i data-lucide="file" class="w-12 h-12 mx-auto text-slate-300"></i><?php endif; ?>
                                    <p class="text-xs truncate mt-2"><?php echo $file['file_name']; ?></p>
                                    <?php if(hasPermission('edit_asset')): ?><form method="POST" onsubmit="return confirm('Excluir?')" class="absolute top-1 right-1 hidden group-hover:block"><input type="hidden" name="action" value="delete_attachment"><input type="hidden" name="file_id" value="<?php echo $file['id']; ?>"><button class="bg-red-500 text-white p-1 rounded"><i data-lucide="trash" class="w-3 h-3"></i></button></form><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                         </div>
                    </div>

                    <div id="content-accessories" class="tab-content hidden p-6">
                        <?php if(hasPermission('manage_peripherals')): ?>
                        <form method="POST" class="mb-6 bg-slate-50 p-5 rounded-xl border border-slate-200 shadow-sm">
                            <input type="hidden" name="action" value="assign_accessory">
                            <input type="hidden" name="asset_id" value="<?php echo $asset_detail['id']; ?>">
                            
                            <div class="flex items-start gap-3 mb-5">
                                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg shrink-0">
                                    <i data-lucide="package-plus" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-slate-800">Vincular Acessório do Estoque</h4>
                                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                                        Selecione um item do estoque geral (ex: Mouse, Cabo) para vincular a este ativo. 
                                        A quantidade será descontada do inventário automaticamente.
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-[3fr,1fr,auto] gap-4 items-end">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Item Disponível</label>
                                    <div class="relative">
                                        <select name="peripheral_id" required class="w-full border border-slate-300 rounded-lg pl-3 pr-8 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white appearance-none">
                                            <option value="">Selecione o acessório...</option>
                                            <?php foreach($available_peripherals as $item): ?>
                                                <option value="<?php echo $item['id']; ?>">
                                                    <?php echo htmlspecialchars($item['name']); ?> (Disponível: <?php echo $item['quantity']; ?> | Setor: <?php echo htmlspecialchars($item['location_name'] ?? 'N/A'); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <i data-lucide="chevron-down" class="absolute right-3 top-3 w-4 h-4 text-slate-400 pointer-events-none"></i>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Quantidade</label>
                                    <input type="number" name="quantity" value="1" min="1" required class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm flex items-center gap-2">
                                    <i data-lucide="link" class="w-4 h-4"></i> Vincular
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 text-slate-500 font-semibold border-b">
                                    <tr>
                                        <th class="p-3">Acessório</th>
                                        <th class="p-3">SKU</th>
                                        <th class="p-3 text-center">Qtd. Vinculada</th>
                                        <th class="p-3 text-right">Ações</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if(empty($assigned_accessories)): ?>
                                        <tr>
                                            <td colspan="4" class="p-8 text-center text-slate-400 italic">
                                                Nenhum acessório associado a este ativo.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($assigned_accessories as $acc): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="p-3 font-medium text-slate-700">
                                                <?php echo htmlspecialchars($acc['name']); ?>
                                            </td>
                                            <td class="p-3 font-mono text-slate-500 text-xs">
                                                <?php echo htmlspecialchars($acc['sku'] ?: '-'); ?>
                                            </td>
                                            <td class="p-3 text-center">
                                                <span class="bg-slate-100 text-slate-700 px-2 py-1 rounded text-xs font-bold border border-slate-200">
                                                    <?php echo $acc['quantity_assigned']; ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-right">
                                                <?php if(hasPermission('manage_peripherals')): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="unassign_accessory">
                                                    <input type="hidden" name="asset_id" value="<?php echo $asset_detail['id']; ?>">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $acc['id']; ?>">
                                                    <button class="text-red-400 hover:text-red-600 hover:bg-red-50 p-1.5 rounded transition-colors" title="Devolver ao Estoque">
                                                        <i data-lucide="undo-2" class="w-4 h-4"></i>
                                                    </button>
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

                    <div id="content-related" class="tab-content hidden p-6">
                        <?php if(hasPermission('edit_asset')): ?>
                        <form method="POST" class="mb-6 bg-slate-50 p-5 rounded-xl border border-slate-200 shadow-sm">
                            <input type="hidden" name="action" value="link_asset">
                            <input type="hidden" name="asset_id_1" value="<?php echo $asset_detail['id']; ?>">
                            
                            <div class="flex items-start gap-3 mb-4">
                                <div class="p-2 bg-blue-100 text-blue-600 rounded-lg shrink-0"><i data-lucide="link-2" class="w-5 h-5"></i></div>
                                <div><h4 class="text-sm font-bold text-slate-800">Vincular Outro Ativo</h4><p class="text-xs text-slate-500 mt-1">Crie uma relação com outro equipamento (ex: Monitor, Dockstation).</p></div>
                            </div>

                            <div class="flex gap-3 items-end">
                                <div class="flex-1">
                                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1.5 ml-1">Ativo para vincular</label>
                                    <div class="relative mb-2">
                                        <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-400"></i>
                                        <input type="text" id="searchRelatedAsset" onkeyup="filterRelatedAssets()" placeholder="Buscar por nome ou código..." class="w-full pl-9 pr-4 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <select name="asset_id_2" id="selectRelatedAsset" required class="w-full border border-slate-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                        <option value="">Selecione...</option>
                                        <?php foreach($all_assets as $a): 
                                            if($a['id'] == $asset_detail['id']) continue; // Não listar o próprio ativo
                                            if (in_array($a['id'], $linked_asset_ids)) continue; // Não listar ativos já vinculados
                                        ?>
                                            <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?> (<?php echo $a['code']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm flex items-center gap-2"><i data-lucide="plus" class="w-4 h-4"></i> Adicionar</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <div class="space-y-3">
                            <?php if(empty($linked_assets)): ?>
                                <div class="text-center py-8 text-slate-400 italic border-2 border-dashed border-slate-100 rounded-xl">Nenhum ativo relacionado.</div>
                            <?php else: ?>
                                <?php foreach($linked_assets as $link): ?>
                                <div class="flex items-center justify-between p-3 bg-white border border-slate-200 rounded-xl hover:border-blue-200 transition-colors group">
                                    <div class="flex items-center gap-3">
                                        <?php if($link['photo_url']): ?><img src="<?php echo htmlspecialchars($link['photo_url']); ?>" class="w-10 h-10 rounded-lg object-cover bg-slate-50 border"><?php else: ?><div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400"><i data-lucide="box" class="w-5 h-5"></i></div><?php endif; ?>
                                        <div>
                                            <a href="index.php?page=assets&id=<?php echo $link['id']; ?>" class="text-sm font-bold text-slate-700 hover:text-blue-600 hover:underline">
                                                <?php echo htmlspecialchars($link['name']); ?>
                                                <span class="font-normal text-slate-400">(<?php echo htmlspecialchars($link['code']); ?>)</span>
                                            </a>
                                            <p class="text-xs text-slate-500 mt-1"><span class="<?php echo strpos(strtolower($link['status']), 'ativo')!==false?'text-green-600':'text-slate-500'; ?>"><?php echo htmlspecialchars($link['status']); ?></span></p>
                                        </div>
                                    </div>
                                    <?php if(hasPermission('edit_asset')): ?><form method="POST"><input type="hidden" name="action" value="unlink_asset"><input type="hidden" name="link_id" value="<?php echo $link['link_id']; ?>"><input type="hidden" name="current_asset_id" value="<?php echo $asset_detail['id']; ?>"><button class="p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Desvincular"><i data-lucide="unlink" class="w-4 h-4"></i></button></form><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="content-components" class="tab-content hidden p-6">
                        <?php if(hasPermission('manage_peripherals')): ?>
                        <form method="POST" class="mb-6 bg-slate-50 p-4 rounded-lg border border-slate-200">
                            <input type="hidden" name="action" value="add_peripheral"><input type="hidden" name="asset_id" value="<?php echo $asset_detail['id']; ?>">
                            <h4 class="text-sm font-bold text-slate-700 mb-3 flex items-center gap-2"><i data-lucide="cpu" class="w-4 h-4"></i> Adicionar Componente</h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                                <input type="text" name="name" placeholder="Nome (ex: Memória RAM 8GB)" required class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <input type="text" name="serial_number" placeholder="Nº Série (Opcional)" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500">
                                <select name="status" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:border-blue-500"><option value="Instalado">Instalado</option><option value="Reserva">Reserva</option><option value="Defeito">Com Defeito</option></select>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition-colors">Adicionar</button>
                        </form>
                        <?php endif; ?>
                        <div class="overflow-x-auto border rounded-lg">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 text-slate-500 font-semibold border-b"><tr><th class="p-3">Nome</th><th class="p-3">Serial</th><th class="p-3">Status</th><th class="p-3 text-right">Ações</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if(empty($peripherals)): ?><tr><td colspan="4" class="p-4 text-center text-slate-400">Nenhum componente registrado.</td></tr><?php else: ?>
                                    <?php foreach($peripherals as $p): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-3 font-medium text-slate-700"><?php echo htmlspecialchars($p['name']); ?></td>
                                        <td class="p-3 font-mono text-slate-500"><?php echo htmlspecialchars($p['serial_number']); ?></td>
                                        <td class="p-3"><span class="px-2 py-1 rounded text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200"><?php echo htmlspecialchars($p['status']); ?></span></td>
                                        <td class="p-3 text-right">
                                            <?php if(hasPermission('manage_peripherals')): ?>
                                            <button type="button" onclick='openPeripheralHistory(<?php echo json_encode($peripherals_history[$p['id']] ?? []); ?>, "<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>")' class="text-slate-400 hover:text-slate-600 p-1 mr-1" title="Histórico"><i data-lucide="history" class="w-4 h-4"></i></button>
                                            <button type="button" onclick="openMovePeripheralModal(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>', <?php echo $asset_detail['id']; ?>)" class="text-blue-400 hover:text-blue-600 p-1 mr-1" title="Mover"><i data-lucide="arrow-right-left" class="w-4 h-4"></i></button>
                                            <form method="POST" onsubmit="return confirm('Remover este item?')" class="inline"><input type="hidden" name="action" value="delete_peripheral"><input type="hidden" name="asset_id" value="<?php echo $asset_detail['id']; ?>"><input type="hidden" name="peripheral_id" value="<?php echo $p['id']; ?>"><button class="text-red-400 hover:text-red-600 p-1"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form><?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="space-y-6">
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm sticky top-6">
                    <?php if($asset_detail['photo_url']): ?><img src="<?php echo htmlspecialchars($asset_detail['photo_url']); ?>" class="w-full aspect-square object-contain bg-slate-50 rounded mb-2"><p class="text-center text-xs text-slate-400">Principal</p><?php else: ?><div class="w-full aspect-square bg-slate-50 rounded flex items-center justify-center text-slate-300 border-2 border-dashed"><i data-lucide="image" class="w-16 h-16 opacity-50"></i></div><?php endif; ?>
                </div>

                <!-- NOVA SEÇÃO: LICENÇAS ASSOCIADAS -->
                <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <i data-lucide="key-round" class="w-4 h-4 text-slate-400"></i> Licenças de Software
                    </h4>
                    <div class="space-y-2">
                        <?php if(empty($assigned_licenses)): ?>
                            <p class="text-xs text-slate-400 text-center py-4">Nenhuma licença associada.</p>
                        <?php else: ?>
                            <?php foreach($assigned_licenses as $lic): ?>
                                <a href="index.php?page=licenses&id=<?php echo $lic['id']; ?>" class="block p-2 bg-slate-50 hover:bg-blue-50 rounded-lg border border-slate-100 hover:border-blue-100 transition-colors">
                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($lic['software_name']); ?></p>
                                    <p class="text-[10px] text-slate-500">Expira em: <?php echo $lic['expiration_date'] ? date('d/m/Y', strtotime($lic['expiration_date'])) : 'Perpétua'; ?></p>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
<?php endif; ?>

<div id="modalQr" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4"><div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalQr')"></div><div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6 text-center"><h3 class="text-lg font-bold mb-2" id="qrTitle">QR Code</h3><img id="qrImage" class="mx-auto mb-4 border p-2 rounded"><button onclick="closeModal('modalQr')" class="w-full bg-slate-100 p-2 rounded">Fechar</button></div></div>

<div id="modalMove" class="fixed inset-0 z-[80] hidden items-center justify-center p-4 sm:p-6 flex">    
    <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-md transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalMove')"></div>    
    <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl flex flex-col max-h-[85vh] overflow-hidden modal-panel transition-all transform scale-95 opacity-0">
        
        <form method="POST" id="formMove" class="flex flex-col h-full overflow-hidden">
            <input type="hidden" name="action" value="move_asset">
            <input type="hidden" name="id" id="moveAssetId">
            
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white shrink-0 z-20">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center shadow-lg shadow-blue-200">
                        <i data-lucide="arrow-right-left" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-slate-900 leading-tight">Movimentar Ativo</h3>
                        <p class="text-sm text-slate-500 font-medium">Transferência de posse ou local</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modalMove')" class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-full transition-all">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto min-h-0 bg-slate-50/50 p-6 sm:p-8 space-y-8 custom-scrollbar overscroll-contain">
                
                <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-blue-300 transition-colors">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span> Destino
                    </h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Novo Local *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="map-pin" class="w-5 h-5"></i></div>
                                <select name="location_id" id="moveLocationSelect" onchange="updateManagerInfoMove()" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all appearance-none font-medium text-slate-700 shadow-sm h-12" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach($locations as $l) echo "<option value='{$l['id']}'>{$l['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div class="relative group/input">
                            <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Novo Status</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-slate-400 group-focus-within/input:text-blue-600 transition-colors"><i data-lucide="activity" class="w-5 h-5"></i></div>
                                <select name="status" class="w-full pl-12 pr-10 py-3.5 border border-slate-300 rounded-xl text-sm bg-white focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all appearance-none font-medium text-slate-700 shadow-sm h-12">
                                    <option value="manutencao">Em Manutenção</option>
                                    <?php foreach($statuses as $st) echo "<option value='{$st['name']}'>{$st['name']}</option>"; ?>
                                </select>
                                <i data-lucide="chevron-down" class="absolute right-4 top-4 w-4 h-4 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <div id="managerInfoCardMove" class="hidden mt-6 bg-blue-50 border border-blue-200 p-4 rounded-2xl flex items-center justify-between animate-in fade-in slide-in-from-top-2">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-white rounded-full text-blue-600 shadow-sm flex items-center justify-center"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
                            <div>
                                <p class="text-xs text-blue-500 font-bold uppercase tracking-wider">Gestor do Setor</p>
                                <p class="text-sm font-bold text-blue-900" id="managerNameDisplayMove">-</p>
                                <input type="hidden" name="location_manager_name" id="managerNameInputMove">
                            </div>
                        </div>
                        <label class="flex items-center gap-3 cursor-pointer bg-white px-4 py-2.5 rounded-xl border border-blue-100 hover:border-blue-300 transition-all shadow-sm group/check">
                            <input type="checkbox" name="manager_confirmed_check" class="w-5 h-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500">
                            <span class="text-sm font-bold text-slate-600 group-hover/check:text-blue-700">Confirmar</span>
                        </label>
                    </div>
                </section>

                <section class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-orange-300 transition-colors">
                    <h4 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-orange-500"></span> Responsabilidade
                    </h4>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                        <div class="relative group/input">
                            <label class="block text-xs font-bold text-orange-600 mb-2 ml-1 uppercase">Quem Repassou?</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-orange-300 group-focus-within/input:text-orange-500 transition-colors"><i data-lucide="user-minus" class="w-5 h-5"></i></div>
                                <input type="text" name="giver_name" 
                                       class="w-full pl-12 pr-4 py-3.5 border border-slate-200 rounded-xl text-sm focus:ring-4 focus:ring-orange-50 focus:border-orange-400 outline-none transition-all placeholder:text-slate-300 font-medium h-12"
                                       placeholder="Ex: TI / Gestor Atual">
                            </div>
                        </div>

                        <div class="relative group/input">
                            <label class="block text-xs font-bold text-green-600 mb-2 ml-1 uppercase">Quem Recebeu? *</label>
                            <div class="relative">
                                <div class="absolute left-4 top-3.5 text-green-300 group-focus-within/input:text-green-500 transition-colors"><i data-lucide="user-plus" class="w-5 h-5"></i></div>
                                <input type="text" name="responsible_name" required
                                       class="w-full pl-12 pr-4 py-3.5 border border-slate-200 rounded-xl text-sm focus:ring-4 focus:ring-green-50 focus:border-green-400 outline-none transition-all placeholder:text-slate-300 font-medium h-12"
                                       placeholder="Ex: Novo Funcionário">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-6">
                    <div class="group/input">
                        <label class="block text-sm font-bold text-slate-700 mb-2 ml-1">Motivo / Observação</label>
                        <textarea name="description" rows="3" 
                                  class="w-full p-4 border border-slate-300 rounded-xl text-sm focus:ring-4 focus:ring-blue-100 focus:border-blue-500 outline-none transition-all shadow-sm resize-none placeholder:text-slate-400" 
                                  placeholder="Justifique a movimentação..."></textarea>
                    </div>

                    <div class="group/input">
                        <div class="flex justify-between items-end mb-2 ml-1">
                            <label class="block text-sm font-bold text-slate-700">Assinatura Digital</label>
                            <button type="button" id="clear-signature" class="text-[10px] uppercase font-bold text-red-500 hover:text-red-700 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors bg-white border border-red-100 shadow-sm">
                                Limpar
                            </button>
                        </div>
                        <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-white touch-none relative group-hover/input:border-blue-400 transition-colors overflow-hidden h-40">
                            <canvas id="signature-pad" class="w-full h-full cursor-crosshair block" style="touch-action: none;"></canvas>
                            
                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none opacity-10">
                                <span class="text-slate-400 text-2xl font-bold uppercase tracking-widest">Assine Aqui</span>
                            </div>
                        </div>
                        <input type="hidden" name="signature_data" id="signatureData">
                    </div>
                </section>
                
                <div class="h-2"></div>
            </div>

            <div class="px-8 py-5 bg-white border-t border-slate-100 flex justify-end gap-4 rounded-b-3xl shrink-0 z-20 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)]">
                <button type="button" onclick="closeModal('modalMove')" class="px-6 py-3.5 border border-slate-200 text-slate-600 rounded-xl text-sm font-bold hover:bg-slate-50 hover:text-slate-800 transition-all">
                    Cancelar
                </button>
                <button type="submit" class="px-8 py-3.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold shadow-lg shadow-blue-200 transition-all flex items-center gap-2 transform active:scale-[0.98]">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> Confirmar
                </button>
            </div>
        </form>
    </div>
</div>
</div>

<div id="modalSmartScan" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm transition-opacity" onclick="closeScanner()"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-6 text-center">
            <h3 class="text-lg font-bold text-slate-900 mb-2" id="scanTitle">Conectar Celular</h3>
            <div id="desktopConnectView" class="hidden">
                <p class="text-sm text-slate-500 mb-4">Escaneie este QR com seu celular.</p>
                <div class="bg-white p-2 border rounded-lg inline-block mb-4"><img id="sessionQrImage" class="w-48 h-48"></div>
                <div class="flex items-center justify-center gap-2 text-xs text-blue-600 animate-pulse"><i data-lucide="loader-2" class="w-3 h-3 animate-spin"></i> Aguardando leitura...</div>
            </div>
            <div id="mobileCameraView" class="hidden"><div id="localReader" class="w-full h-64 bg-black"></div></div>
        </div>
        <button onclick="closeScanner()" class="w-full bg-slate-100 p-3 text-sm font-medium text-slate-600 hover:bg-slate-200 border-t">Cancelar</button>
    </div>
</div>

<div id="modalImport" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalImport')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl"><h3 class="text-lg font-bold text-slate-900">Importar Ativos</h3><button onclick="closeModal('modalImport')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button></div>
        <form method="POST" enctype="multipart/form-data" class="p-6">
            <input type="hidden" name="action" value="import_assets">
            <div class="mb-4 flex justify-between items-center bg-blue-50 p-3 rounded-lg border border-blue-100">
                <div class="flex items-center gap-2"><div class="bg-blue-100 p-2 rounded-full text-blue-600"><i data-lucide="file-spreadsheet" class="w-4 h-4"></i></div><div class="text-sm"><p class="font-bold text-blue-800">Precisa do modelo?</p><p class="text-blue-600 text-xs">Baixe a planilha padrão para preencher.</p></div></div>
                <button type="button" onclick="downloadTemplate()" class="text-xs bg-white text-blue-600 border border-blue-200 px-3 py-1.5 rounded-md font-bold hover:bg-blue-50">Baixar CSV</button>
            </div>
            <div class="relative border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:bg-slate-50 hover:border-blue-400 transition-colors group cursor-pointer">
                <input type="file" name="csv_file" accept=".csv" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateFileName(this)">
                <div class="pointer-events-none"><i data-lucide="upload-cloud" class="w-10 h-10 text-slate-400 mx-auto mb-3 group-hover:text-blue-500 transition-colors"></i><p class="text-sm font-medium text-slate-700">Clique ou arraste o arquivo CSV</p><p class="text-xs text-slate-400 mt-1">Máximo 5MB</p></div>
            </div>
            <p id="fileNameDisplay" class="text-sm text-green-600 font-medium mt-2 text-center hidden"></p>
            <div class="mt-6 flex justify-end gap-2"><button type="button" onclick="closeModal('modalImport')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 shadow-sm flex items-center gap-2"><i data-lucide="check" class="w-4 h-4"></i> Processar Importação</button></div>
        </form>
    </div>
</div>

<div id="modalDelete" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalDelete')"></div>
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6 text-center">
        <div class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4"><i data-lucide="alert-triangle" class="w-6 h-6"></i></div>
        <h3 class="text-lg font-bold text-slate-900 mb-2" id="deleteModalTitle">Tem certeza?</h3>
        <p class="text-sm text-slate-500 mb-6" id="deleteModalDesc">Esta ação removerá o registro permanentemente.</p>
        <form method="POST" id="formDeleteGeneral">
            <input type="hidden" name="action" id="deleteAction" value="delete_asset"><input type="hidden" name="id" id="deleteId"><input type="hidden" name="ids" id="deleteIds">
            <div class="flex gap-3 justify-center"><button type="button" onclick="closeModal('modalDelete')" class="px-4 py-2 border border-slate-300 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 shadow-sm">Sim, Excluir</button></div>
        </form>
    </div>
</div>

<div id="modalMovePeripheral" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalMovePeripheral')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6">
        <h3 class="text-lg font-bold text-slate-900 mb-1">Mover Periférico</h3>
        <p class="text-sm text-slate-500 mb-4">Item: <span id="movePeripheralName" class="font-semibold text-slate-700"></span></p>
        <form method="POST">
            <input type="hidden" name="action" value="move_peripheral">
            <input type="hidden" name="current_asset_id" id="movePeripheralCurrentAssetId">
            <input type="hidden" name="peripheral_id" id="movePeripheralId">
            <div class="mb-4">
                <label class="block text-sm font-bold text-slate-700 mb-2">Mover para o Ativo:</label>
                <select name="new_asset_id" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none" required>
                    <option value="">Selecione o destino...</option>
                    <?php foreach($all_assets as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?> (<?php echo $a['code']; ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end gap-2"><button type="button" onclick="closeModal('modalMovePeripheral')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Confirmar</button></div>
        </form>
    </div>
</div>

<div id="modalPeripheralHistory" class="fixed inset-0 z-[95] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalPeripheralHistory')"></div>
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all flex flex-col max-h-[80vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl"><h3 class="text-lg font-bold text-slate-900">Histórico do Componente</h3><button onclick="closeModal('modalPeripheralHistory')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button></div>
        <div class="p-6 overflow-y-auto">
            <p class="text-sm font-bold text-slate-700 mb-4" id="histPeripheralName"></p>
            <div class="space-y-4 border-l-2 border-slate-200 ml-2 pl-4" id="histPeripheralContent"></div>
        </div>
    </div>
</div>

<div id="modalPrintConfig" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalPrintConfig')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-xl"><h3 class="text-lg font-bold text-slate-900">Configurar Etiquetas</h3><button type="button" onclick="closeModal('modalPrintConfig')" class="text-slate-400 hover:text-slate-600"><i data-lucide="x" class="w-5 h-5"></i></button></div>
        <div class="p-6 space-y-6">
            <div><label class="block text-sm font-bold text-slate-700 mb-2">Tamanho</label><div class="grid grid-cols-2 gap-3"><label class="border p-3 rounded-lg cursor-pointer hover:bg-blue-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="labelSize" value="pimaco" class="hidden" checked><div class="font-bold text-sm">Pimaco / A4</div><div class="text-xs text-slate-500">Folha com várias</div></label><label class="border p-3 rounded-lg cursor-pointer hover:bg-blue-50 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50"><input type="radio" name="labelSize" value="thermal" class="hidden"><div class="font-bold text-sm">Térmica</div><div class="text-xs text-slate-500">50x25mm</div></label></div></div>
            <div><label class="block text-sm font-bold text-slate-700 mb-2">Opções</label><div class="space-y-2"><label class="flex items-center gap-2"><input type="checkbox" id="printShowCompany" checked class="rounded text-blue-600"><span class="text-sm">Empresa</span></label><label class="flex items-center gap-2"><input type="checkbox" id="printShowName" checked class="rounded text-blue-600"><span class="text-sm">Nome</span></label><label class="flex items-center gap-2"><input type="checkbox" id="printShowCode" checked class="rounded text-blue-600"><span class="text-sm">Código</span></label></div></div>
            <div class="bg-yellow-50 p-3 rounded-lg text-xs text-yellow-700 flex gap-2"><i data-lucide="info" class="w-4 h-4"></i><span id="printCountInfo">...</span></div>
        </div>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3 rounded-b-xl"><button type="button" onclick="closeModal('modalPrintConfig')" class="px-4 py-2 border rounded-lg text-sm bg-white">Cancelar</button><button type="button" onclick="generateLabels()" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium flex items-center gap-2"><i data-lucide="printer" class="w-4 h-4"></i> Gerar</button></div>
    </div>
</div>

<div id="modalTransfer" class="fixed inset-0 z-[90] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalTransfer')"></div>
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl modal-panel transform scale-95 opacity-0 transition-all p-6">
        <h3 class="text-lg font-bold text-slate-900 mb-2">Transferir para Empresa</h3>
        <p class="text-sm text-slate-500 mb-4">Selecione a empresa de destino. A categoria e o status serão mantidos (a categoria será criada na nova empresa se não existir).</p>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_transfer_assets">
            <input type="hidden" name="ids" id="transferIds">
            <div class="mb-4">
                <label class="block text-sm font-bold text-slate-700 mb-2">Empresa de Destino</label>
                <select name="target_company_id" id="transferCompanySelect" onchange="updateTransferLocations()" required class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">Selecione...</option>
                    <?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold text-slate-700 mb-2">Setor de Destino</label>
                <select name="target_location_id" id="transferLocationSelect" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">Selecione a empresa primeiro...</option>
                </select>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold text-slate-700 mb-2">Novo Status (Opcional)</label>
                <select name="target_status" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">Manter atual</option>
                    <?php foreach($statuses as $s): ?><option value="<?php echo htmlspecialchars($s['name']); ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end gap-2"><button type="button" onclick="closeModal('modalTransfer')" class="px-4 py-2 border rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50">Cancelar</button><button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700">Confirmar Transferência</button></div>
        </form>
    </div>
</div>

<div id="modalQuickView" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('modalQuickView')"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl modal-panel transform scale-95 opacity-0 transition-all overflow-hidden flex flex-col md:flex-row max-h-[90vh]">
        <div class="w-full md:w-1/2 bg-slate-100 relative flex items-center justify-center min-h-[250px]">
             <img id="qvImage" class="w-full h-full object-cover absolute inset-0 hidden">
             <div id="qvNoImage" class="flex flex-col items-center justify-center text-slate-400">
                 <i data-lucide="image" class="w-16 h-16 mb-2 opacity-50"></i>
                 <span class="text-sm">Sem imagem</span>
             </div>
             <div class="absolute top-3 left-3">
                 <span id="qvStatus" class="px-2 py-1 rounded text-xs font-bold uppercase bg-white/90 backdrop-blur border shadow-sm"></span>
             </div>
        </div>
        <div class="w-full md:w-1/2 p-6 flex flex-col">
            <div class="flex justify-between items-start mb-4">
                <div><h3 id="qvName" class="text-xl font-bold text-slate-800 leading-tight"></h3><p id="qvCode" class="text-sm font-mono text-slate-500 mt-1"></p></div>
                <button onclick="closeModal('modalQuickView')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 transition-colors"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="space-y-4 flex-1 overflow-y-auto pr-2 custom-scrollbar">
                <div class="grid grid-cols-2 gap-4">
                    <div><p class="text-xs text-slate-400 uppercase font-bold">Local</p><p id="qvLocation" class="text-sm font-medium text-slate-700 truncate"></p></div>
                    <div><p class="text-xs text-slate-400 uppercase font-bold">Responsável</p><p id="qvResponsible" class="text-sm font-medium text-slate-700 truncate"></p></div>
                    <div><p class="text-xs text-slate-400 uppercase font-bold">Categoria</p><p id="qvCategory" class="text-sm font-medium text-slate-700 truncate"></p></div>
                    <div><p class="text-xs text-slate-400 uppercase font-bold">Valor</p><p id="qvValue" class="text-sm font-medium text-slate-700"></p></div>
                </div>
                <div class="bg-slate-50 p-3 rounded-lg border border-slate-100"><p class="text-xs text-slate-400 uppercase font-bold mb-1">Descrição</p><p id="qvDescription" class="text-sm text-slate-600 line-clamp-3 italic"></p></div>
            </div>
            <div class="mt-6 pt-4 border-t border-slate-100">
                <a id="qvLink" href="#" class="flex items-center justify-center w-full px-4 py-3 bg-blue-600 text-white rounded-xl text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm shadow-blue-200">Ver Detalhes Completos</a>
            </div>
        </div>
    </div>
</div>

<script>
    const allAssetsData = <?php echo $all_assets_json; ?>; // Mantém para clonar, etc.
    // Centraliza os dados para os filtros e modais
    var assetsLocationsData = <?php echo $locationsJson; ?>;
    var assetsCompaniesData = <?php echo $companiesJson; ?>;
    var assetsCategoriesData = <?php echo $categoriesJson; ?>;
    var assetsStatusesData = <?php echo $statusesJson; ?>;

    // --- TAB SWITCHER ---
    function switchTab(tabName) { document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden')); document.querySelectorAll('.tab-btn').forEach(el => { el.classList.remove('active', 'border-blue-600', 'text-blue-600'); el.classList.add('text-slate-500'); }); const content = document.getElementById('content-' + tabName); if(content) content.classList.remove('hidden'); const btn = document.getElementById('tab-' + tabName); if(btn) { btn.classList.add('active', 'border-blue-600', 'text-blue-600'); btn.classList.remove('text-slate-500'); } }
    document.addEventListener("DOMContentLoaded", function() { const urlParams = new URLSearchParams(window.location.search); if(urlParams.has('tab')) switchTab(urlParams.get('tab')); });

    // --- VIEW / TABLE / PAGINATION ---
    // Adiciona a nova aba ao switcher
    document.addEventListener("DOMContentLoaded", function() {
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.has('tab')) switchTab(urlParams.get('tab'));
    });
    function switchView(mode) {
        const vList = document.getElementById('viewList'); const vGrid = document.getElementById('viewGrid');
        if (!vList || !vGrid) return;
        vList.classList.toggle('hidden', mode !== 'list'); vGrid.classList.toggle('hidden', mode !== 'grid');
        const btnList = document.getElementById('btnList'); const btnGrid = document.getElementById('btnGrid');
        if(btnList) btnList.className = (mode === 'list') ? "p-1.5 rounded transition-all bg-white text-blue-600 shadow-sm" : "p-1.5 rounded transition-all text-slate-500 hover:text-slate-700";
        if(btnGrid) btnGrid.className = (mode === 'grid') ? "p-1.5 rounded transition-all bg-white text-blue-600 shadow-sm" : "p-1.5 rounded transition-all text-slate-500 hover:text-slate-700";
        localStorage.setItem('assetsViewMode', mode);
    }
    if(localStorage.getItem('assetsViewMode') === 'grid') switchView('grid'); else switchView('list');

    // --- LÓGICA DE PAGINAÇÃO UNIFICADA (LISTA + GRADE) ---
    let currentPage = 1; 
    let itemsPerPage = 25; 
    let filteredItems = [];
    let currentSortColumn = '';
    let currentSortDirection = 'asc';

    // Inicializa ao carregar a página
    if(document.getElementById('paginationToolbar')) { 
        document.addEventListener('DOMContentLoaded', () => { 
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('search')) {
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.value = urlParams.get('search');
                }
            }
            filterContent(); 
        }); 
    }

    function applySort(columnKey) {
        if (currentSortColumn === columnKey) {
            currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortColumn = columnKey;
            currentSortDirection = 'asc';
        }
        
        // Update UI indicators
        document.querySelectorAll('.sort-indicator').forEach(icon => {
            const iconColumn = icon.getAttribute('data-column');
            if (iconColumn === columnKey) {
                icon.outerHTML = `<i data-lucide="${currentSortDirection === 'asc' ? 'arrow-up' : 'arrow-down'}" class="w-3 h-3 text-blue-600 sort-indicator" data-column="${columnKey}"></i>`;
            } else {
                icon.outerHTML = `<i data-lucide="arrow-up-down" class="w-3 h-3 text-slate-400 sort-indicator" data-column="${iconColumn}"></i>`;
            }
        });
        lucide.createIcons(); // Re-render icons

        filterContent();
    }

    function toggleFilters() {
        const panel = document.getElementById('filterPanel');
        const chevron = document.getElementById('filterChevron');
        const btn = document.getElementById('btnToggleFilters');
        
        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            chevron.classList.add('rotate-180');
            btn.classList.add('bg-slate-50', 'border-slate-300');
        } else {
            panel.classList.add('hidden');
            chevron.classList.remove('rotate-180');
            btn.classList.remove('bg-slate-50', 'border-slate-300');
        }
    }

    function changeItemsPerPage() { 
        itemsPerPage = parseInt(document.getElementById('itemsPerPage').value); 
        currentPage = 1; 
        renderPagination(); 
    }

    function changePage(direction) { 
        const totalItems = filteredItems.length; 
        const limit = itemsPerPage === -1 ? totalItems : itemsPerPage; 
        const totalPages = Math.ceil(totalItems / limit) || 1;
        const newPage = currentPage + direction; 
        
        if (newPage >= 1 && newPage <= totalPages) { 
            currentPage = newPage; 
            renderPagination(); 
        } 
    }

    function filterContent() {
        const termInput = document.getElementById('searchInput'); 
        if(!termInput) return;
        
        const term = termInput.value.toLowerCase(); 
        const companyFilter = document.getElementById('filterCompany').value.toLowerCase();
        const locationFilter = document.getElementById('filterLocation').value.toLowerCase();
        const catFilter = document.getElementById('filterCategory').value.toLowerCase(); 
        const statusFilter = document.getElementById('filterStatus').value.toLowerCase();
        const favButton = document.getElementById('filterFavorites');
        const favOnly = favButton ? favButton.classList.contains('bg-yellow-100') : false;
        
        // Update Badge
        let activeCount = 0;
        if(companyFilter) activeCount++;
        if(locationFilter) activeCount++;
        if(catFilter) activeCount++;
        if(statusFilter) activeCount++;
        const badge = document.getElementById('activeFiltersCount');
        if(badge) { badge.innerText = activeCount; badge.classList.toggle('hidden', activeCount === 0); }

        // Coleta todos os elementos (Linhas da Tabela e Cards do Grid)
        // Assume-se que a ordem de impressão no PHP é a mesma, então row[0] corresponde a card[0]
        const rows = Array.from(document.querySelectorAll('.asset-row')); 
        const cards = Array.from(document.querySelectorAll('.asset-card'));
        let visibleCount = 0;
        
        filteredItems = [];
        
        rows.forEach((row, index) => {
            const card = cards[index]; // Pega o card correspondente (pode não existir se a view for carregada via AJAX, mas aqui é PHP direto)
            const searchText = row.getAttribute('data-search'); 
            const isFavorite = row.getAttribute('data-favorite') === '1';
            
            const matchesSearch = searchText.includes(term); 
            const matchesCompany = companyFilter === "" || searchText.includes(companyFilter);
            const matchesLocation = locationFilter === "" || searchText.includes(locationFilter);
            const matchesCat = catFilter === "" || searchText.includes(catFilter); 
            const matchesStatus = statusFilter === "" || searchText.includes(statusFilter);
            const matchesFav = !favOnly || isFavorite;
            
            if (matchesSearch && matchesCompany && matchesLocation && matchesCat && matchesStatus && matchesFav) { 
                // Guarda a referência de ambos para paginar depois
                filteredItems.push({ row: row, card: card }); 
                visibleCount++;
            } else { 
                // Esconde imediatamente o que não passa no filtro
                row.style.display = 'none'; 
                if(card) card.style.display = 'none'; 
            }
        });

        // --- APLICA ORDENAÇÃO ---
        if (currentSortColumn) {
            filteredItems.sort((a, b) => {
                const assetA = allAssetsData.find(asset => asset.id == a.row.dataset.assetId);
                const assetB = allAssetsData.find(asset => asset.id == b.row.dataset.assetId);

                if (!assetA || !assetB) return 0;

                let valA = assetA[currentSortColumn] || '';
                let valB = assetB[currentSortColumn] || '';
                
                const isNumericColumn = ['value', 'linked_assets_count'].includes(currentSortColumn);
                
                let comparison = 0;
                if (isNumericColumn) {
                    comparison = parseFloat(valA) - parseFloat(valB);
                } else {
                    comparison = String(valA).localeCompare(String(valB), 'pt-BR', { sensitivity: 'base' });
                }

                return currentSortDirection === 'asc' ? comparison : -comparison;
            });
        }

        const emptyState = document.getElementById('noResultsState');
        if(emptyState) {
            if(visibleCount === 0) emptyState.classList.remove('hidden');
            else emptyState.classList.add('hidden');
        }
        
        currentPage = 1; 
        renderPagination();
    }

    function renderPagination() {
        const totalItems = filteredItems.length; 
        const limit = itemsPerPage === -1 ? totalItems : itemsPerPage; 
        const totalPages = Math.ceil(totalItems / limit) || 1;
        
        if (currentPage > totalPages) currentPage = totalPages;
        
        const startIdx = (currentPage - 1) * limit; 
        const endIdx = startIdx + limit;
        
        // Loop apenas nos itens filtrados para mostrar/esconder baseado na página
        filteredItems.forEach((item, index) => { 
            if (index >= startIdx && index < endIdx) { 
                item.row.style.display = ''; // Mostra linha (default display do table-row)
                if(item.card) item.card.style.display = ''; // Mostra card (default block)
            } else { 
                item.row.style.display = 'none'; 
                if(item.card) item.card.style.display = 'none'; 
            } 
        });
        
        // Atualiza textos da toolbar
        const elStart = document.getElementById('pageInfoStart');
        if(elStart) { 
            elStart.innerText = totalItems === 0 ? 0 : startIdx + 1; 
            document.getElementById('pageInfoEnd').innerText = Math.min(endIdx, totalItems); 
            document.getElementById('pageInfoTotal').innerText = totalItems; 
            document.getElementById('pageIndicator').innerText = currentPage; 
            document.getElementById('btnPrevPage').disabled = currentPage === 1; 
            document.getElementById('btnNextPage').disabled = currentPage === totalPages; 
            
            updateSelection(); 
        }
    }

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterCompany').value = '';
        document.getElementById('filterLocation').value = '';
        document.getElementById('filterCategory').value = '';
        document.getElementById('filterStatus').value = '';
        updateCompanyFilters(); // Reseta as opções
    }

    function updateCompanyFilters() {
        const companyName = document.getElementById('filterCompany').value;
        const locSelect = document.getElementById('filterLocation');
        const catSelect = document.getElementById('filterCategory');
        const statusSelect = document.getElementById('filterStatus');
        
        // Reseta os selects
        locSelect.innerHTML = '<option value="">Todos</option>';
        catSelect.innerHTML = '<option value="">Todas</option>';
        statusSelect.innerHTML = '<option value="">Todos</option>';
        
        let filteredLocs = assetsLocationsData;
        let filteredCats = assetsCategoriesData;
        let filteredStatuses = assetsStatusesData;
        
        if (companyName) {
            const company = assetsCompaniesData.find(c => c.name === companyName);
            if (company) {
                filteredLocs = assetsLocationsData.filter(l => l.company_id == company.id);
                filteredCats = assetsCategoriesData.filter(c => c.company_id == company.id);
                // Verifica se status tem company_id (caso sua tabela de status seja global, isso evita erro)
                if (assetsStatusesData.length > 0 && assetsStatusesData[0].hasOwnProperty('company_id')) {
                    filteredStatuses = assetsStatusesData.filter(s => s.company_id == company.id);
                }
            }
        }
        
        const populate = (items, select) => {
            items.forEach(item => {
            const opt = document.createElement('option');
                opt.value = item.name;
                opt.innerText = item.name;
                select.appendChild(opt);
            });
        };

        populate(filteredLocs, locSelect);
        populate(filteredCats, catSelect);
        populate(filteredStatuses, statusSelect);
        
        filterContent();
    }

    function toggleFavoriteFilter(button) {
        button.classList.toggle('bg-yellow-100');
        button.classList.toggle('text-yellow-700');
        button.classList.toggle('bg-white');
        button.classList.toggle('text-slate-600');
        button.classList.toggle('border-yellow-200');
        const icon = button.querySelector('i') || button.querySelector('svg');
        if (icon) {
            icon.classList.toggle('text-yellow-500');
            icon.classList.toggle('text-slate-400');
        }
        filterContent();
    }

    async function toggleFavorite(button, assetId) {
        const iconEl = button.querySelector('i') || button.querySelector('svg');
        const isCurrentlyFavorite = iconEl ? iconEl.classList.contains('fill-current') : false;
        const newFavoriteStatus = !isCurrentlyFavorite;

        // Find all elements for this asset (card and row) and define a function to update them
        const assetElements = document.querySelectorAll(`[data-asset-id='${assetId}']`);
        const updateUI = (isFavorite) => {
            assetElements.forEach(el => {
                el.dataset.favorite = isFavorite ? '1' : '0';
                const favButton = el.querySelector('.favorite-btn');
                if (favButton) {
                    const icon = favButton.querySelector('i') || favButton.querySelector('svg');
                    if (icon) {
                        icon.classList.toggle('fill-current', isFavorite);
                        icon.classList.toggle('text-yellow-400', isFavorite);
                    }
                }
            });
        };

        // Optimistic UI update
        updateUI(newFavoriteStatus);

        try {
            const formData = new FormData();
            formData.append('action', 'toggle_favorite');
            formData.append('asset_id', assetId);
            const response = await fetch('index.php?page=assets', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Failed to update favorite status.');
            }
        } catch (error) {
            console.error('Error toggling favorite:', error);
            updateUI(isCurrentlyFavorite); // Revert on error
            alert('Erro ao atualizar favorito.');
        }
    }

    // --- OTHER FUNCS ---
    function updateFileName(input) { const display = document.getElementById('fileNameDisplay'); if (input.files && input.files.length > 0) { display.innerText = "Arquivo selecionado: " + input.files[0].name; display.classList.remove('hidden'); } else { display.classList.add('hidden'); } }
    function downloadTemplate() { 
       const headers = [
           "Código", "Nome", "Responsável", "Categoria", "Local", "Status", "Valor", 
           "Marca", "Modelo", "Nº Série", "Data Aquisição", "Data Garantia", 
           "Centro de Custo", "Vida Útil (anos)", "Frequência Manut. (dias)", "Próxima Manut.", "Descrição"
       ]; 
       const example = [
           "NOTE-001", "Notebook Dell Latitude", "João da Silva", "Informática", "TI", "Ativo", "3.500,00", 
           "Dell", "Latitude 3520", "BR-123XYZ", "15/01/2024", "15/01/2025", 
           "TI-DEV", "5", "180", "15/07/2024", "Notebook para desenvolvimento"
       ]; 
       let csvContent = "data:text/csv;charset=utf-8," + headers.join(",") + "\r\n" + example.join(",") + "\r\n"; 
       const encodedUri = encodeURI(csvContent); 
       const link = document.createElement("a"); link.setAttribute("href", encodedUri); link.setAttribute("download", "modelo_importacao_ativos.csv"); document.body.appendChild(link); link.click(); document.body.removeChild(link); 
    }
    function generateCode() { document.getElementById('assetCode').value = `PAT-${new Date().getFullYear()}-${Math.floor(1000 + Math.random() * 9000)}`; }
    function showQrCode(code, name) { document.getElementById('qrTitle').innerText = name; document.getElementById('qrImage').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(`${qrBaseUrl}/public_ticket.php?code=${code}`)}`; openModal('modalQr'); }    
    function toggleSelectAll(source) {
        // Itera sobre todos os itens que passaram no filtro, não apenas os visíveis na página atual.
        filteredItems.forEach(item => {
            const checkbox = item.row.querySelector('.asset-checkbox');
            if (checkbox) checkbox.checked = source.checked;
        });
        updateSelection();
    }
    function updateSelection() {
        const ids = getSelectedUniqueIds();
        const count = ids.length;
        const countEl = document.getElementById('selectedCount');
        if (!countEl) return;

        countEl.innerText = count;
        document.getElementById('selectionToolbar').classList.toggle('hidden', count === 0);
        document.getElementById('selectionToolbar').classList.toggle('flex', count > 0);

        // Mostra/esconde botões de ação em massa
        document.getElementById('bulkEditBtn').classList.toggle('hidden', count < 2);
        document.getElementById('bulkEditBtn').classList.toggle('flex', count >= 2);
        document.getElementById('bulkDeleteBtn').classList.toggle('hidden', count < 2);
        document.getElementById('bulkDeleteBtn').classList.toggle('flex', count >= 2);
        document.getElementById('transferCompanyBtn').classList.toggle('hidden', count === 0);
    }
    function clearSelection() { document.querySelectorAll('.asset-checkbox:checked').forEach(cb => cb.checked = false); updateSelection(); }
    function getSelectedUniqueIds() { const allChecked = document.querySelectorAll('.asset-checkbox:checked'); const uniqueSet = new Set(Array.from(allChecked).map(cb => cb.value)); return Array.from(uniqueSet); }
    function confirmDelete(id) { document.getElementById('deleteAction').value = 'delete_asset'; document.getElementById('deleteId').value = id; document.getElementById('deleteIds').value = ''; document.getElementById('deleteModalTitle').innerText = 'Excluir Ativo?'; document.getElementById('deleteModalDesc').innerText = 'Esta ação removerá o registro permanentemente.'; openModal('modalDelete'); }
    function confirmBulkDelete() { const ids = getSelectedUniqueIds(); if(ids.length === 0) return; document.getElementById('deleteAction').value = 'bulk_delete_assets'; document.getElementById('deleteIds').value = ids.join(','); document.getElementById('deleteId').value = ''; document.getElementById('deleteModalTitle').innerText = `Excluir ${ids.length} itens?`; document.getElementById('deleteModalDesc').innerText = 'Você selecionou múltiplos ativos. Essa ação é irreversível.'; openModal('modalDelete'); }
    function openBulkEditModal() {
        const ids = getSelectedUniqueIds();
        if (ids.length === 0) return alert('Selecione pelo menos um ativo para editar.');
        document.getElementById('bulkEditIds').value = ids.join(',');
        document.getElementById('bulkEditCount').innerText = ids.length;
        openModal('modalBulkEdit');
        
        // Reseta todos os campos e checkboxes
        document.querySelectorAll('#modalBulkEdit input[type=checkbox]').forEach(cb => {
            cb.checked = false;
            const fieldId = 'bulk_' + cb.name.replace('update_', '');
            const field = document.getElementById(fieldId);
            if (field) { field.disabled = true; field.value = ''; }
        });
        // Limpa os selects dependentes para forçar a seleção da empresa
        const bulkLoc = document.getElementById('bulk_location_id'); if(bulkLoc) bulkLoc.innerHTML = '<option value="">Selecione a empresa</option>';
        const bulkCat = document.getElementById('bulk_category_id'); if(bulkCat) bulkCat.innerHTML = '<option value="">Selecione a empresa</option>';
        const bulkStatus = document.getElementById('bulk_status'); if(bulkStatus) bulkStatus.innerHTML = '';
    }
    function toggleBulkField(checkbox, fieldId) {
        const field = document.getElementById(fieldId);
        field.disabled = !checkbox.checked;
        if (checkbox.checked) field.focus();
    }
    function openPrintConfig() { const ids = getSelectedUniqueIds(); if(ids.length === 0) return alert('Selecione pelo menos um ativo.');
    
        document.getElementById('printCountInfo').innerText = `Serão geradas ${ids.length} etiquetas.`;
        openModal('modalPrintConfig'); }
    function openTransferModal() { 
        const ids = getSelectedUniqueIds(); 
        if(ids.length === 0) return alert('Selecione pelo menos um ativo.'); 
        document.getElementById('transferIds').value = ids.join(','); 
        document.getElementById('transferCompanySelect').value = "";
        updateTransferLocations();
        openModal('modalTransfer'); 
    }
    function updateTransferLocations() {
        const companyId = document.getElementById('transferCompanySelect').value;
        const locSelect = document.getElementById('transferLocationSelect');
        locSelect.innerHTML = '<option value="">Selecione...</option>';
        if(!companyId) { locSelect.innerHTML = '<option value="">Selecione a empresa primeiro...</option>'; return; }
        const filtered = assetsLocationsData.filter(l => l.company_id == companyId);
        filtered.forEach(l => { const opt = document.createElement('option'); opt.value = l.id; opt.innerText = l.name; locSelect.appendChild(opt); });
    }
    function generateLabels() { const ids = getSelectedUniqueIds(); const assetsToPrint = allAssetsData.filter(a => ids.includes(String(a.id))); const sizeMode = document.querySelector('input[name="labelSize"]:checked').value; const showCompany = document.getElementById('printShowCompany').checked; const showName = document.getElementById('printShowName').checked; const showCode = document.getElementById('printShowCode').checked; let cssSize = (sizeMode === 'thermal') ? `@page { size: 50mm 30mm; margin: 0; } body { margin: 0; } .print-container { display: block; } .label { width: 46mm; height: 26mm; border: none; page-break-after: always; margin: 2mm; }` : `@page { size: A4; margin: 10mm; } .print-container { display: grid; grid-template-columns: repeat(auto-fill, 5.2cm); gap: 8px; row-gap: 12px; } .label { width: 5.0cm; height: 2.5cm; }`; const style = `<style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');body { font-family: 'Inter', sans-serif; background: #fff; }.label { background: white; border: 1px dashed #ddd; border-radius: 3px; display: flex; padding: 3px 6px; box-sizing: border-box; align-items: center; gap: 6px; position: relative; overflow: hidden; }.label::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #2563eb; }.qr-area img { display: block; width: 55px; height: 55px; mix-blend-mode: multiply; }.info-area { flex: 1; display: flex; flex-direction: column; justify-content: center; overflow: hidden; min-width: 0; }.company-name { font-size: 6px; text-transform: uppercase; color: #64748b; font-weight: 700; white-space: nowrap; ${!showCompany ? 'display:none;' : ''} }.asset-code { font-size: 11px; font-weight: 800; color: #0f172a; line-height: 1.1; margin-top: 1px; ${!showCode ? 'display:none;' : ''} }.asset-name { font-size: 8px; color: #334155; line-height: 1.1; max-height: 2.2em; overflow: hidden; margin-top: 1px; ${!showName ? 'display:none;' : ''} }@media print { .label { border: 1px solid #eee; } } ${cssSize}</style>`; let html = `<html><head><title>Etiquetas</title>${style}</head><body><div class="print-container">`; assetsToPrint.forEach(a => { const url = `${qrBaseUrl}/public_ticket.php?code=${a.code}`; const qrSrc = `https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=0&data=${encodeURIComponent(url)}`; html += `<div class="label"><div class="qr-area"><img src="${qrSrc}"></div><div class="info-area"><div class="company-name">Patrimônio 360º</div><div class="asset-code">${a.code}</div><div class="asset-name">${a.name}</div></div></div>`; }); html += `</div><script>window.onload = function() { window.print(); }<\/script></body></html>`; const win = window.open('', '_blank'); win.document.write(html); win.document.close(); closeModal('modalPrintConfig'); }

    // --- MODAL DE MOVIMENTAÇÃO ---
    function openMoveModal(asset) {
        document.getElementById('formMove').reset();
        document.getElementById('moveAssetId').value = asset.id;
        document.getElementById('managerInfoCardMove').classList.add('hidden');
        if(signaturePad) signaturePad.clear();
        openModal('modalMove');
        resizeSignatureCanvas(); // Garante que o canvas da assinatura seja redimensionado corretamente
    }
    function updateManagerInfoMove() {
        const locationId = document.getElementById('moveLocationSelect').value;
        const managerCard = document.getElementById('managerInfoCardMove');
        const managerNameDisplay = document.getElementById('managerNameDisplayMove');
        const managerNameInput = document.getElementById('managerNameInputMove');
        const location = assetsLocationsData.find(l => l.id == locationId);
        if (location && location.manager_name) {
            managerNameDisplay.textContent = location.manager_name;
            managerNameInput.value = location.manager_name;
            managerCard.classList.remove('hidden');
        } else {
            managerCard.classList.add('hidden');
            managerNameInput.value = '';
        }
    }

    function openMovePeripheralModal(pId, pName, currentAssetId) {
        document.getElementById('movePeripheralId').value = pId;
        document.getElementById('movePeripheralCurrentAssetId').value = currentAssetId;
        document.getElementById('movePeripheralName').innerText = pName;
        openModal('modalMovePeripheral');
    }

    function openPeripheralHistory(history, name) {
        document.getElementById('histPeripheralName').innerText = name;
        const container = document.getElementById('histPeripheralContent');
        container.innerHTML = '';
        
        if (!history || history.length === 0) {
            container.innerHTML = '<p class="text-sm text-slate-400 italic">Nenhum histórico registrado.</p>';
        } else {
            history.forEach(h => {
                const date = new Date(h.created_at).toLocaleDateString('pt-BR') + ' ' + new Date(h.created_at).toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
                let text = '';
                if (!h.from_asset_id && h.to_asset_id) text = `Instalado em <b>${h.to_asset_name || 'Ativo #'+h.to_asset_id}</b>`;
                else if (h.from_asset_id && h.to_asset_id) text = `Movido de <b>${h.from_asset_name || '#'+h.from_asset_id}</b> para <b>${h.to_asset_name || '#'+h.to_asset_id}</b>`;
                else if (h.from_asset_id && !h.to_asset_id) text = `Removido de <b>${h.from_asset_name || '#'+h.from_asset_id}</b>`;
                else text = 'Movimentação registrada';

                container.innerHTML += `<div class="relative"><div class="absolute -left-[21px] top-1.5 w-2.5 h-2.5 bg-slate-300 rounded-full border-2 border-white"></div><p class="text-xs text-slate-400">${date}</p><p class="text-sm text-slate-700">${text}</p><p class="text-xs text-slate-400 mt-0.5">Por: ${h.user_name || 'Sistema'}</p></div>`;
            });
        }
        openModal('modalPeripheralHistory');
    }

    // --- SIGNATURE PAD ---
    var signaturePad; document.addEventListener("DOMContentLoaded", function() { var canvas = document.getElementById('signature-pad'); if(canvas) { signaturePad = new SignaturePad(canvas); function resizeCanvas() { var ratio = Math.max(window.devicePixelRatio || 1, 1); canvas.width = canvas.offsetWidth * ratio; canvas.height = canvas.offsetHeight * ratio; canvas.getContext("2d").scale(ratio, ratio); } window.addEventListener("resize", resizeCanvas); resizeCanvas(); const clearBtn = document.getElementById('clear-signature'); if(clearBtn) clearBtn.addEventListener('click', function () { signaturePad.clear(); }); const moveForm = document.getElementById('formMove'); if(moveForm) { moveForm.addEventListener('submit', function(e) { if (!signaturePad.isEmpty()) { document.getElementById('signatureData').value = signaturePad.toDataURL(); } }); } } });
    // Expõe a função de redimensionamento globalmente
    function resizeSignatureCanvas() {
        var canvas = document.getElementById('signature-pad');
        if(canvas && signaturePad) {
            var ratio =  Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext("2d").scale(ratio, ratio);
            signaturePad.clear(); // Limpa a assinatura após redimensionar
        }
    }

    // --- EXPORTAÇÃO ---
    function toggleExportMenu() {
        const menu = document.getElementById('exportMenu');
        if (menu) menu.classList.toggle('hidden');
    }

    function exportAssets(type) {
        // Prepara os dados
        const data = allAssetsData.map(a => ({
            'Código': a.code,
            'Nome': a.name,
            'Responsável': a.responsible_name || '',
            'Categoria': a.category_name || '',
            'Local': a.location_name || '',
            'Status': a.status,
            'Valor': parseFloat(a.value || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'}),
            'Marca': a.brand || '',
            'Modelo': a.model || '',
            'Nº Série': a.serial_number || '',
            'Data Aquisição': a.acquisition_date || '',
            'Data Garantia': a.warranty_date || '',
            'Centro de Custo': a.cost_center || '',
            'Vida Útil (anos)': a.lifespan_years || '',
            'Frequência Manut. (dias)': a.maintenance_freq || '',
            'Próxima Manut.': a.next_maintenance_date || '',
            'Descrição': a.description || '',
            'Componentes': a.components_list || '',
            'Acessórios': a.accessories_list || ''
        }));

        if (type === 'xlsx' || type === 'csv') {
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Ativos");
            XLSX.writeFile(wb, `relatorio_ativos.${type}`);
        } else if (type === 'pdf') {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({ orientation: 'landscape' });
            
            doc.text("Relatório de Ativos e Componentes", 14, 15);
            doc.setFontSize(10);
            doc.text(`Gerado em: ${new Date().toLocaleString()}`, 14, 22);

            const tableColumn = ["Código", "Nome", "Responsável", "Categoria", "Local", "Status", "Marca", "Modelo"];
            const tableRows = data.map(item => [
                item['Código'],
                item['Nome'],
                item['Responsável'],
                item['Categoria'],
                item['Local'],
                item['Status'],
                item['Marca'],
                item['Modelo']
            ]);

            doc.autoTable({
                head: [tableColumn],
                body: tableRows,
                startY: 28,
                styles: { fontSize: 8, cellPadding: 2 },
                columnStyles: { 
                    0: { cellWidth: 25 }, 
                    1: { cellWidth: 40 }, 
                    2: { cellWidth: 30 }, 
                    3: { cellWidth: 25 }, 
                    4: { cellWidth: 25 }, 
                    5: { cellWidth: 20 }, 
                    6: { cellWidth: 25 }, 
                    7: { cellWidth: 'auto' }
                }
            });
            doc.save("relatorio_ativos.pdf");
        }
        document.getElementById('exportMenu').classList.add('hidden');
    }

    // --- COLUNAS PERSONALIZADAS ---
    function toggleColumnMenu() {
        const menu = document.getElementById('columnMenu');
        if (menu) menu.classList.toggle('hidden');
    }

    function toggleColumn(colName) {
        const cells = document.querySelectorAll(`.col-${colName}`);
        const checkbox = document.querySelector(`#columnMenu input[onchange="toggleColumn('${colName}')"]`);
        const isChecked = checkbox ? checkbox.checked : true;
        
        cells.forEach(cell => {
            if (isChecked) cell.classList.remove('user-hidden');
            else cell.classList.add('user-hidden');
        });
        
        // Salva preferência
        const hiddenCols = [];
        document.querySelectorAll('#columnMenu input[type="checkbox"]').forEach(cb => {
            if (!cb.checked) {
                const name = cb.getAttribute('onchange').match(/'([^']+)'/)[1];
                hiddenCols.push(name);
            }
        });
        localStorage.setItem('assetsHiddenColumns', JSON.stringify(hiddenCols));
    }

    // Carrega preferências de colunas ao iniciar
    document.addEventListener('DOMContentLoaded', () => {
        const hiddenCols = JSON.parse(localStorage.getItem('assetsHiddenColumns') || '[]');
        hiddenCols.forEach(col => {
            const cb = document.querySelector(`#columnMenu input[onchange="toggleColumn('${col}')"]`);
            if (cb) {
                cb.checked = false;
                toggleColumn(col);
            }
        });
    });

    function filterRelatedAssets() {
        const input = document.getElementById('searchRelatedAsset');
        const filter = input.value.toLowerCase();
        const select = document.getElementById('selectRelatedAsset');
        const options = select.getElementsByTagName('option');
        
        for (let i = 0; i < options.length; i++) {
            const txtValue = options[i].textContent || options[i].innerText;
            if (options[i].value === "" || txtValue.toLowerCase().indexOf(filter) > -1) {
                options[i].style.display = "";
                options[i].hidden = false;
            } else {
                options[i].style.display = "none";
                options[i].hidden = true;
            }
        }
    }

    function openQuickView(asset) {
        document.getElementById('qvName').innerText = asset.name;
        document.getElementById('qvCode').innerText = asset.code;
        
        let statusClass = 'bg-slate-100 text-slate-600 border-slate-200';
        const s = asset.status.toLowerCase();
        if(s.includes('ativo') || s.includes('dispon')) statusClass = 'bg-green-100 text-green-700 border-green-200';
        else if(s.includes('manuten') || s.includes('reparo')) statusClass = 'bg-orange-100 text-orange-700 border-orange-200';
        else if(s.includes('uso') || s.includes('alocado')) statusClass = 'bg-blue-100 text-blue-700 border-blue-200';
        else if(s.includes('baixado') || s.includes('roubado')) statusClass = 'bg-red-100 text-red-700 border-red-200';
        document.getElementById('qvStatus').className = `px-2 py-1 rounded text-xs font-bold uppercase bg-white/90 backdrop-blur border shadow-sm ${statusClass}`;
        document.getElementById('qvStatus').innerText = asset.status;

        const img = document.getElementById('qvImage'); const noImg = document.getElementById('qvNoImage');
        if(asset.photo_url) { img.src = asset.photo_url; img.classList.remove('hidden'); noImg.classList.add('hidden'); } else { img.classList.add('hidden'); noImg.classList.remove('hidden'); }

        document.getElementById('qvLocation').innerText = asset.location_name || '-';
        document.getElementById('qvResponsible').innerText = asset.responsible_name || 'Não designado';
        document.getElementById('qvCategory').innerText = asset.category_name || '-';
        document.getElementById('qvValue').innerText = parseFloat(asset.value || 0).toLocaleString('pt-BR', {style: 'currency', currency: 'BRL'});
        document.getElementById('qvDescription').innerText = asset.description || 'Sem descrição.';
        document.getElementById('qvLink').href = `index.php?page=assets&id=${asset.id}`;
        openModal('modalQuickView');
    }

    function showBulkEditConfirmation() {
        const form = document.getElementById('formBulkEdit');
        const summaryContainer = document.getElementById('bulkChangesSummary');
        const countSpan = document.getElementById('confirmBulkCount');
        const ids = document.getElementById('bulkEditIds').value.split(',');
        
        summaryContainer.innerHTML = '';
        let changesHtml = '';
        let hasChanges = false;

        form.querySelectorAll('input[type=checkbox][name^="update_"]').forEach(checkbox => {
            if (checkbox.checked) {
                hasChanges = true;
                const fieldName = checkbox.name.replace('update_', '');
                const fieldElement = form.querySelector(`[name="${fieldName}"]`);
                const labelElement = checkbox.closest('.bulk-edit-field-wrapper').querySelector('span.text-sm.font-medium');
                const fieldLabel = labelElement ? labelElement.innerText : fieldName;
                
                let value = fieldElement.value;
                let displayValue = value;

                if (fieldElement.tagName === 'SELECT') {
                    const selectedOption = fieldElement.options[fieldElement.selectedIndex];
                    displayValue = (selectedOption && selectedOption.value) ? selectedOption.text : '<em>Não alterado</em>';
                } else if (fieldElement.type === 'date' && value) {
                    const [y, m, d] = value.split('-');
                    displayValue = `${d}/${m}/${y}`;
                } else if (!value) {
                    displayValue = '<em>Limpar valor</em>';
                }
                
                changesHtml += `<div class="flex justify-between items-center text-slate-600 p-2 bg-white rounded-md border border-slate-100">
                                    <span class="font-medium text-slate-500">${fieldLabel}:</span>
                                    <strong class="text-slate-800 text-right">${displayValue}</strong>
                                 </div>`;
            }
        });

        if (!hasChanges) { alert('Nenhum campo foi selecionado para alteração.'); return; }

        summaryContainer.innerHTML = changesHtml;
        countSpan.innerText = ids.length;
        openModal('modalBulkEditConfirm'); 
    }

    lucide.createIcons();
</script>