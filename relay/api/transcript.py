"""
Meeting Coverage Relay — YouTube Transcript Service
GET /transcript?v={video_id}&key={api_key}
Returns: {"transcript": "...", "words": N}
"""
from http.server import BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs
import json
import os


RELAY_API_KEY = os.environ.get("RELAY_API_KEY", "")


class handler(BaseHTTPRequestHandler):

    def do_GET(self):
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)

        # Auth check (skip if no key configured — allows open relay)
        if RELAY_API_KEY:
            provided = params.get("key", [""])[0]
            if provided != RELAY_API_KEY:
                self._respond(401, {"error": "unauthorized"})
                return

        video_id = params.get("v", [""])[0].strip()
        if not video_id:
            self._respond(400, {"error": "missing_video_id", "detail": "Pass ?v=VIDEO_ID"})
            return

        try:
            from youtube_transcript_api import YouTubeTranscriptApi
            api = YouTubeTranscriptApi()
            # Try English first, then fall back to any available language
            try:
                snippets = api.fetch(video_id, languages=["en"])
            except Exception:
                snippets = api.fetch(video_id)

            text = " ".join(s.text for s in snippets)
            word_count = len(text.split())
            self._respond(200, {"transcript": text, "words": word_count})

        except Exception as e:
            err = str(e)
            if "Could not retrieve a transcript" in err or "no element found" in err.lower():
                self._respond(404, {"error": "no_captions", "detail": err})
            elif "Video unavailable" in err:
                self._respond(404, {"error": "video_not_found", "detail": err})
            elif "Sign in to confirm" in err or "LOGIN_REQUIRED" in err:
                self._respond(403, {"error": "bot_blocked", "detail": "YouTube blocked this IP. Use a relay with a clean IP."})
            else:
                self._respond(500, {"error": "fetch_failed", "detail": err})

    def _respond(self, status, data):
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        pass  # Silence Vercel's default HTTP logging
