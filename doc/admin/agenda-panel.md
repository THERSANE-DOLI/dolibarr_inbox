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
