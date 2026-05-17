import app from 'flarum/forum/app';

/**
 * Attaches a one-time `paste` listener to the composer's textarea
 * that intercepts GitHub repo URLs and replaces them with the
 * rendered README markdown from the backend proxy.
 *
 * Lifecycle:
 *   1. User pastes (or types and triggers a paste, e.g., autofill).
 *   2. If the pasted text trimmed is a GitHub repo root URL, we
 *      e.preventDefault() and insert a user-visible loading marker
 *      at the cursor via the editor driver (keeps Mithril's value
 *      stream in sync).
 *   3. POST /api/gh-readme/fetch — backend validates, fetches the
 *      README via the GitHub REST API, rewrites relative URLs, and
 *      returns processed markdown.
 *   4. On success: replace the loading marker with the markdown.
 *      On failure: replace the loading marker with the original URL
 *      so the user's paste isn't silently lost, and surface the
 *      backend's error message via app.alerts.
 *
 * Idempotency: the listener gets attached at most once per textarea
 * via a __ghReadmeHooked sentinel. Flarum's composer reuses the same
 * editor DOM across redraws — re-hooking would cause double fetches.
 */
export default function attachPasteHandler(editor) {
  const el = editor.el;
  if (!el || el.__ghReadmeHooked) return;
  el.__ghReadmeHooked = true;

  el.addEventListener('paste', (e) => {
    const pasted = (e.clipboardData && e.clipboardData.getData('text')) || '';
    const trimmed = pasted.trim();

    if (!isGithubRepoRootUrl(trimmed)) return;

    e.preventDefault();
    handleGithubPaste(editor, el, trimmed);
  });
}

/**
 * Strict allowlist — only accepts ROOT repo URLs (https://github.com/owner/repo),
 * optionally with trailing slash. NOT /blob/<branch>/<file> (those are file
 * links, not repos), NOT /tree/<branch> (we always fetch HEAD's README).
 */
function isGithubRepoRootUrl(s) {
  return /^https:\/\/(?:www\.)?github\.com\/[A-Za-z0-9._-]{1,100}\/[A-Za-z0-9._-]{1,100}\/?$/i.test(s);
}

async function handleGithubPaste(editor, el, url) {
  const marker = makeLoadingMarker();

  editor.insertAtCursor(marker);

  try {
    const response = await app.request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/gh-readme/fetch',
      body: { url },
    });

    const markdown = response && response.data && response.data.attributes && response.data.attributes.markdown;
    if (typeof markdown !== 'string' || markdown.length === 0) {
      throw new Error('Empty README returned.');
    }

    replaceInTextarea(el, marker, '\n\n' + markdown.trim() + '\n\n');
  } catch (err) {
    const errMsg = pluckErrorMessage(err) || 'Could not fetch the README.';
    /* Restore the original URL so the user can correct or re-paste. */
    replaceInTextarea(el, marker, url);
    app.alerts.show({ type: 'error' }, errMsg);
  }
}

/**
 * Italic markdown line with a random ID so multiple concurrent pastes
 * don't collide on replacement. Renders as visible "Loading README…
 * (id)" in the post body until replaced.
 */
function makeLoadingMarker() {
  const id = Math.random().toString(36).slice(2, 10);
  return '\n\n*Loading README from GitHub… (' + id + ')*\n\n';
}

/**
 * Replace the first occurrence of `find` in the textarea with
 * `replacement`, then dispatch a synthetic `input` event so Flarum's
 * composer state stream picks up the change.
 */
function replaceInTextarea(el, find, replacement) {
  const value = el.value;
  const idx = value.indexOf(find);
  if (idx < 0) {
    /* Marker got nuked (user undid, or rewrote that region); nothing
     * to replace — silently drop the result rather than appending it
     * to the wrong spot. */
    return;
  }
  const newValue = value.slice(0, idx) + replacement + value.slice(idx + find.length);
  el.value = newValue;
  /* Move caret to end of the inserted content for natural typing flow. */
  const cursor = idx + replacement.length;
  el.setSelectionRange(cursor, cursor);
  el.dispatchEvent(new Event('input', { bubbles: true }));
}

function pluckErrorMessage(err) {
  try {
    if (err && err.response && err.response.errors && err.response.errors[0]) {
      return err.response.errors[0].detail || err.response.errors[0].title;
    }
  } catch (_) { /* fall through */ }
  return err && err.message ? err.message : null;
}
