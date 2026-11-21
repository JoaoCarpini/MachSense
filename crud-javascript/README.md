CRUD DE MÁQUINAS COM JAVASCRIPT (Programação para Web I)

GRUPO: MachSense

INTEGRANTES:
- RA: 110128 - Nome: Antonio Neto
- RA: 115401 - Nome: Felipe Alves Pedro
- RA: 114759 - Nome: Felippe Padovan
- RA: 118175 - Nome: Guilherme Massaro
- RA: 118763 - Nome: Guilherme Somera
- RA: 119284 - Nome: João Pedro Carpini

TEMA DOS DADOS
Máquinas, a mesma entidade do projeto interdisciplinar (PI). Cada máquina tem
id, nome, meta (meta de disponibilidade em %, de 0 a 100) e ativa (sim ou não).
Esta atividade é separada do PI: usa só JavaScript puro e localStorage, sem PHP
e sem MySQL. Os dados ficam na chave "pi_maquinas" do navegador.

ESTRUTURA DE PASTAS
crud-javascript/
  README.md                  este arquivo
  index.html                 formulário e tabela de registros
  css/estilo.css             aparência
  dados/estrutura-dados.txt  estrutura dos campos e dados de exemplo
  js/script-inicial.js       define a chave e semeia os dados de exemplo
  js/banco.js                leitura e gravação no localStorage
  js/crud.js                 criar, listar, atualizar, excluir, prepararEdicao
  js/interface.js            tela, validação e eventos

A ordem dos scripts no index.html é obrigatória: script-inicial.js, banco.js,
crud.js, interface.js.

COMO ABRIR
Opção 1: dar duplo clique no arquivo index.html (abre no navegador).
Opção 2: servidor local. Dentro da pasta crud-javascript, rodar
  python -m http.server 8000
e abrir http://localhost:8000 no navegador.
Não precisa instalar nada nem ter internet.

COMO TESTAR A PERSISTÊNCIA
1. Cadastrar uma máquina nova.
2. Recarregar a página (F5): a máquina continua na tabela.
3. Fechar o navegador por completo, abrir de novo e abrir o index.html: a
   máquina continua lá, porque o localStorage guarda os dados no navegador.
4. Para voltar ao início, apague a chave pi_maquinas (F12, aba Aplicativo,
   Armazenamento local) e recarregue a página: os 3 exemplos voltam.
   Apagar todas as máquinas pela tela NÃO traz os exemplos de volta.
