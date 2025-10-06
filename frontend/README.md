# Predictive Patterns Frontend

## Development

```bash
npm install
npm run dev
```

## CSRF protection

- The app bootstraps the Laravel Sanctum CSRF cookie on startup by calling `/sanctum/csrf-cookie` with `withCredentials` enabled. This happens automatically through `ensureCsrfCookie()` in `src/services/csrf.js` and is invoked before any authenticated traffic leaves the SPA.
- Keep the frontend and API on the same origin during development (for example, always use `http://localhost` _or_ `http://127.0.0.1`). Mixing hostnames or protocols causes browsers to treat the requests as cross-site and invalidate the CSRF token stored in the session cookie.
- Leave Laravel's CSRF middleware enabled on the backend. The middleware is part of the Sanctum stateful pipeline and is required for cookie-based authentication to remain secure.

### Linting

```bash
npm run lint
```

## Environment variables

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | Optional base URL for API requests. When not provided the app targets the `/api` relative path, matching the development proxy configuration. |
| `VITE_PROXY_TARGET` | Overrides the Vite dev-server proxy target. Defaults to `http://localhost:8080` when running locally and is set to `http://nginx` in Docker Compose so the frontend container can reach the backend. |
| `VITE_PUSHER_APP_KEY` | Sockudo application key shared with the SPA. Falls back to `local-key` during local development. |
| `VITE_PUSHER_HOST` | Hostname of the Sockudo websocket server. Defaults to the current browser hostname when omitted. |
| `VITE_PUSHER_PORT` | Port exposed by Sockudo for websocket connections. Defaults to `6001`. |
| `VITE_PUSHER_SCHEME` | Scheme used when connecting to Sockudo (`http` or `https`). Defaults to `http`. |

Set the variable in a `.env` file if the frontend is served from a different origin than the API or when deploying to production.

> **Tip:** When running the local Sockudo container, keep the frontend `VITE_PUSHER_APP_KEY` aligned with the backend's `PUSHER_APP_KEY` so websocket subscriptions authenticate correctly.

## Request idempotency

Model training, evaluation, and prediction submissions now emit an `Idempotency-Key` header derived from the current form state. As long as the payload for a given action remains unchanged, the frontend reuses the same key across retries so the API will not enqueue duplicate jobs. Changing the relevant form fields automatically rotates the key and allows a fresh submission.
