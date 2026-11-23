// crud.js
// Regras do CRUD: criar, listar, atualizar, excluir e prepararEdicao.
// Os dados passam sempre por banco.js. As funções de tela chamadas daqui
// (atualizarTabela, mostrarMensagem...) ficam em interface.js, que é carregado
// depois, mas só rodam quando o usuário clica em algo.

// Gera um id baseado no horário. Se já existir (dois cadastros no mesmo
// milissegundo), soma 1 até achar um livre.
function gerarId(lista) {
    let id = Date.now();
    while (lista.some((ordem) => ordem.id === id)) {
        id += 1;
    }
    return id;
}

function listar() {
    return carregarDados();
}

// Cadastra uma ordem. Recebe os campos já validados e devolve o registro
// criado, ou null se não conseguiu gravar.
function criar(item) {
    const lista = listar();
    const novaOrdem = {
        id: gerarId(lista),
        numero: proximoNumero(lista),
        produto: item.produto,
        quantidade: item.quantidade,
        maquina: item.maquina,
        prioridade: item.prioridade,
        status: item.status,
        dataInicio: item.dataInicio,
        dataEntrega: item.dataEntrega,
        observacoes: item.observacoes,
        criadaEm: new Date().toISOString()
    };

    lista.push(novaOrdem);

    if (!salvarDados(lista)) {
        return null;
    }
    confirmarNumero(novaOrdem.numero);
    return novaOrdem;
}

// Atualiza só os campos informados. id, número e data de criação são
// reaplicados no final para que nunca mudem numa edição.
function atualizar(id, novosDados) {
    const lista = listar();
    const indice = lista.findIndex((ordem) => ordem.id === id);

    if (indice === -1) {
        alert("Ordem não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return false;
    }

    const atual = lista[indice];
    lista[indice] = {
        ...atual,
        ...novosDados,
        id: atual.id,
        numero: atual.numero,
        criadaEm: atual.criadaEm
    };
    return salvarDados(lista);
}

// Pede confirmação (mostrando número e produto) e remove a ordem.
function excluir(id) {
    const lista = listar();
    const ordem = lista.find((item) => item.id === id);

    if (!ordem) {
        alert("Ordem não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return false;
    }

    // confirm() mostra texto puro, então o produto não vira HTML aqui
    if (!confirm(`Excluir a ordem ${formatarNumero(ordem.numero)} (${ordem.produto})?`)) {
        return false;
    }

    if (!salvarDados(lista.filter((item) => item.id !== id))) {
        return false;
    }

    // Se a ordem apagada estava aberta no formulário, a edição perde o sentido
    if (lerIdEdicao() === id) {
        cancelarEdicao();
    }

    atualizarTabela();
    mostrarMensagem([`Ordem ${formatarNumero(ordem.numero)} excluída.`], "ok");
    return true;
}

// Copia os valores da ordem para o formulário e guarda o id no campo
// escondido "id-edicao". É esse campo que diferencia "editar" de "criar".
function prepararEdicao(id) {
    const ordem = listar().find((item) => item.id === id);

    if (!ordem) {
        alert("Ordem não encontrada. Ela pode já ter sido excluída.");
        atualizarTabela();
        return;
    }

    // Garante que a máquina da ordem esteja na lista, mesmo que tenha sido
    // desativada ou renomeada no cadastro de máquinas depois.
    preencherMaquinas(ordem.maquina);

    elementos.produto.value = String(ordem.produto ?? "");
    elementos.quantidade.value = String(ordem.quantidade ?? "");
    elementos.maquina.value = String(ordem.maquina ?? "");
    elementos.dataInicio.value = String(ordem.dataInicio ?? "");
    elementos.dataEntrega.value = String(ordem.dataEntrega ?? "");
    elementos.prioridade.value = PRIORIDADES[ordem.prioridade] ? ordem.prioridade : "media";
    elementos.status.value = STATUS[ordem.status] ? ordem.status : "planejada";
    elementos.observacoes.value = String(ordem.observacoes ?? "");
    elementos.idEdicao.value = String(ordem.id);

    definirModoEdicao(true, ordem.numero);
    elementos.formulario.scrollIntoView({ behavior: "smooth", block: "start" });
    elementos.produto.focus({ preventScroll: true });
}
