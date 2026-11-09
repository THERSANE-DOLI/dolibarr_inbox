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

-- Raw mail read from a mailbox, kept for a while so that it is not read again on the server
CREATE TABLE llx_inbox_message(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	mailbox varchar(255) NOT NULL,
	folder varchar(255) DEFAULT NULL,
	uid integer DEFAULT NULL,
	message_id varchar(255) NOT NULL,
	sha1 varchar(40) NOT NULL,
	date_mail datetime DEFAULT NULL,
	from_email varchar(255) DEFAULT NULL,
	subject varchar(255) DEFAULT NULL,
	filepath varchar(255) NOT NULL,
	size integer DEFAULT 0 NOT NULL,
	date_fetch datetime NOT NULL,
	fk_user_fetch integer DEFAULT NULL
) ENGINE=innodb;
