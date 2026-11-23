// =====================================================================
// ordem_producao_maquina.js — cartão "Ordem de Produção" em maquina.php
//
// PONTE DE DEMONSTRAÇÃO entre o site em PHP e o módulo pi_ordem_producao/:
// as OPs ficam no localStorage DO NAVEGADOR (chave "pi_ordens_producao").
// O PHP roda no servidor e nunca vê esses dados; por isso é este script,
// rodando no navegador, que lê a lista e desenha o cartão.
// As duas páginas estão em http://localhost, então usam o mesmo localStorage.
//
// Este script SÓ LÊ. Ele nunca grava no localStorage nem muda status:
// a mudança de status continua sendo feita só pelo módulo.
//
// Tudo fica dentro de uma função que roda na hora: (function () { ... })();
// Assim os nomes daqui (const, function) não se misturam com os do
// dashboard.js, que também é carregado nesta página.
// =====================================================================

(function () {

    // Mesma chave usada pelo módulo (pi_ordem_producao/js/script.js)
    const CHAVE = "pi_ordens_producao";

    // Cartão montado pelo maquina.php. Se não existir (ex.: máquina não
    // encontrada), não há o que fazer.
    const cartao = document.getElementById("op-maquina");
    const conteudo = document.getElementById("op-conteudo");
    if (cartao === null || conteudo === null) {
        return;
    }

    // Lê a lista de OPs. Chave ausente, JSON inválido ou valor que não é
    // lista viram lista vazia, sem erro no console (o catch "engole" o erro).
    function carregarOrdens() {
        try {
            const texto = localStorage.getItem(CHAVE);
            if (texto === null) {
                return [];
            }
            const dados = JSON.parse(texto);
            if (!Array.isArray(dados)) {
                return [];
            }
            // Descarta itens que não são objetos (null, números, textos)
            return dados.filter(function (item) {
                return item !== null && typeof item === "object";
            });
        } catch (erro) {
            return [];
        }
    }

    // Data de HOJE no fuso do navegador, no formato AAAA-MM-DD (o mesmo do
    // input date do módulo). toISOString() não serve: usa o fuso UTC e,
    // à noite no Brasil, já seria "amanhã".
    function hojeLocal() {
        const agora = new Date();
        const mes = String(agora.getMonth() + 1).padStart(2, "0");
        const dia = String(agora.getDate()).padStart(2, "0");
        return agora.getFullYear() + "-" + mes + "-" + dia;
    }

    // Data válida no formato AAAA-MM-DD? (texto com 10 caracteres)
    function ehData(texto) {
        return typeof texto === "string" && texto.length === 10;
    }

    // "2026-11-23" -> "23/11/2026"
    function formatarData(texto) {
        if (!ehData(texto)) {
            return "—";
        }
        const partes = texto.split("-");
        return partes[2] + "/" + partes[1] + "/" + partes[0];
    }

    // Compara nomes de máquina sem diferença de espaços nas pontas nem de
    // codificação do acento (normalize("NFC") deixa "á" sempre igual).
    function mesmoNome(a, b) {
        return String(a).normalize("NFC").trim() === String(b).normalize("NFC").trim();
    }

    // Da lista recebida, devolve a OP com a MENOR data de início
    // (datas AAAA-MM-DD podem ser comparadas como texto). Lista vazia = null.
    function primeiraPorInicio(lista) {
        if (lista.length === 0) {
            return null;
        }
        const copia = lista.slice(); // slice: copia para não mexer na original
        copia.sort(function (a, b) {
            return String(a.inicio).localeCompare(String(b.inicio));
        });
        return copia[0];
    }

    // Escolhe UMA OP para a máquina, nesta ordem de prioridade:
    //   (a) Em andamento                    -> "Em produção agora"
    //   (b) Pausada                         -> "Pausada"
    //   (c) Planejada com início hoje       -> "Programada para hoje"
    //   (d) Planejada futura, menor início  -> "Próxima ordem"
    // Concluída e Cancelada são ignoradas (nem entram nos filtros).
    // Devolve { ordem, rotulo, selo } ou null se nada se encaixar.
    function escolherOrdem(ordens, maquina, hoje) {
        const daMaquina = ordens.filter(function (o) {
            return mesmoNome(o.maquina, maquina);
        });

        function comStatus(status) {
            return daMaquina.filter(function (o) { return o.status === status; });
        }

        const planejadas = comStatus("planejada").filter(function (o) {
            return ehData(o.inicio);
        });

        const regras = [
            { lista: comStatus("em_andamento"), rotulo: "Em produção agora", selo: "selo-ok" },
            { lista: comStatus("pausada"), rotulo: "Pausada", selo: "selo-parada" },
            {
                lista: planejadas.filter(function (o) { return o.inicio === hoje; }),
                rotulo: "Programada para hoje", selo: "selo-neutro"
            },
            {
                lista: planejadas.filter(function (o) { return o.inicio > hoje; }),
                rotulo: "Próxima ordem", selo: "selo-neutro"
            }
        ];

        // A primeira regra que tiver alguma OP vence
        for (const regra of regras) {
            const ordem = primeiraPorInicio(regra.lista);
            if (ordem !== null) {
                return { ordem: ordem, rotulo: regra.rotulo, selo: regra.selo };
            }
        }
        return null;
    }

    // Cria uma linha "rótulo: valor" da lista de detalhes (<div><dt><dd>)
    function criarLinha(rotulo, valor) {
        const linha = document.createElement("div");
        const dt = document.createElement("dt");
        const dd = document.createElement("dd");
        dt.textContent = rotulo;
        dd.textContent = valor; // textContent: o texto nunca vira HTML (sem XSS)
        linha.appendChild(dt);
        linha.appendChild(dd);
        return linha;
    }

    function formatarQuantidade(quantidade) {
        const numero = Number(quantidade);
        if (!Number.isFinite(numero)) {
            return "—";
        }
        return numero.toLocaleString("pt-BR") + " peças"; // 1500 -> "1.500 peças"
    }

    // Desenha o cartão (sempre do zero: apaga o conteúdo anterior)
    function desenhar() {
        const escolha = escolherOrdem(carregarOrdens(), cartao.dataset.maquina, hojeLocal());
        conteudo.textContent = "";

        if (escolha === null) {
            const vazio = document.createElement("p");
            vazio.className = "empty";
            const forte = document.createElement("strong");
            forte.textContent = "Nenhuma ordem de produção ativa.";
            vazio.appendChild(forte);
            conteudo.appendChild(vazio);
            return;
        }

        const ordem = escolha.ordem;

        // Selo com o rótulo (mesmas classes de selo da página)
        const selo = document.createElement("p");
        const span = document.createElement("span");
        span.className = "selo " + escolha.selo;
        span.textContent = escolha.rotulo;
        selo.appendChild(span);

        // "valor ?? '—'" usa "—" quando o valor não existe (undefined ou null)
        const detalhes = document.createElement("dl");
        detalhes.className = "indicadores panel-gap";
        detalhes.appendChild(criarLinha("Número da OP", String(ordem.numero ?? "—")));
        detalhes.appendChild(criarLinha("Artigo", String(ordem.produto ?? "—")));
        detalhes.appendChild(criarLinha("Quantidade", formatarQuantidade(ordem.quantidade)));
        detalhes.appendChild(criarLinha("Início", formatarData(ordem.inicio)));
        detalhes.appendChild(criarLinha("Término", formatarData(ordem.termino)));

        conteudo.appendChild(selo);
        conteudo.appendChild(detalhes);
    }

    desenhar();

    // Se o módulo estiver aberto em OUTRA aba e mudar uma OP (ex.: botão
    // Iniciar), o navegador avisa esta aba com o evento "storage" e o
    // cartão é redesenhado sem recarregar a página.
    window.addEventListener("storage", function (evento) {
        if (evento.key === CHAVE || evento.key === null) {
            desenhar();
        }
    });

})();
