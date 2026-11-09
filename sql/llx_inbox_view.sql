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

-- Virtual views (1.31.0): a search kept by its author, shown in the section « Views », shared with users and groups
CREATE TABLE llx_inbox_view(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	label varchar(128) NOT NULL,
	icon varchar(48) DEFAULT NULL,
	color varchar(7) DEFAULT NULL,
	search text DEFAULT NULL,
	accounts text DEFAULT NULL,
	shared_edit smallint DEFAULT 0 NOT NULL,
	show_count smallint DEFAULT 0 NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif integer DEFAULT NULL
) ENGINE=innodb;

-- Who a view is shared with: one user or one group per line (the other one is 0)
CREATE TABLE llx_inbox_view_share(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_view integer NOT NULL,
	fk_user integer DEFAULT 0 NOT NULL,
	fk_usergroup integer DEFAULT 0 NOT NULL
) ENGINE=innodb;
