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


-- Default template of a writing (1.118.0), on four levels: fk_user = 0 for every user, fk_account = 0 for every
-- mailbox. Lookup, the most precise first: user + mailbox, mailbox, user, every one; then the template of Dolibarr
-- marked « default for the type » (llx_c_email_templates.defaultfortype, new mails only).
-- usage_type: new (a new mail) or reply (an answer). fk_template = 0: « no template » on purpose, the levels under
-- it are not used.
CREATE TABLE llx_inbox_template_default(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer DEFAULT 0 NOT NULL,
	fk_user integer DEFAULT 0 NOT NULL,
	usage_type varchar(16) NOT NULL,
	fk_template integer DEFAULT 0 NOT NULL,
	fk_user_modif integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
