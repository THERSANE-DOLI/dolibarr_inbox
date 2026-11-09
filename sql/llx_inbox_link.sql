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

-- A mail linked to a piece of Dolibarr (lot W5 1.13.0): thirdparty, contact, proposal, order... Tied to the mailref,
-- so the link survives every move. Own table (not element_element): author, date and origin (the bridge of another
-- module uses it to never copy a link twice).
CREATE TABLE llx_inbox_link(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_mailref integer NOT NULL,
	element_type varchar(64) NOT NULL,
	fk_element integer NOT NULL,
	origin varchar(32) DEFAULT NULL,
	fk_user_creat integer DEFAULT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
