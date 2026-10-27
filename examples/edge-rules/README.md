# Example Edge Rules

Copy the ones a site needs into its Edge Rules directory
(`edge_rules.directory` in `bunny_net.settings`), adjust the paths, and run
`drush bunny:rules-sync` or `drush bunny:sync`.

- `shield-payment-webhooks.yml`, `shield-cron.yml`, `shield-api-clients.yml`:
  with Shield on the pull zone, servers calling these URLs can't solve a
  challenge or pass bot detection. Each rule takes the URLs out of both and
  nothing else: Shield's WAF still inspects them. Keep the patterns as
  narrow as the site allows.
- `block-probes.yml`: answers scanners looking for WordPress, `.env` and
  `.git` with a 403 at the edge.

They take the order indexes 5 to 8, before the caching rules (20 to 45).
