// crud.js
// Regras do CRUD: criar, listar, atualizar, excluir e prepararEdicao.
// Os dados passam sempre por banco.js. As funções de tela que são chamadas
// daqui (atualizarTabela, mostrarMensagem...) ficam em interface.js, que é
// carregado depois, mas só são executadas quando o usuário clica em algo,
// quando todos os scripts já existem.

// Gera um id baseado no horário (Date.now). Se por acaso já existir na lista
// (dois cadastros no mesmo milissegundo), soma 1 até achar um livre.
function gerarId(lista) {
    let id = Date.now();
    while (lista.some((maquina) => maquina.id === id)) {
        id += 1;
    }
    return id;
}

function listar() {
    return carregarDados();
}

// Cadastra uma máquina. Recebe { nome, meta, ativa } e devolve o registro
// criado, ou null se não conseguiu gravar.
function criar(item) {
    const lista = listar();
    const novaMaquina = {
        id: gerarId(lista),
        nome: item.nome,
        meta: item.meta,
        ativa: item.ativa
    };

    lista.push(novaMaquina);

    // salva a lista toda; se falhar, nada foi alterado no armazenamento
    return salvarDados(lista) ? novaMaquina : null;
}

// Atualiza só os campos informados. O id é reaplicado no final para que
// ninguém consiga trocá-lo por engano através de novosDados.
function atualizar(id, novosDados) {
    const lista = listar();
    const indice = lista.findIndex((maquina) => maquina.id === id);

    if (indice === -1) {
        alert("Máquina não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return false;
    }

    lista[indice] = { ...lista[indice], ...novosDados, id };
    return salvarDados(lista);
}

// Pede confirmação (mostrando o nome) e remove a máquina da lista.
function excluir(id) {
    const lista = listar();
    const maquina = lista.find((item) => item.id === id);

    if (!maquina) {
        alert("Máquina não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return false;
    }

    // confirm() mostra texto puro, então o nome digitado não pode virar HTML aqui
    if (!confirm(`Excluir a máquina "${maquina.nome}"?`)) {
        return false;
    }

    const novaLista = lista.filter((item) => item.id !== id);
    if (!salvarDados(novaLista)) {
        return false;
    }

    // Se a máquina apagada estava aberta no formulário, a edição perde o sentido
    if (lerIdEdicao() === id) {
        cancelarEdicao();
    }

    atualizarTabela();
    mostrarMensagem(["Máquina excluída."], "ok");
    return true;
}

// Copia os valores atuais da máquina para o formulário e guarda o id no campo
// escondido "id-edicao". É esse campo que diferencia "editar" de "criar".
function prepararEdicao(id) {
    const maquina = listar().find((item) => item.id === id);

    if (!maquina) {
        alert("Máquina não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return;
    }

    document.getElementById("nome").value = String(maquina.nome ?? "");
    document.getElementById("meta").value = String(maquina.meta ?? "");
    document.getElementById(maquina.ativa ? "ativa-sim" : "ativa-nao").checked = true;
    document.getElementById("id-edicao").value = String(maquina.id);

    definirModoEdicao(true);
    document.getElementById("nome").focus();
}
