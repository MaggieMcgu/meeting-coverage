# Meeting Coverage

Automated local government meeting coverage for WordPress newsrooms.

**AI drafts, human verifies. Always.**

Watches YouTube channels, fetches public agendas, calls Claude AI, and creates WordPress draft posts with `[VERIFY: ...]` flags — ready for a reporter to fact-check and publish.

## Components

| Directory | What it is |
|-----------|-----------|
| [`relay/`](relay/) | Python transcript relay (deploy to Vercel free tier) |
| [`wp-plugin/meeting-coverage/`](wp-plugin/meeting-coverage/) | WordPress plugin |

## Quick start

**1. Deploy the relay**
```bash
cd relay
npm i -g vercel  # if needed
vercel deploy
```
Set `RELAY_API_KEY` to a secret in your Vercel project settings.

**2. Install the plugin**

Upload `wp-plugin/meeting-coverage/` to `/wp-content/plugins/` and activate.

**3. Configure**

WP Admin → Meeting Coverage → Settings → enter your Anthropic API key and the relay URL from step 1.

**4. Add a meeting body**

WP Admin → Meeting Coverage → Add Body. Fill in:
- Entity name (post title)
- YouTube channel URL
- Public agenda URL

Hit **▶ Run Now** to test.

## Why a relay?

Shared WordPress hosting IPs (Bluehost, WP Engine, etc.) are flagged as bots by YouTube's transcript API. The relay runs on Vercel's edge network with clean IPs — 100K requests/day free.

## Cost

- Relay: $0 (Vercel free tier)
- AI: ~$0.16/meeting billed directly to your Anthropic account

## License

MIT. Fork it, use it, contribute your county's config. No support promised.

---

Built by [Maggie McGuire](https://maggie-mcguire.com) at [Moab Sun News](https://moabsunnews.com).
