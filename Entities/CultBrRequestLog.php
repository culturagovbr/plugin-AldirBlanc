<?php

namespace AldirBlanc\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * Envio de uma oportunidade ao CultBR — agrupa as tentativas sob um mesmo identificador.
 *
 * Um registro por envio executado (o uuid nasce na primeira tentativa do OportunidadeCultJob);
 * as retentativas entram como CultBrRequestLogAttempt filhas, não como novos envios.
 *
 * @property int $id
 * @property string $requestUuid Identificador exibido na aba "Logs CultBr"
 * @property int $opportunityId
 * @property string $action Ação do job (hoje só `update`)
 * @property string $result self::RESULT_*
 * @property \DateTime $createTimestamp
 * @property \DateTime|null $updateTimestamp
 * @property \AldirBlanc\Entities\CultBrRequestLogAttempt[] $attempts
 *
 * @ORM\Table(name="cultbr_request_log")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 */
class CultBrRequestLog extends \MapasCulturais\Entity
{
    /** Em curso: ainda há retentativa possível. */
    const RESULT_PENDING = 'pending';

    /** Alguma tentativa foi aceita pelo CultBR. */
    const RESULT_SUCCESS = 'success';

    /** Todas as tentativas falharam (limite de retentativas atingido). */
    const RESULT_ERROR = 'error';

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="cultbr_request_log_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var string
     *
     * @ORM\Column(name="request_uuid", type="string", length=36, nullable=false)
     */
    protected $requestUuid;

    /**
     * @var integer
     *
     * @ORM\Column(name="opportunity_id", type="integer", nullable=false)
     */
    protected $opportunityId;

    /**
     * @var string
     *
     * @ORM\Column(name="action", type="string", length=32, nullable=false)
     */
    protected $action;

    /**
     * Nome `result` (e não `status`) porque \MapasCulturais\Entity::setStatus() é tipado como int
     * e o MagicSetter do core intercepta a propriedade `status`. A coluna segue sendo `status`.
     *
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=16, nullable=false)
     */
    protected $result = self::RESULT_PENDING;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var \DateTime|null
     *
     * @ORM\Column(name="update_timestamp", type="datetime", nullable=true)
     */
    protected $updateTimestamp;

    /**
     * @var \AldirBlanc\Entities\CultBrRequestLogAttempt[]
     *
     * @ORM\OneToMany(targetEntity="AldirBlanc\Entities\CultBrRequestLogAttempt", mappedBy="log", cascade={"remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"attempt" = "ASC"})
     */
    protected $attempts;
}
