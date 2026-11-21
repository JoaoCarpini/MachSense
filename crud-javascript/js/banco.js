// banco.js
// Único arquivo que lê e grava no localStorage (equivale ao conexao.php do PI).
// Se um dia o armazenamento mudar, só este arquivo precisa ser trocado.

// Evita uma chuva de alertas quando o dado salvo está corrompido: a leitura
// acontece várias vezes (tabela, validação...), mas o usuário é avisado uma vez.
let avisoLeituraMostrado = false;

// Cada registro precisa ser um objeto. Qualquer outra coisa dentro da lista
// (número, texto, null) é descartada para não quebrar a tela.
function ehRegistroValido(item) {
    return item !== null && typeof item === "object" && !Array.isArray(item);
}

// Lê a lista de máquinas. Nunca lança erro: se não houver dados ou se o JSON
// estiver corrompido, devolve uma lista vazia.
function carregarDados() {
    try {
        const texto = localStorage.getItem(CHAVE);
        if (texto === null) {
            return [];
        }

        const dados = JSON.parse(texto);
        // JSON válido não garante uma lista (pode ser um objeto ou um número)
        if (!Array.isArray(dados)) {
            throw new Error("O valor salvo não é uma lista.");
        }

        return dados.filter(ehRegistroValido);
    } catch (erro) {
        console.error("Erro ao ler os dados do localStorage:", erro);
        if (!avisoLeituraMostrado) {
            avisoLeituraMostrado = true;
            alert("Não foi possível ler os dados salvos. A lista será exibida vazia.");
        }
        return [];
    }
}

// Grava a lista INTEIRA de uma vez. O localStorage troca o valor todo em uma
// única operação, então nunca fica gravado "só um pedaço" da lista.
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
