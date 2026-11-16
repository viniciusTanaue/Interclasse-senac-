-- ========================================================
-- Interclasse 2026 - Senac Bauru
-- Banco de Dados: interclasse_senac
-- ========================================================

CREATE DATABASE IF NOT EXISTS `interclasse_senac` 
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `interclasse_senac`;

-- --------------------------------------------------------
-- 1. Tabela: usuarios
-- --------------------------------------------------------
DROP TABLE IF EXISTS `denuncias`;
DROP TABLE IF EXISTS `partidas`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `pontuacao_modalidades`;
DROP TABLE IF EXISTS `equipes`;
DROP TABLE IF EXISTS `usuarios`;

CREATE TABLE `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `senha` VARCHAR(255) NOT NULL COMMENT 'Hash gerado por password_hash',
  `turma` VARCHAR(50) NULL DEFAULT NULL,
  `tipo` VARCHAR(20) NOT NULL DEFAULT 'aluno' COMMENT 'aluno, professor, admin',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `responsavel` VARCHAR(100) NULL DEFAULT NULL,
  `data` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `chave` CHAR(64) PRIMARY KEY,
  `tentativas` INT NOT NULL DEFAULT 0,
  `inicio_janela` DATETIME NOT NULL,
  `bloqueado_ate` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Tabela: equipes
-- --------------------------------------------------------
CREATE TABLE `equipes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL,
  `logo_url` VARCHAR(255) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Tabela: partidas
-- --------------------------------------------------------
CREATE TABLE `partidas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `equipe_casa_id` INT NOT NULL,
  `equipe_visitante_id` INT NOT NULL,
  `gols_casa` INT DEFAULT 0,
  `gols_visitante` INT DEFAULT 0,
  `data_hora` DATETIME NOT NULL,
  `local` VARCHAR(100) NOT NULL,
  `modalidade` VARCHAR(100) NOT NULL,
  `status` ENUM('agendado', 'finalizado') DEFAULT 'agendado',
  FOREIGN KEY (`equipe_casa_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`equipe_visitante_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de pontos lançados pelo professor ao finalizar cada partida.
CREATE TABLE IF NOT EXISTS `partida_pontos_historico` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `partida_id` INT NOT NULL,
  `equipe_id` INT NOT NULL,
  `pontos` INT NOT NULL DEFAULT 0,
  `registrado_por` INT NULL,
  `data_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`partida_id`),
  INDEX (`equipe_id`),
  FOREIGN KEY (`partida_id`) REFERENCES `partidas`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`registrado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. Tabela: denuncias
-- --------------------------------------------------------
CREATE TABLE `denuncias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `denunciante_id` INT NOT NULL,
  `equipe_agressora_id` INT NOT NULL,
  `descricao` TEXT NOT NULL,
  `status` ENUM('pendente', 'aceita', 'rejeitada') DEFAULT 'pendente',
  `pontos_penalidade` INT DEFAULT 0,
  `analisado_por` INT NULL DEFAULT NULL,
  `data_analise` DATETIME NULL DEFAULT NULL,
  `data_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`denunciante_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`equipe_agressora_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`analisado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. Tabela: pontuacao_modalidades
-- --------------------------------------------------------
CREATE TABLE `pontuacao_modalidades` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `modalidade` VARCHAR(100) NOT NULL,
  `pontos` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. Tabela: equipe_pontos_extras (Ajustes Manuais, Gincanas e Penalidades)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `equipe_pontos_extras` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `equipe_id` INT NOT NULL,
  `modalidade_id` INT NULL DEFAULT NULL,
  `pontos` INT NOT NULL DEFAULT 0,
  `motivo` VARCHAR(150) NULL DEFAULT NULL,
  `criado_por` INT NULL DEFAULT NULL,
  `data_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`equipe_id`) REFERENCES `equipes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`modalidade_id`) REFERENCES `pontuacao_modalidades`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`criado_por`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- CARGA DE DADOS INICIAIS (SEED DATA)
-- Contas locais com senhas aleatórias; redefina-as no painel após a configuração.
-- ========================================================

-- Usuários de teste (Aluno, Professor e Administrador)
INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `turma`, `tipo`, `responsavel`) VALUES
(1, 'Coordenação Esportiva', 'admin@senac.br', '$2y$10$irLmqg0B1Q0cCZt16Guysezz.3PppiEwPDfIcYTBrbGvyR1ualNxu', 'Administração', 'admin', 'Diretoria Senac'),
(2, 'Lucas Silva (Athena)', 'lucas@senac.br', '$2y$10$q4az7VZKeAEFg2dS09hMGujueBQ8zPSzCwHI5/QYGlgPWc1secmK6', 'Athena 3º TI', 'aluno', 'Prof. Marcos'),
(3, 'Mariana Costa (Fênix)', 'mariana@senac.br', '$2y$10$.TVvhWgKYPUwtKSKnyJSa.9A0ua0rI5zIsPS2Q0Ke7DC/AP0gcVRe', 'Ordem da Fênix 3º Multimídia', 'aluno', 'Prof. Vanessa'),
(4, 'Gabriel Souza (Kairos)', 'gabriel@senac.br', '$2y$10$STE.MnDqzeQvhxwFDlblv..kwm/OU6P1568YnqhohCUX88DmTtdyi', 'Kairos 2º TI', 'aluno', 'Prof. Juliana'),
(5, 'Beatriz Lima (Odisseia)', 'beatriz@senac.br', '$2y$10$pdumjSevDBjxwepx76P8v.SgF6BH7z6GmeulcxaN2FZ3OOweJunCa', 'Odisseia 2º Multimídia', 'aluno', 'Prof. Rogério'),
(6, 'Matheus Rocha (Valhalla)', 'matheus@senac.br', '$2y$10$LHTY3VwDlacvPZXm4L2Yx.JBMKGZt2w19jtZM5u6Fjqvnxdl.Knou', 'Valhalla 1º TI', 'aluno', 'Prof. Carlos'),
(7, 'Camila Santos (Odin)', 'camila@senac.br', '$2y$10$U9UKOAQTeq/lmXAmwUiW1uMk96.dmW5VGelfNhBO7lZ65JOkTmrvi', 'Odin 1º Multimídia', 'aluno', 'Prof. Helena'),
(8, 'Prof. Carlos Eduardo', 'professor@senac.br', '$2y$10$1pxqyBIMdArlvQV4xuVzpuedfzLJpFFsQnBXepCohdSv5ArR3pL8O', 'Corpo Docente', 'professor', 'Coordenação Pedagógica');

-- Apenas Lucas fica habilitado como conta de exemplo para alunos.
UPDATE `usuarios` SET `ativo` = 0 WHERE `tipo` = 'aluno' AND `email` <> 'lucas@senac.br';

-- Equipes participantes
INSERT INTO `equipes` (`id`, `nome`, `logo_url`) VALUES
(1, 'Athena 3º TI', 'assets/teams/athena.svg'),
(2, 'Ordem da Fênix 3º Multimídia', 'assets/teams/fenix.svg'),
(3, 'Kairos 2º TI', 'assets/teams/kairos.svg'),
(4, 'Odisseia 2º Multimídia', 'assets/teams/odisseia.svg'),
(5, 'Valhalla 1º TI', 'assets/teams/valhalla.svg'),
(6, 'Odin 1º Multimídia', 'assets/teams/odin.svg');

-- Modalidades e pontuação padrão
INSERT INTO `pontuacao_modalidades` (`id`, `modalidade`, `pontos`) VALUES
(1, 'Futsal', 20),
(2, 'Basquete 3x3', 15),
(3, 'Voleibol Misto', 15),
(4, 'Queimada Mista', 10),
(5, 'Tênis de Mesa', 10),
(6, 'Desenho', 10),
(7, 'Xadrez', 10),
(8, 'Gincana de Linguagens', 25),
(9, 'Naruto Storm', 15),
(10, 'Just Dance', 15),
(11, 'Penalidades (Fair Play)', -10);

-- Partidas (Finalizadas e Agendadas para o Interclasse 2026)
INSERT INTO `partidas` (`equipe_casa_id`, `equipe_visitante_id`, `gols_casa`, `gols_visitante`, `data_hora`, `local`, `modalidade`, `status`) VALUES
-- Finalizadas
(1, 2, 7, 0, '2026-09-21 09:00:00', 'Quadra 1', 'Futsal', 'finalizado'),
(3, 4, 2, 2, '2026-09-21 10:30:00', 'Quadra 1', 'Futsal', 'finalizado'),
(5, 6, 3, 1, '2026-09-21 14:00:00', 'Quadra 2', 'Basquete 3x3', 'finalizado'),
(1, 3, 4, 1, '2026-09-22 09:30:00', 'Quadra 2', 'Voleibol Misto', 'finalizado'),
(2, 5, 0, 3, '2026-09-22 11:00:00', 'Quadra 1', 'Queimada Mista', 'finalizado'),
-- A Acontecer (Agendadas)
(1, 2, 0, 0, '2026-09-23 09:00:00', 'Quadra 2', 'Futsal', 'agendado'),
(4, 6, 0, 0, '2026-09-23 10:30:00', 'Quadra 1', 'Basquete 3x3', 'agendado'),
(2, 3, 0, 0, '2026-09-23 14:00:00', 'Auditório', 'Naruto Storm', 'agendado'),
(1, 4, 0, 0, '2026-09-23 15:30:00', 'Auditório', 'Just Dance', 'agendado'),
(1, 5, 0, 0, '2026-09-24 09:00:00', 'Quadra 1', 'Voleibol Misto', 'agendado'),
(3, 6, 0, 0, '2026-09-24 10:30:00', 'Sala Multiuso', 'Xadrez', 'agendado'),
(4, 5, 0, 0, '2026-09-24 14:00:00', 'Quadra 2', 'Tênis de Mesa', 'agendado');

-- Pontos Extras de Modalidades Artísticas / Gincana / Penalidades
INSERT INTO `equipe_pontos_extras` (`equipe_id`, `modalidade_id`, `pontos`, `motivo`, `criado_por`) VALUES
(1, 8, 25, '1º Lugar na Gincana de Linguagens', 1),
(2, 6, 10, '1º Lugar no Concurso de Desenho', 1),
(4, 7, 10, 'Campeão do Torneio de Xadrez', 1),
(5, 9, 15, 'Vencedor do Torneio de Naruto Storm', 1),
(3, 10, 15, 'Destaque no Torneio de Just Dance', 1),
(2, 11, -5, 'Atraso de uniforme na cerimônia de abertura', 1);

-- Denúncias de teste
INSERT INTO `denuncias` (`denunciante_id`, `equipe_agressora_id`, `descricao`, `status`, `data_registro`) VALUES
(3, 1, 'Conduta antidesportiva com provocações excessivas ao final da partida de futsal.', 'aceita', '2026-09-21 16:20:00'),
(2, 2, 'Tentativa de escalação de atleta não inscrito na súmula da queimada.', 'rejeitada', '2026-09-22 12:45:00'),
(4, 2, 'Uso de linguagem inapropriada com a arbitragem durante a transição de quadra.', 'pendente', '2026-09-22 17:10:00'),
(5, 6, 'Danos acidentais ao material de apoio do banco de reservas.', 'pendente', '2026-09-22 18:30:00');
