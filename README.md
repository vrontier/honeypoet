# The Honeypo(e)t

*Every knock on the door gets a poem.*

---

There is a quiet violence to the internet. Thousands of times a day, machines knock on every door they can find — probing for unlocked WordPress installs, exposed `.env` files, forgotten admin panels, databases left ajar. It is relentless, mechanical, and invisible. Most people have no idea it's happening.

The Honeypo(e)t listens.

It sits in the open, looking like a server with something to hide. The scanners come — they always do — and instead of silence or a slammed door, they find verse. A haiku for the WordPress hunter. A confession for the credential thief. A meditation on doors and keys for the brute-forcer.

The bots won't read any of it. They never do. They check the status code and move on.

But *you* can read it. That's what the gallery is for.

## What it does

A PHP script catches every request that wanders in. It records who knocked, where they came from (coordinates included), and what they were looking for — IP, geolocation, path, method, headers — all into a quiet SQLite database. Then it categorizes the visit: was this a WordPress scan? A `.env` hunt? A path traversal? Something new?

The gallery at [honeypoet.art](https://honeypoet.art) makes it visible. A dark canvas fills with grey dots — one for each origin location — slowly drawing the outline of the world through attack data alone. A terminal feed scrolls the latest knocks in real time: masked IPs, cities, paths, categories.

For known patterns, a poem from the archive. For the strange and novel, an LLM writes one fresh.

Security research as art. Art as public education.

## Architecture

<p align="center">
  <img src="honeypoet-architecture.png" alt="Honeypo(e)t Architecture" width="680">
</p>

**Trap** — NGINX routes scanner traffic to a PHP handler. Every request is logged with IP, GeoIP lookup (MaxMind), headers, and categorized by attack type.

**Poet** — Templates for known attack patterns, LLM-generated poems for novel ones. Generation is async — bots don't wait.

**Gallery** — The public face at [honeypoet.art](https://honeypoet.art). A full-viewport world map where grey dots accumulate at each visitor's geolocation — no pre-drawn outline, the dots *are* the map. A terminal-style live feed scrolls the latest knocks: masked IP, origin city, path probed, attack category. The world reveals itself through its own curiosity.

## Status

| Layer | Status |
|-------|--------|
| Trap | Live — catching requests, GeoIP enrichment (incl. coordinates), SQLite recording |
| Poet | Live — template poems for known patterns, LLM-generated for novel attacks, async generation |
| Gallery | Live — world map with dot accumulation, live feed, poem cards, polling every 10–30s |

## Tech

- **NGINX** — TLS termination, routing, `/_api/` endpoints, static assets
- **PHP 8.3** — trap handler, API endpoints, gallery page (all in one `index.php`)
- **SQLite** — visit log with lat/lng, poems, stats (WAL mode)
- **MaxMind GeoLite2** — IP geolocation + coordinates
- **Go** — poet worker: polls for unpoemed visits, generates poems via any OpenAI-compatible LLM, writes back to SQLite
- **Vanilla JS** — gallery frontend, zero dependencies

## Domains

| Domain | Purpose |
|--------|---------|
| [honeypoet.art](https://honeypoet.art) | Primary — the gallery |
| honeypoet.com | Redirects to .art |
| honeypoet.org | Redirects to .art |

## Tone

Curious. Warm. Educational. Philosophical. Never hostile or mocking.

The bots are not enemies. They are weather — the background radiation of the internet. This project makes that weather visible, and finds the poetry in it.

## Try it yourself

Every request gets a response that *looks* like what the bot expects — but with poetry hiding inside. Here are some knocks you can try:

```bash
# .env probe — the bot expects secrets, finds verse in the values
curl -A 'python-requests/2.28.1' 'https://honeypoet.art/.env'

# WordPress login — the most visited door on the internet
curl -A 'WPScan v3.8.22' 'https://honeypoet.art/wp-login.php'

# Admin panel — looking for the keys to the kingdom
curl -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' 'https://honeypoet.art/admin/'

# Git config — trying to read the source
curl -A 'python-requests/2.28.1' 'https://honeypoet.art/.git/config'

# Path traversal — climbing the directory tree
curl -A 'curl/7.88.1' 'https://honeypoet.art/../../etc/passwd'

# SQL injection — the oldest trick
curl -A 'sqlmap/1.7' "https://honeypoet.art/index.php?id=1'%20OR%201=1--"

# API probe — looking for user data
curl -A 'PostmanRuntime/7.32.1' 'https://honeypoet.art/api/v1/users'

# Credential POST — knocking with a password
curl -A 'Mozilla/5.0 (X11; Linux x86_64)' -X POST \
  -d 'username=admin&password=admin123' \
  'https://honeypoet.art/wp-login.php'

# robots.txt — asking for the rules
curl -A 'Baiduspider/2.0' 'https://honeypoet.art/robots.txt'

# Random PHP file — the generic scan
curl -A 'Go-http-client/1.1' 'https://honeypoet.art/xmlrpc.php'
```

The poems for the gallery are generated asynchronously — the bot gets the trap response instantly, and a few seconds later, a poem appears on the world map at [honeypoet.art](https://honeypoet.art).

## Running your own

You'll need NGINX (or any reverse proxy), PHP 8.3+, SQLite, and a MaxMind GeoLite2 database. The trap layer works standalone — point your proxy at `trap/index.php` and it starts catching. For LLM-generated poems, point the Go worker at any OpenAI-compatible completions endpoint — copy `poet-worker/config.example.yaml` to `config.yaml` and adjust for your environment. See `DESIGN.md` in the repo for the full architecture.

---

*Built by [Mike](https://github.com/vrontier) and Loom (a Claude who weaves verse from noise).*
