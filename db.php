<?php
/**
 * Conexão com o Banco de Dados e Funções Globais de Autenticação
 * Interclasse Senac Bauru 2026
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || empty($_SESSION['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'], $token)) {
        http_response_code(403);
        exit('Requisição inválida. Atualize a página e tente novamente.');
    }
}

ob_start('injectCsrfFields');

// Configurações do Banco de Dados
define('DB_HOST', getenv('INTERCLASSE_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('INTERCLASSE_DB_NAME') ?: 'interclasse_senac');
define('DB_USER', getenv('INTERCLASSE_DB_USER') ?: 'root');
define('DB_PASS', getenv('INTERCLASSE_DB_PASS') ?: '');
define('DB_PORT', getenv('INTERCLASSE_DB_PORT') ?: '3306');

/**
 * Retorna a instância PDO de conexão com o banco de dados
 */
function getDBConnection(): ?PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Em caso de falha na conexão, renderiza um aviso informativo amigável
            ?>
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Erro de Conexão - Interclasse Senac Bauru 2026</title>
                <link rel="stylesheet" href="style.css">
                <style>
                    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; background: #F5F6F8; }
                    .db-error-card { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); max-width: 480px; width: 100%; border-top: 5px solid #F57C00; }
                    .db-error-card h2 { color: #1565C0; margin-bottom: 12px; font-size: 1.3rem; }
                    .db-error-card p { color: #263238; line-height: 1.5; font-size: 0.95rem; margin-bottom: 16px; }
                    .db-error-code { background: #ECEFF1; padding: 12px; border-radius: 8px; font-family: monospace; font-size: 0.85rem; color: #D84315; word-break: break-all; margin-bottom: 16px; }
                    .instructions { font-size: 0.88rem; color: #546E7A; line-height: 1.6; }
                    .instructions ol { padding-left: 20px; }
                </style>
            </head>
            <body>
                <div class="db-error-card">
                    <h2> Erro ao Conectar com o MySQL</h2>
                    <p>Não foi possível conectar ao banco de dados <strong>interclasse_senac</strong>.</p>
                    <div class="db-error-code">Verifique se o serviço de banco de dados está ativo e tente novamente.</div>
                    <div class="instructions">
                        <strong>Como resolver:</strong>
                        <ol>
                            <li>Inicie o MySQL (XAMPP, WampServer ou MariaDB).</li>
                            <li>Importe o arquivo <code>schema.sql</code> no seu MySQL.</li>
                            <li>Verifique o usuário e senha definidos em <code>db.php</code>.</li>
                        </ol>
                    </div>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    return $pdo;
}

function csrfField(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($_SESSION['_csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}

function injectCsrfFields(string $html): string {
    return preg_replace_callback('/<form\b[^>]*>/i', static function ($match) {
        return $match[0] . csrfField();
    }, $html) ?? $html;
}

/**
 * Verifica se existe usuário autenticado na sessão
 */
function isLoggedIn(): bool {
    return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
}

/**
 * Obriga autenticação para páginas protegidas
 */
function requireAuth(): void {
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Por favor, faça login para acessar esta página.';
        header('Location: login.php');
        exit;
    }
}

/**
 * Retorna os dados do usuário autenticado atual
 */
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'          => $_SESSION['usuario_id'] ?? null,
        'nome'        => $_SESSION['usuario_nome'] ?? '',
        'email'       => $_SESSION['usuario_email'] ?? '',
        'turma'       => $_SESSION['usuario_turma'] ?? '',
        'tipo'        => $_SESSION['usuario_tipo'] ?? 'aluno',
        'responsavel' => $_SESSION['usuario_responsavel'] ?? '',
    ];
}

/**
 * ========================================================
 * FUNÇÕES DE CONTROLE DE ACESSO POR NÍVEL (RBAC)
 * Níveis:
 * - aluno: consulta resultados, tabela, pontuação e registra denúncias
 * - professor: nível aluno + atualiza placar, encerra jogos, modera denúncias e ajeita tabela
 * - admin: nível professor + adiciona equipes, define confrontos, horários e gestão geral
 * ========================================================
 */

/**
 * Verifica se o usuário autenticado possui um dos papéis especificados
 */
function hasRole($roles): bool {
    $user = currentUser();
    if (!$user) return false;
    $tipo = strtolower($user['tipo'] ?? 'aluno');
    if (is_array($roles)) {
        return in_array($tipo, array_map('strtolower', $roles), true);
    }
    return $tipo === strtolower($roles);
}

/**
 * Verifica se é Aluno
 */
function isAluno(): bool {
    return hasRole('aluno');
}

/**
 * Verifica se é Professor ou Admin (Professores têm permissão operacional esportiva)
 */
function isProfessor(): bool {
    return hasRole(['professor', 'admin']);
}

/**
 * Verifica se é Administrador (Acesso total a equipes, confrontos e sistema)
 */
function isAdmin(): bool {
    return hasRole('admin');
}

/**
 * Permissão para atualizar placar e encerrar jogos (Professor e Administrador)
 */
function canEditScores(): bool {
    return isProfessor();
}

/**
 * Permissão para moderar e julgar denúncias (Professor e Administrador)
 */
function canModerateDenuncias(): bool {
    return isProfessor();
}

/**
 * Permissão para ajeitar a tabela de pontuação manualmente (Professor e Administrador)
 */
function canAdjustTable(): bool {
    return isProfessor();
}

/**
 * Permissão para gerenciar equipes, confrontos, horários e usuários (Administrador)
 */
function canManageSystem(): bool {
    return isAdmin();
}

/**
 * Exige um ou mais papéis permitidos para acessar a página / ação
 */
function requireRole($roles): void {
    requireAuth();
    if (!hasRole($roles)) {
        $_SESSION['flash_error'] = 'Acesso negado: seu nível de usuário não possui permissão para esta funcionalidade.';
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Retorna etiqueta HTML visual estilizada para o papel do usuário
 */
function getRoleBadge(?string $tipo): string {
    $tipo = strtolower($tipo ?? 'aluno');
    switch ($tipo) {
        case 'admin':
            return '<span class="role-badge role-admin"> Administrador</span>';
        case 'professor':
            return '<span class="role-badge role-professor"> Professor</span>';
        case 'aluno':
        default:
            return '<span class="role-badge role-aluno"> Aluno</span>';
    }
}

/**
 * Escapa HTML para prevenir XSS
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Formata data e hora para o padrão brasileiro
 */
function formatDateTime(?string $datetime): string {
    if (!$datetime) return '--';
    $timestamp = strtotime($datetime);
    return date('d/m/Y \à\s H:i', $timestamp);
}

/**
 * Formata apenas data (dd/mm/aaaa)
 */
function formatDate(?string $datetime): string {
    if (!$datetime) return '--';
    $timestamp = strtotime($datetime);
    return date('d/m/Y', $timestamp);
}

