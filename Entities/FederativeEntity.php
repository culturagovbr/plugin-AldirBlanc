<?php

namespace AldirBlanc\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\Traits;

/**
 * @property int $id
 * @property string $name
 * @property string $document
 * @property array|null $exercices Dados de exercícios (payload da API: exercicios por ente)
 * @property array|null $parSituation Situação dos cadastros do PAR e a data da consulta
 * @property \DateTime $createTimestamp
 * @property \DateTime $updateTimestamp
 * @property-read int $subsiteId
 * @property-read string $originSiteUrl
 * @property \MapasCulturais\Entities\Subsite $subsite
 * @property \AldirBlanc\Entities\FederativeEntityAgentRelation[] $__agentRelations
 *
 * @ORM\Table(name="federative_entity")
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 */
class FederativeEntity extends \MapasCulturais\Entity
{
    use Traits\EntityAgentRelation,
        Traits\EntityOriginSubsite;

    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer", nullable=false)
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(sequenceName="federative_entity_id_seq", allocationSize=1, initialValue=1)
     */
    protected $id;

    /**
     * @var string
     * 
     * @ORM\Column(name="name", type="string")
     */
    protected $name;

    /**
     * @var string
     * 
     * @ORM\Column(name="document", type="string", nullable=false)
     */
    protected $document;

    /**
     * @var array|null JSON: lista de exercícios retornada pela API do gestor (chave "exercicios" no payload).
     *
     * @ORM\Column(name="exercices", type="json", nullable=true)
     */
    protected $exercices;

    /**
     * @var array|null
     *
     * @ORM\Column(name="par_situation", type="json", nullable=true)
     */
    protected $parSituation;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_timestamp", type="datetime", nullable=false)
     */
    protected $createTimestamp;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="update_timestamp", type="datetime", nullable=true)
     */
    protected $updateTimestamp;

    /**
     * @var integer
     *
     * @ORM\Column(name="subsite_id", type="integer", nullable=true)
     */
    protected $_subsiteId;

    /**
     * @var \MapasCulturais\Entities\Subsite
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\Subsite")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="subsite_id", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     * })
     */
    protected $subsite;

    /**
     * @var \AldirBlanc\Entities\FederativeEntityAgentRelation[] Agent Relations
     *
     * @ORM\OneToMany(targetEntity="AldirBlanc\Entities\FederativeEntityAgentRelation", mappedBy="owner", cascade={"remove"}, orphanRemoval=true)
     */
    protected $__agentRelations;
}
