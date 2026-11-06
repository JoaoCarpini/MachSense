// interface.js
// Tudo que mexe na tela: ler o formulário, validar, desenhar a tabela e
// ligar os eventos. Carregado por último, quando o HTML já existe.

const NOME_MIN = 2;
const NOME_MAX = 60;

// Os elementos da página são buscados uma única vez e guardados aqui
const elementos = {
    formulario: document.getElementById("formulario"),
    nome: document.getElementById("nome"),
    meta: document.getElementById("meta"),
    idEdicao: document.getElementById("id-edicao"),
    titulo: document.getElementById("titulo-formulario"),
    botaoSalvar: document.getElementById("botao-salvar"),
    botaoCancelar: document.getElementById("botao-cancelar"),
    mensagem: document.getElementById("mensagem"),
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

// Troca os caracteres que o HTML interpreta por versões inofensivas. Tudo que
// o usuário digitou e volta para a tela via innerHTML TEM de passar por aqui;
// sem isso, um nome como <img src=x onerror="..."> executaria código (XSS).
function escaparHtml(valor) {
    return String(valor ?? "").replace(/[&<>"']/g, (caractere) => MAPA_HTML[caractere]);
}

/* ------------------------------------------------------------------
 * VALIDAÇÃO
 * ------------------------------------------------------------------ */

// Compara nomes sem diferenciar maiúscula de minúscula (e sem espaços nas pontas)
function normalizarNome(nome) {
    return String(nome ?? "").normalize("NFC").trim().toLowerCase();
}

// Já existe OUTRA máquina com esse nome? Ao editar, o próprio registro (idAtual)
// é ignorado, senão salvar sem mudar o nome daria "duplicado".
function nomeJaExiste(nome, idAtual) {
    const procurado = normalizarNome(nome);
    return listar().some(
        (maquina) => maquina.id !== idAtual && normalizarNome(maquina.nome) === procurado
    );
}

// Confere os campos ANTES de gravar. Recebe os textos crus do formulário e
// devolve a lista de mensagens de erro (lista vazia = tudo certo).
function validar(dados, idAtual) {
    const erros = [];

    const nome = dados.nome.trim();
    const tamanho = Array.from(nome).length; // conta emoji como 1 caractere
    if (tamanho < NOME_MIN || tamanho > NOME_MAX) {
        erros.push(`O nome deve ter de ${NOME_MIN} a ${NOME_MAX} caracteres.`);
    } else if (/[\u0000-\u001F\u007F]/.test(nome)) {
        erros.push("O nome contém caracteres inválidos.");
    } else if (nomeJaExiste(nome, idAtual)) {
        erros.push("Já existe uma máquina com este nome.");
    }

    // Meta: de 0 a 100, com no máximo 2 casas (aceita vírgula ou ponto).
    // A expressão regular recusa vazio, letras, sinal de menos e 3 casas.
    const meta = dados.meta.trim().replace(",", ".");
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(meta) || Number(meta) > 100) {
        erros.push("A meta deve ser um número de 0 a 100, com no máximo 2 casas decimais.");
    }

    if (dados.ativa !== "sim" && dados.ativa !== "nao") {
        erros.push("Informe se a máquina está ativa (sim ou não).");
    }

    return erros;
}

/* ------------------------------------------------------------------
 * FORMULÁRIO
 * ------------------------------------------------------------------ */

// Lê os campos como texto cru (a conversão só acontece depois de validar)
function lerFormulario() {
    const marcado = elementos.formulario.querySelector('input[name="ativa"]:checked');
    return {
        nome: elementos.nome.value,
        meta: elementos.meta.value,
        ativa: marcado ? marcado.value : ""
    };
}

// Converte os textos já validados para os tipos guardados (string, number, boolean)
function converterDados(dados) {
    return {
        nome: dados.nome.trim(),
        meta: Number(dados.meta.trim().replace(",", ".")),
        ativa: dados.ativa === "sim"
    };
}

// null = formulário em modo "nova máquina"; número = editando aquele id
function lerIdEdicao() {
    const valor = elementos.idEdicao.value;
    return valor === "" ? null : Number(valor);
}

// Alterna o formulário entre "Nova máquina" e "Editando máquina"
function definirModoEdicao(editando) {
    elementos.titulo.textContent = editando ? "Editando máquina" : "Nova máquina";
    elementos.botaoSalvar.textContent = editando ? "Salvar alterações" : "Cadastrar máquina";
    elementos.botaoCancelar.hidden = !editando;
    elementos.formulario.classList.toggle("editando", editando);
    limparMensagem();
}

function limparFormulario() {
    elementos.formulario.reset(); // volta "Sim" como padrão
    elementos.idEdicao.value = "";
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

function formatarMeta(meta) {
    if (typeof meta !== "number" || Number.isNaN(meta)) {
        return "—";
    }
    return `${meta.toLocaleString("pt-BR", { maximumFractionDigits: 2 })}%`;
}

// Monta o HTML de uma linha. Todo valor vindo dos dados passa por escaparHtml.
function montarLinha(maquina) {
    const nome = escaparHtml(maquina.nome);
    const id = escaparHtml(maquina.id);
    const situacao = maquina.ativa === true
        ? '<span class="selo selo-ok">Sim</span>'
        : '<span class="selo">Não</span>';

    return `
        <tr>
            <td class="col-nome">${nome}</td>
            <td class="num">${escaparHtml(formatarMeta(maquina.meta))}</td>
            <td>${situacao}</td>
            <td>
                <div class="acoes">
                    <button type="button" class="botao botao-pequeno" data-acao="editar" data-id="${id}" aria-label="Editar ${nome}">Editar</button>
                    <button type="button" class="botao botao-pequeno botao-perigo" data-acao="excluir" data-id="${id}" aria-label="Excluir ${nome}">Excluir</button>
                </div>
            </td>
        </tr>`;
}

function atualizarTabela() {
    const lista = listar();
    elementos.contador.textContent = `${lista.length} máquina(s) cadastrada(s)`;

    if (lista.length === 0) {
        elementos.corpoTabela.innerHTML =
            '<tr><td colspan="4" class="vazio">Nenhuma máquina cadastrada</td></tr>';
        return;
    }

    let html = "";
    lista.forEach((maquina) => {
        html += montarLinha(maquina);
    });
    elementos.corpoTabela.innerHTML = html;
}

/* ------------------------------------------------------------------
 * EVENTOS
 * ------------------------------------------------------------------ */

// Um só formulário serve para criar e para editar: o campo escondido
// "id-edicao" diz qual dos dois casos é.
function aoEnviarFormulario(evento) {
    evento.preventDefault(); // não recarrega a página

    const dadosBrutos = lerFormulario();
    const idAtual = lerIdEdicao();

    const erros = validar(dadosBrutos, idAtual);
    if (erros.length > 0) {
        mostrarMensagem(erros, "erro");
        return;
    }

    const dados = converterDados(dadosBrutos);
    const editando = idAtual !== null;
    const gravou = editando ? atualizar(idAtual, dados) : criar(dados) !== null;

    if (!gravou) {
        return; // o aviso já foi dado por banco.js ou crud.js
    }

    cancelarEdicao(); // limpa o formulário e volta ao modo "nova máquina"
    atualizarTabela();
    mostrarMensagem(
        [editando ? "Máquina atualizada com sucesso." : "Máquina cadastrada com sucesso."],
        "ok"
    );
}

// DELEGAÇÃO DE EVENTOS: um único "ouvinte" no tbody atende todos os botões,
// inclusive os das linhas que forem criadas depois (não precisa religar nada
// a cada redesenho da tabela).
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
    atualizarTabela();
}

iniciarInterface();
