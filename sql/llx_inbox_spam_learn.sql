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

-- Learning of the anti-spam engine of inbox (1.88.0): « Junk » (J) and « Not junk » (Shift+J) of the users, the
-- training of the start; done by the scheduled job (the action stays instant, an engine stopped fails nothing)
CREATE TABLE llx_inbox_spam_learn(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_mail integer NOT NULL,
	is_spam smallint NOT NULL,
	origin varchar(8) DEFAULT 'user' NOT NULL,
	fk_user integer DEFAULT NULL,
	state smallint DEFAULT 0 NOT NULL,
	error varchar(255) DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_done datetime DEFAULT NULL
) ENGINE=innodb;
