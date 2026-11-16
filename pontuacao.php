<?php
require_once __DIR__ . '/db.php';
requireAuth();

$user = currentUser();
$pdo = getDBConnection();

$msgSucesso = $_SESSION['flash_success'] ?? null;
$msgErro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 1. Processamento de Ajuste Manual da Tabela (Professor e Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && canAdjustTable()) {
    if ($_POST['acao'] === 'ajuste_manual_tabela') {
        $equipeId = (int)($_POST['equipe_id'] ?? 0);
        $pontos = (int)($_POST['pontos'] ?? 0);
        $modalidadeId = !empty($_POST['modalidade_id']) ? (int)$_POST['modalidade_id'] : null;
        $motivo = trim($_POST['motivo'] ?? '');

        if ($equipeId <= 0 || $pontos === 0) {
            $msgErro = 'Selecione a equipe e defina a pontuação (diferente de zero).';
        } else {
            // Se o usuário selecionou 'Geral', busca um ID de modalidade padrão caso o banco exija NOT NULL
            $fallbackModId = $modalidadeId;
            if ($fallbackModId === null) {
                $stmtFirstMod = $pdo->query('SELECT id FROM pontuacao_modalidades ORDER BY id ASC LIMIT 1');
                $fallbackModId = (int)($stmtFirstMod ? $stmtFirstMod->fetchColumn() : 1) ?: 1;
            }

            // Tenta inserir considerando possíveis variações da estrutura da tabela no MySQL
            $tentativas = [
                // 1. Completo: com modalidadeId (pode ser null) e com criado_por
                ['sql' => 'INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo, criado_por) VALUES (:eq, :mod, :pts, :motivo, :criador)', 'params' => [':eq' => $equipeId, ':mod' => $modalidadeId, ':pts' => $pontos, ':motivo' => $motivo, ':criador' => $user['id']]],
                // 2. Sem criado_por (se a coluna não existir)
                ['sql' => 'INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo) VALUES (:eq, :mod, :pts, :motivo)', 'params' => [':eq' => $equipeId, ':mod' => $modalidadeId, ':pts' => $pontos, ':motivo' => $motivo]],
                // 3. Com fallback de modalidade (caso modalidade_id seja NOT NULL no banco) e criado_por
                ['sql' => 'INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo, criado_por) VALUES (:eq, :mod, :pts, :motivo, :criador)', 'params' => [':eq' => $equipeId, ':mod' => $fallbackModId, ':pts' => $pontos, ':motivo' => $motivo, ':criador' => $user['id']]],
                // 4. Com fallback de modalidade e sem criado_por
                ['sql' => 'INSERT INTO equipe_pontos_extras (equipe_id, modalidade_id, pontos, motivo) VALUES (:eq, :mod, :pts, :motivo)', 'params' => [':eq' => $equipeId, ':mod' => $fallbackModId, ':pts' => $pontos, ':motivo' => $motivo]],
            ];

            $inserted = false;
            foreach ($tentativas as $t) {
                try {
                    $stmtAjuste = $pdo->prepare($t['sql']);
                    $stmtAjuste->execute($t['params']);
                    $inserted = true;
                    break;
                } catch (PDOException $e) {
                    continue;
                }
            }

            if ($inserted) {
                $msgSucesso = "Tabela ajustada com sucesso! {$pontos} ponto(s) aplicados à equipe.";
            } else {
                $msgErro = "Não foi possível salvar o ajuste de pontos no banco de dados.";
            }
        }
    } elseif ($_POST['acao'] === 'excluir_ajuste') {
        $ajusteId = (int)($_POST['ajuste_id'] ?? 0);
        if ($ajusteId > 0) {
            $stmtDel = $pdo->prepare('DELETE FROM equipe_pontos_extras WHERE id = :id');
            $stmtDel->execute([':id' => $ajusteId]);
            $msgSucesso = 'Ajuste manual removido e tabela recalculada.';
        }
    }
}

// 2. Busca todas as modalidades oficiais
$stmtMod = $pdo->query('SELECT * FROM pontuacao_modalidades ORDER BY id ASC');
$modalidades = $stmtMod->fetchAll();

// 3. Busca todas as equipes
$stmtEq = $pdo->query('SELECT * FROM equipes ORDER BY id ASC');
$equipes = $stmtEq->fetchAll();

// 4. Inicializa array de classificação por equipe
$tabelaClassificacao = [];
$pontosPorModalidadeEquipe = [];

foreach ($equipes as $eq) {
    $tabelaClassificacao[$eq['id']] = [
        'id'           => $eq['id'],
        'nome'         => $eq['nome'],
        'logo_url'     => $eq['logo_url'],
        'jogos'        => 0,
        'vitorias'     => 0,
        'empates'      => 0,
        'derrotas'     => 0,
        'gols_pro'     => 0,
        'gols_contra'  => 0,
        'saldo_gols'   => 0,
        'pontos_jogos' => 0,
        'pontos_extras'=> 0,
        'pontos_total' => 0,
    ];
    $pontosPorModalidadeEquipe[$eq['id']] = [];
}

// 5. Calcula estatísticas das partidas e soma os pontos informados no encerramento.
$pdo->exec("CREATE TABLE IF NOT EXISTS partida_pontos_historico (
    id INT AUTO_INCREMENT PRIMARY KEY, partida_id INT NOT NULL, equipe_id INT NOT NULL,
    pontos INT NOT NULL DEFAULT 0, registrado_por INT NULL,
    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (partida_id), INDEX (equipe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$stmtHistoricoPontos = $pdo->query('SELECT h.partida_id, h.equipe_id, h.pontos FROM partida_pontos_historico h INNER JOIN (SELECT partida_id, equipe_id, MAX(id) AS ultimo_id FROM partida_pontos_historico GROUP BY partida_id, equipe_id) u ON u.ultimo_id = h.id');
$pontosPorPartida = [];
foreach ($stmtHistoricoPontos->fetchAll() as $lancamento) {
    $pontosPorPartida[$lancamento['partida_id']][$lancamento['equipe_id']] = (int)$lancamento['pontos'];
}
$stmtPartidas = $pdo->query("SELECT * FROM partidas WHERE status = 'finalizado'");
$partidasFinalizadas = $stmtPartidas->fetchAll();

foreach ($partidasFinalizadas as $p) {
    $casaId = $p['equipe_casa_id'];
    $visId  = $p['equipe_visitante_id'];
    $gc     = (int)$p['gols_casa'];
    $gv     = (int)$p['gols_visitante'];
    $mod    = $p['modalidade'];

    if (isset($tabelaClassificacao[$casaId]) && isset($tabelaClassificacao[$visId])) {
        $tabelaClassificacao[$casaId]['jogos']++;
        $tabelaClassificacao[$visId]['jogos']++;

        $tabelaClassificacao[$casaId]['gols_pro'] += $gc;
        $tabelaClassificacao[$casaId]['gols_contra'] += $gv;

        $tabelaClassificacao[$visId]['gols_pro'] += $gv;
        $tabelaClassificacao[$visId]['gols_contra'] += $gc;

        $ptsCasa = 0;
        $ptsVis  = 0;

        if ($gc > $gv) {
            $tabelaClassificacao[$casaId]['vitorias']++;
            $tabelaClassificacao[$visId]['derrotas']++;
            $ptsCasa = 0;
        } elseif ($gv > $gc) {
            $tabelaClassificacao[$visId]['vitorias']++;
            $tabelaClassificacao[$casaId]['derrotas']++;
            $ptsVis = 0;
        } else {
            $tabelaClassificacao[$casaId]['empates']++;
            $tabelaClassificacao[$visId]['empates']++;
            $ptsCasa = 0;
            $ptsVis  = 0;
        }

        $ptsCasa = $pontosPorPartida[$p['id']][$casaId] ?? 0;
        $ptsVis = $pontosPorPartida[$p['id']][$visId] ?? 0;
        $tabelaClassificacao[$casaId]['pontos_jogos'] += $ptsCasa;
        $tabelaClassificacao[$visId]['pontos_jogos'] += $ptsVis;

        // Acumula pontos por modalidade esportiva
        $pontosPorModalidadeEquipe[$casaId][$mod] = ($pontosPorModalidadeEquipe[$casaId][$mod] ?? 0) + $ptsCasa;
        $pontosPorModalidadeEquipe[$visId][$mod]  = ($pontosPorModalidadeEquipe[$visId][$mod] ?? 0) + $ptsVis;
    }
}

// 6. Soma pontos extras / ajustes manuais / gincanas / penalidades
$ajustesManuaisLista = [];
try {
    $stmtExtras = $pdo->query("SELECT epe.*, eq.nome as equipe_nome, pm.modalidade as modalidade_nome, u.nome as criador_nome 
        FROM equipe_pontos_extras epe 
        JOIN equipes eq ON epe.equipe_id = eq.id 
        LEFT JOIN pontuacao_modalidades pm ON epe.modalidade_id = pm.id 
        LEFT JOIN usuarios u ON epe.criado_por = u.id 
        ORDER BY epe.id DESC");
    $ajustesManuaisLista = $stmtExtras->fetchAll();
} catch (Exception $e) {
    try {
        $stmtExtras = $pdo->query("SELECT epe.*, eq.nome as equipe_nome, pm.modalidade as modalidade_nome, 'Corpo Docente' as criador_nome 
            FROM equipe_pontos_extras epe 
            JOIN equipes eq ON epe.equipe_id = eq.id 
            LEFT JOIN pontuacao_modalidades pm ON epe.modalidade_id = pm.id 
            ORDER BY epe.id DESC");
        $ajustesManuaisLista = $stmtExtras->fetchAll();
    } catch (Exception $e2) {
        $ajustesManuaisLista = [];
    }
}

foreach ($ajustesManuaisLista as $row) {
    $eqId = $row['equipe_id'];
    if (isset($tabelaClassificacao[$eqId])) {
        $tabelaClassificacao[$eqId]['pontos_extras'] += (int)$row['pontos'];
        $categoriaNome = $row['modalidade_nome'] ?: ($row['motivo'] ?: 'Ajuste Manual Docente');
        $pontosPorModalidadeEquipe[$eqId][$categoriaNome] = ($pontosPorModalidadeEquipe[$eqId][$categoriaNome] ?? 0) + (int)$row['pontos'];
    }
}

// 7. Consolida saldo de gols e pontuação total
foreach ($tabelaClassificacao as &$item) {
    $item['saldo_gols'] = $item['gols_pro'] - $item['gols_contra'];
    $item['pontos_total'] = $item['pontos_jogos'] + $item['pontos_extras'];
}
unset($item);

// 8. Ordena classificação geral
usort($tabelaClassificacao, function ($a, $b) {
    if ($a['pontos_total'] !== $b['pontos_total']) {
        return $b['pontos_total'] <=> $a['pontos_total'];
    }
    if ($a['vitorias'] !== $b['vitorias']) {
        return $b['vitorias'] <=> $a['vitorias'];
    }
    if ($a['saldo_gols'] !== $b['saldo_gols']) {
        return $b['saldo_gols'] <=> $a['saldo_gols'];
    }
    return $b['gols_pro'] <=> $a['gols_pro'];
});
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pontuação & Classificação - Interclasse Senac Bauru 2026</title>
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

        <!-- Hero Card -->
        <div class="user-hero-card" style="background: linear-gradient(135deg, #5E35B1 0%, #1565C0 100%);">
            <span class="user-hero-tag" style="background: #FFD54F; color: #263238;">Ranking Oficial</span>
            <h1 class="user-hero-name">Quadro de Medalhas</h1>
            <p style="font-size: 0.85rem; opacity: 0.95;">
                Acompanhe os pontos consolidados e o desempenho das turmas em cada modalidade esportiva e cultural.
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

        <!-- PAINEL EXCLUSIVO DO PROFESSOR E ADMINISTRADOR: AJUSTE MANUAL DA TABELA -->
        <?php if (canAdjustTable()): ?>
            <div class="action-box" style="border-left: 4px solid var(--accent);">
                <details>
                    <summary style="font-weight: 800; color: var(--accent); cursor: pointer; font-size: 0.92rem; display: flex; align-items: center; justify-content: space-between;">
                        <span> Ajustar Tabela Manualmente (Docente)</span>
                        <span style="font-size: 0.8rem; background: var(--accent-light); padding: 2px 8px; border-radius: 12px;">+ Pontos / Penalidades</span>
                    </summary>

                    <form action="pontuacao.php" method="POST" style="margin-top: 14px; display: flex; flex-direction: column; gap: 10px;">
                        <input type="hidden" name="acao" value="ajuste_manual_tabela">
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.78rem;">Selecione a Equipe</label>
                            <select name="equipe_id" class="form-control" required style="font-size: 0.85rem; padding: 8px;">
                                <option value="">Escolha a equipe para ajuste...</option>
                                <?php foreach ($equipes as $eq): ?>
                                    <option value="<?= (int)$eq['id'] ?>"><?= e($eq['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.78rem;">Pontos (+/-)</label>
                                <input type="number" name="pontos" class="form-control" placeholder="Ex: 50 ou -300" required style="font-size: 0.85rem; padding: 8px;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label" style="font-size: 0.78rem;">Modalidade / Categoria</label>
                                <select name="modalidade_id" id="select_modalidade" class="form-control" style="font-size: 0.85rem; padding: 8px;" onchange="atualizarMotivoAuto()">
                                    <option value="" data-label="Ajuste Geral">Geral / Ajuste de Súmula</option>
                                    <?php foreach ($modalidades as $mod): ?>
                                        <option value="<?= (int)$mod['id'] ?>" data-label="<?= e($mod['modalidade']) ?>"><?= e($mod['modalidade']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Motivo gerado automaticamente pela modalidade selecionada (oculto) -->
                        <input type="hidden" name="motivo" id="motivo_auto" value="Ajuste manual docente">

                        <button type="submit" class="btn btn-accent btn-sm" style="width: 100%; margin-top: 6px;">
                            Gravar Ajuste Manual na Tabela
                        </button>
                    </form>

                    <!-- Lista de Ajustes Manuais já Realizados -->
                    <?php if (!empty($ajustesManuaisLista)): ?>
                        <div style="margin-top: 16px; border-top: 1px dashed var(--border-color); padding-top: 10px;">
                            <strong style="font-size: 0.78rem; color: var(--text-muted); display: block; margin-bottom: 8px;">
                                 Histórico de Ajustes Manuais:
                            </strong>
                            <div style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px;">
                                <?php foreach ($ajustesManuaisLista as $aj): ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; background: #F5F6F8; padding: 6px 10px; border-radius: 6px; font-size: 0.75rem;">
                                        <div>
                                            <strong><?= e($aj['equipe_nome']) ?>:</strong>
                                            <span style="color: <?= $aj['pontos'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-weight: 700;">
                                                <?= $aj['pontos'] > 0 ? '+' : '' ?><?= (int)$aj['pontos'] ?> pts
                                            </span>
                                            <span style="color: var(--text-muted);">• <?= e($aj['motivo']) ?></span>
                                        </div>
                                        <form action="pontuacao.php" method="POST" style="margin: 0;" onsubmit="return confirm('Deseja excluir este ajuste manual?')">
                                            <input type="hidden" name="acao" value="excluir_ajuste">
                                            <input type="hidden" name="ajuste_id" value="<?= (int)$aj['id'] ?>">
                                            <button type="submit" style="background: none; border: none; color: var(--danger); cursor: pointer; font-size: 0.8rem;" title="Remover"></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </details>
            </div>
        <?php endif; ?>

        <!-- 1. Tabela de Classificação Geral -->
        <h2 class="section-title"> Classificação Geral</h2>
        <div class="ranking-table-card">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Equipe</th>
                            <th style="text-align: center;">Jogos</th>
                            <th style="text-align: center;">Pontos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tabelaClassificacao as $idx => $eqData): ?>
                            <?php $modalidadesEquipe = $pontosPorModalidadeEquipe[$eqData['id']] ?? []; ?>
                            <tr
                                style="cursor:pointer"
                                title="Clique para ver os detalhes da equipe"
                                onclick='openTeamModal(<?= json_encode([
                                    "nome" => $eqData["nome"],
                                    "logo" => $eqData["logo_url"],
                                    "pontos" => $eqData["pontos_total"],
                                    "jogos" => $eqData["jogos"],
                                    "vitorias" => $eqData["vitorias"],
                                    "empates" => $eqData["empates"],
                                    "derrotas" => $eqData["derrotas"],
                                    "pontos_extras" => $eqData["pontos_extras"],
                                    "modalidades_pontos" => $modalidadesEquipe
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            >
                                <td>
                                    <div class="team-cell">
                                        <img src="<?= e($eqData['logo_url'] ?: 'assets/teams/athena.svg') ?>" alt="<?= e($eqData['nome']) ?>" class="team-mini-logo">
                                        <span><?= e($eqData['nome']) ?></span>
                                    </div>
                                </td>
                                <td style="text-align: center; color: var(--text-muted);"><?= (int)$eqData['jogos'] ?></td>
                                <td style="text-align: center;">
                                    <span class="points-badge"><?= (int)$eqData['pontos_total'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Tabela Oficial de Pontos em Disputa por Modalidade -->
        <h2 class="section-title"> Pontos em Disputa por Modalidade</h2>
        <div class="ranking-table-card">
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Modalidade</th>
                            <th style="text-align: center;">Semifinal</th>
                            <th style="text-align: center;">Final</th>
                            <th style="text-align: center;">Campeão</th>
                            <th style="text-align: center;">Vice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Modalidades com estrutura eliminatória: semi +50, final +50, campeão +200, vice +100
                        $modalidadesEliminatorias = [
                            ['icon' => '', 'nome' => 'Futsal Masculino'],
                            ['icon' => '', 'nome' => 'Basquete 3x3'],
                            ['icon' => '', 'nome' => 'Voleibol Misto'],
                            ['icon' => '', 'nome' => 'Queimada Mista'],
                            ['icon' => '', 'nome' => 'Tênis de Mesa'],
                            ['icon' => '', 'nome' => 'Xadrez'],
                        ];
                        foreach ($modalidadesEliminatorias as $m): ?>
                        <tr>
                            <td><strong><?= $m['icon'] ?> <?= $m['nome'] ?></strong></td>
                            <td style="text-align: center;"><span style="color: var(--primary); font-weight: 700;">+50</span></td>
                            <td style="text-align: center;"><span style="color: var(--primary); font-weight: 700;">+50</span></td>
                            <td style="text-align: center;"><span style="background: #FFF8E1; color: #E65100; font-weight: 800; padding: 2px 8px; border-radius: 6px; font-size: 0.82rem;">+200</span></td>
                            <td style="text-align: center;"><span style="color: var(--text-muted); font-weight: 700;">+100</span></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background: #F0F4FF;">
                            <td><strong> Naruto Storm</strong></td>
                            <td style="text-align: center;" colspan="3"><span style="font-size: 0.78rem; color: var(--text-muted);">Vencer a semifinal</span> <span style="color: var(--primary); font-weight: 700;">+100 pts</span></td>
                            <td style="text-align: center;">—</td>
                        </tr>
                        <tr style="background: #F0F4FF;">
                            <td><strong> Just Dance</strong></td>
                            <td style="text-align: center;" colspan="3"><span style="font-size: 0.78rem; color: var(--text-muted);">Vencer a semifinal</span> <span style="color: var(--primary); font-weight: 700;">+100 pts</span></td>
                            <td style="text-align: center;">—</td>
                        </tr>
                        <tr>
                            <td><strong> Gincana de Linguagens</strong></td>
                            <td style="text-align: center;" colspan="2"><span style="font-size: 0.78rem; color: var(--text-muted);">1º → <b>+200</b> · 2º → +100 · 3º → +50</span></td>
                            <td style="text-align: center;"><span style="background: #FFF8E1; color: #E65100; font-weight: 800; padding: 2px 8px; border-radius: 6px; font-size: 0.82rem;">+200</span></td>
                            <td style="text-align: center;"><span style="color: var(--text-muted); font-weight: 700;">+100</span></td>
                        </tr>
                        <tr style="background: #F0F4FF;">
                            <td><strong> Apresentação Artística</strong></td>
                            <td style="text-align: center;" colspan="3"><span style="font-size: 0.78rem; color: var(--text-muted);">1º → <b>+400</b> · 2º → +200 · 3º → +150 · demais → +100</span></td>
                            <td style="text-align: center;">—</td>
                        </tr>
                        <tr>
                            <td><strong> FairPlay / Espírito Esportivo</strong></td>
                            <td style="text-align: center;" colspan="3"><span style="font-size: 0.78rem; color: var(--text-muted);">Equipe eleita pela comissão</span> <span style="color: var(--primary); font-weight: 700;">+300 pts</span></td>
                            <td style="text-align: center;">—</td>
                        </tr>
                        <tr style="background: #F0F4FF;">
                            <td><strong> Torcida</strong></td>
                            <td style="text-align: center;" colspan="3"><span style="font-size: 0.78rem; color: var(--text-muted);">Gritos (100) + Organização/Respeito (100) + FairPlay (100)</span></td>
                            <td style="text-align: center;"><span style="color: var(--primary); font-weight: 700;">+300</span></td>
                        </tr>
                        <tr style="border-top: 2px solid var(--danger);">
                            <td><strong style="color: var(--danger);"> Penalidades</strong></td>
                            <td style="text-align: center;" colspan="4"><span style="font-size: 0.78rem; color: var(--danger);">Leve −50 · Moderada −150 · Grave −300 · Gravíssima −500</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Interativo: Detalhes da Equipe Selecionada (Bottom Sheet Mobile) -->
    <div id="teamModalOverlay" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img id="modalTeamLogo" src="assets/logo-senac-oficial.webp" alt="Equipe" style="width: 44px; height: 44px; border-radius: 50%;">
                    <div>
                        <h3 id="modalTeamName" class="modal-title" style="font-size: 1.1rem; line-height: 1.2;">Nome da Equipe</h3>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Raio-X de Pontuação Consolidada</span>
                    </div>
                </div>
                <button type="button" id="modalCloseBtn" class="modal-close-btn">&times;</button>
            </div>

            <!-- Placar de Destaque no Modal -->
            <div style="background: var(--primary-light); border-radius: 12px; padding: 16px; text-align: center; margin-bottom: 20px;">
                <div style="font-size: 0.8rem; font-weight: 700; color: var(--primary); text-transform: uppercase;">Pontuação Geral Total</div>
                <div id="modalTeamPoints" style="font-size: 2.4rem; font-weight: 900; color: var(--primary); line-height: 1.1;">0</div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">pontos acumulados na competição</div>
            </div>

            <!-- SEÇÃO REQUISITO: PONTOS ACUMULADOS POR MODALIDADE -->
            <div style="margin-bottom: 20px;">
                <strong style="font-size: 0.85rem; color: var(--text-main); display: block; margin-bottom: 8px;">
                     Pontos Acumulados por Modalidade:
                </strong>
                <div id="modalTeamModalitiesList" style="display: flex; flex-direction: column; gap: 6px; max-height: 160px; overflow-y: auto;">
                    <!-- Preenchido dinamicamente via JS -->
                </div>
            </div>

            <!-- Grade de Métricas da Equipe -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px; text-align: center;">
                <div style="background: #F5F6F8; padding: 12px 8px; border-radius: 10px;">
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Jogos</div>
                    <div id="modalTeamGames" style="font-size: 1.25rem; font-weight: 800; color: var(--text-main);">0</div>
                </div>
                <div style="background: var(--success-bg); padding: 12px 8px; border-radius: 10px;">
                    <div style="font-size: 0.75rem; color: var(--success);">Vitórias</div>
                    <div id="modalTeamWins" style="font-size: 1.25rem; font-weight: 800; color: var(--success);">0</div>
                </div>
                <div style="background: #ECEFF1; padding: 12px 8px; border-radius: 10px;">
                    <div style="font-size: 0.75rem; color: #546E7A;">Empates</div>
                    <div id="modalTeamDraws" style="font-size: 1.25rem; font-weight: 800; color: #37474F;">0</div>
                </div>
                <div style="background: var(--danger-bg); padding: 12px 8px; border-radius: 10px;">
                    <div style="font-size: 0.75rem; color: var(--danger);">Derrotas</div>
                    <div id="modalTeamLosses" style="font-size: 1.25rem; font-weight: 800; color: var(--danger);">0</div>
                </div>
            </div>

            <button type="button" onclick="closeTeamModal()" class="btn btn-primary" style="margin-top: 24px;">
                Fechar Detalhes
            </button>
        </div>
    </div>

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
        <a href="pontuacao.php" class="bottom-nav-item active">
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
    <script>
        // Preenche o campo oculto "motivo" automaticamente com o nome da modalidade selecionada
        function atualizarMotivoAuto() {
            const sel = document.getElementById('select_modalidade');
            const opt = sel.options[sel.selectedIndex];
            const label = opt.getAttribute('data-label') || 'Ajuste manual docente';
            document.getElementById('motivo_auto').value = label;
        }
        // Inicializa ao carregar
        document.addEventListener('DOMContentLoaded', atualizarMotivoAuto);
    </script>
</body>
</html>
