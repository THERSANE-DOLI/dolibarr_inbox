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

-- A mail for Dolibarr, whatever its place (webmail §5.7 bis, P9 bis): found by its Message-ID, never by mailbox,
-- folder or UID, so that links, notes and assignments survive every move. Check print: date of sending + sender, to
-- tell apart two mails with the same Message-ID. Created in lot W4 for the assignments; links and notes come in W5.
CREATE TABLE llx_inbox_mailref(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	message_id varchar(255) NOT NULL,
	check_print varchar(64) DEFAULT NULL,
	subject varchar(255) DEFAULT NULL,
	from_email varchar(255) DEFAULT NULL,
	date_mail datetime DEFAULT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
