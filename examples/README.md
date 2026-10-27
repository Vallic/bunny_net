# Examples

- `edge-rules/`: Edge Rules to copy into a site's rules directory: Shield
  exemptions for payment webhooks, the cron URL and API clients, and a rule
  blocking probes for WordPress and leaked files. See its README.
- `error-page.html`: the 502/504 page vallic.com uses, with Bunny CDN's
  placeholders, to start a site's own `../bunny/error-page.html` from.
