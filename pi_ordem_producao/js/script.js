// =====================================================================
// script.js — Módulo Ordem de Produção (MachSense)
//
// Organização do arquivo (de cima para baixo):
//   1. Constantes (endereço da API, status e transições)
//   2. Elementos da página e estado
//   3. Funções utilitárias (escaparHtml, formatarData...)
//   4. Comunicação com o servidor (fetch): carregar e enviar
//   5. Validação
//   6. CRUD: criar, atualizar, atualizarStatus, excluir
//   7. Tela: mensagens, formulário e listar
//   8. Importação das OPs antigas do navegador
//   9. Eventos (addEventListener) e início
//
// Os dados ficam no BANCO do sistema (tabela ordens_producao). Este script
// conversa com o arquivo ../ordens_producao_api.php por fetch(), em JSON.
// A validação daqui deixa a tela responder rápido, mas quem decide é o
// servidor: ele confere tudo de novo antes de gravar.
//
// Tudo é declarado com const ou let. const = o valor não é trocado;
// let = o valor muda depois (ex.: a lista de ordens).
// =====================================================================


// ---------------------------------------------------------------------
// 1. CONSTANTES
// ---------------------------------------------------------------------

// Endereço da API (".." sobe da pasta pi_ordem_producao para a raiz)
const URL_API = "../ordens_producao_api.php";

// Chave do localStorage usada pela versão ANTIGA do módulo. Só é lida para
// oferecer a importação das OPs que ficaram salvas neste navegador.
const CHAVE_ANTIGA = "pi_ordens_producao";

// Texto que aparece na tela para cada status. No banco fica só a chave
// (ex.: "em_andamento"), que não tem acento nem espaço.
const NOMES_STATUS = {
    planejada: "Planejada",
    em_andamento: "Em andamento",
    pausada: "Pausada",
    concluida: "Concluída",
    cancelada: "Cancelada"
};

// REGRA DE NEGÓCIO: para quais status cada status pode ir.
// Lista vazia = status final (não sai mais dele).
// A tela só mostra os botões que estão aqui, e o servidor confere de novo.
const TRANSICOES = {
    planejada: ["em_andamento", "cancelada"],
    em_andamento: ["pausada", "concluida", "cancelada"],
    pausada: ["em_andamento", "cancelada"],
    concluida: [],
    cancelada: []
};

// Texto do botão que leva a cada status
const ROTULO_BOTAO = {
    em_andamento: "Iniciar",
    pausada: "Pausar",
    concluida: "Concluir",
    cancelada: "Cancelar"
};

const PRODUTO_MIN = 2;
const PRODUTO_MAX = 60;


// ---------------------------------------------------------------------
// 2. ELEMENTOS DA PÁGINA E ESTADO
// ---------------------------------------------------------------------

const elementos = {
    form: document.getElementById("form-op"),
    tituloForm: document.getElementById("titulo-form"),
    idEdicao: document.getElementById("id-edicao"),
    numero: document.getElementById("numero"),
    produto: document.getElementById("produto"),
    quantidade: document.getElementById("quantidade"),
    maquina: document.getElementById("maquina"),
    inicio: document.getElementById("inicio"),
    termino: document.getElementById("termino"),
    btnSalvar: document.getElementById("btn-salvar"),
    btnCancelar: document.getElementById("btn-cancelar"),
    mensagemForm: document.getElementById("mensagem"),
    mensagemLista: document.getElementById("mensagem-lista"),
    listaOps: document.getElementById("lista-ops"),
    contador: document.getElementById("contador"),
    importar: document.getElementById("importar"),
    importarTexto: document.getElementById("importar-texto"),
    btnImportar: document.getElementById("btn-importar")
};

// Ids dos campos do formulário (usado para marcar/desmarcar erro)
const CAMPOS = ["numero", "produto", "quantidade", "maquina", "inicio", "termino"];

// Token contra CSRF que o PHP colocou na <meta name="csrf-token">
const TOKEN_CSRF = document.querySelector('meta[name="csrf-token"]').content;

// Cópia local do que veio do servidor. É trocada inteira a cada resposta.
let ordens = [];
let maquinas = [];

// true enquanto espera o servidor: evita clicar duas vezes no mesmo botão
let ocupado = false;


// ---------------------------------------------------------------------
// 3. FUNÇÕES UTILITÁRIAS
// ---------------------------------------------------------------------

// SEGURANÇA (XSS): troca os caracteres especiais do HTML por códigos
// inofensivos. Todo texto que vai para innerHTML passa por aqui; assim
// <img onerror=...> aparece como texto e não roda.
// O "&" é trocado primeiro; senão os "&" criados nas outras trocas
// seriam trocados de novo.
function escaparHtml(valor) {
    return String(valor)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}

// "2026-11-23" (formato do input date e do banco) -> "23/11/2026"
function formatarData(data) {
    if (typeof data !== "string" || data.length !== 10) {
        return "—";
    }
    const partes = data.split("-"); // ["2026", "11", "23"]
    return partes[2] + "/" + partes[1] + "/" + partes[0];
}

// O status é um dos 5 que conhecemos?
// Object.hasOwn confere se a chave foi escrita POR NÓS no objeto.
// Só TRANSICOES[status] não bastaria: todo objeto já vem com chaves
// "herdadas", como "toString".
function statusValido(status) {
    return Object.hasOwn(TRANSICOES, status);
}

// Para quais status esta OP pode ir (lista vazia se o status for estranho)
function proximosStatus(status) {
    if (!statusValido(status)) {
        return [];
    }
    return TRANSICOES[status];
}

// Texto do status para a tela
function nomeDoStatus(status) {
    if (!statusValido(status)) {
        return "Desconhecido";
    }
    return NOMES_STATUS[status];
}

// Status final = não tem para onde ir (lista vazia em TRANSICOES)
function ehFinal(status) {
    return proximosStatus(status).length === 0;
}

// Procura uma OP pelo id na cópia local
function acharOrdem(id) {
    return ordens.find(function (ordem) { return ordem.id === id; });
}

// Sugere o próximo número: maior número existente + 1, com 4 dígitos.
// slice(3) tira o "OP-"; padStart(4, "0") completa com zeros: 7 -> "0007".
function sugerirNumero() {
    let maior = 0;
    ordens.forEach(function (ordem) {
        const n = Number(String(ordem.numero).slice(3));
        if (Number.isInteger(n) && n > maior) {
            maior = n;
        }
    });
    return "OP-" + String(maior + 1).padStart(4, "0");
}


// ---------------------------------------------------------------------
// 4. COMUNICAÇÃO COM O SERVIDOR
//
// fetch() faz a requisição sem recarregar a página. Ele devolve uma
// Promise; com "await" o código espera a resposta antes de continuar
// (por isso as funções que usam await são "async").
// ---------------------------------------------------------------------

// Sessão expirada: o servidor responde 401 e mandamos para o login
function irParaLogin() {
    window.location.href = "../index.php";
}

// GET: busca as máquinas e as ordens. Devolve true se deu certo.
async function carregar() {
    // Mensagem padrão; se o servidor explicar o problema, usamos a dele
    let mensagem = "Não foi possível falar com o servidor. Confira se o XAMPP está ligado e recarregue a página.";
    try {
        const resposta = await fetch(URL_API, {
            credentials: "same-origin",
            cache: "no-store",
            headers: { Accept: "application/json" }
        });
        if (resposta.status === 401) {
            irParaLogin();
            return false;
        }
        const dados = await resposta.json();
        if (resposta.ok && dados.ok) {
            ordens = dados.ordens;
            maquinas = dados.maquinas;
            return true;
        }
        if (typeof dados.mensagem === "string") {
            mensagem = dados.mensagem;
        }
    } catch (erro) {
        console.warn("Não foi possível carregar as ordens:", erro);
    }
    elementos.listaOps.innerHTML = "";
    mostrarMensagem(elementos.mensagemLista, [mensagem], "erro");
    return false;
}

// POST: manda uma ação em JSON. Sempre devolve um objeto { ok, ... }:
// nunca lança erro, então quem chama só precisa olhar "ok".
// Se a resposta trouxer a lista de ordens, a cópia local é atualizada.
async function enviar(dados) {
    try {
        const resposta = await fetch(URL_API, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-CSRF-Token": TOKEN_CSRF
            },
            body: JSON.stringify(dados)
        });
        if (resposta.status === 401) {
            irParaLogin();
            return { ok: false, mensagem: "Sessão expirada." };
        }
        const corpo = await resposta.json();
        if (Array.isArray(corpo.ordens)) {
            ordens = corpo.ordens;
        }
        return corpo;
    } catch (erro) {
        console.warn("Falha ao enviar:", erro);
        return { ok: false, mensagem: "Não foi possível falar com o servidor. Tente novamente." };
    }
}


// ---------------------------------------------------------------------
// 5. VALIDAÇÃO (roda ANTES de enviar; o servidor confere de novo)
// ---------------------------------------------------------------------

// Lê o que está digitado no formulário, sem converter nada ainda
function lerFormulario() {
    return {
        numero: elementos.numero.value.trim().toUpperCase(),
        produto: elementos.produto.value.trim(),
        quantidade: elementos.quantidade.value.trim(),
        maquina: elementos.maquina.value,
        inicio: elementos.inicio.value,
        termino: elementos.termino.value
    };
}

// Confere cada campo. Devolve uma lista de erros; cada erro diz o campo e
// o texto. Lista vazia = está tudo certo.
// idIgnorado: na edição, a própria OP não conta como "número repetido".
function validar(dados, idIgnorado) {
    const erros = [];

    // Número: "OP-" seguido de exatamente 4 dígitos.
    // Expressão regular /^OP-\d{4}$/:  ^ = começo do texto,
    // \d{4} = quatro dígitos (0 a 9),  $ = fim do texto.
    if (!/^OP-\d{4}$/.test(dados.numero)) {
        erros.push({ campo: "numero", texto: "O número deve seguir o formato OP-0000 (ex.: OP-0042)." });
    } else {
        const repetido = ordens.some(function (ordem) {
            return ordem.id !== idIgnorado && String(ordem.numero).toUpperCase() === dados.numero;
        });
        if (repetido) {
            erros.push({ campo: "numero", texto: "Já existe uma OP com o número " + dados.numero + "." });
        }
    }

    // Artigo: de 2 a 60 caracteres (os espaços das pontas já foram tirados)
    if (dados.produto.length < PRODUTO_MIN || dados.produto.length > PRODUTO_MAX) {
        erros.push({ campo: "produto", texto: "O artigo deve ter de " + PRODUTO_MIN + " a " + PRODUTO_MAX + " caracteres." });
    }

    // Quantidade: inteiro maior que zero.
    // O input type="number" devolve "" quando o que foi digitado não é número.
    if (dados.quantidade === "") {
        erros.push({ campo: "quantidade", texto: "Informe a quantidade." });
    } else {
        const quantidade = Number(dados.quantidade);
        // Number.isInteger(2.5) é false; Number.isInteger(3) é true
        if (!Number.isInteger(quantidade) || quantidade <= 0) {
            erros.push({ campo: "quantidade", texto: "A quantidade deve ser um número inteiro maior que zero." });
        }
    }

    // Máquina: precisa ser uma das opções do <select> (máquinas do banco)
    const opcoes = Array.from(elementos.maquina.options).map(function (o) { return o.value; });
    if (dados.maquina === "" || !opcoes.includes(dados.maquina)) {
        erros.push({ campo: "maquina", texto: "Escolha uma das máquinas da lista." });
    }

    // Datas: o input date devolve "" se estiver vazio ou incompleto
    if (dados.inicio === "") {
        erros.push({ campo: "inicio", texto: "Informe a data de início." });
    }
    if (dados.termino === "") {
        erros.push({ campo: "termino", texto: "Informe a data de término." });
    }

    // Datas no formato AAAA-MM-DD podem ser comparadas como texto:
    // "2026-11-30" < "2026-12-01" dá o mesmo resultado que comparar as datas.
    if (dados.inicio !== "" && dados.termino !== "" && dados.termino < dados.inicio) {
        erros.push({ campo: "termino", texto: "O término não pode ser antes do início." });
    }

    return erros;
}

// Formato que o servidor espera (o artigo vai como "artigo" e a máquina pelo id)
function paraServidor(dados) {
    return {
        numero: dados.numero,
        artigo: dados.produto,
        quantidade: Number(dados.quantidade),
        maquina_id: Number(dados.maquina),
        inicio: dados.inicio,
        termino: dados.termino
    };
}


// ---------------------------------------------------------------------
// 6. CRUD (cada função devolve a resposta do servidor)
// ---------------------------------------------------------------------

// CREATE: cadastra uma OP nova. O servidor grava com status "planejada".
function criar(dados) {
    return enviar(Object.assign({ acao: "criar" }, paraServidor(dados)));
}

// UPDATE: altera os dados de uma OP. O status NÃO muda aqui
// (status só muda por atualizarStatus, que respeita as transições).
function atualizar(id, dados) {
    return enviar(Object.assign({ acao: "atualizar", id: id }, paraServidor(dados)));
}

// Muda o status respeitando o mapa TRANSICOES (o servidor confere de novo)
async function atualizarStatus(id, novoStatus) {
    const ordem = acharOrdem(id);
    if (ordem === undefined) {
        listar();
        return;
    }

    if (!proximosStatus(ordem.status).includes(novoStatus)) {
        mostrarMensagem(elementos.mensagemLista, [
            "Mudança não permitida: " + nomeDoStatus(ordem.status) +
            " → " + nomeDoStatus(novoStatus) + "."
        ], "erro");
        return;
    }

    // Concluída e Cancelada não têm volta: pede confirmação
    if (ehFinal(novoStatus)) {
        const certeza = confirm(
            "Mudar a " + ordem.numero + " para \"" + nomeDoStatus(novoStatus) + "\"?\n" +
            "Depois disso ela não poderá mais ser alterada."
        );
        if (!certeza) {
            return;
        }
    }

    const resposta = await enviar({ acao: "status", id: id, status: novoStatus });

    // Se a OP estava aberta no formulário e virou final, sai da edição
    if (resposta.ok && lerIdEdicao() === id && ehFinal(novoStatus)) {
        cancelarEdicao();
    }

    listar();
    mostrarMensagem(elementos.mensagemLista, [resposta.mensagem || "Não foi possível mudar o status."], resposta.ok ? "sucesso" : "erro");
}

// DELETE: remove a OP depois de confirmar com o usuário
async function excluir(id) {
    const ordem = acharOrdem(id);
    if (ordem === undefined) {
        listar();
        return;
    }

    // confirm() mostra OK/Cancelar e devolve true ou false.
    // Ele exibe texto puro, então aqui não há risco de XSS.
    if (!confirm("Excluir a " + ordem.numero + " (" + ordem.artigo + ")?")) {
        return;
    }

    const resposta = await enviar({ acao: "excluir", id: id });

    // Se a OP excluída estava aberta no formulário, a edição perde o sentido
    if (resposta.ok && lerIdEdicao() === id) {
        cancelarEdicao();
    }

    listar();
    mostrarMensagem(elementos.mensagemLista, [resposta.mensagem || "Não foi possível excluir."], resposta.ok ? "sucesso" : "erro");
}


// ---------------------------------------------------------------------
// 7. TELA
// ---------------------------------------------------------------------

// Mostra uma lista de textos na área de mensagem escolhida.
// tipo: "erro" ou "sucesso". Usa textContent, que nunca interpreta HTML.
function mostrarMensagem(area, textos, tipo) {
    area.textContent = "";
    area.className = "mensagem mensagem-" + tipo;

    const ul = document.createElement("ul");
    textos.forEach(function (texto) {
        const li = document.createElement("li");
        li.textContent = texto;
        ul.appendChild(li);
    });

    area.appendChild(ul);
    area.hidden = false;
}

function limparMensagem(area) {
    area.hidden = true;
    area.textContent = "";
}

// Marca em vermelho os campos com erro (aria-invalid também avisa o leitor de tela)
function marcarCampos(erros) {
    CAMPOS.forEach(function (campo) {
        elementos[campo].removeAttribute("aria-invalid");
    });
    erros.forEach(function (erro) {
        if (elementos[erro.campo]) {
            elementos[erro.campo].setAttribute("aria-invalid", "true");
        }
    });
}

// Cria as <option> do select com as máquinas ATIVAS do banco.
// idExtra: na edição, a máquina atual da OP entra mesmo se foi desativada.
function preencherMaquinas(idExtra) {
    elementos.maquina.textContent = "";

    const vazia = document.createElement("option");
    vazia.value = "";
    vazia.textContent = "Selecione…";
    elementos.maquina.appendChild(vazia);

    maquinas.forEach(function (maquina) {
        if (!maquina.ativa && maquina.id !== idExtra) {
            return;
        }
        const opcao = document.createElement("option");
        opcao.value = String(maquina.id);
        // textContent: o nome vem do banco, mas nunca vira HTML
        opcao.textContent = maquina.ativa ? maquina.nome : maquina.nome + " (inativa)";
        elementos.maquina.appendChild(opcao);
    });
}

// null = cadastrando; número = editando a OP com esse id
function lerIdEdicao() {
    const valor = elementos.idEdicao.value;
    if (valor === "") {
        return null;
    }
    return Number(valor);
}

// Deixa o formulário pronto para um novo cadastro
function cancelarEdicao() {
    elementos.form.reset();
    elementos.idEdicao.value = "";
    preencherMaquinas(null);
    elementos.numero.value = sugerirNumero();
    elementos.tituloForm.textContent = "Nova ordem de produção";
    elementos.btnSalvar.textContent = "Cadastrar OP";
    elementos.btnCancelar.hidden = true;
    marcarCampos([]);
}

// Copia os dados da OP para o formulário e entra no modo edição
function prepararEdicao(id) {
    const ordem = acharOrdem(id);

    if (ordem === undefined || ehFinal(ordem.status)) {
        listar();
        return;
    }

    preencherMaquinas(ordem.maquina_id);
    elementos.idEdicao.value = String(ordem.id);
    elementos.numero.value = ordem.numero;
    elementos.produto.value = ordem.artigo;
    elementos.quantidade.value = String(ordem.quantidade);
    elementos.maquina.value = String(ordem.maquina_id);
    elementos.inicio.value = ordem.inicio;
    elementos.termino.value = ordem.termino;

    elementos.tituloForm.textContent = "Editando " + ordem.numero;
    elementos.btnSalvar.textContent = "Salvar alterações";
    elementos.btnCancelar.hidden = false;
    marcarCampos([]);
    limparMensagem(elementos.mensagemForm);

    elementos.numero.focus(); // leva o usuário (e a tela) até o formulário
}

// Monta o HTML de UM card. Todo valor que veio do banco passa por
// escaparHtml antes de entrar no HTML.
function montarCard(ordem) {
    const id = escaparHtml(ordem.id);
    const numero = escaparHtml(ordem.numero);
    // Status estranho vira "desconhecido" (sem botões de transição)
    let status = "desconhecido";
    if (statusValido(ordem.status)) {
        status = ordem.status;
    }

    // Botões: um para cada transição permitida + Editar (se não for final) + Excluir.
    // Transição proibida não gera botão nenhum.
    let botoes = "";
    proximosStatus(status).forEach(function (destino) {
        let rotulo = ROTULO_BOTAO[destino];
        if (status === "pausada" && destino === "em_andamento") {
            rotulo = "Retomar";
        }
        // data-* guarda informações no botão; o JS lê com botao.dataset
        botoes += '<button type="button" class="botao" data-acao="status" data-status="' + destino +
                  '" data-id="' + id + '">' + rotulo + "</button>";
    });

    let aviso = "";
    if (ehFinal(status)) {
        aviso = '<p class="aviso-final">OP finalizada: não pode mais ser alterada.</p>';
    } else {
        botoes += '<button type="button" class="botao" data-acao="editar" data-id="' + id + '">Editar</button>';
    }
    botoes += '<button type="button" class="botao botao-perigo" data-acao="excluir" data-id="' + id + '">Excluir</button>';

    // A quantidade é exibida no formato brasileiro: 1500 -> "1.500"
    const quantidade = Number(ordem.quantidade).toLocaleString("pt-BR");

    // Template string (crase): permite quebrar linha e usar ${...} dentro do texto
    return `
        <li>
            <article class="card">
                <div class="card-topo">
                    <h3>${numero}</h3>
                    <span class="selo selo-${status}">${nomeDoStatus(status)}</span>
                </div>
                <p class="card-produto">${escaparHtml(ordem.artigo)}</p>
                <dl class="detalhes">
                    <dt>Quantidade</dt><dd>${escaparHtml(quantidade)} peças</dd>
                    <dt>Máquina</dt><dd>${escaparHtml(ordem.maquina)}</dd>
                    <dt>Início</dt><dd>${escaparHtml(formatarData(ordem.inicio))}</dd>
                    <dt>Término</dt><dd>${escaparHtml(formatarData(ordem.termino))}</dd>
                </dl>
                ${aviso}
                <div class="card-acoes">${botoes}</div>
            </article>
        </li>`;
}

// READ: desenha todos os cards com a cópia local (a mais nova primeiro,
// porque o servidor já manda ordenado do id maior para o menor)
function listar() {
    if (ordens.length === 1) {
        elementos.contador.textContent = "1 ordem";
    } else {
        elementos.contador.textContent = ordens.length + " ordens";
    }

    if (ordens.length === 0) {
        elementos.listaOps.innerHTML = '<li class="vazio">Nenhuma ordem de produção cadastrada.</li>';
        return;
    }

    let html = "";
    ordens.forEach(function (ordem) {
        html += montarCard(ordem);
    });
    elementos.listaOps.innerHTML = html;
}


// ---------------------------------------------------------------------
// 8. IMPORTAÇÃO DAS OPs ANTIGAS (localStorage -> banco)
//
// A versão anterior do módulo guardava as OPs só neste navegador. Se ainda
// houver OPs lá, mostramos um aviso com o botão "Importar para o banco".
// Depois de importar, a lista antiga é guardada com outro nome (cópia de
// segurança) e o aviso não aparece mais.
// ---------------------------------------------------------------------

function lerOrdensAntigas() {
    try {
        const dados = JSON.parse(localStorage.getItem(CHAVE_ANTIGA));
        if (!Array.isArray(dados)) {
            return [];
        }
        return dados.filter(function (item) {
            return item !== null && typeof item === "object";
        });
    } catch (erro) {
        return []; // JSON inválido ou armazenamento bloqueado
    }
}

function verificarImportacao() {
    const antigas = lerOrdensAntigas();
    if (antigas.length === 0) {
        return;
    }
    elementos.importarTexto.textContent =
        "Este navegador tem " + antigas.length + " OP(s) da versão antiga do módulo, " +
        "que salvava os dados só aqui. Importe para o banco para que todos vejam.";
    elementos.importar.hidden = false;
}

async function importarAntigas() {
    const antigas = lerOrdensAntigas();
    elementos.btnImportar.disabled = true;

    const resposta = await enviar({ acao: "importar", ordens: antigas.slice(0, 500) });

    elementos.btnImportar.disabled = false;
    if (!resposta.ok) {
        mostrarMensagem(elementos.mensagemLista, [resposta.mensagem || "Não foi possível importar."], "erro");
        return;
    }

    try {
        localStorage.setItem(CHAVE_ANTIGA + "_importadas", localStorage.getItem(CHAVE_ANTIGA));
        localStorage.removeItem(CHAVE_ANTIGA);
    } catch (erro) {
        console.warn("Não foi possível limpar o localStorage:", erro);
    }

    elementos.importar.hidden = true;
    cancelarEdicao();
    listar();
    mostrarMensagem(elementos.mensagemLista, [resposta.mensagem], "sucesso");
}


// ---------------------------------------------------------------------
// 9. EVENTOS
// ---------------------------------------------------------------------

// Envio do formulário: serve para cadastrar E para editar
async function aoEnviarFormulario(evento) {
    evento.preventDefault(); // impede o navegador de recarregar a página
    if (ocupado) {
        return;
    }

    const dados = lerFormulario();
    const idEdicao = lerIdEdicao();

    // 1º valida aqui; só envia se não houver nenhum erro
    const erros = validar(dados, idEdicao);
    marcarCampos(erros);
    if (erros.length > 0) {
        const textos = erros.map(function (erro) { return erro.texto; });
        mostrarMensagem(elementos.mensagemForm, textos, "erro");
        elementos[erros[0].campo].focus(); // leva o cursor ao 1º campo errado
        return;
    }

    ocupado = true;
    elementos.btnSalvar.disabled = true;
    const resposta = idEdicao === null ? await criar(dados) : await atualizar(idEdicao, dados);
    elementos.btnSalvar.disabled = false;
    ocupado = false;

    // O servidor recusou: mostra os erros dele (ex.: número usado por outra pessoa agora)
    if (!resposta.ok) {
        const errosServidor = Array.isArray(resposta.erros) ? resposta.erros : [];
        marcarCampos(errosServidor);
        const textos = errosServidor.length > 0
            ? errosServidor.map(function (erro) { return erro.texto; })
            : [resposta.mensagem || "Não foi possível salvar."];
        mostrarMensagem(elementos.mensagemForm, textos, "erro");
        listar();
        return;
    }

    cancelarEdicao();
    listar();
    mostrarMensagem(elementos.mensagemForm, [resposta.mensagem], "sucesso");
}

// DELEGAÇÃO DE EVENTOS: em vez de colocar um "ouvinte" em cada botão
// (e refazer isso toda vez que a lista é redesenhada), colocamos UM só
// na <ul>. O clique "sobe" do botão até a <ul>, e aqui descobrimos qual
// botão foi clicado. closest() procura o botão mais próximo do ponto
// clicado (o próprio elemento ou um "pai" dele).
async function aoClicarNaLista(evento) {
    const botao = evento.target.closest("button[data-acao]");
    if (botao === null || ocupado) {
        return; // clicou fora de um botão, ou ainda esperando o servidor
    }

    const id = Number(botao.dataset.id);
    const acao = botao.dataset.acao;

    limparMensagem(elementos.mensagemLista);

    if (acao === "editar") {
        prepararEdicao(id);
        return;
    }

    ocupado = true;
    botao.disabled = true;
    if (acao === "status") {
        await atualizarStatus(id, botao.dataset.status);
    } else if (acao === "excluir") {
        await excluir(id);
    }
    botao.disabled = false; // se a lista foi redesenhada, o botão já é outro
    ocupado = false;
}

async function iniciar() {
    elementos.form.addEventListener("submit", aoEnviarFormulario);
    elementos.btnCancelar.addEventListener("click", function () {
        cancelarEdicao();
        limparMensagem(elementos.mensagemForm);
    });
    elementos.listaOps.addEventListener("click", aoClicarNaLista);
    elementos.btnImportar.addEventListener("click", importarAntigas);

    if (await carregar()) {
        cancelarEdicao(); // formulário limpo, com o próximo número sugerido
        listar();
        verificarImportacao();
    }
}

iniciar();
