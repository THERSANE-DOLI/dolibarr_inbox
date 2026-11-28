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

-- Journal of the actions on the mailboxes (decision A4): who moved, archived, removed, tagged... (kept 12 months
-- by default). Read / unread and starred are not written (too frequent). No content of the mail beyond its subject.
CREATE TABLE llx_inbox_action_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	fk_user integer NOT NULL,
	user_name varchar(255) DEFAULT NULL,
	consumer varchar(64) DEFAULT NULL,
	action varchar(32) NOT NULL,
	nb integer DEFAULT 1 NOT NULL,
	message_id varchar(255) DEFAULT NULL,
	subject varchar(255) DEFAULT NULL,
	folder_from varchar(255) DEFAULT NULL,
	folder_to varchar(255) DEFAULT NULL,
	fk_account_to integer DEFAULT NULL,
	detail varchar(255) DEFAULT NULL,
	undone smallint DEFAULT 0 NOT NULL,
	simulated smallint DEFAULT 0 NOT NULL,
	date_action datetime NOT NULL
) ENGINE=innodb;
