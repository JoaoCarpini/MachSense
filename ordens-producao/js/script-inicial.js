// script-inicial.js
// Primeiro script carregado. Define as chaves do localStorage, os valores
// permitidos de cada campo e grava as ordens de exemplo apenas na primeira vez
// que a página é aberta.
//
// ATENÇÃO: as constantes abaixo são globais e usadas pelos outros scripts.
// Nenhum outro arquivo pode declará-las de novo (daria erro de redeclaração).

const CHAVE = "pi_ordens_producao";

// Contador do número da ordem (OP-0001, OP-0002...). Fica separado da lista
// para que um número nunca seja reaproveitado depois de uma exclusão.
const CHAVE_SEQUENCIA = "pi_ordens_producao_sequencia";

// Mesma chave usada pelo CRUD de máquinas (crud-javascript). Se as duas
// páginas forem abertas pela mesma origem, as máquinas cadastradas lá
// aparecem no formulário daqui.
const CHAVE_MAQUINAS = "pi_maquinas";

// Usadas quando ainda não existe nenhuma máquina ativa cadastrada
const MAQUINAS_PADRAO = ["Máquina 1", "Máquina 2", "Máquina 3"];

const STATUS = {
    planejada: "Planejada",
    em_producao: "Em produção",
    concluida: "Concluída",
    cancelada: "Cancelada"
};

const PRIORIDADES = {
    baixa: "Baixa",
    media: "Média",
    alta: "Alta"
};

// Data de hoje + "dias", no formato do <input type="date"> (AAAA-MM-DD).
// Usa a data LOCAL; toISOString() usaria UTC e poderia pular um dia à noite.
function dataRelativa(dias) {
    const data = new Date();
    data.setDate(data.getDate() + dias);
    const mes = String(data.getMonth() + 1).padStart(2, "0");
    const dia = String(data.getDate()).padStart(2, "0");
    return `${data.getFullYear()}-${mes}-${dia}`;
}

const DADOS_EXEMPLO = [
    {
        id: 1, numero: 1, produto: "Eixo de transmissão 25 mm", quantidade: 500,
        maquina: "Máquina 1", prioridade: "alta", status: "em_producao",
        dataInicio: dataRelativa(-3), dataEntrega: dataRelativa(4),
        observacoes: "Lote para cliente prioritário.", criadaEm: new Date().toISOString()
    },
    {
        id: 2, numero: 2, produto: "Flange de aço carbono", quantidade: 1200,
        maquina: "Máquina 2", prioridade: "media", status: "planejada",
        dataInicio: dataRelativa(2), dataEntrega: dataRelativa(12),
        observacoes: "", criadaEm: new Date().toISOString()
    },
    {
        id: 3, numero: 3, produto: "Bucha de bronze", quantidade: 300,
        maquina: "Máquina 3", prioridade: "baixa", status: "concluida",
        dataInicio: dataRelativa(-10), dataEntrega: dataRelativa(-2),
        observacoes: "", criadaEm: new Date().toISOString()
    }
];

function semearDadosIniciais() {
    try {
        // getItem só devolve null quando a chave nunca foi criada. Se o usuário
        // apagar todas as ordens, a chave passa a guardar "[]" (que não é null),
        // então os exemplos NÃO voltam sozinhos.
        if (localStorage.getItem(CHAVE) === null) {
            localStorage.setItem(CHAVE, JSON.stringify(DADOS_EXEMPLO));
            localStorage.setItem(CHAVE_SEQUENCIA, String(DADOS_EXEMPLO.length));
        }
    } catch (erro) {
        // localStorage pode estar bloqueado (modo privado, política do navegador)
        console.error("Não foi possível preparar o localStorage:", erro);
    }
}

semearDadosIniciais();
