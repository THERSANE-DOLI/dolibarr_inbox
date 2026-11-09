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


-- 1.13.0: origin of a mailref (inbox, doliconnector...) and the last place where the mail was seen (display only)
ALTER TABLE llx_inbox_mailref ADD COLUMN origin varchar(32) DEFAULT NULL;
ALTER TABLE llx_inbox_mailref ADD COLUMN last_account integer DEFAULT NULL;
ALTER TABLE llx_inbox_mailref ADD COLUMN last_folder varchar(255) DEFAULT NULL;
