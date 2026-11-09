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

-- Notification centre of inbox, the bell (webmail §5.4 sexies): one line per notification of a user. Nothing of the
-- mail beyond its subject in the payload. Kept 90 days by default (INBOX_NOTIFICATION_DAYS).
CREATE TABLE llx_inbox_notification(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	type varchar(32) NOT NULL,
	fk_mailref integer DEFAULT NULL,
	fk_account integer DEFAULT NULL,
	fk_user_actor integer DEFAULT NULL,
	payload text DEFAULT NULL,
	url varchar(255) DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_read datetime DEFAULT NULL,
	date_mailed datetime DEFAULT NULL
) ENGINE=innodb;
