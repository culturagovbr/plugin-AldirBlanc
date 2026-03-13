<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\Traits\RegisterFunctions;
use MapasCulturais\i;
use MapasCulturais\Definitions\Metadata;
use OpportunityWorkplan\Entities\Workplan;
use OpportunityWorkplan\Entities\Goal;
use OpportunityWorkplan\Entities\Delivery;
use AldirBlanc\Traits\DoctrineEventListenerTrait;
use AldirBlanc\Jobs\OportunidadeCultJob;

class Plugin extends \MapasCulturais\Plugin
{
    use RegisterFunctions;
    use DoctrineEventListenerTrait;

    protected static $instance;

    function __construct($config = [])
    {
        $config += [
            'client' => [
                'mode' => env('PNAB_CULTBR_MODE', 'development'),
                'host' => env('PNAB_CULTBR_HOST', null),
                'token' => env('PNAB_CULTBR_TOKEN', null),

                // PRIMEIRA FASE DA INTEGRAÇÃO (BUSCAR DADOS DO GESTOR E ENTES FEDERADOS)
                'seficEndpoint' => env('PNAB_CULTBR_SEFIC_ENDPOINT', null),
                'gestorEndpoint' => env('PNAB_CULTBR_GESTOR_ENDPOINT', null),
                'enteFederadoEndpoint' => env('PNAB_CULTBR_ENTE_FEDERADO_ENDPOINT', null),
                'createOportunidadeEndpoint' => env('PNAB_CULTBR_CREATE_OPORTUNIDADE_ENDPOINT', null),
                'updateOportunidadeEndpoint' => env('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT', null),
            ], 
            // Token de integração para consumo do CultBR
            'integration' => [
                'appName' => env('ALDIRBLANC_APPLICATION_NAME', null),
                'subsiteId' => env('ALDIRBLANC_SUBSITE_ID', null),
                'cacheTTL' => (int) env('ALDIRBLANC_INTEGRATION_CACHE_TTL', null),
                'delayJob' => env('ALDIRBLANC_INTEGRATION_DELAY_JOB', null),
                'retryDelayJob' => env('ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB', null),
            ]
        ];

        parent::__construct($config);
        self::$instance = $this;
    }

    /**
     * Retorna a instância do plugin
     * 
     * @return Plugin|null
     */
    public static function getInstance(): ?Plugin
    {
        return self::$instance;
    }


    public function _init()
    {
        // Inicializa os mapeamentos do Doctrine para a entidade FederativeEntity
        $this->initDoctrineMappings();

        $app = App::i();

        // Registra shortcut customizado para API de integração
        $app->config['routes']['shortcuts']['aldirblanc/opportunities'] = [
            'aldirblanc',
            'integrationOpportunities'
        ];

        // Sobrescreve metadados do módulo OpportunityWorkplan com padrão de dados Aldir Blanc (PR-121)
        $app->hook('app.init:after', function () {
            $app = App::i();

            // metadados workplan
            $projectDuration = new Metadata('projectDuration', [
                'label' => i::__('Duração do projeto (meses)'),
            ]);
            $app->registerMetadata($projectDuration, Workplan::class);

            $culturalArtisticSegment = new Metadata('culturalArtisticSegment', [
                'label' => i::__('Segmento artistico-cultural'),
                'type' => 'select',
                'options' => [
                    i::__('Acervos'),
                    i::__('Arquivos'),
                    i::__('Artes Visuais'),
                    i::__('Artesanato'),
                    i::__('Audiovisual'),
                    i::__('Capoeira'),
                    i::__('Circo'),
                    i::__('Cultura de Matriz Africana'),
                    i::__('Cultura dos Povos Originários'),
                    i::__('Culturas Tradicionais e Populares'),
                    i::__('Dança'),
                    i::__('Design'),
                    i::__('Edição e produção editorial'),
                    i::__('Festas e Celebrações'),
                    i::__('Hip Hop'),
                    i::__('Jogos eletrônicos'),
                    i::__('Literatura'),
                    i::__('Mediação e formação de leitores'),
                    i::__('Moda'),
                    i::__('Museu'),
                    i::__('Música'),
                    i::__('Patrimônio Arqueológico'),
                    i::__('Patrimônio Cultural Material'),
                    i::__('Patrimônio Cultural Imaterial'),
                    i::__('Patrimônio Natural'),
                    i::__('Performance'),
                    i::__('Teatro'),
                    i::__('Outros'),
                ],
            ]);
            $app->registerMetadata($culturalArtisticSegment, Workplan::class);

            // metadados goal
            $monthInitial = new Metadata('monthInitial', [
                'label' => i::__('Mês inicial'),
            ]);
            $app->registerMetadata($monthInitial, Goal::class);

            $monthEnd = new Metadata('monthEnd', [
                'label' => i::__('Mês final'),
            ]);
            $app->registerMetadata($monthEnd, Goal::class);

            $title = new Metadata('title', [
                'label' => i::__('Título da meta'),
            ]);
            $app->registerMetadata($title, Goal::class);

            $description = new Metadata('description', [
                'label' => i::__('Descrição'),
            ]);
            $app->registerMetadata($description, Goal::class);

            $culturalMakingStage = new Metadata('culturalMakingStage', [
                'label' => i::__('Etapa do fazer cultural'),
                'type' => 'select',
                'options' => [
                    i::__('Criação'),
                    i::__('Produção'),
                    i::__('Comercialização e Distribuição'),
                    i::__('Difusão e Circulação'),
                    i::__('Acesso, mediação e fruição'),
                    i::__('Formação'),
                    i::__('Pesquisa e reflexão'),
                    i::__('Memória e Preservação'),
                    i::__('Organização e gestão'),
                    i::__('Monitoramento e avaliação'),
                    i::__('Outra (especificar)'),
                ],
            ]);
            $app->registerMetadata($culturalMakingStage, Goal::class);

            $culturalMakingStageOther = new Metadata('culturalMakingStageOther', [
                'label' => i::__('Especificar etapa do fazer cultural'),
                'type' => 'string',
            ]);
            $app->registerMetadata($culturalMakingStageOther, Goal::class);

            // metadados pauta temática (Workplan)
            $thematicAgenda = new Metadata('thematicAgenda', [
                'label' => i::__('Pauta temática'),
                'type' => 'select',
                'options' => [
                    i::__('Não se relaciona a nenhuma pauta temática'),
                    i::__('Cultura Alimentar'),
                    i::__('Cultura DEF'),
                    i::__('Cultura Digital'),
                    i::__('Culturas Imigrantes e Refugiadas'),
                    i::__('Cultura LGBTQIAPN+'),
                    i::__('Cultura, Memória e Direitos Humanos'),
                    i::__('Cultura Nerd'),
                    i::__('Culturas Periféricas'),
                    i::__('Cultura Quilombola'),
                    i::__('Culturas Rurais e Agroecológicas'),
                    i::__('Culturas Urbanas'),
                    i::__('Cultura do Sertão'),
                    i::__('Cultura e Acessibilidade'),
                    i::__('Cultura e Economia Criativa'),
                    i::__('Cultura e Educação'),
                    i::__('Cultura e Gênero'),
                    i::__('Cultura e Idosos'),
                    i::__('Cultura e Infância'),
                    i::__('Cultura e Juventude'),
                    i::__('Cultura e Meio ambiente'),
                    i::__('Cultura e Negritude'),
                    i::__('Cultura e Pessoas em Situação de Privação de Liberdade'),
                    i::__('Cultura e População de Rua'),
                    i::__('Cultura e Povos Ciganos'),
                    i::__('Cultura e Saúde'),
                    i::__('Cultura e Turismo'),
                    i::__('Culturas Indígenas'),
                    i::__('Culturas Tradicionais de Matriz Africana'),
                    i::__('Outra (especificar)'),
                ],
            ]);
            $app->registerMetadata($thematicAgenda, Workplan::class);

            // metadados delivery
            $deliveryName = new Metadata('name', [
                'label' => i::__('Nome da entrega'),
            ]);
            $app->registerMetadata($deliveryName, Delivery::class);

            $deliveryDescription = new Metadata('description', [
                'label' => i::__('Descrição'),
            ]);
            $app->registerMetadata($deliveryDescription, Delivery::class);

            $deliveryType = new Metadata('type', [
                'label' => i::__('Tipo de entrega'),
            ]);
            $app->registerMetadata($deliveryType, Delivery::class);

            $typeDelivery = new Metadata('typeDelivery', [
                'label' => i::__('Tipo entrega'),
                'type' => 'select',
                'options' => [
                    i::__('Álbum musical'),
                    i::__('Aplicativo / Software'),
                    i::__('Apresentação ao vivo / Show'),
                    i::__('Aquisição de acervos e bens culturais'),
                    i::__('Arte gráfica / Desenho / Gravura / Ilustração'),
                    i::__('Artesanato'),
                    i::__('Artigo / Ensaio'),
                    i::__('Audiolivro'),
                    i::__('Aula / Palestra / Conferência'),
                    i::__('Blog / Site'),
                    i::__('Caderno / Cartilha / Apostila'),
                    i::__('Circulação / Turnê'),
                    i::__('Coleção'),
                    i::__('Congresso / Encontro / Seminário / Simpósio'),
                    i::__('Curso / Oficina / Workshop'),
                    i::__('Desfile'),
                    i::__('Digitalização de acervos'),
                    i::__('Ensaio fotográfico'),
                    i::__('Escultura'),
                    i::__('Espetáculo cênico'),
                    i::__('Exibição / Exposição'),
                    i::__('Feira'),
                    i::__('Festa Popular'),
                    i::__('Festival / Mostra'),
                    i::__('Filme de curta-metragem'),
                    i::__('Filme de longa-metragem'),
                    i::__('Filme de média-metragem ou telefilme'),
                    i::__('Grafitti/Mural'),
                    i::__('Instalação artística / videoarte'),
                    i::__('Intercâmbio'),
                    i::__('Jogo eletrônico'),
                    i::__('Licenciamento'),
                    i::__('Livro'),
                    i::__('Livro eletrônico (e-Book)'),
                    i::__('Manutenção de grupos / iniciativas / espaços culturais'),
                    i::__('Melhoria em espaço cultural'),
                    i::__('Pesquisa'),
                    i::__('Plataforma digital'),
                    i::__('Podcast/ Programa de TV ou Rádio'),
                    i::__('Residência Artística'),
                    i::__('Revista / Jornal / Periódico'),
                    i::__('Roteiro de filme ou episódio'),
                    i::__('Sarau / Slam'),
                    i::__('Série / websérie'),
                    i::__('Videoclipe / Album visual'),
                    i::__('Outros (especificar)'),
                ],
            ]);
            $app->registerMetadata($typeDelivery, Delivery::class);

            $typeDeliveryOther = new Metadata('typeDeliveryOther', [
                'label' => i::__('Especificar tipo de entrega'),
                'type' => 'string',
            ]);
            $app->registerMetadata($typeDeliveryOther, Delivery::class);

            $segmentDelivery = new Metadata('segmentDelivery', [
                'label' => i::__('Segmento artístico cultural da entrega'),
                'type' => 'select',
                'options' => [
                    i::__('Acervos'),
                    i::__('Arquivos'),
                    i::__('Artes Visuais'),
                    i::__('Artesanato'),
                    i::__('Audiovisual'),
                    i::__('Capoeira'),
                    i::__('Circo'),
                    i::__('Cultura de Matriz Africana'),
                    i::__('Cultura dos Povos Originários'),
                    i::__('Culturas Tradicionais e Populares'),
                    i::__('Dança'),
                    i::__('Design'),
                    i::__('Edição e produção editorial'),
                    i::__('Festas e Celebrações'),
                    i::__('Hip Hop'),
                    i::__('Jogos eletrônicos'),
                    i::__('Literatura'),
                    i::__('Mediação e formação de leitores'),
                    i::__('Moda'),
                    i::__('Museu'),
                    i::__('Música'),
                    i::__('Patrimônio Arqueológico'),
                    i::__('Patrimônio Cultural Material'),
                    i::__('Patrimônio Cultural Imaterial'),
                    i::__('Patrimônio Natural'),
                    i::__('Performance'),
                    i::__('Teatro'),
                    i::__('Outros'),
                ],
            ]);
            $app->registerMetadata($segmentDelivery, Delivery::class);

            $expectedNumberPeople = new Metadata('expectedNumberPeople', [
                'label' => i::__('Número previsto de pessoas'),
            ]);
            $app->registerMetadata($expectedNumberPeople, Delivery::class);

            $generaterRevenue = new Metadata('generaterRevenue', [
                'label' => i::__('A entrega irá gerar receita?'),
                'type' => 'select',
                'options' => [
                    'true' => i::__('Sim'),
                    'false' => i::__('Não'),
                ],
            ]);
            $app->registerMetadata($generaterRevenue, Delivery::class);

            $renevueQtd = new Metadata('renevueQtd', [
                'label' => i::__('Quantidade'),
            ]);
            $app->registerMetadata($renevueQtd, Delivery::class);

            $unitValueForecast = new Metadata('unitValueForecast', [
                'label' => i::__('Previsão de valor unitário'),
            ]);
            $app->registerMetadata($unitValueForecast, Delivery::class);

            $totalValueForecast = new Metadata('totalValueForecast', [
                'label' => i::__('Previsão de valor total'),
            ]);
            $app->registerMetadata($totalValueForecast, Delivery::class);

            // Expõe no JS as descrições das entidades do plano de metas (estrutura do PR-121)
            $app->hook('mapas.printJsObject:before', function () {
                $this->jsObject['EntitiesDescription']['workplan'] = Workplan::getPropertiesMetadata();
                $this->jsObject['EntitiesDescription']['workplan']['goal'] = Goal::getPropertiesMetadata();
                $this->jsObject['EntitiesDescription']['workplan']['goal']['delivery'] = Delivery::getPropertiesMetadata();
            });
        });
    }

    function register()
    {
        $app = App::i();
        
        // Registra o controller
        $app->registerController('aldirblanc', Controller::class);

        // Registra metadado federativeEntityId para entidades principais
        // Usa registerMetadata com namespace completo, seguindo o padrão do SpamDetector
        $entities = [
            'MapasCulturais\Entities\Opportunity',
            'MapasCulturais\Entities\Project',
            'MapasCulturais\Entities\Event',
            'MapasCulturais\Entities\Space',
            'MapasCulturais\Entities\Agent'
        ];

        foreach ($entities as $entityClass) {
            $this->registerMetadata($entityClass, 'federativeEntityId', [
                'label' => i::__('ID do Ente Federado'),
                'type' => 'integer',
                'private' => false
            ]);
        }

        // Registra metadado last_synced_at apenas para Agent
        $this->registerMetadata('MapasCulturais\Entities\Agent', 'gestorCultBrLastSyncedAt', [
            'label' => i::__('Data da última sincronização com API Gestor CultBR'),
            'type' => 'DateTime',
            'private' => true
        ]);

        // Registra metadado isNotGestorCultBr: quando a API do Cult não retorna entes, grava true
        // para que no 2º ou N-ésimo login o usuário pule a tela de consolidação
        $this->registerMetadata('MapasCulturais\Entities\Agent', 'isNotGestorCultBr', [
            'label' => i::__('Indica que a API CultBR não retornou entes para este agente'),
            'type' => 'boolean',
            'private' => true
        ]);

        // Registra metadado de data de publicação do edital para Opportunity (usado na integração com Oportunidade Cult)
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'publishedTimestamp', [
            'label' => i::__('Data de publicação do edital'),
            'type' => 'DateTime',
            'private' => true,
        ]);

        $app->registerJobType(new OportunidadeCultJob(OportunidadeCultJob::SLUG));
    }

    /**
     * Inicializa os mapeamentos do Doctrine para a entidade FederativeEntity
     * 
     * @return void
     */
    private function initDoctrineMappings()
    {
        $app = App::i();

        // Garante que a classe AgentRelation seja carregada antes de inicializar o listener
        class_exists(\AldirBlanc\Entities\FederativeEntityAgentRelation::class);

        // Inicializa o listener do Doctrine para estender o DiscriminatorMap do AgentRelation
        $this->initAgentRelationListener($app);

        // Registra o valor no ENUM PHP ObjectType
        $app->hook('doctrine.emum(object_type).values', function (&$values) {
            $values['FederativeEntity'] = \AldirBlanc\Entities\FederativeEntity::class;
        });
    }

    /**
     * Retorna os novos mapeamentos a serem adicionados ao DiscriminatorMap do AgentRelation
     * 
     * Formato: "NomeCompletoDaClasse" => "NomeCompletoDaClasseAgentRelation"
     * 
     * IMPORTANTE: Se você adicionar uma nova entidade aqui, também precisa adicionar
     * o nome da classe ao ENUM 'object_type' no banco de dados via db-updates.php
     * 
     * @return array
     */
    protected function getAgentRelationMappings()
    {
        return [
            "AldirBlanc\Entities\FederativeEntity" => "AldirBlanc\Entities\FederativeEntityAgentRelation",
        ];
    }
}
