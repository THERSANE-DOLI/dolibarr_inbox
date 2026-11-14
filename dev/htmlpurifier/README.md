# HTMLPurifier (HTML of a mail cleaned on the server before it leaves the reading frame, InboxSafe::html)

- Package: ezyang/htmlpurifier 4.19.1, licence LGPL-2.1-or-later (lib/vendor/htmlpurifier/LICENSE), http://htmlpurifier.org
- Taken on 2026-11-14 from https://github.com/ezyang/htmlpurifier/archive/refs/tags/v4.19.1.tar.gz
  (sha256 e28be6193246a7d1b864bb72f84756a27e9ab4008d8fca23396e02aaf2dd9e6e), same files as the Composer package
- Folder kept: library/, unchanged (1.1 MB, 379 files)
- Loaded only by InboxSafe::html() (class/inboxsafe.class.php), never when a mail is read; definition cache in
  DOL_DATA_ROOT/inbox/temp/htmlpurifier
- Update with dev/htmlpurifier/update.sh <version>
- TODO core: when inbox moves to the core, the library goes to htdocs/includes/ezyang/htmlpurifier/ (+ line in
  COPYRIGHT) and InboxSafe requires it from DOL_DOCUMENT_ROOT.'/includes/ezyang/htmlpurifier/library/'
