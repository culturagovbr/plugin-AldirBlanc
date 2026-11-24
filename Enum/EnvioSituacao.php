<?php

namespace AldirBlanc\Enum;

/** Em que pé está o último envio do resultado, como o gestor o vê. */
enum EnvioSituacao: string
{
    case Aguardando = 'aguardando';
    case Enviando = 'enviando';
    case Publicado = 'publicado';
    case Simulado = 'simulado';
    case NadaMudou = 'nada-mudou';
    case SemConfirmacao = 'sem-confirmacao';
    case Recusado = 'recusado';
    case NaoEnviado = 'nao-enviado';

    public function isEmAndamento(): bool
    {
        return $this === self::Aguardando || $this === self::Enviando;
    }

    /** Um novo pedido precisa esperar o prazo deste. */
    public function isSemDesfecho(): bool
    {
        return $this->isEmAndamento() || $this === self::SemConfirmacao;
    }
}
