# Agenda panel

For the modules that plug into the panel, see [Agenda panel for modules](../dev/agenda.md).

On the right of the mails, the panel « Today »: the events of the user, late ones to do, today, the next 7 days.

On a computer, a strip of icons on the right edge of the mail space holds the columns beside a mail, one at a time:
Dolibarr (the Dolibarr part of the mail, moved out of the mail into a column as high as the screen) and Today (with
the day of the month); both take the same place, width and splitter. A click shows its column, a click on
the icon pressed (or the cross of the column) closes it; Shift+F9 does it for Today. With a mail open the last column
chosen comes back; with no mail open, Today is shown unless closed by hand. When the reading would keep less than
40 rem beside a column, the column is a drawer over the reading, opened by hand only (never by itself), closed by a
click beside it, Escape, its cross or its icon; a line of chips under the head of the mail (thirdparty, pieces,
notes) opens the drawer Dolibarr. On a tablet or a phone nothing changes: the Dolibarr part on top of the mail (a
sheet on a phone) and Today opened by the button of the rail.

The rail also has the space « Agenda »: the whole agenda in a frame, the page « openAll » of a module that took over
(below), else the agenda of Dolibarr on the user. « Open the whole agenda » of the panel goes there.

It reads the native agenda
of Dolibarr only (`llx_actioncomm`, the user owner or attendee who did not decline), without the automatic events
(type `systemauto`) nor the records of mails (`AC_EMAIL`, `AC_EMAIL_IN`, `AC_FAX`, `AC_INBOX_BOUNCE`). Right
« read his events » of the Agenda module; « create his events » for the case « done ».

## Pages of modules opened from a mail

A module opens its pages from a mail (actions of the mail, panel) with a token of one use (`{ctx}`, 1.127.0): the
page reads the mail on the server, nothing of the mail is in its address. The old markers that put the subject and
the addresses in the address are removed. A module not yet moved to `{ctx}` gets them back with the hidden constant
`INBOX_LEGACY_MAIL_MARKERS` = `1` (Setup > Other setup); remove it once the module is updated.

## Something left to do on an event

An agenda module may show a button under one of its events in the agenda panel, for what is left to do on it (for
example « Write the report » after a meeting). The button opens the page of the module in a window of inbox; the panel
is read again when the window closes. The module decides whether such an event counts as late (warning on the icon of
the agenda).

## « Create > Event » with an agenda module

When an agenda module declares its page of creation of an event, « Create > Event » in the Dolibarr panel of a mail
opens that page in a window of inbox (1.160.0), filled from the mail (third party, contact, project, pieces), instead
of the page of Dolibarr. The event saved there is linked to the mail, like the pieces created from it, with the mail
in its documents when « Put this mail in the documents of the piece » is ticked. « New event » of the agenda panel
opens the same page but links nothing: it belongs to the agenda of the user, not to the mail open.

## Events and tickets linked to a mail

In the pieces of the Dolibarr panel, an event linked to a mail shows its subject and its date (1.157.1), and, since
1.158.0, the faces of its colleagues: the organizer first, then the users invited (an invitation declined in grey),
three at most and « +n » for the others. A ticket shows the user it is assigned to. On hover a bubble gives the name,
the role and the answer to the invitation; a click opens the card of the colleague (write to him, his card in
Dolibarr, his mails). Only active internal users are shown, and only for a piece the user may read.
