<?php
require_once __DIR__ . '/db.php';
requireAuth();

$user = currentUser();
$pdo = getDBConnection();

$msgSucesso = $_SESSION['flash_success'] ?? null;
$msgErro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Busca todas as equipes para o formulário de denúncia
$stmtEquipes = $pdo->query('SELECT id, nome FROM equipes ORDER BY nome ASC');
$todasEquipes = $stmtEquipes->fetchAll();

// Identifica a equipe do aluno logado baseado na turma
$minhaEquipeId = null;
$minhaEquipeNome = 'Sua Turma / Equipe';

foreach ($todasEquipes as $eq) {
    if (stripos($user['turma'], explode(' ', $eq['nome'])[0]) !== false || stripos($eq['nome'], $user['turma']) !== false) {
        $minhaEquipeId = $eq['id'];
        $minhaEquipeNome = $eq['nome'];
        break;
    }
}

if (!$minhaEquipeId) {
    $minhaEquipeId = 1;
    $minhaEquipeNome = 'Athena 3º TI';
}

// 1. Processamento de Nova Denúncia (Aluno, Professor ou Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar_denuncia') {
    $equipeAgressoraId = (int)($_POST['equipe_agressora_id'] ?? 0);
    $descricao = trim($_POST['descricao'] ?? '');

    if ($equipeAgressoraId <= 0 || empty($descricao)) {
        $msgErro = 'Selecione a equipe envolvida e relate os detalhes do ocorrido.';
    } else {
        $stmtInsert = $pdo->prepare('INSERT INTO denuncias (denunciante_id, equipe_agressora_id, descricao, status) VALUES (:denunciante, :agressora, :descricao, "pendente")');
        $stmtInsert->execute([
            ':denunciante' => $user['id'],
            ':agressora'   => $equipeAgressoraId,
            ':descricao'   => $descricao,
        ]);
        $msgSucesso = 'Denúncia protocolada com sucesso! A Comissão de Professores analisará os fatos.';
    }
}

// 2. Moderação de Denúncias e Aplicação de Punições (Professor e Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'julgar_denuncia') {
    if (!canModerateDenuncias()) {
        $msgErro = 'Apenas professores e administradores têm permissão para julgar denúncias e aplicar punições.';
    } else {
        $denunciaId = (int)($_POST['denuncia_id'] ?? 0);
        $novoStatus = in_array($_POST['novo_status'] ?? '', ['aceita', 'rejeitada', 'pendente']) ? $_POST['novo_status'] : 'pendente';
        $pontosPenalidade = (int)($_POST['pontos_penalidade'] ?? 0); // e.g. -10, -5

        // Assegura que pontos de penalidade sejam negativos se forem punições
        if ($novoStatus === 'aceita' && $pontosPenalidade > 0) {
            $pontosPenalidade = -$pontosPenalidade;
        } elseif ($novoStatus !== 'aceita') {
            $pontosPenalidade = 0;
        }

        // Busca dados da denúncia para punir a equipe agressora se necessário
        $stmtDen = $pdo->prepare('SELECT d.*, eq.nome as equipe_nome FROM denuncias d JOIN equipes eq ON d.equipe_agressora_id = eq.id WHERE d.id = :id');
        $stmtDen->execute([':id' => $denunciaId]);
        $denData = $stmtDen->fetch();

        if ($denData) {
            $stmtUp = $pdo->prepare('UPDATE denuncias SET 
                status = :status, 
                pontos_penalidade = :pts, 
                analisado_por = :analisador, 
                data_analise = NOW() 
                WHERE id = :id');
            $stmtUp->execute([
                ':status'     => $novoStatus,
                ':pts'        => $pontosPenalidade,
                ':analisador' => $user['id'],
                ':id'         => $denunciaId,
            ]);

            // Se a denúncia foi aceita com perda de pontos, insere na tabela de pontos extras/penalidades
            if ($novoStatus === 'aceita' && $pontosPenalidade < 0) {
                // Busca a modalidade de Penalidades (Fair Play) no banco de forma dinâmica
                $stmtModPen = $pdo->query("SELECT id FROM pontuacao_modalidades WHERE modalidade LIKE '%Penalidade%' LIMIT 1");
                $penModalidadeId = $stmtModPen ? ($stmtModPen->fetchColumn() ?: 1) : 1;
                $motivoPenalidade = "Punição disciplinar ref. Denúncia #" . $denunciaId;
                try {
                    $stmtExtra = $pdo->prepare('INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo, criado_por) VALUES (:eq, :mod, :pts, :motivo, :criado_por)');
                    $stmtExtra->execute([
                        ':eq'         => $denData['equipe_agressora_id'],
                        ':mod'        => $penModalidadeId,
                        ':pts'        => $pontosPenalidade,
                        ':motivo'     => $motivoPenalidade,
                        ':criado_por' => $user['id'],
                    ]);
                } catch (PDOException $e) {
                    $stmtExtra = $pdo->prepare('INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo) VALUES (:eq, :mod, :pts, :motivo)');
                    $stmtExtra->execute([
                        ':eq'     => $denData['equipe_agressora_id'],
                        ':mod'    => $penModalidadeId,
                        ':pts'    => $pontosPenalidade,
                        ':motivo' => $motivoPenalidade,
                    ]);
                }
            }

            $msgSucesso = 'Denúncia #' . $denunciaId . ' julgada com status "' . ucfirst($novoStatus) . '"' . ($pontosPenalidade < 0 ? " com penalidade de {$pontosPenalidade} pontos aplicada à equipe!" : ".");
        }
    }
}

// Exclusão de denúncias para professores e administradores.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_denuncia') {
    if (!canModerateDenuncias()) {
        $msgErro = 'Apenas professores e administradores podem excluir denúncias.';
    } else {
        $denunciaId = (int)($_POST['denuncia_id'] ?? 0);
        if ($denunciaId > 0) {
            $pdo->beginTransaction();
            try {
                $stmtPenalty = $pdo->prepare("DELETE FROM equipe_pontos_extras WHERE motivo LIKE :motivo");
                $stmtPenalty->execute([':motivo' => 'Punição disciplinar ref. Denúncia #' . $denunciaId . '%']);
                $stmtDelete = $pdo->prepare('DELETE FROM denuncias WHERE id = :id');
                $stmtDelete->execute([':id' => $denunciaId]);
                $pdo->commit();
                $msgSucesso = $stmtDelete->rowCount() ? 'Denúncia removida com sucesso.' : 'A denúncia selecionada não foi encontrada.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msgErro = 'Não foi possível excluir a denúncia.';
            }
        }
    }
}

// 3. Contadores de denúncias contra a equipe do aluno
$stmtCount = $pdo->prepare("SELECT 
    COUNT(*) as total_recebidas,
    SUM(CASE WHEN status = 'aceita' THEN 1 ELSE 0 END) as total_aceitas,
    SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as total_pendentes,
    SUM(CASE WHEN status = 'rejeitada' THEN 1 ELSE 0 END) as total_rejeitadas
    FROM denuncias 
    WHERE equipe_agressora_id = :eq_id");
$stmtCount->execute([':eq_id' => $minhaEquipeId]);
$contadores = $stmtCount->fetch() ?: ['total_recebidas' => 0, 'total_aceitas' => 0, 'total_pendentes' => 0, 'total_rejeitadas' => 0];

// 4. Denúncias registradas contra a equipe do aluno (Sem detalhes expostos para o aluno)
$stmtListaMinha = $pdo->prepare("SELECT id, status, data_registro, pontos_penalidade, descricao
    FROM denuncias
    WHERE equipe_agressora_id = :eq_id
    ORDER BY data_registro DESC");
$stmtListaMinha->execute([':eq_id' => $minhaEquipeId]);
$denunciasContraMinhaEquipe = $stmtListaMinha->fetchAll();

// 5. Denúncias enviadas pelo próprio usuário (para ele acompanhar suas próprias denúncias)
$stmtMinhasEnviadas = $pdo->prepare("SELECT d.*, eq.nome as equipe_agressora_nome
    FROM denuncias d
    JOIN equipes eq ON d.equipe_agressora_id = eq.id
    WHERE d.denunciante_id = :user_id
    ORDER BY d.data_registro DESC");
$stmtMinhasEnviadas->execute([':user_id' => $user['id']]);
$minhasDenunciasEnviadas = $stmtMinhasEnviadas->fetchAll();

// 6. Todas as denúncias para o corpo docente (Professores e Administradores)
$todasDenuncias = [];
if (canModerateDenuncias()) {
    $stmtGeral = $pdo->query("SELECT d.*, eq.nome as equipe_nome, u.nome as denunciante_nome, u.turma as denunciante_turma
        FROM denuncias d
        JOIN equipes eq ON d.equipe_agressora_id = eq.id
        JOIN usuarios u ON d.denunciante_id = u.id
        ORDER BY 
            CASE WHEN d.status = 'pendente' THEN 0 ELSE 1 END,
            d.data_registro DESC");
    $todasDenuncias = $stmtGeral->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canal de Denúncias - Interclasse Senac Bauru 2026</title>
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

        <!-- Alternador de Abas Mobile First -->
        <div class="denuncias-toggle">
            <button type="button" class="denuncias-tab-btn <?= canModerateDenuncias() ? '' : 'active' ?>" data-target="panel-nova">
                 Fazer Denúncia
            </button>
            <button type="button" class="denuncias-tab-btn" data-target="panel-minha">
                 Contra Sua Equipe
            </button>
            <button type="button" class="denuncias-tab-btn" data-target="panel-enviadas">
                 Minhas Enviadas
            </button>
            <?php if (canModerateDenuncias()): ?>
                <button type="button" class="denuncias-tab-btn active" data-target="panel-moderacao">
                     Julgamento Docente
                </button>
            <?php endif; ?>
        </div>

        <!-- PAINEL 1: CADASTRAR NOVA DENÚNCIA (Acesso Geral) -->
        <section id="panel-nova" class="denuncias-panel" style="display: <?= canModerateDenuncias() ? 'none' : 'block' ?>;">
            <div class="user-hero-card" style="background: linear-gradient(135deg, #F57C00 0%, #D84315 100%);">
                <span class="user-hero-tag" style="background: #FFFFFF; color: #D84315;">Fair Play & Ética</span>
                <h1 class="user-hero-name">Registrar Ocorrência</h1>
                <p style="font-size: 0.85rem; opacity: 0.95;">
                    Denuncie condutas antidesportivas, infrações às regras ou agressões verbais/físicas.
                </p>
            </div>

            <form action="denuncias.php" method="POST" class="nav-card-btn" style="display: block; cursor: default;">
                <input type="hidden" name="acao" value="cadastrar_denuncia">

                <div class="form-group">
                    <label for="equipe_agressora_id" class="form-label">Equipe Envolvida / Denunciada</label>
                    <select name="equipe_agressora_id" id="equipe_agressora_id" class="form-control" required>
                        <option value="">Selecione a equipe contra a qual deseja relatar...</option>
                        <?php foreach ($todasEquipes as $equipe): ?>
                            <option value="<?= (int)$equipe['id'] ?>"><?= e($equipe['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="descricao" class="form-label">Relato Detalhado do Fato</label>
                    <textarea 
                        name="descricao" 
                        id="descricao" 
                        class="form-control" 
                        placeholder="Informe com clareza o que aconteceu: quadra/sala, horário aproximado, atletas envolvidos e modalidade..."
                        required
                        rows="4"
                    ></textarea>
                </div>

                <button type="submit" class="btn btn-accent">
                    <span> Enviar Denúncia à Comissão</span>
                </button>
            </form>
        </section>

        <!-- PAINEL 2: DENÚNCIAS CONTRA SUA EQUIPE (COM PROTEÇÃO DE PRIVACIDADE PARA O ALUNO) -->
        <section id="panel-minha" class="denuncias-panel" style="display: none;">
            
            <div class="user-hero-card" style="background: linear-gradient(135deg, #263238 0%, #37474F 100%);">
                <span class="user-hero-tag" style="background: var(--primary); color: #fff;">Painel Disciplinar</span>
                <h2 class="user-hero-name"><?= e($minhaEquipeNome) ?></h2>
                <p style="font-size: 0.85rem; opacity: 0.9;">
                    Acompanhe o quantitativo de notificações disciplinares registradas contra sua equipe.
                </p>
            </div>

            <!-- Contadores Numéricos (Conforme Requisito: mantém a quantidade visível) -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-value"><?= (int)$contadores['total_recebidas'] ?></div>
                    <div class="stat-label">Denúncias Recebidas</div>
                </div>
                <div class="stat-card" style="border-left: 4px solid var(--danger);">
                    <div class="stat-value" style="color: var(--danger);"><?= (int)$contadores['total_aceitas'] ?></div>
                    <div class="stat-label">Punições Aplicadas</div>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--accent);"><?= (int)$contadores['total_pendentes'] ?></div>
                    <div class="stat-label">Em Julgamento</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--success);"><?= (int)$contadores['total_rejeitadas'] ?></div>
                    <div class="stat-label">Desconsideradas</div>
                </div>
            </div>

            <h3 class="section-title"> Notificações da Equipe</h3>

            <?php if (empty($denunciasContraMinhaEquipe)): ?>
                <div class="alert alert-info">
                    <span></span>
                    <span>Nenhuma denúncia registrada contra sua equipe até o momento. Excelente espírito esportivo!</span>
                </div>
            <?php else: ?>
                <?php foreach ($denunciasContraMinhaEquipe as $item): ?>
                    <div class="denuncia-card" style="border-left: 4px solid <?= $item['status'] === 'aceita' ? 'var(--danger)' : ($item['status'] === 'rejeitada' ? 'var(--success)' : 'var(--accent)') ?>;">
                        <div class="denuncia-header">
                            <span style="font-weight: 700; font-size: 0.82rem; color: var(--text-main);">
                                 Protocolo #<?= (int)$item['id'] ?> • <?= formatDateTime($item['data_registro']) ?>
                            </span>
                            <span class="badge-denuncia <?= e($item['status']) ?>">
                                <?= e(ucfirst($item['status'])) ?>
                            </span>
                        </div>
                        
                        <!-- REGRA ESTRITA: ALUNO NÃO VÊ OS DETALHES OU TEXTO DA DENÚNCIA REGISTRADA PELA EQUIPE RIVAL -->
                        <?php if (isAluno()): ?>
                            <div style="background: #F8F9FA; padding: 12px; border-radius: 8px; border: 1px dashed #CFD8DC; margin-top: 8px; font-size: 0.82rem; color: #546E7A; line-height: 1.5;">
                                 <strong>Detalhes mantidos sob sigilo pela Comissão Docente:</strong> Os termos específicos e a autoria da acusação são restritos aos professores para garantir a integridade e evitar atritos entre torcidas (Regulamento Interclasse 2026).
                                <?php if ($item['status'] === 'aceita' && $item['pontos_penalidade'] < 0): ?>
                                    <div style="margin-top: 6px; color: var(--danger); font-weight: 700;">
                                         Punição disciplinar confirmada: dedução de <?= (int)$item['pontos_penalidade'] ?> pontos na tabela.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Professores e Administradores podem visualizar a descrição completa -->
                            <p style="font-size: 0.88rem; color: var(--text-main); margin-top: 8px; background: #FAFAFA; padding: 10px; border-radius: 8px;">
                                <?= nl2br(e($item['descricao'])) ?>
                            </p>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </section>

        <!-- PAINEL 3: MINHAS DENÚNCIAS ENVIADAS (Aluno visualiza o status das que ele próprio fez) -->
        <section id="panel-enviadas" class="denuncias-panel" style="display: none;">
            <h2 class="section-title"> Denúncias que você protocolou</h2>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">
                Acompanhe o andamento das solicitações de punição que você enviou à comissão julgadora.
            </p>

            <?php if (empty($minhasDenunciasEnviadas)): ?>
                <div class="alert alert-info">
                    <span>ℹ</span>
                    <span>Você ainda não registrou nenhuma denúncia durante esta edição dos jogos.</span>
                </div>
            <?php else: ?>
                <?php foreach ($minhasDenunciasEnviadas as $den): ?>
                    <div class="denuncia-card">
                        <div class="denuncia-header">
                            <strong style="color: var(--primary); font-size: 0.88rem;">
                                Contra: <?= e($den['equipe_agressora_nome']) ?>
                            </strong>
                            <span class="badge-denuncia <?= e($den['status']) ?>">
                                <?= e(ucfirst($den['status'])) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.76rem; color: var(--text-muted); margin-bottom: 8px;">
                            Registrada em <?= formatDateTime($den['data_registro']) ?>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--text-main); background: #FAFAFA; padding: 10px; border-radius: 8px; margin-bottom: 8px;">
                            <?= nl2br(e($den['descricao'])) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- PAINEL 4: MODERAÇÃO & JULGAMENTO (PROFESSORES & ADMINISTRADORES) -->
        <?php if (canModerateDenuncias()): ?>
            <section id="panel-moderacao" class="denuncias-panel" style="display: block;">
                <div class="user-hero-card" style="background: linear-gradient(135deg, #1565C0 0%, #5E35B1 100%);">
                    <span class="user-hero-tag" style="background: #FFD54F; color: #263238;">Tribunal Desportivo</span>
                    <h2 class="user-hero-name">Moderação Docente</h2>
                    <p style="font-size: 0.85rem; opacity: 0.95;">
                        Professores e administradores têm acesso a todas as denúncias detalhadas para julgar e aplicar punições regulamentares.
                    </p>
                </div>

                <?php if (empty($todasDenuncias)): ?>
                    <div class="alert alert-info">
                        <span></span>
                        <span>Nenhuma denúncia pendente de avaliação.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($todasDenuncias as $den): ?>
                        <div class="action-box" style="border-left: 4px solid <?= $den['status'] === 'aceita' ? 'var(--danger)' : ($den['status'] === 'rejeitada' ? 'var(--success)' : 'var(--accent)') ?>;">
                            
                            <div class="action-box-header">
                                <div>
                                    <h3 style="margin-bottom: 2px;">
                                         Acusada: <?= e($den['equipe_nome']) ?>
                                    </h3>
                                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                                        Protocolo #<?= (int)$den['id'] ?> • <?= formatDateTime($den['data_registro']) ?>
                                    </span>
                                </div>
                                <span class="badge-denuncia <?= e($den['status']) ?>">
                                    <?= e(ucfirst($den['status'])) ?>
                                </span>
                            </div>

                            <div style="background: #ECEFF1; padding: 8px 12px; border-radius: 6px; font-size: 0.78rem; margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span> <strong>Denunciante:</strong> <?= e($den['denunciante_nome']) ?> (<?= e($den['denunciante_turma'] ?: 'Aluno') ?>)</span>
                            </div>

                            <div style="font-size: 0.88rem; line-height: 1.5; background: #FAFAFA; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 12px;">
                                <strong style="display: block; font-size: 0.78rem; color: var(--text-muted); margin-bottom: 4px;">RELATO COMPLETO:</strong>
                                <?= nl2br(e($den['descricao'])) ?>
                            </div>

                            <?php if ($den['status'] === 'aceita' && $den['pontos_penalidade'] < 0): ?>
                                <div style="font-size:.8rem;color:var(--danger);font-weight:700;margin-bottom:10px">
                                    Penalidade aplicada: <?= (int)$den['pontos_penalidade'] ?> pontos.
                                </div>
                            <?php endif; ?>

                            <!-- Formulário de Julgamento para o Professor / Administrador -->
                            <details <?= $den['status'] === 'pendente' ? 'open' : '' ?>>
                                <summary style="font-size: 0.82rem; font-weight: 700; color: var(--primary); cursor: pointer; padding: 6px 0;">
                                     Emitir Julgamento / Aplicar Punição
                                </summary>
                                <form action="denuncias.php" method="POST" style="margin-top: 10px; background: #F5F6F8; padding: 12px; border-radius: 8px; display: flex; flex-direction: column; gap: 10px;">
                                    <input type="hidden" name="acao" value="julgar_denuncia">
                                    <input type="hidden" name="denuncia_id" value="<?= (int)$den['id'] ?>">

                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label class="form-label" style="font-size: 0.78rem;">Decisão da Comissão</label>
                                        <select name="novo_status" class="form-control julgamento-status-select" data-den-id="<?= (int)$den['id'] ?>" style="font-size: 0.85rem; padding: 8px;">
                                            <option value="pendente" <?= $den['status'] === 'pendente' ? 'selected' : '' ?>>⏱ Manter em Análise (Pendente)</option>
                                            <option value="aceita" <?= $den['status'] === 'aceita' ? 'selected' : '' ?>> Aceitar Denúncia (Aplicar Punição)</option>
                                            <option value="rejeitada" <?= $den['status'] === 'rejeitada' ? 'selected' : '' ?>> Rejeitar / Negar Denúncia</option>
                                        </select>
                                    </div>

                                    <div class="form-group pontos-penalidade-group" id="pontos-group-<?= (int)$den['id'] ?>" style="margin-bottom: 0; display: <?= $den['status'] === 'aceita' ? 'block' : 'none' ?>;">
                                        <label class="form-label" style="font-size: 0.78rem;"> Pontos a deduzir da equipe denunciada (deixe 0 para apenas advertência)</label>
                                        <input 
                                            type="number" 
                                            name="pontos_penalidade" 
                                            class="form-control" 
                                            id="pontos-input-<?= (int)$den['id'] ?>"
                                            value="<?= $den['pontos_penalidade'] != 0 ? abs((int)$den['pontos_penalidade']) : '' ?>"
                                            min="0"
                                            step="1"
                                            placeholder="Ex: 10 (a ser definido pela comissão)"
                                            style="font-size: 0.85rem; padding: 8px;"
                                        >
                                        <small style="color: var(--text-muted); font-size: 0.75rem;">O valor informado será descontado como pontuação negativa da equipe. Deixe em branco ou 0 para advertência sem desconto.</small>
                                    </div>

                                    <!-- Campo oculto para pontos quando não é aceita (envia 0) -->
                                    <input type="hidden" name="pontos_penalidade" id="pontos-hidden-<?= (int)$den['id'] ?>" value="0" <?= $den['status'] === 'aceita' ? 'disabled' : '' ?>>

                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <button type="submit" name="submit_aceitar" class="btn btn-primary btn-sm" style="flex: 1; min-width: 120px;">
                                             Gravar Decisão
                                        </button>
                                    </div>
                                </form>
                            </details>

                            <form action="denuncias.php" method="POST" style="margin-top:10px" onsubmit="return confirm('Excluir esta denúncia permanentemente?')">
                                <input type="hidden" name="acao" value="excluir_denuncia">
                                <input type="hidden" name="denuncia_id" value="<?= (int)$den['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background:var(--danger);color:#fff">Excluir denúncia</button>
                            </form>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

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
        <a href="denuncias.php" class="bottom-nav-item active">
            <span class="nav-icon"></span>
            <span>Denúncias</span>
        </a>
        <?php if (canManageSystem()): ?>
            <a href="admin.php" class="bottom-nav-item">
                <span class="nav-icon"></span>
                <span>Admin</span>
            </a>
        <?php else: ?>
            <a href="regulamento.php" class="bottom-nav-item">
                <span class="nav-icon"></span>
                <span>Regras</span>
            </a>
        <?php endif; ?>
    </nav>

    <script src="script.js"></script>
</body>
</html>
