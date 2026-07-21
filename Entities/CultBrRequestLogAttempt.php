<?php

namespace AldirBlanc\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tentativa individual de envio ao CultBR — o "1/3", "2/3" exibido na aba "Logs CultBr".
 *
 * O número da tentativa acompanha o contador do OportunidadeCultJob (payload do job),
 * e não um contador próprio: assim a listagem reflete exatamente o retry que aconteceu.
 *
 * @property int $id
 * @property \AldirBlanc\Entities\CultBrRequestLog $log
 * @property int $attempt
 * @property int $maxAttempts
 * @property string $endpoint
 * @property string $httpMethod
 * @property int|null $httpStatus Nulo quando a request nem chegou a receber resposta
 * @property array|null $payload Corpo enviado ao CultBR
 * @property array|null $response Corpo devolvido pelo CultBR
 * @property string|null $errorMessage
 * @property string $result self::RESULT_*
 * @property \DateTime $sentAt
 * @property int|null $durationMs
 *
 * @ORM\Table(name="cultbr_request_log_attempt")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 */
class CultBrRequestLogAttempt extends \MapasCulturais\Entity
{
    /** Resposta aceita pelo parseResponse do client. */
    const RESULT_SUCCESS = 'success';

    /** Erro de rede, HTTP >= 400 ou corpo recusado pelo parseResponse. */
    const RESULT_ERROR = 'error';

    /** Modo development: o client devolve fixture sem chamada real (AbstractClient::put). */
    const RESULT_SIMULATED = 'simulated';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="cultbr_request_log_attempt_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var \AldirBlanc\Entities\CultBrRequestLog
     *
     * @ORM\ManyToOne(targetEntity="AldirBlanc\Entities\CultBrRequestLog", inversedBy="attempts")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="log_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     * })
     */
    protected $log;

    /**
     * @var integer
     *
     * @ORM\Column(name="attempt", type="integer", nullable=false)
     */
    protected $attempt;

    /**
     * @var integer
     *
     * @ORM\Column(name="max_attempts", type="integer", nullable=false)
     */
    protected $maxAttempts;

    /**
     * @var string
     *
     * @ORM\Column(name="endpoint", type="text", nullable=false)
     */
    protected $endpoint;

    /**
     * @var string
     *
     * @ORM\Column(name="http_method", type="string", length=8, nullable=false)
     */
    protected $httpMethod;

    /**
     * @var integer|null
     *
     * @ORM\Column(name="http_status", type="integer", nullable=true)
     */
    protected $httpStatus;

    /**
     * @var array|null
     *
     * @ORM\Column(name="payload", type="json", nullable=true)
     */
    protected $payload;

    /**
     * @var array|null JSON quando o CultBR responde JSON; texto cru vai em {"raw": "..."}.
     *
     * @ORM\Column(name="response", type="json", nullable=true)
     */
    protected $response;

    /**
     * @var string|null
     *
     * @ORM\Column(name="error_message", type="text", nullable=true)
     */
    protected $errorMessage;

    /**
     * Nome `result` pelo mesmo motivo de CultBrRequestLog::$result (MagicSetter do core).
     *
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=16, nullable=false)
     */
    protected $result;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="sent_at", type="datetime", nullable=false)
     */
    protected $sentAt;

    /**
     * @var integer|null
     *
     * @ORM\Column(name="duration_ms", type="integer", nullable=true)
     */
    protected $durationMs;
}
