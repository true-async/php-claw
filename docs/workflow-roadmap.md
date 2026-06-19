# План разработки Workflow

Дорожная карта реализации. Сопутствующий документ: `workflow-architecture.md` (дизайн и
текущее состояние модели). Здесь — что уже есть, главный пробел и порядок дальнейших
работ.

## Принципы

- **Вертикальные срезы:** каждая фаза — рабочий, протестированный, мёржабельный кусок.
- **CI зелёный после каждого шага** (PHPStan уровня 8, cs-fixer, Testo).
- **Максимум переиспользования:** `DefaultTurnLoop`, `Registry`, `executor`
  (permission/audit/timeout), `ConversationInterface`, `Claw\Journal`, TrueAsync.
- **Конвенция:** интерфейсы с суффиксом `Interface`; `while (true)` вместо `for (;;)`.

## Текущая модель (реализовано)

Workflow — это **класс с состоянием** (его поля); шаги — **методы** с `#[Step]`;
`WorkflowAbstract` — **тонкий хелпер** (`ai`/`tool`/`param`/`step`/`find`/`set`/`log`;
дефолтный `run()` гонит `#[Step]`-методы по порядку, переопределяем для ручной
оркестрации). Durable **по снапшоту**: `WorkflowStateStore` хранит `{state, done}` по
`runId`; состояние восстанавливается на полях, сделанные шаги пропускаются. Окружение —
`Environment` (scoped, каталог `EnvKey`) + `executor()` из реестра scope. Палитра
инструментов — наименьшие привилегии (`Registry::only`). Критик/супервизор — **подшаги**
(вызов `ai()` в теле). Журнал — уровни Step/Task. Создание — `define_workflow` +
`WorkflowValidator` + `WorkflowStore`. Пример — `Example\ReviewFileWorkflow`.

### Готово

- **`Claw\Workflow`:** `WorkflowInterface`, `WorkflowAbstract`, `Step` (атрибут),
  `Environment`, `EnvKey`, `WorkflowStateStore` + `InMemoryStateStore`, `WorkflowStore`,
  `WorkflowValidator`, `Example\ReviewFileWorkflow`.
- **`Claw\Agent`:** `DefaultTurnLoop` (headless ReAct) + `TurnLoopInterface`/`TurnResult`.
- **`Claw\Tool`:** `Registry` (`only`/`specs`/`get`), `DefineWorkflowTool`.
- **`Claw\Exec`:** `ChainExecutor` + middleware `Audit`/`Permission`/`Timeout`.
- **`Claw\Journal`:** `JournalInterface`, `JournalEntry`, `JournalScope`.
- **`Claw\Project`:** `Project`, `Issue`, `IssueStatus`.
- **`Claw\Knowledge` (скелет):** `KnowledgeBaseInterface`, `Article`, `Tag`, `Provenance`.

## Главный пробел

**Ничто не ЗАПУСКАЕТ новую модель в проде.** Нет composition root, который собрал бы
`Environment` (worker / registry / store / journal) и инстанцировал+погнал
`WorkflowAbstract`-подкласс. `define_workflow` сейчас только **сохраняет** класс. Это
следующий приоритет и предпосылка для всего durable/human-in-the-loop.

## Дальше (по фазам)

1. **Run-path / composition root.** Построить `Environment` с дефолтами проекта и путь
   запуска (инстанцировать сохранённый класс + `run()`). Сюда же — permission `Policy`
   для автономного прогона (сейчас executor воркфлоу без permission = allow-all;
   далее — денлист, затем AI-оценка риска как ещё одна `Policy`).
2. **Durable SQLite-стор.** `WorkflowStateStore` на SQLite (поверх `SessionStore`);
   реестр прогонов + статусы; recovery при старте (running → resume,
   waiting_human → restore, краш-цикл → human). Связка журнала с `AuditMiddleware`.
3. **Человек в петле.** `waiting_human`: пауза и карточка шага через
   `ConversationInterface`; мягкий таймаут (дедлайн в state); FIFO-очередь ожиданий;
   маршрутизация ответа обратно в шаг.
4. **База знаний.** `ObsidianKnowledgeBase` (статьи = MD в репо: frontmatter,
   `[[wikilinks]]`, папки = scope). Сначала `get`/`byTag`/`add`/`update` на чистом MD;
   затем `search()` = индекс `sqlite-vec` + провайдер эмбеддингов. KB как Tool.
5. **Параллелизм в шаге.** `spawn`/`await` внутри тела шага (барьер на обязательных
   задачах, best-effort на необязательных).
6. **Мутация.** Workflow создаёт workflow: валидатор; права ⊆ родителя (анти-эскалация);
   остаётся session-scope, пока человек не продвинет в `Common`.

MVP уже пройден на уровне модели (шаг/состояние/палитра/журнал); следующий MVP запуска —
фаза 1.

## Риски

- **Run-path должен покрыть оба пути исполнения инструмента** — `tool_use` от модели и
  прямой `$this->tool()` — единым executor'ом scope, иначе палитра/permission разойдутся.
- **sqlite-vec + эмбеддинги** — внешняя зависимость; изолировать за `search()`, не
  блокировать ею остальную KB (`get`/`byTag`/`add`/`update` на чистом MD).
- **Снапшот состояния рефлексией** персистит все собственные поля подкласса — следить,
  чтобы в полях не оказалось несериализуемого (ресурсы, замыкания); это контракт для
  авторов воркфлоу.
