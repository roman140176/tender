# AI-слой: провайдер-агностичный фундамент + AI-разбор тендера

**Дата:** 2026-06-21
**Контекст:** Радар в текущем виде немонетизируем — нужен AI-слой (решение из сессии
2026-06-21). Схема БД под AI уже есть, но осиротевшая: модели `AiSummary`
(summary/requirements/risks по тендеру) и `AiUsage` (calls/tokens по company×месяц)
не наполняются ничем. Строим код, который ложится на эту схему.

**Стратегические решения (из разговора):**
- Не прибивать гвоздями ни одного вендора — провайдер за интерфейсом, как
  `ChannelNotifier`/`ChannelNotifierResolver`.
- **Тиринг по задаче**: `cheap` (массовый скоринг/саммари) vs `strong` (премиум-КП).
  Модель и провайдер — строчка в конфиге.
- Дефолтный safe-провайдер `Null` (AI выключен) — миграции/прод не падают без ключа.
- Первый конкретный драйвер — **Qwen** (назван Романом: дёшево, OpenAI-совместимый
  `/chat/completions`). RU-native (GigaChat/YandexGPT) — следующий драйвер за тем же
  интерфейсом (вне scope этой итерации).

## Архитектура (зеркало notification-каналов)

```
app/Ai/
  Contracts/LlmClient.php        interface { complete(LlmRequest): LlmResponse }
  LlmRequest.php                 readonly DTO: system, user, jsonMode, maxTokens
  LlmResponse.php                readonly DTO: content, promptTokens, completionTokens, model
  LlmClientResolver.php          tier(string) → LlmClient (по config('ai.tiers'))
  Providers/
    NullLlmClient.php            safe default: бросает AiDisabledException
    FakeLlmClient.php            тест-дубль: заранее заданный ответ (для unit-тестов сервиса)
    QwenLlmClient.php            Http-фасад, OpenAI-compatible, Bearer-ключ, разбор usage
  Exceptions/
    AiException.php              база
    AiDisabledException.php      AI не сконфигурён
    LlmTransportException.php    сеть/HTTP-ошибка провайдера
app/Services/Ai/
  TenderSummarizer.php           Tender → промпт → LlmClient(cheap) → AiSummary + AiUsage
app/Jobs/
  SummarizeTenderJob.php         идемпотентный (skip если summary свежий), company-scoped usage
app/Console/Commands/
  SummarizeTenderCommand.php     ai:summarize {tender}
config/ai.php                    enabled, default_tier, tiers{cheap,strong}, providers{qwen}, monthly_token_limit
```

Биндинг `LlmClient`-резолвера и провайдеров — в `AppServiceProvider` (как EisGateway/MaxGateway).

## Задачи (TDD: red → green → refactor каждая)

1. **Контракт.** `LlmClient` + `LlmRequest`/`LlmResponse` DTO + иерархия `Ai\Exceptions`.
   Тест: DTO держат значения, иммутабельны.
2. **Конфиг + резолвер.** `config/ai.php`, `LlmClientResolver` (tier→provider), биндинг.
   Тест: резолвер отдаёт нужный класс по тиру; неизвестный тир → исключение;
   при `ai.enabled=false` → `NullLlmClient`.
3. **Null + Fake провайдеры.** `NullLlmClient` (бросает `AiDisabledException`),
   `FakeLlmClient` (отдаёт заданный `LlmResponse`, пишет лог вызовов).
   Тест: Null бросает; Fake возвращает заданное.
4. **QwenLlmClient.** Http-фасад → `POST {base}/chat/completions`, Bearer-ключ,
   system+user messages, разбор `choices[0].message.content` и `usage`.
   Ошибка/4xx/5xx → `LlmTransportException`. Тест: `Http::fake` — корректное тело
   запроса, парсинг ответа, маппинг ошибки.
5. **TenderSummarizer.** Промпт из полей тендера (title/description/customer/price/
   okpd2/...), запрос в `jsonMode`, парсинг {summary, requirements[], risks[]},
   `updateOrCreate` `AiSummary` по `tender_id`, инкремент `AiUsage(company, period)`.
   Тест (с `FakeLlmClient`): создаётся AiSummary, апдейт не дублирует, usage растёт.
6. **SummarizeTenderJob + команда.** Джоб (tries/backoff/onQueue), идемпотентность
   (skip свежего summary), `ai:summarize {tender} {--company=}`. Тест: джоб зовёт
   сервис; повторный вызов не пересоздаёт.
7. **EIS token-expiry guard.** Команда `eis:token-check` (+ расписание): если до
   протуха токена < N дней — алерт через существующий канал уведомлений. Закрывает
   риск «декабрь 2026 молча убьёт пайплайн». Тест: при близком сроке шлёт алерт.

## Тестирование
- Unit: DTO, резолвер, провайдеры (Http::fake), summarizer (FakeLlmClient).
- Feature: джоб + команда (RefreshDatabase, фабрики).
- Цель: phpstan L6 = 0, весь набор зелёный.

## Вне scope (follow-ups)
- GigaChat/YandexGPT драйверы (RU-native дефолт для cheap-тира).
- Премиум-генерация КП (strong-тир) — отдельная фича.
- Квота `monthly_token_limit` enforcement в джобе/UI.
- Привязка саммари к UI карточки тендера.
