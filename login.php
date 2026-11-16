<?php
require_once __DIR__ . '/db.php';

// Se já estiver logado, redireciona para a dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$erro = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);

// Processamento do Formulário de Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');

    if (empty($email) || empty($senha)) {
        $erro = 'Por favor, preencha todos os campos.';
    } else {
        $pdo = getDBConnection();
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $throttleKey = hash('sha256', strtolower($email) . '|' . $ip);
        $stmtThrottle = $pdo->prepare('SELECT tentativas, inicio_janela, bloqueado_ate, (bloqueado_ate > NOW()) AS esta_bloqueado FROM login_attempts WHERE chave = :chave');
        $stmtThrottle->execute([':chave' => $throttleKey]);
        $throttle = $stmtThrottle->fetch();

        if ($throttle && !empty($throttle['esta_bloqueado'])) {
            $erro = 'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.';
        } else {
            $stmt = $pdo->prepare('SELECT id, nome, email, senha, turma, tipo, ativo, responsavel FROM usuarios WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $usuario = $stmt->fetch();
            $storedHash = (string)($usuario['senha'] ?? '');
            $legacyHash = strlen($storedHash) === 40 && ctype_xdigit($storedHash);
            $senhaValida = $usuario && (int)$usuario['ativo'] === 1 && (password_verify($senha, $storedHash)
                || ($legacyHash && hash_equals(strtolower($storedHash), sha1($senha))));

            if ($senhaValida) {
                if ($legacyHash) {
                    $upgrade = $pdo->prepare('UPDATE usuarios SET senha = :senha WHERE id = :id');
                    $upgrade->execute([':senha' => password_hash($senha, PASSWORD_DEFAULT), ':id' => $usuario['id']]);
                }
                $pdo->prepare('DELETE FROM login_attempts WHERE chave = :chave')->execute([':chave' => $throttleKey]);
                session_regenerate_id(true);
                unset($_SESSION['_csrf_token']);
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_email'] = $usuario['email'];
                $_SESSION['usuario_turma'] = $usuario['turma'];
                $_SESSION['usuario_tipo'] = $usuario['tipo'];
                $_SESSION['usuario_responsavel'] = $usuario['responsavel'];
                header('Location: dashboard.php');
                exit;
            }

            $upsert = $pdo->prepare("INSERT INTO login_attempts (chave, tentativas, inicio_janela, bloqueado_ate)
                VALUES (:chave, 1, NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    tentativas = IF(inicio_janela < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, tentativas + 1),
                    inicio_janela = IF(inicio_janela < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), inicio_janela),
                    bloqueado_ate = IF(tentativas >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), bloqueado_ate)");
            $upsert->execute([':chave' => $throttleKey]);
            $erro = 'E-mail ou senha incorretos. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Interclasse Senac Bauru 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/webp" href="assets/logo-senac-oficial.webp">
</head>
<body class="login-page-body">

    <div class="app-container" style="display:flex; justify-content:center; align-items:center;">
        <div class="login-card">
            <!-- Logo do Senac Bauru -->
            <img src="assets/logo-senac-oficial.webp" alt="Senac Bauru" class="login-logo">
            <h1 class="login-title">Interclasse 2026</h1>
            <p class="login-subtitle">Acesso exclusivo para alunos e comissão</p>

            <?php if (!empty($erro)): ?>
                <div class="alert alert-danger" role="alert">
                    <span></span>
                    <span><?= e($erro) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" autocomplete="on">
                <div class="form-group">
                    <label for="email" class="form-label">E-mail Institucional</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="seu.email@senac.br" 
                        required 
                        value="<?= isset($email) ? e($email) : '' ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="senha" class="form-label">Senha de Acesso</label>
                    <input 
                        type="password" 
                        id="senha" 
                        name="senha" 
                        class="form-control" 
                        placeholder="••••••••" 
                        required 
                    >
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">
                    <span>Entrar no Sistema</span>
                    <span></span>
                </button>
            </form>

            <div style="margin-top:16px;text-align:center;font-size:.85rem;color:var(--text-muted)">
                <p>Baixe o regulamento do Interclasse para consultar as informações do evento.</p>
                <a href="assets/regulamento-interclasse-2026.pdf" download="Regulamento-Interclasse-2026.pdf">Baixar regulamento em PDF</a>
            </div>

        </div>
    </div>

</body>
</html>
