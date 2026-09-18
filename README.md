# plugin-AldirBlanc

Plugin do Mapas Cultural que implementa a integração entre o Programa Aldir Blanc/PNAB e a API CultBR (gestão de entes federados via PAR, e envio de oportunidades). Usado em conjunto com o `theme-Pnab`, que registra os hooks que disparam o fluxo.

## Visão geral

A integração com a API CultBR é composta por **quatro casos de uso independentes**:

1. **Gestor → Sync de Entes Federados** — disparado após login de usuários não-admin; busca os entes federados vinculados ao CPF do usuário na API CultBR e sincroniza localmente (`AldirBlanc\Entities\FederativeEntity`, `FederativeEntityAgentRelation`, role `GestorCultBr`). Síncrono, roda na própria request HTTP (`Controller::POST_startSync` → `Jobs\GestorCultJob::sync()`).
2. **Oportunidade → CultBR** — cria/atualiza uma oportunidade na API CultBR quando ela é gerada a partir de um modelo oficial e publicada. Assíncrono, via fila (`Jobs\OportunidadeCultJob`), com retry (até 3 tentativas).
3. **Consulta de Ações do PAR** — repasse direto e síncrono de uma listagem paginada da API CultBR (`Controller::GET_parAcoes` → `Http\Clients\ParAcaoClient`), sem persistência local.
4. **Integração de Oportunidades (inbound)** — endpoint que o CultBR/"Meus Aplicativos" usa para consumir dados de oportunidades do Mapas (`Controller::API_integrationOpportunities`), autenticado via JWT (não o Bearer estático usado nos demais clients).

### Componentes principais

| Componente | Papel |
|---|---|
| `Http/Clients/AbstractClient.php` | Base dos clients HTTP: Bearer token, timeouts, parse de resposta, modo `development` com fixtures locais (`Http/Fixtures/`) |
| `Http/Clients/GestorClient.php` | Busca dados do gestor/entes federados por documento |
| `Http/Clients/OportunidadeCultClient.php` | Create/update de oportunidade na API CultBR |
| `Http/Clients/ParAcaoClient.php` | Lista ações do PAR |
| `Jobs/GestorCultJob.php` | Orquestra o sync de entes federados |
| `Jobs/OportunidadeCultJob.php` | Job de fila para create/update de oportunidade |
| `Services/FederativeEntityService.php` | Lê o ente selecionado na sessão e seus dados já persistidos |
| `Services/UserAccessService.php` | Checagens de role (`isGestorCultBr()`, `isAdmin()`, etc.) |
| `Controller.php` | Endpoints do plugin (sync, seleção de ente, perfil, integração de oportunidades) |

Para uma análise detalhada do fluxo completo (casos de uso, sequência passo a passo, ajustes de testabilidade), veja `analysis.md` na raiz do projeto principal.

## Configuração (variáveis de ambiente)

**Seis precisam ser declaradas.** O que não aparece aqui é fato sobre a API — os caminhos de cada operação, por exemplo — e vive no `Plugin.php`, declarado por provedor.

| Variável | Uso |
|---|---|
| `PNAB_CULTBR_PROVIDER` | Qual API atende: `gestao` ou `conecta`. **Sem default** — ausente, a resolução falha nomeando os valores aceitos e o login do gestor não completa |
| `PNAB_CULTBR_MODE` | `live` ou `development`. Em `development` os clients devolvem fixture, sem tocar a rede. Default: `live`, para que ambiente mal configurado falhe em vez de simular em silêncio. Valor fora da lista falha nomeando os aceitos |
| `PNAB_CULTBR_HOST` | Host base da API ativa |
| `PNAB_CULTBR_TOKEN` | Bearer token, enviado em todas as requisições |
| `ALDIRBLANC_SUBSITE_ID` | Subsite onde a integração está habilitada |
| `ALDIRBLANC_APPLICATION_NAME` | Nome do `UserApp` que autoriza o endpoint inbound |

Os atrasos de enfileiramento (`ALDIRBLANC_INTEGRATION_DELAY_JOB` e `ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB`) e o TTL de cache (`ALDIRBLANC_INTEGRATION_CACHE_TTL`) têm valor declarado no `Plugin.php`; defini-los no ambiente só serve para sobrescrever. O TTL é zero por decisão — o cache está desabilitado.

**Trocar de API é trocar três valores:** `PROVIDER`, `HOST` e `TOKEN`.

## Como rodar os testes

Os testes do plugin rodam na stack Docker de testes do repositório principal (`tests/docker-compose.yml` — não é o mesmo ambiente do `dev/`), mas **toda a configuração necessária para habilitar plugin + tema nessa suíte vive aqui dentro**, em `tests/docker-compose.yml` deste plugin, como um único override via `docker compose -f`. Nenhum arquivo do repositório principal (`tests/config.d/`, `config/`) precisa ser editado. Plugin (`AldirBlanc`) e tema (`Pnab`) são sempre habilitados juntos — não há camadas opcionais.

Os comandos abaixo assumem `cwd = tests/` do **repositório principal** (o caminho relativo do override deste plugin depende disso):

```bash
cd tests   # tests/ do repositório principal, não deste plugin

# build da imagem (necessário na primeira vez ou após mudanças no Dockerfile/composer)
docker compose build

# roda um teste/método específico, com o plugin AldirBlanc + tema Pnab habilitados
docker compose -f docker-compose.yml -f ../src/plugins/AldirBlanc/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/AldirBlanc/SmokeTest.php

# roda um método específico
docker compose -f docker-compose.yml -f ../src/plugins/AldirBlanc/tests/docker-compose.yml \
  run --rm mapas pu /var/www/tests/AldirBlanc/SmokeTest.php --filter "testFederativeEntityAssociationIsPersisted"
```

Sem esse `-f`, a suíte roda normalmente sem o plugin — exatamente como antes, sem nenhum impacto (validado: `RoutesTest` segue `OK (14 tests, 93 assertions)` sem o override).

### Como funciona o override (`tests/docker-compose.yml` deste plugin)

`src/conf/config.php` do core varre, em ordem alfabética, todas as pastas terminadas em `.d/` dentro de `config/` e faz `array_merge` do conteúdo de cada uma. O override deste plugin monta `tests/config.d/` (deste diretório — contém `plugins.php` e `theme.php`) como uma pasta nova `zz-aldirblanc.d/` dentro de `/var/www/config/` — como `"zz-aldirblanc.d"` ordena depois de `"config.d"`, as chaves `'plugins'` (inclui `AldirBlanc`) e `'themes.active'` (`Pnab`) sobrescrevem as do core.

Por que o tema Pnab é sempre habilitado junto: alguns comportamentos (hooks de `auth.successful`, `blockAccessOnError`, `entity(Opportunity).insert/update:finish`) só existem com o Pnab ativo — são registrados pelo `Theme.php` do tema, não pelo plugin. **Trocar `themes.active` globalmente em `tests/config.d/0.main.php` do core quebra outros testes do projeto** (ex.: `RoutesTest`, já que o `blockAccessOnError` intercepta rotas que esses testes esperam sem redirecionamento) — por isso o escopo do override fica restrito a quando este `-f` é explicitamente incluído.

> Nota de implementação: o mount precisa ser do **diretório**, não de um arquivo isolado — montar um arquivo único dentro de uma subpasta que ainda não existe no host, dentro de outro bind mount (`../config:/var/www/config`), falha no Docker Desktop/virtiofs (macOS) com erro `mountpoint ... is outside of rootfs`.

### Modo `development` (fixtures)

Com `PNAB_CULTBR_MODE=development`, os clients HTTP devolvem o conteúdo da fixture que cada um declara na constante `FIXTURE`, em vez de fazer requisição real — é assim que os testes simulam a API sem rede. O layout é `Http/Fixtures/<provedor>/<operação>.php`, como `gestao/gestor.php` e `conecta/entes.php`.

O default é `live`: sem a variável, a integração fala com a API de verdade.

### Testes existentes (`tests/src/`)

| Arquivo | O que valida |
|---|---|
| `SmokeTest.php` | Add-on funcionando: asserts genéricos (round-trip de `ParAction`) + persistência real em banco (`FederativeEntity`/`FederativeEntityAgentRelation`) |
| `Traits/AssertsHooks.php` | Helper `assertHookFired()`/`assertHookNotFired()` — registra um listener temporário para confirmar que um hook foi (ou não foi) disparado, útil quando o efeito do hook não é observável diretamente |
| `AssertsHooksTest.php` | Valida o helper acima nos dois sentidos (positivo e negativo), provando que ele detecta falha de verdade |
