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

-- Journal of the changes for the quicker refresh of the lists (1.121.0): what changed and where, by numbers only.
-- Never a subject, a text, an address or a name: the browser reads the rows again through the calls that check the
-- rights. Written only when Settings > Reactivity is not « Standard »; kept 24 h.
CREATE TABLE llx_inbox_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer DEFAULT 0 NOT NULL,
	fk_user_target integer DEFAULT 0 NOT NULL,
	type varchar(16) NOT NULL,
	fk_folder integer DEFAULT 0 NOT NULL,
	ids varchar(255) DEFAULT NULL,
	fk_user_author integer DEFAULT 0 NOT NULL,
	origin varchar(32) DEFAULT NULL,
	datec datetime NOT NULL
) ENGINE=innodb;
