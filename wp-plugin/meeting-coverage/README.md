# Meeting Coverage WP Plugin

Automates local government meeting coverage drafts. Watches YouTube channels, fetches public agendas, calls Claude AI, and creates WordPress draft posts with `[VERIFY: ...]` flags for human review before publishing.

**AI drafts, human verifies. Always.**

## Requirements

- WordPress 6.0+, PHP 7.4+
- An Anthropic API key (~$0.16/meeting, billed to your account)
- The [transcript relay](../relay/) deployed somewhere with a clean IP (Vercel free tier)
- Meetings must be posted to YouTube with auto-captions enabled
- Agendas must be publicly accessible (PDF or HTML, no login required)

## Installation

1. Upload the `meeting-coverage/` folder to `/wp-content/plugins/`
2. Activate in WP Admin → Plugins
3. Go to **Meeting Coverage → Settings** and enter your Anthropic API key and relay URL
4. Go to **Meeting Coverage → Add Body** to configure your first governing body

## Setup per governing body

Each "Meeting Body" needs three fields:

| Field | Example | Required |
|-------|---------|----------|
| Entity Name (post title) | Grand County Commission | ✅ |
| YouTube Channel URL | `https://www.youtube.com/@GrandCountyUT` | ✅ |
| Agenda URL | `https://www.grandcountyutah.net/agendas/latest.pdf` | ✅ |
| Member Roster | One name per line | Optional |
| Local Name Corrections | `garbled: correct` (one per line) | Optional |

After saving and publishing the body, click **▶ Run Now** to test. It fetches the latest video and creates a draft.

## How it works

1. **WP Cron** checks YouTube RSS feeds every 30 minutes for new videos
2. New video detected → fetch transcript via relay (clean IP, bypasses YouTube bot blocks)
3. Fetch agenda from the configured URL (PDF → text extraction, or HTML)
4. Build a prompt with entity name, date, agenda, transcript, roster, and corrections
5. Call Claude API (`claude-sonnet-4-6`) to generate the "Meeting at a Glance"
6. Create a WP draft post with VERIFY flags inline

## The draft format

```
## Quick Takes
- [Most newsworthy items]

## Agenda Items
**Item Name** — Passed (5–2). [Or: Individual votes unclear — [VERIFY: ▶ 1:23:45]]

## Key Quotes
"Quote text here." — Speaker Name [VERIFY: name uncertain]

## What's Next
- Next meeting: July 15
```

## VERIFY flags

Every `[VERIFY: reason]` flag marks something that needs human confirmation before publishing:
- Uncertain speaker name
- Unclear vote tally (includes timecode to jump to the moment)
- Dollar amounts or legal descriptions
- Any name not clearly in the agenda or roster

**Do not publish without resolving all VERIFY flags.**

## REST API

Authenticate with WP Application Passwords (WP Admin → Users → Application Passwords).

```bash
# List bodies
curl -u "admin:app-password" https://yoursite.com/wp-json/maag/v1/bodies

# Run pipeline for body ID 42
curl -X POST -u "admin:app-password" https://yoursite.com/wp-json/maag/v1/run/42

# Re-run even if already processed
curl -X POST -u "admin:app-password" https://yoursite.com/wp-json/maag/v1/run/42?force=1

# List recent drafts
curl -u "admin:app-password" https://yoursite.com/wp-json/maag/v1/drafts
```

## WP Cron reliability

WP Cron fires on site visits. Low-traffic sites may miss the 30-minute window. For reliable polling, add a real server cron:

```
*/30 * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## Known limitations (v1)

- **JS-rendered portals** (CivicClerk, CivicPlus, Granicus, Legistar): agenda fetch won't work if the URL requires JavaScript to render. Workaround: find a direct PDF link, or note this body as "agenda fetch fails — VERIFY all items."
- **pdftotext**: Used for PDF agenda extraction. If not available on your host, the plugin tries Ghostscript, then falls back to basic PHP extraction (works for simple PDFs, may fail on complex layouts).
- **Voice-vote bodies**: Per-member votes aren't recoverable from audio alone. The draft will say "vote unclear" with a timecode.
- **Videos older than 48 hours**: The cron job skips these (prevents backfill spam). Use "Run Now" to manually process older meetings.

## License

MIT — fork it, use it, adapt it. No support promised.
