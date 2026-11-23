// =====================================================================
// script.js — Módulo Ordem de Produção (MachSense)
//
// Organização do arquivo (de cima para baixo):
//   1. Constantes (chave, máquinas, status e transições)
//   2. Elementos da página
//   3. Funções utilitárias (escaparHtml, formatarData...)
//   4. localStorage: carregar e salvar
//   5. Validação
//   6. CRUD: criar, atualizar, atualizarStatus, excluir
//   7. Tela: mensagens, formulário e listar
//   8. Eventos (addEventListener) e início
//
// Tudo é declarado com const ou let. const = o valor não é trocado;
// let = o valor muda depois (ex.: um contador).
// =====================================================================


// ---------------------------------------------------------------------
// 1. CONSTANTES
// ---------------------------------------------------------------------

// Nome da "gaveta" do localStorage onde a lista de OPs fica guardada
const CHAVE = "pi_ordens_producao";

// Lista fixa de máquinas (decisão do grupo). O <select> é montado a
// partir dela, e a validação confere se o valor escolhido está nela.
const MAQUINAS = ["Máquina 1", "Máquina 2", "Máquina 3"];

// Texto que aparece na tela para cada status. No localStorage guardamos
// só a chave (ex.: "em_andamento"), que não tem acento nem espaço.
const NOMES_STATUS = {
    planejada: "Planejada",
    em_andamento: "Em andamento",
    pausada: "Pausada",
    concluida: "Concluída",
    cancelada: "Cancelada"
};

// REGRA DE NEGÓCIO: para quais status cada status pode ir.
// Lista vazia = status final (não sai mais dele).
// A tela só mostra os botões que estão aqui, e atualizarStatus() confere
// de novo antes de gravar.
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
// 2. ELEMENTOS DA PÁGINA (buscados uma vez só e guardados num objeto)
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
    contador: document.getElementById("contador")
};

// Ids dos campos do formulário (usado para marcar/desmarcar erro)
const CAMPOS = ["numero", "produto", "quantidade", "maquina", "inicio", "termino"];

// Evita repetir o aviso de "dados corrompidos" a cada leitura
let avisouDadosCorrompidos = false;


// ---------------------------------------------------------------------
// 3. FUNÇÕES UTILITÁRIAS
// ---------------------------------------------------------------------

// SEGURANÇA (XSS): troca os caracteres especiais do HTML por códigos
// inofensivos. Todo texto digitado pelo usuário que vai para innerHTML
// passa por aqui; assim <img onerror=...> aparece como texto e não roda.
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

// "2026-11-23" (formato do input date) -> "23/11/2026"
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
// "herdadas", como "toString", e um dado adulterado no localStorage
// com status "toString" enganaria o código.
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

// Status final = não tem para onde ir (lista vazia em TRANSICOES).
// Um status desconhecido também é tratado como final, para não oferecer
// botões que não fazem sentido.
function ehFinal(status) {
    return proximosStatus(status).length === 0;
}

// Gera um id numérico a partir do relógio (milissegundos desde 1970).
// Se dois cadastros caírem no mesmo milissegundo, soma 1 até ficar único.
function gerarId(lista) {
    let id = Date.now();
    while (lista.some(function (ordem) { return ordem.id === id; })) {
        id = id + 1;
    }
    return id;
}

// Sugere o próximo número: maior número existente + 1, com 4 dígitos.
// slice(3) tira o "OP-"; padStart(4, "0") completa com zeros: 7 -> "0007".
function sugerirNumero(lista) {
    let maior = 0;
    lista.forEach(function (ordem) {
        const n = Number(String(ordem.numero).slice(3));
        if (Number.isInteger(n) && n > maior) {
            maior = n;
        }
    });
    return "OP-" + String(maior + 1).padStart(4, "0");
}


// ---------------------------------------------------------------------
// 4. LOCALSTORAGE: carregar e salvar
//
// O localStorage só guarda TEXTO. Por isso:
//   - para salvar: JSON.stringify(lista) transforma a lista em texto;
//   - para ler:    JSON.parse(texto) transforma o texto em lista de novo.
// Tudo fica dentro de try/catch: se o texto estiver corrompido, ou se o
// navegador bloquear o armazenamento, o erro é "pego" e a página continua
// funcionando em vez de travar.
// ---------------------------------------------------------------------

function carregar() {
    try {
        const texto = localStorage.getItem(CHAVE);

        // Primeira visita: a chave ainda não existe
        if (texto === null) {
            return [];
        }

        const dados = JSON.parse(texto); // lança erro se o JSON for inválido

        // JSON válido mas que não é uma lista (ex.: "42" ou "{}")
        if (!Array.isArray(dados)) {
            throw new Error("O conteúdo salvo não é uma lista.");
        }

        // Descarta itens que não são objetos (ex.: null, números, textos)
        return dados.filter(function (item) {
            return item !== null && typeof item === "object";
        });
    } catch (erro) {
        console.warn("Não foi possível ler as ordens salvas:", erro);
        if (!avisouDadosCorrompidos) {
            avisouDadosCorrompidos = true;
            mostrarMensagem(elementos.mensagemLista, [
                "Os dados salvos estavam corrompidos e foram ignorados. " +
                "Ao cadastrar uma nova OP, eles serão substituídos."
            ], "erro");
        }
        return [];
    }
}

// Grava a lista INTEIRA de uma vez. Nunca fica salvo "meio cadastro".
// Devolve true se deu certo e false se falhou (ex.: armazenamento cheio).
function salvar(lista) {
    try {
        localStorage.setItem(CHAVE, JSON.stringify(lista));
        return true;
    } catch (erro) {
        console.warn("Não foi possível salvar:", erro);
        return false;
    }
}


// ---------------------------------------------------------------------
// 5. VALIDAÇÃO (roda ANTES de qualquer gravação)
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
    const lista = carregar();

    // Número: "OP-" seguido de exatamente 4 dígitos.
    // Expressão regular /^OP-\d{4}$/:  ^ = começo do texto,
    // \d{4} = quatro dígitos (0 a 9),  $ = fim do texto.
    if (!/^OP-\d{4}$/.test(dados.numero)) {
        erros.push({ campo: "numero", texto: "O número deve seguir o formato OP-0000 (ex.: OP-0042)." });
    } else {
        const repetido = lista.some(function (ordem) {
            return ordem.id !== idIgnorado && String(ordem.numero).toUpperCase() === dados.numero;
        });
        if (repetido) {
            erros.push({ campo: "numero", texto: "Já existe uma OP com o número " + dados.numero + "." });
        }
    }

    // Produto: de 2 a 60 caracteres (os espaços das pontas já foram tirados)
    if (dados.produto.length < PRODUTO_MIN || dados.produto.length > PRODUTO_MAX) {
        erros.push({ campo: "produto", texto: "O produto deve ter de " + PRODUTO_MIN + " a " + PRODUTO_MAX + " caracteres." });
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

    // Máquina: precisa ser uma das 3 da lista fixa
    if (!MAQUINAS.includes(dados.maquina)) {
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


// ---------------------------------------------------------------------
// 6. CRUD
// ---------------------------------------------------------------------

// CREATE: cadastra uma OP nova. Toda OP nasce com status "planejada".
function criar(dados) {
    const lista = carregar();

    const novaOrdem = {
        id: gerarId(lista),
        numero: dados.numero,
        produto: dados.produto,
        quantidade: Number(dados.quantidade),
        inicio: dados.inicio,
        termino: dados.termino,
        maquina: dados.maquina,
        status: "planejada"
    };

    lista.push(novaOrdem);
    return salvar(lista); // grava a lista inteira de uma vez
}

// UPDATE: altera os dados de uma OP. O id e o status NÃO mudam aqui
// (status só muda por atualizarStatus, que respeita as transições).
function atualizar(id, dados) {
    const lista = carregar();
    const ordem = lista.find(function (item) { return item.id === id; });

    if (ordem === undefined) {
        return "A OP não foi encontrada. Ela pode ter sido excluída.";
    }
    if (ehFinal(ordem.status)) {
        return "Esta OP já foi finalizada e não pode mais ser editada.";
    }

    ordem.numero = dados.numero;
    ordem.produto = dados.produto;
    ordem.quantidade = Number(dados.quantidade);
    ordem.inicio = dados.inicio;
    ordem.termino = dados.termino;
    ordem.maquina = dados.maquina;

    if (!salvar(lista)) {
        return "Não foi possível salvar. Tente novamente.";
    }
    return ""; // texto vazio = deu certo
}

// Muda o status respeitando o mapa TRANSICOES.
// Mesmo que alguém chame esta função pelo console com uma transição
// proibida (ex.: Planejada -> Concluída), ela recusa.
function atualizarStatus(id, novoStatus) {
    const lista = carregar();
    const ordem = lista.find(function (item) { return item.id === id; });

    if (ordem === undefined) {
        mostrarMensagem(elementos.mensagemLista, ["A OP não foi encontrada. Ela pode ter sido excluída."], "erro");
        listar();
        return false;
    }

    const permitidos = proximosStatus(ordem.status);
    if (!permitidos.includes(novoStatus)) {
        mostrarMensagem(elementos.mensagemLista, [
            "Mudança não permitida: " + nomeDoStatus(ordem.status) +
            " → " + nomeDoStatus(novoStatus) + "."
        ], "erro");
        return false;
    }

    // Concluída e Cancelada não têm volta: pede confirmação
    if (ehFinal(novoStatus)) {
        const certeza = confirm(
            "Mudar a " + ordem.numero + " para \"" + nomeDoStatus(novoStatus) + "\"?\n" +
            "Depois disso ela não poderá mais ser alterada."
        );
        if (!certeza) {
            return false;
        }
    }

    ordem.status = novoStatus;
    if (!salvar(lista)) {
        mostrarMensagem(elementos.mensagemLista, ["Não foi possível salvar. Tente novamente."], "erro");
        return false;
    }

    // Se a OP estava aberta no formulário e virou final, sai da edição
    if (lerIdEdicao() === id && ehFinal(novoStatus)) {
        cancelarEdicao();
    }

    listar();
    mostrarMensagem(elementos.mensagemLista, [ordem.numero + " agora está \"" + nomeDoStatus(novoStatus) + "\"."], "sucesso");
    return true;
}

// DELETE: remove a OP depois de confirmar com o usuário
function excluir(id) {
    const lista = carregar();
    const ordem = lista.find(function (item) { return item.id === id; });

    if (ordem === undefined) {
        listar();
        return false;
    }

    // confirm() mostra OK/Cancelar e devolve true ou false.
    // Ele exibe texto puro, então aqui não há risco de XSS.
    if (!confirm("Excluir a " + ordem.numero + " (" + ordem.produto + ")?")) {
        return false;
    }

    // filter cria uma lista NOVA sem a OP escolhida
    const novaLista = lista.filter(function (item) { return item.id !== id; });
    if (!salvar(novaLista)) {
        mostrarMensagem(elementos.mensagemLista, ["Não foi possível excluir. Tente novamente."], "erro");
        return false;
    }

    // Se a OP excluída estava aberta no formulário, a edição perde o sentido
    if (lerIdEdicao() === id) {
        cancelarEdicao();
    }

    listar();
    mostrarMensagem(elementos.mensagemLista, [ordem.numero + " excluída."], "sucesso");
    return true;
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
        elementos[erro.campo].setAttribute("aria-invalid", "true");
    });
}

// Cria as <option> do select a partir da constante MAQUINAS
function preencherMaquinas() {
    const vazia = document.createElement("option");
    vazia.value = "";
    vazia.textContent = "Selecione…";
    elementos.maquina.appendChild(vazia);

    MAQUINAS.forEach(function (nome) {
        const opcao = document.createElement("option");
        opcao.value = nome;
        opcao.textContent = nome;
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
    elementos.numero.value = sugerirNumero(carregar());
    elementos.tituloForm.textContent = "Nova ordem de produção";
    elementos.btnSalvar.textContent = "Cadastrar OP";
    elementos.btnCancelar.hidden = true;
    marcarCampos([]);
}

// Copia os dados da OP para o formulário e entra no modo edição
function prepararEdicao(id) {
    const ordem = carregar().find(function (item) { return item.id === id; });

    if (ordem === undefined || ehFinal(ordem.status)) {
        listar();
        return;
    }

    elementos.idEdicao.value = String(ordem.id);
    elementos.numero.value = ordem.numero;
    elementos.produto.value = ordem.produto;
    elementos.quantidade.value = String(ordem.quantidade);
    elementos.maquina.value = ordem.maquina;
    elementos.inicio.value = ordem.inicio;
    elementos.termino.value = ordem.termino;

    elementos.tituloForm.textContent = "Editando " + ordem.numero;
    elementos.btnSalvar.textContent = "Salvar alterações";
    elementos.btnCancelar.hidden = false;
    marcarCampos([]);
    limparMensagem(elementos.mensagemForm);

    elementos.numero.focus(); // leva o usuário (e a tela) até o formulário
}

// Monta o HTML de UM card. Todo valor que veio do usuário passa por
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
                <p class="card-produto">${escaparHtml(ordem.produto)}</p>
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

// READ: lê o localStorage e desenha todos os cards na tela
function listar() {
    const lista = carregar();

    if (lista.length === 1) {
        elementos.contador.textContent = "1 ordem";
    } else {
        elementos.contador.textContent = lista.length + " ordens";
    }

    if (lista.length === 0) {
        elementos.listaOps.innerHTML = '<li class="vazio">Nenhuma ordem de produção cadastrada.</li>';
        return;
    }

    // slice() faz uma cópia e reverse() inverte: a OP mais nova aparece primeiro
    let html = "";
    lista.slice().reverse().forEach(function (ordem) {
        html += montarCard(ordem);
    });
    elementos.listaOps.innerHTML = html;
}


// ---------------------------------------------------------------------
// 8. EVENTOS
// ---------------------------------------------------------------------

// Envio do formulário: serve para cadastrar E para editar
function aoEnviarFormulario(evento) {
    evento.preventDefault(); // impede o navegador de recarregar a página

    const dados = lerFormulario();
    const idEdicao = lerIdEdicao();

    // 1º valida; só grava se não houver nenhum erro
    const erros = validar(dados, idEdicao);
    marcarCampos(erros);
    if (erros.length > 0) {
        const textos = erros.map(function (erro) { return erro.texto; });
        mostrarMensagem(elementos.mensagemForm, textos, "erro");
        elementos[erros[0].campo].focus(); // leva o cursor ao 1º campo errado
        return;
    }

    if (idEdicao === null) {
        if (!criar(dados)) {
            mostrarMensagem(elementos.mensagemForm, ["Não foi possível salvar. Tente novamente."], "erro");
            return;
        }
        cancelarEdicao();
        listar();
        mostrarMensagem(elementos.mensagemForm, [dados.numero + " cadastrada como \"Planejada\"."], "sucesso");
    } else {
        const problema = atualizar(idEdicao, dados);
        if (problema !== "") {
            mostrarMensagem(elementos.mensagemForm, [problema], "erro");
            cancelarEdicao();
            listar();
            return;
        }
        cancelarEdicao();
        listar();
        mostrarMensagem(elementos.mensagemForm, [dados.numero + " atualizada."], "sucesso");
    }
}

// DELEGAÇÃO DE EVENTOS: em vez de colocar um "ouvinte" em cada botão
// (e refazer isso toda vez que a lista é redesenhada), colocamos UM só
// na <ul>. O clique "sobe" do botão até a <ul>, e aqui descobrimos qual
// botão foi clicado. closest() procura o botão mais próximo do ponto
// clicado (o próprio elemento ou um "pai" dele).
function aoClicarNaLista(evento) {
    const botao = evento.target.closest("button[data-acao]");
    if (botao === null) {
        return; // clicou fora de um botão
    }

    const id = Number(botao.dataset.id);
    const acao = botao.dataset.acao;

    limparMensagem(elementos.mensagemLista);

    if (acao === "status") {
        atualizarStatus(id, botao.dataset.status);
    } else if (acao === "editar") {
        prepararEdicao(id);
    } else if (acao === "excluir") {
        excluir(id);
    }
}

function iniciar() {
    preencherMaquinas();
    cancelarEdicao(); // formulário limpo, com o próximo número sugerido
    listar();

    elementos.form.addEventListener("submit", aoEnviarFormulario);
    elementos.btnCancelar.addEventListener("click", function () {
        cancelarEdicao();
        limparMensagem(elementos.mensagemForm);
    });
    elementos.listaOps.addEventListener("click", aoClicarNaLista);
}

iniciar();
