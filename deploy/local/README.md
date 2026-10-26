# Local isolated mobile services

The API runs in its own XAMPP Apache process on `https://127.0.0.1:8443`. A separate HTTPS browser process hosts pairing at `https://localhost:8444/mobile-enrollment`. Both bind only to the loopback interface; no firewall or global Apache configuration changes are made. The existing HTTP site is unchanged except that mobile API routes now require the API surface.

Run `C:/xampp/php/php.exe spark caresys:migrate`, then run `pwsh -File deploy/local/start.ps1`. Stop only these processes with `pwsh -File deploy/local/stop.ps1`.

Without a native mobile app, open `https://127.0.0.1:8443/mobile/v1/pairing/test-device` (also linked from the pairing screen). Create a real code, keep the test-device tab open, and approve that code at `https://localhost:8444/mobile-enrollment`. The test tab polls the real API and verifies profile access after approval. Select **Disconnect test device** when finished. It keeps credentials only in memory and attempts revocation when closed. This helper is available only in development, over HTTPS, from a direct loopback connection; it returns 404 otherwise. Sample/placeholder codes cannot be paired.

The script generates a self-signed 30-day localhost certificate under ignored `runtime/`. Import `runtime/localhost.crt` into your user certificate store if desired, or explicitly accept the localhost certificate warning in your test browser. Native clients must trust that certificate for local tests; do not disable certificate verification in released apps. This deployment does not install trust certificates automatically. Regenerate the local certificate after expiry with the servers stopped.

The API loads a separate environment containing only the existing restricted DML account, never migration/test credentials, and has a separate writable/cache directory. These local processes run under your Windows account and share application code and the CareSys database. They are not a VM or OS account security boundary. For production use the container deployment and separately provisioned database credentials.

The browser cookies use `localhost`; the native API host uses `127.0.0.1`. Never send browser cookies to the API. API static files, login screens, enrollment and browser routes are denied before framework dispatch. Browser hosts deny `/mobile/v1/*` before boot as well.

Local mode uses development data but API debug output is disabled. Do not expose these local servers publicly.
