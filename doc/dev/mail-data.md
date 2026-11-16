# Data of a mail: one door

Everything a mail brings (body, sender, subject, headers X-Data-Form / X-Quotation-Data, invitations, file names) goes
through one door before it is shown or written in Dolibarr; the type of the field it goes to decides:

| Tool | For |
|---|---|
| `InboxSafe::display()` | the HTML body in the reading frame only (sandboxed iframe, CSP without script): styles of the mail kept |
| `InboxSafe::html()` | the HTML body when it leaves the frame (note of an object, documents, draft, quote): `display()` then HTMLPurifier |
| `InboxSafe::line()` / `lines()` | any other value for a plain field (name, town, title, place, address): no tag, no control character, no `"` (as the filter `alphanohtml` of the forms of the core) |
| `InboxSafe::textToHtml()` | a plain text written in an HTML field of the core (note): escaped, never stripped |
| `Dolibarr.tools.inbox.safeHtml()` (`js/inbox-safe.js`) | any HTML put in the page (editor): DOMPurify, then the Sanitizer API of the browser when it has one |

Rules:
1. A value of a mail is never written in an object of Dolibarr without `InboxSafe`.
2. The HTML body leaves the reading frame only by `InboxSafe::html()` (server) and `safeHtml()` (page).
3. A plain text written in an HTML field goes through `textToHtml()`, never as it is.
4. No `innerHTML` of a value of a mail outside `safeHtml()`; the rest by `textContent` or `esc()`.
5. An editor registered by another module (`inbox:editor:register`) is covered as well: `setHtml`, `insertHtml`,
   `setSignature`, `replaceRange` and the HTML given at its opening go through `safeHtml()`; its paste is its own
   job (the editors of inbox clean theirs).
6. A flaw found in the display of a page of the core gives a pull request to the core, not one more filter in inbox.

Libraries (unchanged copies, update scripts in `dev/`): DOMPurify 3.4.17 in `js/vendor/dompurify/`, HTMLPurifier
4.19.1 in `lib/vendor/htmlpurifier/` (loaded only by `InboxSafe::html()`, definition cache in
`documents/inbox/temp/htmlpurifier`). The day inbox moves to the core, they go to the folders of the libraries of
the core: `grep -rn "TODO core:"` lists what to change.
