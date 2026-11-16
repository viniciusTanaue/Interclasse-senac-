<?php
require_once __DIR__ . '/db.php';
requireRole('admin');

$user = currentUser();
$pdo = getDBConnection();

$msgSucesso = $_SESSION['flash_success'] ?? null;
$msgErro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

function processTeamLogoUpload(): array {
    if (!isset($_FILES['logo_arquivo']) || $_FILES['logo_arquivo']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $file = $_FILES['logo_arquivo'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3 * 1024 * 1024) {
        return [null, 'A imagem não foi enviada corretamente ou excede 3 MB.'];
    }
    $imageInfo = @getimagesize($file['tmp_name']);
    $mimeToExtension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = $imageInfo['mime'] ?? '';
    if (!$imageInfo || !isset($mimeToExtension[$mime])) {
        return [null, 'Envie uma imagem PNG, JPG ou WebP válida.'];
    }
    $extension = $mimeToExtension[$mime];
    $safeName = 'logo_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $relativePath = 'assets/teams/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relativePath)) {
        return [null, 'Não foi possível salvar a imagem enviada.'];
    }
    return [$relativePath, null];
}

function isSafeTeamLogoPath(string $path): bool {
    return preg_match('#\Aassets/teams/[A-Za-z0-9_.-]+\.(?:svg|png|jpe?g|webp)\z#i', $path) === 1
        && is_file(__DIR__ . '/' . $path);
}

// Processamento de Ações Administrativas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $msgErro = null;
    $msgSucesso = null;
    
    // 1. ADICIONAR NOVA EQUIPE
    if ($_POST['acao'] === 'cadastrar_equipe') {
        $nome = trim($_POST['nome'] ?? '');
        $logoUrl = trim($_POST['logo_url'] ?? '');

        [$uploadedLogo, $uploadError] = processTeamLogoUpload();
        if ($uploadedLogo) $logoUrl = $uploadedLogo;

        if ($uploadError) {
            $msgErro = $uploadError;
        } elseif ($logoUrl !== '' && !isSafeTeamLogoPath($logoUrl)) {
            $msgErro = 'Selecione uma imagem existente dentro da pasta de logos.';
        } elseif (empty($logoUrl)) {
            $logoUrl = 'assets/teams/athena.svg';
        }

        if (!$msgErro && empty($nome)) {
            $msgErro = 'Informe o nome da nova equipe.';
        } elseif (!$msgErro) {
            $stmt = $pdo->prepare('INSERT INTO equipes (nome, logo_url) VALUES (:nome, :logo)');
            $stmt->execute([':nome' => $nome, ':logo' => $logoUrl]);
            $msgSucesso = "Equipe \"{$nome}\" cadastrada com sucesso!";
        }
    }

    // 2. EXCLUIR EQUIPE
    elseif ($_POST['acao'] === 'excluir_equipe') {
        $equipeId = (int)($_POST['equipe_id'] ?? 0);
        if ($equipeId > 0) {
            $stmt = $pdo->prepare('DELETE FROM equipes WHERE id = :id');
            $stmt->execute([':id' => $equipeId]);
            $msgSucesso = 'Equipe e seus dados associados foram excluídos.';
        }
    }

    // 3. EDITAR EQUIPE (NOME E LOGO)
    elseif ($_POST['acao'] === 'editar_equipe') {
        $equipeId = (int)($_POST['equipe_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $logoUrl = trim($_POST['logo_url'] ?? '');

        [$uploadedLogo, $uploadError] = processTeamLogoUpload();
        if ($uploadedLogo) $logoUrl = $uploadedLogo;

        if ($equipeId > 0 && !empty($nome)) {
            if ($uploadError) {
                $msgErro = $uploadError;
            } elseif (empty($logoUrl)) {
                $stmtCurrent = $pdo->prepare('SELECT logo_url FROM equipes WHERE id = :id');
                $stmtCurrent->execute([':id' => $equipeId]);
                $logoUrl = $stmtCurrent->fetchColumn() ?: 'assets/teams/athena.svg';
            }

            if (!$uploadError && isSafeTeamLogoPath($logoUrl)) {
                $stmt = $pdo->prepare('UPDATE equipes SET nome = :nome, logo_url = :logo WHERE id = :id');
                $stmt->execute([':nome' => $nome, ':logo' => $logoUrl, ':id' => $equipeId]);
                $msgSucesso = "Equipe \"{$nome}\" atualizada com sucesso!";
            } elseif (!$uploadError) {
                $msgErro = 'Selecione uma imagem existente dentro da pasta de logos.';
            }
        }
    }

    // 4. AGENDAR NOVO CONFRONTO
    elseif ($_POST['acao'] === 'agendar_partida') {
        $casaId = (int)($_POST['equipe_casa_id'] ?? 0);
        $visId = (int)($_POST['equipe_visitante_id'] ?? 0);
        $modalidade = trim($_POST['modalidade'] ?? '');
        $dataConfronto = trim($_POST['data_confronto'] ?? '');
        $dataHora = !empty($dataConfronto) ? $dataConfronto . ' 00:00:00' : date('Y-m-d H:i:s');
        $local = trim($_POST['local'] ?? '') ?: 'Senac Bauru';

        if ($casaId <= 0 || $visId <= 0 || $casaId === $visId || empty($modalidade)) {
            $msgErro = 'Preencha todos os dados da partida. As equipes mandante e visitante devem ser distintas.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO partidas (equipe_casa_id, equipe_visitante_id, modalidade, local, data_hora, status, gols_casa, gols_visitante) VALUES (:ec, :ev, :mod, :loc, :dh, "agendado", 0, 0)');
            $stmt->execute([
                ':ec'  => $casaId,
                ':ev'  => $visId,
                ':mod' => $modalidade,
                ':loc' => $local,
                ':dh'  => $dataHora,
            ]);
            $msgSucesso = 'Novo confronto agendado no calendário!';
        }
    }

    // 5. EDITAR MODALIDADE E DATA DO CONFRONTO
    elseif ($_POST['acao'] === 'editar_confronto') {
        $partidaId = (int)($_POST['partida_id'] ?? 0);
        $modalidade = trim($_POST['modalidade'] ?? '');
        $dataConfronto = trim($_POST['data_confronto'] ?? '');

        if ($partidaId > 0 && !empty($modalidade)) {
            $dataHora = !empty($dataConfronto) ? $dataConfronto . ' 00:00:00' : date('Y-m-d H:i:s');
            $stmt = $pdo->prepare('UPDATE partidas SET modalidade = :mod, data_hora = :dh WHERE id = :id');
            $stmt->execute([
                ':mod' => $modalidade,
                ':dh'  => $dataHora,
                ':id'  => $partidaId,
            ]);
            $msgSucesso = 'Confronto atualizado com sucesso!';
        }
    }

    // 6. EXCLUIR CONFRONTO
    elseif ($_POST['acao'] === 'excluir_confronto') {
        $partidaId = (int)($_POST['partida_id'] ?? 0);
        if ($partidaId > 0) {
            $stmt = $pdo->prepare('DELETE FROM partidas WHERE id = :id');
            $stmt->execute([':id' => $partidaId]);
            $msgSucesso = 'Confronto cancelado e removido do sistema.';
        }
    }

    // 7. ALTERAR NÍVEL DE ACESSO DO USUÁRIO
    elseif ($_POST['acao'] === 'alterar_nivel_usuario') {
        $targetUserId = (int)($_POST['usuario_id'] ?? 0);
        $novoTipo = in_array($_POST['novo_tipo'] ?? '', ['aluno', 'professor', 'admin']) ? $_POST['novo_tipo'] : 'aluno';

        if ($targetUserId > 0) {
            $stmt = $pdo->prepare('UPDATE usuarios SET tipo = :tipo WHERE id = :id');
            $stmt->execute([':tipo' => $novoTipo, ':id' => $targetUserId]);
            $msgSucesso = "Nível de acesso do usuário atualizado para \"{$novoTipo}\"!";
        }
    }

    elseif ($_POST['acao'] === 'redefinir_senha_usuario') {
        $targetUserId = (int)($_POST['usuario_id'] ?? 0);
        $novaSenha = (string)($_POST['nova_senha'] ?? '');
        if ($targetUserId <= 0 || strlen($novaSenha) < 12) {
            $msgErro = 'A nova senha deve ter pelo menos 12 caracteres.';
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET senha = :senha WHERE id = :id');
            $stmt->execute([':senha' => password_hash($novaSenha, PASSWORD_DEFAULT), ':id' => $targetUserId]);
            $msgSucesso = 'Senha atualizada com sucesso.';
        }
    }

    // 8. CADASTRAR NOVO USUÁRIO
    elseif ($_POST['acao'] === 'cadastrar_usuario') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');
        $turma = trim($_POST['turma'] ?? '');
        $tipo = in_array($_POST['tipo'] ?? '', ['aluno', 'professor', 'admin']) ? $_POST['tipo'] : 'aluno';
        $responsavel = trim($_POST['responsavel'] ?? '');

        if (empty($nome) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 12) {
            $msgErro = 'Informe um nome, um e-mail válido e uma senha com pelo menos 12 caracteres.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha, turma, tipo, responsavel) VALUES (:n, :e, :s, :t, :tp, :r)');
                $stmt->execute([
                    ':n'  => $nome,
                    ':e'  => $email,
                    ':s'  => password_hash($senha, PASSWORD_DEFAULT),
                    ':t'  => $turma,
                    ':tp' => $tipo,
                    ':r'  => $responsavel,
                ]);
                $msgSucesso = "Usuário \"{$nome}\" ({$tipo}) cadastrado com sucesso!";
            } catch (PDOException $e) {
                $msgErro = 'Erro ao cadastrar usuário: e-mail já existente.';
            }
        }
    }
}

// Consultas para os painéis
$equipes = $pdo->query('SELECT * FROM equipes ORDER BY nome ASC')->fetchAll();
$modalidades = $pdo->query('SELECT * FROM pontuacao_modalidades ORDER BY modalidade ASC')->fetchAll();

$queryPartidas = "SELECT p.*, ec.nome as casa_nome, ev.nome as visitante_nome 
    FROM partidas p 
    JOIN equipes ec ON p.equipe_casa_id = ec.id 
    JOIN equipes ev ON p.equipe_visitante_id = ev.id 
    ORDER BY p.data_hora ASC";
$partidas = $pdo->query($queryPartidas)->fetchAll();

$usuarios = $pdo->query('SELECT id, nome, email, turma, tipo, ativo, responsavel, data FROM usuarios ORDER BY FIELD(tipo, "admin", "professor", "aluno"), nome ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - Interclasse Senac Bauru 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/webp" href="assets/logo-senac-oficial.webp">
</head>
<body>

    <header class="top-header">
        <div class="top-header-content">
            <div class="brand-wrapper">
                <a href="dashboard.php" style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.2rem; color: #1565C0;">‹</span>
                    <img src="assets/logo-senac-oficial.webp" alt="Senac Bauru" class="brand-logo">
                </a>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?= getRoleBadge($user['tipo']) ?>
            </div>
        </div>
    </header>

    <main class="app-container">

        <div class="user-hero-card" style="background: linear-gradient(135deg, #311B92 0%, #1565C0 100%);">
            <span class="user-hero-tag tag-admin">Gestão Esportiva</span>
            <h1 class="user-hero-name">Painel de Gestão</h1>
            <p style="font-size: 0.85rem; opacity: 0.95;">
                Gestão central: cadastro de equipes, definição de confrontos e controle de usuários.
            </p>
        </div>

        <?php if ($msgSucesso): ?>
            <div class="alert alert-success">
                <span></span>
                <span><?= e($msgSucesso) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($msgErro): ?>
            <div class="alert alert-danger">
                <span></span>
                <span><?= e($msgErro) ?></span>
            </div>
        <?php endif; ?>

        <!-- Abas Administrativas -->
        <div class="denuncias-toggle" style="margin-bottom: 20px;">
            <button type="button" class="denuncias-tab-btn active" data-target="admin-confrontos">
                 Confrontos
            </button>
            <button type="button" class="denuncias-tab-btn" data-target="admin-equipes">
                 Equipes
            </button>
            <button type="button" class="denuncias-tab-btn" data-target="admin-usuarios">
                 Usuários
            </button>
        </div>

        <!-- SEÇÃO 1: DEFINIR CONFRONTOS -->
        <section id="admin-confrontos" class="denuncias-panel">
            
            <div class="action-box" style="border-top: 4px solid var(--primary);">
                <div class="action-box-header">
                    <h3><span></span> Adicionar Novo Confronto</h3>
                </div>
                
                <form action="admin.php" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="hidden" name="acao" value="agendar_partida">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Mandante (Equipe 1)</label>
                            <select name="equipe_casa_id" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                <option value="">Selecione...</option>
                                <?php foreach ($equipes as $eq): ?>
                                    <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Visitante (Equipe 2)</label>
                            <select name="equipe_visitante_id" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                <option value="">Selecione...</option>
                                <?php foreach ($equipes as $eq): ?>
                                    <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Modalidade do Confronto</label>
                            <input type="text" name="modalidade" list="admin_modalidades_list" class="form-control" placeholder="Ex: Futsal" required style="font-size: 0.85rem; padding: 8px;">
                            <datalist id="admin_modalidades_list">
                                <?php foreach ($modalidades as $m): ?>
                                    <option value="<?= e($m['modalidade']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Data do Confronto</label>
                            <input type="date" name="data_confronto" class="form-control" value="<?= date('Y-m-d') ?>" required style="font-size: 0.85rem; padding: 8px;">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 6px;">
                        <span> Salvar e Publicar Confronto</span>
                    </button>
                </form>
            </div>

            <h2 class="section-title"> Confrontos Cadastrados (<?= count($partidas) ?>)</h2>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($partidas as $p): ?>
                    <div class="action-box" style="margin-bottom: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <strong style="color: var(--primary); font-size: 0.85rem;">
                                 <?= e($p['modalidade']) ?>
                            </strong>
                            <span class="badge-status <?= $p['status'] === 'finalizado' ? 'badge-finalizado' : 'badge-agendado' ?>">
                                <?= ucfirst($p['status']) ?>
                            </span>
                        </div>

                        <div style="font-size: 0.95rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">
                            <?= e($p['casa_nome']) ?> 
                            <span style="color: var(--accent); font-weight: 900; margin: 0 4px;">
                                <?= $p['status'] === 'finalizado' ? "{$p['gols_casa']} x {$p['gols_visitante']}" : 'VS' ?>
                            </span> 
                            <?= e($p['visitante_nome']) ?>
                        </div>

                        <div style="font-size: 0.76rem; color: var(--text-muted); margin-bottom: 8px;">
                             <?= formatDate($p['data_hora']) ?>
                        </div>

                        <details>
                            <summary style="font-size: 0.76rem; color: #1565C0; cursor: pointer; font-weight: 700;">
                                 Alterar Modalidade ou Cancelar
                            </summary>
                            <form action="admin.php" method="POST" style="margin-top: 8px; background: #F5F6F8; padding: 10px; border-radius: 8px; display: flex; flex-direction: column; gap: 8px;">
                                <input type="hidden" name="acao" value="editar_confronto">
                                <input type="hidden" name="partida_id" value="<?= (int)$p['id'] ?>">

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <div>
                                        <label style="font-size: 0.72rem; display: block; color: var(--text-muted);">Modalidade</label>
                                        <input type="text" name="modalidade" value="<?= e($p['modalidade']) ?>" class="form-control" style="font-size: 0.8rem; padding: 6px;" required>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.72rem; display: block; color: var(--text-muted);">Data do Confronto</label>
                                        <input type="date" name="data_confronto" value="<?= date('Y-m-d', strtotime($p['data_hora'])) ?>" class="form-control" style="font-size: 0.8rem; padding: 6px;" required>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 8px; margin-top: 4px;">
                                    <button type="submit" class="btn btn-sm btn-primary" style="flex: 2;">
                                        Salvar Alterações
                                    </button>
                                </div>
                            </form>

                            <form action="admin.php" method="POST" style="margin-top: 6px; text-align: right;" onsubmit="return confirm('Deseja cancelar e remover definitivamente esta partida?')">
                                <input type="hidden" name="acao" value="excluir_confronto">
                                <input type="hidden" name="partida_id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" style="background: none; border: none; color: var(--danger); font-size: 0.74rem; cursor: pointer;">
                                     Excluir Confronto
                                </button>
                            </form>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- SEÇÃO 2: ADICIONAR E GERENCIAR EQUIPES -->
        <section id="admin-equipes" class="denuncias-panel" style="display: none;">
            
            <div class="action-box" style="border-top: 4px solid var(--accent);">
                <div class="action-box-header">
                    <h3><span></span> Adicionar Nova Equipe</h3>
                </div>

                <form action="admin.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="hidden" name="acao" value="cadastrar_equipe">

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem;">Nome da Equipe e Turma</label>
                        <input type="text" name="nome" class="form-control" placeholder="Ex: Esparta 3º TI" required style="font-size: 0.85rem; padding: 8px;">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem;">Upload da Logo Oficial (PNG, JPG, WebP; até 3 MB)</label>
                        <input type="file" name="logo_arquivo" accept="image/png,image/jpeg,image/webp" class="form-control" style="font-size: 0.82rem; padding: 6px;">
                        <span style="font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 3px;">Ou informe o caminho de uma imagem local existente:</span>
                        <input type="text" name="logo_url" class="form-control" placeholder="assets/teams/nome.svg" style="font-size: 0.82rem; padding: 6px; margin-top: 3px;">
                    </div>

                    <button type="submit" class="btn btn-accent" style="margin-top: 6px;">
                        <span> Cadastrar Equipe</span>
                    </button>
                </form>
            </div>

            <h2 class="section-title"> Equipes Participantes (<?= count($equipes) ?>)</h2>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($equipes as $eq): ?>
                    <div class="action-box" style="margin-bottom: 0;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <img src="<?= e($eq['logo_url'] ?: 'assets/teams/athena.svg') ?>" alt="<?= e($eq['nome']) ?>" style="width: 42px; height: 42px; border-radius: 50%; object-fit: contain; background: #FFF; border: 1.5px solid var(--border-color); padding: 2px;">
                                <div>
                                    <strong style="font-size: 0.9rem; color: var(--text-main); display: block;"><?= e($eq['nome']) ?></strong>
                                    <span style="font-size: 0.72rem; color: var(--text-muted);">Arquivo: <?= e(basename($eq['logo_url'] ?: 'sem logo')) ?></span>
                                </div>
                            </div>

                            <form action="admin.php" method="POST" onsubmit="return confirm('ATENÇÃO: Excluir esta equipe removerá suas partidas e registros. Deseja prosseguir?')">
                                <input type="hidden" name="acao" value="excluir_equipe">
                                <input type="hidden" name="equipe_id" value="<?= (int)$eq['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background: var(--danger-bg); color: var(--danger); border: none; padding: 6px 10px; font-size: 0.75rem; border-radius: 6px; cursor: pointer;">
                                     Excluir
                                </button>
                            </form>
                        </div>

                        <!-- Edição de Equipe e Troca de Logo -->
                        <details style="margin-top: 10px; border-top: 1px dashed var(--border-color); padding-top: 8px;">
                            <summary style="font-size: 0.76rem; color: #1565C0; cursor: pointer; font-weight: 700;">
                                 Alterar Nome ou Enviar Nova Logo Oficial
                            </summary>
                            <form action="admin.php" method="POST" enctype="multipart/form-data" style="margin-top: 8px; background: #F8F9FA; padding: 10px; border-radius: 8px; display: flex; flex-direction: column; gap: 8px;">
                                <input type="hidden" name="acao" value="editar_equipe">
                                <input type="hidden" name="equipe_id" value="<?= (int)$eq['id'] ?>">

                                <div>
                                    <label style="font-size: 0.72rem; display: block; color: var(--text-muted);">Nome da Equipe</label>
                                    <input type="text" name="nome" value="<?= e($eq['nome']) ?>" class="form-control" style="font-size: 0.82rem; padding: 6px;" required>
                                </div>

                                <div>
                                    <label style="font-size: 0.72rem; display: block; color: var(--text-muted);">Enviar Nova Logo (PNG, JPG ou WebP; até 3 MB)</label>
                                    <input type="file" name="logo_arquivo" accept="image/png,image/jpeg,image/webp" class="form-control" style="font-size: 0.8rem; padding: 5px;">
                                </div>

                                <div>
                                    <label style="font-size: 0.72rem; display: block; color: var(--text-muted);">Ou caminho de uma imagem local existente</label>
                                    <input type="text" name="logo_url" value="<?= e($eq['logo_url']) ?>" class="form-control" style="font-size: 0.8rem; padding: 6px;">
                                </div>

                                <button type="submit" class="btn btn-sm btn-primary" style="margin-top: 4px;">
                                     Salvar Alterações da Equipe
                                </button>
                            </form>
                        </details>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- SEÇÃO 3: GESTÃO DE USUÁRIOS E NÍVEIS DE ACESSO -->
        <section id="admin-usuarios" class="denuncias-panel" style="display: none;">
            
            <div class="action-box" style="border-top: 4px solid #5E35B1;">
                <div class="action-box-header">
                    <h3><span></span> Cadastrar Novo Usuário</h3>
                </div>

                <form action="admin.php" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="hidden" name="acao" value="cadastrar_usuario">

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Nome Completo</label>
                            <input type="text" name="nome" class="form-control" placeholder="Nome do usuário" required style="font-size: 0.85rem; padding: 8px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">E-mail Institucional</label>
                            <input type="email" name="email" class="form-control" placeholder="email@senac.br" required style="font-size: 0.85rem; padding: 8px;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Nível de Acesso (Papel)</label>
                            <select name="tipo" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                <option value="aluno">Aluno (Apenas visualização e denúncias)</option>
                                <option value="professor">Professor (Placar, súmula, denúncias e tabela)</option>
                                <option value="admin">Administrador (Acesso total)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Turma / Setor</label>
                            <input type="text" name="turma" class="form-control" placeholder="Ex: Athena 3º TI ou Docente" style="font-size: 0.85rem; padding: 8px;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" style="font-size: 0.78rem;">Senha Inicial</label>
                        <input type="password" name="senha" minlength="12" required autocomplete="new-password" class="form-control" style="font-size: 0.85rem; padding: 8px;">
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 6px;">
                        <span> Salvar Usuário</span>
                    </button>
                </form>
            </div>

            <h2 class="section-title"> Usuários do Sistema (<?= count($usuarios) ?>)</h2>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($usuarios as $u): ?>
                    <div class="action-box" style="margin-bottom: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                            <div>
                                <strong style="font-size: 0.9rem; color: var(--text-main); display: block;">
                                    <?= e($u['nome']) ?>
                                </strong>
                                <span style="font-size: 0.76rem; color: var(--text-muted); display: block;">
                                    <?= e($u['email']) ?> • Turma: <?= e($u['turma'] ?: 'Geral') ?>
                                </span>
                            </div>
                            <?= getRoleBadge($u['tipo']) ?>
                            <?php if (!(int)$u['ativo']): ?>
                                <span style="font-size:.7rem;color:var(--danger);font-weight:700">Login desativado</span>
                            <?php endif; ?>
                        </div>

                        <!-- Alteração Rápida de Nível -->
                        <form action="admin.php" method="POST" style="display: flex; gap: 8px; align-items: center; background: #F5F6F8; padding: 8px; border-radius: 6px;">
                            <input type="hidden" name="acao" value="alterar_nivel_usuario">
                            <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">

                            <label style="font-size: 0.72rem; color: var(--text-muted); margin-right: 4px;">Alterar Nível:</label>
                            <select name="novo_tipo" class="form-control" style="font-size: 0.76rem; padding: 4px 8px; width: auto; flex: 1;">
                                <option value="aluno" <?= $u['tipo'] === 'aluno' ? 'selected' : '' ?>>Aluno</option>
                                <option value="professor" <?= $u['tipo'] === 'professor' ? 'selected' : '' ?>>Professor</option>
                                <option value="admin" <?= $u['tipo'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                            </select>

                            <button type="submit" class="btn btn-sm btn-outline" style="padding: 4px 10px; font-size: 0.74rem;">
                                Atualizar
                            </button>
                        </form>

                        <form action="admin.php" method="POST" style="display:flex;gap:8px;align-items:center;margin-top:8px">
                            <input type="hidden" name="acao" value="redefinir_senha_usuario">
                            <input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <input type="password" name="nova_senha" minlength="12" required autocomplete="new-password" class="form-control" placeholder="Nova senha (mín. 12 caracteres)" style="font-size:.76rem">
                            <button type="submit" class="btn btn-sm btn-outline">Redefinir senha</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        </section>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item">
            <span class="nav-icon"></span>
            <span>Início</span>
        </a>
        <a href="jogos.php" class="bottom-nav-item">
            <span class="nav-icon"></span>
            <span>Jogos</span>
        </a>
        <a href="pontuacao.php" class="bottom-nav-item">
            <span class="nav-icon"></span>
            <span>Tabela</span>
        </a>
        <a href="denuncias.php" class="bottom-nav-item">
            <span class="nav-icon"></span>
            <span>Denúncias</span>
        </a>
        <a href="admin.php" class="bottom-nav-item active">
            <span class="nav-icon"></span>
            <span>Admin</span>
        </a>
    </nav>

    <script src="script.js"></script>
</body>
</html>
