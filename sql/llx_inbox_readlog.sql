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

-- Every reading of a mail by its Message-ID: who, which mailbox, found or not
CREATE TABLE llx_inbox_readlog(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	datec datetime NOT NULL,
	fk_user integer DEFAULT NULL,
	origin varchar(32) DEFAULT NULL,
	mailbox varchar(255) NOT NULL,
	message_id varchar(255) NOT NULL,
	result varchar(16) NOT NULL,
	folder varchar(255) DEFAULT NULL,
	from_cache smallint DEFAULT 0 NOT NULL,
	error varchar(255) DEFAULT NULL
) ENGINE=innodb;
