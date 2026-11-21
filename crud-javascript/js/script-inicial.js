// script-inicial.js
// Primeiro script carregado. Equivale a "criar a tabela" do MySQL: define o
// nome da chave do localStorage e os dados de exemplo, e grava esses dados
// apenas na primeira vez que a página é aberta.
//
// ATENÇÃO: as constantes abaixo são globais e usadas pelos outros scripts.
// Como todos compartilham o mesmo escopo, nenhum outro arquivo pode declarar
// CHAVE ou DADOS_EXEMPLO de novo (daria erro de redeclaração).

const CHAVE = "pi_maquinas";

const DADOS_EXEMPLO = [
    { id: 1, nome: "Máquina 1", meta: 85, ativa: true },
    { id: 2, nome: "Máquina 2", meta: 85, ativa: true },
    { id: 3, nome: "Máquina 3", meta: 85, ativa: true }
];

function semearDadosIniciais() {
    try {
        // getItem só devolve null quando a chave nunca foi criada. Se o usuário
        // apagar todas as máquinas, a chave passa a guardar o texto "[]" (que
        // não é null), então os dados de exemplo NÃO voltam sozinhos.
        if (localStorage.getItem(CHAVE) === null) {
            localStorage.setItem(CHAVE, JSON.stringify(DADOS_EXEMPLO));
        }
    } catch (erro) {
        // localStorage pode estar bloqueado (modo privado, política do navegador)
        console.error("Não foi possível preparar o localStorage:", erro);
    }
}

semearDadosIniciais();
