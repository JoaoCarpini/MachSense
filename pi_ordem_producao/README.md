# MachSense — Módulo Ordem de Produção (OP)

Atividade SIF072 "Do Conceito ao Código" — Programação para Web I.
Módulo do PI implementado só com HTML, CSS e JavaScript puros, com os dados
salvos no navegador (localStorage).

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

Dê duplo clique em `index.html`. Não precisa instalar nada nem de internet.

Ou rode um servidor local na pasta `pi_ordem_producao`:

```
python -m http.server 8000
```

e abra `http://localhost:8000` no navegador.

## Estrutura

```
pi_ordem_producao/
  README.md             este arquivo
  index.html            página: formulário de cadastro e lista de OPs
  css/styles.css        aparência (mobile-first, tema escuro)
  js/script.js          regras, validação, localStorage e eventos
  docs/ai-usage-log.txt registro de uso de ferramentas na atividade
```

## Regras de negócio implementadas

- Campos: número (OP-0000, único), produto (2 a 60 caracteres), quantidade
  (inteiro maior que zero), início, término (não pode ser antes do início),
  máquina (Máquina 1, 2 ou 3).
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

Chave `pi_ordens_producao` do localStorage, como uma lista em JSON. Para ver:
F12 → Aplicativo (Application) → Armazenamento local. Para zerar, apague a
chave e recarregue a página.
