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

-- Modules subscribed to a mailbox (1.112.0): what a manager of the mailbox allowed a module to receive and to do on it.
-- Not a security barrier (a module is PHP with every right on the base), a control of what runs automatically:
-- refused by default, written in the journal of the mailbox. events, actions, folders: JSON lists (folders empty =
-- the whole mailbox, else rowids of llx_inbox_folder); requested: what the module asked when the manager decided (a
-- request added later by the module is shown as new, never granted by itself); mode: test (the actions are only
-- written in the journal) or active; paused: nothing delivered, kept as set
CREATE TABLE llx_inbox_consumer(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	code varchar(64) NOT NULL,
	events varchar(255) DEFAULT NULL,
	actions varchar(255) DEFAULT NULL,
	folders text DEFAULT NULL,
	requested varchar(512) DEFAULT NULL,
	mode varchar(8) DEFAULT 'test' NOT NULL,
	paused smallint DEFAULT 0 NOT NULL,
	pause_reason varchar(32) DEFAULT NULL,
	last_ok datetime DEFAULT NULL,
	last_error varchar(255) DEFAULT NULL,
	errors_in_row integer DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
