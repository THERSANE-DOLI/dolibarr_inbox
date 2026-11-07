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

-- Who handles a mail (lot W4, webmail §5.4 quinquies): tied to the mailref, so an assignment survives every move,
-- even to another mailbox. Several people per mail. Nothing is written on the mail server.
CREATE TABLE llx_inbox_mail_assign(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_mailref integer NOT NULL,
	fk_user integer NOT NULL,
	fk_user_assigner integer DEFAULT NULL,
	date_assign datetime NOT NULL
) ENGINE=innodb;
