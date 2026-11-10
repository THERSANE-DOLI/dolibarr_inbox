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

-- Recognized domains (1.59.0): domains of organisations checked by a user with the right « Manage the recognized
-- domains »; shown on the sender (authenticated mail) and on the links, and the reference of the lookalike domains
CREATE TABLE llx_inbox_trusted_domain(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	domain varchar(253) NOT NULL,
	label varchar(128) NOT NULL,
	subdomains smallint DEFAULT 1 NOT NULL,
	note varchar(255) DEFAULT NULL,
	date_creation datetime NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
