# Email links (mailto)

A `mailto:` link (an email address clicked in Dolibarr, in a site, in a PDF or in another program) can open a new
email in Inbox instead of the mail program of the computer. The browser never does it by itself: each user gives
their agreement once per computer. Chrome or Edge, Inbox served over HTTPS, user with the right to send.

## In the browser

At the first start of Inbox in a tab, a bar offers « Open them in inbox ». The browser then asks for the agreement,
often with a small icon at the right of its address bar (two diamonds), easy to miss: click on it and accept. The
bar is offered once per browser; the same button stays in My account > Email links (mailto).

From then on, every `mailto:` link clicked in the browser, Dolibarr included, opens a new email in an Inbox tab. The
handlers are listed in `chrome://settings/handlers` (to remove one, or when the question was refused: the browser
does not ask again).

## In the installed application

Installed as an application (icon at the right of the address bar), Inbox also declares itself to the system as a
program for the `mailto:` links. At the first link, Chrome asks « Open with Inbox? ». If the application is already
open, its window comes forward with the new email. An application installed before version 1.123.0 may have to be
uninstalled and installed again for the system to know it.

For the links clicked outside the browser (mail program, PDF, office files), choose Inbox as default program:

- **Windows**: Settings > Apps > Default apps > « Choose defaults by link type » > `MAILTO` > Inbox.
- **Linux**: Chrome writes `~/.local/share/applications/chrome-<id>-Default.desktop` for the application. Settings >
  Default applications > Mail (GNOME), or in a terminal:

  ```
  grep -l "x-scheme-handler/mailto" ~/.local/share/applications/chrome-*.desktop
  xdg-mime default chrome-<id>-Default.desktop x-scheme-handler/mailto
  xdg-mime query default x-scheme-handler/mailto
  ```

## What is read from the link

The address, and the fields `cc`, `bcc`, `subject` and `body` of the link (RFC 6068). Nothing is sent: the email
stays a draft until the user sends it.
