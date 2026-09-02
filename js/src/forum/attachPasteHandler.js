import app from 'flarum/forum/app';

/**
 * Attach a one-time `paste` listener to the composer editor that
 * intercepts GitHub repo URLs and replaces them with the rendered
 * README markdown from the backend proxy.
 *
 * Supports both editor drivers:
 *
 *   - **BasicEditorDriver** (Flarum default, plain `<textarea>` +
 *     flarum/markdown rendering at display time). We insert a
 *     visible inline "Loading…" marker in the textarea, fetch, then
 *     swap the marker for the markdown source. The post renders as
 *     formatted markdown via flarum/markdown.
 *
 *   - **TiptapEditorDriver** (fof/rich-text — WYSIWYG composer
 *     backed by Tiptap/ProseMirror). The driver's
 *     `insertAtCursor(text, false)` overload PARSES the text as
 *     markdown and inserts it as rich nodes, so the user sees the
 *     formatted README appear in the composer immediately. We use a
 *     Flarum alert toast for the loading hint rather than an inline
 *     marker — finding and replacing a specific text node inside a
 *     ProseMirror document is awkward and brittle.
 *
 * Detection: `editor.editor` is the underlying Tiptap instance on
 * fof/rich-text's driver; absent on the basic driver. The check is
 * minification-stable (an object-property lookup) and survives the
 * `BasicEditorDriver` ↔ `TiptapEditorDriver` class-name mangling
 * that any production webpack build would do.
 *
 * The paste listener is attached in the capture phase so it runs
 * BEFORE Tiptap's own ProseMirror paste plugin — calling
 * `e.preventDefault()` then stops Tiptap from also inserting the URL
 * as plain text.
 *
 * Idempotency: a `__ghReadmeHooked` sentinel on the target element
 * prevents double-hooking when Mithril remounts the composer.
 */
export default function attachPasteHandler(editor) {
  /*
   * 🚨 Decided at PASTE time, not here.
   *
   * A rich driver may not have built its editor yet when onbuild runs — Scribe
   * loads Tiptap asynchronously, so `editor.editor` is undefined for a moment
   * after the composer appears. Deciding once, here, meant every Scribe
   * composer was permanently classified as a plain textarea.
   *
   * That failure was silent and total: the textarea path inserts a marker,
   * fetches, then looks for that marker in `editor.value`. On a rich editor
   * `value` is the document's PLAIN TEXT, so the blank lines around the marker
   * are gone by the time we search for them, indexOf misses, and the helper
   * returns without a word — leaving "*Loading README from GitHub…*" in the
   * post, which is then what gets published.
   */
  const isRichText = () => !!(editor && editor.editor && typeof editor.editor === 'object');

  /* Basic: editor.el is the <textarea>.
   * Tiptap: editor.el is the contenteditable view OR can be reached
   *         via the Tiptap instance's view.dom. We prefer the
   *         driver's own .el reference when present so any future
   *         wrapper element added by fof/rich-text still receives
   *         the listener. */
  const el = (editor && editor.el)
    || (isRichText() && editor.editor.view && editor.editor.view.dom)
    || null;
  if (!el || el.__ghReadmeHooked) return;
  el.__ghReadmeHooked = true;

  el.addEventListener(
    'paste',
    (e) => {
      const pasted = (e.clipboardData && e.clipboardData.getData('text')) || '';
      const trimmed = pasted.trim();

      if (!isGithubRepoRootUrl(trimmed)) return;

      e.preventDefault();
      e.stopPropagation();
      handleGithubPaste(editor, el, trimmed, isRichText());
    },
    /* capture: */ true
  );
}

/**
 * Strict allowlist — only accepts ROOT repo URLs.
 * Rejects: /tree/<branch>, /blob/<branch>/<file>, /pull/<n>, /issues, etc.
 */
function isGithubRepoRootUrl(s) {
  return /^https:\/\/(?:www\.)?github\.com\/[A-Za-z0-9._-]{1,100}\/[A-Za-z0-9._-]{1,100}\/?$/i.test(s);
}

async function handleGithubPaste(editor, el, url, isRichText) {
  /* Two divergent loading affordances: */
  let marker = null;
  let loadingAlertKey = null;

  if (isRichText) {
    /* Tiptap — show a toast. The Mithril alert manager returns a key
     * we can dismiss when the fetch resolves. */
    loadingAlertKey = app.alerts.show(
      { type: 'info', dismissible: false },
      'Loading README from GitHub…'
    );
  } else {
    /* Textarea — drop an inline italic marker the user can see at
     * the cursor position. Random ID so concurrent pastes don't
     * collide on string replace. */
    marker = makeLoadingMarker();
    editor.insertAtCursor(marker);
  }

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

    if (isRichText) {
      /* insertAtCursor(text, false) — escape=false tells the Tiptap
       * driver to PARSE markdown into rich nodes instead of inserting
       * literal characters. After preventDefault on the paste event,
       * the cursor is still at the position where the URL would have
       * landed, so this inserts in the right place. */
      editor.insertAtCursor(markdown.trim(), false);
    } else {
      if (! replaceInTextarea(el, marker, '\n\n' + markdown.trim() + '\n\n')) {
        /*
         * The marker is gone or unrecognisable — the editor rewrote it, or the
         * author edited over it while we were fetching. Say so rather than
         * finishing quietly, because the alternative is a draft that still
         * reads "Loading README from GitHub…" and an author with no reason to
         * think anything went wrong.
         */
        app.alerts.show({ type: 'error' }, 'The README arrived but could not be inserted — the placeholder was no longer in the post.');
      }
    }
  } catch (err) {
    const errMsg = pluckErrorMessage(err) || 'Could not fetch the README.';

    if (isRichText) {
      /* Tiptap: nothing to clean up in the document (we didn't insert
       * a marker). Inserting the original URL as escaped text gives
       * the user back what they pasted so they can edit and retry. */
      editor.insertAtCursor(url, true);
    } else {
      /* Textarea: swap the loading marker back to the original URL. */
      replaceInTextarea(el, marker, url);
    }

    app.alerts.show({ type: 'error' }, errMsg);
  } finally {
    if (loadingAlertKey !== null) {
      app.alerts.dismiss(loadingAlertKey);
    }
  }
}

function makeLoadingMarker() {
  const id = Math.random().toString(36).slice(2, 10);
  return '\n\n*Loading README from GitHub… (' + id + ')*\n\n';
}

/**
 * Swap the marker for the fetched README.
 *
 * @returns {boolean} whether the marker was found and replaced.
 *
 * 🚨 Reports failure instead of returning in silence. The marker is visible
 * text sitting in somebody's draft; if we cannot find it to remove it, the
 * worst outcome is publishing "*Loading README from GitHub…*" as though it were
 * the post. A caller that knows the swap failed can at least say so.
 */
function replaceInTextarea(el, find, replacement) {
  const value = el.value;
  const idx = value.indexOf(find);

  // Whitespace around the marker may not survive a round-trip through a rich
  // document, so fall back to the marker's own trimmed text before giving up.
  const needle = idx >= 0 ? find : find.trim();
  const at = idx >= 0 ? idx : value.indexOf(needle);

  if (at < 0) return false;

  el.value = value.slice(0, at) + replacement + value.slice(at + needle.length);

  // Only a real textarea has a selection to restore; a contenteditable does
  // not, and calling this on one throws.
  if (typeof el.setSelectionRange === 'function') {
    const cursor = at + replacement.length;
    el.setSelectionRange(cursor, cursor);
  }

  el.dispatchEvent(new Event('input', { bubbles: true }));

  return true;
}

function pluckErrorMessage(err) {
  try {
    if (err && err.response && err.response.errors && err.response.errors[0]) {
      return err.response.errors[0].detail || err.response.errors[0].title;
    }
  } catch (_) { /* fall through */ }
  return err && err.message ? err.message : null;
}
