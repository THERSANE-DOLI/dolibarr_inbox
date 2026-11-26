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

-- 1.143.0: a data source may sign its headers (HMAC-SHA256 of a key shared with the site, stored with dolEncrypt()):
-- a signed mail is believed without the authentication of its sender, for the mail servers that write none
ALTER TABLE llx_inbox_source ADD COLUMN header_signature varchar(64) DEFAULT NULL AFTER header_data;
ALTER TABLE llx_inbox_source ADD COLUMN signature_key text DEFAULT NULL AFTER header_signature;

-- 1.143.0: a signature serves one mail only (a replay on a mail of another Message-ID is refused)
CREATE TABLE llx_inbox_source_signature(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_source integer NOT NULL,
	signature char(64) NOT NULL,
	mail_key varchar(255) NOT NULL,
	date_signature datetime NOT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
ALTER TABLE llx_inbox_source_signature ADD UNIQUE INDEX uk_inbox_source_signature (fk_source, signature);
