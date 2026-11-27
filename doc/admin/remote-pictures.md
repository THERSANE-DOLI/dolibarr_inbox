# Remote pictures: blocked, shown on a click, always shown

An email may load pictures from the Internet (logos, banners, signatures). Loading them tells the server that hosts
them that the email was opened, when and from where: the « trackers » of the newsletters work this way. Inbox
blocks them at the reading, by the Content Security Policy of the reading frame (`ajax/body.php`): the browser
itself refuses any picture not allowed, whatever the HTML of the email.

## What the user sees

A banner « Remote pictures blocked » above the email, with:

- **Show**: the pictures of this email, this time only.
- **Always show** (menu):
  - « Always for name@example.com » or « Always for @example.com »: the pictures of every email of this sender or
    domain. Offered for an authenticated sender only (SPF, DKIM, DMARC), never for a forged one.
  - « Always for the pictures of cdn.example.com » (1.149.0): the pictures loaded from this host, **whoever sends
    the email**. One entry per blocked host of the email (6 at most). The exact host only: a choice for
    `cdn.example.com` does not allow `img.example.com`, so that a shared host of a CDN or of an emailing platform is
    never widened to everyone hosted there.

When some hosts are allowed and others are not, the banner says which ones are shown (« Remote pictures blocked,
except the ones of www.example.com. ») and the frame loads those only. When every host of the email is allowed,
there is no banner.

## Pictures of our own domains (1.149.0)

The pictures hosted on the domain of a mailbox, or on one of its subdomains, are shown without a click, whoever sends
the email. Typical case: a customer answers and quotes our email; its signature loads the logo from our own website.
Loading a picture from our own server tells nothing to anyone outside.

- The domains are the ones of the addresses of the mailboxes of the entity.
- A public mail service or a shared platform (gmail.com, outlook.com, amazonaws.com...) never counts as ours, even
  when a mailbox has such an address.
- Settings > Security, « Pictures of our own domains », on by default. Turned off: these pictures are blocked as any
  other one.

## Settings of the administrator

Settings > Security, « Always show the pictures »:

| Choice | Sender | Domain of the sender | Host of the pictures | Our own domains |
|---|---|---|---|---|
| For a sender or a whole domain | yes | yes | yes | per its own switch |
| For a sender only | yes | no | yes | per its own switch |
| Never: each email on a click | no | no | no | per its own switch |

## Where the choices are kept

Table `llx_inbox_image_allow`, one row per user and choice: `address` is an email address, `@domain`, or a host
without « @ » (`cdn.example.com`, with its port when the address of the picture gives one). They are removed with the
user. The server checks every choice: a host is saved only when the email read loads a picture from it, and the CSP
lists only hosts made of letters, digits, dots, dashes and a port.
