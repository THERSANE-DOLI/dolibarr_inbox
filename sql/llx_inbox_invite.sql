-- Copyright (C) 2026	John BOTELLA
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Invitations answered (1.22.0): one row per user and event (UID of the invitation, whatever the mailbox or
-- the copy of the mail): the answer given, and the event of the agenda of Dolibarr made from it (updates, cancel).
CREATE TABLE llx_inbox_invite(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	uid_hash varchar(40) NOT NULL,
	sequence integer DEFAULT 0 NOT NULL,
	partstat varchar(16) DEFAULT NULL,
	date_reply datetime DEFAULT NULL,
	fk_actioncomm integer DEFAULT NULL,
	agenda_sequence integer DEFAULT 0 NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
