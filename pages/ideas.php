<?php
// ==========================================================
// SETUP AUTOMÁTICO DA TABELA (Executa apenas se não existir)
// ==========================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ideas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        image_url VARCHAR(255) NULL,
        status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
        admin_feedback TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Tabela para os comentários
    $pdo->exec("CREATE TABLE IF NOT EXISTS idea_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        idea_id INT NOT NULL,
        user_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {
    // Falha silenciosa se houver erro de permissão, mas a tabela deve ser criada
}

// ==========================================================
// PROCESSAMENTO DE POST (Formulários)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. ENVIAR NOVA IDEIA
    if (isset($_POST['action']) && $_POST['action'] === 'submit_idea') {
        try {
            $title = trim($_POST['title']);
            $desc = trim($_POST['description']);
            $imgUrl = null;

            // Upload de Imagem (Opcional)
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $newName = 'idea_' . uniqid() . '.' . $ext;
                    $targetDir = 'uploads/ideas/';
                    
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetDir . $newName)) {
                        $imgUrl = $targetDir . $newName;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO ideas (user_id, title, description, image_url) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $title, $desc, $imgUrl]);

            $_SESSION['message'] = "Ideia enviada com sucesso! Aguarde a triagem.";
            header("Location: index.php?page=ideas");
            exit;

        } catch (Exception $e) {
            $_SESSION['message'] = "Erro ao enviar ideia: " . $e->getMessage();
        }
    }
    

    // 2. TRIAGEM (ADMIN/GESTOR)
    if (isset($_POST['action']) && $_POST['action'] === 'triage_idea') {
        if (in_array($_SESSION['user_role'], ['admin', 'gestor'])) {
            $id = $_POST['idea_id'];
            $status = $_POST['status']; // 'aprovado' ou 'rejeitado'
            $feedback = trim($_POST['feedback']);

            $stmt = $pdo->prepare("UPDATE ideas SET status = ?, admin_feedback = ? WHERE id = ?");
            $stmt->execute([$status, $feedback, $id]);

            // --- ENVIO DE NOTIFICAÇÃO POR E-MAIL DE TRIAGEM ---
            $stmt_author = $pdo->prepare("
                SELECT u.email, u.name AS author_name, i.title AS idea_title
                FROM ideas i
                JOIN users u ON i.user_id = u.id
                WHERE i.id = ?
            ");
            $stmt_author->execute([$id]);
            $idea_info = $stmt_author->fetch(PDO::FETCH_ASSOC);

            if ($idea_info && !empty($idea_info['email'])) {
                $to = $idea_info['email'];
                $status_text = ($status === 'aprovado') ? 'aprovada' : 'rejeitada';
                $subject = "Sua ideia \"{$idea_info['idea_title']}\" foi {$status_text}!";
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $domain = $_SERVER['HTTP_HOST'];
                $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $link_to_idea = $protocol . $domain . $base_path . '/index.php?page=ideas#idea-' . $id;

                $body = "Olá {$idea_info['author_name']},\n\nTemos uma atualização sobre sua ideia \"{$idea_info['idea_title']}\".\n\nStatus: " . ucfirst($status_text) . "\n";
                if (!empty($feedback)) { $body .= "Feedback da gestão: \"{$feedback}\"\n\n"; }
                $body .= "Para ver os detalhes, acesse o link abaixo:\n{$link_to_idea}\n\nAtenciosamente,\nSistema Patrimônio 360º";
                $headers = "From: no-reply@patrimonio360.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                @mail($to, $subject, $body, $headers);
            }

            $_SESSION['message'] = "Status da ideia atualizado!";
            header("Location: index.php?page=ideas");
            exit;
        }
    }

    // 3. ENVIAR NOVO COMENTÁRIO
    if (isset($_POST['action']) && $_POST['action'] === 'submit_comment') {
        try {
            $idea_id = $_POST['idea_id'];
            $comment = trim($_POST['comment']);
            $user_id = $_SESSION['user_id'];

            if (!empty($comment)) {
                $stmt = $pdo->prepare("INSERT INTO idea_comments (idea_id, user_id, comment) VALUES (?, ?, ?)");
                $stmt->execute([$idea_id, $user_id, $comment]);
                
                // --- ENVIO DE NOTIFICAÇÃO POR E-MAIL ---
                // Busca dados do autor da ideia para notificar
                $stmt_author = $pdo->prepare("
                    SELECT u.email, u.name AS author_name, i.title AS idea_title
                    FROM ideas i
                    JOIN users u ON i.user_id = u.id
                    WHERE i.id = ? AND u.id != ? -- Não notificar se o autor comentar na própria ideia
                ");
                $stmt_author->execute([$idea_id, $user_id]);
                $idea_info = $stmt_author->fetch(PDO::FETCH_ASSOC);

                if ($idea_info && !empty($idea_info['email'])) {
                    $to = $idea_info['email'];
                    $commenter_name = $_SESSION['user_name'];
                    $subject = "Sua ideia \"{$idea_info['idea_title']}\" recebeu um novo comentário!";
                    
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $domain = $_SERVER['HTTP_HOST'];
                    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                    $link_to_idea = $protocol . $domain . $base_path . '/index.php?page=ideas#idea-' . $idea_id;

                    $body = "Olá {$idea_info['author_name']},\n\nSua ideia \"{$idea_info['idea_title']}\" recebeu um novo comentário de {$commenter_name}.\n\nComentário:\n\"{$comment}\"\n\nPara ver a discussão e responder, acesse o link abaixo:\n{$link_to_idea}\n\nAtenciosamente,\nSistema Patrimônio 360º";
                    $headers = "From: no-reply@patrimonio360.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";

                    @mail($to, $subject, $body, $headers); // O '@' suprime erros caso o servidor de e-mail não esteja configurado
                }

                header("Location: index.php?page=ideas#idea-" . $idea_id);
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['message'] = "Erro ao adicionar comentário: " . $e->getMessage();
            header("Location: index.php?page=ideas");
            exit;
        }
    }
}

// ==========================================================
// CONSULTA DE DADOS
// ==========================================================
$isAdmin = in_array($_SESSION['user_role'], ['admin', 'gestor']);
$filter = $_GET['filter'] ?? 'all';

// Query base
$sql = "SELECT i.*, u.name as author_name, (SELECT COUNT(*) FROM idea_comments WHERE idea_id = i.id) as comment_count FROM ideas i LEFT JOIN users u ON i.user_id = u.id";
$params = [];

// Filtros de visualização
if (!$isAdmin) {
    // Usuários normais veem apenas as suas próprias ideias
    $sql .= " WHERE i.user_id = ?";
    $params[] = $_SESSION['user_id'];
} else {
    // Admins podem filtrar
    if ($filter === 'pending') {
        $sql .= " WHERE i.status = 'pendente'";
    }
}

$sql .= " ORDER BY i.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ideas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Busca todos os comentários para as ideias carregadas (evita N+1 query)
$idea_ids = array_column($ideas, 'id');
$comments = [];
if (!empty($idea_ids)) {
    $in = str_repeat('?,', count($idea_ids) - 1) . '?';
    $sql_comments = "
        SELECT c.*, u.name as author_name, u.photo_url as author_photo 
        FROM idea_comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.idea_id IN ($in) 
        ORDER BY c.created_at ASC
    ";
    $stmt_comments = $pdo->prepare($sql_comments);
    $stmt_comments->execute($idea_ids);
    $all_comments = $stmt_comments->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_comments as $comment) { $comments[$comment['idea_id']][] = $comment; }
}
?>

<div class="space-y-6">
    <!-- Cabeçalho -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i data-lucide="lightbulb" class="w-6 h-6 text-yellow-500"></i>
                Canal de Ideias
            </h2>
            <p class="text-slate-500 text-sm mt-1">Contribua com sugestões e inovações para a empresa.</p>
        </div>
        <button onclick="openModal('newIdeaModal')" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg flex items-center gap-2 transition-all shadow-sm font-medium">
            <i data-lucide="plus" class="w-5 h-5"></i>
            Nova Ideia
        </button>
    </div>

    <!-- Filtros (Apenas Admin) -->
    <?php if ($isAdmin): ?>
    <div class="flex gap-2">
        <a href="index.php?page=ideas&filter=all" class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors <?php echo $filter=='all' ? 'bg-blue-100 text-blue-700 ring-1 ring-blue-200' : 'bg-white text-slate-600 border hover:bg-slate-50'; ?>">Todas</a>
        <a href="index.php?page=ideas&filter=pending" class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors <?php echo $filter=='pending' ? 'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200' : 'bg-white text-slate-600 border hover:bg-slate-50'; ?>">Pendentes</a>
    </div>
    <?php endif; ?>

    <!-- Grid de Ideias -->
    <?php if (empty($ideas)): ?>
        <div class="text-center py-12 bg-white rounded-xl border border-dashed border-slate-300">
            <div class="bg-slate-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="inbox" class="w-8 h-8 text-slate-400"></i>
            </div>
            <h3 class="text-lg font-medium text-slate-900">Nenhuma ideia encontrada</h3>
            <p class="text-slate-500 text-sm">Seja o primeiro a compartilhar uma sugestão!</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach($ideas as $idea): ?>
                <?php 
                    // Definição de cores por status
                    $statusColors = [
                        'pendente' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                        'aprovado' => 'bg-green-100 text-green-800 border-green-200',
                        'rejeitado' => 'bg-red-100 text-red-800 border-red-200'
                    ];
                    $statusLabel = ucfirst($idea['status']);
                    $badgeClass = $statusColors[$idea['status']] ?? 'bg-slate-100 text-slate-800';
                ?>
                <div id="idea-<?php echo $idea['id']; ?>" class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col h-full hover:shadow-md transition-shadow">
                    <!-- Imagem (se houver) -->
                    <?php if (!empty($idea['image_url'])): ?>
                        <div class="h-48 w-full bg-slate-100 overflow-hidden relative group">
                            <img src="<?php echo htmlspecialchars($idea['image_url']); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        </div>
                    <?php endif; ?>

                    <div class="p-5 flex-1 flex flex-col">
                        <div class="flex justify-between items-start mb-3">
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold border <?php echo $badgeClass; ?>">
                                <?php echo $statusLabel; ?>
                            </span>
                            <span class="text-xs text-slate-400" title="<?php echo $idea['created_at']; ?>">
                                <?php echo date('d/m/Y', strtotime($idea['created_at'])); ?>
                            </span>
                        </div>

                        <h3 class="font-bold text-lg text-slate-800 mb-2 line-clamp-2"><?php echo htmlspecialchars($idea['title']); ?></h3>
                        <p class="text-slate-600 text-sm mb-4 line-clamp-3 flex-1"><?php echo nl2br(htmlspecialchars($idea['description'])); ?></p>

                        <!-- Feedback do Admin -->
                        <?php if (!empty($idea['admin_feedback'])): ?>
                            <div class="mt-4 p-3 bg-slate-50 rounded-lg border border-slate-100 text-sm">
                                <p class="font-semibold text-slate-700 text-xs uppercase mb-1">Feedback da Gestão:</p>
                                <p class="text-slate-600 italic">"<?php echo htmlspecialchars($idea['admin_feedback']); ?>"</p>
                            </div>
                        <?php endif; ?>

                        <!-- Área de Ação (Admin) -->
                        <?php if ($isAdmin && $idea['status'] === 'pendente'): ?>
                            <div class="mt-4 pt-4 border-t border-slate-100">
                                <form method="POST" class="space-y-3">
                                    <input type="hidden" name="action" value="triage_idea">
                                    <input type="hidden" name="idea_id" value="<?php echo $idea['id']; ?>">
                                    
                                    <textarea name="feedback" rows="2" class="w-full text-sm border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" placeholder="Escreva um feedback (opcional)..."></textarea>
                                    
                                    <div class="flex gap-2">
                                        <button type="submit" name="status" value="aprovado" class="flex-1 bg-green-600 hover:bg-green-700 text-white text-xs font-bold py-2 rounded transition-colors">Aprovar</button>
                                        <button type="submit" name="status" value="rejeitado" class="flex-1 bg-red-500 hover:bg-red-600 text-white text-xs font-bold py-2 rounded transition-colors">Rejeitar</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Seção de Comentários -->
                        <div class="mt-auto pt-4 border-t border-slate-100">
                            <button onclick="toggleComments('comments-<?php echo $idea['id']; ?>')" class="w-full text-sm font-medium text-blue-600 hover:text-blue-800 flex items-center gap-2 transition-colors">
                                <i data-lucide="message-square" class="w-4 h-4"></i>
                                <?php echo $idea['comment_count']; ?> Comentário(s)
                                <i data-lucide="chevron-down" class="w-4 h-4 ml-auto toggle-icon transition-transform"></i>
                            </button>
                            <div id="comments-<?php echo $idea['id']; ?>" class="hidden mt-4 space-y-4">
                                <!-- Lista de comentários -->
                                <div class="space-y-4 max-h-60 overflow-y-auto pr-2 custom-scrollbar">
                                    <?php if (!empty($comments[$idea['id']])): ?>
                                        <?php foreach($comments[$idea['id']] as $comment): ?>
                                        <div class="flex items-start gap-3">
                                            <img src="<?php echo htmlspecialchars($comment['author_photo'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($comment['author_name']) . '&background=e2e8f0&color=64748b'); ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 mt-1">
                                            <div class="flex-1 bg-slate-50 p-3 rounded-lg rounded-tl-none border border-slate-100">
                                                <div class="flex justify-between items-center">
                                                    <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($comment['author_name']); ?></p>
                                                    <p class="text-[10px] text-slate-400" title="<?php echo $comment['created_at']; ?>"><?php echo date('d/m H:i', strtotime($comment['created_at'])); ?></p>
                                                </div>
                                                <p class="text-sm text-slate-600 mt-1"><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-xs text-slate-400 italic text-center py-2">Nenhum comentário ainda. Seja o primeiro!</p>
                                    <?php endif; ?>
                                </div>
                                <!-- Formulário para novo comentário -->
                                <div class="flex items-start gap-3 pt-4 border-t border-slate-200">
                                    <img src="<?php echo htmlspecialchars($user_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['user_name']) . '&background=e2e8f0&color=64748b'); ?>" class="w-8 h-8 rounded-full object-cover mt-1 border border-slate-200">
                                    <form method="POST" class="flex-1">
                                        <input type="hidden" name="action" value="submit_comment">
                                        <input type="hidden" name="idea_id" value="<?php echo $idea['id']; ?>">
                                        <textarea name="comment" rows="2" class="w-full text-sm border-slate-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" placeholder="Escreva um comentário..." required></textarea>
                                        <button type="submit" class="mt-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold py-1.5 px-4 rounded-lg transition-colors shadow-sm">Enviar</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="px-5 py-3 bg-slate-50 border-t border-slate-100 text-xs text-slate-500 flex items-center gap-2">
                        <i data-lucide="user" class="w-3 h-3"></i>
                        <?php echo htmlspecialchars($idea['author_name'] ?? 'Usuário'); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Nova Ideia -->
<div id="newIdeaModal" class="fixed inset-0 z-[60] hidden flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm transition-opacity opacity-0 modal-backdrop" onclick="closeModal('newIdeaModal')"></div>
    
    <div class="relative w-full max-w-2xl bg-slate-50 rounded-2xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden modal-panel transform scale-95 opacity-0 transition-all duration-300">
        
        <form method="POST" enctype="multipart/form-data" class="flex flex-col h-full overflow-hidden">
            <input type="hidden" name="action" value="submit_idea">
            
            <!-- Header -->
            <div class="px-6 py-5 border-b border-slate-200 flex justify-between items-center bg-white shrink-0">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-yellow-100 text-yellow-600 flex items-center justify-center border border-yellow-200">
                        <i data-lucide="lightbulb" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">Compartilhe sua Ideia</h3>
                        <p class="text-sm text-slate-500">Sua sugestão pode fazer a diferença!</p>
                    </div>
                </div>
                <button type="button" onclick="closeModal('newIdeaModal')" class="w-8 h-8 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-full transition-colors">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Body (Scrollable) -->
            <div class="flex-1 overflow-y-auto p-6 space-y-6">
                <!-- Title Input -->
                <div>
                    <label for="ideaTitle" class="block text-sm font-bold text-slate-700 mb-1.5">Título da Ideia</label>
                    <div class="relative">
                        <i data-lucide="edit-3" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                        <input type="text" name="title" id="ideaTitle" required class="w-full pl-10 pr-4 py-2.5 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Um título curto e direto para sua sugestão">
                    </div>
                </div>
                
                <!-- Description Input -->
                <div>
                    <label for="ideaDescription" class="block text-sm font-bold text-slate-700 mb-1.5">Descreva sua Ideia</label>
                    <textarea name="description" id="ideaDescription" required rows="5" class="w-full p-3 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none" placeholder="Seja detalhista! Explique o problema, sua solução, os benefícios esperados e como poderia ser implementado."></textarea>
                </div>

                <!-- Image Upload -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Anexar uma Imagem (Opcional)</label>
                    <div id="imageUploader" class="relative border-2 border-dashed border-slate-300 rounded-xl p-6 text-center hover:border-blue-400 transition-colors group cursor-pointer">
                        <input type="file" name="image" id="ideaImage" accept="image/*" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        <div id="uploadPlaceholder" class="pointer-events-none">
                            <i data-lucide="image-plus" class="w-10 h-10 text-slate-400 mx-auto mb-3 group-hover:text-blue-500 transition-colors"></i>
                            <p class="text-sm font-medium text-slate-700">Clique ou arraste uma imagem</p>
                            <p class="text-xs text-slate-400 mt-1">PNG, JPG ou WEBP (máx. 5MB)</p>
                        </div>
                        <img id="imagePreview" class="hidden max-h-32 mx-auto rounded-lg">
                        <button type="button" id="removeImageBtn" class="hidden absolute top-2 right-2 p-1 bg-white/70 backdrop-blur-sm rounded-full text-red-500 hover:bg-red-100">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="px-6 py-4 bg-white border-t border-slate-100 flex justify-end gap-3 rounded-b-2xl">
                <button type="button" onclick="closeModal('newIdeaModal')" class="px-5 py-2.5 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700 shadow-sm shadow-blue-200 transition-colors flex items-center gap-2">
                    <i data-lucide="send" class="w-4 h-4"></i> Enviar Ideia
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.querySelector('.modal-backdrop')?.classList.remove('opacity-0');
            modal.querySelector('.modal-panel')?.classList.remove('opacity-0', 'scale-95');
        }, 10);
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.querySelector('.modal-backdrop')?.classList.add('opacity-0');
        modal.querySelector('.modal-panel')?.classList.add('opacity-0', 'scale-95');
        setTimeout(() => modal.classList.add('hidden'), 300);
    }

    function toggleComments(id) {
        const el = document.getElementById(id);
        const icon = el.previousElementSibling.querySelector('.toggle-icon');
        if (el) {
            el.classList.toggle('hidden');
            icon.classList.toggle('rotate-180');
        }
    }

    // Lógica para o upload de imagem
    document.addEventListener('DOMContentLoaded', function() {
        const imageInput = document.getElementById('ideaImage');
        const imagePreview = document.getElementById('imagePreview');
        const uploadPlaceholder = document.getElementById('uploadPlaceholder');
        const removeImageBtn = document.getElementById('removeImageBtn');

        if (imageInput) {
            imageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imagePreview.src = event.target.result;
                        imagePreview.classList.remove('hidden');
                        uploadPlaceholder.classList.add('hidden');
                        removeImageBtn.classList.remove('hidden');
                    }
                    reader.readAsDataURL(file);
                }
            });
        }

        if (removeImageBtn) {
            removeImageBtn.addEventListener('click', function(e) {
                e.stopPropagation(); // Impede que o clique no botão acione o input de arquivo
                imageInput.value = ''; // Limpa o input
                imagePreview.src = '';
                imagePreview.classList.add('hidden');
                uploadPlaceholder.classList.remove('hidden');
                removeImageBtn.classList.add('hidden');
            });
        }
    });
</script>