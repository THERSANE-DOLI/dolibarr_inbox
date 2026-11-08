# Tests of the writing in a browser made by jsdom (editor, voice typing)

`js/inbox-editor.js` with the frozen Tiptap bundle (`js/vendor/tiptap`), run in a DOM made by jsdom (no browser).
Never shipped, never served (`dev/.htaccess`).

    cd dev/editor-tests
    npm ci          # once: jsdom 26.1.0, versions pinned in package-lock.json, node_modules ignored by git
    npm test        # node --test

- jsdom 26.1.0 (MIT), integrity of npm: sha512-Cvc9WUhxSMEo4McES3P7oK3QaXldCfNWp7pl2NNeiIFlCoLr3kfq9kb1fxftiwk1FLV7CvpvDfonxtzUDeSOPg==
- Node 18 or later.
- jsdom has no layout: what ProseMirror measures is given flat, and the pictures are « loaded » at 400 x 200.
- What is covered (editor.test.mjs): pictures (data: source and size kept, corners to resize, bar of the picture:
  sizes, proportions, alternative text, removal), variables of a template, tables in inline styles, formatting,
  selection read and replaced (AI of the writing).
- What is covered (dictation.test.mjs, js/inbox-dictation.js with a fake speech recognition): receiver of the voice
  told from the browser (Chrome, Edge, Safari, others, none in Firefox and Brave), button per mailbox, consent
  asked once and sent with the mailbox, refused by the server, words inserted at the cursor (escaped), what is being
  heard shown apart, started again after a silence, stopped by the user, microphone refused, writing closed.
