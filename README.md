# 🏆 Remodelação do Site do Interclasse 2026 - Senac Bauru

Projeto desenvolvido com foco em **Mobile First**, layout vertical responsivo e design moderno para os jogos interclasses do **Senac Bauru**.

---

## 🎨 Identidade Visual e Paleta de Cores
- **Azul Principal:** `#1565C0`
- **Laranja:** `#F57C00`
- **Branco:** `#FFFFFF`
- **Cinza Claro:** `#F5F6F8`
- **Cinza Escuro:** `#263238`

---

## 🛠️ Tecnologias Utilizadas
- **Backend:** PHP puro (com PDO e Prepared Statements).
- **Banco de Dados:** MySQL / MariaDB (`utf8mb4`).
- **Frontend:** HTML5 Semântico, CSS3 Moderno (Flexbox/Grid, variáveis CSS, sombras suaves, cantos com `border-radius: 12px`), JavaScript Vanilla para modais, filtros e abas.
- **Segurança:** Senhas com `password_hash`, proteção CSRF, limitação de tentativas e sessões endurecidas.

---

## 📂 Estrutura de Arquivos

```text
interclasse-senac-2026/
├── assets/
│   ├── logo-senac.svg         # Logo institucional do Senac Bauru
│   └── teams/                 # Emblemas vetoriais das 6 equipes
│       ├── athena.svg
│       ├── fenix.svg
│       ├── kairos.svg
│       ├── odisseia.svg
│       ├── valhalla.svg
│       └── odin.svg
├── db.php                     # Conexão PDO, sessão e funções de segurança
├── schema.sql                 # Criação do banco, 5 tabelas e dados de teste (seed)
├── index.php                  # Roteador inicial
├── login.php                  # Tela de autenticação SHA1
├── logout.php                 # Encerramento de sessão
├── dashboard.php              # Menu principal vertical com atalhos e cards
├── regulamento.php            # Categorias em acordeão e download PDF/DOCX
├── denuncias.php              # Cadastro e monitoramento de denúncias da equipe
├── jogos.php                  # Cronograma de jogos, placares e filtros mobile
├── pontuacao.php              # Classificação geral (3/1/0 pts) e modal de equipe
├── style.css                  # CSS unificado Mobile First
├── script.js                  # Lógica de modais, tabs e interações
└── README.md                  # Documentação do projeto
```

---

## 🚀 Como Executar o Projeto

### Opção 1: Via XAMPP / WampServer
1. Copie a pasta `interclasse-senac-2026` para a pasta raiz do seu servidor web (ex: `htdocs` no XAMPP ou `www` no WAMP).
2. Abra o **phpMyAdmin** (geralmente em `http://localhost/phpmyadmin`).
3. Vá em **Importar** e selecione o arquivo `schema.sql` (ele criará a base `interclasse_senac` com todas as tabelas e dados de teste).
4. Se necessário, ajuste as credenciais de usuário/senha em `db.php` (o padrão é usuário `root` e senha vazia).
5. Acesse no navegador:
   ```text
   http://localhost/interclasse-senac-2026/
   ```

### Opção 2: Via Terminal com PHP Built-in Server
1. Certifique-se de que o serviço MySQL está em execução e importe o `schema.sql`:
   ```bash
   mysql -u root -p < schema.sql
   ```
2. Inicie o servidor embutido do PHP:
   ```bash
   php -S localhost:8000
   ```
3. Abra no navegador (ou ative a visualização móvel no DevTools F12):
   ```text
   http://localhost:8000/
   ```

---

## Acesso de Desenvolvimento
As contas seed são apenas para desenvolvimento local. Somente Lucas fica ativo como exemplo de aluno; os demais alunos seed ficam desativados. Não publique dados seed nem use credenciais compartilhadas em uma instalação real. Crie usuários com senhas únicas de pelo menos 12 caracteres.

---

## ✨ Funcionalidades Principais
1. **Autenticação:** Senhas novas usam `password_hash`; hashes antigos são atualizados automaticamente após um login válido.
2. **Dashboard Vertical:** Cards com toques ergonômicos e atalhos rápidos.
3. **Regulamento Interativo:** Visualização dividida por tópicos (Inscrições, Uniformes, Regras, Arbitragem, Pontuação e Fair Play) e download direto de arquivos.
4. **Canal Ético de Denúncias:**
   - Envio de relato com seleção da equipe agressora.
   - Contadores de denúncias recebidas e aceitas contra a equipe do usuário.
   - Painel exclusivo para moderadores/administradores avaliarem casos.
5. **Jogos do Dia:** Visualização com logos, placar, data/hora formatada, locais de quadra e filtros por status ou modalidade.
6. **Classificação Automatizada:** Cálculo em tempo real com regra de 3 pontos por vitória e 1 por empate, ordenação por critérios de desempate e modal deslizante (bottom-sheet) com histórico completo de cada equipe.
