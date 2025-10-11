# Traefik Reverse Proxy Integration

The local Docker environment now includes a [Traefik](https://traefik.io/) reverse proxy that sits in front of the application containers. Traefik inspects container metadata and automatically configures HTTP routing, which provides several benefits over exposing container ports directly.

## What Traefik Replaces

* Direct host port bindings on the Laravel backend, Vite frontend, and Sockudo websocket containers.
* Manual management of per-service hostnames and ports when accessing the stack from a browser.

## Improvements

* **Unified entrypoint** – All HTTP traffic now flows through `http://app.localhost`, `http://api.localhost`, and `http://ws.localhost`, eliminating port juggling for developers.
* **Automatic routing** – Traefik derives routes from Docker labels, simplifying maintenance as services are added or removed.
* **Websocket support** – Sockudo traffic is proxied via Traefik, enabling the frontend to connect through the same gateway without exposing raw ports.
* **Extensibility** – Traefik’s dashboard (`http://localhost:8080`) offers live insight into routing and is a foundation for future HTTPS, middleware, or authentication upgrades.

To use the new hostnames locally, add the following entries to your `/etc/hosts` file:

```
127.0.0.1 app.localhost api.localhost ws.localhost
```

After updating `/etc/hosts`, start the stack with `docker compose up --build` and browse to `http://app.localhost` for the Vite frontend and `http://api.localhost` for the Laravel API.
