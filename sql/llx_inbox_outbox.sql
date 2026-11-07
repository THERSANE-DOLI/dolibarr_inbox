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


-- Queue of the sending (lot W6 1.17.0): a mail built and frozen when « Send » is clicked, sent after the delay, at the
-- date chosen, or at once; what remains to do after the sending kept with it (mail answered, draft, pieces).
-- status: 0 waiting, 1 sending (lock), 2 sent, -1 failed
CREATE TABLE llx_inbox_outbox(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	fk_account integer NOT NULL,
	fk_identity integer DEFAULT 0 NOT NULL,
	message_id varchar(255) NOT NULL,
	subject varchar(255) DEFAULT NULL,
	recipients text DEFAULT NULL,
	nb_recipients integer DEFAULT 0 NOT NULL,
	compose_token varchar(32) DEFAULT NULL,
	actions text DEFAULT NULL,
	typed mediumtext DEFAULT NULL,
	scheduled smallint DEFAULT 0 NOT NULL,
	date_send datetime NOT NULL,
	status smallint DEFAULT 0 NOT NULL,
	tries integer DEFAULT 0 NOT NULL,
	error varchar(255) DEFAULT NULL,
	sent_copy smallint DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	date_sent datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
