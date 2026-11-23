MÓDULO DE ORDENS DE PRODUÇÃO COM JAVASCRIPT (Programação para Web I)

GRUPO: MachSense

TEMA DOS DADOS
Ordens de produção (OP). Cada ordem diz O QUE produzir, QUANTO, EM QUAL
máquina e ATÉ QUANDO. Usa só HTML, CSS, JavaScript puro e localStorage, sem
PHP e sem MySQL. Os dados ficam na chave "pi_ordens_producao" do navegador.

CAMPOS DE CADA ORDEM
  id           número gerado pelo sistema (Date.now)
  numero       número sequencial, exibido como OP-0001 (nunca é reaproveitado)
  produto      texto de 2 a 80 caracteres
  quantidade   inteiro de 1 a 1.000.000
  maquina      nome da máquina (vem das máquinas ativas da chave "pi_maquinas")
  prioridade   baixa | media | alta
  status       planejada | em_producao | concluida | cancelada
  dataInicio   AAAA-MM-DD
  dataEntrega  AAAA-MM-DD (não pode ser antes do início)
  observacoes  texto opcional, até 300 caracteres
  criadaEm     data e hora do cadastro (ISO)

ESTRUTURA DE PASTAS
ordens-producao/
  README.md                  este arquivo
  index.html                 formulário e lista de ordens
  css/estilo.css             aparência
  js/script-inicial.js       chaves, valores permitidos e dados de exemplo
  js/banco.js                leitura e gravação no localStorage
  js/crud.js                 criar, listar, atualizar, excluir, prepararEdicao
  js/interface.js            tela, validação, filtros e eventos

A ordem dos scripts no index.html é obrigatória: script-inicial.js, banco.js,
crud.js, interface.js.

FUNCIONALIDADES
- Cadastrar ordem com validação de todos os campos (mensagens na página).
- Lista com número, produto, quantidade, máquina, prioridade, entrega e status.
- Editar e excluir ordens (exclusão pede confirmação).
- Busca por número, produto ou máquina e filtro por status.
- Destaque "Atrasada" para ordens abertas com entrega antes de hoje.

INTEGRAÇÃO COM O CRUD DE MÁQUINAS
O campo Máquina lista as máquinas ATIVAS salvas pelo crud-javascript (chave
"pi_maquinas"). Isso só funciona se as duas páginas forem abertas pela mesma
origem (por exemplo, as duas por http://localhost:8000). Se não houver
máquinas ativas, aparecem "Máquina 1", "Máquina 2" e "Máquina 3".
Para isso, rode o servidor na pasta MachSense (a de cima) e abra:
  http://localhost:8000/crud-javascript/
  http://localhost:8000/ordens-producao/

COMO ABRIR
Opção 1: dar duplo clique no arquivo index.html (abre no navegador).
Opção 2: servidor local. Na pasta MachSense, rodar
  python -m http.server 8000
e abrir http://localhost:8000/ordens-producao/ no navegador.

COMO TESTAR A PERSISTÊNCIA
1. Cadastrar uma ordem nova.
2. Recarregar a página (F5): a ordem continua na lista.
3. Fechar o navegador, abrir de novo: a ordem continua lá.
4. Para voltar ao início, apague as chaves pi_ordens_producao e
   pi_ordens_producao_sequencia (F12, aba Aplicativo, Armazenamento local) e
   recarregue a página: as 3 ordens de exemplo voltam.
