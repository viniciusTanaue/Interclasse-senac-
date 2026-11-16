<?php
require_once __DIR__ . '/db.php';
requireAuth();

$user = currentUser();
$pdo = getDBConnection();

$msgSucesso = $_SESSION['flash_success'] ?? null;
$msgErro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 1. Total de jogos finalizados e agendados
$stmtStats = $pdo->query("SELECT 
    COUNT(*) as total_jogos,
    SUM(CASE WHEN status = 'finalizado' THEN 1 ELSE 0 END) as jogos_finalizados,
    SUM(CASE WHEN status = 'agendado' THEN 1 ELSE 0 END) as jogos_agendados
    FROM partidas");
$stats = $stmtStats->fetch() ?: ['total_jogos' => 0, 'jogos_finalizados' => 0, 'jogos_agendados' => 0];

// 2. Denúncias pendentes (para professores e admin)
$denunciasPendentes = 0;
if (canModerateDenuncias()) {
    $stmtPend = $pdo->query("SELECT COUNT(*) as pendentes FROM denuncias WHERE status = 'pendente'");
    $denunciasPendentes = (int)($stmtPend->fetch()['pendentes'] ?? 0);
}

// 3. Próximo jogo agendado
$stmtNext = $pdo->query("SELECT p.*, ec.nome as casa_nome, ec.logo_url as casa_logo, ev.nome as visitante_nome, ev.logo_url as visitante_logo
    FROM partidas p
    JOIN equipes ec ON p.equipe_casa_id = ec.id
    JOIN equipes ev ON p.equipe_visitante_id = ev.id
    WHERE p.status = 'agendado'
    ORDER BY p.data_hora ASC
    LIMIT 1");
$proximoJogo = $stmtNext->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Interclasse 2026 - Senac Bauru</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/webp" href="assets/logo-senac-oficial.webp">
</head>
<body>

    <!-- Header Superior com Logo e Perfil -->
    <header class="top-header">
        <div class="top-header-content">
            <div class="brand-wrapper">
                <img src="assets/logo-senac-oficial.webp" alt="Senac Bauru" class="brand-logo">
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?= getRoleBadge($user['tipo']) ?>
                <a href="logout.php" class="btn-logout-icon" title="Encerrar Sessão">
                    🚪
                </a>
            </div>
        </div>
    </header>

    <!-- Conteúdo Principal Vertical Mobile First -->
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
        
        <!-- Cartão Hero com Dados do Usuário e Papel -->
        <section class="user-hero-card">
            <span class="user-hero-tag tag-<?= e($user['tipo']) ?>">
                <?php if (isAdmin()): ?>
                     Administrador Geral
                <?php elseif (isProfessor()): ?>
                     Corpo Docente / Arbitragem
                <?php else: ?>
                     Atleta / Torcedor
                <?php endif; ?>
            </span>
            <h1 class="user-hero-name">Olá, <?= e($user['nome']) ?>!</h1>
            <div class="user-hero-meta">
                <span> <strong>Turma:</strong> <?= e($user['turma'] ?: 'Geral') ?></span>
                <?php if (!empty($user['responsavel'])): ?>
                    <span> <strong>Resp.:</strong> <?= e($user['responsavel']) ?></span>
                <?php endif; ?>
            </div>
        </section>

        <!-- Alerta de Denúncias Pendentes para Professores e Admin -->
        <?php if (canModerateDenuncias() && $denunciasPendentes > 0): ?>
            <div class="alert" style="background: #FFF3E0; color: #E65100; border-left: 4px solid #F57C00;">
                <span></span>
                <div style="flex: 1;">
                    <strong><?= $denunciasPendentes ?> denúncia(s) pendente(s)</strong> aguardando julgamento da comissão.
                    <a href="denuncias.php" style="color: #1565C0; text-decoration: underline; font-weight: 700; display: block; margin-top: 2px;">
                        Avaliar Denúncias
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Resumo Rápido em Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value"><?= (int)$stats['jogos_finalizados'] ?></div>
                <div class="stat-label">Jogos Realizados</div>
            </div>
            <div class="stat-card accent">
                <div class="stat-value"><?= (int)$stats['jogos_agendados'] ?></div>
                <div class="stat-label">Jogos Agendados</div>
            </div>
        </div>

        <?php if ($proximoJogo): ?>
            <!-- Destaque do Próximo Jogo -->
            <div style="margin-bottom: 24px;">
                <h2 class="section-title"> Próxima Partida</h2>
                <div class="match-card">
                    <div class="match-header">
                        <span class="match-modality"> <?= e($proximoJogo['modalidade']) ?></span>
                        <span class="badge-status badge-agendado">Em Breve</span>
                    </div>
                    <div class="match-body">
                        <div class="team-box">
                            <img src="<?= e($proximoJogo['casa_logo'] ?: 'assets/teams/athena.svg') ?>" alt="<?= e($proximoJogo['casa_nome']) ?>" class="team-logo-img">
                            <span class="team-name"><?= e($proximoJogo['casa_nome']) ?></span>
                        </div>
                        <div class="score-box">
                            <span class="vs-badge">VS</span>
                        </div>
                        <div class="team-box">
                            <img src="<?= e($proximoJogo['visitante_logo'] ?: 'assets/teams/fenix.svg') ?>" alt="<?= e($proximoJogo['visitante_nome']) ?>" class="team-logo-img">
                            <span class="team-name"><?= e($proximoJogo['visitante_nome']) ?></span>
                        </div>
                    </div>
                    <!-- Data do Confronto -->
                    <div class="match-footer" style="justify-content: center; padding: 6px 12px; margin-top: 10px;">
                        <div class="match-info-item">
                            <span></span>
                            <span><strong>Data:</strong> <?= formatDate($proximoJogo['data_hora']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navegação Principal Vertical (Layout Celular) -->
        <h2 class="section-title"> Menu Principal</h2>
        <nav class="vertical-menu-grid">
            
            <!-- Painel de Gestão do Administrador -->
            <?php if (isAdmin()): ?>
                <a href="admin.php" class="nav-card-btn" style="border-left: 4px solid #5E35B1;">
                    <div class="nav-card-left">
                        <div class="nav-card-icon" style="background: #EDE7F6; color: #5E35B1;">
                            ⚡
                        </div>
                        <div class="nav-card-info">
                            <h3>Painel de Gestão</h3>
                            <p>Adicionar equipes, confrontos e gerenciar o torneio</p>
                        </div>
                    </div>
                    <span class="nav-card-arrow">➔</span>
                </a>
            <?php endif; ?>

            <!-- 1. Jogos do Dia -->
            <a href="jogos.php" class="nav-card-btn">
                <div class="nav-card-left">
                    <div class="nav-card-icon icon-green">
                        📅
                    </div>
                    <div class="nav-card-info">
                        <h3>Jogos & Resultados</h3>
                        <p><?= canEditScores() ? 'Acompanhar, atualizar placares e encerrar jogos' : 'Partidas, confrontos e resultados oficiais' ?></p>
                    </div>
                </div>
                <span class="nav-card-arrow">➔</span>
            </a>

            <!-- 2. Pontuação e Classificação -->
            <a href="pontuacao.php" class="nav-card-btn">
                <div class="nav-card-left">
                    <div class="nav-card-icon icon-purple">
                        🏆
                    </div>
                    <div class="nav-card-info">
                        <h3>Pontuação & Modalidades</h3>
                        <p><?= canAdjustTable() ? 'Tabela geral, pontos por modalidade e ajuste manual' : 'Classificação e pontos que as equipes acumularam' ?></p>
                    </div>
                </div>
                <span class="nav-card-arrow">➔</span>
            </a>

            <!-- 3. Canal de Denúncias -->
            <a href="denuncias.php" class="nav-card-btn">
                <div class="nav-card-left">
                    <div class="nav-card-icon icon-orange">
                        🛡️
                    </div>
                    <div class="nav-card-info">
                        <h3>Canal de Denúncias (Fair Play)</h3>
                        <p><?= canModerateDenuncias() ? 'Julgar ocorrências e aplicar penalidades de pontos' : 'Registrar denúncia e quantitativo contra sua equipe' ?></p>
                    </div>
                </div>
                <span class="nav-card-arrow">➔</span>
            </a>

            <!-- 4. Regulamento -->
            <a href="regulamento.php" class="nav-card-btn">
                <div class="nav-card-left">
                    <div class="nav-card-icon icon-blue">
                        📄
                    </div>
                    <div class="nav-card-info">
                        <h3>Regulamento Oficial</h3>
                <p>Baixe o regulamento completo do evento</p>
                    </div>
                </div>
                <span class="nav-card-arrow">➔</span>
            </a>

        </nav>

    </main>

    <!-- Barra de Navegação Inferior Fixa para Mobile -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item active">
            <span class="nav-icon">🏠</span>
            <span>Início</span>
        </a>
        <a href="jogos.php" class="bottom-nav-item">
            <span class="nav-icon">📅</span>
            <span>Jogos</span>
        </a>
        <a href="pontuacao.php" class="bottom-nav-item">
            <span class="nav-icon">🏆</span>
            <span>Tabela</span>
        </a>
        <a href="denuncias.php" class="bottom-nav-item">
            <span class="nav-icon">🛡️</span>
            <span>Denúncias</span>
        </a>
        <?php if (canManageSystem()): ?>
            <a href="admin.php" class="bottom-nav-item">
                <span class="nav-icon">⚡</span>
                <span>Admin</span>
            </a>
        <?php else: ?>
            <a href="regulamento.php" class="bottom-nav-item">
                <span class="nav-icon">📄</span>
                <span>Regras</span>
            </a>
        <?php endif; ?>
    </nav>

    <script src="script.js"></script>
</body>
</html>
