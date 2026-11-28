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


-- Mail templates of inbox (type inbox_send of llx_c_email_templates, 1.18.0) offered in some mailboxes only:
-- a template without any row here is offered in every mailbox
CREATE TABLE llx_inbox_template_account(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_template integer NOT NULL,
	fk_account integer NOT NULL
) ENGINE=innodb;
