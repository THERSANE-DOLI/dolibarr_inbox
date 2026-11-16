# Writing editor

The writing, the signatures and the templates use the editor of Inbox (Tiptap, in the module) by default. Settings >
General > Writing editor offers the CKEditor of Dolibarr instead, when the module « WYSIWYG editor » (FCKeditor) is
on with CKEditor as its editor (`FCKEDITOR_EDITORNAME` empty or `ckeditor`). It is loaded like in the pages of
Dolibarr: `includes/ckeditor/ckeditor/` or the external CKEditor 4 of `JS_CKEDITOR`, the configuration of the theme
(`theme/<theme>/ckeditor/config.js`), the skin `FCKEDITOR_SKIN`.

- Toolbar and context menu of Inbox (default): inline CKEditor without its bar nor menu, driven by the toolbar, the
  menus and the bubbles of Inbox; the same features as the Inbox editor (tables, pictures, variables of the templates
  as chips, AI, voice typing).
- Those of CKEditor (`INBOX_CKEDITOR_NATIVE`): the classic CKEditor of Dolibarr, toolbar `dolibarr_mailings` and its
  context menu; voice typing, AI and the variables of Inbox are the last group of that toolbar.

Small screens (`INBOX_EDITOR_SMALL`, Settings > General > Writing editor > « On a small screen », 1.101.0): under
the width of the phone layout (64rem, 1024 px) a writing opens with the Inbox editor (default), CKEditor driven by the
toolbar of Inbox (never its own rows of buttons, whatever `INBOX_CKEDITOR_NATIVE` says), or the same editor as on a
computer (`same`). Decided when the writing opens: turning the screen or resizing the window while typing keeps the
editor. The width of the writing window itself is not used (the window docked on a computer is narrow too).

When CKEditor cannot start (files missing, blocked by the browser), the writing opens with the Inbox editor. Another
module can register its own editor: event `inbox:editor:register`, interface described at the top of
`js/inbox-editor.js`.

Signature of the writing: by default it follows the identity (shown under the text, added by the server at the
sending, marked `div.inbox-signature`). Its pencil puts it in the text to change it for one mail only, marked
`div.inbox-signature-edited`: kept as it is in the drafts (never replaced by the one of the identity), sent once, the
« -- » delimiter of the text part written before it as for the other one.
