<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regulamento - Interclasse Senac Bauru 2026</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/webp" href="assets/logo-senac-oficial.webp">
</head>
<body>
    <header class="top-header">
        <div class="top-header-content">
            <div class="brand-wrapper">
                <a href="dashboard.php" style="display:flex;align-items:center;gap:8px">
                    <span aria-hidden="true" style="font-size:1.2rem;color:#1565C0">‹</span>
                    <img src="assets/logo-senac-oficial.webp" alt="Senac" class="brand-logo">
                </a>
            </div>
            <?= getRoleBadge($user['tipo']) ?>
        </div>
    </header>
    <main class="app-container">
        <section class="user-hero-card">
            <h1 class="user-hero-name">Regulamento do Interclasse 2026</h1>
            <p>Baixe o regulamento do Interclasse para consultar todas as informações do evento.</p>
        </section>
        <div class="action-box" style="text-align:center">
            <a class="btn btn-primary" href="assets/regulamento-interclasse-2026.pdf" download="Regulamento-Interclasse-2026.pdf">Baixar regulamento em PDF</a>
        </div>
    </main>
    <nav class="bottom-nav">
        <a href="dashboard.php" class="bottom-nav-item"><span>Início</span></a>
        <a href="jogos.php" class="bottom-nav-item"><span>Jogos</span></a>
        <a href="pontuacao.php" class="bottom-nav-item"><span>Tabela</span></a>
        <a href="denuncias.php" class="bottom-nav-item"><span>Denúncias</span></a>
        <?php if (canManageSystem()): ?><a href="admin.php" class="bottom-nav-item"><span>Admin</span></a><?php endif; ?>
    </nav>
</body>
</html>
