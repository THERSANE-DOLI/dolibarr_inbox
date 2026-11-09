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

-- Delivery reports (DSN, RFC 3464) of the mails sent (1.25.0): one line per recipient of a report, linked to the mail
-- sent by its Message-ID (the mail may be sent by inbox or by another software, then indexed)
CREATE TABLE llx_inbox_bounce(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	message_id varchar(255) NOT NULL,
	report_id varchar(255) NOT NULL,
	fk_account integer DEFAULT 0 NOT NULL,
	recipient varchar(255) NOT NULL,
	action varchar(16) NOT NULL,
	status varchar(16) DEFAULT NULL,
	diagnostic varchar(300) DEFAULT NULL,
	date_report datetime DEFAULT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
