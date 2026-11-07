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

-- Index of inbox (rebuilt from the mail server at any time): a folder of a mailbox. sync_cursor is opaque (connector).
CREATE TABLE llx_inbox_folder(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_account integer NOT NULL,
	path varchar(255) NOT NULL,
	name varchar(255) NOT NULL,
	parent_path varchar(255) DEFAULT NULL,
	delimiter varchar(4) DEFAULT NULL,
	role varchar(16) DEFAULT NULL,
	flags varchar(255) DEFAULT NULL,
	selectable smallint DEFAULT 1 NOT NULL,
	subscribed smallint DEFAULT 0 NOT NULL,
	sync_enabled smallint DEFAULT 1 NOT NULL,
	sync_cursor text DEFAULT NULL,
	total integer DEFAULT 0 NOT NULL,
	unread integer DEFAULT 0 NOT NULL,
	indexed integer DEFAULT 0 NOT NULL,
	oldest_indexed datetime DEFAULT NULL,
	first_sync_done smallint DEFAULT 0 NOT NULL,
	att_repair integer DEFAULT 0 NOT NULL,
	date_sync datetime DEFAULT NULL,
	sync_error text DEFAULT NULL,
	status smallint DEFAULT 1 NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
