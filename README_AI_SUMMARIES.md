# Dynabase Client Survey AI — Implementation & Verification

## Scope
- AI summaries for **one survey response** from Client Surveys > Response Details.
- AI summaries for **one survey link** from its details page.
- AI summaries for **multiple links** from Client Surveys > AI Summaries, with selection preserved across paginated search results.
- Client-survey AI summary requests are permission-gated by `client_surveys.ai.summarize` **and** `client_surveys.view`.
- Super Admin has the permission through the existing catalog; authorized staff may receive the new permission in Users & Access. Existing custom permission lists are not silently modified.

## Configuration
Use your existing backend `.env` values:

    AI_ASSISTANT_ENABLED=true
    OPENAI_API_KEY=<real server-side key>
    OPENAI_MODEL=gpt-4.1-mini
    AI_HOURLY_LIMIT=12
    AI_DAILY_LIMIT=40
    AI_CHAT_RETENTION_DAYS=180

Never put `OPENAI_API_KEY` in a `VITE_` variable or client-side code. No actual `.env` or `includes/connection.php` is shipped.

## Deployment sequence
1. Back up your database and application.
2. Import `Dynabase_Survey_AI_Migration.sql` into the **same** Dynabase database used by the API.
3. Deploy backend changed files preserving paths, including `index.php`, `includes/permissions.php`, `includes/surveyAi.php`, and two `routes/surveys/admin/ai/` endpoints.
4. Deploy frontend changed files preserving paths, then `npm ci && npm run build` in your local project.
5. Confirm PHP cURL extension is available and the server can reach `https://api.openai.com` over HTTPS.
6. Sign in as Super Admin; test a disposable survey response, a one-link summary and two-link summary.
7. Grant `Generate AI survey summaries` only to staff authorized to analyze survey data.

## Endpoints
- `GET /surveys/admin/ai/status`: enablement flag, model, global quota usage and supported limits.
- `POST /surveys/admin/ai/summary`: `{ "scope":"response", "response_id":123 }` or `{ "scope":"links", "link_ids":[10,11] }`.
- Requires session, permission and normal CSRF/origin protection; creates no public routes.

## Data minimization and safety
- Only survey scores, ratings and feedback are sent. Dedicated respondent name, email, telephone number, address, and invitation recipient fields are excluded.
- Free-text comments are screened for email addresses and recognizable phone-like strings, but may still contain other personal or confidential information. Authorized staff should review the privacy impact before enabling the feature.
- Survey text is treated as untrusted evidence, not instructions. Stored submission-time scores are kept distinct from reweighting historical surveys.
- Request body uses OpenAI Responses API with `store:false`. Summary text is returned to the browser and **not saved** to Dynabase; UI includes Copy Summary.
- Only request metadata (actor, count, scope, model, status, token usage and timestamps) is stored in `client_survey_ai_requests`; it is cleaned lazily on successful requests using `AI_CHAT_RETENTION_DAYS` (180).
- `AI_CHAT_RETENTION_DAYS` therefore controls **request metadata**, not chat conversations; no chat-history feature is introduced.
- Summary requests have `Cache-Control: private, no-store`; frontend skips automatic POST retries to avoid inadvertent repeated charges.
- Quotas are **global platform caps**, 12 per UTC hour and 40 per UTC day by default, protected by transactional quota bucket updates. Failed attempts after reservation count against the limit.
- Maximum 20 distinct links and 150 responses per request; larger sets return an explicit error rather than silently sampling. Input-size cap of 160,000 UTF-8 bytes also applies.
- Deleted responses and links are excluded. If a response is deleted during analysis the result is not returned.

## Verification completed
- PHP syntax: `index.php`, `includes/permissions.php`, `includes/surveyAi.php`, `routes/surveys/admin/ai/status.php`, `routes/surveys/admin/ai/summary.php` PASS.
- JS/JSX syntax: service and 4 client-survey JSX files PASS.
- CSS parsing: new dialog stylesheet PASS.
- Isolated PHP tests: redaction, v1/v2 extraction, deterministic statistics, Responses API text extraction PASS.
- Checked that no API key or `.env` data is included in the delivered bundles.
- ZIP integrity checks PASS.

## Not verified in this environment
- Live MariaDB migration execution or transaction locking under concurrent requests.
- Actual OpenAI API calls, billing, quota reset, and provider error handling.
- Full production Vite build (local install lacks Vite node_modules) and live browser/E2E behaviour.
- Final organizational privacy/legal approval for transmitting survey feedback to a third-party AI provider.

## Files
Backend: `index.php`, `includes/permissions.php`, `includes/surveyAi.php`, `routes/surveys/admin/ai/status.php`, `routes/surveys/admin/ai/summary.php`.
Frontend: `src/services/clientSurveyService.js`, `src/pages/client-surveys/ClientSurveyAiDialog.jsx`, `src/pages/client-surveys/ClientSurveyAiDialog.css`, `ClientSurveyList.jsx`, `ClientSurveyLinkDetailsPage.jsx`, `ClientSurveyProfilePage.jsx` in that same pages directory.
Migration: `Dynabase_Survey_AI_Migration.sql`.
