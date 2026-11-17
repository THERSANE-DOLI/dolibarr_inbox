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


-- Options of a template of inbox kept by inbox (1.118.0): llx_c_email_templates has no column for them.
-- whole = 1: a complete mail, that takes the place of the text (asked when the text was already written);
-- 0 or no row: a piece of text, put at the cursor.
CREATE TABLE llx_inbox_template_option(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_template integer NOT NULL,
	whole smallint DEFAULT 0 NOT NULL
) ENGINE=innodb;
