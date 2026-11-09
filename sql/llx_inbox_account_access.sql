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

-- Who can use a mailbox: one user or one group (the other one is 0), level read < send < manage.
-- 0 instead of NULL so that the unique key really prevents duplicates.
CREATE TABLE llx_inbox_account_access(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_account integer NOT NULL,
	fk_user integer DEFAULT 0 NOT NULL,
	fk_usergroup integer DEFAULT 0 NOT NULL,
	level varchar(8) NOT NULL,
	date_creation datetime NOT NULL,
	fk_user_creat integer NOT NULL
) ENGINE=innodb;
