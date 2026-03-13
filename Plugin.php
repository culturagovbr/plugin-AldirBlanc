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

            // Metadados de monitoramento de projeto (ProjectMonitoring) sobrescritos no plugin

            // Goal (meta) – detalhamento da execução
            $executionDetail = new Metadata('executionDetail', [
                'label' => i::__('Detalhamento da execução da meta'),
            ]);
            $app->registerMetadata($executionDetail, Goal::class);

            // Delivery (entrega) – forma de disponibilização
            $availabilityType = new Metadata('availabilityType', [
                'label' => i::__('Forma de disponibilização'),
                'type' => 'select',
                'options' => [
                    i::__('Virtual/Digital'),
                    i::__('Presencial/Físico'),
                    i::__('Híbrido'),
                ],
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('availabilityType')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($availabilityType, Delivery::class);

            $accessibilityMeasures = new Metadata('accessibilityMeasures', [
                'label' => i::__('Medidas de acessibilidade'),
                'type' => 'multiselect',
                'options' => [
                    i::__('Rotas acessíveis, com espaço de manobra para cadeira de rodas'),
                    i::__('Palco acessível'),
                    i::__('Camarim acessível'),
                    i::__('Piso tátil'),
                    i::__('Rampas'),
                    i::__("Elevadores adequados para PCD's"),
                    i::__('Corrimãos e guarda-corpos'),
                    i::__("Banheiros adaptados para PCD's"),
                    i::__('Área de alimentação preferencial identificada'),
                    i::__("Vagas de estacionamento para PCD's reservadas"),
                    i::__("Assentos para pessoas obesas, pessoas com mobilidade reduzida, PCD's e pessoas idosas reservadas"),
                    i::__('Filas preferenciais identificadas'),
                    i::__('Iluminação adequada'),
                    i::__('Livro e/ou similares em braile'),
                    i::__('Audiolivro'),
                    i::__('Uso Língua Brasileira de Sinais - Libras'),
                    i::__('Sistema Braille em materiais impressos'),
                    i::__('Sistema de sinalização ou comunicação tátil'),
                    i::__('Audiodescrição'),
                    i::__('Legendas para surdos e ensurdecidos'),
                    i::__('Linguagem simples'),
                    i::__('Textos adaptados para software de leitor de tela'),
                    i::__('Capacitação em acessibilidade para equipes atuantes nos projetos culturais'),
                    i::__('Contratação de profissionais especializados em acessibilidade cultural'),
                    i::__('Contratação de profissionais com deficiência'),
                    i::__('Formação e sensibilização de agentes culturais sobre acessibilidade'),
                    i::__('Formação e sensibilização de públicos da cadeia produtiva cultural sobre acessibilidade'),
                    i::__("Envolvimento de PCD's na concepção do projeto"),
                    i::__('Outras'),
                ],
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('accessibilityMeasures')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($accessibilityMeasures, Delivery::class);

            $participantProfile = new Metadata('participantProfile', [
                'label' => i::__('Perfil dos participantes'),
                'type' => 'text',
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('participantProfile')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($participantProfile, Delivery::class);

            $priorityAudience = new Metadata('priorityAudience', [
                'label' => i::__('Territórios prioritários'),
                'type' => 'multiselect',
                'options' => [
                    i::__('Território indígena'),
                    i::__('Território de povos e comunidades tradicionais'),
                    i::__('Território rural'),
                    i::__('Território de fronteira'),
                    i::__('Regiões com menor índice de Desenvolvimento Humano - IDH'),
                    i::__('Regiões com menor histórico de acesso aos recursos da política pública de cultura'),
                    i::__('Área atingida por desastre natural'),
                    i::__('Assentamento ou acampamento'),
                    i::__('Conjunto ou empreendimento habitacional de interesse social'),
                    i::__('Periferia'),
                    i::__('Favelas e comunidades urbanas'),
                    i::__('Zona especial de interesse social'),
                    i::__('Sítios de arqueológicos e de patrimônio cultural'),
                    i::__('Não se aplica'),
                    i::__('Outros'),
                ],
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('priorityAudience')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($priorityAudience, Delivery::class);

            $numberOfParticipants = new Metadata('numberOfParticipants', [
                'label' => i::__('Número de participantes'),
                'type' => 'integer',
                'validations' => [
                    'v::intVal()->positive()' => i::__('O valor deve ser um número inteiro positivo'),
                ],
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('numberOfParticipants')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($numberOfParticipants, Delivery::class);

            $executedRevenue = new Metadata('executedRevenue', [
                'label' => i::__('Receita executada'),
                'type' => 'object',
                'should_validate' => function ($entity) {
                    if ($entity->isMetadataRequired('executedRevenue')) {
                        return i::__('Campo obrigatório');
                    }
                    return false;
                },
            ]);
            $app->registerMetadata($executedRevenue, Delivery::class);

            $evidenceLinks = new Metadata('evidenceLinks', [
                'label' => i::__('Links das evidências'),
                'type' => 'array',
            ]);
            $app->registerMetadata($evidenceLinks, Delivery::class);

            // Expõe no JS as descrições das entidades do plano de metas (estrutura do PR-121)
            $app->hook('mapas.printJsObject:before', function () {
                $this->jsObject['EntitiesDescription']['workplan'] = Workplan::getPropertiesMetadata();
                $this->jsObject['EntitiesDescription']['workplan']['goal'] = Goal::getPropertiesMetadata();
                $this->jsObject['EntitiesDescription']['workplan']['goal']['delivery'] = Delivery::getPropertiesMetadata();
            });

            // Aplica metadados "Outros (especificar)" ao salvar Goal/Delivery pelo controller workplan do core
            self::getInstance()->registerWorkplanMetadataHooks($app);
        });
    }

    /**
     * Registra hooks em entity(Goal).save:before e entity(Delivery).save:before para
     * preencher culturalMakingStageOther e typeDeliveryOther a partir do corpo da requisição
     * quando o save for disparado pelo POST workplan/save do core.
     */
    private function registerWorkplanMetadataHooks(App $app): void
    {
        $goalIndex = 0;
        $goalDataByObjectId = [];

        $app->hook('entity(OpportunityWorkplan.Entities.Goal).save:before', function () use ($app, &$goalIndex, &$goalDataByObjectId) {
            /** @var Goal $this */
            $data = self::getInstance()->getWorkplanSaveDataFromRequest($app);
            if ($data === null) {
                return;
            }
            $goalsData = $data['workplan']['goals'] ?? [];
            $g = null;
            if ($this->id) {
                foreach ($goalsData as $item) {
                    if (isset($item['id']) && (int) $item['id'] === (int) $this->id) {
                        $g = $item;
                        break;
                    }
                }
            } else {
                if (isset($goalsData[$goalIndex])) {
                    $g = $goalsData[$goalIndex];
                    $goalIndex++;
                }
            }
            if ($g !== null) {
                $this->culturalMakingStageOther = $g['culturalMakingStageOther'] ?? null;
                $goalDataByObjectId[spl_object_id($this)] = $g;
            }
        });

        $app->hook('entity(OpportunityWorkplan.Entities.Delivery).save:before', function () use ($app, &$goalDataByObjectId) {
            /** @var Delivery $this */
            $data = self::getInstance()->getWorkplanSaveDataFromRequest($app);
            if ($data === null) {
                return;
            }
            $goal = $this->goal;
            if (!$goal) {
                return;
            }
            $g = $goalDataByObjectId[spl_object_id($goal)] ?? null;
            if ($g === null && $goal->id) {
                $goalsData = $data['workplan']['goals'] ?? [];
                foreach ($goalsData as $item) {
                    if (isset($item['id']) && (int) $item['id'] === (int) $goal->id) {
                        $g = $item;
                        break;
                    }
                }
            }
            if ($g === null) {
                return;
            }
            $deliveriesData = $g['deliveries'] ?? [];
            $d = null;
            if ($this->id) {
                foreach ($deliveriesData as $item) {
                    if (isset($item['id']) && (int) $item['id'] === (int) $this->id) {
                        $d = $item;
                        break;
                    }
                }
            } else {
                static $deliveryIndexByGoalId = [];
                $oid = spl_object_id($goal);
                $idx = $deliveryIndexByGoalId[$oid] ?? 0;
                if (isset($deliveriesData[$idx])) {
                    $d = $deliveriesData[$idx];
                    $deliveryIndexByGoalId[$oid] = $idx + 1;
                }
            }
            if ($d !== null) {
                $this->typeDeliveryOther = $d['typeDeliveryOther'] ?? null;
            }
        });
    }

    /**
     * Retorna o payload do POST workplan/save se a requisição atual for essa; caso contrário null.
     * Usa os dados já parseados do controller workplan (evita php://input, que pode estar vazio).
     * @return array|null
     */
    public function getWorkplanSaveDataFromRequest(App $app): ?array
    {
        static $cached = null;
        static $checked = false;
        if ($checked) {
            return $cached;
        }
        $checked = true;
        $workplanController = $app->controller('workplan');
        if ($workplanController && !empty($workplanController->data['workplan'])) {
            return $cached = $workplanController->data;
        }
        if (php_sapi_name() === 'cli') {
            return $cached = null;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'POST') {
            return $cached = null;
        }
        $path = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($path, 'workplan') === false) {
            return $cached = null;
        }
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return $cached = null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['workplan'])) {
            return $cached = null;
        }
        return $cached = $decoded;
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
