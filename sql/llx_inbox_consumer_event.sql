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


-- Queue of the events given to the modules subscribed (1.146.0): one row per subscription, event and mail, never two
-- (unique key on the Message-ID, which stays the same when the mail is moved). Written by the synchronisation, given
-- to the module at the end of the same pass; status waiting (to give, at next_try), running (being given), done,
-- skipped (the module said « not for me », or the mail is gone or in the junk), failed (after 5 errors). Rows done,
-- skipped or failed are removed after 30 days by the scheduled cleaning.
CREATE TABLE llx_inbox_consumer_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_consumer integer NOT NULL,
	fk_account integer NOT NULL,
	event varchar(32) NOT NULL,
	message_id varchar(255) NOT NULL,
	fk_mail integer DEFAULT NULL,
	status varchar(8) DEFAULT 'waiting' NOT NULL,
	attempts integer DEFAULT 0 NOT NULL,
	next_try datetime DEFAULT NULL,
	error varchar(255) DEFAULT NULL,
	datec datetime NOT NULL,
	date_done datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
