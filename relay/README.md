# Meeting Coverage Transcript Relay

A thin Python serverless function that fetches YouTube transcripts from a clean IP. Used by the [Meeting Coverage WP Plugin](../wp-plugin/meeting-coverage/).

**Why this exists:** YouTube's InnerTube API blocks shared hosting IPs (like Bluehost). This relay runs on Vercel's edge network with a clean IP.

## Deploy your own (free)

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https://github.com/maggiemcgu/meeting-coverage)

Or manually:
```bash
npm i -g vercel
cd relay/
vercel deploy
```

Set the `RELAY_API_KEY` environment variable in Vercel to a secret string, then enter the same key in the WP plugin's settings.

## Endpoint

```
GET /transcript?v={video_id}&key={api_key}
```

**Success (200):**
```json
{"transcript": "I pledge allegiance...", "words": 32298}
```

**Errors:**
| Code | `error` field | Meaning |
|------|---------------|---------|
| 400 | `missing_video_id` | `?v=` not provided |
| 401 | `unauthorized` | Wrong API key |
| 403 | `bot_blocked` | YouTube blocked this relay IP (rare on Vercel) |
| 404 | `no_captions` | Video has no captions |
| 404 | `video_not_found` | Video ID doesn't exist |
| 500 | `fetch_failed` | Unexpected error (detail in response) |

## Test

After deploying, verify with the Estes Park test video:

```bash
curl "https://YOUR-RELAY.vercel.app/transcript?v=fQVAfCbln0k&key=YOUR_KEY"
```

Should return ~32,298 words starting with "I pledge allegiance".

## Local dev

```bash
pip install youtube-transcript-api==1.2.4
vercel dev
```

## Notes

- No key configured = open relay (fine for personal use, set a key for public deployments)
- Vercel free tier: 100K requests/day, zero cost at local news scale
- Transcripts may include `[Music]`, `[Applause]` markers — Claude handles them gracefully
