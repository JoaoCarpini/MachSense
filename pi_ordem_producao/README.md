# MachSense — Módulo Ordem de Produção (OP)

Atividade SIF072 "Do Conceito ao Código" — Programação para Web I.
Módulo do PI com a tela em HTML, CSS e JavaScript puros. Na primeira versão
os dados ficavam no navegador (localStorage); agora ficam no banco do
sistema (tabela `ordens_producao`), e o JavaScript conversa com o servidor
por `fetch()`.

## Grupo: MachSense

| RA     | Nome               |
|--------|--------------------|
| 110128 | Antonio Neto       |
| 115401 | Felipe Alves Pedro |
| 114759 | Felippe Padovan    |
| 118175 | Guilherme Massaro  |
| 118763 | Guilherme Somera   |
| 119284 | João Pedro Carpini |

## Como abrir

O módulo faz parte do site e exige login. Com o XAMPP ligado, entre no
MachSense e clique em **Ordens de Produção** no menu (ou abra
`http://localhost/machsense/pi_ordem_producao/`).

## Estrutura

```
pi_ordem_producao/
  README.md             este arquivo
  index.php             página: formulário de cadastro e lista de OPs (exige login)
  css/styles.css        aparência (mobile-first, tema escuro)
  js/script.js          regras, validação, fetch() para o servidor e eventos
  docs/ai-usage-log.txt registro de uso de ferramentas na atividade

../ordens_producao_api.php  API em JSON que lê e grava a tabela ordens_producao
```

## Regras de negócio implementadas

- Campos: número (OP-0000, único), artigo (2 a 60 caracteres), quantidade
  (inteiro maior que zero), início, término (não pode ser antes do início),
  máquina (uma das máquinas ativas cadastradas no sistema).
- As regras são conferidas duas vezes: no JavaScript (para a tela responder
  rápido) e no servidor, que é quem decide antes de gravar.
- Toda OP nova nasce com status **Planejada**.
- Transições de status permitidas:

| De           | Para                                  |
|--------------|---------------------------------------|
| Planejada    | Em andamento, Cancelada               |
| Em andamento | Pausada, Concluída, Cancelada         |
| Pausada      | Em andamento, Cancelada               |
| Concluída    | — (final)                             |
| Cancelada    | — (final)                             |

- Só aparecem os botões das transições permitidas.
- Editar só é possível enquanto a OP não for final. Excluir pede confirmação.
- Fora do escopo: registro de produção e de perdas.

## Onde os dados ficam

Tabela `ordens_producao` do banco `machsense` (veja `schema.sql`). Todos os
usuários e computadores veem as mesmas OPs. Para ver no phpMyAdmin:
`SELECT * FROM ordens_producao;`

As OPs da versão antiga continuam no localStorage do navegador onde foram
criadas. Ao abrir o módulo nesse navegador, aparece o botão **Importar para o
banco**, que copia essas OPs para a tabela (as inválidas ou com número
repetido são ignoradas).
