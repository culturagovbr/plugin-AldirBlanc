<?php

namespace AldirBlanc\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="MapasCulturais\Repository")
 */
class FederativeEntityAgentRelation extends \MapasCulturais\Entities\AgentRelation
{
    /**
     * @var \AldirBlanc\Entities\FederativeEntity
     *
     * @ORM\ManyToOne(targetEntity="AldirBlanc\Entities\FederativeEntity")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="object_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $owner;
}
