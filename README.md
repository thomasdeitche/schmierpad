# SchmierPAD

Persönliche Aufgabenliste pro Arbeitsplatz — kein Login, kein Benutzerkonto. Jeder Rechner im
Firmennetz bekommt automatisch seine eigene Liste, erkannt an seiner MAC-Adresse.

Live intern unter `https://schmierpad.intern/`.

## Was es ist

Ursprünglich eine einzelne HTML-Datei mit `localStorage`, die ein Mitarbeiter sich selbst gebaut
hatte ("TASK's") und die "wunderbar funktionierte". SchmierPAD übernimmt genau diese Bedienung
(Klick auf eine Zeile = erledigt, ✕ löscht), speichert die Daten aber zentral in einer MariaDB-
Datenbank statt im Browser — damit nichts verloren geht und mehrere Geräte am selben Arbeitsplatz
dieselbe Liste sehen.

## Was es kann

- **Zwei Listen:** offene Aufgaben mit hoher Priorität stehen oben unter "Dringend", alles andere
  (inkl. erledigter Aufgaben) darunter.
- **8 Prioritätsstufen** von "Jetzt sofort" (pulsierend rot) bis "Wenn mal Zeit ist", mit Farblegende
  per Klick änderbar.
- **Zeitstempel:** zeigt an, wie lange eine Aufgabe schon offen ist bzw. wie lange sie bis zur
  Erledigung gebraucht hat.
- **Löschen nur bei erledigten Aufgaben** — offene Aufgaben können nicht versehentlich entfernt
  werden (serverseitig erzwungen).
- **Export/Import** als JSON-Datei, z. B. beim Umzug auf einen neuen Rechner (Import geht nur in
  eine leere Liste, damit nichts vermischt wird).

## Wie die Zuordnung funktioniert

Es gibt keine Anmeldung. Der Server liest die MAC-Adresse des anfragenden Clients aus der
ARP-Tabelle und nutzt sie als Schlüssel für die jeweilige Liste. Das funktioniert nur, wenn Client
und Server im selben Subnetz hängen (`allowed_subnet` in `config.php`) — über Router oder VPN
gäbe es sonst nur eine MAC für viele Nutzer. Die MAC ist damit keine Authentifizierung (spoofbar),
sondern nur eine einfache, loginfreie Zuordnung — so vom Nutzer gewünscht.

## Stack

Flaches PHP + Vanilla-JS, kein Framework, kein Build-Schritt.

```
public/            DocumentRoot
  index.php         Seiten-Shell, CSP-Header
  api.php           JSON-API (Prepared Statements)
  assets/           CSS, JS, Favicon
database/
  schema.sql        Tabelle `tasks`
config.php          DB-Zugang + Netzwerk-Einstellungen (nicht im Repo, siehe unten)
```

## Einrichtung

1. `database/schema.sql` in eine MariaDB/MySQL-Datenbank einspielen.
2. `config.example.php` nach `config.php` kopieren und anpassen:
   - `db_dsn`, `db_user`, `db_password`
   - `allowed_subnet` — das Subnetz, in dem die Arbeitsplätze stehen
   - `blocked_macs` — MACs von Netzwerk-Gateways (Router, VPN-Endpunkte), die keine echten
     Arbeitsplätze sind
3. `config.php` außerhalb des Webroots halten, `public/` als DocumentRoot setzen.
4. Der Server braucht Zugriff auf die ARP-Tabelle (`ip neigh show`), um Client-MACs aufzulösen.

## Sicherheit

Keine externen Ressourcen (keine CDNs, keine Google Fonts) — bewusst für den Einsatz im
Firmennetz. Strikte CSP (`default-src 'none'`), alle Datenbankzugriffe per Prepared Statements,
schreibende API-Zugriffe nur per `POST` mit eigenem Header (erzwingt CORS-Preflight).
