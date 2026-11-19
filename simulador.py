"""
MachSense - Simulador de sensor (peça 1)

Simula 3 máquinas de fábrica. Cada uma alterna entre produzir e parar
(almoço, furo de processo, setup, manutenção) e manda um "pulso" pra API
a cada 5 segundos reais, dizendo em que estado está naquele momento.

Autor: Guilherme Somera Barboza - RA 118763

Como rodar (com a API já de pé em outro terminal):
    python simulador.py

Ideia central: o pulso NÃO decide o estado. A máquina entra num estado, fica
nele pelo tempo sorteado, e só depois sorteia o próximo. O pulso só reporta
"em que estado a máquina está agora".
"""

import random
import time
from datetime import date, datetime, timedelta
from datetime import time as hora

import requests

# ----------------------------------------------------------------------------
# CONFIGURAÇÃO
# ----------------------------------------------------------------------------
# 1  = tempo real (1 segundo real = 1 segundo simulado)
# 60 = cada segundo real vale 1 minuto simulado (uma manutenção de 8 h dura 8 min)
FATOR_TEMPO = 60

INTERVALO_PULSO_REAL = 5  # segundos reais entre um pulso e o próximo
QTD_MAQUINAS = 3
API_URL = "http://localhost:8000/status"

# O dia simulado começa hoje às 06:00
HORARIO_INICIO_SIMULADO = datetime.combine(date.today(), hora(6, 0))

# Duração de cada estado, em minutos: (mínimo, máximo)
DURACOES = {
    "produzindo": (20, 240),       # 20 min a 4 h
    "furo_processo": (2, 40),      # 2 a 40 min
    "setup": (5, 20),              # 5 a 20 min
    "manutencao": (30, 480),       # 30 min a 8 h
}
DURACAO_ALMOCO = timedelta(hours=1)
HORA_ALMOCO = hora(12, 0)

# Quando a produção acaba, qual é o motivo da parada? (pesos, não precisam somar 100)
MOTIVOS_PARADA = ["furo_processo", "setup", "manutencao"]
PESOS_PARADA = [70, 20, 10]


# ----------------------------------------------------------------------------
# A MÁQUINA (máquina de estados)
# ----------------------------------------------------------------------------
class Maquina:
    def __init__(self, machine_id: int, inicio: datetime):
        self.machine_id = machine_id
        self.pulse_id = 0
        self.almoco_dia = None          # em que dia o almoço já aconteceu
        self.estado = "produzindo"      # "produzindo" ou o nome da parada
        self.fim_estado = inicio        # quando o estado atual termina
        self._iniciar_producao(inicio)

    # -- estados ------------------------------------------------------------
    def _iniciar_producao(self, inicio: datetime):
        """Entra em 'produzindo'. Se o período passar do meio-dia, corta nele pra ir almoçar."""
        self.estado = "produzindo"
        minutos = random.randint(*DURACOES["produzindo"])
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
            minutos = random.randint(*DURACOES[self.estado])
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
# ENVIO PRA API
# ----------------------------------------------------------------------------
def enviar_pulso(pulso: dict):
    """Manda o pulso. Se a API estiver fora do ar, avisa, descarta esse pulso e segue."""
    try:
        resposta = requests.post(API_URL, json=pulso, timeout=2)
        resposta.raise_for_status()
    except requests.exceptions.RequestException as erro:
        print(f"  [ERRO] pulso {pulso['pulse_id']} da máquina {pulso['machine_id']} descartado: {erro}")


def descrever_troca(maquina_id, instante, anterior, novo, duracao_min):
    hora_txt = instante.strftime("%d/%m %H:%M")
    if novo == "produzindo":
        return f"[{hora_txt}] Máquina {maquina_id}: voltou a produzir (por {duracao_min} min)"
    if anterior == "produzindo":
        return f"[{hora_txt}] Máquina {maquina_id}: PAROU por {novo} ({duracao_min} min)"
    return f"[{hora_txt}] Máquina {maquina_id}: {anterior} -> {novo} ({duracao_min} min)"


# ----------------------------------------------------------------------------
# LOOP PRINCIPAL
# ----------------------------------------------------------------------------
def main():
    maquinas = [Maquina(i, HORARIO_INICIO_SIMULADO) for i in range(1, QTD_MAQUINAS + 1)]
    inicio_real = time.monotonic()

    print(f"Simulando {QTD_MAQUINAS} máquinas | FATOR_TEMPO={FATOR_TEMPO} | enviando para {API_URL}")
    print(f"Relógio simulado começa em {HORARIO_INICIO_SIMULADO:%d/%m %H:%M}. Ctrl+C para parar.\n")

    try:
        while True:
            # Relógio simulado = início + tempo real decorrido x fator
            decorrido_real = time.monotonic() - inicio_real
            agora = HORARIO_INICIO_SIMULADO + timedelta(seconds=decorrido_real * FATOR_TEMPO)

            for maquina in maquinas:
                for troca in maquina.avancar(agora):
                    print(descrever_troca(maquina.machine_id, *troca))
                enviar_pulso(maquina.montar_pulso(agora))

            time.sleep(INTERVALO_PULSO_REAL)
    except KeyboardInterrupt:
        print("\nSimulador encerrado.")


if __name__ == "__main__":
    main()
