"""
MachSense - Simulador de sensor (peça 1)

Simula as máquinas de fábrica cadastradas no site. Cada uma alterna entre
produzir e parar (almoço, furo de processo, setup, manutenção) e manda um
"pulso" pra API, dizendo em que estado está naquele momento. A API grava o
pulso no MariaDB, de onde o site em PHP lê.

Autor: Guilherme Somera Barboza - RA 118763

Como rodar (com o MariaDB e a API já de pé, e o .env.local criado):
    python simulador.py
    python simulador.py --fator 600          # 10x mais rápido
    python simulador.py --maquinas 2         # só as 2 primeiras máquinas ativas

Ideia central: o pulso NÃO decide o estado. A máquina entra num estado, fica
nele pelo tempo sorteado, e só depois sorteia o próximo. O pulso só reporta
"em que estado a máquina está agora".
"""

import argparse
import math
import os
import random
import sys
import time
from datetime import date, datetime, timedelta
from datetime import time as hora
from pathlib import Path

import requests
from dotenv import load_dotenv

# A chave da API fica em .env.local (que NÃO vai para o Git)
load_dotenv(Path(__file__).resolve().parent / ".env.local")

# ----------------------------------------------------------------------------
# CONFIGURAÇÃO
# ----------------------------------------------------------------------------
# 1  = tempo real (1 segundo real = 1 segundo simulado)
# 60 = cada segundo real vale 1 minuto simulado (uma manutenção de 8 h dura 8 min)
FATOR_TEMPO_PADRAO = 60

API_URL_PADRAO = "http://127.0.0.1:8000"

# Quanto vale cada pulso, em minutos simulados. O valor de verdade vem do banco
# (parametros.intervalo_pulso_min, via API); este é só o plano B.
INTERVALO_PULSO_MIN_PADRAO = 5

# Se não houver nenhum pulso no banco, o dia simulado começa hoje às 06:00
HORARIO_INICIO_PADRAO = hora(6, 0)

# Duração de cada estado, em minutos: (mínimo, máximo)
DURACOES = {
    "produzindo": (20, 240),       # 20 min a 4 h
    "furo_processo": (2, 40),      # 2 a 40 min (arredondado para múltiplos do pulso)
    "setup": (5, 20),              # 5 a 20 min
    "manutencao": (30, 480),       # 30 min a 8 h
}
DURACAO_ALMOCO = timedelta(hours=1)
HORA_ALMOCO = hora(12, 0)

# Quando a produção acaba, qual é o motivo da parada? (pesos, não precisam somar 100)
MOTIVOS_PARADA = ["furo_processo", "setup", "manutencao"]
PESOS_PARADA = [70, 20, 10]

# De quanto em quanto tempo (em pulsos) o simulador pergunta à API se surgiram
# máquinas novas (ex.: cadastradas pelo site) ou se alguma foi desativada.
REDESCOBRIR_A_CADA_PULSOS = 12

# Uma máquina que ficou muito para trás (ex.: desativada por dias) não "recupera"
# o passado: retoma no horário atual, deixando um buraco no histórico.
MAX_ATRASO_PULSOS = 12

# Quando a API está fora do ar, não adianta insistir mais rápido que isto (segundos reais)
ESPERA_MINIMA_RETENTATIVA = 1.0


def imprimir(texto: str):
    # flush=True: a linha aparece na hora, mesmo quando a saída é redirecionada
    print(texto, flush=True)


def sortear_minutos(estado: str, intervalo: int) -> int:
    """
    Sorteia a duração de um estado em MÚLTIPLOS do intervalo do pulso.

    Por quê: cada pulso vale exatamente 'intervalo' minutos nas views do banco.
    Com durações quebradas (ex.: 17 min) a conta "pulsos x 5" nunca bateria com
    o que a máquina sorteou. Em múltiplos de 5, bate sempre.
    """
    minimo, maximo = DURACOES[estado]
    return intervalo * random.randint(math.ceil(minimo / intervalo), maximo // intervalo)


def proximo_multiplo(instante: datetime, intervalo: int) -> datetime:
    """
    Primeiro horário DEPOIS de 'instante' que é múltiplo de 'intervalo' minutos
    (contando desde 00:00). Ex.: 06:05 -> 06:10 ; 06:03:30 -> 06:05.
    """
    base = instante.replace(second=0, microsecond=0)
    minutos_do_dia = base.hour * 60 + base.minute
    return base + timedelta(minutes=intervalo - (minutos_do_dia % intervalo))


# ----------------------------------------------------------------------------
# A MÁQUINA (máquina de estados)
# ----------------------------------------------------------------------------
class Maquina:
    def __init__(self, machine_id: int, inicio: datetime, intervalo: int):
        self.machine_id = machine_id
        self.intervalo = intervalo      # minutos que cada pulso representa
        self.pulse_id = 0
        self.proximo_tick = inicio      # horário simulado do próximo pulso a enviar
        self.pulso_pendente = None      # pulso que a API ainda não aceitou (será reenviado igual)
        self.almoco_dia = None          # em que dia o almoço já aconteceu
        self.estado = "produzindo"      # "produzindo" ou o nome da parada
        self.fim_estado = inicio        # quando o estado atual termina
        self._iniciar_producao(inicio)

    # -- estados ------------------------------------------------------------
    def _iniciar_producao(self, inicio: datetime):
        """Entra em 'produzindo'. Se o período passar do meio-dia, corta nele pra ir almoçar."""
        self.estado = "produzindo"
        minutos = sortear_minutos("produzindo", self.intervalo)
        fim = inicio + timedelta(minutes=minutos)

        meio_dia = datetime.combine(inicio.date(), HORA_ALMOCO)
        # Só vai almoçar se a produção começou ANTES do meio-dia e o almoço do dia ainda não rolou.
        # (Se a máquina estava parada por outro motivo às 12h, o almoço é considerado pulado.)
        if inicio < meio_dia <= fim and self.almoco_dia != inicio.date():
            fim = meio_dia
            self.proximo_e_almoco = True
        else:
            self.proximo_e_almoco = False
        self.fim_estado = fim

    def _iniciar_parada(self, inicio: datetime):
        """A produção acabou: almoço (se for a hora) ou um motivo sorteado."""
        if self.proximo_e_almoco:
            self.estado = "almoco"
            self.almoco_dia = inicio.date()
            self.fim_estado = inicio + DURACAO_ALMOCO
        else:
            self.estado = random.choices(MOTIVOS_PARADA, weights=PESOS_PARADA, k=1)[0]
            minutos = sortear_minutos(self.estado, self.intervalo)
            self.fim_estado = inicio + timedelta(minutes=minutos)

    def avancar(self, agora: datetime) -> list:
        """
        Atualiza o estado até o instante 'agora'. Pode haver mais de uma troca
        (ex.: uma parada curta que começou e terminou entre dois pulsos).
        Devolve a lista de trocas pra gente imprimir na tela.
        """
        trocas = []
        while agora >= self.fim_estado:
            instante = self.fim_estado            # hora exata em que a troca aconteceu
            anterior = self.estado
            if anterior == "produzindo":
                self._iniciar_parada(instante)
            else:
                self._iniciar_producao(instante)
            duracao_min = round((self.fim_estado - instante).total_seconds() / 60)
            trocas.append((instante, anterior, self.estado, duracao_min))
        return trocas

    # -- pulso --------------------------------------------------------------
    def montar_pulso(self, agora: datetime) -> dict:
        self.pulse_id += 1
        produzindo = self.estado == "produzindo"
        return {
            "pulse_id": self.pulse_id,
            "machine_id": self.machine_id,
            "status": "produzindo" if produzindo else "parado",
            "motivo": None if produzindo else self.estado,
            "timestamp": agora.replace(microsecond=0).isoformat(),
        }


# ----------------------------------------------------------------------------
# CONVERSA COM A API
# ----------------------------------------------------------------------------
class ClienteApi:
    def __init__(self, url_base: str, chave: str):
        self.url_base = url_base.rstrip("/")
        self.sessao = requests.Session()
        self.sessao.headers["X-API-Key"] = chave

    def listar_maquinas(self) -> dict:
        """GET /maquinas. Lança erro de requests se a API estiver fora do ar."""
        resposta = self.sessao.get(f"{self.url_base}/maquinas", timeout=3)
        resposta.raise_for_status()
        return resposta.json()

    def enviar_pulso(self, pulso: dict):
        """
        Manda o pulso. Devolve (resultado, detalhe), com resultado igual a:
          "ok" / "duplicado"   -> a API tem o pulso, pode seguir para o próximo
          "repetir"            -> API ou banco fora do ar: tentar o MESMO pulso de novo
          "sem_maquina"        -> a máquina não existe mais no banco (404)
          "descartar"          -> a API recusou o pulso (422): não adianta repetir
          "fatal"              -> chave recusada (401): parar o simulador
        """
        try:
            resposta = self.sessao.post(f"{self.url_base}/status", json=pulso, timeout=3)
        except requests.exceptions.RequestException as erro:
            return "repetir", f"sem resposta da API ({type(erro).__name__})"

        if resposta.status_code == 200:
            return ("duplicado" if resposta.json().get("duplicado") else "ok"), ""
        detalhe = f"HTTP {resposta.status_code}"
        if resposta.status_code == 401:
            return "fatal", detalhe
        if resposta.status_code == 404:
            return "sem_maquina", detalhe
        if resposta.status_code == 422:
            return "descartar", detalhe
        return "repetir", detalhe  # 503 (banco fora do ar) e qualquer outro erro do servidor


def descrever_troca(maquina_id, instante, anterior, novo, duracao_min):
    hora_txt = instante.strftime("%d/%m %H:%M")
    if novo == "produzindo":
        return f"[{hora_txt}] Máquina {maquina_id}: voltou a produzir (por {duracao_min} min)"
    if anterior == "produzindo":
        return f"[{hora_txt}] Máquina {maquina_id}: PAROU por {novo} ({duracao_min} min)"
    return f"[{hora_txt}] Máquina {maquina_id}: {anterior} -> {novo} ({duracao_min} min)"


# ----------------------------------------------------------------------------
# DESCOBERTA DAS MÁQUINAS E RETOMADA
# ----------------------------------------------------------------------------
def escolher_maquinas(cadastro: dict, limite) -> list:
    """Só máquinas ATIVAS e que existem no banco; --maquinas N limita às N primeiras."""
    ativas = sorted((m for m in cadastro["maquinas"] if m["ativa"]), key=lambda m: m["id"])
    return ativas[:limite] if limite else ativas


def ler_horario(texto):
    return datetime.fromisoformat(texto) if texto else None


def criar_maquina(dados: dict, intervalo: int, horario_atual: datetime) -> Maquina:
    """
    Cria a máquina já no horário certo: continua do último pulso gravado no banco
    (próximo múltiplo do intervalo) ou, se ela é nova ou ficou muito para trás,
    começa no horário atual da simulação.
    """
    ultimo = ler_horario(dados["ultimo_pulso"])
    if ultimo is None:
        return Maquina(dados["id"], horario_atual, intervalo)

    continuar_em = proximo_multiplo(ultimo, intervalo)
    if horario_atual - continuar_em > timedelta(minutes=intervalo * MAX_ATRASO_PULSOS):
        imprimir(f"  Máquina {dados['id']}: parada há muito tempo, retomando em "
                 f"{horario_atual:%d/%m %H:%M} (fica um buraco no histórico)")
        continuar_em = horario_atual
    return Maquina(dados["id"], continuar_em, intervalo)


def preparar_maquinas(cadastro: dict, limite, intervalo: int):
    """
    Devolve ({id: Maquina}, horario_inicial).

    O relógio da simulação continua do pulso mais recente do banco; assim dá para
    parar e rodar de novo sem repetir horários (UNIQUE maquina_id + timestamp).
    """
    escolhidas = escolher_maquinas(cadastro, limite)
    ultimos = [proximo_multiplo(ler_horario(m["ultimo_pulso"]), intervalo)
               for m in escolhidas if m["ultimo_pulso"]]

    if ultimos:
        horario = max(ultimos)
        imprimir(f"Continuando de onde parou: próximo pulso em {horario:%d/%m %H:%M}")
    else:
        horario = datetime.combine(date.today(), HORARIO_INICIO_PADRAO)
        imprimir(f"Banco sem pulsos: começando hoje às {horario:%H:%M}")

    maquinas = {m["id"]: criar_maquina(m, intervalo, horario) for m in escolhidas}
    return maquinas, horario


def atualizar_maquinas(cliente: ClienteApi, maquinas: dict, limite, intervalo: int, horario: datetime):
    """Inclui máquinas novas e tira as desativadas. Se a API falhar, mantém como está."""
    try:
        cadastro = cliente.listar_maquinas()
    except requests.exceptions.RequestException:
        return

    escolhidas = {m["id"]: m for m in escolher_maquinas(cadastro, limite)}

    for id_maquina, dados in escolhidas.items():
        if id_maquina not in maquinas:
            maquinas[id_maquina] = criar_maquina(dados, intervalo, horario)
            imprimir(f"[{horario:%d/%m %H:%M}] Máquina {id_maquina}: entrou na simulação")

    for id_maquina in [i for i in maquinas if i not in escolhidas]:
        del maquinas[id_maquina]
        imprimir(f"[{horario:%d/%m %H:%M}] Máquina {id_maquina}: saiu da simulação (desativada ou apagada)")


def esperar_api(cliente: ClienteApi) -> dict:
    """Na partida, espera a API responder (ela pode ainda estar subindo)."""
    avisou = False
    while True:
        try:
            return cliente.listar_maquinas()
        except (requests.exceptions.RequestException, ValueError) as erro:
            if not avisou:
                imprimir(f"[AVISO] API fora do ar ({type(erro).__name__}). Tentando de novo a cada 2 s...")
                avisou = True
            time.sleep(2)


# ----------------------------------------------------------------------------
# LOOP PRINCIPAL
# ----------------------------------------------------------------------------
def enviar_ate(maquina: Maquina, horario: datetime, cliente: ClienteApi):
    """
    Envia os pulsos da máquina até o horário simulado 'horario' (inclusive).

    Só avança o horário da máquina quando a API CONFIRMA o pulso. Se a API cair,
    o mesmo pulso fica pendente e é reenviado depois: nenhum horário é pulado.
    Devolve (situação, detalhe, quantos pulsos foram aceitos).
    """
    aceitos = 0
    while maquina.proximo_tick <= horario:
        pulso = maquina.pulso_pendente
        if pulso is None:
            for troca in maquina.avancar(maquina.proximo_tick):
                imprimir(descrever_troca(maquina.machine_id, *troca))
            pulso = maquina.montar_pulso(maquina.proximo_tick)

        resultado, detalhe = cliente.enviar_pulso(pulso)

        if resultado in ("ok", "duplicado"):
            maquina.pulso_pendente = None
            maquina.proximo_tick += timedelta(minutes=maquina.intervalo)
            aceitos += 1
        elif resultado == "repetir":
            maquina.pulso_pendente = pulso
            return "repetir", detalhe, aceitos
        elif resultado == "descartar":
            imprimir(f"  [ERRO] pulso {pulso['pulse_id']} da máquina {maquina.machine_id} "
                     f"recusado pela API ({detalhe}); seguindo")
            maquina.pulso_pendente = None
            maquina.proximo_tick += timedelta(minutes=maquina.intervalo)
        else:  # "sem_maquina" ou "fatal"
            return resultado, detalhe, aceitos
    return "ok", "", aceitos


def ler_argumentos():
    analisador = argparse.ArgumentParser(description="Simulador de sensores do MachSense.")
    analisador.add_argument("--api-url", default=os.getenv("MACHSENSE_API_URL", API_URL_PADRAO),
                            help=f"endereço da API (padrão: {API_URL_PADRAO})")
    analisador.add_argument("--fator", type=float,
                            default=float(os.getenv("MACHSENSE_FATOR", FATOR_TEMPO_PADRAO)),
                            help="quantas vezes o tempo simulado é mais rápido que o real (padrão: 60)")
    analisador.add_argument("--maquinas", type=int, default=None,
                            help="simula só as N primeiras máquinas ativas (padrão: todas as ativas)")
    analisador.add_argument("--ticks", type=int, default=None,
                            help="para sozinho depois de N pulsos por máquina (útil em testes)")
    args = analisador.parse_args()

    if args.fator <= 0:
        analisador.error("--fator precisa ser maior que zero")
    if args.maquinas is not None and args.maquinas < 1:
        analisador.error("--maquinas precisa ser 1 ou mais")
    return args


def main():
    args = ler_argumentos()

    chave = os.getenv("MACHSENSE_API_KEY", "")
    if not chave:
        sys.exit("MACHSENSE_API_KEY não definida. Crie o .env.local a partir do .env.example "
                 "(a mesma chave usada pela API).")

    cliente = ClienteApi(args.api_url, chave)
    cadastro = esperar_api(cliente)
    intervalo = cadastro.get("intervalo_pulso_min", INTERVALO_PULSO_MIN_PADRAO)

    maquinas, horario = preparar_maquinas(cadastro, args.maquinas, intervalo)
    if not maquinas:
        sys.exit("Nenhuma máquina ativa no banco. Cadastre ou ative máquinas no site.")

    # Tempo real que equivale a um pulso: 5 min simulados / fator 60 = 5 s reais
    espera = intervalo * 60 / args.fator

    imprimir(f"Simulando {len(maquinas)} máquina(s) {sorted(maquinas)} | fator {args.fator:g} | "
             f"1 pulso = {intervalo} min simulados = {espera:g} s reais | API {cliente.url_base}")
    imprimir("Ctrl+C para parar.\n")

    prazo = time.monotonic()
    pulsos_por_maquina = 0
    total_aceitos = 0
    em_falha = False
    tentativas = 0

    try:
        while True:
            em_dia = True
            for maquina in list(maquinas.values()):
                situacao, detalhe, aceitos = enviar_ate(maquina, horario, cliente)
                total_aceitos += aceitos

                if situacao == "fatal":
                    sys.exit(f"A API recusou a chave ({detalhe}). Confira MACHSENSE_API_KEY no .env.local.")
                if situacao == "sem_maquina":
                    imprimir(f"  [AVISO] máquina {maquina.machine_id} não existe mais no banco; removida da simulação")
                    del maquinas[maquina.machine_id]
                elif situacao == "repetir":
                    em_dia = False
                    tentativas += 1
                    if not em_falha:
                        imprimir(f"  [AVISO] API/banco fora do ar ({detalhe}). O horário simulado fica parado "
                                 f"em {horario:%d/%m %H:%M} e o pulso é reenviado (nenhum pulso se perde).")
                        em_falha = True
                    break  # sem API, não adianta tentar as outras máquinas agora

            if em_dia:
                if em_falha:
                    imprimir(f"  [OK] API voltou depois de {tentativas} tentativa(s). Retomando.")
                    em_falha = False
                    tentativas = 0
                if not maquinas:
                    sys.exit("Nenhuma máquina restante na simulação.")

                horario += timedelta(minutes=intervalo)
                pulsos_por_maquina += 1
                if args.ticks and pulsos_por_maquina >= args.ticks:
                    break
                if pulsos_por_maquina % REDESCOBRIR_A_CADA_PULSOS == 0:
                    atualizar_maquinas(cliente, maquinas, args.maquinas, intervalo, horario)
                prazo += espera
            else:
                prazo = time.monotonic() + max(espera, ESPERA_MINIMA_RETENTATIVA)

            restante = prazo - time.monotonic()
            if restante > 0:
                time.sleep(restante)
            else:
                prazo = time.monotonic()  # ficou para trás (API lenta): não acumula atraso
    except KeyboardInterrupt:
        pass

    imprimir(f"\nSimulador encerrado. Pulsos aceitos pela API: {total_aceitos}.")


if __name__ == "__main__":
    main()
