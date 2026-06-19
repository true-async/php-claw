# План разработки Workflow

Дорожная карта реализации модели из `workflow-architecture.md`. Сопутствующий
документ: там дизайн, здесь порядок работ, состояние компонентов и риски.

## Принципы

- **Вертикальные срезы:** каждая фаза это рабочий, протестированный, мёржабельный кусок.
- **CI зелёный после каждого шага** (PHPStan уровня 8, cs-fixer, Testo).
- **Максимум переиспользования:** `turnLoop`, `Session`, `SessionStore`, `Registry`,
  `executor` (permission/audit/timeout), `ConversationInterface`, TrueAsync.
- **Конвенция:** интерфейсы с суффиксом `Interface`; `while (true)` вместо `for(;;)`.

## Состояние

> **Обновление после код-ревью (2026-06-16): новая модель реализована и приземлена.**
> Workflow это `WorkflowInterface.run(WorkflowContext): void` (код пишет AI; `WorkflowAbstract`
> даёт дефолты). `WorkflowContext` — единственная дверь: `param()`, `step(StepSpec, ...inputs)`
> (собирает pre-context из артефактов предшественников + инструкции), `ai()`/`tool()`,
> `bindCritic(tag, rubric)`, `issue()`/`project()`. `StepRunner` (бывш. `WorkflowEngine`) гонит
> один шаг: работа → `needsHuman`? → критик (только если предписан rubric — на шаге или по тегу)
> → политика. `RunConfig` — бандл окружения прогона (вместо россыпи `model`/`system`).
> `WorkflowLauncher` — точка старта: Project → Issue → Run → `run(ctx)`. Супервизор
> (`SupervisedStepExecutor`+`TieredSupervisor`, маркер `[question]` → guide/solve/тир/человек)
> вшит в исполнение шага. Задача = плоский метод (журналируемые `ctx->task/parallel` — позже,
> нужен durable store). Старый compiled-слой изолирован как `Compiled*` и сосуществует.
> **Осталось:** R4 (миграция `define/run` на `run(ctx)`, ретайр старого формата) и durable
> `task()/parallel()`. Детали в памяти `workflow-supervisor-design` + `workflow-feature-state`.

### Готово (скелеты + ядро)

- Пакеты: `Claw\Workflow`, `Claw\Knowledge`, `Claw\Project`, `Claw\Journal`,
  `Claw\Tool\ToolSet`, `Claw\Agent\TurnLoopInterface` + `TurnResult`.
- Сущности/типы: `Step`, `StepSpec`, `StepResult`, `StepCard`, `StepStatus`,
  `StepOutcome`, `TaskInterface`, `TaskKind`, `TaskStatus`, `Score`,
  `CriticInterface`, `RetryPolicyInterface` + `ThresholdRetryPolicy`,
  `StepExecutorInterface`, `Budget`, `ContextNode`, `AgentRouterInterface`,
  `WorkflowState` (с цепочкой project → issue → workflow → parent).
- База знаний: `KnowledgeBaseInterface`, `Article`, `Tag`, `Provenance`.
- Проект: `Project`, `Issue`, `IssueStatus`.
- Журнал: `JournalInterface`, `JournalEntry`, `JournalScope`.
- **Ядро движка:** `WorkflowEngine::runStep` (петля шаг → критик → политика →
  повтор/эскалация) + тест.

### Переходный слой (мигрировать в фазе 3)

Старая модель, рабочая и в проде, но не по новому дизайну:
`WorkflowInterface` с `run(array): array`, старый `WorkflowContext` (`call`/`run`),
`WorkflowRunner`, `define_workflow` / `run_workflow` инструменты. Их тесты зелёные.

## Фазы

1. **TurnLoop как компонент.** ✅ `DefaultTurnLoop implements TurnLoopInterface` —
   чистая **headless** ReAct-петля под шаг workflow (`run(history): TurnResult`).
   `Session` **намеренно не трогаем**: он заточен под интерактив (inbox, `/stop`,
   статус-строка, сообщения человеку), и протаскивать эти заботы в шов означало бы
   протекающую абстракцию. Headless-петля без UI/отмены/сообщений человеку: отмена
   просто всплывает исключением (structured concurrency TrueAsync), прогресс позже
   уходит в журнал. Две маленькие ReAct-петли сосуществуют; схождение — позже, когда
   устаканится шов step-executor. (PHPStan 8 чисто, тесты зелёные.)
2. **Исполнитель и критик.** ✅ `TurnLoopStepExecutor implements StepExecutorInterface`
   (шаг = один turn-loop через инжектированный `TurnLoopInterface`; pre-context → working
   context + артефакт `answer`; несколько turn-loop/параллельные задачи — позже).
   `AgentCritic implements CriticInterface`: один round-trip без инструментов → строгий
   JSON-вердикт → `Score` (значение клампится 0..max, `adviseHuman`, note). Парсер берёт
   **последний** сбалансированный JSON-объект со `score` (устойчив к прозе/нескольким
   объектам/эху данных; `adviseHuman` через `filter_var`, не `(bool)`); нечитаемый
   вердикт → `Score(0, adviseHuman)` (эскалация, а не пропуск). Встаёт в готовый
   `WorkflowEngine::runStep`. (PHPStan 8 чисто, тесты зелёные.)
3. **Новый контекст (аддитивно) + перевод интерфейса.**
   - ✅ **Composition root `WorkflowRun`** (новое имя, не конфликтует со старым
     `WorkflowContext`): `param()`, `step(StepSpec, preContext): StepResult` — строит
     `DefaultTurnLoop`+`TurnLoopStepExecutor`+`AgentCritic`+`WorkflowEngine` на шаг из
     агента/ToolSet/rubric/guidance шага. Связывает фазы 1-2 в реальный многошаговый
     прогон. `runStep` получил `preContext` (поток данных между шагами), `StepResult`
     несёт `number`, критик — `guidance`+`maxScore`. (PHPStan 8, тесты зелёные.)
   - **Отложено (отдельным осознанным шагом):** перевод `WorkflowInterface` на
     `run(WorkflowContext): void` и миграция переходного слоя — **скомпилированные
     workflow на диске зависят от старой формы `WorkflowInterface`/`WorkflowContext`**,
     поэтому миграция = отдельная фаза с разбором совместимости персистентных классов.
   - Здесь же позже: `node()`/`kb()`/`budget()`/`journal()` в контексте, **навигация как
     Tool** (подъём project → issue → workflow), Tool-обёртки workflow/navigation.

   **Новые проектные направления (интервью 2026-06-16, см. память
   `workflow-supervisor-design`):** супервизор-над-агент (разблокирует застрявшие шаги:
   `[question]` → guide/solve/сильнее-модель/человек; предлагает — решает код); критик
   как **подшаг** (мульти-задачный); **теги шагов** (`StepSpec.tags` → before/after
   обработчики по тегу). Заходят отдельными аддитивными срезами.
4. **Durable.** `WorkflowStateStore` + `WorkflowRegistry` (статусы) + `RecoveryService`
   (скан при старте: running → resume, waiting_human → restore, краш-цикл → human).
   На SQLite поверх `SessionStore`. Здесь же: **генерация id** прогонов (явный сервис)
   и **связка журнала с `AuditMiddleware`** (аудит вызовов вливается в `Claw\Journal`).
5. **Человек в петле.** `HumanGateway` поверх `ConversationInterface`: карточка шага,
   мягкий таймаут (дедлайн в state), FIFO-очередь ожиданий, маршрутизация ответа в шаг.
6. **База знаний.** `ObsidianKnowledgeBase` (статьи = MD в репо: frontmatter,
   `[[wikilinks]]`, папки = scope). Сначала `get/byTag/add/update` на MD; затем
   `search()` = `sqlite-vec` индекс + провайдер эмбеддингов. KB как Tool.
7. **Проект и Issue.** Хранилища `Project`/`Issue`; issue → запуск прогонов; статусы
   верхнего уровня; журнал на всех уровнях.
8. **Задачи и параллелизм.** Реализации `TaskInterface` (Prompt / Tool / Subworkflow /
   Code); параллельный `spawn`/`await` (барьер на обязательных, best-effort на
   необязательных); `TaskStatus` persist.
9. **Enforcement и мутация.** ToolSet режет specs агента; Budget заряжается и
   останавливает; `define` внутри workflow (мутация) с инвариантами доверия.

MVP = фазы 1–3 (workflow реально исполняется с критиком и эскалацией).

## Чек-лист ключевых компонентов

Продумано и готово скелетом:

- [x] Workflow / Step / Task / Critic / RetryPolicy / Budget / ToolSet
- [x] WorkflowEngine (петля одного шага) + тест
- [x] WorkflowState (durable снимок + иерархия)
- [x] ContextNode (дерево), Journal, KnowledgeBase, Project / Issue
- [x] TurnLoopInterface (шов агента)

Продумано в дизайне, скелета пока нет (по фазам):

- [x] `DefaultTurnLoop` (фаза 1, headless) · `TurnLoopStepExecutor` + `AgentCritic` (фаза 2) — готово
- [ ] Новый `WorkflowContext` + перевод `WorkflowInterface` (фаза 3)
- [ ] `WorkflowStateStore` / `WorkflowRegistry` / `RecoveryService` (фаза 4)
- [ ] `HumanGateway` (фаза 5)
- [ ] `ObsidianKnowledgeBase` + Tool-обёртка KB (фаза 6)
- [ ] Хранилища `Project`/`Issue` + оркестрация (фаза 7)
- [ ] Реализации `TaskInterface` + параллелизм (фаза 8)

## Сквозные компоненты

Не отдельная фаза, а компоненты, проходящие через несколько фаз. Каждый привязан к
фазе, где его строить:

- [ ] **Генерация id** прогонов / issue / статей — **фаза 4** (прогон получает id) и
      **фаза 7** (issue, статьи). В скриптах workflow `Date`/random ограничены, поэтому
      источник id даётся снаружи как явный сервис.
- [ ] **Связка журнала с `AuditMiddleware`** — **фаза 4**: аудит вызовов инструментов
      вливается в `Claw\Journal` через общий интерфейс записи.
- [ ] **Навигация как Tool** — **фаза 3**: обёртка над `ContextNode` и цепочкой
      project → issue → workflow, чтобы агент сам поднимался за контекстом.
- [ ] **Tool-обёртки** — workflow-as-tool (**фаза 3**, миграция `run_workflow`),
      KB-as-tool (**фаза 6**), navigation-as-tool (**фаза 3**); агент зовёт их
      единообразно через `Registry`.
- [ ] **Провайдер эмбеддингов** — **фаза 6**: отдельный компонент, зовущий
      embedding-модель, питает `sqlite-vec` для KB `search()`. Изолирован за `search()`.

## Риски

- **Миграция переходного слоя** (`run(array)` → `run(WorkflowContext)`): делать целиком
  в фазе 3, не оставляя половину; следить, чтобы тесты `define/run` не повисли.
- **sqlite-vec + эмбеддинги:** внешняя зависимость; изолировать за `search()`, не
  блокировать ею остальную KB (get/byTag/add/update работают на чистом MD).
- **Две ReAct-петли (фаза 1):** `Session` оставлен как есть — интерактивный путь
  (консоль/Telegram) не трогаем, `DefaultTurnLoop` это отдельная headless-петля. Риск
  дрейфа двух копий кора (~15 строк); снимается схождением позже, когда устаканится шов
  step-executor. Сейчас `DefaultTurnLoop` даже чуть устойчивее (терминирует по
  диспатчируемому `toolCalls`, а не по `wantsToolUse()`).
