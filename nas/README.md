# Garmin-connector op de Synology NAS (gratis, altijd aan)

De NAS serveert `garmin.db` aan de Claude-connector. Alleen PHP (Web Station) en Tailscale nodig, geen Python of Docker. Werkt ook op een oude ARM-NAS zoals de DS214+.

- `server.php`: MCP-server met inlogpagina (één wachtwoord) op `/mcp`.
- `fetch_db.php`: haalt elke dag de nieuwste `garmin.db` uit de privé draft release `garmin-db`.
- `start.sh`: start de server en Tailscale Funnel (`https://<nas>.<tailnet>.ts.net`).
- `install.sh`: eenmalige installatie in `/volume1/garmin`.

## Installeren

1. DSM → Configuratiescherm → Gedeelde map → maak `garmin` (alleen admin).
2. Tailscale-app op de NAS: inloggen. In de Tailscale-admin (login.tailscale.com) → DNS → **HTTPS Certificates** aan.
3. GitHub → Settings → Developer settings → Fine-grained token: alleen repo `garmin-givemydata`, *Contents: Read and write* (nodig voor de draft release).
4. DSM → Configuratiescherm → Taakplanner → Maken → Geactiveerde taak → Door gebruiker gedefinieerd script, gebruiker **root**, gebeurtenis **Opstarten**, script:
   ```
   sh /volume1/garmin/mcp/start.sh
   ```
5. Nog een taak: Geplande taak, gebruiker **root**, dagelijks 07:00:
   ```
   sh /volume1/garmin/mcp/start.sh update
   ```
6. Eenmalig: Geplande taak (root), script hieronder, opslaan, rechtsklik → **Uitvoeren**, daarna de taak verwijderen:
   ```
   curl -fsSL https://raw.githubusercontent.com/StephanH76/garmin-givemydata/main/nas/install.sh | sh -s -- 'JOUW-WACHTWOORD' 'github_pat_...'
   ```
7. File Station → `garmin/mcp/install.log`. Staat er een link van Tailscale om Funnel aan te zetten: open die, keur goed, en voer de opstart-taak (stap 4) één keer uit. Onderaan `server.log` staat je adres (`https://….ts.net`).
8. claude.ai (browser of desktop-app) → Instellingen → Connectors → oude Garmin verwijderen → Add custom connector: naam `Garmin`, URL `https://<nas>.<tailnet>.ts.net/mcp` → Connect → wachtwoord.

Controle: `https://<nas>.<tailnet>.ts.net/health` toont `db_present: true` en de laatste activiteit.

Toegang blijft een jaar geldig, ook na een herstart van de NAS. Een ander wachtwoord in `config.php` betekent opnieuw verbinden.
