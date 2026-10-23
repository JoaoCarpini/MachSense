# MachSense

Sistema web de monitoramento de produção, no estilo de um MES simplificado (Manufacturing Execution System). Ele mostra, em uma tela só, como estão as máquinas da fábrica: quanto tempo cada uma ficou produzindo, quanto ficou parada, por que parou e se bateu a meta de disponibilidade.

O público é o gestor ou supervisor de produção, que precisa saber rápido onde está perdendo tempo.

Os dados não vêm de sensores reais. O sistema trabalha com máquinas simuladas que enviam um sinal a cada 5 minutos dizendo se estão rodando ou paradas, e guarda esses sinais no banco.

## O que o sistema faz hoje

- Login por usuário e senha, com a senha guardada como hash (bcrypt).
- Dashboard com disponibilidade geral, tempo produzindo, tempo parado, metas atingidas, disponibilidade por hora e paradas por motivo, tudo lido do banco.
- Cadastro de usuário, com validação no servidor.
- Logout.

## O que ainda está em desenvolvimento

- Telas de Máquinas, Detalhe da máquina, Paradas, Relatórios e Configurações.
- Restringir o cadastro de usuários ao administrador logado. Hoje a tela é aberta.
- Ajustes de segurança no login e no logout (novo ID de sessão no login, token CSRF no login e logout por POST).
- A simulação que gera os sinais das máquinas. Enquanto ela não está ligada, o dashboard mostra o estado vazio, a não ser que se carreguem dados de teste na mão.

## Tecnologias

PHP puro (sem framework), MySQL/MariaDB com PDO, HTML, CSS e JavaScript. Para rodar localmente, o XAMPP (Apache, PHP 8.2 ou mais novo e phpMyAdmin).

## Como rodar

1. Instale o XAMPP e ligue o Apache e o MySQL pelo painel.
2. Copie a pasta do projeto para `C:\xampp\htdocs\machsense`.
3. Abra o phpMyAdmin (`http://localhost/phpmyadmin`), crie um banco chamado `machsense` com collation `utf8mb4_unicode_ci`, entre nele e use a aba Importar para carregar o arquivo `schema.sql`. Ele cria as tabelas, as duas views e os dados iniciais (3 máquinas, 4 motivos de parada e o intervalo de 5 minutos).
4. Copie `config.example.php` para `config.local.php` e confira os dados de conexão. No XAMPP padrão são host `localhost`, porta `3306`, usuário `root` e senha vazia. O `config.local.php` não vai para o Git.
5. No `php.ini`, confirme `extension=pdo_mysql`, `display_errors = Off`, `log_errors = On` e `session.cookie_httponly = 1`, e reinicie o Apache.
6. Crie o primeiro usuário. O `schema.sql` já traz um usuário `admin`, mas o repositório é público e a senha dele não está registrada aqui. Gere um hash novo com `php -r "echo password_hash('SUA_SENHA', PASSWORD_BCRYPT);"` e atualize a coluna `senha` do `admin` no phpMyAdmin.
7. Abra `http://localhost/machsense/` e entre.

## Estrutura do projeto

```
index.php            tela de login
login_process.php    recebe o login (POST) e abre a sessão
logout.php           encerra a sessão
dashboard.php        painel de produção (lê as views do banco)
dashboard.css / .js  estilo e gráficos do painel
cadastro.php         criar conta de usuário
cadastro.css         estilo do cadastro
Usuario.php          consultas de usuário
db.php               conexão PDO
config.example.php   modelo de configuração (copiar para config.local.php)
style.css            estilo do login
includes/            funções de segurança e partes de layout compartilhadas
schema.sql           tabelas, views e dados iniciais
```

## Banco de dados

O banco `machsense` tem 5 tabelas:

- `usuarios`: quem acessa o sistema (nome, usuário, hash da senha, cargo).
- `maquinas`: as máquinas, com a meta de disponibilidade em % (padrão 85) e se estão ativas.
- `motivos_parada`: almoço, furo de processo, manutenção e setup.
- `parametros`: configurações, por enquanto o intervalo entre sinais (5 minutos).
- `pulsos`: um registro por sinal de cada máquina, com o horário, o estado (rodando ou parado) e o motivo quando está parada.

Uma regra do banco garante que uma parada sempre tem motivo e que uma máquina rodando nunca tem. Cada pulso vale o intervalo configurado, então o tempo produzindo é a quantidade de pulsos rodando vezes 5 minutos.

Duas views fazem os cálculos que o dashboard usa: `vw_indicadores_maquina` (tempos, disponibilidade e se a meta foi atingida) e `vw_paradas_por_motivo`.

## Segurança

Dados sensíveis só por POST, validação no servidor, `htmlspecialchars` em tudo que volta para a tela, prepared statements em todas as consultas, `password_hash` para senhas, token CSRF nos formulários, cookies de sessão com `HttpOnly` e `SameSite=Strict`, e erros gravados em log em vez de aparecerem na tela.

O repositório é público: senhas e dados de conexão reais não devem ser enviados ao Git.

Demais integrantes: ________
