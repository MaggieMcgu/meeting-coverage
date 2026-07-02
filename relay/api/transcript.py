"""
Meeting Coverage Relay — YouTube Transcript Service
GET /transcript?v={video_id}&key={api_key}
Returns: {"transcript": "...", "words": N}

Requires YOUTUBE_COOKIES env var (Netscape cookie file content) for deployments
on datacenter IPs (Vercel, Bluehost, etc). YouTube accepts authenticated requests
from any IP; cookies from a Google account bypass bot-detection.
"""
import os
import re
import tempfile
import glob

from flask import Flask, request, jsonify

app = Flask(__name__)

RELAY_API_KEY = os.environ.get("RELAY_API_KEY", "")
YOUTUBE_COOKIES = os.environ.get("YOUTUBE_COOKIES", "")
YOUTUBE_COOKIES_PATH = os.environ.get("YOUTUBE_COOKIES_PATH", "")


def parse_vtt(vtt_content: str) -> str:
    """Strip VTT headers/timecodes, deduplicate adjacent lines, return plain text."""
    seen_prev = None
    texts = []
    for line in vtt_content.splitlines():
        line = line.strip()
        if not line:
            continue
        if line.startswith("WEBVTT") or line.startswith("NOTE") or line.startswith("STYLE"):
            continue
        if re.match(r"^\d{2}:\d{2}", line) or re.match(r"^align:", line):
            continue
        text = re.sub(r"<[^>]+>", "", line).strip()
        if text and text != seen_prev:
            texts.append(text)
            seen_prev = text
    return " ".join(texts)


@app.route("/transcript")
@app.route("/api/transcript")
def transcript():
    if RELAY_API_KEY:
        if request.args.get("key", "") != RELAY_API_KEY:
            return jsonify({"error": "unauthorized"}), 401

    video_id = request.args.get("v", "").strip()
    if not video_id:
        return jsonify({"error": "missing_video_id", "detail": "Pass ?v=VIDEO_ID"}), 400

    try:
        import yt_dlp

        with tempfile.TemporaryDirectory() as tmpdir:
            ydl_opts = {
                "writeautomaticsub": True,
                "writesubtitles": True,
                "subtitleslangs": ["en"],
                "subtitlesformat": "vtt",
                "skip_download": True,
                "quiet": True,
                "no_warnings": True,
                "outtmpl": os.path.join(tmpdir, "%(id)s"),
            }

            # Cookie injection: inline content takes priority over file path
            if YOUTUBE_COOKIES:
                cookie_file = os.path.join(tmpdir, "cookies.txt")
                with open(cookie_file, "w") as f:
                    f.write(YOUTUBE_COOKIES)
                ydl_opts["cookiefile"] = cookie_file
            elif YOUTUBE_COOKIES_PATH and os.path.exists(YOUTUBE_COOKIES_PATH):
                ydl_opts["cookiefile"] = YOUTUBE_COOKIES_PATH

            url = f"https://www.youtube.com/watch?v={video_id}"

            with yt_dlp.YoutubeDL(ydl_opts) as ydl:
                ydl.download([url])

            # Locate the VTT output
            vtt_files = glob.glob(os.path.join(tmpdir, "*.vtt"))
            if not vtt_files:
                return jsonify({"error": "no_captions", "detail": "No captions track found"}), 404

            with open(vtt_files[0]) as f:
                text = parse_vtt(f.read())

            if not text:
                return jsonify({"error": "no_captions", "detail": "Empty caption track"}), 404

            return jsonify({"transcript": text, "words": len(text.split())})

    except Exception as e:
        err = str(e)
        if "Sign in to confirm" in err or "bot" in err.lower():
            return jsonify({
                "error": "bot_blocked",
                "detail": "Set YOUTUBE_COOKIES env var with Netscape-format cookie file content.",
            }), 403
        if "Video unavailable" in err or "not available" in err.lower():
            return jsonify({"error": "video_not_found", "detail": err[:200]}), 404
        if "No video formats found" in err or "no captions" in err.lower():
            return jsonify({"error": "no_captions", "detail": err[:200]}), 404
        return jsonify({"error": "fetch_failed", "detail": err[:200]}), 500
