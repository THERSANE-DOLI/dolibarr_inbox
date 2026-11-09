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

-- Changes of a mailbox: access added, removed or changed, server or password changed. Never a password in it.
CREATE TABLE llx_inbox_account_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	datec datetime NOT NULL,
	fk_user integer DEFAULT NULL,
	action varchar(32) NOT NULL,
	value_before text DEFAULT NULL,
	value_after text DEFAULT NULL
) ENGINE=innodb;
