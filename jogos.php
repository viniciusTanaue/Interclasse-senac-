<?php
require_once __DIR__ . '/db.php';
requireAuth();

$user = currentUser();
$pdo = getDBConnection();

// Guarda pontos atribuídos a cada equipe e mantém cada lançamento no histórico.
$pdo->exec("CREATE TABLE IF NOT EXISTS partida_pontos_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partida_id INT NOT NULL,
    equipe_id INT NOT NULL,
    pontos INT NOT NULL DEFAULT 0,
    registrado_por INT NULL,
    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (partida_id), INDEX (equipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$msgSucesso = $_SESSION['flash_success'] ?? null;
$msgErro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 1. AÇÃO: Atualizar Placar ou Encerrar Jogo (Professor & Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && in_array($_POST['acao'], ['atualizar_placar', 'encerrar_jogo'])) {
    if (!canEditScores()) {
        $msgErro = 'Apenas professores e administradores podem atualizar placares ou encerrar jogos.';
    } else {
        $partidaId = (int)($_POST['partida_id'] ?? 0);
        $golsCasa = (int)($_POST['gols_casa'] ?? 0);
        $golsVisitante = (int)($_POST['gols_visitante'] ?? 0);
        $pontosCasa = (int)($_POST['pontos_casa'] ?? 0);
        $pontosVisitante = (int)($_POST['pontos_visitante'] ?? 0);
        
        if ($_POST['acao'] === 'encerrar_jogo') {
            $status = 'finalizado';
        } else {
            $status = ($_POST['status'] ?? 'agendado') === 'finalizado' ? 'finalizado' : 'agendado';
        }

        if ($partidaId > 0) {
            $stmtTeams = $pdo->prepare('SELECT equipe_casa_id, equipe_visitante_id FROM partidas WHERE id = :id');
            $stmtTeams->execute([':id' => $partidaId]);
            $equipesPartida = $stmtTeams->fetch();
            $stmtUpdate = $pdo->prepare('UPDATE partidas SET gols_casa = :gc, gols_visitante = :gv, status = :st WHERE id = :id');
            $stmtUpdate->execute([
                ':gc' => $golsCasa,
                ':gv' => $golsVisitante,
                ':st' => $status,
                ':id' => $partidaId,
            ]);
            if ($status === 'finalizado' && $equipesPartida) {
                $stmtHistorico = $pdo->prepare('INSERT INTO partida_pontos_historico (partida_id, equipe_id, pontos, registrado_por) VALUES (:partida, :equipe, :pontos, :usuario)');
                $stmtHistorico->execute([':partida' => $partidaId, ':equipe' => $equipesPartida['equipe_casa_id'], ':pontos' => $pontosCasa, ':usuario' => $user['id']]);
                $stmtHistorico->execute([':partida' => $partidaId, ':equipe' => $equipesPartida['equipe_visitante_id'], ':pontos' => $pontosVisitante, ':usuario' => $user['id']]);
            }
            $msgSucesso = $status === 'finalizado' ? 'Partida encerrada com sucesso!' : 'Placar da partida atualizado com sucesso!';
        }
    }
}

// 2. AÇÕES DE CONFRONTOS (Professor & Admin: cadastrar e excluir confrontos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && in_array($_POST['acao'], ['agendar_partida', 'editar_confronto', 'excluir_confronto'])) {
    if (!isProfessor()) {
        $msgErro = 'Apenas professores e administradores podem cadastrar confrontos.';
    } else {
        if ($_POST['acao'] === 'agendar_partida') {
            $casaId = (int)($_POST['equipe_casa_id'] ?? 0);
            $visId = (int)($_POST['equipe_visitante_id'] ?? 0);
            $modalidade = trim($_POST['modalidade'] ?? '');
            $dataConfronto = trim($_POST['data_confronto'] ?? '');
            $dataHora = !empty($dataConfronto) ? $dataConfronto . ' 00:00:00' : date('Y-m-d H:i:s');
            $local = trim($_POST['local'] ?? '') ?: 'Senac Bauru';

            if ($casaId <= 0 || $visId <= 0 || $casaId === $visId || empty($modalidade)) {
                $msgErro = 'Preencha todos os campos corretamente. As equipes mandante e visitante devem ser diferentes.';
            } else {
                $stmtInsert = $pdo->prepare('INSERT INTO partidas (equipe_casa_id, equipe_visitante_id, modalidade, local, data_hora, status, gols_casa, gols_visitante) VALUES (:ec, :ev, :mod, :loc, :dh, "agendado", 0, 0)');
                $stmtInsert->execute([
                    ':ec'  => $casaId,
                    ':ev'  => $visId,
                    ':mod' => $modalidade,
                    ':loc' => $local,
                    ':dh'  => $dataHora,
                ]);
                $msgSucesso = 'Novo confronto adicionado com sucesso!';
            }
        } elseif ($_POST['acao'] === 'editar_confronto') {
            $partidaId = (int)($_POST['partida_id'] ?? 0);
            $modalidade = trim($_POST['modalidade'] ?? '');
            $dataConfronto = trim($_POST['data_confronto'] ?? '');

            if ($partidaId > 0 && !empty($modalidade)) {
                $dataHora = !empty($dataConfronto) ? $dataConfronto . ' 00:00:00' : date('Y-m-d H:i:s');
                $stmtUpd = $pdo->prepare('UPDATE partidas SET modalidade = :mod, data_hora = :dh WHERE id = :id');
                $stmtUpd->execute([
                    ':mod' => $modalidade,
                    ':dh'  => $dataHora,
                    ':id'  => $partidaId,
                ]);
                $msgSucesso = 'Confronto atualizado com sucesso!';
            }
        } elseif ($_POST['acao'] === 'excluir_confronto') {
            $partidaId = (int)($_POST['partida_id'] ?? 0);
            if ($partidaId > 0) {
                $stmtDel = $pdo->prepare('DELETE FROM partidas WHERE id = :id');
                $stmtDel->execute([':id' => $partidaId]);
                $msgSucesso = 'Confronto removido do calendário.';
            }
        }
    }
}

// Busca todas as equipes e modalidades para o formulário de confrontos (Professores e Admins)
$todasEquipes = [];
$todasModalidades = [];
if (isProfessor()) {
    $todasEquipes = $pdo->query('SELECT id, nome FROM equipes ORDER BY nome ASC')->fetchAll();
    $todasModalidades = $pdo->query('SELECT id, modalidade FROM pontuacao_modalidades WHERE pontos > 0 ORDER BY modalidade ASC')->fetchAll();
}

// Busca todas as partidas com detalhes das equipes
$query = "SELECT 
    p.*,
    ec.nome AS casa_nome, ec.logo_url AS casa_logo,
    ev.nome AS visitante_nome, ev.logo_url AS visitante_logo
    FROM partidas p
    JOIN equipes ec ON p.equipe_casa_id = ec.id
    JOIN equipes ev ON p.equipe_visitante_id = ev.id
    ORDER BY 
        CASE WHEN p.status = 'agendado' THEN 0 ELSE 1 END,
        p.data_hora ASC";

$stmt = $pdo->query($query);
$partidas = $stmt->fetchAll();
$pontosLancados = [];
$historicoPontos = [];
$stmtHistorico = $pdo->query('SELECT h.*, e.nome AS equipe_nome, u.nome AS usuario_nome FROM partida_pontos_historico h JOIN equipes e ON e.id = h.equipe_id LEFT JOIN usuarios u ON u.id = h.registrado_por ORDER BY h.id DESC');
foreach ($stmtHistorico->fetchAll() as $registroPonto) {
    $historicoPontos[$registroPonto['partida_id']][] = $registroPonto;
}
$stmtPontosLancados = $pdo->query('SELECT h.partida_id, h.equipe_id, h.pontos FROM partida_pontos_historico h INNER JOIN (SELECT partida_id, equipe_id, MAX(id) AS ultimo_id FROM partida_pontos_historico GROUP BY partida_id, equipe_id) u ON u.ultimo_id = h.id');
foreach ($stmtPontosLancados->fetchAll() as $lancamento) {
    $pontosLancados[$lancamento['partida_id']][$lancamento['equipe_id']] = (int)$lancamento['pontos'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jogos do Dia - Interclasse Senac Bauru 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/webp" href="assets/logo-senac-oficial.webp">
</head>
<body>

    <!-- Header Superior -->
    <header class="top-header">
        <div class="top-header-content">
            <div class="brand-wrapper">
                <a href="dashboard.php" style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.2rem; color: #1565C0;">‹</span>
            <img src="assets/logo-senac-oficial.webp" alt="Senac" class="brand-logo">
                </a>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <?= getRoleBadge($user['tipo']) ?>
            </div>
        </div>
    </header>

    <main class="app-container">

        <div class="user-hero-card" style="background: linear-gradient(135deg, #1565C0 0%, #2E7D32 100%);">
            <span class="user-hero-tag" style="background: #FFFFFF; color: #1565C0;">Ao Vivo & Cronograma</span>
            <h1 class="user-hero-name">Jogos do Interclasse</h1>
            <p style="font-size: 0.85rem; opacity: 0.95;">
                <?php if (isProfessor()): ?>
                    Acompanhe resultados, atualize súmulas, encerre jogos e adicione novos confrontos.
                <?php else: ?>
                    Acompanhe os resultados oficiais em tempo real e os jogos da sua turma.
                <?php endif; ?>
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

        <!-- PAINEL DE CONFRONTOS: PROFESSORES E ADMINISTRADORES -->
        <?php if (isProfessor()): ?>
            <div class="action-box" style="border-left: 4px solid var(--primary);">
                <details>
                    <summary style="font-weight: 800; color: var(--primary); cursor: pointer; font-size: 0.92rem; display: flex; align-items: center; justify-content: space-between;">
                        <span> + Adicionar Novo Confronto</span>
                        <span style="font-size: 0.8rem; background: var(--primary-light); padding: 2px 8px; border-radius: 12px;">Criar Jogo</span>
                    </summary>
                    <form action="jogos.php" method="POST" style="margin-top: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <input type="hidden" name="acao" value="agendar_partida">
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label class="form-label" style="font-size: 0.78rem;">Equipe Mandante</label>
                                <select name="equipe_casa_id" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($todasEquipes as $eq): ?>
                                        <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" style="font-size: 0.78rem;">Equipe Visitante</label>
                                <select name="equipe_visitante_id" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($todasEquipes as $eq): ?>
                                        <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group" style="margin-bottom: 6px;">
                                <label class="form-label" style="font-size: 0.78rem;">Modalidade do Confronto</label>
                                <input type="text" name="modalidade" list="modalidades_list" class="form-control" placeholder="Ex: Futsal" required style="font-size: 0.85rem; padding: 8px;">
                                <datalist id="modalidades_list">
                                    <?php foreach ($todasModalidades as $m): ?>
                                        <option value="<?= e($m['modalidade']) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group" style="margin-bottom: 6px;">
                                <label class="form-label" style="font-size: 0.78rem;">Data do Confronto</label>
                                <input type="date" name="data_confronto" class="form-control" value="<?= date('Y-m-d') ?>" required style="font-size: 0.85rem; padding: 8px;">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; margin-top: 6px;">
                            Confirmar & Publicar Confronto
                        </button>
                    </form>
                </details>
            </div>
        <?php endif; ?>

        <!-- Chips de Filtro Rápido Mobile -->
        <div class="filter-chips">
            <button type="button" class="chip active" data-filter="todos">Todos</button>
            <button type="button" class="chip" data-filter="agendado">Ainda por Acontecer</button>
            <button type="button" class="chip" data-filter="finalizado">Finalizados</button>
            <button type="button" class="chip" data-filter="futsal">Futsal</button>
            <button type="button" class="chip" data-filter="basquete">Basquete 3x3</button>
            <button type="button" class="chip" data-filter="voleibol">Voleibol</button>
            <button type="button" class="chip" data-filter="queimada">Queimada</button>
            <button type="button" class="chip" data-filter="naruto">Naruto Storm</button>
            <button type="button" class="chip" data-filter="dance">Just Dance</button>
        </div>

        <!-- Lista Vertical de Cards de Partida -->
        <div class="matches-list">
            <?php if (empty($partidas)): ?>
                <div class="alert alert-info">
                    <span>ℹ</span>
                    <span>Nenhuma partida cadastrada no sistema.</span>
                </div>
            <?php else: ?>
                <?php foreach ($partidas as $partida): ?>
                    <div class="match-card" data-status="<?= e($partida['status']) ?>" data-modality="<?= e(strtolower($partida['modalidade'])) ?>">
                        
                        <!-- Cabeçalho do Card -->
                        <div class="match-header">
                            <span class="match-modality">
                                 <?= e($partida['modalidade']) ?>
                            </span>
                            <?php if ($partida['status'] === 'finalizado'): ?>
                                <span class="badge-status badge-finalizado">Finalizado</span>
                            <?php else: ?>
                                <span class="badge-status badge-agendado">Ainda por acontecer</span>
                            <?php endif; ?>
                        </div>

                        <!-- Corpo com Logos e Placar -->
                        <div class="match-body">
                            
                            <!-- Mandante -->
                            <div class="team-box">
                                <img src="<?= e($partida['casa_logo'] ?: 'assets/teams/athena.svg') ?>" alt="<?= e($partida['casa_nome']) ?>" class="team-logo-img">
                                <span class="team-name"><?= e($partida['casa_nome']) ?></span>
                            </div>

                            <!-- Placar Central -->
                            <div class="score-box">
                                <?php if ($partida['status'] === 'finalizado'): ?>
                                    <div class="score-numbers">
                                        <span><?= (int)$partida['gols_casa'] ?></span>
                                        <span style="font-size: 1.1rem; color: var(--text-muted); font-weight: 400;">x</span>
                                        <span><?= (int)$partida['gols_visitante'] ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="score-numbers" style="font-size: 1.3rem;">
                                        <span class="vs-badge">VS</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Visitante -->
                            <div class="team-box">
                                <img src="<?= e($partida['visitante_logo'] ?: 'assets/teams/fenix.svg') ?>" alt="<?= e($partida['visitante_nome']) ?>" class="team-logo-img">
                                <span class="team-name"><?= e($partida['visitante_nome']) ?></span>
                            </div>

                        </div>

                        <!-- Data do Confronto -->
                        <div class="match-footer" style="justify-content: center; padding: 6px 12px; margin-top: 10px;">
                            <div class="match-info-item">
                                <span><strong>Data:</strong></span>
                                <span><?= formatDate($partida['data_hora']) ?></span>
                            </div>
                        </div>

                        <!-- GESTÃO OPERACIONAL DO PROFESSOR E ADMINISTRADOR -->
                        <?php if (canEditScores()): ?>
                            <details style="margin-top: 12px; border-top: 1px dashed var(--border-color); padding-top: 10px;">
                                <summary style="font-size: 0.78rem; color: #1565C0; cursor: pointer; font-weight: 700; display: flex; align-items: center; justify-content: space-between;">
                                    <span> Painel do Árbitro / Professor (Placar & Súmula)</span>
                                    <span style="font-size: 0.7rem; color: var(--text-muted);">Editar</span>
                                </summary>
                                
                                <form action="jogos.php" method="POST" style="margin-top: 10px; background: #FAFAFA; padding: 10px; border-radius: 8px;">
                                    <input type="hidden" name="partida_id" value="<?= (int)$partida['id'] ?>">
                                    
                                    <div style="display: flex; gap: 8px; align-items: center; justify-content: center; margin-bottom: 10px;">
                                        <div style="text-align: center;">
                                            <span style="font-size: 0.72rem; display: block; color: var(--text-muted);"><?= e(explode(' ', $partida['casa_nome'])[0]) ?></span>
                                            <input type="number" name="gols_casa" value="<?= (int)$partida['gols_casa'] ?>" class="form-control" style="width: 60px; padding: 6px; text-align: center; font-weight: 800; font-size: 1rem;" required min="0">
                                        </div>
                                        <span style="font-weight: 800; color: var(--text-muted);">x</span>
                                        <div style="text-align: center;">
                                            <span style="font-size: 0.72rem; display: block; color: var(--text-muted);"><?= e(explode(' ', $partida['visitante_nome'])[0]) ?></span>
                                            <input type="number" name="gols_visitante" value="<?= (int)$partida['gols_visitante'] ?>" class="form-control" style="width: 60px; padding: 6px; text-align: center; font-weight: 800; font-size: 1rem;" required min="0">
                                        </div>
                                        <div style="text-align: center; margin-left: 6px;">
                                            <span style="font-size: 0.72rem; display: block; color: var(--text-muted);">Status</span>
                                            <select name="status" class="form-control" style="width: auto; padding: 6px; font-size: 0.8rem;">
                                                <option value="agendado" <?= $partida['status'] === 'agendado' ? 'selected' : '' ?>>Agendado</option>
                                                <option value="finalizado" <?= $partida['status'] === 'finalizado' ? 'selected' : '' ?>>Finalizado</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
                                        <div class="form-group" style="margin:0">
                                            <label class="form-label" style="font-size:.75rem">Pontos da equipe mandante</label>
                                            <input type="number" name="pontos_casa" value="<?= $pontosLancados[$partida['id']][$partida['equipe_casa_id']] ?? 0 ?>" class="form-control" required>
                                        </div>
                                        <div class="form-group" style="margin:0">
                                            <label class="form-label" style="font-size:.75rem">Pontos da equipe visitante</label>
                                            <input type="number" name="pontos_visitante" value="<?= $pontosLancados[$partida['id']][$partida['equipe_visitante_id']] ?? 0 ?>" class="form-control" required>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <button type="submit" name="acao" value="atualizar_placar" class="btn btn-sm btn-primary" style="flex: 1;">
                                             Salvar Placar
                                        </button>
                                        
                                        <?php if ($partida['status'] !== 'finalizado'): ?>
                                            <button type="submit" name="acao" value="encerrar_jogo" class="btn btn-sm btn-accent" style="flex: 1;" onclick="return confirm('Deseja encerrar este jogo e consolidar o placar na tabela de classificação?')">
                                                 Encerrar Jogo
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="acao" value="atualizar_placar" class="btn btn-sm btn-outline" style="flex: 1;">
                                                 Reabrir Partida
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <!-- Opções do confronto (Professor e Administrador) -->
                                <?php if (isProfessor()): ?>
                                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dotted #ccc; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 0.72rem; color: #5E35B1; font-weight: 700;"> Opções do Confronto</span>
                                        <form action="jogos.php" method="POST" onsubmit="return confirm('Tem certeza que deseja excluir este confronto do cronograma?')">
                                            <input type="hidden" name="acao" value="excluir_confronto">
                                            <input type="hidden" name="partida_id" value="<?= (int)$partida['id'] ?>">
                                            <button type="submit" class="btn btn-sm" style="background: none; color: var(--danger); padding: 2px 6px; font-size: 0.75rem; border: none; cursor: pointer;">
                                                 Excluir Confronto
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                            </details>
                        <?php endif; ?>

                        <?php if (!empty($historicoPontos[$partida['id']])): ?>
                            <details style="margin-top:10px">
                                <summary style="font-size:.78rem;cursor:pointer;font-weight:700">Histórico de pontos</summary>
                                <ul style="font-size:.75rem;margin:8px 0;padding-left:18px">
                                    <?php foreach ($historicoPontos[$partida['id']] as $registro): ?>
                                        <li><?= e($registro['equipe_nome']) ?>: <?= (int)$registro['pontos'] ?> pontos, <?= e(formatDate($registro['data_registro'])) ?><?= $registro['usuario_nome'] ? ' por ' . e($registro['usuario_nome']) : '' ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item">
            <span class="nav-icon"></span>
            <span>Início</span>
        </a>
        <a href="jogos.php" class="bottom-nav-item active">
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
