# GitHub README Paste — Flarum 2 extension

Paste a GitHub repo URL into the Flarum composer and the URL is **auto-replaced** with the rendered README markdown.

## How it works

1. User pastes `https://github.com/owner/repo` into any composer (new discussion, reply, edit, private message).
2. The frontend recognizes it as a GitHub repo root URL and intercepts the paste.
3. It calls `POST /api/gh-readme/fetch` on the Flarum backend.
4. The backend fetches `https://api.github.com/repos/{owner}/{repo}/readme`, decodes the base64 payload, rewrites relative image / link / anchor URLs against the repo's HEAD branch on `raw.githubusercontent.com`, and returns the processed markdown.
5. The frontend replaces the URL in the composer with the README markdown. The user can edit freely from there.

Subsequent pastes of the same repo are served from a 10-minute server-side cache so a popular forum doesn't burn GitHub's rate limit.

## Install

```bash
composer require ernestdefoe/gh-readme
php flarum cache:clear
```

Then enable in **Admin → Extensions → GitHub README Paste**.

[`flarum/markdown`](https://github.com/flarum/markdown) is a `suggest` dep — without it the inserted markdown ends up in the post but renders as plain text rather than formatted headings/lists/code blocks.

## Configure

**Admin → Extensions → GitHub README Paste:**

- **GitHub Personal Access Token** *(optional)* — raises GitHub's API rate limit from 60/hour-per-IP (unauth) to 5000/hour. No scopes required for public repos.
- **Cache duration (minutes)** — how long the server caches each README before refetching. Default 10. Clamped 1–60.

## URL shapes accepted

| URL | Behavior |
| --- | --- |
| `https://github.com/owner/repo` | ✅ expanded |
| `https://github.com/owner/repo/` | ✅ expanded |
| `https://github.com/owner/repo.git` | ✅ expanded (strips `.git`) |
| `https://www.github.com/owner/repo` | ✅ expanded |
| `https://github.com/owner/repo/tree/main` | ❌ left as-is (use the root URL) |
| `https://github.com/owner/repo/blob/main/file.md` | ❌ left as-is (file links, not repos) |
| `http://github.com/owner/repo` | ❌ rejected (must be https) |
| `https://gitlab.com/owner/repo` | ❌ not GitHub |

## Security notes

- Strict URL allowlist (`github.com` host only).
- Owner/repo regex-allowlisted to GitHub's own charset.
- The backend never fetches the user-supplied URL directly — it constructs the API call from validated owner/repo segments. SSRF surface is the GitHub API host only.
- Response body capped at 2 MB.
- 10s connect + 15s total request timeout.
- TLS verification on.
- Endpoint requires an authenticated Flarum actor; throttled by Flarum's standard per-actor API throttler.

## License

MIT — see [LICENSE](LICENSE).
