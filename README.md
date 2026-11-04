# MachSense

Sistema web de monitoramento de produção, no estilo de um MES simplificado (Manufacturing Execution System). Ele mostra como estão as máquinas da fábrica: quanto tempo cada uma ficou produzindo, quanto ficou parada, por que parou e se bateu a meta de disponibilidade.

O público é o gestor ou supervisor de produção, que precisa saber rápido onde está perdendo tempo.

Os dados não vêm de sensores reais. O sistema trabalha com máquinas simuladas que enviam um sinal a cada 5 minutos dizendo se estão rodando ou paradas, e guarda esses sinais no banco.

## O que o sistema faz hoje

- Login por usuário e senha, com a senha guardada como hash (bcrypt) e novo ID de sessão a cada login.
- Dashboard com disponibilidade geral, tempo produzindo, tempo parado, metas atingidas, disponibilidade por hora e paradas por motivo.
- Máquinas: um card por máquina ativa com o estado atual, e uma tela de detalhe com indicadores e histórico de paradas.
- Paradas: lista dos períodos de parada, com filtro por período, máquina e motivo.
- Relatórios: disponibilidade por máquina e por dia em um período, com exportação em CSV.
- Gerenciar máquinas (CRUD): listar, cadastrar, editar e excluir. A exclusão apaga a máquina e os pulsos dela na mesma transação.
- Configurações: dados do usuário, troca de senha e cadastro, meta e ativação das máquinas.
- Cadastro de usuário, só para o administrador, com validação no servidor.
- Logout.

## O que ainda está em desenvolvimento

- A simulação que gera os sinais das máquinas. Enquanto ela não está ligada, os dados de demonstração (`database/dados_demo.sql`) fazem o papel dela.
- Token CSRF no formulário de login e logout por POST.
- Unificar o controle de acesso. As páginas do CRUD usam o `protect.php`; as demais ainda usam o `includes/auth.php`.
- Escolher o perfil ao cadastrar um usuário. Hoje toda conta nova é um usuário comum, e só o `admin` do banco é administrador.

## Tecnologias

PHP puro (sem framework), MySQL/MariaDB com PDO, HTML, CSS e JavaScript. Para rodar localmente, o XAMPP (Apache, PHP 8.2 ou mais novo e phpMyAdmin). O banco precisa ser MariaDB 10.2 ou mais novo, porque as views usam funções de janela.

## Como rodar

1. Instale o XAMPP e ligue o Apache e o MySQL pelo painel.
2. Copie a pasta do projeto para `C:\xampp\htdocs\machsense`.
3. Abra o phpMyAdmin (`http://localhost/phpmyadmin`), crie um banco chamado `machsense` com collation `utf8mb4_unicode_ci`, entre nele e use a aba Importar para carregar o arquivo `schema.sql`. Ele cria as tabelas, as views e os dados iniciais (3 máquinas, 4 motivos de parada, o intervalo de 5 minutos e o usuário `admin`). Se preferir, o `banco_de_dados.txt` faz a mesma coisa e ainda cria o banco: cole o conteúdo na aba SQL.
   - Já tem o banco de uma versão anterior? Importe o `schema.sql` de novo no mesmo banco. O script pode ser repetido sem problema: atualiza as views e acrescenta a coluna `perfil` na tabela `usuarios`.
4. Copie `config.example.php` para `config.local.php` e confira os dados de conexão. No XAMPP padrão são host `localhost`, porta `3306`, usuário `root` e senha vazia. O `config.local.php` não vai para o Git.
5. No `php.ini`, confirme `extension=pdo_mysql`, `display_errors = Off`, `log_errors = On` e `session.cookie_httponly = 1`, e reinicie o Apache.
6. Para ver o painel com dados, importe também `database/dados_demo.sql` no banco `machsense`. Ele gera 7 dias de sinais (das 08h às 18h) para as 3 primeiras máquinas ativas e pode ser repetido sem duplicar. Sem ele, o dashboard abre vazio.
7. Defina a senha do administrador. O `schema.sql` cria o usuário `admin` com um hash de senha que ninguém conhece, de propósito, porque o repositório é público. Gere um hash novo com `php -r "echo password_hash('SUA_SENHA', PASSWORD_BCRYPT);"` e atualize a coluna `senha` do `admin` no phpMyAdmin.
8. Abra `http://localhost/machsense/` e entre com `admin` e a senha que você definiu. O administrador vê no menu o item "Cadastrar usuário".

## Estrutura do projeto

```
index.php            tela de login
login_process.php    recebe o login (POST) e abre a sessão
logout.php           encerra a sessão
dashboard.php        painel de produção (lê as views do banco)
maquinas.php         cards das máquinas ativas
maquina.php          detalhe de uma máquina
paradas.php          períodos de parada com filtros
relatorios.php       relatório por período e exportação CSV
configuracoes.php    perfil e metas das máquinas
dashboard.css / .js  estilo e gráficos do painel
cadastro.php         criar conta de usuário (só administrador)
cadastro.css         estilo do cadastro
Usuario.php          consultas de usuário
conexao.php          conexão PDO (dados vêm de config.local.php)
protect.php          exige login: sessão segura + redireciona para index.php
maquinas_listar.php  CRUD de máquinas: lista (Read)
maquinas_criar.php   CRUD de máquinas: cadastro (Create)
maquinas_editar.php  CRUD de máquinas: edição (Update)
maquinas_excluir.php CRUD de máquinas: exclusão com confirmação e transação (Delete)
banco_de_dados.txt   scripts de criação do banco, das tabelas e das views
GRUPO.txt            integrantes do grupo (RA e nome)
config.example.php   modelo de configuração (copiar para config.local.php)
style.css            estilo do login
includes/            funções de segurança, consultas e partes de layout compartilhadas
database/            dados de demonstração (dados_demo.sql)
schema.sql           tabelas, views e dados iniciais
```

## Banco de dados

O banco `machsense` tem 5 tabelas:

- `usuarios`: quem acessa o sistema (nome, usuário, hash da senha, cargo e perfil `admin` ou `usuario`).
- `maquinas`: as máquinas, com a meta de disponibilidade em % (padrão 85) e se estão ativas.
- `motivos_parada`: almoço, furo de processo, manutenção e setup.
- `parametros`: configurações, por enquanto o intervalo entre sinais (5 minutos).
- `pulsos`: um registro por sinal de cada máquina, com o horário, o estado (rodando ou parado) e o motivo quando está parada.

Uma regra do banco garante que uma parada sempre tem motivo e que uma máquina rodando nunca tem. Cada pulso vale o intervalo configurado, então o tempo produzindo é a quantidade de pulsos rodando vezes 5 minutos.

Os cálculos ficam em 5 views, e as páginas leem delas em vez de repetir o SQL:

- `vw_indicadores_maquina`: tempos, disponibilidade e se a meta foi atingida, por máquina. Usa JOIN e um subselect com o intervalo de pulso.
- `vw_paradas_por_motivo`: tempo parado por máquina e motivo.
- `vw_disponibilidade_diaria`: pulsos e disponibilidade de cada máquina em cada dia. Alimenta os relatórios.
- `vw_ultimo_estado_maquina`: estado atual e motivo da parada, achados com um subselect (o último pulso de cada máquina) e JOIN.
- `vw_periodos_parada`: junta pulsos de parada seguidos, da mesma máquina e do mesmo motivo, em um período com início, fim e duração. Usa três subselects aninhados com funções de janela.

## Segurança

Dados sensíveis só por POST, validação no servidor, `htmlspecialchars` em tudo que volta para a tela, prepared statements em todas as consultas, `password_hash` para senhas, token CSRF nos formulários, cookies de sessão com `HttpOnly` e `SameSite=Strict`, e erros gravados em log em vez de aparecerem na tela.

O cadastro de usuário só abre para administrador. A página confere o perfil no banco a cada acesso: quem não está logado volta para o login, e quem está logado sem ser administrador recebe 403.

O repositório é público: senhas e dados de conexão reais não devem ser enviados ao Git.

## Equipe

Integrantes (RA e nomes completos em `GRUPO.txt`):

- Antonio Neto
- Felipe Alves Pedro
- Felippe Padovan
- Guilherme Massaro
- Guilherme Somera
- João Pedro Carpini (banco de dados)
