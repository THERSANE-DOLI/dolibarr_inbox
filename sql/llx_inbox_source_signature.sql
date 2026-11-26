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

-- Signatures of a signed data source already used (1.143.0): one row per signed mail (its Message-ID, else the time
-- of its reception), so that the same signature on a mail of another key is a replay, refused. Kept as long as the
-- source: the requester of a mail is read again at each reading. signature: sha256 of the HMAC of the header.
CREATE TABLE llx_inbox_source_signature(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_source integer NOT NULL,
	signature char(64) NOT NULL,
	mail_key varchar(255) NOT NULL,
	date_signature datetime NOT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
