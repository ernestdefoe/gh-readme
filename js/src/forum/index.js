import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';
import attachPasteHandler from './attachPasteHandler';

/**
 * Forum-side wiring.
 *
 * TextEditor.onbuild fires once per composer mount, right after Flarum
 * constructs the underlying editor driver. The driver exposes `.el`
 * (the textarea DOM node) and `.insertAtCursor(text)`. We hook the
 * textarea's `paste` event there so every composer (new discussion,
 * reply, edit, private message) gets the same behavior with zero
 * per-composer wiring.
 */
app.initializers.add('ernestdefoe-gh-readme', () => {
  extend(TextEditor.prototype, 'onbuild', function () {
    const editor = this.attrs.composer?.editor;
    if (!editor) return;
    attachPasteHandler(editor);
  });
});
