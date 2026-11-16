# Agenda panel for modules

What the panel shows: [Agenda panel](../admin/agenda-panel.md).

inbox knows no agenda module: your module plugs itself here, inbox stays the same with or without it. inbox keeps
the frame (column, splitter, pin, close, rail button, when it shows); you take what is inside.

**Your pages instead of the ones of Dolibarr, without JavaScript**: hook `inboxAgendaProvider`, context `inbox`.
Addresses of this site only (a path starting with `/`); inbox fills the markers and opens the page in a window of
inbox, then reads the panel again when the window closes. Give them only when the user has your rights.

```php
public function inboxAgendaProvider($parameters, &$object, &$action, $hookmanager)
{
	if (!$parameters['user']->hasRight('mymodule', 'read')) {
		return 0;
	}
	$base = dol_buildpath('/mymodule/calendar.php', 1);
	$this->results['provider'] = array('label' => 'My agenda', 'source' => 'mymodule', 'urls' => array(
		'create' => $base.'?action=create&socid={socid}&contactids={contactids}&projectid={projectid}&elements={elements}',
		'open' => $base.'?event={id}',
		'openAll' => $base,
	));
	return 0;
}
```

Markers: `{id}` (the event), `{socid}`, `{contactids}`, `{projectid}` (recognized on the mail open, empty without one),
`{elements}` (pieces linked to the conversation, `type:id` separated by commas, types as Dolibarr names them:
`propal:12,facture:34`), `{start}` (empty for now). Your page may close its window with
`parent.postMessage({source: 'inbox-panel', action: 'close'}, location.origin)`.

Markers of the mail open, **after the `#` only** (the browser never sends this part to the server, so no subject
nor address of a mail lands in the logs of the web server; read it with `location.hash`): `{subject}`, `{messageid}`
(without angle brackets), `{emails}` (the sender and the recipients, without the mailboxes of the user, separated by
commas). Before the `#` they are emptied.

**Actions of a mail**: hook `inboxMailActions`, context `inbox`, called with the mail open (`mail`, `account`,
`message_id`, `from`, `subject`, and `socid`, `contactid` recognized, 0 when the user may not read them). Each action
is a button of the Dolibarr band of the mail; the same markers are filled when it is clicked. `window` true opens the
page in a window of inbox (it may tell its title, its unsaved work and when it is done, as the pages of the agenda
panel), else in a new tab.

```php
public function inboxMailActions($parameters, &$object, &$action, $hookmanager)
{
	global $user;
	if ($user->hasRight('mymodule', 'write')) {
		$this->results['actions'][] = array('label' => 'Plan a meeting', 'icon' => 'event-new', 'window' => true,
			'url' => dol_buildpath('/mymodule/meeting.php', 1).'?socid={socid}#subject={subject}&emails={emails}');
	}
	return 0;
}
```

**An invitation your module sent itself**: hook `inboxInviteEvent`, context `inbox`. When a mail holds an invitation
(`METHOD` other than `REPLY`), inbox asks with `mode` `find` (`user`, `uid`, `method`, `sequence`, `organizer` email):
put the event of this Dolibarr in `$this->results['event']` (`id`, `attendee` true when the user is an attendee and
not the owner, `partstat` `ACCEPTED` / `TENTATIVE` / `DECLINED` or '', `url` of a page of this site). inbox keeps it
only when the user owns the event or is one of its attendees; its card then says « Already in your agenda » with
« Open the event » (a window of inbox) instead of « Add to the agenda », and an attendee answers with three buttons:
inbox calls the hook again with `mode` `answer` (`event`, `partstat`, the event asked again on the server, never from
the browser) and your module records the answer (`$this->results['answered'] = true`); no mail is sent from the
mailbox.

**Events of your own** (data not in `llx_actioncomm`): hook `inboxAgendaEvents`, context `inbox`. Mode `declare`:
`$this->results['declare'] = 1` shows the panel even without the Agenda module. Mode `list` (`user`, `from`, `to`,
and the native `events` and `late`): add yours in `$this->results['events']` and `['late']`; return 1 to replace the
native ones. Fields: `id`, `source` (your module), `label`, `start`, `end` (timestamps), `allday`, `percent` (-1
without progress), `location`, `color` (`#rrggbb`), `thirdparty` (`id`, `name`), `url` (of this site), `messageId`,
`answer` (1 accepted, 2 maybe). Anything else is dropped; inbox never ticks an event of a module (its clicks go to
your JS hooks).

**JS hooks** (your script loaded by `module_parts['inboxjs']`). Each one gets a context object; change it with
`Dolibarr.on(name, ctx => {...})`, or with `Dolibarr.onAwait(name, async ctx => {...; return ctx;})` when you need to
wait. A listener that throws is written in the console and inbox goes on as without it.

| Hook | When | Context | Your module may |
|---|---|---|---|
| `inbox:agenda:render` | before each drawing of the content | `el`, `user` (`id`, `timezone`), `range` (`from`, `to`, `days`), `mail`, `events`, `late`, `handled` | draw in `el` itself and set `handled = true`; or change `events` / `late` and let inbox draw |
| `inbox:agenda:action` | each click of the panel, before inbox | `action` (`create`, `open`, `openAll`, `done`, `undone`, `openMail`, or the key of your button), `event`, `mail`, `handled` | do it your way and set `handled = true` |
| `inbox:agenda:toolbar` | drawing of the head | `buttons` (`key`, `label`, `icon`, `rank`, `pressed`), `mail` | add, remove or order buttons; yours come back through `inbox:agenda:action` |
| `inbox:agenda:shown` / `inbox:agenda:hidden` | panel shown or hidden | `pinned`, `reason` (`auto`, `button`, `key`, `api`) | follow its state |

`mail` is the mail open or `null`: `id`, `account`, `folder`, `messageId`, `subject`, `from`, and what the Dolibarr
panel recognized once loaded: `thirdparty`, `contact`, `user`, `project`, `elements` (`type`, `id`, `ref`). Never its
body. API: `Dolibarr.tools.inbox.agenda.refresh()`, `.open()`, `.close()`, `.isVisible()`.

```js
// Creation of events in your module, with the thirdparty of the mail open
Dolibarr.on('inbox:agenda:action', ctx => {
	if (ctx.action === 'create') {
		myModule.openEventForm({ socid: ctx.mail && ctx.mail.thirdparty ? ctx.mail.thirdparty.id : 0 });
		ctx.handled = true;
	}
});
```
