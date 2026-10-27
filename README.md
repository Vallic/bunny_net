# bunny.net

Connects Drupal to the [bunny.net](https://bunny.net) CDN:

- **Cache tags.** Responses get a `CDN-Tag` header. When Drupal invalidates a
  cache tag, for example on a node save, the pages carrying it are purged.
- **Purging** everything in the pull zone, or individual URLs, from Drush or
  the admin UI.
- **Pull zone settings**, such as cookie vary and stale caching, kept in config
  and synced to the pull zone.
- **Edge Scripts** (pull zone middleware) deployed from files in your
  repository.
- **Edge Rules** kept as YAML files in your repository and synced to the pull
  zone.

It talks to bunny.net through
[valicm/bunny-toolkit](https://github.com/valicm/bunny-toolkit), which handles
Bunny's purge rate limits.

## Requirements

- Drupal 11.3 or later, or Drupal 12
- PHP 8.3 or later
- A bunny.net account API key and a pull zone
- [Cache Purifier](https://www.drupal.org/project/cache_purifier) 1.0 or
  later, which decides which tags are written and purged

The [Purge](https://www.drupal.org/project/purge) module is not needed.

## Installation

```
composer require drupal/bunny_net
drush en bunny_net
```

## Configuration

### API key

The key opens your whole bunny.net account, so it lives in `settings.php`, not
in config:

```php
$settings['bunny_net']['api_key'] = getenv('BUNNY_API_KEY');
```

### Pull zone and purging

Set the pull zone ID under *Configuration → Web services → Bunny CDN*
(`/admin/config/services/bunny-net`).

Purging is **off** until you turn it on. Turn it on only in the environment
that owns the pull zone, so a local or staging copy of production's config
cannot empty production's cache:

```php
// settings.php on production only.
$config['bunny_net.settings']['purge']['enabled'] = TRUE;
```

| Setting | Default | What it does |
|---|---|---|
| `pull_zone_id` | `0` | The pull zone to purge. |
| `pull_zone_settings` | `{}` | See below. |
| `purge.enabled` | `false` | Purge when Drupal invalidates cache tags. |
| `purge.mode` | `terminate` | `terminate` purges after the response is sent and queues what the rate limits defer; `queue` leaves everything to cron. |
| `purge.concurrency` | `10` | Purge requests sent at once, 1 to 50. bunny.net takes one tag per request; each still waits for the rate limit. |
| `purge.on_cache_rebuild` | `false` | Empty the pull zone on every cache rebuild, such as `drush cr` at the end of a deploy. |
| `tags.header` | `true` | Add the `CDN-Tag` header. |
| `tags.prefix` | `''` | Prepended to every tag. Needed when several sites share a pull zone; give each site its own prefix. |
| `edge_scripts` | `{}` | See below. |
| `edge_rules.directory` | `''` | See below. |

Drupal only lets the CDN cache pages that it marks cacheable. Set *Browser and
proxy cache maximum age* under *Configuration → Development → Performance*.

### How cache tags are written

Only public responses get a `CDN-Tag` header; bunny.net caches no others.

Which tags are written and purged is Cache Purifier's outbound policy: its
blocklist, aliases and buckets, and the order that puts list tags first. Its
rules decide who and what may purge; target them at
`bunny_net.cache_tags_invalidator`.

Bunny caps `CDN-Tag` at 1024 bytes. Each tag is therefore hashed to a
5-character token, and a purge matches the token with a wildcard. About 170
tags fit in one header. If a page has more, the last ones are left out, and
those pages refresh only when their cache lifetime ends. A hash collision only
causes an extra purge.

### Rate limits and the queue

Bunny limits purges per account. What a request can't purge straight away goes
to the `bunny_net_purge` queue, which cron works through for up to 30 seconds
per run. To work it through on its own, for example more often than cron runs
or from a worker:

```
drush queue:run bunny_net_purge --time-limit=60
```

The settings page shows how many purges are waiting. A purge that bunny.net
rejects, for example because of a wrong API key, is logged to the `bunny_net`
channel and not retried.

Without more, each PHP process counts the limits for itself, so several web
heads and queue runners together can send more than bunny.net allows and get
429s back, which only delays purges. With the
[Redis](https://www.drupal.org/project/redis) module connected (PhpRedis or
Predis), the counts are kept in Redis or Valkey, under `bunny_net:purge:`, and
shared by every process. If the server stops answering, a warning is logged and
the process counts for itself again.

Other code on the site that purges the same bunny.net account can draw on the
same counts through the `bunny_net.rate_limiter` service, or replace it with
its own `Valicm\BunnyToolkit\Purge\RateLimiter\RateLimiter`.

## Drush commands

| Command | Alias | What it does |
|---|---|---|
| `bunny:purge-tags <tags>` | `bunny-pt` | Purge pages carrying the given cache tags (comma-separated). |
| `bunny:purge-url <urls>` | `bunny-pu` | Purge URLs; a `*` purges everything under it. |
| `bunny:purge-all` | `bunny-pa` | Empty the whole pull zone. |
| `bunny:zone-sync` | `bunny-zs` | Sync the pull zone's settings with config. `--dry-run`. |
| `bunny:zone-export [keys]` | `bunny-ze` | Print the pull zone's settings as config YAML. `--save`. |
| `bunny:caching-sync` | `bunny-cs` | Write the caching settings and their Edge Rules to the pull zone. `--dry-run`. |
| `bunny:script-deploy [names]` | `bunny-sd` | Deploy Edge Scripts that changed. `--force`, `--no-publish`. |
| `bunny:rules-sync` | `bunny-rs` | Sync Edge Rules with the rules directory. `--dry-run`, `--prune`. |
| `bunny:rules-export [dir]` | `bunny-re` | Write the pull zone's rules as YAML files. `--overwrite`. |

## Pull zone settings

Settings from the bunny.net dashboard can be kept in the exported
`bunny_net.settings`, under their bunny.net API names, so every environment's
pull zone is set up the same way:

```yaml
pull_zone_settings:
  EnableCookieVary: true
  CookieVaryParameters: [my_role]
  IgnoreQueryStrings: false
  UseStaleWhileUpdating: true
  UseStaleWhileOffline: true
  OriginRetries: 1
  OriginRetry5XXResponses: true
  LogFormat: Json
```

`drush bunny:zone-sync` shows the settings that differ, then asks before sending
them. Settings left out of config are not touched, so the rest can still be
managed in the dashboard.

- The names are those of bunny.net's update pull zone API, as in the toolkit's
  `PullZoneSettingsModel`. A misspelt name or a value of the wrong type fails
  the sync and the settings page shows why.
- Enum settings, such as `LogFormat`, take the case names of the toolkit's
  enums in `Valicm\BunnyToolkit\Api\Core\Enum`, or a number.
- `AWSSigningKey`, `AWSSigningSecret` and `LogForwardingToken` hold
  credentials, so they are refused; set them in the dashboard.
- bunny.net does not return a few settings, such as
  `CacheControlBrowserMaxAgeOverride`, so those are sent on every sync.

To start from what the pull zone has today, run
`drush bunny:zone-export EnableCookieVary,CookieVaryParameters --save` with the
settings you want to manage, or `drush bunny:zone-export all` to see them all,
then export the config.

## Caching

The **Caching** section of the settings page sets what bunny.net caches, for
sites that don't handle it in their own Edge Rules or middleware. Turn on
*Manage the pull zone's caching from here*, choose, save, then either press
*Save and apply caching to the pull zone* or run `drush bunny:caching-sync`
(after a config import on deploy, for example). The choices are kept in the
exported `bunny_net.settings` under `caching`.

Whatever is chosen, two rules are always written:

- A response Drupal marks `private`, `no-cache` or `no-store` is never
  cached, and the browser is told so.
- A visitor with a Drupal session (`SESS…` or `SSESS…`) gets pages from
  Drupal, not from the cache, so an editor never sees the anonymous copy.
  Stylesheets, scripts, images and fonts are still cached for them.

The rest is chosen:

- **Separate copies** per WebP or AVIF support, mobile or desktop, country,
  region, hostname, or named cookies. Each one multiplies what is cached.
- **Request headers** to vary by, such as `Accept-Language`.
- **Countries to block**, answered with a 403.
- **Paths never cached**, such as `/cart/*`, at bunny.net or in browsers.
- **Other session cookies**, for a session Drupal doesn't set.
- **Browser times** for pages and for static files. They replace the times
  Drupal sends, never on a private response, and never for a page a visitor
  with a session gets.
- **Smart Cache**, which lets bunny.net decide what is cacheable.

The rules are named `bunny_net: …` and take the order indexes 20 to 45; a
sync leaves other rules alone, including those from the Edge Rules directory.
Settings set here can't also be in `pull_zone_settings`: the settings page
and the sync say which.

The rules come from the toolkit's `Caching\ZoneCaching`, which other code
can use to write the same rules to any pull zone.

### With middleware

Middleware and these rules can run on the same pull zone, but bunny.net
doesn't document their order within a stage, so give each concern one
owner. If a middleware script bypasses the cache for sessions or paths
itself, leave those choices empty here.

## Edge Scripts

List scripts in the exported `bunny_net.settings`. The settings page shows
them, with whether each file exists and which variables and secrets are set,
but they are edited in config, like the files they point to. Paths are
relative to the Drupal root, like the config sync directory:

```yaml
edge_scripts:
  middleware:
    script_id: 1234
    file: ../bunny/middleware.ts
    variables:
      ORIGIN_HOST: www.example.com
```

Secrets go in `settings.php`:

```php
$settings['bunny_net']['edge_script_secrets']['middleware']['SIGNING_KEY'] = getenv('BUNNY_SIGNING_KEY');
```

`drush bunny:script-deploy` sets the variables and secrets, uploads the code
and publishes a release. A script whose deployed code already matches the file
is skipped; use `--force` after changing only variables or secrets. Deploys
never run on their own, so add the command to your deploy steps.

## Edge Rules

Point `edge_rules.directory` in the exported `bunny_net.settings` at a
directory of YAML files, one rule per file. The settings page shows the
directory and how many rules it holds, or what is wrong with a file:

```yaml
# ../bunny/rules/block-probes.yml
description: Block WordPress probes
action: BlockRequest
triggers:
  - type: Url
    patterns: ['*wp-login.php', '*xmlrpc.php']
```

```yaml
# ../bunny/rules/no-index-staging.yml
description: Keep staging out of search engines
action: SetResponseHeader
parameters: [X-Robots-Tag, noindex]
match: MatchAll
triggers:
  - type: RequestHeader
    parameter: Host
    patterns: ['staging.*']
extra_actions:
  - action: OverrideCacheTime
    parameters: ['0']
```

| Key | Default | Values |
|---|---|---|
| `description` | `''` | Shown in the bunny.net dashboard. |
| `enabled` | `true` | |
| `action` | required | An `EdgeRuleActionType` case, such as `Redirect`, `BlockRequest`, `SetResponseHeader`, `OverrideCacheTime`. |
| `parameters` | `[]` | Up to three action parameters. |
| `match` | `MatchAny` | How triggers combine: `MatchAny`, `MatchAll`, `MatchNone`. |
| `triggers[].type` | required | A `TriggerTypes` case, such as `Url`, `RequestHeader`, `CountryCode`, `RemoteIp`. |
| `triggers[].match` | `MatchAny` | How patterns combine. |
| `triggers[].patterns` | `[]` | Patterns; `*` is a wildcard. |
| `triggers[].parameter` | `''` | E.g. the header name for `RequestHeader`. |
| `extra_actions[]` | `[]` | More actions, each with `action` and `parameters`. |

The allowed names are the cases of the toolkit's generated enums in
`Valicm\BunnyToolkit\Api\Core\Enum`. A misspelt name fails the sync and lists
the valid ones. A plain number is accepted for values bunny.net added after the
toolkit was generated.

`drush bunny:rules-sync` shows what it will create, update or delete, then asks
before making the changes.

- A rule synced from a file gets `[bunny_net:<file name>]` at the end of its
  description. That is how later syncs find it, in every environment, without
  storing its ID.
- Rules made in the bunny.net dashboard are never changed or deleted. If a file
  describes one exactly, the sync adopts that rule instead of adding a copy.
- A managed rule whose file was removed is deleted only with `--prune`.

To start from what is in the dashboard today, run
`drush bunny:rules-export ../bunny/rules`, review the files, then sync.

Rule order is not managed yet.

## Maintainers

- Valentino Međimorec - [valic](https://www.drupal.org/u/valic)
