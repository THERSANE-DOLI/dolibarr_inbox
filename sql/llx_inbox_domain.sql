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

-- Shared domains (lot A7, 1.76.0): only the differences with the list shipped in data/domains/ (read in its files,
-- never copied here): a domain added in Settings > Public and shared domains (origin local, with its category), a shipped domain
-- disabled (origin shipped, status 0)
CREATE TABLE llx_inbox_domain(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	domain varchar(253) NOT NULL,
	category varchar(16) NOT NULL,
	origin varchar(16) DEFAULT 'local' NOT NULL,
	status smallint DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
