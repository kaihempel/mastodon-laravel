# Mastodon für Laravel

[![Tests](https://github.com/kaihempel/mastodon-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/kaihempel/mastodon-laravel/actions/workflows/tests.yml)

Laravel-Client für die Mastodon-REST-API mit Schwerpunkt auf dem Veröffentlichen von Text, Bildern, Videos, Umfragen und geplanten Beiträgen auf beliebigen Instanzen.

- Instanz-Discovery (Limits, Version, OAuth-Metadaten), Limits werden immer zur Laufzeit gelesen
- App-Registrierung pro Instanz, OAuth mit PKCE (S256), verschlüsselte Token
- Medien-Upload mit asynchroner Verarbeitung, Status erstellen, bearbeiten, löschen
- Geplante Beiträge (`scheduled_at`, Auflisten, Verschieben, Abbrechen)
- Validierung gegen die Limits der jeweiligen Instanz, bevor ein Request rausgeht
- Fehler-, Rate-Limit- und Retry-Behandlung, Idempotenz, Events, Jobs, Test-Fake

**Voraussetzungen:** PHP 8.3+, Laravel 12 oder 13.

## Installation

```bash
composer require kaihempel/mastodon-laravel
php artisan vendor:publish --tag=mastodon-config      # optional
php artisan migrate
```

Die Migrationen werden automatisch geladen; mit `--tag=mastodon-migrations` lassen sie sich ins Projekt kopieren.

`.env`:

```dotenv
MASTODON_REDIRECT_URI=https://example.com/mastodon/callback   # optional, sonst Route "mastodon.callback"
MASTODON_CLIENT_NAME="Meine App"
MASTODON_WEBSITE=https://example.com
```

## Konto verbinden

Mit den mitgelieferten Routen (abschaltbar über `mastodon.routes.enabled`, Middleware konfigurierbar):

| Methode | Pfad | Zweck |
| --- | --- | --- |
| `POST` | `/mastodon/connect` (Feld `instance`) | Weiterleitung zur Instanz |
| `GET` | `/mastodon/callback` | Token tauschen, Konto speichern, Redirect nach `routes.redirect_after` |
| `DELETE` | `/mastodon/accounts/{account}` | Token widerrufen, Konto löschen (nur Besitzer) |

Oder direkt über den `AuthorizationFlow`:

```php
use KaiHempel\Mastodon\Facades\Mastodon;

// 1. Redirect
$request = Mastodon::authorization()->start('mastodon.social');
return redirect()->away($request->url);

// 2. Callback
$account = Mastodon::authorization()->complete(
    $request->query('state'),
    $request->query('code'),
    owner: $project,                     // beliebiges Eloquent-Model (Morph)
    error: $request->query('error'),
);

// 3. Trennen
Mastodon::authorization()->disconnect($account);
```

Der Ablauf prüft die Instanz-Eingabe (nur `https`, nur Hostname, keine privaten Adressen), liest die OAuth-Metadaten (Server vor 4.3 erhalten die `fallback_scopes`), registriert die App nur bei Bedarf, legt `state` und PKCE-Verifier in der Session ab, vergleicht `state` in konstanter Zeit und prüft die gewährten Scopes.

## Veröffentlichen

```php
$result = Mastodon::for($account)
    ->status('Neue Version ist da #release')
    ->visibility('public')            // public, unlisted, private, direct
    ->language('de')
    ->sensitive(false)
    ->attach(storage_path('app/bild.jpg'), description: 'Screenshot der Startseite', focus: [0.0, 0.3])
    ->scheduleAt(now()->addHour())    // optional
    ->idempotencyKey($uuid)           // optional, sonst automatisch
    ->publish();                      // Status oder ScheduledStatus
```

- Dateien werden nur aus `media.allowed_paths` (Pfade) bzw. `media.allowed_disks` (`->attachFromDisk('public', 'bild.jpg')`) gelesen; der MIME-Typ wird aus dem Inhalt ermittelt.
- Umfragen: `->poll(['Ja', 'Nein'], expiresIn: 3600, multiple: false)`.
- Im Hintergrund: `->queue()` liefert den Idempotency-Key und startet `PublishStatusJob`.
- Jeder Versuch wird in `mastodon_posts` festgehalten. Ein erneuter `publish()` mit gleichem Key erzeugt keinen zweiten Beitrag, bereits hochgeladene Medien werden wiederverwendet.

Weitere Ressourcen:

```php
$client = Mastodon::for($account);

$client->statuses()->get($id);
$client->statuses()->edit($id, ['status' => 'Korrigiert']);
$client->statuses()->delete($id, deleteMedia: true);   // ->text enthält den Quelltext

$client->scheduled()->list();                          // LazyCollection, folgt Link-Headern
$client->scheduled()->reschedule($id, now()->addDay());
$client->scheduled()->cancel($id);

$client->media()->upload(MediaFile::fromPath($pfad), wait: true);
$client->media()->delete($id);                         // ab Mastodon 4.4.0

Mastodon::instance('mastodon.social')->info();         // Limits, Version (gecacht)
```

## Fehler und Rate Limits

Alle Exceptions erben von `MastodonException` (`httpStatus`, `serverMessage`, `errorDescription`):

| HTTP | Exception |
| --- | --- |
| 401 | `AuthenticationException` (Konto wird mit `disabled_at` markiert, Event `MastodonAccountDisconnected`) |
| 403 | `AuthorizationException` |
| 404 | `NotFoundException` |
| 410 | `GoneException` |
| 422 | `ValidationException` (auch lokal, mit `$errors`) |
| 429 | `RateLimitException` (`resetAt`, `retryAfterSeconds()`) |
| 5xx | `ServerException` |
| Netzwerk | `ConnectionException` |

Wiederholt wird nur bei 429, 5xx und Verbindungsfehlern mit exponentiellem Backoff (`http.retries`, `http.backoff_ms`); bei 429 wird bis `X-RateLimit-Reset` gewartet, sofern das höchstens `http.max_retry_wait_s` dauert. Der zuletzt gemeldete `X-RateLimit-*`-Stand wird je Account im Cache gehalten. Lokale Bremsen für Medien-Uploads und Löschungen (je 30 pro 30 Minuten) stehen unter `throttle`.

## Events, Jobs, Befehle

- Events: `MastodonAccountConnected`, `MastodonAccountDisconnected`, `MastodonStatusPublished`, `MastodonStatusScheduled`, `MastodonStatusFailed`, `MastodonRateLimited`
- Jobs: `PublishStatusJob`, `VerifyAccountJob`, `SyncScheduledStatusesJob`
- Artisan: `mastodon:register {instance}`, `mastodon:verify {account?}`, `mastodon:instance {instance}`, `mastodon:scheduled:sync {account?} {--queue}`

## Testen in der eigenen App

```php
$fake = Mastodon::fake();               // optional mit eigener InstanceInfo

// ... Code, der veröffentlicht ...

Mastodon::assertPublished(fn ($status) => str_contains($status->getStatus(), '#release'));
Mastodon::assertScheduled();
Mastodon::assertNothingPublished();
```

Der Fake validiert gegen die Instanzlimits und sendet keine HTTP-Requests.

## Entwicklung

```bash
composer install
vendor/bin/phpunit
```

Die GitHub Action `.github/workflows/tests.yml` führt die Tests mit PHP 8.3 und 8.4 gegen Laravel 12 und 13 aus (jeweils mit niedrigsten und aktuellen Abhängigkeiten).

## Umgesetzte Entscheidungen

Die offenen Punkte aus der Spezifikation (Abschnitt 16) sind so umgesetzt:

1. Paket `kaihempel/mastodon-laravel`, Namespace `KaiHempel\Mastodon`, PHP 8.3+, Laravel 12/13.
2. Routen und Controller werden mitgeliefert, sind aber abschaltbar; der `AuthorizationFlow` ist eigenständig nutzbar.
3. Planung standardmäßig serverseitig (`scheduled_at`); lokale Planung über einen eigenen verzögerten `PublishStatusJob`.
4. Medien nur aus erlaubten Disks und Pfaden, nicht aus URLs.
5. `/api/v2/media` wird lokal wie `/api/v1/media` gedrosselt.
6. Umfragen sind im MVP enthalten.

Statusparameter werden als JSON mit den dokumentierten Feldnamen gesendet (Mastodon akzeptiert JSON-Bodies); das vermeidet die mehrdeutige Kodierung von `media_ids[]` und `poll[options][]` in Formularen.
