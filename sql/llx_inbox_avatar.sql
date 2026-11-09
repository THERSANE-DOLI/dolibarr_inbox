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

-- Pictures of the correspondents looked for by the server (lot W3, 1.5.0): hash of the address (or of the domain for
-- BIMI), source (bimi, gravatar), file in documents/inbox/avatars ('' = none found), date of the check.
CREATE TABLE llx_inbox_avatar(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	hash varchar(40) NOT NULL,
	source varchar(16) NOT NULL,
	file varchar(128) DEFAULT NULL,
	date_check datetime NOT NULL
) ENGINE=innodb;
