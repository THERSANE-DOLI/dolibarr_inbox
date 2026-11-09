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

-- Conversations ignored (the whole mailbox: the answers arrive read) or watched (one user: highlighted), lot W4 1.11.0
-- (B8, keys K and W). Tied to the key of the thread in the mailbox, which follows the threads merged.
CREATE TABLE llx_inbox_thread_mark(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	thread_key varchar(32) NOT NULL,
	fk_user integer DEFAULT 0 NOT NULL,
	mode varchar(8) NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
