# Container deployment (prepared; Docker runtime required)

The running local deployment is documented in `../local/README.md`. Docker is not installed on the current machine, so this optional configuration has not been built or run here.

Copy `runtime.env.example` to both `api.env` and `web.env` and supply restricted database accounts. Use a separate API account with DML privileges only on required CareSys tables; never provide migration/root credentials. The external database must accept connections from the Docker network and have the pairing migration applied using the separate migration command. Production mode rejects demo accounts. Use real activated accounts, or explicitly choose development mode only for local synthetic tests.

From this directory run `docker compose config --quiet`, `docker compose build`, then `docker compose up -d`. API: `https://api.localhost:9443/mobile/v1`; browser pairing: `https://localhost:9443/mobile-enrollment`. The Caddy internal CA must be trusted by local test clients. Only the proxy publishes a loopback port. API and web use separate containers and networks, read-only code filesystems, and separate writable areas. They share only the external database. Use `docker compose down` to stop them.

Single API/web replicas only: writable cache/session state is ephemeral. Before scaling or production use, configure a shared throttler/session store with fail-closed handling. For public hosting, replace local hostnames, use real DNS and Caddy public TLS, set base URLs, publish the intended interface, provision database TLS and credentials, and pin reviewed image digests. The API trusts only its proxy's fixed private IP; the proxy replaces forwarded scheme/IP headers.

Reference: [Caddy reverse proxy documentation](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy) and the [official PHP image](https://hub.docker.com/_/php).
