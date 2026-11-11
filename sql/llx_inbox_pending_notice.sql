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

-- Sending of a protected mailbox that failed while no page was open (1.89.0): only where the draft is and a code,
-- never a subject, an address or a content
CREATE TABLE llx_inbox_pending_notice(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_account integer NOT NULL,
	fk_user integer NOT NULL,
	draft_folder varchar(255) DEFAULT NULL,
	draft_uid integer DEFAULT NULL,
	error_code varchar(32) NOT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
