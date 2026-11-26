# MachSense

Sistema web de monitoramento de produção, no estilo de um MES simplificado (Manufacturing Execution System). Ele mostra como estão as máquinas da fábrica: quanto tempo cada uma ficou produzindo, quanto ficou parada, por que parou e se bateu a meta de disponibilidade.

O público é o gestor ou supervisor de produção, que precisa saber rápido onde está perdendo tempo.

Os dados não vêm de sensores reais. O sistema trabalha com máquinas simuladas que enviam um sinal a cada 5 minutos dizendo se estão rodando ou paradas, e guarda esses sinais no banco.

## Sumário

- [O que o sistema faz hoje](#o-que-o-sistema-faz-hoje)
- [Tecnologias](#tecnologias)
- [Como rodar](#como-rodar)
- [Como rodar o sistema completo](#como-rodar-o-sistema-completo)
- [Módulo Ordem de Produção](#módulo-ordem-de-produção-programação-para-web-i)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Banco de dados](#banco-de-dados)
- [Segurança](#segurança)
- [Equipe](#equipe)

## O que o sistema faz hoje

- Login por usuário e senha, com a senha guardada como hash (bcrypt), token CSRF, novo ID de sessão a cada login e limite de 5 tentativas erradas em 15 minutos.
- Dashboard com disponibilidade geral, tempo produzindo, tempo parado, metas atingidas, disponibilidade por hora e paradas por motivo.
- Máquinas: um card por máquina ativa com o estado atual, e uma tela de detalhe com indicadores e histórico de paradas.
- Paradas: lista dos períodos de parada, com filtro por período, máquina e motivo. O departamento Manutenção (e o administrador) pode corrigir o motivo de uma parada.
- Relatórios: disponibilidade por máquina e por dia em um período, filtro por artigo das ordens de produção e exportação em CSV.
- Ordens de Produção: cadastro de OPs (número, artigo, quantidade, máquina e período) com controle de status (Planejada, Em andamento, Pausada, Concluída, Cancelada). Os dados ficam no banco, na tabela `ordens_producao`.
- Gerenciar máquinas (CRUD): listar, cadastrar, editar e excluir. A exclusão apaga a máquina, os pulsos e as ordens de produção dela na mesma transação.
- Configurações: dados do usuário, troca de senha e cadastro, meta e ativação das máquinas. O administrador vê também a aba Usuários, com a lista de contas.
- Cadastro de usuário, só para o administrador, com validação no servidor.
- Simulação pelo site, para o administrador e o departamento Produção.
- Logout (por POST, com token CSRF).
- Simulador de sensores (`simulador.py`) e API (`api.py`) em Python: o simulador manda um pulso por máquina e a API grava na tabela `pulsos`. O dashboard se atualiza sozinho a cada 10 segundos.

## Tecnologias

PHP puro (sem framework), MySQL/MariaDB com PDO, HTML, CSS e JavaScript. O simulador e a API são em Python (FastAPI e PyMySQL; as versões fixadas em `requirements.txt` foram testadas com Python 3.13). Para rodar localmente, o XAMPP (Apache, PHP 8.2 ou mais novo e phpMyAdmin). O banco precisa ser MariaDB 10.2 ou mais novo, porque as views usam funções de janela.

## Como rodar

1. Instale o XAMPP e ligue o Apache e o MySQL pelo painel.
2. Copie a pasta do projeto para `C:\xampp\htdocs\machsense`.
3. Abra o phpMyAdmin (`http://localhost/phpmyadmin`), crie um banco chamado `machsense` com collation `utf8mb4_unicode_ci`, entre nele e use a aba Importar para carregar o arquivo `schema.sql`. Ele cria as tabelas, as views e os dados iniciais (3 máquinas, 4 motivos de parada, o intervalo de 5 minutos e o usuário `admin`). Se preferir, o `banco_de_dados.txt` faz a mesma coisa e ainda cria o banco: cole o conteúdo na aba SQL.
   - Já tem o banco de uma versão anterior? Importe o `schema.sql` de novo no mesmo banco. O script pode ser repetido sem problema: não apaga nenhum dado, atualiza as views, acrescenta a coluna `perfil` na tabela `usuarios` e cria as tabelas que faltarem (`ordens_producao` e `tentativas_login`).
4. Copie `config.example.php` para `config.local.php` e confira os dados de conexão. No XAMPP padrão são host `localhost`, porta `3306`, usuário `root` e senha vazia. O `config.local.php` não vai para o Git.
5. No `php.ini`, confirme `extension=pdo_mysql`, `display_errors = Off`, `log_errors = On` e `session.cookie_httponly = 1`, e reinicie o Apache.
6. Para ver o painel com dados, importe também `database/dados_demo.sql` no banco `machsense`. Ele gera 7 dias de sinais (das 08h às 18h) para as 3 primeiras máquinas ativas e pode ser repetido sem duplicar. Sem ele, o dashboard abre vazio.
7. Defina a senha do administrador. O `schema.sql` cria o usuário `admin` com um hash de senha que ninguém conhece, de propósito, porque o repositório é público. Gere um hash novo com `php -r "echo password_hash('SUA_SENHA', PASSWORD_BCRYPT);"` e atualize a coluna `senha` do `admin` no phpMyAdmin.
8. Abra `http://localhost/machsense/` e entre com `admin` e a senha que você definiu. O administrador vê no menu o item "Cadastrar usuário".

## Como rodar o sistema completo

O caminho dos dados é: `simulador.py` -> `api.py` -> MariaDB (tabela `pulsos`) -> páginas PHP. Para ver o dashboard se atualizando sozinho, ligue tudo nesta ordem:

1. Abra o XAMPP e ligue o Apache e o MariaDB (MySQL).
2. No phpMyAdmin, aba SQL, cole o conteúdo de `banco_de_dados.txt` e execute. Ele cria o banco `machsense`, as tabelas, as views e os dados iniciais. (Se importar o `schema.sql` em vez dele, entre antes no banco `machsense`.)
3. Copie `config.example.php` para `config.local.php` e confira host, porta, usuário e senha do banco.
4. Troque a senha do `admin`: gere um hash com `php -r "echo password_hash('SUA_SENHA', PASSWORD_BCRYPT);"` e atualize a coluna `senha` do usuário `admin` no phpMyAdmin.
5. Copie `.env.example` para `.env.local` e defina `MACHSENSE_API_KEY` com uma chave longa (gere com `python -c "import secrets; print(secrets.token_hex(24))"`). Confira também `MACHSENSE_DB_PORT`. O `.env.local` não vai para o Git.
6. Instale as dependências do Python: `pip install -r requirements.txt`
7. Em um terminal, suba a API: `uvicorn api:app --host 127.0.0.1 --port 8000`
8. Em outro terminal, rode o simulador: `python simulador.py`
9. Abra `http://localhost/machsense/`, entre com `admin` e a senha que você definiu. O dashboard busca números novos a cada 10 segundos, sem recarregar a página.

Iniciar a simulação pelo botão do site (para a apresentação):

Em vez de abrir o terminal do simulador, você liga a simulação com um clique. A API precisa estar rodando antes; o botão só liga o simulador.

1. No `.env.local`, acrescente a linha `MACHSENSE_SIMULACAO_NO_SITE=1` (vem desligado por segurança). Veja o `.env.example`.
2. Suba a API dando duplo clique em `iniciar_api.bat` (ou com `python -m uvicorn api:app --host 127.0.0.1 --port 8000`) e deixe a janela aberta.
3. No site, entre como administrador ou com um usuário do departamento Produção, abra o menu **Simulação**, escolha a velocidade e clique em **Iniciar e abrir o dashboard**. O dashboard abre e se enche sozinho. Para parar, volte em Simulação e clique em **Parar**. Fechar a API também para o simulador.

A página só abre no computador onde o sistema roda, só para o administrador ou o departamento Produção e só com o recurso ligado. O PHP não executa programas: ele pede para a API (que roda na mesma máquina, com a chave do `.env.local`) ligar o simulador. Não rode o simulador no terminal ao mesmo tempo que o botão.

Como o simulador funciona:

- Cada pulso vale 5 minutos simulados. Com o fator padrão (60), um pulso sai a cada 5 segundos reais. Para acelerar: `python simulador.py --fator 600`.
- As durações de produção e de parada são sorteadas em múltiplos de 5 minutos, então a conta "pulsos x 5 minutos" do banco bate exatamente com o que o simulador sorteou.
- Ele simula as máquinas ativas cadastradas no site. `--maquinas 2` limita às 2 primeiras. Uma máquina cadastrada ou ativada depois entra na simulação em alguns pulsos.
- Pode parar (Ctrl+C) e rodar de novo: ele continua do último horário gravado no banco, sem repetir pulsos. Se a API cair, ele avisa e reenvia o mesmo pulso quando ela voltar, sem pular nenhum horário.
- Se o banco tiver os dados de demonstração (`database/dados_demo.sql`), a simulação continua depois do último pulso deles. Para começar do zero, apague os pulsos no phpMyAdmin (`DELETE FROM pulsos;`).

Teste da API (com o MariaDB ligado e o `.env.local` criado): `python tests/teste_integracao.py`. Ele usa uma máquina temporária e apaga o que criou.

Problemas comuns:

- Erro `#1046 - Nenhum banco de dados foi selecionado` ao importar: use o `banco_de_dados.txt` na aba SQL, ou clique no banco `machsense` antes de importar o `schema.sql`.
- Erro `#1054 Coluna 'cargo' desconhecida`: sua tabela `usuarios` é antiga. Rode `ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cargo VARCHAR(80) NULL AFTER senha;` e importe o script de novo.
- Páginas Máquinas, Paradas ou Relatórios em branco: o banco está sem as views novas. Importe o `schema.sql` de novo no banco `machsense`.
- Porta 3306 ocupada ou MariaDB em outra porta (3307): ajuste `port` no `config.local.php` e `MACHSENSE_DB_PORT` no `.env.local`.
- A API não sobe e mostra "MACHSENSE_API_KEY não definida": crie o `.env.local` com uma chave de pelo menos 16 caracteres (o texto de exemplo não vale).
- API responde 401: a chave do `.env.local` usada pelo simulador é diferente da usada pela API. As duas leem o mesmo arquivo; reinicie a API depois de mudar a chave.
- API responde 503: o MariaDB está desligado ou a senha/porta do `.env.local` está errada. A API volta sozinha quando o banco voltar.
- Simulador diz "Nenhuma máquina ativa": cadastre ou ative máquinas no site (Gerenciar máquinas).
- Página Simulação diz "desligada": falta `MACHSENSE_SIMULACAO_NO_SITE=1` no `.env.local`. O item do menu só aparece para o administrador e para o departamento Produção.
- Página Simulação diz API "Fora do ar": rode `iniciar_api.bat` (ou o comando do uvicorn) e deixe a janela aberta.
- Login diz "Muitas tentativas erradas": espere 15 minutos ou, no phpMyAdmin, rode `DELETE FROM tentativas_login;`.
- Ordens de Produção diz que a tabela não existe: importe o `schema.sql` de novo no banco `machsense` (não apaga dados).
- OPs antigas sumiram: a versão anterior do módulo salvava as OPs só no navegador. Abra Ordens de Produção no mesmo navegador de antes e clique em **Importar para o banco**.
- Dashboard abre vazio: ainda não chegou nenhum pulso. Ligue a API e o simulador; a página recarrega sozinha quando o primeiro pulso chegar.

## Módulo Ordem de Produção (Programação para Web I)

Atividade em grupo SIF072 "Do Conceito ao Código". O módulo fica na pasta `pi_ordem_producao/`, feito em HTML, CSS e JavaScript puros e integrado ao sistema.

- Cadastro, edição e acompanhamento das ordens de produção (OP), com controle de status.
- Os dados ficam no MariaDB (tabela `ordens_producao`). O JavaScript fala com `ordens_producao_api.php` por `fetch()`.
- A primeira versão gravava no `localStorage`. Migramos para o banco para que as ordens apareçam na página da máquina e filtrem os relatórios por artigo, o que dados presos ao navegador não permitem.
- Acesso: menu **Ordens de Produção** (exige login) ou `http://localhost/machsense/pi_ordem_producao/`.
- Regras de negócio, estrutura da pasta e grupo: `pi_ordem_producao/README.md`. Registro de uso de IA: `pi_ordem_producao/docs/ai-usage-log.txt`.

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
configuracoes.php    perfil, metas das máquinas e lista de usuários (só administrador)
ordens_producao_api.php  API em JSON do módulo Ordens de Produção (lê e grava a tabela ordens_producao)
pi_ordem_producao/   módulo Ordens de Produção (index.php, css/, js/, docs/ e README.md), exige login
dashboard.css / .js  estilo e gráficos do painel (o .js também atualiza os números a cada 10 s)
dashboard_dados.php  os números do dashboard em JSON (só leitura, exige login)
cadastro.php         criar conta de usuário (só administrador)
cadastro.css         estilo do cadastro
Usuario.php          consultas de usuário
conexao.php          conexão PDO (dados vêm de config.local.php)
protect.php          exige login em todas as páginas: sessão segura, CSP + redireciona para index.php
maquinas_listar.php  CRUD de máquinas: lista (Read)
maquinas_criar.php   CRUD de máquinas: cadastro (Create)
maquinas_editar.php  CRUD de máquinas: edição (Update)
maquinas_excluir.php CRUD de máquinas: exclusão com confirmação e transação (Delete)
banco_de_dados.txt   scripts de criação do banco, das tabelas e das views
api.py               API (FastAPI) que recebe os pulsos e grava no MariaDB
simulador.py         simulador das máquinas (manda os pulsos para a API)
iniciar_api.bat      sobe a API com duplo clique (Windows)
simulacao.php / .js  página com o botão que liga e desliga a simulação (administrador e Produção)
includes/simulacao_api.php  ponte do site com a API do simulador (chamadas HTTP locais)
requirements.txt     dependências do Python, com versões fixadas
.env.example         modelo do .env.local (banco e chave da API)
tests/               teste de integração da API
GRUPO.txt            integrantes do grupo (RA e nome)
config.example.php   modelo de configuração (copiar para config.local.php)
style.css            estilo do login
includes/            funções de segurança, consultas e partes de layout compartilhadas
database/            dados de demonstração (dados_demo.sql)
schema.sql           tabelas, views e dados iniciais
```

## Banco de dados

O banco `machsense` tem 7 tabelas:

- `usuarios`: quem acessa o sistema (nome, usuário, hash da senha, cargo e perfil `admin` ou `usuario`).
- `maquinas`: as máquinas, com a meta de disponibilidade em % (padrão 85) e se estão ativas.
- `motivos_parada`: almoço, furo de processo, manutenção e setup.
- `parametros`: configurações, por enquanto o intervalo entre sinais (5 minutos).
- `pulsos`: um registro por sinal de cada máquina, com o horário, o estado (rodando ou parado) e o motivo quando está parada.
- `ordens_producao`: as ordens de produção, com número (OP-0000, único), artigo, quantidade, máquina, início, término e status. O banco confere o formato do número, a quantidade maior que zero e o término depois do início.
- `tentativas_login`: tentativas de login erradas (usuário, IP e horário), usadas para limitar a força bruta. Não guarda senha.

Uma regra do banco garante que uma parada sempre tem motivo e que uma máquina rodando nunca tem. Cada pulso vale o intervalo configurado, então o tempo produzindo é a quantidade de pulsos rodando vezes 5 minutos.

Os cálculos ficam em 5 views, e as páginas leem delas em vez de repetir o SQL:

- `vw_indicadores_maquina`: tempos, disponibilidade e se a meta foi atingida, por máquina. Usa JOIN e um subselect com o intervalo de pulso.
- `vw_paradas_por_motivo`: tempo parado por máquina e motivo.
- `vw_disponibilidade_diaria`: pulsos e disponibilidade de cada máquina em cada dia. Alimenta os relatórios.
- `vw_ultimo_estado_maquina`: estado atual e motivo da parada, achados com um subselect (o último pulso de cada máquina) e JOIN.
- `vw_periodos_parada`: junta pulsos de parada seguidos, da mesma máquina e do mesmo motivo, em um período com início, fim e duração. Usa três subselects aninhados com funções de janela.

## Segurança

Dados sensíveis só por POST, validação no servidor, `htmlspecialchars` em tudo que volta para a tela, prepared statements em todas as consultas, `password_hash` para senhas, token CSRF em todos os formulários (inclusive login e logout), cookies de sessão com `HttpOnly` e `SameSite=Strict`, e erros gravados em log em vez de aparecerem na tela.

Todas as páginas que exigem login passam pelo `protect.php`, que liga a sessão segura e os cabeçalhos de segurança (CSP, X-Frame-Options, nosniff). A CSP só aceita CSS e JavaScript do próprio site, por isso nenhuma página usa `style="..."` nem script embutido no HTML.

O login limita as tentativas: 5 erros do mesmo usuário no mesmo IP em 15 minutos bloqueiam novas tentativas até a janela passar.

Permissões conferidas no banco a cada acesso (não só na sessão):

- Cadastro de usuário e aba Usuários: só administrador (os outros recebem 403 ou não veem a aba).
- Simulação: administrador e departamento Produção.
- Corrigir o motivo de uma parada: administrador e departamento Manutenção.

A API do simulador sobe só em 127.0.0.1, exige a chave `X-API-Key` para gravar pulsos, não tem CORS aberto e usa consultas parametrizadas. Se o banco cair, responde 503 com mensagem genérica e grava o detalhe no log.

O repositório é público: senhas, chaves e dados de conexão reais não devem ser enviados ao Git (`config.local.php` e `.env.local` ficam fora dele).

## Equipe

Integrantes (RA e nomes completos em `GRUPO.txt`):

- Antonio Neto
- Felipe Alves Pedro
- Felippe Padovan
- Guilherme Massaro 
- Guilherme Somera 
- João Pedro Carpini
