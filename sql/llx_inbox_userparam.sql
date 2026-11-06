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

-- Settings of a user: fk_account = 0 for all the mailboxes of the user, otherwise for this mailbox only.
-- Lookup: user + mailbox, then user, then the setup of the module.
CREATE TABLE llx_inbox_userparam(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_user integer NOT NULL,
	fk_account integer DEFAULT 0 NOT NULL,
	param varchar(64) NOT NULL,
	value text DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
