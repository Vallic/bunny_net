# bunny.net

Connects Drupal to the [bunny.net](https://bunny.net) CDN:

- **Cache tags.** Responses get a `CDN-Tag` header. When Drupal invalidates a
  cache tag, for example on a node save, the pages carrying it are purged.
- **Purging** everything in the pull zone, or individual URLs, from Drush or
  the admin UI.
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

Set the pull zone ID under *Configuration → Web services → bunny.net*
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
| `purge.enabled` | `false` | Purge when Drupal invalidates cache tags. |
| `purge.mode` | `terminate` | `terminate` purges after the response is sent and queues what the rate limits defer; `queue` leaves everything to cron. |
| `purge.on_cache_rebuild` | `false` | Empty the pull zone on every cache rebuild, such as `drush cr` at the end of a deploy. |
| `tags.header` | `true` | Add the `CDN-Tag` header. |
| `tags.prefix` | `''` | Prepended to every tag. Needed when several sites share a pull zone; give each site its own prefix. |
| `tags.blocklist` | `http_response`, `rendered`, `library_info` | Tags never sent or purged; `*` matches any characters. |
| `edge_scripts` | `{}` | See below. |
| `edge_rules.directory` | `''` | See below. |

Drupal only lets the CDN cache pages that it marks cacheable. Set *Browser and
proxy cache maximum age* under *Configuration → Development → Performance*.

### How cache tags are written

Bunny caps `CDN-Tag` at 1024 bytes. Each tag is therefore hashed to a
12-character token, and a purge matches the token with a wildcard. About 78
tags fit in one header. If a page has more, the rest are left out, and those
pages refresh only when their cache lifetime ends. A hash collision only causes
an extra purge.

`rendered` is blocklisted because Drupal invalidates it on every theme or
config change, and purging it would empty the whole pull zone each time.

### Rate limits and the queue

Bunny limits purges per account. What a request can't purge straight away goes
to the `bunny_net_purge` queue, which cron works through. A purge that bunny.net
rejects, for example because of a wrong API key, is logged to the `bunny_net`
channel and not retried.

## Drush commands

| Command | Alias | What it does |
|---|---|---|
| `bunny:purge-tags <tags>` | `bunny-pt` | Purge pages carrying the given cache tags (comma-separated). |
| `bunny:purge-url <urls>` | `bunny-pu` | Purge URLs; a `*` purges everything under it. |
| `bunny:purge-all` | `bunny-pa` | Empty the whole pull zone. |
| `bunny:script-deploy [names]` | `bunny-sd` | Deploy Edge Scripts that changed. `--force`, `--no-publish`. |
| `bunny:rules-sync` | `bunny-rs` | Sync Edge Rules with the rules directory. `--dry-run`, `--prune`. |
| `bunny:rules-export [dir]` | `bunny-re` | Write the pull zone's rules as YAML files. `--overwrite`. |

## Edge Scripts

List scripts in `bunny_net.settings` (there is no form for this; edit the
exported config file). Paths are relative to the Drupal root, like the config
sync directory:

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

Point `edge_rules.directory` at a directory of YAML files, one rule per file:

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
