// banco.js
// Único arquivo que lê e grava no localStorage (equivale ao conexao.php do PI).
// Se um dia o armazenamento mudar, só este arquivo precisa ser trocado.

// A leitura acontece várias vezes (tabela, filtros...), mas se o dado salvo
// estiver corrompido o usuário é avisado uma única vez.
let avisoLeituraMostrado = false;

// Cada registro precisa ser um objeto. Qualquer outra coisa dentro da lista
// (número, texto, null) é descartada para não quebrar a tela.
function ehRegistroValido(item) {
    return item !== null && typeof item === "object" && !Array.isArray(item);
}

// Lê a lista de ordens. Nunca lança erro: se não houver dados ou se o JSON
// estiver corrompido, devolve uma lista vazia.
function carregarDados() {
    try {
        const texto = localStorage.getItem(CHAVE);
        if (texto === null) {
            return [];
        }

        const dados = JSON.parse(texto);
        if (!Array.isArray(dados)) {
            throw new Error("O valor salvo não é uma lista.");
        }

        return dados.filter(ehRegistroValido);
    } catch (erro) {
        console.error("Erro ao ler os dados do localStorage:", erro);
        if (!avisoLeituraMostrado) {
            avisoLeituraMostrado = true;
            alert("Não foi possível ler as ordens salvas. A lista será exibida vazia.");
        }
        return [];
    }
}

// Grava a lista INTEIRA de uma vez (nunca fica salvo "só um pedaço").
// Devolve true se gravou e false se falhou (ex.: armazenamento cheio).
function salvarDados(lista) {
    try {
        localStorage.setItem(CHAVE, JSON.stringify(lista));
        return true;
    } catch (erro) {
        console.error("Erro ao gravar os dados no localStorage:", erro);
        alert("Não foi possível salvar os dados. Tente novamente.");
        return false;
    }
}

// Devolve o próximo número de ordem SEM gravar. O contador só avança em
// confirmarNumero(), depois que a ordem foi salva com sucesso.
// Usa o maior entre o contador e os números já existentes, para continuar
// certo mesmo se o contador tiver sido apagado à mão.
function proximoNumero(lista) {
    let ultimo = 0;
    try {
        ultimo = Number(localStorage.getItem(CHAVE_SEQUENCIA)) || 0;
    } catch (erro) {
        console.error("Erro ao ler o contador de ordens:", erro);
    }

    lista.forEach((ordem) => {
        if (Number.isInteger(ordem.numero) && ordem.numero > ultimo) {
            ultimo = ordem.numero;
        }
    });

    return ultimo + 1;
}

function confirmarNumero(numero) {
    try {
        localStorage.setItem(CHAVE_SEQUENCIA, String(numero));
    } catch (erro) {
        // Não é grave: proximoNumero() também olha os números da lista
        console.error("Erro ao gravar o contador de ordens:", erro);
    }
}

// Nomes das máquinas ativas do CRUD de máquinas. Se a chave não existir,
// estiver corrompida ou sem máquinas ativas, usa MAQUINAS_PADRAO.
function carregarMaquinas() {
    try {
        const dados = JSON.parse(localStorage.getItem(CHAVE_MAQUINAS));
        if (Array.isArray(dados)) {
            const nomes = dados
                .filter((m) => ehRegistroValido(m) && m.ativa === true && typeof m.nome === "string")
                .map((m) => m.nome.trim())
                .filter((nome) => nome !== "");
            if (nomes.length > 0) {
                return nomes;
            }
        }
    } catch (erro) {
        console.error("Erro ao ler as máquinas do localStorage:", erro);
    }
    return MAQUINAS_PADRAO.slice();
}
