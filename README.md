# Bunny CDN

Connects Drupal to [Bunny CDN](https://bunny.net):

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

It talks to Bunny CDN through
[valicm/bunny-toolkit](https://github.com/valicm/bunny-toolkit), which handles
Bunny's purge rate limits.

## Requirements

- Drupal 11.3 or later, or Drupal 12
- PHP 8.3 or later
- A Bunny CDN account API key and a pull zone
- [Cache Purifier](https://www.drupal.org/project/cache_purifier) 1.0 or
  later, which decides which tags are written and purged

The [Purge](https://www.drupal.org/project/purge) module is not needed.

## Installation

```
composer require drupal/bunny_net
drush en bunny_net
```

## Configuration

### What is managed where

| What | Set in | Written to the pull zone by | Found again by |
|---|---|---|---|
| Caching settings and rules | the settings page, kept as `caching` in config | the page's *Save and apply* button, `drush bunny:caching-sync` | descriptions starting `bunny_net: ` |
| Any other Edge Rules | YAML files in the rules directory | `drush bunny:rules-sync` | `[bunny_net:<file name>]` at the end of the description |
| Any pull zone setting | `pull_zone_settings` in config, shown on the settings page | `drush bunny:zone-sync` | its Bunny CDN API name |
| The 502/504 error page | an HTML file at `../bunny/error-page.html` | `drush bunny:zone-sync`, with the settings | its Bunny CDN API names |
| Edge Scripts (middleware) | files in the repository, listed under `edge_scripts` | `drush bunny:script-deploy` | the script ID |

`drush bunny:sync` runs the pull zone settings, caching and Edge Rules
syncs, in that order, after one confirmation. Each part runs only where it is
configured; Edge Scripts are deployed separately. All of it is in exported config or the
repository, so every environment can be brought to the same setup.

The three never change each other's rules, and none changes a rule made in the
Bunny CDN dashboard. A setting can be in `pull_zone_settings` or set by
caching, not both: the sync refuses it and the settings page says which.

### Where files go

What a site deploys to Bunny CDN lives in one folder beside the Drupal root,
in the repository but outside the web root:

```
bunny/
  error-page.html     the 502/504 page, sent with the pull zone settings
  middleware.ts       an Edge Script, named middleware in edge_scripts
  rules/              Edge Rules, one YAML file each
web/                  the Drupal root
```

Nothing there is required: a missing file or folder is simply not synced.
`edge_rules.directory`, an Edge Script's `file`, and
`$settings['bunny_net']['error_page']` in `settings.php` name other places.

### API key

The key opens your whole Bunny CDN account, so it lives in `settings.php`, not
in config:

```php
$settings['bunny_net']['api_key'] = getenv('BUNNY_API_KEY');
```

### Pull zone and purging

Set the pull zone ID under *Configuration → Development → Bunny CDN*
(`/admin/config/development/bunny`).

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
| `purge.concurrency` | `10` | Purge requests sent at once, 1 to 50. Bunny CDN takes one tag per request; each still waits for the rate limit. |
| `purge.on_cache_rebuild` | `false` | Empty the pull zone on every cache rebuild, such as `drush cr` at the end of a deploy. |
| `tags.header` | `true` | Add the `CDN-Tag` header. |
| `tags.prefix` | `''` | Prepended to every tag. Needed when several sites share a pull zone; give each site its own prefix. |
| `edge_scripts` | `{}` | See below. |
| `edge_rules.directory` | `''` | Empty reads the rules from `../bunny/rules`. See below. |

Drupal only lets the CDN cache pages that it marks cacheable. Set *Browser and
proxy cache maximum age* under *Configuration → Development → Performance*.

### How cache tags are written

Only public responses get a `CDN-Tag` header; Bunny CDN caches no others.

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

The settings page shows how many purges are waiting. A purge that Bunny CDN
rejects, for example because of a wrong API key, is logged to the `bunny_net`
channel and not retried.

Without more, each PHP process counts the limits for itself, so several web
heads and queue runners together can send more than Bunny CDN allows and get
429s back, which only delays purges. With the
[Redis](https://www.drupal.org/project/redis) module connected (PhpRedis or
Predis), the counts are kept in Redis or Valkey, under `bunny_net:purge:`, and
shared by every process. If the server stops answering, a warning is logged and
the process counts for itself again.

Other code on the site that purges the same Bunny CDN account can draw on the
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
| `bunny:zone-recommend` | `bunny-zr` | Print the settings a Drupal site wants, with the reasons. `--save` adds them to `pull_zone_settings`. |
| `bunny:caching-sync` | `bunny-cs` | Write the caching settings and their Edge Rules to the pull zone. `--dry-run`. |
| `bunny:sync` | `bunny-sync` | Pull zone settings, caching and Edge Rules in one go, after one confirmation; `-y` for deploy scripts. `--dry-run`, `--prune`. |
| `bunny:script-deploy [names]` | `bunny-sd` | Deploy Edge Scripts that changed. `--force`, `--no-publish`. |
| `bunny:rules-sync` | `bunny-rs` | Sync Edge Rules with the rules directory. `--dry-run`, `--prune`. |
| `bunny:rules-export [dir]` | `bunny-re` | Write the pull zone's rules as YAML files. `--overwrite`. |

## Pull zone settings

Settings from the Bunny CDN dashboard can be kept in the exported
`bunny_net.settings`, under their Bunny CDN API names, so every environment's
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

- The names are those of Bunny CDN's update pull zone API, as in the toolkit's
  `PullZoneSettingsModel`. A misspelt name or a value of the wrong type fails
  the sync and the settings page shows why.
- Enum settings, such as `LogFormat`, take the case names of the toolkit's
  enums in `Valicm\BunnyToolkit\Api\Core\Enum`, or a number.
- `AWSSigningKey`, `AWSSigningSecret` and `LogForwardingToken` hold
  credentials, so they are refused; set them in the dashboard.
- Bunny CDN does not return a few settings, such as
  `CacheControlBrowserMaxAgeOverride`, so those are sent on every sync.

To start from what the pull zone has today, run
`drush bunny:zone-export EnableCookieVary,CookieVaryParameters --save` with the
settings you want to manage, or `drush bunny:zone-export all` to see them all,
then export the config.

### Recommended for Drupal

A new install has these in `pull_zone_settings`. Nothing is sent until
`drush bunny:zone-sync` or `drush bunny:sync`, which show each change first:
review them in the exported config, and remove any your pull zone needs
otherwise. On a site installed before, `drush bunny:zone-recommend` prints
them with their reasons, and `--save` adds them, keeping any already set:

| Setting | Value | Why |
|---|---|---|
| `AddHostHeader` | `true` | Drupal builds links and checks `trusted_host_patterns` from the Host header. |
| `FollowRedirects` | `false` | Followed at the edge, Drupal's redirects serve the page under the wrong URL. |
| `DisableCookies` | `false` | Strips `Set-Cookie`, so nobody can log in through the CDN. New pull zones have it on. |
| `CacheErrorResponses` | `false` | With stale caching, one cached 503 can outlast the outage. |
| `IgnoreQueryStrings` | `false` | Pagers and search results would all get the first cached page. |
| `UseStaleWhileUpdating`, `UseStaleWhileOffline` | `true` | The cached page instead of a wait or an error. |

`VerifyOriginSSL` is worth turning on once the origin has a certificate for
the public hostname: with `AddHostHeader`, Bunny CDN asks the origin for the
visitor's domain, and fails if the origin only has a certificate for its own.

Limits for the whole zone, such as `MonthlyBandwidthLimit`,
`ConnectionLimitPerIPCount` or `RequestLimit`, are pull zone settings too.
Limits for single paths, such as the login form, belong to Shield's rate
limiting, set in the Bunny CDN dashboard.

### Error page

Bunny CDN shows its own page when it can't reach the site, a 502 or 504. To
show the site's own, put it at `../bunny/error-page.html`, relative to the
Drupal root, beside the Edge Rules. `drush bunny:zone-sync` sends it with the
other settings, as Bunny CDN's custom error page without Bunny CDN's name.
Without the file, nothing is sent for it, and the page set in the Bunny CDN
dashboard stays. For another place, name it in `settings.php`:

```php
$settings['bunny_net']['error_page'] = '../cdn/502.html';
```

It can't also be set in `pull_zone_settings`. Removing the file doesn't take
the page off the pull zone: switch it off in the dashboard.

The page is shown while Drupal is down, so nothing on it can come from the
site: inline the CSS and a logo as SVG, or load them from elsewhere.
Bunny CDN fills in `{{status_code}}`, `{{status_title}}`,
`{{status_description}}`, `{{target_hostname}}` and `{{user_ip}}`.
`examples/error-page.html` is the page vallic.com uses, to start from.

## Caching

The **Caching** section of the settings page sets what Bunny CDN caches, for
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
- **Paths never cached**, such as `/cart/*`, at Bunny CDN or in browsers.
- **Other session cookies**, for a session Drupal doesn't set.
- **Browser cache times** for pages and for static files: how long
  visitors' browsers keep them, in place of the times Drupal sends, never on
  a private response, and never for a page a visitor with a session gets.
  Bunny CDN itself keeps them as long as Drupal's `Cache-Control` says, until
  a purge.
- **Smart Cache**, which lets Bunny CDN decide what is cacheable.

The rules are named `bunny_net: …` and take the order indexes 20 to 45; a
sync leaves other rules alone, including those from the Edge Rules directory.
Settings set here can't also be in `pull_zone_settings`: the settings page
and the sync say which.

The rules come from the toolkit's `Caching\ZoneCaching`, which other code
can use to write the same rules to any pull zone.

### With middleware

Middleware and these rules can run on the same pull zone, but Bunny CDN
doesn't document their order within a stage, so give each concern one
owner. If a middleware script bypasses the cache for sessions or paths
itself, leave those choices empty here.

## Edge Scripts

List scripts in the exported `bunny_net.settings`, each with the ID of its
script at Bunny CDN. Its code is read from `../bunny/<name>.ts`, or from the
`file` given, relative to the Drupal root like the config sync directory. The
settings page shows them, with the file each is read from, whether it exists,
and which variables and secrets are set; they are edited in config.

```yaml
edge_scripts:
  middleware:
    script_id: 1234
    # file: ../bunny/middleware.ts, the default for "middleware"
    variables:
      ORIGIN_HOST: www.example.com
```

Config is needed here, unlike for the error page: a file can't say which
script at Bunny CDN it is. The ID often differs per environment; set it in
`settings.php` then, on top of the exported entry:

```php
$config['bunny_net.settings']['edge_scripts']['middleware']['script_id'] = (int) getenv('BUNNY_MIDDLEWARE_ID');
```

An override like that only adds to an entry config already has. On a site
whose config has no `middleware` entry yet, before a config import, it makes
one with the ID alone, which is read from the default file.

Secrets go in `settings.php`:

```php
$settings['bunny_net']['edge_script_secrets']['middleware']['SIGNING_KEY'] = getenv('BUNNY_SIGNING_KEY');
```

`drush bunny:script-deploy` sets the variables and secrets, uploads the code
and publishes a release. A script whose deployed code already matches the file
is skipped; use `--force` after changing only variables or secrets. Deploys
never run on their own, so add the command to your deploy steps.

## Edge Rules

Keep the rules in `../bunny/rules`, one YAML file per rule, or name another
directory as `edge_rules.directory` in the exported `bunny_net.settings`.
Without the directory, nothing is synced. The settings page shows the
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
| `description` | `''` | Shown in the Bunny CDN dashboard. |
| `order` | none | The rule's place among the zone's rules, Bunny CDN's `OrderIndex`. Left out, Bunny CDN places it and syncs leave it there. |
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
the valid ones. A plain number is accepted for values Bunny CDN added after the
toolkit was generated.

`drush bunny:rules-sync` shows what it will create, update or delete, then asks
before making the changes.

- A rule synced from a file gets `[bunny_net:<file name>]` at the end of its
  description. That is how later syncs find it, in every environment, without
  storing its ID.
- Rules made in the Bunny CDN dashboard are never changed or deleted. If a file
  describes one exactly, the sync adopts that rule instead of adding a copy.
- A managed rule whose file was removed is deleted only with `--prune`.

To start from what is in the dashboard today, run
`drush bunny:rules-export`, which writes to the rules directory, review the
files, then sync.

### Order

Bunny CDN runs rules from the lowest `order` up. For a cache time, a browser
time and Shield's switches only the first matching rule counts; header
actions all apply. Give `order` to a rule that has to come before or after
others. Bunny CDN refuses two rules at one place.

With caching managed, its rules take 20 to 45: a file with an `order` in that
range fails, and a caching sync stops while another rule holds one of those
places. Rules that should win over caching go below 20, rules that should
come after it above 45.

### Example rules

`examples/edge-rules` holds rules to copy:

- **Shield exemptions.** With Shield on the pull zone, servers calling
  Drupal can't solve a challenge or pass bot detection. The examples take
  payment webhooks (`/payment/notify/*`), the cron URL and API clients
  (JSON:API, REST, Simple OAuth's token endpoint) out of both, and nothing
  else: Shield's WAF still inspects them.
- **Probes.** Blocks scanners looking for WordPress, `.env` and `.git` at the
  edge.

Shield itself, its WAF and bot detection, is set up in the Bunny CDN
dashboard.

## Maintainers

- Valentino Međimorec - [valic](https://www.drupal.org/u/valic)
