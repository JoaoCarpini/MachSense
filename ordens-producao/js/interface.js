// interface.js
// Tudo que mexe na tela: ler o formulário, validar, desenhar a tabela,
// aplicar os filtros e ligar os eventos. Carregado por último.

const PRODUTO_MIN = 2;
const PRODUTO_MAX = 80;
const QUANTIDADE_MAX = 1000000;
const OBSERVACOES_MAX = 300;

// Os elementos da página são buscados uma única vez e guardados aqui
const elementos = {
    formulario: document.getElementById("formulario"),
    produto: document.getElementById("produto"),
    quantidade: document.getElementById("quantidade"),
    maquina: document.getElementById("maquina"),
    dataInicio: document.getElementById("data-inicio"),
    dataEntrega: document.getElementById("data-entrega"),
    prioridade: document.getElementById("prioridade"),
    status: document.getElementById("status"),
    observacoes: document.getElementById("observacoes"),
    idEdicao: document.getElementById("id-edicao"),
    titulo: document.getElementById("titulo-formulario"),
    botaoSalvar: document.getElementById("botao-salvar"),
    botaoCancelar: document.getElementById("botao-cancelar"),
    mensagem: document.getElementById("mensagem"),
    busca: document.getElementById("busca"),
    filtroStatus: document.getElementById("filtro-status"),
    corpoTabela: document.getElementById("corpo-tabela"),
    contador: document.getElementById("contador")
};

/* ------------------------------------------------------------------
 * SEGURANÇA
 * ------------------------------------------------------------------ */

const MAPA_HTML = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
};

// Tudo que o usuário digitou e volta para a tela via innerHTML TEM de passar
// por aqui; sem isso, um produto como <img src=x onerror="..."> executaria
// código (XSS).
function escaparHtml(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, (caractere) => MAPA_HTML[caractere]);
}

/* ------------------------------------------------------------------
 * FORMATAÇÃO
 * ------------------------------------------------------------------ */

// 7 -> "OP-0007"
function formatarNumero(numero) {
    return `OP-${String(numero ?? "?").padStart(4, "0")}`;
}

// "2026-11-23" -> "23/11/2026". Monta pelo texto, sem new Date(), para não
// sofrer com fuso horário.
function formatarData(texto) {
    if (!ehDataValida(texto)) {
        return "—";
    }
    const [ano, mes, dia] = texto.split("-");
    return `${dia}/${mes}/${ano}`;
}

function formatarQuantidade(quantidade) {
    return typeof quantidade === "number" ? quantidade.toLocaleString("pt-BR") : "—";
}

// Ordem aberta (planejada ou em produção) com entrega antes de hoje.
// Datas AAAA-MM-DD podem ser comparadas direto como texto.
function estaAtrasada(ordem) {
    const aberta = ordem.status === "planejada" || ordem.status === "em_producao";
    return aberta && ehDataValida(ordem.dataEntrega) && ordem.dataEntrega < dataRelativa(0);
}

/* ------------------------------------------------------------------
 * VALIDAÇÃO
 * ------------------------------------------------------------------ */

// Formato AAAA-MM-DD e data que existe de verdade (recusa 2026-02-30)
function ehDataValida(texto) {
    if (typeof texto !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(texto)) {
        return false;
    }
    const [ano, mes, dia] = texto.split("-").map(Number);
    const data = new Date(ano, mes - 1, dia);
    return data.getFullYear() === ano && data.getMonth() === mes - 1 && data.getDate() === dia;
}

// Confere os campos ANTES de gravar. Recebe os textos crus do formulário e
// devolve a lista de mensagens de erro (lista vazia = tudo certo).
function validar(dados) {
    const erros = [];

    const produto = dados.produto.trim();
    const tamanho = Array.from(produto).length; // conta emoji como 1 caractere
    if (tamanho < PRODUTO_MIN || tamanho > PRODUTO_MAX) {
        erros.push(`O produto deve ter de ${PRODUTO_MIN} a ${PRODUTO_MAX} caracteres.`);
    } else if (/[\u0000-\u001F\u007F]/.test(produto)) {
        erros.push("O produto contém caracteres inválidos.");
    }

    // Só dígitos (aceita "1.200" como milhar), sem sinal, sem casas decimais
    const quantidade = dados.quantidade.trim().replace(/\./g, "");
    if (!/^\d+$/.test(quantidade) || Number(quantidade) < 1 || Number(quantidade) > QUANTIDADE_MAX) {
        erros.push(`A quantidade deve ser um número inteiro de 1 a ${QUANTIDADE_MAX.toLocaleString("pt-BR")}.`);
    }

    if (dados.maquina.trim() === "") {
        erros.push("Escolha a máquina que vai produzir a ordem.");
    }

    const inicioOk = ehDataValida(dados.dataInicio);
    const entregaOk = ehDataValida(dados.dataEntrega);
    if (!inicioOk) {
        erros.push("Informe uma data de início válida.");
    }
    if (!entregaOk) {
        erros.push("Informe uma data de entrega válida.");
    }
    if (inicioOk && entregaOk && dados.dataEntrega < dados.dataInicio) {
        erros.push("A entrega não pode ser antes do início.");
    }

    if (!PRIORIDADES[dados.prioridade]) {
        erros.push("Escolha uma prioridade válida.");
    }
    if (!STATUS[dados.status]) {
        erros.push("Escolha um status válido.");
    }

    if (Array.from(dados.observacoes.trim()).length > OBSERVACOES_MAX) {
        erros.push(`As observações podem ter no máximo ${OBSERVACOES_MAX} caracteres.`);
    }

    return erros;
}

/* ------------------------------------------------------------------
 * FORMULÁRIO
 * ------------------------------------------------------------------ */

// Lê os campos como texto cru (a conversão só acontece depois de validar)
function lerFormulario() {
    return {
        produto: elementos.produto.value,
        quantidade: elementos.quantidade.value,
        maquina: elementos.maquina.value,
        dataInicio: elementos.dataInicio.value,
        dataEntrega: elementos.dataEntrega.value,
        prioridade: elementos.prioridade.value,
        status: elementos.status.value,
        observacoes: elementos.observacoes.value
    };
}

// Converte os textos já validados para os tipos guardados
function converterDados(dados) {
    return {
        produto: dados.produto.trim(),
        quantidade: Number(dados.quantidade.trim().replace(/\./g, "")),
        maquina: dados.maquina.trim(),
        dataInicio: dados.dataInicio,
        dataEntrega: dados.dataEntrega,
        prioridade: dados.prioridade,
        status: dados.status,
        observacoes: dados.observacoes.trim()
    };
}

// Monta as opções do <select> de máquinas. "extra" é a máquina de uma ordem
// em edição que não está mais na lista (desativada ou renomeada).
function preencherMaquinas(extra) {
    const nomes = carregarMaquinas();
    if (extra && !nomes.includes(extra)) {
        nomes.push(extra);
    }

    elementos.maquina.textContent = "";
    nomes.forEach((nome) => {
        const opcao = document.createElement("option");
        opcao.value = nome;
        opcao.textContent = nome; // textContent: o nome nunca vira HTML
        elementos.maquina.appendChild(opcao);
    });
}

// null = formulário em modo "nova ordem"; número = editando aquele id
function lerIdEdicao() {
    const valor = elementos.idEdicao.value;
    return valor === "" ? null : Number(valor);
}

function definirModoEdicao(editando, numero) {
    elementos.titulo.textContent = editando
        ? `Editando ordem ${formatarNumero(numero)}`
        : "Nova ordem de produção";
    elementos.botaoSalvar.textContent = editando ? "Salvar alterações" : "Cadastrar ordem";
    elementos.botaoCancelar.hidden = !editando;
    elementos.formulario.classList.toggle("editando", editando);
    limparMensagem();
}

// Volta aos valores padrão; o início já vem preenchido com a data de hoje
function limparFormulario() {
    elementos.formulario.reset();
    elementos.idEdicao.value = "";
    preencherMaquinas();
    elementos.dataInicio.value = dataRelativa(0);
}

function cancelarEdicao() {
    limparFormulario();
    definirModoEdicao(false);
}

/* ------------------------------------------------------------------
 * MENSAGENS (na página, com role="alert", em vez de alert())
 * ------------------------------------------------------------------ */

function limparMensagem() {
    elementos.mensagem.hidden = true;
    elementos.mensagem.textContent = "";
}

// tipo: "erro" ou "ok". Usa textContent, então nunca interpreta HTML.
function mostrarMensagem(textos, tipo) {
    elementos.mensagem.textContent = "";
    elementos.mensagem.className = `mensagem mensagem-${tipo}`;

    const lista = document.createElement("ul");
    textos.forEach((texto) => {
        const item = document.createElement("li");
        item.textContent = texto;
        lista.appendChild(item);
    });

    elementos.mensagem.appendChild(lista);
    elementos.mensagem.hidden = false;
}

/* ------------------------------------------------------------------
 * TABELA
 * ------------------------------------------------------------------ */

// Busca sem diferenciar maiúsculas nem acentos ("maquina" acha "Máquina")
function normalizarTexto(texto) {
    return String(texto ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .trim();
}

function filtrarOrdens(lista) {
    const termo = normalizarTexto(elementos.busca.value);
    const status = elementos.filtroStatus.value;

    return lista.filter((ordem) => {
        if (status !== "" && ordem.status !== status) {
            return false;
        }
        if (termo === "") {
            return true;
        }
        const texto = normalizarTexto(
            `${formatarNumero(ordem.numero)} ${ordem.produto} ${ordem.maquina}`
        );
        return texto.includes(termo);
    });
}

// Monta o HTML de uma linha. Todo valor vindo dos dados passa por escaparHtml.
function montarLinha(ordem) {
    const id = escaparHtml(ordem.id);
    const numero = escaparHtml(formatarNumero(ordem.numero));
    const produto = escaparHtml(ordem.produto);

    const prioridade = PRIORIDADES[ordem.prioridade] ? ordem.prioridade : "media";
    const status = STATUS[ordem.status] ? ordem.status : "planejada";

    const observacoes = ordem.observacoes
        ? `<div class="obs">${escaparHtml(ordem.observacoes)}</div>`
        : "";
    const atrasada = estaAtrasada(ordem)
        ? ' <span class="selo selo-atrasada">Atrasada</span>'
        : "";

    return `
        <tr>
            <td class="col-numero">${numero}</td>
            <td class="col-produto">${produto}${observacoes}</td>
            <td class="num">${escaparHtml(formatarQuantidade(ordem.quantidade))}</td>
            <td class="col-maquina">${escaparHtml(ordem.maquina)}</td>
            <td><span class="selo selo-prioridade-${prioridade}">${PRIORIDADES[prioridade]}</span></td>
            <td class="col-data">${escaparHtml(formatarData(ordem.dataEntrega))}${atrasada}</td>
            <td><span class="selo selo-status-${status}">${STATUS[status]}</span></td>
            <td>
                <div class="acoes">
                    <button type="button" class="botao botao-pequeno" data-acao="editar" data-id="${id}" aria-label="Editar ordem ${numero}">Editar</button>
                    <button type="button" class="botao botao-pequeno botao-perigo" data-acao="excluir" data-id="${id}" aria-label="Excluir ordem ${numero}">Excluir</button>
                </div>
            </td>
        </tr>`;
}

function atualizarTabela() {
    // Mais recentes primeiro
    const lista = listar().sort((a, b) => (b.numero || 0) - (a.numero || 0));
    const visiveis = filtrarOrdens(lista);

    const atrasadas = lista.filter(estaAtrasada).length;
    let resumo = visiveis.length === lista.length
        ? `${lista.length} ordem(ns) cadastrada(s)`
        : `${visiveis.length} de ${lista.length} ordem(ns)`;
    if (atrasadas > 0) {
        resumo += ` · ${atrasadas} atrasada(s)`;
    }
    elementos.contador.textContent = resumo;

    if (visiveis.length === 0) {
        const texto = lista.length === 0
            ? "Nenhuma ordem de produção cadastrada"
            : "Nenhuma ordem encontrada com esses filtros";
        elementos.corpoTabela.innerHTML = `<tr><td colspan="8" class="vazio">${texto}</td></tr>`;
        return;
    }

    elementos.corpoTabela.innerHTML = visiveis.map(montarLinha).join("");
}

/* ------------------------------------------------------------------
 * EVENTOS
 * ------------------------------------------------------------------ */

// Um só formulário serve para criar e para editar: o campo escondido
// "id-edicao" diz qual dos dois casos é.
function aoEnviarFormulario(evento) {
    evento.preventDefault(); // não recarrega a página

    const dadosBrutos = lerFormulario();
    const erros = validar(dadosBrutos);
    if (erros.length > 0) {
        mostrarMensagem(erros, "erro");
        return;
    }

    const dados = converterDados(dadosBrutos);
    const idAtual = lerIdEdicao();
    const editando = idAtual !== null;

    let texto;
    if (editando) {
        if (!atualizar(idAtual, dados)) {
            return; // o aviso já foi dado por banco.js ou crud.js
        }
        texto = "Ordem atualizada com sucesso.";
    } else {
        const nova = criar(dados);
        if (nova === null) {
            return;
        }
        texto = `Ordem ${formatarNumero(nova.numero)} cadastrada com sucesso.`;
    }

    cancelarEdicao(); // limpa o formulário e volta ao modo "nova ordem"
    atualizarTabela();
    mostrarMensagem([texto], "ok");
}

// DELEGAÇÃO DE EVENTOS: um único ouvinte no tbody atende todos os botões,
// inclusive os das linhas criadas depois.
function aoClicarNaTabela(evento) {
    const botao = evento.target.closest("button[data-id]");
    if (!botao || !elementos.corpoTabela.contains(botao)) {
        return;
    }

    const id = Number(botao.dataset.id);
    if (botao.dataset.acao === "editar") {
        prepararEdicao(id);
    } else if (botao.dataset.acao === "excluir") {
        excluir(id);
    }
}

function iniciarInterface() {
    elementos.formulario.addEventListener("submit", aoEnviarFormulario);
    elementos.botaoCancelar.addEventListener("click", cancelarEdicao);
    elementos.corpoTabela.addEventListener("click", aoClicarNaTabela);
    elementos.busca.addEventListener("input", atualizarTabela);
    elementos.filtroStatus.addEventListener("change", atualizarTabela);

    // Se outra aba alterar as ordens ou as máquinas, esta se atualiza sozinha
    window.addEventListener("storage", (evento) => {
        if (evento.key === CHAVE) {
            atualizarTabela();
        } else if (evento.key === CHAVE_MAQUINAS && lerIdEdicao() === null) {
            const selecionada = elementos.maquina.value;
            preencherMaquinas();
            if (carregarMaquinas().includes(selecionada)) {
                elementos.maquina.value = selecionada;
            }
        }
    });

    limparFormulario();
    atualizarTabela();
}

iniciarInterface();
