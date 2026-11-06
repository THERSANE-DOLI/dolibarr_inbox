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

-- Verdict of the antivirus for a file received (by its SHA-1): the same file received ten times is scanned once.
-- result: clean (kept 7 days, signatures change), infected. « Not scanned » is never kept.
CREATE TABLE llx_inbox_attachment_scan(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	sha1 varchar(40) NOT NULL,
	result varchar(16) NOT NULL,
	message varchar(255) DEFAULT NULL,
	date_scan datetime NOT NULL
) ENGINE=innodb;
