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

-- Data sources (lot A2 level 1, 1.72.0): mails sent by a site or an application from its own address, the real
-- requester written in structured headers; believed only for a trusted sender, authenticated. Declared by the
-- administrators of Dolibarr; code = source shipped with the module (example, disabled)
CREATE TABLE llx_inbox_source(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	code varchar(32) DEFAULT NULL,
	label varchar(128) NOT NULL,
	status smallint DEFAULT 0 NOT NULL,
	senders text DEFAULT NULL,
	header_email varchar(64) DEFAULT NULL,
	header_data varchar(64) DEFAULT NULL,
	header_fields text DEFAULT NULL,
	mapping text DEFAULT NULL,
	request_type varchar(32) DEFAULT NULL,
	date_creation datetime NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
