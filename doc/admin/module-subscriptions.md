# Modules subscribed to the mailboxes

Modules installed in Dolibarr may react to the mails of a mailbox: create a ticket when a request arrives, sort the
mails, link them to a record. Inbox never lets them do it on their own: the administrator of Dolibarr turns the
feature on for the instance, then the managers of each mailbox allow each module, and only what they tick.

What it is, and what it is not:

- **a control of what runs automatically**: nothing happens on a mailbox without a decision of its managers, every
  decision and every action of a module is written in the journal of the mailbox;
- **not a security barrier**: a module is PHP code with every right on the database of Dolibarr, it could read the
  mails without asking. The protection against a module you do not trust is not to install it. The only mailboxes a
  module can never read are the ones protected with their content (see « Protected mailboxes » below).

## Turn it on for the instance

Setup > Modules > Inbox > Settings, section « Modules subscribed to the mailboxes », switch « Allow the modules to
subscribe to the mailboxes » (`INBOX_CONSUMERS_ENABLED`, off by default). Administrators of Dolibarr only.

On this instance: {{conf:INBOX_CONSUMERS_ENABLED}}

Below the switch, one line per module that declares itself: what it asks, and on how many mailboxes it is active,
in test or paused.

Turned off: the authorizations already given are kept, nothing is given to the modules, the section Modules of the
mailboxes says so (with a link to the setup for the administrators of Dolibarr).

## Allow a module on a mailbox

Settings > Mailboxes > the mailbox > Reception > Modules, with the level « Manage all » on the mailbox (the
owner of a personal mailbox has it).

Each module that declares itself is listed with what it is for. « Allow » opens what it asks:

| Part | What you choose |
|---|---|
| Events received | « Arrival of a mail »: the module is told about each new mail, from the moment it is allowed, never about the mails already there |
| Actions allowed | only the ones the module asks, none ticked the first time: read the content (text and attachments; sender, recipients and subject are always given), mark as read or unread, tag, move (archive, junk or trash, never delete for good), link to a record of Dolibarr, prepare a reply in the drafts |
| Folders | the whole mailbox, or only some folders |
| Mode | **Test**: the module receives the mails, its actions are only written in the journal; **Active**: it acts within what is ticked |

The test mode is offered the first time: look in the journal at what the module would do, then change it to Active.

A module allowed on a **personal** mailbox, or given more (an event, an action), by someone else than its owner: the
owner and the administrators of Dolibarr are told (notification « Module allowed on a personal mailbox »), as when a
manager gives himself an access. The owner who sets his own mailbox is not told.

What a module may never do: delete a mail for good, empty the trash, create or remove folders, change the accesses,
the rules or the identities of the mailbox, send a mail by itself (sending will come later, with protections
against loops between automatic answers).

Then, on the line of the module:

- **Change**: what is ticked, the folders, the mode;
- **Pause** / **Resume**: nothing is given to the module while it is paused, the settings are kept;
- **Remove** (cross): the module receives nothing more from this mailbox, what was ticked is lost.

**New request**: when a new version of a module asks something more, the line shows « New request » and the new
item is marked « new » in the window, unticked. The module never gets it until a manager ticks it and saves.

A module turned off or removed from Dolibarr stays on the list (« Module turned off or removed ») with its settings:
it runs again when the module is turned on, or you remove its authorization.

## Protected mailboxes

- **Protected with its content**: nothing of the mailbox is kept on the server, so no module can receive its mails.
  The section Modules says so and nothing can be allowed. When a mailbox becomes protected with its content, the
  modules allowed on it are paused (« Paused: mailbox protected with its content ») and the journal says so.
- **Protected with its password**: the content is kept in the index of Inbox like any mailbox, only the password is
  protected. Modules may be allowed, but the mailbox is read only while someone has it unlocked: the modules receive
  its mails at that time only.

## Journal

Settings > Mailboxes > the mailbox > Access, « Journal of the changes »: « Module allowed », « Authorization changed », « Module
paused », « Module running again », « Authorization removed », « Modules paused, mailbox protected with its
content », with who did it and when.

## What happens then

From 1.146.0 the mails are given to the modules allowed:

- each new mail of the mailbox (or of the folders chosen) is given once, at the end of the synchronisation that
  found it; never the mails already there when the module was allowed, never a mail in the junk or the trash;
- on the line of the module: the last mail it handled, the mails waiting, the mails given up after 5 tries (a module
  in error never stops the synchronisation, its mails are given again after 1 min, 5 min, 15 min, 1 h);
- in the journal of the mailbox (tab Access), every action of the module in its name, with the badge « module »;
  in test mode, its actions with the badge « test, not done »; the filter « Who » lists the modules;
- the scheduled job « Inbox: mails given to the modules subscribed » (every 5 minutes) gives again the mails in
  error and the ones the synchronisation had no time for.

The guards of the next versions (automatic pause after errors, limit per hour, sending by a module) are not there
yet: watch the line of the module and the journal.

For the developers of modules: [Subscribe a module to the mailboxes](../dev/consumers.md).
