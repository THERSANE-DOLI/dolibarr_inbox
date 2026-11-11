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

-- Browsers of a user (1.89.0, protected mailboxes): public key of a non extractable key pair kept in the browser
CREATE TABLE llx_inbox_device(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	public_key text NOT NULL,
	fingerprint varchar(19) NOT NULL,
	label varchar(128) DEFAULT NULL,
	approve_asked datetime DEFAULT NULL,
	status smallint DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	date_last_use datetime DEFAULT NULL,
	fk_user_revoke integer DEFAULT NULL,
	date_revoke datetime DEFAULT NULL
) ENGINE=innodb;
