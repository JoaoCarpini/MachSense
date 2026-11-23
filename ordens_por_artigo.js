// =====================================================================
// ordens_por_artigo.js — relatorios.php
//   PARTE 1: cartão "Ordens de Produção por Artigo"
//   PARTE 2: filtro "Artigo" no formulário do topo (relatório principal)
//
// As OPs ficam no localStorage DO NAVEGADOR (chave "pi_ordens_producao",
// módulo pi_ordem_producao/). O PHP roda no servidor e não enxerga esses
// dados: no cartão, ele só entrega o período em data-desde e data-ate e a
// conta é feita aqui; no filtro do topo, este script envia ao PHP, por GET,
// as datas das OPs do artigo escolhido, e o PHP filtra os dias.
//
// Este script SÓ LÊ: não grava nada no localStorage e não muda status.
//
// Tudo fica dentro de uma função que roda na hora: (function () { ... })();
// Assim os nomes daqui não se misturam com os do dashboard.js.
// =====================================================================

(function () {

    // Mesma chave usada pelo módulo (pi_ordem_producao/js/script.js)
    const CHAVE = "pi_ordens_producao";

    // Os 5 status do módulo, na ordem das colunas da tabela
    const STATUS = [
        { chave: "planejada", nome: "Planejada" },
        { chave: "em_andamento", nome: "Em andamento" },
        { chave: "pausada", nome: "Pausada" },
        { chave: "concluida", nome: "Concluída" },
        { chave: "cancelada", nome: "Cancelada" }
    ];

    const cartao = document.getElementById("ops-artigo");
    const conteudo = document.getElementById("ops-artigo-conteudo");
    const filtro = document.getElementById("ops-artigo-filtro");
    // O cartão (PARTE 1) só funciona se os três elementos existirem.
    // A PARTE 2 roda de qualquer jeito.
    const temCartao = cartao !== null && conteudo !== null && filtro !== null;

    // Período vindo do PHP. Texto vazio = sem limite daquele lado.
    const desde = temCartao ? (cartao.dataset.desde || "") : "";
    const ate = temCartao ? (cartao.dataset.ate || "") : "";

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
            return dados.filter(function (item) {
                return item !== null && typeof item === "object";
            });
        } catch (erro) {
            return [];
        }
    }

    // Data no formato AAAA-MM-DD? (texto com 10 caracteres)
    function ehData(texto) {
        return typeof texto === "string" && texto.length === 10;
    }

    // A OP vale para o período se o intervalo [início, término] dela
    // encostar no período [desde, até]:
    //   - começa antes do fim do período   (início <= até)
    //   - e termina depois do começo dele  (término >= desde)
    // Datas AAAA-MM-DD podem ser comparadas como texto.
    // Sem filtro (desde e até vazios) = todas as OPs valem.
    function estaNoPeriodo(ordem) {
        if (desde === "" && ate === "") {
            return true;
        }
        if (!ehData(ordem.inicio) || !ehData(ordem.termino)) {
            return false; // sem datas válidas não dá para saber o período
        }
        const comecaAntesDoFim = ate === "" || ordem.inicio <= ate;
        const terminaDepoisDoInicio = desde === "" || ordem.termino >= desde;
        return comecaAntesDoFim && terminaDepoisDoInicio;
    }

    // Chave do agrupamento: ignora maiúsculas/minúsculas e espaços nas pontas.
    // "Coca-Cola", "coca-cola" e " COCA-COLA " viram a mesma chave.
    // normalize("NFC") deixa letras acentuadas sempre com o mesmo código.
    // "produto ?? ''" usa texto vazio quando o produto não existe.
    function chaveDoArtigo(produto) {
        return String(produto ?? "").normalize("NFC").trim().toLowerCase();
    }

    // Agrupa as OPs por artigo. Devolve uma lista de grupos:
    // { chave, nome, ops, quantidade, porStatus: { planejada: 0, ... } }
    // O nome mostrado é o da PRIMEIRA OP daquele artigo na lista.
    function agrupar(ordens) {
        const grupos = {}; // chave do artigo -> grupo

        ordens.forEach(function (ordem) {
            // Só conta OPs com um dos 5 status conhecidos (dado adulterado
            // no localStorage não entra na soma)
            const status = STATUS.find(function (s) { return s.chave === ordem.status; });
            if (status === undefined) {
                return;
            }

            const chave = chaveDoArtigo(ordem.produto);
            if (chave === "") {
                return; // sem artigo não há como agrupar
            }

            // Object.hasOwn: a chave foi criada POR NÓS neste objeto?
            // (evita confusão com nomes que todo objeto já tem, ex.: "constructor")
            if (!Object.hasOwn(grupos, chave)) {
                grupos[chave] = {
                    chave: chave,
                    nome: String(ordem.produto),
                    ops: 0,
                    quantidade: 0,
                    porStatus: { planejada: 0, em_andamento: 0, pausada: 0, concluida: 0, cancelada: 0 }
                };
            }

            const grupo = grupos[chave];
            grupo.ops = grupo.ops + 1;
            const quantidade = Number(ordem.quantidade);
            if (Number.isFinite(quantidade)) {
                grupo.quantidade = grupo.quantidade + quantidade;
            }
            grupo.porStatus[status.chave] = grupo.porStatus[status.chave] + 1;
        });

        // Object.values transforma o objeto em lista; depois ordena pelo nome
        return Object.values(grupos).sort(function (a, b) {
            return a.nome.localeCompare(b.nome, "pt-BR");
        });
    }

    function formatarNumero(numero) {
        return numero.toLocaleString("pt-BR"); // 1500 -> "1.500"
    }

    // Cria uma célula (<th> ou <td>) com texto. textContent nunca vira HTML.
    function criarCelula(tag, texto, classe) {
        const celula = document.createElement(tag);
        celula.textContent = texto;
        if (classe) {
            celula.className = classe;
        }
        return celula;
    }

    // Recria as opções do <select> "Artigo", mantendo a escolha atual se o
    // artigo ainda existir no período.
    function preencherFiltro(grupos) {
        const escolhido = filtro.value;
        filtro.textContent = "";
        filtro.appendChild(new Option("Todos", ""));
        grupos.forEach(function (grupo) {
            // new Option(texto, valor): texto visível e valor da opção
            filtro.appendChild(new Option(grupo.nome, grupo.chave));
        });
        const aindaExiste = grupos.some(function (g) { return g.chave === escolhido; });
        filtro.value = aindaExiste ? escolhido : "";
        filtro.disabled = grupos.length === 0;
    }

    function mostrarVazio() {
        const vazio = document.createElement("p");
        vazio.className = "empty";
        const forte = document.createElement("strong");
        forte.textContent = "Nenhuma ordem de produção no período.";
        vazio.appendChild(forte);
        conteudo.appendChild(vazio);
    }

    // Monta a tabela só com os grupos visíveis (todos ou o artigo escolhido)
    function montarTabela(grupos) {
        const tabela = document.createElement("table");
        tabela.className = "tabela";

        const cabecalho = document.createElement("tr");
        cabecalho.appendChild(criarCelula("th", "Artigo"));
        cabecalho.appendChild(criarCelula("th", "OPs", "num"));
        cabecalho.appendChild(criarCelula("th", "Qtd. planejada", "num"));
        STATUS.forEach(function (s) {
            cabecalho.appendChild(criarCelula("th", s.nome, "num"));
        });
        const thead = document.createElement("thead");
        thead.appendChild(cabecalho);

        const tbody = document.createElement("tbody");
        grupos.forEach(function (grupo) {
            const linha = document.createElement("tr");
            linha.appendChild(criarCelula("td", grupo.nome));
            linha.appendChild(criarCelula("td", formatarNumero(grupo.ops), "num mono"));
            linha.appendChild(criarCelula("td", formatarNumero(grupo.quantidade), "num mono"));
            STATUS.forEach(function (s) {
                linha.appendChild(criarCelula("td", formatarNumero(grupo.porStatus[s.chave]), "num mono"));
            });
            tbody.appendChild(linha);
        });

        tabela.appendChild(thead);
        tabela.appendChild(tbody);

        // Mesma moldura da tabela do relatório; rola por dentro no celular
        const moldura = document.createElement("div");
        moldura.className = "tabela-wrap tabela-relatorio";
        moldura.appendChild(tabela);
        return moldura;
    }

    // Desenha o cartão do zero (lê, filtra pelo período, agrupa e mostra)
    function desenhar() {
        const grupos = agrupar(carregarOrdens().filter(estaNoPeriodo));
        preencherFiltro(grupos);
        conteudo.textContent = "";

        if (grupos.length === 0) {
            mostrarVazio();
            return;
        }

        // Filtro do <select>: "" = todos os artigos
        const visiveis = grupos.filter(function (g) {
            return filtro.value === "" || g.chave === filtro.value;
        });
        conteudo.appendChild(montarTabela(visiveis));
    }

    // =================================================================
    // PARTE 2: filtro "Artigo" no formulário do topo
    //
    // Ao aplicar com um artigo escolhido, o formulário (GET) leva também:
    //   artigo  = nome do artigo
    //   janelas = OPs desse artigo que de fato rodaram (Em andamento,
    //             Pausada ou Concluída): "máquina|início|término;..."
    //   outras  = OPs de OUTROS artigos que se sobrepõem a essas janelas
    //             (só para o PHP mostrar o aviso de mistura)
    // Com "Todos", nada disso é enviado e o relatório fica como antes.
    // =================================================================

    // Status que indicam que o artigo esteve de verdade na máquina
    const STATUS_COM_PRODUCAO = ["em_andamento", "pausada", "concluida"];

    // Mesmo limite que o PHP aceita
    const MAX_JANELAS = 50;

    // Janela de uma OP: { maquina, inicio, fim }. Devolve null se a OP não
    // serve: datas inválidas, término antes do início, ou nome de máquina
    // com "|" ou ";" (são os separadores do texto enviado ao PHP).
    function janelaDaOrdem(ordem) {
        if (!ehData(ordem.inicio) || !ehData(ordem.termino) || ordem.termino < ordem.inicio) {
            return null;
        }
        const maquina = String(ordem.maquina ?? "").trim();
        if (maquina === "" || maquina.includes("|") || maquina.includes(";")) {
            return null;
        }
        return { maquina: maquina, inicio: ordem.inicio, fim: ordem.termino };
    }

    // Junta janelas da MESMA máquina que se sobrepõem, para mandar menos
    // itens na URL. Ex.: M1 01→05 e M1 04→08 viram M1 01→08.
    // Primeiro ordena por máquina e início; depois, cada janela ou "cola"
    // na anterior (se começar antes de ela terminar) ou vira uma nova.
    function juntarJanelas(janelas) {
        const ordenadas = janelas.slice().sort(function (a, b) {
            if (a.maquina !== b.maquina) {
                return a.maquina.localeCompare(b.maquina);
            }
            return a.inicio.localeCompare(b.inicio);
        });

        const resultado = [];
        ordenadas.forEach(function (j) {
            const ultima = resultado[resultado.length - 1];
            if (ultima !== undefined && ultima.maquina === j.maquina && j.inicio <= ultima.fim) {
                if (j.fim > ultima.fim) {
                    ultima.fim = j.fim; // estende a janela anterior
                }
            } else {
                resultado.push({ maquina: j.maquina, inicio: j.inicio, fim: j.fim });
            }
        });
        return resultado;
    }

    // Duas janelas se sobrepõem: mesma máquina e as datas se cruzam
    function seSobrepoem(a, b) {
        return a.maquina === b.maquina && a.inicio <= b.fim && b.inicio <= a.fim;
    }

    // [{maquina, inicio, fim}, ...] -> "Máquina 1|2026-11-10|2026-11-14;..."
    function janelasParaTexto(janelas) {
        return janelas.map(function (j) {
            return j.maquina + "|" + j.inicio + "|" + j.fim;
        }).join(";");
    }

    // Separa as janelas do artigo escolhido (chave) e as de outros artigos
    // que se sobrepõem a elas
    function calcularJanelas(ordens, chave) {
        const doArtigo = [];
        const deOutros = [];
        ordens.forEach(function (ordem) {
            if (!STATUS_COM_PRODUCAO.includes(ordem.status)) {
                return; // Planejada, Cancelada ou status estranho: ignora
            }
            const janela = janelaDaOrdem(ordem);
            const chaveDaOrdem = chaveDoArtigo(ordem.produto);
            if (janela === null || chaveDaOrdem === "") {
                return;
            }
            if (chaveDaOrdem === chave) {
                doArtigo.push(janela);
            } else {
                deOutros.push(janela);
            }
        });

        const janelas = juntarJanelas(doArtigo);
        const outras = juntarJanelas(deOutros.filter(function (o) {
            return janelas.some(function (j) { return seSobrepoem(j, o); });
        }));
        // slice(0, 50): o aviso de mistura só precisa de algumas
        return { janelas: janelas, outras: outras.slice(0, MAX_JANELAS) };
    }

    // Artigos de todas as OPs: lista de { chave, nome } ordenada pelo nome.
    // O nome é o da primeira OP daquele artigo (mesma regra do cartão).
    function listarArtigos(ordens) {
        const vistos = {};
        ordens.forEach(function (ordem) {
            const chave = chaveDoArtigo(ordem.produto);
            if (chave !== "" && !Object.hasOwn(vistos, chave)) {
                vistos[chave] = { chave: chave, nome: String(ordem.produto) };
            }
        });
        return Object.values(vistos).sort(function (a, b) {
            return a.nome.localeCompare(b.nome, "pt-BR");
        });
    }

    // Cria um campo escondido do formulário (vai junto no GET)
    function campoEscondido(nome, valor) {
        const campo = document.createElement("input");
        campo.type = "hidden";
        campo.name = nome;
        campo.value = valor;
        campo.dataset.filtroArtigo = "sim"; // marca para poder apagar depois
        return campo;
    }

    function iniciarFiltroDoTopo() {
        const form = document.querySelector(".toolbar form.filtros");
        if (form === null) {
            return;
        }
        const botao = form.querySelector('button[type="submit"]');
        const ordens = carregarOrdens();

        // <label><span>Artigo</span><select>...</select></label>, igual ao de Período
        const rotulo = document.createElement("label");
        rotulo.className = "filtro-artigo"; // limita a largura (ver dashboard.css)
        const texto = document.createElement("span");
        texto.textContent = "Artigo";
        const select = document.createElement("select");
        select.id = "filtro-artigo"; // sem name: quem vai no GET são os campos escondidos
        select.appendChild(new Option("Todos", ""));

        listarArtigos(ordens).forEach(function (artigo) {
            const opcao = new Option(artigo.nome, artigo.chave);
            // Mais de 50 janelas não cabem no limite do PHP: a opção fica
            // desabilitada em vez de filtrar pela metade
            if (calcularJanelas(ordens, artigo.chave).janelas.length > MAX_JANELAS) {
                opcao.disabled = true;
                opcao.textContent = artigo.nome + " (OPs demais para filtrar)";
            }
            select.appendChild(opcao);
        });

        // Ao recarregar a página, volta marcado o artigo que veio na URL
        const artigoNaUrl = new URLSearchParams(window.location.search).get("artigo");
        if (artigoNaUrl !== null) {
            const chave = chaveDoArtigo(artigoNaUrl);
            const existe = Array.from(select.options).some(function (o) {
                return o.value === chave && !o.disabled;
            });
            if (existe) {
                select.value = chave;
            }
        }

        rotulo.appendChild(texto);
        rotulo.appendChild(select);
        form.insertBefore(rotulo, botao); // fica entre Período e Aplicar

        // Na hora de enviar: com artigo, acrescenta os campos escondidos;
        // com "Todos", não acrescenta nada (URL igual à de antes)
        form.addEventListener("submit", function () {
            form.querySelectorAll("input[data-filtro-artigo]").forEach(function (campo) {
                campo.remove(); // apaga campos de um envio anterior
            });
            if (select.value === "") {
                return;
            }

            const nome = select.options[select.selectedIndex].textContent;
            const calculo = calcularJanelas(carregarOrdens(), select.value);
            form.appendChild(campoEscondido("artigo", nome));
            form.appendChild(campoEscondido("janelas", janelasParaTexto(calculo.janelas)));
            if (calculo.outras.length > 0) {
                form.appendChild(campoEscondido("outras", janelasParaTexto(calculo.outras)));
            }
        });
    }

    // =================================================================
    // INÍCIO
    // =================================================================

    // PARTE 1: cartão (só se ele existir na página)
    if (temCartao) {
        // Trocar o artigo no <select> redesenha a tabela, sem recarregar a página
        filtro.addEventListener("change", desenhar);

        // Se o módulo mudar as OPs em outra aba, o cartão se atualiza sozinho
        window.addEventListener("storage", function (evento) {
            if (evento.key === CHAVE || evento.key === null) {
                desenhar();
            }
        });

        desenhar();
    }

    // PARTE 2: filtro "Artigo" do topo
    iniciarFiltroDoTopo();

})();
